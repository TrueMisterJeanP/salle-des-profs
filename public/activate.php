<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

if (is_logged_in()) {
    logout_user();
}

$status = activate_user_by_token(get_value('token'));

if ($status === 'activated') {
    set_flash('success', 'Votre adresse email est validée. Vous pouvez maintenant vous connecter.');
} elseif ($status === 'already_active') {
    set_flash('success', 'Votre compte est déjà activé. Vous pouvez vous connecter.');
} elseif ($status === 'expired') {
    set_flash('error', 'Ce lien de validation a expiré. Contactez l’administration pour réactiver votre inscription.');
} else {
    set_flash('error', 'Lien de validation invalide.');
}

redirect(url('login.php'));
