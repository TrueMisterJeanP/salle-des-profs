<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/web_notifications.php';

require_login();

db_query("UPDATE groups SET visibility = 'members' WHERE visibility = 'public'");

$user = current_user();
$messengerNotificationsEnabled = web_notifications_messenger_enabled((int)$user['id']);
$errors = [];
$page = current_page();
$editGroupId = (int)get_value('edit', '0');

if (is_post() && post_value('action') === 'update') {
    $editGroupId = (int)post_value('group_id', '0');
}

if (is_post()) {
    require_csrf();

    $action = post_value('action', 'create');
    $name = post_value('name');
    $description = post_value('description');
    $visibility = post_value('visibility', 'private');

    $allowedVisibilities = ['private', 'members'];

    if ($name === '') {
        $errors[] = 'Le nom du groupe est obligatoire.';
    }

    if (!in_array($visibility, $allowedVisibilities, true)) {
        $errors[] = 'Visibilité invalide.';
    }

    $groupToUpdate = null;

    if ($action === 'update') {
        $groupToUpdate = db_fetch_one(
            "SELECT id
             FROM groups
             WHERE id = :id
               AND created_by = :created_by
             LIMIT 1",
            [
                'id' => $editGroupId,
                'created_by' => $user['id'],
            ]
        );

        if (!$groupToUpdate) {
            $errors[] = 'Groupe introuvable ou non modifiable.';
        }
    } elseif ($action !== 'create') {
        $errors[] = 'Action inconnue.';
    }

    if (!$errors) {
        try {
            if ($action === 'update') {
                db_query(
                    "UPDATE groups
                     SET name = :name,
                         description = :description,
                         visibility = :visibility,
                         updated_at = :updated_at
                     WHERE id = :id
                       AND created_by = :created_by",
                    [
                        'name' => $name,
                        'description' => $description,
                        'visibility' => $visibility,
                        'updated_at' => now(),
                        'id' => $editGroupId,
                        'created_by' => $user['id'],
                    ]
                );

                set_flash('success', 'Groupe modifié.');
                redirect(url('groups.php?page=' . $page));
            } else {
                $groupId = db_insert(
                    "INSERT INTO groups (name, description, created_by, visibility, created_at)
                     VALUES (:name, :description, :created_by, :visibility, :created_at)",
                    [
                        'name' => $name,
                        'description' => $description,
                        'created_by' => $user['id'],
                        'visibility' => $visibility,
                        'created_at' => now(),
                    ]
                );

                db_insert(
                    "INSERT INTO group_members (group_id, user_id, role, joined_at)
                     VALUES (:group_id, :user_id, 'admin', :joined_at)",
                    [
                        'group_id' => $groupId,
                        'user_id' => $user['id'],
                        'joined_at' => now(),
                    ]
                );

                set_flash('success', 'Groupe créé.');
                redirect(url('group.php?id=' . $groupId));
            }
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$editGroup = null;

if ($editGroupId > 0) {
    $editGroup = db_fetch_one(
        "SELECT id, name, description, visibility
         FROM groups
         WHERE id = :id
           AND created_by = :created_by
         LIMIT 1",
        [
            'id' => $editGroupId,
            'created_by' => $user['id'],
        ]
    );
}

$perPage = 10;
$offset = pagination_offset($page, $perPage);

$groupAccessSql = "
    groups.created_by = :current_user_id
    OR EXISTS (
        SELECT 1
        FROM group_members gm
        WHERE gm.group_id = groups.id
            AND gm.user_id = :current_user_id
    )
";

$totalGroups = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM groups
     WHERE $groupAccessSql",
    ['current_user_id' => $user['id']]
)['total'] ?? 0);

$totalPages = total_pages($totalGroups, $perPage);

$groups = db_fetch_all(
    "SELECT groups.*,
            users.username,
            users.display_name,
            COUNT(group_members.id) AS member_count
     FROM groups
     JOIN users ON users.id = groups.created_by
     LEFT JOIN group_members ON group_members.group_id = groups.id
     WHERE $groupAccessSql
     GROUP BY groups.id
     ORDER BY groups.created_at DESC
     LIMIT :limit OFFSET :offset",
    [
        'current_user_id' => $user['id'],
        'limit' => $perPage,
        'offset' => $offset,
    ]
);

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Groupes — <?= e(APP_NAME) ?></title>
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

        <section class="card messenger-entry-card">
            <div class="messenger-entry-copy">
                <h1>Messages de groupes</h1>
                <p class="muted">
                    Créez des discussions de groupe entre membres sélectionnés.
                </p>

                <nav class="message-type-nav" aria-label="Types de messages">
                    <a href="<?= e(url('chat.php')) ?>">
                        Messages privés
                    </a>
                    <a class="active" href="<?= e(url('groups.php')) ?>" aria-current="page">
                        Messages de groupes
                    </a>
                    <button
                        type="button"
                        class="button-secondary messenger-notification-nav-button"
                        data-messenger-notification-toggle
                        data-messenger-notification-enabled="<?= $messengerNotificationsEnabled ? '1' : '0' ?>"
                        hidden
                    >
                        Activer les notifications
                    </button>
                    <a
                        class="button-secondary messenger-open-button"
                        href="<?= e(url('messenger.php?type=group')) ?>"
                        aria-label="Ouvrir la messagerie instantanée"
                        title="Ouvrir la messagerie instantanée"
                    >
                        Interface messagerie
                    </a>
                </nav>

            </div>
        </section>

        <section class="card" id="group-form">
            <h2><?= $editGroup ? 'Modifier le groupe' : 'Créer un groupe' ?></h2>

            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editGroup ? 'update' : 'create' ?>">
                <?php if ($editGroup): ?>
                    <input type="hidden" name="group_id" value="<?= e((string)$editGroup['id']) ?>">
                <?php endif; ?>

                <label for="name">Nom du groupe</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= e(is_post() ? post_value('name') : (string)($editGroup['name'] ?? '')) ?>"
                    required
                >

                <label for="description">Description</label>
                <textarea
                    id="description"
                    name="description"
                    placeholder="Description facultative..."
                ><?= e(is_post() ? post_value('description') : (string)($editGroup['description'] ?? '')) ?></textarea>

                <label for="visibility">Visibilité</label>
                <select id="visibility" name="visibility">
                    <?php $formVisibility = is_post() ? post_value('visibility', 'private') : (string)($editGroup['visibility'] ?? 'private'); ?>
                    <option value="private" <?= $formVisibility === 'private' ? 'selected' : '' ?>>Privé — uniquement les membres ajoutés</option>
                    <option value="members" <?= $formVisibility === 'members' ? 'selected' : '' ?>>Membres — visible par les utilisateurs connectés</option>
                </select>

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        <?= $editGroup ? 'Enregistrer' : 'Créer le groupe' ?>
                    </button>
                    <?php if ($editGroup): ?>
                        <a class="button-secondary" href="<?= e(url('groups.php?page=' . $page)) ?>">Annuler</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="group-list">
            <?php if (!$groups): ?>
                <article class="card">
                    <p class="muted">Aucun groupe pour l’instant.</p>
                </article>
            <?php else: ?>
                <?php foreach ($groups as $group): ?>
                    <article class="card group-card">
                        <div class="group-card-header">
                            <div>
                                <h2>
                                    <a href="<?= e(url('group.php?id=' . (int)$group['id'])) ?>">
                                        <?= e($group['name']) ?>
                                    </a>
                                </h2>

                                <p class="meta">
                                    Créé par <?= e($group['display_name'] ?: $group['username']) ?>
                                    · <?= e($group['created_at']) ?>
                                </p>
                            </div>

                            <span class="badge"><?= e(visibility_label($group['visibility'])) ?></span>
                        </div>

                        <?php if (!empty($group['description'])): ?>
                            <p><?= nl2br(e($group['description'])) ?></p>
                        <?php endif; ?>

                        <p class="meta">
                            <?= e((string)$group['member_count']) ?> membre(s)
                        </p>

                        <?php if ((int)$group['created_by'] === (int)$user['id']): ?>
                            <div class="form-actions">
                                <a class="button-secondary" href="<?= e(url('groups.php?' . http_build_query([
                                    'page' => $page,
                                    'edit' => (int)$group['id'],
                                ]) . '#group-form')) ?>">Modifier</a>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        
        <?php if ($totalPages > 1): ?>
            <section class="card">
                <?= render_pagination($page, $totalPages) ?>
            </section>
        <?php endif; ?>
        
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
