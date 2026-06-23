<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';

function mastodon_get_user_setting(int $userId, string $key, string $default = ''): string
{
    return setting_value('user_' . $userId . '_' . $key, $default);
}

function mastodon_is_configured(int $userId): bool
{
    return mastodon_get_user_setting($userId, 'mastodon_instance_url') !== ''
        && mastodon_get_user_setting($userId, 'mastodon_access_token') !== '';
}

function mastodon_publish_status(int $userId, string $status, ?string $visibility = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('L’extension PHP cURL est requise pour publier sur Mastodon.');
    }

    $instanceUrl = rtrim(mastodon_get_user_setting($userId, 'mastodon_instance_url'), '/');
    $accessToken = mastodon_get_user_setting($userId, 'mastodon_access_token');
    $defaultVisibility = mastodon_get_user_setting($userId, 'mastodon_default_visibility', 'public');

    if ($instanceUrl === '' || $accessToken === '') {
        throw new RuntimeException('Mastodon n’est pas configuré pour cet utilisateur.');
    }

    $visibility = $visibility ?: $defaultVisibility;

    if (!in_array($visibility, ['public', 'unlisted', 'private'], true)) {
        $visibility = 'public';
    }

    $status = trim($status);

    if ($status === '') {
        throw new RuntimeException('Le statut Mastodon est vide.');
    }

    if (mb_strlen($status, 'UTF-8') > 480) {
        $status = mb_substr($status, 0, 477, 'UTF-8') . '…';
    }

    $payload = http_build_query([
        'status' => $status,
        'visibility' => $visibility,
    ]);

    $ch = curl_init($instanceUrl . '/api/v1/statuses');

    if ($ch === false) {
        throw new RuntimeException('Impossible d’initialiser cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    $responseBody = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Erreur cURL : ' . $error);
    }

    $decoded = json_decode($responseBody, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = is_array($decoded) && isset($decoded['error'])
            ? (string)$decoded['error']
            : $responseBody;

        throw new RuntimeException('Erreur Mastodon HTTP ' . $httpCode . ' : ' . $message);
    }

    return is_array($decoded) ? $decoded : [];
}
