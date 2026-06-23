<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';

require_login();

$user = current_user();
$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action', 'profile');

    if ($action === 'avatar') {
        $avatarFile = $_FILES['avatar'] ?? null;

        if (!is_array($avatarFile) || ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Veuillez choisir une photo.';
        } elseif (($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Erreur pendant l’envoi de la photo.';
        } else {
            $tmpPath = (string)($avatarFile['tmp_name'] ?? '');
            $size = (int)($avatarFile['size'] ?? 0);

            if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                $errors[] = 'Fichier temporaire invalide.';
            } elseif ($size <= 0 || $size > MAX_UPLOAD_SIZE) {
                $errors[] = 'La photo est vide ou trop volumineuse.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($tmpPath) ?: 'application/octet-stream';

                if (!in_array($mimeType, ALLOWED_AVATAR_MIME_TYPES, true)) {
                    $errors[] = 'Format de photo non autorisé.';
                } else {
                    $extension = match ($mimeType) {
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/gif' => 'gif',
                        'image/webp' => 'webp',
                        default => 'bin',
                    };

                    ensure_dir(upload_subdir_path('avatars'));

                    $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
                    $destinationPath = upload_subdir_path('avatars') . DIRECTORY_SEPARATOR . $safeName;

                    if (!move_uploaded_file($tmpPath, $destinationPath)) {
                        $errors[] = 'Impossible d’enregistrer la photo.';
                    } else {
                        db_query(
                            "UPDATE users
                             SET avatar = :avatar,
                                 updated_at = :updated_at
                             WHERE id = :id",
                            [
                                'avatar' => upload_logical_path('avatars', $safeName),
                                'updated_at' => now(),
                                'id' => $user['id'],
                            ]
                        );

                        set_flash('success', 'Photo de profil mise à jour.');
                        redirect(url('profile.php'));
                    }
                }
            }
        }
    }

    if ($action === 'delete_avatar') {
        $avatarPath = trim((string)($user['avatar'] ?? ''));

        db_query(
            "UPDATE users
             SET avatar = NULL,
                 updated_at = :updated_at
             WHERE id = :id",
            [
                'updated_at' => now(),
                'id' => $user['id'],
            ]
        );

        if ($avatarPath !== '') {
            $avatarRoot = realpath(upload_subdir_path('avatars'));
            $filePath = upload_realpath_from_logical($avatarPath);

            if (
                $avatarRoot !== false
                && $filePath !== false
                && str_starts_with($filePath, $avatarRoot . DIRECTORY_SEPARATOR)
                && is_file($filePath)
            ) {
                @unlink($filePath);
            }
        }

        set_flash('success', 'Photo de profil supprimée.');
        redirect(url('profile.php'));
    }

    if ($action === 'profile') {
    $displayName = post_value('display_name');
    $bio = post_value('bio');
    $email = mb_strtolower(post_value('email'), 'UTF-8');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string)($_POST['new_password_confirm'] ?? '');

    if ($displayName === '') {
        $errors[] = 'Le nom affiché est obligatoire.';
    }

    if (!is_valid_email($email)) {
        $errors[] = 'Adresse email invalide.';
    }

    $existingEmail = db_fetch_one(
        "SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1",
        [
            'email' => $email,
            'id' => $user['id'],
        ]
    );

    if ($existingEmail) {
        $errors[] = 'Cette adresse email est déjà utilisée par un autre compte.';
    }

    $passwordMustChange = $newPassword !== '' || $newPasswordConfirm !== '' || $currentPassword !== '';

    if ($passwordMustChange) {
        $userWithPassword = db_fetch_one(
            "SELECT password_hash FROM users WHERE id = :id LIMIT 1",
            ['id' => $user['id']]
        );

        if (!$userWithPassword || !password_verify($currentPassword, $userWithPassword['password_hash'])) {
            $errors[] = 'Le mot de passe actuel est incorrect.';
        }

        foreach (password_policy_errors($newPassword) as $passwordError) {
            $errors[] = $passwordError;
        }

        if ($newPassword !== $newPasswordConfirm) {
            $errors[] = 'Les deux nouveaux mots de passe ne correspondent pas.';
        }
    }

    if (!$errors) {
        try {
            if ($passwordMustChange) {
                db_query(
                    "UPDATE users
                     SET display_name = :display_name,
                         bio = :bio,
                         email = :email,
                         password_hash = :password_hash,
                         admin_visible_password = NULL,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'display_name' => $displayName,
                        'bio' => $bio,
                        'email' => $email,
                        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                        'updated_at' => now(),
                        'id' => $user['id'],
                    ]
                );
            } else {
                db_query(
                    "UPDATE users
                     SET display_name = :display_name,
                         bio = :bio,
                         email = :email,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'display_name' => $displayName,
                        'bio' => $bio,
                        'email' => $email,
                        'updated_at' => now(),
                        'id' => $user['id'],
                    ]
                );
            }

            set_flash('success', 'Profil mis à jour.');
            redirect(url('profile.php'));
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
    }
}

$user = current_user();
$flashes = get_flashes();

$userStats = [
    'posts' => 0,
    'articles' => 0,
    'messages' => 0,
    'groups' => 0,
];

try {
    $userStats['posts'] = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total FROM posts WHERE author_id = :id",
        ['id' => $user['id']]
    )['total'] ?? 0);

    $userStats['articles'] = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total FROM articles WHERE author_id = :id",
        ['id' => $user['id']]
    )['total'] ?? 0);

    $userStats['messages'] = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total FROM messages WHERE sender_id = :id AND is_deleted = 0",
        ['id' => $user['id']]
    )['total'] ?? 0);

    $userStats['groups'] = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total FROM group_members WHERE user_id = :id",
        ['id' => $user['id']]
    )['total'] ?? 0);
} catch (Throwable $e) {
    $errors[] = 'Erreur lors du chargement des statistiques : ' . $e->getMessage();
}

$icalFeedUrl = ical_enabled() ? ical_feed_url() : '';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Mon profil — <?= e(APP_NAME) ?></title>
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
            <h1>Mon profil</h1>
            <p class="muted">
                Gérez votre identité, votre bio et votre mot de passe.
            </p>
        </section>

        <section class="grid grid-2">
            <article class="card">
                <h2>Informations</h2>

                <form method="post" action="">
                    <?= csrf_field() ?>

                    <label for="display_name">Nom affiché</label>
                    <input
                        type="text"
                        id="display_name"
                        name="display_name"
                        value="<?= e($user['display_name'] ?: $user['username']) ?>"
                        required
                    >

                    <label for="email">Adresse email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e($user['email']) ?>"
                        required
                    >

                    <label for="bio">Bio</label>
                    <textarea
                        id="bio"
                        name="bio"
                        placeholder="Quelques mots sur vous..."
                    ><?= e($user['bio'] ?? '') ?></textarea>

                    <hr>

                    <h3>Changer le mot de passe</h3>
                    <p class="muted">
                        Laissez ces champs vides si vous ne voulez pas modifier le mot de passe. Nouveau mot de passe : 16 caractères minimum, avec lettres, chiffres et caractères spéciaux.
                    </p>

                    <label for="current_password">Mot de passe actuel</label>
                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        autocomplete="current-password"
                    >

                    <label for="new_password">Nouveau mot de passe</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        minlength="16"
                        autocomplete="new-password"
                    >

                    <label for="new_password_confirm">Confirmation du nouveau mot de passe</label>
                    <input
                        type="password"
                        id="new_password_confirm"
                        name="new_password_confirm"
                        minlength="16"
                        autocomplete="new-password"
                    >

                    <div class="form-actions">
                        <button type="submit" class="button-primary">
                            Enregistrer
                        </button>
                    </div>
                </form>
            </article>

            <aside class="card">
                <h2>Compte</h2>

                <div class="profile-avatar-block">
                    <span class="post-avatar profile-avatar">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="<?= e(avatar_url((int)$user['id'], (string)$user['avatar'])) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                        <?php endif; ?>
                    </span>

                    <form method="post" action="" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="avatar">

                        <label for="avatar">Photo de profil</label>
                        <input
                            type="file"
                            id="avatar"
                            name="avatar"
                            accept="image/jpeg,image/png,image/gif,image/webp"
                            required
                        >

                        <div class="form-actions profile-avatar-actions">
                            <button type="submit" class="button-secondary">Changer la photo</button>

                            <?php if (!empty($user['avatar'])): ?>
                                <button
                                    type="submit"
                                    class="button-danger"
                                    form="delete-avatar-form"
                                    onclick="return confirm('Supprimer votre photo de profil ?');"
                                >
                                    Supprimer la photo
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if (!empty($user['avatar'])): ?>
                        <form id="delete-avatar-form" method="post" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_avatar">
                        </form>
                    <?php endif; ?>
                </div>

                <p>
                    <strong>Identifiant :</strong><br>
                    @<?= e($user['username']) ?>
                </p>

                <p>
                    <strong>Rôle :</strong><br>
                    <span class="badge <?= $user['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                        <?= e(user_role_label($user['role'])) ?>
                    </span>
                </p>

                <p>
                    <strong>Créé le :</strong><br>
                    <?= e($user['created_at']) ?>
                </p>

                <p>
                    <strong>Dernière activité :</strong><br>
                    <?= e($user['last_seen_at'] ?: 'Jamais') ?>
                </p>

                <hr>

                <h2>Activité</h2>

                <div class="profile-stats">
                    <div>
                        <span class="stat"><?= e((string)$userStats['posts']) ?></span>
                        <span class="muted">publications</span>
                    </div>
                    <div>
                        <span class="stat"><?= e((string)$userStats['articles']) ?></span>
                        <span class="muted">articles</span>
                    </div>
                    <div>
                        <span class="stat"><?= e((string)$userStats['messages']) ?></span>
                        <span class="muted">messages</span>
                    </div>
                    <div>
                        <span class="stat"><?= e((string)$userStats['groups']) ?></span>
                        <span class="muted">groupes</span>
                    </div>
                </div>

                <?php if ($icalFeedUrl !== ''): ?>
                    <hr>

                    <label for="profile_ical_feed_url">URL d’abonnement iCal</label>
                    <input
                        type="url"
                        id="profile_ical_feed_url"
                        value="<?= e($icalFeedUrl) ?>"
                        readonly
                    >
                    <p class="muted">
                        À copier dans Apple Calendar, Outlook, Thunderbird ou Google Calendar comme calendrier par URL.
                    </p>
                <?php endif; ?>
            </aside>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
