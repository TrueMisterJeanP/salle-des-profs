<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tags.php';
require_once __DIR__ . '/../includes/activitypub.php';
require_once __DIR__ . '/../includes/mastodon.php';

require_once __DIR__ . '/../includes/pagination.php';

require_login();

$user = current_user();
$errors = [];
$mastodonConfigured = mastodon_is_configured((int)$user['id']);
$page = current_page();
$perPage = 10;
$offset = pagination_offset($page, $perPage);
$returnQuery = http_build_query($_GET);
$returnUrl = url('articles.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $articleId = (int)post_value('article_id', '0');

    if ($action !== 'delete') {
        $errors[] = 'Action inconnue.';
    }

    if ($articleId <= 0) {
        $errors[] = 'Article invalide.';
    }

    $articleToDelete = null;

    if (!$errors) {
        $articleToDelete = db_fetch_one(
            "SELECT *
             FROM articles
             WHERE id = :id
               AND (
                    author_id = :author_id
                    OR :is_admin = 1
               )
             LIMIT 1",
            [
                'id' => $articleId,
                'author_id' => $user['id'],
                'is_admin' => ($user['role'] ?? '') === 'admin' ? 1 : 0,
            ]
        );

        if (!$articleToDelete) {
            $errors[] = 'Article introuvable ou non supprimable.';
        }
    }

    if (!$errors) {
        try {
            $before = activitypub_article_by_id_for_event($articleId);

            db_query(
                "DELETE FROM comments
                 WHERE target_type = 'article'
                   AND target_id = :article_id",
                ['article_id' => $articleId]
            );

            article_delete_tags($articleId);

            db_query(
                "DELETE FROM articles WHERE id = :id",
                ['id' => $articleId]
            );
            activitypub_after_article_delete($before);

            set_flash('success', 'Article supprimé.');
            redirect($returnUrl);
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$flashes = get_flashes();
    
$totalArticles = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM articles
     WHERE articles.status = 'published'
       AND articles.author_id = :current_user_id",
    ['current_user_id' => $user['id']]
)['total'] ?? 0);

$totalPages = total_pages($totalArticles, $perPage);

$articles = db_fetch_all(
    "SELECT articles.*, users.username, users.display_name, users.avatar
     FROM articles
     JOIN users ON users.id = articles.author_id
     WHERE articles.status = 'published'
       AND articles.author_id = :current_user_id
     ORDER BY articles.published_at DESC, articles.created_at DESC
     LIMIT :limit OFFSET :offset",
    [
        'current_user_id' => $user['id'],
        'limit' => $perPage,
        'offset' => $offset,
    ]
);

$draftsPage = current_page('drafts_page');
$draftsPerPage = 10;
$draftsOffset = pagination_offset($draftsPage, $draftsPerPage);

$totalDrafts = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM articles
     WHERE author_id = :author_id
       AND status = 'draft'",
    ['author_id' => $user['id']]
)['total'] ?? 0);

$draftsTotalPages = total_pages($totalDrafts, $draftsPerPage);

$myDrafts = db_fetch_all(
    "SELECT id, title, slug, status, visibility, created_at, updated_at
     FROM articles
     WHERE author_id = :author_id
       AND status = 'draft'
     ORDER BY updated_at DESC, created_at DESC
     LIMIT :limit OFFSET :offset",
    [
        'author_id' => $user['id'],
        'limit' => $draftsPerPage,
        'offset' => $draftsOffset,
    ]
);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Mes articles — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="container page">
        <?php foreach ($flashes as $flash): ?>
            <div class="<?= e(flash_class($flash['type'])) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($errors): ?>
            <div class="flash flash-error">
                <strong>Erreur :</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="card">
            <h1>Mes articles</h1>
            <p class="muted">
                Publiez des contenus longs : fiches, ressources, synthèses, comptes rendus ou billets structurés.
            </p>

            <div class="form-actions">
                <a class="button-primary" href="<?= e(url('article_edit.php')) ?>">
                    Nouvel article
                </a>
            </div>
        </section>

        <?php if ($myDrafts): ?>
            <section class="card">
                <h2>Mes brouillons</h2>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Titre</th>
                                <th>Visibilité</th>
                                <th>Création</th>
                                <th>Modification</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myDrafts as $draft): ?>
                                <tr>
                                    <td><?= e($draft['title']) ?></td>
                                    <td><?= e(visibility_label($draft['visibility'])) ?></td>
                                    <td><?= e($draft['created_at']) ?></td>
                                    <td><?= e($draft['updated_at'] ?: '-') ?></td>
                                    <td>
                                        <a class="button-primary" href="<?= e(url('article_edit.php?id=' . (int)$draft['id'])) ?>">
                                            Modifier
                                        </a>
                                        <form method="post" action="<?= e($returnUrl) ?>" onsubmit="return confirm('Supprimer définitivement ce brouillon ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="article_id" value="<?= e((string)$draft['id']) ?>">
                                            <button type="submit" class="button-danger">Supprimer</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($draftsTotalPages > 1): ?>
                    <?= render_pagination($draftsPage, $draftsTotalPages, 'drafts_page') ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="article-list">
            <?php if (!$articles): ?>
                <article class="card">
                    <p class="muted">Aucun article publié pour l’instant.</p>
                </article>
            <?php else: ?>
                <?php foreach ($articles as $article): ?>
                    <article class="article-preview">
                        <div class="article-preview-header">
                            <h2>
                                <a href="<?= e(url('article.php?slug=' . urlencode($article['slug']))) ?>">
                                    <?= e($article['title']) ?>
                                </a>
                            </h2>
                            <span class="post-header-right">
                                <span class="badge"><?= e(article_status_label($article['status'])) ?></span>
                                <span class="post-avatar">
                                    <?php if (!empty($article['avatar'])): ?>
                                        <img src="<?= e(avatar_url((int)$article['author_id'], (string)$article['avatar'])) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_strtoupper(mb_substr($article['display_name'] ?: $article['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                    <?php endif; ?>
                                </span>
                            </span>
                        </div>

                        <p>
                            <?= e(article_preview_text($article['excerpt'] ?: $article['content'], 260)) ?>
                        </p>

                        <p class="meta">
                            Par <?= e($article['display_name'] ?: $article['username']) ?>
                            · <?= e($article['published_at'] ?: $article['created_at']) ?>
                            · <?= e(visibility_label($article['visibility'])) ?>
                        </p>

                        <?php if ((int)$article['author_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin'): ?>
                            <div class="form-actions">
                                <a class="button-primary" href="<?= e(url('article_edit.php?id=' . (int)$article['id'])) ?>">
                                    Modifier
                                </a>
                                <?php if ((int)$article['author_id'] === (int)$user['id'] && $article['visibility'] === 'public' && $mastodonConfigured): ?>
                                    <button
                                        type="button"
                                        data-publish-mastodon
                                        data-content-type="article"
                                        data-content-id="<?= e((string)$article['id']) ?>"
                                    >
                                        Publier sur Mastodon
                                    </button>
                                <?php endif; ?>
                                <form method="post" action="<?= e($returnUrl) ?>" onsubmit="return confirm('Supprimer définitivement cet article et ses commentaires ?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="article_id" value="<?= e((string)$article['id']) ?>">
                                    <button type="submit" class="button-danger">Supprimer</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        
        <?php if ($totalPages > 1): ?>
            <section class="card">
                <?= render_pagination($page, $totalPages) ?>
            </section>
        <?php endif; ?>
        
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
    <script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
