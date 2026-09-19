<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/request_guard.php';

app_start_session();
$requestUri = request_guard_not_found_uri();
$attempt = request_guard_record_not_found($requestUri, null, request_guard_is_authenticated());

if ($attempt['blocked']) {
    request_guard_render_blocked_response($attempt['retry_after']);
}

http_response_code(404);
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');

$isWordPressProbe = isset($_GET['rest_route'])
    || str_contains(strtolower($requestUri), 'rest_route')
    || str_contains(strtolower($requestUri), 'wp-json')
    || str_contains(strtolower($requestUri), 'wp-admin');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Rien à fouiller ici</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 2rem; background: #ffffff; color: #1d2939; }
        main { position: relative; width: min(46rem, 100%); overflow: hidden; padding: clamp(2rem, 7vw, 5rem); border: 1px solid #d8e2ef; border-radius: 10px; background: #ffffff; box-shadow: 0 28px 70px rgba(22, 32, 42, 0.14); }
        main::before { content: ''; position: absolute; inset: 0 0 auto; height: 7px; background: linear-gradient(90deg, #244978, #315f9f, #7ea6d7); }
        .code { color: #315f9f; font: 900 clamp(5rem, 20vw, 11rem)/.75 ui-monospace, monospace; letter-spacing: -.09em; }
        h1 { max-width: 15ch; margin: 2rem 0 1rem; color: #172b4d; font-size: clamp(2rem, 7vw, 3.75rem); line-height: 1; }
        p { max-width: 38rem; color: #667085; font-size: clamp(1rem, 3vw, 1.2rem); line-height: 1.7; }
        a { display: inline-block; margin-top: 1rem; padding: .85rem 1.2rem; border: 1px solid #315f9f; border-radius: 8px; background: #315f9f; color: #ffffff; font-weight: 800; text-decoration: none; }
        a:hover { background: #244978; border-color: #244978; }
        a:focus-visible { outline: 3px solid rgba(49, 95, 159, 0.28); outline-offset: 4px; }
    </style>
</head>
<body>
    <main>
        <div class="code" aria-hidden="true">404</div>
        <?php if ($isWordPressProbe): ?>
            <h1>Mauvais terrier, Sherlock.</h1>
            <p>Ici, ni WordPress, ni Gravity SMTP, ni trésor oublié. Votre scanner automatique vient surtout de prouver qu’il sait lire une liste toute faite.</p>
        <?php else: ?>
            <h1>Cette page joue très bien à cache-cache.</h1>
            <p>L’adresse demandée n’existe pas. Si c’était une tentative de reconnaissance, elle manque encore un peu de… reconnaissance.</p>
        <?php endif; ?>
        <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/public.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Retourner à l’accueil</a>
    </main>
</body>
</html>
