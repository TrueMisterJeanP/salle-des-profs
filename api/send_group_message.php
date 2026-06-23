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

$groupId = (int)post_value('group_id', '0');
$content = post_value('content');
$attachmentId = (int)post_value('attachment_id', '0');

if ($groupId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Groupe invalide.',
    ], 400);
}

if ($content === '' && $attachmentId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Le message ne peut pas être vide.',
    ], 400);
}

$group = db_fetch_one(
    "SELECT id, name, created_by
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

$canWrite = $membership !== null
    || (int)$group['created_by'] === (int)$user['id'];

if (!$canWrite) {
    json_response([
        'success' => false,
        'error' => 'Vous ne pouvez pas écrire dans ce groupe.',
    ], 403);
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
            (:sender_id, NULL, :group_id, :content, :attachment_id, :message_type, 0, 0, :created_at)",
        [
            'sender_id' => $user['id'],
            'group_id' => $groupId,
            'content' => $content,
            'attachment_id' => $attachmentId > 0 ? $attachmentId : null,
            'message_type' => $attachmentId > 0 ? 'attachment' : 'text',
            'created_at' => now(),
        ]
    );

    $members = db_fetch_all(
        "SELECT user_id
         FROM group_members
         WHERE group_id = :group_id
           AND user_id != :sender_id",
        [
            'group_id' => $groupId,
            'sender_id' => $user['id'],
        ]
    );

    foreach ($members as $member) {
        db_insert(
            "INSERT INTO notifications
                (user_id, type, content, link, is_read, created_at)
             VALUES
                (:user_id, 'group_message', :content, :link, 0, :created_at)",
                [
                    'user_id' => $member['user_id'],
                    'content' => 'Nouveau message dans le groupe « ' . $group['name'] . ' »',
                    'link' => url('messenger.php?type=group&group_id=' . $groupId),
                    'created_at' => now(),
                ]
            );
    }

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
