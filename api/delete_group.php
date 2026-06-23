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

try {
    $group = db_fetch_one(
        "SELECT id, created_by
         FROM groups
         WHERE id = :id
         LIMIT 1",
        ['id' => $groupId]
    );

    $canDelete = $group
        && ((int)$group['created_by'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin');

    if (!$canDelete) {
        json_response([
            'success' => false,
            'error' => 'Vous ne pouvez pas supprimer ce groupe.',
        ], 403);
    }

    db()->beginTransaction();

    db_query(
        "DELETE FROM messages WHERE group_id = :group_id",
        ['group_id' => $groupId]
    );
    db_query(
        "DELETE FROM group_members WHERE group_id = :group_id",
        ['group_id' => $groupId]
    );
    db_query(
        "DELETE FROM groups WHERE id = :id",
        ['id' => $groupId]
    );

    db()->commit();

    json_response(['success' => true]);
} catch (Throwable $e) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }

    json_response([
        'success' => false,
        'error' => 'Erreur lors de la suppression du groupe.',
    ], 500);
}
