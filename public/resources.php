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
$canPinResources = protection_user_can_pin_resources($user);

if (is_post()) {
    require_csrf();
    $action = post_value('action');
    $id = (int)post_value('id', '0');

    if ($action === 'delete' && $id > 0) {
        $resource = db_fetch_one("SELECT * FROM protection_resources WHERE id = :id AND is_active = 1 LIMIT 1", ['id' => $id]);
        if (!$resource || !protection_user_can_manage($resource, $user)) {
            $errors[] = 'Ressource introuvable ou non modifiable.';
        } else {
            db_query(
                "UPDATE protection_resources
                 SET is_active = 0, is_pinned = 0, pinned_at = NULL, updated_at = :updated_at
                 WHERE id = :id",
                ['updated_at' => now(), 'id' => $id]
            );
            set_flash('success', 'Ressource supprimée.');
            redirect(url('resources.php'));
        }
    } elseif (in_array($action, ['pin', 'unpin'], true) && $id > 0) {
        $resource = db_fetch_one(
            "SELECT id FROM protection_resources WHERE id = :id AND is_active = 1 LIMIT 1",
            ['id' => $id]
        );

        if (!$canPinResources) {
            $errors[] = 'Seuls les administrateurs et les syndicalistes peuvent épingler une ressource.';
        } elseif (!$resource) {
            $errors[] = 'Ressource introuvable.';
        } else {
            $isPinned = $action === 'pin';
            $updatedAt = now();
            db_query(
                "UPDATE protection_resources
                 SET is_pinned = :is_pinned,
                     pinned_at = :pinned_at,
                     updated_at = :updated_at
                 WHERE id = :id",
                [
                    'is_pinned' => $isPinned ? 1 : 0,
                    'pinned_at' => $isPinned ? $updatedAt : null,
                    'updated_at' => $updatedAt,
                    'id' => $id,
                ]
            );
            set_flash('success', $isPinned ? 'Ressource épinglée.' : 'Ressource désépinglée.');
            redirect(url('resources.php?resource=' . $id . '#resource-directory'));
        }
    }
}
$type = get_value('type');
$where = 'WHERE protection_resources.is_active = 1';
$params = [];
if ($type !== '' && isset(PROTECTION_RESOURCE_TYPES[$type])) {
    $where .= ' AND protection_resources.resource_type = :type';
    $params['type'] = $type;
}
$totalResourceCount = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total FROM protection_resources WHERE is_active = 1"
)['total'] ?? 0);
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
$pinnedResources = db_fetch_all(
    "SELECT id, title, resource_type, organization, pinned_at
     FROM protection_resources
     WHERE is_active = 1 AND is_pinned = 1
     ORDER BY pinned_at DESC, title ASC"
);
$resourceCount = count($resources);
$resourcePage = max(1, (int)get_value('resource_page', '1'));
$requestedResourceId = (int)get_value('resource', '0');
if ($requestedResourceId > 0) {
    foreach ($resources as $index => $resourceItem) {
        if ((int)$resourceItem['id'] === $requestedResourceId) {
            $resourcePage = $index + 1;
            break;
        }
    }
}
if ($resourceCount > 0 && $resourcePage > $resourceCount) {
    $resourcePage = $resourceCount;
}
$selectedResource = $resourceCount > 0 ? $resources[$resourcePage - 1] : null;
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta name="theme-color" content="#ffffff">
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
    <section class="resource-hero">
        <div>
            <h1>Ressources</h1>
            <p class="muted">Inventaire des textes, services, procédures, modèles et contacts mobilisables.</p>
        </div>
        <div class="resource-hero-actions">
            <a class="button-primary" href="<?= e(url('resource_edit.php')) ?>">Ajouter une ressource</a>
        </div>
    </section>
    <section class="grid grid-4 dashboard-grid resources-overview" aria-label="Ressources disponibles, ressources épinglées et répertoire">
        <div class="resources-overview-sidebar">
            <article class="card dashboard-card resource-available-card">
                <h2>Ressources disponibles</h2>
                <p class="stat"><?= e((string)$totalResourceCount) ?></p>
                <p class="muted">ressource(s) active(s)</p>
            </article>

            <section class="card resource-pinned-card" aria-labelledby="pinned-resources-title">
                <h2 id="pinned-resources-title">Ressources épinglées</h2>
                <?php if (!$pinnedResources): ?>
                    <p class="muted resource-pinned-empty">Aucune ressource épinglée.</p>
                <?php else: ?>
                    <ul class="resource-pinned-list">
                        <?php foreach ($pinnedResources as $pinnedResource): ?>
                            <li>
                                <a class="resource-pinned-link" href="<?= e(url('resources.php?resource=' . (int)$pinnedResource['id'] . '#resource-directory')) ?>">
                                    <?= e($pinnedResource['title']) ?>
                                </a>
                                <span class="resource-pinned-meta">
                                    <?= e(protection_label(PROTECTION_RESOURCE_TYPES, $pinnedResource['resource_type'])) ?>
                                    <?php if ($pinnedResource['organization']): ?>
                                        · <?= e($pinnedResource['organization']) ?>
                                    <?php endif; ?>
                                </span>
                                <?php if ($canPinResources): ?>
                                    <form class="resource-unpin-form" method="post" action="">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="unpin">
                                        <input type="hidden" name="id" value="<?= e((string)$pinnedResource['id']) ?>">
                                        <button class="button-secondary resource-pin-button" type="submit">Désépingler</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        <section class="card resource-directory-card" id="resource-directory">
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

                    <?php if (protection_user_can_manage($resource, $user) || $canPinResources): ?>
                        <div class="form-actions">
                            <?php if (protection_user_can_manage($resource, $user)): ?>
                                <a class="button-primary" href="<?= e(url('resource_edit.php?id=' . (int)$resource['id'])) ?>">Modifier</a>
                                <form method="post" action="" onsubmit="return confirm('Supprimer cette ressource ?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= e((string)$resource['id']) ?>">
                                    <button class="button-danger" type="submit">Supprimer</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canPinResources): ?>
                                <form method="post" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="<?= !empty($resource['is_pinned']) ? 'unpin' : 'pin' ?>">
                                    <input type="hidden" name="id" value="<?= e((string)$resource['id']) ?>">
                                    <button class="button-secondary" type="submit">
                                        <?= !empty($resource['is_pinned']) ? 'Désépingler' : 'Épingler' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
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
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
