<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();

if (!is_post()) {
    json_response([
        'success' => false,
        'error' => 'Méthode non autorisée.',
    ], 405);
}

require_csrf();

$user = current_user();
$type = post_value('type');
$targetId = (int)post_value('target_id', '0');

if (!in_array($type, ['private', 'group'], true) || $targetId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Conversation invalide.',
    ], 400);
}

try {
    if ($type === 'private') {
        $creator = db_fetch_one(
            "SELECT sender_id
             FROM messages
             WHERE group_id IS NULL
               AND is_deleted = 0
               AND (
                    (sender_id = :current_user_id_a AND receiver_id = :peer_id_a)
                    OR
                    (sender_id = :peer_id_b AND receiver_id = :current_user_id_b)
               )
             ORDER BY created_at ASC, id ASC
             LIMIT 1",
            [
                'current_user_id_a' => $user['id'],
                'peer_id_a' => $targetId,
                'peer_id_b' => $targetId,
                'current_user_id_b' => $user['id'],
            ]
        );

        $canDelete = $creator
            && ((int)$creator['sender_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin');

        if (!$canDelete) {
            json_response([
                'success' => false,
                'error' => 'Vous ne pouvez pas supprimer cette conversation.',
            ], 403);
        }

        $stmt = db_query(
            "UPDATE messages
             SET is_deleted = 1,
                 updated_at = :updated_at
             WHERE group_id IS NULL
               AND is_deleted = 0
               AND (
                    (sender_id = :current_user_id_a AND receiver_id = :peer_id_a)
                    OR
                    (sender_id = :peer_id_b AND receiver_id = :current_user_id_b)
               )",
            [
                'updated_at' => now(),
                'current_user_id_a' => $user['id'],
                'peer_id_a' => $targetId,
                'peer_id_b' => $targetId,
                'current_user_id_b' => $user['id'],
            ]
        );

        json_response([
            'success' => true,
            'deleted_count' => $stmt->rowCount(),
        ]);
    }

    $group = db_fetch_one(
        "SELECT id, created_by
         FROM groups
         WHERE id = :id
         LIMIT 1",
        ['id' => $targetId]
    );

    $canDelete = $group
        && ((int)$group['created_by'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin');

    if (!$canDelete) {
        json_response([
            'success' => false,
            'error' => 'Vous ne pouvez pas supprimer cette conversation.',
        ], 403);
    }

    $stmt = db_query(
        "UPDATE messages
         SET is_deleted = 1,
             updated_at = :updated_at
         WHERE group_id = :group_id
           AND is_deleted = 0",
        [
            'updated_at' => now(),
            'group_id' => $targetId,
        ]
    );

    json_response([
        'success' => true,
        'deleted_count' => $stmt->rowCount(),
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors de la suppression de la conversation.',
    ], 500);
}
