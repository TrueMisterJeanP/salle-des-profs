<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Échappe une chaîne pour un affichage HTML sécurisé.
 */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Redirige vers une URL interne ou absolue.
 */
function redirect(string $url): never
{
    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n")) {
        $url = '/';
    }

    header('Location: ' . $url);
    exit;
}

/**
 * Génère une URL complète à partir d'un chemin relatif à public/.
 */
function url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $path = ltrim($path, '/');

    return $path === '' ? $base : $base . '/' . $path;
}

/**
 * Génère une URL d'avatar versionnée pour invalider le cache quand la photo change.
 */
function avatar_url(int $userId, ?string $avatarPath = null): string
{
    $params = ['user_id' => (string)$userId];
    $avatarPath = trim((string)$avatarPath);

    if ($avatarPath !== '') {
        $params['v'] = substr(hash('sha256', $avatarPath), 0, 12);
    }

    return url('avatar.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}

/**
 * Génère une URL vers la racine du projet, hors dossier public/.
 */
function root_url(string $path = ''): string
{
    $base = rtrim(BASE_URL, '/');
    $root = preg_replace('#/public$#', '', $base) ?? $base;
    $path = ltrim($path, '/');

    return $path === '' ? $root : $root . '/' . $path;
}

/**
 * Génère une URL vers l'administration.
 */
function admin_url(string $path = ''): string
{
    $path = ltrim($path, '/');

    return root_url($path === '' ? 'admin' : 'admin/' . $path);
}

/**
 * Retourne la date actuelle au format SQL.
 */
function now(): string
{
    return date('Y-m-d H:i:s');
}

/**
 * Nettoie un nom d'utilisateur.
 */
function normalize_username(string $username): string
{
    $username = trim($username);
    $username = mb_strtolower($username, 'UTF-8');

    return preg_replace('/[^a-z0-9_.-]/u', '', $username) ?? '';
}

/**
 * Vérifie si une adresse email semble valide.
 */
function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Crée un slug lisible à partir d'un titre.
 */
function slugify(string $text): string
{
    $text = trim($text);
    $text = mb_strtolower($text, 'UTF-8');

    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text !== '' ? $text : 'article';
}

/**
 * Coupe proprement un texte.
 */
function excerpt(string $text, int $length = 180): string
{
    $text = trim(strip_tags($text));

    if (mb_strlen($text, 'UTF-8') <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length, 'UTF-8') . '…';
}

/**
 * Retourne une classe CSS selon le type de message flash.
 */
function flash_class(string $type): string
{
    return match ($type) {
        'success' => 'flash flash-success',
        'error' => 'flash flash-error',
        'warning' => 'flash flash-warning',
        default => 'flash flash-info',
    };
}

/**
 * Définit un message flash en session.
 */
function set_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name(SESSION_NAME);
        session_start();
    }

    $_SESSION['flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * Récupère et supprime les messages flash.
 */
function get_flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name(SESSION_NAME);
        session_start();
    }

    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $flashes;
}

/**
 * Vérifie si une méthode HTTP est POST.
 */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * Retourne une valeur POST nettoyée.
 */
function post_value(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

/**
 * Retourne une valeur GET nettoyée.
 */
function get_value(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

/**
 * Réponse JSON standardisée.
 */
function json_response(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function content_visibility_options(bool $allowPublic = false): array
{
    $options = [
        'private' => 'Privé',
        'members' => 'Membres',
        'group' => 'Groupe',
    ];

    if ($allowPublic) {
        $options = ['public' => 'Public'] + $options;
    }

    return $options;
}

function ensure_content_group_column(string $table): void
{
    if (!function_exists('db_column_exists') || !db_column_exists($table, 'group_id')) {
        db_query("ALTER TABLE $table ADD COLUMN group_id INTEGER");
    }
}

function ensure_content_group_columns(array $tables): void
{
    foreach ($tables as $table) {
        ensure_content_group_column($table);
    }
}

function user_group_options(int $userId, bool $includeAll = false): array
{
    if ($includeAll) {
        return db_fetch_all(
            "SELECT id, name, visibility
             FROM groups
             ORDER BY LOWER(name) ASC"
        );
    }

    return db_fetch_all(
        "SELECT groups.id, groups.name, groups.visibility
         FROM groups
         WHERE groups.created_by = :user_id
            OR EXISTS (
                SELECT 1
                FROM group_members gm
                WHERE gm.group_id = groups.id
                  AND gm.user_id = :user_id
            )
         ORDER BY LOWER(groups.name) ASC",
        ['user_id' => $userId]
    );
}

function normalize_group_visibility(string $visibility, int $groupId, array &$errors): ?int
{
    if ($visibility !== 'group') {
        return null;
    }

    if ($groupId <= 0) {
        $errors[] = 'Vous devez choisir un groupe.';
        return null;
    }

    return $groupId;
}

function user_can_use_group(int $userId, int $groupId, bool $isAdmin = false): bool
{
    if ($isAdmin) {
        return db_fetch_one("SELECT id FROM groups WHERE id = :id LIMIT 1", ['id' => $groupId]) !== null;
    }

    return db_fetch_one(
        "SELECT groups.id
         FROM groups
         WHERE groups.id = :group_id
           AND (
                groups.created_by = :user_id
                OR EXISTS (
                    SELECT 1
                    FROM group_members gm
                    WHERE gm.group_id = groups.id
                      AND gm.user_id = :user_id
                )
           )
         LIMIT 1",
        [
            'group_id' => $groupId,
            'user_id' => $userId,
        ]
    ) !== null;
}

function visibility_label(?string $visibility, ?string $groupName = null): string
{
    if ($visibility === 'group') {
        return $groupName ? 'Groupe : ' . $groupName : 'Groupe';
    }

    if ($visibility === 'public') {
        return 'Public';
    }

    return content_visibility_options(false)[$visibility ?? ''] ?? (string)$visibility;
}

function user_avatar_initial(?string $displayName, ?string $username = null): string
{
    $source = trim((string)($displayName ?: $username));

    if ($source === '') {
        return '?';
    }

    return mb_strtoupper(mb_substr($source, 0, 1, 'UTF-8'), 'UTF-8');
}

function article_status_label(?string $status): string
{
    return match ($status) {
        'draft' => 'Brouillon',
        'published' => 'Publié',
        default => (string)$status,
    };
}

function content_type_label(?string $type): string
{
    return match ($type) {
        'article' => 'Article',
        'post' => 'Publication',
        default => (string)$type,
    };
}

/**
 * Convertit une taille PHP abrégée (2M, 128K, 1G) en octets.
 */
function php_size_to_bytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit = strtolower($value[strlen($value) - 1]);
    $number = (float)$value;

    return match ($unit) {
        'g' => (int)($number * 1024 * 1024 * 1024),
        'm' => (int)($number * 1024 * 1024),
        'k' => (int)($number * 1024),
        default => (int)$number,
    };
}

/**
 * Retourne la taille maximale acceptée par les réglages PHP du serveur.
 */
function server_upload_size_limit(): int
{
    $limits = array_filter([
        php_size_to_bytes((string)ini_get('upload_max_filesize')),
        php_size_to_bytes((string)ini_get('post_max_size')),
    ]);

    return $limits ? min($limits) : 0;
}

/**
 * Retourne la taille maximale réellement acceptée pour un upload.
 */
function effective_upload_size_limit(): int
{
    $limits = array_filter([
        MAX_UPLOAD_SIZE,
        server_upload_size_limit(),
    ]);

    return $limits ? min($limits) : 0;
}

/**
 * Crée un dossier s'il n'existe pas.
 */
function ensure_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
}

/**
 * Retourne une taille lisible.
 */
function human_file_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' o';
    }

    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' Ko';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' Mo';
    }

    return round($bytes / (1024 * 1024 * 1024), 2) . ' Go';
}

function article_preview_text(string $text, int $length = 220): string
{
    $text = preg_replace('/\$\$(.*?)\$\$/su', ' ', $text) ?? $text;
    $text = preg_replace('/\\\\\((.*?)\\\\\)/su', '$1', $text) ?? $text;
    $text = preg_replace('/(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)/su', '$1', $text) ?? $text;
    
    $text = str_replace(['**', '*', '`'], '', $text);
    $text = preg_replace('/^#+\s*/m', '', $text) ?? $text;
    $text = preg_replace('/^\-\s*/m', '• ', $text) ?? $text;
    $text = preg_replace('/$begin:math:display$\(\.\*\?\)$end:math:display$$begin:math:text$\(\.\*\?\)$end:math:text$/u', '$1', $text) ?? $text;
    
    $replacements = [
        '\rightarrow' => '→',
        '\leftarrow' => '←',
        '\leftrightarrow' => '↔',
        '\Rightarrow' => '⇒',
        '\Leftarrow' => '⇐',
        '\text{-}' => '-',
        '\,' => ' ',
    ];
    
    $text = str_replace(array_keys($replacements), array_values($replacements), $text);
    $text = str_replace(['{', '}'], '', $text);
    
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    
    return excerpt($text, $length);
}

function activity_log_should_track_request(): bool
{
    if (!empty($GLOBALS['activity_log_disabled'])) {
        return false;
    }

    if (
        session_status() === PHP_SESSION_ACTIVE
        && !empty($_SESSION['skip_next_activity_log'])
    ) {
        unset($_SESSION['skip_next_activity_log']);

        return false;
    }

    if (PHP_SAPI === 'cli') {
        return false;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($method, ['GET', 'POST'], true)) {
        return false;
    }

    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    $normalizedPath = str_replace('\\', '/', $path);

    foreach (['/assets/', '/api/'] as $excludedPath) {
        if (str_contains($normalizedPath, $excludedPath)) {
            return false;
        }
    }

    foreach (['/service-worker.js', '/manifest.json', '/site_logo.php', '/avatar.php'] as $excludedSuffix) {
        if (str_ends_with($normalizedPath, $excludedSuffix)) {
            return false;
        }
    }

    return $normalizedPath === '' || str_ends_with($normalizedPath, '.php') || !str_contains(basename($normalizedPath), '.');
}

function activity_log_disable_current_request(): void
{
    $GLOBALS['activity_log_disabled'] = true;
}

function activity_log_ip_address(): string
{
    $forwardedFor = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

    if ($forwardedFor !== '') {
        $parts = array_map('trim', explode(',', $forwardedFor));
        $candidate = $parts[0] ?? '';

        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($remoteAddress, FILTER_VALIDATE_IP) ? $remoteAddress : '';
}

function activity_log_ensure_table(): void
{
    static $done = false;

    if ($done || !function_exists('db')) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS visitor_activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            visitor_hash TEXT,
            method TEXT NOT NULL,
            path TEXT NOT NULL,
            query_string TEXT,
            full_url TEXT,
            referer TEXT,
            user_agent TEXT,
            ip_address TEXT,
            http_status INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        )"
    );

    db_exec_schema(
        "CREATE INDEX IF NOT EXISTS idx_visitor_activity_created_at
         ON visitor_activity(created_at)"
    );

    db_exec_schema(
        "CREATE INDEX IF NOT EXISTS idx_visitor_activity_user_id
         ON visitor_activity(user_id)"
    );

    $done = true;
}

function activity_log_current_user_id(): ?int
{
    if (!function_exists('current_user_id')) {
        return null;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        return current_user_id();
    }

    if (!empty($_COOKIE[SESSION_NAME])) {
        return current_user_id();
    }

    return null;
}

function activity_log_insert_current_request(bool $retryAfterCreate = true): void
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $fullUrl = $host !== '' ? $scheme . '://' . $host . $uri : $uri;
    $ipAddress = activity_log_ip_address();
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $visitorHash = hash('sha256', $ipAddress . '|' . $userAgent);

    try {
        db_query(
            "INSERT INTO visitor_activity
                (user_id, visitor_hash, method, path, query_string, full_url, referer, user_agent, ip_address, http_status, created_at)
             VALUES
                (:user_id, :visitor_hash, :method, :path, :query_string, :full_url, :referer, :user_agent, :ip_address, :http_status, :created_at)",
            [
                'user_id' => activity_log_current_user_id(),
                'visitor_hash' => $visitorHash,
                'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
                'path' => substr($path, 0, 255),
                'query_string' => substr($queryString, 0, 500),
                'full_url' => substr($fullUrl, 0, 1000),
                'referer' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 1000),
                'user_agent' => $userAgent,
                'ip_address' => substr($ipAddress, 0, 45),
                'http_status' => http_response_code(),
                'created_at' => now(),
            ]
        );
    } catch (Throwable $e) {
        if ($retryAfterCreate && str_contains($e->getMessage(), 'visitor_activity')) {
            activity_log_ensure_table();
            activity_log_insert_current_request(false);
        }
    }
}

function activity_log_current_request(): void
{
    static $logged = false;

    if ($logged || !activity_log_should_track_request() || !function_exists('db')) {
        return;
    }

    $logged = true;

    try {
        activity_log_insert_current_request();
    } catch (Throwable $e) {
        return;
    }
}

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/db.php';
    register_shutdown_function('activity_log_current_request');
}
