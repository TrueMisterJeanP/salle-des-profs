<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/protection.php';
require_once __DIR__ . '/../includes/pagination.php';

require_admin();
protection_ensure_schema();

$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $resourceId = (int)post_value('resource_id', '0');
    $resource = $resourceId > 0
        ? db_fetch_one(
            "SELECT id, title, is_active, is_pinned
             FROM protection_resources
             WHERE id = :id
             LIMIT 1",
            ['id' => $resourceId]
        )
        : null;

    if (!$resource) {
        $errors[] = 'Ressource introuvable.';
    } else {
        try {
            $updatedAt = now();

            if ($action === 'pin') {
                if ((int)$resource['is_active'] !== 1) {
                    $errors[] = 'Une ressource désactivée ne peut pas être épinglée.';
                } else {
                    db_query(
                        "UPDATE protection_resources
                         SET is_pinned = 1, pinned_at = :pinned_at, updated_at = :updated_at
                         WHERE id = :id",
                        ['pinned_at' => $updatedAt, 'updated_at' => $updatedAt, 'id' => $resourceId]
                    );
                    set_flash('success', 'Ressource épinglée.');
                }
            } elseif ($action === 'unpin') {
                db_query(
                    "UPDATE protection_resources
                     SET is_pinned = 0, pinned_at = NULL, updated_at = :updated_at
                     WHERE id = :id",
                    ['updated_at' => $updatedAt, 'id' => $resourceId]
                );
                set_flash('success', 'Ressource désépinglée.');
            } elseif ($action === 'enable') {
                db_query(
                    "UPDATE protection_resources
                     SET is_active = 1, updated_at = :updated_at
                     WHERE id = :id",
                    ['updated_at' => $updatedAt, 'id' => $resourceId]
                );
                set_flash('success', 'Ressource réactivée.');
            } elseif ($action === 'disable') {
                db_query(
                    "UPDATE protection_resources
                     SET is_active = 0, is_pinned = 0, pinned_at = NULL, updated_at = :updated_at
                     WHERE id = :id",
                    ['updated_at' => $updatedAt, 'id' => $resourceId]
                );
                set_flash('success', 'Ressource désactivée.');
            } elseif ($action === 'delete') {
                db_query("DELETE FROM protection_resources WHERE id = :id", ['id' => $resourceId]);
                set_flash('success', 'Ressource supprimée définitivement.');
            } else {
                $errors[] = 'Action invalide.';
            }

            if (!$errors) {
                redirect(admin_url('ressources.php'));
            }
        } catch (Throwable $e) {
            $errors[] = 'Impossible de modifier cette ressource.';
        }
    }
}

$query = trim(get_value('q'));
$type = get_value('type');
$status = get_value('status', 'all');

if (!isset(PROTECTION_RESOURCE_TYPES[$type])) {
    $type = '';
}
if (!in_array($status, ['all', 'active', 'disabled', 'pinned'], true)) {
    $status = 'all';
}

$where = [];
$params = [];

if ($query !== '') {
    $where[] = '(r.title LIKE :query OR r.organization LIKE :query OR r.description LIKE :query)';
    $params['query'] = '%' . $query . '%';
}
if ($type !== '') {
    $where[] = 'r.resource_type = :type';
    $params['type'] = $type;
}
if ($status === 'active') {
    $where[] = 'r.is_active = 1';
} elseif ($status === 'disabled') {
    $where[] = 'r.is_active = 0';
} elseif ($status === 'pinned') {
    $where[] = 'r.is_active = 1 AND r.is_pinned = 1';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$totalFiltered = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM protection_resources r
     $whereSql",
    $params
)['total'] ?? 0);
$perPage = 50;
$page = current_page();
$totalPages = total_pages($totalFiltered, $perPage);
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = pagination_offset($page, $perPage);
$resources = db_fetch_all(
    "SELECT r.*,
            u.username,
            u.display_name,
            a.original_name AS attachment_original_name
     FROM protection_resources r
     JOIN users u ON u.id = r.created_by
     LEFT JOIN attachments a ON a.id = r.attachment_id
     $whereSql
     ORDER BY r.is_pinned DESC, r.is_active DESC, r.pinned_at DESC, r.updated_at DESC, r.created_at DESC, r.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

$stats = db_fetch_one(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_total,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS disabled_total,
            SUM(CASE WHEN is_active = 1 AND is_pinned = 1 THEN 1 ELSE 0 END) AS pinned_total
     FROM protection_resources"
) ?: [];
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta name="theme-color" content="#ffffff">
    <meta charset="utf-8">
    <title>Gestion des ressources — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/../public/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page">
    <?php foreach ($flashes as $flash): ?>
        <div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?php if ($errors): ?>
        <div class="flash flash-error">
            <strong>Erreur :</strong>
            <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <section class="card">
        <h1>Gestion des ressources</h1>
        <p class="muted">Administrer, rechercher, épingler, désactiver ou supprimer les ressources du site.</p>
        <div class="form-actions">
            <a class="button-primary" href="<?= e(url('resource_edit.php?return_to=admin_resources')) ?>">Ajouter une ressource</a>
            <a class="button-secondary" href="<?= e(url('resources.php')) ?>">Voir la page Ressources</a>
            <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
        </div>
    </section>

    <section class="grid grid-4 dashboard-grid" aria-label="Statistiques des ressources">
        <article class="card dashboard-card">
            <h2>Total</h2>
            <p class="stat"><?= e((string)(int)($stats['total'] ?? 0)) ?></p>
            <p class="muted">ressource(s)</p>
        </article>
        <article class="card dashboard-card">
            <h2>Actives</h2>
            <p class="stat"><?= e((string)(int)($stats['active_total'] ?? 0)) ?></p>
            <p class="muted">visible(s)</p>
        </article>
        <article class="card dashboard-card">
            <h2>Épinglées</h2>
            <p class="stat"><?= e((string)(int)($stats['pinned_total'] ?? 0)) ?></p>
            <p class="muted">mise(s) en avant</p>
        </article>
        <article class="card dashboard-card">
            <h2>Désactivées</h2>
            <p class="stat"><?= e((string)(int)($stats['disabled_total'] ?? 0)) ?></p>
            <p class="muted">masquée(s)</p>
        </article>
    </section>

    <section class="card">
        <h2>Rechercher et filtrer</h2>
        <form method="get" action="" class="form-grid">
            <div>
                <label for="q">Recherche</label>
                <input id="q" name="q" type="search" value="<?= e($query) ?>" placeholder="Titre, organisme ou description">
            </div>
            <div>
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="">Tous les types</option>
                    <?php foreach (PROTECTION_RESOURCE_TYPES as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="status">État</label>
                <select id="status" name="status">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Toutes</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Actives</option>
                    <option value="pinned" <?= $status === 'pinned' ? 'selected' : '' ?>>Épinglées</option>
                    <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>Désactivées</option>
                </select>
            </div>
            <div class="form-actions">
                <button class="button-primary" type="submit">Filtrer</button>
                <a class="button-secondary" href="<?= e(admin_url('ressources.php')) ?>">Réinitialiser</a>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Liste des ressources <span class="meta">(<?= e((string)$totalFiltered) ?>)</span></h2>
        <?php if (!$resources): ?>
            <p class="muted">Aucune ressource ne correspond aux critères.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>Ressource</th>
                        <th>Type</th>
                        <th>Auteur</th>
                        <th>État</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($resources as $resource): ?>
                        <tr>
                            <td>
                                <strong><?= e($resource['title']) ?></strong>
                                <?php if ($resource['organization']): ?><br><span class="meta"><?= e($resource['organization']) ?></span><?php endif; ?>
                                <?php if ($resource['attachment_original_name']): ?><br><span class="meta">Document : <?= e($resource['attachment_original_name']) ?></span><?php endif; ?>
                            </td>
                            <td><?= e(protection_label(PROTECTION_RESOURCE_TYPES, $resource['resource_type'])) ?></td>
                            <td><?= e($resource['display_name'] ?: $resource['username']) ?></td>
                            <td>
                                <?= (int)$resource['is_active'] === 1 ? '<span class="badge badge-active">Active</span>' : '<span class="badge badge-disabled">Désactivée</span>' ?>
                                <?php if ((int)$resource['is_active'] === 1 && (int)$resource['is_pinned'] === 1): ?>
                                    <span class="badge">Épinglée</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="admin-actions">
                                    <a class="button-secondary" href="<?= e(url('resource_edit.php?id=' . (int)$resource['id'] . '&return_to=admin_resources')) ?>">Modifier</a>
                                    <?php if ((int)$resource['is_active'] === 1): ?>
                                        <a class="button-secondary" href="<?= e(url('resources.php?resource=' . (int)$resource['id'] . '#resource-directory')) ?>">Voir</a>
                                        <form method="post" action="">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="resource_id" value="<?= e((string)$resource['id']) ?>">
                                            <input type="hidden" name="action" value="<?= (int)$resource['is_pinned'] === 1 ? 'unpin' : 'pin' ?>">
                                            <button class="button-secondary" type="submit"><?= (int)$resource['is_pinned'] === 1 ? 'Désépingler' : 'Épingler' ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="resource_id" value="<?= e((string)$resource['id']) ?>">
                                        <input type="hidden" name="action" value="<?= (int)$resource['is_active'] === 1 ? 'disable' : 'enable' ?>">
                                        <button class="button-secondary" type="submit"><?= (int)$resource['is_active'] === 1 ? 'Désactiver' : 'Réactiver' ?></button>
                                    </form>
                                    <form method="post" action="" onsubmit="return confirm('Supprimer définitivement cette ressource ?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="resource_id" value="<?= e((string)$resource['id']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="button-danger" type="submit">Supprimer</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= render_pagination($page, $totalPages) ?>
        <?php endif; ?>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
