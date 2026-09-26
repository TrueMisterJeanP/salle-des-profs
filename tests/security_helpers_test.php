<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/request_guard.php';

$tests = [];

$tests['IP publique IPv4'] = outbound_http_ip_is_public('8.8.8.8') === true;
$tests['IP locale IPv4 refusée'] = outbound_http_ip_is_public('127.0.0.1') === false;
$tests['IP privée IPv4 refusée'] = outbound_http_ip_is_public('10.10.0.4') === false;
$tests['IP link-local refusée'] = outbound_http_ip_is_public('169.254.169.254') === false;
$tests['IP locale IPv6 refusée'] = outbound_http_ip_is_public('::1') === false;
$tests['URL HTTP publique'] = outbound_http_url_is_safe('https://8.8.8.8/example') === true;
$tests['URL localhost refusée'] = outbound_http_url_is_safe('http://localhost/test') === false;
$tests['URL privée refusée'] = outbound_http_url_is_safe('http://192.168.1.10/test') === false;
$tests['URL avec identifiants refusée'] = outbound_http_url_is_safe('https://user:pass@8.8.8.8/test') === false;
$tests['Protocole fichier refusé'] = outbound_http_url_is_safe('file:///etc/passwd') === false;
$tests['Schéma HTTP accepté'] = http_url_has_allowed_scheme('http://192.168.1.10/site') === true;
$tests['Schéma non HTTP refusé'] = http_url_has_allowed_scheme('ftp://8.8.8.8/file') === false;
$tests['Connexion cURL publique autorisée'] = outbound_http_curl_security_options('https://8.8.8.8/example') === [];
$tests['Connexion cURL locale refusée'] = outbound_http_curl_security_options('http://127.0.0.1/test') === null;

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
$tests['Proxy non approuvé ignoré'] = activity_log_ip_address() === '203.0.113.10';

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8, 127.0.0.1';
$tests['Proxy local approuvé'] = activity_log_ip_address() === '8.8.8.8';
$tests['Jeton de journal masqué'] = activity_log_redact_query_string('q=test&token=secret&setup_token=autre')
    === 'q=test&token=REDACTED&setup_token=REDACTED';
$tests['Référent sensible masqué'] = activity_log_redact_url('https://example.test/login.php?setup_token=secret&q=test')
    === 'https://example.test/login.php?setup_token=REDACTED&q=test';

$_SERVER['REQUEST_URI'] = '/api/fetch_messages.php';
$_SERVER['HTTP_ACCEPT'] = '*/*';
$tests['Endpoint API détecté comme JSON'] = request_expects_json() === true;
$_SERVER['REQUEST_URI'] = '/public/dashboard.php';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$tests['Page HTML non détectée comme JSON'] = request_expects_json() === false;
$tests['Empreinte IP stable'] = request_guard_ip_hash('203.0.113.10') === request_guard_ip_hash('203.0.113.10');
$tests['Empreintes IP séparées'] = request_guard_ip_hash('203.0.113.10') !== request_guard_ip_hash('203.0.113.11');
$tests['Empreinte URL stable'] = request_guard_route_hash('/absent?a=1') === request_guard_route_hash('/absent?a=1');
$tests['Requêtes REST distinctes comptables'] = request_guard_route_hash('/public/public.php?rest_route=/wp/v2/tags')
    !== request_guard_route_hash('/public/public.php?rest_route=/wp/v2/pages');
$_SERVER['REQUEST_URI'] = '/public/404.php';
$_SERVER['REDIRECT_URL'] = '/vraie-url-absente';
$_SERVER['REDIRECT_QUERY_STRING'] = 'test=1';
$tests['URL 404 Apache d’origine conservée'] = request_guard_not_found_uri() === '/vraie-url-absente?test=1';
unset($_SERVER['REDIRECT_URL'], $_SERVER['REDIRECT_QUERY_STRING']);

$_SERVER['SCRIPT_NAME'] = '/public/404.php';
$_SERVER['SCRIPT_FILENAME'] = ROOT_PATH . '/public/404.php';
$tests['Requête vers la page 404 reconnue'] = request_guard_is_not_found_request() === true;
$_SERVER['SCRIPT_NAME'] = '/public/dashboard.php';
$_SERVER['SCRIPT_FILENAME'] = ROOT_PATH . '/public/dashboard.php';
$tests['Route valide reconnue'] = request_guard_is_not_found_request() === false;
$_SERVER['SCRIPT_NAME'] = '/public/login.php';
$_SERVER['SCRIPT_FILENAME'] = ROOT_PATH . '/public/login.php';
$tests['Formulaire login exempté du bannissement'] = request_guard_is_login_request() === true
    && request_guard_is_not_found_request() === false;
$_SERVER['SCRIPT_NAME'] = '/public/messenger_login.php';
$_SERVER['SCRIPT_FILENAME'] = ROOT_PATH . '/public/messenger_login.php';
$tests['Formulaire messenger exempté du bannissement'] = request_guard_is_login_request() === true
    && request_guard_is_not_found_request() === false;
$_SERVER['SCRIPT_NAME'] = '/public/dashboard.php';
$_SERVER['SCRIPT_FILENAME'] = ROOT_PATH . '/public/dashboard.php';
$tests['Route ordinaire soumise au bannissement'] = request_guard_is_login_request() === false;
$tests['Favicon reconnu comme ressource statique'] = request_guard_is_static_asset_path('/favicon.ico') === true
    && request_guard_is_static_asset_path('/apple-touch-icon.png') === true;
$tests['Sonde PHP non traitée comme ressource statique'] = request_guard_is_static_asset_path('/wp-login.php') === false
    && request_guard_is_static_asset_path('/.env') === false;

unset($_SERVER['HTTP_X_FORWARDED_FOR']);
app_start_session();
$tests['Visiteur anonyme reconnu'] = request_guard_is_authenticated() === false;
$_SESSION['user_id'] = 123;
$tests['Utilisateur connecté reconnu'] = request_guard_is_authenticated() === true;
$authenticatedAttempt = request_guard_record_not_found('/url-inexistante', 1_700_000_000);
$tests['404 connectée jamais bloquée'] = $authenticatedAttempt === [
    'attempts' => 0,
    'blocked' => false,
    'retry_after' => 0,
];
unset($_SESSION['user_id']);
$cookieParameters = session_get_cookie_params();
$tests['Cookie de session HttpOnly'] = ($cookieParameters['httponly'] ?? false) === true;
$tests['Cookie de session SameSite'] = ($cookieParameters['samesite'] ?? '') === 'Lax';

$failures = 0;
foreach ($tests as $name => $passed) {
    if (!$passed) {
        $failures++;
        fwrite(STDERR, $name . " : échec\n");
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

if ($failures > 0) {
    exit(1);
}

echo count($tests) . " tests de sécurité réussis.\n";
