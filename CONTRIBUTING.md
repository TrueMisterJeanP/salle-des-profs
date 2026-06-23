# Contribuer

Les signalements de bugs et propositions d’amélioration sont bienvenus.

## Avant de proposer une modification

- N’ajoutez jamais de base de données réelle, d’adresse électronique, de jeton, de clé privée ou de fichier téléversé.
- Vérifiez la syntaxe de tous les fichiers PHP :

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

- Exécutez les tests disponibles :

```sh
php tests/mastodon_text_test.php
```

## Licence des contributions

En proposant une contribution, vous acceptez qu’elle soit distribuée sous la
licence GNU General Public License version 3 ou ultérieure, comme le reste du
projet.

