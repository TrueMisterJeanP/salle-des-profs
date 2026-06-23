<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/activitypub.php';

// Endpoint objet ActivityPub pour un post public.
$post = activitypub_public_post_by_id((int)get_value('id', '0'));

if (!$post) {
    activitypub_json_response(['error' => 'Publication publique introuvable.'], 404);
}

activitypub_json_response(activitypub_post_object($post));
