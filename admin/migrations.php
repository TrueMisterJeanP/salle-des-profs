<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_setup.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/storage_quota.php';

require_admin();

$errors = [];
$executed = [];

try {
    db_query(
        "CREATE TABLE IF NOT EXISTS migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filename VARCHAR(255) NOT NULL UNIQUE,
            executed_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP
        )"
    );
} catch (Throwable $e) {
    $errors[] = 'Impossible de créer la table migrations : ' . $e->getMessage();
}

if (is_post()) {
    require_csrf();

    $migrationDir = __DIR__ . '/../database/migrations';

    if (!is_dir($migrationDir)) {
        $errors[] = 'Dossier migrations introuvable.';
    }

    if (!$errors) {
        $files = glob($migrationDir . '/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);

            $alreadyDone = db_fetch_one(
                "SELECT id FROM migrations WHERE filename = :filename LIMIT 1",
                ['filename' => $filename]
            );

            if ($alreadyDone) {
                continue;
            }

            try {
                $sql = file_get_contents($file);

                if ($sql === false || trim($sql) === '') {
                    throw new RuntimeException('Fichier vide ou illisible.');
                }

                if (!db_is_mysql()) {
                    db()->beginTransaction();
                }

                db()->exec(db_translate_sql($sql));

                if ($filename === '015_storage_quotas.sql') {
                    storage_quota_ensure_schema();
                }

                if ($filename === '023_user_password_setup.sql') {
                    auth_ensure_password_setup_columns();
                }

                db_query(
                    "INSERT INTO migrations (filename, executed_at)
                     VALUES (:filename, :executed_at)",
                    [
                        'filename' => $filename,
                        'executed_at' => now(),
                    ]
                );

                if (db()->inTransaction()) {
                    db()->commit();
                }

                $executed[] = $filename;
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }

                $errors[] = 'Erreur migration ' . $filename . ' : ' . $e->getMessage();
                break;
            }
        }

        if (!$errors) {
            if ($executed) {
                set_flash('success', 'Migrations exécutées : ' . implode(', ', $executed));
            } else {
                set_flash('success', 'Aucune nouvelle migration à exécuter.');
            }

            redirect(admin_url('migrations.php'));
        }
    }
}

$migrations = [];

try {
    $migrations = db_fetch_all(
        "SELECT *
         FROM migrations
         ORDER BY executed_at DESC"
    );
} catch (Throwable $e) {
    $migrations = [];
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Migrations — <?= e(APP_NAME) ?></title>
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

        <?php if ($errors): ?>
            <div class="flash flash-error">
                <strong>Erreur :</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="card">
            <h1>Migrations</h1>
            <p class="muted">
                Exécute les fichiers SQL présents dans <code>database/migrations/</code>.
            </p>

            <form method="post" action="">
                <?= csrf_field() ?>

                <div class="form-actions">
                    <button type="submit" class="button-primary">
                        Exécuter les migrations
                    </button>

                    <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">
                        Retour admin
                    </a>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Migrations déjà exécutées</h2>

            <?php if (!$migrations): ?>
                <p class="muted">Aucune migration exécutée pour l’instant.</p>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Fichier</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($migrations as $migration): ?>
                                <tr>
                                    <td><?= e($migration['filename']) ?></td>
                                    <td><?= e($migration['executed_at']) ?></td>
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
