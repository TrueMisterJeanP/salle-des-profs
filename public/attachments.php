<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/pagination.php';

require_login();

$user = current_user();
$errors = [];
$uploadSizeLimit = effective_upload_size_limit();

if (is_post()) {
    require_csrf();

    $action = post_value('action');

    try {
        if ($action === 'delete_attachment') {
            $attachmentId = (int)post_value('attachment_id', '0');

            if ($attachmentId <= 0) {
                $errors[] = 'Fichier invalide.';
            } else {
                $attachment = db_fetch_one(
                    "SELECT *
                     FROM attachments
                     WHERE id = :id
                       AND user_id = :user_id
                     LIMIT 1",
                    [
                        'id' => $attachmentId,
                        'user_id' => $user['id'],
                    ]
                );

                if (!$attachment) {
                    $errors[] = 'Fichier introuvable.';
                } else {
                    ensure_article_attachments_table();

                    $uploadsRoot = upload_storage_realpath();
                    $filePath = upload_realpath_from_logical((string)$attachment['path']);
                    $canDeleteFile = $uploadsRoot !== false
                        && $filePath !== false
                        && is_file($filePath)
                        && strncmp($filePath, $uploadsRoot . DIRECTORY_SEPARATOR, strlen($uploadsRoot . DIRECTORY_SEPARATOR)) === 0;

                    $pdo = db();
                    storage_quota_ensure_schema();
                    $pdo->beginTransaction();

                    try {
                        db_query(
                            "UPDATE messages
                             SET attachment_id = NULL,
                                 updated_at = :updated_at
                             WHERE attachment_id = :attachment_id",
                            [
                                'attachment_id' => $attachmentId,
                                'updated_at' => now(),
                            ]
                        );

                        db_query(
                            "DELETE FROM article_attachments
                             WHERE attachment_id = :attachment_id",
                            ['attachment_id' => $attachmentId]
                        );

                        db_query(
                            "UPDATE users
                             SET avatar = NULL,
                                 updated_at = :updated_at
                             WHERE id = :user_id
                               AND avatar = :avatar",
                            [
                                'avatar' => $attachment['path'],
                                'updated_at' => now(),
                                'user_id' => $user['id'],
                            ]
                        );

                        db_query(
                            "DELETE FROM attachments
                             WHERE id = :id
                               AND user_id = :user_id",
                            [
                                'id' => $attachmentId,
                                'user_id' => $user['id'],
                            ]
                        );

                        storage_quota_release((int)$user['id'], (int)$attachment['size']);

                        if ($pdo->inTransaction()) {
                            $pdo->commit();
                        }
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        throw $e;
                    }

                    if ($canDeleteFile && !unlink($filePath)) {
                        set_flash('warning', 'Le fichier a été retiré de la plateforme, mais le fichier physique n’a pas pu être supprimé.');
                    } else {
                        set_flash('success', 'Fichier supprimé.');
                    }

                    redirect(url('attachments.php'));
                }
            }
        }

        if ($action !== 'delete_attachment') {
            $errors[] = 'Action inconnue.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Erreur : ' . $e->getMessage();
    }
}

$page = current_page();
$perPage = 10;
$offset = pagination_offset($page, $perPage);

$totalAttachments = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM attachments
     WHERE user_id = :current_user_id",
    ['current_user_id' => $user['id']]
)['total'] ?? 0);

$totalPages = total_pages($totalAttachments, $perPage);
$currentUserQuota = db_fetch_one(
    "SELECT used_storage_bytes, quota_storage_bytes
     FROM users
     WHERE id = :user_id
     LIMIT 1",
    ['user_id' => $user['id']]
) ?: ['used_storage_bytes' => 0, 'quota_storage_bytes' => null];
$effectiveUserQuota = storage_quota_effective_user_quota(
    $currentUserQuota['quota_storage_bytes'] !== null ? (int)$currentUserQuota['quota_storage_bytes'] : null
);

$attachments = db_fetch_all(
    "SELECT attachments.*
     FROM attachments
     WHERE attachments.user_id = :current_user_id
     ORDER BY attachments.created_at DESC
     LIMIT :limit OFFSET :offset",
    [
        'current_user_id' => $user['id'],
        'limit' => $perPage,
        'offset' => $offset,
    ]
);

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Fichiers — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="container page">
        <?php foreach ($flashes as $flash): ?>
            <div class="<?= e(flash_class($flash['type'])) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($errors as $error): ?>
            <div class="<?= e(flash_class('error')) ?>">
                <?= e($error) ?>
            </div>
        <?php endforeach; ?>

        <section class="card">
            <h1>Mes fichiers</h1>
            <p class="muted">
                Fichiers que vous avez envoyés sur la plateforme.
                Stockage utilisé : <?= e(human_file_size((int)$currentUserQuota['used_storage_bytes'])) ?>
                / <?= e($effectiveUserQuota !== null ? human_file_size($effectiveUserQuota) : 'illimité') ?>.
            </p>
        </section>

        <section class="card">
            <h2>Envoyer un fichier</h2>

            <form
                id="upload-form"
                action="upload.php"
                method="post"
                enctype="multipart/form-data"
                data-max-upload-size="<?= (int)$uploadSizeLimit ?>"
            >
                <?= csrf_field() ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= (int)$uploadSizeLimit ?>">

                <label for="file">Fichier</label>
                <input
                    type="file"
                    id="file"
                    name="file"
                    required
                >
                <p class="meta">Taille maximale : <?= e(human_file_size($uploadSizeLimit)) ?>.</p>

                <input type="hidden" name="type" value="attachment">

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        Envoyer
                    </button>
                </div>
            </form>

            <div id="upload-result" class="upload-result"></div>
        </section>

        <section class="card">
            <h2>Liste des fichiers</h2>

            <?php if (!$attachments): ?>
                <p class="muted">Aucun fichier envoyé pour l’instant.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Nom</th>
                                <th>Type</th>
                                <th>Taille</th>
                                <th>Date</th>
                                <th>Lien</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attachments as $attachment): ?>
                                <?php
                                    $attachmentId = (int)$attachment['id'];
                                    $filePath = 'file.php?id=' . $attachmentId;
                                    $fileUrl = url($filePath);
                                    $originalName = (string)$attachment['original_name'];
                                    $mimeType = (string)$attachment['mime_type'];
                                    $isImagePreview = str_starts_with($mimeType, 'image/');
                                    $extension = mb_strtoupper((string)pathinfo($originalName, PATHINFO_EXTENSION), 'UTF-8');
                                    $markdownLabel = str_replace(['[', ']', "\r", "\n"], ['', '', ' ', ' '], $originalName);
                                    $markdownLink = ($isImagePreview ? '!' : '') . '[' . $markdownLabel . '](' . $filePath . ')';
                                ?>
                                <tr>
                                    <td>
                                        <div class="attachment-list-file">
                                            <div class="attachment-list-file-copy">
                                                <strong><?= e($originalName) ?></strong>
                                            </div>
                                            <a
                                                class="attachment-list-preview"
                                                href="<?= e($fileUrl) ?>"
                                                target="_blank"
                                                rel="noopener"
                                                aria-label="Ouvrir <?= e($originalName) ?>"
                                            >
                                                <?php if ($isImagePreview): ?>
                                                    <img src="<?= e($fileUrl) ?>" alt="">
                                                <?php else: ?>
                                                    <span><?= e($extension !== '' ? $extension : 'FILE') ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </div>
                                    </td>
                                    <td><?= e($mimeType) ?></td>
                                    <td><?= e(human_file_size((int)$attachment['size'])) ?></td>
                                    <td><?= e($attachment['created_at']) ?></td>
                                    <td>
                                        <a
                                            class="button-secondary"
                                            href="<?= e($fileUrl) ?>"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            Ouvrir
                                        </a>
                                        <label class="attachment-link-label" for="attachment_markdown_<?= $attachmentId ?>">Lien Markdown</label>
                                        <input
                                            class="attachment-link-input"
                                            type="text"
                                            id="attachment_markdown_<?= $attachmentId ?>"
                                            value="<?= e($markdownLink) ?>"
                                            readonly
                                        >
                                    </td>
                                    <td>
                                        <form
                                            method="post"
                                            action="<?= e(url('attachments.php')) ?>"
                                            class="inline-form"
                                            onsubmit="return confirm('Supprimer ce fichier ? Cette action est irréversible.');"
                                        >
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_attachment">
                                            <input type="hidden" name="attachment_id" value="<?= $attachmentId ?>">
                                            <button type="submit" class="button-danger">
                                                Supprimer
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            
            <?php if ($totalPages > 1): ?>
                <section class="card">
                    <?= render_pagination($page, $totalPages) ?>
                </section>
            <?php endif; ?>
            
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>

    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
