<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/web_notifications.php';

require_login();

$user = current_user();
$messengerNotificationsEnabled = web_notifications_messenger_enabled((int)$user['id']);
$messengerPreferenceEndpoint = parse_url(root_url('api/message_alert_preference.php'), PHP_URL_PATH) ?: '../api/message_alert_preference.php';
$messengerAlertsEndpoint = parse_url(root_url('api/message_alerts.php'), PHP_URL_PATH) ?: '../api/message_alerts.php';
$selectedUserId = (int)get_value('user_id', '0');
$messageAttachments = user_attachment_options((int)$user['id']);
$uploadSizeLimit = effective_upload_size_limit();
$membersPage = current_page('members_page');
$membersPerPage = 10;
$membersOffset = pagination_offset($membersPage, $membersPerPage);

$totalMembers = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM users
     WHERE id != :current_user_id
       AND is_active = 1",
    ['current_user_id' => $user['id']]
)['total'] ?? 0);

$membersTotalPages = total_pages($totalMembers, $membersPerPage);

$users = db_fetch_all(
    "SELECT id, username, display_name, avatar, last_seen_at
     FROM users
     WHERE id != :current_user_id
       AND is_active = 1
     ORDER BY display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC
     LIMIT :limit OFFSET :offset",
    [
        'current_user_id' => $user['id'],
        'limit' => $membersPerPage,
        'offset' => $membersOffset,
    ]
);

$selectedUser = null;
$canDeletePrivateConversation = false;

if ($selectedUserId > 0) {
    $selectedUser = db_fetch_one(
        "SELECT id, username, display_name, avatar, last_seen_at
         FROM users
         WHERE id = :id
           AND is_active = 1
         LIMIT 1",
        ['id' => $selectedUserId]
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

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Messages — <?= e(APP_NAME) ?></title>
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

        <section class="card messenger-entry-card">
            <div class="messenger-entry-copy">
                <h1>Messagerie</h1>
                <p class="muted">
                    Choisissez une conversation privée ou accédez aux discussions de groupe.
                </p>

                <nav class="message-type-nav" aria-label="Types de messages">
                    <div class="message-type-buttons">
                        <a class="active" href="<?= e(url('chat.php')) ?>" aria-current="page">
                            Messages privés
                        </a>
                        <a href="<?= e(url('groups.php')) ?>">
                            Messages de groupes
                        </a>
                        <span class="message-type-break" aria-hidden="true"></span>
                        <button
                            type="button"
                            class="button-secondary messenger-notification-nav-button"
                            data-messenger-notification-toggle
                            data-messenger-notification-enabled="<?= $messengerNotificationsEnabled ? '1' : '0' ?>"
                            data-preference-endpoint="<?= e($messengerPreferenceEndpoint) ?>"
                            data-messages-endpoint="<?= e($messengerAlertsEndpoint) ?>"
                            data-csrf-token="<?= e(csrf_token()) ?>"
                        >
                            Activer les notifications
                        </button>
                        <a
                            class="button-secondary messenger-open-button"
                            href="<?= e(url('messenger.php?type=private' . ($selectedUser ? '&user_id=' . (int)$selectedUser['id'] : ''))) ?>"
                            aria-label="Ouvrir la messagerie instantanée"
                            title="Ouvrir la messagerie instantanée"
                        >
                            Interface messagerie
                        </a>
                    </div>
                    <span class="meta messenger-notification-status" data-messenger-notification-status role="status" aria-live="polite"></span>
                </nav>

            </div>
        </section>

        <section class="chat-layout">
            <aside class="chat-sidebar card">
                <h2>Membres</h2>

                <?php if (!$users): ?>
                    <p class="muted">Aucun autre membre disponible.</p>
                <?php else: ?>
                    <div class="contact-list">
                        <?php foreach ($users as $member): ?>
                            <a
                                class="contact-item <?= (int)$member['id'] === $selectedUserId ? 'active' : '' ?>"
                                href="<?= e(url('chat.php?user_id=' . (int)$member['id'] . '&members_page=' . $membersPage)) ?>"
                            >
                                <span class="contact-avatar">
                                    <?php if (!empty($member['avatar'])): ?>
                                        <img src="<?= e(avatar_url((int)$member['id'], (string)$member['avatar'])) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(mb_strtoupper(mb_substr($member['display_name'] ?: $member['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                    <?php endif; ?>
                                </span>

                                <span>
                                    <strong><?= e($member['display_name'] ?: $member['username']) ?></strong>
                                    <small class="meta">@<?= e($member['username']) ?></small>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($membersTotalPages > 1): ?>
                        <?= render_pagination($membersPage, $membersTotalPages, 'members_page') ?>
                    <?php endif; ?>
                <?php endif; ?>
            </aside>

            <section class="chat-panel card">
                <?php if (!$selectedUser): ?>
                    <div class="empty-state">
                        <h2>Choisissez un membre</h2>
                        <p class="muted">
                            Sélectionnez une personne dans la colonne de gauche pour démarrer une conversation.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="chat-header">
                        <div>
                            <h2><?= e($selectedUser['display_name'] ?: $selectedUser['username']) ?></h2>
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
                    </div>

                    <div
                        id="messages"
                        class="messages-list"
                        data-current-user-id="<?= e((string)$user['id']) ?>"
                        data-peer-id="<?= e((string)$selectedUser['id']) ?>"
                    >
                        <p class="muted">Chargement des messages…</p>
                    </div>

                    <form id="message-form" class="message-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="receiver_id" value="<?= e((string)$selectedUser['id']) ?>">

                        <label for="message-content" class="sr-only">Message</label>
                        <textarea
                            id="message-content"
                            name="content"
                            placeholder="Écrire un message..."
                        ></textarea>
                        
                        <label for="message-file">Pièce jointe</label>
                        <input
                            type="file"
                            id="message-file"
                            name="file"
                        >
                        <p class="meta">Taille maximale : <?= e(human_file_size($uploadSizeLimit)) ?>.</p>

                        <?php if ($messageAttachments): ?>
                            <label for="message-existing-attachment">Ou choisir un fichier déjà envoyé</label>
                            <select id="message-existing-attachment" name="existing_attachment_id">
                                <option value="">Aucun fichier existant</option>
                                <?php foreach ($messageAttachments as $attachment): ?>
                                    <option value="<?= e((string)$attachment['id']) ?>">
                                        <?= e($attachment['original_name'] . ' — ' . human_file_size((int)$attachment['size'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        
                        <input type="hidden" name="attachment_id" value="">
                        
                        <div id="message-upload-status" class="meta"></div>
                        
                        <div class="form-actions">
                            <button type="submit" class="button-primary">
                                Envoyer
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>

    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
