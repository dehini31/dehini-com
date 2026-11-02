<?php
/**
 * Socle de la boutique SWANBIDA (dehini.com/swanbida/) : configuration, base SQLite,
 * réglages, adresses, sécurité et petites fonctions utilitaires. Aucune dépendance externe.
 *
 * Tout ce qui est « vivant » (commandes, produits, réglages, clés) est dans la base, hors de
 * public_html : ~/swanbida-data/swanbida.sqlite. Le code peut donc être redéployé sans risque.
 */
declare(strict_types=1);

// Configuration locale facultative — jamais dans Git (voir config.example.php).
$cfgFile = dirname(__DIR__) . '/config.php';
if (is_file($cfgFile)) require $cfgFile;
// Sans réglage SMTP propre, on reprend le compte email du défi Impact s'il est configuré.
$impactCfg = dirname(__DIR__, 2) . '/impact/config.php';
if (!defined('SWB_SMTP_HOST') && is_file($impactCfg)) {
    try { require_once $impactCfg; } catch (Throwable $e) { /* config Impact illisible : on s'en passe */ }
}

/**
 * Dossier des données par défaut, toujours hors de public_html — que la boutique soit un
 * sous-dossier d'un site plus large (ex. dehini.com/swanbida/, public_html/swanbida/lib/…)
 * ou la racine de son propre domaine (ex. swanbida.com, public_html/lib/…).
 */
function swb_default_data_dir(string $appRoot): string
{
    if (basename($appRoot) === 'public_html') return dirname($appRoot) . '/swanbida-data';
    if (basename(dirname($appRoot)) === 'public_html') return dirname($appRoot, 2) . '/swanbida-data';
    return dirname($appRoot) . '/swanbida-data'; // disposition non standard (tests locaux…)
}

$defaults = [
    'SWB_SITE_URL'       => '',   // ex. https://swanbida.com/ — déduit de la requête si vide, mais recommandé en CLI (cron)
    'SWB_DATA_DIR'       => swb_default_data_dir(dirname(__DIR__)), // toujours hors public_html
    'SWB_TIMEZONE'       => 'Africa/Algiers',
    'SWB_GRAPH_URL'      => 'https://graph.facebook.com/',  // tests : une fausse API peut la remplacer
    'SWB_TELEGRAM_URL'   => 'https://api.telegram.org/',
    'SWB_ADMIN_CONFIG'   => dirname(__DIR__, 2) . '/admin/config.php', // mot de passe de /admin (propriétaire)
    'SWB_MAIL_FROM'      => defined('IMPACT_MAIL_FROM') ? IMPACT_MAIL_FROM : '',
    'SWB_MAIL_FROM_NAME' => 'SWANBIDA',
    'SWB_SMTP_HOST'      => defined('IMPACT_SMTP_HOST') ? IMPACT_SMTP_HOST : '',
    'SWB_SMTP_PORT'      => defined('IMPACT_SMTP_PORT') ? IMPACT_SMTP_PORT : 465,
    'SWB_SMTP_SECURE'    => defined('IMPACT_SMTP_SECURE') ? IMPACT_SMTP_SECURE : 'ssl',
    'SWB_SMTP_USER'      => defined('IMPACT_SMTP_USER') ? IMPACT_SMTP_USER : '',
    'SWB_SMTP_PASS'      => defined('IMPACT_SMTP_PASS') ? IMPACT_SMTP_PASS : '',
    'SWB_SMTP_VERIFY'    => defined('IMPACT_SMTP_VERIFY') ? IMPACT_SMTP_VERIFY : true,
    'SWB_MAIL_LOG'       => '',   // tests : chemin d'un fichier où écrire les emails au lieu de les envoyer
    'SWB_FORCE_HTTPS'    => true, // en-tête HSTS quand la page est servie en HTTPS
];
foreach ($defaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
define('SWB_DB_PATH', SWB_DATA_DIR . '/swanbida.sqlite');
define('SWB_ROOT', dirname(__DIR__));
date_default_timezone_set(SWB_TIMEZONE);
mb_internal_encoding('UTF-8');

const SWB_LANGS = ['fr', 'ar'];
const SWB_VERSION = '1.0.0';

/** Statuts commerciaux d'une commande, dans l'ordre du parcours. */
const ORDER_STATUSES = ['new', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled', 'returned'];
/** Statuts qui impliquent que la commande a été confirmée par le client (téléphone). */
const CONFIRMED_STATUSES = ['confirmed', 'preparing', 'shipped', 'delivered'];
/** Statuts où le stock réservé est rendu. */
const RELEASED_STATUSES = ['cancelled', 'returned'];

/* =================================================================== adresses */

/** Chemin d'URL de la boutique, sans barre finale (ex. « /swanbida »). */
function base_path(): string
{
    static $b = null;
    if ($b !== null) return $b;
    if (SWB_SITE_URL !== '') return $b = rtrim((string) (parse_url(SWB_SITE_URL, PHP_URL_PATH) ?: ''), '/');
    if (PHP_SAPI === 'cli') return $b = '/swanbida';
    $name = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/swanbida/index.php'));
    $file = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real = $file !== '' ? str_replace('\\', '/', (string) (realpath($file) ?: $file)) : '';
    $root = str_replace('\\', '/', (string) (realpath(SWB_ROOT) ?: SWB_ROOT));
    if ($real !== '' && strpos($real, $root . '/') === 0) {
        $rel = substr($real, strlen($root)); // ex. « /admin/api.php »
        if ($rel !== '' && substr($name, -strlen($rel)) === $rel) return $b = rtrim(substr($name, 0, -strlen($rel)), '/');
    }
    return $b = rtrim(str_replace('\\', '/', dirname($name)), '/');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Origine publique (schéma + hôte), ex. https://dehini.com */
function site_origin(): string
{
    if (SWB_SITE_URL !== '') {
        $p = parse_url(SWB_SITE_URL);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'dehini.com') . (isset($p['port']) ? ':' . $p['port'] : '');
    }
    if (PHP_SAPI === 'cli') return 'https://dehini.com';
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'dehini.com')) ?: 'dehini.com';
    // Le site public est toujours annoncé en https:// (canonical, sitemap, Meta), même si le proxy de
    // l'hébergeur ne transmet pas l'information HTTPS au serveur. Seul un test local reste en http.
    $local = (bool) preg_match('/^(localhost|127\.|\[::1\]|0\.0\.0\.0)/', $host);
    return ($local && !is_https() ? 'http' : 'https') . '://' . $host;
}

/** URL publique absolue d'un chemin relatif à la boutique (« produit/x », « assets/… »). */
function abs_url(string $path = ''): string
{
    return site_origin() . base_path() . '/' . ltrim($path, '/');
}

/** Chemin relatif à la racine du domaine (pour les liens internes). */
function rel_url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

/* ======================================================================= base */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = SWB_DATA_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de la base : ' . $dir);
    }
    $pdo = new PDO('sqlite:' . SWB_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

/**
 * Schéma — compatible avec les vieilles versions de SQLite des hébergeurs mutualisés
 * (pas d'UPSERT, de RETURNING ni de fonctions JSON). Les contenus bilingues des produits,
 * collections et guides sont un document JSON par ligne (colonne data).
 */
function migrate(PDO $pdo): void
{
    $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= 1) return;
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // Une autre requête a pu migrer pendant qu'on attendait le verrou.
        if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 1) {
            $pdo->exec('COMMIT');
            return;
        }
        $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            sku TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'active',      -- active | draft
            featured INTEGER NOT NULL DEFAULT 0,
            pos INTEGER NOT NULL DEFAULT 0,
            price INTEGER NOT NULL DEFAULT 0,            -- prix d'une unité (offre « 1 »), en DZD
            compare_price INTEGER NOT NULL DEFAULT 0,
            stock INTEGER,                               -- NULL = stock non suivi
            stock_alert INTEGER NOT NULL DEFAULT 5,
            data TEXT NOT NULL DEFAULT '{}',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS collections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            active INTEGER NOT NULL DEFAULT 1,
            pos INTEGER NOT NULL DEFAULT 0,
            data TEXT NOT NULL DEFAULT '{}',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS product_collections (
            pid INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
            cid INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
            PRIMARY KEY (pid, cid)
        );
        CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'draft',       -- published | draft
            published INTEGER NOT NULL DEFAULT 0,
            data TEXT NOT NULL DEFAULT '{}',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ref TEXT NOT NULL UNIQUE,
            token TEXT NOT NULL,
            client_key TEXT,
            status TEXT NOT NULL DEFAULT 'new',
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            wilaya TEXT NOT NULL,
            wilaya_name TEXT NOT NULL DEFAULT '',
            commune TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            delivery TEXT NOT NULL DEFAULT 'home',      -- home | desk
            items TEXT NOT NULL DEFAULT '[]',
            qty INTEGER NOT NULL DEFAULT 1,
            subtotal INTEGER NOT NULL DEFAULT 0,
            shipping INTEGER NOT NULL DEFAULT 0,
            total INTEGER NOT NULL DEFAULT 0,
            lang TEXT NOT NULL DEFAULT 'fr',
            utm_source TEXT NOT NULL DEFAULT '',
            utm_medium TEXT NOT NULL DEFAULT '',
            utm_campaign TEXT NOT NULL DEFAULT '',
            utm_content TEXT NOT NULL DEFAULT '',
            utm_term TEXT NOT NULL DEFAULT '',
            campaign_id TEXT NOT NULL DEFAULT '',
            adset_id TEXT NOT NULL DEFAULT '',
            ad_id TEXT NOT NULL DEFAULT '',
            placement TEXT NOT NULL DEFAULT '',
            fbclid TEXT NOT NULL DEFAULT '',
            attribution TEXT NOT NULL DEFAULT '{}',
            fbp TEXT NOT NULL DEFAULT '',
            fbc TEXT NOT NULL DEFAULT '',
            vid TEXT NOT NULL DEFAULT '',
            ip TEXT NOT NULL DEFAULT '',
            ua TEXT NOT NULL DEFAULT '',
            source_url TEXT NOT NULL DEFAULT '',
            iph TEXT NOT NULL DEFAULT '',
            stock_reserved INTEGER NOT NULL DEFAULT 0,
            duplicate_of INTEGER,
            carrier TEXT NOT NULL DEFAULT '',
            tracking TEXT NOT NULL DEFAULT '',
            admin_note TEXT NOT NULL DEFAULT '',
            lead_event TEXT NOT NULL DEFAULT '',
            purchase_sent INTEGER NOT NULL DEFAULT 0,
            delivered_sent INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL,
            confirmed_at INTEGER NOT NULL DEFAULT 0,
            shipped_at INTEGER NOT NULL DEFAULT 0,
            delivered_at INTEGER NOT NULL DEFAULT 0,
            closed_at INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS orders_ck ON orders(client_key);
        CREATE INDEX IF NOT EXISTS orders_status ON orders(status, created);
        CREATE INDEX IF NOT EXISTS orders_created ON orders(created);
        CREATE INDEX IF NOT EXISTS orders_phone ON orders(phone, created);
        CREATE INDEX IF NOT EXISTS orders_campaign ON orders(campaign_id);
        CREATE TABLE IF NOT EXISTS order_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            oid INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,                         -- status | edit | note | meta | create
            from_status TEXT NOT NULL DEFAULT '',
            to_status TEXT NOT NULL DEFAULT '',
            detail TEXT NOT NULL DEFAULT '',
            actor TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS order_log_o ON order_log(oid, id);
        -- Événements serveur envoyés à Meta (Conversions API) : file d'attente + journal.
        CREATE TABLE IF NOT EXISTS capi_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            oid INTEGER,
            name TEXT NOT NULL,
            event_id TEXT NOT NULL,
            payload TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',     -- pending | sent | failed | skipped
            attempts INTEGER NOT NULL DEFAULT 0,
            next_try INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT '',
            response TEXT NOT NULL DEFAULT '',
            test INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            sent INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS capi_eid ON capi_events(name, event_id);
        CREATE INDEX IF NOT EXISTS capi_status ON capi_events(status, next_try);
        CREATE INDEX IF NOT EXISTS capi_order ON capi_events(oid);
        CREATE TABLE IF NOT EXISTS wilayas (
            code TEXT PRIMARY KEY,
            fr TEXT NOT NULL,
            ar TEXT NOT NULL DEFAULT '',
            fee_home INTEGER NOT NULL DEFAULT 0,
            fee_desk INTEGER NOT NULL DEFAULT 0,
            delay TEXT NOT NULL DEFAULT '',
            active INTEGER NOT NULL DEFAULT 1,
            pos INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS communes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            wilaya TEXT NOT NULL REFERENCES wilayas(code) ON DELETE CASCADE ON UPDATE CASCADE,
            fr TEXT NOT NULL,
            ar TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS communes_w ON communes(wilaya, fr);
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'agent',         -- manager | agent
            pass TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created INTEGER NOT NULL,
            last_login INTEGER NOT NULL DEFAULT 0
        );
        -- Compteurs du tunnel par jour (visites, vues produit, ajouts, débuts de commande).
        CREATE TABLE IF NOT EXISTS daily (
            day TEXT NOT NULL,
            k TEXT NOT NULL,
            n INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day, k)
        );
        CREATE TABLE IF NOT EXISTS rate (
            k TEXT PRIMARY KEY,
            n INTEGER NOT NULL,
            reset INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        );
        SQL);
        require_once __DIR__ . '/seed.php';
        seed_all($pdo);
        $pdo->exec('PRAGMA user_version = 1');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/* ================================================================== réglages */

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
    settings_cache(true);
}
function meta_del(string $k): void
{
    db()->prepare('DELETE FROM meta WHERE k = ?')->execute([$k]);
    settings_cache(true);
}

/** Tous les réglages « cfg.* » en une requête. */
function settings_cache(bool $reset = false): array
{
    static $c = null;
    if ($reset) { $c = null; return []; }
    if ($c !== null) return $c;
    $c = [];
    foreach (db()->query("SELECT k, v FROM meta WHERE k LIKE 'cfg.%'") as $r) $c[substr($r['k'], 4)] = (string) $r['v'];
    return $c;
}

/** Valeurs par défaut des réglages modifiables dans l'admin. */
const SETTING_DEFAULTS = [
    'pixel_id'          => '',
    'capi_token'        => '',          // chiffré (seal)
    'capi_enabled'      => '1',
    'capi_browser'      => '1',         // doubler PageView / ViewContent / AddToCart / InitiateCheckout côté serveur
    'test_code'         => '',          // code de test du Gestionnaire d'événements
    'graph_version'     => 'v24.0',
    'purchase_trigger'  => 'confirmed', // confirmed | delivered
    'delivered_event'   => 'OrderDelivered',
    'notify_emails'     => '',
    'notify_telegram_token' => '',      // chiffré
    'notify_telegram_chat'  => '',
    'gsc_verification'  => '',
    'fb_domain_verification' => '',
    'allow_backorder'   => '0',
    'duplicate_hours'   => '24',
    'desk_enabled'      => '0',
    'return_days'       => '7',
    'wa_template_fr'    => "Bonjour {name}, merci pour votre commande SWANBIDA n° {ref} : {items}, total {total}, livraison à {commune} ({wilaya}). Pouvez-vous nous confirmer la commande ?",
    'wa_template_ar'    => "مرحباً {name}، شكراً على طلبك من SWANBIDA رقم {ref}: {items}، المجموع {total}، التوصيل إلى {commune} ({wilaya}). هل تؤكد الطلب؟",
    'order_rate_ip'     => '8',         // commandes max par heure et par IP
];

function setting(string $k): string
{
    $c = settings_cache();
    return $c[$k] ?? (SETTING_DEFAULTS[$k] ?? '');
}
function setting_set(string $k, string $v): void
{
    meta_set('cfg.' . $k, $v);
}

/** Contenus éditables (accueil, histoire, livraison…) : un document JSON par section. */
function content(string $section): array
{
    static $cache = [];
    if (isset($cache[$section])) return $cache[$section];
    $raw = meta_get('content.' . $section);
    $d = $raw !== null ? json_decode($raw, true) : null;
    if (!is_array($d)) {
        require_once __DIR__ . '/seed.php';
        $d = default_content()[$section] ?? [];
    }
    return $cache[$section] = $d;
}
function content_set(string $section, array $data): void
{
    meta_set('content.' . $section, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    meta_set('cache_v', (string) time());
}

/* ===================================================================== crypto */

function token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

function install_salt(): string
{
    $s = meta_get('salt');
    if ($s === null) {
        $s = token(16);
        meta_set('salt', $s);
    }
    return $s;
}

/** Empreinte de l'IP (jamais stockée en clair pour les limites d'essais). */
function ip_hash(): string
{
    return substr(hash('sha256', install_salt() . '|' . client_ip()), 0, 32);
}

function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    // Derrière Cloudflare ou un proxy de l'hébergeur, l'IP réelle du visiteur est transmise.
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $cand = trim(explode(',', (string) $_SERVER[$h])[0]);
            if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $cand;
        }
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * Clé de chiffrement des secrets (jeton Conversions API, bot Telegram) : générée au premier
 * passage dans le dossier de la base (hors public_html), jamais dans Git ni dans la base.
 */
function secret_key(): string
{
    static $k = null;
    if ($k !== null) return $k;
    $f = SWB_DATA_DIR . '/secret.key';
    $raw = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
        if (!is_dir(SWB_DATA_DIR)) @mkdir(SWB_DATA_DIR, 0750, true);
        $raw = token(32);
        if (@file_put_contents($f, $raw, LOCK_EX) === false) throw new RuntimeException('secret.key impossible à écrire');
        @chmod($f, 0600);
    }
    return $k = (string) hex2bin($raw);
}
function seal(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('chiffrement impossible');
    return base64_encode($iv . $tag . $c);
}
function unseal(string $box): string
{
    if ($box === '') return '';
    $raw = base64_decode($box, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

/** Secret : constante de config.php si définie, sinon réglage chiffré de l'admin. */
function secret_setting(string $k, string $const): string
{
    if (defined($const) && (string) constant($const) !== '') return (string) constant($const);
    return unseal(setting($k));
}

/* ============================================================ limites d'essais */

/** Vrai si l'action est encore permise ; compte l'essai. */
function rate_ok(string $key, int $max, int $window): bool
{
    $pdo = db();
    $now = time();
    $s = $pdo->prepare('SELECT n, reset FROM rate WHERE k = ?');
    $s->execute([$key]);
    $r = $s->fetch();
    if (!$r || (int) $r['reset'] < $now) {
        $pdo->prepare('INSERT OR REPLACE INTO rate (k, n, reset) VALUES (?, 1, ?)')->execute([$key, $now + $window]);
        return true;
    }
    if ((int) $r['n'] >= $max) return false;
    $pdo->prepare('UPDATE rate SET n = n + 1 WHERE k = ?')->execute([$key]);
    return true;
}
function rate_clear(string $key): void
{
    db()->prepare('DELETE FROM rate WHERE k = ?')->execute([$key]);
}

/* ================================================================== compteurs */

const DAILY_KEYS = ['visit', 'pageview', 'view_content', 'add_to_cart', 'initiate_checkout', 'contact', 'lead'];

function daily_inc(string $k, int $n = 1, ?string $day = null): void
{
    $day = $day ?? date('Y-m-d');
    $pdo = db();
    $u = $pdo->prepare('UPDATE daily SET n = n + ? WHERE day = ? AND k = ?');
    $u->execute([$n, $day, $k]);
    if ($u->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO daily (day, k, n) VALUES (?, ?, ?)')->execute([$day, $k, $n]);
        } catch (PDOException $e) {
            $u->execute([$n, $day, $k]);
        }
    }
}

/* ===================================================================== textes */

function str_clean($s, int $max): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '');
    $s = preg_replace("/\r\n?/", "\n", $s) ?? '';
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? '';
    return mb_substr($s, 0, $max);
}

function one_line($s, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', str_clean($s, $max * 2)) ?? ''), 0, $max);
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $s): string
{
    $s = trim($s);
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
        if (is_string($t)) $s = $t;
    } else {
        $s = strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s));
    }
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '';
    return trim(substr($s, 0, 80), '-');
}

/** Texte d'un champ bilingue {fr, ar}, avec repli sur l'autre langue si vide. */
function tr($v, string $lang): string
{
    if (is_string($v)) return $v;
    if (!is_array($v)) return '';
    if (!empty($v[$lang])) return (string) $v[$lang];
    foreach (SWB_LANGS as $l) {
        if (!empty($v[$l])) return (string) $v[$l];
    }
    return '';
}

function money(int $n, string $lang): string
{
    $s = number_format($n, 0, ',', "\u{202F}");
    return $lang === 'ar' ? $s . "\u{00A0}دج" : $s . "\u{00A0}DA";
}

/* ================================================================== téléphone */

/**
 * Numéro algérien normalisé « 0XXXXXXXXX » (mobile 05/06/07 + 8 chiffres, ou fixe sur
 * 9 chiffres), ou chaîne vide s'il n'est pas valide. Accepte +213 / 00213 et les chiffres arabes.
 */
function normalize_phone(string $p): string
{
    $p = strtr($p, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    $p = preg_replace('/[^\d+]/', '', $p) ?? '';
    if (strpos($p, '+213') === 0) $p = '0' . substr($p, 4);
    elseif (strpos($p, '00213') === 0) $p = '0' . substr($p, 5);
    elseif (strpos($p, '213') === 0 && strlen($p) >= 12) $p = '0' . substr($p, 3);
    if (strlen($p) === 9 && preg_match('/^[567]/', $p)) $p = '0' . $p; // « 661 23 45 67 » sans le 0
    if (preg_match('/^0[567]\d{8}$/', $p)) return $p;
    if (preg_match('/^0[2-49]\d{7}$/', $p)) return $p;
    return '';
}

/** Format international sans « + » pour Meta et WhatsApp : 213XXXXXXXXX. */
function phone_e164(string $p): string
{
    $n = normalize_phone($p);
    return $n === '' ? '' : '213' . substr($n, 1);
}

function phone_display(string $p): string
{
    $n = normalize_phone($p);
    if ($n === '') return $p;
    return strlen($n) === 10
        ? substr($n, 0, 4) . ' ' . substr($n, 4, 2) . ' ' . substr($n, 6, 2) . ' ' . substr($n, 8, 2)
        : substr($n, 0, 3) . ' ' . substr($n, 3, 2) . ' ' . substr($n, 5, 2) . ' ' . substr($n, 7, 2);
}

/* ================================================================ HTTP sortant */

/**
 * Requête sortante (Meta, Telegram) : [code HTTP, corps]. $json = corps JSON envoyé en POST.
 */
function http_post_json(string $url, array $json, int $timeout = 15): array
{
    $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        if ($res === false) {
            $err = curl_error($ch);
            throw new RuntimeException('HTTP : ' . $err);
        }
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $res];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n", 'content' => $body,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) throw new RuntimeException('HTTP : échec');
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) $code = (int) $m[1];
    }
    return [$code, $res];
}

/* ========================================================= après la réponse */

/** Tâches lancées après l'envoi de la réponse au navigateur (Meta, notifications). */
function after_response(?callable $fn = null, bool $pop = false)
{
    static $tasks = [];
    if ($fn) $tasks[] = $fn;
    if ($pop) return array_shift($tasks);
    return count($tasks);
}

/** Envoie la réponse tout de suite au visiteur, puis exécute les tâches en attente. */
function finish_and_run(string $body, string $type = 'application/json; charset=utf-8', int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: ' . $type);
    if (!after_response()) {
        echo $body;
        return;
    }
    ignore_user_abort(true);
    @set_time_limit(60);
    if (function_exists('fastcgi_finish_request')) {
        echo $body;
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        echo $body;
        litespeed_finish_request();
    } else {
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        header('Content-Encoding: none');
        while (ob_get_level() > 0) @ob_end_flush();
        echo $body;
        @flush();
    }
    run_after_tasks();
}

/** Exécute les tâches différées (y compris celles ajoutées pendant l'exécution). */
function run_after_tasks(): void
{
    $guard = 0;
    while (($t = after_response(null, true)) && $guard++ < 50) {
        try { $t(); } catch (Throwable $e) { error_log('[swanbida] tâche différée : ' . $e->getMessage()); }
    }
}

/* =========================================================== en-têtes communs */

function security_headers(bool $admin = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header($admin ? 'X-Frame-Options: DENY' : 'X-Frame-Options: SAMEORIGIN');
    if (SWB_FORCE_HTTPS && is_https()) header('Strict-Transport-Security: max-age=15552000');
}

function json_out(array $data, int $code = 200): void
{
    finish_and_run(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json; charset=utf-8', $code);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
    return is_array($d) ? $d : [];
}

/** Version des contenus : change à chaque enregistrement dans l'admin (caches navigateur). */
function cache_version(): string
{
    return meta_get('cache_v') ?? '1';
}

/** Numéro de version d'un fichier statique (pour forcer le rafraîchissement du cache). */
function asset(string $path): string
{
    $f = SWB_ROOT . '/' . ltrim($path, '/');
    $v = is_file($f) ? substr(md5((string) filemtime($f) . SWB_VERSION), 0, 8) : SWB_VERSION;
    return rel_url($path) . '?v=' . $v;
}
