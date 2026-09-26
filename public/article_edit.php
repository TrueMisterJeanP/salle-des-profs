<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/tags.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/activitypub.php';

require_login();

$user = current_user();
$errors = [];
ensure_content_group_column('articles');

$articleSlug = get_value('slug');
// Ancienne adresse article_edit.php?id=12, redirigée vers l'URL lisible une fois les droits vérifiés.
$articleId = (int)get_value('id', '0');
$article = null;

if ($articleSlug !== '') {
    $articleBySlug = db_fetch_one("SELECT id FROM articles WHERE slug = :slug LIMIT 1", ['slug' => $articleSlug]);

    if (!$articleBySlug) {
        http_response_code(404);
        exit('Article introuvable.');
    }

    $articleId = (int)$articleBySlug['id'];
}
$defaultVisibility = setting_value('default_visibility', 'members');

if (!in_array($defaultVisibility, ['private', 'members', 'public'], true)) {
    $defaultVisibility = 'members';
}
$groupOptions = user_group_options((int)$user['id']);

function generate_unique_slug(string $title, ?int $ignoreArticleId = null): string
{
    $baseSlug = slugify($title);
    $slug = $baseSlug;
    $counter = 2;

    while (true) {
        $params = ['slug' => $slug];
        $sql = "SELECT id FROM articles WHERE slug = :slug";

        if ($ignoreArticleId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $ignoreArticleId;
        }

        $existing = db_fetch_one($sql . " LIMIT 1", $params);

        if (!$existing) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

if ($articleId > 0) {
    $article = db_fetch_one(
        "SELECT *
         FROM articles
         WHERE id = :id
         LIMIT 1",
        ['id' => $articleId]
    );

    if (!$article) {
        http_response_code(404);
        exit('Article introuvable.');
    }

    $canEdit = (int)$article['author_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin';

    if (!$canEdit) {
        http_response_code(403);
        exit('Vous ne pouvez pas modifier cet article.');
    }

    redirect_legacy_slug_url(article_edit_url((string)$article['slug']));
}

$title = $article['title'] ?? '';
$content = $article['content'] ?? '';
$excerptValue = $article['excerpt'] ?? '';
$visibility = $article['visibility'] ?? $defaultVisibility;
$groupIdValue = (int)($article['group_id'] ?? 0);

if (!in_array($visibility, array_keys(content_visibility_options(true)), true)) {
    $visibility = 'members';
}
$status = $article['status'] ?? 'draft';
$selectedAttachmentIds = [];
$linkedAttachments = [];
$existingAttachmentIds = $articleId > 0 ? article_attachment_ids($articleId) : [];

if (is_post()) {
    require_csrf();

    $action = post_value('action', 'save');

    if ($action === 'remove_attachment') {
        $attachmentId = (int)post_value('attachment_id', '0');

        if ($articleId <= 0) {
            $errors[] = 'Article invalide.';
        }

        if ($attachmentId <= 0) {
            $errors[] = 'Fichier invalide.';
        }

        if (!$errors) {
            try {
                $beforeActivityPubArticle = activitypub_article_by_id_for_event($articleId);
                $updatedContent = remove_attachment_markdown_links((string)$article['content'], [$attachmentId]);
                $pdo = db();
                ensure_article_attachments_table();
                article_ensure_tag_tables();
                $pdo->beginTransaction();

                remove_article_attachment($articleId, $attachmentId);
                if ($updatedContent !== (string)$article['content']) {
                    db_query(
                        "UPDATE articles SET content = :content, updated_at = :updated_at WHERE id = :id",
                        [
                            'content' => $updatedContent,
                            'updated_at' => now(),
                            'id' => $articleId,
                        ]
                    );
                    article_sync_tags($articleId, (string)$article['title'], $updatedContent);
                }

                $pdo->commit();
                activitypub_after_article_change($beforeActivityPubArticle, $articleId);
                set_flash('success', 'Fichier retiré de l’article.');
                redirect(article_edit_url((string)$article['slug']));
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save') {
        $title = post_value('title');
        $content = post_value('content');
        $excerptValue = post_value('excerpt');
        $visibility = post_value('visibility', 'members');
        $groupIdValue = (int)post_value('group_id', '0');
        $status = post_value('status', 'draft');
        $selectedAttachmentIds = array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['attachment_ids'] ?? []))
        )));

        if ($articleId > 0) {
            $removedAttachmentIds = array_values(array_diff(
                $existingAttachmentIds,
                $selectedAttachmentIds
            ));
            $content = remove_attachment_markdown_links($content, $removedAttachmentIds);
        }

        $allowedVisibilities = array_keys(content_visibility_options(true));
        $allowedStatuses = ['draft', 'published'];

        if ($title === '') {
            $errors[] = 'Le titre est obligatoire.';
        }

        if ($content === '') {
            $errors[] = 'Le contenu est obligatoire.';
        }

        if (!in_array($visibility, $allowedVisibilities, true)) {
            $errors[] = 'Visibilité invalide.';
        }
        $selectedGroupId = normalize_group_visibility($visibility, $groupIdValue, $errors);
        if ($selectedGroupId !== null && !user_can_use_group((int)$user['id'], $selectedGroupId, ($user['role'] ?? '') === 'admin')) {
            $errors[] = 'Groupe invalide.';
        }

        if (!in_array($status, $allowedStatuses, true)) {
            $errors[] = 'Statut invalide.';
        }

        if ($selectedAttachmentIds) {
            $placeholders = [];
            $params = ['user_id' => $user['id']];

            foreach ($selectedAttachmentIds as $index => $attachmentId) {
                $key = 'attachment_id_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $attachmentId;
            }

            $attachmentAccessSql = 'attachments.user_id = :user_id';
            if ($articleId > 0) {
                $attachmentAccessSql .= " OR EXISTS (
                    SELECT 1
                    FROM article_attachments existing_article_attachment
                    WHERE existing_article_attachment.article_id = :article_id
                      AND existing_article_attachment.attachment_id = attachments.id
                )";
                $params['article_id'] = $articleId;
            }

            $ownedAttachments = db_fetch_all(
                "SELECT attachments.id
                 FROM attachments
                 WHERE attachments.id IN (" . implode(',', $placeholders) . ")
                   AND ($attachmentAccessSql)",
                $params
            );

            $ownedAttachmentIds = array_fill_keys(
                array_map(static fn (array $row): int => (int)$row['id'], $ownedAttachments),
                true
            );

            foreach ($selectedAttachmentIds as $attachmentId) {
                if (!isset($ownedAttachmentIds[$attachmentId])) {
                    $errors[] = 'Vous ne pouvez associer que vos propres fichiers.';
                    break;
                }
            }
        }

        if (!$errors) {
            $pdo = db();

            try {
                $slug = generate_unique_slug($title, $articleId > 0 ? $articleId : null);
                $publishedAt = $status === 'published'
                    ? ($article['published_at'] ?? now())
                    : null;
                $timestamp = now();
                $successMessage = 'Article créé.';
                $beforeActivityPubArticle = $articleId > 0 ? activitypub_article_by_id_for_event($articleId) : null;

                article_ensure_tag_tables();
                ensure_article_attachments_table();

                $pdo->beginTransaction();

                if ($articleId > 0) {
                    $successMessage = 'Article mis à jour.';

                    db_query(
                        "UPDATE articles
                         SET title = :title,
                             slug = :slug,
                             content = :content,
                             excerpt = :excerpt,
                             visibility = :visibility,
                             group_id = :group_id,
                             status = :status,
                             updated_at = :updated_at,
                             published_at = :published_at
                         WHERE id = :id",
                        [
                            'title' => $title,
                            'slug' => $slug,
                            'content' => $content,
                            'excerpt' => $excerptValue !== '' ? $excerptValue : excerpt($content, 220),
                            'visibility' => $visibility,
                            'group_id' => $selectedGroupId,
                            'status' => $status,
                            'updated_at' => $timestamp,
                            'published_at' => $publishedAt,
                            'id' => $articleId,
                        ]
                    );

                    article_sync_tags($articleId, $title, $content);
                    sync_article_attachments($articleId, $selectedAttachmentIds, (int)$user['id']);
                } else {
                    $articleId = db_insert(
                        "INSERT INTO articles
                            (author_id, title, slug, content, excerpt, visibility, group_id, status, created_at, updated_at, published_at)
                         VALUES
                            (:author_id, :title, :slug, :content, :excerpt, :visibility, :group_id, :status, :created_at, :updated_at, :published_at)",
                        [
                            'author_id' => $user['id'],
                            'title' => $title,
                            'slug' => $slug,
                            'content' => $content,
                            'excerpt' => $excerptValue !== '' ? $excerptValue : excerpt($content, 220),
                            'visibility' => $visibility,
                            'group_id' => $selectedGroupId,
                            'status' => $status,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                            'published_at' => $publishedAt,
                        ]
                    );

                    article_sync_tags($articleId, $title, $content);
                    sync_article_attachments($articleId, $selectedAttachmentIds, (int)$user['id']);
                }

                $pdo->commit();
                activitypub_after_article_change($beforeActivityPubArticle, $articleId);
                set_flash('success', $successMessage);

                if ($status === 'published') {
                    redirect(article_url($slug));
                }

                redirect(article_edit_url($slug));
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    } else {
        $errors[] = 'Action inconnue.';
    }
}

$availableAttachments = user_attachment_options((int)$user['id']);

if (!is_post() && $articleId > 0) {
    $selectedAttachmentIds = $existingAttachmentIds;
}

if ($articleId > 0) {
    $linkedAttachments = article_attachment_options($articleId);

    $availableAttachmentIds = array_fill_keys(
        array_map(static fn (array $attachment): int => (int)$attachment['id'], $availableAttachments),
        true
    );
    foreach ($linkedAttachments as $linkedAttachment) {
        if (!isset($availableAttachmentIds[(int)$linkedAttachment['id']])) {
            $availableAttachments[] = $linkedAttachment;
        }
    }
}

$flashes = get_flashes();
$pageTitle = $articleId > 0 ? 'Modifier l’article' : 'Nouvel article';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta name="theme-color" content="#ffffff">
    <meta charset="utf-8">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
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
            <h1><?= e($pageTitle) ?></h1>
            <p class="muted">
                Rédigez une fiche, une ressource, une synthèse ou un billet long.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(url('articles.php')) ?>">Retour à mes articles</a>
            </div>
        </section>

        <section class="card">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">

                <div class="markdown-toolbar" aria-label="Barre d’outils Markdown">
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

                <p class="muted">
                    La barre d’outils insère le Markdown dans le champ actif.
                    Sélectionnez du texte dans le résumé ou le contenu, puis cliquez sur un bouton pour l’entourer.
                </p>

                <label for="title">Titre</label>
                <input
                    type="text"
                    id="title"
                    name="title"
                    value="<?= e($title) ?>"
                    required
                >

                <label for="excerpt">Résumé court</label>
                <textarea
                    id="excerpt"
                    name="excerpt"
                    placeholder="Résumé facultatif. S’il est vide, il sera généré automatiquement."
                ><?= e($excerptValue) ?></textarea>

                <label for="content">Contenu</label>

                <p class="muted">
                    Markdown accepté :
                    <code># Titre</code>,
                    <code>## Sous-titre</code>,
                    <code>**gras**</code>,
                    <code>*italique*</code>,
                    <code>[lien](https://exemple.fr)</code>,
                    <code>- liste</code>,
                    <code>```text</code>,
                    <code>$ formule $</code>.
                </p>

                <textarea
                    id="content"
                    name="content"
                    class="article-editor"
                    placeholder="Écrire l’article..."
                    required
                ><?= e($content) ?></textarea>

                <?php if ($availableAttachments): ?>
                    <span class="form-label" id="article-attachments-label">Fichiers joints</span>
                    <div class="article-attachment-picker" id="article-attachments" role="group" aria-labelledby="article-attachments-label">
                        <?php foreach ($availableAttachments as $attachment): ?>
                            <?php $attachmentInputId = 'article_attachment_' . (int)$attachment['id']; ?>
                            <div class="article-attachment-option">
                                <label for="<?= e($attachmentInputId) ?>">
                                    <input
                                        type="checkbox"
                                        id="<?= e($attachmentInputId) ?>"
                                        name="attachment_ids[]"
                                        value="<?= e((string)$attachment['id']) ?>"
                                        data-article-attachment-input
                                        data-url="<?= e(url('file.php?id=' . (int)$attachment['id'])) ?>"
                                        data-name="<?= e($attachment['original_name']) ?>"
                                        data-mime="<?= e($attachment['mime_type']) ?>"
                                        <?= in_array((int)$attachment['id'], $selectedAttachmentIds, true) ? 'checked' : '' ?>
                                    >
                                    <span><?= e($attachment['original_name'] . ' — ' . human_file_size((int)$attachment['size'])) ?></span>
                                </label>
                                <button
                                    type="button"
                                    class="button-secondary"
                                    data-insert-article-attachment
                                    data-attachment-input="<?= e($attachmentInputId) ?>"
                                >
                                    Insérer
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="muted">
                        Les fichiers cochés seront affichés avec l’article. Décochez un fichier pour le retirer de l’article et de son contenu.
                    </p>
                <?php endif; ?>

                <div class="grid grid-2">
                    <div>
                        <label for="visibility">Visibilité</label>
                        <select id="visibility" name="visibility">
                            <option value="members" <?= $visibility === 'members' ? 'selected' : '' ?>>
                                Membres uniquement
                            </option>
                            <option value="public" <?= $visibility === 'public' ? 'selected' : '' ?>>
                                Membres et mastodon
                            </option>
                            <option value="private" <?= $visibility === 'private' ? 'selected' : '' ?>>
                                Privé
                            </option>
                            <option value="group" <?= $visibility === 'group' ? 'selected' : '' ?>>
                                Groupe
                            </option>
                        </select>
                        <label for="group_id">Groupe</label>
                        <select id="group_id" name="group_id">
                            <option value="">Choisir un groupe</option>
                            <?php foreach ($groupOptions as $group): ?>
                                <option value="<?= e((string)$group['id']) ?>" <?= $groupIdValue === (int)$group['id'] ? 'selected' : '' ?>>
                                    <?= e($group['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="status">Statut</label>
                        <select id="status" name="status">
                            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>
                                Brouillon
                            </option>
                            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>
                                Publié
                            </option>
                        </select>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        Enregistrer
                    </button>
                    <a class="button-secondary" href="<?= e(url('articles.php')) ?>">
                        Annuler
                    </a>
                </div>
            </form>
        </section>

        <?php if ($linkedAttachments): ?>
            <section class="card">
                <h2>Fichiers associés</h2>

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Type</th>
                                <th>Taille</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linkedAttachments as $attachment): ?>
                                <tr>
                                    <td><?= e($attachment['original_name']) ?></td>
                                    <td><?= e($attachment['mime_type']) ?></td>
                                    <td><?= e(human_file_size((int)$attachment['size'])) ?></td>
                                    <td>
                                        <div class="admin-actions">
                                            <a
                                                class="button-secondary"
                                                href="<?= e(url('file.php?id=' . (int)$attachment['id'])) ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                Ouvrir
                                            </a>
                                            <form method="post" action="" onsubmit="return confirm('Retirer ce fichier de l’article ? Le fichier restera disponible dans vos fichiers.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="remove_attachment">
                                                <input type="hidden" name="attachment_id" value="<?= e((string)$attachment['id']) ?>">
                                                <button type="submit" class="button-danger">
                                                    Retirer
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>

    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
