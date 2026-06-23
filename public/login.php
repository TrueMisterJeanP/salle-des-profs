<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/password_setup.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

if (is_logged_in()) {
    redirect(url('dashboard.php'));
}

$errors = [];
$action = is_post() ? post_value('action', 'login') : 'login';
$setupToken = trim($action === 'setup_password' ? post_value('setup_token') : get_value('setup_token'));
$setupUser = $setupToken !== '' ? user_password_setup_by_token($setupToken) : null;

if (is_post()) {
    if (!csrf_verify()) {
        csrf_regenerate();
        $errors[] = 'Session expirée. Réessayez.';
    }

    if ($action === 'setup_password') {
        if (!$setupUser) {
            $errors[] = 'Ce lien est invalide, expiré ou a déjà été utilisé.';
        } elseif (!$errors) {
            $password = (string)($_POST['new_password'] ?? '');
            $errors = password_policy_errors($password);

            if (!$errors) {
                $user = complete_user_password_setup($setupToken, $password);

                if (!$user) {
                    $errors[] = 'Ce lien est invalide, expiré ou a déjà été utilisé.';
                } else {
                    login_user($user);
                    set_flash('success', 'Votre mot de passe a été enregistré. Vous êtes maintenant connecté.');
                    redirect(user_must_accept_charter($user) ? url('charter.php') : url('dashboard.php'));
                }
            }
        }
    } else {
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
            redirect(url('dashboard.php'));
        }

        $inactiveUser = db_fetch_one(
            "SELECT *
             FROM users
             WHERE email = :identifier OR username = :identifier
             LIMIT 1",
            ['identifier' => mb_strtolower($identifier, 'UTF-8')]
        );

        if (
            $inactiveUser
            && (int)$inactiveUser['is_active'] !== 1
            && password_verify($password, (string)$inactiveUser['password_hash'])
        ) {
            $errors[] = 'Votre compte n’est pas encore activé. Cliquez sur le lien reçu par email pour valider votre inscription.';
        } else {
            $errors[] = 'Identifiants incorrects ou compte désactivé.';
        }

        auth_rate_limit_record('login', $identifier, false, $password);
    }
    }
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Connexion — <?= e(APP_NAME) ?></title>
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
            <?php if ($setupToken !== ''): ?>
                <p class="public-eyebrow">Première connexion</p>
                <h1>Espace professionnel</h1>
                <p class="muted">
                    <?= $setupUser ? 'Ce formulaire vous permet durant 24h de choisir votre mot de passe et de vous connecter à votre compte.' : '' ?>
                </p>
                <p class="muted">
                    <?= $setupUser ? 'Identifiant : ' . e((string)$setupUser['username']) : 'Ce lien ne permet plus de définir un mot de passe.' ?>
                </p>
            <?php else: ?>
                <p class="public-eyebrow">Salle des profs</p>
                <h1>Espace professionnel</h1>
                <p class="muted">
                    Accès réservé aux personnels enregistrés par l’administrateur de l’établissement.
                </p>
            <?php endif; ?>

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

            <?php if ($setupToken !== '' && $setupUser): ?>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="setup_password">
                    <input type="hidden" name="setup_token" value="<?= e($setupToken) ?>">

                    <label for="new_password">Nouveau mot de passe</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        minlength="16"
                        required
                        autofocus
                        autocomplete="new-password"
                    >
                    <p class="meta">
                        16 caractères minimum, avec lettres, chiffres et caractères spéciaux.
                    </p>

                    <div class="form-actions">
                        <button type="submit" class="button-primary">
                            Enregistrer et me connecter
                        </button>
                    </div>
                </form>
            <?php elseif ($setupToken !== ''): ?>
                <p class="auth-return-link">
                    <a class="button-secondary" href="<?= e(url('login.php')) ?>">Revenir à la connexion</a>
                </p>
            <?php else: ?>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">

                    <label for="identifier">Nom d’utilisateur ou email</label>
                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        value="<?= e(post_value('identifier')) ?>"
                        required
                        autocomplete="username"
                    >

                    <label for="password">Mot de passe</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        autocomplete="current-password"
                    >

                    <div class="form-actions">
                        <button type="submit" class="button-primary">
                            Se connecter
                        </button>
                        <a class="button-secondary" href="<?= e(url('messenger_login.php')) ?>">Accès direct à la messagerie</a>
                    </div>
                </form>
                <p class="auth-return-link muted">
                    Pour obtenir un compte, contactez l’administrateur du site ou votre référent d’établissement.
                </p>
            <?php endif; ?>
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
