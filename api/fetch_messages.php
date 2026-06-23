<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/markdown.php';

require_login();

$user = current_user();
$peerId = (int)get_value('user_id', '0');

if ($peerId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Interlocuteur invalide.',
    ], 400);
}

$peer = db_fetch_one(
    "SELECT id, is_active
     FROM users
     WHERE id = :id
     LIMIT 1",
    ['id' => $peerId]
);

if (!$peer || (int)$peer['is_active'] !== 1) {
    json_response([
        'success' => false,
        'error' => 'Interlocuteur introuvable ou désactivé.',
    ], 404);
}

try {
    db_query(
        "UPDATE messages
         SET is_read = 1
         WHERE receiver_id = :current_user_id
           AND sender_id = :peer_id
           AND group_id IS NULL
           AND is_read = 0",
        [
            'current_user_id' => $user['id'],
            'peer_id' => $peerId,
        ]
    );

    $messages = db_fetch_all(
        "SELECT messages.id,
                messages.sender_id,
                messages.receiver_id,
                messages.content,
                messages.message_type,
                messages.attachment_id,
                messages.is_read,
                messages.created_at,
                messages.updated_at,
                sender.username AS sender_username,
                sender.display_name AS sender_display_name,
                sender.avatar AS sender_avatar,
                attachments.original_name AS attachment_original_name,
                attachments.mime_type AS attachment_mime_type,
                attachments.size AS attachment_size
         FROM messages
         JOIN users AS sender ON sender.id = messages.sender_id
         LEFT JOIN attachments ON attachments.id = messages.attachment_id
         WHERE messages.group_id IS NULL
           AND messages.is_deleted = 0
           AND (
                (messages.sender_id = :current_user_id AND messages.receiver_id = :peer_id)
                OR
                (messages.sender_id = :peer_id AND messages.receiver_id = :current_user_id)
           )
         ORDER BY messages.created_at ASC
         LIMIT 300",
        [
            'current_user_id' => $user['id'],
            'peer_id' => $peerId,
        ]
    );

    foreach ($messages as &$message) {
        $message['content_html'] = render_markdown($message['content'] ?? '');
        $message['can_delete'] = (int)$message['sender_id'] === (int)$user['id']
            || ($user['role'] ?? '') === 'admin';
    }
    unset($message);

    json_response([
        'success' => true,
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors du chargement des messages.',
    ], 500);
}
    
