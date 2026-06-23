<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_setup.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

$token = trim(is_post() ? post_value('token') : get_value('token'));
$setupUser = user_password_setup_by_token($token);
$errors = [];

if (is_post()) {
    require_csrf();

    if (!$setupUser) {
        $errors[] = 'Ce lien est invalide, expiré ou a déjà été utilisé.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $errors = password_policy_errors($password);

        if (!$errors) {
            $user = complete_user_password_setup($token, $password);

            if (!$user) {
                $errors[] = 'Ce lien est invalide, expiré ou a déjà été utilisé.';
            } else {
                login_user($user);
                set_flash('success', 'Votre mot de passe a été enregistré. Vous êtes maintenant connecté.');
                redirect(user_must_accept_charter($user) ? url('charter.php') : url('dashboard.php'));
            }
        }
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Choisir mon mot de passe — <?= e(site_name()) ?></title>
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
            <p class="public-eyebrow">Première connexion</p>
            <h1>Choisir mon mot de passe</h1>

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

            <?php if ($setupUser): ?>
                <p class="muted">
                    Compte : <strong><?= e((string)$setupUser['username']) ?></strong>
                </p>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">

                    <label for="password">Nouveau mot de passe</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="16"
                        autocomplete="new-password"
                        required
                        autofocus
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
            <?php elseif (!$errors): ?>
                <div class="flash flash-error">
                    Ce lien est invalide, expiré ou a déjà été utilisé.
                </div>
                <p class="auth-return-link">
                    <a href="<?= e(url('login.php')) ?>">Revenir à la connexion</a>
                </p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
