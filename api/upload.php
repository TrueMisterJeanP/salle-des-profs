<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/attachments.php';

require_login();

$user = current_user();

if (!is_post()) {
    json_response([
        'success' => false,
        'error' => 'Méthode non autorisée.',
    ], 405);
}

require_csrf();

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response([
        'success' => false,
        'error' => 'Aucun fichier reçu.',
    ], 400);
}

$uploadType = post_value('type', 'attachment');

if (!in_array($uploadType, ['attachment', 'avatar'], true)) {
    json_response([
        'success' => false,
        'error' => 'Type d’envoi invalide.',
    ], 400);
}

try {
    $attachment = save_uploaded_attachment($_FILES['file'], (int)$user['id'], $uploadType);

    json_response([
        'success' => true,
        'attachment_id' => $attachment['id'],
        'filename' => $attachment['filename'],
        'original_name' => $attachment['original_name'],
        'mime_type' => $attachment['mime_type'],
        'size' => $attachment['size'],
        'path' => $attachment['path'],
        'url' => url('file.php?id=' . (int)$attachment['id']),
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'error' => $e->getMessage(),
    ], $e instanceof RuntimeException ? 400 : 500);
}
