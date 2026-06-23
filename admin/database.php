<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/database_migration.php';

require_admin();

$errors = [];
$copyReport = [];
$config = database_config();
$mysql = $config['mysql'] ?? database_default_config()['mysql'];

if (is_post()) {
    require_csrf();

    $action = post_value('action');

    if (in_array($action, ['save_mysql', 'test_mysql', 'copy_to_mysql', 'switch_mysql'], true)) {
        $postedMysql = database_mysql_config_from_post();

        if (($postedMysql['password'] ?? '') === '' && !empty($mysql['password'])) {
            $postedMysql['password'] = $mysql['password'];
        }

        if (($postedMysql['database'] ?? '') === '') {
            $errors[] = 'Le nom de la base MariaDB est obligatoire.';
        }

        if (!$errors) {
            $mysql = $postedMysql;
        }
    }

    if (!$errors && $action === 'save_mysql') {
        $config['mysql'] = $mysql;
        database_write_config($config);
        set_flash('success', 'Configuration MariaDB enregistrée.');
        redirect(admin_url('database.php'));
    }

    if (!$errors && $action === 'test_mysql') {
        try {
            database_mysql_pdo($mysql);
            set_flash('success', 'Connexion MariaDB réussie.');
            $config['mysql'] = $mysql;
            database_write_config($config);
            redirect(admin_url('database.php'));
        } catch (Throwable $e) {
            $errors[] = 'Connexion MariaDB impossible : ' . $e->getMessage();
        }
    }

    if (!$errors && $action === 'copy_to_mysql') {
        try {
            $copyReport = database_copy_sqlite_to_mysql($mysql);
            $config['mysql'] = $mysql;
            database_write_config($config);
            set_flash('success', 'Contenu SQLite copié vers MariaDB.');
            $_SESSION['database_copy_report'] = $copyReport;
            redirect(admin_url('database.php'));
        } catch (Throwable $e) {
            $errors[] = 'Copie impossible : ' . $e->getMessage();
        }
    }

    if (!$errors && $action === 'switch_mysql') {
        try {
            if (!database_mysql_has_users($mysql)) {
                throw new RuntimeException('La base MariaDB ne contient pas encore les tables et utilisateurs du site.');
            }

            $config['mysql'] = $mysql;
            $config['active'] = 'mysql';
            database_write_config($config);
            set_flash('success', 'Le site utilise maintenant MariaDB.');
            redirect(admin_url('database.php'));
        } catch (Throwable $e) {
            $errors[] = 'Bascule impossible : ' . $e->getMessage();
        }
    }

    if ($action === 'switch_sqlite') {
        if (!is_file(DATABASE_PATH)) {
            $errors[] = 'Base SQLite introuvable.';
        } else {
            $config['active'] = 'sqlite';
            database_write_config($config);
            set_flash('success', 'Le site utilise maintenant SQLite.');
            redirect(admin_url('database.php'));
        }
    }
}

$config = database_config();
$mysql = $mysql ?: ($config['mysql'] ?? database_default_config()['mysql']);
$activeDriver = db_driver();
$sqliteInstalled = is_file(DATABASE_PATH);
$copyReport = $_SESSION['database_copy_report'] ?? $copyReport;
unset($_SESSION['database_copy_report']);

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Base de données — Administration — <?= e(APP_NAME) ?></title>
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
            <h1>Base de données</h1>
            <p class="muted">
                Moteur actif : <strong><?= e($activeDriver === 'mysql' ? 'MariaDB' : 'SQLite') ?></strong>.
                L’installation initiale reste en SQLite, puis cette page permet de copier les données vers MariaDB et de choisir le moteur utilisé.
            </p>

            <div class="form-actions">
                <a class="button-secondary" href="<?= e(admin_url('index.php')) ?>">Retour admin</a>
            </div>
        </section>

        <section class="grid grid-2">
            <article class="card">
                <h2>SQLite</h2>
                <p class="muted">
                    Fichier : <code class="database-path"><?= e(DATABASE_PATH) ?></code>
                </p>
                <p>
                    État : <?= $sqliteInstalled ? 'présent' : 'introuvable' ?>
                </p>

                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="switch_sqlite">
                    <div class="form-actions">
                        <button type="submit" class="button-primary" <?= $activeDriver === 'sqlite' || !$sqliteInstalled ? 'disabled' : '' ?>>
                            Utiliser SQLite
                        </button>
                    </div>
                </form>
            </article>

            <article class="card">
                <h2>MariaDB</h2>

                <form method="post" action="">
                    <?= csrf_field() ?>

                    <label for="mysql_host">Serveur</label>
                    <input type="text" id="mysql_host" name="mysql_host" value="<?= e((string)($mysql['host'] ?? '127.0.0.1')) ?>" required>

                    <label for="mysql_port">Port</label>
                    <input type="number" id="mysql_port" name="mysql_port" value="<?= e((string)($mysql['port'] ?? 3306)) ?>" min="1" max="65535" required>

                    <label for="mysql_database">Base</label>
                    <input type="text" id="mysql_database" name="mysql_database" value="<?= e((string)($mysql['database'] ?? '')) ?>" required>

                    <label for="mysql_username">Utilisateur</label>
                    <input type="text" id="mysql_username" name="mysql_username" value="<?= e((string)($mysql['username'] ?? '')) ?>" autocomplete="username">

                    <label for="mysql_password">Mot de passe</label>
                    <input
                        type="password"
                        id="mysql_password"
                        name="mysql_password"
                        value=""
                        autocomplete="new-password"
                        placeholder="<?= !empty($mysql['password']) ? 'Mot de passe enregistré' : '' ?>"
                    >
                    <p class="muted">Laissez vide pour conserver le mot de passe MariaDB actuel.</p>

                    <label for="mysql_charset">Jeu de caractères</label>
                    <input type="text" id="mysql_charset" name="mysql_charset" value="<?= e((string)($mysql['charset'] ?? 'utf8mb4')) ?>" required>

                    <div class="form-actions">
                        <button type="submit" class="button-secondary" name="action" value="save_mysql">
                            Enregistrer
                        </button>
                        <button type="submit" class="button-secondary" name="action" value="test_mysql" formnovalidate>
                            Tester
                        </button>
                        <button type="submit" class="button-primary" name="action" value="copy_to_mysql" onclick="return confirm('Copier tout le contenu SQLite vers MariaDB ? Les tables MariaDB existantes seront vidées.');">
                            Copier SQLite vers MariaDB
                        </button>
                        <button type="submit" class="button-primary" name="action" value="switch_mysql" onclick="return confirm('Basculer le site vers MariaDB ?');" <?= $activeDriver === 'mysql' ? 'disabled' : '' ?>>
                            Utiliser MariaDB
                        </button>
                    </div>
                </form>
            </article>
        </section>

        <?php if ($copyReport): ?>
            <section class="card">
                <h2>Dernière copie</h2>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Table</th>
                                <th>Lignes copiées</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($copyReport as $table => $count): ?>
                                <tr>
                                    <td><?= e((string)$table) ?></td>
                                    <td><?= e((string)$count) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
