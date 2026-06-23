<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/web_notifications.php';

require_login();

$user = current_user();

if (is_post()) {
    require_csrf();

    $enabled = post_value('messenger_enabled', '0') === '1';
    web_notifications_set_messenger_enabled((int)$user['id'], $enabled);

    json_response([
        'success' => true,
        'messenger_enabled' => $enabled,
    ]);
}

json_response([
    'success' => true,
    'messenger_enabled' => web_notifications_messenger_enabled((int)$user['id']),
]);
