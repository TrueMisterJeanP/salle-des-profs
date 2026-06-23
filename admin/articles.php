<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/tags.php';
require_once __DIR__ . '/../includes/activitypub.php';

require_once __DIR__ . '/../includes/pagination.php';

require_admin();
ensure_content_group_column('articles');

$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $articleId = (int)post_value('article_id', '0');

    if ($articleId <= 0) {
        $errors[] = 'Article invalide.';
    }

    if (!$errors) {
        $article = db_fetch_one(
            "SELECT id, slug, title, content FROM articles WHERE id = :id LIMIT 1",
            ['id' => $articleId]
        );

        if (!$article) {
            $errors[] = 'Article introuvable.';
        }
    }

    if (!$errors) {
        try {
            if ($action === 'publish') {
                $before = activitypub_article_by_id_for_event($articleId);
                db_query(
                    "UPDATE articles
                     SET status = 'published',
                         published_at = COALESCE(published_at, :published_at),
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'published_at' => now(),
                        'updated_at' => now(),
                        'id' => $articleId,
                    ]
                );

                article_sync_tags($articleId, (string)$article['title'], (string)$article['content']);
                activitypub_after_article_change($before, $articleId);

                set_flash('success', 'Article publié.');
                redirect(admin_url('articles.php'));
            }

            if ($action === 'draft') {
                $before = activitypub_article_by_id_for_event($articleId);
                db_query(
                    "UPDATE articles
                     SET status = 'draft',
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $articleId,
                    ]
                );
                activitypub_after_article_change($before, $articleId);

                set_flash('success', 'Article repassé en brouillon.');
                redirect(admin_url('articles.php'));
            }

            if ($action === 'change_visibility') {
                $visibility = post_value('visibility', 'members');
                $allowedVisibilities = ['private', 'members'];

                if (!in_array($visibility, $allowedVisibilities, true)) {
                    $errors[] = 'Visibilité invalide.';
                } else {
                    $before = activitypub_article_by_id_for_event($articleId);
                    db_query(
                        "UPDATE articles
                         SET visibility = :visibility,
                             updated_at = :updated_at
                         WHERE id = :id",
                        [
                            'visibility' => $visibility,
                            'updated_at' => now(),
                            'id' => $articleId,
                        ]
                    );
                    activitypub_after_article_change($before, $articleId);

                    set_flash('success', 'Visibilité de l’article mise à jour.');
                    redirect(admin_url('articles.php'));
                }
            }

            if ($action === 'delete') {
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
                redirect(admin_url('articles.php'));
            }

            if (!in_array($action, ['publish', 'draft', 'change_visibility', 'delete'], true)) {
                $errors[] = 'Action inconnue.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$search = get_value('q');
$params = [];
$whereParts = [];

if ($search !== '') {
    $whereParts[] = "(articles.title LIKE :q OR articles.content LIKE :q OR users.username LIKE :q OR users.display_name LIKE :q)";
    $params['q'] = '%' . $search . '%';
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    $page = current_page();
    $perPage = 10;
    $offset = pagination_offset($page, $perPage);
    
    $totalArticles = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
        FROM articles
        JOIN users ON users.id = articles.author_id
        $where",
        $params
    )['total'] ?? 0);
    
    $totalPages = total_pages($totalArticles, $perPage);
    
    $articles = db_fetch_all(
        "SELECT articles.*,
        users.username,
        users.display_name,
        (
            SELECT COUNT(*)
            FROM comments
            WHERE comments.target_type = 'article'
            AND comments.target_id = articles.id
            AND comments.is_deleted = 0
        ) AS comment_count
        FROM articles
        JOIN users ON users.id = articles.author_id
        $where
        ORDER BY articles.created_at DESC
        LIMIT :limit OFFSET :offset",
        array_merge($params, [
            'limit' => $perPage,
            'offset' => $offset,
        ])
    );

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Articles — Administration — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/../public/assets/app.css')) ?>">
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
            <h1>Administration des articles</h1>
            <p class="muted">
                Gérez les articles longs, les brouillons et les contenus à accès contrôlé.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
                <a class="button-secondary" href="<?= e(url('articles.php')) ?>">Voir les articles</a>
                <a class="button-primary" href="<?= e(url('article_edit.php')) ?>">Nouvel article</a>
            </div>
        </section>

        <section class="card">
            <form method="get" action="">
                <label for="q">Rechercher</label>
                <input
                    type="search"
                    id="q"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="Titre, contenu, auteur..."
                >

                <div class="form-actions">
                    <button type="submit" class="button-primary">Rechercher</button>
                    <a class="button-secondary" href="<?= e(admin_url('articles.php')) ?>">Réinitialiser</a>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Liste des articles</h2>

            <?php if (!$articles): ?>
                <p class="muted">Aucun article trouvé.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Article</th>
                                <th>Auteur</th>
                                <th>Visibilité</th>
                                <th>Statut</th>
                                <th>Commentaires</th>
                                <th>Création</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($articles as $article): ?>
                                <tr>
                                    <td>
                                        <strong><?= e($article['title']) ?></strong><br>
                                        <span class="meta">
                                            <?= e(excerpt($article['excerpt'] ?: $article['content'], 120)) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?= e($article['display_name'] ?: $article['username']) ?><br>
                                        <span class="meta">@<?= e($article['username']) ?></span>
                                    </td>
                                    <td>
                                        <form method="post" action="" class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="change_visibility">
                                            <input type="hidden" name="article_id" value="<?= e((string)$article['id']) ?>">
                                            <select name="visibility" onchange="this.form.submit()">
                                                <option value="private" <?= $article['visibility'] === 'private' ? 'selected' : '' ?>>
                                                    Privé
                                                </option>
                                                <option value="members" <?= $article['visibility'] === 'members' ? 'selected' : '' ?>>
                                                    Membres
                                                </option>
                                            </select>
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($article['status'] === 'published'): ?>
                                            <span class="badge badge-active">Publié</span>
                                        <?php else: ?>
                                            <span class="badge badge-disabled">Brouillon</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e((string)$article['comment_count']) ?></td>
                                    <td><?= e($article['created_at']) ?></td>
                                    <td>
                                        <div class="admin-actions">
                                            <a class="button-secondary" href="<?= e(url('article_edit.php?id=' . (int)$article['id'])) ?>">
                                                Modifier
                                            </a>

                                            <?php if ($article['status'] === 'published'): ?>
                                                <a class="button-secondary" href="<?= e(url('article.php?slug=' . urlencode($article['slug']))) ?>">
                                                    Voir
                                                </a>

                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="draft">
                                                    <input type="hidden" name="article_id" value="<?= e((string)$article['id']) ?>">
                                                    <button type="submit" class="button-secondary">Brouillon</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="publish">
                                                    <input type="hidden" name="article_id" value="<?= e((string)$article['id']) ?>">
                                                    <button type="submit" class="button-secondary">Publier</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" action="" onsubmit="return confirm('Supprimer définitivement cet article et ses commentaires ?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="article_id" value="<?= e((string)$article['id']) ?>">
                                                <button type="submit" class="button-danger">Supprimer</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            
            <?php if ($totalPages > 1): ?>
                <section class="card">
                    <?= render_pagination($page, $totalPages) ?>
                </section>
            <?php endif; ?>
            
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
