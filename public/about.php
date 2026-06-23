<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/markdown.php';

require_login();

$siteName = site_name();
$siteLogoUrl = site_logo_url();
$siteIcon = site_icon();
$aboutContent = about_content();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>À propos — <?= e($siteName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php render_seo_meta([
    'title' => 'À propos — ' . $siteName,
    'description' => seo_description($aboutContent),
    'canonical' => url('about.php'),
]); ?>
    <link rel="manifest" href="<?= e(url('manifest.json')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>

    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="container page">
        <section class="card about-card">
            <h1>À propos</h1>

            <div class="markdown-content">
                <?= render_markdown($aboutContent) ?>
            </div>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
