<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/posts.php';
require_once __DIR__ . '/../includes/tags.php';

redirect(is_logged_in() ? url('dashboard.php') : url('login.php'));

$siteName = site_name();
$siteLogoUrl = site_logo_url();
$siteIcon = site_icon();
$selectedTag = article_normalize_tag(get_value('tag'));
$selectedTagType = get_value('tag_type') === 'post' ? 'post' : 'article';

$publicPosts = [];
$publicArticles = [];
$tagCloud = [];
$postsPage = current_page('posts_page');
$articlesPage = current_page('articles_page');
$perPage = 10;
$postsPerPage = 30;
$postsOffset = pagination_offset($postsPage, $postsPerPage);
$articlesOffset = pagination_offset($articlesPage, $perPage);
$postsTotalPages = 1;
$articlesTotalPages = 1;
$totalFilteredPosts = 0;
$totalFilteredArticles = 0;
$publicStats = [
    'posts' => 0,
    'articles' => 0,
    'members' => 0,
];

try {
    posts_ensure_pinning_column();
    article_backfill_missing_tags();
    post_backfill_missing_tags();

    $publicStats['posts'] = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM posts
         WHERE is_published = 1
           AND visibility = 'public'"
    )['total'] ?? 0);

    $publicStats['articles'] = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM articles
         WHERE status = 'published'
           AND visibility = 'public'"
    )['total'] ?? 0);

    $publicStats['members'] = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM users
         WHERE is_active = 1"
    )['total'] ?? 0);

    $postWhere = "posts.is_published = 1
           AND posts.visibility = 'public'";
    $postParams = [];

    if ($selectedTag !== '' && $selectedTagType === 'post') {
        $postWhere .= "
           AND EXISTS (
                SELECT 1
                FROM content_tags
                JOIN tags ON tags.id = content_tags.tag_id
                WHERE content_tags.target_type = 'post'
                  AND content_tags.target_id = posts.id
                  AND tags.name = :selected_post_tag
           )";
        $postParams['selected_post_tag'] = $selectedTag;
    }

    $totalFilteredPosts = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM posts
         WHERE $postWhere",
        $postParams
    )['total'] ?? 0);

    $postsTotalPages = total_pages($totalFilteredPosts, $postsPerPage);

    $publicPosts = db_fetch_all(
        "SELECT posts.*,
                users.username,
                users.display_name,
                users.avatar
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE $postWhere
         ORDER BY CASE WHEN posts.pinned_at IS NULL THEN 1 ELSE 0 END ASC,
                  posts.pinned_at DESC,
                  posts.created_at DESC
         LIMIT :limit OFFSET :offset",
        array_merge($postParams, [
            'limit' => $postsPerPage,
            'offset' => $postsOffset,
        ])
    );

    $articleWhere = "articles.status = 'published'
           AND articles.visibility = 'public'";
    $articleParams = [];

    if ($selectedTag !== '' && $selectedTagType === 'article') {
        $articleWhere .= "
           AND EXISTS (
                SELECT 1
                FROM content_tags
                JOIN tags ON tags.id = content_tags.tag_id
                WHERE content_tags.target_type = 'article'
                  AND content_tags.target_id = articles.id
                  AND tags.name = :selected_tag
           )";
        $articleParams['selected_tag'] = $selectedTag;
    }

    $totalFilteredArticles = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
         FROM articles
         WHERE $articleWhere",
        $articleParams
    )['total'] ?? 0);

    $articlesTotalPages = total_pages($totalFilteredArticles, $perPage);

    $publicArticles = db_fetch_all(
        "SELECT articles.*,
                users.username,
                users.display_name,
                users.avatar
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE $articleWhere
         ORDER BY articles.published_at DESC, articles.created_at DESC
         LIMIT :limit OFFSET :offset",
        array_merge($articleParams, [
            'limit' => $perPage,
            'offset' => $articlesOffset,
        ])
    );

    $tagCloud = public_tag_cloud();
} catch (Throwable $e) {
    $publicPosts = [];
    $publicArticles = [];
    $tagCloud = [];
}

$publicPosts = posts_attach_polls($publicPosts, current_user_id());

$showPostsPanel = $selectedTag === '' || $selectedTagType === 'post';
$showArticlesPanel = $selectedTag === '' || $selectedTagType === 'article';
$activeTagContentLabel = $selectedTagType === 'post' ? 'Publications' : 'Articles';

if (is_post() && post_value('action') === 'poll_vote') {
    require_csrf();

    $votePostId = (int)post_value('post_id', '0');

    try {
        post_poll_submit_vote($votePostId, (int)post_value('option_id', '0'), current_user_id(), 'public');
        set_flash('success', 'Vote enregistré.');
    } catch (Throwable $e) {
        set_flash('error', 'Erreur : ' . $e->getMessage());
    }

    redirect(url('public.php' . ($postsPage > 1 ? '?posts_page=' . $postsPage : '') . '#post-' . $votePostId));
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= e(site_meta_title()) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_seo_meta([
    'title' => site_meta_title(),
    'description' => site_meta_description(),
    'canonical' => url('public.php'),
]); ?>
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body id="top">
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
                <a href="<?= e(url('rss.php')) ?>">RSS</a>
            </nav>
        </div>
    </header>

    <main class="public-home">
        <?php if ($flashes): ?>
            <section class="container page-flashes">
                <?php foreach ($flashes as $flash): ?>
                    <div class="<?= e(flash_class($flash['type'])) ?>">
                        <?= e($flash['message']) ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="public-hero">
            <div class="public-hero-media" aria-hidden="true"></div>

            <div class="container public-hero-content">
                <div class="public-hero-copy">
                    <p class="public-eyebrow">Protection enseignants · Articles · Messages · ActivityPub</p>
                    <h1><?= e($siteName) ?></h1>
                    <p>
                        Un espace collectif pour <b>documenter les faits d’établissement</b>,
                        partager des <b>ressources officielles</b>, coordonner les réponses
                        des enseignants et publier vers <b>Mastodon & ActivityPub</b>.
                    </p>

                    <div class="form-actions">
                        <a class="button-primary" href="<?= e(url('register.php')) ?>">Rejoindre la communauté</a>
                        <a class="button-secondary" href="<?= e(url('login.php')) ?>">Se connecter</a>
                    </div>
                </div>

                <aside class="public-hero-card" aria-label="Aperçu du réseau">
                    <div class="public-composer">
                        <span class="post-avatar">+</span>
                        <div>
                            <strong>Publier une alerte</strong>
                            <p class="meta">Message court, ressource, annonce syndicale ou compte rendu.</p>
                        </div>
                    </div>

                    <div class="public-mini-post">
                        <span class="badge">Public</span>
                        <p>Rendez visibles les dysfonctionnements, les réponses collectives et les appuis disponibles.</p>
                    </div>

                    <div class="public-mini-grid">
                        <div>
                            <strong><?= e((string)$publicStats['members']) ?></strong>
                            <span>membres</span>
                        </div>
                        <div>
                            <strong><?= e((string)$publicStats['posts']) ?></strong>
                            <span>publications</span>
                        </div>
                        <div>
                            <strong><?= e((string)$publicStats['articles']) ?></strong>
                            <span>articles</span>
                        </div>
                    </div>
                </aside>
            </div>
        </section>

        <section class="container public-feature-band">
            <article>
                <span class="feature-icon">01</span>
                <h2>Un fil d’alertes lisible</h2>
                <p>Des publications courtes pour signaler, informer et coordonner les réponses.</p>
            </article>

            <article>
                <span class="feature-icon">02</span>
                <h2>Des dossiers structurés</h2>
                <p>Publiez des synthèses, guides, tribunes, modèles et comptes rendus.</p>
            </article>

            <article>
                <span class="feature-icon">03</span>
                <h2>Des collectifs protégés</h2>
                <p>Messagerie, groupes et visibilité fine pour travailler entre enseignants.</p>
            </article>
        </section>

        <section class="container public-home-section">
            <div>
                <p class="public-eyebrow">Aperçu public</p>
                <h2>On en parle en ce moment</h2>
            </div>
            <a class="button-secondary" href="<?= e(url('rss.php')) ?>">Suivre le RSS</a>
        </section>

        <?php if ($tagCloud): ?>
            <section class="container tag-cloud-section" aria-label="Thèmes des contenus publics">
                <div class="public-section-heading">
                    <div>
                        <h2>Explorer par thème</h2>
                        <?php if ($selectedTag !== ''): ?>
                            <p class="meta"><?= e($activeTagContentLabel) ?> liés à #<?= e($selectedTag) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php if ($selectedTag !== ''): ?>
                        <a class="button-secondary" href="<?= e(url('public.php')) ?>">Tous les contenus</a>
                    <?php endif; ?>
                </div>

                <div class="tag-cloud" id="tag-cloud">
                    <?php foreach ($tagCloud as $tag): ?>
                        <?php
                            $tagName = (string)$tag['name'];
                            $tagTotal = (int)$tag['total'];
                            $tagType = (string)($tag['target_type'] ?? 'article');
                            $isPostTag = $tagType === 'post';
                            $tagUrl = $isPostTag
                                ? url('public.php?tag=' . urlencode($tagName) . '&tag_type=post')
                                : url('public.php?tag=' . urlencode($tagName));
                            $tagClass = 'tag-pill' . ($isPostTag ? ' tag-pill-post' : '');

                            if ($selectedTag === $tagName && $selectedTagType === $tagType) {
                                $tagClass .= ' active';
                            }
                        ?>
                        <a
                            class="<?= e($tagClass) ?>"
                            href="<?= e($tagUrl) ?>"
                            style="--tag-weight: <?= e((string)min(5, max(1, $tagTotal))) ?>;"
                        >
                            #<?= e($tagName) ?>
                            <span><?= e((string)$tagTotal) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <button
                    type="button"
                    class="tag-cloud-next"
                    data-tag-cloud-next
                    aria-controls="tag-cloud"
                    aria-label="Afficher les thèmes suivants"
                >
                    ↓
                </button>
            </section>
        <?php endif; ?>

        <section class="container grid <?= $selectedTag !== '' ? 'grid-1' : 'grid-2' ?> public-home-grid">
            <?php if ($showPostsPanel): ?>
                <article class="public-stream-panel public-posts-panel" data-height-paged-posts data-height-target="#public-articles-panel" data-height-min="720">
                    <div class="public-section-heading">
                        <h2><?= $selectedTag !== '' ? 'Publications sélectionnées' : 'Publications publiques' ?></h2>
                        <?php if ($selectedTag !== ''): ?>
                            <span class="badge">#<?= e($selectedTag) ?></span>
                        <?php else: ?>
                            <span class="badge"><?= e((string)$publicStats['posts']) ?> publications</span>
                        <?php endif; ?>
                    </div>

                    <?php if (!$publicPosts): ?>
                        <div class="empty-public-card">
                            <p class="muted">
                                <?= $selectedTag !== '' ? 'Aucune publication publique pour ce thème.' : 'Aucune publication publique pour l’instant.' ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="feed-list" data-height-paged-list>
                            <?php foreach ($publicPosts as $post): ?>
                                <article class="post <?= !empty($post['pinned_at']) ? 'post-pinned' : '' ?>" id="post-<?= e((string)$post['id']) ?>">
                                    <div class="post-header">
                                        <div>
                                            <strong><?= e($post['display_name'] ?: $post['username']) ?></strong>
                                            <span class="meta">@<?= e($post['username']) ?></span>
                                        </div>

                                        <span class="post-header-right">
                                            <?php if (!empty($post['pinned_at'])): ?>
                                                <span class="badge badge-pinned">épinglé</span>
                                            <?php endif; ?>
                                            <span class="badge">Public</span>
                                            <span class="post-avatar">
                                            <?php if (!empty($post['avatar'])): ?>
                                                    <img src="<?= e(avatar_url((int)$post['author_id'], (string)$post['avatar'])) ?>" alt="">
                                                <?php else: ?>
                                                    <?= e(mb_strtoupper(mb_substr($post['display_name'] ?: $post['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </div>

                                    <div class="post-content markdown-content">
                                        <?= render_markdown($post['content']) ?>
                                    </div>

                                    <?= render_post_poll($post) ?>

                                    <p class="meta">
                                        Publié le <?= e($post['created_at']) ?>
                                    </p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <nav class="height-post-pager" data-height-post-pager aria-label="Pages des publications"></nav>
                        <div data-height-server-pagination>
                            <?php if ($postsTotalPages > 1): ?>
                                <?= render_pagination($postsPage, $postsTotalPages, 'posts_page') ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ($showArticlesPanel): ?>
            <article class="public-stream-panel" id="public-articles-panel">
                <div class="public-section-heading">
                    <h2><?= $selectedTag !== '' ? 'Articles sélectionnés' : 'Articles publics' ?></h2>
                    <?php if ($selectedTag !== ''): ?>
                        <span class="badge">#<?= e($selectedTag) ?></span>
                    <?php else: ?>
                        <span class="badge"><?= e((string)$publicStats['articles']) ?> articles</span>
                    <?php endif; ?>
                </div>

                <?php if (!$publicArticles): ?>
                    <div class="empty-public-card">
                        <p class="muted">
                            <?= $selectedTag !== '' ? 'Aucun article public pour ce thème.' : 'Aucun article public pour l’instant.' ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="article-list">
                        <?php foreach ($publicArticles as $article): ?>
                            <article class="article-preview">
                                <div class="article-preview-header">
                                    <h3>
                                        <a href="<?= e(url('public_article.php?slug=' . urlencode($article['slug']))) ?>">
                                            <?= e($article['title']) ?>
                                        </a>
                                    </h3>
                                    <span class="post-avatar">
                                        <?php if (!empty($article['avatar'])): ?>
                                            <img src="<?= e(avatar_url((int)$article['author_id'], (string)$article['avatar'])) ?>" alt="">
                                        <?php else: ?>
                                            <?= e(mb_strtoupper(mb_substr($article['display_name'] ?: $article['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <div class="article-preview-summary markdown-content">
                                    <?= render_markdown($article['excerpt'] ?: excerpt($article['content'], 220)) ?>
                                </div>

                                <p class="meta">
                                    Par <?= e($article['display_name'] ?: $article['username']) ?>
                                    · <?= e($article['published_at'] ?: $article['created_at']) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($articlesTotalPages > 1): ?>
                        <?= render_pagination($articlesPage, $articlesTotalPages, 'articles_page') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </article>
            <?php endif; ?>
        </section>

        <section class="container public-cta">
            <div>
                <p class="public-eyebrow">Prêt à participer ?</p>
                <h2>Créez votre profil et commencez à publier.</h2>
                <p>
                    Rejoignez les conversations, suivez les articles publics et partagez vos propres ressources.
                </p>
            </div>
            <a class="button-primary" href="<?= e(url('register.php')) ?>">Créer un compte</a>
        </section>

        <div class="container public-back-to-top">
            <a href="#top" aria-label="Remonter en haut de la page">↑</a>
        </div>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
    <script>
        document.querySelectorAll('[data-tag-cloud-next]').forEach((button) => {
            const cloud = document.getElementById(button.getAttribute('aria-controls') || '');

            if (!cloud || cloud.scrollHeight <= cloud.clientHeight) {
                button.hidden = true;
                return;
            }

            const updateButton = () => {
                const maxScroll = cloud.scrollHeight - cloud.clientHeight;
                const isAtBottom = cloud.scrollTop >= maxScroll - 4;

                button.textContent = isAtBottom ? '↑' : '↓';
                button.setAttribute(
                    'aria-label',
                    isAtBottom ? 'Afficher les thèmes précédents' : 'Afficher les thèmes suivants'
                );
            };

            cloud.addEventListener('scroll', updateButton, {passive: true});
            updateButton();

            button.addEventListener('click', () => {
                const maxScroll = cloud.scrollHeight - cloud.clientHeight;
                const isAtBottom = cloud.scrollTop >= maxScroll - 4;

                cloud.scrollTo({
                    top: isAtBottom ? Math.max(0, cloud.scrollTop - cloud.clientHeight) : Math.min(maxScroll, cloud.scrollTop + cloud.clientHeight),
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>
