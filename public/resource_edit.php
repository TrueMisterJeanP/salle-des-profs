<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/protection.php';
require_once __DIR__ . '/../includes/attachments.php';

require_login();
protection_ensure_schema();

$user = current_user();
$errors = [];
$returnToAdmin = ($user['role'] ?? '') === 'admin'
    && get_value('return_to', post_value('return_to')) === 'admin_resources';
$returnUrl = $returnToAdmin ? admin_url('ressources.php') : url('resources.php');
$resourceId = (int)get_value('id', post_value('id', '0'));
$resource = null;
$uploadSizeLimit = effective_upload_size_limit();

if ($resourceId > 0) {
    $resource = db_fetch_one(
        "SELECT * FROM protection_resources WHERE id = :id LIMIT 1",
        ['id' => $resourceId]
    );
    if (
        !$resource
        || ((int)($resource['is_active'] ?? 0) !== 1 && ($user['role'] ?? '') !== 'admin')
        || !protection_user_can_manage($resource, $user)
    ) {
        set_flash('error', 'Ressource introuvable ou non modifiable.');
        redirect($returnUrl);
    }
}

if (is_post()) {
    require_csrf();
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
        if ($resource) {
            db_query(
                "UPDATE protection_resources
                 SET title = :title, resource_type = :resource_type, organization = :organization,
                     url = :url, attachment_id = :attachment_id, contact_info = :contact_info,
                     description = :description, updated_at = :updated_at
                 WHERE id = :id",
                $data + [
                    'attachment_id' => $uploadedAttachment ? (int)$uploadedAttachment['id'] : ((int)($resource['attachment_id'] ?? 0) ?: null),
                    'updated_at' => now(),
                    'id' => $resourceId,
                ]
            );
            set_flash('success', 'Ressource mise à jour.');
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
        }
        redirect($returnUrl);
    }
}

$form = is_post()
    ? ($data + ['id' => $resourceId])
    : ($resource ?: []);
$pageTitle = $resource ? 'Modifier la ressource' : 'Ajouter une ressource';
$flashes = get_flashes();
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
    <?php foreach ($flashes as $flash): ?><div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="flash flash-error"><strong>Erreur :</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="resource-hero">
        <div>
            <h1>Ressources</h1>
            <p class="muted">Inventaire des textes, services, procédures, modèles et contacts mobilisables.</p>
        </div>
        <div class="resource-hero-actions">
            <a class="button-primary" href="<?= e(url('resource_edit.php' . ($returnToAdmin ? '?return_to=admin_resources' : ''))) ?>">Ajouter une ressource</a>
        </div>
    </section>

    <section class="card">
        <h2><?= e($pageTitle) ?></h2>
        <form method="post" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= e((string)($form['id'] ?? 0)) ?>">
            <?php if ($returnToAdmin): ?><input type="hidden" name="return_to" value="admin_resources"><?php endif; ?>
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
            <div class="form-actions">
                <button class="button-primary" type="submit"><?= $resource ? 'Enregistrer' : 'Ajouter' ?></button>
                <a class="button-secondary" href="<?= e($returnUrl) ?>">Annuler</a>
            </div>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
