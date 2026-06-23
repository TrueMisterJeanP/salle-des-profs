<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

function storage_quota_ensure_schema(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    if (db_is_mysql()) {
        db()->exec(
            "CREATE TABLE IF NOT EXISTS storage_quota (
                id INTEGER PRIMARY KEY,
                used_bytes BIGINT NOT NULL DEFAULT 0,
                quota_bytes BIGINT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } else {
        db_exec_schema(
            "CREATE TABLE IF NOT EXISTS storage_quota (
            id INTEGER PRIMARY KEY,
            used_bytes BIGINT NOT NULL DEFAULT 0,
            quota_bytes BIGINT
            )"
        );
    }

    storage_quota_ensure_mysql_innodb();

    if (!db_column_exists('users', 'used_storage_bytes')) {
        db_query("ALTER TABLE users ADD COLUMN used_storage_bytes BIGINT NOT NULL DEFAULT 0");
    }

    if (!db_column_exists('users', 'quota_storage_bytes')) {
        db_query("ALTER TABLE users ADD COLUMN quota_storage_bytes BIGINT");
    }

    $total = (int)(db_fetch_one("SELECT COALESCE(SUM(size), 0) AS total FROM attachments")['total'] ?? 0);

    db_insert_ignore('storage_quota', [
        'id' => 1,
        'used_bytes' => $total,
        'quota_bytes' => null,
    ]);

    db_insert_ignore('settings', [
        'setting_key' => 'default_user_storage_quota_bytes',
        'setting_value' => '',
    ]);

    if (setting_value('storage_quota_counters_initialized', '') !== '1') {
        storage_quota_recalculate_counters();
        save_setting_value('storage_quota_counters_initialized', '1');
    }

    $done = true;
}

function storage_quota_ensure_mysql_innodb(): void
{
    if (!db_is_mysql() || setting_value('storage_quota_innodb_checked', '') === '1') {
        return;
    }

    $tables = ['users', 'attachments', 'storage_quota'];
    $placeholders = [];
    $params = [];

    foreach ($tables as $index => $table) {
        $key = 'table_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $table;
    }

    $rows = db_fetch_all(
        "SELECT TABLE_NAME, ENGINE
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN (" . implode(',', $placeholders) . ")",
        $params
    );

    foreach ($rows as $row) {
        $table = (string)($row['TABLE_NAME'] ?? '');
        $engine = mb_strtolower((string)($row['ENGINE'] ?? ''), 'UTF-8');

        if (!in_array($table, $tables, true) || $engine === 'innodb') {
            continue;
        }

        db()->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ENGINE=InnoDB');
    }

    save_setting_value('storage_quota_innodb_checked', '1');
}

function storage_quota_recalculate_counters(): void
{
    db_query("UPDATE users SET used_storage_bytes = 0");

    $rows = db_fetch_all(
        "SELECT user_id, COALESCE(SUM(size), 0) AS used_bytes
         FROM attachments
         GROUP BY user_id"
    );

    foreach ($rows as $row) {
        db_query(
            "UPDATE users
             SET used_storage_bytes = :used_bytes
             WHERE id = :user_id",
            [
                'used_bytes' => (int)$row['used_bytes'],
                'user_id' => (int)$row['user_id'],
            ]
        );
    }

    $total = (int)(db_fetch_one("SELECT COALESCE(SUM(size), 0) AS total FROM attachments")['total'] ?? 0);

    db_query(
        "UPDATE storage_quota
         SET used_bytes = :used_bytes
         WHERE id = 1",
        ['used_bytes' => $total]
    );
}

function storage_quota_parse_size_to_bytes(string $value, int $unitMultiplier): ?int
{
    $value = str_replace(',', '.', trim($value));

    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
        throw new RuntimeException('Quota invalide.');
    }

    $bytes = (float)$value * $unitMultiplier;

    if ($bytes < 0 || $bytes > PHP_INT_MAX) {
        throw new RuntimeException('Quota invalide.');
    }

    return (int)round($bytes);
}

function storage_quota_bytes_to_unit(?int $bytes, int $unitMultiplier): string
{
    if ($bytes === null || $bytes <= 0) {
        return '';
    }

    $value = $bytes / $unitMultiplier;

    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function storage_quota_global(): array
{
    storage_quota_ensure_schema();

    $quota = db_fetch_one(
        "SELECT used_bytes, quota_bytes
         FROM storage_quota
         WHERE id = 1
         LIMIT 1"
    );

    return $quota ?: ['used_bytes' => 0, 'quota_bytes' => null];
}

function storage_quota_default_user_quota_bytes(): ?int
{
    $value = trim(setting_value('default_user_storage_quota_bytes', ''));

    return $value === '' ? null : max(0, (int)$value);
}

function storage_quota_effective_user_quota(?int $userQuotaBytes): ?int
{
    if ($userQuotaBytes !== null && $userQuotaBytes > 0) {
        return $userQuotaBytes;
    }

    $default = storage_quota_default_user_quota_bytes();

    return $default !== null && $default > 0 ? $default : null;
}

function storage_quota_begin_write_transaction(PDO $pdo): void
{
    if (db_is_mysql()) {
        $pdo->beginTransaction();
        return;
    }

    $pdo->exec('BEGIN IMMEDIATE');
}

function storage_quota_reserve_for_upload(int $userId, int $fileSize): void
{
    storage_quota_ensure_schema();

    $globalSql = "SELECT used_bytes, quota_bytes FROM storage_quota WHERE id = 1";
    $userSql = "SELECT used_storage_bytes, quota_storage_bytes FROM users WHERE id = :user_id";

    if (db_is_mysql()) {
        $globalSql .= " FOR UPDATE";
        $userSql .= " FOR UPDATE";
    }

    $global = db_fetch_one($globalSql);
    $user = db_fetch_one($userSql, ['user_id' => $userId]);

    if (!$global || !$user) {
        throw new RuntimeException('Impossible de vérifier le quota de stockage.');
    }

    $globalUsed = (int)$global['used_bytes'];
    $globalQuota = $global['quota_bytes'] !== null ? (int)$global['quota_bytes'] : null;
    $userUsed = (int)$user['used_storage_bytes'];
    $userQuota = storage_quota_effective_user_quota(
        $user['quota_storage_bytes'] !== null ? (int)$user['quota_storage_bytes'] : null
    );

    if ($globalQuota !== null && $globalQuota > 0 && $globalUsed + $fileSize > $globalQuota) {
        throw new RuntimeException('Quota global de stockage dépassé.');
    }

    if ($userQuota !== null && $userQuota > 0 && $userUsed + $fileSize > $userQuota) {
        throw new RuntimeException('Quota personnel de stockage dépassé.');
    }

    db_query(
        "UPDATE users
         SET used_storage_bytes = used_storage_bytes + :file_size
         WHERE id = :user_id",
        [
            'file_size' => $fileSize,
            'user_id' => $userId,
        ]
    );

    db_query(
        "UPDATE storage_quota
         SET used_bytes = used_bytes + :file_size
         WHERE id = 1",
        ['file_size' => $fileSize]
    );
}

function storage_quota_release(int $userId, int $fileSize): void
{
    storage_quota_ensure_schema();

    db_query(
        "UPDATE users
         SET used_storage_bytes = CASE
             WHEN used_storage_bytes >= :file_size THEN used_storage_bytes - :file_size
             ELSE 0
         END
         WHERE id = :user_id",
        [
            'file_size' => $fileSize,
            'user_id' => $userId,
        ]
    );

    db_query(
        "UPDATE storage_quota
         SET used_bytes = CASE
             WHEN used_bytes >= :file_size THEN used_bytes - :file_size
             ELSE 0
         END
         WHERE id = 1",
        ['file_size' => $fileSize]
    );
}

function storage_quota_set_global_quota(?int $quotaBytes): void
{
    storage_quota_ensure_schema();

    db_query(
        "UPDATE storage_quota
         SET quota_bytes = :quota_bytes
         WHERE id = 1",
        ['quota_bytes' => $quotaBytes !== null && $quotaBytes > 0 ? $quotaBytes : null]
    );
}
