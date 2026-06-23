<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/attachments.php';

if (!is_logged_in()) {
    redirect(url('messenger_login.php'));
}

if (is_post() && post_value('action') === 'logout') {
    require_csrf();
    logout_user();

    start_app_session();
    set_flash('success', 'Vous êtes déconnecté.');

    redirect(url('messenger_login.php'));
}

$user = current_user();
$mode = get_value('type') === 'group' ? 'group' : 'private';
$selectedUserId = (int)get_value('user_id', '0');
$selectedGroupId = (int)get_value('group_id', '0');
$messageAttachments = user_attachment_options((int)$user['id']);
$uploadSizeLimit = effective_upload_size_limit();

$contacts = db_fetch_all(
    "SELECT users.id,
            users.username,
            users.display_name,
            users.avatar,
            users.last_seen_at,
            (
                SELECT MAX(messages.created_at)
                FROM messages
                WHERE messages.group_id IS NULL
                  AND messages.is_deleted = 0
                  AND (
                        (messages.sender_id = :current_user_id_a AND messages.receiver_id = users.id)
                        OR
                        (messages.sender_id = users.id AND messages.receiver_id = :current_user_id_b)
                  )
            ) AS last_message_at,
            (
                SELECT COUNT(*)
                FROM messages
                WHERE messages.group_id IS NULL
                  AND messages.is_deleted = 0
                  AND messages.sender_id = users.id
                  AND messages.receiver_id = :current_user_id_c
                  AND messages.is_read = 0
            ) AS unread_count
     FROM users
     WHERE users.id != :current_user_id
       AND users.is_active = 1
     ORDER BY last_message_at IS NULL ASC,
              last_message_at DESC,
              users.display_name COLLATE NOCASE ASC,
              users.username COLLATE NOCASE ASC
     LIMIT 80",
    [
        'current_user_id' => $user['id'],
        'current_user_id_a' => $user['id'],
        'current_user_id_b' => $user['id'],
        'current_user_id_c' => $user['id'],
    ]
);

$groups = db_fetch_all(
    "SELECT groups.id,
            groups.name,
            groups.description,
            groups.visibility,
            groups.created_by,
            (
                SELECT MAX(messages.created_at)
                FROM messages
                WHERE messages.group_id = groups.id
                  AND messages.is_deleted = 0
            ) AS last_message_at
     FROM groups
     WHERE groups.created_by = :current_user_id
        OR EXISTS (
            SELECT 1
            FROM group_members gm
            WHERE gm.group_id = groups.id
              AND gm.user_id = :current_user_id
        )
     ORDER BY last_message_at IS NULL ASC, last_message_at DESC, groups.name COLLATE NOCASE ASC
     LIMIT 80",
    ['current_user_id' => $user['id']]
);

if ($mode === 'private' && $selectedUserId <= 0 && $contacts) {
    $selectedUserId = (int)$contacts[0]['id'];
}

if ($mode === 'group' && $selectedGroupId <= 0 && $groups) {
    $selectedGroupId = (int)$groups[0]['id'];
}

$selectedUser = null;
$selectedGroup = null;
$canDeletePrivateConversation = false;
$canDeleteGroupConversation = false;
$canLeaveGroup = false;

if ($mode === 'private' && $selectedUserId > 0) {
    $selectedUser = db_fetch_one(
        "SELECT id, username, display_name, avatar, last_seen_at
         FROM users
         WHERE id = :id
           AND id != :current_user_id
           AND is_active = 1
         LIMIT 1",
        [
            'id' => $selectedUserId,
            'current_user_id' => $user['id'],
        ]
    );
}

if ($selectedUser) {
    $conversationCreator = db_fetch_one(
        "SELECT sender_id
         FROM messages
         WHERE group_id IS NULL
           AND is_deleted = 0
           AND (
                (sender_id = :current_user_id_a AND receiver_id = :peer_id_a)
                OR
                (sender_id = :peer_id_b AND receiver_id = :current_user_id_b)
           )
         ORDER BY created_at ASC, id ASC
         LIMIT 1",
        [
            'current_user_id_a' => $user['id'],
            'peer_id_a' => $selectedUser['id'],
            'peer_id_b' => $selectedUser['id'],
            'current_user_id_b' => $user['id'],
        ]
    );
    $canDeletePrivateConversation = $conversationCreator
        && ((int)$conversationCreator['sender_id'] === (int)$user['id'] || ($user['role'] ?? '') === 'admin');
}

if ($mode === 'group' && $selectedGroupId > 0) {
    $selectedGroup = db_fetch_one(
        "SELECT groups.*
         FROM groups
         WHERE groups.id = :id
           AND (
                groups.created_by = :current_user_id
                OR EXISTS (
                    SELECT 1
                    FROM group_members gm
                    WHERE gm.group_id = groups.id
                      AND gm.user_id = :current_user_id
                )
           )
         LIMIT 1",
        [
            'id' => $selectedGroupId,
            'current_user_id' => $user['id'],
        ]
    );
}

if ($selectedGroup) {
    $canDeleteGroupConversation = (int)$selectedGroup['created_by'] === (int)$user['id']
        || ($user['role'] ?? '') === 'admin';
    $canLeaveGroup = (int)$selectedGroup['created_by'] !== (int)$user['id'];
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Messagerie — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body class="messenger-page">
    <input type="hidden" name="<?= e(CSRF_TOKEN_NAME) ?>" value="<?= e(csrf_token()) ?>" data-csrf-token>

    <main class="messenger-shell">
        <button type="button" class="messenger-mobile-launcher" data-messenger-drawer-open aria-label="Afficher les conversations">
            <span class="post-avatar">
                <?php if (!empty($user['avatar'])): ?>
                    <img src="<?= e(avatar_url((int)$user['id'], (string)$user['avatar'])) ?>" alt="">
                <?php else: ?>
                    <?= e(mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                <?php endif; ?>
            </span>
            <span class="messenger-mobile-launcher-icon" aria-hidden="true">☰</span>
        </button>

        <aside class="messenger-sidebar" data-messenger-drawer>
            <button type="button" class="messenger-drawer-close" data-messenger-drawer-close aria-label="Fermer les conversations">×</button>
            <div class="messenger-account">
                <span class="post-avatar">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= e(avatar_url((int)$user['id'], (string)$user['avatar'])) ?>" alt="">
                    <?php else: ?>
                        <?= e(mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                    <?php endif; ?>
                </span>
                <div>
                    <strong><?= e($user['display_name'] ?: $user['username']) ?></strong>
                    <span class="meta">@<?= e($user['username']) ?></span>
                </div>
            </div>

            <nav class="message-type-nav messenger-mode-nav" aria-label="Messagerie">
                <a class="<?= $mode === 'private' ? 'active' : '' ?>" href="<?= e(url('messenger.php?type=private')) ?>">Privés</a>
                <a class="<?= $mode === 'group' ? 'active' : '' ?>" href="<?= e(url('messenger.php?type=group')) ?>">Groupes</a>
            </nav>

            <div class="messenger-thread-list">
                <?php if ($mode === 'private'): ?>
                    <?php if (!$contacts): ?>
                        <p class="muted">Aucun membre disponible.</p>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): ?>
                            <a class="contact-item <?= (int)$contact['id'] === $selectedUserId ? 'active' : '' ?>" href="<?= e(url('messenger.php?type=private&user_id=' . (int)$contact['id'])) ?>">
                                <span class="contact-avatar">
                                    <?php if (!empty($contact['avatar'])): ?>
                                        <img src="<?= e(avatar_url((int)$contact['id'], (string)$contact['avatar'])) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_strtoupper(mb_substr($contact['display_name'] ?: $contact['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                    <?php endif; ?>
                                </span>
                                <span>
                                    <strong><?= e($contact['display_name'] ?: $contact['username']) ?></strong>
                                    <small class="meta">
                                        @<?= e($contact['username']) ?>
                                        <?php if ((int)$contact['unread_count'] > 0): ?>
                                            · <?= e((string)$contact['unread_count']) ?> non lu(s)
                                        <?php endif; ?>
                                    </small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (!$groups): ?>
                        <p class="muted">Aucun groupe disponible.</p>
                    <?php else: ?>
                        <?php foreach ($groups as $group): ?>
                            <a class="contact-item <?= (int)$group['id'] === $selectedGroupId ? 'active' : '' ?>" href="<?= e(url('messenger.php?type=group&group_id=' . (int)$group['id'])) ?>">
                                <span class="contact-avatar">#</span>
                                <span>
                                    <strong><?= e($group['name']) ?></strong>
                                    <small class="meta"><?= e(visibility_label($group['visibility'])) ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="messenger-sidebar-actions">
                <a class="button-secondary" href="<?= e(url('chat.php')) ?>">Retour au site</a>
                <form method="post" action="<?= e(url('messenger.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="button-danger">Déconnexion</button>
                </form>
            </div>
        </aside>
        <button type="button" class="messenger-drawer-backdrop" data-messenger-drawer-close aria-label="Fermer les conversations"></button>

        <section class="messenger-main">
            <?php foreach ($flashes as $flash): ?>
                <?php $isDismissibleFlash = in_array($flash['type'], ['success', 'info'], true); ?>
                <div class="<?= e(flash_class($flash['type']) . ' messenger-flash' . ($isDismissibleFlash ? ' flash-is-dismissible' : '')) ?>">
                    <?= e($flash['message']) ?>
                    <?php if ($isDismissibleFlash): ?>
                        <button
                            type="button"
                            class="flash-dismiss"
                            data-dismiss-flash
                            aria-label="Fermer le message"
                            onclick="var flash=this.closest('.flash'); if (flash) flash.remove();"
                        >x</button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ($mode === 'private' && $selectedUser): ?>
                <header class="messenger-header">
                    <div>
                        <h1><?= e($selectedUser['display_name'] ?: $selectedUser['username']) ?></h1>
                        <p class="meta">
                            @<?= e($selectedUser['username']) ?>
                            <?php if (!empty($selectedUser['last_seen_at'])): ?>
                                · dernière activité : <?= e($selectedUser['last_seen_at']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($canDeletePrivateConversation): ?>
                        <button
                            type="button"
                            class="button-danger"
                            data-delete-conversation
                            data-conversation-type="private"
                            data-conversation-target="<?= e((string)$selectedUser['id']) ?>"
                        >
                            Supprimer la conversation
                        </button>
                    <?php endif; ?>
                    <span class="post-avatar article-avatar">
                        <?php if (!empty($selectedUser['avatar'])): ?>
                            <img src="<?= e(avatar_url((int)$selectedUser['id'], (string)$selectedUser['avatar'])) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_strtoupper(mb_substr($selectedUser['display_name'] ?: $selectedUser['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                        <?php endif; ?>
                    </span>
                </header>

                <div id="messages" class="messages-list messenger-messages" data-current-user-id="<?= e((string)$user['id']) ?>" data-peer-id="<?= e((string)$selectedUser['id']) ?>">
                </div>

                <form id="message-form" class="message-form messenger-composer">
                    <?= csrf_field() ?>
                    <input type="hidden" name="receiver_id" value="<?= e((string)$selectedUser['id']) ?>">
                    <input type="hidden" name="attachment_id" value="">

                    <textarea name="content" placeholder="Écrire un message..."></textarea>
                    <div class="messenger-composer-tools">
                        <div class="messenger-attach-control">
                            <label class="button-secondary" for="messenger-message-file">Joindre</label>
                        </div>
                        <input type="file" id="messenger-message-file" name="file">
                        <?php if ($messageAttachments): ?>
                            <select name="existing_attachment_id" aria-label="Fichier existant">
                                <option value="">Fichier existant</option>
                                <?php foreach ($messageAttachments as $attachment): ?>
                                    <option value="<?= e((string)$attachment['id']) ?>">
                                        <?= e($attachment['original_name'] . ' — ' . human_file_size((int)$attachment['size'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="button-primary">Envoyer</button>
                    </div>
                    <div class="meta messenger-upload-limit">Taille maximale : <?= e(human_file_size($uploadSizeLimit)) ?>.</div>
                    <div id="message-upload-status" class="meta"></div>
                </form>
            <?php elseif ($mode === 'group' && $selectedGroup): ?>
                <header class="messenger-header">
                    <div>
                        <h1><?= e($selectedGroup['name']) ?></h1>
                        <p class="meta"><?= e(visibility_label($selectedGroup['visibility'])) ?></p>
                    </div>
                    <?php if ($canDeleteGroupConversation): ?>
                        <button
                            type="button"
                            class="button-danger"
                            data-delete-group="<?= e((string)$selectedGroup['id']) ?>"
                        >
                            Supprimer le groupe
                        </button>
                    <?php endif; ?>
                    <?php if ($canLeaveGroup): ?>
                        <button
                            type="button"
                            class="button-secondary"
                            data-leave-group="<?= e((string)$selectedGroup['id']) ?>"
                        >
                            Quitter le groupe
                        </button>
                    <?php endif; ?>
                    <span class="post-avatar article-avatar">#</span>
                </header>

                <div id="group-messages" class="messages-list messenger-messages" data-current-user-id="<?= e((string)$user['id']) ?>" data-group-id="<?= e((string)$selectedGroup['id']) ?>">
                </div>

                <form id="group-message-form" class="message-form messenger-composer">
                    <?= csrf_field() ?>
                    <input type="hidden" name="group_id" value="<?= e((string)$selectedGroup['id']) ?>">
                    <input type="hidden" name="attachment_id" value="">

                    <textarea name="content" placeholder="Écrire un message dans le groupe..."></textarea>
                    <div class="messenger-composer-tools">
                        <div class="messenger-attach-control">
                            <label class="button-secondary" for="messenger-group-file">Joindre</label>
                        </div>
                        <input type="file" id="messenger-group-file" name="file">
                        <?php if ($messageAttachments): ?>
                            <select name="existing_attachment_id" aria-label="Fichier existant">
                                <option value="">Fichier existant</option>
                                <?php foreach ($messageAttachments as $attachment): ?>
                                    <option value="<?= e((string)$attachment['id']) ?>">
                                        <?= e($attachment['original_name'] . ' — ' . human_file_size((int)$attachment['size'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        <button type="submit" class="button-primary">Envoyer</button>
                    </div>
                    <div class="meta messenger-upload-limit">Taille maximale : <?= e(human_file_size($uploadSizeLimit)) ?>.</div>
                    <div id="group-message-upload-status" class="meta"></div>
                </form>
            <?php else: ?>
                <div class="empty-state">
                    <div>
                        <h1>Choisissez une conversation</h1>
                        <p class="muted">Sélectionnez un membre ou un groupe pour lire et envoyer des messages.</p>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
