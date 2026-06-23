<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/contact_messages.php';

require_admin();
contact_messages_ensure_table();

$errors = [];
$replyMessageId = (int)get_value('reply_id', '0');

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $messageId = (int)post_value('message_id', '0');

    if ($action === 'delete') {
        db_query("DELETE FROM contact_messages WHERE id = :id", ['id' => $messageId]);
        set_flash('success', 'Message supprimé.');
        redirect(admin_url('contact_messages.php'));
    }

    if ($action === 'delete_all') {
        db_query("DELETE FROM contact_messages");
        set_flash('success', 'Tous les messages de contact ont été supprimés.');
        redirect(admin_url('contact_messages.php'));
    }

    if ($action === 'reply') {
        $contactMessage = db_fetch_one(
            "SELECT *
             FROM contact_messages
             WHERE id = :id
             LIMIT 1",
            ['id' => $messageId]
        );

        $replySubject = post_value('reply_subject');
        $replyBody = trim((string)($_POST['reply_message'] ?? ''));

        if (!$contactMessage) {
            $errors[] = 'Message introuvable.';
        }

        if ($replySubject === '') {
            $errors[] = 'Le sujet de la réponse est obligatoire.';
        }

        if ($replyBody === '') {
            $errors[] = 'Le message de réponse est obligatoire.';
        }

        if (!$errors && $contactMessage) {
            $sent = mail_send_text(
                (string)$contactMessage['email'],
                (string)$contactMessage['name'],
                $replySubject,
                $replyBody
            );

            if ($sent) {
                db_query(
                    "UPDATE contact_messages
                     SET replied_at = :replied_at,
                         replied_by = :replied_by,
                         reply_subject = :reply_subject,
                         reply_message = :reply_message
                     WHERE id = :id",
                    [
                        'replied_at' => now(),
                        'replied_by' => current_user_id(),
                        'reply_subject' => $replySubject,
                        'reply_message' => $replyBody,
                        'id' => $messageId,
                    ]
                );

                set_flash('success', 'Réponse envoyée par email.');
                redirect(admin_url('contact_messages.php'));
            }

            $errors[] = 'L’email n’a pas pu être envoyé. Vérifiez la configuration mail.';
            $replyMessageId = $messageId;
        }
    }
}

$messages = db_fetch_all(
    "SELECT contact_messages.*,
            users.username AS replied_by_username,
            users.display_name AS replied_by_display_name
     FROM contact_messages
     LEFT JOIN users ON users.id = contact_messages.replied_by
     ORDER BY contact_messages.created_at DESC, contact_messages.id DESC"
);

$unrepliedCount = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM contact_messages
     WHERE replied_at IS NULL"
)['total'] ?? 0);

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Messages de contact — Administration — <?= e(APP_NAME) ?></title>
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
            <h1>Messages de contact</h1>
            <p class="muted">
                <?= e((string)count($messages)) ?> message(s), dont <?= e((string)$unrepliedCount) ?> sans réponse.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>

                <?php if ($messages): ?>
                    <form method="post" action="" onsubmit="return confirm('Supprimer tous les messages de contact ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_all">
                        <button type="submit" class="button-danger">
                            Tout supprimer
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!$messages): ?>
            <section class="card">
                <p class="muted">Aucun message de contact pour l’instant.</p>
            </section>
        <?php else: ?>
            <?php foreach ($messages as $contactMessage): ?>
                <?php
                    $messageId = (int)$contactMessage['id'];
                    $isReplyOpen = $replyMessageId === $messageId || get_value('reply_id') === (string)$messageId;
                    $defaultReplySubject = 'Re: ' . (string)$contactMessage['subject'];
                ?>
                <section class="card" id="message-<?= e((string)$messageId) ?>">
                    <div class="post-header">
                        <div>
                            <h2><?= e($contactMessage['subject']) ?></h2>
                            <p class="meta">
                                De <?= e($contactMessage['name']) ?> &lt;<?= e($contactMessage['email']) ?>&gt;
                                · reçu le <?= e($contactMessage['created_at']) ?>
                                <?php if (!empty($contactMessage['ip_address'])): ?>
                                    · IP <?= e($contactMessage['ip_address']) ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <span class="badge">
                            <?= $contactMessage['replied_at'] ? 'répondu' : 'à traiter' ?>
                        </span>
                    </div>

                    <div class="post-content">
                        <?= nl2br(e($contactMessage['message'])) ?>
                    </div>

                    <?php if (!empty($contactMessage['user_agent'])): ?>
                        <p class="meta">Navigateur : <?= e($contactMessage['user_agent']) ?></p>
                    <?php endif; ?>

                    <?php if ($contactMessage['replied_at']): ?>
                        <div class="flash flash-info">
                            Répondu le <?= e($contactMessage['replied_at']) ?>
                            <?php if (!empty($contactMessage['replied_by_display_name']) || !empty($contactMessage['replied_by_username'])): ?>
                                par <?= e($contactMessage['replied_by_display_name'] ?: $contactMessage['replied_by_username']) ?>
                            <?php endif; ?>
                            <?php if (!empty($contactMessage['reply_subject'])): ?>
                                <br>Sujet : <?= e($contactMessage['reply_subject']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <a class="button-secondary" href="<?= e(admin_url('contact_messages.php?reply_id=' . $messageId . '#message-' . $messageId)) ?>">
                            Répondre
                        </a>

                        <form method="post" action="" onsubmit="return confirm('Supprimer ce message ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="message_id" value="<?= e((string)$messageId) ?>">
                            <button type="submit" class="button-danger">
                                Supprimer
                            </button>
                        </form>
                    </div>

                    <?php if ($isReplyOpen): ?>
                        <form method="post" action="#message-<?= e((string)$messageId) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reply">
                            <input type="hidden" name="message_id" value="<?= e((string)$messageId) ?>">

                            <label for="reply_subject_<?= e((string)$messageId) ?>">Sujet</label>
                            <input
                                type="text"
                                id="reply_subject_<?= e((string)$messageId) ?>"
                                name="reply_subject"
                                value="<?= e(post_value('reply_subject', $defaultReplySubject)) ?>"
                                required
                            >

                            <label for="reply_message_<?= e((string)$messageId) ?>">Réponse</label>
                            <textarea
                                id="reply_message_<?= e((string)$messageId) ?>"
                                name="reply_message"
                                rows="8"
                                required
                            ><?= e((string)($_POST['reply_message'] ?? '')) ?></textarea>

                            <p class="muted">
                                L’email sera envoyé à <?= e($contactMessage['email']) ?> depuis <?= e(mail_from_name()) ?> &lt;<?= e(mail_from_email()) ?>&gt;.
                            </p>

                            <div class="form-actions">
                                <button type="submit" class="button-primary">
                                    Envoyer la réponse
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>

