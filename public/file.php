<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/posts.php';

$user = current_user();
$attachmentId = (int)get_value('id', '0');

if ($attachmentId <= 0) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$attachment = db_fetch_one(
    "SELECT *
     FROM attachments
     WHERE id = :id
     LIMIT 1",
    ['id' => $attachmentId]
);

if (!$attachment) {
    http_response_code(404);
    exit('Fichier introuvable.');
}

$canAccess = false;

/**
 * 1. Le propriétaire du fichier peut toujours y accéder.
 */
if ($user && (int)$attachment['user_id'] === (int)$user['id']) {
    $canAccess = true;
}

/**
 * 2. Un administrateur peut accéder aux fichiers.
 */
if ($user && ($user['role'] ?? '') === 'admin') {
    $canAccess = true;
}

/**
 * 3. Fichier joint à un message privé :
 * l’expéditeur et le destinataire peuvent y accéder.
 */
if (!$canAccess) {
    if (!$user) {
        $privateMessage = null;
    } else {
    $privateMessage = db_fetch_one(
        "SELECT id
         FROM messages
         WHERE attachment_id = :attachment_id
           AND group_id IS NULL
           AND is_deleted = 0
           AND (
                sender_id = :user_id
                OR receiver_id = :user_id
           )
         LIMIT 1",
        [
            'attachment_id' => $attachmentId,
            'user_id' => $user['id'],
        ]
    );
    }

    if ($privateMessage) {
        $canAccess = true;
    }
}

/**
 * 4. Fichier joint à un message de groupe :
 * les membres du groupe peuvent y accéder.
 */
if (!$canAccess) {
    if (!$user) {
        $groupMessage = null;
    } else {
    $groupMessage = db_fetch_one(
        "SELECT messages.id
         FROM messages
         JOIN group_members ON group_members.group_id = messages.group_id
         WHERE messages.attachment_id = :attachment_id
           AND messages.group_id IS NOT NULL
           AND messages.is_deleted = 0
           AND group_members.user_id = :user_id
         LIMIT 1",
        [
            'attachment_id' => $attachmentId,
            'user_id' => $user['id'],
        ]
    );
    }

    if ($groupMessage) {
        $canAccess = true;
    }
}

/**
 * 5. Fichier joint à un article :
 * - article membres/publié : utilisateurs connectés
 * - article groupe/publié : membres du groupe
 * - article privé/brouillon : auteur ou admin uniquement
 */
if (!$canAccess) {
    ensure_article_attachments_table();

    $linkedArticle = db_fetch_one(
        "SELECT articles.*
         FROM article_attachments
         JOIN articles ON articles.id = article_attachments.article_id
         WHERE article_attachments.attachment_id = :attachment_id
         LIMIT 1",
        ['attachment_id' => $attachmentId]
    );

    if ($linkedArticle) {
        if (
            $user
            && $linkedArticle['status'] === 'published'
            && $linkedArticle['visibility'] === 'members'
        ) {
            $canAccess = true;
        } elseif (
            $user
            && $linkedArticle['status'] === 'published'
            && $linkedArticle['visibility'] === 'group'
            && !empty($linkedArticle['group_id'])
            && user_can_use_group((int)$user['id'], (int)$linkedArticle['group_id'])
        ) {
            $canAccess = true;
        } elseif (
            $user
            && (
                (int)$linkedArticle['author_id'] === (int)$user['id']
                || ($user['role'] ?? '') === 'admin'
            )
        ) {
            $canAccess = true;
        }
    }
}

/**
 * 6. Fichier associe a une annonce :
 * - annonce publique : tout le monde
 * - annonce membres : utilisateurs connectes
 * - annonce groupe : membres du groupe
 * - annonce privee : auteur ou admin uniquement
 */
if (!$canAccess) {
    posts_ensure_attachments_table();

    $linkedPosts = db_fetch_all(
        "SELECT posts.id, posts.author_id, posts.visibility, posts.group_id
         FROM post_attachments
         JOIN posts ON posts.id = post_attachments.post_id
         WHERE post_attachments.attachment_id = :attachment_id
           AND posts.is_published = 1
         ORDER BY posts.created_at DESC
         LIMIT 50",
        ['attachment_id' => $attachmentId]
    );

    foreach ($linkedPosts as $linkedPost) {
        if ((string)$linkedPost['visibility'] === 'public') {
            $canAccess = true;
        } elseif ($user && (string)$linkedPost['visibility'] === 'members') {
            $canAccess = true;
        } elseif (
            $user
            && (
                (int)$linkedPost['author_id'] === (int)$user['id']
                || ($user['role'] ?? '') === 'admin'
            )
        ) {
            $canAccess = true;
        } elseif (
            $user
            && (string)$linkedPost['visibility'] === 'group'
            && !empty($linkedPost['group_id'])
            && user_can_use_group((int)$user['id'], (int)$linkedPost['group_id'])
        ) {
            $canAccess = true;
        }

        if ($canAccess) {
            break;
        }
    }
}

/**
 * 7. Fichier joint à une ressource de protection :
 * les utilisateurs connectés peuvent télécharger les documents actifs.
 */
if (!$canAccess && $user && db_column_exists('protection_resources', 'attachment_id')) {
    $linkedResource = db_fetch_one(
        "SELECT id
         FROM protection_resources
         WHERE attachment_id = :attachment_id
           AND is_active = 1
         LIMIT 1",
        ['attachment_id' => $attachmentId]
    );

    if ($linkedResource) {
        $canAccess = true;
    }
}

if (!$canAccess) {
    if (!$user) {
        require_login();
    }

    http_response_code(403);
    exit('Accès refusé.');
}

$uploadsRoot = upload_storage_realpath();
$filePath = upload_realpath_from_logical((string)$attachment['path']);

if ($uploadsRoot === false || $filePath === false || !is_file($filePath)) {
    http_response_code(404);
    exit('Fichier absent du serveur.');
}

if (strncmp($filePath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(403);
    exit('Chemin de fichier refusé.');
}

$mimeType = (string)($attachment['mime_type'] ?: 'application/octet-stream');

if (preg_match('#^[A-Za-z0-9][A-Za-z0-9.+-]*/[A-Za-z0-9][A-Za-z0-9.+-]*$#', $mimeType) !== 1) {
    $mimeType = 'application/octet-stream';
}

$originalName = (string)($attachment['original_name'] ?: 'fichier');
$safeDownloadName = trim(str_replace(['"', '\\', "\r", "\n"], '', $originalName));
$safeDownloadName = $safeDownloadName !== '' ? $safeDownloadName : 'fichier';
$inlineMimeTypes = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/pdf',
    'text/plain',
    'text/csv',
    'video/mp4',
    'video/quicktime',
    'video/webm',
    'video/ogg',
    'video/x-m4v',
    'audio/mpeg',
    'audio/ogg',
];
$disposition = get_value('download') !== '1' && in_array($mimeType, $inlineMimeTypes, true)
    ? 'inline'
    : 'attachment';
$fileSize = filesize($filePath);

if ($fileSize === false) {
    http_response_code(404);
    exit('Fichier illisible.');
}

header('Content-Type: ' . $mimeType);
header(
    'Content-Disposition: ' . $disposition
    . '; filename="' . $safeDownloadName . '"'
    . "; filename*=UTF-8''" . rawurlencode($originalName)
);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');

$rangeHeader = (string)($_SERVER['HTTP_RANGE'] ?? '');
$start = 0;
$end = $fileSize - 1;

if (preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches) === 1) {
    $rangeStart = $matches[1];
    $rangeEnd = $matches[2];

    if ($rangeStart === '' && $rangeEnd !== '') {
        $suffixLength = min((int)$rangeEnd, $fileSize);
        $start = $fileSize - $suffixLength;
    } elseif ($rangeStart !== '') {
        $start = (int)$rangeStart;

        if ($rangeEnd !== '') {
            $end = min((int)$rangeEnd, $fileSize - 1);
        }
    }

    if ($start < 0 || $start > $end || $start >= $fileSize) {
        header('Content-Range: bytes */' . (string)$fileSize);
        http_response_code(416);
        exit;
    }

    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . (string)$fileSize);
}

$length = $end - $start + 1;
header('Content-Length: ' . (string)$length);

$handle = fopen($filePath, 'rb');

if ($handle === false) {
    http_response_code(404);
    exit('Fichier illisible.');
}

if ($start > 0) {
    fseek($handle, $start);
}

$remaining = $length;

while ($remaining > 0 && !feof($handle)) {
    $chunkSize = min(8192, $remaining);
    $chunk = fread($handle, $chunkSize);

    if ($chunk === false || $chunk === '') {
        break;
    }

    echo $chunk;
    $remaining -= strlen($chunk);
}

fclose($handle);
exit;
