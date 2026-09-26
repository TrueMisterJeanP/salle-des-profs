# Installation

## Prérequis

- PHP 8.1 ou plus récent recommandé.
- Extensions PHP : `pdo_sqlite`, `mbstring`, `fileinfo`.
- Extension `pdo_mysql` nécessaire si vous voulez copier la base SQLite vers MariaDB et basculer le site sur MariaDB.
- Extension `curl` uniquement nécessaire pour publier vers Mastodon.
- Un serveur web pointant vers le dossier `public/`.

## Étapes

1. Cloner ou copier le projet sur le serveur.
2. Configurer `includes/config.php`.
3. Donner les droits d’écriture au processus web sur :
   - `database/`
   - `uploads/`
4. Ouvrir `install.php` dans le navigateur.
5. Renseigner le nom du site et le premier compte administrateur.
6. Se connecter à l’espace admin via le lien `Admin` dans la navigation.

La base `database/app.sqlite` et le fichier
`database/database.config.php` sont volontairement absents du dépôt public :
ils sont créés localement pendant l’installation ou la configuration.

## Compte local de démonstration

Lorsque `APP_ENV` vaut `development` et que l’installateur est ouvert depuis
localhost, un bouton permet de créer le compte suivant :

```text
Utilisateur : root
Mot de passe : root
```

Ce raccourci n’est pas disponible à distance ni en production. Il ne doit
jamais servir à déployer un site public.

## URL publique

Définir la variable d’environnement `APP_BASE_URL` avec l’URL qui pointe vers
le dossier public de l’application. Cette valeur fixe évite notamment qu’un
en-tête HTTP `Host` inattendu soit utilisé dans les liens envoyés par email.

Exemples :

```text
APP_BASE_URL=http://localhost/salle-des-profs/public
APP_BASE_URL=https://example.com/public
```

Si le serveur web pointe déjà directement vers `public/`, utiliser l’URL publique réelle :

```text
APP_BASE_URL=https://salle-des-profs.example.com
```

Les liens vers `install.php` et `admin/` sont construits à partir de cette valeur.

## Production

Dans `includes/config.php` :

```php
const APP_ENV = 'production';
```

À configurer côté serveur :

- HTTPS.
- `APP_BASE_URL` avec l’URL HTTPS publique exacte.
- Pas d’accès direct à `database/`.
- Pas d’exécution PHP ou CGI dans `uploads/`.
- Sauvegardes régulières de `database/app.sqlite` et `uploads/`.

Les fichiers `.htaccess` fournis appliquent ces refus sous Apache. Avec Nginx
ou un autre serveur, reproduire explicitement ces règles dans le virtual host.

Le `.htaccess` racine définit aussi les URL lisibles (`mod_rewrite` requis) :
`/public/article/mon-titre`, `/public/article/mon-titre/modifier`,
`/public/publication/mon-titre`, `/public/groupe/nom-du-groupe`,
`/public/messages/nom-utilisateur`, `/public/messagerie/prive/nom-utilisateur`
et `/public/messagerie/groupe/nom-du-groupe`. Sur un autre serveur, reproduire
ces réécritures, sinon ces pages répondent 404. En local, `router.php` les
reproduit pour `php -S`.

Si un proxy inverse transmet `X-Forwarded-For`, renseigner ses adresses IP
exactes, séparées par des virgules, dans `TRUSTED_PROXY_IPS`. Sans cette
variable, seuls les proxys locaux `127.0.0.1` et `::1` sont approuvés.

La détection des mots de passe réessayés dans le journal de sécurité utilise
`AUTH_FINGERPRINT_KEY` lorsqu’elle est définie. À défaut, une clé aléatoire est
créée dans `database/.auth_fingerprint_key` avec des droits restreints. Cette
clé doit rester secrète et être partagée par les différents nœuds si le site
est répliqué.

## Stockage des fichiers sur un disque externe

Le dossier physique des fichiers peut être changé dans :

```text
Admin > Configuration > Stockage des fichiers
```

Indiquer un chemin absolu existant et accessible en écriture par PHP, par exemple le point de montage d’un SSD USB. Avant de changer ce chemin sur un site déjà utilisé, copier le contenu de l’ancien dossier `uploads/` vers le nouveau dossier.

## Migrations

Après installation, les migrations peuvent être lancées depuis :

```text
Admin > Migrations
```

Les schémas d’installation contiennent déjà toutes les évolutions historiques.
Le dossier `database/migrations/` accueillera les futures migrations à partir
de `025`, nécessaires uniquement pour mettre à niveau une installation
existante.

## MariaDB

L’installation initiale se fait avec SQLite. Après connexion en administrateur, la page suivante permet de configurer MariaDB, tester la connexion, copier le contenu SQLite vers MariaDB puis choisir le moteur actif :

```text
Admin > Base de données
```

La configuration active est écrite dans `database/database.config.php`.
