<?php
/**
 * Socle commun du défi Impact (api.php, cron.php) : configuration, base SQLite,
 * questions et petites fonctions utilitaires. Aucune dépendance externe.
 */
declare(strict_types=1);

// Configuration locale facultative (SMTP, chemin de la base…) — jamais dans Git.
$cfg = dirname(__DIR__) . '/config.php';
if (is_file($cfg)) require $cfg;

// Valeurs par défaut : le site fonctionne sans config.php (envoi via mail()).
$defaults = [
    'IMPACT_SITE_URL'       => '',          // ex. https://dehini.com/impact/ — déduit de la requête si vide
    'IMPACT_DB_PATH'        => dirname(__DIR__, 3) . '/impact-data/impact.sqlite', // hors public_html
    'IMPACT_TIMEZONE'       => 'Africa/Algiers',
    'IMPACT_MAIL_FROM'      => '',          // ex. impact@dehini.com — par défaut impact@<domaine>
    'IMPACT_MAIL_FROM_NAME' => 'Défi Impact · dehini.com',
    'IMPACT_SMTP_HOST'      => '',          // vide = fonction mail() de PHP
    'IMPACT_SMTP_PORT'      => 465,
    'IMPACT_SMTP_SECURE'    => 'ssl',       // ssl (465) | tls (587, STARTTLS) | none
    'IMPACT_SMTP_USER'      => '',
    'IMPACT_SMTP_PASS'      => '',
    'IMPACT_SMTP_VERIFY'    => true,        // vérification du certificat TLS
    'IMPACT_MODERATION'     => 'auto',      // auto = publié tout de suite | manual = validé dans /impact/moderation.html
    'IMPACT_NOTIFY_EMAIL'   => '',          // reçoit un email quand un récit attend une validation
    'IMPACT_DAILY_HOUR'     => 9,           // heure locale à partir de laquelle part la question du jour
    'IMPACT_DIGEST_DAYS'    => 7,           // au plus un email « impacts positifs » tous les N jours
    'IMPACT_MAIL_BATCH'     => 80,          // emails max par passage du cron (limites de l'hébergeur)
];
foreach ($defaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
date_default_timezone_set(IMPACT_TIMEZONE);

const IMPACT_LANGS = ['fr', 'ar', 'en'];

function impact_lang($l): string
{
    return in_array($l, IMPACT_LANGS, true) ? $l : 'fr';
}

/** URL publique du dossier /impact/ (avec la barre finale). */
function site_url(): string
{
    if (IMPACT_SITE_URL !== '') return rtrim(IMPACT_SITE_URL, '/') . '/';
    if (PHP_SAPI === 'cli') return 'https://dehini.com/impact/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host  = $_SERVER['HTTP_HOST'] ?? 'dehini.com';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/impact/api.php')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $dir = dirname(IMPACT_DB_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de la base : ' . $dir);
    }
    $pdo = new PDO('sqlite:' . IMPACT_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

/**
 * Schéma — volontairement compatible avec les vieilles versions de SQLite des
 * hébergeurs mutualisés (pas d'UPSERT, de RETURNING ni de fonctions JSON).
 */
function migrate(PDO $pdo): void
{
    $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v >= 1) return;
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        pass TEXT NOT NULL,
        lang TEXT NOT NULL DEFAULT 'fr',
        created INTEGER NOT NULL,
        verified INTEGER NOT NULL DEFAULT 0,
        vtoken TEXT,
        utoken TEXT NOT NULL,
        opt_daily INTEGER NOT NULL DEFAULT 1,
        opt_digest INTEGER NOT NULL DEFAULT 1,
        last_daily TEXT,
        last_digest INTEGER NOT NULL DEFAULT 0,
        digest_check INTEGER NOT NULL DEFAULT 0,
        rhash TEXT,
        rexp INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS sessions (
        hash TEXT PRIMARY KEY,
        uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        csrf TEXT NOT NULL,
        exp INTEGER NOT NULL
    );
    -- pid = « u<id> » pour un compte, « d<appareil> » pour un visiteur sans compte.
    CREATE TABLE IF NOT EXISTS answers (
        pid TEXT NOT NULL,
        qid INTEGER NOT NULL,
        v INTEGER NOT NULL,
        t INTEGER NOT NULL,
        PRIMARY KEY (pid, qid)
    );
    CREATE INDEX IF NOT EXISTS answers_q ON answers(qid, v);
    CREATE TABLE IF NOT EXISTS stories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        qid INTEGER NOT NULL,
        uid INTEGER REFERENCES users(id) ON DELETE CASCADE,
        author TEXT NOT NULL DEFAULT '',
        exp TEXT NOT NULL,
        imp TEXT NOT NULL,
        lang TEXT NOT NULL,
        status TEXT NOT NULL,
        likes INTEGER NOT NULL DEFAULT 0,
        reports INTEGER NOT NULL DEFAULT 0,
        ehash TEXT NOT NULL,
        iph TEXT NOT NULL DEFAULT '',
        created INTEGER NOT NULL,
        updated INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS stories_q ON stories(status, qid);
    CREATE INDEX IF NOT EXISTS stories_t ON stories(status, created);
    CREATE TABLE IF NOT EXISTS digest_seen (
        uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        sid INTEGER NOT NULL,
        PRIMARY KEY (uid, sid)
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
    PRAGMA user_version = 1;
    SQL);
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

/** Sel propre à l'installation (hachage des IP, jamais stockées en clair). */
function install_salt(): string
{
    $s = meta_get('salt');
    if ($s === null) {
        $s = bin2hex(random_bytes(16));
        meta_set('salt', $s);
    }
    return $s;
}

function token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}

/** @return array<int, array{id:int,cat:string,fr:string,ar:string,en:string}> indexé par id */
function questions(): array
{
    static $q = null;
    if ($q !== null) return $q;
    $raw = json_decode((string) file_get_contents(dirname(__DIR__) . '/questions.json'), true) ?: [];
    $q = [];
    foreach ($raw as $item) $q[(int) $item['id']] = $item;
    return $q;
}

function str_clean(string $s, int $max): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
    $s = preg_replace("/\r\n?/", "\n", $s) ?? '';
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? '';
    return mb_substr($s, 0, $max);
}
