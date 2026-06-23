# Checklist de tests manuels

## Installation

- Ouvrir `install.php` avec une base vide.
- Créer le premier administrateur.
- Vérifier la redirection vers le tableau de bord.
- Vérifier que relancer `install.php` redirige vers la connexion quand un utilisateur existe.

## Authentification

- Se connecter avec l’administrateur.
- Se déconnecter.
- Vérifier qu’une page connectée redirige vers `login.php` hors session.
- Créer un utilisateur standard si les inscriptions sont activées.

## Administration

- Ouvrir chaque page admin depuis la navigation :
  - Utilisateurs
  - Groupes
  - Posts
  - Articles
  - Commentaires
  - Configuration
  - Migrations
  - Historique Mastodon
- Vérifier qu’un utilisateur non-admin reçoit un refus d’accès sur `admin/index.php`.
- Modifier un rôle utilisateur, puis le remettre comme avant.
- Changer la visibilité d’un post, article ou groupe.

## Publications

- Créer un post.
- Créer un article en brouillon.
- Publier un article.
- Commenter un article connecté.
- Vérifier qu’un article public est visible via `public_article.php` sans connexion.

## Messages

- Envoyer un message privé entre deux utilisateurs.
- Vérifier que seul l’expéditeur ou le destinataire voit la conversation.
- Modifier son propre message.
- Supprimer son propre message.
- Transformer un message en post ou en brouillon d’article.

## Groupes

- Créer un groupe.
- Rejoindre un groupe public ou membres.
- Ajouter un membre depuis la page groupe si autorisé.
- Envoyer un message de groupe.
- Vérifier qu’un non-membre ne peut pas lire les messages d’un groupe privé.

## Fichiers

- Envoyer une pièce jointe depuis la page fichiers.
- Envoyer une pièce jointe dans un message privé.
- Envoyer une pièce jointe dans un message de groupe.
- Ouvrir le fichier via `file.php?id=...`.
- Vérifier qu’un utilisateur sans droit ne peut pas ouvrir la pièce jointe.

## Public et RSS

- Ouvrir `public.php` sans connexion.
- Ouvrir `about.php` sans connexion.
- Ouvrir `rss.php` sans connexion.
- Vérifier que les liens RSS vers posts et articles publics fonctionnent.

## Mastodon

- Tenter de publier un contenu public sans configuration Mastodon.
- Vérifier que l’erreur est propre et compréhensible.
- Enregistrer une URL d’instance et un jeton.
- Revenir sur la page de configuration et vérifier que le jeton n’est pas affiché.

## Qualité

- Lancer le lint PHP :

```sh
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

- Parcourir les pages principales avec `APP_ENV = production` et vérifier qu’aucun warning PHP ne s’affiche.
