<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

$logoPath = site_logo_path();

if ($logoPath === '') {
    http_response_code(404);
    exit('Logo introuvable.');
}

$uploadsRoot = upload_storage_realpath();
$filePath = upload_realpath_from_logical($logoPath);

if ($uploadsRoot === false || $filePath === false || !is_file($filePath)) {
    http_response_code(404);
    exit('Logo introuvable.');
}

if (strncmp($filePath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(403);
    exit('Accès refusé.');
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath) ?: 'application/octet-stream';

if (!in_array($mimeType, ALLOWED_AVATAR_MIME_TYPES, true)) {
    http_response_code(415);
    exit('Type de fichier non autorisé.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($filePath));
header('Cache-Control: public, max-age=86400');

readfile($filePath);
