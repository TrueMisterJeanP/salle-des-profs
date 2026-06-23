<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/attachments.php';

require_login();

$acceptHeader = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$expectsJson = str_contains($acceptHeader, 'application/json')
    || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($expectsJson) {
    require __DIR__ . '/../api/upload.php';
}

if (!is_post()) {
    http_response_code(405);
    exit('Méthode non autorisée.');
}

require_csrf();

$user = current_user();
$uploadType = post_value('type', 'attachment');

try {
    if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new RuntimeException('Aucun fichier reçu.');
    }

    if (!in_array($uploadType, ['attachment', 'avatar'], true)) {
        throw new RuntimeException('Type d’envoi invalide.');
    }

    save_uploaded_attachment($_FILES['file'], (int)$user['id'], $uploadType);
    set_flash('success', 'Fichier envoyé.');
} catch (Throwable $e) {
    set_flash('error', $e->getMessage());
}

redirect(url('attachments.php'));
