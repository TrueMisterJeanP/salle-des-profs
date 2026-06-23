<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/activitypub.php';

// Endpoint WebFinger compatible Fediverse.
$resource = get_value('resource');

if (!preg_match('/^acct:([^@]+)@(.+)$/', $resource, $matches)) {
    activitypub_json_response(['error' => 'Ressource WebFinger invalide.'], 400, 'application/jrd+json; charset=utf-8');
}

$username = normalize_username($matches[1]);
$requestedHost = mb_strtolower($matches[2], 'UTF-8');
$expectedHost = mb_strtolower(activitypub_host(), 'UTF-8');

if ($requestedHost !== $expectedHost) {
    activitypub_json_response(['error' => 'Domaine WebFinger invalide.'], 404, 'application/jrd+json; charset=utf-8');
}

$user = activitypub_public_user_by_username($username);

if (!$user) {
    activitypub_json_response(['error' => 'Utilisateur introuvable.'], 404, 'application/jrd+json; charset=utf-8');
}

$actor = activitypub_ensure_actor_keys($user);
$host = activitypub_host();

activitypub_json_response([
    'subject' => 'acct:' . $user['username'] . '@' . $host,
    'aliases' => [
        (string)$actor['actor_url'],
        url('public.php'),
    ],
    'links' => [
        [
            'rel' => 'self',
            'type' => 'application/activity+json',
            'href' => (string)$actor['actor_url'],
        ],
    ],
], 200, 'application/jrd+json; charset=utf-8');
