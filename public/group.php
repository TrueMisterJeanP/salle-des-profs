<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/pagination.php';

require_login();

$user = current_user();
$groupId = (int)get_value('id', '0');
$messageAttachments = user_attachment_options((int)$user['id']);

if ($groupId <= 0) {
    http_response_code(404);
    exit('Groupe introuvable.');
}

$group = db_fetch_one(
    "SELECT groups.*, users.username, users.display_name
     FROM groups
     JOIN users ON users.id = groups.created_by
     WHERE groups.id = :id
     LIMIT 1",
    ['id' => $groupId]
);

if (!$group) {
    http_response_code(404);
    exit('Groupe introuvable.');
}

$membership = db_fetch_one(
    "SELECT *
     FROM group_members
     WHERE group_id = :group_id
       AND user_id = :user_id
     LIMIT 1",
    [
        'group_id' => $groupId,
        'user_id' => $user['id'],
    ]
);

$isMember = $membership !== null;
$isGroupAdmin = $isMember && in_array($membership['role'], ['admin', 'moderator'], true);
$isCreator = (int)$group['created_by'] === (int)$user['id'];
$isSiteAdmin = ($user['role'] ?? '') === 'admin';

$canRead = $isMember || $isCreator || $isSiteAdmin || $group['visibility'] === 'members';
$canWrite = $isMember || $isCreator || $isSiteAdmin;
$canManage = $isGroupAdmin || $isCreator || $isSiteAdmin;

if (!$canRead) {
    http_response_code(403);
    exit('Vous ne pouvez pas accéder à ce groupe.');
}

$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action');

    if ($action === 'join') {
        if ($group['visibility'] === 'private' && !$isSiteAdmin) {
            $errors[] = 'Ce groupe est privé. Vous devez être ajouté par un administrateur.';
        }

        if (!$errors && !$isMember) {
            try {
                db_insert(
                    "INSERT INTO group_members (group_id, user_id, role, joined_at)
                     VALUES (:group_id, :user_id, 'member', :joined_at)",
                    [
                        'group_id' => $groupId,
                        'user_id' => $user['id'],
                        'joined_at' => now(),
                    ]
                );

                set_flash('success', 'Vous avez rejoint le groupe.');
                redirect(url('group.php?id=' . $groupId));
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    }

    if ($action === 'add_member' && $canManage) {
        $memberId = (int)post_value('member_id', '0');

        if ($memberId <= 0) {
            $errors[] = 'Membre invalide.';
        }

        if (!$errors) {
            $member = db_fetch_one(
                "SELECT id FROM users WHERE id = :id AND is_active = 1 LIMIT 1",
                ['id' => $memberId]
            );

            if (!$member) {
                $errors[] = 'Utilisateur introuvable ou désactivé.';
            }
        }

        if (!$errors) {
            try {
                db_query(
                    "INSERT OR IGNORE INTO group_members (group_id, user_id, role, joined_at)
                     VALUES (:group_id, :user_id, 'member', :joined_at)",
                    [
                        'group_id' => $groupId,
                        'user_id' => $memberId,
                        'joined_at' => now(),
                    ]
                );

                set_flash('success', 'Membre ajouté au groupe.');
                redirect(url('group.php?id=' . $groupId));
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    }

    if ($action === 'remove_member' && $canManage) {
        $memberId = (int)post_value('member_id', '0');

        if ($memberId <= 0) {
            $errors[] = 'Membre invalide.';
        }

        if ($memberId === (int)$group['created_by']) {
            $errors[] = 'Le créateur du groupe ne peut pas être retiré.';
        }

        if (!$errors) {
            try {
                db_query(
                    "DELETE FROM group_members
                     WHERE group_id = :group_id
                       AND user_id = :user_id",
                    [
                        'group_id' => $groupId,
                        'user_id' => $memberId,
                    ]
                );

                set_flash('success', 'Membre retiré du groupe.');
                redirect(url('group.php?id=' . $groupId));
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    }
}

$membersPage = current_page('members_page');
$membersPerPage = 10;
$membersOffset = pagination_offset($membersPage, $membersPerPage);

$totalMembers = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM group_members
     WHERE group_id = :group_id",
    ['group_id' => $groupId]
)['total'] ?? 0);

$membersTotalPages = total_pages($totalMembers, $membersPerPage);

$members = db_fetch_all(
    "SELECT group_members.role, group_members.joined_at,
            users.id, users.username, users.display_name, users.email, users.last_seen_at
     FROM group_members
     JOIN users ON users.id = group_members.user_id
     WHERE group_members.group_id = :group_id
     ORDER BY group_members.role ASC, users.display_name COLLATE NOCASE ASC, users.username COLLATE NOCASE ASC
     LIMIT :limit OFFSET :offset",
    [
        'group_id' => $groupId,
        'limit' => $membersPerPage,
        'offset' => $membersOffset,
    ]
);

$availableUsers = [];

if ($canManage) {
    $availableUsers = db_fetch_all(
        "SELECT id, username, display_name, email
         FROM users
         WHERE is_active = 1
           AND id NOT IN (
                SELECT user_id
                FROM group_members
                WHERE group_id = :group_id
           )
         ORDER BY display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC",
        ['group_id' => $groupId]
    );
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title><?= e($group['name']) ?> — <?= e(APP_NAME) ?></title>
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
            <div class="group-card-header">
                <div>
                    <h1><?= e($group['name']) ?></h1>
                    <p class="meta">
                        Créé par <?= e($group['display_name'] ?: $group['username']) ?>
                        · <?= e($group['created_at']) ?>
                        · <?= e(visibility_label($group['visibility'])) ?>
                    </p>
                </div>
            </div>

            <?php if (!empty($group['description'])): ?>
                <p><?= nl2br(e($group['description'])) ?></p>
            <?php endif; ?>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(url('groups.php')) ?>">Retour aux groupes</a>

                <?php if ($isCreator || $isSiteAdmin): ?>
                    <button
                        type="button"
                        class="button-danger"
                        data-delete-group="<?= e((string)$groupId) ?>"
                    >
                        Supprimer le groupe
                    </button>
                <?php endif; ?>

                <?php if ($isMember && !$isCreator): ?>
                    <button
                        type="button"
                        class="button-secondary"
                        data-leave-group="<?= e((string)$groupId) ?>"
                    >
                        Quitter le groupe
                    </button>
                <?php endif; ?>

                <?php if (!$isMember && $group['visibility'] === 'members'): ?>
                    <form method="post" action="">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="join">
                        <button type="submit" class="button-primary">Rejoindre le groupe</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($canWrite): ?>
            <section class="group-chat-layout">
                <section class="card group-chat-panel">
                    <h2>Discussion du groupe</h2>

                    <div
                        id="group-messages"
                        class="messages-list"
                        data-current-user-id="<?= e((string)$user['id']) ?>"
                        data-group-id="<?= e((string)$groupId) ?>"
                    >
                        <p class="muted">Chargement des messages…</p>
                    </div>

                    <form id="group-message-form" class="message-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="group_id" value="<?= e((string)$groupId) ?>">

                        <label for="group-message-content" class="sr-only">Message</label>
                        <textarea
                            id="group-message-content"
                            name="content"
                            placeholder="Écrire un message dans le groupe..."
                        ></textarea>
                        
                        <label for="group-message-file">Pièce jointe</label>
                        <input
                            type="file"
                            id="group-message-file"
                            name="file"
                        >
                        <p class="meta">
                            Taille maximale : <?= e(human_file_size(MAX_UPLOAD_SIZE)) ?>.
                        </p>

                        <?php if ($messageAttachments): ?>
                            <label for="group-message-existing-attachment">Ou choisir un fichier déjà envoyé</label>
                            <select id="group-message-existing-attachment" name="existing_attachment_id">
                                <option value="">Aucun fichier existant</option>
                                <?php foreach ($messageAttachments as $attachment): ?>
                                    <option value="<?= e((string)$attachment['id']) ?>">
                                        <?= e($attachment['original_name'] . ' — ' . human_file_size((int)$attachment['size'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        
                        <input type="hidden" name="attachment_id" value="">
                        
                        <div id="group-message-upload-status" class="meta"></div>
                        
                        <div class="form-actions">
                            <button type="submit" class="button-primary">
                                Envoyer
                            </button>
                        </div>
                    </form>
                </section>

                <aside class="card">
                    <h2>Membres</h2>

                    <?php if (!$members): ?>
                        <p class="muted">Aucun membre.</p>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($members as $member): ?>
                                <div class="member-item">
                                    <div>
                                        <strong><?= e($member['display_name'] ?: $member['username']) ?></strong><br>
                                        <span class="meta">
                                            @<?= e($member['username']) ?> · <?= e($member['role']) ?>
                                        </span>
                                    </div>

                                    <?php if ($canManage && (int)$member['id'] !== (int)$group['created_by']): ?>
                                        <form method="post" action="" onsubmit="return confirm('Retirer ce membre du groupe ?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="member_id" value="<?= e((string)$member['id']) ?>">
                                            <button type="submit" class="button-danger">Retirer</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($membersTotalPages > 1): ?>
                            <?= render_pagination($membersPage, $membersTotalPages, 'members_page') ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($canManage): ?>
                        <hr>

                        <h3>Ajouter un membre</h3>

                        <?php if (!$availableUsers): ?>
                            <p class="muted">Aucun utilisateur disponible à ajouter.</p>
                        <?php else: ?>
                            <form method="post" action="">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_member">

                                <label for="member_id">Utilisateur</label>
                                <select id="member_id" name="member_id" required>
                                    <?php foreach ($availableUsers as $availableUser): ?>
                                        <option value="<?= e((string)$availableUser['id']) ?>">
                                            <?= e(($availableUser['display_name'] ?: $availableUser['username']) . ' — ' . $availableUser['email']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div class="form-actions">
                                    <button type="submit" class="button-primary">
                                        Ajouter
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </aside>
            </section>
        <?php else: ?>
            <section class="card">
                <h2>Discussion privée</h2>
                <p class="muted">
                    Rejoignez ce groupe pour participer à la discussion.
                </p>
            </section>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>

    <script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
