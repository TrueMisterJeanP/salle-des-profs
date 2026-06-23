<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/markdown.php';

require_login();

$user = current_user();

if ($user && !user_must_accept_charter($user)) {
    redirect(url('dashboard.php'));
}

if (is_post()) {
    require_csrf();

    $decision = post_value('decision');

    if ($decision === 'accept') {
        db_query(
            "UPDATE users
             SET charter_accepted_at = :accepted_at,
                 updated_at = :updated_at
             WHERE id = :id",
            [
                'accepted_at' => now(),
                'updated_at' => now(),
                'id' => (int)$user['id'],
            ]
        );
        set_flash('success', 'La charte a été acceptée, bienvenue.');
        redirect(url('dashboard.php'));
    }

    if ($decision === 'refuse') {
        db_query(
            "UPDATE users
             SET is_active = 0,
                 updated_at = :updated_at
             WHERE id = :id",
            [
                'updated_at' => now(),
                'id' => (int)$user['id'],
            ]
        );
        logout_user();
        set_flash('error', 'Votre compte a été désactivé suite au refus de la charte.');
        redirect(url('login.php'));
    }
}

$charterContent = usage_charter_content();
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Charte d’utilisation — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/../templates/header.php'; ?>
<main class="container page">
    <?php foreach ($flashes as $flash): ?>
        <div class="<?= e(flash_class($flash['type'])) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>

    <section class="card charter-card">
        <div class="markdown-content">
            <?= render_markdown($charterContent) ?>
        </div>

        <form method="post" action="" class="form-actions charter-actions">
            <?= csrf_field() ?>
            <button class="button-primary" type="submit" name="decision" value="accept">J'accepte</button>
            <button class="button-danger" type="submit" name="decision" value="refuse" onclick="return confirm('Refuser la charte désactivera votre compte. Continuer ?');">Je refuse</button>
        </form>
    </section>
</main>
<?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
