<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mailer.php';

function auth_ensure_email_activation_columns(): void
{
    if (!db_column_exists('users', 'email_verified_at')) {
        db_query("ALTER TABLE users ADD COLUMN email_verified_at TEXT");
    }

    if (!db_column_exists('users', 'email_activation_token_hash')) {
        db_query("ALTER TABLE users ADD COLUMN email_activation_token_hash TEXT");
    }

    if (!db_column_exists('users', 'email_activation_expires_at')) {
        db_query("ALTER TABLE users ADD COLUMN email_activation_expires_at TEXT");
    }

    db_query(
        "UPDATE users
         SET email_verified_at = COALESCE(email_verified_at, created_at, :verified_at)
         WHERE is_active = 1
           AND email_verified_at IS NULL",
        ['verified_at' => now()]
    );
}

function auth_ensure_user_charter_columns(): void
{
    static $done = false;

    if ($done || !database_is_installed()) {
        return;
    }

    if (!db_column_exists('users', 'charter_accepted_at')) {
        db_query("ALTER TABLE users ADD COLUMN charter_accepted_at TEXT");
    }

    $done = true;
}

function auth_ensure_rate_limit_table(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS auth_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            action TEXT NOT NULL,
            identifier_hash TEXT NOT NULL,
            identifier_value TEXT,
            ip_hash TEXT NOT NULL,
            ip_address TEXT,
            password_fingerprint TEXT,
            success INTEGER NOT NULL DEFAULT 0,
            rate_limit_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );

    if (!db_column_exists('auth_attempts', 'identifier_value')) {
        db_query("ALTER TABLE auth_attempts ADD COLUMN identifier_value TEXT");
    }

    if (!db_column_exists('auth_attempts', 'ip_address')) {
        db_query("ALTER TABLE auth_attempts ADD COLUMN ip_address TEXT");
    }

    if (!db_column_exists('auth_attempts', 'password_fingerprint')) {
        db_query("ALTER TABLE auth_attempts ADD COLUMN password_fingerprint TEXT");
    }

    if (!db_column_exists('auth_attempts', 'rate_limit_active')) {
        db_query("ALTER TABLE auth_attempts ADD COLUMN rate_limit_active INTEGER NOT NULL DEFAULT 1");
    }

    db_query(
        "CREATE INDEX IF NOT EXISTS idx_auth_attempts_lookup
         ON auth_attempts(action, identifier_hash, ip_hash, success, created_at)"
    );

    db_query(
        "CREATE INDEX IF NOT EXISTS idx_auth_attempts_created_at
         ON auth_attempts(created_at)"
    );

    $done = true;
}

function auth_client_ip_address(): string
{
    if (function_exists('activity_log_ip_address')) {
        return activity_log_ip_address();
    }

    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function auth_rate_limit_identifier_hash(string $identifier): string
{
    $normalized = mb_strtolower(trim($identifier), 'UTF-8');

    return hash('sha256', $normalized);
}

function auth_rate_limit_ip_hash(): string
{
    return hash('sha256', auth_client_ip_address());
}

function auth_password_attempt_fingerprint(string $password): string
{
    if ($password === '') {
        return '';
    }

    return hash_hmac('sha256', $password, 'protection-auth-attempt|' . __DIR__ . '|' . SESSION_NAME);
}

function password_policy_errors(string $password): array
{
    $errors = [];

    if (mb_strlen($password, 'UTF-8') < 16) {
        $errors[] = 'Le mot de passe doit contenir au moins 16 caractères.';
    }

    if (!preg_match('/\p{L}/u', $password)) {
        $errors[] = 'Le mot de passe doit contenir au moins une lettre.';
    }

    if (!preg_match('/\d/u', $password)) {
        $errors[] = 'Le mot de passe doit contenir au moins un chiffre.';
    }

    if (!preg_match('/[\p{P}\p{S}]/u', $password)) {
        $errors[] = 'Le mot de passe doit contenir au moins un caractère spécial.';
    }

    return $errors;
}

function password_meets_policy(string $password): bool
{
    return password_policy_errors($password) === [];
}

function generate_policy_password(int $length = 16): string
{
    $length = max(16, $length);
    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $numbers = '23456789';
    $specials = '!@#$%&*?+-_=';
    $all = $letters . $numbers . $specials;
    $chars = [
        $letters[random_int(0, strlen($letters) - 1)],
        $numbers[random_int(0, strlen($numbers) - 1)],
        $specials[random_int(0, strlen($specials) - 1)],
    ];

    while (count($chars) < $length) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }

    for ($i = count($chars) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
    }

    return implode('', $chars);
}

function auth_rate_limit_config(string $action): array
{
    return match ($action) {
        'register' => [
            'max_attempts' => 6,
            'window_seconds' => 30 * 60,
        ],
        default => [
            'max_attempts' => 5,
            'window_seconds' => 15 * 60,
        ],
    };
}

function auth_rate_limit_retry_after(string $action, string $identifier): int
{
    auth_ensure_rate_limit_table();

    $config = auth_rate_limit_config($action);
    $windowStart = date('Y-m-d H:i:s', time() - (int)$config['window_seconds']);
    $identifierHash = auth_rate_limit_identifier_hash($identifier);
    $ipHash = auth_rate_limit_ip_hash();

    db_query(
        "DELETE FROM auth_attempts
         WHERE created_at < :created_at",
        ['created_at' => date('Y-m-d H:i:s', time() - 24 * 60 * 60)]
    );

    $row = db_fetch_one(
        "SELECT COUNT(*) AS total,
                MIN(created_at) AS first_attempt_at
         FROM auth_attempts
         WHERE action = :action
           AND success = 0
           AND rate_limit_active = 1
           AND created_at >= :window_start
           AND (
                identifier_hash = :identifier_hash
                OR ip_hash = :ip_hash
           )",
        [
            'action' => $action,
            'window_start' => $windowStart,
            'identifier_hash' => $identifierHash,
            'ip_hash' => $ipHash,
        ]
    );

    if ((int)($row['total'] ?? 0) < (int)$config['max_attempts']) {
        return 0;
    }

    $firstAttempt = strtotime((string)($row['first_attempt_at'] ?? ''));

    if ($firstAttempt === false) {
        return (int)$config['window_seconds'];
    }

    return max(1, ($firstAttempt + (int)$config['window_seconds']) - time());
}

function auth_rate_limit_record(string $action, string $identifier, bool $success, string $password = ''): void
{
    auth_ensure_rate_limit_table();

    $identifierHash = auth_rate_limit_identifier_hash($identifier);
    $ipHash = auth_rate_limit_ip_hash();
    $displayIdentifier = mb_substr(trim($identifier), 0, 254, 'UTF-8');
    $ipAddress = mb_substr(auth_client_ip_address(), 0, 45, 'UTF-8');
    $passwordFingerprint = auth_password_attempt_fingerprint($password);

    if ($success) {
        db_query(
            "UPDATE auth_attempts
             SET rate_limit_active = 0
             WHERE action = :action
               AND identifier_hash = :identifier_hash
               AND success = 0
               AND rate_limit_active = 1",
            [
                'action' => $action,
                'identifier_hash' => $identifierHash,
            ]
        );

        return;
    }

    db_query(
        "INSERT INTO auth_attempts
            (action, identifier_hash, identifier_value, ip_hash, ip_address, password_fingerprint, success, created_at)
         VALUES
            (:action, :identifier_hash, :identifier_value, :ip_hash, :ip_address, :password_fingerprint, 0, :created_at)",
        [
            'action' => $action,
            'identifier_hash' => $identifierHash,
            'identifier_value' => $displayIdentifier,
            'ip_hash' => $ipHash,
            'ip_address' => $ipAddress,
            'password_fingerprint' => $passwordFingerprint !== '' ? $passwordFingerprint : null,
            'created_at' => now(),
        ]
    );
}

function auth_rate_limit_message(int $retryAfter): string
{
    $minutes = max(1, (int)ceil($retryAfter / 60));

    return 'Trop de tentatives. Réessayez dans ' . $minutes . ' minute(s).';
}

/**
 * Démarre la session applicative.
 */
function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        csrf_start_session();
    }
}

/**
 * Retourne l'identifiant de l'utilisateur connecté.
 */
function current_user_id(): ?int
{
    start_app_session();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return (int) $_SESSION['user_id'];
}

/**
 * Retourne l'utilisateur connecté.
 */
function current_user(): ?array
{
    $userId = current_user_id();

    if ($userId === null) {
        return null;
    }

    if (
        isset($GLOBALS['auth_current_user_cache'])
        && is_array($GLOBALS['auth_current_user_cache'])
        && array_key_exists($userId, $GLOBALS['auth_current_user_cache'])
    ) {
        return $GLOBALS['auth_current_user_cache'][$userId];
    }

    $sql = "SELECT id, username, email, display_name, bio, avatar, role, is_active, charter_accepted_at, last_seen_at, created_at
            FROM users
            WHERE id = :id
            LIMIT 1";

    try {
        $user = db_fetch_one($sql, ['id' => $userId]);
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), 'charter_accepted_at')) {
            throw $e;
        }

        auth_ensure_user_charter_columns();

        $user = db_fetch_one($sql, ['id' => $userId]);
    }

    if (!isset($GLOBALS['auth_current_user_cache']) || !is_array($GLOBALS['auth_current_user_cache'])) {
        $GLOBALS['auth_current_user_cache'] = [];
    }

    $GLOBALS['auth_current_user_cache'][$userId] = $user;

    return $user;
}

/**
 * Vérifie si un utilisateur est connecté.
 */
function is_logged_in(): bool
{
    return current_user_id() !== null;
}

/**
 * Vérifie si l'utilisateur connecté est administrateur.
 */
function is_admin(): bool
{
    $user = current_user();

    return $user !== null && $user['role'] === 'admin';
}

function user_role_options(): array
{
    return [
        'user' => 'Membre',
        'syndicate' => 'Syndicats',
        'admin' => 'Administrateur',
    ];
}

function user_role_label(string $role): string
{
    return user_role_options()[$role] ?? $role;
}

function is_syndicate_user(): bool
{
    $user = current_user();

    return $user !== null && $user['role'] === 'syndicate';
}

function can_manage_syndicates(): bool
{
    $user = current_user();

    return $user !== null && in_array($user['role'], ['admin', 'syndicate'], true);
}

function user_must_accept_charter(?array $user = null): bool
{
    $user ??= current_user();

    if ($user === null || ($user['role'] ?? '') === 'admin') {
        return false;
    }

    return trim((string)($user['charter_accepted_at'] ?? '')) === '';
}

/**
 * Bloque l'accès si aucun utilisateur n'est connecté.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('warning', 'Vous devez vous connecter pour accéder à cette page.');
        redirect(url('login.php'));
    }

    $user = current_user();

    if (!$user || (int)$user['is_active'] !== 1) {
        logout_user();
        set_flash('error', 'Votre compte est désactivé.');
        redirect(url('login.php'));
    }

    $currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (user_must_accept_charter($user) && !in_array($currentScript, ['charter.php', 'logout.php'], true)) {
        redirect(url('charter.php'));
    }

    update_last_seen();
}

/**
 * Bloque l'accès si l'utilisateur n'est pas administrateur.
 */
function require_admin(): void
{
    require_login();

    if (!is_admin()) {
        http_response_code(403);
        exit('Accès réservé aux administrateurs.');
    }
}

/**
 * Connecte un utilisateur.
 */
function login_user(array $user): void
{
    start_app_session();

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    unset($GLOBALS['auth_current_user_cache']);

    csrf_regenerate();
    update_last_seen();
}

/**
 * Déconnecte l'utilisateur courant.
 */
function logout_user(): void
{
    start_app_session();

    $_SESSION = [];
    unset($GLOBALS['auth_current_user_cache']);

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

/**
 * Met à jour la dernière activité de l'utilisateur connecté.
 */
function update_last_seen(): void
{
    $userId = current_user_id();

    if ($userId === null) {
        return;
    }

    $now = time();
    $lastUpdateAt = (int)($_SESSION['last_seen_update_at'] ?? 0);

    if ($lastUpdateAt > 0 && $lastUpdateAt >= $now - 300) {
        return;
    }

    $lastSeenAt = date('Y-m-d H:i:s', $now);

    db_query(
        "UPDATE users SET last_seen_at = :last_seen_at WHERE id = :id",
        [
            'last_seen_at' => $lastSeenAt,
            'id' => $userId,
        ]
    );

    $_SESSION['last_seen_update_at'] = $now;

    if (
        isset($GLOBALS['auth_current_user_cache'][$userId])
        && is_array($GLOBALS['auth_current_user_cache'][$userId])
    ) {
        $GLOBALS['auth_current_user_cache'][$userId]['last_seen_at'] = $lastSeenAt;
    }
}

/**
 * Crée un nouvel utilisateur.
 */
function register_user(
    string $username,
    string $email,
    string $password,
    string $displayName = '',
    string $role = 'user',
    bool $isActive = true
): int {
    auth_ensure_email_activation_columns();

    $username = normalize_username($username);
    $email = mb_strtolower(trim($email), 'UTF-8');
    $displayName = trim($displayName);

    if (!array_key_exists($role, user_role_options())) {
        throw new InvalidArgumentException('Rôle invalide.');
    }

    if ($username === '' || mb_strlen($username, 'UTF-8') < 3) {
        throw new InvalidArgumentException('Le nom d’utilisateur doit contenir au moins 3 caractères.');
    }

    if (!is_valid_email($email)) {
        throw new InvalidArgumentException('Adresse email invalide.');
    }

    $passwordErrors = password_policy_errors($password);

    if ($passwordErrors) {
        throw new InvalidArgumentException(implode(' ', $passwordErrors));
    }

    $existing = db_fetch_one(
        "SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1",
        [
            'username' => $username,
            'email' => $email,
        ]
    );

    if ($existing) {
        throw new InvalidArgumentException('Ce nom d’utilisateur ou cette adresse email est déjà utilisé.');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $createdAt = now();

    return db_insert(
        "INSERT INTO users
            (username, email, password_hash, display_name, role, is_active, email_verified_at, created_at)
         VALUES
            (:username, :email, :password_hash, :display_name, :role, :is_active, :email_verified_at, :created_at)",
        [
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
            'display_name' => $displayName !== '' ? $displayName : $username,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
            'email_verified_at' => $isActive ? $createdAt : null,
            'created_at' => $createdAt,
        ]
    );
}

function user_activation_token_create(int $userId): string
{
    auth_ensure_email_activation_columns();

    $token = bin2hex(random_bytes(32));

    db_query(
        "UPDATE users
         SET email_activation_token_hash = :token_hash,
             email_activation_expires_at = :expires_at,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
            'updated_at' => now(),
            'id' => $userId,
        ]
    );

    return $token;
}

function user_activation_url(string $token): string
{
    return url('activate.php?token=' . rawurlencode($token));
}

function send_user_activation_email(array $user, string $token): bool
{
    $activationUrl = user_activation_url($token);
    $displayName = (string)($user['display_name'] ?: $user['username']);
    $subject = 'Validez votre inscription — ' . site_name();
    $text = "Bonjour " . $displayName . ",\n\n"
        . "Votre compte sur " . site_name() . " a été créé, mais il doit être validé avant la première connexion.\n\n"
        . "Cliquez sur ce lien pour activer votre compte :\n"
        . $activationUrl . "\n\n"
        . "Ce lien expire dans 48 heures.\n\n"
        . "Si vous n’êtes pas à l’origine de cette demande, ignorez cet email.";
    $html = '<p>Bonjour ' . e($displayName) . ',</p>'
        . '<p>Votre compte sur <strong>' . e(site_name()) . '</strong> a été créé, mais il doit être validé avant la première connexion.</p>'
        . '<p><a href="' . e($activationUrl) . '">Activer mon compte</a></p>'
        . '<p>Ce lien expire dans 48 heures.</p>'
        . '<p>Si vous n’êtes pas à l’origine de cette demande, ignorez cet email.</p>';

    return mail_send_text(
        (string)$user['email'],
        $displayName,
        $subject,
        $text,
        $html
    );
}

function activate_user_by_token(string $token): string
{
    auth_ensure_email_activation_columns();

    $token = trim($token);

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return 'invalid';
    }

    $user = db_fetch_one(
        "SELECT *
         FROM users
         WHERE email_activation_token_hash = :token_hash
         LIMIT 1",
        ['token_hash' => hash('sha256', $token)]
    );

    if (!$user) {
        return 'invalid';
    }

    if ((int)$user['is_active'] === 1 && !empty($user['email_verified_at'])) {
        return 'already_active';
    }

    if (
        empty($user['email_activation_expires_at'])
        || strtotime((string)$user['email_activation_expires_at']) < time()
    ) {
        return 'expired';
    }

    db_query(
        "UPDATE users
         SET is_active = 1,
             email_verified_at = :email_verified_at,
             email_activation_token_hash = NULL,
             email_activation_expires_at = NULL,
             updated_at = :updated_at
         WHERE id = :id",
        [
            'email_verified_at' => now(),
            'updated_at' => now(),
            'id' => (int)$user['id'],
        ]
    );

    return 'activated';
}

/**
 * Tente une authentification par email/nom d'utilisateur + mot de passe.
 */
function attempt_login(string $identifier, string $password): bool
{
    $identifier = trim($identifier);

    if ($identifier === '' || $password === '') {
        return false;
    }

    $user = db_fetch_one(
        "SELECT *
         FROM users
         WHERE email = :identifier OR username = :identifier
         LIMIT 1",
        ['identifier' => mb_strtolower($identifier, 'UTF-8')]
    );

    if (!$user) {
        return false;
    }

    if ((int)$user['is_active'] !== 1) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    login_user($user);

    return true;
}

/**
 * Retourne le nombre d'utilisateurs.
 */
function count_users(): int
{
    $row = db_fetch_one("SELECT COUNT(*) AS total FROM users");

    return (int)($row['total'] ?? 0);
}

/**
 * Détermine si le premier compte créé doit devenir administrateur.
 */
function should_create_first_user_as_admin(): bool
{
    return count_users() === 0;
}
