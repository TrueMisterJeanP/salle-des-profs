<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/tags.php';
require_once __DIR__ . '/../includes/activitypub.php';
require_once __DIR__ . '/../includes/mastodon.php';

require_login();
ensure_content_group_column('articles');

$user = current_user();
$mastodonConfigured = mastodon_is_configured((int)$user['id']);
$slug = get_value('slug');

if ($slug === '') {
    http_response_code(404);
    exit('Article introuvable.');
}

$article = db_fetch_one(
    "SELECT articles.*, users.username, users.display_name, users.avatar, groups.name AS group_name
     FROM articles
     JOIN users ON users.id = articles.author_id
     LEFT JOIN groups ON groups.id = articles.group_id
     WHERE articles.slug = :slug
     LIMIT 1",
    ['slug' => $slug]
);

if (!$article) {
    http_response_code(404);
    exit('Article introuvable.');
}

$canRead = false;

if ($article['status'] === 'published') {
    if ($article['visibility'] === 'public') {
        $canRead = true;
    }

    if ($article['visibility'] === 'members') {
        $canRead = true;
    }

    if ($article['visibility'] === 'group' && !empty($article['group_id'])) {
        $canRead = user_can_use_group((int)$user['id'], (int)$article['group_id']);
    }

    if ((int)$article['author_id'] === (int)$user['id']) {
        $canRead = true;
    }

    if (($user['role'] ?? '') === 'admin') {
        $canRead = true;
    }
} else {
    if ((int)$article['author_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin') {
        $canRead = true;
    }
}

if (!$canRead) {
    http_response_code(403);
    exit('Vous ne pouvez pas consulter cet article.');
}

$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action', 'comment');
    $content = post_value('content');

    if ($action === 'delete') {
        $articleId = (int)post_value('article_id', '0');
        $canDelete = (int)$article['author_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin';

        if ($articleId !== (int)$article['id']) {
            $errors[] = 'Article invalide.';
        }

        if (!$canDelete) {
            $errors[] = 'Vous ne pouvez pas supprimer cet article.';
        }

        if (!$errors) {
            try {
                $before = activitypub_article_by_id_for_event((int)$article['id']);

                db_query(
                    "DELETE FROM comments
                     WHERE target_type = 'article'
                       AND target_id = :article_id",
                    ['article_id' => $article['id']]
                );

                article_delete_tags((int)$article['id']);

                db_query(
                    "DELETE FROM articles WHERE id = :id",
                    ['id' => $article['id']]
                );
                activitypub_after_article_delete($before);

                set_flash('success', 'Article supprimé.');
                redirect(url('articles.php'));
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    } elseif ($action === 'comment') {
        if ($content === '') {
            $errors[] = 'Le commentaire ne peut pas être vide.';
        }

        if (!$errors) {
            try {
                db_insert(
                    "INSERT INTO comments (user_id, target_type, target_id, content, created_at)
                     VALUES (:user_id, 'article', :target_id, :content, :created_at)",
                    [
                        'user_id' => $user['id'],
                        'target_id' => $article['id'],
                        'content' => $content,
                        'created_at' => now(),
                    ]
                );

                set_flash('success', 'Commentaire ajouté.');
                redirect(url('article.php?slug=' . urlencode($article['slug'])));
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    } else {
        $errors[] = 'Action inconnue.';
    }
}

$comments = db_fetch_all(
    "SELECT comments.*, users.username, users.display_name
     FROM comments
     JOIN users ON users.id = comments.user_id
     WHERE comments.target_type = 'article'
       AND comments.target_id = :article_id
       AND comments.is_deleted = 0
     ORDER BY comments.created_at ASC",
    ['article_id' => $article['id']]
);
$articleAttachments = article_attachments((int)$article['id']);

$flashes = get_flashes();

$canEdit = (int)$article['author_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= e($article['title']) ?> — <?= e(APP_NAME) ?></title>
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

        <section class="card page-hero-card">
            <h1>Mes articles</h1>
            <p class="muted">
                Par <?= e($article['display_name'] ?: $article['username']) ?>
                · <?= e($article['published_at'] ?: $article['created_at']) ?>
                · <?= e(visibility_label($article['visibility'], $article['group_name'] ?? null)) ?>
                · <?= e(article_status_label($article['status'])) ?>
            </p>
        </section>

        <article class="card article-full">
            <header class="article-full-header">
                <div class="article-title-row">
                    <h2><?= e($article['title']) ?></h2>
                    <span class="post-header-right">
                        <span class="badge"><?= e(article_status_label($article['status'])) ?></span>
                        <span class="post-avatar article-avatar">
                            <?php if (!empty($article['avatar'])): ?>
                                <img src="<?= e(avatar_url((int)$article['author_id'], (string)$article['avatar'])) ?>" alt="">
                            <?php else: ?>
                                <?= e(mb_strtoupper(mb_substr($article['display_name'] ?: $article['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                            <?php endif; ?>
                        </span>
                    </span>
                </div>

                <?php if ($canEdit): ?>
                <div class="form-actions">
                    <a class="button-primary" href="<?= e(url('article_edit.php?id=' . (int)$article['id'])) ?>">
                        Modifier
                    </a>
                    
                    <a class="button-secondary" href="<?= e(url('articles.php')) ?>">
                        Retour à mes articles
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

                    <form method="post" action="" onsubmit="return confirm('Supprimer définitivement cet article et ses commentaires ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="article_id" value="<?= e((string)$article['id']) ?>">
                        <button type="submit" class="button-danger">Supprimer</button>
                    </form>
                    
                </div>
                <?php endif; ?>
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
                    <h3>Fichiers joints</h3>
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

        <section class="card">
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

            <hr>

            <h3>Ajouter un commentaire</h3>

            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="comment">

                <label for="content">Commentaire</label>
                <textarea
                    id="content"
                    name="content"
                    required
                    placeholder="Votre commentaire..."
                ><?= e(post_value('content')) ?></textarea>

                <div class="form-actions">
                    <button type="submit" class="button-secondary">
                        Commenter
                    </button>
                </div>
            </form>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
    <script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
