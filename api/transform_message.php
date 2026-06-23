<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tags.php';

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
$type = post_value('type', 'post');

if ($messageId <= 0) {
    json_response([
        'success' => false,
        'error' => 'Message invalide.',
    ], 400);
}

if (!in_array($type, ['post', 'article'], true)) {
    json_response([
        'success' => false,
        'error' => 'Type de transformation invalide.',
    ], 400);
}

$message = db_fetch_one(
    "SELECT messages.*, users.username, users.display_name
     FROM messages
     JOIN users ON users.id = messages.sender_id
     WHERE messages.id = :id
       AND messages.is_deleted = 0
     LIMIT 1",
    ['id' => $messageId]
);

if (!$message) {
    json_response([
        'success' => false,
        'error' => 'Message introuvable.',
    ], 404);
}

$canUseMessage = false;

if ((int)$message['sender_id'] === (int)$user['id']) {
    $canUseMessage = true;
}

if (!empty($message['receiver_id']) && (int)$message['receiver_id'] === (int)$user['id']) {
    $canUseMessage = true;
}

if (!empty($message['group_id'])) {
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

    if ($membership || ($user['role'] ?? '') === 'admin') {
        $canUseMessage = true;
    }
}

if (($user['role'] ?? '') === 'admin') {
    $canUseMessage = true;
}

if (!$canUseMessage) {
    json_response([
        'success' => false,
        'error' => 'Vous ne pouvez pas transformer ce message.',
    ], 403);
}

$content = trim((string)$message['content']);

if ($content === '') {
    json_response([
        'success' => false,
        'error' => 'Ce message ne contient pas de texte exploitable.',
    ], 400);
}

try {
    if ($type === 'post') {
        $postId = db_insert(
            "INSERT INTO posts
                (author_id, content, visibility, source_message_id, is_published, created_at)
             VALUES
                (:author_id, :content, 'members', :source_message_id, 1, :created_at)",
            [
                'author_id' => $user['id'],
                'content' => $content,
                'source_message_id' => $messageId,
                'created_at' => now(),
            ]
        );

        json_response([
            'success' => true,
            'type' => 'post',
            'id' => $postId,
            'redirect' => url('feed.php'),
        ]);
    }

    $title = excerpt($content, 70);
    $slug = slugify($title);
    $baseSlug = $slug;
    $counter = 2;

    while (db_fetch_one("SELECT id FROM articles WHERE slug = :slug LIMIT 1", ['slug' => $slug])) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }

    $articleId = db_insert(
        "INSERT INTO articles
            (author_id, title, slug, content, excerpt, visibility, status, source_message_id, created_at, updated_at)
         VALUES
            (:author_id, :title, :slug, :content, :excerpt, 'members', 'draft', :source_message_id, :created_at, :updated_at)",
        [
            'author_id' => $user['id'],
            'title' => $title,
            'slug' => $slug,
            'content' => $content,
            'excerpt' => excerpt($content, 180),
            'source_message_id' => $messageId,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    article_sync_tags($articleId, $title, $content);

    json_response([
        'success' => true,
        'type' => 'article',
        'id' => $articleId,
        'redirect' => url('article_edit.php?id=' . $articleId),
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur lors de la transformation.',
    ], 500);
}
