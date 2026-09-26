<?php
declare(strict_types=1);

/**
 * Routeur du serveur de développement PHP (php -S localhost:8000 router.php).
 * Reproduit les URL lisibles définies dans .htaccess. Inutile sous Apache.
 */
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Motif => [script, paramètres fixes, nom du paramètre capturé].
$routes = [
    '#^/public/article/([^/]+)/modifier/?$#' => ['public/article_edit.php', [], 'slug'],
    '#^/public/article/([^/]+)/?$#' => ['public/article.php', [], 'slug'],
    '#^/public/publication/([^/]+)/?$#' => ['public/public_article.php', [], 'slug'],
    '#^/public/groupe/([^/]+)/?$#' => ['public/group.php', [], 'slug'],
    '#^/public/messages/([^/]+)/?$#' => ['public/chat.php', [], 'user'],
    '#^/public/messagerie/prive/([^/]+)/?$#' => ['public/messenger.php', ['type' => 'private'], 'user'],
    '#^/public/messagerie/groupe/([^/]+)/?$#' => ['public/messenger.php', ['type' => 'group'], 'group'],
];

foreach ($routes as $pattern => [$script, $fixedParams, $captureParam]) {
    if (preg_match($pattern, $path, $matches) === 1) {
        $_GET = $fixedParams + [$captureParam => rawurldecode($matches[1])] + $_GET;
        $_SERVER['SCRIPT_NAME'] = '/' . $script;
        $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/' . $script;
        chdir(dirname(__DIR__ . '/' . $script));
        require __DIR__ . '/' . $script;
        return true;
    }
}

return false;
