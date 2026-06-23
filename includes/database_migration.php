<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function database_mysql_config_from_post(): array
{
    return [
        'host' => post_value('mysql_host', '127.0.0.1'),
        'port' => max(1, min(65535, (int)post_value('mysql_port', '3306'))),
        'database' => post_value('mysql_database'),
        'username' => post_value('mysql_username'),
        'password' => (string)($_POST['mysql_password'] ?? ''),
        'charset' => post_value('mysql_charset', 'utf8mb4') ?: 'utf8mb4',
    ];
}

function database_mysql_pdo(array $mysql, bool $withDatabase = true): PDO
{
    $host = (string)($mysql['host'] ?? '127.0.0.1');
    $port = (int)($mysql['port'] ?? 3306);
    $database = (string)($mysql['database'] ?? '');
    $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($mysql['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';

    if ($withDatabase && $database === '') {
        throw new RuntimeException('Nom de base MariaDB obligatoire.');
    }

    $dsn = 'mysql:host=' . $host . ';port=' . $port . ($withDatabase ? ';dbname=' . $database : '') . ';charset=' . $charset;
    $pdo = new PDO($dsn, (string)($mysql['username'] ?? ''), (string)($mysql['password'] ?? ''));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
    $pdo->exec('SET NAMES ' . $charset);

    return $pdo;
}

function database_mysql_execute_schema(PDO $pdo): void
{
    $schemaPath = ROOT_PATH . '/database/schema.mysql.sql';
    $sql = file_get_contents($schemaPath);

    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Schéma MariaDB introuvable ou vide.');
    }

    foreach (database_split_sql($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function database_split_sql(string $sql): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $quote = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $current .= $char;

        if ($inString) {
            if ($char === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = false;
            }

            continue;
        }

        if ($char === "'" || $char === '"') {
            $inString = true;
            $quote = $char;
            continue;
        }

        if ($char === ';') {
            $statement = trim(substr($current, 0, -1));
            $current = '';

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
    }

    $tail = trim($current);

    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function database_copy_sqlite_to_mysql(array $mysql): array
{
    if (!is_file(DATABASE_PATH)) {
        throw new RuntimeException('Base SQLite introuvable.');
    }

    @set_time_limit(0);
    @ignore_user_abort(true);

    $sqlite = new PDO('sqlite:' . DATABASE_PATH);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $mysqlPdo = database_mysql_pdo($mysql);

    database_mysql_execute_schema($mysqlPdo);

    $tables = database_copy_table_order();
    $copied = [];

    $mysqlPdo->beginTransaction();
    $mysqlPdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        foreach (array_reverse($tables) as $table) {
            $mysqlPdo->exec('DELETE FROM `' . str_replace('`', '``', $table) . '`');
        }

        foreach ($tables as $table) {
            $columns = database_sqlite_columns($sqlite, $table);

            if (!$columns) {
                continue;
            }

            $copied[$table] = database_copy_sqlite_table_to_mysql($sqlite, $mysqlPdo, $table, $columns);
        }

        $mysqlPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $mysqlPdo->commit();
    } catch (Throwable $e) {
        if ($mysqlPdo->inTransaction()) {
            $mysqlPdo->rollBack();
        }

        try {
            $mysqlPdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable) {
        }

        throw $e;
    }

    return $copied;
}

function database_copy_sqlite_table_to_mysql(PDO $sqlite, PDO $mysqlPdo, string $table, array $columns): int
{
    $safeTableSqlite = '"' . str_replace('"', '""', $table) . '"';
    $select = $sqlite->query('SELECT * FROM ' . $safeTableSqlite);

    if (!$select) {
        return 0;
    }

    $columnCount = max(1, count($columns));
    $batchSize = min(250, max(1, intdiv(60000, $columnCount)));
    $batch = [];
    $copied = 0;

    while (($row = $select->fetch()) !== false) {
        $batch[] = $row;

        if (count($batch) >= $batchSize) {
            $copied += database_insert_mysql_batch($mysqlPdo, $table, $columns, $batch);
            $batch = [];
        }
    }

    if ($batch) {
        $copied += database_insert_mysql_batch($mysqlPdo, $table, $columns, $batch);
    }

    return $copied;
}

function database_insert_mysql_batch(PDO $pdo, string $table, array $columns, array $rows): int
{
    if (!$rows) {
        return 0;
    }

    $quotedColumns = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns);
    $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $placeholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));
    $values = [];

    foreach ($rows as $row) {
        foreach ($columns as $column) {
            $values[] = $row[$column] ?? null;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(', ', $quotedColumns) . ')
         VALUES ' . $placeholders
    );
    $stmt->execute($values);

    return count($rows);
}

function database_sqlite_columns(PDO $sqlite, string $table): array
{
    $stmt = $sqlite->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
    $rows = $stmt ? $stmt->fetchAll() : [];

    return array_map(static fn (array $row): string => (string)$row['name'], $rows);
}

function database_copy_table_order(): array
{
    return [
        'users',
        'contacts',
        'groups',
        'group_members',
        'messages',
        'attachments',
        'posts',
        'post_polls',
        'post_poll_options',
        'post_poll_votes',
        'articles',
        'article_attachments',
        'comments',
        'tags',
        'content_tags',
        'notifications',
        'web_notification_preferences',
        'contact_messages',
        'visitor_activity',
        'settings',
        'migrations',
        'mastodon_publications',
        'activitypub_actors',
        'activitypub_remote_actors',
        'activitypub_followers',
        'activitypub_inbox',
        'activitypub_deliveries',
        'activitypub_blocks',
    ];
}

function database_mysql_has_users(array $mysql): bool
{
    $pdo = database_mysql_pdo($mysql);
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");

    if (!$stmt || !$stmt->fetch()) {
        return false;
    }

    $row = $pdo->query('SELECT COUNT(*) AS total FROM users')->fetch();

    return (int)($row['total'] ?? 0) > 0;
}
