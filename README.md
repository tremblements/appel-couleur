# Contribalapelle — plugin WordPress « Dépôt contribution »

Revue Pourtant (association Tremblements). Fonction « Dépôt contribution » du cahier des charges v1 :
une personne inscrite à l'appel à contributions en cours via la billetterie HelloAsso dépose une
contribution (texte, poème ou image — un seul fichier) depuis une page du site WordPress ; le fichier
est rangé dans Dropbox après vérification du ticket.

## Fonctionnement

- **Fenêtre de dépôt** : dates d'ouverture et de clôture réglables (fuseau Europe/Paris). Hors période,
  le formulaire reste visible en lecture seule avec les dates affichées ; tout dépôt est refusé côté serveur.
- **Vérification du ticket** : au moment du dépôt, le numéro de ticket est vérifié en temps réel via l'API
  HelloAsso v5 (commande payée/autorisée de la billetterie de l'appel). La correspondance
  numéro ↔ commande (et e-mail du payeur) est enregistrée localement après la première vérification pour
  tracer les dépôts sans réinterroger l'API à chaque étape du formulaire.
- **Un ticket = une contribution** : après un dépôt réussi, le même numéro ne peut déposer qu'une nouvelle
  version de la même sous-catégorie, qui remplace le fichier précédent. Aucun second dépôt d'un autre type.
- **Sous-catégories** : fiction 500 mots, fiction 250 mots, haïku, poème long, poème court (textes :
  `.odt`, `.docx`, `.pdf`) et image (`.jpg`, `.jpeg`, `.png`, `.tif`, `.tiff`), poids maximum 25 Mo par défaut.
- **Rangement Dropbox** : `Depots-<appel>/fiction/`, `Depots-<appel>/poesie/`, `Depots-<appel>/image/` ;
  nom de fichier anonymisé `<aammjj>-<n° de ticket>.<ext>` (date de dépôt, heure de Paris).
  Le dépôt n'est réussi qu'après confirmation d'écriture côté Dropbox ; si Dropbox est indisponible,
  le dépôt est refusé et le ticket n'est pas consommé.
- **Message de réussite** : court, sans rappel du type ni du numéro, envoyé par e-mail (adresse récupérée
  sur la commande HelloAsso) ; repli à l'écran si l'e-mail est introuvable.
- **Journalisation** : chaque opération (vérification/dépôt, réussi ou refusé, avec le motif) est enregistrée
  et consultable dans l'admin (menu **Contribalapelle**). Les données (ticket, type, horodatage, e-mail) sont
  supprimées après la publication du numéro de la revue.
- **Double soumission simultanée** : le premier dépôt gagne, le second est refusé.

## Installation

1. Copier le dossier `contribalapelle/` dans `wp-content/plugins/`.
2. Activer le plugin **Contribalapelle** dans l'admin WordPress.
3. Renseigner les réglages : **Réglages → Contribalapelle** (voir `docs/parametrage.md` pour où trouver
   chaque valeur : identifiants API HelloAsso, token Dropbox, dates de la fenêtre, nom de l'appel).
4. Créer une page WordPress contenant le shortcode `[contribalapelle]` et y intégrer la billetterie
   HelloAsso (options d'intégration proposées par HelloAsso).
5. Le site doit fonctionner en **HTTPS** ; prévoir `upload_max_filesize` ≥ 25 Mo côté serveur.

Un nouvel appel se configure en modifiant uniquement les paramètres (dates, nom de l'appel, libellés),
sans toucher au code.

## Structure du dépôt

```
contribalapelle/
├── contribalapelle.php            # amorçage
├── includes/
│   ├── class-clp-db.php           # tables tickets + journal
│   ├── class-clp-settings.php     # réglages (options) + sous-catégories
│   ├── class-clp-window.php       # fenêtre de dépôt (Europe/Paris)
│   ├── class-clp-helloasso.php    # API HelloAsso v5 (OAuth2, vérification ticket)
│   ├── class-clp-dropbox.php      # API Dropbox (upload, suppression, chemins)
│   ├── class-clp-upload.php       # AJAX vérification + dépôt, validations, e-mail
│   ├── class-clp-shortcode.php    # shortcode [contribalapelle] (formulaire public)
│   └── class-clp-admin.php        # page admin de suivi des dépôts
├── assets/clp.css, clp.js
└── uninstall.php                  # supprime tables et options à la désinstallation
```

## Développement

- PHP ≥ 8.1, aucune dépendance externe.
- Vérification syntaxique : `php -l` sur chaque fichier PHP du plugin.
- Les appels à HelloAsso et Dropbox passent par l'API HTTP de WordPress (`wp_remote_*`).
