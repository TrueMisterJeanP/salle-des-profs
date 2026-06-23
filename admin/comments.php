<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/pagination.php';

require_admin();

$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $commentId = (int)post_value('comment_id', '0');

    if ($commentId <= 0) {
        $errors[] = 'Commentaire invalide.';
    }

    if (!$errors) {
        $comment = db_fetch_one(
            "SELECT id
             FROM comments
             WHERE id = :id
               AND target_type = 'article'
             LIMIT 1",
            ['id' => $commentId]
        );

        if (!$comment) {
            $errors[] = 'Commentaire introuvable.';
        }
    }

    if (!$errors) {
        try {
            if ($action === 'hide') {
                db_query(
                    "UPDATE comments
                     SET is_deleted = 1,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $commentId,
                    ]
                );

                set_flash('success', 'Commentaire masqué.');
                redirect(admin_url('comments.php'));
            }

            if ($action === 'restore') {
                db_query(
                    "UPDATE comments
                     SET is_deleted = 0,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $commentId,
                    ]
                );

                set_flash('success', 'Commentaire restauré.');
                redirect(admin_url('comments.php'));
            }

            if ($action === 'delete') {
                db_query(
                    "DELETE FROM comments WHERE id = :id",
                    ['id' => $commentId]
                );

                set_flash('success', 'Commentaire supprimé définitivement.');
                redirect(admin_url('comments.php'));
            }

            if (!in_array($action, ['hide', 'restore', 'delete'], true)) {
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
    $whereParts[] = "(comments.content LIKE :q OR users.username LIKE :q OR users.display_name LIKE :q OR articles.title LIKE :q)";
    $params['q'] = '%' . $search . '%';
}

$whereParts[] = "comments.target_type = 'article'";
$where = 'WHERE ' . implode(' AND ', $whereParts);

    $page = current_page();
    $perPage = 10;
    $offset = pagination_offset($page, $perPage);
    
    $totalComments = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
        FROM comments
        JOIN users ON users.id = comments.user_id
        LEFT JOIN articles ON comments.target_id = articles.id
        $where",
        $params
    )['total'] ?? 0);
    
    $totalPages = total_pages($totalComments, $perPage);
    
    $comments = db_fetch_all(
        "SELECT comments.*,
        users.username,
        users.display_name,
        articles.title AS article_title,
        articles.slug AS article_slug
        FROM comments
        JOIN users ON users.id = comments.user_id
        LEFT JOIN articles ON comments.target_id = articles.id
        $where
        ORDER BY comments.created_at DESC
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
    <title>Commentaires des articles — Administration — <?= e(APP_NAME) ?></title>
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
            <h1>Commentaires des articles</h1>
            <p class="muted">
                Modérez les commentaires publiés sur les articles.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
                <a class="button-secondary" href="<?= e(url('articles.php')) ?>">Voir les articles</a>
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
                    placeholder="Contenu, auteur..."
                >

                <div class="form-actions">
                    <button type="submit" class="button-primary">Rechercher</button>
                    <a class="button-secondary" href="<?= e(admin_url('comments.php')) ?>">Réinitialiser</a>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Liste des commentaires</h2>

            <?php if (!$comments): ?>
                <p class="muted">Aucun commentaire trouvé.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Commentaire</th>
                                <th>Auteur</th>
                                <th>Cible</th>
                                <th>État</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($comments as $comment): ?>
                                <tr>
                                    <td><?= nl2br(e(excerpt($comment['content'], 180))) ?></td>
                                    <td>
                                        <?= e($comment['display_name'] ?: $comment['username']) ?><br>
                                        <span class="meta">@<?= e($comment['username']) ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($comment['article_slug'])): ?>
                                            Article :
                                            <a href="<?= e(url('article.php?slug=' . urlencode($comment['article_slug']))) ?>">
                                                <?= e($comment['article_title'] ?: 'Article') ?>
                                            </a>
                                        <?php else: ?>
                                            Article supprimé #<?= e((string)$comment['target_id']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$comment['is_deleted'] === 1): ?>
                                            <span class="badge badge-disabled">Masqué</span>
                                        <?php else: ?>
                                            <span class="badge badge-active">Visible</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($comment['created_at']) ?></td>
                                    <td>
                                        <div class="admin-actions">
                                            <?php if ((int)$comment['is_deleted'] === 1): ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="restore">
                                                    <input type="hidden" name="comment_id" value="<?= e((string)$comment['id']) ?>">
                                                    <button type="submit" class="button-secondary">Restaurer</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="hide">
                                                    <input type="hidden" name="comment_id" value="<?= e((string)$comment['id']) ?>">
                                                    <button type="submit" class="button-secondary">Masquer</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" action="" onsubmit="return confirm('Supprimer définitivement ce commentaire ?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="comment_id" value="<?= e((string)$comment['id']) ?>">
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
