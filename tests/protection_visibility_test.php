<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protection.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    "CREATE TABLE group_members (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL);
     CREATE TABLE protection_events (
        id INTEGER PRIMARY KEY,
        visibility TEXT NOT NULL,
        group_id INTEGER,
        created_by INTEGER NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 1
     );
     INSERT INTO group_members (group_id, user_id) VALUES (7, 10);
     INSERT INTO protection_events (id, visibility, group_id, created_by) VALUES
        (1, 'public', NULL, 1),
        (2, 'members', NULL, 1),
        (3, 'private', NULL, 10),
        (4, 'private', NULL, 20),
        (5, 'group', 7, 20),
        (6, 'group', 8, 20);"
);

$fetchIds = static function (array $user) use ($pdo): array {
    $where = protection_user_visibility_where($user, 'e');
    $rows = $pdo->query(
        "SELECT e.id
         FROM protection_events e
         WHERE e.is_active = 1 AND $where
         ORDER BY e.id"
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_map('intval', $rows);
};

$tests = [
    'membre' => [[1, 2, 3, 5], $fetchIds(['id' => 10, 'role' => 'user'])],
    'administrateur' => [[1, 2, 3, 4, 5, 6], $fetchIds(['id' => 1, 'role' => 'admin'])],
];

$failures = 0;

foreach ($tests as $name => [$expected, $actual]) {
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, sprintf(
            "%s\nAttendu : %s\nObtenu  : %s\n\n",
            $name,
            json_encode($expected),
            json_encode($actual)
        ));
    }
}

if ($failures > 0) {
    exit(1);
}

echo count($tests) . " tests de visibilité réussis.\n";
