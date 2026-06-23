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
$groupId = (int)post_value('group_id', '0');

if ($groupId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Groupe invalide.',
    ], 400);
}

$group = db_fetch_one(
    "SELECT id, created_by
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

if ((int)$group['created_by'] === (int)$user['id']) {
    json_response([
        'success' => false,
        'error' => 'Le créateur du groupe ne peut pas quitter son propre groupe.',
    ], 403);
}

try {
    db_query(
        "DELETE FROM group_members
         WHERE group_id = :group_id
           AND user_id = :user_id",
        [
            'group_id' => $groupId,
            'user_id' => $user['id'],
        ]
    );

    json_response([
        'success' => true,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors de la sortie du groupe.',
    ], 500);
}
