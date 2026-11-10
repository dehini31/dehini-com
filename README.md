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
cd ~/dehini-com && git pull -q && cp -f index.html ~/public_html/index.html && cp -f admin/index.html admin/api.php admin/.htaccess admin/.user.ini ~/public_html/admin/ && cp -f uploads/.htaccess ~/public_html/uploads/.htaccess && cp -rf img ~/public_html/ && cp -rf elbahiferti ~/public_html/ && cp -rf impact ~/public_html/ && cp -rf metakpis ~/public_html/ && cp -rf swanbida ~/public_html/ && cp -rf marketing ~/public_html/ && cp -f robots.txt sitemap.xml ~/public_html/
```

Cette commande ne touche jamais `data.json`, `admin/config.php`, `impact/config.php`,
`metakpis/config.php`, `swanbida/config.php`, `marketing/config.php`, les bases du défi Impact, de Stats Meta KPIs,
de SWANBIDA et du Marketing Dashboard, ni les images envoyées dans `uploads/` et `swanbida/media/` : seul le code
(`index.html`, `admin/*.php`, `elbahiferti/`, `impact/`, `metakpis/`, `swanbida/`, `marketing/`) est mis à jour.

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
| 📊 Funnel Analytics | Création d'un funnel (modèle Publicité → Landing Page → Inscription → Investissement), étapes avec objectif chiffré, tags et **canaux de communication** (cochés pour chaque étape, par catégorie : c'est le seul endroit où l'on relie un funnel à des canaux, réseaux sociaux compris), association aux campagnes publicitaires, landing pages et emails. Chaque étape prend ses chiffres dans une **saisie manuelle** ou **automatiquement** dans les éléments associés (clics des campagnes, visites ou inscriptions des pages, ouvertures d'emails, leads liés…), ou dans **les canaux de l'étape** (actions de communication, portée, engagement, clics et conversions de ces actions, leads, leads qualifiés, questions reçues par ces canaux, et relevés des comptes sociaux de ces canaux). Prospects par étape, taux de conversion, taux d'abandon, étape où l'on perd le plus. |
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

Ce que la synchronisation remplit (bouton « Synchroniser maintenant », et chaque matin avec la tâche cron) :

| Source Meta | Va dans | Classement automatique |
| --- | --- | --- |
| Statistiques de la Page et du compte Instagram | Social Media › Relevés des comptes (un relevé par jour : portée, vues, engagement, nouveaux abonnés, abonnés, clics) | canal Facebook / Instagram |
| Publications, Reels, carrousels, stories | Communication › Actions (portée, vues, engagement, clics, lien) | support (Post, Carrousel, Reel, Story, Live), cible et objectif d'après le texte |
| Campagnes Meta Ads | Ads Manager › Campagnes (nom, statut, dates, budget converti) et Résultats jour par jour (dépense, impressions, clics, leads, conversions) | objectif Meta → objectif du tableau de bord, cible d'après le nom |
| Formulaires Lead Ads | Leads & Contacts (nom, téléphone, email, ville, réponses dans les notes, campagne et funnel) | profil investisseur / porteur, canal Meta Ads (ou Facebook / Instagram si organique), support Formulaire |
| Commentaires qui posent une question (français, arabe, darija) | Leads & Contacts › Questions | sujet (investissement, projet, plateforme, partenariat, réclamation) et profil |

Le classement suit des mots-clés modifiables dans **Connexions › Classement automatique** (avec un champ « Essayer »
qui montre le résultat en direct). Règles de mise à jour : les chiffres venant de Meta sont mis à jour à chaque
synchronisation (les 3 derniers jours, et les publications des 14 derniers jours) ; ce que l'équipe complète (statut
et commercial d'un lead, objectif chiffré et funnel d'une campagne…) n'est jamais écrasé ; une campagne ou un lead déjà
saisi à la main (même nom de campagne, même téléphone ou email) est relié au lieu d'être dupliqué ; une donnée
importée puis supprimée n'est pas réimportée. Les lignes importées portent l'étiquette « Meta ». Les indicateurs
retirés par Meta (impressions remplacées par les vues en 2025, par exemple) sont détectés et remplacés tout seuls par
leurs successeurs. **Connexions › Historique** garde le détail des 60 dernières synchronisations.

Pour une mise à jour toutes les heures, ajouter une 2e tâche cron (Minute `0`, autres champs `*`) :

```bash
/usr/local/bin/php ~/public_html/marketing/cron.php --meta-only > /dev/null 2>&1
```

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

## Référencement (SEO)

Ce que fait le code :

- **dehini.com** : titre, description, adresse canonique, aperçu de partage (Open Graph, X), et données
  structurées « Person » + « WebSite » (nom, poste, entreprise, profils LinkedIn et GitHub repris de
  `data.json`), texte alternatif du portrait, liens vers le défi Impact et Stats Meta KPIs en pied de page.
- **Défi Impact** : une page par question et une page de toutes les questions (voir « 10. Pages des
  questions »), versions arabe et anglaise signalées à Google (hreflang), données structurées.
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
