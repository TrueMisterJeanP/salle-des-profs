<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/activitypub.php';

// Endpoint acteur ActivityPub public.
$user = activitypub_public_user_by_username(get_value('username'));

if (!$user) {
    activitypub_json_response(['error' => 'Utilisateur introuvable.'], 404);
}

activitypub_json_response(activitypub_actor_for_user($user));
