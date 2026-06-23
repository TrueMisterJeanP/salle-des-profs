<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';

$errors = [];
$success = false;
$developmentRootInstallAllowed = app_development_root_install_allowed();

ensure_dir(__DIR__ . '/database');
ensure_dir(UPLOAD_PATH);
ensure_dir(AVATAR_UPLOAD_PATH);
ensure_dir(ATTACHMENT_UPLOAD_PATH);

if (database_is_installed()) {
    redirect(url('login.php'));
}

if (is_post()) {
    require_csrf();

    $action = post_value('action', 'install');
    $developmentRootRequested = $action === 'install_development_root';
    $siteName = $developmentRootRequested ? APP_NAME : post_value('site_name', APP_NAME);
    $username = $developmentRootRequested ? 'root' : post_value('username');
    $email = $developmentRootRequested ? 'root@example.invalid' : post_value('email');
    $displayName = $developmentRootRequested ? 'Root' : post_value('display_name');
    $password = $developmentRootRequested ? 'root' : (string)($_POST['password'] ?? '');
    $passwordConfirm = $developmentRootRequested ? 'root' : (string)($_POST['password_confirm'] ?? '');

    if ($developmentRootRequested && !$developmentRootInstallAllowed) {
        $errors[] = 'L’installation root/root est réservée au développement local.';
    }

    if ($siteName === '') {
        $errors[] = 'Le nom du site est obligatoire.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Les deux mots de passe ne correspondent pas.';
    }

    if (!$errors) {
        try {
            execute_sql_file(__DIR__ . '/database/schema.sqlite.sql');

            save_setting_value('site_name', $siteName);

            if ($developmentRootRequested) {
                $createdAt = now();
                $adminId = db_insert(
                    "INSERT INTO users
                        (username, email, password_hash, display_name, role, is_active, email_verified_at, created_at)
                     VALUES
                        (:username, :email, :password_hash, :display_name, 'admin', 1, :email_verified_at, :created_at)",
                    [
                        'username' => 'root',
                        'email' => 'root@example.invalid',
                        'password_hash' => password_hash('root', PASSWORD_DEFAULT),
                        'display_name' => 'Root',
                        'email_verified_at' => $createdAt,
                        'created_at' => $createdAt,
                    ]
                );
            } else {
                $adminId = register_user(
                    $username,
                    $email,
                    $password,
                    $displayName,
                    'admin'
                );
            }

            $admin = db_fetch_one(
                "SELECT * FROM users WHERE id = :id LIMIT 1",
                ['id' => $adminId]
            );

            if ($admin) {
                login_user($admin);
            }

            $success = true;
            redirect(url('dashboard.php'));
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Installation — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/public/assets/app.css')) ?>">
</head>
<body class="auth-page">
    <main class="auth-container">
        <section class="card">
            <h1>Installation</h1>
            <p class="muted">
                Création de la base de données et du premier compte administrateur.
            </p>

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

            <?php if ($developmentRootInstallAllowed): ?>
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="install_development_root">
                    <button type="submit" class="button-secondary">
                        Installation locale de démonstration
                    </button>
                    <p class="muted">
                        Crée le compte <code>root</code> avec le mot de passe <code>root</code>.
                        Cette option est désactivée hors de localhost et en production.
                    </p>
                </form>

                <hr>
            <?php endif; ?>

            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="install">

                <label for="site_name">Nom du site</label>
                <input
                    type="text"
                    id="site_name"
                    name="site_name"
                    value="<?= e(post_value('site_name', APP_NAME)) ?>"
                    required
                >

                <hr>

                <h2>Compte administrateur</h2>

                <label for="username">Nom d’utilisateur</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    value="<?= e(post_value('username')) ?>"
                    required
                    minlength="3"
                    autocomplete="username"
                >

                <label for="display_name">Nom affiché</label>
                <input
                    type="text"
                    id="display_name"
                    name="display_name"
                    value="<?= e(post_value('display_name')) ?>"
                    autocomplete="name"
                >

                <label for="email">Adresse email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= e(post_value('email')) ?>"
                    required
                    autocomplete="email"
                >

                <label for="password">Mot de passe</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >

                <label for="password_confirm">Confirmation du mot de passe</label>
                <input
                    type="password"
                    id="password_confirm"
                    name="password_confirm"
                    required
                    minlength="8"
                    autocomplete="new-password"
                >

                <button type="submit" class="button-primary">
                    Installer
                </button>
            </form>
        </section>
    </main>
</body>
</html>
