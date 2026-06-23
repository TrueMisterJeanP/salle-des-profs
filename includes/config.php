<?php
declare(strict_types=1);

/**
 * Configuration générale de l'application.
 */

const APP_NAME = 'Salle des profs';
const APP_VERSION = '1.0.0';
const APP_ENV = 'development'; // development ou production

/**
 * URL de base de l'application. Peut être forcée avec APP_BASE_URL.
 */
function app_request_host(): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = str_replace(["\r", "\n", "\t"], '', $host);

    if ($host === '' || strlen($host) > 255 || str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '@')) {
        return '';
    }

    if (preg_match('/^\[[0-9a-fA-F:.]+\](?::\d{1,5})?$/', $host) === 1) {
        return $host;
    }

    if (preg_match('/^(localhost|[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::\d{1,5})?$/i', $host) !== 1) {
        return '';
    }

    if (str_contains($host, '..')) {
        return '';
    }

    if (preg_match('/:(\d{1,5})$/', $host, $matches) === 1) {
        $port = (int)$matches[1];

        if ($port < 1 || $port > 65535) {
            return '';
        }
    }

    return $host;
}

function app_detect_base_url(): string
{
    $configured = trim((string)(getenv('APP_BASE_URL') ?: ''));

    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $host = app_request_host();

    if ($host === '') {
        return '';
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFilename = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $publicPath = realpath(__DIR__ . '/../public') ?: '';
    $scriptPath = realpath($scriptFilename) ?: '';
    $isPublicScript = $publicPath !== ''
        && $scriptPath !== ''
        && str_starts_with($scriptPath, $publicPath . DIRECTORY_SEPARATOR);

    if (preg_match('#^(.*)/(public|admin|api)(?:/|$)#', $scriptName, $matches) === 1) {
        $projectBase = rtrim((string)$matches[1], '/');
        $basePath = $projectBase . '/public';
    } elseif ($isPublicScript) {
        $basePath = rtrim(str_replace('/index.php', '', dirname($scriptName)), '/');
    } else {
        $projectBase = rtrim(str_replace('/index.php', '', dirname($scriptName)), '/');
        $basePath = $projectBase . '/public';
    }

    if ($basePath === '/' || $basePath === '.') {
        $basePath = '';
    }

    return $scheme . '://' . $host . $basePath;
}

function app_development_root_install_allowed(): bool
{
    if (APP_ENV !== 'development') {
        return false;
    }

    $host = strtolower(app_request_host());
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host) ?: $host;
    $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    return in_array($hostWithoutPort, ['localhost', '127.0.0.1', '[::1]'], true)
        && in_array($remoteAddress, ['127.0.0.1', '::1'], true);
}

define('BASE_URL', app_detect_base_url());

/**
 * Chemins internes.
 */
const ROOT_PATH = __DIR__ . '/..';
const DATABASE_PATH = ROOT_PATH . '/database/app.sqlite';
const UPLOAD_PATH = ROOT_PATH . '/uploads';
const AVATAR_UPLOAD_PATH = UPLOAD_PATH . '/avatars';
const ATTACHMENT_UPLOAD_PATH = UPLOAD_PATH . '/attachments';
const SITE_UPLOAD_PATH = UPLOAD_PATH . '/site';

/**
 * Paramètres de sécurité.
 */
const SESSION_NAME = 'protection_enseignants_session';
const CSRF_TOKEN_NAME = 'csrf_token';

/**
 * Taille maximale des fichiers envoyés.
 * Ici : 20 Mo.
 */
const MAX_UPLOAD_SIZE = 20 * 1024 * 1024;

/**
 * Types MIME autorisés pour les pièces jointes.
 */
const ALLOWED_ATTACHMENT_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
    'text/plain',
    'text/csv',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/zip',
    'application/mp4',
    'video/mp4',
    'video/quicktime',
    'video/webm',
    'video/ogg',
    'video/x-m4v',
    'audio/mpeg',
    'audio/ogg',
];

/**
 * Types MIME autorisés pour les avatars.
 */
const ALLOWED_AVATAR_MIME_TYPES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
];


/**
 * Fuseau horaire.
 */
date_default_timezone_set('Europe/Paris');

/**
 * Affichage des erreurs en développement.
 */
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
}
