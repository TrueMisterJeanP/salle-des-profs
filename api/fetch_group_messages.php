<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/markdown.php';

require_login();

$user = current_user();
$groupId = (int)get_value('group_id', '0');

if ($groupId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Groupe invalide.',
    ], 400);
}

$group = db_fetch_one(
    "SELECT id, name, visibility, created_by
     FROM groups
     WHERE id = :id
     LIMIT 1",
    ['id' => $groupId]
);

if (!$group) {
    json_response([
        'success' => false,
        'error' => 'Groupe introuvable.',
    ], 404);
}

$membership = db_fetch_one(
    "SELECT id, role
     FROM group_members
     WHERE group_id = :group_id
       AND user_id = :user_id
     LIMIT 1",
    [
        'group_id' => $groupId,
        'user_id' => $user['id'],
    ]
);

$canRead = $membership !== null
    || (int)$group['created_by'] === (int)$user['id'];

if (!$canRead) {
    json_response([
        'success' => false,
        'error' => 'Vous devez être membre du groupe pour lire ses messages.',
    ], 403);
}

try {
    $canModerateMessages = (int)$group['created_by'] === (int)$user['id']
        || ($membership && in_array($membership['role'], ['admin', 'moderator'], true));

    $messages = db_fetch_all(
        "SELECT messages.id,
                messages.sender_id,
                messages.group_id,
                messages.content,
                messages.message_type,
                messages.attachment_id,
                messages.created_at,
                messages.updated_at,
                users.username,
                users.display_name,
                users.avatar,
                attachments.original_name AS attachment_original_name,
                attachments.mime_type AS attachment_mime_type,
                attachments.size AS attachment_size
         FROM messages
         JOIN users ON users.id = messages.sender_id
         LEFT JOIN attachments ON attachments.id = messages.attachment_id
         WHERE messages.group_id = :group_id
           AND messages.is_deleted = 0
         ORDER BY messages.created_at ASC
         LIMIT 400",
        ['group_id' => $groupId]
    );

    foreach ($messages as &$message) {
        $message['content_html'] = render_markdown($message['content'] ?? '');
        $message['can_delete'] = (int)$message['sender_id'] === (int)$user['id'] || $canModerateMessages;
    }
    unset($message);

    json_response([
        'success' => true,
        'messages' => $messages,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors du chargement des messages du groupe.',
    ], 500);
}
    
