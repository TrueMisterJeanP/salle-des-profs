<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

function article_extract_tags(string $title, string $content, int $limit = 10): array
{
    return content_extract_tags($title, $content, $limit);
}

function content_extract_tags(string $title, string $content, int $limit = 10): array
{
    $weightedText = trim($title . ' ' . $title . ' ' . $title . ' ' . $content);
    $weightedText = article_plain_text_for_tags($weightedText);

    if ($weightedText === '') {
        return [];
    }

    $words = preg_split('/\s+/u', $weightedText) ?: [];
    $scores = [];
    $stopWords = article_tag_stop_words();

    foreach ($words as $word) {
        $word = article_normalize_tag($word);

        if ($word === '' || isset($stopWords[$word])) {
            continue;
        }

        if (mb_strlen($word, 'UTF-8') < 3 || preg_match('/^\d+$/', $word)) {
            continue;
        }

        $scores[$word] = ($scores[$word] ?? 0) + 1;
    }

    arsort($scores);

    return array_slice(array_keys($scores), 0, $limit);
}

function article_sync_tags(int $articleId, string $title, string $content, int $limit = 10): void
{
    if ($articleId <= 0) {
        return;
    }

    content_sync_tags('article', $articleId, article_extract_tags($title, $content, $limit));
}

function content_sync_tags(string $targetType, int $targetId, array $tagNames): void
{
    if (!in_array($targetType, ['article', 'post'], true) || $targetId <= 0) {
        return;
    }

    article_ensure_tag_tables();

    db_query(
        "DELETE FROM content_tags
         WHERE target_type = :target_type
           AND target_id = :target_id",
        [
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]
    );

    foreach (array_slice(array_values(array_unique($tagNames)), 0, 10) as $tagName) {
        db_insert_ignore('tags', [
            'name' => $tagName,
            'created_at' => now(),
        ]);

        $tag = db_fetch_one(
            "SELECT id FROM tags WHERE name = :name LIMIT 1",
            ['name' => $tagName]
        );

        if (!$tag) {
            continue;
        }

        db_insert_ignore('content_tags', [
            'tag_id' => (int)$tag['id'],
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]);
    }
}

function post_sync_tags(int $postId, string $content, int $limit = 10): void
{
    if ($postId <= 0) {
        return;
    }

    content_sync_tags('post', $postId, content_extract_tags('', $content, $limit));
}

function article_delete_tags(int $articleId): void
{
    content_delete_tags('article', $articleId);
}

function content_delete_tags(string $targetType, int $targetId): void
{
    article_ensure_tag_tables();

    db_query(
        "DELETE FROM content_tags
         WHERE target_type = :target_type
           AND target_id = :target_id",
        [
            'target_type' => $targetType,
            'target_id' => $targetId,
        ]
    );
}

function article_tag_cloud(int $limit = 50): array
{
    article_ensure_tag_tables();

    return db_fetch_all(
        "SELECT tags.name, COUNT(*) AS total
         FROM tags
         JOIN content_tags ON content_tags.tag_id = tags.id
         JOIN articles ON articles.id = content_tags.target_id
         WHERE content_tags.target_type = 'article'
           AND articles.status = 'published'
           AND articles.visibility = 'public'
         GROUP BY tags.id, tags.name
         ORDER BY total DESC, tags.name ASC
         LIMIT :limit",
        ['limit' => $limit]
    );
}

function public_tag_cloud(int $limit = 80): array
{
    article_ensure_tag_tables();

    return db_fetch_all(
        "SELECT tags.name, content_tags.target_type, COUNT(*) AS total
         FROM tags
         JOIN content_tags ON content_tags.tag_id = tags.id
         JOIN articles ON articles.id = content_tags.target_id
         WHERE content_tags.target_type = 'article'
           AND articles.status = 'published'
           AND articles.visibility = 'public'
         GROUP BY tags.id, tags.name, content_tags.target_type
         UNION ALL
         SELECT tags.name, content_tags.target_type, COUNT(*) AS total
         FROM tags
         JOIN content_tags ON content_tags.tag_id = tags.id
         JOIN posts ON posts.id = content_tags.target_id
         WHERE content_tags.target_type = 'post'
           AND posts.is_published = 1
           AND posts.visibility = 'public'
         GROUP BY tags.id, tags.name, content_tags.target_type
         ORDER BY total DESC, name ASC, target_type ASC
         LIMIT :limit",
        ['limit' => $limit]
    );
}

function article_backfill_missing_tags(int $limit = 100): void
{
    article_ensure_tag_tables();

    $articles = db_fetch_all(
        "SELECT articles.id, articles.title, articles.content
         FROM articles
         WHERE articles.status = 'published'
           AND NOT EXISTS (
                SELECT 1
                FROM content_tags
                WHERE content_tags.target_type = 'article'
                  AND content_tags.target_id = articles.id
           )
         ORDER BY articles.updated_at DESC, articles.created_at DESC
         LIMIT :limit",
        ['limit' => $limit]
    );

    foreach ($articles as $article) {
        article_sync_tags((int)$article['id'], (string)$article['title'], (string)$article['content']);
    }
}

function post_backfill_missing_tags(int $limit = 100): void
{
    article_ensure_tag_tables();

    $posts = db_fetch_all(
        "SELECT posts.id, posts.content
         FROM posts
         WHERE posts.is_published = 1
           AND NOT EXISTS (
                SELECT 1
                FROM content_tags
                WHERE content_tags.target_type = 'post'
                  AND content_tags.target_id = posts.id
           )
         ORDER BY posts.updated_at DESC, posts.created_at DESC
         LIMIT :limit",
        ['limit' => $limit]
    );

    foreach ($posts as $post) {
        post_sync_tags((int)$post['id'], (string)$post['content']);
    }
}

function article_tags_by_article_ids(array $articleIds): array
{
    article_ensure_tag_tables();

    $articleIds = array_values(array_unique(array_filter(array_map('intval', $articleIds))));

    if (!$articleIds) {
        return [];
    }

    $placeholders = [];
    $params = [];

    foreach ($articleIds as $index => $articleId) {
        $key = 'id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $articleId;
    }

    $rows = db_fetch_all(
        "SELECT content_tags.target_id, tags.name
         FROM content_tags
         JOIN tags ON tags.id = content_tags.tag_id
         WHERE content_tags.target_type = 'article'
           AND content_tags.target_id IN (" . implode(',', $placeholders) . ")
         ORDER BY tags.name ASC",
        $params
    );

    $tagsByArticle = [];

    foreach ($rows as $row) {
        $articleId = (int)$row['target_id'];
        $tagsByArticle[$articleId][] = $row['name'];
    }

    return $tagsByArticle;
}

function article_ensure_tag_tables(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS content_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tag_id INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            UNIQUE(tag_id, target_type, target_id),
            FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
        )"
    );

    db()->exec(
        "CREATE INDEX IF NOT EXISTS idx_content_tags_target
         ON content_tags(target_type, target_id)"
    );

    db()->exec(
        "CREATE INDEX IF NOT EXISTS idx_content_tags_tag_id
         ON content_tags(tag_id)"
    );

    $done = true;
}

function article_normalize_tag(string $tag): string
{
    $tag = trim(mb_strtolower($tag, 'UTF-8'));
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $tag);

    if ($converted !== false) {
        $tag = $converted;
    }

    $tag = str_replace(["'", '`', '’'], '', $tag);
    $tag = preg_replace('/[^a-z0-9]+/i', '-', $tag) ?? '';
    $tag = trim($tag, '-');

    return mb_substr($tag, 0, 48, 'UTF-8');
}

function article_plain_text_for_tags(string $text): string
{
    $text = preg_replace('/```.*?```/su', ' ', $text) ?? $text;
    $text = preg_replace('/\$\$(.*?)\$\$/su', ' ', $text) ?? $text;
    $text = preg_replace('/\\\\\((.*?)\\\\\)/su', ' ', $text) ?? $text;
    $text = preg_replace('/(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)/su', ' ', $text) ?? $text;
    $text = preg_replace('/\[[^\]]+\]\([^)]+\)/u', ' ', $text) ?? $text;
    $text = strip_tags($text);
    $text = str_replace(['_', '*', '`', '#', '|', '>', '•'], ' ', $text);

    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function article_tag_stop_words(): array
{
    static $words = null;

    if ($words !== null) {
        return $words;
    }

    $list = [
        'afin', 'ainsi', 'alors', 'apres', 'article', 'articles', 'assez', 'aucun', 'aussi', 'autre', 'avant',
        'avec', 'avoir', 'bien', 'car', 'ceci', 'cela', 'celle', 'celui', 'ces', 'cet',
        'cette', 'chez', 'comme', 'comment', 'dans', 'des', 'deux', 'donc', 'dont',
        'elle', 'elles', 'entre', 'est', 'etre', 'faire', 'fait', 'fois', 'hors', 'ici', 'ils',
        'les', 'leur', 'leurs', 'mais', 'meme', 'mes', 'moins', 'mon', 'nos', 'notre',
        'nous', 'ont', 'par', 'pas', 'peu', 'plus', 'pour', 'quand', 'que', 'quel',
        'quelle', 'qui', 'quoi', 'sans', 'ses', 'son', 'sont', 'sous', 'sur', 'tes',
        'toi', 'ton', 'tous', 'tout', 'tres', 'une', 'vos', 'votre', 'vous',
        'about', 'after', 'also', 'and', 'are', 'because', 'been', 'before', 'between',
        'but', 'can', 'from', 'has', 'have', 'into', 'not', 'our', 'that', 'the',
        'their', 'there', 'these', 'this', 'those', 'with', 'would', 'you', 'your',
    ];

    $words = array_fill_keys($list, true);

    return $words;
}
