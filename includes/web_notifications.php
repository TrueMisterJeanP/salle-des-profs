<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function web_notifications_ensure_table(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS web_notification_preferences (
            user_id INTEGER PRIMARY KEY,
            messenger_enabled INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    $done = true;
}

function web_notifications_messenger_enabled(int $userId): bool
{
    web_notifications_ensure_table();

    $row = db_fetch_one(
        "SELECT messenger_enabled
         FROM web_notification_preferences
         WHERE user_id = :user_id
         LIMIT 1",
        ['user_id' => $userId]
    );

    return (int)($row['messenger_enabled'] ?? 0) === 1;
}

function web_notifications_set_messenger_enabled(int $userId, bool $enabled): void
{
    web_notifications_ensure_table();

    $existing = db_fetch_one(
        "SELECT user_id
         FROM web_notification_preferences
         WHERE user_id = :user_id
         LIMIT 1",
        ['user_id' => $userId]
    );

    if ($existing) {
        db_query(
            "UPDATE web_notification_preferences
             SET messenger_enabled = :messenger_enabled,
                 updated_at = :updated_at
             WHERE user_id = :user_id",
            [
                'messenger_enabled' => $enabled ? 1 : 0,
                'updated_at' => now(),
                'user_id' => $userId,
            ]
        );
        return;
    }

    db_insert(
        "INSERT INTO web_notification_preferences (user_id, messenger_enabled, updated_at)
         VALUES (:user_id, :messenger_enabled, :updated_at)",
        [
            'user_id' => $userId,
            'messenger_enabled' => $enabled ? 1 : 0,
            'updated_at' => now(),
        ]
    );
}
