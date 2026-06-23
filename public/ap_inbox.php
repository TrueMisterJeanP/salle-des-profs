<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/activitypub.php';

$result = activitypub_handle_inbox(get_value('username'));

activitypub_json_response($result['body'], $result['status']);
