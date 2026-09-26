<?php
declare(strict_types=1);

const REQUEST_GUARD_MAX_NOT_FOUND = 3;
const REQUEST_GUARD_WINDOW_SECONDS = 600;
const REQUEST_GUARD_BLOCK_SECONDS = 600;

function request_guard_client_ip(): string
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
        foreach (array_reverse(array_map('trim', explode(',', $forwardedFor))) as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false
                && !in_array($candidate, $trustedProxies, true)
            ) {
                return $candidate;
            }
        }
    }

    return $remoteAddress;
}

function request_guard_ip_hash(string $ipAddress): string
{
    return hash('sha256', $ipAddress);
}

function request_guard_route_hash(string $requestUri): string
{
    return hash('sha256', substr($requestUri, 0, 2048));
}

function request_guard_not_found_uri(): string
{
    $redirectUrl = trim((string)($_SERVER['REDIRECT_URL'] ?? ''));

    if ($redirectUrl !== '') {
        $redirectQuery = (string)($_SERVER['REDIRECT_QUERY_STRING'] ?? '');

        return $redirectUrl . ($redirectQuery !== '' ? '?' . $redirectQuery : '');
    }

    return (string)($_SERVER['REQUEST_URI'] ?? '/');
}

function request_guard_is_not_found_request(): bool
{
    if ((int)($_SERVER['REDIRECT_STATUS'] ?? 0) === 404) {
        return true;
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFilename = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));

    return basename($scriptName) === '404.php' || basename($scriptFilename) === '404.php';
}

function request_guard_is_login_request(): bool
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFilename = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $loginScripts = ['login.php', 'messenger_login.php', 'forgot_password.php'];

    return in_array(basename($scriptName), $loginScripts, true)
        || in_array(basename($scriptFilename), $loginScripts, true);
}

/**
 * Les navigateurs demandent d'eux-mêmes des ressources annexes (favicon,
 * icônes tactiles…) : leur absence ne doit pas être comptée comme une sonde.
 */
function request_guard_is_static_asset_path(string $path): bool
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    return in_array($extension, [
        'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif',
        'css', 'js', 'map', 'woff', 'woff2', 'ttf', 'eot',
    ], true);
}

function request_guard_is_authenticated(): bool
{
    return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']);
}

function request_guard_ensure_schema(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS missing_route_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash VARCHAR(64) NOT NULL,
            route_hash VARCHAR(64) NOT NULL,
            path VARCHAR(255) NOT NULL,
            attempted_at INTEGER NOT NULL
        )"
    );
    db_exec_schema(
        "CREATE INDEX IF NOT EXISTS idx_missing_route_attempts_lookup
         ON missing_route_attempts(ip_hash, attempted_at)"
    );
    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS blocked_clients (
            ip_hash VARCHAR(64) PRIMARY KEY,
            blocked_until INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )"
    );
    db_exec_schema(
        "CREATE INDEX IF NOT EXISTS idx_blocked_clients_until
         ON blocked_clients(blocked_until)"
    );

    $done = true;
}

function request_guard_block_remaining_for_hash(string $ipHash, ?int $timestamp = null): int
{
    $timestamp ??= time();

    try {
        $row = db_fetch_one(
            "SELECT blocked_until
             FROM blocked_clients
             WHERE ip_hash = :ip_hash
             LIMIT 1",
            ['ip_hash' => $ipHash]
        );
    } catch (Throwable) {
        try {
            request_guard_ensure_schema();
            $row = db_fetch_one(
                "SELECT blocked_until
                 FROM blocked_clients
                 WHERE ip_hash = :ip_hash
                 LIMIT 1",
                ['ip_hash' => $ipHash]
            );
        } catch (Throwable) {
            return 0;
        }
    }

    $remaining = (int)($row['blocked_until'] ?? 0) - $timestamp;

    return max(0, $remaining);
}

/**
 * Efface les 404 mémorisées pour une IP sans lever un bannissement déjà
 * prononcé, qui reste actif pendant toute sa durée.
 */
function request_guard_reset_not_found_attempts(?string $ipAddress = null): void
{
    $ipAddress ??= request_guard_client_ip();

    if ($ipAddress === '') {
        return;
    }

    $ipHash = request_guard_ip_hash($ipAddress);

    try {
        db_query(
            "DELETE FROM missing_route_attempts WHERE ip_hash = :ip_hash",
            ['ip_hash' => $ipHash]
        );
    } catch (Throwable) {
        // Le mécanisme anti-abus ne doit jamais empêcher une route valide.
    }
}

/**
 * Enregistre une URL inexistante et bloque un visiteur anonyme à la troisième
 * 404 observée dans la fenêtre glissante de dix minutes.
 *
 * @return array{attempts: int, blocked: bool, retry_after: int}
 */
function request_guard_record_not_found(
    ?string $requestUri = null,
    ?int $timestamp = null,
    ?bool $isAuthenticated = null
): array
{
    $result = ['attempts' => 0, 'blocked' => false, 'retry_after' => 0];
    $ipAddress = request_guard_client_ip();

    if ($ipAddress === '') {
        return $result;
    }

    $isAuthenticated ??= request_guard_is_authenticated();

    if ($isAuthenticated) {
        request_guard_reset_not_found_attempts($ipAddress);

        return $result;
    }

    $timestamp ??= time();
    $requestUri ??= request_guard_not_found_uri();
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $ipHash = request_guard_ip_hash($ipAddress);
    $routeHash = request_guard_route_hash($requestUri);
    $windowStart = $timestamp - REQUEST_GUARD_WINDOW_SECONDS;

    try {
        request_guard_ensure_schema();

        db_query(
            "DELETE FROM missing_route_attempts
             WHERE attempted_at < :cleanup_before",
            ['cleanup_before' => $timestamp - 86400]
        );
        db_query(
            "DELETE FROM blocked_clients
             WHERE blocked_until <= :now",
            ['now' => $timestamp]
        );

        $remaining = request_guard_block_remaining_for_hash($ipHash, $timestamp);
        if ($remaining > 0) {
            return [
                'attempts' => REQUEST_GUARD_MAX_NOT_FOUND,
                'blocked' => true,
                'retry_after' => $remaining,
            ];
        }

        if (request_guard_is_static_asset_path($path)) {
            return $result;
        }

        db_query(
            "INSERT INTO missing_route_attempts
                (ip_hash, route_hash, path, attempted_at)
             VALUES
                (:ip_hash, :route_hash, :path, :attempted_at)",
            [
                'ip_hash' => $ipHash,
                'route_hash' => $routeHash,
                'path' => substr($path, 0, 255),
                'attempted_at' => $timestamp,
            ]
        );

        $countRow = db_fetch_one(
            "SELECT COUNT(*) AS total
             FROM missing_route_attempts
             WHERE ip_hash = :ip_hash
               AND attempted_at >= :window_start",
            [
                'ip_hash' => $ipHash,
                'window_start' => $windowStart,
            ]
        );
        $result['attempts'] = (int)($countRow['total'] ?? 0);

        if ($result['attempts'] >= REQUEST_GUARD_MAX_NOT_FOUND) {
            $blockedUntil = $timestamp + REQUEST_GUARD_BLOCK_SECONDS;
            $block = db_fetch_one(
                "SELECT ip_hash FROM blocked_clients WHERE ip_hash = :ip_hash LIMIT 1",
                ['ip_hash' => $ipHash]
            );

            if ($block === null) {
                try {
                    db_query(
                        "INSERT INTO blocked_clients (ip_hash, blocked_until, updated_at)
                         VALUES (:ip_hash, :blocked_until, :updated_at)",
                        [
                            'ip_hash' => $ipHash,
                            'blocked_until' => $blockedUntil,
                            'updated_at' => $timestamp,
                        ]
                    );
                } catch (Throwable) {
                    db_query(
                        "UPDATE blocked_clients
                         SET blocked_until = :blocked_until, updated_at = :updated_at
                         WHERE ip_hash = :ip_hash",
                        [
                            'ip_hash' => $ipHash,
                            'blocked_until' => $blockedUntil,
                            'updated_at' => $timestamp,
                        ]
                    );
                }
            } else {
                db_query(
                    "UPDATE blocked_clients
                     SET blocked_until = :blocked_until, updated_at = :updated_at
                     WHERE ip_hash = :ip_hash",
                    [
                        'ip_hash' => $ipHash,
                        'blocked_until' => $blockedUntil,
                        'updated_at' => $timestamp,
                    ]
                );
            }

            $result['blocked'] = true;
            $result['retry_after'] = REQUEST_GUARD_BLOCK_SECONDS;
        }
    } catch (Throwable) {
        // Une panne du mécanisme anti-abus ne doit pas rendre le site indisponible.
    }

    return $result;
}

function request_guard_render_blocked_response(int $retryAfter): never
{
    $retryAfter = max(1, $retryAfter);
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    header('Cache-Control: no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('Content-Type: text/html; charset=utf-8');

    $minutes = max(1, (int)ceil($retryAfter / 60));
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pause technique imposée</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 2rem; background: #ffffff; color: #1d2939; }
        main { position: relative; width: min(46rem, 100%); overflow: hidden; padding: clamp(2rem, 7vw, 5rem); border: 1px solid #d8e2ef; border-radius: 10px; background: #ffffff; box-shadow: 0 28px 70px rgba(22, 32, 42, 0.14); }
        main::before { content: ''; position: absolute; inset: 0 0 auto; height: 7px; background: linear-gradient(90deg, #244978, #315f9f, #7ea6d7); }
        .code { color: #315f9f; font: 900 clamp(5rem, 20vw, 11rem)/.75 ui-monospace, monospace; letter-spacing: -.09em; }
        h1 { max-width: 15ch; margin: 2rem 0 1rem; color: #172b4d; font-size: clamp(2rem, 7vw, 3.75rem); line-height: 1; }
        p { max-width: 38rem; color: #667085; font-size: clamp(1rem, 3vw, 1.2rem); line-height: 1.7; }
        strong { color: #172b4d; }
    </style>
</head>
<body>
    <main>
        <div class="code" aria-hidden="true">429</div>
        <h1>Pause café imposée.</h1>
        <p>Trois portes imaginaires en dix minutes&nbsp;: joli trousseau, mais aucune clé ne va.</p>
        <p>Cette adresse IP pourra retenter sa chance dans <strong><?= $minutes ?> minute<?= $minutes > 1 ? 's' : '' ?></strong>. Le robot peut en profiter pour relire le manuel.</p>
    </main>
</body>
</html>
<?php
    exit;
}

function request_guard_anonymous_block_remaining(
    ?bool $isAuthenticated = null,
    ?int $timestamp = null
): int
{
    $isAuthenticated ??= request_guard_is_authenticated();

    if ($isAuthenticated) {
        return 0;
    }

    $ipAddress = request_guard_client_ip();
    if ($ipAddress === '') {
        return 0;
    }

    return request_guard_block_remaining_for_hash(request_guard_ip_hash($ipAddress), $timestamp);
}

/**
 * Applique le bannissement à chaque route valide demandée par un visiteur
 * anonyme. Les URL inexistantes sont traitées par public/404.php. Les
 * formulaires de connexion, déjà protégés par leur propre limiteur, restent
 * joignables afin qu'un utilisateur légitime puisse ouvrir une session, ce qui
 * lève le blocage.
 */
function request_guard_handle_current_request(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    if (request_guard_is_not_found_request() || request_guard_is_login_request()) {
        return;
    }

    $ipAddress = request_guard_client_ip();
    if ($ipAddress === '') {
        return;
    }

    $retryAfter = request_guard_block_remaining_for_hash(request_guard_ip_hash($ipAddress));
    if ($retryAfter <= 0) {
        return;
    }

    // La session n'est ouverte que si le navigateur en présente déjà une :
    // un scanner sans cookie ne doit pas s'en voir attribuer une.
    if (session_status() === PHP_SESSION_NONE
        && defined('SESSION_NAME')
        && isset($_COOKIE[SESSION_NAME])
    ) {
        require_once __DIR__ . '/helpers.php';
        app_start_session();
    }

    if (request_guard_is_authenticated()) {
        return;
    }

    request_guard_render_blocked_response($retryAfter);
}
