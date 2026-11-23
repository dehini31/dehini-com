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
- `marketing/` — **Marketing Dashboard** (`https://dehini.com/marketing/`), réservé à l'administration (même mot de
  passe que `/admin`) : suivi marketing de YINVESTI saisi à la main (leads, questions, projets, social media, publicité,
  site, emails, funnels) et rapports générés automatiquement chaque jour, chaque semaine et chaque mois. Voir la
  section « Marketing Dashboard » plus bas.
- `maillage/` — **Maillage** (`https://dehini.com/maillage/`), plateforme de gestion des points de vente et des
  réseaux de distribution : landing page d'inscription, carte interactive des points de vente (OpenStreetMap),
  pages des marques et catalogue, disponibilité d'un produit par magasin, commandes et réservations, espace
  entreprise (marques et distributeurs) et analyses commerciales. Voir la section « Maillage » plus bas.
- `studioma/` — **Studioma** (`https://dehini.com/studioma/`), réseau social et studio collaboratif des community
  managers, en anglais : landing, comptes individuels et entreprises, espace de travail privé (projets → Studios →
  niveaux → colonnes → cartes, glisser-déposer, passage de relais entre niveaux, calendrier, « My work »),
  invitations et rôles, profils publics, fil d'actualité, portfolio et preuves de travail, opportunités, annuaire
  des talents et messagerie. Voir la section « Studioma » plus bas.
- `img/projets/` — captures d'écran des plateformes de la vitrine de la section Projets (voir « Vitrine des
  plateformes en ligne ») ; `tools/vitrine/captures.js` les refait.
- `robots.txt` — robots du domaine (déclare les plans du site ; Google ne lit que celui de la racine).
- `sitemap.xml` — plan des pages principales de dehini.com (les questions du défi Impact ont le leur :
  `/impact/sitemap.xml`). Voir « Référencement (SEO) » plus bas.

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
cp -rf dehini-com/marketing ~/public_html/
cp -rf dehini-com/maillage ~/public_html/
cp -rf dehini-com/studioma ~/public_html/
cp -rf dehini-com/.well-known ~/public_html/
cp -f dehini-com/robots.txt dehini-com/sitemap.xml ~/public_html/
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
cd ~/dehini-com && git pull -q && cp -f index.html ~/public_html/index.html && cp -f admin/index.html admin/api.php admin/.htaccess admin/.user.ini ~/public_html/admin/ && cp -f uploads/.htaccess ~/public_html/uploads/.htaccess && cp -rf img ~/public_html/ && cp -rf elbahiferti ~/public_html/ && cp -rf impact ~/public_html/ && cp -rf metakpis ~/public_html/ && cp -rf swanbida ~/public_html/ && cp -rf marketing ~/public_html/ && cp -rf maillage ~/public_html/ && cp -rf studioma ~/public_html/ && cp -rf .well-known ~/public_html/ && cp -f robots.txt sitemap.xml ~/public_html/
```

Cette commande ne touche jamais `data.json`, `admin/config.php`, `impact/config.php`,
`metakpis/config.php`, `swanbida/config.php`, `marketing/config.php`, `maillage/config.php`, `studioma/config.php`,
les bases du défi Impact, de Stats Meta KPIs, de SWANBIDA, du Marketing Dashboard, de Maillage et de Studioma
(`~/studioma-data/`, fichiers envoyés compris), ni les images envoyées dans `uploads/`, `swanbida/media/` et
`maillage/media/` : seul le code (`index.html`, `admin/*.php`, `elbahiferti/`, `impact/`, `metakpis/`, `swanbida/`,
`marketing/`, `maillage/`, `studioma/`) est mis à jour.

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

L'**espace pro** (`https://dehini.com/impact/#pro`, lien « Espace pro » en haut et bandeau sur
l'accueil) permet aux entreprises, auto-entrepreneurs, professions libérales et associations de créer
leur **page professionnelle**, de la lier aux questions de leur domaine et d'y publier leurs
expériences. Les visiteurs sans compte peuvent laisser leur email pour recevoir les nouvelles
expériences positives sur les questions auxquelles ils ont répondu « négatif ». Voir « 11. Pages pro
et alertes email ».

L'**impact mondial** (`https://dehini.com/impact/#monde`, bouton globe en haut) montre les
résultats de tous les participants : part d'impacts positifs, « vous et le monde », les trois
univers, la carte des 1000 questions (cliquer une case ouvre la question et ses chiffres), les
questions les plus positives, les plus difficiles, les plus partagées, et les pays. Le pays est
déduit du fuseau horaire du navigateur (jamais de l'adresse IP).

- `impact/index.html` — la page (vitrine + défi + résultat + impact mondial + compte), en arabe,
  français et anglais.
- `impact/admin.html` — l'administration du défi, avec **le même mot de passe que `/admin`**
  (lien « Défi Impact » en bas du menu de `/admin`) : statistiques (dont qui est en ligne en ce
  moment), inscrits et abonnés aux alertes, pages pro (mise en avant, vérification), emails (automatisations,
  invitations), questions
  (ajouter, modifier, masquer, supprimer), récits, soutiens et connexion Google.
- `impact/lib/pros.php` — pages pro : types de profils, domaines (et les mots qui servent à proposer
  des questions), logo.
- `impact/lib/automations.php` — emails automatiques (premiers pas, paliers, relances, emails des pros) ;
  `impact/lib/campaigns.php` — invitations (modèles particuliers et 10 domaines pro, envoi, suivi) ;
  `impact/lib/mailer.php` — design commun de tous les emails et envoi SMTP. Voir « 12 ».
- `impact/uploads/pros/` — les logos envoyés par les pros (voir « 11 »).
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
- `impact/page.php`, `impact/sitemap.php`, `impact/index.php` — pages des questions et page de toutes les
  questions lisibles par Google, plan du site, et en-tête de chaque langue du défi (voir « 10. Pages des
  questions »).
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
bienvenue, mot de passe oublié, nouveau message, remerciement après un soutien, confirmation d'abonnement
aux alertes, alerte « nouvelle expérience positive »). Ajouter `--lang=ar` ou `--lang=en` pour les autres langues. Si
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
- **Emails automatiques** et **invitations** — voir « 12. Emails : automatisations et invitations ».
- **Alertes « nouvelle expérience positive »** — dès qu'une personne ou une page pro publie une
  expérience sur une question à laquelle quelqu'un a répondu « négatif », cette personne la reçoit
  (au plus un email toutes les 6 heures, 3 expériences maximum, jamais deux fois la même) : les
  abonnés sans compte (fenêtre « Me prévenir par email ») et les comptes qui ont gardé « impacts
  positifs ». Seules les expériences publiées depuis moins de 7 jours sont envoyées.

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
- **Affichages du site** — pages vues et visiteurs différents aujourd'hui, sur 7 et 30 jours,
  l'évolution des 14 derniers jours, les pages les plus vues (accueil, défi, résultat, pages des
  questions, pages pro, annuaire…) et les pros les plus affichés. Les robots (Google, aperçus de
  liens…) ne sont pas comptés ; un visiteur = un appareil ou un compte. Voir aussi « 11 ».
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

### 9. Inscrits (les emails)

Onglet « Inscrits » de `https://dehini.com/impact/admin.html` (lien « 📧 Défi Impact : inscrits » dans le
menu de `/admin`) : tous les comptes, du plus récent au plus ancien, 100 par page.

- **Recherche** sur le nom ou l'adresse email, et **filtres** : adresse confirmée ou non, compte Google,
  personnes qui reçoivent la question du jour, personnes désabonnées de tout.
- Pour chaque compte : langue, adresse confirmée ou non, en ligne en ce moment, emails reçus (question du
  jour, impacts positifs, avis de messages), date d'inscription, dernière visite, nombre de réponses et de
  récits.
- **Actions** : décocher un type d'email désabonne la personne ; « Confirmer » valide l'adresse sans
  attendre son clic ; « Renvoyer l'email » renvoie le lien de confirmation ; « Modifier l'email » corrige une
  adresse (la nouvelle devra être confirmée) ; « Supprimer » efface le compte, ses réponses, ses récits et ses
  conversations.
- **Exporter (CSV pour Excel)** télécharge la liste affichée (filtre et recherche compris), avec accents et
  séparateur point-virgule pour Excel en français.

Les emails de rappel ne partent qu'aux adresses confirmées, et chacun contient un lien de désabonnement.
Les comptes de Stats Meta KPIs ont leur propre liste dans `metakpis/admin.html`.

### 10. Pages des questions (référencement)

Chaque question a sa propre page, rendue par le serveur pour être lue par Google, en trois langues :
`/impact/q/12-quel-impact-a-eu-…` (français), `/impact/ar/q/12-…`, `/impact/en/q/12-…`. On y trouve la
question, les chiffres du monde, les **récits publiés** (le vrai contenu unique qui attire les visiteurs
depuis Google), trois boutons pour répondre en un clic (la réponse est enregistrée dans le défi), le partage
(WhatsApp, Facebook, X, LinkedIn) et les questions proches. La page `/impact/questions` (et `/ar/questions`,
`/en/questions`) les liste toutes, par univers.

Une question n'est proposée à Google qu'à partir de son **premier récit publié** (avant, sa page existe
mais porte « noindex » : une page avec seulement une question serait jugée trop mince). Le plan
`/impact/sitemap.xml` se met à jour tout seul. Ces adresses passent par `impact/.htaccess` (réécriture
d'adresses, déjà utilisée par Stats Meta KPIs et SWANBIDA) ; `impact/index.php` donne à `/impact/?lang=ar`
et `/impact/?lang=en` leur propre titre et leur propre adresse canonique.

### 11. Pages pro et alertes email (monétisation)

**Pour les pros** — `https://dehini.com/impact/#pro` (aussi dans « Mon compte » → « Espace pro »).
Il faut un compte (gratuit) ; une page pro par compte :

1. **Profil** : nom, type (entreprise, auto-entrepreneur / indépendant, profession libérale,
   association, autre), domaine (15 domaines : coaching, psychologie, santé, éducation, emploi,
   immobilier, finance…), ville, mots-clés, présentation, site web, téléphone / WhatsApp, email
   public et logo (JPEG, PNG ou WebP, 3 Mo max ; l'image est vérifiée, réduite à 400 px et
   réenregistrée en WebP).
2. **Questions liées** (60 max) : le site **propose les questions** qui correspondent au domaine et
   aux mots-clés (« déménagement » → les questions sur le déménagement et le logement…), et on peut
   chercher parmi les 1000 questions.
3. **Expériences** publiées **au nom de la page** (son nom et son logo à la place du prénom). Comme
   tous les récits, une expérience qui contient un lien, une adresse ou un numéro attend une
   validation dans « Récits » : la publicité passe par la page pro, pas par les récits.

La page pro apparaît :

- à côté de ses questions dans le défi (« Des pros en parlent », sous chaque réponse et dans la fiche
  d'une question) et sur les pages des questions (`/impact/q/…`) ;
- sur ses expériences, partout où elles s'affichent (mur, résultat, pages des questions, emails) ;
- sur sa **page publique** `https://dehini.com/impact/pro/nom-de-la-page` (en `/ar/pro/…` et
  `/en/pro/…` aussi) : bandeau, logo, nom, badge « Vérifié », boutons WhatsApp / Appeler / Site web
  (barre de contact fixe en bas sur téléphone), « À propos » avec ses spécialités (ses mots-clés),
  ses expériences, et une colonne Contact et Partager. La liste des questions liées n'y est pas
  affichée : elles servent à le montrer à côté de ces questions dans le défi. Et dans l'**annuaire**
  `https://dehini.com/impact/pros`. Ces pages sont dans le plan du
  site dès qu'elles ont du contenu (présentation de 80 caractères, une question liée ou une
  expérience ; sinon « noindex »).

**Ce que vous vendez** — onglet **« Pros »** de l'admin : la liste des pages (recherche, filtres en
ligne / mises en avant / masquées), avec pour chacune le compte propriétaire, les contacts, le nombre
de questions et d'expériences, et quatre actions :

- **Mettre en avant** : l'emplacement sponsorisé. La page passe en tête à côté de ses questions, dans
  le défi, sur les pages des questions et dans l'annuaire, avec la mention « Sponsorisé » ; ses liens
  portent `rel="sponsored"` comme le demande Google. On l'active quand le pro a payé et on la retire à
  la fin de la période.
- **Vérifier** : badge « Vérifié » après contrôle de l'activité (le lien vers son site devient alors
  un lien suivi par les moteurs de recherche ; sans vérification il porte `ugc nofollow`).
- **Masquer / Afficher** : retire la page du site sans la supprimer.
- **Supprimer** : efface la page, son logo et ses expériences (le compte et ses récits personnels
  restent). Le pro peut aussi supprimer sa page lui-même depuis son espace.

**Statistiques d'affichage** — chaque pro les suit dans son espace (carte « Vos statistiques », sur
7, 30 ou 90 jours) et l'admin les voit dans l'onglet « Pros » (chiffres des 30 derniers jours sur
chaque page, « Statistiques détaillées », et « Copier le rapport » à envoyer au pro par email ou
WhatsApp — utile pour vendre ou renouveler une mise en avant) :

- **Affichages** — son nom ou son logo vu à l'écran : à côté d'une question (« Des pros en
  parlent »), sur une de ses expériences (mur, résultat, pages des questions) ou dans l'annuaire.
  Compté quand l'élément est réellement visible, une fois par question et par page vue ; avec la
  comparaison à la période précédente.
- **Personnes touchées** (personnes différentes), **visites de sa page**, **clics sur ses contacts**
  (site web, WhatsApp, appel, email) et le taux de clic.
- Un graphique jour par jour (semaine par semaine sur 90 jours), le tableau des chiffres, et les
  questions qui le montrent le plus.

Ces chiffres sont honnêtes par construction : les robots ne sont pas comptés, une même personne
compte au plus 20 fois par jour pour un même pro, et le pro ne compte pas ses propres visites.
Les totaux sont gardés jour par jour (la base reste petite) ; les listes qui servent à compter les
personnes différentes sont effacées après 95 jours.

**Alertes email sans compte** — après 3 réponses « négatif », le défi propose une fois (puis pas
avant 14 jours) de laisser son email pour recevoir les nouvelles expériences positives sur ces
questions ; le même bouton apparaît sous une réponse « négatif » qui n'a encore aucun récit. Rien ne
part avant le clic sur le lien de confirmation (personne ne peut inscrire l'adresse d'un autre).
Chaque alerte contient un lien de désinscription (une page de confirmation, pour que les antivirus
des messageries qui ouvrent les liens ne désinscrivent personne). Avec un compte, le bouton active
simplement « impacts positifs ». Les abonnés sont listés sous les comptes dans l'onglet
**« Inscrits »** (confirmés / en attente, nombre de réponses négatives, dernière alerte, export CSV,
suppression). Si l'abonné crée ensuite un compte sur le même appareil, son abonnement suit.

**Logos et déploiement** — les logos sont enregistrés dans `~/public_html/impact/uploads/pros/`. La
copie du déploiement (`cp -rf impact ~/public_html/`) ne supprime jamais de fichier, ils restent donc
d'une mise à jour à l'autre ; ils ne sont pas dans Git : pour une sauvegarde complète, copier ce
dossier avec `~/impact-data/`. `impact/uploads/.htaccess` interdit l'exécution de scripts dans ce
dossier.

### 12. Emails : automatisations et invitations

Onglet **« Emails »** de `https://dehini.com/impact/admin.html`. Tous les emails du défi (rappels, alertes,
automatisations, invitations) partagent le même design : en-tête de marque avec bandeau titre, carte
blanche, boutons lisibles, version téléphone, mode sombre là où la messagerie le permet, arabe de droite
à gauche, version texte et lien de désabonnement en un clic (en-tête `List-Unsubscribe` reconnu par Gmail).

**Emails automatiques** — ils partent tout seuls avec la tâche cron de chaque heure, dans la langue du
compte. Chacun s'active ou se coupe d'un clic, avec son nombre d'envois, un **aperçu** (FR / AR / EN,
ordinateur / téléphone) et **« M'envoyer un test »** :

| Email | Quand | Pour qui |
|---|---|---|
| Premiers pas | le lendemain de l'inscription, si moins de 10 réponses | comptes confirmés |
| Rappel de confirmation | 2 jours après l'inscription, une seule fois | adresses non confirmées |
| Paliers de réponses | à 100, 250, 500 et 1000 réponses (son bilan + inviter à raconter) | comptes confirmés |
| Relance des absents | après 21 jours sans visite, au plus tous les 60 jours | comptes confirmés |
| Récit apprécié | un récit atteint 1, 5, 10, 25 ou 50 « j'aime » (au plus un par jour) | auteurs |
| Bienvenue pro | dans l'heure qui suit la création d'une page pro (étapes à cocher) | pros |
| Page pro incomplète | 3 jours après, s'il manque logo, présentation, questions ou expérience | pros |
| Rapport mensuel | le 1er du mois : affichages, personnes touchées, visites, clics, questions | pros |

Un email ne part jamais deux fois pour la même raison. À la mise en service (et quand on réactive un
scénario), les paliers et « j'aime » déjà atteints sont notés sans envoi : personne ne reçoit d'emails
pour le passé. Chacun peut couper ces emails depuis « Mon compte » (« Conseils, paliers et nouvelles »,
« Emails de ma page pro ») ou par le lien en bas de l'email ; l'admin voit et modifie ces choix dans
« Inscrits ».

**Invitations** — pour faire découvrir le défi à de nouvelles personnes :

1. Choisir le **public** : *Particuliers* (la question du jour à essayer en un clic, les chiffres du
   site, une expérience positive partagée) ou *Professionnels* avec un des **10 domaines** (psychologie,
   santé, coaching, éducation, emploi/RH, entrepreneuriat, finance, immobilier, famille, droit) : une
   accroche propre au domaine, trois vraies questions du domaine avec la part de réponses « négatif »,
   ce que la page pro leur apporte et le bouton « Créer ma page pro gratuite ».
2. Choisir la **langue**, ajuster l'**objet** (`{name}` = prénom), ajouter un **mot personnel** et la
   **signature**.
3. Coller les **destinataires** (une personne par ligne : `email`, `Prénom <email>`, `email;Prénom`, ou
   une colonne copiée depuis Excel) ou importer un fichier CSV / TXT.
4. **Aperçu** (ordinateur / téléphone, « Copier le HTML » ou « Télécharger .html » pour l'utiliser dans
   un autre outil), **« M'envoyer un test »**, puis **« Créer la campagne »** et **« Lancer l'envoi »**.

Les invitations partent peu à peu, au plus le nombre réglé **par heure** (30 par défaut, à adapter à la
limite de l'hébergeur), par la tâche cron ; « Envoyer un lot maintenant » en envoie jusqu'à 20 tout de
suite. Sont ignorées automatiquement : les adresses déjà inscrites (déjà pro pour le modèle pro), déjà
invitées depuis moins de 90 jours, et celles de la liste **« Ne jamais contacter »** (elles y entrent
toutes seules quand quelqu'un clique « Ne plus jamais recevoir d'invitation »). Pour chaque campagne :
envoyés, **clics**, **inscriptions** et **pages pro créées** par les invités, désinscriptions, et la
liste des destinataires en CSV.

**Pour arriver en boîte de réception (et pas en spam)** — le domaine est déjà bien configuré
(vérifié le 7 novembre 2026) : SPF (`v=spf1 +a +mx +ip4:157.90.66.113 +ip4:157.90.66.110 ~all`), clé DKIM
`default._domainkey`, DMARC `p=none`, DNS inverse du serveur correct, et ses deux adresses IP absentes
des listes noires courantes. Un nouveau domaine qui envoie des emails illustrés depuis un serveur
mutualisé doit encore **se faire une réputation** auprès de Gmail. Dans l'ordre :

1. **Vérifier que l'email est bien authentifié** : dans Gmail, ouvrir un email du défi, menu « ⋮ » →
   « Afficher l'original ». Les trois lignes SPF, DKIM et DMARC doivent indiquer **PASS** (c'est le cas
   depuis le 7 novembre 2026, par SMTP avec `impact@dehini.com`). Les emails eux-mêmes évitent les
   signaux que notent les filtres : pas de texte caché, pas de « Reply-To » en double, pas d'objet
   finissant par « ? », un seul alphabet par email d'invitation. Si DKIM
   n'est pas « PASS », dans cPanel → **Email Deliverability**, cliquer « Repair » sur `dehini.com`.
2. **Envoyer par SMTP avec le compte `impact@dehini.com`** (`IMPACT_SMTP_HOST`, `IMPACT_SMTP_USER`,
   `IMPACT_SMTP_PASS` dans `~/public_html/impact/config.php`) plutôt que par la fonction `mail()` :
   les emails sont alors signés DKIM à coup sûr.
3. **Noter un email** sur [mail-tester.com](https://www.mail-tester.com) : copier l'adresse que le site
   affiche, puis lancer
   `php ~/public_html/impact/cron.php --test=ADRESSE_DONNEE --only=invite` et cliquer « Ensuite,
   vérifiez votre score ». Viser 9/10 ou plus ; la page explique chaque point perdu.
4. **Apprendre à Gmail** : dans le dossier Spam, ouvrir les emails du défi et cliquer « Signaler comme
   non-spam », et ajouter `impact@dehini.com` aux contacts. Inscrire `dehini.com` sur
   [Google Postmaster Tools](https://postmaster.google.com) pour suivre la réputation du domaine.
5. **Monter en charge doucement** : d'abord les emails des comptes inscrits (attendus, donc ouverts),
   puis les invitations par petits lots (10 à 20 par heure la première semaine), uniquement vers des
   personnes qui vous connaissent ou dont l'adresse professionnelle est publique. Un lot vers des
   inconnus qui ne s'ouvrent pas fait chuter la réputation de tout le domaine.
6. **Si les emails restent en spam** (même vers d'autres boîtes Gmail) : c'est la réputation du serveur
   mutualisé chez Gmail, que le code ne peut pas changer. Envoyer alors par **Brevo**, un service
   d'emailing dont les serveurs ont une bonne réputation (gratuit jusqu'à 300 emails par jour). Le site
   passe par son API en HTTPS, ce qui marche même quand l'hébergeur bloque le SMTP sortant :
   1. Créer un compte sur brevo.com.
   2. **Expéditeurs, domaines et IP dédiées → Domaines → Ajouter un domaine** : `dehini.com`. Brevo
      affiche des enregistrements DNS (code Brevo, DKIM, DMARC) : les ajouter dans cPanel → **Zone
      Editor**, puis cliquer « Authentifier » dans Brevo. Ajouter aussi l'expéditeur `impact@dehini.com`.
   3. **SMTP & API → onglet « Clés API » → Générer une nouvelle clé API** (elle commence par
      `xkeysib-` ; la « clé SMTP » `xsmtpsib-` ne marche pas avec l'API). La coller dans l'admin :
      **onglet Emails → Envoi → Brevo → « Enregistrer et vérifier »**. Le site demande à Brevo si la clé
      est valide avant de l'enregistrer, puis affiche le compte, les crédits restants, si le domaine
      est authentifié et si l'expéditeur est ajouté. La clé n'est plus jamais réaffichée en entier
      (seulement `xkeysib-…` et ses 4 derniers caractères) ; « Retirer la clé » revient à l'envoi par
      le serveur. Elle peut aussi être mise dans `config.php` (`define('IMPACT_BREVO_KEY', 'xkeysib-…');`),
      mais celle de l'admin est prioritaire. Avec une clé, le SMTP n'est plus utilisé.
   4. Tester : bouton « M'envoyer un test » d'un email de l'onglet Emails, ou
      `php ~/public_html/impact/cron.php --test=une-autre-adresse@gmail.com --only=welcome`.
      Le cron affiche « API Brevo » ; une erreur de Brevo s'affiche telle quelle (clé invalide…). Si
      Brevo répond que l'adresse IP n'est pas reconnue, l'autoriser dans Brevo → **Sécurité → IP
      autorisées** (157.90.66.110 et 157.90.66.113).
   5. Avec le forfait gratuit (300 par jour), régler `IMPACT_MAIL_BATCH` à 10 dans `config.php` et les
      invitations à 2 par heure dans l'onglet Emails ; au-delà, prendre un forfait Brevo payant.

`php ~/public_html/impact/cron.php --test=ton-adresse@gmail.com` envoie un exemple de chaque email
(avec une pause de 2 secondes entre deux envois) ; `--only=invite,onboard,pro_report…` n'envoie que
ceux-là (la liste des noms s'affiche si un nom est inconnu), `--lang=ar` ou `--lang=en` change la langue
et `--domain=finance` le domaine de l'invitation pro.

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

Boutique de la marque SWANBIDA 🦢 — *L'élégance dans chaque détail* — en **arabe, langue principale**
(`/swanbida/`, de droite à gauche) et en français (`/swanbida/fr/`, bouton « FR » en haut de chaque page), pensée
d'abord pour le téléphone (trafic Facebook / Instagram). PHP + SQLite,
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

Chaque page existe en français sous `/swanbida/fr/…` (ex. `/swanbida/fr/produit/pack-serviette-blanc`) : c'est
l'adresse à mettre dans les publicités en français. Les anciennes adresses `/swanbida/ar/…` redirigent (301) vers la
même page sans `/ar/`, paramètres de publicité compris. `hreflang` x-default = arabe.

- `swanbida/index.php` — toutes les pages (rendu serveur : titres, descriptions, canonical, hreflang fr-DZ / ar-DZ,
  Open Graph, données structurées Schema.org Organization, WebSite, Product, BreadcrumbList, Article).
- `swanbida/api.php` — commande, wilayas / communes, événements de navigation. `swanbida/admin/` — administration.
- `swanbida/cron.php` — renvoi des événements Meta, sauvegarde quotidienne, alertes des commandes abandonnées,
  purge des données techniques.
- `swanbida/lib/` (code), `swanbida/templates/` (gabarits) — bloqués en accès direct.
- `swanbida/assets/` — style, script, logo, icônes et **visuels provisoires** (illustrations du pack, à remplacer par
  les vraies photos depuis l'admin). `swanbida/media/` — photos et vidéos envoyées depuis l'admin (hors Git).
- La base SQLite est créée toute seule **hors de `public_html`** : `~/swanbida-data/swanbida.sqlite`, avec
  `secret.key` (chiffre le jeton Meta) et `backups/`. Ne jamais les mettre dans Git.

Hébergement : PHP 7.4 ou plus récent avec `pdo_sqlite`, `gd` (avec WebP), `openssl`, `mbstring` ; `curl`, `intl`,
`zip` et `exif` sont utilisés s'ils sont présents. Les adresses canoniques, le sitemap et les événements Meta sont
toujours en `https://` ; pour rediriger aussi les visites en `http://`, activer « Force HTTPS Redirect » dans
**cPanel → Domaines** (une redirection dans `.htaccess` tournerait en boucle derrière le proxy de l'hébergeur).

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
prénom, téléphone, wilaya, commune (liste selon la wilaya, ou saisie libre « Autre commune »), remarque
facultative — pas de champ « adresse détaillée » (le livreur appelle le client). Le montant (offre + frais de la wilaya) est **recalculé par le serveur**, jamais pris du navigateur.
Chaque commande reçoit un numéro unique lisible au téléphone (`SW-7K4M2Q`) et un lien de suivi privé.

- Validation côté serveur de chaque champ (numéros algériens 05 / 06 / 07, +213, chiffres arabes acceptés).
- Anti-doublons : double clic ou retour arrière → même commande (pas de second Lead) ; même téléphone et même produit
  dans les 24 h → commande acceptée mais marquée « doublon possible ». Champ piège anti-robots et limites par
  connexion (8 commandes / heure, réglable) et par téléphone.
- Stock décompté à la commande, rendu si la commande est annulée ou retournée ; rupture → formulaire remplacé par
  un bouton WhatsApp (ou commandes acceptées si « accepter en rupture » est coché). Alerte stock bas par email.
- Sans JavaScript, le formulaire fonctionne quand même (envoi classique).

**Administration → Commandes** : onglets par statut (nouvelle, confirmée, en préparation, expédiée, livrée,
annulée, retournée) avec compteurs, recherche (n°, nom, téléphone, commune, n° de suivi), filtres (wilaya, dates,
source, doublons, campagne / publicité depuis le tableau de bord), changement de statut groupé, export Excel / CSV
(séparateur « ; », UTF-8). Fiche commande : boutons Appeler et WhatsApp (message de confirmation pré-rempli,
modifiable dans Réglages), modification du client, de l'adresse (facultative, pour le livreur), de l'offre, des frais, du transporteur et du n° de
suivi (affichés au client sur sa page de suivi), notes internes, historique complet, origine publicitaire et
événements Meta envoyés.

**Commandes abandonnées** (menu « Abandonnées », compteur rouge) : dès qu'un visiteur a tapé un numéro de téléphone
valide dans le formulaire, ce qu'il a saisi (offre, nom, wilaya, commune, remarque, publicité d'origine) est
enregistré automatiquement — même s'il ferme la page. S'il envoie sa commande, la ligne disparaît d'elle-même
(« commandée par le client »). Sinon, après **20 minutes sans activité** (réglable), elle passe dans **À rappeler** :

- boutons **Appeler** et **WhatsApp** (message de relance pré-rempli dans la langue du client, modifiable dans
  Réglages) — un appel ou un message la classe dans « Contactées » ; note interne ; « Ignorer » ;
- **Créer la commande** : compléter le nom, la wilaya, la commune ou l'offre et enregistrer — la commande arrive en
  « Nouvelle », avec l'attribution publicitaire du client (Lead Meta envoyé avec le clic d'origine `fbc`, Purchase à
  la confirmation comme d'habitude). La ligne passe en « Récupérée » ;
- alerte **email** (une seule par commande abandonnée, envoyée par le cron, aux adresses de « Notifications » dans
  Réglages), tuile « Abandonnées / récupérées » sur le tableau de bord ; effacement automatique au bout de 30 jours
  (mentionné dans la page Confidentialité).

**Notifications** de chaque nouvelle commande : email (jusqu'à 5 adresses, via le compte SMTP du défi Impact si
`swanbida/config.php` n'en définit pas d'autre, bouton Réglages → « Enregistrer et tester »), et dans l'admin
ouverte : signal sonore, notification du navigateur (« Activer les alertes ») et compteur des commandes à
confirmer.

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

### 9. Hébergement dédié (domaine séparé, ex. swanbida.com)

Le même code peut tourner seul, à la **racine** de son propre domaine, sur un hébergement complètement séparé de
`dehini.com` — c'est le cas si la marque a son propre compte cPanel (ex. `swanbida.com` chez dzSecurity). La seule
différence avec l'installation plus haut : on publie le **contenu** de `swanbida/` directement dans `public_html/`
(pas dans un sous-dossier `swanbida/`). Le reste — admin, commandes, Meta, cron — fonctionne à l'identique ; aucune
donnée n'est reprise de `dehini.com/swanbida/` (base neuve).

1. **Domaine** : vérifier que `swanbida.com` pointe bien vers ce nouvel hébergement. Si le nom de domaine a été
   enregistré ou transféré chez le même hébergeur, c'est automatique. S'il a été acheté ailleurs, il faut aller
   chez son bureau d'enregistrement et remplacer ses **serveurs de noms (nameservers)** par ceux indiqués dans
   l'email d'activation (ex. `oort-shared.dzsecurity.net` et `dns2-r.dzsecurity.net`) ; la propagation peut prendre
   jusqu'à 24–48 h.
2. **Terminal cPanel** du nouveau compte (cPanel → Avancé → Terminal ; identifiants reçus par email) :

```bash
cd ~ && git clone https://github.com/dehini31/dehini-com.git
mkdir -p ~/public_html/admin ~/public_html/media
cp -rf dehini-com/swanbida/. ~/public_html/
```

3. **Mot de passe propriétaire**, propre à ce site (rien à voir avec celui de `/admin` sur `dehini.com`) — affiché
   une seule fois, à noter tout de suite :

```bash
php -r '$p = bin2hex(random_bytes(6)); echo "Mot de passe administrateur SWANBIDA : $p\n"; file_put_contents(getenv("HOME")."/public_html/config.php", "<?php\ndefine('"'"'SWB_OWNER_PASSWORD_HASH'"'"', '"'"'".password_hash($p, PASSWORD_DEFAULT)."'"'"');\ndefine('"'"'SWB_SITE_URL'"'"', '"'"'https://swanbida.com/'"'"');\n");'
```

4. **HTTPS** : dans cPanel → Sécurité → SSL/TLS Status, lancer AutoSSL si le certificat n'est pas déjà actif, puis
   activer **Force HTTPS Redirect** dans cPanel → Domaines (pas de redirection forcée dans `.htaccess` : comme pour
   `dehini.com`, le serveur peut être derrière un proxy qui ne transmet pas toujours l'information HTTPS, ce qui
   tournerait en boucle).
5. **Mise à jour automatique** : ce sont des tâches planifiées, pas des commandes à taper telles quelles. Le plus
   simple, dans le Terminal (relançable sans créer de doublons), puis `crontab -l` pour vérifier :

```bash
(crontab -l 2>/dev/null | grep -v 'dehini-com && git pull' | grep -v 'public_html/cron.php'; echo '*/5 * * * * cd ~/dehini-com && git pull -q && cp -rf swanbida/. ~/public_html/'; echo '*/10 * * * * /usr/local/bin/php ~/public_html/cron.php > /dev/null 2>&1') | crontab -
```

   Ou dans cPanel → Tâches Cron → Ajouter : « Une fois toutes les cinq minutes », commande
   `cd ~/dehini-com && git pull -q && cp -rf swanbida/. ~/public_html/` ; puis Minute `*/10` (autres champs `*`),
   commande `/usr/local/bin/php ~/public_html/cron.php > /dev/null 2>&1`.

6. Ouvrir `https://swanbida.com/`, se connecter à `https://swanbida.com/admin/` avec le mot de passe de l'étape 3,
   puis reconfigurer **Contenus → Général** (WhatsApp, téléphone, réseaux…), **Meta & suivi** (le pixel peut être le
   même qu'ailleurs, mais Meta Business Suite doit **vérifier `swanbida.com` séparément** — Paramètres de la marque
   → Domaines) et **Réglages** (notifications). Passer une commande test de bout en bout avant toute campagne
   publicitaire.
7. Une fois `swanbida.com` vérifié en ligne, **basculer `dehini.com/swanbida/`** vers la nouvelle adresse sans
   perdre les clics publicitaires en cours : sur l'hébergement `dehini.com`, ajouter à `swanbida/config.php` :

```php
define('SWB_REDIRECT_TO', 'https://swanbida.com');
```

   Chaque page (et chaque paramètre d'URL, utile pour les publicités déjà actives) est alors redirigée vers la
   même adresse sur `swanbida.com` (redirection permanente, bonne pour Google). Retirer cette ligne ramène
   l'ancien site tel quel.

Si le Terminal n'est pas proposé sur le forfait, la même publication se fait avec un client FTP (ex. FileZilla) et
les identifiants FTP reçus par email, en envoyant le contenu du dossier `swanbida/` du dépôt (fichiers cachés
comme `.htaccess` compris) dans `public_html/` ; les étapes 3 à 7 restent identiques.

## Marketing Dashboard (`/marketing/`)

Tableau de bord du suivi marketing de **YINVESTI** : on y saisit l'activité à la main, et il en tire tout seul les
rapports quotidiens, hebdomadaires et mensuels, avec une analyse (« Analyse & décisions ») pour décider vite.
Ouvrir `https://dehini.com/marketing/` (lien « 📈 Marketing Dashboard » dans le menu de `/admin`) et se connecter avec le
**mot de passe de `/admin`** (même session). Rien à installer de plus : la base SQLite se crée toute seule **hors de
`public_html`**, dans `~/marketing-data/marketing.sqlite` (pour sauvegarder, copier ce dossier).

- `marketing/index.html` + `marketing/assets/app.js` + `marketing/assets/app.css` — l'interface (français,
  ordinateur et téléphone, thème clair / sombre, icônes dessinées en SVG, sans dépendance).
- `marketing/api.php` — l'API (réservée à la session de `/admin`, jeton anti-CSRF sur chaque écriture).
- `marketing/lib/core.php` — base, **schéma des données** (le même schéma crée les tables, valide chaque saisie et
  construit les formulaires : ajouter un champ = une ligne, la colonne est ajoutée toute seule), réglages, emails.
- `marketing/lib/taxonomy.php` — canaux (niveau 1, par catégorie) et supports (niveau 2, par type) : listes de
  départ, validation, migration des anciennes listes.
- `marketing/lib/report.php` — calcul des rapports, performance de la communication (niveau 3), comparaisons,
  constats et email du rapport.
- `marketing/lib/meta.php` + `marketing/meta.php` — connexion à Facebook, Instagram et Meta Ads (API Graph), import
  et classement automatique des données (voir « Connexion Meta » plus bas).
- `marketing/cron.php` — synchronisation Meta et envoi automatique des rapports (voir plus bas).

### Organisation

Un seul tableau de bord, trois niveaux de suivi : **Daily Monitoring** (gérer la journée), **Weekly / Monthly
Reports** (analyser la semaine ou le mois, générés automatiquement) et **analyse par campagne** (objectifs, budget,
résultats et funnel de chaque campagne, quel que soit le canal). Chaque donnée n'est saisie qu'une fois, dans sa
section ; l'Overview et les rapports la reprennent automatiquement. Le menu est rangé en trois groupes :
**Pilotage** (Overview, Connexions), **Marketing** (Communication, Social Media, Ads Manager, Website, Email, Funnels) et
**Commercial** (Leads & Contacts, Projets).

| Menu | Contenu |
| --- | --- |
| 🏠 Overview | **Daily Monitoring** (page d'accueil) : « Bonjour, *prénom* », les 5 indicateurs du jour comparés à la veille, l'analyse du jour (les 5 points les plus urgents, les autres sur demande), ce qui a été saisi ce jour-là (onglets Leads, Questions, Actions, Projets), les leads en attente, la liste « À traiter maintenant » (leads sans contact depuis 48 h, à traiter, sans commercial, questions sans réponse, projets à étudier), la saisie rapide, un calendrier du mois (un point sous chaque jour où quelque chose a été saisi ; cliquer un jour ouvre son suivi) et l'activité récente. **Weekly Reports** et **Monthly Reports** : 16 indicateurs comparés à la période précédente, analyse & décisions, leads (évolution, sources, qualité, commerciaux, raisons de non-qualification), questions, projets en cours de financement et montants levés, website, social media, publicité (résultats, budgets, objectifs), emails, communication par canal et support, et funnels. Envoi par email, archivage, CSV, impression / PDF. **Archives** : rapports figés. |
| 🔗 Connexions | **Facebook · Instagram · Ads** : relier la Page, le compte Instagram et les comptes Meta Ads, synchroniser ; **Classement automatique** : mots-clés de classement (cible, objectif, sujet des questions) ; **Historique** des synchronisations. Voir « Connexion Meta » plus bas. |
| 📣 Communication | **Actions** : chaque action de communication, saisie avec un assistant pas à pas (voir plus bas). **Performance** (niveau 3) : comparer les canaux, puis les supports à l'intérieur d'un canal (ex. Reels contre carrousels sur Instagram), tableau dépliable catégorie ▸ canal ▸ support, constats « où concentrer les efforts ». **Canaux & supports** (niveaux 1 et 2) : gérer les canaux par catégorie et les supports par type. |
| 📊 Funnel Analytics | **Des parcours à branches, pas une ligne droite** : un funnel peut avoir plusieurs points d'entrée (publicité, Instagram, messages, salon…), des **aiguillages** (qualifié / sinon, investisseurs / porteurs, ou branches libres) et des circuits qui se **rejoignent** plus loin. **Funnels** : chaque parcours dessiné avec ses chiffres. **Parcours & suivi** : entrées, arrivées à l'objectif, conversion globale, plus forte perte, le parcours chiffré (épaisseur des liens = prospects qui passent, part de chaque branche, meilleur circuit en vert, plus forte perte en orange), chaque **circuit** complet comparé aux autres, le détail par étape (vient de, canaux, source, conversion depuis l'étape précédente et depuis l'entrée, objectif) et les chiffres saisis à la main. **Conversions** : circuits et étapes de tous les funnels. Chaque étape prend ses chiffres dans une **saisie manuelle**, **automatiquement** dans les éléments associés (clics des campagnes, visites ou inscriptions des pages, emails, leads liés), dans **les canaux de l'étape** (actions, portée, engagement, clics, leads, questions, relevés des comptes sociaux, conversations Messenger / Instagram, conversations chaudes, prospects créés depuis les messages) ou dans le **total des étapes précédentes** (aiguillage). Une étape qui compte des leads peut se limiter à un **segment** (investisseurs, porteurs, qualifiés, non qualifiés, convertis). |
| 📱 Social Media | Les canaux de la catégorie « Réseaux sociaux » : **Vue d'ensemble** (portée, engagement, abonnés comparés à la période précédente, résultats par réseau, formats qui engagent le plus, dernières actions, liens vers les leads et les actions des réseaux) et **Relevés des comptes** (portée, impressions, engagement, abonnés, clics — à saisir chaque semaine). Les leads, questions et actions des réseaux ne sont pas dupliqués ici : ils sont dans Leads & Contacts et Communication, filtrés sur les réseaux sociaux. |
| 📢 Ads Manager | Chaque campagne est rattachée à un canal de la catégorie « Publicité digitale » (Meta Ads, Google Ads…). **Campagnes** (liste et fiche de chaque campagne : objectifs, budget, résultats, funnel, leads), **Budgets & objectifs** (prévu, dépensé, restant, rythme, et avancement de chaque objectif comparé au temps écoulé), **Résultats** (dépense, impressions, clics, leads, conversions, CTR, coût par lead / conversion). |
| 🌐 Website Analytics | **Site** (site principal), **Landing pages** (visites et conversion par page), **Inscriptions** (investisseurs et porteurs de projets). Chiffres de Google Analytics saisis par jour ou par semaine. Les leads venus du site sont dans Leads & Contacts (filtre « Web »). |
| ✉️ Email Marketing | **Campagnes** et **Performance** (envois, ouvertures, clics, clics / ouvertures, objectif atteint, désabonnements, dans un seul tableau ; ouvertures estimées : certaines messageries bloquent leur mesure), chaque campagne pouvant être associée à un funnel. |
| 👥 Leads & Contacts | **Leads** (recherche, filtres par profil, canal, traitement et commercial, pastilles par catégorie de canaux, export, **import d'un fichier CSV** : modèle à télécharger, aperçu ligne par ligne avant l'import), **Questions** (questions & inquiries de tous les canaux), **Profils** (investisseurs / porteurs), **Qualité par canal** (canal de communication d'origine, et support s'il est connu : volume et qualité), **Assignation** au commercial responsable (ou non), **Traitement** : à traiter, contacté, qualifié, non qualifié (avec la raison : injoignable, pas intéressé, budget, hors cible…), converti. Statut et commercial se changent directement dans les tableaux. |
| 📁 Projets | **Demandes de projets** et **Projets en cours de financement** (montant levé, investisseurs, fin de la levée). Mettre à jour le montant levé avec sa date : le montant levé chaque semaine et chaque mois en est déduit. |
| ⚙️ Réglages | Votre prénom (pour l'accueil), nom de l'organisation, devise, liste des commerciaux, destinataires et fréquence des rapports, email de test, exports CSV de toutes les données. |

En haut de chaque page : la **recherche globale** (`Ctrl K` ou `/`) dans les leads, questions, actions, projets,
campagnes, emails, funnels, canaux et supports (résultats par section, flèches et Entrée pour ouvrir), la cloche
(leads et questions en retard) et le bouton **Nouveau** (saisie de tout élément, import de leads). Les anciennes
adresses (`#social/leads`, `#email/sent`, `#ads/objectives`…) redirigent vers leur nouvelle place.

**Importer des leads (CSV).** Leads & Contacts → « Importer un fichier » (ou Nouveau → Importer des leads) :
télécharger le modèle, ou enregistrer son fichier Excel / Google Sheets au format CSV (séparateur `;` ou `,`), avec
une ligne de titres (Nom complet, Téléphone, Email, Ville, Profil, Canal, Support, Commercial, Traitement, Notes,
Date). Les titres et les valeurs sont reconnus sans tenir compte des accents ni des majuscules (« porteur de projet »,
« Instagram », « 03/11/2026 »…). L'aperçu indique les lignes prêtes, celles où une valeur inconnue est remplacée par
la valeur par défaut, et celles ignorées (sans nom ou sans canal reconnu). 1 000 lignes au maximum par import.

**Canaux, supports, performance.** La communication est organisée en trois niveaux pour ne pas mélanger les notions :

1. **Canal** — le moyen utilisé pour transmettre le message (*où ?*), rangé dans l'une des 10 catégories (chacune
   avec son icône et sa couleur) : Réseaux sociaux (Facebook, Instagram, TikTok, LinkedIn, YouTube, X, Pinterest,
   Snapchat), Web, Messagerie directe, Publicité digitale (Meta Ads, Google Ads…), Médias traditionnels,
   Communication extérieure, Communication directe, Événementiel, Relations publiques, Points de vente.
2. **Support** — la forme concrète du message (*sous quelle forme ?*), rangé par type : supports digitaux (Visuels,
   Publications, Vidéo, Documents, Email, Web, Audio, Interaction) et physiques (Documents imprimés, Publicité
   extérieure, Événementiel, Commercial, Packaging, Promotion, Objets publicitaires). Chaque canal a sa liste de
   supports (Instagram → Post, Carrousel, Story, Reel, Live…).
3. **Performance** — chaque action de communication suit *Catégorie → Canal → Support → Contenu → Cible → Objectif →
   KPI* (portée, impressions, engagement, clics, leads, conversions, coût). Communication → Performance compare les
   canaux, puis les supports d'un canal. Y sont aussi repris les résultats d'Ads Manager (canaux Publicité digitale),
   les campagnes email envoyées (canal Email : portée = envois, engagement = clics) et les leads suivis dans Leads &
   Contacts (par canal et support).

Communication → **Canaux & supports** : canaux regroupés par catégorie et supports par type (sections dépliables),
renommer, changer de catégorie ou de type, réordonner, retirer, cocher les supports de chaque canal, ajout rapide
dans une catégorie ou avec l'**assistant « Nouveau canal »** (Catégorie → Nom → Supports → Vérifier). Les
modifications s'enregistrent d'un coup (barre « Modifications non enregistrées »). Renommer ou déplacer garde les
données déjà saisies ; un élément retiré reste affiché sur les anciennes données (le nombre de données concernées est
indiqué avant) et les retrouve s'il est recréé avec le même nom.

**Assistant « Nouvelle action de communication »** (stepper en 5 étapes, une question à la fois) : 1. Canal
(catégorie, puis les canaux de cette catégorie) ; 2. Support (ceux du canal, les autres sur demande) ; 3. Contenu,
date, cible et objectif ; 4. Résultats (les KPI principaux de l'objectif d'abord, les autres sur demande, taux
calculés en direct — à compléter plus tard si besoin) ; 5. Vérifier, avec un lien « Modifier » vers chaque étape.
Une action existante s'ouvre directement sur « Vérifier ». Dans les formulaires (leads, questions, projets), le canal
se choisit de la même façon : catégorie, puis canal, puis support.

À la mise à jour, la liste des canaux de départ est installée ; les anciens réseaux et anciennes sources déjà
utilisés dans des données (Facebook, Site web, Téléphone…) sont repris, et les plateformes d'Ads Manager deviennent
les canaux Meta Ads, Google Ads, LinkedIn Ads, TikTok Ads. L'ancien champ « Réseaux sociaux associés » d'un funnel
(en double avec les canaux de chaque étape) disparaît : ses réseaux sont recopiés sur la première étape du funnel si
aucune étape n'avait encore de canal.

**Funnel Studio** (Funnel Analytics › « Créer un funnel » ou « Modifier le parcours »). On part d'un modèle
(parcours simple, plusieurs points d'entrée, qualifié / sinon, investisseurs / porteurs, messages → prospects, ou de
zéro), puis on dessine le parcours sur un canevas :

- le **+** d'une étape ajoute la suite : étape suivante, aiguillage (qualifié / sinon, investisseurs / porteurs, libre),
  objectif final, ou « relier à une étape existante » ;
- **glisser ce +** jusqu'à une autre étape relie les deux (deux circuits se rejoignent) ; « Ajouter une entrée » crée
  un autre point d'entrée ;
- cliquer sur une étape ouvre ses réglages : rôle (étape, aiguillage, objectif final), nom, libellé affiché sur la
  flèche (« Qualifié », « Sinon »…), étapes dont elle vient (plusieurs = circuits qui se rejoignent), source des
  chiffres, segment de leads, canaux, objectif, tags. L'onglet « Funnel & associations » règle l'objectif, la cible, le
  statut et les campagnes, pages et emails associés ;
- un parcours ne peut pas tourner en rond (une étape ne peut pas mener à une étape qui la précède) ; supprimer une
  étape relie ses suivantes à ses précédentes ; zoom et « ajuster à l'écran » ; `Suppr` retire l'étape choisie,
  `Échap` annule ;
- tant que le parcours n'est pas enregistré, une barre le rappelle et quitter la page demande confirmation.

**Ce qu'affiche la case de chaque étape.** Dans ses réglages, chaque étape choisit :

- ses **canaux de communication** et ses **supports** (Reel, carrousel, newsletter… : ceux des canaux cochés d'abord,
  les autres sur demande) ; les actions et les leads comptés pour l'étape se limitent à ces supports ;
- ses **KPIs affichés dans la case** (12 au plus, dans l'ordre choisi, chacun avec un objectif facultatif) :
  - **KPIs calculés** (une quarantaine) : actions publiées, portée, impressions, engagement, taux d'engagement, clics,
    taux de clic, conversions, coût des actions (canaux et supports de l'étape) ; abonnés, nouveaux abonnés, portée et
    engagement des comptes ; leads, leads qualifiés, convertis, taux de qualification et de conversion, questions ;
    conversations, conversations chaudes, prospects issus des messages, commentaires, taux de réponse aux
    commentaires ; dépense, impressions, clics, CTR, leads, coût par lead et conversions des campagnes associées ;
    visites, inscriptions, formulaires et taux d'inscription des pages associées ; envois, ouvertures, taux
    d'ouverture, clics et taux de clic des emails associés. Une nouvelle étape reçoit d'office les KPIs qui vont avec
    sa source de chiffres ;
  - **KPIs manuels** : on leur donne un nom (« Rendez-vous pris », « Appels passés »…), un type (nombre, pourcentage,
    montant) et un calcul sur la période (total, ou dernière valeur saisie).

Dans le suivi, chaque case montre ses KPIs avec leur valeur sur la période (coche verte quand l'objectif est atteint),
le détail par étape les reprend tous, et **« Saisir des chiffres »** ouvre une fenêtre avec une date et un champ par
chiffre manuel (prospects des étapes en saisie manuelle et KPIs manuels), groupés par étape ; une date déjà saisie
s'affiche pour être corrigée. Retirer un KPI manuel supprime ses chiffres saisis.

Sur téléphone, le parcours se lit de haut en bas et les réglages s'affichent sous le canevas. Les funnels existants
(en ligne droite) sont repris tels quels à la mise à jour : chaque étape suit la précédente, et on peut ensuite leur
ajouter des branches. Les rapports (écran et email) indiquent pour chaque étape d'où elle vient et signalent le
circuit qui convertit le mieux.

**Design.** Fond lavande, cartes blanches arrondies, couleur principale violette, icônes au trait dans des tuiles
pastel (une couleur par domaine : leads bleu, questions turquoise, communication violet, publicité orange, projets
rose…), police Plus Jakarta Sans, mêmes couleurs de graphiques en clair et en sombre. Les confirmations (supprimer,
retirer, quitter sans enregistrer, envoyer un rapport) s'ouvrent dans une fenêtre du tableau de bord dont les boutons
disent l'action (« Supprimer la campagne » / « Annuler ») ; `Échap` annule. Les messages vides expliquent ce que la
section contient et comment la remplir.

Les semaines vont du lundi au dimanche. Une semaine ou un mois en cours est comparé au même nombre de jours de la
période précédente (du 1er au 7 novembre contre du 1er au 7 octobre), jamais à une période entière.

### Connexion Meta : Facebook, Instagram, Meta Ads (remplissage et classement automatiques)

Menu **Connexions** du tableau de bord, en trois étapes :

1. **App Meta** — si l'app Meta d'ecom.dehini.com existe (`~/ecom/.env` : `META_APP_ID` / `META_APP_SECRET`), elle
   est reprise toute seule. Sinon : sur developers.facebook.com, créer une app (type « Entreprise ») reliée au
   Business Manager de YINVESTI, ajouter **Facebook Login** avec l'URI de redirection
   `https://dehini.com/marketing/meta.php` (affichée avec un bouton « Copier »), puis coller l'App ID et la clé
   secrète dans Connexions. Tant que l'app est « en développement », seuls ses administrateurs peuvent se connecter :
   c'est suffisant pour ce tableau de bord.
   **« URL bloquée » chez Facebook** = l'adresse de retour n'est pas autorisée dans l'app (y compris quand c'est l'app
   d'ecom.dehini.com qui est reprise). Dans l'app Meta › Facebook Login (ou Facebook Login for Business) ›
   Paramètres : activer « Connexion OAuth cliente » et « Connexion OAuth Web », ajouter
   `https://dehini.com/marketing/meta.php` (exactement l'adresse affichée dans Connexions, avec son bouton « Copier »)
   dans « URI de redirection OAuth valides », enregistrer ; dans Paramètres › Général, `dehini.com` doit figurer dans
   « Domaines de l'app ». Si l'adresse affichée n'a pas le bon domaine ou commence par `http://`, régler « Adresse du
   tableau de bord » dans Réglages (`https://dehini.com/marketing/`). Une app « Facebook Login for Business » peut
   refuser les autorisations demandées (« Invalid Scopes ») : créer une configuration (Jeton d'accès utilisateur, avec
   les autorisations listées ci-dessous) et coller son ID dans Connexions.
2. **Connexion** — « Se connecter avec Facebook » (autorisations en lecture seule : `pages_show_list`,
   `pages_read_engagement`, `pages_read_user_content`, `read_insights`, `business_management`, `instagram_basic`,
   `instagram_manage_insights`, `instagram_manage_comments`, `ads_read`, `leads_retrieval`, `pages_manage_ads` ;
   rien n'est publié ni modifié chez Meta). La connexion dure environ 60 jours : l'accueil prévient 7 jours avant.
   Autre méthode, sans expiration : coller un **jeton d'utilisateur système** (Business Manager › Paramètres ›
   Utilisateurs système › Générer un jeton). Les jetons sont chiffrés (AES-256-GCM) avec une clé rangée hors de
   `public_html` (`~/marketing-data/meta.key`) et ne sont jamais renvoyés au navigateur.
3. **Page, Instagram et comptes publicitaires** — choisir la Page Facebook (son compte Instagram professionnel suit),
   les comptes publicitaires, le taux de conversion si le compte publicitaire est dans une autre devise (ex. 1 USD =
   250 DA), la profondeur de la première importation (30, 60 ou 90 jours) et l'import des questions en commentaire.
   Une fois enregistrés, seuls les choix s'affichent (bouton « Changer » / « Modifier ») ; la liste des comptes
   publicitaires a une recherche et masque les comptes inactifs (désactivés, fermés, paiement en attente), qu'une case
   permet d'afficher. Pour ne voir que les comptes de YINVESTI dès la fenêtre Facebook, ne cochez qu'eux lors de la
   connexion (« Modifier les paramètres » de la fenêtre).

**Se connecter avec Instagram.** Si aucun compte Instagram professionnel n'est relié à la Page, la carte Instagram
propose de le connecter directement (Business Login for Instagram) : dans l'app Meta, ajouter le cas d'utilisation
Instagram (« Configuration de l'API avec la connexion Instagram »), y déclarer l'URI de redirection
`https://dehini.com/marketing/meta.php`, puis coller dans Connexions l'**ID de l'app Instagram** et la **clé secrète de
l'app Instagram** (différents de l'App ID Facebook) et cliquer sur « Se connecter avec Instagram » (autorisations
`instagram_business_basic`, `instagram_business_manage_insights`, `instagram_business_manage_comments`). Le jeton
Instagram dure 60 jours et se renouvelle tout seul à chaque synchronisation. « Invalid platform app » chez Instagram =
l'ID collé n'est pas l'ID de l'app Instagram (souvent l'App ID Facebook, que le tableau de bord refuse désormais) ou
la connexion Instagram n'est pas encore configurée dans l'app : « Modifier l'app Instagram » permet de recoller le bon. Autre solution : relier le compte
Instagram à la Page dans Meta Business Suite › Paramètres › Comptes › Comptes Instagram, puis se reconnecter.

Ce que la synchronisation remplit (bouton « Synchroniser maintenant », et chaque matin avec la tâche cron) :

| Source Meta | Va dans | Classement automatique |
| --- | --- | --- |
| Statistiques de la Page et du compte Instagram | Social Media › Relevés des comptes (un relevé par jour : portée, vues, engagement, nouveaux abonnés, abonnés, clics) | canal Facebook / Instagram |
| Publications, Reels, carrousels, stories | Communication › Actions (portée, vues, engagement, clics, lien) | support (Post, Carrousel, Reel, Story, Live), cible et objectif d'après le texte |
| Campagnes Meta Ads | Ads Manager › Campagnes (nom, statut, dates, budget converti) et Résultats jour par jour (dépense, impressions, clics, leads, conversions) | objectif Meta → objectif du tableau de bord, cible d'après le nom |
| Formulaires Lead Ads | Leads & Contacts (nom, téléphone, email, ville, réponses dans les notes, campagne et funnel) | profil investisseur / porteur, canal Meta Ads (ou Facebook / Instagram si organique), support Formulaire |
| Tous les commentaires Facebook et Instagram, avec leurs réponses | Leads & Contacts › Commentaires | sujet, profil, question posée, téléphone / email / wilaya laissés, « la Page a répondu » |
| Commentaires qui posent une question (français, arabe, darija) | Leads & Contacts › Questions | sujet (investissement, projet, plateforme, partenariat, réclamation) et profil |

**Messages Messenger et Instagram** (Leads & Contacts › **Messages**). Les conversations de la Page (Messenger) et du
compte Instagram relié sont importées à chaque synchronisation, **avec tous leurs messages** (une longue conversation
est lue page par page jusqu'aux messages déjà importés), et analysées :
sujet (investissement, projet, plateforme…), profil (investisseur / porteur), question posée, intention forte (« je
veux investir », « rappelez-moi », numéro laissé…), **téléphone** (format algérien, chiffres arabes compris),
**email** et **wilaya** trouvés dans les messages, temps de première réponse, et une **chaleur** (Chaud / Tiède /
Froid, score sur 100). Filtres : sans réponse, chaudes, avec coordonnées, sans prospect, prospects créés, ignorées.
« Lire » ouvre le fil de discussion et l'analyse ; **« Créer le prospect »** ouvre un formulaire prérempli (nom,
téléphone, email, wilaya, profil) à relire, puis crée le lead dans Leads & Contacts (canal Messenger ou Instagram,
lien et derniers messages dans les notes) ; une personne déjà enregistrée (même téléphone ou email) est reliée au
lieu d'être recréée. Le tableau de bord ne fait que lire : on répond depuis Meta Business Suite (bouton « Répondre »).
Autorisations à ajouter à la configuration Facebook Login for Business : `pages_messaging`, `pages_manage_metadata`,
`instagram_manage_messages`, puis « Reconnecter ». L'accueil affiche les messages sans réponse.

**Commentaires Facebook et Instagram** (Leads & Contacts › **Commentaires**). Tous les commentaires des publications,
avec leurs réponses (celles de la Page sont marquées « Vous (la Page) »), et pas seulement ceux qui posent une
question. À chaque synchronisation, les publications des 3 derniers mois dont le nombre de commentaires a changé sont
relues : les nouveaux commentaires des anciennes publications arrivent aussi. Indicateurs (commentaires, sans réponse
de la Page, répondus, questions, coordonnées laissées, prospects créés), sujets, réseaux, profils ; filtres (sans
réponse, questions, avec coordonnées, Facebook, Instagram, prospects, traités, ignorés) ; recherche ; pour chaque
commentaire : « Créer le prospect » (formulaire prérempli), « Répondre » (ouvre le commentaire chez Meta), « Traité »,
« Ignorer ». L'accueil affiche les commentaires sans réponse.

**Tout afficher, tout importer.** Messages et Commentaires ont un choix « *période* / **Tout l'historique** » (toutes
les conversations ou tous les commentaires importés, quelle que soit la date) et un bouton « Afficher plus » (100 par
100). Le bouton **« Importer tout l'historique »** (dans Messages, Commentaires et Connexions › Synchronisation) relit
chez Meta toutes les conversations avec tous leurs messages, et tous les commentaires des publications des 2 dernières
années ; cela peut prendre plusieurs minutes, et s'il s'arrête avant la fin il suffit de le relancer.

**Répondus / sans réponse.** Messages : « Déjà répondues » (la Page a répondu en dernier) et « Sans réponse, à
traiter » (le dernier message vient de la personne, conversation ni traitée ni ignorée), avec les filtres Répondues
et Traitées et un bouton « Traitée » ; un nouveau message de la personne remet la conversation « à traiter ».
Commentaires : « Déjà répondus » (la Page a répondu) et « Sans réponse, à traiter », avec les filtres correspondants.
L'accueil affiche les deux nombres.

**Temps réel** (Connexions › *Temps réel : messages et commentaires* › « Activer le temps réel »). Le tableau de
bord abonne l'app Meta et la Page (et le compte Instagram) : Meta envoie alors chaque nouveau message (Messenger,
Instagram) et chaque nouveau commentaire (Facebook, Instagram) à `marketing/webhook.php`, qui le range aussitôt
(envois refusés s'ils ne sont pas signés avec la clé secrète de l'app). Les pages ouvertes l'affichent toutes seules
en quelques secondes (pastille « En direct », notification « Message de … »), sans recharger. Il faut une adresse en
`https://`. Tant que l'app Meta est en mode Développement, Meta n'envoie en temps réel que les messages et
commentaires des personnes qui ont un rôle dans l'app : pour tout recevoir, passer l'app en mode Live. En attendant
(ou en complément), le tableau de bord vérifie les nouveaux messages et commentaires toutes les 2 minutes tant qu'il
est ouvert, et la tâche cron `cron.php --live` (toutes les 5 minutes) le fait même tableau de bord fermé.

**Synchronisation par lots.** Chaque synchronisation lancée depuis le tableau de bord travaille 40 secondes au plus
(les hébergeurs coupent les requêtes plus longues) ; ce qui reste passe au lot suivant, enchaîné tout seul
(« Synchroniser maintenant », « Importer tout l'historique », synchronisation automatique, tâche cron). La période
n'avance qu'une fois tout importé sans erreur. Connexions signale une synchronisation en cours ou pas terminée.

**Noms des commentateurs.** Meta ne donne le nom de l'auteur d'un commentaire Facebook qu'aux apps qui ont
`pages_read_user_content` et sont « en ligne » (mode Live) ; sinon la question s'affiche « Nom masqué par Meta » avec
un lien « Voir le commentaire » pour l'ouvrir sur Facebook. Sur Instagram, le nom d'utilisateur est toujours donné.

Le classement suit des mots-clés modifiables dans **Connexions › Classement automatique** (avec un champ « Essayer »
qui montre le résultat en direct). Règles de mise à jour : les chiffres venant de Meta sont mis à jour à chaque
synchronisation (les 3 derniers jours, et les publications des 14 derniers jours) ; ce que l'équipe complète (statut
et commercial d'un lead, objectif chiffré et funnel d'une campagne…) n'est jamais écrasé ; une campagne ou un lead déjà
saisi à la main (même nom de campagne, même téléphone ou email) est relié au lieu d'être dupliqué ; une donnée
importée puis supprimée n'est pas réimportée. Les lignes importées portent l'étiquette « Meta ». Les indicateurs
retirés par Meta (impressions remplacées par les vues en 2025, par exemple) sont détectés et remplacés tout seuls par
leurs successeurs. **Connexions › Historique** garde le détail des 60 dernières synchronisations.

**Synchronisation automatique.** Tant que le tableau de bord est ouvert (même dans un onglet en arrière-plan), Meta est
synchronisé tout seul à l'intervalle choisi dans Connexions › Synchronisation (15 min à 12 h, 1 h par défaut, ou
désactivé) ; s'il y a du nouveau, une notification le signale et la page se met à jour. Le choix de la Page Facebook est
obligatoire pour Facebook, les leads et les messages : l'accueil et Connexions préviennent s'il manque (une seule Page
accessible est choisie d'office). Pour synchroniser aussi quand personne n'a ouvert le tableau de bord, ajouter une 2e
tâche cron (cPanel › Tâches Cron › « Une fois par heure ») :

```bash
/usr/local/bin/php ~/public_html/marketing/cron.php --meta-only > /dev/null 2>&1
```

### Changer d'app Meta sans rien perdre (Connexions › Nouvelle app Meta)

Pour passer à une nouvelle app Meta (ici « chatgptapp », ID `3025910297765050`) pendant que l'ancienne continue de
fonctionner. Guides : [`docs/meta-app-review.md`](docs/meta-app-review.md) (autorisations exactes et leur usage,
configuration Facebook Login for Business, webhooks, jeton d'utilisateur système, Instagram, examen par Meta) et
[`docs/meta-real-test-checklist.md`](docs/meta-real-test-checklist.md) (liste de contrôle avec les vrais comptes).

1. **Sur le serveur**, dans `~/public_html/marketing/config.php` (jamais dans GitHub) :

   ```php
   define('MK_META_NEXT_APP_ID', '3025910297765050');
   define('MK_META_NEXT_APP_SECRET', '… clé secrète de la nouvelle app …');
   define('MK_META_NEXT_CONFIG_ID', '… ID de la configuration Facebook Login for Business …');
   define('MK_META_WEBHOOK_VERIFY_TOKEN', '… texte secret au choix, 20 caractères ou plus …');
   ```

   La clé n'est jamais envoyée au navigateur.
2. **Chez Meta**, dans la nouvelle app : URI de redirection `https://dehini.com/marketing/meta.php`, politique de
   confidentialité `https://dehini.com/marketing/confidentialite.html`, suppression des données
   `https://dehini.com/marketing/suppression-donnees.html`, une configuration Facebook Login for Business (jeton
   d'utilisateur système, les 15 autorisations listées dans l'écran, la Page, @yinvesti_ et le compte publicitaire comme
   ressources), et l'objet **Instagram** des webhooks (`comments`, `messages`) réglé une fois dans le tableau de bord de
   l'app : l'API de Meta ne permet pas de le faire automatiquement.
3. **Dans le tableau de bord**, Connexions › Nouvelle app Meta : « Se connecter avec la nouvelle app », puis
   « Lancer les tests » : connexion (validité, expiration), autorisations par fonction et par ressource, Page,
   statistiques, publications et commentaires, messages, Instagram, publicité, Lead Ads, numéros identiques à ceux
   déjà enregistrés (aucun doublon), abonnements aux webhooks relus chez Meta. Puis « Activer le temps réel en
   parallèle » : la Page est abonnée aux deux apps, chaque envoi est enregistré une fois, et un compteur montre ce que
   la nouvelle app envoie vraiment. Rien ne change pour la production tant que ce n'est pas basculé.
4. **« Basculer vers la nouvelle app »** : l'ancienne app est désabonnée de la Page, la nouvelle y est abonnée et
   vérifiée. Aucune donnée n'est touchée ; un Instagram connecté par « Se connecter avec Instagram » passe par la Page
   (relevés renumérotés si le numéro du compte diffère, conversations rattachées par personne).
   **« Revenir à l'ancienne app »** remet tout comme avant (la nouvelle reste prête), **« Terminer la migration »**
   oublie l'ancienne app une fois tout validé.

**Santé de la connexion.** Meta ne prévient pas quand un jeton est retiré, même un jeton « sans expiration » : la
connexion active est vérifiée chez Meta toutes les 6 heures (et par « Vérifier maintenant »). Jeton de Page refusé :
redemandé tout seul. Connexion refusée : raison affichée, synchronisations suspendues sans rien effacer, un email aux
destinataires des rapports. Trop de requêtes : les passages automatiques attendent 15 minutes.

### Rapports automatiques par email

1. Dans le tableau de bord → **Réglages** : saisir les destinataires et cocher les rapports voulus (quotidien,
   hebdomadaire, mensuel), puis « Envoyer un test ». Les emails partent par le compte SMTP du défi Impact
   (`impact/config.php`) s'il est configuré, sinon par la fonction `mail()` du serveur ; un autre compte peut être
   réglé dans `marketing/config.php` (modèle : `marketing/config.example.php`).
2. Dans **cPanel → Tâches Cron**, **une fois par jour** (par exemple Minute `5`, Heure `7`, autres champs `*`) :

   ```bash
   /usr/local/bin/php ~/public_html/marketing/cron.php > /dev/null 2>&1
   ```

Chaque matin : synchronisation Meta (si un compte est connecté), puis rapport de la veille ; le lundi : rapport de la
semaine écoulée ; le 1er : rapport du mois écoulé. Chaque rapport est aussi **archivé** (Overview → Archives), même sans destinataire. Si la tâche repasse le même jour,
rien n'est envoyé deux fois ; si l'envoi a échoué pour tous les destinataires, il est retenté au passage suivant. `php cron.php --dry-run` affiche ce qui partirait (avec les constats) sans rien envoyer ;
`--date=2026-11-09` rattrape un jour manqué ; `--force=week` produit un rapport hors de son jour ;
`--test=adresse@exemple.com` envoie les trois rapports à cette adresse ; `--no-meta` saute la synchronisation Meta,
`--meta-only` ne fait qu'elle.

## Maillage (`/maillage/`)

Plateforme qui réunit, pour des marques, leurs distributeurs et les points de vente, la découverte des produits,
leur disponibilité près de chez soi, la commande et l'analyse de la demande. Une seule base :

| Adresse | Pour qui | Ce qu'on y fait |
|---|---|---|
| `https://dehini.com/maillage/` | entreprises | **Landing page d'inscription** : présentation, carte en direct, formulaire (marque ou distributeur), démonstration |
| `https://dehini.com/maillage/point-de-vente/` | magasins | **Landing page des points de vente** : le radar expliqué, inscription d'un point de vente (wilaya, commune, marques choisies), espace d'un magasin en démonstration |
| `https://dehini.com/maillage/offre/?p=<produit>` | clients | **Page de vente d'un produit** (à partager dans les publicités) : photos, accroche, points forts, commande livrée payée à la réception |
| `https://dehini.com/maillage/explorer/` | clients | **Plateforme publique** : carte des points de vente, marques, catalogue, « où le trouver », commande ou réservation, suivi |
| `https://dehini.com/maillage/espace/` | entreprises, magasins | **Espace entreprise** : points de vente, catalogue, stock par magasin, commandes, radar, réseau, analyses — et **espace privé de chaque point de vente** (même adresse, avec ses propres identifiants) |
| `https://dehini.com/maillage/admin.html` | toi | **Administration** : inscriptions à valider, entreprises, réglages, démonstration (même mot de passe que `/admin`) |

Les six fonctions :

1. **Carte interactive** (`explorer/#/carte`) — magasins, succursales et distributeurs sur un fond OpenStreetMap
   (Leaflet), recherche par nom, ville ou marque, filtres (type, ouvert maintenant, livraison), « autour de moi »
   ou choix de la ville, tri du plus proche au plus loin, téléphone, horaires et itinéraire.
2. **Marques et produits** (`#/marques`, `#/produits`) — une page par marque, fiches produit (photos, prix,
   caractéristiques), classement par rayon, recherche sans accents sur le nom, la marque, les caractéristiques, les
   tailles et les couleurs.
3. **Disponibilité** (`#/produit/…`) — le client choisit une taille et une couleur : la fiche liste les points de
   vente qui l'ont en stock, avec la distance et le niveau de stock (« en stock », « plus que 2 »).
4. **Commandes et réservations** — depuis la fiche, choix du point de vente, puis réservation (article mis de
   côté 48 h), commande à retirer ou commande livrée, selon ce que le magasin propose. Pas de paiement en ligne :
   le client paie au retrait ou à la livraison. Il suit sa demande avec son numéro (`ML-XXXXXX`) et son téléphone,
   et peut l'annuler tant qu'elle n'est pas confirmée. Le stock du magasin baisse à la commande et remonte si elle
   est annulée ou si la réservation expire.
5. **Espace entreprise** — vue d'ensemble (étapes de mise en route, commandes à traiter, stock à surveiller,
   activité du réseau), commandes (confirmer → prête ou expédiée → retirée ou livrée, annuler avec motif),
   catalogue (marques, produits, déclinaisons taille × couleur, photos), points de vente (épingle placée d'un clic
   sur la carte, horaires, services), disponibilité magasin par magasin, réseau (une marque invite un distributeur
   ou un distributeur demande à distribuer une marque ; une fois le partenariat accepté, le distributeur référence
   le catalogue de la marque dans ses magasins).
6. **Analyses** — recherches et commandes par jour, chiffre d'affaires, mots recherchés, **demandes restées sans
   réponse**, produits, tailles et couleurs les plus demandés, demande par wilaya, tableau de bord des points de
   vente et des distributeurs (commandes menées à terme, annulations, délai de confirmation, taux de
   disponibilité), et **expansion** : les wilayas où l'on cherche les produits sans point de vente à moins de
   30 km, classées par nombre de recherches.

**Livraison : wilaya → commune → adresse facultative.** Toute commande livrée demande la wilaya puis la commune
(liste officielle des 1 541 communes, en français et en arabe, `lib/data/communes.json`) ; l'adresse précise est
facultative (le point de vente la demande au téléphone).

**Le radar.** La fiche produit (« Commander · livraison à domicile ») et la page de vente d'un produit prennent une
commande **sans choisir de point de vente**, payée à la livraison. Elle est proposée aux points de vente qui ont
l'article en stock, du plus proche du client au plus loin : **ceux de sa commune**, puis, si personne ne l'a prise,
**ceux de sa wilaya**, puis **tous** (un niveau de plus toutes les 30 minutes ; réglable dans `admin.html`, 0 = tous
tout de suite). Rien n'est calculé par une tâche planifiée : le niveau se déduit de l'heure de la commande. Les
points de vente concernés la voient dans **Radar** (et par email, avec une pastille dans le menu) ; **le premier
qui la prend la traite** : son stock baisse à ce moment-là, il voit alors les coordonnées du client et la commande
suit le parcours habituel (expédiée → livrée). S'il ne peut pas la servir, il la **remet sur le radar** (stock
rétabli). La marque suit dans **Radar → Commandes reçues par vos pages** toutes les commandes de ses pages, y
compris celles prises par les magasins de ses distributeurs (en lecture seule pour elle) ; une commande que
personne ne peut servir (aucun stock) lui reste, elle peut la traiter ou l'annuler.

**Pages de vente.** Dans la fiche d'un produit (Catalogue → un produit → Page de vente) : accroche, points forts,
argumentaire et l'adresse de la page à partager (`offre/?p=…`).

**Points de vente.** Chaque point de vente peut avoir son **accès privé** : la marque ou le distributeur saisit un
email et un mot de passe dans sa fiche (obligatoires à la création). Le magasin se connecte sur `espace/` et ne voit
que sa vue d'ensemble, le radar, ses commandes, son stock, sa fiche et son compte (le catalogue, le réseau, les
analyses et les autres magasins lui sont fermés, côté serveur). Un **point de vente indépendant** s'inscrit depuis
`point-de-vente/` : il choisit sa wilaya, sa commune et les marques qu'il veut vendre (chaque marque reçoit une
demande de partenariat à accepter dans Réseau) ; sa fiche est créée tout de suite, à placer sur la carte.

**Recherche par photo** (`explorer/#/photo`, entrée « Photo » du menu) — le client prend une photo (appareil photo du
téléphone, webcam sur ordinateur) ou choisit une image :

1. **Identification, sans service extérieur.** La photo est reconnue dans le navigateur du client par un modèle
   d'image hébergé sur le site (TinyCLIP, licence MIT ; voir `tools/vision/README.md`) : type de produit (≈ 250, en
   français et en arabe), rayon, couleur. Si elle ressemble nettement à une photo du catalogue, cette fiche est
   proposée en priorité. C'est toujours une **proposition à vérifier**, affichée comme telle, avec d'autres
   possibilités à choisir en un geste ; si rien n'est reconnu, le client décrit le produit. Le modèle (≈ 37 Mo) se
   télécharge à la première recherche par photo, avec une barre d'avancement et un bouton pour ne pas attendre.
2. **Recherche** avec l'image *et* la description : mots du nom et des détails, et ressemblance de la photo avec
   celles du catalogue (signature visuelle calculée à l'enregistrement de chaque photo de produit).
3. **Produit trouvé** seulement avec une preuve, affichée : photo identique à celle du catalogue, ou nom (et marque) identiques. La fiche
   montre alors les points de vente qui l'ont en stock (du plus proche au plus loin), la marque, et les boutons
   fiche produit / points de vente proches / marque / produits similaires.
4. Sinon, les **produits les plus proches**, clairement présentés comme similaires ; et un message clair s'il n'y a rien.
5. **Recherche active (Radar)** : le client envoie sa photo et sa description au réseau. La demande apparaît dans le
   **Radar** des points de vente (commune, puis wilaya, puis tous, même délai que les commandes) et des marques du
   rayon ; chacun répond « J'ai ce produit » (magasin, fiche du catalogue, prix, message) ou l'écarte. Le client suit
   sa recherche (`RS-XXXXXX` + téléphone, ou lien, dans « Suivi ») et la clôt quand il a trouvé. Son téléphone n'est
   montré qu'aux comptes qui ont répondu, s'il l'a accepté. Une recherche se clôt seule après 14 jours.

L'image n'est conservée que si une recherche active est lancée (`media/r/`, supprimée un mois après la clôture).
**La plateforme apprend** : quand un client clôt sa recherche « J'ai trouvé » et qu'un point de vente y avait
répondu avec une fiche du catalogue, la photo du client est rattachée à ce produit (30 au plus par produit). Les
photos suivantes du même produit le font proposer — comme proposition ou produit similaire, jamais comme preuve.
Limite à connaître : la comparaison porte sur les formes et les couleurs de l'image ; elle reconnaît une photo du
catalogue ou une photo déjà apprise, pas un objet photographié sous un angle tout à fait nouveau.

**Base Algérie (fiches publiques)** — 100 produits (50 téléphones du catalogue E-Mobile, 50 vêtements Decathlon),
28 marques, 25 entreprises, 24 points de vente physiques (Alger, Oran, Constantine, Sétif) et 100 offres en ligne,
relevés dans des sources publiques le 13 novembre 2026 (`maillage/lib/data/import/`, préparé par
`tools/import/prepare_algerie.py`). Import **automatique**, une seule fois, à la première visite après la mise en ligne (`MLG_AUTO_IMPORT`, `false` pour le
désactiver) ; mise à jour depuis `admin.html` → « Base Algérie : fiches publiques » (relançable, sans doublon). Ces entreprises n'ont pas de compte : elles sont publiées comme
**fiches publiques** (mention visible, source et date de vérification), **sans prix ni stock inventés** (« Prix non
communiqué », « Stock à confirmer par téléphone »), sans commande vers leurs magasins ; la page produit renvoie vers
la fiche du vendeur en ligne et liste les magasins qui vendent la marque. Les magasins sont placés sur la carte
(OpenStreetMap) ; ceux placés au centre de leur commune portent la mention « position approximative ». Les photos
servent aussi à la recherche par photo.

**Français / arabe** — toutes les pages (landings, plateforme publique, page de vente, espace, administration) sont
bilingues : un bouton FR/AR (en haut de chaque page) bascule la langue, mémorisée par appareil (`localStorage`),
avec mise en page RTL (sens de lecture, icônes directionnelles, carte, tableaux) et police Readex Pro (déjà
utilisée pour l'arabe sur le Défi Impact, pour rester cohérent visuellement). Le dictionnaire de traduction
(`assets/i18n.js`) ne touche que les textes fixes de l'interface, jamais le contenu saisi par les entreprises ou
les clients (noms de produits, adresses, notes…), pour ne jamais l'altérer. Limites connues : les emails
(confirmation de commande, mot de passe oublié…) restent en français ; quelques libellés de wilaya résolus
côté serveur (zone de livraison d'une commande, ville d'un distributeur dans l'annuaire réseau) s'affichent aussi
en français faute du code de wilaya transmis à ces endroits précis.

### Organisation

- `maillage/index.html` + `assets/landing.{css,js}`, `assets/terms.js` — la landing.
- `maillage/point-de-vente/` + `assets/pos.{css,js}` — la landing des points de vente.
- `maillage/offre/` + `assets/offre.{css,js}` — la page de vente d'un produit ; `assets/cod.js` — le formulaire de
  commande livrée (radar), partagé avec la fiche produit.
- `maillage/explorer/` + `assets/explorer.{css,js}` — la plateforme publique ; `assets/visual.{css,js}` — la
  recherche par photo et le suivi d'une recherche active ; `assets/vision.js` + `assets/vision/` — la
  reconnaissance d'image dans le navigateur (construite par `tools/vision/build.py`).
- `maillage/espace/` + `assets/espace*.{css,js}`, `assets/charts.js` — l'espace entreprise et ses graphiques.
- `maillage/admin.html` + `assets/admin.js` — l'administration.
- `maillage/assets/core.js`, `base.css`, `icons.js` — socle commun (appels à l'API, gabarits HTML échappés,
  carte, formats, icônes Lucide utilisées).
- `maillage/api.php` — l'API (la liste des actions est en tête du fichier).
- `maillage/lib/` — `core.php` (configuration, base, emails), `auth.php` (comptes, sessions), `catalog.php`
  (côté public, recherche, suivi des recherches), `orders.php`, `radar.php` (commandes sans point de vente choisi),
  `visual.php` (recherche par photo : signatures, identification, apprentissage), `research.php` (recherches actives),
  `dash.php` (espace entreprise et point de vente), `analytics.php`, `media.php` (photos), `seed.php`
  (démonstration), `data/zones.json` (wilayas et coordonnées de leur chef-lieu), `data/communes.json` (communes).
- La base se met à jour toute seule (version 2 : commune, radar, accès des points de vente, pages de vente) au
  premier passage après la mise à jour du code ; rien n'est perdu.
- `maillage/vendor/` — Leaflet 1.9.4 et Leaflet.markercluster 1.5.3, embarqués (pas de CDN).
- La base est créée toute seule au premier passage dans `~/maillage-data/maillage.sqlite` (hors `public_html`).
  Les photos des produits vont dans `maillage/media/` (ré-encodées en WebP, ignorées par Git).

### 1. Mise en route

Il suffit que le dossier soit copié dans `public_html` (les commandes d'installation et le cron ci-dessus le font).
**Si le cron est déjà en place sur le serveur, il faut lui ajouter `cp -rf maillage ~/public_html/`** (cPanel →
Tâches Cron → modifier la commande, voir « 4. Mise à jour automatique du code »).

À la première visite, un **réseau de démonstration** est chargé : trois marques fictives (Vélora, Dar Lina, Kivo),
deux distributeurs, 22 points de vente, 30 produits et deux mois de recherches et de commandes. Tout est marqué
« Démo ». Il sert à montrer la plateforme avant l'arrivée des premières entreprises, et il alimente le bouton
« Voir le tableau de bord en démonstration » de la landing (session en **lecture seule**). Elle comprend des
commandes radar (libres et prises) et l'espace privé d'un magasin (« Voir l'espace d'un magasin » sur
`point-de-vente/`).
Pour le retirer : `https://dehini.com/maillage/admin.html` → « Supprimer la démonstration » (on peut la recharger
au même endroit). Pour ne jamais la charger : `define('MLG_SEED_DEMO', false);` dans `maillage/config.php` avant la
première visite.

### 2. Inscriptions

Une entreprise s'inscrit depuis la landing et arrive tout de suite dans son espace, où elle peut tout préparer.
**Son réseau n'apparaît sur la plateforme publique qu'après ta validation** : `maillage/admin.html` → « Valider ».
Dans les réglages de cette page :

- **« Valider chaque inscription avant sa mise en ligne »** — activé par défaut. Désactivé, une entreprise inscrite
  est publiée immédiatement.
- **« Email prévenu à chaque inscription »** — mets ton adresse pour être averti.

« Ouvrir l'espace » ouvre l'espace d'une entreprise en ton nom (pour l'aider ou vérifier) ; « Suspendre » retire
son réseau de la plateforme ; la corbeille supprime l'entreprise et tout ce qui lui appartient.

### 3. Emails

Bienvenue, inscription validée, nouvelle commande (au magasin, sinon à l'entreprise), confirmation et suivi au
client s'il a laissé son email, demande de partenariat, mot de passe oublié. Comme pour Stats Meta KPIs, le compte
SMTP du défi Impact (`impact/config.php`) est réutilisé s'il existe ; sinon `mail()` du serveur. Pour un compte
dédié, voir `maillage/config.example.php`. Un email qui échoue ne bloque jamais une commande ou une inscription.

### 4. Fond de carte

Les tuiles viennent des serveurs d'OpenStreetMap, gratuits et prévus pour un **trafic modéré**. Si la plateforme
prend de l'ampleur, passer à un fournisseur de tuiles (MapTiler, Stadia Maps, Thunderforest… souvent gratuits
jusqu'à un certain volume) avec `MLG_TILES_URL` et `MLG_TILES_ATTR` dans `maillage/config.php`. La recherche
d'adresse du formulaire « point de vente » interroge Nominatim (OpenStreetMap), une fois, au clic.

### 5. Réglages (`maillage/config.php`, facultatif)

Copier `maillage/config.example.php` : devise (`MLG_CURRENCY`, « DA » par défaut), durée d'une réservation
(`MLG_RESERVATION_HOURS`, 48), rayon de couverture d'un point de vente pour l'analyse d'expansion (`MLG_COVER_KM`,
30), centre et zoom de la carte, emplacement de la base, compte email. Les rayons du catalogue sont dans
`lib/core.php` (`MLG_CATEGORIES`) ; les zones dans `lib/data/zones.json`.

### 6. Tests

```bash
tests/maillage/run.sh /tmp/mlg-test
```

Faux hébergement neuf, puis parcours complet dans Chromium (Playwright) : inscription d'une marque depuis la
landing, produit avec photo, point de vente placé sur la carte, stock, validation par le propriétaire, recherche et
réservation côté client sur mobile, traitement de la commande, annulation par le client, distributeur et
partenariat, analyses, radar (page de vente, commune obligatoire, niveaux commune → wilaya → partout, prise,
remise, livraison), recherche par photo (identification interne, apprentissage) et recherche active, accès privé d'un point de vente, inscription depuis la landing des points de vente, mot de passe
oublié, démonstration en lecture seule, cloisonnement entre entreprises,
fichiers internes interdits, politique de sécurité (CSP), absence d'erreur JavaScript et de défilement horizontal.
Voir `tests/maillage/README.md`.

### Ce qui n'est pas fait

- Pas de paiement en ligne, ni de frais de livraison calculés : le point de vente s'arrange avec le client.
- Une commande porte sur un produit (une déclinaison, une quantité) dans un point de vente ; il n'y a pas de panier
  multi-produits.
- Un seul compte par entreprise (pas encore d'équipe avec plusieurs utilisateurs), plus un accès par point de vente.
- Le radar propose une commande aux magasins par commune puis par wilaya (pas de calcul de distance routière) ; la
  commande porte sur un seul article.
- Les conditions d'utilisation affichées à l'inscription sont un texte de départ, à relire et adapter.

## Studioma (`/studioma/`)

Réseau social professionnel et studio collaboratif pour les community managers, les créateurs de contenu, les
agences et les entreprises. Trois expériences dans une seule plateforme, sur une seule base : *Create together.
Grow professionally. Get discovered.* L'interface est en anglais.

| Adresse | Pour qui | Ce qu'on y fait |
|---|---|---|
| `https://dehini.com/studioma/` | tout le monde | **Landing** : présentation, boutons d'inscription (particulier ou entreprise) |
| `https://dehini.com/studioma/app/` | membres | **L'application** : fil, découverte, réseau, projets, Studios, opportunités, messages, notifications, profil, réglages — et, pour les entreprises, talents et équipe |
| `…/app/#/u/<identifiant>`, `#/c/<entreprise>`, `#/opportunity/<n>`, `#/post/<n>` | visiteurs | **Pages publiques** lisibles sans compte : profil et portfolio, page d'une entreprise, opportunité, publication |
| `…/app/#/join/<jeton>` | invités | **Invitation** à un projet ou à un Studio (lien ou email) |
| `…/app/#/pricing` | tout le monde | **Prix** : formules Free, Solo, Team (mensuel ou annuel), comparatif, questions fréquentes (voir « 8. Formules et abonnements ») |
| `https://dehini.com/studioma/admin/` | propriétaire du site | **Administration** : statistiques en direct, comptes, modération, emails et campagnes, système (voir « 7. Administration ») |

### Ce que fait la plateforme

1. **Comptes** — particulier (community manager, créateur, freelance…) ou entreprise (marque, agence), avec un
   email et un mot de passe ou **« Continue with Google »** (voir « 3. Connexion avec Google »). Un compte
   est une personne : elle peut créer plusieurs pages d'entreprise, rejoindre plusieurs équipes, projets et
   Studios sans jamais changer d'identifiants. Premiers pas guidés : profil, puis premier Studio.
2. **Espace de travail privé** — `Projet → Studios → Niveaux → Colonnes → Cartes`. Un projet est une marque, un
   client ou une campagne ; un Studio est un tableau privé. Niveaux et colonnes se créent, se renomment (clic sur
   le nom), se réordonnent au glisser-déposer, se dupliquent, s'archivent et se suppriment ; une colonne se
   déplace d'un niveau à l'autre avec ses cartes. Chaque niveau a une **colonne principale** (arrivée des cartes)
   et peut avoir une **colonne finale**.
3. **Modèle par défaut** — le premier Studio propose *Creation* (💡 Ideas, ✍️ Script in Progress, ✅ Script
   Approved), *Production* (✅ Script Approved, 🎬 Video in Progress, 🎨 Design in Progress, 🚀 Ready to Publish)
   et *Publication* (🚀 Ready to Publish, 📅 Scheduled, ✔️ Published, 🚫 Cancelled). Deux autres modèles : un
   tableau simple et un Studio vide.
4. **Passage de relais** — une carte qui arrive dans la colonne finale d'un niveau est transmise à la colonne
   principale du niveau suivant : automatiquement, à la demande (bouton « Send to… ») ou jamais, au choix pour
   chaque niveau (menu du niveau › *Icon, color and handoff*). **C'est la même carte qui change de colonne**
   (même identifiant) : commentaires, pièces jointes, check-list, responsables, dates et historique la suivent ;
   aucune copie n'est créée. La colonne finale garde le compte des cartes transmises.
5. **Cartes** — titre, script mis en forme, responsable et collaborateurs, échéance, priorité, étape, tags,
   plateformes, type de contenu, pièces jointes (images, vidéos, documents), check-list, commentaires avec
   mentions `@`, demande de validation, historique, date et lien de publication, indicateurs facultatifs.
   Création d'un geste (on enchaîne les idées avec Entrée), déplacement, duplication, sélection multiple
   (Ctrl + clic), archivage et suppression.
6. **Trois vues** — *Board* (le tableau, vue de tous les niveaux ou d'un seul), *Calendar* (échéances et dates
   de publication, à déplacer d'un jour à l'autre) et *My work* (les cartes confiées au compte dans tous ses
   projets, filtrées par échéance, projet, priorité et statut). *Insights* ajoute un tableau de bord privé du
   Studio (avancement, rythme, respect des échéances, répartition).
7. **Équipe** — invitation par email ou par lien, à tout un projet ou à un seul Studio, avec six rôles :
   *Owner*, *Manager*, *Creator*, *Collaborator*, *Client / Approver*, *Viewer*. Validation : la personne
   sollicitée approuve ou demande des modifications ; une carte approuvée avance dans la colonne finale de son
   niveau (et passe au suivant si le relais est automatique). Notifications dans l'application et par email.
   Le tableau se resynchronise tout seul (toutes les 4 secondes) quand un coéquipier le modifie.
8. **Réseau** — profil public (photo, couverture, titre, bio, compétences, plateformes, langues, disponibilité),
   mur de publications (texte, images, vidéos), j'aime, commentaires, partages, enregistrements, abonnements et
   mises en relation, recommandations (affichées seulement si leur destinataire le décide).
9. **Portfolio et preuves de travail** — études de cas (marque, rôle, objectifs, résultats, liens, médias). Trois
   origines sont toujours distinguées : **vérifié par un Studio** (cartes publiées avec leur lien), **confirmé
   par un client**, **déclaré**. Pas de note, pas de classement général.
10. **Entreprises** — page publique, équipe (propriétaire, administrateurs, membres), opportunités (titre,
    description, compétences, à distance ou sur place, lieu, contrat, mode de candidature) et candidatures,
    **annuaire des talents** (recherche, compétence, plateforme, secteur, langue, lieu, expérience,
    disponibilité, portfolio), professionnels enregistrés, invitation à collaborer, messagerie privée.
11. **Assistants IA (serveur MCP)** — Claude, ChatGPT, Gemini CLI et les autres assistants compatibles MCP se
    connectent à `https://dehini.com/studioma/mcp` et gèrent les Studios au nom de la personne : lire les projets,
    les tableaux et les cartes, créer, modifier, déplacer (avec passage de relais), commenter, demander une
    validation, archiver, ajouter niveaux et colonnes, régler les relais, inviter. Voir « 4. Assistants IA ».
12. **Partage sans compte** — un Studio se partage par un **lien public** (lecture seule pour quiconque a le lien)
    ou un **lien privé** (seulement les adresses ajoutées, vérifiées par un code). Un client ou un partenaire sans
    compte consulte, puis demande à commenter ou à modifier ; le responsable accepte ou refuse, choisit
    *Viewer*, *Commenter* (commente et valide) ou *Editor* (modifie ce qu'on lui ouvre) et suit tout par email.
    Voir « 5. Partage avec des invités sans compte ».
13. **Administration** — pour le propriétaire du site, avec le mot de passe de `/admin` : qui est en ligne et ce
    qui se passe, en direct ; courbes sur 30 jours ; recherche et fiche de chaque compte (envoyer un lien de mot
    de passe, confirmer l'adresse, déconnecter partout, **suspendre**, supprimer, écrire à la personne), export
    CSV ; modération des contenus publics ; **campagnes d'emails** avec désinscription en un clic, journal de tous
    les emails envoyés, test de l'envoi. Voir « 7. Administration ».

### Confidentialité

Le contenu d'un Studio est privé : seuls ses membres le voient, pièces jointes comprises (elles sont servies par
l'API, qui vérifie l'accès à chaque demande). **Rien n'arrive sur un profil public sans publication explicite.**
Une étude de cas tirée d'un Studio (menu du Studio › *Create a case study*) reste un brouillon privé ; sa
publication exige l'accord du propriétaire du projet si l'auteur ne l'est pas, **et d'un client si le projet en
compte un** (page *Publication requests*). L'activité dans les Studios (« pièces livrées ») n'apparaît sur un
profil que si la personne l'active dans ses réglages, et seulement sous forme de totaux, sans nom de projet. La
charge de travail par personne (*Insights*) n'est visible que des managers du Studio.

**Liens de partage.** Un Studio n'est visible hors de son équipe que si un manager crée un lien de partage, et
seulement ce que ce lien montre (tout le Studio ou certains niveaux). Un lien public montre ce contenu en lecture
seule ; un lien privé ne montre rien tant que la personne n'a pas prouvé, par un code reçu à son adresse, qu'elle
figure sur la liste. Un invité ne voit jamais les adresses email des membres, ni les autres Studios, ni les
réglages ; il ne modifie rien sans l'accord explicite d'un manager.

**Invitations envoyées par email et adresse confirmée.** L'adresse d'un compte créé avec un mot de passe n'est
pas vérifiée à l'inscription : quelqu'un pourrait donc créer un compte avec l'adresse d'une autre personne avant
qu'elle ne rejoigne Studioma. Pour qu'il ne voie pas les invitations qui lui sont destinées (projet, auteur, rôle,
lien) et ne puisse pas les accepter, **une invitation n'est rattachée à un compte par son adresse que si cette
adresse est confirmée** — par Google, par le lien de « Forgot your password? » ou par le lien de confirmation :

- tant que l'adresse n'est pas confirmée, l'invitation n'apparaît ni dans l'espace de travail du compte, ni dans
  ses pastilles, ni dans ses notifications ; le compte ne peut ni l'accepter ni la refuser par son adresse. Une
  invitation envoyée à cette adresse n'est pas non plus reliée au compte au moment de l'envoi ;
- **le lien de l'email d'invitation fonctionne toujours** : le recevoir prouve l'accès à la boîte. La personne
  invitée l'ouvre et accepte, avec ou sans adresse confirmée, comme avant ;
- dès que l'adresse est confirmée, les invitations qui lui ont été envoyées apparaissent dans l'espace de travail.

Confirmer son adresse : le lien de l'email de bienvenue, ou **Settings › Email › Send me a confirmation link**
(un avertissement y explique pourquoi). Le lien est valable 2 jours et ne sert qu'à cela (ni connexion, ni
changement de mot de passe) ; **il faut l'ouvrir en étant connecté au compte qui l'a demandé** — sinon la
personne dont l'adresse a été prise, ou un logiciel qui ouvre les liens de ses emails, confirmerait sans le savoir
le compte de quelqu'un d'autre. Changer d'adresse la rend « non confirmée », envoie un lien à la nouvelle et
annule ceux envoyés à l'ancienne (mot de passe oublié compris). Se connecter avec Google ne confirme que l'adresse
du compte Google.

### Organisation

- `studioma/index.html` + `assets/landing.{css,js}` — la landing (statique).
- `studioma/app/index.html` — l'application (une seule page, routes après le `#`).
- `studioma/assets/` — `core.js` (appels à l'API, gabarits HTML échappés, routeur, fenêtres, menus,
  glisser-déposer souris et tactile, texte mis en forme), `app.js` (cadre, connexion, premiers pas,
  notifications, réglages), `studio.js` (projets, Studios, partage), `board.js` (tableau, calendrier, My work,
  Insights), `card.js` (panneau d'une carte), `social.js` (fil, profils, portfolio, réseau), `talent.js`
  (entreprises, opportunités, talents, messages), `guest.js` (un Studio ouvert par un lien de partage : porte du
  lien privé, vérification de l'adresse, demande de droits), `ai.js` (écran d'accord des assistants IA, Settings › AI
  assistants), `icons.js` (icônes Lucide utilisées), `app.css` (système de design, thèmes clair et sombre).
- `studioma/s.php` — l'adresse d'un lien de partage (`/studioma/s/<clé>`), renvoyée vers l'application.
- `studioma/mcp.php` — le serveur MCP ; `studioma/oauth.php` — son OAuth (adresses `oauth/…` et `.well-known/…`
  réécrites par `studioma/.htaccess`) ; `.well-known/.htaccess` (à la racine du dépôt) — les documents de
  découverte que les assistants cherchent à la racine du domaine.
- `studioma/api.php` — l'API : la table `ROUTES` liste toutes les actions (méthode, compte obligatoire ou non).
- `studioma/assets/meta.js` — le pixel Meta (sans script en ligne) ; `studioma/lib/meta.php` — l'API Conversions
  et la liste des événements.
- `studioma/lib/` — `core.php` (configuration, base, listes de référence, emails), `auth.php` (comptes,
  sessions, notifications), `google.php` (connexion avec Google), `access.php` (**rôles et droits**), `files.php` (fichiers envoyés), `studio.php`
  (projets, Studios, niveaux, colonnes, cartes, passage de relais), `cards.php` (check-lists, commentaires,
  pièces jointes, validations), `collab.php` (invitations, membres), `social.php` (profils, fil, relations,
  portfolio, recommandations, recherche), `talent.php` (entreprises, opportunités, talents, messages),
  `oauth.php` (OAuth et jetons des assistants IA), `mcp.php` (outils du serveur MCP), `share.php` (**partage avec
  des invités sans compte** : liens, vérification de l'adresse, sessions d'invité, droits, journal, emails),
  `admin.php` (**administration** : statistiques, comptes, modération, campagnes et leur file d'envoi, système).
- `studioma/admin/index.html` + `assets/admin.{js,css}` — la page d'administration (même système de design que
  l'application, aucun pixel) ; `studioma/unsubscribe.php` — la page de désinscription des campagnes (lien signé,
  sans connexion) ; `studioma/cron.php` — la tâche planifiée facultative (ligne de commande seulement).
- Les données sont créées toutes seules au premier passage dans `~/studioma-data/` (hors `public_html`) :
  `studioma.sqlite` et `files/` (images ré-encodées en WebP, vidéos, documents).

### 1. Mise en route

Il suffit que le dossier soit copié dans `public_html` (les commandes d'installation et le cron ci-dessus le font).
**Si le cron est déjà en place sur le serveur, il faut lui ajouter `cp -rf studioma ~/public_html/`** (cPanel →
Tâches Cron → modifier la commande, voir « 4. Mise à jour automatique du code »). Puis ouvrir
`https://dehini.com/studioma/` et créer le premier compte : il n'y a ni démonstration ni donnée fictive, la
plateforme démarre vide.

### 2. Emails

Invitations, mot de passe oublié, bienvenue (avec le lien de confirmation de l'adresse), confirmation d'une
nouvelle adresse et notifications importantes (carte confiée, mention, demande de validation, autorisation de
publication) partent du compte email du défi Impact s'il est configuré
(`impact/config.php`), sinon de la fonction `mail()` du serveur. Pour un autre compte, voir
`studioma/config.example.php`. Chacun peut couper les emails de notification dans ses réglages, et les
annonces de l'équipe (campagnes) dans **Settings › Studioma news and announcements** ou par le lien de
désinscription de chaque campagne. **Chaque email envoyé est inscrit au journal** de l'administration (destinataire,
objet, réussite ou erreur ; les codes de vérification y sont masqués ; gardé 180 jours).

### 3. Connexion avec Google

**Rien à faire si le bouton Google fonctionne déjà sur le défi Impact** : Studioma reprend le même ID client
(réglage de l'administration du défi, sinon `IMPACT_GOOGLE_CLIENT_ID`), puisque c'est la même origine
`https://dehini.com`, déjà autorisée dans Google Cloud. Sinon : créer un ID client OAuth « Application Web » dans
[Google Cloud Console → Identifiants](https://console.cloud.google.com/apis/credentials) avec l'origine
JavaScript autorisée `https://dehini.com` (aucune URI de redirection n'est nécessaire), puis le mettre dans
`studioma/config.php` : `define('STM_GOOGLE_CLIENT_ID', '….apps.googleusercontent.com');`. Sans ID client, le
bouton n'apparaît pas.

- Le bouton « Continue with Google » est sur les pages de connexion et d'inscription : une nouvelle identité
  Google crée un compte (particulier, ou entreprise depuis l'inscription « Company », nom de l'entreprise
  demandé), avec la photo du compte Google reprise comme photo de profil.
- **Conditions d'utilisation** : par email, elles sont acceptées en créant le compte (phrase sous « Create my
  account », sans case à cocher). Avec Google, une fenêtre **« One last step »** les demande une fois, en arrivant
  dans le compte : « I agree and continue » ou « Not now — sign out » (elle ne se ferme pas autrement). Tant
  qu'elles ne sont pas acceptées, le serveur refuse toute écriture (erreur `terms`) sauf accepter, se déconnecter
  ou supprimer son compte ; la date d'acceptation est gardée (`users.terms_at`, visible dans l'administration).
  Les comptes existants créés par email sont considérés comme les ayant acceptées (le formulaire les exigeait) ;
  ceux reliés à Google les acceptent une fois à leur prochaine visite.
- Le jeton d'identité est vérifié par le serveur : signature (clés publiques de Google), émetteur, destinataire
  (notre ID client), expiration et adresse confirmée par Google. Aucun secret n'est stocké.
- Une identité Google dont l'adresse correspond à un compte existant y est reliée. Comme les adresses des
  comptes créés par email ne sont pas vérifiées, **si ce compte avait un mot de passe posé sans que l'adresse ait
  jamais été confirmée, ce mot de passe est retiré et ses sessions sont fermées** : quelqu'un qui aurait créé le
  compte avec l'adresse d'une autre personne en perd l'accès, et son vrai propriétaire peut choisir un nouveau
  mot de passe. Une adresse est confirmée par Google, par le lien de « Forgot your password? » ou par le lien de
  confirmation (voir « Confidentialité » : les invitations envoyées par email n'apparaissent qu'aux adresses
  confirmées).
- **Settings › Sign-in methods** : relier ou retirer Google (retrait possible seulement avec un mot de passe),
  choisir un mot de passe pour un compte créé avec Google. Un compte sans mot de passe confirme sa suppression en
  retapant son adresse ; il doit choisir un mot de passe avant de changer d'adresse.

### 4. Assistants IA (serveur MCP)

Adresse du serveur : **`https://dehini.com/studioma/mcp`** (aussi affichée dans Settings › AI assistants, avec les
guides de connexion). Transport MCP « Streamable HTTP » sans état (un POST JSON par message, ni flux ni
session), qui fonctionne sur l'hébergement mutualisé.

**Mise en route** : le cron doit aussi copier le dossier `.well-known` (ligne de « 4. Mise à jour automatique du
code », en haut de ce fichier) — les assistants y cherchent les documents de découverte OAuth, à la racine du
domaine (`https://dehini.com/.well-known/oauth-authorization-server/studioma`). Seul `.well-known/.htaccess` est
copié : il ne réécrit que ces adresses et ne touche pas `acme-challenge` (certificat SSL de cPanel).

- **Connexion** : l'assistant découvre le serveur d'autorisation (RFC 9728, RFC 8414), s'enregistre lui-même
  (RFC 7591), envoie la personne sur l'écran **« Allow access »** de Studioma (connexion d'abord si besoin, Google
  compris), puis échange le code contre un jeton (PKCE S256 obligatoire). Jeton d'accès valable 1 heure, jeton de
  renouvellement 60 jours, à usage unique. Rien à créer chez Google, OpenAI ou Anthropic.
- **Jetons personnels** (Settings › AI assistants) pour les outils qui demandent un en-tête
  `Authorization: Bearer …` (Cursor, VS Code, scripts) ; montrés une seule fois, valables jusqu'à leur suppression.
- **Droits** : l'assistant agit au nom de la personne, avec son rôle dans chaque Studio et dans chaque entreprise —
  les mêmes vérifications que l'application (un lecteur ne peut rien modifier, un collaborateur seulement ses
  cartes, seuls les admins d'une entreprise publient en son nom…). Les actions apparaissent dans l'historique et
  les publications sous le nom de la personne. Aucun outil de suppression définitive.
- **Outils** (44) :
  - Studios : `search` et `fetch` (format attendu par ChatGPT), `list_projects`, `get_project`, `get_studio`,
    `get_card`, `my_work`, `studio_activity`, `studio_insights`, `create_project`, `update_project`,
    `archive_project`, `create_studio`, `update_studio`, `archive_studio`, `create_card`, `update_card`,
    `move_card`, `add_comment`, `add_checklist_items`, `check_item`, `request_approval`, `archive_card`,
    `create_level`, `create_column`, `set_handoff`, `invite_member` ;
  - études de cas : `list_case_studies`, `save_case_study` (créer, modifier, publier), `case_study_from_studio`
    (brouillon tiré de cartes publiées, avec leurs liens et chiffres ; la publication attend les mêmes
    autorisations que dans l'application), `request_results_confirmation` ;
  - publications : `list_posts` (fil, les siennes, une entreprise, découvrir, enregistrées), `create_post` (en son
    nom ou celui d'une entreprise, partage possible), `comment_on_post` ;
  - messages : `search_people`, `list_conversations`, `read_conversation`, `send_message` ;
  - entreprises : `get_company` (sans argument : ses entreprises et son rôle), `save_company` (créer, modifier),
    `manage_company_team` (ajouter, changer l'accès ou l'intitulé, retirer), `save_opportunity`, `get_opportunity`
    (avec les candidatures pour les admins), `update_application`.

  Colonnes et personnes se donnent par id ou par nom (« Ideas », « @amel ») ; les descriptions s'écrivent en
  Markdown. Pour une modification, seuls les champs donnés changent. Une valeur hors liste (rôle, priorité,
  taille…) est refusée avec les choix possibles. Publications et études de cas : texte seulement — photos et vidéos
  s'ajoutent dans l'application. Les instructions du serveur demandent à l'assistant de faire valider le texte
  exact avant de publier, commenter ou envoyer un message, et de traiter le contenu écrit par d'autres (cartes,
  publications, messages) comme des données, jamais comme des consignes.
- **Déconnecter** : Settings › AI assistants › Disconnect (application) ou Delete (jeton) : effet immédiat.

### 5. Partage avec des invités sans compte

Rien à configurer : la base passe d'elle-même à la version 5 au premier passage après la mise à jour. Dans un
Studio : **Share › Guests without an account**.

Un lien ressemble à `https://dehini.com/studioma/s/aJq3XvR8kP2LwN7tZ0mHc5YbQe1dWPEc` : toujours en **https** (même
si la page a été ouverte en http), suivi d'une clé de 32 caractères tirée au hasard (192 bits : impossible à
deviner). Dans la fenêtre de partage, la clé est masquée (*Show* l'affiche, *Copy link* copie le lien entier).
`studioma/s.php` (adresse réécrite par `studioma/.htaccess`) renvoie vers l'application sans jamais transmettre la
clé à un autre site (`Referrer-Policy: no-referrer`), sans cache ni indexation par les moteurs de recherche.

- **Lien public** — n'importe qui l'ouvre, sans compte ni email, en **lecture seule** : niveaux, colonnes, cartes,
  check-lists, pièces jointes et commentaires de ce que le lien montre. Pour aller plus loin, le visiteur clique
  *Request editing permission*, choisit *Comment & approve* ou *Edit*, donne son adresse et la vérifie.
- **Lien privé** — le responsable ajoute des adresses (et peut leur envoyer le lien par email). À l'ouverture,
  Studioma demande l'adresse et envoie un code : seules les adresses de la liste, vérifiées, entrent (la réponse
  est la même pour toutes les adresses : la liste ne se devine pas). Accès en lecture seule au départ.
- **Vérification de l'adresse** — un code à 6 chiffres **et** un lien magique dans le même email, valables 15
  minutes, à usage unique ; au 5e code faux, le code ne vaut plus. Ensuite, une **session d'invité** de 14 jours,
  limitée à ce lien (cookie `stm_g<n°>`, `HttpOnly`, `SameSite=Lax`) : ni compte, ni mot de passe.
- **Droits** — *Viewer* (consulte), *Commenter* (consulte, commente, et valide ou demande des modifications quand
  on lui demande une validation : le responsable peut choisir un invité comme validateur d'une carte),
  *Editor* (modifie les cartes, en ajoute, les déplace, coche la check-list). Le périmètre d'un *Editor* se limite
  au besoin à des niveaux, des colonnes ou des cartes précises. Un invité ne gère jamais le Studio : ni
  structure, ni membres, ni archivage, ni suppression, ni responsable d'une carte, ni pièce jointe.
- **Gestion** — invités, demandes en attente (accepter avec un droit au choix, refuser), droit et périmètre de
  chacun, retrait (immédiat : sa session est fermée), remise, suppression de la liste ; lien activé ou coupé,
  **régénéré** (l'ancien ne marche plus ; les invités vérifiés gardent leurs droits avec le nouveau), date
  d'expiration, niveaux montrés ; **journal** des accès et de toutes les modifications des invités.
- **Emails** — au responsable (celui qui a créé le lien, ou les propriétaires du projet s'il ne gère plus le
  Studio) : chaque demande, avec l'adresse vérifiée, le Studio, le message, et les liens *Review*, *Approve*,
  *Reject* (ils ouvrent Studioma, qui demande confirmation) ; les **modifications des invités** — qui, quoi, quand,
  lien direct vers chaque carte — la première tout de suite, les suivantes regroupées au plus toutes les 10
  minutes. À l'invité : son code, la réponse à sa demande, les validations qu'on lui demande.
- **Sécurité** — clés de 192 bits, liens toujours en https ; aucun accès privé sur simple saisie d'une adresse ; lecture seule par
  défaut ; tout est vérifié par le serveur : une requête faite avec un lien n'atteint qu'une liste fermée
  d'actions (`GUEST_ROUTES` dans `api.php`), sur le seul Studio du lien, ses niveaux partagés et les droits de
  l'invité ; limites de débit (codes, vérifications, écritures) ; actions des invités notées dans l'historique de
  chaque carte sous leur adresse.

Pour empêcher quelqu'un de **consulter** un lien public, il faut le régénérer (ou le couper) : retirer un invité
lui enlève ses droits, mais le lien public reste lisible par quiconque l'a.

Conseillé : dans **cPanel › Domaines**, activer **Force HTTPS Redirect** pour dehini.com. Les liens de partage
sont déjà en https, mais le reste du site répond encore aussi en http si on tape l'adresse sans `https://`.

### 6. Meta Pixel et API Conversions

Ensemble de données **1718566156353795** (réglé dans le code, `STM_META_PIXEL_ID`). Le **jeton** de l'API
Conversions n'est jamais dans le dépôt : il se met dans `~/studioma-data/meta-token.txt` (hors du site, lisible par
PHP seulement) ou dans `studioma/config.php` (`STM_META_TOKEN`). Sans jeton, seul le pixel fonctionne.

```bash
printf '%s' 'LE_JETON' > ~/studioma-data/meta-token.txt && chmod 600 ~/studioma-data/meta-token.txt
```

Chaque action est décidée par le serveur (`lib/meta.php`, `meta_after()`) : l'événement part par l'API Conversions
après la réponse, et le pixel du navigateur envoie le même avec le **même event_id** (Meta n'en compte qu'un).
PageView part du pixel à chaque écran (et sur la page d'accueil).

| Événement | Quand |
|---|---|
| `PageView` | chaque écran de l'application et la page d'accueil (pixel) |
| `CompleteRegistration` | inscription (email ou Google ; `content_name` individual / company) |
| `Login` | connexion (email ou Google) |
| `ViewContent` | profil d'une autre personne, page entreprise, opportunité, publication |
| `Search` | recherche, annuaire des talents ou opportunités filtrés (seulement les valeurs des listes) |
| `Contact` | message privé envoyé |
| `SubmitApplication` | candidature à une opportunité |
| `AddToWishlist` | professionnel enregistré par une entreprise |
| `Lead` | un invité d'un lien de partage demande à commenter ou à modifier (serveur seulement) |
| `StartSignup` | clic vers l'inscription sur la page d'accueil (pixel) |
| `CreateProject`, `CreateStudio`, `CreateCard`, `CardHandoff`, `ContentPublished` | travail dans les Studios |
| `AddComment`, `AddAttachment`, `RequestApproval`, `ApproveContent`, `RequestChanges` | collaboration sur les cartes |
| `InviteMember`, `JoinTeam`, `ShareStudio`, `AddGuest`, `ApproveGuest` | équipe et partage sans compte |
| `GuestVerified`, `GuestCollaboration` | invités sans compte (serveur seulement, sans leur email) |
| `CreatePost`, `LikePost`, `SavePost`, `Follow`, `ConnectRequest`, `Recommend`, `UpdateProfile` | réseau |
| `CreateCaseStudy`, `PublishCaseStudy`, `AuthorizeCaseStudy`, `ConfirmResults` | portfolio |
| `CreateCompany`, `AddTeamMember`, `PublishOpportunity`, `AnswerApplication` | entreprises |
| `ConfirmEmail`, `ConnectAI` | adresse confirmée, assistant IA connecté |

**Ce qui part chez Meta** : nom de l'événement, catégories (type de compte, rôle, type de contenu, modèle de Studio,
secteur…), identifiants publics (`profile-12`, `opportunity-4`), et pour une personne connectée son email, son id,
son prénom et son nom **hachés en SHA-256**, avec l'IP, le navigateur et les cookies `_fbp` / `_fbc`. **Jamais** :
contenu des Studios (titres, descriptions, commentaires), noms de projets, de Studios ou d'entreprises, messages,
texte tapé dans une recherche, clés de liens de partage ou d'invitation (le pixel se tait sur ces écrans, et l'adresse
envoyée s'arrête avant `#`), email des invités sans compte. Les événements automatiques du pixel (clics,
microdonnées) sont coupés. Comme pour SWANBIDA, pas de bandeau de consentement aux cookies.

Tester : Gestionnaire d'événements → *Événements de test* → copier le code, l'ajouter dans `config.php`
(`define('STM_META_TEST_CODE', 'TEST…');`), faire une action, puis **retirer la ligne**. En cas d'échec, la
dernière erreur de l'API est gardée dans la table `meta` de la base (`meta_capi_error`).

### 7. Administration

`https://dehini.com/studioma/admin/` (aussi dans la liste de liens de `/admin`). **On se connecte avec le mot de
passe de l'administration du site** (`/admin`) : c'est la même session (12 heures), aucun compte Studioma n'a de
droit d'administration. Toute modification exige en plus le jeton de sécurité de cette session ; tout est vérifié
par le serveur (`lib/admin.php`) et chaque action est inscrite au journal de l'administration (onglet *System*).

- **Live overview** — rafraîchie toutes les 10 secondes : comptes en ligne (actifs dans les 5 dernières minutes,
  invités et assistants IA à part), actifs du jour / de la semaine / du mois, inscriptions, cartes, publications,
  emails du jour ; fil de l'activité de toute la plateforme ; six courbes sur 30 jours (comptes actifs, nouveaux
  comptes, cartes, publications, messages, emails), avec la valeur au survol et le tableau des valeurs ; totaux
  (projets, Studios, cartes livrées, offres, études de cas, fichiers et stockage, liens de partage, invités,
  connexions IA) ; état des emails.
- **Visiteurs en temps réel** — tuile *Visitors now* et panneau *Who's here now* : chaque personne présente dans
  les 5 dernières minutes, connectée (nom) ou non (« Visitor »), avec l'écran où elle se trouve (page d'accueil, un
  Studio, un profil, inscription…), son appareil et depuis quand ; nombre par écran ; visiteurs uniques du jour,
  de la semaine et courbe sur 30 jours. La page d'accueil et l'application signalent leur présence toutes les
  minutes tant que l'onglet est visible. Sans compte, un visiteur est une empreinte anonyme du jour (IP et
  navigateur hachés avec un sel, **sans cookie**, change chaque jour) ; seul le type d'écran est gardé, jamais
  l'adresse ni une clé ; les robots sont ignorés.
- **Studios** — tous les Studios créés : nom, projet, propriétaire, nombre de cartes (et livrées), équipe, liens
  de partage et invités, dates ; recherche (Studio, projet, propriétaire), filtres actifs / archivés, tri. La fiche
  d'un Studio : équipe et rôles, niveaux et leur nombre de cartes, activité de la semaine, partage. Le contenu des
  cartes (titres, scripts, commentaires) n'y apparaît jamais.
- **Accounts** — recherche (nom, email, identifiant), filtres (en ligne, nouveaux, actifs, inactifs, adresse non
  confirmée, particuliers, entreprises, Google, suspendus), tri, **export CSV** (une cellule qui commence par `=`
  est neutralisée pour les tableurs). La fiche d'un compte : jours d'activité sur 30 jours, sessions ouvertes,
  nombres d'usage, entreprises, assistants IA connectés, emails reçus, historique de l'administration ; actions :
  écrire à la personne, envoyer un lien de mot de passe, renvoyer le lien de confirmation ou confirmer l'adresse,
  déconnecter partout, déconnecter les assistants IA, couper ses emails d'annonces, **suspendre** (avec un motif)
  ou lever la suspension, **supprimer** (en retapant son adresse ; refusé tant qu'elle est seule propriétaire d'un
  projet ou d'une entreprise partagés, comme la suppression faite par la personne elle-même).
- **Un compte suspendu** ne peut plus se connecter (mot de passe, Google, lien de mot de passe oublié, assistants
  IA) et ses sessions sont fermées ; son profil, ses publications, sa présence dans la recherche, les suggestions
  et l'annuaire des talents disparaissent. Rien n'est supprimé : lever la suspension rétablit tout.
- **Moderation** — contenus publics seulement : publications, commentaires, offres (fermer ou supprimer), pages
  d'entreprise (supprimer ; les comptes membres restent), études de cas publiées (repasser en brouillon).
- **Emails** — *Campaigns* : écrire une annonce (objet, titre, texte, bouton facultatif ; `{name}` devient le
  prénom), aperçu en direct, choisir le public (tout le monde, adresses confirmées ou non, particuliers,
  entreprises, actifs ou inactifs depuis 30 jours, nouveaux de la semaine, sans projet), s'envoyer un essai, puis
  envoyer. Les comptes suspendus et ceux qui ont coupé les annonces ne reçoivent jamais rien ; chaque email porte
  un lien de désinscription signé et l'en-tête de **désinscription en un clic** des messageries (Gmail, Outlook).
  Les emails partent **par petits lots**, sans jamais dépasser `STM_MAIL_PER_HOUR` emails par heure, tous emails
  confondus (200 par défaut — les hébergeurs mutualisés limitent souvent l'envoi : régler cette valeur dans
  `studioma/config.php` un peu sous leur limite) ; pause, reprise, annulation, duplication. *Email log* : tous les
  emails envoyés, avec leurs erreurs. *Sending settings* : d'où partent les emails, test d'envoi immédiat (l'erreur
  SMTP s'affiche), état de la tâche planifiée.
- **System** — versions de PHP et SQLite, schéma de la base, taille de la base et des fichiers, espace libre,
  Google, pixel et jeton Meta (dernière réussite ou erreur de l'API Conversions), tâche planifiée, journal de
  l'administration.

**Tâche planifiée (conseillée pour les campagnes)** : cPanel → Tâches Cron → « Une fois toutes les cinq
minutes », commande

```bash
/usr/local/bin/php ~/public_html/studioma/cron.php >/dev/null 2>&1
```

Elle envoie les emails de campagne en attente (dans la limite horaire), les emails groupés des modifications
d'invités et efface les sessions expirées. Sans elle, une campagne part quand même pendant que l'administration
est ouverte (et après les requêtes des membres si le serveur sait terminer une réponse avant la fin du script),
mais plus lentement. `cron.php` ne répond pas depuis le web (404). Le chemin de PHP peut différer selon
l'hébergeur *(chemin habituel de cPanel, à vérifier)*.

**Confidentialité** : l'administration voit les comptes (nom, email, dates), les contenus publics et la liste des
Studios (nom, projet, équipe, nombres) ; du contenu des Studios et des messages, elle ne voit que des nombres —
jamais un titre de carte, un script, un commentaire ou le texte d'un message. Aucun pixel ni suivi sur ses pages. Pas de suivi d'ouverture des emails.

### 8. Formules et abonnements (Free, Solo, Team)

Trois formules, définies à un seul endroit (`studioma/lib/plans.php`) et **appliquées par le serveur** à chaque
action (application, serveur MCP, liens de partage) : **Free** (0 $ : 1 projet, 1 Studio, 30 nouvelles cartes par
mois, 1 membre, 2 liens invités en lecture seule), **Solo** (12 $ / mois ou 120 $ / an : 3 projets, 5 Studios,
cartes illimitées, liens privés, invités qui commentent / valident / modifient, validations des clients) et
**Team** (29 $ / mois ou 290 $ / an : 10 projets, 20 Studios, 5 membres, invitations et rôles, Insights). Le compte
propriétaire d'un projet paie pour tout ce qui s'y passe ; les invités ne sont jamais des membres payants.

- Page des prix `…/app/#/pricing` (lien « Pricing » sur la page d'accueil) ; **Settings › Plan & billing** : formule,
  état, échéance, usage réel, dépassements, changer de formule ou de rythme, résilier, « Manage in PayPal »
  (paiements automatiques du compte PayPal), paiements. Une limite atteinte ouvre une invitation à changer de formule.
- **Rien n'est jamais supprimé** par un changement de formule : au-delà des limites, tout reste lisible, la création
  est refusée, les membres en trop et les invités passent en lecture seule.
- Paiement : **PayPal** (abonnements ; remplace Paddle depuis le 2026-11-23, décision S41). Le serveur crée
  l'abonnement chez PayPal, le membre paie sur paypal.com (compte PayPal ou carte), revient par `studioma/pay/`, et
  la formule n'est activée que par l'état relu chez PayPal (API ou notification vérifiée par PayPal,
  `studioma/paypal-webhook.php`). Aucun script PayPal sur Studioma. **Studioma est le vendeur** (PayPal n'est pas un
  revendeur comme Paddle) : prix finaux en dollars, sans taxe ajoutée (choix du propriétaire). Pas de prorata chez
  PayPal : une montée vaut tout de suite (nouveau prix au renouvellement), une descente à la fin de la période payée ;
  une résiliation garde la formule jusqu'à la fin de la période payée. **Fermé tant que PayPal n'est pas configuré**
  (la page des prix propose alors l'essai gratuit de l'offre de lancement, § 10 — ou « Coming soon » si l'offre est
  coupée). Réglages (identifiants de l'application PayPal, webhook, formules créées depuis l'administration), essai en
  sandbox, passage en live et limites : **`docs/billing.md`**.
- Administration › **Billing** : abonnés, MRR, ARR, conversions, résiliations, notifications PayPal, adresse du
  webhook, bouton « Create the PayPal plans » (produit et quatre formules chez PayPal) ; dans la fiche
  d'un compte, **offrir une formule** (sans paiement, hors chiffre d'affaires).
- `STM_PLANS_ENFORCED` (true par défaut) applique les limites. Les comptes existants passent en Free : avant de
  mettre en ligne, offrir Solo ou Team aux comptes qui doivent continuer à collaborer (voir `docs/billing.md` § 6).

### 9. Partager une carte et la publier dans la communauté (Share & publish)

Rien à configurer : la base passe d'elle-même à la version 10 au premier passage. Dans le panneau d'une carte,
bouton **Share & publish** (ou menu de la carte › *Share & publish…*). Ce qu'il propose dépend de l'étape de la carte :

| Carte | Choix |
|---|---|
| toute carte | **Share card** (responsables du Studio) |
| en cours (colonne ni « done » ni « cancelled ») | + **Publish opportunity** (rôle Creator ou plus) |
| terminée (colonne « done ») | + **Publish achievement** (rôle Creator ou plus) |
| annulée | partage seulement |

- **Partager une seule carte** — la même fenêtre et les mêmes règles que le partage d'un Studio (§ 5) : lien public
  ou privé, adresses vérifiées par code, demande de droits et réponse, retrait, coupure, expiration, nouveau lien,
  journal, emails. Mais l'invité ne voit **que cette carte** : son titre, sa description, sa check-list et son étape,
  plus ce que le responsable coche dans *What guests see* — certaines pièces jointes (les autres restent
  introuvables, même avec l'adresse du fichier) et, s'il le veut, les commentaires. Jamais l'historique, les autres
  cartes, l'équipe ni le reste du Studio. Un *Editor* ne modifie que cette carte (ni création, ni déplacement). Les
  liens d'une carte sont à part de ceux du Studio (`shares.card_id`). Carte archivée : lien inactif (il revient avec
  la carte) ; carte supprimée : ses liens et leurs invités sont supprimés.
- **Publier une opportunité** (carte en cours) — *Find a professional*, *Request proposals*, *Request
  collaboration* ou *Request feedback or help* : titre, description, catégorie, compétences, livrables, budget
  (fourchette et devise, facultatif), échéance, date limite des propositions, à distance ou sur place, pièces
  jointes cochées. **Aperçu**, puis confirmation. Les professionnels connectés envoient une **proposition**
  (approche, prix, délai, études de cas et réalisations de leur profil) et la suivent (*Community › My proposals*).
  L'auteur (ou un responsable du Studio) présélectionne, accepte, refuse, écrit à la personne (messages), ferme et
  rouvre. **Accepter ne donne aucun accès à la carte** : *Invite to collaborate* propose ensuite, au choix, un lien
  privé vers cette seule carte (Editor ou Commenter) ou l'invitation habituelle au Studio.
- **Publier une réalisation** (carte terminée) — titre, description, couverture, visuels cochés, catégorie, outils
  et compétences, résultat, et au choix l'équipe de la carte. Aperçu, confirmation ; la réalisation va dans la
  Communauté, sur le profil et dans le fil (une publication liée porte les J'aime, commentaires, partages,
  enregistrements). Retirer (*Unpublish*) la masque partout ; la republier rend J'aime et commentaires.
- **Ce qui est public** : seulement ce qui est saisi ou coché dans le formulaire. Les fichiers choisis sont **copiés**
  en fichiers publics ; ceux de la carte restent privés. La carte n'est jamais déplacée, dupliquée ni modifiée, et
  modifier la publication ne touche pas la carte. Seuls les membres du Studio voient le lien vers la carte d'origine.
- **Cycle de vie** — une réalisation et une opportunité au plus par carte (republier réutilise la même). Une
  opportunité **se ferme d'elle-même** quand la carte est terminée, annulée, archivée ou supprimée, ou à sa date
  limite ; fermée, elle n'accepte plus de proposition (rouvrir exige une carte de nouveau en cours). Une réalisation
  reste publiée si la carte disparaît (c'est une copie) ; son auteur ou un responsable du Studio peut la retirer.
- **Pastilles** sur le tableau et dans la carte : *Achievement*, *Open / Closed opportunity*, *Shared*.
- **Communauté** (`…/app/#/community`, menu *Community*) : *Achievements* (recherche, catégories), *Opportunities*
  (recherche, type, collaboration, budget, ouvertes / fermées, avec les offres des entreprises de la page
  *Opportunities*), *My publications*, *My proposals*. Pages publiques `#/pub/<n°>`, visibles sans compte.
- **Notifications** — dans l'application et par email : nouvelle proposition, proposition présélectionnée,
  acceptée ou refusée ; J'aime et commentaires d'une réalisation ; demandes d'accès des invités d'une carte (lien
  direct vers son partage) ; invitation à collaborer.
- **Administration › Moderation › Card publications** : fermer une opportunité, retirer ou supprimer une publication.
- Code : `studioma/lib/pubs.php` (règles), `studioma/lib/share.php` (liens d'une carte), `studioma/assets/publish.js`
  (menu, formulaires, Communauté) ; décisions S25 à S27 de `docs/DECISIONS.md`.

### 10. Offre de lancement : 30 jours gratuits, 1 an pour 10 amis (sans carte)

Rien à configurer : la base passe d'elle-même à la version 11. L'offre est ouverte par défaut (`STM_LAUNCH_PROMO`,
voir `config.example.php`) ; **aucune carte, aucun paiement, aucun appel à PayPal**, jamais de renouvellement.

- **30 jours d'accès complet** — toutes les fonctionnalités de la formule Team, avec ses limites (10 projets, 20
  Studios, 5 membres). Accordés à l'inscription (email ou Google) ; un compte existant qui ne les a jamais eus les
  réclame une fois (bannière de l'accueil ou page des prix). Jamais par-dessus un accès Team payé ou offert, jamais
  deux fois (choisir l'autre carte de prix ne relance rien). À la fin : formule Free, rien n'est supprimé (au-delà des
  limites, tout reste lisible, la création est refusée, y compris par l'API).
- **Parrainage** — chaque compte a un lien permanent `https://dehini.com/studioma/?ref=<code>` (8 caractères tirés
  au hasard, ni id ni email) : menu **Refer & Earn** (copier, partager — WhatsApp, Facebook, LinkedIn, email, partage
  du téléphone —, message prêt à envoyer, clics, visiteurs uniques, inscriptions, parrainages vérifiés, progression,
  historique sans nom ni adresse des amis). Un clic (JavaScript de la page : les robots d'aperçu ne comptent pas) pose
  une attribution signée de **30 jours** ; la première attribution valable l'emporte. Un ami **compte quand il crée un
  nouveau compte par ce lien et confirme son adresse** (lien de confirmation, Google, mot de passe oublié, ou
  l'administration). Refusés : se parrainer soi-même (même adresse aux alias près), un compte déjà inscrit, deux
  crédits pour un même compte. « À examiner » (administration › Launch promotion) : 3 comptes ou plus du même parrain
  depuis la même adresse IP en 7 jours, 10 vérifications ou plus en une heure (`STM_REFERRAL_SAME_IP_MAX`,
  `STM_REFERRAL_BURST_MAX`).
- **10 amis vérifiés : 12 mois civils d'accès complet**, une seule fois, accordés automatiquement (notification et
  email de félicitations). Ils commencent à la fin de l'essai s'il est en cours, sinon tout de suite ; un accès payé
  ou offert n'est ni réduit ni remplacé. À la fin : formule Free, sans paiement.
- **Écrans** — page des prix : annonce de l'offre, « Get 30 Days Free » et « No credit card required » sur Solo et
  Team (même essai), prix indiqués comme prévus (abonnements pas encore ouverts), section « Invite 10 Friends. Get 1
  Year Free. » ; page d'accueil : « 30 Days Free. All Features Unlocked. », messages de confiance, section du
  parrainage ; bannière dans l'application (essai en cours, fin proche, essai terminé, année offerte) ;
  Réglages › Plan & billing. Administration › **Launch promotion** : essais (accordés, actifs, terminés), clics,
  visiteurs uniques, inscriptions, parrainages vérifiés, taux, parrains à 1 et à 10, récompenses (accordées, actives, à
  venir, terminées), activité suspecte à approuver ou refuser, filtre de période.
- **Données** — accès : lignes de `subscriptions` (provider « promo »), lues par la même fonction que les abonnements
  payés (`account_plan`) : toutes les limites restent appliquées au même endroit. `promo_grants` (un essai et une année
  par compte, contrainte d'unicité), `ref_codes`, `ref_clicks` (empreinte d'un identifiant aléatoire du navigateur,
  cookie `stm_vid` ; aucune adresse IP), `referrals` (empreinte salée de l'adresse IP à l'inscription, pour les seuls
  contrôles anti-abus). Cookies : `stm_vid` (1 an) et `stm_ref` (attribution signée, 30 jours), propres au site.
- Code : `studioma/lib/promo.php`, `studioma/assets/promo.js` ; décisions S28 à S30 de `docs/DECISIONS.md`.

### 11. Parcours du créateur : XP, réalisations vérifiées, niveaux, passeport public

Rien à configurer : la base passe d'elle-même à la version 12, et le parcours se calcule aussi à partir du travail
déjà fait (Studios, cartes terminées, validations, invitations, réalisations publiées). Ouvert à toutes les formules,
sans carte ni paiement ; ce qui est acquis reste quand l'abonnement change.

- **Menu « My Achievements »** (`#/achievements`) — passeport du créateur (photo, nom, titre, niveau, XP vers le niveau
  suivant, réalisations, projets terminés, réalisations vérifiées et validées par un client, résumé factuel), « Your
  Next Milestone », collection en onglets **Verified / In Progress / Pending Verification** (et « Self reported » s'il y
  en a), chronologie filtrable (*All Activity, Achievements, Projects, Collaboration, Client Recognition*), niveaux et
  leurs critères, barème des XP. Détails d'une réalisation : `#/achievements/<n°>` (preuves, ce qu'elle prouve et ne
  prouve pas, identifiant, visibilité). Réglages : `#/achievements/settings`.
- **XP (serveur seulement)** — chaque événement s'inscrit une fois dans le grand livre `xp_transactions` (clé
  d'idempotence par compte, version de règle, moment, projet / Studio / carte d'origine) : premier Studio 20, premier
  projet 15, carte éligible créée 2, carte éligible terminée 10, niveau de Studio dégagé 20, projet terminé 50, Studio
  partagé avec un collaborateur 10, réalisation publiée dans la communauté 20, validation d'un client 30. **Carte
  éligible** : une description d'au moins 20 caractères, une pièce jointe, une liste de contrôle ou un lien publié (les
  cartes vides ne rapportent rien). Limites par jour (10 cartes créées, 10 terminées, 3 niveaux, 2 projets, 3 partages,
  2 publications, 5 validations) : au-delà, l'événement compte pour les réalisations mais ne rapporte pas d'XP. Une
  carte terminée, rouverte puis terminée de nouveau, un projet rouvert puis terminé, une publication modifiée ou un
  deuxième membre dans le même projet ne rapportent rien de plus. Le navigateur ne peut rien déclarer lui-même.
- **Qui reçoit les points d'une carte** : son responsable et ses collaborateurs, sinon son créateur. **Niveau de Studio
  dégagé** : au moins 3 cartes éligibles y sont passées (terminées dedans ou envoyées vers un niveau suivant) et
  aucune carte ouverte n'y reste. **Projet terminé** : un propriétaire ou un responsable le marque (menu du projet ›
  *Mark as completed…*) ; le serveur vérifie au moins 3 cartes éligibles terminées, aucune carte ouverte et 24 heures
  d'existence ; chaque contributeur reçoit l'événement une fois. **Studio partagé** : un autre compte a rejoint le
  projet ou le Studio par une invitation de la personne, ou un invité a vérifié son adresse sur un de ses liens.
- **Validation d'un client** — compte seulement si la personne désignée décide elle-même, en tant que *Client /
  Approver* (adresse confirmée) ou invitée vérifiée d'un lien de partage ; jamais pour soi-même, jamais quand un
  propriétaire décide à la place du client, jamais celle d'un *Manager* (relecture interne). Une fois par carte.
  Les décisions gardent désormais qui a décidé et à quel titre (`card_approvals.decided_by`, `decider_role`) ; celles
  d'avant ont été retrouvées dans l'historique des cartes.
- **Six réalisations** — *First Studio*, *First Delivery*, *Project Finisher*, *Team Player* (carte terminée avec un
  autre compte vérifié) : **System Verified** ; *Client Approved* : **Client Verified** ; *Proven Creator* (3 projets
  terminés et 3 validations de client sur au moins 2 projets — jamais par les XP seuls) : **Evidence Based**. **Pending
  Verification** : une demande de validation adressée à un client attend sa réponse. **Self Reported** : réalisation
  ajoutée par la personne (titre, description, date, lien), sans XP, jamais présentée comme vérifiée.
- **Six niveaux** (indicateurs internes, pas des qualifications reconnues) : 01 Explorer ; 02 Organizer (150 XP, 5
  cartes) ; 03 Creator (500 XP, 15 cartes, 1 projet) ; 04 Collaborator (1 500 XP, 2 projets, Team Player, 1 Studio
  partagé) ; 05 Professional (3 000 XP, 3 projets, 3 validations de client) ; 06 Mentor (6 000 XP, 6 projets, Proven
  Creator, 25 cartes dans les projets d'autres personnes, actif 6 mois différents).
- **Célébrations** — une carte discrète dans un coin (jamais une fenêtre bloquante, sans son, mouvement réduit
  respecté) pour une réalisation ou un niveau ; plusieurs à la fois sont regroupées ; montrée une seule fois.
- **Partager** — chaque réalisation est **privée par défaut** ; « Anyone with the link » ou « Public on my Creator
  Passport ». Page publique de vérification `https://dehini.com/studioma/verify/<identifiant aléatoire>` (état actuel,
  créatrice, date, type de vérification, preuves sans nom de projet, de Studio, de carte, de fichier ni de client ;
  le nom d'un client seulement s'il l'accepte : *Settings › Approvals you gave as a client*). Une réalisation retirée
  affiche « Revoked », jamais « Verified ». **Image PNG** dessinée dans le navigateur (1200 × 630, 1080 × 1080,
  1080 × 1920), avec un QR code vers la page de vérification (encodeur intégré `assets/qr.js`, sans bibliothèque
  externe). Boutons LinkedIn, Facebook, WhatsApp, email, partage du téléphone, copie du lien : rien n'est publié sans
  geste de la personne.
- **Passeport public** `https://dehini.com/studioma/creator/<identifiant>` — fermé par défaut ; titre, niveau, chiffres
  et résumé, réalisations choisies, travaux publiés du portfolio, progression et chronologie publique, chacun
  masquable. Pages rendues par le serveur (`studioma/p.php`, aperçus des liens, sans JavaScript ; seul un passeport
  ouvert est indexable).
- **Administration › Creator Journey** — participants, comptes qui progressent, réalisations accordées, projets
  terminés, réalisations validées par un client, taux de partage, passeports et leurs vues, répartition par niveau et
  par XP ; activité à examiner (limites du jour souvent atteintes, gains très rapides, validations en série d'un même
  client — aucune sanction automatique) ; règles modifiables (XP, limites, critères, seuils, réalisations actives ;
  les XP déjà accordés gardent leur valeur) ; fiche d'un compte (grand livre, réalisations, preuves) avec **retrait et
  restitution motivés** (journal de l'administration, membre prévenu).
- Code : `studioma/lib/journey.php`, `studioma/p.php`, `studioma/assets/journey.js`, `studioma/assets/qr.js`,
  `studioma/assets/passport.css` ; décisions S31 à S35 de `docs/DECISIONS.md`.

### 12. Pages publiques, référencement et qualité sur téléphone

Rien à configurer : la base passe d'elle-même à la version 13 (les opportunités déjà ouvertes reçoivent leur
publication du fil). Audit complet, mesures et liste des problèmes corrigés : `docs/studioma-quality-audit.md`.

- **Opportunités dans le fil** — publier une opportunité (bouton *Share & publish* de la carte › *Publish
  opportunity*, aperçu, confirmation) crée sa publication du fil, comme une réalisation : elle apparaît dans
  l'accueil **« For you » de tous les membres** (pas seulement des abonnés de l'auteur) et dans *Discover*, avec son
  type, son budget, sa date limite, son mode de travail et « View and send a proposal ». La modifier met le fil à jour ;
  la fermer (ou terminer la carte, ou passer la date limite) la retire du fil ; la rouvrir la remet (même publication,
  aucun doublon) ; la supprimer la retire partout. Une opportunité masquée par la modération ne peut être ni rouverte
  ni republiée par son auteur. Partager une carte (lien privé) ne la publie jamais.
- **Pages publiques rendues par le serveur** (`studioma/p.php`, lisibles sans JavaScript et par les moteurs) :
  `/studioma/opportunities/` (opportunités ouvertes, 20 par page), `/studioma/opportunity/<n°>-<titre>` (une
  opportunité : description, livrables, budget, dates, auteur, « Send a proposal », partages ; adresse canonique, 301
  depuis une ancienne adresse, non indexée une fois fermée, 404 si masquée ou supprimée ; jamais de nom de carte, de
  Studio ou de projet), `/studioma/pricing/` (les formules de `lib/plans.php`, avec l'offre de lancement), et
  `/studioma/sitemap.xml` (accueil, prix, opportunités ouvertes, passeports ouverts et réalisations qu'ils montrent —
  jamais l'application, l'API ni un lien de partage). Dans l'application, une opportunité a *Copy public link* et
  *Share* (LinkedIn, Facebook, WhatsApp, email, partage du téléphone). Pas de données `JobPosting` : ce ne sont pas
  des offres d'emploi.
- **Accueil** — titre et description centrés sur la planification de contenu et la collaboration avec les clients,
  image des aperçus de liens (`assets/og-studioma.jpg`), données structurées Organization, WebSite et
  SoftwareApplication (formule gratuite, sans note ni avis), un seul `h1` en premier, liens vers les prix et les
  opportunités. `robots.txt` (à la racine du site) déclare le plan du site de Studioma et laisse lire les fichiers
  publics (`api.php?action=file`), l'application et le reste de l'API restant exclus.
- **Performance** — police Plus Jakarta Sans hébergée sur le site (`assets/fonts/`, licence OFL ; plus aucun appel à
  Google Fonts, donc aucune adresse IP de visiteur transmise) et préchargée ; tous les scripts des pages en `defer`
  (ordre conservé) : la page s'affiche avant le JavaScript.
- **Téléphone et accessibilité** — fenêtres plus hautes que l'écran défilables depuis leur haut (le haut du formulaire
  de publication était inaccessible sur téléphone), bouton *Create* de 40 px, en-têtes et barre d'onglets sans
  débordement à 320 px, tableau des prix défilant dans son cadre, page d'une publication : contenu avant les actions du
  gestionnaire ; contrastes ≥ 4,5:1 (gris, vert, avatars, boutons du thème sombre), boutons d'icône nommés, fenêtres
  nommées par leur titre, un `h1` par écran, liens soulignés dans les textes, cibles tactiles agrandies.
- **Pages légales** — `/studioma/terms/` (conditions d'utilisation), `/studioma/privacy/` (confidentialité, avec le
  tableau des cookies réellement posés) et `/studioma/refunds/` (remboursements), rendues par `p.php` sans base de
  données (lisibles même si SQLite est indisponible), indexées et dans le plan du site. Exploitant **Mohammed
  Dehini**, contact **contact@dehini.com**, droit **algérien**, âge minimum **16 ans**, garantie **14 jours** sur le
  premier paiement d'un abonnement (une fois par compte ; renouvellements non remboursés sauf obligation légale ;
  erreurs de facturation toujours remboursées) ; paiement par PayPal, Studioma vendeur, prix sans taxe ajoutée. Ces valeurs
  sont dans `lib/core.php` (`STM_LEGAL_NAME`, `STM_LEGAL_EMAIL`, `STM_LEGAL_COUNTRY`, `STM_LEGAL_UPDATED`,
  `STM_MIN_AGE`, `STM_REFUND_DAYS`) et se remplacent dans `config.php` ; **changer `STM_LEGAL_UPDATED`** à chaque
  modification du texte. Liens : pied de page des pages publiques et de l'accueil, formulaire d'inscription,
  fenêtre « One last step », FAQ et pied de la page des prix de l'application, page de paiement. Textes rédigés à
  partir du fonctionnement réel du code (cookies, Meta, Google, PayPal, durées de conservation) ; **ils ne
  remplacent pas l'avis d'un juriste**.

### 13. Fichiers envoyés

64 Mo par fichier (`studioma/.user.ini` et `STM_MAX_UPLOAD_MB`), 12 Mo par image. Si l'hébergeur plafonne plus
bas, baisser les deux valeurs ensemble. Le type est vérifié sur le contenu réel du fichier.

### 14. Sauvegarde

Tout tient dans `~/studioma-data/` : copier ce dossier sauvegarde la base et les fichiers.

### 15. Tests

```bash
tests/studioma/run.sh /tmp/stm-test
```

Faux hébergement neuf, puis seize batteries : `legal.js` (pages légales : contenu tiré de la configuration et
remplaçable, sommaire, liens croisés et depuis le reste du site, plan du site, lisibles sans base de données, aucun
script en ligne, 320 px, liens de l'inscription, de la fenêtre des conditions et de la FAQ des prix), `quality.js` (opportunités dans le fil de tout le monde, détails,
modification, fermeture et réouverture sans doublon, modération, suppression, droits, rien de privé ; pages publiques
des opportunités et des prix, plan du site, robots.txt, métadonnées et données structurées de l'accueil ; migration
v13), `quality-e2e.js` (sur téléphone : nouveau projet, cartes, changement de colonne sans glisser-déposer,
publication d'une opportunité, fil, lien public, partage, menu ; 14 écrans à 320 et 390 px sans débordement, un `h1`,
boutons nommés), `journey.js` (le parcours du créateur : XP inscrits une fois, cartes
vides sans points, limites du jour, niveau de Studio dégagé, projet terminé après vérification, partage, travail en
équipe, validations de client — désignée seulement, pas de soi-même, ni d'un propriétaire à sa place, ni d'un
Manager —, réalisations et preuves, en attente, déclarées, niveaux, jalons, chronologie, visibilité, pages publiques
sans rien de privé, consentement du client, règles, retrait et restitution, formule sans effet, migration rejouée,
requêtes simultanées), `journey-e2e.js` (les mêmes écrans dans le navigateur, image PNG et QR code, ordinateur et
mobile), `promo.js` (l'offre de lancement : essai unique aux limites Team,
réclamation, fin de l'essai sans perte, lien et clics, attribution signée de 30 jours, vérification, parrainage de soi
refusé, activité suspecte, récompense unique même sous vérifications simultanées, indicateurs, aucun paiement ni appel
à PayPal), `promo-e2e.js` (les mêmes écrans dans le navigateur, ordinateur et mobile), `publish.js` (partager une carte seule, la publier : invités limités à
la carte et aux pièces jointes choisies, demandes, retrait, opportunités, propositions, réponses, invitation après
acceptation, fermeture automatique, réalisations, fil, profil, rien de privé, cycle de vie), `publish-e2e.js` (les
mêmes écrans dans le navigateur, ordinateur et mobile), `billing.js` (formules et abonnements, limites appliquées, faux PayPal :
migration (schéma 14), limites Free / Solo / Team, compteur mensuel, invités en lecture seule, abonnement créé par le
serveur et activé seulement par PayPal, webhooks vérifiés par PayPal, rejeu, état relu chez PayPal, montée immédiate,
descente à la fin de la période, résiliation, double paiement refusé, paiement en échec, suspension, remboursement,
PayPal indisponible, places de membres, créations simultanées, isolement, indicateurs, création des formules PayPal,
page des prix, aller-retour par PayPal et page de retour dans le navigateur), `admin.js` (l'administration : accès réservé à la session `/admin`
et à son jeton, statistiques sans contenu privé, actions sur les comptes, suspension appliquée partout,
suppression, export CSV, modération, journal des emails, campagnes et désinscription, tâche planifiée), `meta.js` (pixel et API Conversions : chaque événement, même event_id,
données hachées, rien de privé), `share.js` (le partage sans compte : lecture seule, Studio et
niveaux du lien, liste fermée d'actions, codes et liens magiques, demandes et réponses, droits et périmètre,
validation par un invité, emails, retrait, liens coupés, expirés, régénérés), `mcp.js` (le serveur MCP et son OAuth, comme un assistant :
découverte, enregistrement, PKCE, jetons, tous les outils, droits, renouvellement, révocation), `api.js` (l'API seule : comptes, modèle par défaut, relais avec
conservation de l'identité de la carte, rôles et refus côté serveur, invitations par email réservées aux adresses
confirmées, pièces jointes privées, validations,
archivage, portfolio et autorisations, entreprises, talents, messages) et `e2e.js` (Chromium / Playwright : le
parcours complet dans le navigateur, sur ordinateur et sur mobile, avec deux comptes synchronisés). Voir
`tests/studioma/README.md`.

### Ce qui n'est pas fait

- **Aucune connexion aux réseaux sociaux** : pas de publication automatique sur Instagram, TikTok, etc., ni de
  récupération automatique des statistiques. La date, le lien de publication et les indicateurs d'une carte sont
  saisis à la main.
- Pas d'IA intégrée à l'application : l'assistance passe par les assistants externes connectés au serveur MCP.
- Serveur MCP : pas de flux d'événements ni de notifications envoyées par le serveur (chaque appel reçoit sa
  réponse) ; pas encore de jeton « lecture seule ».
- Qualité : testé dans Chromium seulement (Safari / WebKit et Firefox non disponibles ici) ; l'application reste une
  page JavaScript (son premier contenu attend les scripts : LCP mobile mesuré à ~5 s en laboratoire local) ; les
  adresses `http://` et `www.` répondent encore (balises canoniques en place ; la redirection se règle dans cPanel) ;
  pages publiques en anglais seulement.
- Parcours du créateur : pas de réalisation ni d'XP par le serveur MCP ; un niveau de Studio n'est reconnu qu'à partir
  des passages enregistrés depuis la version 12 (et des cartes déjà terminées) ; les invitations par lien réutilisable
  sont datées de leur création ; un invité sans compte qui valide reste « un client vérifié » (son nom n'apparaît
  jamais) ; l'image PNG se crée dans le navigateur (pas d'image d'aperçu générée par le serveur pour les réseaux) ;
  une seule langue (anglais).
- Offre de lancement : une seule langue (anglais) ; pas de bannière de consentement pour les cookies du parrainage
  (fonctionnels, sans adresse IP) ; la vérification d'adresse ne prouve pas qu'un ami est une personne distincte —
  les cas suspects vont à l'administration, sans empreinte d'appareil.
- Share & publish : pas d'opportunité ni de réalisation par le serveur MCP (assistants IA) ; pas de signalement
  d'une publication par les membres (modération depuis l'administration) ; un professionnel ne peut joindre que
  des travaux déjà publiés sur son profil ; pas de paiement entre membres (le prix d'une proposition est indicatif).
- Invités sans compte : pas d'envoi de fichier, pas de calendrier ni de tableau de bord (le tableau seulement),
  pas de demande de validation (ils valident celles qu'on leur adresse). L'email regroupant leurs modifications
  part dès qu'une requête arrive après la fenêtre de 10 minutes : sur un site sans aucune visite, il attend la
  suivante.
- La synchronisation entre navigateurs se fait par interrogation toutes les 4 secondes, pas en continu : deux
  personnes qui écrivent le même champ au même moment, la dernière enregistrée l'emporte.
- L'adresse email d'un compte créé avec un mot de passe n'est pas vérifiée à l'inscription : on peut utiliser
  Studioma sans la confirmer. Elle l'est par le lien de confirmation (email de bienvenue, Settings › Email), par
  Google ou par le lien de mot de passe oublié ; seules les invitations envoyées par email en dépendent.
- Pas de signalement d'une publication par les membres ni de blocage entre membres : la modération se fait
  depuis l'administration. Pas de statistiques d'ouverture ou de clic des campagnes (volontairement : aucun suivi
  dans les emails). Une seule session d'administration (le mot de passe de `/admin`), sans comptes
  d'administrateurs distincts.
- Une seule langue (anglais). Pages légales en anglais seulement, non relues par un juriste ; les membres inscrits
  avant leur publication ont accepté l'ancienne phrase (« use Studioma respectfully… ») : les prévenir (campagne
  d'administration) est conseillé, sans nouvelle acceptation forcée.
- Les pages publiques sont rendues par JavaScript : elles se partagent par lien mais ne sont pas optimisées pour
  les moteurs de recherche (seules la landing, les opportunités, les prix, les pages de vérification et les passeports publics le sont).

## Référencement (SEO)

Ce que fait le code :

- **dehini.com** : titre, description, adresse canonique, aperçu de partage (Open Graph, X), et données
  structurées « Person » + « WebSite » (nom, poste, entreprise, profils LinkedIn et GitHub repris de
  `data.json`), texte alternatif du portrait, liens vers le défi Impact et Stats Meta KPIs en pied de page.
- **Défi Impact** : une page par question et une page de toutes les questions (voir « 10. Pages des
  questions »), versions arabe et anglaise signalées à Google (hreflang), données structurées.
- **Maillage** : titre, description, adresse canonique et aperçu de partage sur la landing et la plateforme
  publique ; l'espace entreprise et l'administration sont exclus des moteurs de recherche.
- **Plans du site** : `/sitemap.xml` (pages principales), `/impact/sitemap.xml` (défi, toutes les questions,
  questions qui ont des récits), `/swanbida/sitemap.xml` ; tous déclarés dans `/robots.txt`.

À faire une fois (15 minutes, gratuit) — sans cela Google met des semaines à tout découvrir :

1. **Google Search Console** (`https://search.google.com/search-console`) → « Ajouter une propriété » →
   **Domaine** `dehini.com`. Google donne un enregistrement **TXT** : dans **cPanel → Zone Editor** →
   `dehini.com` → « + Ajouter un enregistrement » → TXT, nom `dehini.com.`, valeur collée telle quelle.
   Revenir sur Search Console → « Valider » (parfois quelques minutes à quelques heures).
2. Search Console → **Sitemaps** : soumettre `https://dehini.com/sitemap.xml` puis
   `https://dehini.com/impact/sitemap.xml`.
3. Search Console → **Inspection de l'URL** : coller `https://dehini.com/`, puis `https://dehini.com/impact/` et
   `https://dehini.com/impact/questions` → « Demander l'indexation ».
4. **Bing Webmaster Tools** (`https://www.bing.com/webmasters`) → « Importer depuis Google Search Console »
   (Bing alimente aussi DuckDuckGo et Yahoo).

Ce qui fait vraiment monter dans Google (le code ne peut pas le faire à votre place) :

- **Des récits publiés** : chaque question qui reçoit un récit devient une page que Google peut proposer à
  quelqu'un qui cherche « quitter la maison familiale témoignage ». Plus il y a de récits (et dans les trois
  langues), plus il y a de portes d'entrée. Les emails « Et si vous racontiez cet impact positif ? » y aident.
- **Des liens vers le site** : mettre `https://dehini.com` sur LinkedIn (section Coordonnées et « Sélection »),
  GitHub, Facebook, Instagram, la signature email, les pages des marques (Swanbida, Rotinia…), et partager des
  pages de questions sur les réseaux. Chaque lien depuis un autre site compte.
- **Le temps** : les premières visites depuis Google arrivent en général après 2 à 8 semaines. Search
  Console → « Performances » montre les recherches qui affichent le site et les clics.

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

## Vitrine des plateformes en ligne (section Projets)

En haut de la section **Projets** de la page d'accueil, une vitrine présente les plateformes hébergées sur
dehini.com et développées dans ce dépôt : **Défi Impact**, **Studioma**, **ELBAHI FERTI** et **Maillage**.

- Un onglet par plateforme ; le défilement passe tout seul de l'une à l'autre (barre de progression, bouton
  Pause / Lecture auto). Il s'arrête au survol, au clavier, hors de l'écran et dès qu'on choisit un onglet.
  Sur téléphone : balayage gauche / droite.
- Chaque fiche montre le site dans un navigateur et un téléphone : **survoler un écran fait défiler la page**
  (sur téléphone : le toucher), et la couleur de la fiche suit celle de la marque.
- **Aperçu en direct** (bouton, ou clic sur un écran) : le vrai site s'ouvre dans une fenêtre, en taille
  ordinateur, tablette ou téléphone. Cela fonctionne parce que les sites sont sur le même domaine
  (Studioma et Maillage n'autorisent l'affichage en fenêtre que depuis dehini.com).
- Si un projet de l'admin a un PDF et un titre qui correspond (ex. « ELBAHI » pour ELBAHI FERTI), la fiche
  propose aussi **Feuilleter le PDF**.
- Le chiffre « projets réalisés » du haut de page compte les projets de l'admin **plus** ceux de la vitrine.

Ces fiches font partie du **code** (constante `SHOWCASE` dans `index.html`), pas de `data.json` : elles se
modifient ici sur GitHub et arrivent sur le serveur avec le cron. Les captures sont dans `img/projets/`
(`<slug>-desktop.webp` et `<slug>-mobile.webp`). Pour les refaire après un changement sur un site :

```bash
node tools/vitrine/captures.js            # les quatre plateformes
node tools/vitrine/captures.js maillage   # une seule
```

(Playwright doit être installé : `npm i -g playwright && npx playwright install chromium`.)

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
- Liste des inscrits (admin) : réservée à la session de `/admin` (jeton anti-CSRF sur les modifications) ;
  l'export CSV neutralise les cellules qui commenceraient par une formule Excel (`=`, `+`, `-`, `@`).
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
  pages publiques ; jeton Conversions API chiffré (AES-256-GCM, clé hors `public_html`) ;
  `lib/`, `templates/`, `config.php`, `cron.php` bloqués en accès direct ; aucun script exécutable dans `media/` ;
  IP et navigateur des commandes effacés au bout de 45 jours ; exports CSV protégés contre les formules Excel.
- Marketing Dashboard : réservé à la session de `/admin` (expiration après 12 h), jeton anti-CSRF sur chaque écriture,
  toutes les saisies validées côté serveur à partir du schéma, requêtes SQL préparées, base hors de `public_html` ;
  `config.php`, `cron.php` et `lib/` bloqués en accès direct, page exclue des moteurs de recherche (`noindex` et
  `robots.txt`) ; exports CSV protégés contre les formules Excel ; connexion Meta en lecture seule, jetons chiffrés
  hors de `public_html`, jamais envoyés au navigateur, `appsecret_proof` sur chaque appel, retour OAuth vérifié par un
  jeton `state` lié à la session.
- Maillage : mots de passe hachés, sessions à jeton aléatoire (seul leur hash est en base), jeton anti-CSRF et
  en-tête `X-Requested-With` sur chaque écriture, limites d'essais (inscription, connexion, commandes, suivi) ;
  chaque action de l'espace entreprise vérifie que l'objet appartient à l'entreprise connectée ; la session de
  démonstration est en lecture seule ; les inscriptions attendent une validation avant d'être publiées. Tout ce
  que saisissent les entreprises et les clients est échappé à l'affichage, et une politique de sécurité du contenu
  (CSP, `maillage/.htaccess`) interdit tout script en ligne. Photos ré-encodées (GD) et rangées dans `media/`, où
  aucun script ne s'exécute ; `config.php` et `lib/` bloqués en accès direct ; base hors de `public_html`. Le
  suivi d'une commande demande le numéro **et** le téléphone ; les recherches sont comptées sans identité, avec une
  position arrondie à environ 1 km.
- Studioma : mots de passe hachés, sessions à jeton aléatoire (seul leur hash est en base), jeton anti-CSRF et
  en-tête `X-Requested-With` sur chaque écriture, limites d'essais (inscription, connexion, invitations,
  publications, messages). **Les droits sont vérifiés par le serveur à chaque action** (`lib/access.php`) : un
  compte sans accès à un Studio reçoit « introuvable », un rôle insuffisant « refusé » — l'interface ne fait que
  masquer ce que le rôle n'autorise pas. Fichiers hors de `public_html`, servis par l'API après vérification de
  l'accès, jamais exécutés ; images ré-encodées (métadonnées et position GPS retirées). Connexion Google : jeton
  d'identité vérifié côté serveur (signature, émetteur, destinataire, expiration, adresse confirmée) ; un mot de
  passe posé sur une adresse jamais confirmée est retiré quand son propriétaire arrive avec Google. Invitations
  envoyées par email : rattachées à un compte par son adresse seulement si elle est confirmée (le lien reçu par
  email suffit toujours) ; lien de confirmation à usage unique, valable 2 jours, ouvert depuis le compte connecté,
  annulé par un changement d'adresse. Texte mis en forme
  réduit à une liste de balises sans attribut, tout le reste échappé à l'affichage, et politique de sécurité du
  contenu sans script en ligne (`studioma/.htaccess`). `config.php` et `lib/` bloqués en accès direct.
  Serveur MCP : jeton exigé à chaque appel (OAuth avec PKCE S256, ou jeton personnel), seuls les hashs des jetons
  sont gardés, code d'autorisation à usage unique (retiré même après un essai raté) et lié à l'adresse de retour
  enregistrée, jeton de renouvellement à usage unique, accès retirable à tout moment ; les outils appellent les
  mêmes actions que l'application, donc les mêmes droits ; limites d'appels par compte.
