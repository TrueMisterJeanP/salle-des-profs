<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/federation.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    protection_ensure_schema();

    $configuredToken = federation_publication_token();
    $receivedToken = federation_authorization_token();

    if (!federation_token_is_valid($configuredToken) || !hash_equals($configuredToken, $receivedToken)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $limit = (int)get_value('limit', '30');
    $type = (string)get_value('type', '');
    $payload = federation_feed_payload($limit, $type);

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Federation feed unavailable'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
