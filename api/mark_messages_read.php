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
$peerId = (int)post_value('user_id', '0');

if ($peerId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Interlocuteur invalide.',
    ], 400);
}

try {
    $statement = db_query(
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

    json_response([
        'success' => true,
        'marked_read' => $statement->rowCount(),
    ]);
} catch (Throwable) {
    json_response([
        'success' => false,
        'error' => 'Impossible de marquer les messages comme lus.',
    ], 500);
}
