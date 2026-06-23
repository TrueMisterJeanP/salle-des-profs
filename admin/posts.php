<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/posts.php';
require_once __DIR__ . '/../includes/activitypub.php';

require_once __DIR__ . '/../includes/pagination.php';

require_admin();
posts_ensure_pinning_column();

$errors = [];
$groupOptions = user_group_options(0, true);
$editPostId = (int)get_value('edit', '0');
$editPost = null;

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $postId = (int)post_value('post_id', '0');

    if ($postId <= 0) {
        $errors[] = 'Publication invalide.';
    }

    if (!$errors) {
        $post = db_fetch_one(
            "SELECT * FROM posts WHERE id = :id LIMIT 1",
            ['id' => $postId]
        );

        if (!$post) {
            $errors[] = 'Publication introuvable.';
        }
    }

    if (!$errors) {
        try {
            if ($action === 'publish') {
                $before = activitypub_post_by_id_for_event($postId);
                db_query(
                    "UPDATE posts
                     SET is_published = 1,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $postId,
                    ]
                );
                activitypub_after_post_change($before, $postId);

                set_flash('success', 'Publication publiée.');
                redirect(admin_url('posts.php'));
            }

            if ($action === 'hide') {
                $before = activitypub_post_by_id_for_event($postId);
                db_query(
                    "UPDATE posts
                     SET is_published = 0,
                         pinned_at = NULL,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $postId,
                    ]
                );
                activitypub_after_post_change($before, $postId);

                set_flash('success', 'Publication masquée.');
                redirect(admin_url('posts.php'));
            }

            if ($action === 'change_visibility') {
                $visibility = post_value('visibility', 'members');
                $groupId = (int)post_value('group_id', '0');
                $selectedGroupId = null;
                $allowedVisibilities = array_keys(content_visibility_options(false));

                if (!in_array($visibility, $allowedVisibilities, true)) {
                    $errors[] = 'Visibilité invalide.';
                }
                $selectedGroupId = normalize_group_visibility($visibility, $groupId, $errors);
                if ($selectedGroupId !== null && !user_can_use_group(0, $selectedGroupId, true)) {
                    $errors[] = 'Groupe invalide.';
                }

                if (!$errors) {
                    $before = activitypub_post_by_id_for_event($postId);
                    db_query(
                        "UPDATE posts
                         SET visibility = :visibility,
                             group_id = :group_id,
                             pinned_at = NULL,
                             updated_at = :updated_at
                         WHERE id = :id",
                        [
                            'visibility' => $visibility,
                            'group_id' => $selectedGroupId,
                            'updated_at' => now(),
                            'id' => $postId,
                        ]
                    );
                    activitypub_after_post_change($before, $postId);

                    set_flash('success', 'Visibilité de la publication mise à jour.');
                    redirect(admin_url('posts.php'));
                }
            }

            if ($action === 'update') {
                $content = post_value('content');
                $visibility = post_value('visibility', 'members');
                $groupId = (int)post_value('group_id', '0');
                $selectedGroupId = null;
                $isPublished = post_value('is_published', '0') === '1' ? 1 : 0;
                $allowedVisibilities = array_keys(content_visibility_options(false));
                $poll = post_poll_parse_input($errors);

                if ($content === '') {
                    $errors[] = 'Le contenu de la publication est obligatoire.';
                }

                if (!in_array($visibility, $allowedVisibilities, true)) {
                    $errors[] = 'Visibilité invalide.';
                }
                $selectedGroupId = normalize_group_visibility($visibility, $groupId, $errors);
                if ($selectedGroupId !== null && !user_can_use_group(0, $selectedGroupId, true)) {
                    $errors[] = 'Groupe invalide.';
                }

                if (!$errors) {
                    $before = activitypub_post_by_id_for_event($postId);
                    db_query(
                        "UPDATE posts
                         SET content = :content,
                             visibility = :visibility,
                             group_id = :group_id,
                             is_published = :is_published,
                             pinned_at = NULL,
                             updated_at = :updated_at
                         WHERE id = :id",
                        [
                            'content' => $content,
                            'visibility' => $visibility,
                            'group_id' => $selectedGroupId,
                            'is_published' => $isPublished,
                            'updated_at' => now(),
                            'id' => $postId,
                        ]
                    );
                    post_poll_save_for_post($postId, $poll);
                    activitypub_after_post_change($before, $postId);

                    set_flash('success', 'Publication mise à jour.');
                    redirect(admin_url('posts.php'));
                }
            }

            if ($action === 'reset_poll') {
                post_poll_reset_votes_for_post($postId);

                set_flash('success', 'Vote remis à zéro.');
                redirect(admin_url('posts.php?edit=' . $postId));
            }

            if ($action === 'censor') {
                $before = activitypub_post_by_id_for_event($postId);
                db_query(
                    "UPDATE posts
                     SET content = :content,
                         is_published = 0,
                         pinned_at = NULL,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'content' => '[Publication censurée par la modération]',
                        'updated_at' => now(),
                        'id' => $postId,
                    ]
                );
                activitypub_after_post_change($before, $postId);

                set_flash('success', 'Publication censurée et masquée.');
                redirect(admin_url('posts.php'));
            }

            if ($action === 'pin') {
                $before = activitypub_post_by_id_for_event($postId);
                post_pin($postId);
                activitypub_after_post_change($before, $postId);
                set_flash('success', 'Publication épinglée.');
                redirect(admin_url('posts.php'));
            }

            if ($action === 'unpin') {
                $before = activitypub_post_by_id_for_event($postId);
                post_unpin($postId);
                activitypub_after_post_change($before, $postId);
                set_flash('success', 'Publication désépinglée.');
                redirect(admin_url('posts.php'));
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
                    "DELETE FROM posts WHERE id = :id",
                    ['id' => $postId]
                );
                activitypub_after_post_delete($before);

                set_flash('success', 'Publication supprimée.');
                redirect(admin_url('posts.php'));
            }

            if (!in_array($action, ['publish', 'hide', 'change_visibility', 'update', 'reset_poll', 'censor', 'pin', 'unpin', 'delete'], true)) {
                $errors[] = 'Action inconnue.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

if ($editPostId > 0) {
    $editPost = db_fetch_one(
        "SELECT posts.*,
                users.username,
                users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE posts.id = :id
         LIMIT 1",
        ['id' => $editPostId]
    );

    if (!$editPost) {
        $errors[] = 'Publication à éditer introuvable.';
    } else {
        $editPost['poll'] = post_poll_for_post((int)$editPost['id']);
    }
}

$search = get_value('q');
$params = [];
$whereParts = [];

if ($search !== '') {
    $whereParts[] = "(posts.content LIKE :q OR users.username LIKE :q OR users.display_name LIKE :q)";
    $params['q'] = '%' . $search . '%';
}

$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

    $page = current_page();
    $perPage = 10;
    $offset = pagination_offset($page, $perPage);
    
    $totalPosts = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
        FROM posts
        JOIN users ON users.id = posts.author_id
        $where",
        $params
    )['total'] ?? 0);
    
    $totalPages = total_pages($totalPosts, $perPage);
    
    $posts = db_fetch_all(
        "SELECT posts.*,
        users.username,
        users.display_name,
        groups.name AS group_name
        FROM posts
        JOIN users ON users.id = posts.author_id
        LEFT JOIN groups ON groups.id = posts.group_id
        $where
        ORDER BY posts.created_at DESC
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
    <title>Annonces — Administration — <?= e(APP_NAME) ?></title>
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
            <h1>Administration des annonces</h1>
            <p class="muted">
                Modérez les annonces courtes.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
                <a class="button-secondary" href="<?= e(url('feed.php')) ?>">Voir les publications</a>
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
                    <a class="button-secondary" href="<?= e(admin_url('posts.php')) ?>">Réinitialiser</a>
                </div>
            </form>
        </section>

        <?php if ($editPost): ?>
            <section class="card">
                <h2>Éditer la publication #<?= e((string)$editPost['id']) ?></h2>
                <p class="muted">
                    Auteur : <?= e($editPost['display_name'] ?: $editPost['username']) ?>
                    · Créé le <?= e($editPost['created_at']) ?>
                </p>

                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="post_id" value="<?= e((string)$editPost['id']) ?>">

                    <div class="markdown-toolbar" aria-label="Barre d’outils Markdown" data-md-target="edit-content">
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

                    <label for="edit-content">Contenu</label>
                    <textarea id="edit-content" name="content" class="article-editor" required><?= e($editPost['content']) ?></textarea>

                    <div class="grid grid-2">
                        <div>
                            <label for="edit-visibility">Visibilité</label>
                            <select id="edit-visibility" name="visibility">
                                <option value="private" <?= $editPost['visibility'] === 'private' ? 'selected' : '' ?>>
                                    Privé
                                </option>
                                <option value="members" <?= $editPost['visibility'] === 'members' ? 'selected' : '' ?>>
                                    Membres
                                </option>
                                <option value="group" <?= $editPost['visibility'] === 'group' ? 'selected' : '' ?>>
                                    Groupe
                                </option>
                            </select>
                            <div data-group-visibility-field <?= $editPost['visibility'] === 'group' ? '' : 'hidden' ?>>
                                <label for="edit-group-id">Groupe</label>
                                <select id="edit-group-id" name="group_id">
                                    <option value="">Choisir un groupe</option>
                                    <?php foreach ($groupOptions as $group): ?>
                                        <option value="<?= e((string)$group['id']) ?>" <?= (int)($editPost['group_id'] ?? 0) === (int)$group['id'] ? 'selected' : '' ?>>
                                            <?= e($group['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="edit-is-published">État</label>
                            <select id="edit-is-published" name="is_published">
                                <option value="1" <?= (int)$editPost['is_published'] === 1 ? 'selected' : '' ?>>
                                    Publié
                                </option>
                                <option value="0" <?= (int)$editPost['is_published'] === 0 ? 'selected' : '' ?>>
                                    Masqué
                                </option>
                            </select>
                        </div>
                    </div>

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

                        <label for="edit-poll-question">Question du vote</label>
                        <input
                            type="text"
                            id="edit-poll-question"
                            name="poll_question"
                            maxlength="240"
                            value="<?= e($editPollQuestion) ?>"
                            placeholder="Ex. Quelle option préférez-vous ?"
                        >

                        <label for="edit-poll-options">Réponses possibles</label>
                        <textarea
                            id="edit-poll-options"
                            name="poll_options"
                            rows="4"
                            placeholder="Oui&#10;Non&#10;Je ne sais pas"
                        ><?= e($editPollOptions) ?></textarea>

                        <label for="edit-poll-duration-days">Date limite de vote, en jours</label>
                        <input
                            type="number"
                            id="edit-poll-duration-days"
                            name="poll_duration_days"
                            min="0"
                            max="3650"
                            step="1"
                            value="<?= e($editPollDurationDays) ?>"
                            placeholder="0 = aucune limite"
                        >
                    </div>

                    <div class="form-actions">
                        <button
                            type="submit"
                            name="action"
                            value="reset_poll"
                            class="button-danger"
                            onclick="return confirm('Remettre à zéro les réponses déjà enregistrées pour ce vote ?');"
                        >
                            Remettre le vote à zéro
                        </button>
                        <button type="submit" class="button-primary">Enregistrer</button>
                        <a class="button-secondary" href="<?= e(admin_url('posts.php')) ?>">Annuler</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="card">
            <h2>Liste des publications</h2>

            <?php if (!$posts): ?>
                <p class="muted">Aucune publication trouvée.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Contenu</th>
                                <th>Auteur</th>
                                <th>Visibilité</th>
                                <th>État</th>
                                <th>Création</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($posts as $post): ?>
                                <tr>
                                    <td>
                                        <div class="post-content markdown-content">
                                            <?= render_markdown(excerpt($post['content'], 160)) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?= e($post['display_name'] ?: $post['username']) ?><br>
                                        <span class="meta">@<?= e($post['username']) ?></span>
                                    </td>
                                    <td>
                                        <form method="post" action="" class="inline-form" data-visibility-group-form>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="change_visibility">
                                            <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                                            <select name="visibility">
                                                <option value="private" <?= $post['visibility'] === 'private' ? 'selected' : '' ?>>
                                                    Privé
                                                </option>
                                                <option value="members" <?= $post['visibility'] === 'members' ? 'selected' : '' ?>>
                                                    Membres
                                                </option>
                                                <option value="group" <?= $post['visibility'] === 'group' ? 'selected' : '' ?>>
                                                    Groupe
                                                </option>
                                            </select>
                                            <span data-group-visibility-field <?= $post['visibility'] === 'group' ? '' : 'hidden' ?>>
                                                <select name="group_id">
                                                    <option value="">Groupe</option>
                                                    <?php foreach ($groupOptions as $group): ?>
                                                        <option value="<?= e((string)$group['id']) ?>" <?= (int)($post['group_id'] ?? 0) === (int)$group['id'] ? 'selected' : '' ?>>
                                                            <?= e($group['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </span>
                                        </form>
                                        <?php if (!in_array($post['visibility'], ['private', 'members'], true)): ?>
                                            <span class="meta"><?= e(visibility_label($post['visibility'], $post['group_name'] ?? null)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int)$post['is_published'] === 1): ?>
                                            <span class="badge badge-active">Publié</span>
                                        <?php else: ?>
                                            <span class="badge badge-disabled">Masqué</span>
                                        <?php endif; ?>
                                        <?php if (!empty($post['pinned_at'])): ?>
                                            <br><span class="badge badge-pinned">Épinglé</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= e($post['created_at']) ?></td>
                                    <td>
                                        <div class="admin-actions admin-post-actions">
                                            <a
                                                class="button-secondary"
                                                href="<?= e(admin_url('posts.php?edit=' . (int)$post['id'])) ?>"
                                            >
                                                Éditer
                                            </a>

                                            <?php if ((int)$post['is_published'] === 1): ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="hide">
                                                    <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                                                    <button type="submit" class="button-secondary">Masquer</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="publish">
                                                    <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                                                    <button type="submit" class="button-secondary">Publier</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if (!empty($post['pinned_at'])): ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="unpin">
                                                    <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                                                    <button type="submit" class="button-secondary">Désépingler</button>
                                                </form>
                                            <?php elseif ((int)$post['is_published'] === 1 && in_array($post['visibility'], ['members', 'group'], true)): ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="pin">
                                                    <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                                                    <button type="submit" class="button-pinned">Épinglé</button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="post" action="" onsubmit="return confirm('Censurer cette publication ? Son contenu sera remplacé et la publication sera masquée.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="censor">
                                                <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
                                                <button type="submit" class="button-danger">Censurer</button>
                                            </form>

                                            <form method="post" action="" onsubmit="return confirm('Supprimer définitivement cette publication ?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="post_id" value="<?= e((string)$post['id']) ?>">
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
    <script src="<?= e(url('assets/app.js')) ?>"></script>
    <script>
        document.querySelectorAll('form[data-visibility-group-form], form').forEach((form) => {
            const visibility = form.querySelector('select[name="visibility"]');
            const groupField = form.querySelector('[data-group-visibility-field]');

            if (!visibility || !groupField) {
                return;
            }

            const syncGroupField = () => {
                groupField.hidden = visibility.value !== 'group';
            };

            visibility.addEventListener('change', () => {
                syncGroupField();

                if (form.matches('[data-visibility-group-form]') && visibility.value !== 'group') {
                    form.submit();
                }
            });

            const groupSelect = groupField.querySelector('select[name="group_id"]');
            if (groupSelect && form.matches('[data-visibility-group-form]')) {
                groupSelect.addEventListener('change', () => {
                    if (visibility.value === 'group' && groupSelect.value !== '') {
                        form.submit();
                    }
                });
            }

            syncGroupField();
        });
    </script>
</body>
</html>
