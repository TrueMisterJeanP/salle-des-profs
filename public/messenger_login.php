<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/markdown.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

if (is_logged_in()) {
    redirect(url('messenger.php'));
}

$errors = [];

if (is_post()) {
    if (!csrf_verify()) {
        csrf_regenerate();
        $errors[] = 'Session expirée. Réessayez.';
    }

    $identifier = post_value('identifier');
    $password = (string)($_POST['password'] ?? '');

    if ($identifier === '') {
        $errors[] = 'Veuillez saisir votre nom d’utilisateur ou votre adresse email.';
    }

    if ($password === '') {
        $errors[] = 'Veuillez saisir votre mot de passe.';
    }

    if (!$errors) {
        $retryAfter = auth_rate_limit_retry_after('login', $identifier);

        if ($retryAfter > 0) {
            $errors[] = auth_rate_limit_message($retryAfter);
        }
    }

    if (!$errors) {
        if (attempt_login($identifier, $password)) {
            auth_rate_limit_record('login', $identifier, true);
            set_flash('success', 'Connexion réussie.');
            redirect(url('messenger.php'));
        }

        $errors[] = 'Identifiants incorrects ou compte désactivé.';
        auth_rate_limit_record('login', $identifier, false, $password);
    }
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
<body class="auth-page">
    <main class="auth-container education-login">
        <section class="card login-card">
            <div class="login-mark" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <p class="public-eyebrow">Salle des profs</p>
            <h1>Messagerie</h1>
            <p class="muted">Connectez-vous pour accéder à l’interface de messagerie instantanée.</p>

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

            <form method="post" action="">
                <?= csrf_field() ?>

                <label for="identifier">Nom d’utilisateur ou email</label>
                <input type="text" id="identifier" name="identifier" value="<?= e(post_value('identifier')) ?>" required autocomplete="username">

                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">

                <div class="form-actions">
                    <button type="submit" class="button-primary">Se connecter</button>
                    <a class="button-secondary" href="<?= e(url('login.php')) ?>">Revenir au site web</a>
                </div>
            </form>
            <p class="auth-return-link muted">
                Pour obtenir un compte, contactez l’administrateur du site ou votre référent d’établissement.
            </p>
        </section>
    </main>
    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
    <script>
        if (typeof typesetMath === 'function') {
            typesetMath(document.body);
        }
    </script>
</body>
</html>
