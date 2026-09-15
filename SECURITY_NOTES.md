# Notes de sécurité V1

Cette revue a corrigé les failles simples directement dans le code. Les points suivants restent des recommandations de durcissement qui dépassent une correction ciblée.

## Déploiement

- Conserver `APP_ENV` à `production` afin de désactiver l’affichage des erreurs PHP.
- Définir `APP_BASE_URL` avec l’URL HTTPS publique exacte du site.
- Placer `database/` et `uploads/` hors du document root web quand c’est possible.
- Les règles Apache fournies refusent l’accès direct à `database/` et `uploads/`. Reproduire ces règles sous Nginx ou tout autre serveur. Les fichiers doivent être servis via `public/file.php`, qui vérifie les droits d’accès.
- Servir l’application en HTTPS afin de protéger les sessions et les jetons Mastodon.

## Sessions

- Les sessions utilisent des cookies `HttpOnly`, `SameSite=Lax` et `Secure` sous HTTPS.
- Déclarer les proxys inverses de confiance dans `TRUSTED_PROXY_IPS` afin de fiabiliser la limitation des tentatives de connexion.
- Envisager une durée d’inactivité maximale côté serveur pour les sessions.

## Jetons Mastodon

- Les jetons Mastodon sont stockés en clair dans SQLite dans la V1. Pour une version plus sensible, chiffrer ces valeurs avec une clé hors base de données.
- Limiter les jetons Mastodon au scope minimal, actuellement `write:statuses`.

## Uploads

- Les extensions enregistrées sont forcées d’après le MIME détecté, mais la détection MIME reste une défense imparfaite.
- Pour un usage public ou multi-tenant, ajouter une analyse antivirus et éventuellement une génération de vignettes côté serveur pour les images.
- Conserver les règles serveur refusant l’accès direct aux fichiers uploadés si le serveur expose le dossier `uploads/`.

## Autorisations

- Les contrôles d’accès sont faits dans les pages/endpoints concernés. Pour une V2, centraliser les règles d’autorisation des messages, groupes, articles et fichiers dans des helpers dédiés réduirait le risque de divergence.
- Les administrateurs peuvent accéder aux fichiers et modérer les contenus. Cette règle doit être documentée dans la politique d’exploitation.
- `api/fetch_messages.php` marque actuellement certains messages privés comme lus pendant une requête GET. Pour durcir strictement les sémantiques HTTP, déplacer cette mutation vers un endpoint POST protégé CSRF.

## Navigateur

- Ajouter une politique CSP adaptée après inventaire des scripts/styles réellement nécessaires.
- Les en-têtes `Referrer-Policy`, `X-Frame-Options` et `X-Content-Type-Options` sont appliqués par PHP et Apache.
- Ajouter ultérieurement une politique CSP adaptée après suppression ou mise sous nonce des scripts et styles intégrés aux pages.

## Journalisation

- Les erreurs détaillées ne devraient pas être affichées aux utilisateurs en production. Les enregistrer côté serveur dans des logs non publics.
- Ajouter une journalisation des actions sensibles : suppression utilisateur, changement de rôle, suppression de message, changement de visibilité, exécution de migration.
