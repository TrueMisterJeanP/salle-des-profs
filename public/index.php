<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

redirect(is_logged_in() ? url('dashboard.php') : url('login.php'));
