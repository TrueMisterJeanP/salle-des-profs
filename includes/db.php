<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function database_config_path(): string
{
    return ROOT_PATH . '/database/database.config.php';
}

function database_default_config(): array
{
    return [
        'active' => 'sqlite',
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
    ];
}

function database_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $config = database_default_config();
    $path = database_config_path();

    if (is_file($path)) {
        $loaded = require $path;

        if (is_array($loaded)) {
            $config = array_replace_recursive($config, $loaded);
        }
    }

    if (!in_array($config['active'] ?? 'sqlite', ['sqlite', 'mysql'], true)) {
        $config['active'] = 'sqlite';
    }

    return $config;
}

function database_write_config(array $config): void
{
    $config = array_replace_recursive(database_default_config(), $config);
    $config['active'] = in_array($config['active'] ?? 'sqlite', ['sqlite', 'mysql'], true)
        ? $config['active']
        : 'sqlite';

    $dir = dirname(database_config_path());

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $php = "<?php\nreturn " . var_export($config, true) . ";\n";

    if (file_put_contents(database_config_path(), $php, LOCK_EX) === false) {
        throw new RuntimeException('Impossible d’écrire la configuration de base de données.');
    }
}

function db_driver(): string
{
    return (string)(database_config()['active'] ?? 'sqlite');
}

function db_is_mysql(): bool
{
    return db_driver() === 'mysql';
}

/**
 * Retourne une instance PDO connectée à la base SQLite.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = database_config();

    if (($config['active'] ?? 'sqlite') === 'mysql') {
        $mysql = $config['mysql'] ?? [];
        $host = (string)($mysql['host'] ?? '127.0.0.1');
        $port = (int)($mysql['port'] ?? 3306);
        $database = (string)($mysql['database'] ?? '');
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($mysql['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';

        if ($database === '') {
            throw new RuntimeException('Base MariaDB non configurée.');
        }

        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=' . $charset;
        $pdo = new PDO($dsn, (string)($mysql['username'] ?? ''), (string)($mysql['password'] ?? ''));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        $pdo->exec('SET NAMES ' . $charset);

        return $pdo;
    }

    $databaseDir = dirname(DATABASE_PATH);

    if (!is_dir($databaseDir)) {
        mkdir($databaseDir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . DATABASE_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    /**
     * Active les contraintes de clés étrangères SQLite.
     */
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

/**
 * Vérifie si la base de données semble déjà installée.
 */
function database_is_installed(): bool
{
    if (db_driver() === 'sqlite' && !file_exists(DATABASE_PATH)) {
        return false;
    }

    try {
        if (db_is_mysql()) {
            $stmt = db()->query("SHOW TABLES LIKE 'users'");
        } else {
            $stmt = db()->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
        }

        if (!$stmt->fetch()) {
            return false;
        }

        $row = db_fetch_one("SELECT COUNT(*) AS total FROM users");

        return (int)($row['total'] ?? 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Exécute un fichier SQL complet.
 */
function execute_sql_file(string $path): void
{
    if (!file_exists($path)) {
        throw new RuntimeException("Fichier SQL introuvable : " . $path);
    }

    $sql = file_get_contents($path);

    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException("Fichier SQL vide ou illisible : " . $path);
    }

    db()->exec(db_translate_sql($sql));
}

function db_translate_sql(string $sql): string
{
    if (!db_is_mysql()) {
        return $sql;
    }

    $sql = preg_replace('/^\s*PRAGMA\s+foreign_keys\s*=\s*ON\s*;\s*/mi', '', $sql) ?? $sql;
    $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER PRIMARY KEY AUTO_INCREMENT', $sql);
    $sql = str_replace('INSERT OR IGNORE INTO', 'INSERT IGNORE INTO', $sql);
    $sql = str_replace('COLLATE NOCASE', '', $sql);

    return $sql;
}

function db_exec_schema(string $sql): void
{
    db()->exec(db_translate_sql($sql));
}

function db_insert_ignore(string $table, array $data): void
{
    if (!$data) {
        return;
    }

    $columns = array_keys($data);
    $quotedColumns = array_map(static fn (string $column): string => $column, $columns);
    $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
    $verb = db_is_mysql() ? 'INSERT IGNORE' : 'INSERT OR IGNORE';

    db_query(
        $verb . ' INTO ' . $table . ' (' . implode(', ', $quotedColumns) . ')
         VALUES (' . implode(', ', $placeholders) . ')',
        $data
    );
}

function db_save_setting_value(string $key, string $value): void
{
    if (db_is_mysql()) {
        db_query(
            "INSERT INTO settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [
                'setting_key' => $key,
                'setting_value' => $value,
            ]
        );

        return;
    }

    db_query(
        "INSERT INTO settings (setting_key, setting_value)
         VALUES (:setting_key, :setting_value)
         ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value",
        [
            'setting_key' => $key,
            'setting_value' => $value,
        ]
    );
}

function db_column_exists(string $table, string $column): bool
{
    if (db_is_mysql()) {
        $row = db_fetch_one(
            "SELECT COUNT(*) AS total
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column",
            [
                'table' => $table,
                'column' => $column,
            ]
        );

        return (int)($row['total'] ?? 0) > 0;
    }

    $stmt = db()->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")');
    $columns = $stmt ? $stmt->fetchAll() : [];

    foreach ($columns as $info) {
        if (($info['name'] ?? '') === $column) {
            return true;
        }
    }

    return false;
}

/**
 * Exécute une requête préparée et retourne le statement.
 */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare(db_translate_sql($sql));

    foreach ($params as $key => $value) {
        $param = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');

        if (is_int($value)) {
            $stmt->bindValue($param, $value, PDO::PARAM_INT);
        } elseif ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($param, $value, PDO::PARAM_STR);
        }
    }

    $stmt->execute();

    return $stmt;
}

/**
 * Récupère une seule ligne.
 */
function db_fetch_one(string $sql, array $params = []): ?array
{
    $stmt = db_query($sql, $params);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Récupère plusieurs lignes.
 */
function db_fetch_all(string $sql, array $params = []): array
{
    $stmt = db_query($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Insère une ligne et retourne l'identifiant généré.
 */
function db_insert(string $sql, array $params = []): int
{
    db_query($sql, $params);
    return (int) db()->lastInsertId();
}
