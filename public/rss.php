<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

redirect(is_logged_in() ? url('dashboard.php') : url('login.php'));

$siteName = site_name();

$items = [];

try {
    $posts = db_fetch_all(
        "SELECT posts.id,
                posts.content,
                posts.created_at,
                users.username,
                users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE posts.is_published = 1
           AND posts.visibility = 'public'
         ORDER BY posts.created_at DESC
         LIMIT 30"
    );

    foreach ($posts as $post) {
        $items[] = [
            'type' => 'post',
            'title' => 'Publication de ' . ($post['display_name'] ?: $post['username']),
            'description' => $post['content'],
            'link' => url('public.php') . '#post-' . (int)$post['id'],
            'date' => $post['created_at'],
            'guid' => 'post-' . (int)$post['id'],
        ];
    }

    $articles = db_fetch_all(
        "SELECT articles.id,
                articles.title,
                articles.slug,
                articles.content,
                articles.excerpt,
                articles.published_at,
                articles.created_at,
                users.username,
                users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE articles.status = 'published'
           AND articles.visibility = 'public'
         ORDER BY articles.published_at DESC, articles.created_at DESC
         LIMIT 30"
    );

    foreach ($articles as $article) {
        $items[] = [
            'type' => 'article',
            'title' => $article['title'],
            'description' => $article['excerpt'] ?: excerpt($article['content'], 300),
            'link' => url('public_article.php?slug=' . urlencode($article['slug'])),
            'date' => $article['published_at'] ?: $article['created_at'],
            'guid' => 'article-' . (int)$article['id'],
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp($b['date'], $a['date']);
    });

    $items = array_slice($items, 0, 40);
} catch (Throwable $e) {
    $items = [];
}

header('Content-Type: application/rss+xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0">
    <channel>
        <title><?= e($siteName) ?></title>
        <link><?= e(url('index.php')) ?></link>
        <description><?= e($siteName) ?> — publications publiques</description>
        <language>fr-fr</language>
        <lastBuildDate><?= e(date(DATE_RSS)) ?></lastBuildDate>

        <?php foreach ($items as $item): ?>
            <item>
                <title><?= e($item['title']) ?></title>
                <link><?= e($item['link']) ?></link>
                <guid isPermaLink="false"><?= e($item['guid']) ?></guid>
                <description><?= e($item['description']) ?></description>
                <pubDate><?= e(date(DATE_RSS, strtotime($item['date']))) ?></pubDate>
            </item>
        <?php endforeach; ?>
    </channel>
</rss>
