<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/password_recovery.php';

$tests = [];

$tests['Réponse : casse et accents ignorés'] = password_recovery_normalize_answer('  SAINT-ÉTIENNE ') === password_recovery_normalize_answer('saint-etienne');
$tests['Réponse : espaces multiples réduits'] = password_recovery_normalize_answer("Le   Puy\ten Velay") === 'le puy en velay';
$tests['Réponse différente refusée'] = password_recovery_normalize_answer('Lyon') !== password_recovery_normalize_answer('Saint-Étienne');

$envBackup = getenv('APP_BASE_URL');
$serverBackup = $_SERVER;
$_SERVER['HTTP_HOST'] = 'pirate.example';
putenv('APP_BASE_URL=https://salledesprofs.example/public');
$tests['Lien construit depuis APP_BASE_URL, pas depuis Host'] = password_recovery_url('abc') === 'https://salledesprofs.example/public/forgot_password.php?token=abc';
$tests['Fiche admin construite depuis APP_BASE_URL'] = password_recovery_admin_user_url(7) === 'https://salledesprofs.example/admin/users.php?edit_id=7';
putenv('APP_BASE_URL=javascript:alert(1)');
$tests['Adresse non HTTP ignorée'] = !str_starts_with(password_recovery_base_url(), 'javascript:');
$envBackup === false ? putenv('APP_BASE_URL') : putenv('APP_BASE_URL=' . $envBackup);
$_SERVER = $serverBackup;

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

echo count($tests) . " tests de récupération de mot de passe réussis.\n";
