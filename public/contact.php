<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/contact_messages.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

$siteName = site_name();
$siteLogoUrl = site_logo_url();
$siteIcon = site_icon();
$errors = [];
$notifiedAdmins = 0;

contact_messages_ensure_table();

if (is_post()) {
    require_csrf();

    $name = post_value('name');
    $email = mb_strtolower(post_value('email'), 'UTF-8');
    $subject = post_value('subject');
    $message = trim((string)($_POST['message'] ?? ''));
    $captchaAnswer = post_value('captcha_answer');

    if ($name === '') {
        $errors[] = 'Votre nom est obligatoire.';
    } elseif (mb_strlen($name, 'UTF-8') > 160) {
        $errors[] = 'Votre nom ne doit pas dépasser 160 caractères.';
    }

    if (!is_valid_email($email)) {
        $errors[] = 'Adresse email invalide.';
    }

    if ($subject === '') {
        $errors[] = 'Le sujet est obligatoire.';
    } elseif (mb_strlen($subject, 'UTF-8') > 220) {
        $errors[] = 'Le sujet ne doit pas dépasser 220 caractères.';
    }

    if ($message === '') {
        $errors[] = 'Le message est obligatoire.';
    } elseif (mb_strlen($message, 'UTF-8') > 20000) {
        $errors[] = 'Le message ne doit pas dépasser 20 000 caractères.';
    }

    if (!contact_captcha_verify($captchaAnswer)) {
        $errors[] = 'Réponse au calcul incorrecte.';
    }

    $retryAfter = contact_message_retry_after(contact_message_ip_address());
    if ($retryAfter > 0) {
        $errors[] = 'Trop de messages ont été envoyés récemment. Réessayez dans '
            . max(1, (int)ceil($retryAfter / 60)) . ' minute(s).';
    }

    if (!$errors) {
        db_insert(
            "INSERT INTO contact_messages
                (name, email, subject, message, ip_address, user_agent, created_at)
             VALUES
                (:name, :email, :subject, :message, :ip_address, :user_agent, :created_at)",
            [
                'name' => mb_substr($name, 0, 160, 'UTF-8'),
                'email' => mb_substr($email, 0, 254, 'UTF-8'),
                'subject' => mb_substr($subject, 0, 220, 'UTF-8'),
                'message' => $message,
                'ip_address' => mb_substr(contact_message_ip_address(), 0, 45, 'UTF-8'),
                'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500, 'UTF-8'),
                'created_at' => now(),
            ]
        );

        $adminRecipients = db_fetch_all(
            "SELECT email, username, display_name
             FROM users
             WHERE role = 'admin'
               AND is_active = 1
               AND email != ''"
        );

        $notificationBody = "Nouveau message envoyé depuis le formulaire de contact.\n\n"
            . "Nom : $name\n"
            . "Courriel : $email\n"
            . "Sujet : $subject\n"
            . "IP : " . contact_message_ip_address() . "\n"
            . "Date : " . now() . "\n\n"
            . $message . "\n\n"
            . "Consulter : " . admin_url('contact_messages.php');

        foreach (mail_deduplicate_recipients($adminRecipients) as $admin) {
            $toName = (string)($admin['display_name'] ?: $admin['username'] ?: '');

            if (mail_send_text((string)$admin['email'], $toName, '[' . $siteName . '] Nouveau contact : ' . $subject, $notificationBody)) {
                $notifiedAdmins++;
            }
        }

        contact_captcha_clear();
        set_flash('success', 'Votre message a été envoyé aux administrateurs.');
        redirect(url('contact.php'));
    }

    contact_captcha_generate();
}

$captchaQuestion = contact_captcha_question();
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta name="theme-color" content="#ffffff">
    <meta charset="utf-8">
    <title>Contact — <?= e($siteName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_seo_meta([
    'title' => 'Contact — ' . $siteName,
    'description' => 'Contacter l’administration de ' . $siteName . '.',
    'canonical' => url('contact.php'),
]); ?>
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

        <section class="card about-card">
            <h1>Nous contacter</h1>
            <p class="muted">
                Envoyez un message aux administrateurs du site.
            </p>

            <form method="post" action="">
                <?= csrf_field() ?>

                <label for="name">Nom</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= e(post_value('name')) ?>"
                    required
                    maxlength="160"
                    autocomplete="name"
                >

                <label for="email">Adresse email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= e(post_value('email')) ?>"
                    required
                    maxlength="254"
                    autocomplete="email"
                >

                <label for="subject">Sujet</label>
                <input
                    type="text"
                    id="subject"
                    name="subject"
                    value="<?= e(post_value('subject')) ?>"
                    required
                    maxlength="220"
                >

                <label for="message">Message</label>
                <textarea
                    id="message"
                    name="message"
                    rows="10"
                    required
                    maxlength="20000"
                ><?= e((string)($_POST['message'] ?? '')) ?></textarea>

                <label for="captcha_answer">Anti-robot : combien font <?= e($captchaQuestion) ?> ?</label>
                <input
                    type="number"
                    id="captcha_answer"
                    name="captcha_answer"
                    required
                    inputmode="numeric"
                >

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        Envoyer
                    </button>

                    <a class="button-secondary" href="<?= e(url('public.php')) ?>">
                        Retour à l’accueil
                    </a>
                </div>
            </form>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
