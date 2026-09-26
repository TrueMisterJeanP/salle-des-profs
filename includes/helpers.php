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
 * Initialise la session avec les mêmes protections, quel que soit le premier
 * composant qui en a besoin (authentification, CSRF ou message flash).
 */
function app_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || parse_url(BASE_URL, PHP_URL_SCHEME) === 'https',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Indique si une adresse IP peut être jointe depuis une requête HTTP sortante.
 * Les réseaux locaux, de bouclage, réservés et link-local sont refusés afin
 * qu'une URL fournie par un utilisateur ne puisse pas atteindre le serveur.
 */
function outbound_http_ip_is_public(string $ipAddress): bool
{
    return filter_var(
        $ipAddress,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function http_url_has_allowed_scheme(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($url);

    return is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass']);
}

/**
 * Valide une URL HTTP(S) et toutes les adresses auxquelles son hôte se résout.
 */
function outbound_http_url_public_addresses(string $url): array
{
    if (!http_url_has_allowed_scheme($url)) {
        return [];
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return [];
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));

    if (!in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        return [];
    }

    if ($host === 'localhost'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.internal')
    ) {
        return [];
    }

    $literalAddress = trim($host, '[]');
    if (filter_var($literalAddress, FILTER_VALIDATE_IP) !== false) {
        return outbound_http_ip_is_public($literalAddress) ? [$literalAddress] : [];
    }

    $addresses = [];
    if (function_exists('dns_get_record')) {
        $recordType = DNS_A;
        if (defined('DNS_AAAA')) {
            $recordType |= DNS_AAAA;
        }

        $records = @dns_get_record($host, $recordType);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = (string)($record['ip'] ?? $record['ipv6'] ?? '');
                if ($address !== '') {
                    $addresses[] = $address;
                }
            }
        }
    }

    if (!$addresses && function_exists('gethostbynamel')) {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved)) {
            $addresses = $resolved;
        }
    }

    if (!$addresses) {
        return [];
    }

    foreach (array_unique($addresses) as $address) {
        if (!outbound_http_ip_is_public((string)$address)) {
            return [];
        }
    }

    return array_values(array_unique(array_map('strval', $addresses)));
}

function outbound_http_url_is_safe(string $url): bool
{
    return outbound_http_url_public_addresses($url) !== [];
}

/**
 * Retourne les options cURL qui figent la résolution DNS déjà contrôlée.
 * Cela empêche une seconde résolution vers une adresse locale entre le
 * contrôle de l'URL et la connexion effective (DNS rebinding).
 */
function outbound_http_curl_security_options(string $url): ?array
{
    $addresses = outbound_http_url_public_addresses($url);
    $parts = parse_url($url);

    if (!$addresses || !is_array($parts)) {
        return null;
    }

    $host = (string)($parts['host'] ?? '');
    $literalAddress = trim($host, '[]');
    if (filter_var($literalAddress, FILTER_VALIDATE_IP) !== false) {
        return [];
    }

    if (!defined('CURLOPT_RESOLVE')) {
        return null;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $address = $addresses[0];

    if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $address = '[' . $address . ']';
    }

    return [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $address]];
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
 * URL lisible d'un article dans l'espace membres : /article/mon-titre.
 */
function article_url(string $slug): string
{
    return url('article/' . rawurlencode($slug));
}

/**
 * URL lisible d'un article public : /publication/mon-titre.
 */
function public_article_url(string $slug): string
{
    return url('publication/' . rawurlencode($slug));
}

/**
 * URL lisible de l'édition d'un article : /article/mon-titre/modifier.
 */
function article_edit_url(string $slug): string
{
    return url('article/' . rawurlencode($slug) . '/modifier');
}

/**
 * URL lisible d'une conversation privée sur le site : /messages/nom-utilisateur.
 */
function chat_url(string $username, array $query = []): string
{
    $url = url('messages/' . rawurlencode($username));

    return $query ? $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : $url;
}

/**
 * URL lisible d'une conversation privée dans la messagerie : /messagerie/prive/nom-utilisateur.
 */
function messenger_private_url(string $username): string
{
    return url('messagerie/prive/' . rawurlencode($username));
}

/**
 * URL lisible d'un groupe dans la messagerie : /messagerie/groupe/nom-du-groupe.
 */
function messenger_group_url(string $slug): string
{
    return url('messagerie/groupe/' . rawurlencode($slug));
}

/**
 * Redirige de façon permanente une ancienne adresse en ?slug= vers son URL lisible.
 */
function redirect_legacy_slug_url(string $canonicalUrl): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');

    if (!str_ends_with($path, '.php')) {
        return;
    }

    header('Location: ' . $canonicalUrl, true, 301);
    exit;
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

    // Retire les accents avant iconv, dont la translittération varie selon le système (é → 'e sous macOS).
    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

        if (is_string($decomposed)) {
            $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
        }
    }

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
    app_start_session();

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
    app_start_session();

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

function request_expects_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $uri = str_replace('\\', '/', (string)($_SERVER['REQUEST_URI'] ?? ''));

    return str_contains($accept, 'application/json')
        || str_contains($uri, '/api/');
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

/**
 * URL lisible d'un groupe : /groupe/nom-du-groupe.
 */
function group_url(string $slug): string
{
    return url('groupe/' . rawurlencode($slug));
}

/**
 * Slug unique d'un groupe, dérivé de son nom.
 */
function group_unique_slug(string $name, ?int $ignoreGroupId = null): string
{
    $baseSlug = slugify($name);
    $slug = $baseSlug;
    $counter = 2;

    while (true) {
        $params = ['slug' => $slug];
        $sql = "SELECT id FROM groups WHERE slug = :slug";

        if ($ignoreGroupId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $ignoreGroupId;
        }

        if (!db_fetch_one($sql . " LIMIT 1", $params)) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

/**
 * Ajoute la colonne groups.slug sur une installation existante et renseigne
 * les groupes qui n'en ont pas encore (migration 024).
 */
function groups_ensure_slug_column(): void
{
    static $done = false;

    if ($done || !database_is_installed()) {
        return;
    }

    if (!db_column_exists('groups', 'slug')) {
        db_query("ALTER TABLE groups ADD COLUMN slug VARCHAR(255)");
        db_query("CREATE UNIQUE INDEX IF NOT EXISTS idx_groups_slug ON groups(slug)");
    }

    $groupsWithoutSlug = db_fetch_all("SELECT id, name FROM groups WHERE slug IS NULL OR slug = '' ORDER BY id ASC");

    foreach ($groupsWithoutSlug as $group) {
        db_query(
            "UPDATE groups SET slug = :slug WHERE id = :id",
            [
                'slug' => group_unique_slug((string)$group['name'], (int)$group['id']),
                'id' => (int)$group['id'],
            ]
        );
    }

    $done = true;
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
    $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
        return '';
    }

    $configuredProxies = array_filter(array_map(
        'trim',
        explode(',', (string)(getenv('TRUSTED_PROXY_IPS') ?: ''))
    ));
    $trustedProxies = array_values(array_unique(array_merge(['127.0.0.1', '::1'], $configuredProxies)));

    if (!in_array($remoteAddress, $trustedProxies, true)) {
        return $remoteAddress;
    }

    $forwardedFor = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwardedFor !== '') {
        $parts = array_reverse(array_map('trim', explode(',', $forwardedFor)));

        foreach ($parts as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if (!in_array($candidate, $trustedProxies, true)) {
                return $candidate;
            }
        }
    }

    return $remoteAddress;
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

    activity_log_redact_stored_sensitive_values();

    $done = true;
}

function activity_log_redact_stored_sensitive_values(): void
{
    try {
        $completed = db_fetch_one(
            "SELECT setting_value
             FROM settings
             WHERE setting_key = 'security_activity_redaction_v1'
             LIMIT 1"
        );
        if (($completed['setting_value'] ?? '') === '1') {
            return;
        }

        $lastId = 0;
        do {
            $rows = db_fetch_all(
                "SELECT id, query_string, full_url, referer
                 FROM visitor_activity
                 WHERE id > :last_id
                 ORDER BY id ASC
                 LIMIT 500",
                ['last_id' => $lastId]
            );

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $queryString = activity_log_redact_query_string((string)($row['query_string'] ?? ''));
                $fullUrl = activity_log_redact_url((string)($row['full_url'] ?? ''));
                $referer = activity_log_redact_url((string)($row['referer'] ?? ''));

                if ($queryString !== (string)($row['query_string'] ?? '')
                    || $fullUrl !== (string)($row['full_url'] ?? '')
                    || $referer !== (string)($row['referer'] ?? '')
                ) {
                    db_query(
                        "UPDATE visitor_activity
                         SET query_string = :query_string,
                             full_url = :full_url,
                             referer = :referer
                         WHERE id = :id",
                        [
                            'query_string' => $queryString,
                            'full_url' => $fullUrl,
                            'referer' => $referer,
                            'id' => $lastId,
                        ]
                    );
                }
            }
        } while (count($rows) === 500);

        db_save_setting_value('security_activity_redaction_v1', '1');
    } catch (Throwable) {
        // La journalisation ne doit jamais empêcher l'application de répondre.
    }
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

function activity_log_redact_query_string(string $queryString): string
{
    $pairs = explode('&', $queryString);

    foreach ($pairs as &$pair) {
        $separator = strpos($pair, '=');
        $rawKey = $separator === false ? $pair : substr($pair, 0, $separator);
        $key = rawurldecode(str_replace('+', ' ', $rawKey));

        if (preg_match('/(?:^|_)(?:token|password|secret|key|code)(?:$|_)/i', $key) === 1) {
            $pair = $rawKey . '=REDACTED';
        }
    }
    unset($pair);

    return implode('&', $pairs);
}

function activity_log_redact_url(string $url): string
{
    $queryStart = strpos($url, '?');
    if ($queryStart === false) {
        return $url;
    }

    $fragmentStart = strpos($url, '#', $queryStart);
    $queryEnd = $fragmentStart === false ? strlen($url) : $fragmentStart;
    $query = substr($url, $queryStart + 1, $queryEnd - $queryStart - 1);

    return substr($url, 0, $queryStart + 1)
        . activity_log_redact_query_string($query)
        . substr($url, $queryEnd);
}

function activity_log_insert_current_request(bool $retryAfterCreate = true): void
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    $queryString = activity_log_redact_query_string((string)($_SERVER['QUERY_STRING'] ?? ''));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $fullUrl = activity_log_redact_url($host !== '' ? $scheme . '://' . $host . $uri : $uri);
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
                'referer' => substr(activity_log_redact_url((string)($_SERVER['HTTP_REFERER'] ?? '')), 0, 1000),
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
