# Paramétrage de Contribalapelle

Toutes les valeurs se saisissent dans **Réglages → Contribalapelle** (admin WordPress, capacité
`manage_options`). Aucun secret n'est écrit dans le code : tout est stocké dans `wp_options`.

## 1. Appel et fenêtre de dépôt

| Champ | Où le trouver | Remarque |
| --- | --- | --- |
| Nom de l'appel | — | Sert à nommer le dossier Dropbox : `Depots-<nom>/` avec sous-dossiers `fiction`, `poesie`, `image`. Ex. `appel-couleur`. |
| Ouverture du dépôt | décision de l'équipe | Format `JJ/MM/AAAA HH:MM`, fuseau **Europe/Paris**. |
| Clôture du dépôt | décision de l'équipe | Idem. Hors fenêtre, le formulaire est en lecture seule et tout dépôt est refusé. |

## 2. Libellés des sous-catégories

Cinq sous-catégories de texte (fiction 500, fiction 250, haïku, poème long, poème court) et la catégorie
image : les libellés affichés sur le formulaire sont modifiables (utile pour chaque nouvel appel).

## 3. HelloAsso (vérification des tickets)

| Champ | Où le trouver |
| --- | --- |
| URL de l'API | `https://api.helloasso.com/v5` (production) — sandbox : `https://api.helloasso-sandbox.com/v5` |
| Client ID / Client Secret | [dev.helloasso.com](https://dev.helloasso.com) → votre application (OAuth 2.0, grant `client_credentials`) |
| Slug de l'organisation | dev.helloasso.com → organisation, ou URL de la page publique HelloAsso |
| Type de formulaire | `Event` pour une billetterie d'événement (appel à contributions), sinon `PaymentForm`, etc. |
| Slug de la billetterie | dans l'URL de la billetterie : `helloasso.com/associations/<org>/evenements/<slug>` |
| Champ de vérification du ticket | `item_id` (n° d'article, défaut), `order_id` (n° de commande) ou `custom_field` (champ personnalisé du formulaire de vente) selon ce qui figure sur le billet |

Seules les commandes **payées/autorisées** (`state = Authorized`) valident un ticket. L'e-mail du payeur
est lu sur la commande pour le message de confirmation.

## 4. Dropbox

| Champ | Où le trouver |
| --- | --- |
| Access Token | [Dropbox App Console](https://www.dropbox.com/developers/apps) → app du compte de la revue → onglet Settings → « Generated access token » (ou OAuth avec refresh token selon l'app). Portée : `files.content.write`, `files.content.read`, `files.metadata.write` |
| Dossier racine | créé automatiquement : `Depots-<nom-de-l'appel>/fiction|poesie|image/` |
| Poids max par fichier | 25 Mo par défaut (le serveur PHP doit avoir `upload_max_filesize` ≥ cette valeur) |

## 5. E-mail de confirmation et confidentialité

- Case « Envoi de l'e-mail de confirmation » : e-mail automatique après chaque dépôt réussi (adresse lue
  sur la commande HelloAsso ; à défaut, e-mail saisi dans le formulaire ; à défaut, message affiché à l'écran).
- Avis RGPD affiché sur le formulaire : modifiable (traitement des données, base légale, durée de
  conservation jusqu'à la publication du numéro, politique de confidentialité HelloAsso).

## 6. Prérequis serveur

- Site en **HTTPS**.
- PHP ≥ 8.1, `upload_max_filesize` et `post_max_size` ≥ 25 Mo, `max_execution_time` suffisant (120 s conseillé).
- Le fuseau de la fenêtre est géré en `Europe/Paris` par le plugin, indépendamment du réglage WordPress.

## 7. Suivi

Menu admin **Contribalapelle** : journal des opérations (vérifications et dépôts, réussis ou refusés avec
motif) et table des tickets (correspondance commande HelloAsso, sous-catégorie, chemin Dropbox, horodatages).
Suppression des données après publication du numéro (intervention manuelle ou cron selon l'organisation choisie).
