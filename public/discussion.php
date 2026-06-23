<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/posts.php';

require_login();
posts_ensure_pinning_column();

$user = current_user();
$errors = [];
$postId = (int)get_value('post_id', post_value('post_id', '0'));

if ($postId <= 0 || !post_user_can_view($postId, (int)$user['id'])) {
    set_flash('error', 'Annonce introuvable ou inaccessible.');
    redirect(url('dashboard.php'));
}

if (is_post()) {
    require_csrf();

    if (post_value('action') === 'post_comment') {
        try {
            post_comment_create($postId, (int)$user['id'], post_value('comment_content'));
            set_flash('success', 'Commentaire ajouté.');
            redirect(url('discussion.php?post_id=' . $postId . '#comments'));
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    } else {
        $errors[] = 'Action inconnue.';
    }
}

$post = db_fetch_one(
    "SELECT posts.*, users.username, users.display_name, users.avatar, groups.name AS group_name
     FROM posts
     JOIN users ON users.id = posts.author_id
     LEFT JOIN groups ON groups.id = posts.group_id
     WHERE posts.id = :post_id
       AND posts.is_published = 1
     LIMIT 1",
    ['post_id' => $postId]
);

if (!$post) {
    set_flash('error', 'Annonce introuvable.');
    redirect(url('dashboard.php'));
}

$post = posts_attach_comments([$post])[0];
$comments = $post['comments'] ?? [];
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Discussion — <?= e(APP_NAME) ?></title>
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

        <section class="card discussion-hero">
            <div>
                <p class="meta">Forum de discussion</p>
                <h1>Discussion autour de l’annonce</h1>
                <p class="muted">
                    Répondez au message, complétez les informations et poursuivez l’échange entre membres.
                </p>
            </div>
            <a class="button-secondary" href="<?= e(url('dashboard.php#post-' . $postId)) ?>">Retour au tableau de bord</a>
        </section>

        <section class="discussion-thread" id="comments">
            <article class="post discussion-original-post">
                <div class="post-header">
                    <div>
                        <strong><?= e($post['display_name'] ?: $post['username']) ?></strong>
                        <span class="meta">@<?= e($post['username']) ?></span>
                    </div>
                    <span class="post-header-right">
                        <span class="badge"><?= e(visibility_label($post['visibility'], $post['group_name'] ?? null)) ?></span>
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
                <p class="meta">Annonce publiée le <?= e($post['created_at']) ?></p>
            </article>

            <section class="card discussion-replies">
                <div class="section-heading-row">
                    <h2>Réponses</h2>
                    <span class="badge"><?= e((string)count($comments)) ?> commentaire<?= count($comments) > 1 ? 's' : '' ?></span>
                </div>

                <?php if (!$comments): ?>
                    <p class="muted">Aucune réponse pour l’instant.</p>
                <?php else: ?>
                    <div class="comments-list discussion-comments-list">
                        <?php foreach ($comments as $index => $comment): ?>
                            <article class="comment discussion-comment">
                                <div class="discussion-comment-index">#<?= e((string)($index + 1)) ?></div>
                                <div>
                                    <p><?= nl2br(e((string)$comment['content'])) ?></p>
                                    <p class="meta">
                                        <?= e((string)($comment['display_name'] ?: $comment['username'])) ?>
                                        · <?= e((string)$comment['created_at']) ?>
                                    </p>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card discussion-reply-form">
                <h2>Répondre</h2>
                <form method="post" action="<?= e(url('discussion.php?post_id=' . $postId . '#comments')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="post_comment">
                    <input type="hidden" name="post_id" value="<?= e((string)$postId) ?>">
                    <label for="comment_content">Commentaire</label>
                    <textarea id="comment_content" name="comment_content" required placeholder="Votre réponse..."><?= e(post_value('comment_content')) ?></textarea>
                    <div class="form-actions">
                        <button type="submit" class="button-primary">Publier la réponse</button>
                    </div>
                </form>
            </section>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
