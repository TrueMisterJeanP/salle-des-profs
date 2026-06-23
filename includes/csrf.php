<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Initialise la session si nécessaire.
 */
function csrf_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
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
}

function csrf_prevent_form_cache(): void
{
    if (headers_sent()) {
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

/**
 * Génère ou retourne le jeton CSRF courant.
 */
function csrf_token(): string
{
    csrf_start_session();
    csrf_prevent_form_cache();

    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_TOKEN_NAME];
}

/**
 * Affiche un champ hidden contenant le jeton CSRF.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Vérifie le jeton CSRF reçu en POST.
 */
function csrf_verify(): bool
{
    csrf_start_session();

    $sessionToken = $_SESSION[CSRF_TOKEN_NAME] ?? '';
    $postedToken = $_POST[CSRF_TOKEN_NAME] ?? '';

    if (!is_string($sessionToken) || !is_string($postedToken)) {
        return false;
    }

    return hash_equals($sessionToken, $postedToken);
}

/**
 * Bloque la requête si le jeton CSRF est invalide.
 */
function require_csrf(): void
{
    if (!csrf_verify()) {
        http_response_code(403);

        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');

        if (str_contains($accept, 'application/json') || str_contains($uri, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Jeton de sécurité invalide.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        unset($_SESSION[CSRF_TOKEN_NAME]);
        csrf_token();

        set_flash('warning', 'La page avait expiré. Merci de réessayer.');

        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        $query = parse_url($uri, PHP_URL_QUERY);

        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')) {
            redirect(url('login.php'));
        }

        redirect($path . (is_string($query) && $query !== '' ? '?' . $query : ''));
    }
}

/**
 * Renouvelle le jeton CSRF, utile après connexion/déconnexion.
 */
function csrf_regenerate(): void
{
    csrf_start_session();
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
}
