<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/protection.php';

require_login();
protection_ensure_schema();

$user = current_user();
$canManageSyndicates = can_manage_syndicates();
$errors = [];
$editBoardId = (int)get_value('edit', '0');
$showAddForm = get_value('add', '0') === '1';
$editBoard = null;

function can_manage_union_board(array $board, array $user): bool
{
    return ($user['role'] ?? '') === 'admin' || (int)($board['created_by'] ?? 0) === (int)$user['id'];
}

function selected_syndicate_member_ids(): array
{
    $memberIds = $_POST['member_ids'] ?? [];
    if (!is_array($memberIds)) {
        return [];
    }

    $normalizedIds = array_map(static fn ($id): int => (int)$id, $memberIds);
    $normalizedIds = array_filter($normalizedIds, static fn (int $id): bool => $id > 0);

    return array_values(array_unique($normalizedIds));
}

function sync_union_board_members(int $boardId, array $memberIds): void
{
    db_query(
        "DELETE FROM protection_union_board_members WHERE board_id = :board_id",
        ['board_id' => $boardId]
    );

    foreach ($memberIds as $memberId) {
        db_insert_ignore('protection_union_board_members', [
            'board_id' => $boardId,
            'user_id' => (int)$memberId,
            'created_at' => now(),
        ]);
    }
}

if ($editBoardId > 0 && $canManageSyndicates) {
    $editBoard = db_fetch_one(
        "SELECT *
         FROM protection_union_boards
         WHERE id = :id
           AND is_active = 1
         LIMIT 1",
        ['id' => $editBoardId]
    );

    if ($editBoard && !can_manage_union_board($editBoard, $user)) {
        $editBoard = null;
        $errors[] = 'Vous ne pouvez modifier que les syndicats que vous avez créés.';
    }
}

if (is_post()) {
    require_csrf();

    if (!$canManageSyndicates) {
        http_response_code(403);
        exit('Accès réservé aux administrateurs et aux comptes syndicats.');
    }

    $action = post_value('action', 'save');
    $boardId = (int)post_value('board_id', '0');

    if ($action === 'delete') {
        if ($boardId <= 0) {
            $errors[] = 'Syndicat invalide.';
        }

        $existingBoard = null;
        if (!$errors) {
            $existingBoard = db_fetch_one(
                "SELECT id, created_by
                 FROM protection_union_boards
                 WHERE id = :id
                   AND is_active = 1
                 LIMIT 1",
                ['id' => $boardId]
            );

            if (!$existingBoard) {
                $errors[] = 'Syndicat introuvable.';
            } elseif (!can_manage_union_board($existingBoard, $user)) {
                $errors[] = 'Vous ne pouvez supprimer que les syndicats que vous avez créés.';
            }
        }

        if (!$errors) {
            db_query(
                "UPDATE protection_union_boards
                 SET is_active = 0,
                     updated_at = :updated_at
                 WHERE id = :id",
                [
                    'updated_at' => now(),
                    'id' => $boardId,
                ]
            );

            set_flash('success', 'Syndicat supprimé.');
            redirect(url('syndicates.php'));
        }
    }

    if ($action === 'save') {
        $memberIds = selected_syndicate_member_ids();
        $data = [
            'union_name' => post_value('union_name'),
            'local_section' => post_value('local_section'),
            'contact_name' => post_value('contact_name'),
            'email' => post_value('email'),
            'phone' => post_value('phone'),
            'office_hours' => post_value('office_hours'),
            'location' => post_value('location'),
            'announcement' => post_value('announcement'),
        ];
        if ($data['union_name'] === '') {
            $errors[] = 'Le nom du syndicat est obligatoire.';
        }
        if ($data['email'] !== '' && !is_valid_email($data['email'])) {
            $errors[] = 'Courriel invalide.';
        }
        if ($memberIds) {
            $validMemberCount = (int)(db_fetch_one(
                "SELECT COUNT(*) AS total
                 FROM users
                 WHERE role = 'syndicate'
                   AND is_active = 1
                   AND id IN (" . implode(',', array_fill(0, count($memberIds), '?')) . ")",
                $memberIds
            )['total'] ?? 0);

            if ($validMemberCount !== count($memberIds)) {
                $errors[] = 'Un ou plusieurs membres syndicats sélectionnés sont invalides.';
            }
        }
        if ($boardId > 0) {
            $existingBoard = db_fetch_one(
                "SELECT id, created_by
                 FROM protection_union_boards
                 WHERE id = :id
                   AND is_active = 1
                 LIMIT 1",
                ['id' => $boardId]
            );

            if (!$existingBoard) {
                $errors[] = 'Syndicat introuvable.';
            } elseif (!can_manage_union_board($existingBoard, $user)) {
                $errors[] = 'Vous ne pouvez modifier que les syndicats que vous avez créés.';
            }
        }
        if (!$errors) {
            if ($boardId > 0) {
                db_query(
                    "UPDATE protection_union_boards
                     SET union_name = :union_name,
                         local_section = :local_section,
                         contact_name = :contact_name,
                         email = :email,
                         phone = :phone,
                         office_hours = :office_hours,
                         location = :location,
                         announcement = :announcement,
                         updated_at = :updated_at
                     WHERE id = :id",
                    $data + ['updated_at' => now(), 'id' => $boardId]
                );
                sync_union_board_members($boardId, $memberIds);
                set_flash('success', 'Syndicat mis à jour.');
            } else {
                $boardId = db_insert(
                    "INSERT INTO protection_union_boards
                     (union_name, local_section, contact_name, email, phone, office_hours, location,
                      announcement, created_by, created_at)
                     VALUES (:union_name, :local_section, :contact_name, :email, :phone,
                      :office_hours, :location, :announcement, :created_by, :created_at)",
                    $data + ['created_by' => (int)$user['id'], 'created_at' => now()]
                );
                sync_union_board_members($boardId, $memberIds);
                set_flash('success', 'Syndicat ajouté.');
            }
            redirect(url('syndicates.php'));
        }
    }
}
$boards = db_fetch_all("SELECT * FROM protection_union_boards WHERE is_active = 1 ORDER BY union_name ASC, local_section ASC");
$syndicateMembers = db_fetch_all(
    "SELECT id, username, display_name, last_seen_at
     FROM users
     WHERE role = 'syndicate'
       AND is_active = 1
     ORDER BY display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC"
);
$syndicateMemberOptions = db_fetch_all(
    "SELECT id, username, display_name
     FROM users
     WHERE role = 'syndicate'
       AND is_active = 1
     ORDER BY display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC"
);
$boardMemberRows = db_fetch_all(
    "SELECT protection_union_board_members.board_id,
            users.id,
            users.username,
            users.display_name
     FROM protection_union_board_members
     JOIN users ON users.id = protection_union_board_members.user_id
     WHERE users.role = 'syndicate'
       AND users.is_active = 1
     ORDER BY users.display_name COLLATE NOCASE ASC, users.username COLLATE NOCASE ASC"
);
$boardMembersByBoardId = [];
foreach ($boardMemberRows as $boardMember) {
    $boardMembersByBoardId[(int)$boardMember['board_id']][] = $boardMember;
}
$editBoardMemberIds = [];
if ($editBoard) {
    foreach ($boardMembersByBoardId[(int)$editBoard['id']] ?? [] as $boardMember) {
        $editBoardMemberIds[] = (int)$boardMember['id'];
    }
}
$formMemberIds = is_post() && post_value('action', 'save') === 'save'
    ? selected_syndicate_member_ids()
    : $editBoardMemberIds;
$formBoard = [
    'id' => (int)post_value('board_id', (string)($editBoard['id'] ?? 0)),
    'union_name' => post_value('union_name', (string)($editBoard['union_name'] ?? '')),
    'local_section' => post_value('local_section', (string)($editBoard['local_section'] ?? '')),
    'contact_name' => post_value('contact_name', (string)($editBoard['contact_name'] ?? '')),
    'email' => post_value('email', (string)($editBoard['email'] ?? '')),
    'phone' => post_value('phone', (string)($editBoard['phone'] ?? '')),
    'office_hours' => post_value('office_hours', (string)($editBoard['office_hours'] ?? '')),
    'location' => post_value('location', (string)($editBoard['location'] ?? '')),
    'announcement' => post_value('announcement', (string)($editBoard['announcement'] ?? '')),
];
$showSyndicateForm = $canManageSyndicates && (
    $showAddForm
    || $editBoard !== null
    || (is_post() && post_value('action', 'save') === 'save' && $errors)
);
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Syndicats — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page syndicates-page">
    <?php foreach ($flashes as $flash): ?><div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if ($errors): ?><div class="flash flash-error"><strong>Erreur :</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <section class="syndicate-hero">
        <div>
            <p class="meta">Sections syndicales · permanences et contacts</p>
            <h1>Syndicats</h1>
            <p>Retrouvez les coordonnées, permanences, lieux de rencontre et annonces des organisations syndicales.</p>
        </div>
        <?php if ($canManageSyndicates): ?>
            <div class="syndicate-hero-actions">
                <a class="button-primary" href="<?= e(url('syndicates.php?add=1#edit')) ?>">Ajouter un syndicat</a>
            </div>
        <?php endif; ?>
    </section>
    <section class="grid grid-4 dashboard-grid syndicates-overview">
        <div class="syndicates-overview-sidebar">
            
            <article class="card dashboard-card">
                <h2>Syndicats enregistrés</h2>
                <p class="stat"><?= e((string)count($boards)) ?></p>
                <p class="muted">syndicat(s) actif(s)</p>
            </article>
            
            <article class="card syndicate-members-card">
                <h2>Liste des syndicalistes</h2>
                <?php if (!$syndicateMembers): ?>
                    <p class="muted">Aucun compte syndicat actif.</p>
                <?php else: ?>
                    <div class="syndicate-member-list">
                        <?php foreach ($syndicateMembers as $member): ?>
                            <div class="syndicate-member-item">
                                <div>
                                    <strong><?= e($member['display_name'] ?: $member['username']) ?></strong>
                                    <span class="meta">@<?= e($member['username']) ?></span>
                                </div>
                                <a class="button-secondary" href="<?= e(url('messenger.php?type=private&user_id=' . (int)$member['id'])) ?>">Message</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        </div>
        <div class="union-board-list syndicates-overview-list">
        <?php if (!$boards): ?><article class="card union-board-empty"><p class="muted">Aucun syndicat enregistré.</p></article><?php else: ?>
            <?php foreach ($boards as $board): ?>
                <article class="card union-board">
                    <div class="record-card-header">
                        <div><h2><?= e($board['union_name']) ?></h2><p class="meta"><?= e($board['local_section'] ?: 'Section à renseigner') ?></p></div>
                        <div class="form-actions">
                            <?php if ($canManageSyndicates && can_manage_union_board($board, $user)): ?>
                                <a class="button-secondary" href="<?= e(url('syndicates.php?edit=' . (int)$board['id'] . '#edit')) ?>">Modifier</a>
                                <form method="post" action="" onsubmit="return confirm('Supprimer ce syndicat ?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="board_id" value="<?= e((string)$board['id']) ?>">
                                    <button class="button-danger" type="submit">Supprimer</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($board['announcement']): ?><div class="markdown-content"><?= render_markdown($board['announcement']) ?></div><?php endif; ?>
                    <dl class="detail-list">
                        <?php if ($board['contact_name']): ?><dt>Contact</dt><dd><?= e($board['contact_name']) ?></dd><?php endif; ?>
                        <?php if ($board['email']): ?><dt>Courriel</dt><dd><a href="mailto:<?= e($board['email']) ?>"><?= e($board['email']) ?></a></dd><?php endif; ?>
                        <?php if ($board['phone']): ?><dt>Téléphone</dt><dd><?= e($board['phone']) ?></dd><?php endif; ?>
                        <?php if ($board['office_hours']): ?><dt>Permanences</dt><dd><?= nl2br(e($board['office_hours'])) ?></dd><?php endif; ?>
                        <?php if ($board['location']): ?><dt>Lieu</dt><dd><?= e($board['location']) ?></dd><?php endif; ?>
                    </dl>
                    <?php $attachedMembers = $boardMembersByBoardId[(int)$board['id']] ?? []; ?>
                    <?php if ($attachedMembers): ?>
                        <div class="union-board-members">
                            <h3>Syndicalistes rattachés</h3>
                            <div class="syndicate-member-list">
                                <?php foreach ($attachedMembers as $member): ?>
                                    <div class="syndicate-member-item">
                                        <div>
                                            <strong><?= e($member['display_name'] ?: $member['username']) ?></strong>
                                            <span class="meta">@<?= e($member['username']) ?></span>
                                        </div>
                                        <?php if ((int)$member['id'] !== (int)$user['id']): ?>
                                            <a class="button-secondary" href="<?= e(url('messenger.php?type=private&user_id=' . (int)$member['id'])) ?>">Message</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!$attachedMembers && !$board['announcement'] && !$board['contact_name'] && !$board['email'] && !$board['phone'] && !$board['office_hours'] && !$board['location']): ?>
                        <p class="muted">Coordonnées et informations à compléter.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </section>
    <?php if ($showSyndicateForm): ?>
        <section class="card" id="edit">
            <h2><?= $formBoard['id'] ? 'Modifier un syndicat' : 'Ajouter un syndicat' ?></h2>
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="board_id" value="<?= e((string)$formBoard['id']) ?>">
                <div class="form-grid">
                    <div><label for="union_name">Syndicat</label><input id="union_name" name="union_name" required value="<?= e($formBoard['union_name']) ?>"></div>
                    <div><label for="local_section">Section locale</label><input id="local_section" name="local_section" value="<?= e($formBoard['local_section']) ?>"></div>
                    <div><label for="contact_name">Contact</label><input id="contact_name" name="contact_name" value="<?= e($formBoard['contact_name']) ?>"></div>
                    <div><label for="email">Courriel</label><input id="email" name="email" type="email" value="<?= e($formBoard['email']) ?>"></div>
                    <div><label for="phone">Téléphone</label><input id="phone" name="phone" value="<?= e($formBoard['phone']) ?>"></div>
                    <div><label for="location">Lieu</label><input id="location" name="location" value="<?= e($formBoard['location']) ?>"></div>
                </div>
                <fieldset class="union-member-picker">
                    <legend>Membres syndicats rattachés</legend>
                    <?php if (!$syndicateMemberOptions): ?>
                        <p class="muted">Aucun compte syndicat actif disponible.</p>
                    <?php else: ?>
                        <div class="checkbox-grid">
                            <?php foreach ($syndicateMemberOptions as $member): ?>
                                <?php $memberId = (int)$member['id']; ?>
                                <label class="checkbox-label">
                                    <input
                                        type="checkbox"
                                        name="member_ids[]"
                                        value="<?= e((string)$memberId) ?>"
                                        <?= in_array($memberId, $formMemberIds, true) ? 'checked' : '' ?>
                                    >
                                    <?= e($member['display_name'] ?: $member['username']) ?>
                                    <span class="meta">@<?= e($member['username']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>
                <label for="office_hours">Permanences</label><textarea id="office_hours" name="office_hours"><?= e($formBoard['office_hours']) ?></textarea>
                <label for="announcement">Annonce</label><textarea id="announcement" name="announcement"><?= e($formBoard['announcement']) ?></textarea>
                <div class="form-actions">
                    <button class="button-primary" type="submit"><?= $formBoard['id'] ? 'Enregistrer' : 'Publier le syndicat' ?></button>
                    <a class="button-secondary" href="<?= e(url('syndicates.php')) ?>">Annuler</a>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
