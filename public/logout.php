<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_login();

if (!is_post()) {
    http_response_code(405);
    exit('Méthode non autorisée.');
}

require_csrf();
logout_user();

start_app_session();
set_flash('success', 'Vous êtes déconnecté.');

redirect(url('public.php'));
