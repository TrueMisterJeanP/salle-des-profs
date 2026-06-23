<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mastodon.php';
require_once __DIR__ . '/../includes/mastodon_text.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();

$user = current_user();

function mastodon_publication_payload(array $publication): array
{
    return [
        'url' => $publication['mastodon_url'] ?? null,
        'id' => $publication['mastodon_id'] ?? null,
    ];
}

function mastodon_claim_publication(int $userId, string $targetType, int $targetId): ?array
{
    try {
        db_insert(
            "INSERT INTO mastodon_publications
             (user_id, target_type, target_id, mastodon_url, mastodon_id, created_at)
             VALUES
             (:user_id, :target_type, :target_id, NULL, NULL, :created_at)",
            [
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'created_at' => now(),
            ]
        );

        return null;
    } catch (Throwable $e) {
        $existing = db_fetch_one(
            "SELECT *
             FROM mastodon_publications
             WHERE user_id = :user_id
               AND target_type = :target_type
               AND target_id = :target_id
             LIMIT 1",
            [
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ]
        );

        if ($existing) {
            return $existing;
        }

        throw $e;
    }
}

function mastodon_complete_publication(
    int $userId,
    string $targetType,
    int $targetId,
    ?string $mastodonUrl,
    ?string $mastodonId
): void {
    db_query(
        "UPDATE mastodon_publications
         SET mastodon_url = :mastodon_url,
             mastodon_id = :mastodon_id
         WHERE user_id = :user_id
           AND target_type = :target_type
           AND target_id = :target_id",
        [
            'mastodon_url' => $mastodonUrl,
            'mastodon_id' => $mastodonId,
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]
    );
}

function mastodon_release_publication_claim(int $userId, string $targetType, int $targetId): void
{
    db_query(
        "DELETE FROM mastodon_publications
         WHERE user_id = :user_id
           AND target_type = :target_type
           AND target_id = :target_id
           AND mastodon_url IS NULL
           AND mastodon_id IS NULL",
        [
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]
    );
}

if (!is_post()) {
    json_response([
        'success' => false,
        'error' => 'Méthode non autorisée.',
    ], 405);
}

require_csrf();

$type = post_value('type');
$id = (int)post_value('id', '0');

if (!in_array($type, ['post', 'article'], true)) {
    json_response([
        'success' => false,
        'error' => 'Type de contenu invalide.',
    ], 400);
}

if ($id <= 0) {
    json_response([
        'success' => false,
        'error' => 'Contenu invalide.',
    ], 400);
}

try {
    db_query(
        "CREATE TABLE IF NOT EXISTS mastodon_publications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            mastodon_url TEXT,
            mastodon_id TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, target_type, target_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    if (!mastodon_is_configured((int)$user['id'])) {
        json_response([
            'success' => false,
            'error' => 'Configurez d’abord votre compte Mastodon.',
        ], 400);
    }

    $alreadyPublished = db_fetch_one(
        "SELECT *
         FROM mastodon_publications
         WHERE user_id = :user_id
           AND target_type = :target_type
           AND target_id = :target_id
         LIMIT 1",
        [
            'user_id' => $user['id'],
            'target_type' => $type,
            'target_id' => $id,
        ]
    );

    if ($alreadyPublished) {
        json_response([
            'success' => true,
            'already_published' => true,
            'mastodon' => mastodon_publication_payload($alreadyPublished),
        ]);
    }

    if ($type === 'post') {
        $post = db_fetch_one(
            "SELECT posts.*, users.username, users.display_name
             FROM posts
             JOIN users ON users.id = posts.author_id
             WHERE posts.id = :id
               AND posts.is_published = 1
               AND posts.visibility = 'public'
             LIMIT 1",
            ['id' => $id]
        );

        if (!$post) {
            json_response([
                'success' => false,
                'error' => 'Post public introuvable.',
            ], 404);
        }

        if ((int)$post['author_id'] !== (int)$user['id'] && ($user['role'] ?? '') !== 'admin') {
            json_response([
                'success' => false,
                'error' => 'Vous ne pouvez publier que vos propres contenus publics.',
            ], 403);
        }

        $publicUrl = url('public.php') . '#post-' . (int)$post['id'];

        $status = mastodon_build_status(
            '',
            (string)$post['content'],
            $publicUrl,
            480
        );

        $claimedByExisting = mastodon_claim_publication((int)$user['id'], 'post', $id);

        if ($claimedByExisting) {
            json_response([
                'success' => true,
                'already_published' => true,
                'mastodon' => mastodon_publication_payload($claimedByExisting),
            ]);
        }

        try {
            $result = mastodon_publish_status((int)$user['id'], $status);
        } catch (Throwable $publishThrowable) {
            mastodon_release_publication_claim((int)$user['id'], 'post', $id);
            throw $publishThrowable;
        }

        $localSaved = true;
        $localError = null;
        
        try {
            mastodon_complete_publication(
                (int)$user['id'],
                'post',
                $id,
                isset($result['url']) ? (string)$result['url'] : null,
                isset($result['id']) ? (string)$result['id'] : null
            );
        } catch (Throwable $localThrowable) {
            $localSaved = false;
            $localError = $localThrowable->getMessage();
        }
        
        json_response([
            'success' => true,
            'mastodon' => $result,
            'local_saved' => $localSaved,
            'local_error' => $localError,
        ]);
    }

    $article = db_fetch_one(
        "SELECT articles.*, users.username, users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE articles.id = :id
           AND articles.status = 'published'
           AND articles.visibility = 'public'
         LIMIT 1",
        ['id' => $id]
    );

    if (!$article) {
        json_response([
            'success' => false,
            'error' => 'Article public introuvable.',
        ], 404);
    }

    if ((int)$article['author_id'] !== (int)$user['id'] && ($user['role'] ?? '') !== 'admin') {
        json_response([
            'success' => false,
            'error' => 'Vous ne pouvez publier que vos propres contenus publics.',
        ], 403);
    }

    $publicUrl = url('public_article.php?slug=' . urlencode($article['slug']));
    $summary = $article['excerpt'] ?: excerpt((string)$article['content'], 260);

    $status = mastodon_build_status(
        (string)$article['title'],
        (string)$summary,
        $publicUrl,
        480
    );

    $claimedByExisting = mastodon_claim_publication((int)$user['id'], 'article', $id);

    if ($claimedByExisting) {
        json_response([
            'success' => true,
            'already_published' => true,
            'mastodon' => mastodon_publication_payload($claimedByExisting),
        ]);
    }

    try {
        $result = mastodon_publish_status((int)$user['id'], $status);
    } catch (Throwable $publishThrowable) {
        mastodon_release_publication_claim((int)$user['id'], 'article', $id);
        throw $publishThrowable;
    }
    
    $localSaved = true;
    $localError = null;
    
    try {
        mastodon_complete_publication(
            (int)$user['id'],
            'article',
            $id,
            isset($result['url']) ? (string)$result['url'] : null,
            isset($result['id']) ? (string)$result['id'] : null
        );
    } catch (Throwable $localThrowable) {
        $localSaved = false;
        $localError = $localThrowable->getMessage();
    }
    
    json_response([
        'success' => true,
        'mastodon' => $result,
        'local_saved' => $localSaved,
        'local_error' => $localError,
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => 'Erreur pendant la publication Mastodon : ' . $e->getMessage(),
    ], 500);
}
