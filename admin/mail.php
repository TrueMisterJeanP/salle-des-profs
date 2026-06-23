<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/markdown.php';

require_admin();

$errors = [];
$sentCount = 0;
$failedRecipients = [];

$target = post_value('target', 'users');
$subject = post_value('subject');
$message = (string)($_POST['message'] ?? '');
$selectedUserIds = array_map('intval', (array)($_POST['user_ids'] ?? []));
$selectedGroupId = (int)post_value('group_id', '0');

$users = db_fetch_all(
    "SELECT id, username, email, display_name
     FROM users
     WHERE is_active = 1
       AND email != ''
     ORDER BY display_name COLLATE NOCASE ASC, username COLLATE NOCASE ASC"
);

$groups = db_fetch_all(
    "SELECT id, name, visibility
     FROM groups
     ORDER BY name COLLATE NOCASE ASC"
);

if (is_post()) {
    require_csrf();

    $allowedTargets = ['users', 'group', 'all'];

    if (!in_array($target, $allowedTargets, true)) {
        $errors[] = 'Cible invalide.';
    }

    if ($subject === '') {
        $errors[] = 'Le sujet est obligatoire.';
    }

    if (trim($message) === '') {
        $errors[] = 'Le message est obligatoire.';
    }

    $recipients = [];

    if (!$errors) {
        if ($target === 'all') {
            $recipients = mail_recipients_all_users();
        } elseif ($target === 'group') {
            if ($selectedGroupId <= 0) {
                $errors[] = 'Sélectionnez un groupe.';
            } else {
                $recipients = mail_recipients_by_group_id($selectedGroupId);
            }
        } else {
            if (!$selectedUserIds) {
                $errors[] = 'Sélectionnez au moins un utilisateur.';
            } else {
                $recipients = mail_recipients_by_user_ids($selectedUserIds);
            }
        }
    }

    $recipients = mail_deduplicate_recipients($recipients);

    if (!$errors && !$recipients) {
        $errors[] = 'Aucun destinataire actif avec une adresse email.';
    }

    if (!$errors) {
        $messageHtml = render_markdown($message);

        foreach ($recipients as $recipient) {
            $toName = (string)($recipient['display_name'] ?: $recipient['username'] ?: '');
            $toEmail = (string)$recipient['email'];

            if (mail_send_text($toEmail, $toName, $subject, $message, $messageHtml)) {
                $sentCount++;
            } else {
                $failedRecipients[] = $toName !== '' ? $toName . ' <' . $toEmail . '>' : $toEmail;
            }
        }

        if ($sentCount > 0 && !$failedRecipients) {
            set_flash('success', $sentCount . ' email(s) envoyé(s).');
            redirect(admin_url('mail.php'));
        }

        if ($sentCount > 0) {
            set_flash('warning', $sentCount . ' email(s) envoyé(s), ' . count($failedRecipients) . ' échec(s).');
        } else {
            $errors[] = 'Aucun email n’a pu être envoyé. Vérifiez la configuration mail du serveur.';
        }
    }
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Courriels — Administration — <?= e(APP_NAME) ?></title>
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

        <?php if ($failedRecipients): ?>
            <div class="flash flash-warning">
                <strong>Échecs d’envoi :</strong>
                <ul>
                    <?php foreach ($failedRecipients as $failedRecipient): ?>
                        <li><?= e($failedRecipient) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="card">
            <h1>Envoyer un email</h1>
            <p class="muted">
                Envoyez un message à des utilisateurs, à un groupe ou à tous les comptes actifs.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
                <a class="button-secondary" href="<?= e(admin_url('settings.php')) ?>">Configuration email</a>
            </div>
        </section>

        <section class="card">
            <form method="post" action="">
                <?= csrf_field() ?>

                <label for="target">Destinataires</label>
                <select id="target" name="target">
                    <option value="users" <?= $target === 'users' ? 'selected' : '' ?>>
                        Utilisateur(s) sélectionné(s)
                    </option>
                    <option value="group" <?= $target === 'group' ? 'selected' : '' ?>>
                        Membres d’un groupe
                    </option>
                    <option value="all" <?= $target === 'all' ? 'selected' : '' ?>>
                        Tous les utilisateurs actifs
                    </option>
                </select>

                <label for="user_ids">Utilisateurs</label>
                <select id="user_ids" name="user_ids[]" multiple size="10">
                    <?php foreach ($users as $mailUser): ?>
                        <option
                            value="<?= e((string)$mailUser['id']) ?>"
                            <?= in_array((int)$mailUser['id'], $selectedUserIds, true) ? 'selected' : '' ?>
                        >
                            <?= e(($mailUser['display_name'] ?: $mailUser['username']) . ' — ' . $mailUser['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="group_id">Groupe</label>
                <select id="group_id" name="group_id">
                    <option value="">Sélectionner un groupe</option>
                    <?php foreach ($groups as $group): ?>
                        <option
                            value="<?= e((string)$group['id']) ?>"
                            <?= (int)$group['id'] === $selectedGroupId ? 'selected' : '' ?>
                        >
                            <?= e($group['name'] . ' — ' . visibility_label($group['visibility'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="subject">Sujet</label>
                <input
                    type="text"
                    id="subject"
                    name="subject"
                    value="<?= e($subject) ?>"
                    required
                >

                <div class="markdown-toolbar" aria-label="Barre d’outils Markdown" data-md-target="message">
                    <button type="button" data-md-action="h1" title="Titre niveau 1">H1</button>
                    <button type="button" data-md-action="h2" title="Titre niveau 2">H2</button>
                    <button type="button" data-md-action="h3" title="Titre niveau 3">H3</button>

                    <span class="toolbar-separator"></span>

                    <button type="button" data-md-action="bold" title="Gras"><strong>B</strong></button>
                    <button type="button" data-md-action="italic" title="Italique"><em>I</em></button>
                    <button type="button" data-md-action="inline-code" title="Code inline">&lt;/&gt;</button>

                    <span class="toolbar-separator"></span>

                    <button type="button" data-md-action="link" title="Lien">🔗</button>
                    <button type="button" data-md-action="list" title="Liste">• Liste</button>
                    <button type="button" data-md-action="table" title="Tableau">▦ Tableau</button>
                    <button type="button" data-md-action="code-block" title="Bloc de code">```</button>

                    <span class="toolbar-separator"></span>

                    <button type="button" data-md-action="math-inline" title="Formule inline">$x$</button>
                    <button type="button" data-md-action="math-block" title="Formule bloc">$$</button>
                </div>

                <label for="message">Message</label>
                <textarea
                    id="message"
                    name="message"
                    class="article-editor"
                    rows="12"
                    required
                ><?= e($message) ?></textarea>

                <p class="muted">
                    Markdown et LaTeX acceptés :
                    <code>## Titre</code>,
                    <code>**gras**</code>,
                    <code>[lien](https://exemple.fr)</code>,
                    <code>$ formule $</code>,
                    <code>$$ formule $$</code>.
                </p>

                <?php if (trim($message) !== ''): ?>
                    <section>
                        <h2>Prévisualisation</h2>
                        <div class="markdown-content">
                            <?= render_markdown($message) ?>
                        </div>
                    </section>
                <?php endif; ?>

                <p class="muted">
                    Les emails sont envoyés individuellement afin de ne pas exposer les adresses des destinataires.
                    Expéditeur actuel : <?= e(mail_from_name()) ?> &lt;<?= e(mail_from_email()) ?>&gt;.
                </p>

                <div class="form-actions">
                    <button type="submit" class="button-primary" onclick="return confirm('Envoyer cet email aux destinataires sélectionnés ?');">
                        Envoyer
                    </button>
                </div>
            </form>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
    <script src="<?= e(url('assets/app.js')) ?>"></script>
</body>
</html>
