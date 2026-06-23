<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/activitypub.php';

// Collection minimale : les abonnements entrants ne sont pas encore gérés.
$user = activitypub_public_user_by_username(get_value('username'));

if (!$user) {
    activitypub_json_response(['error' => 'Utilisateur introuvable.'], 404);
}

$page = (int)get_value('page', '0');

activitypub_json_response(activitypub_followers_collection($user, $page));
