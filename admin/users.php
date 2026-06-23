<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_setup.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/protection.php';
require_once __DIR__ . '/../includes/storage_quota.php';

require_admin();
protection_ensure_schema();
storage_quota_ensure_schema();

$currentUser = current_user();
$errors = [];
$founderUserId = (int)(db_fetch_one("SELECT MIN(id) AS id FROM users")['id'] ?? 0);
$roleOptions = user_role_options();

function admin_teacher_unique_username(string $email, string $firstName, string $lastName, ?int $existingUserId = null): string
{
    $base = normalize_username((string)strstr($email, '@', true));

    if ($base === '') {
        $base = normalize_username($firstName . '.' . $lastName);
    }

    if ($base === '') {
        $base = 'enseignant';
    }

    $username = mb_substr($base, 0, 40, 'UTF-8');
    $suffix = 1;

    while (true) {
        $existing = db_fetch_one(
            "SELECT id FROM users WHERE username = :username LIMIT 1",
            ['username' => $username]
        );

        if (!$existing || ($existingUserId !== null && (int)$existing['id'] === $existingUserId)) {
            return $username;
        }

        $suffix++;
        $username = mb_substr($base, 0, 34, 'UTF-8') . '-' . $suffix;
    }
}

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $userId = (int)post_value('user_id');

    if (!in_array($action, ['import_csv', 'create_user'], true) && $userId <= 0) {
        $errors[] = 'Utilisateur invalide.';
    }

    if (
        !$errors
        && $userId === (int)$currentUser['id']
        && in_array($action, ['disable', 'delete', 'make_user'], true)
    ) {
        $errors[] = 'Vous ne pouvez pas désactiver, supprimer ou retirer vos propres droits administrateur.';
    }

    if (
        !$errors
        && $userId === $founderUserId
        && in_array($action, ['disable', 'delete', 'make_user'], true)
    ) {
        $errors[] = 'Le premier compte administrateur du site ne peut pas être désactivé, supprimé ou rétrogradé.';
    }

    if (!$errors) {
        try {
            if ($action === 'import_csv') {
                $csvFile = $_FILES['teacher_csv'] ?? null;

                if (!is_array($csvFile) || ($csvFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Veuillez choisir un fichier CSV.';
                } elseif (($csvFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $errors[] = 'Erreur pendant l’envoi du fichier CSV.';
                } else {
                    $tmpPath = (string)($csvFile['tmp_name'] ?? '');

                    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
                        $errors[] = 'Fichier CSV invalide.';
                    } else {
                        $handle = fopen($tmpPath, 'r');

                        if ($handle === false) {
                            $errors[] = 'Impossible de lire le fichier CSV.';
                        } else {
                            $created = 0;
                            $updated = 0;
                            $line = 0;
                            $generatedCredentials = [];

                            while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
                                $line++;

                                if (count($row) === 1 && str_contains((string)$row[0], ',')) {
                                    $row = str_getcsv((string)$row[0], ',', '"', '\\');
                                }

                                $row = array_map(static fn ($value): string => trim((string)$value), $row);

                                if ($line === 1 && isset($row[0]) && mb_strtolower($row[0], 'UTF-8') === 'nom') {
                                    continue;
                                }

                                [$lastName, $firstName, $email, $discipline] = array_pad($row, 4, '');
                                $email = mb_strtolower($email, 'UTF-8');

                                if ($lastName === '' && $firstName === '' && $email === '') {
                                    continue;
                                }

                                if ($lastName === '' || $firstName === '' || !is_valid_email($email)) {
                                    $errors[] = 'Ligne ' . $line . ' invalide : nom, prénom et email sont obligatoires.';
                                    continue;
                                }

                                $displayName = $firstName . ' ' . $lastName;
                                $existing = db_fetch_one(
                                    "SELECT id, role FROM users WHERE email = :email LIMIT 1",
                                    ['email' => $email]
                                );
                                $password = generate_policy_password(16);

                                if ($existing) {
                                    $existingId = (int)$existing['id'];
                                    $username = admin_teacher_unique_username($email, $firstName, $lastName, $existingId);
                                    db_query(
                                        "UPDATE users
                                         SET username = :username,
                                             display_name = :display_name,
                                             discipline = :discipline,
                                             password_hash = :password_hash,
                                             admin_visible_password = NULL,
                                             is_active = 1,
                                             email_verified_at = COALESCE(email_verified_at, :email_verified_at),
                                             updated_at = :updated_at
                                         WHERE id = :id",
                                        [
                                            'username' => $username,
                                            'display_name' => $displayName,
                                            'discipline' => $discipline,
                                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                            'email_verified_at' => now(),
                                            'updated_at' => now(),
                                            'id' => $existingId,
                                        ]
                                    );
                                    $updated++;
                                    $generatedCredentials[] = [
                                        'status' => 'mis à jour',
                                        'username' => $username,
                                        'display_name' => $displayName,
                                        'email' => $email,
                                        'password' => $password,
                                    ];
                                } else {
                                    $username = admin_teacher_unique_username($email, $firstName, $lastName);
                                    db_insert(
                                        "INSERT INTO users
                                            (username, email, password_hash, display_name, discipline,
                                             admin_visible_password, role, is_active, email_verified_at, created_at)
                                         VALUES
                                            (:username, :email, :password_hash, :display_name, :discipline,
                                             NULL, 'user', 1, :email_verified_at, :created_at)",
                                        [
                                            'username' => $username,
                                            'email' => $email,
                                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                                            'display_name' => $displayName,
                                            'discipline' => $discipline,
                                            'email_verified_at' => now(),
                                            'created_at' => now(),
                                        ]
                                    );
                                    $created++;
                                    $generatedCredentials[] = [
                                        'status' => 'créé',
                                        'username' => $username,
                                        'display_name' => $displayName,
                                        'email' => $email,
                                        'password' => $password,
                                    ];
                                }
                            }

                            fclose($handle);

                            if (!$errors) {
                                $_SESSION['admin_import_credentials'] = $generatedCredentials;
                                set_flash('success', 'Import terminé : ' . $created . ' compte(s) créé(s), ' . $updated . ' compte(s) mis à jour.');
                            }
                        }
                    }
                }
            } elseif ($action === 'create_user') {
                $username = normalize_username(post_value('new_username'));
                $displayName = post_value('new_display_name');
                $email = mb_strtolower(post_value('new_email'), 'UTF-8');
                $discipline = post_value('new_discipline');
                $role = post_value('new_role', 'user');
                $isActive = isset($_POST['new_is_active']) ? 1 : 0;
                $password = (string)($_POST['new_user_password'] ?? '');

                if ($username === '' || mb_strlen($username, 'UTF-8') < 3) {
                    $errors[] = 'L’identifiant doit contenir au moins 3 caractères.';
                }

                if ($displayName === '') {
                    $errors[] = 'Le nom affiché est obligatoire.';
                }

                if (!is_valid_email($email)) {
                    $errors[] = 'Adresse email invalide.';
                }

                if (!array_key_exists($role, $roleOptions)) {
                    $errors[] = 'Rôle invalide.';
                }

                foreach (password_policy_errors($password) as $passwordError) {
                    $errors[] = $passwordError;
                }

                $existingUser = db_fetch_one(
                    "SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1",
                    [
                        'username' => $username,
                        'email' => $email,
                    ]
                );

                if ($existingUser) {
                    $errors[] = 'Cet identifiant ou cette adresse email est déjà utilisé.';
                }

                if (!$errors) {
                    db_insert(
                        "INSERT INTO users
                            (username, email, password_hash, display_name, discipline,
                             admin_visible_password, role, is_active, email_verified_at, created_at)
                         VALUES
                            (:username, :email, :password_hash, :display_name, :discipline,
                             NULL, :role, :is_active, :email_verified_at, :created_at)",
                        [
                            'username' => $username,
                            'email' => $email,
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'display_name' => $displayName,
                            'discipline' => $discipline,
                            'role' => $role,
                            'is_active' => $isActive,
                            'email_verified_at' => $isActive ? now() : null,
                            'created_at' => now(),
                        ]
                    );

                    set_flash('success', 'Compte ' . user_role_label($role) . ' créé.');
                }
            } elseif ($action === 'send_password_link') {
                $targetUser = db_fetch_one(
                    "SELECT id, username, email, display_name, is_active
                     FROM users
                     WHERE id = :id
                     LIMIT 1",
                    ['id' => $userId]
                );

                if (!$targetUser) {
                    $errors[] = 'Utilisateur introuvable.';
                } elseif ((int)$targetUser['is_active'] !== 1) {
                    $errors[] = 'Activez le compte avant d’envoyer un lien de connexion.';
                } elseif (!is_valid_email((string)$targetUser['email'])) {
                    $errors[] = 'L’adresse email de cet utilisateur est invalide.';
                } else {
                    $token = user_password_setup_token_create($userId);

                    if (!send_user_password_setup_email($targetUser, $token)) {
                        user_password_setup_revoke($userId);
                        $errors[] = 'Le courriel n’a pas pu être envoyé. Vérifiez la configuration email.';
                    } else {
                        set_flash('success', 'Lien de choix du mot de passe envoyé à ' . (string)$targetUser['email'] . '. Il est valable 24 heures.');
                    }
                }
            } elseif ($action === 'update') {
                $username = normalize_username(post_value('username'));
                $displayName = post_value('display_name');
                $email = mb_strtolower(post_value('email'), 'UTF-8');
                $discipline = post_value('discipline');
                $bio = post_value('bio');
                $role = post_value('role', 'user');
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $newPassword = (string)($_POST['new_password'] ?? '');
                $quotaStorageMb = post_value('quota_storage_mb');
                $quotaStorageBytes = null;

                if ($username === '' || mb_strlen($username, 'UTF-8') < 3) {
                    $errors[] = 'L’identifiant doit contenir au moins 3 caractères.';
                }

                if ($displayName === '') {
                    $errors[] = 'Le nom affiché est obligatoire.';
                }

                if (!is_valid_email($email)) {
                    $errors[] = 'Adresse email invalide.';
                }

                if (!array_key_exists($role, $roleOptions)) {
                    $errors[] = 'Rôle invalide.';
                }

                if ($userId === (int)$currentUser['id'] || $userId === $founderUserId) {
                    $role = 'admin';
                    $isActive = 1;
                }

                $existingUser = db_fetch_one(
                    "SELECT id FROM users
                     WHERE (username = :username OR email = :email)
                       AND id != :id
                     LIMIT 1",
                    [
                        'username' => $username,
                        'email' => $email,
                        'id' => $userId,
                    ]
                );

                if ($existingUser) {
                    $errors[] = 'Cet identifiant ou cette adresse email est déjà utilisé.';
                }

                if ($newPassword !== '') {
                    foreach (password_policy_errors($newPassword) as $passwordError) {
                        $errors[] = $passwordError;
                    }
                }

                try {
                    $quotaStorageBytes = storage_quota_parse_size_to_bytes($quotaStorageMb, 1024 * 1024);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }

                if (!$errors) {
                    $params = [
                        'username' => $username,
                        'display_name' => $displayName,
                        'email' => $email,
                        'discipline' => $discipline,
                        'bio' => $bio,
                        'role' => $role,
                        'is_active' => $isActive,
                        'quota_storage_bytes' => $quotaStorageBytes !== null && $quotaStorageBytes > 0 ? $quotaStorageBytes : null,
                        'updated_at' => now(),
                        'id' => $userId,
                    ];

                    $passwordSql = '';

                    if ($newPassword !== '') {
                        $passwordSql = ', password_hash = :password_hash';
                        $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        $passwordSql .= ', admin_visible_password = NULL';
                    }

                    db_query(
                        "UPDATE users
                         SET username = :username,
                             display_name = :display_name,
                             email = :email,
                             discipline = :discipline,
                             bio = :bio,
                             role = :role,
                             is_active = :is_active,
                             quota_storage_bytes = :quota_storage_bytes,
                             email_verified_at = CASE WHEN :is_active_verify = 1 THEN COALESCE(email_verified_at, :email_verified_at) ELSE email_verified_at END,
                             updated_at = :updated_at
                             $passwordSql
                         WHERE id = :id",
                        array_merge($params, [
                            'is_active_verify' => $isActive,
                            'email_verified_at' => now(),
                        ])
                    );

                    set_flash('success', 'Utilisateur mis à jour.');
                }
            } elseif ($action === 'delete_avatar') {
                $targetUser = db_fetch_one(
                    "SELECT id, avatar FROM users WHERE id = :id LIMIT 1",
                    ['id' => $userId]
                );

                if (!$targetUser) {
                    $errors[] = 'Utilisateur introuvable.';
                } else {
                    $avatarPath = trim((string)($targetUser['avatar'] ?? ''));

                    db_query(
                        "UPDATE users
                         SET avatar = NULL,
                             updated_at = :updated_at
                         WHERE id = :id",
                        [
                            'updated_at' => now(),
                            'id' => $userId,
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
                    redirect(admin_url('users.php?edit_id=' . $userId . '#edit-user'));
                }
            } elseif ($action === 'avatar') {
                $avatarFile = $_FILES['avatar'] ?? null;
                $targetUser = db_fetch_one(
                    "SELECT id FROM users WHERE id = :id LIMIT 1",
                    ['id' => $userId]
                );

                if (!$targetUser) {
                    $errors[] = 'Utilisateur introuvable.';
                } elseif (!is_array($avatarFile) || ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
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
                                        'id' => $userId,
                                    ]
                                );

                                set_flash('success', 'Photo de profil mise à jour.');
                            }
                        }
                    }
                }
            } elseif ($action === 'enable') {
                $targetUser = db_fetch_one(
                    "SELECT role, charter_accepted_at
                     FROM users
                     WHERE id = :id
                     LIMIT 1",
                    ['id' => $userId]
                );

                db_query(
                    "UPDATE users
                     SET is_active = 1,
                         email_verified_at = COALESCE(email_verified_at, :email_verified_at),
                         email_activation_token_hash = NULL,
                         email_activation_expires_at = NULL,
                         updated_at = :updated_at
                     WHERE id = :id",
                    [
                        'email_verified_at' => now(),
                        'updated_at' => now(),
                        'id' => $userId,
                    ]
                );

                if (
                    $targetUser
                    && ($targetUser['role'] ?? '') !== 'admin'
                    && trim((string)($targetUser['charter_accepted_at'] ?? '')) === ''
                ) {
                    set_flash('success', 'Utilisateur activé. Il sera redirigé vers la charte à sa prochaine connexion.');
                } else {
                    set_flash('success', 'Utilisateur activé.');
                }
            } elseif ($action === 'disable') {
                db_query(
                    "UPDATE users SET is_active = 0, updated_at = :updated_at WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $userId,
                    ]
                );

                set_flash('success', 'Utilisateur désactivé.');
            } elseif ($action === 'make_admin') {
                db_query(
                    "UPDATE users SET role = 'admin', updated_at = :updated_at WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $userId,
                    ]
                );

                set_flash('success', 'Utilisateur promu administrateur.');
            } elseif ($action === 'make_user') {
                db_query(
                    "UPDATE users SET role = 'user', updated_at = :updated_at WHERE id = :id",
                    [
                        'updated_at' => now(),
                        'id' => $userId,
                    ]
                );

                set_flash('success', 'Utilisateur repassé en rôle standard.');
            } elseif ($action === 'delete') {
                $deletedUserStorage = (int)(db_fetch_one(
                    "SELECT used_storage_bytes
                     FROM users
                     WHERE id = :id
                     LIMIT 1",
                    ['id' => $userId]
                )['used_storage_bytes'] ?? 0);

                db_query(
                    "DELETE FROM users WHERE id = :id",
                    ['id' => $userId]
                );

                if ($deletedUserStorage > 0) {
                    db_query(
                        "UPDATE storage_quota
                         SET used_bytes = CASE
                             WHEN used_bytes >= :used_bytes THEN used_bytes - :used_bytes
                             ELSE 0
                         END
                         WHERE id = 1",
                        ['used_bytes' => $deletedUserStorage]
                    );
                }

                set_flash('success', 'Utilisateur supprimé.');
            } else {
                $errors[] = 'Action inconnue.';
            }

            if (!$errors) {
                if ($action === 'update' || $action === 'avatar') {
                    redirect(admin_url('users.php?edit_id=' . $userId . '#edit-user'));
                }

                redirect(admin_url('users.php'));
            }
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$search = get_value('q');
$params = [];
$where = '';

if ($search !== '') {
    $where = "WHERE username LIKE :q OR email LIKE :q OR display_name LIKE :q OR discipline LIKE :q";
    $params['q'] = '%' . $search . '%';
}

$page = current_page();
$perPage = 10;
$offset = pagination_offset($page, $perPage);

$totalUsers = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM users
     $where",
    $params
)['total'] ?? 0);

$totalPages = total_pages($totalUsers, $perPage);

$users = db_fetch_all(
    "SELECT id, username, email, display_name, discipline, admin_visible_password, avatar, role, is_active, email_verified_at, last_seen_at, created_at, used_storage_bytes, quota_storage_bytes
     FROM users
     $where
     ORDER BY created_at DESC
     LIMIT :limit OFFSET :offset",
    array_merge($params, [
        'limit' => $perPage,
        'offset' => $offset,
    ])
);

$editUserId = (int)get_value('edit_id', '0');

if ($editUserId <= 0 && is_post() && in_array(post_value('action'), ['update', 'avatar'], true)) {
    $editUserId = (int)post_value('user_id');
}
$editUser = null;

if ($editUserId > 0) {
    $editUser = db_fetch_one(
        "SELECT id, username, email, display_name, discipline, admin_visible_password, bio, avatar, role, is_active, email_verified_at, created_at, used_storage_bytes, quota_storage_bytes
         FROM users
         WHERE id = :id
         LIMIT 1",
        ['id' => $editUserId]
    );
}

$flashes = get_flashes();
$importCredentials = $_SESSION['admin_import_credentials'] ?? [];
unset($_SESSION['admin_import_credentials']);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Utilisateurs — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

        <?php if (is_array($importCredentials) && $importCredentials): ?>
            <section class="card">
                <h2>Mots de passe générés</h2>
                <p class="muted">
                    Ces mots de passe sont affichés une seule fois et ne sont pas conservés en clair dans la base.
                </p>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Statut</th>
                                <th>Nom</th>
                                <th>Identifiant</th>
                                <th>Email</th>
                                <th>Mot de passe</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($importCredentials as $credential): ?>
                                <?php if (!is_array($credential)) { continue; } ?>
                                <tr>
                                    <td><?= e((string)($credential['status'] ?? '')) ?></td>
                                    <td><?= e((string)($credential['display_name'] ?? '')) ?></td>
                                    <td><code><?= e((string)($credential['username'] ?? '')) ?></code></td>
                                    <td><?= e((string)($credential['email'] ?? '')) ?></td>
                                    <td><code><?= e((string)($credential['password'] ?? '')) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="card">
            <h1>Utilisateurs</h1>
            <p class="muted">
                Gestion des comptes enseignants, des disciplines, des rôles et des accès.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
                <a class="button-secondary" href="<?= e(url('dashboard.php')) ?>">Retour au site</a>
            </div>
        </section>

        <section class="card">
            <h2>Importer des enseignants par CSV</h2>
            <p class="muted">
                Format attendu : <code>nom;prenom;email;discipline</code>. Les comptes sont activés directement et un mot de passe conforme est généré pour chaque ligne.
            </p>
            <form method="post" action="" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="import_csv">

                <label for="teacher_csv">Fichier CSV</label>
                <input
                    type="file"
                    id="teacher_csv"
                    name="teacher_csv"
                    accept=".csv,text/csv,text/plain"
                    required
                >

                <div class="form-actions">
                    <button type="submit" class="button-primary">Importer les comptes</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Créer un compte individuellement</h2>
            <p class="muted">
                Créez un membre, un compte syndicats ou un administrateur sans passer par l’import CSV. Les mots de passe administrateurs ne sont pas affichés dans la liste.
            </p>
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="form-grid">
                    <div>
                        <label for="new_username">Identifiant</label>
                        <input id="new_username" name="new_username" minlength="3" value="<?= e(post_value('new_username')) ?>" required>
                    </div>
                    <div>
                        <label for="new_display_name">Nom affiché</label>
                        <input id="new_display_name" name="new_display_name" value="<?= e(post_value('new_display_name')) ?>" required>
                    </div>
                    <div>
                        <label for="new_email">Courriel</label>
                        <input id="new_email" name="new_email" type="email" value="<?= e(post_value('new_email')) ?>" required>
                    </div>
                    <div>
                        <label for="new_discipline">Discipline</label>
                        <input id="new_discipline" name="new_discipline" value="<?= e(post_value('new_discipline')) ?>">
                    </div>
                    <div>
                        <label for="new_role">Rôle</label>
                        <select id="new_role" name="new_role">
                            <?php foreach ($roleOptions as $roleKey => $roleLabel): ?>
                                <option value="<?= e($roleKey) ?>" <?= post_value('new_role', 'user') === $roleKey ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="new_user_password">Mot de passe initial</label>
                        <div class="password-generator-row">
                            <input
                                id="new_user_password"
                                name="new_user_password"
                                type="text"
                                minlength="16"
                                autocomplete="new-password"
                                required
                            >
                            <button class="button-secondary" type="button" data-generate-password="new_user_password">
                                Générer
                            </button>
                        </div>
                    </div>
                </div>

                <label class="checkbox-label">
                    <input type="checkbox" name="new_is_active" value="1" checked>
                    Compte actif
                </label>
                <p class="meta">Règle : 16 caractères minimum, avec lettres, chiffres et caractères spéciaux.</p>

                <div class="form-actions">
                    <button type="submit" class="button-primary">Créer le compte</button>
                </div>
            </form>
        </section>

        <section class="card">
            <form method="get" action="">
                <label for="q">Rechercher</label>
                <input
                    type="search"
                    id="q"
                    name="q"
                    value="<?= e($search) ?>"
                    placeholder="Nom, email, identifiant..."
                >

                <div class="form-actions">
                    <button type="submit" class="button-primary">Rechercher</button>
                    <a class="button-secondary" href="<?= e(admin_url('users.php')) ?>">Réinitialiser</a>
                </div>
            </form>
        </section>

        <?php if ($editUser): ?>
            <section class="card" id="edit-user">
                <h2>Modifier <?= e($editUser['display_name'] ?: $editUser['username']) ?></h2>

                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="user_id" value="<?= e((string)$editUser['id']) ?>">

                    <div class="admin-user-edit-grid">
                        <div>
                            <label for="username">Identifiant</label>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                value="<?= e($editUser['username']) ?>"
                                required
                                minlength="3"
                                autocomplete="username"
                            >

                            <label for="display_name">Nom affiché</label>
                            <input
                                type="text"
                                id="display_name"
                                name="display_name"
                                value="<?= e($editUser['display_name'] ?: $editUser['username']) ?>"
                                required
                            >

                            <label for="email">Adresse email</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value="<?= e($editUser['email']) ?>"
                                required
                            >

                            <label for="discipline">Discipline</label>
                            <input
                                type="text"
                                id="discipline"
                                name="discipline"
                                value="<?= e($editUser['discipline'] ?? '') ?>"
                            >

                            <label for="bio">Bio</label>
                            <textarea id="bio" name="bio"><?= e($editUser['bio'] ?? '') ?></textarea>
                        </div>

                        <div>
                            <label for="role">Rôle</label>
                            <select id="role" name="role" <?= (int)$editUser['id'] === (int)$currentUser['id'] || (int)$editUser['id'] === $founderUserId ? 'disabled' : '' ?>>
                                <?php foreach ($roleOptions as $roleKey => $roleLabel): ?>
                                    <option value="<?= e($roleKey) ?>" <?= $editUser['role'] === $roleKey ? 'selected' : '' ?>><?= e($roleLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ((int)$editUser['id'] === (int)$currentUser['id'] || (int)$editUser['id'] === $founderUserId): ?>
                                <input type="hidden" name="role" value="admin">
                            <?php endif; ?>

                            <label class="checkbox-label">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    <?= (int)$editUser['is_active'] === 1 ? 'checked' : '' ?>
                                    <?= (int)$editUser['id'] === (int)$currentUser['id'] || (int)$editUser['id'] === $founderUserId ? 'disabled' : '' ?>
                                >
                                Compte actif
                            </label>
                            <?php if ((int)$editUser['id'] === (int)$currentUser['id'] || (int)$editUser['id'] === $founderUserId): ?>
                                <input type="hidden" name="is_active" value="1">
                            <?php endif; ?>
                            <?php if ((int)$editUser['id'] === $founderUserId): ?>
                                <p class="muted">Compte fondateur protégé.</p>
                            <?php endif; ?>
                            <p class="muted">
                                Courriel :
                                <?= !empty($editUser['email_verified_at']) ? 'validé le ' . e((string)$editUser['email_verified_at']) : 'non validé' ?>
                            </p>

                            <label for="quota_storage_mb">Quota personnel des documents</label>
                            <input
                                type="number"
                                id="quota_storage_mb"
                                name="quota_storage_mb"
                                value="<?= e(storage_quota_bytes_to_unit($editUser['quota_storage_bytes'] !== null ? (int)$editUser['quota_storage_bytes'] : null, 1024 * 1024)) ?>"
                                min="0"
                                step="1"
                                placeholder="Quota par défaut"
                            >
                            <p class="meta">
                                Valeur en Mo. Utilisé : <?= e(human_file_size((int)$editUser['used_storage_bytes'])) ?>.
                            </p>

                            <label for="new_password">Nouveau mot de passe</label>
                            <input
                                type="text"
                                id="new_password"
                                name="new_password"
                                minlength="16"
                                autocomplete="new-password"
                                placeholder="Laisser vide pour ne pas changer"
                            >
                            <p class="meta">
                                Règle : 16 caractères minimum, avec lettres, chiffres et caractères spéciaux.
                            </p>
                            <p class="meta">
                                Les mots de passe ne sont plus conservés en clair.
                            </p>
                            <button class="button-secondary" type="button" data-generate-password="new_password">
                                Générer un mot de passe
                            </button>

                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button-primary">Enregistrer</button>
                        <a class="button-secondary" href="<?= e(admin_url('users.php')) ?>">Annuler</a>
                    </div>
                </form>

                <hr>

                <h3>Avatar</h3>
                <div class="profile-avatar-block">
                    <span class="post-avatar profile-avatar">
                        <?php if (!empty($editUser['avatar'])): ?>
                            <img src="<?= e(avatar_url((int)$editUser['id'], (string)$editUser['avatar'])) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_strtoupper(mb_substr($editUser['display_name'] ?: $editUser['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                        <?php endif; ?>
                    </span>

                    <form method="post" action="" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="avatar">
                        <input type="hidden" name="user_id" value="<?= e((string)$editUser['id']) ?>">

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

                            <?php if (!empty($editUser['avatar'])): ?>
                                <button
                                    type="submit"
                                    class="button-danger"
                                    form="delete-user-avatar-form"
                                    onclick="return confirm('Supprimer la photo de profil de cet utilisateur ?');"
                                >
                                    Supprimer la photo
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>

                    <?php if (!empty($editUser['avatar'])): ?>
                        <form id="delete-user-avatar-form" method="post" action="">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_avatar">
                            <input type="hidden" name="user_id" value="<?= e((string)$editUser['id']) ?>">
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($editUserId > 0): ?>
            <div class="flash flash-warning">Utilisateur introuvable.</div>
        <?php endif; ?>

        <section class="card">
            <h2>Liste des utilisateurs</h2>

            <?php if (!$users): ?>
                <p class="muted">Aucun utilisateur trouvé.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Discipline</th>
                                <th>Rôle</th>
                                <th>État</th>
                                <th>Stockage</th>
                                <th>Dernière activité</th>
                                <th>Création</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <span class="user-cell">
                                            <span class="post-avatar">
                                                <?php if (!empty($user['avatar'])): ?>
                                                    <img src="<?= e(avatar_url((int)$user['id'], (string)$user['avatar'])) ?>" alt="">
                                                <?php else: ?>
                                                    <?= e(mb_strtoupper(mb_substr($user['display_name'] ?: $user['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                                <?php endif; ?>
                                            </span>
                                            <span>
                                                <strong><?= e($user['display_name'] ?: $user['username']) ?></strong><br>
                                                <span class="meta">
                                                    @<?= e($user['username']) ?> · <?= e($user['email']) ?>
                                                    <?= (int)$user['id'] === $founderUserId ? ' · fondateur' : '' ?>
                                                </span>
                                            </span>
                                        </span>
                                    </td>
                                    <td><?= e($user['discipline'] ?: 'Non renseignée') ?></td>
                                    <td>
                                        <span class="badge <?= $user['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                                            <?= e(user_role_label($user['role'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((int)$user['is_active'] === 1): ?>
                                            <span class="badge badge-active">Actif</span>
                                        <?php else: ?>
                                            <span class="badge badge-disabled">Désactivé</span>
                                        <?php endif; ?>
                                        <?php if (empty($user['email_verified_at'])): ?>
                                            <br><span class="meta">email non validé</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= e(human_file_size((int)$user['used_storage_bytes'])) ?>
                                        <?php $userQuota = storage_quota_effective_user_quota($user['quota_storage_bytes'] !== null ? (int)$user['quota_storage_bytes'] : null); ?>
                                        <br><span class="meta">
                                            / <?= e($userQuota !== null ? human_file_size($userQuota) : 'illimité') ?>
                                        </span>
                                    </td>
                                    <td><?= e($user['last_seen_at'] ?: 'Jamais') ?></td>
                                    <td><?= e($user['created_at']) ?></td>
                                    <td>
                                        <div class="admin-actions">
                                            <a class="button-secondary" href="<?= e(admin_url('users.php?edit_id=' . (int)$user['id'] . '#edit-user')) ?>">
                                                Modifier
                                            </a>

                                            <form method="post" action="" onsubmit="return confirm('Envoyer un lien de choix du mot de passe valable 24 heures à cet utilisateur ?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                                <input type="hidden" name="action" value="send_password_link">
                                                <button type="submit" class="button-secondary">Lien</button>
                                            </form>

                                            <?php if ((int)$user['id'] === $founderUserId): ?>
                                                <span class="badge badge-active">Protégé</span>
                                            <?php elseif ((int)$user['is_active'] === 1): ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                                    <input type="hidden" name="action" value="disable">
                                                    <button type="submit" class="button-secondary">Désactiver</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                                    <input type="hidden" name="action" value="enable">
                                                    <button type="submit" class="button-secondary">Activer</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ((int)$user['id'] === $founderUserId): ?>
                                                <span class="meta">Rôle admin verrouillé</span>
                                            <?php elseif ($user['role'] === 'admin'): ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                                    <input type="hidden" name="action" value="make_user">
                                                    <button type="submit" class="button-secondary">Rôle user</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                                    <input type="hidden" name="action" value="make_admin">
                                                    <button type="submit" class="button-secondary">Rôle admin</button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ((int)$user['id'] !== $founderUserId): ?>
                                                <form method="post" action="" onsubmit="return confirm('Supprimer définitivement cet utilisateur ?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="button-danger">Supprimer</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php if ($totalPages > 1): ?>
                <section class="card">
                    <?= render_pagination($page, $totalPages) ?>
                </section>
            <?php endif; ?>
                
            
            <?php endif; ?>
        </section>
    </main>

    <script>
        document.querySelectorAll('[data-generate-password]').forEach((button) => {
            const input = document.getElementById(button.dataset.generatePassword || '');

            if (!input) {
                return;
            }

            const randomIndex = (max) => {
                const values = new Uint32Array(1);
                window.crypto.getRandomValues(values);

                return values[0] % max;
            };
            const pick = (characters) => characters[randomIndex(characters.length)];
            const shuffle = (characters) => {
                const output = characters.slice();

                for (let index = output.length - 1; index > 0; index--) {
                    const swapIndex = randomIndex(index + 1);
                    [output[index], output[swapIndex]] = [output[swapIndex], output[index]];
                }

                return output.join('');
            };

            button.addEventListener('click', () => {
                const lowercase = 'abcdefghijkmnopqrstuvwxyz';
                const uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
                const digits = '23456789';
                const symbols = '!@#$%&*+-?';
                const allCharacters = lowercase + uppercase + digits + symbols;
                const password = [
                    pick(lowercase),
                    pick(uppercase),
                    pick(digits),
                    pick(symbols),
                ];

                while (password.length < 16) {
                    password.push(pick(allCharacters));
                }

                input.value = shuffle(password);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
                input.select();
            });
        });
    </script>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
