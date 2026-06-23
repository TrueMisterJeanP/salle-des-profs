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

## BASE_URL

`BASE_URL` doit pointer vers le dossier public de l’application.

Exemples :

```php
const BASE_URL = 'http://localhost/salle-des-profs/public';
const BASE_URL = 'https://example.com/public';
```

Si le serveur web pointe déjà directement vers `public/`, utiliser l’URL publique réelle :

```php
const BASE_URL = 'https://salle-des-profs.example.com';
```

Les liens vers `install.php` et `admin/` sont construits à partir de cette valeur.

## Production

Dans `includes/config.php` :

```php
const APP_ENV = 'production';
```

À configurer côté serveur :

- HTTPS.
- Pas d’accès direct à `database/`.
- Pas d’exécution PHP ou CGI dans `uploads/`.
- Sauvegardes régulières de `database/app.sqlite` et `uploads/`.

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
de `024`, nécessaires uniquement pour mettre à niveau une installation
existante.

## MariaDB

L’installation initiale se fait avec SQLite. Après connexion en administrateur, la page suivante permet de configurer MariaDB, tester la connexion, copier le contenu SQLite vers MariaDB puis choisir le moteur actif :

```text
Admin > Base de données
```

La configuration active est écrite dans `database/database.config.php`.
