<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';

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
$content = post_value('content');

if ($messageId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Message invalide.',
    ], 400);
}

if ($content === '') {
    json_response([
        'success' => false,
        'error' => 'Le message ne peut pas être vide.',
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

$canEdit = false;

/**
 * V1 : seul l’auteur du message peut modifier son message.
 * Un administrateur peut supprimer/modérer, mais pas modifier le contenu d’autrui.
 */
if ((int)$message['sender_id'] === (int)$user['id']) {
    $canEdit = true;
}

if (!$canEdit) {
    json_response([
        'success' => false,
        'error' => 'Vous ne pouvez modifier que vos propres messages.',
    ], 403);
}

try {
    db_query(
        "UPDATE messages
         SET content = :content,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'content' => $content,
            'updated_at' => now(),
            'id' => $messageId,
        ]
    );

    json_response([
        'success' => true,
        'message_id' => $messageId,
        'content' => $content,
        'content_html' => render_markdown($content),
        'updated_at' => now(),
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors de la modification.',
    ], 500);
}
