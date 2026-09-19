<?php
declare(strict_types=1);

$GLOBALS['request_guard_test_db'] = new PDO('sqlite::memory:');
$GLOBALS['request_guard_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['request_guard_test_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function db_exec_schema(string $sql): void
{
    $GLOBALS['request_guard_test_db']->exec($sql);
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $statement = $GLOBALS['request_guard_test_db']->prepare($sql);
    $statement->execute($params);

    return $statement;
}

function db_fetch_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();

    return $row === false ? null : $row;
}

require_once __DIR__ . '/../includes/request_guard.php';

$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
$now = 1_700_000_000;
$tests = [];

$first = request_guard_record_not_found('/absente', $now, false);
$second = request_guard_record_not_found('/absente', $now + 1, false);
$tests['Chaque 404 anonyme est comptée'] = $first['attempts'] === 1
    && $first['blocked'] === false
    && $second['attempts'] === 2
    && $second['blocked'] === false;

request_guard_reset_not_found_attempts();
$afterValidRoute = request_guard_record_not_found('/encore-absente', $now + 2, false);
$tests['Une route valide remet la série à zéro'] = $afterValidRoute['attempts'] === 1
    && $afterValidRoute['blocked'] === false;

request_guard_record_not_found('/deuxieme-absente', $now + 3, false);
$third = request_guard_record_not_found('/troisieme-absente', $now + 4, false);
$tests['La troisième 404 anonyme déclenche le 429'] = $third['attempts'] === 3
    && $third['blocked'] === true
    && $third['retry_after'] === REQUEST_GUARD_BLOCK_SECONDS;

request_guard_reset_not_found_attempts();
$tests['Une route valide ne lève pas le bannissement'] = request_guard_anonymous_block_remaining(
    false,
    $now + 5
) === REQUEST_GUARD_BLOCK_SECONDS - 1;

$tests['Une session connectée ignore le bannissement'] = request_guard_anonymous_block_remaining(
    true,
    $now + 5
) === 0;

db_query('DELETE FROM blocked_clients');

request_guard_record_not_found('/absente-1', $now + 6, false);
request_guard_record_not_found('/absente-2', $now + 7, false);
$authenticated = request_guard_record_not_found('/absente-3', $now + 8, true);
$afterAuthenticatedRequest = request_guard_record_not_found('/absente-4', $now + 9, false);
$tests['Un utilisateur connecté ne déclenche jamais le blocage'] = $authenticated === [
    'attempts' => 0,
    'blocked' => false,
    'retry_after' => 0,
] && $afterAuthenticatedRequest['attempts'] === 1
    && $afterAuthenticatedRequest['blocked'] === false;

$failures = 0;
foreach ($tests as $name => $passed) {
    if (!$passed) {
        $failures++;
        fwrite(STDERR, $name . " : échec\n");
    }
}

if ($failures > 0) {
    exit(1);
}

echo count($tests) . " tests du contrôle des URL réussis.\n";
