<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/activitypub.php';

// Endpoint outbox ActivityPub : derniers contenus publics de l’auteur.
$user = activitypub_public_user_by_username(get_value('username'));

if (!$user) {
    activitypub_json_response(['error' => 'Utilisateur introuvable.'], 404);
}

$page = (int)get_value('page', '0');

activitypub_json_response(activitypub_outbox($user, $page));
