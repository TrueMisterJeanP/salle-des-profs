<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/web_notifications.php';

require_login();

$user = current_user();

if (!web_notifications_messenger_enabled((int)$user['id'])) {
    json_response([
        'success' => true,
        'notifications' => [],
        'unread_count' => 0,
        'messenger_enabled' => false,
    ]);
}

try {
    $notifications = db_fetch_all(
        "SELECT id, type, content, link, created_at
         FROM notifications
         WHERE user_id = :user_id
           AND is_read = 0
           AND type IN ('private_message', 'group_message')
         ORDER BY id ASC
         LIMIT 50",
        ['user_id' => $user['id']]
    );

    $unreadCount = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_id = :user_id
           AND is_read = 0
           AND type IN ('private_message', 'group_message')",
        ['user_id' => $user['id']]
    )['total'] ?? 0);

    json_response([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => $unreadCount,
        'messenger_enabled' => true,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors du chargement des notifications de messagerie.',
    ], 500);
}
