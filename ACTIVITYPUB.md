# ActivityPub

Cette version ajoute la découverte, la lecture et les échanges fédérés principaux sans remplacer le système local existant.

## Endpoints

- WebFinger : `/.well-known/webfinger.php?resource=acct:USERNAME@HOST`
- Variante directe : `/public/.well-known/webfinger.php?resource=acct:USERNAME@HOST`
- Acteur : `/public/ap_user.php?username=USERNAME`
- Outbox : `/public/ap_outbox.php?username=USERNAME`
- Objet article : `/public/ap_article.php?id=ID`
- Objet post : `/public/ap_post.php?id=ID`
- Followers : `/public/ap_followers.php?username=USERNAME`
- Inbox : `/public/ap_inbox.php?username=USERNAME`

Les URLs ActivityPub sont construites depuis `BASE_URL` dans `includes/config.php`.

## Fonctionnalités

- WebFinger valide le domaine demandé.
- L’acteur JSON-LD expose `inbox`, `outbox`, `followers` et `publicKey`.
- Les URLs d’acteurs persistées sont resynchronisées avec `BASE_URL`.
- L’outbox et la collection followers sont paginées avec `OrderedCollectionPage`.
- L’inbox vérifie les signatures HTTP entrantes.
- Les `Follow` entrants sont acceptés, persistés et reçoivent une activité `Accept`.
- Les publications publiques déclenchent `Create`, `Update` ou `Delete` vers les followers.
- Les livraisons sortantes sont signées avec HTTP Signatures et journalisées.
- `Undo(Follow)` retire un follower.
- `Block` retire le follower et mémorise le blocage.
- `Like`, `Announce`, `Accept`, `Create`, `Update` et `Delete` entrants sont acceptés et journalisés sans effet local supplémentaire.
- Les contenus exposés sont uniquement les posts publics publiés et les articles publics publiés.
- Les messages privés, groupes privés, brouillons et contenus membres ne sont pas exposés.

## Tests curl

Avec le serveur local lancé sur `http://localhost:8000` et `BASE_URL=http://localhost:8000/public` :

```bash
curl "http://localhost:8000/.well-known/webfinger.php?resource=acct:root@localhost"
curl "http://localhost:8000/public/.well-known/webfinger.php?resource=acct:root@localhost"
curl -H "Accept: application/activity+json" "http://localhost:8000/public/ap_user.php?username=root"
curl -H "Accept: application/activity+json" "http://localhost:8000/public/ap_outbox.php?username=root"
curl -H "Accept: application/activity+json" "http://localhost:8000/public/ap_outbox.php?username=root&page=1"
curl -H "Accept: application/activity+json" "http://localhost:8000/public/ap_post.php?id=1"
```

## À faire ensuite

- Ajouter un worker de retry périodique pour les entrées `activitypub_deliveries` en échec.
- Ajouter une interface d’administration pour inspecter followers, inbox et livraisons.
- Étendre les effets locaux de `Like` et `Announce` si l’application expose ces interactions dans l’UI.
