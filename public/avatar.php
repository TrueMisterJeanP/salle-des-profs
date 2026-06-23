<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/settings.php';

$userId = (int)get_value('user_id', '0');

if ($userId <= 0) {
    http_response_code(404);
    exit('Avatar introuvable.');
}

$avatarUser = db_fetch_one(
    "SELECT avatar
     FROM users
     WHERE id = :id
       AND is_active = 1
     LIMIT 1",
    ['id' => $userId]
);

if (!$avatarUser || empty($avatarUser['avatar'])) {
    http_response_code(404);
    exit('Avatar introuvable.');
}

$uploadsRoot = upload_storage_realpath();
$filePath = upload_realpath_from_logical((string)$avatarUser['avatar']);

if ($uploadsRoot === false || $filePath === false || !is_file($filePath)) {
    http_response_code(404);
    exit('Avatar absent du serveur.');
}

if (strncmp($filePath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(403);
    exit('Chemin de fichier refusé.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($filePath) ?: 'application/octet-stream';

if (!in_array($mimeType, ALLOWED_AVATAR_MIME_TYPES, true)) {
    http_response_code(415);
    exit('Type de fichier refusé.');
}

$fileSize = filesize($filePath);

if ($fileSize === false) {
    http_response_code(404);
    exit('Avatar illisible.');
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)$fileSize);
header('Content-Disposition: inline; filename="avatar"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

readfile($filePath);
exit;
