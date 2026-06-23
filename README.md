# Salle des profs

Version actuelle : **1.0.0**

Application web libre d’échange, de soutien et de coordination destinée aux
enseignants, développée en PHP avec SQLite ou MariaDB.

## Fonctionnalités

- Installation guidée via `install.php`.
- Création du premier administrateur.
- Connexion, déconnexion et inscription selon paramètre.
- Administration des utilisateurs, groupes, posts, articles, commentaires, paramètres, migrations et publications Mastodon.
- Posts courts, articles Markdown, commentaires, messages privés, groupes, messages de groupe et pièces jointes.
- Pages publiques, articles publics et flux RSS.
- Publication manuelle vers Mastodon si un compte est configuré.

## Structure

- `install.php` : installation initiale et création du premier administrateur.
- `public/` : pages publiques et espace connecté.
- `admin/` : pages d’administration.
- `api/` : endpoints AJAX pour messages, upload et Mastodon.
- `includes/` : configuration, base de données, auth, CSRF, settings, helpers.
- `templates/` : en-tête et pied de page communs.
- `database/schema.sqlite.sql` : schéma initial SQLite.
- `database/migrations/` : futures migrations SQL, à partir de la version `024`.

## Configuration

La configuration principale est dans `includes/config.php`.

À vérifier avant usage :

- `BASE_URL` doit pointer vers le dossier `public/`.
- `APP_ENV` doit être `production` en production.
- Le serveur web doit pouvoir écrire dans `database/` et `uploads/`.

Exemple local :

```php
const BASE_URL = 'http://localhost/salle-des-profs/public';
```

## Installation

Voir [INSTALL.md](INSTALL.md).

Installation locale rapide :

```sh
git clone URL_DU_DEPOT/salle-des-profs.git
cd salle-des-profs
php -S localhost:8000
```

Ouvrez ensuite `http://localhost:8000/install.php`. Pour un déploiement réel,
utilisez un serveur web configuré selon [INSTALL.md](INSTALL.md).

En développement local uniquement, l’installateur propose un compte de
démonstration `root` / `root`. Cette option exige une requête depuis localhost
et disparaît lorsque `APP_ENV` vaut `production`.

## Sécurité

Les notes de durcissement sont dans [SECURITY_NOTES.md](SECURITY_NOTES.md).

Points importants :

- Ne pas exposer directement `database/`.
- Ne pas exécuter de scripts depuis `uploads/`.
- Utiliser HTTPS en production.
- Chiffrer les jetons Mastodon pour un usage sensible.

## Vérification rapide

Quand PHP est disponible en ligne de commande :

```sh
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

La checklist fonctionnelle est dans [CHECKLIST.md](CHECKLIST.md).

## Contribution

Consultez [CONTRIBUTING.md](CONTRIBUTING.md). Avant toute publication, vérifiez
que `git status` ne contient aucune donnée d’exploitation.

## Licence

Ce logiciel est distribué sous la licence
[GNU General Public License version 3 ou ultérieure](LICENSE).

Vous pouvez l’utiliser, l’étudier, le modifier et le redistribuer selon les
conditions de cette licence. Toute redistribution d’une version modifiée doit
rester sous une licence compatible avec la GPL.
