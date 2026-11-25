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
| Client ID / Client Secret | C'est la **clé API du compte association** (paire OAuth 2.0). Sur **www.helloasso.com**, connecté avec le compte **administrateur de l'association `pourtant`** : **Mon compte → Intégrations et API** (boutons copier pour Client ID et Client Secret). [dev.helloasso.com](https://dev.helloasso.com) ne sert qu'aux applications « partenaires » multi-associations. La clé est scopée à l'organisation qui l'a émise. Pour tester : environnement sandbox helloasso-sandbox.com (sa propre clé) + URL d'API `https://api.helloasso-sandbox.com/v5` |
| Slug de l'organisation | dev.helloasso.com → organisation, ou URL de la page publique HelloAsso. **Appel Purple N°13 en cours : `pourtant`** |
| Type de formulaire | `Event` pour une billetterie d'événement (appel à contributions), sinon `PaymentForm`, etc. **Appel Purple : `Event`** |
| Slug de la billetterie | dans l'URL publique de la billetterie : `helloasso.com/associations/<org>/evenements/<slug>` (bouton « Voir la page publique » dans le backoffice HelloAsso ; aucun ID numérique n'est nécessaire pour l'API v5). **Appel Purple : `participation-a-pourtant-13-purple`** |

La vérification ne porte **que** sur cette billetterie : l'API interrogée est
`/organizations/{org}/forms/{type}/{slug}/orders` — un billet acheté sur une autre campagne
(adhésion, autre appel) ne sera jamais accepté.

Après avoir enregistré les réglages, cliquez sur **« Tester la connexion à la billetterie »**
(sur la page des réglages) : le plugin compte les commandes payées de la billetterie et confirme
que le slug est correct.
| Champ de vérification du ticket | `item_id` (n° d'article, défaut), `order_id` (n° de commande) ou `custom_field` (champ personnalisé du formulaire de vente) selon ce qui figure sur le billet |
| Identification par e-mail | case cochée par défaut dans les réglages : le déposant peut saisir, à la place du n° de ticket, **l'adresse e-mail utilisée pour l'achat** sur HelloAsso |

Seules les commandes **payées/autorisées** (`state = Authorized`) valident un identifiant. L'e-mail du
payeur est lu sur la commande (message de confirmation) et sert aussi à retrouver le ticket quand le
déposant s'identifie par e-mail. Si plusieurs billets ont été achetés avec la même adresse, l'e-mail
désigne le premier billet non encore déposé.

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
