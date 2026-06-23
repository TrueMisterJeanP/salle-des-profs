<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function posts_ensure_pinning_column(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    if (!db_column_exists('posts', 'pinned_at')) {
        db_query("ALTER TABLE posts ADD COLUMN pinned_at TEXT");
    }

    ensure_content_group_column('posts');

    $done = true;
}

function posts_ensure_comments_table(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
    db_query("CREATE INDEX IF NOT EXISTS idx_comments_target ON comments(target_type, target_id)");

    $done = true;
}

function posts_ensure_attachments_table(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS post_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            attachment_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(post_id, attachment_id),
            FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE,
            FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
        )"
    );
    db_query("CREATE INDEX IF NOT EXISTS idx_post_attachments_post ON post_attachments(post_id)");
    db_query("CREATE INDEX IF NOT EXISTS idx_post_attachments_attachment ON post_attachments(attachment_id)");

    $done = true;
}

function posts_ensure_poll_tables(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS post_polls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL UNIQUE,
            question TEXT NOT NULL,
            closes_at TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS post_poll_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            option_text VARCHAR(255) NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(poll_id) REFERENCES post_polls(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS post_poll_votes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            poll_id INTEGER NOT NULL,
            option_id INTEGER NOT NULL,
            user_id INTEGER,
            visitor_key VARCHAR(64),
            visitor_fingerprint VARCHAR(64),
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT,
            FOREIGN KEY(poll_id) REFERENCES post_polls(id) ON DELETE CASCADE,
            FOREIGN KEY(option_id) REFERENCES post_poll_options(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(poll_id, user_id),
            UNIQUE(poll_id, visitor_key),
            UNIQUE(poll_id, visitor_fingerprint)
        );

        CREATE INDEX IF NOT EXISTS idx_post_poll_options_poll_id ON post_poll_options(poll_id);
        CREATE INDEX IF NOT EXISTS idx_post_poll_votes_poll_id ON post_poll_votes(poll_id);
        CREATE INDEX IF NOT EXISTS idx_post_poll_votes_option_id ON post_poll_votes(option_id);"
    );

    if (!db_column_exists('post_polls', 'closes_at')) {
        db_query("ALTER TABLE post_polls ADD COLUMN closes_at TEXT");
    }

    if (!db_column_exists('post_poll_votes', 'visitor_fingerprint')) {
        db_query("ALTER TABLE post_poll_votes ADD COLUMN visitor_fingerprint VARCHAR(64)");
    }

    db_exec_schema(
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_post_poll_votes_fingerprint
         ON post_poll_votes(poll_id, visitor_fingerprint)"
    );

    $done = true;
}

function post_poll_parse_input(array &$errors): ?array
{
    $question = post_value('poll_question');
    $rawOptions = str_replace(["\r\n", "\r"], "\n", post_value('poll_options'));
    $durationDays = post_value('poll_duration_days', '0');
    $durationDaysValue = 0;
    $options = [];

    foreach (explode("\n", $rawOptions) as $option) {
        $option = trim($option);

        if ($option !== '' && !in_array($option, $options, true)) {
            $options[] = $option;
        }
    }

    if ($question === '' && !$options) {
        return null;
    }

    if ($question === '') {
        $errors[] = 'La question du vote est obligatoire.';
    }

    if (count($options) < 2) {
        $errors[] = 'Le vote doit proposer au moins deux réponses.';
    }

    if (count($options) > 10) {
        $errors[] = 'Le vote ne peut pas proposer plus de dix réponses.';
    }

    if (mb_strlen($question, 'UTF-8') > 240) {
        $errors[] = 'La question du vote ne peut pas dépasser 240 caractères.';
    }

    if ($durationDays !== '') {
        if (!ctype_digit($durationDays)) {
            $errors[] = 'La durée du vote doit être exprimée en nombre de jours.';
        } else {
            $durationDaysValue = (int)$durationDays;

            if ($durationDaysValue > 3650) {
                $errors[] = 'La durée du vote ne peut pas dépasser 3650 jours.';
            }
        }
    }

    foreach ($options as $option) {
        if (mb_strlen($option, 'UTF-8') > 120) {
            $errors[] = 'Chaque réponse du vote doit rester sous 120 caractères.';
            break;
        }
    }

    if ($errors) {
        return null;
    }

    return [
        'question' => $question,
        'options' => $options,
        'closes_at' => $durationDaysValue > 0 ? date('Y-m-d H:i:s', time() + $durationDaysValue * 86400) : null,
    ];
}

function post_poll_save_for_post(int $postId, ?array $poll): void
{
    posts_ensure_poll_tables();

    if ($poll === null) {
        db_query(
            "DELETE FROM post_polls
             WHERE post_id = :post_id",
            ['post_id' => $postId]
        );

        return;
    }

    $existingPoll = db_fetch_one(
        "SELECT id
         FROM post_polls
         WHERE post_id = :post_id
         LIMIT 1",
        ['post_id' => $postId]
    );

    if (!$existingPoll) {
        $pollId = db_insert(
            "INSERT INTO post_polls (post_id, question, closes_at, is_active, created_at)
             VALUES (:post_id, :question, :closes_at, 1, :created_at)",
            [
                'post_id' => $postId,
                'question' => $poll['question'],
                'closes_at' => $poll['closes_at'],
                'created_at' => now(),
            ]
        );

        foreach ($poll['options'] as $position => $option) {
            db_insert(
                "INSERT INTO post_poll_options (poll_id, option_text, position, created_at)
                 VALUES (:poll_id, :option_text, :position, :created_at)",
                [
                    'poll_id' => $pollId,
                    'option_text' => $option,
                    'position' => $position,
                    'created_at' => now(),
                ]
            );
        }

        return;
    }

    $pollId = (int)$existingPoll['id'];

    db_query(
        "UPDATE post_polls
         SET question = :question,
             closes_at = :closes_at,
             is_active = 1,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'id' => $pollId,
            'question' => $poll['question'],
            'closes_at' => $poll['closes_at'],
            'updated_at' => now(),
        ]
    );

    $existingOptions = db_fetch_all(
        "SELECT id, option_text
         FROM post_poll_options
         WHERE poll_id = :poll_id
         ORDER BY position ASC, id ASC",
        ['poll_id' => $pollId]
    );
    $optionsByText = [];

    foreach ($existingOptions as $option) {
        $optionsByText[(string)$option['option_text']] = (int)$option['id'];
    }

    $keptOptionIds = [];

    foreach ($poll['options'] as $position => $option) {
        $optionId = $optionsByText[$option] ?? null;

        if ($optionId !== null) {
            db_query(
                "UPDATE post_poll_options
                 SET position = :position
                 WHERE id = :id
                   AND poll_id = :poll_id",
                [
                    'id' => $optionId,
                    'poll_id' => $pollId,
                    'position' => $position,
                ]
            );
            $keptOptionIds[] = $optionId;
            continue;
        }

        $keptOptionIds[] = db_insert(
            "INSERT INTO post_poll_options (poll_id, option_text, position, created_at)
             VALUES (:poll_id, :option_text, :position, :created_at)",
            [
                'poll_id' => $pollId,
                'option_text' => $option,
                'position' => $position,
                'created_at' => now(),
            ]
        );
    }

    foreach ($existingOptions as $option) {
        $optionId = (int)$option['id'];

        if (in_array($optionId, $keptOptionIds, true)) {
            continue;
        }

        db_query(
            "DELETE FROM post_poll_options
             WHERE id = :id
               AND poll_id = :poll_id",
            [
                'id' => $optionId,
                'poll_id' => $pollId,
            ]
        );
    }
}

function post_poll_reset_votes_for_post(int $postId): void
{
    posts_ensure_poll_tables();

    $poll = db_fetch_one(
        "SELECT id
         FROM post_polls
         WHERE post_id = :post_id
         LIMIT 1",
        ['post_id' => $postId]
    );

    if (!$poll) {
        throw new RuntimeException('Aucun vote à remettre à zéro.');
    }

    db_query(
        "DELETE FROM post_poll_votes
         WHERE poll_id = :poll_id",
        ['poll_id' => (int)$poll['id']]
    );
}

function post_poll_for_post(int $postId): ?array
{
    posts_ensure_poll_tables();

    $poll = db_fetch_one(
        "SELECT *
         FROM post_polls
         WHERE post_id = :post_id
         LIMIT 1",
        ['post_id' => $postId]
    );

    if (!$poll) {
        return null;
    }

    $poll['options'] = db_fetch_all(
        "SELECT *
         FROM post_poll_options
         WHERE poll_id = :poll_id
         ORDER BY position ASC, id ASC",
        ['poll_id' => (int)$poll['id']]
    );

    return $poll;
}

function post_poll_options_text(?array $poll): string
{
    if (!$poll || empty($poll['options'])) {
        return '';
    }

    return implode("\n", array_map(
        static fn (array $option): string => (string)$option['option_text'],
        $poll['options']
    ));
}

function post_poll_duration_days_value(?array $poll): string
{
    if (!$poll || empty($poll['closes_at'])) {
        return '';
    }

    $closesAt = strtotime((string)$poll['closes_at']);

    if ($closesAt === false) {
        return '';
    }

    return (string)max(0, (int)ceil(($closesAt - time()) / 86400));
}

function post_poll_is_closed(array $poll): bool
{
    if (empty($poll['closes_at'])) {
        return false;
    }

    $closesAt = strtotime((string)$poll['closes_at']);

    return $closesAt !== false && $closesAt <= time();
}

function post_poll_existing_visitor_key(): ?string
{
    $key = (string)($_COOKIE['post_poll_visitor'] ?? '');

    return preg_match('/^[a-f0-9]{64}$/', $key) === 1 ? $key : null;
}

function post_poll_visitor_key(): string
{
    $key = post_poll_existing_visitor_key();

    if ($key !== null) {
        return $key;
    }

    $key = bin2hex(random_bytes(32));
    $_COOKIE['post_poll_visitor'] = $key;

    setcookie('post_poll_visitor', $key, [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $key;
}

function post_poll_visitor_fingerprint(): string
{
    $ipAddress = function_exists('activity_log_ip_address')
        ? activity_log_ip_address()
        : (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $acceptLanguage = substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 255);

    return hash('sha256', $ipAddress . '|' . $userAgent . '|' . $acceptLanguage);
}

function post_poll_submit_vote(int $postId, int $optionId, ?int $userId, string $scope): void
{
    posts_ensure_poll_tables();

    $params = [
        'post_id' => $postId,
        'option_id' => $optionId,
    ];

    $visibilitySql = match ($scope) {
        'public' => "1 = 0",
        'members' => "(posts.visibility = 'members'
            OR (
                posts.visibility = 'group'
                AND EXISTS (
                    SELECT 1
                    FROM group_members gm
                    WHERE gm.group_id = posts.group_id
                      AND gm.user_id = :current_user_id
                )
            )
            OR posts.author_id = :current_user_id)",
        default => "(posts.visibility = 'members'
            OR (
                posts.visibility = 'group'
                AND EXISTS (
                    SELECT 1
                    FROM group_members gm
                    WHERE gm.group_id = posts.group_id
                      AND gm.user_id = :current_user_id
                )
            )
            OR posts.author_id = :current_user_id)",
    };

    if ($scope !== 'public') {
        $params['current_user_id'] = (int)$userId;
    }

    $poll = db_fetch_one(
        "SELECT post_polls.id AS poll_id,
                post_polls.closes_at,
                post_poll_options.id AS option_id
         FROM post_poll_options
         JOIN post_polls ON post_polls.id = post_poll_options.poll_id
         JOIN posts ON posts.id = post_polls.post_id
         WHERE posts.id = :post_id
           AND post_poll_options.id = :option_id
           AND posts.is_published = 1
           AND post_polls.is_active = 1
           AND $visibilitySql
         LIMIT 1",
        $params
    );

    if (!$poll) {
        throw new RuntimeException('Vote introuvable ou inaccessible.');
    }

    if (post_poll_is_closed($poll)) {
        throw new RuntimeException('Le vote est clôturé.');
    }

    $pollId = (int)$poll['poll_id'];
    $visitorFingerprint = post_poll_visitor_fingerprint();

    if ($userId !== null) {
        $existing = db_fetch_one(
            "SELECT id
             FROM post_poll_votes
             WHERE poll_id = :poll_id
               AND (
                    user_id = :user_id
                    OR (user_id IS NULL AND visitor_fingerprint = :visitor_fingerprint)
               )
             LIMIT 1",
            [
                'poll_id' => $pollId,
                'user_id' => $userId,
                'visitor_fingerprint' => $visitorFingerprint,
            ]
        );

        if ($existing) {
            db_query(
                "UPDATE post_poll_votes
                 SET option_id = :option_id,
                     user_id = :user_id,
                     visitor_fingerprint = :visitor_fingerprint,
                     updated_at = :updated_at
                 WHERE id = :id",
                [
                    'option_id' => $optionId,
                    'user_id' => $userId,
                    'visitor_fingerprint' => $visitorFingerprint,
                    'updated_at' => now(),
                    'id' => (int)$existing['id'],
                ]
            );
            return;
        }

        db_insert(
            "INSERT INTO post_poll_votes (poll_id, option_id, user_id, visitor_fingerprint, created_at)
             VALUES (:poll_id, :option_id, :user_id, :visitor_fingerprint, :created_at)",
            [
                'poll_id' => $pollId,
                'option_id' => $optionId,
                'user_id' => $userId,
                'visitor_fingerprint' => $visitorFingerprint,
                'created_at' => now(),
            ]
        );
        return;
    }

    $visitorKey = post_poll_visitor_key();
    $existing = db_fetch_one(
        "SELECT id
         FROM post_poll_votes
         WHERE poll_id = :poll_id
           AND (visitor_key = :visitor_key OR visitor_fingerprint = :visitor_fingerprint)
         LIMIT 1",
        [
            'poll_id' => $pollId,
            'visitor_key' => $visitorKey,
            'visitor_fingerprint' => $visitorFingerprint,
        ]
    );

    if ($existing) {
        db_query(
            "UPDATE post_poll_votes
             SET option_id = :option_id,
                 visitor_key = :visitor_key,
                 visitor_fingerprint = :visitor_fingerprint,
                 updated_at = :updated_at
             WHERE id = :id",
            [
                'option_id' => $optionId,
                'visitor_key' => $visitorKey,
                'visitor_fingerprint' => $visitorFingerprint,
                'updated_at' => now(),
                'id' => (int)$existing['id'],
            ]
        );
        return;
    }

    db_insert(
        "INSERT INTO post_poll_votes (poll_id, option_id, visitor_key, visitor_fingerprint, created_at)
         VALUES (:poll_id, :option_id, :visitor_key, :visitor_fingerprint, :created_at)",
        [
            'poll_id' => $pollId,
            'option_id' => $optionId,
            'visitor_key' => $visitorKey,
            'visitor_fingerprint' => $visitorFingerprint,
            'created_at' => now(),
        ]
    );
}

function posts_attach_polls(array $posts, ?int $userId = null): array
{
    posts_ensure_poll_tables();

    if (!$posts) {
        return $posts;
    }

    $postIds = array_values(array_unique(array_map(
        static fn (array $post): int => (int)$post['id'],
        $posts
    )));

    $placeholders = [];
    $params = [];

    foreach ($postIds as $index => $postId) {
        $key = 'post_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $postId;
    }

    $polls = db_fetch_all(
        "SELECT *
         FROM post_polls
         WHERE post_id IN (" . implode(', ', $placeholders) . ")
         ORDER BY id ASC",
        $params
    );

    if (!$polls) {
        return $posts;
    }

    $pollIds = [];
    $pollsByPost = [];

    foreach ($polls as $poll) {
        $poll['options'] = [];
        $poll['total_votes'] = 0;
        $poll['current_option_id'] = null;
        $pollsByPost[(int)$poll['post_id']] = $poll;
        $pollIds[] = (int)$poll['id'];
    }

    $pollPlaceholders = [];
    $pollParams = [];

    foreach ($pollIds as $index => $pollId) {
        $key = 'poll_id_' . $index;
        $pollPlaceholders[] = ':' . $key;
        $pollParams[$key] = $pollId;
    }

    $options = db_fetch_all(
        "SELECT *
         FROM post_poll_options
         WHERE poll_id IN (" . implode(', ', $pollPlaceholders) . ")
         ORDER BY position ASC, id ASC",
        $pollParams
    );

    $counts = db_fetch_all(
        "SELECT poll_id, option_id, COUNT(*) AS total
         FROM post_poll_votes
         WHERE poll_id IN (" . implode(', ', $pollPlaceholders) . ")
         GROUP BY poll_id, option_id",
        $pollParams
    );

    $countsByOption = [];
    foreach ($counts as $count) {
        $countsByOption[(int)$count['option_id']] = (int)$count['total'];
    }

    $currentVotes = [];
    $visitorKey = $userId === null ? post_poll_existing_visitor_key() : null;
    $visitorFingerprint = post_poll_visitor_fingerprint();

    if ($userId !== null) {
        $currentVotes = db_fetch_all(
            "SELECT poll_id, option_id
             FROM post_poll_votes
             WHERE (
                    user_id = :user_id
                    OR (user_id IS NULL AND visitor_fingerprint = :visitor_fingerprint)
             )
               AND poll_id IN (" . implode(', ', $pollPlaceholders) . ")",
            array_merge($pollParams, [
                'user_id' => $userId,
                'visitor_fingerprint' => $visitorFingerprint,
            ])
        );
    } elseif ($visitorKey !== null || $visitorFingerprint !== null) {
        $currentVotes = db_fetch_all(
            "SELECT poll_id, option_id
             FROM post_poll_votes
             WHERE (visitor_key = :visitor_key OR visitor_fingerprint = :visitor_fingerprint)
               AND poll_id IN (" . implode(', ', $pollPlaceholders) . ")",
            array_merge($pollParams, [
                'visitor_key' => $visitorKey,
                'visitor_fingerprint' => $visitorFingerprint,
            ])
        );
    }

    $currentByPoll = [];
    foreach ($currentVotes as $vote) {
        $currentByPoll[(int)$vote['poll_id']] = (int)$vote['option_id'];
    }

    foreach ($options as $option) {
        $pollId = (int)$option['poll_id'];
        $option['votes'] = $countsByOption[(int)$option['id']] ?? 0;

        foreach ($pollsByPost as $postId => $poll) {
            if ((int)$poll['id'] === $pollId) {
                $pollsByPost[$postId]['options'][] = $option;
                $pollsByPost[$postId]['total_votes'] += (int)$option['votes'];
                break;
            }
        }
    }

    foreach ($pollsByPost as $postId => $poll) {
        $pollsByPost[$postId]['current_option_id'] = $currentByPoll[(int)$poll['id']] ?? null;
    }

    foreach ($posts as $index => $post) {
        $posts[$index]['poll'] = $pollsByPost[(int)$post['id']] ?? null;
    }

    return $posts;
}

function post_user_can_view(int $postId, int $userId): bool
{
    $post = db_fetch_one(
        "SELECT id
         FROM posts
         WHERE posts.id = :post_id
           AND posts.is_published = 1
           AND (
                posts.visibility IN ('public', 'members')
                OR posts.author_id = :current_user_id
                OR (
                    posts.visibility = 'group'
                    AND EXISTS (
                        SELECT 1
                        FROM group_members gm
                        WHERE gm.group_id = posts.group_id
                          AND gm.user_id = :current_user_id
                    )
                )
           )
         LIMIT 1",
        [
            'post_id' => $postId,
            'current_user_id' => $userId,
        ]
    );

    return (bool)$post;
}

function post_comment_create(int $postId, int $userId, string $content): void
{
    posts_ensure_comments_table();

    $content = trim($content);

    if ($content === '') {
        throw new RuntimeException('Le commentaire ne peut pas être vide.');
    }

    if (!post_user_can_view($postId, $userId)) {
        throw new RuntimeException('Annonce introuvable ou inaccessible.');
    }

    db_insert(
        "INSERT INTO comments (user_id, target_type, target_id, content, created_at)
         VALUES (:user_id, 'post', :target_id, :content, :created_at)",
        [
            'user_id' => $userId,
            'target_id' => $postId,
            'content' => $content,
            'created_at' => now(),
        ]
    );
}

function posts_attach_comments(array $posts): array
{
    posts_ensure_comments_table();

    if (!$posts) {
        return $posts;
    }

    $postIds = array_values(array_unique(array_map(
        static fn (array $post): int => (int)$post['id'],
        $posts
    )));

    if (!$postIds) {
        return $posts;
    }

    $placeholders = [];
    $params = [];

    foreach ($postIds as $index => $postId) {
        $key = 'post_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $postId;
    }

    $comments = db_fetch_all(
        "SELECT comments.*, users.username, users.display_name
         FROM comments
         JOIN users ON users.id = comments.user_id
         WHERE comments.target_type = 'post'
           AND comments.target_id IN (" . implode(', ', $placeholders) . ")
           AND comments.is_deleted = 0
         ORDER BY comments.created_at ASC, comments.id ASC",
        $params
    );
    $commentsByPost = [];

    foreach ($comments as $comment) {
        $commentsByPost[(int)$comment['target_id']][] = $comment;
    }

    foreach ($posts as $index => $post) {
        $posts[$index]['comments'] = $commentsByPost[(int)$post['id']] ?? [];
    }

    return $posts;
}

function post_user_owns_attachment(int $attachmentId, int $userId): bool
{
    if ($attachmentId <= 0) {
        return true;
    }

    return (bool)db_fetch_one(
        "SELECT id
         FROM attachments
         WHERE id = :id
           AND user_id = :user_id
         LIMIT 1",
        [
            'id' => $attachmentId,
            'user_id' => $userId,
        ]
    );
}

function sync_post_attachment(int $postId, int $attachmentId, int $userId): void
{
    posts_ensure_attachments_table();

    db_query(
        "DELETE FROM post_attachments WHERE post_id = :post_id",
        ['post_id' => $postId]
    );

    if ($attachmentId <= 0) {
        return;
    }

    if (!post_user_owns_attachment($attachmentId, $userId)) {
        throw new RuntimeException('Fichier invalide.');
    }

    db_insert_ignore('post_attachments', [
        'post_id' => $postId,
        'attachment_id' => $attachmentId,
        'created_at' => now(),
    ]);
}

function post_attachment_id(int $postId): int
{
    posts_ensure_attachments_table();

    $row = db_fetch_one(
        "SELECT attachment_id
         FROM post_attachments
         WHERE post_id = :post_id
         ORDER BY created_at ASC, id ASC
         LIMIT 1",
        ['post_id' => $postId]
    );

    return (int)($row['attachment_id'] ?? 0);
}

function post_legacy_attachment_ids_from_content(?string $content): array
{
    $content = (string)$content;

    if ($content === '') {
        return [];
    }

    preg_match_all('/file\.php\?id=(\d+)/', $content, $matches);

    if (empty($matches[1])) {
        return [];
    }

    return array_values(array_unique(array_map('intval', $matches[1])));
}

function posts_backfill_legacy_markdown_attachments(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    posts_ensure_attachments_table();

    $posts = db_fetch_all(
        "SELECT id, author_id, content
         FROM posts
         WHERE content LIKE :needle",
        ['needle' => '%file.php?id=%']
    );

    foreach ($posts as $post) {
        $postId = (int)$post['id'];
        $authorId = (int)$post['author_id'];

        foreach (post_legacy_attachment_ids_from_content($post['content'] ?? '') as $attachmentId) {
            if (!post_user_owns_attachment($attachmentId, $authorId)) {
                continue;
            }

            db_insert_ignore('post_attachments', [
                'post_id' => $postId,
                'attachment_id' => $attachmentId,
                'created_at' => now(),
            ]);
        }
    }

    $done = true;
}

function posts_attach_attachments(array $posts): array
{
    if (!$posts) {
        return $posts;
    }

    posts_ensure_attachments_table();
    posts_backfill_legacy_markdown_attachments();

    $placeholders = [];
    $params = [];

    foreach ($posts as $index => $post) {
        $key = 'post_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = (int)$post['id'];
    }

    $attachments = db_fetch_all(
        "SELECT post_attachments.post_id,
                attachments.id,
                attachments.original_name,
                attachments.mime_type,
                attachments.size
         FROM post_attachments
         JOIN attachments ON attachments.id = post_attachments.attachment_id
         WHERE post_attachments.post_id IN (" . implode(', ', $placeholders) . ")
         ORDER BY post_attachments.created_at ASC, post_attachments.id ASC",
        $params
    );
    $attachmentsByPost = [];

    foreach ($attachments as $attachment) {
        $attachmentsByPost[(int)$attachment['post_id']][] = $attachment;
    }

    $legacyAttachmentIds = [];
    $legacyAttachmentIdsByPost = [];

    foreach ($posts as $post) {
        $postId = (int)$post['id'];

        if (!empty($attachmentsByPost[$postId])) {
            continue;
        }

        $ids = post_legacy_attachment_ids_from_content($post['content'] ?? '');

        if (!$ids) {
            continue;
        }

        $legacyAttachmentIdsByPost[$postId] = $ids;

        foreach ($ids as $attachmentId) {
            $legacyAttachmentIds[] = $attachmentId;
        }
    }

    $legacyAttachmentIds = array_values(array_unique($legacyAttachmentIds));

    if ($legacyAttachmentIds) {
        $legacyPlaceholders = [];
        $legacyParams = [];

        foreach ($legacyAttachmentIds as $index => $attachmentId) {
            $key = 'legacy_attachment_id_' . $index;
            $legacyPlaceholders[] = ':' . $key;
            $legacyParams[$key] = $attachmentId;
        }

        $legacyAttachments = db_fetch_all(
            "SELECT id, original_name, mime_type, size
             FROM attachments
             WHERE id IN (" . implode(', ', $legacyPlaceholders) . ")",
            $legacyParams
        );
        $legacyAttachmentsById = [];

        foreach ($legacyAttachments as $attachment) {
            $legacyAttachmentsById[(int)$attachment['id']] = $attachment;
        }

        foreach ($legacyAttachmentIdsByPost as $postId => $ids) {
            foreach ($ids as $attachmentId) {
                if (!isset($legacyAttachmentsById[$attachmentId])) {
                    continue;
                }

                $attachmentsByPost[$postId][] = $legacyAttachmentsById[$attachmentId];
            }
        }
    }

    foreach ($posts as $index => $post) {
        $posts[$index]['attachments'] = $attachmentsByPost[(int)$post['id']] ?? [];
    }

    return $posts;
}

function render_post_attachments(array $post): string
{
    $attachments = $post['attachments'] ?? [];

    if (!$attachments) {
        return '';
    }

    ob_start();
    ?>
    <div class="post-attachments" aria-label="Fichiers joints a l’annonce">
        <?php foreach ($attachments as $attachment): ?>
            <?php
            $attachmentId = (int)$attachment['id'];
            $label = (string)($attachment['original_name'] ?: 'Fichier joint');
            $href = url('file.php?id=' . $attachmentId . '&download=1');
            $meta = trim((string)$attachment['mime_type']);

            if (!empty($attachment['size'])) {
                $meta .= ($meta !== '' ? ' · ' : '') . human_file_size((int)$attachment['size']);
            }
            ?>
            <a
                class="post-attachment-link"
                href="<?= e($href) ?>"
                target="_blank"
                rel="noopener noreferrer"
                title="<?= e('Ouvrir ' . $label) ?>"
            >
                <span class="post-attachment-icon" aria-hidden="true">&#128206;</span>
                <span class="post-attachment-copy">
                    <span class="post-attachment-name"><?= e($label) ?></span>
                    <?php if ($meta !== ''): ?>
                        <span class="post-attachment-meta"><?= e($meta) ?></span>
                    <?php endif; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function render_post_comments(array $post, string $actionUrl, string $actionsBeforeHtml = '', string $actionsAfterHtml = ''): string
{
    $comments = $post['comments'] ?? [];
    $postId = (int)$post['id'];
    $formId = 'post-comment-form-' . $postId;
    $textareaId = 'post-comment-' . $postId;

    ob_start();
    ?>
    <section class="post-comments" aria-label="Commentaires de l’annonce">
        <h3>Commentaires</h3>

        <?php if (!$comments): ?>
            <p class="muted">Aucun commentaire pour l’instant.</p>
        <?php else: ?>
            <div class="comments-list post-comments-list">
                <?php foreach ($comments as $comment): ?>
                    <article class="comment">
                        <p><?= nl2br(e((string)$comment['content'])) ?></p>
                        <p class="meta">
                            <?= e((string)($comment['display_name'] ?: $comment['username'])) ?>
                            · <?= e((string)$comment['created_at']) ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form id="<?= e($formId) ?>" class="post-comment-form" method="post" action="<?= e($actionUrl) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="post_comment">
            <input type="hidden" name="post_id" value="<?= e((string)$postId) ?>">
            <label for="<?= e($textareaId) ?>">Ajouter un commentaire</label>
            <textarea id="<?= e($textareaId) ?>" name="comment_content" required placeholder="Répondre à cette annonce..."></textarea>
        </form>
        <div class="form-actions post-feed-actions">
            <?= $actionsBeforeHtml ?>
            <button type="submit" class="button-secondary post-action-comment" form="<?= e($formId) ?>">Commenter</button>
            <?= $actionsAfterHtml ?>
        </div>
    </section>
    <?php
    return (string)ob_get_clean();
}

function render_post_poll(array $post): string
{
    $poll = $post['poll'] ?? null;

    if (!$poll || empty($poll['options'])) {
        return '';
    }

    $totalVotes = (int)($poll['total_votes'] ?? 0);
    $currentOptionId = isset($poll['current_option_id']) ? (int)$poll['current_option_id'] : null;
    $isClosed = post_poll_is_closed($poll);
    $action = (string)($_SERVER['REQUEST_URI'] ?? '');

    ob_start();
    ?>
    <form class="post-poll" method="post" action="<?= e($action) ?>#post-<?= e((string)$post['id']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="poll_vote">
        <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">

        <fieldset>
            <legend><?= e((string)$poll['question']) ?></legend>

            <div class="post-poll-options">
                <?php foreach ($poll['options'] as $option): ?>
                    <?php
                    $optionId = (int)$option['id'];
                    $votes = (int)($option['votes'] ?? 0);
                    $percent = $totalVotes > 0 ? round(($votes / $totalVotes) * 100) : 0;
                    ?>
                    <label class="post-poll-option <?= $currentOptionId === $optionId ? 'selected' : '' ?>">
                        <input
                            type="radio"
                            name="option_id"
                            value="<?= e((string)$optionId) ?>"
                            <?= $currentOptionId === $optionId ? 'checked' : '' ?>
                            <?= $isClosed ? 'disabled' : '' ?>
                            required
                        >
                        <span class="post-poll-option-main">
                            <span class="post-poll-option-text"><?= e((string)$option['option_text']) ?></span>
                            <span class="post-poll-option-score"><?= e((string)$percent) ?>%</span>
                        </span>
                        <span class="post-poll-bar" aria-hidden="true">
                            <span style="width: <?= e((string)$percent) ?>%;"></span>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="post-poll-footer">
            <span class="meta">
                <?= e((string)$totalVotes) ?> réponse<?= $totalVotes > 1 ? 's' : '' ?>
                <?php if ($isClosed): ?>
                    · Vote clôturé
                <?php elseif (!empty($poll['closes_at'])): ?>
                    · Ouvert jusqu’au <?= e((string)$poll['closes_at']) ?>
                <?php endif; ?>
            </span>
            <?php if (!$isClosed): ?>
                <button type="submit" class="button-secondary"><?= $currentOptionId ? 'Modifier mon vote' : 'Voter' ?></button>
            <?php endif; ?>
        </div>
    </form>
    <?php
    return (string)ob_get_clean();
}

function post_pin(int $postId): void
{
    posts_ensure_pinning_column();

    $post = db_fetch_one(
        "SELECT id
         FROM posts
         WHERE id = :id
           AND visibility IN ('members', 'group')
           AND is_published = 1
         LIMIT 1",
        ['id' => $postId]
    );

    if (!$post) {
        throw new RuntimeException('Seule une publication visible aux membres ou à un groupe peut être épinglée.');
    }

    db()->beginTransaction();

    try {
        db_query("UPDATE posts SET pinned_at = NULL WHERE pinned_at IS NOT NULL");
        db_query(
            "UPDATE posts
             SET pinned_at = :pinned_at,
                 updated_at = :updated_at
             WHERE id = :id",
            [
                'pinned_at' => now(),
                'updated_at' => now(),
                'id' => $postId,
            ]
        );

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }

        throw $e;
    }
}

function post_unpin(int $postId): void
{
    posts_ensure_pinning_column();

    db_query(
        "UPDATE posts
         SET pinned_at = NULL,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'updated_at' => now(),
            'id' => $postId,
        ]
    );
}
