<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/protection.php';
require_once __DIR__ . '/../includes/attachments.php';

require_login();
protection_ensure_schema();

$user = current_user();
$errors = [];
$editId = (int)get_value('edit', '0');
$editResource = null;
$uploadSizeLimit = effective_upload_size_limit();

if ($editId > 0) {
    $editResource = db_fetch_one(
        "SELECT * FROM protection_resources WHERE id = :id AND is_active = 1 LIMIT 1",
        ['id' => $editId]
    );
    if ($editResource && !protection_user_can_manage($editResource, $user)) {
        set_flash('error', 'Vous ne pouvez pas modifier cette ressource.');
        redirect(url('resources.php'));
    }
}

if (is_post()) {
    require_csrf();
    $action = post_value('action', 'create');
    $id = (int)post_value('id', '0');

    if ($action === 'delete' && $id > 0) {
        $resource = db_fetch_one("SELECT * FROM protection_resources WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
        if (!$resource || !protection_user_can_manage($resource, $user)) {
            $errors[] = 'Ressource introuvable ou non modifiable.';
        } else {
            db_query(
                "UPDATE protection_resources SET is_active = 0, updated_at = :updated_at WHERE id = :id",
                ['updated_at' => now(), 'id' => $id]
            );
            set_flash('success', 'Ressource supprimée.');
            redirect(url('resources.php'));
        }
    }

    $data = [
        'title' => post_value('title'),
        'resource_type' => post_value('resource_type', 'law'),
        'organization' => post_value('organization'),
        'url' => post_value('url'),
        'contact_info' => post_value('contact_info'),
        'description' => post_value('description'),
    ];
    if ($data['title'] === '') {
        $errors[] = 'Le titre est obligatoire.';
    }
    if (!isset(PROTECTION_RESOURCE_TYPES[$data['resource_type']])) {
        $errors[] = 'Type de ressource invalide.';
    }
    if ($data['url'] !== '' && filter_var($data['url'], FILTER_VALIDATE_URL) === false) {
        $errors[] = 'URL invalide.';
    }
    $uploadedAttachment = null;
    if (!$errors && !empty($_FILES['file']) && is_array($_FILES['file']) && (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $uploadedAttachment = save_uploaded_attachment($_FILES['file'], (int)$user['id']);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    if (!$errors) {
        if ($action === 'update' && $id > 0) {
            $resource = db_fetch_one("SELECT * FROM protection_resources WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
            if (!$resource || !protection_user_can_manage($resource, $user)) {
                $errors[] = 'Ressource introuvable ou non modifiable.';
            } else {
                db_query(
                    "UPDATE protection_resources
                     SET title = :title, resource_type = :resource_type, organization = :organization,
                         url = :url, attachment_id = :attachment_id, contact_info = :contact_info,
                         description = :description, updated_at = :updated_at
                     WHERE id = :id",
                    $data + [
                        'attachment_id' => $uploadedAttachment ? (int)$uploadedAttachment['id'] : ((int)($resource['attachment_id'] ?? 0) ?: null),
                        'updated_at' => now(),
                        'id' => $id,
                    ]
                );
                set_flash('success', 'Ressource mise à jour.');
                redirect(url('resources.php'));
            }
        } else {
            db_insert(
                "INSERT INTO protection_resources
                 (title, resource_type, organization, url, attachment_id, contact_info, description, created_by, created_at)
                 VALUES (:title, :resource_type, :organization, :url, :attachment_id, :contact_info, :description, :created_by, :created_at)",
                $data + [
                    'attachment_id' => $uploadedAttachment ? (int)$uploadedAttachment['id'] : null,
                    'created_by' => (int)$user['id'],
                    'created_at' => now(),
                ]
            );
            set_flash('success', 'Ressource ajoutée.');
            redirect(url('resources.php'));
        }
    }

    if (!$errors) {
        redirect(url('resources.php'));
    }
}
$type = get_value('type');
$where = 'WHERE protection_resources.is_active = 1';
$params = [];
if ($type !== '' && isset(PROTECTION_RESOURCE_TYPES[$type])) {
    $where .= ' AND protection_resources.resource_type = :type';
    $params['type'] = $type;
}
$resources = db_fetch_all(
    "SELECT protection_resources.*,
            attachments.original_name AS attachment_original_name,
            attachments.mime_type AS attachment_mime_type,
            attachments.size AS attachment_size,
            users.username,
            users.display_name
     FROM protection_resources
     JOIN users ON users.id = protection_resources.created_by
     LEFT JOIN attachments ON attachments.id = protection_resources.attachment_id
     $where
     ORDER BY protection_resources.resource_type ASC, protection_resources.title ASC",
    $params
);
$resourceCount = count($resources);
$resourcePage = max(1, (int)get_value('resource_page', '1'));
if ($resourceCount > 0 && $resourcePage > $resourceCount) {
    $resourcePage = $resourceCount;
}
$selectedResource = $resourceCount > 0 ? $resources[$resourcePage - 1] : null;
$form = $editResource ?: [];
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Textes officiels et services — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page">
    <?php foreach ($flashes as $flash): ?><div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="flash flash-error"><strong>Erreur :</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <section class="card"><h1>Inventaire des textes et services</h1><p class="muted">Répertoriez les bases réglementaires, services, procédures, modèles et contacts mobilisables.</p></section>
    <section class="card" id="resource-directory">
        <div class="section-heading-row"><h2>Répertoire</h2><nav class="filter-tabs"><a href="<?= e(url('resources.php')) ?>">Tout</a><?php foreach (PROTECTION_RESOURCE_TYPES as $key => $label): ?><a href="<?= e(url('resources.php?type=' . urlencode($key))) ?>"><?= e($label) ?></a><?php endforeach; ?></nav></div>
        <?php if (!$resources): ?><p class="muted">Aucune ressource enregistrée.</p><?php else: ?>
            <?php $resource = $selectedResource; ?>
            <div class="resource-directory-detail">
                <article class="record-card">
                    <dl class="event-field-grid resource-field-grid">
                        <div class="event-field event-field-title">
                            <dt>Titre</dt>
                            <dd><?= e($resource['title']) ?></dd>
                        </div>
                        <div class="event-field">
                            <dt>Type</dt>
                            <dd><span class="badge"><?= e(protection_label(PROTECTION_RESOURCE_TYPES, $resource['resource_type'])) ?></span></dd>
                        </div>
                        <div class="event-field">
                            <dt>Service / organisme</dt>
                            <dd><?= e($resource['organization'] ?: 'Non renseigné') ?></dd>
                        </div>
                        <div class="event-field">
                            <dt>Lien officiel</dt>
                            <dd>
                                <?php if ($resource['url']): ?>
                                    <a href="<?= e($resource['url']) ?>" rel="noopener noreferrer"><?= e($resource['url']) ?></a>
                                <?php else: ?>
                                    <span class="muted">Non renseigné.</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="event-field">
                            <dt>Document</dt>
                            <dd>
                                <?php if (!empty($resource['attachment_id'])): ?>
                                    <a href="<?= e(url('file.php?id=' . (int)$resource['attachment_id'] . '&download=1')) ?>">
                                        <?= e((string)$resource['attachment_original_name']) ?>
                                    </a>
                                    <?php if (!empty($resource['attachment_size'])): ?>
                                        <span class="muted"> [<?= e(human_file_size((int)$resource['attachment_size'])) ?>]</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">Aucun document.</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="event-field event-field-wide">
                            <dt>Coordonnées ou canal de saisine</dt>
                            <dd>
                                <?php if ($resource['contact_info']): ?>
                                    <div class="markdown-content"><?= render_markdown($resource['contact_info']) ?></div>
                                <?php else: ?>
                                    <span class="muted">Non renseigné.</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="event-field event-field-wide">
                            <dt>Usage concret</dt>
                            <dd>
                                <?php if ($resource['description']): ?>
                                    <div class="markdown-content"><?= render_markdown($resource['description']) ?></div>
                                <?php else: ?>
                                    <span class="muted">Non renseigné.</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>

                    <?php if (protection_user_can_manage($resource, $user)): ?>
                        <div class="form-actions">
                            <a class="button-primary" href="<?= e(url('resources.php?edit=' . (int)$resource['id'] . '#title')) ?>">Modifier</a>
                            <form method="post" action="" onsubmit="return confirm('Supprimer cette ressource ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= e((string)$resource['id']) ?>">
                                <button class="button-danger" type="submit">Supprimer</button>
                            </form>
                        </div>
                    <?php endif; ?>
                    <?php if ($resourceCount > 1): ?>
                        <nav class="pagination resource-pagination" aria-label="Ressources du répertoire">
                            <?php for ($pageNumber = 1; $pageNumber <= $resourceCount; $pageNumber++): ?>
                                <?php if ($pageNumber === $resourcePage): ?>
                                    <span class="button-primary pagination-current" aria-current="page"><?= e((string)$pageNumber) ?></span>
                                <?php else: ?>
                                    <a class="button-secondary" href="<?= e(url('resources.php' . ($type !== '' ? '?type=' . urlencode($type) . '&resource_page=' . $pageNumber : '?resource_page=' . $pageNumber) . '#resource-directory')) ?>"><?= e((string)$pageNumber) ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </nav>
                    <?php endif; ?>
                </article>
            </div>
        <?php endif; ?>
    </section>
    <section class="card">
        <h2><?= $editResource ? 'Modifier la ressource' : 'Ajouter une ressource' ?></h2>
        <form method="post" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editResource ? 'update' : 'create' ?>">
            <input type="hidden" name="id" value="<?= e((string)($form['id'] ?? 0)) ?>">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$uploadSizeLimit ?>">
            <label for="title">Titre</label><input id="title" name="title" required value="<?= e((string)($form['title'] ?? post_value('title'))) ?>">
            <div class="form-grid resource-form-grid">
                <div><label for="resource_type">Type</label><select id="resource_type" name="resource_type"><?php foreach (PROTECTION_RESOURCE_TYPES as $key => $label): ?><option value="<?= e($key) ?>" <?= (($form['resource_type'] ?? post_value('resource_type', 'law')) === $key) ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div><label for="organization">Service / organisme</label><input id="organization" name="organization" value="<?= e((string)($form['organization'] ?? post_value('organization'))) ?>"></div>
            </div>
            <label for="url">Lien officiel</label><input id="url" name="url" type="url" value="<?= e((string)($form['url'] ?? post_value('url'))) ?>">
            <label for="file">Document à inclure</label><input id="file" name="file" type="file">
            <p class="meta">Taille maximale : <?= e(human_file_size($uploadSizeLimit)) ?>.</p>
            <label for="contact_info">Coordonnées ou canal de saisine</label><textarea id="contact_info" name="contact_info"><?= e((string)($form['contact_info'] ?? post_value('contact_info'))) ?></textarea>
            <label for="description">Usage concret</label><textarea id="description" name="description"><?= e((string)($form['description'] ?? post_value('description'))) ?></textarea>
            <div class="form-actions"><button class="button-primary" type="submit"><?= $editResource ? 'Enregistrer' : 'Ajouter' ?></button><?php if ($editResource): ?><a class="button-secondary" href="<?= e(url('resources.php')) ?>">Annuler</a><?php endif; ?></div>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
