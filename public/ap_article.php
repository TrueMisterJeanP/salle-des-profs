<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/activitypub.php';

// Endpoint objet ActivityPub pour un article public.
$article = activitypub_public_article_by_id((int)get_value('id', '0'));

if (!$article) {
    activitypub_json_response(['error' => 'Article public introuvable.'], 404);
}

activitypub_json_response(activitypub_article_object($article));
