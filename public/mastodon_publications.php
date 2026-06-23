<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_login();

$user = current_user();

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

$publications = [];

try {
    $publications = db_fetch_all(
        "SELECT *
         FROM mastodon_publications
         WHERE user_id = :user_id
         ORDER BY created_at DESC
         LIMIT 100",
        ['user_id' => $user['id']]
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
    <title>Publications Mastodon — <?= e(APP_NAME) ?></title>
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

        <section class="card">
            <h1>Publications Mastodon</h1>
            <p class="muted">
                Historique des contenus envoyés vers votre compte Mastodon.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(url('mastodon_settings.php')) ?>">
                    Paramètres Mastodon
                </a>
                <a class="button-secondary" href="<?= e(url('feed.php')) ?>">
                    Mes annonces
                </a>
                <a class="button-secondary" href="<?= e(url('articles.php')) ?>">
                    Mes articles
                </a>
            </div>
        </section>

        <section class="card">
            <h2>Historique</h2>

            <?php if (!$publications): ?>
                <p class="muted">Aucune publication Mastodon pour l’instant.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>ID contenu</th>
                                <th>Date</th>
                                <th>Mastodon</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($publications as $publication): ?>
                                <tr>
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
