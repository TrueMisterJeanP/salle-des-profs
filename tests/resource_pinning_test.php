<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/protection.php';

$tests = [
    'administrateur autorisé' => [true, protection_user_can_pin_resources(['role' => 'admin'])],
    'syndicaliste autorisé' => [true, protection_user_can_pin_resources(['role' => 'syndicate'])],
    'membre non autorisé' => [false, protection_user_can_pin_resources(['role' => 'user'])],
    'visiteur non autorisé' => [false, protection_user_can_pin_resources([])],
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

echo count($tests) . " tests d’épinglage des ressources réussis.\n";
