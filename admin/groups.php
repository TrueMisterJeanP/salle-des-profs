<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/pagination.php';

require_admin();

db_query("UPDATE groups SET visibility = 'members' WHERE visibility = 'public'");

$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $groupId = (int)post_value('group_id', '0');

    if ($groupId <= 0) {
        $errors[] = 'Groupe invalide.';
    }

    if (!$errors) {
        $group = db_fetch_one(
            "SELECT id, name FROM groups WHERE id = :id LIMIT 1",
            ['id' => $groupId]
        );

        if (!$group) {
            $errors[] = 'Groupe introuvable.';
        }
    }

    if (!$errors) {
        try {
            if ($action === 'delete') {
                db_query(
                    "DELETE FROM groups WHERE id = :id",
                    ['id' => $groupId]
                );

                set_flash('success', 'Groupe supprimé.');
                redirect(admin_url('groups.php'));
            }

            if ($action === 'change_visibility') {
                $visibility = post_value('visibility', 'private');
                $allowedVisibilities = ['private', 'members'];

                if (!in_array($visibility, $allowedVisibilities, true)) {
                    $errors[] = 'Visibilité invalide.';
                } else {
                    db_query(
                        "UPDATE groups
                         SET visibility = :visibility,
                             updated_at = :updated_at
                         WHERE id = :id",
                        [
                            'visibility' => $visibility,
                            'updated_at' => now(),
                            'id' => $groupId,
                        ]
                    );

                    set_flash('success', 'Visibilité du groupe mise à jour.');
                    redirect(admin_url('groups.php'));
                }
            }

            if (!in_array($action, ['delete', 'change_visibility'], true)) {
                $errors[] = 'Action inconnue.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$search = get_value('q');
$params = [];
$where = '';

if ($search !== '') {
    $where = "WHERE groups.name LIKE :q OR groups.description LIKE :q";
    $params['q'] = '%' . $search . '%';
}

    $page = current_page();
    $perPage = 10;
    $offset = pagination_offset($page, $perPage);
    
    $totalGroups = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
        FROM groups
        $where",
        $params
    )['total'] ?? 0);
    
    $totalPages = total_pages($totalGroups, $perPage);
    
    $groups = db_fetch_all(
        "SELECT groups.*,
        users.username,
        users.display_name,
        COUNT(group_members.id) AS member_count,
        (
            SELECT COUNT(*)
            FROM messages
            WHERE messages.group_id = groups.id
            AND messages.is_deleted = 0
        ) AS message_count
        FROM groups
        JOIN users ON users.id = groups.created_by
        LEFT JOIN group_members ON group_members.group_id = groups.id
        $where
        GROUP BY groups.id
        ORDER BY groups.created_at DESC
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
    <title>Groupes — Administration — <?= e(APP_NAME) ?></title>
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
            <h1>Administration des groupes</h1>
            <p class="muted">
                Consultez, modifiez la visibilité ou supprimez les groupes.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
                <a class="button-secondary" href="<?= e(url('groups.php')) ?>">Voir les groupes</a>
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
                    placeholder="Nom ou description..."
                >

                <div class="form-actions">
                    <button type="submit" class="button-primary">Rechercher</button>
                    <a class="button-secondary" href="<?= e(admin_url('groups.php')) ?>">Réinitialiser</a>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Liste des groupes</h2>

            <?php if (!$groups): ?>
                <p class="muted">Aucun groupe trouvé.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Groupe</th>
                                <th>Créateur</th>
                                <th>Visibilité</th>
                                <th>Membres</th>
                                <th>Messages</th>
                                <th>Création</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <a href="<?= e(url('group.php?id=' . (int)$group['id'])) ?>">
                                                <?= e($group['name']) ?>
                                            </a>
                                        </strong>
                                        <?php if (!empty($group['description'])): ?>
                                            <br>
                                            <span class="meta"><?= e(excerpt($group['description'], 90)) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e($group['display_name'] ?: $group['username']) ?>
                                    </td>
                                    <td>
                                        <form method="post" action="" class="inline-form">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="change_visibility">
                                            <input type="hidden" name="group_id" value="<?= e((string)$group['id']) ?>">
                                            <select name="visibility" onchange="this.form.submit()">
                                                <option value="private" <?= $group['visibility'] === 'private' ? 'selected' : '' ?>>
                                                    Privé
                                                </option>
                                                <option value="members" <?= $group['visibility'] === 'members' ? 'selected' : '' ?>>
                                                    Membres
                                                </option>
                                            </select>
                                        </form>
                                    </td>
                                    <td><?= e((string)$group['member_count']) ?></td>
                                    <td><?= e((string)$group['message_count']) ?></td>
                                    <td><?= e($group['created_at']) ?></td>
                                    <td>
                                        <div class="admin-actions">
                                            <a class="button-secondary" href="<?= e(url('group.php?id=' . (int)$group['id'])) ?>">
                                                Ouvrir
                                            </a>

                                            <form method="post" action="" onsubmit="return confirm('Supprimer définitivement ce groupe et ses messages ?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="group_id" value="<?= e((string)$group['id']) ?>">
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
