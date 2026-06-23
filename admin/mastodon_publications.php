<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_admin();

$errors = [];

try {
    db_query(
        "CREATE TABLE IF NOT EXISTS mastodon_publications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            target_type TEXT NOT NULL,
            target_id INTEGER NOT NULL,
            mastodon_url TEXT,
            mastodon_id TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, target_type, target_id),
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );
} catch (Throwable $e) {
    // La page affichera simplement une liste vide si la table n'est pas disponible.
}

if (is_post()) {
    require_csrf();

    $action = post_value('action');
    $publicationId = (int)post_value('publication_id', '0');

    if ($publicationId <= 0) {
        $errors[] = 'Publication invalide.';
    }

    if (!$errors && $action !== 'delete') {
        $errors[] = 'Action inconnue.';
    }

    if (!$errors) {
        $publication = db_fetch_one(
            "SELECT id FROM mastodon_publications WHERE id = :id LIMIT 1",
            ['id' => $publicationId]
        );

        if (!$publication) {
            $errors[] = 'Historique Mastodon introuvable.';
        }
    }

    if (!$errors) {
        try {
            db_query(
                "DELETE FROM mastodon_publications WHERE id = :id",
                ['id' => $publicationId]
            );

            set_flash('success', 'Historique Mastodon effacé.');
            redirect(admin_url('mastodon_publications.php'));
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

$publications = [];

try {
    $publications = db_fetch_all(
        "SELECT mastodon_publications.*,
                users.username,
                users.display_name,
                users.email
         FROM mastodon_publications
         JOIN users ON users.id = mastodon_publications.user_id
         ORDER BY mastodon_publications.created_at DESC
         LIMIT 300"
    );
} catch (Throwable $e) {
    $publications = [];
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Publications Mastodon — Administration — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/../public/assets/app.css')) ?>">
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
            <div class="flash flash-error">
                <?= e($error) ?>
            </div>
        <?php endforeach; ?>

        <section class="card">
            <h1>Publications Mastodon</h1>
            <p class="muted">
                Historique global des contenus envoyés vers Mastodon.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">
                    Retour admin
                </a>
                <a class="button-secondary" href="<?= e(admin_url('migrations.php')) ?>">
                    Migrations
                </a>
            </div>
        </section>

        <section class="card">
            <h2>Historique global</h2>

            <?php if (!$publications): ?>
                <p class="muted">Aucune publication Mastodon pour l’instant.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Utilisateur</th>
                                <th>Type</th>
                                <th>ID contenu</th>
                                <th>Date</th>
                                <th>Mastodon</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($publications as $publication): ?>
                                <tr>
                                    <td>
                                        <?= e($publication['display_name'] ?: $publication['username']) ?><br>
                                        <span class="meta"><?= e($publication['email']) ?></span>
                                    </td>
                                    <td><?= e(content_type_label($publication['target_type'])) ?></td>
                                    <td>#<?= e((string)$publication['target_id']) ?></td>
                                    <td><?= e($publication['created_at']) ?></td>
                                    <td>
                                        <?php if (!empty($publication['mastodon_url'])): ?>
                                            <a
                                                class="button-secondary"
                                                href="<?= e($publication['mastodon_url']) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                Ouvrir
                                            </a>
                                        <?php else: ?>
                                            <span class="muted">
                                                Identifiant Mastodon : <?= e($publication['mastodon_id'] ?: '-') ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="admin-actions">
                                            <form method="post" action="" onsubmit="return confirm('Effacer cet historique Mastodon ? La publication déjà envoyée sur Mastodon ne sera pas supprimée.');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="publication_id" value="<?= e((string)$publication['id']) ?>">
                                                <button type="submit" class="button-danger">Effacer</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
