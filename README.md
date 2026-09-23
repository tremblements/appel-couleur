# Appel Couleur — Dépôts

Plugin WordPress v0.1 permettant de déposer des fichiers (nouvelles, poèmes, photographies) via une zone de dépôt sur une page du site, avec identification par numéro de ticket HelloAsso.

## Fonctionnalités

- **Zone de dépôt** via le shortcode `[appel_couleur_depots]` à insérer dans n'importe quelle page :
  - nouvelles (`.docx`, `.odt`) → dossier Dropbox **nouvelles**
  - poèmes (`.docx`, `.odt`) → dossier Dropbox **poemes**
  - photographies (`.jpg`, `.tiff`) → dossier Dropbox **photos**
- **Compteurs de dépôts** affichés sur la page pour chaque catégorie.
- **Identification par ticket HelloAsso** : le numéro de ticket saisi est vérifié via l'API HelloAsso v5 (OAuth 2.0, `client_credentials`) avant d'accepter le dépôt.
- Taille maximale par fichier : 20 Mo.
- Journal des dépôts en base de données (table `wp_acd_submissions`).

## Installation

1. Copier le dossier du plugin dans `wp-content/plugins/`.
2. Activer le plugin dans l'administration WordPress (le plugin ne propose pas encore de ZIP automatique en v0.1).
3. Configurer le plugin via **Appel Couleur** dans le menu d'administration.

## Configuration

### HelloAsso

1. Récupérer une clé API (Client ID + Client Secret) depuis [l'espace d'administration HelloAsso](https://dev.helloasso.com/docs/obtenir-une-cl%C3%A9-api).
2. Renseigner le **slug de l'organisation** (visible dans l'URL du compte HelloAsso).
3. Laisser « Vérification des tickets » activé pour que chaque dépôt soit validé auprès de HelloAsso.

> En v0.1, le numéro saisi est comparé à l'identifiant de commande retourné par `GET /organizations/{slug}/orders` et à un champ de formulaire. Le point de correspondance exact avec le numéro imprimé sur le ticket dépend de la configuration du formulaire de vente ; il pourra être affiné au fil des retours de terrain.

### Dropbox

1. Créer une application sur [Dropbox App Console](https://www.dropbox.com/developers/apps) avec les droits **files.content.write** sur le dossier applicatif.
2. Générer un **access token** et le renseigner dans les réglages du plugin.
3. Créer dans le Dropbox de l'application les dossiers `nouvelles`, `poemes` et `photos` (l'upload dans un dossier inexistant le crée automatiquement, mais mieux vaut les créer explicitement).

### Accès au formulaire

- Par défaut, seules les personnes connectées au site peuvent déposer.
- L'option « Dépôt public » autorise les visiteurs non connectés ; le numéro de ticket HelloAsso sert alors de seule identification.

## Utilisation

Insérer dans une page :

```
[appel_couleur_depots]
```

Ou, restreint à certaines catégories :

```
[appel_couleur_depots types="poeme,photo"]
```

Attributs :
- `types` — liste des catégories proposées parmi `nouvelle`, `poeme`, `photo` (toutes par défaut)
- `show_counters` — `yes` (défaut) ou `no`, affiche les compteurs de dépôts

## Développement — feuille de route (v0.2+)

- [ ] Compteur distinct par ticket (limiter le nombre de dépôts par ticket)
- [ ] Correspondance exacte avec le numéro de ticket billetterie HelloAsso (Plus Billetterie API)
- [ ] Affichage instantané des compteurs via AJAX après un dépôt
- [ ] Écran d'administration listant les dépôts (export CSV)
- [ ] Drag & drop dans la zone de dépôt

## Structure

```
appel-couleur-depots.php        Point d'entrée du plugin
includes/class-acd-helloasso.php  Client API HelloAsso v5 (OAuth 2.0 + vérification ticket)
includes/class-acd-dropbox.php    Client Dropbox Content API (upload)
includes/class-acd-submissions.php  Table des dépôts + compteurs
includes/class-acd-shortcode.php   Shortcode zone de dépôt + compteurs
includes/class-acd-admin.php       Page de réglages
assets/css/acd-upload.css           Styles front
```

## Licence

GPL-2.0-or-later, comme WordPress.
