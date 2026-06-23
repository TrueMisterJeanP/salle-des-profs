<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/storage_quota.php';

function save_uploaded_attachment(array $file, int $userId, string $uploadType = 'attachment'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        throw new RuntimeException(match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier est trop volumineux.',
            UPLOAD_ERR_PARTIAL => 'Le fichier n’a été envoyé que partiellement.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier reçu.',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant sur le serveur.',
            UPLOAD_ERR_CANT_WRITE => 'Impossible d’écrire le fichier sur le serveur.',
            UPLOAD_ERR_EXTENSION => 'Une extension PHP a bloqué l’envoi du fichier.',
            default => 'Erreur inconnue pendant l’envoi du fichier.',
        });
    }

    $originalName = (string)($file['name'] ?? 'fichier');
    $originalExtension = mb_strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION), 'UTF-8');
    $tmpPath = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Fichier temporaire invalide.');
    }

    if ($size <= 0) {
        throw new RuntimeException('Le fichier est vide.');
    }

    if ($size > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('Le fichier dépasse la taille maximale autorisée.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath) ?: 'application/octet-stream';

    if ($uploadType === 'avatar') {
        $allowedMimeTypes = ALLOWED_AVATAR_MIME_TYPES;
        $destinationDir = upload_subdir_path('avatars');
        $publicSubdir = 'avatars';
    } else {
        $allowedMimeTypes = ALLOWED_ATTACHMENT_MIME_TYPES;
        $destinationDir = upload_subdir_path('attachments');
        $publicSubdir = 'attachments';
    }

    $allowedOfficeExtensions = ['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx'];
    $isGenericOfficeFile = in_array($mimeType, ['application/octet-stream', 'application/zip'], true)
        && in_array($originalExtension, $allowedOfficeExtensions, true);

    if (!in_array($mimeType, $allowedMimeTypes, true) && !$isGenericOfficeFile) {
        throw new RuntimeException('Type de fichier non autorisé : ' . $mimeType);
    }

    ensure_dir($destinationDir);

    $extension = match (true) {
        in_array($mimeType, ['application/octet-stream', 'application/zip'], true)
            && in_array($originalExtension, $allowedOfficeExtensions, true) => $originalExtension,
        $mimeType === 'application/zip' && in_array($originalExtension, ['docx', 'pptx', 'xlsx'], true) => $originalExtension,
        $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        $mimeType === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        $mimeType === 'application/msword' => 'doc',
        $mimeType === 'application/vnd.ms-powerpoint' => 'ppt',
        $mimeType === 'application/vnd.ms-excel' => 'xls',
        $mimeType === 'image/jpeg' => 'jpg',
        $mimeType === 'image/png' => 'png',
        $mimeType === 'image/gif' => 'gif',
        $mimeType === 'image/webp' => 'webp',
        $mimeType === 'application/pdf' => 'pdf',
        $mimeType === 'text/plain' => 'txt',
        $mimeType === 'text/csv' => 'csv',
        $mimeType === 'application/zip' => 'zip',
        $mimeType === 'application/mp4' => 'mp4',
        $mimeType === 'video/mp4' => 'mp4',
        $mimeType === 'video/quicktime' => 'mov',
        $mimeType === 'video/webm' => 'webm',
        $mimeType === 'video/ogg' => 'ogv',
        $mimeType === 'video/x-m4v' => 'm4v',
        $mimeType === 'audio/mpeg' => 'mp3',
        $mimeType === 'audio/ogg' => 'ogg',
        default => 'bin',
    };

    $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
    $destinationPath = rtrim($destinationDir, '/\\') . DIRECTORY_SEPARATOR . $safeName;
    $relativePath = upload_logical_path($publicSubdir, $safeName);
    $pdo = db();
    $moved = false;

    storage_quota_ensure_schema();

    try {
        storage_quota_begin_write_transaction($pdo);
        storage_quota_reserve_for_upload($userId, $size);

        if (!move_uploaded_file($tmpPath, $destinationPath)) {
            throw new RuntimeException('Impossible d’enregistrer le fichier.');
        }

        $moved = true;

        $attachmentId = db_insert(
            "INSERT INTO attachments
                (user_id, filename, original_name, mime_type, size, path, created_at)
             VALUES
                (:user_id, :filename, :original_name, :mime_type, :size, :path, :created_at)",
            [
                'user_id' => $userId,
                'filename' => $safeName,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size' => $size,
                'path' => $relativePath,
                'created_at' => now(),
            ]
        );

        if ($uploadType === 'avatar') {
            db_query(
                "UPDATE users
                 SET avatar = :avatar,
                     updated_at = :updated_at
                 WHERE id = :id",
                [
                    'avatar' => $relativePath,
                    'updated_at' => now(),
                    'id' => $userId,
                ]
            );
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($moved && is_file($destinationPath)) {
            unlink($destinationPath);
        }

        throw $e;
    }

    return [
        'id' => $attachmentId,
        'filename' => $safeName,
        'original_name' => $originalName,
        'mime_type' => $mimeType,
        'size' => $size,
        'path' => $relativePath,
    ];
}

function ensure_article_attachments_table(): void
{
    static $done = false;

    if ($done) {
        return;
    }

    db_exec_schema(
        "CREATE TABLE IF NOT EXISTS article_attachments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            article_id INTEGER NOT NULL,
            attachment_id INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(article_id, attachment_id),
            FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,
            FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
        )"
    );

    db()->exec(
        "CREATE INDEX IF NOT EXISTS idx_article_attachments_article
         ON article_attachments(article_id)"
    );

    db()->exec(
        "CREATE INDEX IF NOT EXISTS idx_article_attachments_attachment
         ON article_attachments(attachment_id)"
    );

    $done = true;
}

function user_attachment_options(int $userId, int $limit = 100): array
{
    return db_fetch_all(
        "SELECT id, original_name, mime_type, size, created_at
         FROM attachments
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT :limit",
        [
            'user_id' => $userId,
            'limit' => $limit,
        ]
    );
}

function article_attachment_ids(int $articleId): array
{
    ensure_article_attachments_table();

    $rows = db_fetch_all(
        "SELECT attachment_id
         FROM article_attachments
         WHERE article_id = :article_id",
        ['article_id' => $articleId]
    );

    return array_map(static fn (array $row): int => (int)$row['attachment_id'], $rows);
}

function article_attachments(int $articleId): array
{
    ensure_article_attachments_table();

    return db_fetch_all(
        "SELECT attachments.*
         FROM article_attachments
         JOIN attachments ON attachments.id = article_attachments.attachment_id
         WHERE article_attachments.article_id = :article_id
         ORDER BY article_attachments.created_at ASC, attachments.original_name ASC",
        ['article_id' => $articleId]
    );
}

function article_attachment_options(int $articleId): array
{
    ensure_article_attachments_table();

    return db_fetch_all(
        "SELECT attachments.id,
                attachments.original_name,
                attachments.mime_type,
                attachments.size,
                article_attachments.created_at AS attached_at
         FROM article_attachments
         JOIN attachments ON attachments.id = article_attachments.attachment_id
         WHERE article_attachments.article_id = :article_id
         ORDER BY article_attachments.created_at ASC, attachments.original_name ASC",
        ['article_id' => $articleId]
    );
}

function remove_article_attachment(int $articleId, int $attachmentId): void
{
    ensure_article_attachments_table();

    db_query(
        "DELETE FROM article_attachments
         WHERE article_id = :article_id
           AND attachment_id = :attachment_id",
        [
            'article_id' => $articleId,
            'attachment_id' => $attachmentId,
        ]
    );
}

function sync_article_attachments(int $articleId, array $attachmentIds, int $userId): void
{
    ensure_article_attachments_table();

    $attachmentIds = array_values(array_unique(array_filter(array_map('intval', $attachmentIds))));

    db_query(
        "DELETE FROM article_attachments WHERE article_id = :article_id",
        ['article_id' => $articleId]
    );

    if (!$attachmentIds) {
        return;
    }

    $placeholders = [];
    $params = ['user_id' => $userId];

    foreach ($attachmentIds as $index => $attachmentId) {
        $key = 'attachment_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $attachmentId;
    }

    $rows = db_fetch_all(
        "SELECT id
         FROM attachments
         WHERE user_id = :user_id
           AND id IN (" . implode(',', $placeholders) . ")",
        $params
    );

    $allowedAttachmentIds = array_fill_keys(
        array_map(static fn (array $row): int => (int)$row['id'], $rows),
        true
    );

    foreach ($attachmentIds as $attachmentId) {
        if (!isset($allowedAttachmentIds[$attachmentId])) {
            continue;
        }

        db_insert_ignore('article_attachments', [
            'article_id' => $articleId,
            'attachment_id' => $attachmentId,
            'created_at' => now(),
        ]);
    }
}
