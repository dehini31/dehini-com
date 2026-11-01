# dehini.com

Site personnel de Mohammed Dehini.

- `index.html` — la page (statique, sans build). Elle charge son contenu depuis `data.json`.
- `data.json` — tout le contenu éditable : textes, expériences, projets, compétences, liens.
  **Ne pas l'éditer à la main une fois le site installé** — passer par `/admin` (voir plus bas) ;
  après l'installation, le fichier `data.json` réellement affiché vit sur le serveur et n'est
  plus synchronisé avec Git (voir « Comment ça se met à jour »).
- `admin/` — back-office protégé par mot de passe pour modifier textes, images et liens sans
  toucher au code.
- `uploads/` — images et PDF envoyés depuis l'admin (ignoré par Git).
- `elbahiferti/` — page vitrine du laboratoire ELBAHI FERTI — DIAG PMA, publiée sur
  `https://dehini.com/elbahiferti/`. Page statique autonome (français / arabe), ses images et la
  vidéo du logo sont dans `elbahiferti/assets/`. Elle ne dépend ni de `data.json` ni de l'admin.
- `impact/` — le **Défi Impact** (`https://dehini.com/impact/`), arabe / français / anglais :
  1000 questions sur la vie personnelle, sociale et professionnelle (modifiables depuis l'admin),
  impact mondial, comptes (dont « Continuer avec Google »), récits partagés avec « j'aime / je
  n'aime pas » et conversations privées avec leur auteur, page « Soutenir » (carte CIB / Edahabia
  via SofizPay, PayPal, RedotPay, CCP…) et emails de rappel. Voir la section « Défi Impact »
  plus bas.
- `metakpis/` — **Stats Meta KPIs** (`https://dehini.com/metakpis/`), français / anglais : page de
  présentation, connexion (Google ou email), profil détaillé, et l'extension Chrome qui affiche les KPI Meta Ads
  en couleur dans Ads Manager, exporte en Excel (placement, âge, genre, textes des annonces) et propose des
  campagnes à partir des meilleurs KPI. Voir la section « Stats Meta KPIs » plus bas.
- `swanbida/` — **SWANBIDA** (`https://dehini.com/swanbida/`), boutique en ligne de linge de bain, français /
  arabe (RTL) : catalogue, fiche produit avec commande en paiement à la livraison, administration (commandes,
  produits, stock, contenus, livraison, statistiques), Meta Pixel + Conversions API et référencement. Voir la section
  « SWANBIDA » plus bas.
- `robots.txt` — robots du domaine (déclare le sitemap de SWANBIDA ; Google ne lit que celui de la racine).

## Installation (serveur dzSecurity, compte cPanel `dehini91`)

### 1. Cloner le dépôt et publier le code

Dans le **Terminal cPanel** :

```bash
cd ~ && git clone https://github.com/dehini31/dehini-com.git
mkdir -p ~/public_html/admin ~/public_html/uploads
cp -f dehini-com/index.html ~/public_html/index.html
cp -f dehini-com/admin/index.html dehini-com/admin/api.php dehini-com/admin/.htaccess dehini-com/admin/.user.ini ~/public_html/admin/
cp -f dehini-com/uploads/.htaccess ~/public_html/uploads/.htaccess
cp -rf dehini-com/img ~/public_html/
cp -rf dehini-com/elbahiferti ~/public_html/
cp -rf dehini-com/impact ~/public_html/
cp -rf dehini-com/metakpis ~/public_html/
cp -rf dehini-com/swanbida ~/public_html/
cp -f dehini-com/robots.txt ~/public_html/robots.txt
```

### 2. Créer le mot de passe de l'admin

Cette commande génère un mot de passe aléatoire, l'affiche une seule fois (à noter tout de
suite) et écrit son hash dans `admin/config.php` sur le serveur — ce fichier n'est jamais
envoyé sur GitHub :

```bash
php -r '$p = bin2hex(random_bytes(6)); echo "Mot de passe admin : $p\n"; file_put_contents(getenv("HOME")."/public_html/admin/config.php", "<?php\ndefine('"'"'ADMIN_PASSWORD_HASH'"'"', '"'"'".password_hash($p, PASSWORD_DEFAULT)."'"'"');\n");'
```

Le mot de passe s'affiche à l'écran : **note-le, il n'est montré qu'une fois.** Pour le
changer plus tard, relance la même commande.

### 3. Mettre en place le contenu de départ

```bash
cp -f ~/dehini-com/data.json ~/public_html/data.json
```

À partir de là, `~/public_html/data.json` est la version « live » : elle se modifie uniquement
depuis `/admin`, jamais depuis ce dépôt.

### 4. Mise à jour automatique du code

Dans **cPanel → Tâches Cron**, toutes les 5 minutes :

```bash
cd ~/dehini-com && git pull -q && cp -f index.html ~/public_html/index.html && cp -f admin/index.html admin/api.php admin/.htaccess admin/.user.ini ~/public_html/admin/ && cp -f uploads/.htaccess ~/public_html/uploads/.htaccess && cp -rf img ~/public_html/ && cp -rf elbahiferti ~/public_html/ && cp -rf impact ~/public_html/ && cp -rf metakpis ~/public_html/ && cp -rf swanbida ~/public_html/ && cp -f robots.txt ~/public_html/robots.txt
```

Cette commande ne touche jamais `data.json`, `admin/config.php`, `impact/config.php`,
`metakpis/config.php`, `swanbida/config.php`, les bases du défi Impact, de Stats Meta KPIs et de SWANBIDA, ni les
images envoyées dans `uploads/` et `swanbida/media/` : seul le code (`index.html`, `admin/*.php`, `elbahiferti/`,
`impact/`, `metakpis/`, `swanbida/`) est mis à jour.

## Défi Impact (`/impact/`)

Le visiteur répond question par question (impact négatif / aucun / positif), consulte son
résultat à tout moment, raconte ses impacts positifs et découvre, sur ses réponses négatives ou
« aucun impact », les impacts positifs partagés par les autres. Sans compte, les réponses sont
gardées sur l'appareil ; avec un compte, elles sont sauvegardées automatiquement. **Raconter un
impact positif demande un compte** : le texte écrit sans compte est gardé, la fenêtre
d'inscription s'ouvre, et le récit est publié dès que le compte est créé. On peut créer son
compte ou se connecter avec un email et un mot de passe, ou avec **Google** (voir « 6. Connexion
avec Google »).

Sur chaque récit publié, les visiteurs peuvent mettre **« j'aime » ou « je n'aime pas »** (une
réaction par personne, modifiable) et **écrire à l'auteur** : la conversation est privée, seuls
l'auteur et ce lecteur la voient (elle n'apparaît pas dans l'administration). L'auteur voit le prénom du lecteur ;
le lecteur voit le nom choisi pour le récit (ou « auteur anonyme »), jamais le nom du compte de
l'auteur ni les adresses email. Écrire demande un compte. Chacun peut bloquer une conversation, et
reçoit un email quand on lui écrit (au plus un par conversation et par demi-heure, désactivable).
Les conversations sont dans « Messages » (`https://dehini.com/impact/#messages`, bulle en haut).

La page **Soutenir** (`https://dehini.com/impact/#soutenir`) permet de soutenir la plateforme par
carte CIB / Edahabia (paiement en ligne SofizPay, sur le même compte que ecom.dehini.com) et
affiche les autres moyens saisis à la main dans l'admin (PayPal, RedotPay, CCP, BaridiMob…). Voir
« 7. Soutiens ».

L'**impact mondial** (`https://dehini.com/impact/#monde`, bouton globe en haut) montre les
résultats de tous les participants : part d'impacts positifs, « vous et le monde », les trois
univers, la carte des 1000 questions (cliquer une case ouvre la question et ses chiffres), les
questions les plus positives, les plus difficiles, les plus partagées, et les pays. Le pays est
déduit du fuseau horaire du navigateur (jamais de l'adresse IP).

- `impact/index.html` — la page (vitrine + défi + résultat + impact mondial + compte), en arabe,
  français et anglais.
- `impact/admin.html` — l'administration du défi, avec **le même mot de passe que `/admin`**
  (lien « Défi Impact » en bas du menu de `/admin`) : statistiques (dont qui est en ligne en ce
  moment), questions (ajouter, modifier, masquer, supprimer), récits, soutiens et connexion
  Google.
- `impact/questions.json` — les 1000 questions de départ (340 personnelles, 330 sociales, 330
  professionnelles) dans les trois langues. Elles sont copiées dans la base au premier passage ;
  ensuite **c'est l'admin qui fait foi** et modifier ce fichier n'a plus d'effet (il sert
  seulement de secours si l'API ne répond pas).
- `impact/api.php` — questions, comptes (dont Google), synchronisation des réponses, statistiques
  mondiales, récits, « j'aime / je n'aime pas », conversations privées, signalements, soutiens,
  désabonnement.
- `impact/support.php` — retour de la page de paiement et notification de SofizPay (chaque
  paiement est revérifié auprès de SofizPay, montant et compte destinataire compris, avant d'être
  compté).
- `impact/cron.php` — emails de rappel (question du jour, impacts positifs des autres).
- La base de données (SQLite) est créée toute seule au premier passage, **hors de
  `public_html`** : `~/impact-data/impact.sqlite`. Elle n'est jamais dans Git ni écrasée par le
  déploiement, et se met à jour toute seule quand le code évolue. Pour la sauvegarder, il suffit
  de copier ce fichier.

### 1. Créer le compte email d'envoi

Dans **cPanel → Comptes de messagerie**, créer `impact@dehini.com` (c'est l'expéditeur des
emails). Puis, dans le **Terminal cPanel** :

```bash
cp ~/dehini-com/impact/config.example.php ~/public_html/impact/config.php
nano ~/public_html/impact/config.php
```

Renseigner le mot de passe de ce compte email (`IMPACT_SMTP_PASS`) et l'adresse qui reçoit les
récits à valider (`IMPACT_NOTIFY_EMAIL`). Ce fichier n'est jamais envoyé sur GitHub et est
bloqué en accès direct. Sans `config.php`, le site fonctionne quand même et envoie avec la
fonction `mail()` de PHP (moins fiable pour la boîte de réception).

### 2. Tester l'envoi

```bash
php ~/public_html/impact/cron.php --test=ton-adresse@gmail.com
```

Envoie un exemple de chaque email (question du jour, rappel de récit, impacts positifs,
bienvenue, mot de passe oublié, nouveau message, remerciement après un soutien). Ajouter `--lang=ar` ou `--lang=en` pour les autres langues. Si
la connexion SMTP échoue à cause du certificat, essayer `IMPACT_SMTP_HOST` = le nom du serveur
cPanel (visible dans « Configurer le client de messagerie »).

### 3. Programmer les rappels

Dans **cPanel → Tâches Cron**, **une fois par heure** :

```bash
/usr/local/bin/php ~/public_html/impact/cron.php > /dev/null 2>&1
```

À chaque passage (80 emails max, réglable) :

- **Question du jour** — à partir de 9 h (heure d'Alger), une fois par jour, pour chaque compte
  confirmé qui l'a demandée : une question sans réponse avec trois boutons ; un clic enregistre
  la réponse et ouvre le résultat. Quand tout est répondu : un rappel tous les 3 jours pour
  raconter un impact positif pas encore écrit.
- **Impacts positifs des autres** — au plus une fois par semaine : jusqu'à 3 récits publiés par
  d'autres sur les questions où la personne a répondu « négatif » ou « aucun impact » (jamais deux
  fois le même).

Les emails ne partent qu'aux adresses **confirmées** (lien envoyé à l'inscription), dans la
langue du site choisie par la personne, et chacun contient un lien de désabonnement en un clic
(question du jour, impacts positifs ou tout). `php cron.php --dry-run` affiche ce qui partirait
sans rien envoyer.

### 4. Modération

Les récits sont publiés tout de suite (`IMPACT_MODERATION = 'auto'`), sauf ceux qui contiennent
un lien, une adresse ou un numéro de téléphone : ils attendent une validation. Un récit signalé
3 fois est masqué automatiquement. Tout se gère dans l'onglet « Récits » de
`https://dehini.com/impact/admin.html` (publier, masquer, supprimer ; l'ancienne adresse
`moderation.html` y redirige). Avec `IMPACT_MODERATION = 'manual'`, chaque récit attend une
validation avant d'apparaître.

### 5. Gérer les questions

Onglet « Questions » de `https://dehini.com/impact/admin.html` : recherche (texte ou `#numéro`),
filtres par univers et par état (affichées, masquées, traduction manquante), « + Nouvelle
question », « Exporter (JSON) ». Une question peut être écrite dans une seule langue : les autres
langues affichent alors ce texte en attendant la traduction.

- **Masquer** retire la question du défi, des emails et de la carte de l'impact mondial, sans rien effacer :
  on peut la réafficher plus tard avec ses réponses et ses récits.
- **Supprimer** efface définitivement la question avec toutes ses réponses et tous ses récits.
  Son numéro n'est jamais réutilisé.

Les changements apparaissent sur le site dès le chargement suivant de la page.

### 6. Connexion avec Google

Onglet « Réglages » de `https://dehini.com/impact/admin.html`, qui rappelle les étapes :

1. Dans [Google Cloud Console → Identifiants](https://console.cloud.google.com/apis/credentials),
   créer un « ID client OAuth » de type « Application Web » (avec un écran de consentement OAuth
   « Externe » si Google le demande).
2. Dans « Origines JavaScript autorisées », ajouter `https://dehini.com`.
3. Coller l'« ID client » (`…apps.googleusercontent.com`) dans l'admin et enregistrer. Aucun code
   secret n'est nécessaire.

Le bouton « Continuer avec Google » apparaît alors dans la fenêtre de connexion. Un compte Google
dont l'adresse existe déjà sur le défi est relié à ce compte (l'adresse est confirmée par Google).
Un compte créé avec Google n'a pas de mot de passe : « Mot de passe oublié ? » permet d'en choisir
un si besoin. Vider le champ dans l'admin retire le bouton.

### 7. Soutiens

Onglet « Soutiens » de `https://dehini.com/impact/admin.html` :

- **Paiement par carte (SofizPay)** — coller l'identifiant du compte SofizPay (celui de
  ecom.dehini.com : il commence par « G » et compte 56 caractères ; on le trouve dans
  l'application SofizPay, ou dans Paramètres → SofizPay sur ecom.dehini.com), puis cocher
  « Activer ». Le mode test permet d'essayer sans débit réel. Si SofizPay demande une adresse de
  notification, l'admin l'affiche (`https://dehini.com/impact/support.php?hook=1`).
- **Autres moyens** — PayPal, RedotPay, CCP, BaridiMob ou autre : titulaire, identifiant ou
  numéro, lien `https://` facultatif (ex. `https://paypal.me/…`) et note. Ils s'affichent tels quels
  sur la page « Soutenir » avec un bouton « Copier » ; ces versements ne sont pas suivis par le
  site.
- **Liste des soutiens par carte** — montant, nom, email, téléphone, message, statut (payé, en
  attente, échoué). « Revérifier auprès de SofizPay » relance la vérification d'un paiement en
  attente. Chaque soutien payé déclenche un email de remerciement au donateur et un avis à
  `IMPACT_NOTIFY_EMAIL`.

Montants acceptés : de 100 à 200 000 DA. Seul le prénom (et l'initiale du nom) des donateurs qui
l'ont accepté apparaît sur le mur des soutiens ; jamais les montants.

### 8. Statistiques

Onglet « Statistiques » de `https://dehini.com/impact/admin.html`, ouvert en premier à la
connexion :

- **En ligne maintenant** — combien de personnes sont sur le site à l'instant (avec ou sans
  compte), et sur quelle page (défi, impact mondial, messages…). Se rafraîchit toute seule
  toutes les 20 secondes tant que l'onglet est ouvert. La page envoie un petit signal toutes les
  45 secondes tant qu'elle est visible dans le navigateur du visiteur ; au-delà de 150 secondes
  sans signal, la personne n'est plus comptée comme en ligne.
- **Comptes et participation** — comptes créés, adresses confirmées, connexions Google,
  participants, réponses (total, aujourd'hui, cette semaine).
- **Récits, réactions, messages et soutiens** — récits par statut, « j'aime » / « je n'aime
  pas », conversations et messages échangés, soutiens payés et montant collecté.
- **14 derniers jours** — un petit graphique des comptes créés et des réponses données, jour par
  jour.
- **Pays** — les 10 pays les plus représentés (déduits du fuseau horaire, comme pour l'impact
  mondial).
- **Les trois univers** — questions et réponses par univers (personnel, social, professionnel),
  avec le pourcentage de réponses positives.
- **Questions les plus répondues, les plus positives et les plus difficiles** — un aperçu rapide
  sans avoir à ouvrir l'onglet « Questions » (le classement positif/difficile demande au moins
  8 réponses pour être significatif).

## Stats Meta KPIs (`/metakpis/`)

Extension Chrome pour Meta Ads Manager + son site, en français et en anglais.

- `metakpis/index.html` — page de présentation, connexion / inscription (Google ou email + mot de passe, « mot de
  passe oublié »), et **profil** : extension connectée ou non (navigateurs reliés, bouton « Connecter
  l'extension »), connexion Meta Ads, « Mes statistiques » (dernières analyses par compte publicitaire : dépense,
  achats, CPA, ROAS, CTR, CPM, gagnantes / perdantes), campagnes créées (publier / mettre en pause), partage des
  données, activité, suppression du compte.
- `metakpis/extension/` — l'extension (v2.1.0). Téléchargeable sur
  `https://dehini.com/metakpis/download.php` (zip fabriqué à la volée, toujours à jour avec le code déployé).
  Mode d'emploi dans `metakpis/extension/README.md`.
- `metakpis/api.php` — comptes, jetons de l'extension, statistiques partagées, export Meta (âge, genre, placement,
  textes), création des campagnes proposées et administration. `metakpis/meta.php` — connexion Facebook (Meta Ads).
- `metakpis/admin.html` — administration, **même mot de passe que `/admin`** (lien « Stats Meta KPIs » dans le menu
  de `/admin`) : utilisateurs, KPI agrégés des données partagées, erreurs Meta, réglages Google et app Meta.
- La base SQLite est créée toute seule **hors de `public_html`** : `~/metakpis-data/metakpis.sqlite`, avec
  `secret.key` (clé qui chiffre les accès Meta). Ne jamais les mettre dans Git ; pour sauvegarder, copier le dossier.

Le compte est obligatoire pour utiliser l'extension : sans compte, le panneau affiche « Connectez-vous ». Le lien se
fait en un clic depuis le profil (« Connecter l'extension ») ; déconnecter un navigateur depuis le profil remet
l'extension sur l'écran de connexion.

**Partage des données.** Sous les boutons de connexion, une note discrète (petits caractères gris) indique que
l'extension partage les statistiques lues (KPI, ventilations, textes) pour alimenter le profil et améliorer
l'extension. Le partage est activé par défaut, désactivable et supprimable dans le profil (« Données et
confidentialité »). Il n'envoie que le résumé de chaque analyse (KPI par ligne, 500 lignes max), jamais de jeton
Facebook ni de données de clients. La note reste lisible exprès : une mention invisible ne vaut pas accord (RGPD,
loi algérienne 18-07) et le Chrome Web Store exige une information visible avant toute collecte.

### 1. Connexion avec Google

Rien à faire si le bouton Google fonctionne déjà sur le défi Impact : le même ID client est repris
automatiquement (même origine `https://dehini.com`). Sinon, le coller dans `metakpis/admin.html` → Réglages.

### 2. App Meta (export âge / genre / placement, textes, campagnes)

Le tableau de bord marche sans elle ; l'export détaillé et les campagnes proposées passent par l'API Marketing
officielle de Meta. **Par défaut, Stats Meta KPIs reprend l'app Meta d'ecom.dehini.com** : son ID et sa clé secrète
sont lus dans `~/ecom/.env` (`META_APP_ID`, `META_APP_SECRET`), sur le même compte cPanel. Rien n'est recopié : si
la clé change dans ecom, `/metakpis` suit. Une seule étape est nécessaire, dans cette même app, sur
[developers.facebook.com/apps](https://developers.facebook.com/apps) :

1. **Facebook Login → Paramètres → URI de redirection OAuth valides** : ajouter
   `https://dehini.com/metakpis/meta.php` (en gardant celle d'ecom, `https://ecom.dehini.com/publicites/callback`).
2. **Paramètres → Général → Domaines de l'app** : `dehini.com` doit y figurer (en plus de `ecom.dehini.com`).

`metakpis/admin.html` → Réglages indique quelle app est utilisée. Saisir un autre ID et une autre clé dans l'admin
remplace l'app d'ecom ; vider les deux champs revient à celle d'ecom. Les autorisations demandées (`ads_read`,
`ads_management`) font partie de celles qu'ecom demande déjà : si l'app est validée par Meta pour ecom, elle l'est
aussi pour `/metakpis`. Sinon, seuls ses administrateurs, développeurs et testeurs peuvent se connecter.

Chaque utilisateur clique ensuite sur « Connecter Meta Ads » dans son profil. L'accès dure environ 60 jours ; le
profil prévient quand il faut le renouveler.

### 3. Campagnes proposées

L'extension choisit la meilleure annonce de la période (verdict, puis ROAS, puis CPA), les âges, le genre et les
placements rentables (CPA dans l'objectif réglé dans l'extension, ou ROAS au moins orange ; ceux qui dépensent sans
vendre sont écartés), et propose un budget quotidien. Tout est modifiable avant la création. Le serveur crée alors
une campagne (budget de campagne, coût le plus bas), un ensemble (ciblage de l'ensemble source : lieux, centres
d'intérêt, audiences, pixel ; avec l'âge, le genre et les placements choisis) et une publicité (mêmes visuels, textes
modifiés) — **tout en pause**. En cas d'erreur Meta, ce qui a été créé est supprimé et le message de Meta s'affiche.
« Publier » active la publicité, l'ensemble puis la campagne.

### 4. Diffuser l'extension

Aujourd'hui : téléchargement du zip et « Charger l'extension non empaquetée » (étapes sur la page). Pour le Chrome
Web Store : zipper `metakpis/extension/` (ou utiliser `download.php`), fiche en français et anglais, politique de
confidentialité `https://dehini.com/metakpis/#privacy`, justification des autorisations : `storage` (jeton du
compte et réglages) et accès à `dehini.com` (compte et statistiques). L'ID de l'extension change avec le Store : le
lien « Connecter l'extension » n'en dépend pas.

## SWANBIDA (`/swanbida/`)

Boutique de la marque SWANBIDA 🦢 — *L'élégance dans chaque détail* — en français (`/swanbida/`) et en arabe
(`/swanbida/ar/`, de droite à gauche), pensée d'abord pour le téléphone (trafic Facebook / Instagram). PHP + SQLite,
sans dépendance ni étape de construction, comme le reste du site.

| Adresse | Page |
| --- | --- |
| `/swanbida/` | Accueil : présentation, pack phare, offre spéciale, collections, emballage, avantages, guides |
| `/swanbida/boutique`, `/swanbida/boutique/<collection>` | Catalogue, collections, tri, « en stock uniquement » |
| `/swanbida/produit/<produit>` | Fiche produit : galerie photos / vidéos (glisser, zoom plein écran), offres, formulaire de commande |
| `/swanbida/notre-histoire`, `/livraison`, `/contact`, `/confidentialite` | Pages de la marque |
| `/swanbida/guides`, `/swanbida/guides/<guide>` | Guides (3 livrés : choisir ses serviettes, idées cadeaux, entretien) |
| `/swanbida/suivi` | Suivi de commande (numéro + téléphone) |
| `/swanbida/commande/<n°>?k=…` | Remerciement puis suivi (lien privé) |
| `/swanbida/admin/` | Administration |
| `/swanbida/sitemap.xml`, `/swanbida/robots.txt` | Référencement |

Chaque page existe en arabe sous `/swanbida/ar/…`.

- `swanbida/index.php` — toutes les pages (rendu serveur : titres, descriptions, canonical, hreflang fr-DZ / ar-DZ,
  Open Graph, données structurées Schema.org Organization, WebSite, Product, BreadcrumbList, Article).
- `swanbida/api.php` — commande, wilayas / communes, événements de navigation. `swanbida/admin/` — administration.
- `swanbida/cron.php` — renvoi des événements Meta, sauvegarde quotidienne, purge des données techniques.
- `swanbida/lib/` (code), `swanbida/templates/` (gabarits) — bloqués en accès direct.
- `swanbida/assets/` — style, script, logo, icônes et **visuels provisoires** (illustrations du pack, à remplacer par
  les vraies photos depuis l'admin). `swanbida/media/` — photos et vidéos envoyées depuis l'admin (hors Git).
- La base SQLite est créée toute seule **hors de `public_html`** : `~/swanbida-data/swanbida.sqlite`, avec
  `secret.key` (chiffre le jeton Meta et celui du bot Telegram) et `backups/`. Ne jamais les mettre dans Git.

Hébergement : PHP 7.4 ou plus récent avec `pdo_sqlite`, `gd` (avec WebP), `openssl`, `mbstring` ; `curl`, `intl`,
`zip` et `exif` sont utilisés s'ils sont présents. HTTPS est imposé par `swanbida/.htaccess`.

### 1. Première mise en route

1. Déployer (commandes de l'« Installation » plus haut) puis ouvrir `https://dehini.com/swanbida/` : la base, le
   produit **SWANBIDA – Pack Serviette Blanc** (7 750 DA ; 2 packs 13 500 DA au lieu de 15 500 DA ; stock de départ
   100), les deux collections, les trois guides et les 58 wilayas avec leurs 1 541 communes se créent tout seuls.
2. Se connecter sur `https://dehini.com/swanbida/admin/` **avec le mot de passe de `/admin`** (propriétaire, champ
   email vide). Lien « 🦢 SWANBIDA » aussi en bas du menu de `/admin`.
3. **Contenus → Général** : WhatsApp, téléphone, email, Facebook, Instagram, TikTok (tant qu'ils sont vides, les
   boutons correspondants n'apparaissent pas).
4. **Produits → Pack Serviette Blanc → Photos** : remplacer les illustrations provisoires par les vraies photos
   (JPG / PNG / WebP ; converties automatiquement en WebP 480 / 960 / 1600 px, métadonnées retirées) et renseigner
   les textes alternatifs FR / AR. Vidéos MP4 (60 Mo max) ou liens YouTube possibles. Vérifier le stock réel.
5. **Livraison** : frais par wilaya (0 = gratuit, valeur par défaut) ; « Appliquer à toutes les wilayas » pour un
   tarif unique. La livraison au bureau du transporteur (stop desk) s'active dans **Réglages**.
6. **Meta & suivi** puis **Réglages** (notifications) : voir ci-dessous.
7. **cPanel → Tâches Cron**, toutes les 10 minutes :

```bash
/usr/local/bin/php ~/public_html/swanbida/cron.php > /dev/null 2>&1
```

Si l'envoi de photos échoue avec « Fichier trop lourd », relever `upload_max_filesize` / `post_max_size` dans
**cPanel → MultiPHP INI Editor** (`swanbida/admin/.user.ini` demande 64 Mo).

### 2. Commandes (paiement à la livraison)

Le formulaire est directement sur la fiche produit : offre (1 ou 2 packs, offres modifiables dans l'admin), nom et
prénom, téléphone, wilaya, commune (liste selon la wilaya, ou saisie libre « Autre commune »), adresse, remarque
facultative. Le montant (offre + frais de la wilaya) est **recalculé par le serveur**, jamais pris du navigateur.
Chaque commande reçoit un numéro unique lisible au téléphone (`SW-7K4M2Q`) et un lien de suivi privé.

- Validation côté serveur de chaque champ (numéros algériens 05 / 06 / 07, +213, chiffres arabes acceptés).
- Anti-doublons : double clic ou retour arrière → même commande (pas de second Lead) ; même téléphone et même produit
  dans les 24 h → commande acceptée mais marquée « doublon possible ». Champ piège anti-robots et limites par
  connexion (8 commandes / heure, réglable) et par téléphone.
- Stock décompté à la commande, rendu si la commande est annulée ou retournée ; rupture → formulaire remplacé par
  un bouton WhatsApp (ou commandes acceptées si « accepter en rupture » est coché). Alerte stock bas par email /
  Telegram.
- Sans JavaScript, le formulaire fonctionne quand même (envoi classique).

**Administration → Commandes** : onglets par statut (nouvelle, confirmée, en préparation, expédiée, livrée,
annulée, retournée) avec compteurs, recherche (n°, nom, téléphone, commune, n° de suivi), filtres (wilaya, dates,
source, doublons, campagne / publicité depuis le tableau de bord), changement de statut groupé, export Excel / CSV
(séparateur « ; », UTF-8). Fiche commande : boutons Appeler et WhatsApp (message de confirmation pré-rempli,
modifiable dans Réglages), modification du client, de l'adresse, de l'offre, des frais, du transporteur et du n° de
suivi (affichés au client sur sa page de suivi), notes internes, historique complet, origine publicitaire et
événements Meta envoyés.

**Notifications** de chaque nouvelle commande : email (jusqu'à 5 adresses, via le compte SMTP du défi Impact si
`swanbida/config.php` n'en définit pas d'autre), **Telegram** (créer un bot avec @BotFather, coller son jeton et
l'identifiant du chat dans Réglages → « Enregistrer et tester »), et dans l'admin ouverte : signal sonore,
notification du navigateur (« Activer les alertes ») et compteur des commandes à confirmer.

**Équipe** (Réglages) : comptes email + mot de passe avec deux rôles — *Gestion* (commandes, produits, contenus,
livraison, statistiques) et *Confirmation* (commandes uniquement, idéal pour les appels de confirmation). Le
propriétaire seul accède à Meta, aux réglages, à l'équipe et aux sauvegardes.

**Tableau de bord** : commandes, montants demandés / confirmés / encaissés, taux de confirmation, de livraison,
d'annulation et de retour, graphique par jour, tunnel (visites → vues produit → ajouts → débuts de commande →
commandes → confirmées → livrées), performances par **campagne, publicité, source et wilaya**, santé du suivi Meta.

### 3. Meta Pixel et Conversions API

Dans **Meta & suivi** : ID du pixel (jeu de données), jeton d'accès Conversions API (Gestionnaire d'événements →
Paramètres → « Générer un jeton d'accès » ; stocké chiffré, jamais renvoyé au navigateur) et, pour les essais, le
« code de test » de l'onglet *Tester les événements* (« Envoyer un événement test » vérifie la connexion ; **vider le
code de test ensuite**, sinon les événements restent en mode test). La balise `facebook-domain-verification` se
colle au même endroit.

| Événement | Quand | Envoyé par |
| --- | --- | --- |
| PageView | chaque page | navigateur + serveur, même `event_id` |
| ViewContent | fiche produit | navigateur + serveur |
| AddToCart | choix d'une offre ou clic « Commander » | navigateur + serveur |
| InitiateCheckout | première saisie dans le formulaire | navigateur + serveur |
| Contact | clic WhatsApp / téléphone | navigateur + serveur |
| **Lead** | **demande de commande enregistrée** | navigateur + serveur, `event_id` = `lead.<n°>`, une seule fois |
| **Purchase** | **commande confirmée par l'équipe** (statut « confirmée » ou plus loin) | **serveur uniquement**, une seule fois, `event_id` = `purchase.<n°>` |
| OrderDelivered | commande livrée | serveur uniquement (`delivered.<n°>`) |

- **Déduplication** : chaque événement part avec un identifiant unique, identique côté navigateur et côté serveur.
- **Purchase n'est jamais envoyé par le navigateur** : la page de remerciement n'envoie aucune conversion, l'ouvrir
  ou la rafraîchir ne change rien. Le statut commercial qui déclenche Purchase est la **confirmation** (le client a
  confirmé au téléphone) : c'est le signal le plus fiable qui arrive assez vite pour optimiser les campagnes. Le
  réglage « Quand envoyer Purchase » permet de choisir la **livraison** à la place (un événement `OrderConfirmed`
  marque alors la confirmation). Les livraisons sont toujours suivies à part (`OrderDelivered`, utilisable comme
  conversion personnalisée). Une commande annulée après confirmation reste comptée par Meta (Meta ne permet pas
  d'annuler un événement) : l'historique de la commande le signale.
- **Données envoyées côté serveur** : IP et navigateur du client, cookies `_fbp` / `_fbc` (reconstruit depuis le
  `fbclid` de la publicité), identifiant visiteur, et — hachés en SHA-256 — téléphone (+213), prénom, nom, commune,
  wilaya, pays. L'adresse n'est jamais envoyée. Valeur, devise DZD, `content_ids` (SKU), quantités et `order_id`.
- Les événements de commande passent par une file d'attente : envoyés juste après la réponse au client, renvoyés
  par le cron en cas d'échec (6 essais), journal consultable et « Réessayer » dans **Meta & suivi**.
- **Attribution** : coller dans Ads Manager (Publicité → Paramètres d'URL) la chaîne affichée dans **Meta & suivi** :

```text
utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.name}}&utm_term={{adset.name}}&utm_content={{ad.name}}&campaign_id={{campaign.id}}&adset_id={{adset.id}}&ad_id={{ad.id}}&placement={{placement}}&site_source_name={{site_source_name}}
```

  Chaque commande garde alors (dernier clic, 30 jours) campagne, ensemble, publicité, placement, UTM, `fbclid`,
  page d'arrivée, valeur et numéro de commande ; le tableau de bord les regroupe par campagne et par publicité.

### 4. Référencement (Google)

Titres et descriptions uniques par page et par langue (modifiables : Contenus → Référencement, et chaque produit,
collection et guide), adresses courtes, `canonical`, `hreflang` fr-DZ / ar-DZ / x-default, textes alternatifs des
images, données structurées (Organization, WebSite, Product avec prix DZD, disponibilité, livraison et politique
d'échange, BreadcrumbList, ItemList, Article), `sitemap.xml` avec les versions des deux langues, et `robots.txt` à la
racine du domaine (livré dans ce dépôt) qui déclare le sitemap.

**Google Search Console** : ajouter une propriété « préfixe d'URL » `https://dehini.com/swanbida/`, choisir la
validation par balise HTML, coller la balise dans **Réglages → Google Search Console**, valider, puis déclarer le
sitemap `https://dehini.com/swanbida/sitemap.xml`. (Si le domaine `dehini.com` est déjà validé, déclarer seulement
le sitemap.)

### 5. Sauvegardes

Le cron fait chaque jour une copie compressée de la base dans `~/swanbida-data/backups/` (30 jours conservés).
**Réglages → Sauvegardes** : « Sauvegarder maintenant », télécharger la base ou une archive base + photos. Pour
restaurer : remettre `swanbida.sqlite` dans `~/swanbida-data/` (et `media/` dans `~/public_html/swanbida/media/`).

### 6. Ajouter des produits, collections et guides

**Produits → + Nouveau produit** : textes FR / AR, offres et prix, photos, vidéos, contenu, caractéristiques,
emballage, stock, collections, référencement. « Brouillon » le cache aux visiteurs (aperçu possible quand on est
connecté à l'admin). **Collections** et **Guides** fonctionnent de la même façon ; un guide apparaît dans une langue
dès que son titre et son texte y sont écrits.

### 7. Wilayas

La liste livrée est celle des **58 wilayas** et de leurs communes, utilisée par les sociétés de livraison. Le
nouveau découpage (69 wilayas) entre en vigueur progressivement : **Livraison → Ajouter une wilaya** permet
d'ajouter les wilayas 59 à 69 et leurs communes le moment venu, sans toucher au code.

### 8. Tests

`tests/swanbida/` contient un faux hébergement et une batterie de tests dans un vrai navigateur : voir
`tests/swanbida/README.md`. Rien de ce dossier n'est copié sur le serveur.

## Utiliser l'admin

1. Ouvrir `https://dehini.com/admin/`.
2. Se connecter avec le mot de passe généré à l'étape 2.
3. Modifier les textes, glisser une nouvelle image (elle est envoyée automatiquement), changer
   un lien, ajouter/supprimer/réordonner une expérience ou un projet avec les flèches et le ✕.
4. Cliquer sur **Enregistrer** (ou Ctrl+S). Le site est mis à jour immédiatement, sans
   attendre le cron.

### Projets avec PDF interactif

Dans l'onglet **Projets**, chaque projet a une photo, un titre, une description et, en option,
un **document PDF** (bouton « Choisir un fichier », 30 Mo max). Sur le site, le projet affiche
alors le badge « PDF interactif » et un bouton **Feuilleter le document** : le PDF s'ouvre
comme un livre dont on tourne les pages (glisser le coin, clic, flèches du clavier, balayage
sur mobile), avec zoom, plein écran, liens cliquables et téléchargement. Sur téléphone ou
fenêtre étroite, les pages s'affichent une par une ; la double page n'apparaît que lorsque
l'écran est assez large pour deux pages en pleine hauteur. Un PDF au format paysage
(présentation) s'affiche toujours une page à la fois.

Si l'envoi d'un PDF échoue avec « Fichier trop lourd pour le serveur », la limite PHP de
l'hébergeur est trop basse : le fichier `admin/.user.ini` la relève à 32 Mo, et sinon il faut
régler `upload_max_filesize = 32M` et `post_max_size = 34M` dans **cPanel → MultiPHP INI
Editor**.

Dans les champs de titre, `**mot**` affiche le mot en bleu (accent) et, sur le titre principal
uniquement, `__mot__` l'affiche en gris clair.

## Comment ça se met à jour

- **Code** (mise en page, back-office) : modifié ici sur GitHub → récupéré automatiquement sur
  le serveur toutes les 5 minutes par le cron.
- **Contenu** (textes, projets, images, liens) : modifié directement sur le serveur depuis
  `/admin` → jamais écrasé par un `git pull`, jamais renvoyé vers GitHub.

Le `data.json` de ce dépôt ne sert qu'à amorcer une nouvelle installation (étape 3) ; il n'est
pas mis à jour automatiquement après coup.

## Sécurité

- L'admin est protégé par mot de passe (haché avec `password_hash`, jamais stocké en clair) et
  un jeton anti-CSRF sur chaque écriture.
- Les fichiers envoyés sont vérifiés sur leur contenu réel, pas sur leur nom : images (jpg, png,
  webp, gif — 6 Mo max) ou PDF (signature `%PDF-` — 30 Mo max). Le dossier `uploads/` refuse
  d'exécuter un script.
- `admin/config.php` contient le hash du mot de passe : il est exclu de Git (`.gitignore`) et
  bloqué en accès direct par `admin/.htaccess`.
- Défi Impact : mots de passe des comptes hachés (`password_hash`), sessions et liens (confirmation,
  mot de passe oublié valable 1 h, désabonnement) à jetons aléatoires, jeton anti-CSRF sur les
  écritures d'un compte connecté, limites d'essais par IP (connexion, inscription, récits, « j'aime »,
  messages, signalements, soutiens), IP jamais stockées en clair. `impact/config.php`,
  `impact/cron.php` et `impact/lib/` sont bloqués en accès direct ; la base est hors de
  `public_html`.
- Connexion Google : le jeton d'identité est vérifié sur le serveur (signature avec les clés
  publiques de Google, émetteur, destinataire, expiration, adresse confirmée).
- Conversations privées : seuls leurs deux participants peuvent les lire ou y écrire ; une
  personne bloquée ne peut plus écrire.
- Soutiens par carte : les données de carte ne passent jamais par le site (page de paiement SATIM) ;
  un paiement n'est compté qu'après vérification serveur auprès de SofizPay (statut, montant et
  compte destinataire), jamais sur la seule foi du retour navigateur ou de la notification.
- « En ligne maintenant » (admin) : seul le nombre de personnes et la page qu'elles consultent
  sont gardés, pendant 150 secondes au plus (nettoyé automatiquement après une heure) ; rien de
  plus n'est retenu sur ce signal.
- Stats Meta KPIs : mots de passe hachés, sessions à jeton aléatoire + anti-CSRF, jetons de l'extension stockés
  hachés (un par navigateur, révocables depuis le profil), limites d'essais. L'accès Meta est chiffré
  (AES-256-GCM, clé `secret.key` hors `public_html`) et chaque appel à Meta est signé (`appsecret_proof`). La page
  Ads Manager ne voit jamais le jeton du compte : seul le service worker de l'extension parle au serveur, pour une
  liste fermée d'actions. `metakpis/config.php` et `metakpis/lib/` sont bloqués en accès direct.
- SWANBIDA : administration à sessions (cookie `HttpOnly`, `Secure`, `SameSite=Strict`, expiration après 4 h
  d'inactivité et 12 h au plus), anti-CSRF sur chaque écriture, rôles, limites d'essais de connexion, mots de passe
  hachés ; validation serveur de toutes les commandes ; politique de sécurité du contenu (CSP) avec nonce sur les
  pages publiques ; jeton Conversions API et jeton Telegram chiffrés (AES-256-GCM, clé hors `public_html`) ;
  `lib/`, `templates/`, `config.php`, `cron.php` bloqués en accès direct ; aucun script exécutable dans `media/` ;
  IP et navigateur des commandes effacés au bout de 45 jours ; exports CSV protégés contre les formules Excel.
