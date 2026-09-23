# Paramétrage du plugin « Dépôt Appel à Couleur »

Ce document liste toutes les données à saisir, leur localisation précise (où les trouver), et où les renseigner dans le plugin.

Version PHP requise : **8.1** (compatible et testé pour PHP 8.1).

---

## Où renseigner les paramètres

Tous les paramètres se saisissent dans l'administration WordPress :

**Réglages → Dépôt Appel à Couleur** (`/wp-admin/options-general.php?page=depot-appel-couleur`)

Ils sont stockés dans la base WordPress (table `wp_options`, clé `dac_settings`) — jamais dans un fichier, ce qui évite de stocker des secrets dans le code.

---

## 1. Compte Dropbox

| Paramètre | Description | Où le trouver |
|---|---|---|
| `dropbox_access_token` | Jeton d'accès Dropbox (type « App token ») | Voir procédure ci-dessous |
| `dropbox_root` | Dossier racine Dropbox, ex. `/depot-appel-couleur` | Vous choisissez ce nom ; il sera créé automatiquement dans Dropbox si besoin |
| `folder_nouvelles` | Sous-dossier des nouvelles, ex. `nouvelles` | Vous choisissez ce nom |
| `folder_poemes` | Sous-dossier des poèmes, ex. `poemes` | Vous choisissez ce nom |
| `folder_photos` | Sous-dossier des photos, ex. `photos` | Vous choisissez ce nom |
| `max_file_mb` | Taille max par fichier en Mo | Selon la configuration de votre serveur (voir `upload_max_filesize` PHP) |

### Procédure pour obtenir le `dropbox_access_token`

1. Aller sur [Dropbox App Console](https://www.dropbox.com/developers/apps) → **Create app**.
2. Choisir : « Scoped access » → « App folder » (recommandé : l'app n'a accès qu'à son propre dossier) ou « Full Dropbox » si vous voulez un autre emplacement racine.
3. Donner un nom à l'app (ex. `appel-couleur-depot`).
4. Dans l'onglet **Permissions**, activer : `files.content.write` et `files.content.read`.
5. Dans l'onglet **Settings**, section « OAuth 2.0 », cliquer **Generate access token**.
6. Copier le jeton généré (commence par `sl.`) et le coller dans le champ **Access Token (app)** de la page de réglages du plugin.

### Arborescence Dropbox résultante

```
/Applications/dropbox.../                  (racine si app folder)  ou  /  (si full dropbox)
└── /depot-appel-couleur/                  (paramètre dropbox_root)
    ├── nouvelles/                          (paramètre folder_nouvelles)
    ├── poemes/                             (paramètre folder_poemes)
    └── photos/                             (paramètre folder_photos)
```

Chaque fichier déposé est préfixé par le numéro de ticket du déposant : `123456-mon-fichier.docx`.

---

## 2. Compte HelloAsso

| Paramètre | Description | Où le trouver |
|---|---|---|
| `helloasso_client_id` | Client ID de l'application | HelloAsso → **Mon compte → Paramètres → Open API / Jetons d'accès** (ou via [dev.helloasso.com](https://dev.helloasso.com)) : voir procédure ci-dessous |
| `helloasso_client_secret` | Client Secret | Même endroit que le Client ID (affiché une seule fois à la création, à conserver précieusement) |
| `helloasso_org_slug` | Slug de l'organisation (l'association) | Visible dans l'URL de votre page association : `helloasso.com/associations/<slug>` |
| `helloasso_form_type` | Type de formulaire de la campagne de vente | Dans le back-office HelloAsso : type de la campagne (Event pour un événement/billetterie, Donation, Membership, etc.) |
| `helloasso_form_slug` | Slug de la campagne de vente (formulaire) | Visible dans l'URL de la campagne : `helloasso.com/associations/<org-slug>/collections/<form-slug>` ou dans les paramètres de la campagne |
| `helloasso_ticket_field` | Quel champ identifiera le numéro saisi par le déposant | Voir tableau ci-dessous |
| `helloasso_api_url` | URL de l'API | `https://api.helloasso.com/v5` (production) ou `https://api.helloasso-sandbox.com/v5` (tests) |

### Procédure pour obtenir le Client ID / Client Secret

1. Aller sur [dev.helloasso.com](https://dev.helloasso.com) et suivre « Obtenir une clé API », ou depuis votre back-office HelloAsso : **Paramètres → Open API**.
2. Créer une application : le `client_id` et le `client_secret` sont fournis à la création.
3. Renseigner le `client_id` et le `client_secret` dans les réglages du plugin.

L'authentification utilise OAuth 2.0 « client credentials » (`POST https://api.helloasso.com/oauth2/token`) ; le jeton d'accès est mis en cache 25 minutes par le plugin.

### Choix du champ de vérification (`helloasso_ticket_field`)

Le plugin interroge `GET /v5/organizations/{orgSlug}/forms/{formType}/{formSlug}/orders` (avec `withDetails=true`) et cherche le numéro saisi parmi :

| Valeur | Signification | À choisir si |
|---|---|---|
| `item_id` (défaut) | L'identifiant d'article (ligne de commande / billet acheté) | Les déposants doivent recopier le « numéro » de leur billet visible sur leur ticket PDF (champ ID de l'item). Utilisez ceci si chaque billet correspond à un dépôt. |
| `order_id` | L'identifiant de la commande | Les déposants doivent saisir le numéro de leur commande HelloAsso (visible dans l'e-mail de confirmation). |
| `custom_field` | Un champ personnalisé de la campagne (la valeur saisie est cherchée dans toutes les réponses aux champs personnalisés) | La campagne HelloAsso comporte un champ personnalisé « numéro de ticket » à faire remplir aux acheteurs ; les déposants saisissent cette valeur. |

**Recommandation** : utilisez un **champ personnalisé** dans votre campagne HelloAsso (par ex. « Code de participation ») et choisissez `custom_field`, car ni les IDs d'ordre ni les IDs d'item ne sont affichés de façon conviviale au participant. Le plugin accepte aussi `order_id` si vous préférez faire saisir le n° de commande de l'e-mail de confirmation.

> Note : l'API v5 de HelloAsso n'expose pas de champ « numéro de ticket » unique normalisé ; c'est pourquoi ce paramétrage existe. Dans tous les cas, les commandes annulées/abandonnées sont refusées.

---

## 3. Page WordPress

- Créer/éditer la page de dépôt, et insérer le shortcode :
  ```
  [depot_appel_couleur]
  ```
  ou, sans les compteurs :
  ```
  [depot_appel_couleur show_counters="no"]
  ```
- Le plugin affiche alors : les compteurs de dépôts (nouvelles / poèmes / photos), le champ « numéro de ticket », le choix du type de dépôt, la zone de sélection de fichier, et un bouton « Déposer ».

Les compteurs sont stockés dans `wp_options` : `dac_count_nouvelles`, `dac_count_poemes`, `dac_count_photos`. Ils sont mis à jour après chaque dépôt réussi et rafraîchis en direct dans la page.

---

## 4. Formats et limites

| Catégorie | Extensions acceptées |
|---|---|
| Nouvelle | `.docx`, `.odt` |
| Poème | `.docx`, `.odt` |
| Photographie | `.jpg`, `.jpeg`, `.tiff` |

- La taille maximale par fichier est configurable (`max_file_mb`, défaut 20 Mo) mais dépend aussi de `upload_max_filesize` / `post_max_size` de PHP — adaptez si nécessaire (les photos TIFF peuvent être lourdes).
- Le fichier est d'abord reçu par WordPress, puis transmis à Dropbox, puis supprimé du serveur local uniquement après succès. En cas d'échec Dropbox, le fichier temporaire est nettoyé.

---

## 5. Récapitulatif des valeurs à saisir (checklist)

- [ ] Dropbox : `dropbox_access_token` (Dropbox App Console → Settings → Generate access token)
- [ ] Dropbox : `dropbox_root` (ex. `/depot-appel-couleur`)
- [ ] Dropbox : `folder_nouvelles`, `folder_poemes`, `folder_photos`
- [ ] HelloAsso : `helloasso_client_id`, `helloasso_client_secret`
- [ ] HelloAsso : `helloasso_org_slug`
- [ ] HelloAsso : `helloasso_form_type` + `helloasso_form_slug` (la campagne de vente)
- [ ] HelloAsso : `helloasso_ticket_field` (`item_id`, `order_id` ou `custom_field`)
- [ ] WordPress : insérer `[depot_appel_couleur]` dans la page de dépôt
- [ ] Serveur : PHP ≥ 8.1, `upload_max_filesize` suffisant, `curl` actif pour les appels API

---

## 6. Sécurité et confidentialité

- Les secrets (Dropbox token, HelloAsso client secret) sont stockés dans `wp_options`, accessibles uniquement aux administrateurs (`manage_options`).
- Le formulaire est protégé par un nonce AJAX et vérifie le ticket **avant** tout transfert vers Dropbox.
- Aucune donnée personnelle n'est conservée sur le serveur WordPress après un dépôt réussi (seuls les compteurs restent).
