<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();

$user = current_user();

if (!is_post()) {
    json_response([
        'success' => false,
        'error' => 'Méthode non autorisée.',
    ], 405);
}

require_csrf();

$receiverId = (int)post_value('receiver_id', '0');
$content = post_value('content');
$attachmentId = (int)post_value('attachment_id', '0');

if ($receiverId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Destinataire invalide.',
    ], 400);
}

if ($receiverId === (int)$user['id']) {
    json_response([
        'success' => false,
        'error' => 'Vous ne pouvez pas vous envoyer un message à vous-même.',
    ], 400);
}

if ($content === '' && $attachmentId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Le message ne peut pas être vide.',
    ], 400);
}

$receiver = db_fetch_one(
    "SELECT id, is_active
     FROM users
     WHERE id = :id
     LIMIT 1",
    ['id' => $receiverId]
);

if (!$receiver || (int)$receiver['is_active'] !== 1) {
    json_response([
        'success' => false,
        'error' => 'Destinataire introuvable ou désactivé.',
    ], 404);
}

if ($attachmentId > 0) {
    $attachment = db_fetch_one(
        "SELECT id, user_id
         FROM attachments
         WHERE id = :id
         LIMIT 1",
        ['id' => $attachmentId]
    );

    if (!$attachment || (int)$attachment['user_id'] !== (int)$user['id']) {
        json_response([
            'success' => false,
            'error' => 'Pièce jointe invalide.',
        ], 400);
    }
}

try {
    $messageId = db_insert(
        "INSERT INTO messages
            (sender_id, receiver_id, group_id, content, attachment_id, message_type, is_read, is_deleted, created_at)
         VALUES
            (:sender_id, :receiver_id, NULL, :content, :attachment_id, :message_type, 0, 0, :created_at)",
        [
            'sender_id' => $user['id'],
            'receiver_id' => $receiverId,
            'content' => $content,
            'attachment_id' => $attachmentId > 0 ? $attachmentId : null,
            'message_type' => $attachmentId > 0 ? 'attachment' : 'text',
            'created_at' => now(),
        ]
    );

    db_insert(
        "INSERT INTO notifications
            (user_id, type, content, link, is_read, created_at)
         VALUES
            (:user_id, 'private_message', :content, :link, 0, :created_at)",
            [
                'user_id' => $receiverId,
                'content' => 'Nouveau message de ' . ($user['display_name'] ?: $user['username']),
                'link' => url('messenger.php?type=private&user_id=' . (int)$user['id']),
                'created_at' => now(),
            ]
        );

    json_response([
        'success' => true,
        'message_id' => $messageId,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors de l’envoi du message.',
    ], 500);
}
