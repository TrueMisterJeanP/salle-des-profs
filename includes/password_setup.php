<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/settings.php';

function auth_ensure_password_setup_columns(): void
{
    static $done = false;

    if ($done || !database_is_installed()) {
        return;
    }

    if (!db_column_exists('users', 'password_setup_token_hash')) {
        db_query("ALTER TABLE users ADD COLUMN password_setup_token_hash TEXT");
    }

    if (!db_column_exists('users', 'password_setup_expires_at')) {
        db_query("ALTER TABLE users ADD COLUMN password_setup_expires_at TEXT");
    }

    $done = true;
}

function user_password_setup_token_create(int $userId): string
{
    auth_ensure_password_setup_columns();
    $token = bin2hex(random_bytes(32));

    db_query(
        "UPDATE users
         SET password_setup_token_hash = :token_hash,
             password_setup_expires_at = :expires_at,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+24 hours')),
            'updated_at' => now(),
            'id' => $userId,
        ]
    );

    return $token;
}

function user_password_setup_url(string $token): string
{
    return url('login.php?setup_token=' . rawurlencode($token));
}

function user_password_setup_by_token(string $token): ?array
{
    auth_ensure_password_setup_columns();
    $token = trim($token);

    if ($token === '' || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return null;
    }

    $user = db_fetch_one(
        "SELECT id, username, email, display_name, role, is_active, charter_accepted_at,
                password_setup_expires_at
         FROM users
         WHERE password_setup_token_hash = :token_hash
         LIMIT 1",
        ['token_hash' => hash('sha256', $token)]
    );

    if (
        !$user
        || (int)$user['is_active'] !== 1
        || empty($user['password_setup_expires_at'])
        || strtotime((string)$user['password_setup_expires_at']) < time()
    ) {
        return null;
    }

    return $user;
}

function user_password_setup_revoke(int $userId): void
{
    auth_ensure_password_setup_columns();

    db_query(
        "UPDATE users
         SET password_setup_token_hash = NULL,
             password_setup_expires_at = NULL,
             updated_at = :updated_at
         WHERE id = :id",
        ['updated_at' => now(), 'id' => $userId]
    );
}

function send_user_password_setup_email(array $user, string $token): bool
{
    $setupUrl = user_password_setup_url($token);
    $siteUrl = url();
    $displayName = trim((string)($user['display_name'] ?? ''));
    $username = (string)($user['username'] ?? '');
    $recipientName = $displayName !== '' ? $displayName : $username;
    $subject = 'Choisissez votre mot de passe — ' . site_name();
    $text = "Bonjour " . $recipientName . ",\n\n"
        . "Un lien de connexion a été créé pour votre compte sur " . site_name() . ".\n\n"
        . "Adresse du site : " . $siteUrl . "\n"
        . "Identifiant : " . $username . "\n\n"
        . "Choisissez votre mot de passe puis connectez-vous :\n"
        . $setupUrl . "\n\n"
        . "Ce lien est valable 24 heures et ne peut être utilisé qu’une seule fois.\n\n"
        . "Si vous n’attendiez pas ce message, ignorez-le.";
    $html = '<p>Bonjour ' . e($recipientName) . ',</p>'
        . '<p>Un lien de connexion a été créé pour votre compte sur <strong>' . e(site_name()) . '</strong>.</p>'
        . '<p>Adresse du site : <a href="' . e($siteUrl) . '">' . e($siteUrl) . '</a><br>'
        . 'Identifiant : <code>' . e($username) . '</code></p>'
        . '<p><a href="' . e($setupUrl) . '">Choisir mon mot de passe et me connecter</a></p>'
        . '<p>Ce lien est valable 24 heures et ne peut être utilisé qu’une seule fois.</p>'
        . '<p>Si vous n’attendiez pas ce message, ignorez-le.</p>';

    return mail_send_text(
        (string)($user['email'] ?? ''),
        $recipientName,
        $subject,
        $text,
        $html
    );
}

function complete_user_password_setup(string $token, string $password): ?array
{
    $user = user_password_setup_by_token($token);

    if (!$user || password_policy_errors($password)) {
        return null;
    }

    $statement = db_query(
        "UPDATE users
         SET password_hash = :password_hash,
             admin_visible_password = NULL,
             password_setup_token_hash = NULL,
             password_setup_expires_at = NULL,
             updated_at = :updated_at
         WHERE id = :id
           AND password_setup_token_hash = :token_hash
           AND password_setup_expires_at >= :current_time
           AND is_active = 1",
        [
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => now(),
            'id' => (int)$user['id'],
            'token_hash' => hash('sha256', trim($token)),
            'current_time' => now(),
        ]
    );

    if ($statement->rowCount() !== 1) {
        return null;
    }

    return db_fetch_one(
        "SELECT id, username, email, display_name, bio, avatar, role, is_active,
                charter_accepted_at, last_seen_at, created_at
         FROM users
         WHERE id = :id
         LIMIT 1",
        ['id' => (int)$user['id']]
    );
}
