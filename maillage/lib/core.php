<?php
/**
 * Socle de Maillage (dehini.com/maillage/) : configuration, base SQLite, réglages, outils de
 * texte et de géographie, envoi d'emails. Aucune dépendance externe.
 */
declare(strict_types=1);

define('MLG_ROOT', dirname(__DIR__));

// Configuration locale facultative — jamais dans Git (voir config.example.php).
$cfg = MLG_ROOT . '/config.php';
if (is_file($cfg)) require $cfg;
// Sans réglage SMTP propre, on réutilise le compte email du défi Impact s'il est configuré.
$impactCfg = dirname(MLG_ROOT) . '/impact/config.php';
if (!defined('MLG_SMTP_HOST') && is_file($impactCfg)) {
    try { require_once $impactCfg; } catch (Throwable $e) { /* config Impact illisible : on s'en passe */ }
}

$defaults = [
    'MLG_NAME'              => 'Maillage',
    'MLG_SITE_URL'          => '',          // ex. https://dehini.com/maillage/ — déduit de la requête si vide
    'MLG_DB_PATH'           => dirname(MLG_ROOT, 2) . '/maillage-data/maillage.sqlite', // hors public_html
    'MLG_TIMEZONE'          => 'Africa/Algiers',
    'MLG_CURRENCY'          => 'DA',
    'MLG_SEED_DEMO'         => true,        // charge le réseau de démonstration à la première visite
    'MLG_RESERVATION_HOURS' => 48,          // durée pendant laquelle un article réservé est mis de côté
    'MLG_COVER_KM'          => 30,          // au-delà, une zone est considérée comme non couverte
    'MLG_RADAR_STEP_MIN'    => 30,          // radar : minutes avant d'élargir une commande (commune → wilaya → partout)
    'MLG_TILES_URL'         => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    'MLG_TILES_ATTR'        => '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    'MLG_MAP_CENTER'        => [34.6, 3.2], // vue de départ de la carte
    'MLG_MAP_ZOOM'          => 6,
    'MLG_MAIL_FROM'         => defined('IMPACT_MAIL_FROM') ? IMPACT_MAIL_FROM : '',
    'MLG_MAIL_FROM_NAME'    => 'Maillage',
    'MLG_SMTP_HOST'         => defined('IMPACT_SMTP_HOST') ? IMPACT_SMTP_HOST : '',
    'MLG_SMTP_PORT'         => defined('IMPACT_SMTP_PORT') ? IMPACT_SMTP_PORT : 465,
    'MLG_SMTP_SECURE'       => defined('IMPACT_SMTP_SECURE') ? IMPACT_SMTP_SECURE : 'ssl',
    'MLG_SMTP_USER'         => defined('IMPACT_SMTP_USER') ? IMPACT_SMTP_USER : '',
    'MLG_SMTP_PASS'         => defined('IMPACT_SMTP_PASS') ? IMPACT_SMTP_PASS : '',
    'MLG_SMTP_VERIFY'       => defined('IMPACT_SMTP_VERIFY') ? IMPACT_SMTP_VERIFY : true,
    'MLG_MAIL_LOG'          => '',          // tests : les emails sont écrits dans ce fichier au lieu d'être envoyés
];
foreach ($defaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
date_default_timezone_set(MLG_TIMEZONE);

/** Rayons du catalogue (classification des produits et des marques). */
const MLG_CATEGORIES = [
    'mode'        => 'Mode & sport',
    'maison'      => 'Maison & déco',
    'electro'     => 'Électroménager',
    'high-tech'   => 'High-tech',
    'beaute'      => 'Beauté & santé',
    'alimentation' => 'Alimentation',
    'bricolage'   => 'Bricolage & jardin',
    'auto'        => 'Auto & moto',
    'enfants'     => 'Enfants & jouets',
    'autre'       => 'Autre',
];
const MLG_STORE_TYPES = ['magasin' => 'Magasin', 'succursale' => 'Succursale', 'distributeur' => 'Distributeur'];
const MLG_KINDS = ['brand' => 'Entreprise / marque', 'distributor' => 'Distributeur', 'pos' => 'Point de vente'];

/** URL publique du dossier /maillage/ (avec la barre finale). */
function site_url(): string
{
    if (MLG_SITE_URL !== '') return rtrim(MLG_SITE_URL, '/') . '/';
    if (PHP_SAPI === 'cli') return 'https://dehini.com/maillage/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host  = $_SERVER['HTTP_HOST'] ?? 'dehini.com';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/maillage/api.php')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname(MLG_DB_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de la base : ' . $dir);
    }
    $pdo = new PDO('sqlite:' . MLG_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (Throwable $e) { /* base occupée : la prochaine connexion s'en chargera */ }
    migrate($pdo);
    return $pdo;
}

/*
 * Transactions d'écriture. Le verrou d'écriture est pris dès le début (BEGIN IMMEDIATE) : une
 * transaction qui lirait d'abord puis écrirait serait refusée tout de suite (« database is
 * locked ») si une autre requête a écrit entre-temps, au lieu de patienter.
 */
function tx_begin(): void
{
    db()->exec('BEGIN IMMEDIATE');
    $GLOBALS['mlg_tx'] = true;
}
function tx_commit(): void
{
    db()->exec('COMMIT');
    $GLOBALS['mlg_tx'] = false;
}
/** Annule la transaction en cours, s'il y en a une (sans effet sinon). */
function tx_rollback(): void
{
    if (empty($GLOBALS['mlg_tx'])) return;
    $GLOBALS['mlg_tx'] = false;
    try { db()->exec('ROLLBACK'); } catch (Throwable $e) { /* déjà annulée par SQLite */ }
}

/** Schéma compatible avec les vieilles versions de SQLite des hébergeurs mutualisés. */
function migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 2) return;
    // Deux premières visites simultanées : la seconde attend la fin de la création, puis ne fait rien.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v < 1) {
            $pdo->exec(<<<SQL
        -- Une entreprise inscrite : une marque (qui possède un catalogue) ou un distributeur.
        CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            about TEXT NOT NULL DEFAULT '',
            logo TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            website TEXT NOT NULL DEFAULT '',
            wilaya TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            sector TEXT NOT NULL DEFAULT '',
            size TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            demo INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            email TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            pass TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            last_seen INTEGER NOT NULL DEFAULT 0,
            rhash TEXT,
            rexp INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS users_c ON users(company_id);
        -- ro = session de démonstration en lecture seule ; adm = ouverte depuis l'administration.
        CREATE TABLE IF NOT EXISTS sessions (
            hash TEXT PRIMARY KEY,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf TEXT NOT NULL,
            exp INTEGER NOT NULL,
            ro INTEGER NOT NULL DEFAULT 0,
            adm INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS brands (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            tagline TEXT NOT NULL DEFAULT '',
            about TEXT NOT NULL DEFAULT '',
            logo TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '',
            category TEXT NOT NULL DEFAULT 'autre',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS brands_c ON brands(company_id);
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            category TEXT NOT NULL DEFAULT 'autre',
            about TEXT NOT NULL DEFAULT '',
            price REAL NOT NULL DEFAULT 0,
            images TEXT NOT NULL DEFAULT '[]',
            specs TEXT NOT NULL DEFAULT '[]',
            search TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'active',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS products_c ON products(company_id);
        CREATE INDEX IF NOT EXISTS products_b ON products(brand_id);
        -- Une déclinaison (taille / couleur) : c'est elle qui est en stock dans un point de vente.
        CREATE TABLE IF NOT EXISTS variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
            sku TEXT NOT NULL DEFAULT '',
            size TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '',
            color_hex TEXT NOT NULL DEFAULT '',
            price REAL,
            pos INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS variants_p ON variants(product_id);
        CREATE TABLE IF NOT EXISTS stores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            type TEXT NOT NULL DEFAULT 'magasin',
            address TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            wilaya TEXT NOT NULL DEFAULT '',
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            phone TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '',
            hours TEXT NOT NULL DEFAULT '{}',
            pickup INTEGER NOT NULL DEFAULT 1,
            delivery INTEGER NOT NULL DEFAULT 0,
            reserve INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL DEFAULT 'active',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS stores_c ON stores(company_id);
        CREATE TABLE IF NOT EXISTS stock (
            store_id INTEGER NOT NULL REFERENCES stores(id) ON DELETE CASCADE,
            variant_id INTEGER NOT NULL REFERENCES variants(id) ON DELETE CASCADE,
            qty INTEGER NOT NULL DEFAULT 0,
            updated INTEGER NOT NULL,
            PRIMARY KEY (store_id, variant_id)
        );
        CREATE INDEX IF NOT EXISTS stock_v ON stock(variant_id);
        -- Une marque et un distributeur qui travaillent ensemble.
        CREATE TABLE IF NOT EXISTS partnerships (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            brand_cid INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            dist_cid INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT 'pending',
            initiator INTEGER NOT NULL,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL,
            UNIQUE (brand_cid, dist_cid)
        );
        -- Commandes et réservations. Les lignes gardent une copie du nom et du prix.
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref TEXT NOT NULL UNIQUE,
            thash TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL,
            mode TEXT NOT NULL,
            store_id INTEGER NOT NULL,
            seller_cid INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            total REAL NOT NULL DEFAULT 0,
            cname TEXT NOT NULL,
            cphone TEXT NOT NULL,
            cemail TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '',
            wilaya TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL,
            expires INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS orders_s ON orders(seller_cid, created);
        CREATE INDEX IF NOT EXISTS orders_st ON orders(store_id);
        CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            product_id INTEGER NOT NULL,
            variant_id INTEGER NOT NULL,
            product_cid INTEGER NOT NULL,
            brand_id INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT '',
            size TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '',
            price REAL NOT NULL,
            qty INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS order_items_o ON order_items(order_id);
        CREATE INDEX IF NOT EXISTS order_items_c ON order_items(product_cid);
        CREATE TABLE IF NOT EXISTS order_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            status TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            actor TEXT NOT NULL DEFAULT 'store',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS order_events_o ON order_events(order_id);
        -- Recherches des visiteurs (catalogue, disponibilité, carte) : la matière des analyses.
        CREATE TABLE IF NOT EXISTS searches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            kind TEXT NOT NULL,
            q TEXT NOT NULL DEFAULT '',
            brand_id INTEGER NOT NULL DEFAULT 0,
            product_id INTEGER NOT NULL DEFAULT 0,
            category TEXT NOT NULL DEFAULT '',
            size TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT '',
            wilaya TEXT NOT NULL DEFAULT '',
            lat REAL,
            lng REAL,
            results INTEGER NOT NULL DEFAULT 0,
            nearest_km REAL,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS searches_t ON searches(created);
        CREATE TABLE IF NOT EXISTS search_hits (
            search_id INTEGER NOT NULL REFERENCES searches(id) ON DELETE CASCADE,
            company_id INTEGER NOT NULL,
            PRIMARY KEY (search_id, company_id)
        );
        CREATE INDEX IF NOT EXISTS search_hits_c ON search_hits(company_id);
        -- Compteurs par jour : fiches consultées, appels et itinéraires demandés.
        CREATE TABLE IF NOT EXISTS views (
            kind TEXT NOT NULL,
            ref_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            n INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (kind, ref_id, day)
        );
        -- Journal du réseau : about_cid = la marque dont les produits sont concernés.
        CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            about_cid INTEGER NOT NULL DEFAULT 0,
            store_id INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL,
            detail TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS activity_c ON activity(company_id, created);
        CREATE INDEX IF NOT EXISTS activity_a ON activity(about_cid, created);
        CREATE TABLE IF NOT EXISTS rate (
            k TEXT PRIMARY KEY,
            n INTEGER NOT NULL,
            reset INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        );
        PRAGMA user_version = 1;
        SQL);
        }
        if ($v < 2) {
            // Communes (livraison, radar), commandes radar, comptes de point de vente, page de vente d'un produit.
            $has = function (string $table, string $col) use ($pdo): bool {
                foreach ($pdo->query("PRAGMA table_info($table)") as $c) {
                    if ($c['name'] === $col) return true;
                }
                return false;
            };
            $add = [
                ['stores', 'commune', "TEXT NOT NULL DEFAULT ''"],
                ['orders', 'commune', "TEXT NOT NULL DEFAULT ''"],
                ['orders', 'radar', 'INTEGER NOT NULL DEFAULT 0'],      // 1 = reçue sans point de vente, proposée aux plus proches
                ['orders', 'brand_cid', 'INTEGER NOT NULL DEFAULT 0'],  // entreprise dont la page a reçu la commande radar
                ['orders', 'claimed', 'INTEGER NOT NULL DEFAULT 0'],    // date de prise en charge par un point de vente
                ['users', 'store_id', 'INTEGER NOT NULL DEFAULT 0'],    // > 0 : compte limité à ce point de vente
                ['products', 'landing', "TEXT NOT NULL DEFAULT '{}'"],  // textes de la page de vente
            ];
            foreach ($add as [$table, $col, $def]) {
                if (!$has($table, $col)) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS orders_radar ON orders(radar, status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS orders_b ON orders(brand_cid, created)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS users_s ON users(store_id)');
            // Les points de vente déjà enregistrés reçoivent leur commune quand la ville en porte le nom.
            $up = $pdo->prepare('UPDATE stores SET commune = ? WHERE id = ?');
            foreach ($pdo->query("SELECT id, city, wilaya FROM stores WHERE commune = ''")->fetchAll() as $st) {
                $c = commune_guess((string) $st['wilaya'], (string) $st['city']);
                if ($c !== '') $up->execute([$c, $st['id']]);
            }
            $pdo->exec('PRAGMA user_version = 2');
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) { /* rien à annuler */ }
        throw $e;
    }
}

function meta_get(string $k): ?string
{
    $s = db()->prepare('SELECT v FROM meta WHERE k = ?');
    $s->execute([$k]);
    $v = $s->fetchColumn();
    return $v === false ? null : (string) $v;
}
function meta_set(string $k, string $v): void
{
    db()->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

/** Réglages modifiables depuis l'administration (table meta, préfixe « cfg. »). */
function setting(string $k, string $default = ''): string
{
    return meta_get('cfg.' . $k) ?? $default;
}
function setting_set(string $k, string $v): void
{
    meta_set('cfg.' . $k, $v);
}
/** Les nouvelles inscriptions attendent-elles une validation avant d'être publiées ? */
function moderation_on(): bool
{
    return setting('moderation', '1') === '1';
}

function token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function install_salt(): string
{
    $s = meta_get('salt');
    if ($s === null) {
        $s = bin2hex(random_bytes(16));
        meta_set('salt', $s);
    }
    return $s;
}

/* ------------------------------------------------------------------ texte */

function str_clean($s, int $max): string
{
    if (!is_string($s) && !is_int($s) && !is_float($s)) return '';
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '');
    $s = preg_replace("/\r\n?/", "\n", $s) ?? '';
    return mb_substr($s, 0, $max);
}
/** Une seule ligne (noms, villes, références…). */
function line_clean($s, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', str_clean($s, $max * 2)) ?? ''), 0, $max);
}

/** Minuscules sans accents : sert à la recherche et aux comparaisons. */
function norm(string $s): string
{
    static $map = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        '’' => "'", '‘' => "'",
    ];
    $s = strtr(mb_strtolower($s, 'UTF-8'), $map);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

function slugify(string $s): string
{
    $s = preg_replace('/[^a-z0-9]+/', '-', norm($s)) ?? '';
    return substr(trim($s, '-'), 0, 60);
}

/** Adresse unique dans une table (suffixe -2, -3… si le nom est déjà pris). */
function unique_slug(string $table, string $name, int $exceptId = 0): string
{
    if (!in_array($table, ['companies', 'brands', 'products'], true)) throw new InvalidArgumentException('table');
    $base = slugify($name);
    if ($base === '' || ctype_digit($base)) $base = substr($table, 0, -1) . '-' . substr(token(4), 0, 6);
    $slug = $base;
    $s = db()->prepare("SELECT id FROM $table WHERE slug = ? AND id <> ?");
    for ($i = 2; $i < 500; $i++) {
        $s->execute([$slug, $exceptId]);
        if (!$s->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
    }
    return $base . '-' . substr(token(4), 0, 6);
}

function valid_email(string $e): bool
{
    return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}
/** Chiffres d'un numéro de téléphone (le + initial est gardé). */
function phone_clean($p): string
{
    $p = str_clean($p, 40);
    $plus = strpos($p, '+') === 0 ? '+' : '';
    return $plus . substr(preg_replace('/\D+/', '', $p) ?? '', 0, 15);
}
function phone_ok(string $p): bool
{
    $d = ltrim($p, '+');
    return strlen($d) >= 8 && strlen($d) <= 15;
}
/** Les 8 derniers chiffres : comparer un numéro saisi avec ou sans indicatif. */
function phone_key(string $p): string
{
    return substr(preg_replace('/\D+/', '', $p) ?? '', -8);
}
function hex_color($c): string
{
    $c = is_string($c) ? trim($c) : '';
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : '';
}

/* ------------------------------------------------------------- géographie */

/** Zones (wilayas) avec les coordonnées approximatives de leur chef-lieu. */
function zones(): array
{
    static $z = null;
    if ($z === null) {
        $z = [];
        foreach (json_decode((string) file_get_contents(__DIR__ . '/data/zones.json'), true) ?: [] as $r) $z[$r['code']] = $r;
    }
    return $z;
}
function zone_name(string $code): string
{
    return zones()[$code]['name'] ?? '';
}
function zone_code($code): string
{
    $code = is_string($code) || is_int($code) ? (string) $code : '';
    return isset(zones()[$code]) ? $code : '';
}
/** Communes de chaque wilaya : [code => [[nom, nom arabe], …]]. */
function communes(): array
{
    static $c = null;
    if ($c === null) $c = json_decode((string) file_get_contents(__DIR__ . '/data/communes.json'), true) ?: [];
    return $c;
}
/** Clé de comparaison d'un nom de commune (sans accents, tirets ni espaces). */
function commune_key(string $s): string
{
    return preg_replace('/[^a-z0-9]+/', '', norm($s)) ?? '';
}
/** Nom officiel d'une commune de la wilaya (saisi en français ou en arabe), ou '' si elle n'en fait pas partie. */
function commune_clean(string $zone, $name): string
{
    $name = is_string($name) ? trim($name) : '';
    if ($name === '' || !isset(communes()[$zone])) return '';
    $k = commune_key($name);
    foreach (communes()[$zone] as $c) {
        if (($k !== '' && commune_key($c[0]) === $k) || $c[1] === $name) return (string) $c[0];
    }
    return '';
}
/** Commune correspondant à une ville saisie librement (« Alger » → « Alger Centre »), ou ''. */
function commune_guess(string $zone, string $city): string
{
    $exact = commune_clean($zone, $city);
    if ($exact !== '') return $exact;
    $k = commune_key($city);
    if ($k === '') return '';
    $hits = [];
    foreach (communes()[$zone] ?? [] as $c) {
        if (strpos(commune_key($c[0]), $k) === 0) $hits[] = (string) $c[0];
    }
    return count($hits) === 1 ? $hits[0] : '';
}
function commune_ar(string $zone, string $name): string
{
    foreach (communes()[$zone] ?? [] as $c) {
        if ($c[0] === $name) return (string) $c[1];
    }
    return '';
}
function coord($v, float $max): ?float
{
    if (!is_numeric($v)) return null;
    $f = (float) $v;
    return is_finite($f) && abs($f) <= $max ? $f : null;
}
function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $p = M_PI / 180;
    $a = 0.5 - cos(($lat2 - $lat1) * $p) / 2 + cos($lat1 * $p) * cos($lat2 * $p) * (1 - cos(($lng2 - $lng1) * $p)) / 2;
    return 12742 * asin(sqrt(max(0.0, min(1.0, $a))));
}
/** Zone dont le chef-lieu est le plus proche d'un point. */
function zone_nearest(float $lat, float $lng): string
{
    $best = '';
    $bd = INF;
    foreach (zones() as $code => $z) {
        $d = haversine($lat, $lng, (float) $z['lat'], (float) $z['lng']);
        if ($d < $bd) { $bd = $d; $best = (string) $code; }
    }
    return $best;
}

/* ------------------------------------------------------------------- email */

function mail_from(): string
{
    if (MLG_MAIL_FROM !== '') return MLG_MAIL_FROM;
    if (MLG_SMTP_USER !== '' && strpos(MLG_SMTP_USER, '@') !== false) return MLG_SMTP_USER;
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'dehini.com';
    return 'maillage@' . preg_replace('/^www\./', '', (string) $host);
}

function encode_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Gabarit commun des emails : un titre, des paragraphes, un bouton facultatif. */
function mail_layout(string $title, array $lines, string $btnLabel = '', string $btnUrl = ''): array
{
    $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#14213a">'
        . '<p style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#c2410c;margin:0 0 8px">' . $e(MLG_NAME) . '</p>'
        . '<h1 style="font-size:20px;margin:0 0 16px">' . $e($title) . '</h1>';
    foreach ($lines as $l) $html .= '<p style="font-size:15px;line-height:1.55;margin:0 0 12px">' . nl2br($e($l)) . '</p>';
    if ($btnUrl !== '') {
        $html .= '<p style="margin:20px 0"><a href="' . $e($btnUrl) . '" style="background:#f4511e;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;display:inline-block">' . $e($btnLabel) . '</a></p>';
    }
    $html .= '</div>';
    $text = $title . "\n\n" . implode("\n\n", $lines) . ($btnUrl !== '' ? "\n\n" . $btnLabel . ' : ' . $btnUrl : '') . "\n";
    return [$html, $text];
}

/** Envoi « au mieux » : une panne d'email ne doit jamais bloquer une commande ou une inscription. */
function try_mail(string $to, string $subject, string $title, array $lines, string $btnLabel = '', string $btnUrl = ''): bool
{
    if (!valid_email($to)) return false;
    try {
        [$html, $text] = mail_layout($title, $lines, $btnLabel, $btnUrl);
        send_mail($to, $subject, $html, $text);
        return true;
    } catch (Throwable $e) {
        error_log('maillage mail: ' . $e->getMessage());
        return false;
    }
}

/** Envoie un email texte + HTML (SMTP si configuré, sinon mail()). Lève une exception en cas d'échec. */
function send_mail(string $to, string $subject, string $html, string $text): void
{
    if (MLG_MAIL_LOG !== '') {
        file_put_contents(MLG_MAIL_LOG, json_encode(['to' => $to, 'subject' => $subject, 'text' => $text], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    $from = mail_from();
    $domain = substr(strrchr($from, '@') ?: '@dehini.com', 1);
    $b = 'b_' . token(12);
    $headers = implode("\r\n", [
        'From: ' . encode_header(MLG_MAIL_FROM_NAME) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . token(16) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ]);
    $body = '--' . $b . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n--" . $b . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n--" . $b . "--\r\n";
    if (MLG_SMTP_HOST === '') {
        if (!@mail($to, encode_header($subject), $body, $headers, '-f' . $from)) throw new RuntimeException('mail() a échoué');
        return;
    }
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool) MLG_SMTP_VERIFY, 'verify_peer_name' => (bool) MLG_SMTP_VERIFY,
        'allow_self_signed' => !MLG_SMTP_VERIFY, 'peer_name' => MLG_SMTP_HOST,
    ]]);
    $secure = MLG_SMTP_SECURE;
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . MLG_SMTP_HOST . ':' . (int) MLG_SMTP_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP : connexion impossible ($errstr)");
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (?string $line, array $codes) use ($fp, $read): void {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), $codes, true)) throw new RuntimeException('SMTP : « ' . trim($r) . ' »');
    };
    try {
        $helo = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, [220]);
        $cmd('EHLO ' . $helo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP : STARTTLS a échoué');
            $cmd('EHLO ' . $helo, [250]);
        }
        if (MLG_SMTP_USER !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(MLG_SMTP_USER), [334]);
            $cmd(base64_encode(MLG_SMTP_PASS), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = 'To: <' . $to . ">\r\nSubject: " . encode_header($subject) . "\r\n" . $headers . "\r\n\r\n" . $body;
        fwrite($fp, preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data)) . "\r\n.\r\n");
        $cmd(null, [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}
