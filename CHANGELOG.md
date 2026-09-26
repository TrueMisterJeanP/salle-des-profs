# Historique des versions

## 1.1.0

Adresses lisibles, récupération de mot de passe et améliorations de la messagerie.

- Adresses lisibles sans identifiant numérique : `/public/article/mon-titre`, `/public/publication/mon-titre`, `/public/groupe/nom-du-groupe`, `/public/messages/nom-utilisateur` et `/public/messagerie/…`. Les anciennes adresses redirigent en 301 après vérification des droits.
- Migration `024` : slug unique pour les groupes.
- Récupération de mot de passe par email pour les membres (administrateurs exclus) : lien valable 10 minutes, question anti-robot paramétrable, administrateurs informés.
- Réponse HTTP `429` avec `Retry-After` lorsque le limiteur des pages de connexion et de récupération est atteint.
- Blocage des scans de 404 durci : fenêtre glissante et ressources statiques ignorées.
- Messagerie : discussions en cours épinglées en tête avec le nombre de messages non lus, aucune discussion ouverte par défaut, bouton « Joindre » unique, contrôles alignés, suppression possible d'une conversation vide.
- Corrections : fichier trop volumineux signalé clairement sans bloquer l'envoi suivant, « Aucun message » dans une conversation vide, liens d'images valides quelle que soit l'adresse de la page, slugs sans accents identiques sur tous les systèmes.

## 1.0.1

Mise à jour fonctionnelle, visuelle et de sécurité de Salle des profs.

- Nouvelle navigation responsive, en-têtes harmonisés et pieds de page centrés.
- Pages de connexion complétées par l’en-tête, le pied de page et les liens publics utiles.
- Tableau de bord réorganisé avec accès direct aux ressources.
- Présentation harmonisée des événements, incidents, actions collectives et syndicats.
- Page Ressources autonome, ressources épinglées et administration globale dédiée.
- Recherche étendue aux événements, incidents, actions et ressources.
- Correction de l’actualisation des pièces jointes et de leur retrait dans les articles.
- Publication fédérée limitée aux événements, incidents et actions publics et actifs.
- Améliorations de la messagerie, de ses notifications et de son interface responsive.
- Disparition automatique des bandeaux d’information temporaires.
- Durcissement des sessions, contrôles CSRF, téléversements, appels distants, journaux et échanges ActivityPub.
- Nouvelles icônes par défaut et captures d’écran anonymisées mises à jour.

## 1.0.0

Première version publique de Salle des profs.

- Installation avec SQLite et prise en charge de MariaDB.
- Gestion des utilisateurs, groupes, annonces, articles et commentaires.
- Messagerie privée et discussions de groupe.
- Outils de protection collective, calendrier, ressources et syndicats.
- Pièces jointes, notifications, flux RSS, ActivityPub et Mastodon.
- Interface d’administration et configuration du site.
