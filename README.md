# dehini.com

Site personnel de Mohammed Dehini.

- `index.html` — la page (statique, sans build). Elle charge son contenu depuis `data.json`.
- `data.json` — tout le contenu éditable : textes, expériences, projets, compétences, liens.
  **Ne pas l'éditer à la main une fois le site installé** — passer par `/admin` (voir plus bas) ;
  après l'installation, le fichier `data.json` réellement affiché vit sur le serveur et n'est
  plus synchronisé avec Git (voir « Comment ça se met à jour »).
- `admin/` — back-office protégé par mot de passe pour modifier textes, images et liens sans
  toucher au code.
- `uploads/` — images envoyées depuis l'admin (ignoré par Git).

## Installation (serveur dzSecurity, compte cPanel `dehini91`)

### 1. Cloner le dépôt et publier le code

Dans le **Terminal cPanel** :

```bash
cd ~ && git clone https://github.com/dehini31/dehini-com.git
mkdir -p ~/public_html/admin ~/public_html/uploads
cp -f dehini-com/index.html ~/public_html/index.html
cp -f dehini-com/admin/index.html dehini-com/admin/api.php dehini-com/admin/.htaccess ~/public_html/admin/
cp -f dehini-com/uploads/.htaccess ~/public_html/uploads/.htaccess
cp -rf dehini-com/img ~/public_html/
mkdir -p ~/public_html/Yinvesti && cp -rf dehini-com/Yinvesti/. ~/public_html/Yinvesti/
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
cd ~/dehini-com && git pull -q && cp -f index.html ~/public_html/index.html && cp -f admin/index.html admin/api.php admin/.htaccess ~/public_html/admin/ && cp -f uploads/.htaccess ~/public_html/uploads/.htaccess && cp -rf img ~/public_html/ && mkdir -p ~/public_html/Yinvesti && cp -rf Yinvesti/. ~/public_html/Yinvesti/
```

Cette commande ne touche jamais `data.json`, `admin/config.php` ni les images envoyées dans
`uploads/` : seul le code (`index.html`, `admin/*.php`) est mis à jour.

## Page Yinvesti × African Startup Conference 2026 (`/Yinvesti`)

Page vitrine de préinscription au crowdfunding Yinvesti pendant l'ASC 2026 (5 – 7 décembre,
CIC Alger), en coordination avec Algeria Venture. Français, arabe et anglais.

- `Yinvesti/index.html` — la page (aucune dépendance, aucun build).
- `Yinvesti/candidature.php` — reçoit le formulaire : fiche JSON, ligne dans
  `candidatures.csv` (ouvrable dans Excel) et document joint, rangés **hors du site** dans
  `~/yinvesti-candidatures/`. Envoie un accusé de réception au porteur.
- `https://dehini.com/Yinvesti/candidatures.php` — liste des candidatures, fiches, documents
  joints et export CSV. **Même mot de passe que `/admin`.**

Réglages (une fois, sur le serveur) :

```bash
cp ~/public_html/Yinvesti/config.example.php ~/public_html/Yinvesti/config.php
nano ~/public_html/Yinvesti/config.php   # adresse qui reçoit chaque candidature
```

`config.php` n'est jamais envoyé sur GitHub et n'est pas écrasé par le cron. Pour accepter des
pitch decks jusqu'à 25 Mo : **cPanel → MultiPHP INI Editor** → `upload_max_filesize = 26M` et
`post_max_size = 28M` (sinon la page affiche un message clair et propose d'envoyer un fichier
plus léger).

## Utiliser l'admin

1. Ouvrir `https://dehini.com/admin/`.
2. Se connecter avec le mot de passe généré à l'étape 2.
3. Modifier les textes, glisser une nouvelle image (elle est envoyée automatiquement), changer
   un lien, ajouter/supprimer/réordonner une expérience ou un projet avec les flèches et le ✕.
4. Cliquer sur **Enregistrer** (ou Ctrl+S). Le site est mis à jour immédiatement, sans
   attendre le cron.

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
- Les images envoyées sont vérifiées (type réel de l'image, taille max 6 Mo) et le dossier
  `uploads/` refuse d'exécuter un script.
- `admin/config.php` contient le hash du mot de passe : il est exclu de Git (`.gitignore`) et
  bloqué en accès direct par `admin/.htaccess`.
- Préinscriptions Yinvesti : stockées hors de `public_html`, liste et téléchargements réservés à
  l'admin connecté ; champ piège anti-robots, 5 envois max par heure et par IP, types de
  fichiers contrôlés (PDF, PowerPoint, Word, ZIP).
