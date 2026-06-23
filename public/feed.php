<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/activitypub.php';
require_once __DIR__ . '/../includes/mastodon.php';

require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/posts.php';

require_login();
posts_ensure_pinning_column();
posts_ensure_attachments_table();

$user = current_user();
$errors = [];
$mastodonConfigured = mastodon_is_configured((int)$user['id']);
$defaultVisibility = setting_value('default_visibility', 'members');

if (!in_array($defaultVisibility, ['private', 'members', 'public'], true)) {
    $defaultVisibility = 'members';
}
$groupOptions = user_group_options((int)$user['id']);
$availableAttachments = user_attachment_options((int)$user['id'], 100);
$page = current_page();
$perPage = 20;
$offset = pagination_offset($page, $perPage);
$returnUrl = url('feed.php' . ($page > 1 ? '?page=' . $page : ''));
$editPostId = (int)get_value('edit', '0');
$editPost = null;

if (is_post()) {
    require_csrf();

    $action = post_value('action', 'create');
    $content = post_value('content');
    $visibility = post_value('visibility', $defaultVisibility);
    $groupId = (int)post_value('group_id', '0');
    $attachmentId = (int)post_value('attachment_id', '0');
    $selectedGroupId = null;
    $allowedVisibilities = array_keys(content_visibility_options(true));

    if ($action === 'poll_vote') {
        try {
            $votePostId = (int)post_value('post_id', '0');
            post_poll_submit_vote($votePostId, (int)post_value('option_id', '0'), (int)$user['id'], 'feed');
            set_flash('success', 'Vote enregistré.');
            redirect($returnUrl . '#post-' . $votePostId);
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    } elseif ($action === 'post_comment') {
        $commentPostId = (int)post_value('post_id', '0');

        try {
            post_comment_create($commentPostId, (int)$user['id'], post_value('comment_content'));
            set_flash('success', 'Commentaire ajouté.');
            redirect($returnUrl . '#post-' . $commentPostId);
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    } elseif ($action === 'create') {
        $poll = post_poll_parse_input($errors);

        if ($content === '') {
            $errors[] = 'Le contenu de la publication est obligatoire.';
        }

        if (!in_array($visibility, $allowedVisibilities, true)) {
            $errors[] = 'Visibilité invalide.';
        }
        $selectedGroupId = normalize_group_visibility($visibility, $groupId, $errors);
        if ($selectedGroupId !== null && !user_can_use_group((int)$user['id'], $selectedGroupId)) {
            $errors[] = 'Groupe invalide.';
        }
        if ($attachmentId > 0 && !post_user_owns_attachment($attachmentId, (int)$user['id'])) {
            $errors[] = 'Fichier invalide.';
        }

        if (!$errors) {
            try {
                $postId = db_insert(
                    "INSERT INTO posts (author_id, content, visibility, group_id, is_published, created_at)
                     VALUES (:author_id, :content, :visibility, :group_id, 1, :created_at)",
                    [
                        'author_id' => $user['id'],
                        'content' => $content,
                        'visibility' => $visibility,
                        'group_id' => $selectedGroupId,
                        'created_at' => now(),
                    ]
                );
                post_poll_save_for_post($postId, $poll);
                sync_post_attachment($postId, $attachmentId, (int)$user['id']);

                activitypub_after_post_change(null, $postId);

                set_flash('success', 'Publication publiée.');
                redirect(url('feed.php'));
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update' || $action === 'delete' || $action === 'reset_poll') {
        $postId = (int)post_value('post_id', '0');

        if ($postId <= 0) {
            $errors[] = 'Publication invalide.';
        }

        $post = null;

        if (!$errors) {
            $post = db_fetch_one(
                "SELECT *
                 FROM posts
                 WHERE id = :id
                   AND author_id = :author_id
                   AND is_published = 1
                 LIMIT 1",
                [
                    'id' => $postId,
                    'author_id' => $user['id'],
                ]
            );

            if (!$post) {
                $errors[] = 'Publication introuvable ou non modifiable.';
            }
        }

        if ($action === 'update') {
            $poll = post_poll_parse_input($errors);

            if ($content === '') {
                $errors[] = 'Le contenu de la publication est obligatoire.';
            }

            if (!in_array($visibility, $allowedVisibilities, true)) {
                $errors[] = 'Visibilité invalide.';
            }
            $selectedGroupId = normalize_group_visibility($visibility, $groupId, $errors);
            if ($selectedGroupId !== null && !user_can_use_group((int)$user['id'], $selectedGroupId)) {
                $errors[] = 'Groupe invalide.';
            }
            if ($attachmentId > 0 && !post_user_owns_attachment($attachmentId, (int)$user['id'])) {
                $errors[] = 'Fichier invalide.';
            }

            if ($errors && $post) {
                $editPost = array_merge($post, [
                    'content' => $content,
                    'visibility' => $visibility,
                    'group_id' => $selectedGroupId,
                    'attachment_id' => $attachmentId,
                ]);
            }
        }

        if (!$errors) {
            try {
                if ($action === 'update') {
                    $before = activitypub_post_by_id_for_event($postId);
                    db_query(
                        "UPDATE posts
                         SET content = :content,
                             visibility = :visibility,
                             group_id = :group_id,
                             pinned_at = NULL,
                             updated_at = :updated_at
                         WHERE id = :id
                           AND author_id = :author_id",
                        [
                            'content' => $content,
                            'visibility' => $visibility,
                            'group_id' => $selectedGroupId,
                            'updated_at' => now(),
                            'id' => $postId,
                            'author_id' => $user['id'],
                        ]
                    );
                    post_poll_save_for_post($postId, $poll);
                    sync_post_attachment($postId, $attachmentId, (int)$user['id']);
                    activitypub_after_post_change($before, $postId);

                    set_flash('success', 'Publication mise à jour.');
                    redirect($returnUrl);
                }

                if ($action === 'reset_poll') {
                    post_poll_reset_votes_for_post($postId);

                    set_flash('success', 'Vote remis à zéro.');
                    redirect(url('feed.php?edit=' . $postId . ($page > 1 ? '&page=' . $page : '') . '#modifier-post'));
                }

                if ($action === 'delete') {
                    $before = activitypub_post_by_id_for_event($postId);
                    db_query(
                        "DELETE FROM comments
                         WHERE target_type = 'post'
                           AND target_id = :post_id",
                        ['post_id' => $postId]
                    );
                    db_query(
                        "DELETE FROM post_attachments
                         WHERE post_id = :post_id",
                        ['post_id' => $postId]
                    );
                    db_query(
                        "DELETE FROM posts
                         WHERE id = :id
                           AND author_id = :author_id",
                        [
                            'id' => $postId,
                            'author_id' => $user['id'],
                        ]
                    );
                    activitypub_after_post_delete($before);

                    set_flash('success', 'Publication supprimée.');
                    redirect($returnUrl);
                }
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    } else {
        $errors[] = 'Action inconnue.';
    }
}

if ($editPostId > 0) {
    $editPost = db_fetch_one(
        "SELECT *
         FROM posts
         WHERE id = :id
           AND author_id = :author_id
           AND is_published = 1
         LIMIT 1",
        [
            'id' => $editPostId,
            'author_id' => $user['id'],
        ]
    );

    if (!$editPost) {
        set_flash('error', 'Publication introuvable ou non modifiable.');
        redirect($returnUrl);
    }

    $editPost['poll'] = post_poll_for_post((int)$editPost['id']);
    $editPost['attachment_id'] = post_attachment_id((int)$editPost['id']);
}

$totalPosts = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM posts
     WHERE posts.is_published = 1
       AND posts.author_id = :current_user_id",
    ['current_user_id' => $user['id']]
)['total'] ?? 0);

$totalPages = total_pages($totalPosts, $perPage);

$posts = db_fetch_all(
    "SELECT posts.*, users.username, users.display_name, users.avatar, groups.name AS group_name
     FROM posts
     JOIN users ON users.id = posts.author_id
     LEFT JOIN groups ON groups.id = posts.group_id
     WHERE posts.is_published = 1
       AND posts.author_id = :current_user_id
     ORDER BY posts.created_at DESC
     LIMIT :limit OFFSET :offset",
    [
        'current_user_id' => $user['id'],
        'limit' => $perPage,
        'offset' => $offset,
    ]
);

$posts = posts_attach_attachments(posts_attach_comments(posts_attach_polls($posts, (int)$user['id'])));

$flashes = get_flashes();
$createVisibility = post_value('visibility', $defaultVisibility);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Mes annonces — <?= e(APP_NAME) ?></title>
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
            <h1>Mes annonces</h1>
            <p class="muted">
                Publiez des messages courts, des idées, des annonces ou des ressources rapides.
            </p>
        </section>

        <section class="card">
            <h2>Nouvelle publication</h2>

            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">

                <div class="markdown-toolbar" aria-label="Barre d’outils Markdown" data-md-target="content">
                    <button type="button" data-md-action="h1" title="Titre niveau 1">H1</button>
                    <button type="button" data-md-action="h2" title="Titre niveau 2">H2</button>
                    <button type="button" data-md-action="h3" title="Titre niveau 3">H3</button>

                    <span class="toolbar-separator"></span>

                    <button type="button" data-md-action="bold" title="Gras"><strong>B</strong></button>
                    <button type="button" data-md-action="italic" title="Italique"><em>I</em></button>
                    <button type="button" data-md-action="inline-code" title="Code inline">&lt;/&gt;</button>

                    <span class="toolbar-separator"></span>

                    <button type="button" data-md-action="link" title="Lien">🔗</button>
                    <button type="button" data-md-action="list" title="Liste">• Liste</button>
                    <button type="button" data-md-action="table" title="Tableau">▦ Tableau</button>
                    <button type="button" data-md-action="code-block" title="Bloc de code">```</button>

                    <span class="toolbar-separator"></span>

                    <button type="button" data-md-action="math-inline" title="Formule inline">$x$</button>
                    <button type="button" data-md-action="math-block" title="Formule bloc">$$</button>
                </div>

                <label for="content">Contenu</label>
                <textarea
                    id="content"
                    name="content"
                    class="article-editor"
                    placeholder="Écrire un message court..."
                    required
                ><?= e(post_value('content')) ?></textarea>

                <?php if ($availableAttachments): ?>
                    <label for="post-attachments">Fichier à associer</label>
                    <select id="post-attachments" name="attachment_id">
                        <option value="">Aucun fichier associé</option>
                        <?php foreach ($availableAttachments as $attachment): ?>
                            <option
                                value="<?= e((string)$attachment['id']) ?>"
                                <?= (int)post_value('attachment_id', '0') === (int)$attachment['id'] ? 'selected' : '' ?>
                            >
                                <?= e($attachment['original_name'] . ' — ' . human_file_size((int)$attachment['size'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <div class="post-poll-editor">
                    <h3>Vote dans la publication</h3>
                    <p class="meta">Optionnel : ajoutez une question et une réponse par ligne.</p>

                    <label for="poll_question">Question du vote</label>
                    <input
                        type="text"
                        id="poll_question"
                        name="poll_question"
                        maxlength="240"
                        value="<?= e(post_value('poll_question')) ?>"
                        placeholder="Ex. Quelle option préférez-vous ?"
                    >

                    <label for="poll_options">Réponses possibles</label>
                    <textarea
                        id="poll_options"
                        name="poll_options"
                        rows="4"
                        placeholder="Oui&#10;Non&#10;Je ne sais pas"
                    ><?= e(post_value('poll_options')) ?></textarea>

                    <label for="poll_duration_days">Date limite de vote, en jours</label>
                    <input
                        type="number"
                        id="poll_duration_days"
                        name="poll_duration_days"
                        min="0"
                        max="3650"
                        step="1"
                        value="<?= e(post_value('poll_duration_days')) ?>"
                        placeholder="0 = aucune limite"
                    >
                </div>

                <div class="post-visibility-field">
                    <label for="visibility">Visibilité</label>
                    <select id="visibility" name="visibility">
                        <option value="public" <?= $createVisibility === 'public' ? 'selected' : '' ?>>
                            Public — visible publiquement et ActivityPub
                        </option>
                        <option value="private" <?= $createVisibility === 'private' ? 'selected' : '' ?>>
                            Privé — seulement moi
                        </option>
                        <option value="members" <?= $createVisibility === 'members' ? 'selected' : '' ?>>
                            Membres — utilisateurs connectés
                        </option>
                        <option value="group" <?= $createVisibility === 'group' ? 'selected' : '' ?>>
                            Groupe
                        </option>
                    </select>
                    <label for="group_id">Groupe</label>
                    <select id="group_id" name="group_id">
                        <option value="">Choisir un groupe</option>
                        <?php foreach ($groupOptions as $group): ?>
                            <option value="<?= e((string)$group['id']) ?>" <?= (int)post_value('group_id', '0') === (int)$group['id'] ? 'selected' : '' ?>>
                                <?= e($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" class="button-primary">Publier</button>
                </div>
            </form>
        </section>

        <?php if ($editPost): ?>
            <section class="card" id="modifier-post">
                <h2>Modifier la publication</h2>

                <form method="post" action="<?= e($returnUrl) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="post_id" value="<?= e((string)$editPost['id']) ?>">

                    <div class="markdown-toolbar" aria-label="Barre d’outils Markdown" data-md-target="edit_content">
                        <button type="button" data-md-action="h1" title="Titre niveau 1">H1</button>
                        <button type="button" data-md-action="h2" title="Titre niveau 2">H2</button>
                        <button type="button" data-md-action="h3" title="Titre niveau 3">H3</button>

                        <span class="toolbar-separator"></span>

                        <button type="button" data-md-action="bold" title="Gras"><strong>B</strong></button>
                        <button type="button" data-md-action="italic" title="Italique"><em>I</em></button>
                        <button type="button" data-md-action="inline-code" title="Code inline">&lt;/&gt;</button>

                        <span class="toolbar-separator"></span>

                        <button type="button" data-md-action="link" title="Lien">🔗</button>
                        <button type="button" data-md-action="list" title="Liste">• Liste</button>
                        <button type="button" data-md-action="table" title="Tableau">▦ Tableau</button>
                        <button type="button" data-md-action="code-block" title="Bloc de code">```</button>

                        <span class="toolbar-separator"></span>

                        <button type="button" data-md-action="math-inline" title="Formule inline">$x$</button>
                        <button type="button" data-md-action="math-block" title="Formule bloc">$$</button>
                    </div>

                    <label for="edit_content">Contenu</label>
                    <textarea
                        id="edit_content"
                        name="content"
                        class="article-editor"
                        required
                    ><?= e($editPost['content']) ?></textarea>

                    <?php if ($availableAttachments): ?>
                        <?php
                        $editSelectedAttachmentId = is_post() && post_value('action') === 'update'
                            ? (int)post_value('attachment_id', '0')
                            : (int)($editPost['attachment_id'] ?? 0);
                        ?>
                        <label for="edit-post-attachments">Fichier à associer</label>
                        <select id="edit-post-attachments" name="attachment_id">
                            <option value="">Aucun fichier associé</option>
                            <?php foreach ($availableAttachments as $attachment): ?>
                                <option
                                    value="<?= e((string)$attachment['id']) ?>"
                                    <?= $editSelectedAttachmentId === (int)$attachment['id'] ? 'selected' : '' ?>
                                >
                                    <?= e($attachment['original_name'] . ' — ' . human_file_size((int)$attachment['size'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <label for="edit_visibility">Visibilité</label>
                    <select id="edit_visibility" name="visibility">
                        <option value="public" <?= $editPost['visibility'] === 'public' ? 'selected' : '' ?>>
                            Public — visible publiquement et ActivityPub
                        </option>
                        <option value="private" <?= $editPost['visibility'] === 'private' ? 'selected' : '' ?>>
                            Privé — seulement moi
                        </option>
                        <option value="members" <?= $editPost['visibility'] === 'members' ? 'selected' : '' ?>>
                            Membres — utilisateurs connectés
                        </option>
                        <option value="group" <?= $editPost['visibility'] === 'group' ? 'selected' : '' ?>>
                            Groupe
                        </option>
                    </select>
                    <label for="edit_group_id">Groupe</label>
                    <select id="edit_group_id" name="group_id">
                        <option value="">Choisir un groupe</option>
                        <?php foreach ($groupOptions as $group): ?>
                            <option value="<?= e((string)$group['id']) ?>" <?= (int)($editPost['group_id'] ?? 0) === (int)$group['id'] ? 'selected' : '' ?>>
                                <?= e($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php
                    $editPollQuestion = is_post() && post_value('action') === 'update'
                        ? post_value('poll_question')
                        : (string)($editPost['poll']['question'] ?? '');
                    $editPollOptions = is_post() && post_value('action') === 'update'
                        ? post_value('poll_options')
                        : post_poll_options_text($editPost['poll'] ?? null);
                    $editPollDurationDays = is_post() && post_value('action') === 'update'
                        ? post_value('poll_duration_days')
                        : post_poll_duration_days_value($editPost['poll'] ?? null);
                    ?>
                    <div class="post-poll-editor">
                        <h3>Vote dans la publication</h3>
                        <p class="meta">Laisser ces champs vides retire le vote de la publication.</p>

                        <label for="edit_poll_question">Question du vote</label>
                        <input
                            type="text"
                            id="edit_poll_question"
                            name="poll_question"
                            maxlength="240"
                            value="<?= e($editPollQuestion) ?>"
                        >

                        <label for="edit_poll_options">Réponses possibles</label>
                        <textarea
                            id="edit_poll_options"
                            name="poll_options"
                            rows="4"
                        ><?= e($editPollOptions) ?></textarea>

                        <label for="edit_poll_duration_days">Date limite de vote, en jours</label>
                        <input
                            type="number"
                            id="edit_poll_duration_days"
                            name="poll_duration_days"
                            min="0"
                            max="3650"
                            step="1"
                            value="<?= e($editPollDurationDays) ?>"
                            placeholder="0 = aucune limite"
                        >
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button-primary">Enregistrer</button>
                        <a class="button-secondary" href="<?= e($returnUrl) ?>">Annuler</a>
                        <button
                            type="submit"
                            name="action"
                            value="reset_poll"
                            class="button-danger"
                            onclick="return confirm('Remettre à zéro les réponses déjà enregistrées pour ce vote ?');"
                        >
                            Remettre le vote à zéro
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
        
        <?php if ($totalPages > 1): ?>
            <section class="card">
                <?= render_pagination($page, $totalPages) ?>
            </section>
        <?php endif; ?>

        <section class="feed-list">
            <?php if (!$posts): ?>
                <article class="card">
                    <p class="muted">Aucune publication pour l’instant.</p>
                </article>
            <?php else: ?>
                <?php foreach ($posts as $post): ?>
                    <article class="post" id="post-<?= e((string)$post['id']) ?>">
                        <div class="post-header">
                            <div>
                                <strong><?= e($post['display_name'] ?: $post['username']) ?></strong>
                                <span class="meta">@<?= e($post['username']) ?></span>
                            </div>

                            <span class="post-header-right">
                                <span class="badge">
                                    <?= e(visibility_label($post['visibility'], $post['group_name'] ?? null)) ?>
                                </span>
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
                        <?= render_post_attachments($post) ?>

                        <?= render_post_poll($post) ?>

                        <p class="meta">
                            Publié le <?= e($post['created_at']) ?>
                        </p>
                        <?php if ((int)$post['author_id'] === (int)$user['id']): ?>
                            <?php $deleteFormId = 'delete-post-' . (int)$post['id']; ?>
                            <form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($returnUrl) ?>" hidden>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                            </form>
                            <?php ob_start(); ?>
                            <a
                                class="button-secondary post-action-edit"
                                href="<?= e(url('feed.php?edit=' . (int)$post['id'] . ($page > 1 ? '&page=' . $page : '') . '#modifier-post')) ?>"
                            >
                                Modifier
                            </a>
                            <?php $postActionsBeforeHtml = (string)ob_get_clean(); ?>
                            <?php ob_start(); ?>
                            <button
                                type="submit"
                                class="button-danger"
                                form="<?= e($deleteFormId) ?>"
                                onclick="return confirm('Supprimer cette publication ?');"
                            >
                                Supprimer
                            </button>
                            <?php if ($post['visibility'] === 'public' && $mastodonConfigured): ?>
                                <button
                                    type="button"
                                    data-publish-mastodon
                                    data-content-type="post"
                                    data-content-id="<?= e((string)$post['id']) ?>"
                                >
                                    Publier sur Mastodon
                                </button>
                            <?php endif; ?>
                            <?php $postActionsAfterHtml = (string)ob_get_clean(); ?>
                        <?php else: ?>
                            <?php $postActionsBeforeHtml = ''; ?>
                            <?php $postActionsAfterHtml = ''; ?>
                        <?php endif; ?>
                        <?= render_post_comments($post, $returnUrl . '#post-' . (int)$post['id'], $postActionsBeforeHtml, $postActionsAfterHtml) ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>
    
    <?php require __DIR__ . '/../templates/footer.php'; ?>
    
    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
