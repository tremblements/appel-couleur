# Paramétrage de Contribalapelle

Toutes les valeurs se saisissent dans **Réglages → Contribalapelle** (admin WordPress, capacité
`manage_options`). Aucun secret n'est écrit dans le code : tout est stocké dans `wp_options`.

## 1. Appel et fenêtre de dépôt

| Champ | Où le trouver | Remarque |
| --- | --- | --- |
| Nom de l'appel | — | Sert à nommer le dossier Dropbox : `Depots-<nom>/` avec **un sous-dossier par catégorie** (`fiction_500`, `fiction_250`, `haiku`, `poeme_long`, `poeme_court`, `photographie`, `illustration`). Ex. `purple`. |
| Nom de l'appel affiché | défaut = nom de l'appel | Utilisé dans les e-mails et messages (ex. « Purple — Pourtant couleur n°13 »). |
| Ouverture du dépôt | décision de l'équipe | Format `JJ/MM/AAAA HH:MM`, fuseau **Europe/Paris**. |
| Clôture du dépôt | décision de l'équipe | Idem. Hors fenêtre, le formulaire est en lecture seule et tout dépôt est refusé. |
| Taille de la liste de codes humains | 5000 par défaut (10–50 000) | Mots français de 6 lettres échantillonnés dans `contribalapelle/assets/mots-francais.json` (remplaçable par le `words.json` complet de an-array-of-french-words, ~44 600 mots de 6 lettres), puis bouton « Régénérer la liste de codes ». Tirage de préférence sans doublon avec les codes déjà attribués. Nom des fichiers déposés : `date-numero_ticket-code_humain.ext`. Le code est tiré une fois par ticket. |

## 2. Libellés des sous-catégories

Sept catégories : fiction 500, fiction 250, haïku, poème long, poème court, **photographie**, **illustration**
(texte : odt/docx/pdf ; photographie et illustration : jpg/jpeg/png/tif/tiff). Les libellés affichés sur le
formulaire sont modifiables (utile pour chaque nouvel appel). Un dossier Dropbox est créé par catégorie.

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
| Identifiants de test | un n° de billet ou e-mail par ligne : acceptés **sans appel à l'API HelloAsso** (commande fictive « TEST »), pour retester le parcours de dépôt en recette. **Laisser vide en production.** |

Seules les commandes **payées/autorisées** (`state = Authorized`) valident un identifiant. L'e-mail du
payeur est lu sur la commande (message de confirmation) et sert aussi à retrouver le ticket quand le
déposant s'identifie par e-mail. Si plusieurs billets ont été achetés avec la même adresse, l'e-mail
désigne le premier billet non encore déposé.

## 4. Dropbox

| Champ | Où le trouver |
| --- | --- |
| Access Token | Procédure ci-dessous (App Console) |
| Dossier racine | créé automatiquement : `Depots-<nom-de-l'appel>/fiction|poesie|image/` |
| Poids max par fichier | 25 Mo par défaut (le serveur PHP doit avoir `upload_max_filesize` ≥ cette valeur) |

### Créer l'app Dropbox et obtenir l'Access Token

1. Connectez-vous à Dropbox avec **le compte de la revue** (celui qui contiendra les dépôts), puis ouvrez [Dropbox App Console](https://www.dropbox.com/developers/apps) → **Create app**.
2. Choisissez **Scoped access**, puis le type d'accès :
   - **App folder** (recommandé) : le dossier `Depots-<nom>/` sera créé dans `Apps/<nom-de-l'app>/` — isolé du reste du compte ;
   - **Full Dropbox** : `Depots-<nom>/` sera créé **à la racine** du compte Dropbox (à côté de votre dossier existant).
   Nom de l'app : ex. `Contribalapelle`. Validez.
3. Onglet **Permissions** : cochez `files.content.write` (upload + suppression), `files.content.read`, `files.metadata.read`, `files.metadata.write` → **Submit**.
4. Onglet **Settings** → section **OAuth 2** → cliquez **Generate** sous « Generated access token » → copiez le token (commence par `sl.`). S'il est proposé, réglez l'expiration sur **« No expiration »** (sinon le token expire au bout de ~4 h et il faudra le régénérer — on peut ajouter le support refresh token au plugin si besoin).
5. Collez le token dans **Réglages → Contribalapelle → Access Token Dropbox**, enregistrez.

Le token est un secret : il est stocké dans `wp_options` et n'est jamais journalisé ni affiché côté public.

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

Menu admin **Contribalapelle** (`admin.php?page=contribalapelle-suivi`) : journal des opérations
(vérifications et dépôts, réussis ou refusés avec motif) et table des tickets (correspondance commande
HelloAsso, sous-catégorie, code humain, chemin Dropbox, horodatages), avec **suppression individuelle**
d'un ticket (utile pour les tests) et bouton **« Purger toutes les données (RGPD) »** à utiliser après la
publication du numéro.
