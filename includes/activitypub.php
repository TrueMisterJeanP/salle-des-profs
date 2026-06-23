<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/markdown.php';

function activitypub_json_response(array $data, int $statusCode = 200, string $contentType = 'application/activity+json; charset=utf-8'): never
{
    http_response_code($statusCode);
    header('Content-Type: ' . $contentType);

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function activitypub_base_url(): string
{
    return rtrim(BASE_URL, '/');
}

function activitypub_host(): string
{
    $host = parse_url(activitypub_base_url(), PHP_URL_HOST);

    return is_string($host) && $host !== '' ? $host : ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function activitypub_create_table(): void
{
    db_query(
        "CREATE TABLE IF NOT EXISTS activitypub_actors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL UNIQUE,
            actor_url TEXT NOT NULL UNIQUE,
            inbox_url TEXT NOT NULL,
            outbox_url TEXT NOT NULL,
            followers_url TEXT NOT NULL,
            public_key TEXT NOT NULL,
            private_key TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    db_query(
        "CREATE INDEX IF NOT EXISTS idx_activitypub_actors_user_id
         ON activitypub_actors(user_id)"
    );

    db_query(
        "CREATE TABLE IF NOT EXISTS activitypub_remote_actors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            actor_url TEXT NOT NULL UNIQUE,
            inbox_url TEXT NOT NULL,
            shared_inbox_url TEXT,
            followers_url TEXT,
            preferred_username TEXT,
            public_key_id TEXT,
            public_key_pem TEXT NOT NULL,
            raw_json TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT
        )"
    );

    db_query(
        "CREATE TABLE IF NOT EXISTS activitypub_followers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            remote_actor_id INTEGER NOT NULL,
            follow_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'accepted',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            UNIQUE(user_id, remote_actor_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
        )"
    );

    db_query(
        "CREATE TABLE IF NOT EXISTS activitypub_inbox (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            remote_actor_id INTEGER,
            activity_id TEXT NOT NULL UNIQUE,
            activity_type TEXT NOT NULL,
            raw_json TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE SET NULL
        )"
    );

    db_query(
        "CREATE TABLE IF NOT EXISTS activitypub_deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            remote_actor_id INTEGER NOT NULL,
            activity_id TEXT NOT NULL,
            inbox_url TEXT NOT NULL,
            activity_json TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            attempts INTEGER NOT NULL DEFAULT 0,
            last_status_code INTEGER,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
        )"
    );

    db_query(
        "CREATE TABLE IF NOT EXISTS activitypub_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            remote_actor_id INTEGER NOT NULL,
            block_id TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, remote_actor_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
        )"
    );

    db_query("CREATE INDEX IF NOT EXISTS idx_activitypub_followers_user_id ON activitypub_followers(user_id)");
    db_query("CREATE INDEX IF NOT EXISTS idx_activitypub_deliveries_status ON activitypub_deliveries(status)");
}

function activitypub_public_user_by_username(string $username): ?array
{
    $username = normalize_username($username);

    if ($username === '') {
        return null;
    }

    return db_fetch_one(
        "SELECT id, username, display_name, bio, avatar, is_active, created_at
         FROM users
         WHERE username = :username
           AND is_active = 1
         LIMIT 1",
        ['username' => $username]
    );
}

function activitypub_ensure_actor_keys(array $user): array
{
    activitypub_create_table();

    $actor = db_fetch_one(
        "SELECT *
         FROM activitypub_actors
         WHERE user_id = :user_id
         LIMIT 1",
        ['user_id' => (int)$user['id']]
    );

    if ($actor) {
        $expected = activitypub_actor_urls((string)$user['username']);

        if (
            (string)$actor['actor_url'] !== $expected['actor']
            || (string)$actor['inbox_url'] !== $expected['inbox']
            || (string)$actor['outbox_url'] !== $expected['outbox']
            || (string)$actor['followers_url'] !== $expected['followers']
        ) {
            db_query(
                "UPDATE activitypub_actors
                 SET actor_url = :actor_url,
                     inbox_url = :inbox_url,
                     outbox_url = :outbox_url,
                     followers_url = :followers_url,
                     updated_at = :updated_at
                 WHERE id = :id",
                [
                    'actor_url' => $expected['actor'],
                    'inbox_url' => $expected['inbox'],
                    'outbox_url' => $expected['outbox'],
                    'followers_url' => $expected['followers'],
                    'updated_at' => now(),
                    'id' => (int)$actor['id'],
                ]
            );

            $actor = array_merge($actor, [
                'actor_url' => $expected['actor'],
                'inbox_url' => $expected['inbox'],
                'outbox_url' => $expected['outbox'],
                'followers_url' => $expected['followers'],
            ]);
        }

        return $actor;
    }

    if (!function_exists('openssl_pkey_new')) {
        throw new RuntimeException('L’extension PHP OpenSSL est requise pour générer les clés ActivityPub.');
    }

    $privateKeyResource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($privateKeyResource === false) {
        throw new RuntimeException('Impossible de générer une clé RSA ActivityPub.');
    }

    $privateKey = '';

    if (!openssl_pkey_export($privateKeyResource, $privateKey)) {
        throw new RuntimeException('Impossible d’exporter la clé privée ActivityPub.');
    }

    $details = openssl_pkey_get_details($privateKeyResource);

    if (!is_array($details) || empty($details['key'])) {
        throw new RuntimeException('Impossible d’extraire la clé publique ActivityPub.');
    }

    $urls = activitypub_actor_urls((string)$user['username']);

    $actorId = db_insert(
        "INSERT INTO activitypub_actors
            (user_id, actor_url, inbox_url, outbox_url, followers_url, public_key, private_key, created_at)
         VALUES
            (:user_id, :actor_url, :inbox_url, :outbox_url, :followers_url, :public_key, :private_key, :created_at)",
        [
            'user_id' => (int)$user['id'],
            'actor_url' => $urls['actor'],
            'inbox_url' => $urls['inbox'],
            'outbox_url' => $urls['outbox'],
            'followers_url' => $urls['followers'],
            'public_key' => (string)$details['key'],
            'private_key' => $privateKey,
            'created_at' => now(),
        ]
    );

    return db_fetch_one(
        "SELECT *
         FROM activitypub_actors
         WHERE id = :id
         LIMIT 1",
        ['id' => $actorId]
    ) ?? [];
}

function activitypub_actor_url(string $username): string
{
    return activitypub_actor_urls($username)['actor'];
}

function activitypub_outbox_url(string $username): string
{
    return activitypub_actor_urls($username)['outbox'];
}

function activitypub_actor_urls(string $username): array
{
    $encoded = rawurlencode($username);

    return [
        'actor' => activitypub_base_url() . '/ap_user.php?username=' . $encoded,
        'inbox' => activitypub_base_url() . '/ap_inbox.php?username=' . $encoded,
        'outbox' => activitypub_base_url() . '/ap_outbox.php?username=' . $encoded,
        'followers' => activitypub_base_url() . '/ap_followers.php?username=' . $encoded,
    ];
}

function activitypub_actor_for_username(string $username): ?array
{
    $user = activitypub_public_user_by_username($username);

    return $user ? activitypub_ensure_actor_keys($user) : null;
}

function activitypub_actor_for_user(array $user): array
{
    $actor = activitypub_ensure_actor_keys($user);
    $actorUrl = (string)$actor['actor_url'];
    $name = (string)($user['display_name'] ?: $user['username']);

    return [
        '@context' => [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
        ],
        'id' => $actorUrl,
        'type' => 'Person',
        'preferredUsername' => (string)$user['username'],
        'name' => $name,
        'summary' => activitypub_plain_html((string)($user['bio'] ?? '')),
        'inbox' => (string)$actor['inbox_url'],
        'outbox' => (string)$actor['outbox_url'],
        'followers' => (string)$actor['followers_url'],
        'url' => url('public.php'),
        'publicKey' => [
            'id' => $actorUrl . '#main-key',
            'owner' => $actorUrl,
            'publicKeyPem' => (string)$actor['public_key'],
        ],
    ];
}

function activitypub_plain_html(string $content): string
{
    $content = trim($content);

    if ($content === '') {
        return '';
    }

    $content = preg_replace('/^\s*\[[^\]]+\]:\s+\S+.*$/m', '', $content) ?? $content;
    $content = preg_replace('/^\*\s+/m', '- ', $content) ?? $content;
    $content = preg_replace('/\[([^\]]+)\]\[\d+\]/u', '$1', $content) ?? $content;

    return render_markdown($content);
}

function activitypub_post_object(array $post): array
{
    $username = (string)$post['username'];
    $actor = activitypub_actor_for_username($username);
    $actorUrl = $actor ? (string)$actor['actor_url'] : activitypub_actor_url($username);
    $objectUrl = activitypub_base_url() . '/ap_post.php?id=' . (int)$post['id'];
    $publicUrl = url('public.php') . '#post-' . (int)$post['id'];
    $content = nl2br(e((string)$post['content']));

    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $objectUrl,
        'type' => 'Note',
        'attributedTo' => $actorUrl,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [$actor ? (string)$actor['followers_url'] : activitypub_actor_urls($username)['followers']],
        'content' => $content,
        'published' => activitypub_iso_date((string)$post['created_at']),
        'updated' => !empty($post['updated_at']) ? activitypub_iso_date((string)$post['updated_at']) : null,
        'url' => $publicUrl,
    ];
}

function activitypub_article_object(array $article): array
{
    $username = (string)$article['username'];
    $actor = activitypub_actor_for_username($username);
    $actorUrl = $actor ? (string)$actor['actor_url'] : activitypub_actor_url($username);
    $objectUrl = activitypub_base_url() . '/ap_article.php?id=' . (int)$article['id'];
    $publicUrl = url('public_article.php?slug=' . rawurlencode((string)$article['slug']));

    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $objectUrl,
        'type' => 'Article',
        'attributedTo' => $actorUrl,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [$actor ? (string)$actor['followers_url'] : activitypub_actor_urls($username)['followers']],
        'name' => (string)$article['title'],
        'summary' => e(article_preview_text((string)($article['excerpt'] ?: $article['content']), 260)),
        'content' => activitypub_plain_html((string)$article['content']),
        'published' => activitypub_iso_date((string)($article['published_at'] ?: $article['created_at'])),
        'updated' => !empty($article['updated_at']) ? activitypub_iso_date((string)$article['updated_at']) : null,
        'url' => $publicUrl,
    ];
}

function activitypub_outbox(array $user, int $page = 0, int $perPage = 20): array
{
    $actor = activitypub_ensure_actor_keys($user);
    $page = max(0, $page);
    $perPage = max(1, min(50, $perPage));

    $totalPosts = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM posts
         WHERE author_id = :user_id
           AND is_published = 1
           AND visibility = 'public'",
        ['user_id' => (int)$user['id']]
    )['total'] ?? 0);

    $totalArticles = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM articles
         WHERE author_id = :user_id
           AND status = 'published'
           AND visibility = 'public'",
        ['user_id' => (int)$user['id']]
    )['total'] ?? 0);

    $totalItems = $totalPosts + $totalArticles;
    $outboxUrl = (string)$actor['outbox_url'];

    if ($page <= 0) {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $outboxUrl,
            'type' => 'OrderedCollection',
            'totalItems' => $totalItems,
            'first' => $outboxUrl . '&page=1',
        ];
    }

    $candidatesLimit = $perPage + (($page - 1) * $perPage);

    $posts = db_fetch_all(
        "SELECT posts.*, users.username, users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE posts.author_id = :user_id
           AND posts.is_published = 1
           AND posts.visibility = 'public'
         ORDER BY posts.created_at DESC
         LIMIT :limit",
        [
            'user_id' => (int)$user['id'],
            'limit' => $candidatesLimit,
        ]
    );

    $articles = db_fetch_all(
        "SELECT articles.*, users.username, users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE articles.author_id = :user_id
           AND articles.status = 'published'
           AND articles.visibility = 'public'
         ORDER BY articles.published_at DESC, articles.created_at DESC
         LIMIT :limit",
        [
            'user_id' => (int)$user['id'],
            'limit' => $candidatesLimit,
        ]
    );

    foreach ($posts as $post) {
        $items[] = [
            'type' => 'Create',
            'id' => activitypub_activity_id('post', (int)$post['id'], 'create'),
            'actor' => (string)$actor['actor_url'],
            'published' => activitypub_iso_date((string)$post['created_at']),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [(string)$actor['followers_url']],
            'object' => activitypub_post_object($post),
        ];
    }

    foreach ($articles as $article) {
        $items[] = [
            'type' => 'Create',
            'id' => activitypub_activity_id('article', (int)$article['id'], 'create'),
            'actor' => (string)$actor['actor_url'],
            'published' => activitypub_iso_date((string)($article['published_at'] ?: $article['created_at'])),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'cc' => [(string)$actor['followers_url']],
            'object' => activitypub_article_object($article),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string)($b['published'] ?? ''), (string)($a['published'] ?? ''));
    });

    $items = array_slice($items, ($page - 1) * $perPage, $perPage);
    $lastPage = max(1, (int)ceil($totalItems / $perPage));

    $response = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $outboxUrl . '&page=' . $page,
        'type' => 'OrderedCollectionPage',
        'partOf' => $outboxUrl,
        'totalItems' => $totalItems,
        'orderedItems' => $items,
    ];

    if ($page > 1) {
        $response['prev'] = $outboxUrl . '&page=' . ($page - 1);
    }

    if ($page < $lastPage) {
        $response['next'] = $outboxUrl . '&page=' . ($page + 1);
    }

    return $response;
}

function activitypub_iso_date(string $date): string
{
    $timestamp = strtotime($date);

    if ($timestamp === false) {
        $timestamp = time();
    }

    return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
}

function activitypub_public_post_by_id(int $id): ?array
{
    return db_fetch_one(
        "SELECT posts.*, users.username, users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE posts.id = :id
           AND posts.is_published = 1
           AND posts.visibility = 'public'
           AND users.is_active = 1
         LIMIT 1",
        ['id' => $id]
    );
}

function activitypub_public_article_by_id(int $id): ?array
{
    return db_fetch_one(
        "SELECT articles.*, users.username, users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE articles.id = :id
           AND articles.status = 'published'
           AND articles.visibility = 'public'
           AND users.is_active = 1
         LIMIT 1",
        ['id' => $id]
    );
}

function activitypub_followers_collection(array $user, int $page = 0, int $perPage = 50): array
{
    activitypub_create_table();

    $actor = activitypub_ensure_actor_keys($user);
    $page = max(0, $page);
    $perPage = max(1, min(100, $perPage));
    $total = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM activitypub_followers
         WHERE user_id = :user_id
           AND status = 'accepted'",
        ['user_id' => (int)$user['id']]
    )['total'] ?? 0);

    if ($page <= 0) {
        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => (string)$actor['followers_url'],
            'type' => 'OrderedCollection',
            'totalItems' => $total,
            'first' => (string)$actor['followers_url'] . '&page=1',
        ];
    }

    $followers = db_fetch_all(
        "SELECT remote.actor_url
         FROM activitypub_followers followers
         JOIN activitypub_remote_actors remote ON remote.id = followers.remote_actor_id
         WHERE followers.user_id = :user_id
           AND followers.status = 'accepted'
         ORDER BY followers.created_at DESC
         LIMIT :limit OFFSET :offset",
        [
            'user_id' => (int)$user['id'],
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]
    );

    $lastPage = max(1, (int)ceil($total / $perPage));
    $response = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => (string)$actor['followers_url'] . '&page=' . $page,
        'type' => 'OrderedCollectionPage',
        'partOf' => (string)$actor['followers_url'],
        'totalItems' => $total,
        'orderedItems' => array_map(static fn (array $row): string => (string)$row['actor_url'], $followers),
    ];

    if ($page > 1) {
        $response['prev'] = (string)$actor['followers_url'] . '&page=' . ($page - 1);
    }

    if ($page < $lastPage) {
        $response['next'] = (string)$actor['followers_url'] . '&page=' . ($page + 1);
    }

    return $response;
}

function activitypub_activity_id(string $type, int $id, string $activityType): string
{
    $objectPath = $type === 'article' ? 'ap_article.php' : 'ap_post.php';

    return activitypub_base_url() . '/' . $objectPath . '?id=' . $id . '#' . strtolower($activityType);
}

function activitypub_content_activity(string $type, array $content, string $activityType): array
{
    $username = (string)$content['username'];
    $actor = activitypub_actor_for_username($username);
    $actorUrl = $actor ? (string)$actor['actor_url'] : activitypub_actor_url($username);
    $followersUrl = $actor ? (string)$actor['followers_url'] : activitypub_actor_urls($username)['followers'];
    $object = $type === 'article' ? activitypub_article_object($content) : activitypub_post_object($content);
    $activityType = ucfirst(strtolower($activityType));

    if ($activityType === 'Delete') {
        $object = [
            'id' => (string)$object['id'],
            'type' => 'Tombstone',
        ];
    }

    $activityId = activitypub_activity_id($type, (int)$content['id'], $activityType);
    if ($activityType !== 'Create') {
        $activityId .= '-' . hash('sha256', $activityType . '|' . ((string)($content['updated_at'] ?? '')) . '|' . now());
    }

    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $activityId,
        'type' => $activityType,
        'actor' => $actorUrl,
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [$followersUrl],
        'object' => $object,
        'published' => activitypub_iso_date(now()),
    ];
}

function activitypub_is_public_post(?array $post): bool
{
    return $post !== null
        && (int)($post['is_published'] ?? 0) === 1
        && (string)($post['visibility'] ?? '') === 'public';
}

function activitypub_is_public_article(?array $article): bool
{
    return $article !== null
        && (string)($article['status'] ?? '') === 'published'
        && (string)($article['visibility'] ?? '') === 'public';
}

function activitypub_post_by_id_for_event(int $id): ?array
{
    return db_fetch_one(
        "SELECT posts.*, users.username, users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE posts.id = :id
         LIMIT 1",
        ['id' => $id]
    );
}

function activitypub_article_by_id_for_event(int $id): ?array
{
    return db_fetch_one(
        "SELECT articles.*, users.username, users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE articles.id = :id
         LIMIT 1",
        ['id' => $id]
    );
}

function activitypub_after_post_change(?array $before, int $postId): void
{
    $after = activitypub_post_by_id_for_event($postId);
    activitypub_after_content_change('post', $before, $after);
}

function activitypub_after_article_change(?array $before, int $articleId): void
{
    $after = activitypub_article_by_id_for_event($articleId);
    activitypub_after_content_change('article', $before, $after);
}

function activitypub_after_post_delete(?array $before): void
{
    if (activitypub_is_public_post($before)) {
        activitypub_deliver_content_activity('post', $before, 'Delete');
    }
}

function activitypub_after_article_delete(?array $before): void
{
    if (activitypub_is_public_article($before)) {
        activitypub_deliver_content_activity('article', $before, 'Delete');
    }
}

function activitypub_after_content_change(string $type, ?array $before, ?array $after): void
{
    $wasPublic = $type === 'article' ? activitypub_is_public_article($before) : activitypub_is_public_post($before);
    $isPublic = $type === 'article' ? activitypub_is_public_article($after) : activitypub_is_public_post($after);

    if (!$after && $wasPublic && $before) {
        activitypub_deliver_content_activity($type, $before, 'Delete');
        return;
    }

    if (!$after) {
        return;
    }

    if (!$wasPublic && $isPublic) {
        activitypub_deliver_content_activity($type, $after, 'Create');
        return;
    }

    if ($wasPublic && $isPublic) {
        activitypub_deliver_content_activity($type, $after, 'Update');
        return;
    }

    if ($wasPublic && !$isPublic && $before) {
        activitypub_deliver_content_activity($type, $before, 'Delete');
    }
}

function activitypub_deliver_content_activity(string $type, array $content, string $activityType): void
{
    try {
        activitypub_deliver_to_followers((int)$content['author_id'], activitypub_content_activity($type, $content, $activityType));
    } catch (Throwable $e) {
        error_log('ActivityPub delivery failed: ' . $e->getMessage());
    }
}

function activitypub_upsert_remote_actor(array $actor): array
{
    activitypub_create_table();

    $actorUrl = (string)($actor['id'] ?? '');
    $inboxUrl = (string)($actor['inbox'] ?? '');
    $endpoints = is_array($actor['endpoints'] ?? null) ? $actor['endpoints'] : [];
    $publicKey = is_array($actor['publicKey'] ?? null) ? $actor['publicKey'] : [];
    $publicKeyPem = (string)($publicKey['publicKeyPem'] ?? '');

    if ($actorUrl === '' || $inboxUrl === '' || $publicKeyPem === '') {
        throw new RuntimeException('Acteur ActivityPub distant incomplet.');
    }

    $existing = db_fetch_one(
        "SELECT *
         FROM activitypub_remote_actors
         WHERE actor_url = :actor_url
         LIMIT 1",
        ['actor_url' => $actorUrl]
    );

    $data = [
        'actor_url' => $actorUrl,
        'inbox_url' => $inboxUrl,
        'shared_inbox_url' => (string)($endpoints['sharedInbox'] ?? ''),
        'followers_url' => (string)($actor['followers'] ?? ''),
        'preferred_username' => (string)($actor['preferredUsername'] ?? ''),
        'public_key_id' => (string)($publicKey['id'] ?? ($actorUrl . '#main-key')),
        'public_key_pem' => $publicKeyPem,
        'raw_json' => json_encode($actor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => now(),
    ];

    if ($existing) {
        db_query(
            "UPDATE activitypub_remote_actors
             SET inbox_url = :inbox_url,
                 shared_inbox_url = :shared_inbox_url,
                 followers_url = :followers_url,
                 preferred_username = :preferred_username,
                 public_key_id = :public_key_id,
                 public_key_pem = :public_key_pem,
                 raw_json = :raw_json,
                 updated_at = :updated_at
             WHERE id = :id",
            [
                'inbox_url' => $data['inbox_url'],
                'shared_inbox_url' => $data['shared_inbox_url'],
                'followers_url' => $data['followers_url'],
                'preferred_username' => $data['preferred_username'],
                'public_key_id' => $data['public_key_id'],
                'public_key_pem' => $data['public_key_pem'],
                'raw_json' => $data['raw_json'],
                'updated_at' => $data['updated_at'],
                'id' => (int)$existing['id'],
            ]
        );

        return array_merge($existing, $data);
    }

    $id = db_insert(
        "INSERT INTO activitypub_remote_actors
            (actor_url, inbox_url, shared_inbox_url, followers_url, preferred_username, public_key_id, public_key_pem, raw_json, created_at, updated_at)
         VALUES
            (:actor_url, :inbox_url, :shared_inbox_url, :followers_url, :preferred_username, :public_key_id, :public_key_pem, :raw_json, :created_at, :updated_at)",
        array_merge($data, ['created_at' => now()])
    );

    return db_fetch_one("SELECT * FROM activitypub_remote_actors WHERE id = :id LIMIT 1", ['id' => $id]) ?? $data;
}

function activitypub_fetch_json(string $url): ?array
{
    if (!function_exists('curl_init') || !preg_match('#^https?://#i', $url)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Accept: application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams", application/ld+json',
            'User-Agent: Salle des profs ActivityPub',
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body) || $status < 200 || $status >= 300) {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

function activitypub_remote_actor_from_key_id(string $keyId): ?array
{
    activitypub_create_table();

    $existing = db_fetch_one(
        "SELECT *
         FROM activitypub_remote_actors
         WHERE public_key_id = :key_id OR actor_url = :actor_url
         LIMIT 1",
        [
            'key_id' => $keyId,
            'actor_url' => strtok($keyId, '#') ?: $keyId,
        ]
    );

    if ($existing) {
        return $existing;
    }

    $actorUrl = strtok($keyId, '#') ?: $keyId;
    $actor = activitypub_fetch_json($actorUrl);

    if (!$actor) {
        return null;
    }

    return activitypub_upsert_remote_actor($actor);
}

function activitypub_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }
    }

    $normalized = [];
    foreach ($headers as $key => $value) {
        $normalized[strtolower((string)$key)] = (string)$value;
    }

    if (!isset($normalized['signature']) && isset($_SERVER['HTTP_SIGNATURE'])) {
        $normalized['signature'] = (string)$_SERVER['HTTP_SIGNATURE'];
    }

    if (!isset($normalized['authorization']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $normalized['authorization'] = (string)$_SERVER['HTTP_AUTHORIZATION'];
    }

    return $normalized;
}

function activitypub_parse_signature_header(string $header): array
{
    $header = trim($header);
    if (str_starts_with(strtolower($header), 'signature ')) {
        $header = trim(substr($header, 10));
    }

    $parts = [];
    preg_match_all('/([a-zA-Z]+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $parts[strtolower($match[1])] = $match[2];
    }

    return $parts;
}

function activitypub_verify_http_signature(string $body): ?array
{
    $headers = activitypub_request_headers();
    $signatureHeader = $headers['signature'] ?? $headers['authorization'] ?? '';

    if ($signatureHeader === '') {
        return null;
    }

    $signature = activitypub_parse_signature_header($signatureHeader);
    $keyId = (string)($signature['keyid'] ?? '');
    $signatureValue = (string)($signature['signature'] ?? '');
    $signedHeaders = strtolower((string)($signature['headers'] ?? 'date'));

    if ($keyId === '' || $signatureValue === '') {
        return null;
    }

    if (isset($headers['digest'])) {
        $expectedDigest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        if (!hash_equals($expectedDigest, $headers['digest'])) {
            return null;
        }
    }

    $remote = activitypub_remote_actor_from_key_id($keyId);

    if (!$remote || empty($remote['public_key_pem'])) {
        return null;
    }

    $lines = [];
    foreach (preg_split('/\s+/', trim($signedHeaders)) ?: [] as $headerName) {
        if ($headerName === '(request-target)') {
            $path = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $lines[] = '(request-target): ' . strtolower((string)($_SERVER['REQUEST_METHOD'] ?? 'get')) . ' ' . $path;
            continue;
        }

        if (!isset($headers[$headerName])) {
            return null;
        }

        $lines[] = $headerName . ': ' . $headers[$headerName];
    }

    $verified = openssl_verify(
        implode("\n", $lines),
        base64_decode($signatureValue, true) ?: '',
        (string)$remote['public_key_pem'],
        OPENSSL_ALGO_SHA256
    );

    return $verified === 1 ? $remote : null;
}

function activitypub_handle_inbox(string $username): array
{
    activitypub_create_table();

    $user = activitypub_public_user_by_username($username);
    if (!$user) {
        return ['status' => 404, 'body' => ['error' => 'Utilisateur introuvable.']];
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return ['status' => 405, 'body' => ['error' => 'Méthode non autorisée.']];
    }

    $body = file_get_contents('php://input');
    $body = is_string($body) ? $body : '';
    $activity = json_decode($body, true);

    if (!is_array($activity)) {
        return ['status' => 400, 'body' => ['error' => 'JSON ActivityPub invalide.']];
    }

    $remote = activitypub_verify_http_signature($body);
    if (!$remote) {
        return ['status' => 401, 'body' => ['error' => 'Signature HTTP invalide ou absente.']];
    }

    $type = (string)($activity['type'] ?? '');
    $activityId = (string)($activity['id'] ?? (activitypub_base_url() . '/inbox#' . hash('sha256', $body)));
    db_insert_ignore('activitypub_inbox', [
        'user_id' => (int)$user['id'],
        'remote_actor_id' => (int)$remote['id'],
        'activity_id' => $activityId,
        'activity_type' => $type,
        'raw_json' => $body,
        'created_at' => now(),
    ]);

    $localActor = activitypub_ensure_actor_keys($user);
    $object = $activity['object'] ?? null;
    $objectId = is_array($object) ? (string)($object['id'] ?? '') : (string)$object;

    if ($type === 'Follow' && $objectId === (string)$localActor['actor_url']) {
        activitypub_accept_follow($user, $localActor, $remote, $activity);
        return ['status' => 202, 'body' => ['ok' => true]];
    }

    if ($type === 'Undo' && is_array($object) && (string)($object['type'] ?? '') === 'Follow') {
        db_query(
            "DELETE FROM activitypub_followers
             WHERE user_id = :user_id
               AND remote_actor_id = :remote_actor_id",
            [
                'user_id' => (int)$user['id'],
                'remote_actor_id' => (int)$remote['id'],
            ]
        );

        return ['status' => 202, 'body' => ['ok' => true]];
    }

    if ($type === 'Block') {
        db_insert_ignore('activitypub_blocks', [
            'user_id' => (int)$user['id'],
            'remote_actor_id' => (int)$remote['id'],
            'block_id' => $activityId,
            'created_at' => now(),
        ]);
        db_query(
            "DELETE FROM activitypub_followers
             WHERE user_id = :user_id
               AND remote_actor_id = :remote_actor_id",
            [
                'user_id' => (int)$user['id'],
                'remote_actor_id' => (int)$remote['id'],
            ]
        );

        return ['status' => 202, 'body' => ['ok' => true]];
    }

    if (in_array($type, ['Like', 'Announce', 'Accept', 'Create', 'Update', 'Delete'], true)) {
        return ['status' => 202, 'body' => ['ok' => true]];
    }

    return ['status' => 202, 'body' => ['ok' => true, 'ignored' => true]];
}

function activitypub_accept_follow(array $user, array $localActor, array $remote, array $follow): void
{
    db_insert_ignore('activitypub_followers', [
        'user_id' => (int)$user['id'],
        'remote_actor_id' => (int)$remote['id'],
        'follow_id' => (string)($follow['id'] ?? ''),
        'status' => 'accepted',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    db_query(
        "UPDATE activitypub_followers
         SET follow_id = :follow_id,
             status = 'accepted',
             updated_at = :updated_at
         WHERE user_id = :user_id
           AND remote_actor_id = :remote_actor_id",
        [
            'follow_id' => (string)($follow['id'] ?? ''),
            'updated_at' => now(),
            'user_id' => (int)$user['id'],
            'remote_actor_id' => (int)$remote['id'],
        ]
    );

    $accept = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => (string)$localActor['actor_url'] . '#accept-' . hash('sha256', (string)($follow['id'] ?? json_encode($follow))),
        'type' => 'Accept',
        'actor' => (string)$localActor['actor_url'],
        'object' => $follow,
    ];

    activitypub_send_activity((int)$user['id'], (int)$remote['id'], (string)$remote['inbox_url'], $accept);
}

function activitypub_deliver_to_followers(int $userId, array $activity): void
{
    activitypub_create_table();

    $followers = db_fetch_all(
        "SELECT remote.*
         FROM activitypub_followers followers
         JOIN activitypub_remote_actors remote ON remote.id = followers.remote_actor_id
         WHERE followers.user_id = :user_id
           AND followers.status = 'accepted'",
        ['user_id' => $userId]
    );

    foreach ($followers as $remote) {
        $inbox = (string)($remote['shared_inbox_url'] ?: $remote['inbox_url']);
        activitypub_send_activity($userId, (int)$remote['id'], $inbox, $activity);
    }
}

function activitypub_send_activity(int $userId, int $remoteActorId, string $inboxUrl, array $activity): void
{
    $json = json_encode($activity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    $deliveryId = db_insert(
        "INSERT INTO activitypub_deliveries
            (user_id, remote_actor_id, activity_id, inbox_url, activity_json, status, attempts, created_at, updated_at)
         VALUES
            (:user_id, :remote_actor_id, :activity_id, :inbox_url, :activity_json, 'pending', 0, :created_at, :updated_at)",
        [
            'user_id' => $userId,
            'remote_actor_id' => $remoteActorId,
            'activity_id' => (string)($activity['id'] ?? ''),
            'inbox_url' => $inboxUrl,
            'activity_json' => $json,
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    $result = activitypub_signed_post($userId, $inboxUrl, $json);

    db_query(
        "UPDATE activitypub_deliveries
         SET status = :status,
             attempts = attempts + 1,
             last_status_code = :last_status_code,
             last_error = :last_error,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'status' => $result['ok'] ? 'sent' : 'failed',
            'last_status_code' => $result['status'],
            'last_error' => $result['error'],
            'updated_at' => now(),
            'id' => $deliveryId,
        ]
    );
}

function activitypub_signed_post(int $userId, string $inboxUrl, string $body): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'Extension cURL absente.'];
    }

    $user = db_fetch_one("SELECT id, username FROM users WHERE id = :id LIMIT 1", ['id' => $userId]);
    if (!$user) {
        return ['ok' => false, 'status' => 0, 'error' => 'Utilisateur local introuvable.'];
    }

    $actor = activitypub_ensure_actor_keys($user);
    $parts = parse_url($inboxUrl);
    $host = (string)($parts['host'] ?? '');
    $path = (string)($parts['path'] ?? '/');
    if (!empty($parts['query'])) {
        $path .= '?' . $parts['query'];
    }

    if ($host === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Inbox distante invalide.'];
    }

    $date = gmdate(DATE_RFC7231);
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $signedString = implode("\n", [
        '(request-target): post ' . $path,
        'host: ' . $host,
        'date: ' . $date,
        'digest: ' . $digest,
    ]);

    $signature = '';
    $privateKey = openssl_pkey_get_private((string)$actor['private_key']);
    if (!$privateKey || !openssl_sign($signedString, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        return ['ok' => false, 'status' => 0, 'error' => 'Signature sortante impossible.'];
    }

    $signatureHeader = 'keyId="' . (string)$actor['actor_url'] . '#main-key",headers="(request-target) host date digest",algorithm="rsa-sha256",signature="' . base64_encode($signature) . '"';

    $ch = curl_init($inboxUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/activity+json',
            'Accept: application/activity+json',
            'Host: ' . $host,
            'Date: ' . $date,
            'Digest: ' . $digest,
            'Signature: ' . $signatureHeader,
            'User-Agent: Salle des profs ActivityPub',
        ],
    ]);

    curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error,
    ];
}
