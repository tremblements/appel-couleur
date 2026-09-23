# Dépôt Appel à Couleur — plugin WordPress

Plugin WordPress (PHP ≥ 8.1) permettant le dépôt de fichiers sur une page du site :

- **Nouvelles** : `.docx`, `.odt` → dossier Dropbox `nouvelles`
- **Poèmes** : `.docx`, `.odt` → dossier Dropbox `poemes`
- **Photographies** : `.jpg`, `.jpeg`, `.tiff` → dossier Dropbox `photos`

Chaque déposant est identifié par un **numéro de ticket HelloAsso**, vérifié via l'API HelloAsso v5 (OAuth 2.0 client credentials) avant tout transfert. La page affiche un **compteur de dépôts** par catégorie, mis à jour en direct.

## Installation

1. Copier le dossier `depot-appel-couleur/` dans `wp-content/plugins/`.
2. Activer le plugin dans WordPress.
3. Configurer : **Réglages → Dépôt Appel à Couleur** (compte Dropbox, arborescence des dossiers, compte HelloAsso, campagne de vente, mode de vérification du ticket) — voir [`docs/parametrage.md`](docs/parametrage.md).
4. Insérer le shortcode dans la page de dépôt :

   ```
   [depot_appel_couleur]
   ```

   Variante sans compteurs : `[depot_appel_couleur show_counters="no"]`.

## Paramétrage

Toutes les données à saisir (compte Dropbox, dossiers de l'arborescence, compte HelloAsso, nom de la campagne de vente, champ de vérification du ticket, limites) sont listées avec leur localisation dans **[docs/parametrage.md](docs/parametrage.md)**.

## Structure

```
depot-appel-couleur/
├── depot-appel-couleur.php        # point d'entrée du plugin
├── includes/
│   ├── class-dac-settings.php     # page de réglages (Réglages → Dépôt Appel à Couleur)
│   ├── class-dac-helloasso.php    # vérification du ticket via l'API HelloAsso v5
│   ├── class-dac-dropbox.php      # envoi du fichier vers Dropbox
│   ├── class-dac-counters.php     # compteurs de dépôts (wp_options)
│   ├── class-dac-upload.php       # gestion AJAX du dépôt (nonce, formats, taille)
│   └── class-dac-shortcode.php    # shortcode [depot_appel_couleur]
└── assets/
    ├── depot.css                   # styles du formulaire et des compteurs
    └── depot.js                    # soumission AJAX + rafraîchissement des compteurs
```

## Compatibilité

- PHP **8.1** minimum (aucune dépendance Composer ; utilise les API natives WordPress `WP_Http`, `wp_handle_upload`, `admin-ajax`).
- Aucune donnée personnelle conservée sur le serveur après un dépôt réussi : le fichier est transféré vers Dropbox puis supprimé localement ; seuls les compteurs restent.
