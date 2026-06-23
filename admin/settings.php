<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/storage_quota.php';
require_once __DIR__ . '/../includes/federation.php';

require_admin();

$errors = [];
if (is_post()) {
    require_csrf();

    $action = post_value('action', 'save_settings');

    if ($action === 'upload_site_logo') {
        $file = $_FILES['site_logo'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Aucune image reçue.';
        } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $errors[] = 'Erreur pendant l’envoi de l’image.';
        } else {
            $tmpPath = (string)($file['tmp_name'] ?? '');
            $size = (int)($file['size'] ?? 0);

            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                $errors[] = 'Fichier temporaire invalide.';
            } elseif ($size <= 0 || $size > effective_upload_size_limit()) {
                $errors[] = 'L’image dépasse la taille maximale autorisée.';
            } else {
                $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';

                if (!in_array($mimeType, ALLOWED_AVATAR_MIME_TYPES, true)) {
                    $errors[] = 'Type d’image non autorisé : ' . $mimeType;
                } else {
                    $extension = match ($mimeType) {
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        default => 'bin',
                    };
                    $safeName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
                    $destinationPath = upload_subdir_path('site') . DIRECTORY_SEPARATOR . $safeName;
                    $relativePath = upload_logical_path('site', $safeName);
                    $oldLogoPath = site_logo_path();

                    ensure_dir(upload_subdir_path('site'));

                    if (!move_uploaded_file($tmpPath, $destinationPath)) {
                        $errors[] = 'Impossible d’enregistrer le logo.';
                    } else {
                        save_setting_value('site_logo_path', $relativePath);

                        if ($oldLogoPath !== '') {
                            $oldFilePath = upload_realpath_from_logical($oldLogoPath);
                            $siteRoot = realpath(upload_subdir_path('site'));

                            if (
                                $oldFilePath !== false
                                && $siteRoot !== false
                                && is_file($oldFilePath)
                                && strncmp($oldFilePath, $siteRoot . DIRECTORY_SEPARATOR, strlen($siteRoot . DIRECTORY_SEPARATOR)) === 0
                            ) {
                                unlink($oldFilePath);
                            }
                        }

                        set_flash('success', 'Logo du site enregistré.');
                        redirect(admin_url('settings.php'));
                    }
                }
            }
        }
    } elseif ($action === 'delete_site_logo') {
        $oldLogoPath = site_logo_path();

        if ($oldLogoPath !== '') {
            $oldFilePath = upload_realpath_from_logical($oldLogoPath);
            $siteRoot = realpath(upload_subdir_path('site'));

            if (
                $oldFilePath !== false
                && $siteRoot !== false
                && is_file($oldFilePath)
                && strncmp($oldFilePath, $siteRoot . DIRECTORY_SEPARATOR, strlen($siteRoot . DIRECTORY_SEPARATOR)) === 0
            ) {
                unlink($oldFilePath);
            }
        }

        save_setting_value('site_logo_path', '');
        set_flash('success', 'Logo du site supprimé.');
        redirect(admin_url('settings.php'));
    } elseif ($action === 'send_test_mail') {
        $testEmail = mail_from_email();

        if (!is_valid_email($testEmail)) {
            $errors[] = 'Adresse email d’expéditeur invalide.';
        } elseif (mail_send_text(
            $testEmail,
            mail_from_name(),
            'Courriel de test — ' . site_name(),
            "Ceci est un email de test envoyé depuis l'administration de " . site_name() . ".\n\nSi vous recevez ce message, la configuration d'envoi fonctionne."
        )) {
            set_flash('success', 'Courriel de test envoyé à ' . $testEmail . '.');
            redirect(admin_url('settings.php'));
        } else {
            $errors[] = 'Impossible d’envoyer l’email de test. Vérifiez la configuration SMTP ou mail() du serveur.';
        }
    } elseif ($action === 'generate_federation_publication_token') {
        save_setting_value('federation_publication_token', bin2hex(random_bytes(24)));
        set_flash('success', 'Token de publication fédérée généré.');
        redirect(admin_url('settings.php'));
    } elseif ($action === 'generate_ical_token') {
        save_setting_value('ical_enabled', isset($_POST['ical_enabled']) ? '1' : '0');
        save_setting_value('ical_token', bin2hex(random_bytes(24)));
        set_flash('success', 'Token iCal généré.');
        redirect(admin_url('settings.php'));
    } elseif ($action !== 'save_settings') {
        $errors[] = 'Action inconnue.';
    }

    $siteName = post_value('site_name');
    $siteIcon = trim(post_value('site_icon'));
    $siteMetaTitle = post_value('site_meta_title');
    $siteMetaDescription = post_value('site_meta_description');
    $siteMetaKeywords = post_value('site_meta_keywords');
    $siteMetaAuthor = post_value('site_meta_author');
    $siteMetaRobots = post_value('site_meta_robots', 'index,follow');
    $siteCanonicalUrl = trim(post_value('site_canonical_url'));
    $siteOgImageUrl = trim(post_value('site_og_image_url'));
    $uploadStoragePath = trim(post_value('upload_storage_path'));
    $globalStorageQuotaGb = post_value('global_storage_quota_gb');
    $defaultUserStorageQuotaMb = post_value('default_user_storage_quota_mb');
    $federationEnabled = isset($_POST['federation_enabled']) ? '1' : '0';
    $federationFollowSlots = [];
    for ($index = 1; $index <= 4; $index++) {
        $nameKey = $index === 1 ? 'federation_name' : 'federation_name_' . $index;
        $urlKey = $index === 1 ? 'federation_follow_url' : 'federation_follow_url_' . $index;
        $tokenKey = $index === 1 ? 'federation_token' : 'federation_token_' . $index;
        $federationFollowSlots[] = [
            'index' => $index,
            'name' => trim(post_value($nameKey)),
            'url' => rtrim(trim(post_value($urlKey)), '/'),
            'token' => trim(post_value($tokenKey)),
        ];
    }
    $federationPublicationToken = trim(post_value('federation_publication_token'));
    $icalEnabled = isset($_POST['ical_enabled']) ? '1' : '0';
    $icalToken = trim(post_value('ical_token'));
    $googleSiteVerification = trim(post_value('google_site_verification'));
    $bingSiteVerification = trim(post_value('bing_site_verification'));
    $mailFromName = post_value('mail_from_name');
    $mailFromEmail = mb_strtolower(post_value('mail_from_email'), 'UTF-8');
    $smtpHost = post_value('smtp_host');
    $smtpPort = (int)post_value('smtp_port', '0');
    $smtpEncryption = post_value('smtp_encryption', 'none');
    $smtpUsername = post_value('smtp_username');
    $smtpPassword = (string)($_POST['smtp_password'] ?? '');
    $clearSmtpPassword = isset($_POST['clear_smtp_password']);
    $imapHost = post_value('imap_host');
    $imapPort = (int)post_value('imap_port', '0');
    $imapEncryption = post_value('imap_encryption', 'none');
    $imapUsername = post_value('imap_username');
    $imapPassword = (string)($_POST['imap_password'] ?? '');
    $clearImapPassword = isset($_POST['clear_imap_password']);
    $aboutContent = post_value('about_content');
    $footerContent = post_value('footer_content');
    $usageCharterContent = post_value('usage_charter_content');

    $allowedRobots = ['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'];

    if ($action === 'save_settings' && $siteName === '') {
        $errors[] = 'Le nom du site est obligatoire.';
    }

    if ($action === 'save_settings' && $siteMetaDescription !== '' && mb_strlen($siteMetaDescription, 'UTF-8') > 300) {
        $errors[] = 'La description SEO doit faire 300 caractères maximum.';
    }

    if ($action === 'save_settings' && !in_array($siteMetaRobots, $allowedRobots, true)) {
        $errors[] = 'Directive robots invalide.';
    }

    if ($action === 'save_settings' && $siteCanonicalUrl !== '' && filter_var($siteCanonicalUrl, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'URL canonique invalide.';
    }

    if ($action === 'save_settings' && $siteOgImageUrl !== '' && filter_var($siteOgImageUrl, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'URL de l’image de partage invalide.';
    }

    if ($action === 'save_settings') {
        foreach ($federationFollowSlots as $slot) {
            $slotLabel = 'flux fédéré #' . $slot['index'];

            if ($slot['url'] !== '' && filter_var($slot['url'], FILTER_VALIDATE_URL) === false) {
                $errors[] = 'URL du ' . $slotLabel . ' invalide.';
            }

            if ($slot['token'] !== '' && !federation_token_is_valid($slot['token'])) {
                $errors[] = 'Le token du ' . $slotLabel . ' doit contenir exactement 48 caractères alphanumériques.';
            }

            if (($slot['url'] === '') !== ($slot['token'] === '')) {
                $errors[] = 'L’URL et le token doivent être renseignés ensemble pour le ' . $slotLabel . '.';
            }
        }
    }

    if ($action === 'save_settings' && $federationPublicationToken !== '' && !federation_token_is_valid($federationPublicationToken)) {
        $errors[] = 'Votre token de publication fédérée doit contenir exactement 48 caractères alphanumériques.';
    }

    if ($action === 'save_settings' && $icalEnabled === '1' && $icalToken === '') {
        $icalToken = bin2hex(random_bytes(24));
    }

    if ($action === 'save_settings' && $icalToken !== '' && !ical_token_is_valid($icalToken)) {
        $errors[] = 'Le token iCal doit contenir exactement 48 caractères alphanumériques.';
    }

    if ($action === 'save_settings' && $federationEnabled === '1') {
        $hasFederationSlot = false;

        foreach ($federationFollowSlots as $slot) {
            if ($slot['url'] !== '' && $slot['token'] !== '') {
                $hasFederationSlot = true;
                break;
            }
        }

        if (!$hasFederationSlot) {
            $errors[] = 'Au moins un flux distant complet est obligatoire pour activer la fédération.';
        }
    }

    if ($action === 'save_settings' && $uploadStoragePath !== '') {
        $isAbsolutePath = str_starts_with($uploadStoragePath, '/')
            || preg_match('/^[A-Za-z]:[\/\\\\]/', $uploadStoragePath) === 1;

        if (!$isAbsolutePath) {
            $errors[] = 'Le dossier de stockage doit être un chemin absolu.';
        } elseif (!path_is_allowed_by_open_basedir($uploadStoragePath)) {
            $errors[] = 'Le dossier de stockage est bloqué par la restriction PHP open_basedir.';
        } elseif (!is_dir($uploadStoragePath)) {
            $errors[] = 'Le dossier de stockage n’existe pas.';
        } elseif (!is_writable($uploadStoragePath)) {
            $errors[] = 'Le dossier de stockage n’est pas accessible en écriture par PHP.';
        }
    }

    $globalStorageQuotaBytes = null;
    $defaultUserStorageQuotaBytes = null;

    if ($action === 'save_settings') {
        try {
            $globalStorageQuotaBytes = storage_quota_parse_size_to_bytes($globalStorageQuotaGb, 1024 * 1024 * 1024);
            $defaultUserStorageQuotaBytes = storage_quota_parse_size_to_bytes($defaultUserStorageQuotaMb, 1024 * 1024);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if ($action === 'save_settings' && $mailFromEmail !== '' && !is_valid_email($mailFromEmail)) {
        $errors[] = 'Adresse email d’expéditeur invalide.';
    }

    if ($action === 'save_settings' && !in_array($smtpEncryption, mail_encryption_options(), true)) {
        $errors[] = 'Chiffrement SMTP invalide.';
    }

    if ($action === 'save_settings' && $smtpHost !== '' && $smtpPort !== 0 && ($smtpPort < 1 || $smtpPort > 65535)) {
        $errors[] = 'Port SMTP invalide.';
    }

    if ($action === 'save_settings' && !in_array($imapEncryption, mail_encryption_options(), true)) {
        $errors[] = 'Chiffrement IMAP invalide.';
    }

    if ($action === 'save_settings' && $imapHost !== '' && $imapPort !== 0 && ($imapPort < 1 || $imapPort > 65535)) {
        $errors[] = 'Port IMAP invalide.';
    }

    if ($action === 'save_settings' && !$errors) {
        try {
            save_setting_value('site_name', $siteName);
            save_setting_value('site_icon', mb_substr($siteIcon, 0, 8, 'UTF-8'));
            save_setting_value('site_meta_title', $siteMetaTitle !== '' ? $siteMetaTitle : $siteName);
            save_setting_value('site_meta_description', $siteMetaDescription);
            save_setting_value('site_meta_keywords', $siteMetaKeywords);
            save_setting_value('site_meta_author', $siteMetaAuthor);
            save_setting_value('site_meta_robots', $siteMetaRobots);
            save_setting_value('site_canonical_url', $siteCanonicalUrl !== '' ? rtrim($siteCanonicalUrl, '/') : rtrim(BASE_URL, '/'));
            save_setting_value('site_og_image_url', $siteOgImageUrl);
            save_setting_value('upload_storage_path', $uploadStoragePath !== '' ? rtrim($uploadStoragePath, '/\\') : '');
            storage_quota_set_global_quota($globalStorageQuotaBytes);
            save_setting_value('default_user_storage_quota_bytes', $defaultUserStorageQuotaBytes !== null && $defaultUserStorageQuotaBytes > 0 ? (string)$defaultUserStorageQuotaBytes : '');
            save_setting_value('federation_enabled', $federationEnabled);
            foreach ($federationFollowSlots as $slot) {
                $nameKey = $slot['index'] === 1 ? 'federation_name' : 'federation_name_' . $slot['index'];
                $urlKey = $slot['index'] === 1 ? 'federation_follow_url' : 'federation_follow_url_' . $slot['index'];
                $tokenKey = $slot['index'] === 1 ? 'federation_token' : 'federation_token_' . $slot['index'];
                save_setting_value($nameKey, $slot['name']);
                save_setting_value($urlKey, $slot['url']);
                save_setting_value($tokenKey, $slot['token']);
            }
            save_setting_value('federation_publication_token', $federationPublicationToken);
            save_setting_value('federation_cache_last_refresh_at', '');
            save_setting_value('ical_enabled', $icalEnabled);
            save_setting_value('ical_token', $icalToken);
            save_setting_value('google_site_verification', $googleSiteVerification);
            save_setting_value('bing_site_verification', $bingSiteVerification);
            save_setting_value('mail_from_name', $mailFromName);
            save_setting_value('mail_from_email', $mailFromEmail);
            save_setting_value('smtp_host', $smtpHost);
            save_setting_value('smtp_port', $smtpHost !== '' && $smtpPort > 0 ? (string)$smtpPort : '');
            save_setting_value('smtp_encryption', $smtpEncryption);
            save_setting_value('smtp_username', $smtpUsername);

            if ($clearSmtpPassword) {
                save_setting_value('smtp_password', '');
            } elseif ($smtpPassword !== '') {
                save_setting_value('smtp_password', $smtpPassword);
            }

            save_setting_value('imap_host', $imapHost);
            save_setting_value('imap_port', $imapHost !== '' && $imapPort > 0 ? (string)$imapPort : '');
            save_setting_value('imap_encryption', $imapEncryption);
            save_setting_value('imap_username', $imapUsername);

            if ($clearImapPassword) {
                save_setting_value('imap_password', '');
            } elseif ($imapPassword !== '') {
                save_setting_value('imap_password', $imapPassword);
            }

            save_setting_value('about_content', $aboutContent);
            save_setting_value('footer_content', $footerContent);
            save_setting_value('usage_charter_content', $usageCharterContent);

            set_flash('success', 'Configuration enregistrée.');
            redirect(admin_url('settings.php'));
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$siteName = setting_value('site_name', APP_NAME);
$siteIcon = site_icon();
$siteLogoUrl = site_logo_url();
$siteMetaTitle = site_meta_title();
$siteMetaDescription = site_meta_description();
$siteMetaKeywords = site_meta_keywords();
$siteMetaAuthor = site_meta_author();
$siteMetaRobots = site_meta_robots();
$siteCanonicalUrl = site_canonical_base_url();
$siteOgImageUrl = setting_value('site_og_image_url', '');
$uploadStoragePath = setting_value('upload_storage_path', '');
$uploadStorageEffectivePath = upload_storage_path();
$uploadStorageAllowed = path_is_allowed_by_open_basedir($uploadStorageEffectivePath);
$uploadStorageWritable = $uploadStorageAllowed
    && is_dir($uploadStorageEffectivePath)
    && is_writable($uploadStorageEffectivePath);
$storageQuota = storage_quota_global();
$globalStorageQuotaGb = storage_quota_bytes_to_unit(
    $storageQuota['quota_bytes'] !== null ? (int)$storageQuota['quota_bytes'] : null,
    1024 * 1024 * 1024
);
$defaultUserStorageQuotaMb = storage_quota_bytes_to_unit(
    storage_quota_default_user_quota_bytes(),
    1024 * 1024
);
$federationEnabled = federation_enabled();
$federationFollowSlots = federation_follow_slots();
$federationPublicationToken = federation_publication_token();
$icalEnabled = ical_enabled();
$icalToken = ical_token();
$icalFeedUrl = ical_feed_url($icalToken);
$googleSiteVerification = site_google_verification();
$bingSiteVerification = site_bing_verification();
$mailFromName = mail_from_name();
$mailFromEmail = setting_value('mail_from_email', '');
$smtpHost = setting_value('smtp_host', '');
$smtpPort = setting_value('smtp_port', '');
$smtpEncryption = mail_setting_encryption('smtp_encryption');
$smtpUsername = setting_value('smtp_username', '');
$smtpPasswordConfigured = setting_value('smtp_password', '') !== '';
$imapHost = setting_value('imap_host', '');
$imapPort = setting_value('imap_port', '');
$imapEncryption = mail_setting_encryption('imap_encryption');
$imapUsername = setting_value('imap_username', '');
$imapPasswordConfigured = setting_value('imap_password', '') !== '';
$aboutContent = setting_value('about_content', str_replace('{site_name}', $siteName, default_about_content()));
$footerContent = setting_value('footer_content', str_replace('{site_name}', $siteName, default_footer_content()));
$usageCharterContent = usage_charter_content();

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Configuration — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
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
            <h1>Configuration</h1>
            <p class="muted">
                Paramètres généraux de la plateforme.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
                <a class="button-secondary" href="<?= e(url('dashboard.php')) ?>">Retour au site</a>
            </div>
        </section>

        <section class="card">
            <h2>Logo du site</h2>

            <?php if ($siteLogoUrl !== ''): ?>
                <p>
                    <span class="brand-icon brand-logo">
                        <img src="<?= e($siteLogoUrl) ?>" alt="">
                    </span>
                </p>
            <?php else: ?>
                <p class="muted">Aucun logo image n’est configuré.</p>
            <?php endif; ?>

            <form method="post" action="" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_site_logo">

                <label for="site_logo">Télécharger une image</label>
                <input
                    type="file"
                    id="site_logo"
                    name="site_logo"
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    required
                >
                <p class="muted">
                    Formats acceptés : JPG, PNG, GIF, WebP. Taille maximale : <?= e(human_file_size(effective_upload_size_limit())) ?>.
                </p>

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        Télécharger le logo
                    </button>
                </div>
            </form>

            <?php if ($siteLogoUrl !== ''): ?>
                <form method="post" action="" onsubmit="return confirm('Supprimer le logo du site ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_site_logo">

                    <div class="form-actions">
                        <button type="submit" class="button-danger">
                            Supprimer le logo
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="card">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">

                <label for="site_name">Nom du site</label>
                <input
                    type="text"
                    id="site_name"
                    name="site_name"
                    value="<?= e($siteName) ?>"
                    required
                >

                <label for="site_icon">Icône du site</label>
                <input
                    type="text"
                    id="site_icon"
                    name="site_icon"
                    value="<?= e($siteIcon) ?>"
                    maxlength="8"
                    placeholder="Ex. MB, ★, µ"
                >
                <p class="muted">
                    Affichée en haut à gauche des pages. Laissez vide pour utiliser l’icône par défaut.
                </p>

                <hr>

                <h2>Référencement</h2>

                <label for="site_meta_title">Titre SEO par défaut</label>
                <input
                    type="text"
                    id="site_meta_title"
                    name="site_meta_title"
                    value="<?= e($siteMetaTitle) ?>"
                    maxlength="120"
                    placeholder="<?= e($siteName) ?>"
                >

                <label for="site_meta_description">Description SEO</label>
                <textarea
                    id="site_meta_description"
                    name="site_meta_description"
                    rows="3"
                    maxlength="300"
                    placeholder="Résumé clair du site pour Google et les partages sociaux."
                ><?= e($siteMetaDescription) ?></textarea>

                <label for="site_meta_keywords">Mots-clés</label>
                <input
                    type="text"
                    id="site_meta_keywords"
                    name="site_meta_keywords"
                    value="<?= e($siteMetaKeywords) ?>"
                    placeholder="protection enseignants, soutien, coordination"
                >

                <label for="site_meta_author">Auteur / organisation</label>
                <input
                    type="text"
                    id="site_meta_author"
                    name="site_meta_author"
                    value="<?= e($siteMetaAuthor) ?>"
                    placeholder="<?= e($siteName) ?>"
                >

                <label for="site_meta_robots">Indexation</label>
                <select id="site_meta_robots" name="site_meta_robots">
                    <option value="index,follow" <?= $siteMetaRobots === 'index,follow' ? 'selected' : '' ?>>
                        Indexer et suivre les liens
                    </option>
                    <option value="index,nofollow" <?= $siteMetaRobots === 'index,nofollow' ? 'selected' : '' ?>>
                        Indexer sans suivre les liens
                    </option>
                    <option value="noindex,follow" <?= $siteMetaRobots === 'noindex,follow' ? 'selected' : '' ?>>
                        Ne pas indexer, suivre les liens
                    </option>
                    <option value="noindex,nofollow" <?= $siteMetaRobots === 'noindex,nofollow' ? 'selected' : '' ?>>
                        Ne pas indexer et ne pas suivre les liens
                    </option>
                </select>

                <label for="site_canonical_url">URL canonique du site</label>
                <input
                    type="url"
                    id="site_canonical_url"
                    name="site_canonical_url"
                    value="<?= e($siteCanonicalUrl) ?>"
                    placeholder="<?= e(BASE_URL) ?>"
                >

                <label for="site_og_image_url">Image de partage Open Graph</label>
                <input
                    type="url"
                    id="site_og_image_url"
                    name="site_og_image_url"
                    value="<?= e($siteOgImageUrl) ?>"
                    placeholder="https://exemple.fr/image-sociale.jpg"
                >
                <p class="muted">
                    Utilisée par Google, les réseaux sociaux et les messageries. Si vide, le logo du site est utilisé.
                </p>

                <hr>

                <h2>Stockage des fichiers</h2>

                <label for="upload_storage_path">Dossier de stockage des fichiers</label>
                <input
                    type="text"
                    id="upload_storage_path"
                    name="upload_storage_path"
                    value="<?= e($uploadStoragePath) ?>"
                    placeholder="<?= e(UPLOAD_PATH) ?>"
                    autocomplete="off"
                >
                <p class="muted">
                    Chemin absolu du dossier qui contient <code>attachments/</code>, <code>avatars/</code> et <code>site/</code>.
                    Laissez vide pour utiliser <code><?= e(UPLOAD_PATH) ?></code>.
                </p>
                <p class="<?= $uploadStorageWritable ? 'muted' : 'flash flash-warning' ?>">
                    Dossier actif : <code><?= e($uploadStorageEffectivePath) ?></code>
                    <?php if ($uploadStorageWritable): ?>
                        · accessible en écriture.
                    <?php elseif (!$uploadStorageAllowed): ?>
                        · bloqué par <code>open_basedir</code>.
                    <?php else: ?>
                        · non accessible en écriture.
                    <?php endif; ?>
                </p>
                <p class="muted">
                    Si vous changez ce chemin, copiez d’abord les fichiers existants de l’ancien dossier vers le nouveau disque.
                </p>

                <div class="grid grid-2">
                    <div>
                        <label for="global_storage_quota_gb">Quota global des documents</label>
                        <input
                            type="number"
                            id="global_storage_quota_gb"
                            name="global_storage_quota_gb"
                            value="<?= e($globalStorageQuotaGb) ?>"
                            min="0"
                            step="0.01"
                            placeholder="Illimité"
                        >
                        <p class="meta">
                            Valeur en Go. Actuellement utilisé : <?= e(human_file_size((int)$storageQuota['used_bytes'])) ?>.
                        </p>
                    </div>

                    <div>
                        <label for="default_user_storage_quota_mb">Quota personnel par défaut</label>
                        <input
                            type="number"
                            id="default_user_storage_quota_mb"
                            name="default_user_storage_quota_mb"
                            value="<?= e($defaultUserStorageQuotaMb) ?>"
                            min="0"
                            step="1"
                            placeholder="Illimité"
                        >
                        <p class="meta">
                            Valeur en Mo. Peut être remplacée sur la fiche d’un utilisateur.
                        </p>
                    </div>
                </div>

                <hr>

                <h2>Fédération</h2>

                <h3>Recevoir un fil distant</h3>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="federation_enabled"
                        value="1"
                        <?= $federationEnabled ? 'checked' : '' ?>
                    >
                    Afficher le fil fédéré sur le tableau de bord
                </label>

                <?php foreach ($federationFollowSlots as $slot): ?>
                    <?php
                        $slotIndex = (int)$slot['index'];
                        $nameField = $slotIndex === 1 ? 'federation_name' : 'federation_name_' . $slotIndex;
                        $urlField = $slotIndex === 1 ? 'federation_follow_url' : 'federation_follow_url_' . $slotIndex;
                        $tokenField = $slotIndex === 1 ? 'federation_token' : 'federation_token_' . $slotIndex;
                    ?>
                    <h4>Flux distant #<?= e((string)$slotIndex) ?></h4>

                    <label for="<?= e($nameField) ?>">Nom du flux</label>
                    <input
                        type="text"
                        id="<?= e($nameField) ?>"
                        name="<?= e($nameField) ?>"
                        value="<?= e($slot['name']) ?>"
                        maxlength="120"
                        placeholder="Flux #<?= e((string)$slotIndex) ?>"
                    >

                    <label for="<?= e($urlField) ?>">URL du site à suivre</label>
                    <input
                        type="url"
                        id="<?= e($urlField) ?>"
                        name="<?= e($urlField) ?>"
                        value="<?= e($slot['url']) ?>"
                        placeholder="https://salle-des-profs.example"
                    >

                    <label for="<?= e($tokenField) ?>">Token du site suivi</label>
                    <input
                        type="text"
                        id="<?= e($tokenField) ?>"
                        name="<?= e($tokenField) ?>"
                        value="<?= e($slot['token']) ?>"
                        minlength="48"
                        maxlength="48"
                        pattern="[A-Za-z0-9]{48}"
                        autocomplete="off"
                        placeholder="48 caractères alphanumériques"
                    >
                <?php endforeach; ?>
                <p class="muted">
                    Chaque URL publique sera lue sur <code>/federation_feed.php</code>. Le token correspondant est fourni par le site suivi.
                </p>

                <h3>Autoriser un site distant à nous suivre</h3>

                <label for="federation_publication_token">Notre token de publication fédérée</label>
                <input
                    type="text"
                    id="federation_publication_token"
                    name="federation_publication_token"
                    value="<?= e($federationPublicationToken) ?>"
                    minlength="48"
                    maxlength="48"
                    pattern="[A-Za-z0-9]{48}"
                    autocomplete="off"
                    placeholder="Générez un token, puis transmettez-le au site distant"
                >
                <p class="muted">
                    Donnez ce token au site distant qui doit suivre ce site. Il permet d’accéder à <code><?= e(url('federation_feed.php')) ?></code>.
                </p>

                <div class="form-actions">
                    <button
                        type="submit"
                        class="button-secondary"
                        name="action"
                        value="generate_federation_publication_token"
                        formnovalidate
                    >
                        Générer un token
                    </button>
                </div>

                <hr>

                <h2>iCal</h2>

                <label class="checkbox-label">
                    <input
                        type="checkbox"
                        name="ical_enabled"
                        value="1"
                        <?= $icalEnabled ? 'checked' : '' ?>
                    >
                    Activer l’abonnement iCal du calendrier des événements
                </label>

                <label for="ical_token">Token iCal</label>
                <input
                    type="text"
                    id="ical_token"
                    name="ical_token"
                    value="<?= e($icalToken) ?>"
                    minlength="48"
                    maxlength="48"
                    pattern="[A-Za-z0-9]{48}"
                    autocomplete="off"
                    placeholder="Générez un token pour créer le lien d’abonnement"
                >
                <p class="muted">
                    Le lien iCal permet d’abonner un calendrier d’ordinateur aux événements actifs de <code>events.php</code>.
                    Toute personne qui possède ce lien peut lire ce calendrier.
                </p>

                <?php if ($icalFeedUrl !== ''): ?>
                    <label for="ical_feed_url">URL d’abonnement iCal</label>
                    <input
                        type="url"
                        id="ical_feed_url"
                        value="<?= e($icalFeedUrl) ?>"
                        readonly
                    >
                    <p class="muted">
                        À copier dans Apple Calendar, Outlook, Thunderbird ou Google Calendar comme calendrier par URL.
                    </p>
                <?php endif; ?>

                <div class="form-actions">
                    <button
                        type="submit"
                        class="button-secondary"
                        name="action"
                        value="generate_ical_token"
                        formnovalidate
                    >
                        Générer un token iCal
                    </button>
                </div>

                <label for="google_site_verification">Code de vérification Google Search Console</label>
                <input
                    type="text"
                    id="google_site_verification"
                    name="google_site_verification"
                    value="<?= e($googleSiteVerification) ?>"
                    autocomplete="off"
                >

                <label for="bing_site_verification">Code de vérification Bing Webmaster Tools</label>
                <input
                    type="text"
                    id="bing_site_verification"
                    name="bing_site_verification"
                    value="<?= e($bingSiteVerification) ?>"
                    autocomplete="off"
                >

                <hr>

                <h2>Courriel</h2>

                <label for="mail_from_name">Nom de l’expéditeur</label>
                <input
                    type="text"
                    id="mail_from_name"
                    name="mail_from_name"
                    value="<?= e($mailFromName) ?>"
                    placeholder="<?= e($siteName) ?>"
                >

                <label for="mail_from_email">Adresse email de l’expéditeur</label>
                <input
                    type="email"
                    id="mail_from_email"
                    name="mail_from_email"
                    value="<?= e($mailFromEmail) ?>"
                    placeholder="<?= e(mail_default_from_email()) ?>"
                >
                <p class="muted">
                    Utilisée dans les emails envoyés par l’administration.
                </p>

                <div class="grid grid-2">
                    <div>
                        <h3>SMTP</h3>

                        <label for="smtp_host">Serveur SMTP</label>
                        <input
                            type="text"
                            id="smtp_host"
                            name="smtp_host"
                            value="<?= e($smtpHost) ?>"
                            placeholder="smtp.exemple.fr"
                        >

                        <label for="smtp_port">Port SMTP</label>
                        <input
                            type="number"
                            id="smtp_port"
                            name="smtp_port"
                            value="<?= e($smtpPort) ?>"
                            min="1"
                            max="65535"
                            step="1"
                            placeholder="587"
                        >

                        <label for="smtp_encryption">Chiffrement SMTP</label>
                        <select id="smtp_encryption" name="smtp_encryption">
                            <option value="tls" <?= $smtpEncryption === 'tls' ? 'selected' : '' ?>>
                                TLS / STARTTLS
                            </option>
                            <option value="ssl" <?= $smtpEncryption === 'ssl' ? 'selected' : '' ?>>
                                SSL
                            </option>
                            <option value="none" <?= $smtpEncryption === 'none' ? 'selected' : '' ?>>
                                Aucun
                            </option>
                        </select>

                        <label for="smtp_username">Identifiant SMTP</label>
                        <input
                            type="text"
                            id="smtp_username"
                            name="smtp_username"
                            value="<?= e($smtpUsername) ?>"
                            autocomplete="username"
                        >

                        <label for="smtp_password">Mot de passe SMTP</label>
                        <input
                            type="password"
                            id="smtp_password"
                            name="smtp_password"
                            value=""
                            autocomplete="new-password"
                            placeholder="<?= $smtpPasswordConfigured ? 'Mot de passe enregistré' : '' ?>"
                        >
                        <p class="muted">Laissez vide pour conserver le mot de passe SMTP actuel.</p>
                        <?php if ($smtpPasswordConfigured): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="clear_smtp_password" value="1">
                                Effacer le mot de passe SMTP enregistré
                            </label>
                        <?php endif; ?>
                    </div>

                    <div>
                        <h3>IMAP</h3>

                        <label for="imap_host">Serveur IMAP</label>
                        <input
                            type="text"
                            id="imap_host"
                            name="imap_host"
                            value="<?= e($imapHost) ?>"
                            placeholder="imap.exemple.fr"
                        >

                        <label for="imap_port">Port IMAP</label>
                        <input
                            type="number"
                            id="imap_port"
                            name="imap_port"
                            value="<?= e($imapPort) ?>"
                            min="1"
                            max="65535"
                            step="1"
                            placeholder="993"
                        >

                        <label for="imap_encryption">Chiffrement IMAP</label>
                        <select id="imap_encryption" name="imap_encryption">
                            <option value="ssl" <?= $imapEncryption === 'ssl' ? 'selected' : '' ?>>
                                SSL
                            </option>
                            <option value="tls" <?= $imapEncryption === 'tls' ? 'selected' : '' ?>>
                                TLS / STARTTLS
                            </option>
                            <option value="none" <?= $imapEncryption === 'none' ? 'selected' : '' ?>>
                                Aucun
                            </option>
                        </select>

                        <label for="imap_username">Identifiant IMAP</label>
                        <input
                            type="text"
                            id="imap_username"
                            name="imap_username"
                            value="<?= e($imapUsername) ?>"
                            autocomplete="username"
                        >

                        <label for="imap_password">Mot de passe IMAP</label>
                        <input
                            type="password"
                            id="imap_password"
                            name="imap_password"
                            value=""
                            autocomplete="new-password"
                            placeholder="<?= $imapPasswordConfigured ? 'Mot de passe enregistré' : '' ?>"
                        >
                        <p class="muted">Laissez vide pour conserver le mot de passe IMAP actuel.</p>
                        <?php if ($imapPasswordConfigured): ?>
                            <label class="checkbox-label">
                                <input type="checkbox" name="clear_imap_password" value="1">
                                Effacer le mot de passe IMAP enregistré
                            </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button
                        type="submit"
                        class="button-secondary"
                        name="action"
                        value="send_test_mail"
                        formnovalidate
                    >
                        Envoyer un email de test
                    </button>
                </div>

                <hr>

                <h2>Page À propos</h2>

                <div class="markdown-toolbar" aria-label="Barre d’outils Markdown" data-md-target="about_content">
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

                <label for="about_content">Contenu</label>
                <textarea
                    id="about_content"
                    name="about_content"
                    class="article-editor"
                    rows="12"
                ><?= e($aboutContent) ?></textarea>

                <p class="muted">
                    Markdown et LaTeX acceptés :
                    <code>## Titre</code>,
                    <code>**gras**</code>,
                    <code>[lien](https://exemple.fr)</code>,
                    <code>$ formule $</code>,
                    <code>$$ formule $$</code>.
                </p>

                <hr>

                <h2>Pied de page</h2>

                <div class="markdown-toolbar" aria-label="Barre d’outils Markdown" data-md-target="footer_content">
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

                <label for="footer_content">Contenu</label>
                <textarea
                    id="footer_content"
                    name="footer_content"
                    class="article-editor"
                    rows="5"
                ><?= e($footerContent) ?></textarea>

                <p class="muted">
                    Markdown et LaTeX acceptés dans le pied de page.
                </p>

                <hr>

                <h2>Charte d’utilisation</h2>
                <p class="muted">
                    Cette charte est affichée à la première connexion des membres. Refuser la charte désactive le compte.
                </p>

                <div class="markdown-toolbar" aria-label="Barre d’outils Markdown" data-md-target="usage_charter_content">
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

                <label for="usage_charter_content">Contenu</label>
                <textarea
                    id="usage_charter_content"
                    name="usage_charter_content"
                    class="article-editor"
                    rows="10"
                ><?= e($usageCharterContent) ?></textarea>

                <p class="muted">
                    Markdown et LaTeX acceptés. La variable <code>{site_name}</code> est remplacée dans le contenu par défaut.
                </p>

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        Enregistrer
                    </button>
                </div>
            </form>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>

    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/../public/assets/app.js')) ?>"></script>
</body>
</html>
