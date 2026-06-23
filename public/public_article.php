<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/attachments.php';

$slug = get_value('slug');

if ($slug === '') {
    http_response_code(404);
    exit('Article introuvable.');
}

$article = db_fetch_one(
    "SELECT articles.*,
            users.username,
            users.display_name,
            users.avatar
     FROM articles
     JOIN users ON users.id = articles.author_id
     WHERE articles.slug = :slug
       AND articles.status = 'published'
       AND articles.visibility = 'public'
     LIMIT 1",
    ['slug' => $slug]
);

if (!$article) {
    http_response_code(404);
    exit('Article introuvable ou non public.');
}

$comments = db_fetch_all(
    "SELECT comments.*,
            users.username,
            users.display_name
     FROM comments
     JOIN users ON users.id = comments.user_id
     WHERE comments.target_type = 'article'
       AND comments.target_id = :article_id
       AND comments.is_deleted = 0
     ORDER BY comments.created_at ASC",
    ['article_id' => $article['id']]
);
$articleAttachments = article_attachments((int)$article['id']);

$siteName = site_name();
$siteLogoUrl = site_logo_url();
$siteIcon = site_icon();

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= e($article['title']) ?> — <?= e($siteName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_seo_meta([
    'title' => (string)$article['title'] . ' — ' . $siteName,
    'description' => seo_description((string)($article['excerpt'] ?: $article['content'])),
    'author' => (string)($article['display_name'] ?: $article['username']),
    'canonical' => url('public_article.php?slug=' . rawurlencode((string)$article['slug'])),
    'type' => 'article',
    'schema' => [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => (string)$article['title'],
        'description' => seo_description((string)($article['excerpt'] ?: $article['content'])),
        'author' => [
            '@type' => 'Person',
            'name' => (string)($article['display_name'] ?: $article['username']),
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => $siteName,
            'url' => site_canonical_base_url(),
        ],
        'datePublished' => gmdate('c', strtotime((string)($article['published_at'] ?: $article['created_at'])) ?: time()),
        'dateModified' => gmdate('c', strtotime((string)($article['updated_at'] ?: $article['published_at'] ?: $article['created_at'])) ?: time()),
        'mainEntityOfPage' => url('public_article.php?slug=' . rawurlencode((string)$article['slug'])),
    ],
]); ?>
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
    <header class="site-header">
        <div class="container site-header-inner">
            <a class="brand <?= $siteLogoUrl !== '' || $siteIcon !== '' ? 'brand-has-icon' : '' ?>" href="<?= e(url('public.php')) ?>">
                <?php if ($siteLogoUrl !== ''): ?>
                    <span class="brand-icon brand-logo" aria-hidden="true">
                        <img src="<?= e($siteLogoUrl) ?>" alt="">
                    </span>
                <?php elseif ($siteIcon !== ''): ?>
                    <span class="brand-icon" aria-hidden="true"><?= e($siteIcon) ?></span>
                <?php endif; ?>
                <?= e($siteName) ?>
            </a>

            <button class="site-menu-toggle site-menu-vertical-toggle" type="button" data-site-menu-toggle="site-public-menu" aria-controls="site-public-menu" aria-expanded="false">
                <span class="site-menu-icon" aria-hidden="true"></span>
                <span>Menu</span>
            </button>

            <nav class="main-nav" id="site-public-menu" aria-label="Navigation publique">
                <a href="<?= e(url('public.php')) ?>">Accueil</a>
                <a href="<?= e(url('login.php')) ?>">Connexion</a>
                <a href="<?= e(url('register.php')) ?>">Inscription</a>
                <a href="<?= e(url('about.php')) ?>">À propos</a>
                <a href="<?= e(url('rss.php')) ?>">RSS</a>
            </nav>
        </div>
    </header>

    <main class="container page public-article-page">
        <article class="card article-full">
            <header class="article-full-header">
                <div class="article-title-row">
                    <h1><?= e($article['title']) ?></h1>
                    <span class="post-avatar article-avatar">
                    <?php if (!empty($article['avatar'])): ?>
                        <img src="<?= e(avatar_url((int)$article['author_id'], (string)$article['avatar'])) ?>" alt="">
                    <?php else: ?>
                            <?= e(mb_strtoupper(mb_substr($article['display_name'] ?: $article['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <p class="meta">
                    Par <?= e($article['display_name'] ?: $article['username']) ?>
                    · <?= e($article['published_at'] ?: $article['created_at']) ?>
                    · Public
                </p>
            </header>

            <?php if (!empty($article['excerpt'])): ?>
                <div class="article-excerpt markdown-content">
                    <?= render_markdown($article['excerpt']) ?>
                </div>
            <?php endif; ?>

            <div class="article-content markdown-content">
                <?= render_markdown($article['content']) ?>
            </div>

            <?php if ($articleAttachments): ?>
                <section class="article-attachments">
                    <h2>Fichiers joints</h2>
                    <div class="attachment-card-list">
                        <?php foreach ($articleAttachments as $attachment): ?>
                            <a href="<?= e(url('file.php?id=' . (int)$attachment['id'])) ?>" target="_blank" rel="noopener">
                                <strong><?= e($attachment['original_name']) ?></strong>
                                <span class="meta">
                                    <?= e($attachment['mime_type']) ?> · <?= e(human_file_size((int)$attachment['size'])) ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </article>

        <section class="card public-article-comments">
            <h2>Commentaires</h2>

            <?php if (!$comments): ?>
                <p class="muted">Aucun commentaire pour l’instant.</p>
            <?php else: ?>
                <div class="comments-list">
                    <?php foreach ($comments as $comment): ?>
                        <article class="comment">
                            <p>
                                <?= nl2br(e($comment['content'])) ?>
                            </p>
                            <p class="meta">
                                <?= e($comment['display_name'] ?: $comment['username']) ?>
                                · <?= e($comment['created_at']) ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p class="muted">
                Connectez-vous pour ajouter un commentaire.
            </p>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
