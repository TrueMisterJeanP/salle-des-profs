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

$messageId = (int)post_value('message_id', '0');

if ($messageId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Message invalide.',
    ], 400);
}

$message = db_fetch_one(
    "SELECT *
     FROM messages
     WHERE id = :id
       AND is_deleted = 0
     LIMIT 1",
    ['id' => $messageId]
);

if (!$message) {
    json_response([
        'success' => false,
        'error' => 'Message introuvable.',
    ], 404);
}

$canDelete = false;

if ((int)$message['sender_id'] === (int)$user['id']) {
    $canDelete = true;
}

if (($user['role'] ?? '') === 'admin') {
    $canDelete = true;
}

if (!$canDelete && !empty($message['group_id'])) {
    $group = db_fetch_one(
        "SELECT created_by
         FROM groups
         WHERE id = :id
         LIMIT 1",
        ['id' => $message['group_id']]
    );

    if ($group && (int)$group['created_by'] === (int)$user['id']) {
        $canDelete = true;
    }
}

if (!$canDelete && !empty($message['group_id'])) {
    $membership = db_fetch_one(
        "SELECT role
         FROM group_members
         WHERE group_id = :group_id
           AND user_id = :user_id
         LIMIT 1",
        [
            'group_id' => $message['group_id'],
            'user_id' => $user['id'],
        ]
    );

    if ($membership && in_array($membership['role'], ['admin', 'moderator'], true)) {
        $canDelete = true;
    }
}

if (!$canDelete) {
    json_response([
        'success' => false,
        'error' => 'Vous ne pouvez pas supprimer ce message.',
    ], 403);
}

try {
    db_query(
        "UPDATE messages
         SET is_deleted = 1,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'updated_at' => now(),
            'id' => $messageId,
        ]
    );

    json_response([
        'success' => true,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors de la suppression.',
    ], 500);
}
