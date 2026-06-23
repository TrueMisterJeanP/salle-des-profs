<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';

require_login();

$user = current_user();
$errors = [];

function get_mastodon_setting(int $userId, string $key, string $default = ''): string
{
    return setting_value('user_' . $userId . '_' . $key, $default);
}

function save_mastodon_setting(int $userId, string $key, string $value): void
{
    save_setting_value('user_' . $userId . '_' . $key, $value);
}

if (is_post()) {
    require_csrf();

    $instanceUrl = rtrim(post_value('mastodon_instance_url'), '/');
    $accessToken = trim((string)($_POST['mastodon_access_token'] ?? ''));
    $defaultVisibility = post_value('mastodon_default_visibility', 'public');

    $allowedVisibilities = ['public', 'unlisted', 'private'];

    if ($instanceUrl !== '' && !filter_var($instanceUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'L’URL de l’instance Mastodon est invalide.';
    }

    if (!in_array($defaultVisibility, $allowedVisibilities, true)) {
        $errors[] = 'La visibilité Mastodon est invalide.';
    }

    if (!$errors) {
        try {
            if ($accessToken === '') {
                $accessToken = get_mastodon_setting((int)$user['id'], 'mastodon_access_token');
            }

            save_mastodon_setting((int)$user['id'], 'mastodon_instance_url', $instanceUrl);
            save_mastodon_setting((int)$user['id'], 'mastodon_access_token', $accessToken);
            save_mastodon_setting((int)$user['id'], 'mastodon_default_visibility', $defaultVisibility);

            set_flash('success', 'Paramètres Mastodon enregistrés.');
            redirect(url('mastodon_settings.php'));
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$instanceUrl = get_mastodon_setting((int)$user['id'], 'mastodon_instance_url');
$accessToken = get_mastodon_setting((int)$user['id'], 'mastodon_access_token');
$defaultVisibility = get_mastodon_setting((int)$user['id'], 'mastodon_default_visibility', 'public');
$hasAccessToken = $accessToken !== '';

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Mastodon — <?= e(APP_NAME) ?></title>
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
            <h1>Connexion Mastodon</h1>
            <p class="muted">
                Configurez un compte Mastodon pour publier manuellement certains contenus publics.
            </p>
        </section>

        <section class="card">
            <form method="post" action="">
                <?= csrf_field() ?>

                <label for="mastodon_instance_url">URL de l’instance Mastodon</label>
                <input
                    type="url"
                    id="mastodon_instance_url"
                    name="mastodon_instance_url"
                    value="<?= e($instanceUrl) ?>"
                    placeholder="https://mastodon.social"
                >

                <label for="mastodon_access_token">Jeton d’accès Mastodon</label>
                <input
                    type="password"
                    id="mastodon_access_token"
                    name="mastodon_access_token"
                    value=""
                    placeholder="<?= $hasAccessToken ? 'Jeton enregistré, laisser vide pour le conserver' : 'Jeton avec droit write:statuses' ?>"
                    autocomplete="new-password"
                >

                <label for="mastodon_default_visibility">Visibilité par défaut</label>
                <select id="mastodon_default_visibility" name="mastodon_default_visibility">
                    <option value="public" <?= $defaultVisibility === 'public' ? 'selected' : '' ?>>
                        Public
                    </option>
                    <option value="unlisted" <?= $defaultVisibility === 'unlisted' ? 'selected' : '' ?>>
                        Non listé
                    </option>
                    <option value="private" <?= $defaultVisibility === 'private' ? 'selected' : '' ?>>
                        Privé
                    </option>
                </select>

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        Enregistrer
                    </button>

                    <a class="button-secondary" href="<?= e(url('profile.php')) ?>">
                        Retour au profil
                    </a>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Comment obtenir un jeton ?</h2>

            <p>
                Dans votre compte Mastodon, allez dans les préférences de développement,
                créez une application personnelle, puis générez un jeton avec l’autorisation :
            </p>

            <pre><code>write:statuses</code></pre>

            <p class="muted">
                Cette V1 stocke le jeton dans la base SQLite. Pour une version plus sensible,
                on pourra ensuite chiffrer cette valeur.
            </p>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
