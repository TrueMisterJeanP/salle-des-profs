<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

set_flash('warning', 'Les comptes sont créés par l’administrateur de l’établissement.');
redirect(url('login.php'));
