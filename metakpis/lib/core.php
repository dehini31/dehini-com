<?php
/**
 * Socle de Stats Meta KPIs (dehini.com/metakpis/) : configuration, base SQLite,
 * chiffrement des jetons Meta, requêtes HTTP sortantes et envoi d'emails.
 * Aucune dépendance externe.
 */
declare(strict_types=1);

// Configuration locale facultative — jamais dans Git (voir config.example.php).
$cfg = dirname(__DIR__) . '/config.php';
if (is_file($cfg)) require $cfg;
// Sans réglage SMTP propre, on réutilise le compte email du défi Impact s'il est configuré.
$impactCfg = dirname(__DIR__, 2) . '/impact/config.php';
if (!defined('METAKPIS_SMTP_HOST') && is_file($impactCfg)) {
    try { require_once $impactCfg; } catch (Throwable $e) { /* config Impact illisible : on s'en passe */ }
}

$defaults = [
    'METAKPIS_SITE_URL'      => '',          // ex. https://dehini.com/metakpis/ — déduit de la requête si vide
    'METAKPIS_DB_PATH'       => dirname(__DIR__, 3) . '/metakpis-data/metakpis.sqlite', // hors public_html
    'METAKPIS_TIMEZONE'      => 'Africa/Algiers',
    'METAKPIS_GRAPH_VERSION' => 'v24.0',     // version de l'API Marketing de Meta
    'METAKPIS_GRAPH_URL'     => 'https://graph.facebook.com/', // tests : une fausse API peut la remplacer
    'METAKPIS_GOOGLE_CERTS'  => 'https://www.googleapis.com/oauth2/v1/certs',
    'METAKPIS_MAIL_FROM'     => defined('IMPACT_MAIL_FROM') ? IMPACT_MAIL_FROM : '',
    'METAKPIS_MAIL_FROM_NAME' => 'Stats Meta KPIs · dehini.com',
    'METAKPIS_SMTP_HOST'     => defined('IMPACT_SMTP_HOST') ? IMPACT_SMTP_HOST : '',
    'METAKPIS_SMTP_PORT'     => defined('IMPACT_SMTP_PORT') ? IMPACT_SMTP_PORT : 465,
    'METAKPIS_SMTP_SECURE'   => defined('IMPACT_SMTP_SECURE') ? IMPACT_SMTP_SECURE : 'ssl',
    'METAKPIS_SMTP_USER'     => defined('IMPACT_SMTP_USER') ? IMPACT_SMTP_USER : '',
    'METAKPIS_SMTP_PASS'     => defined('IMPACT_SMTP_PASS') ? IMPACT_SMTP_PASS : '',
    'METAKPIS_SMTP_VERIFY'   => defined('IMPACT_SMTP_VERIFY') ? IMPACT_SMTP_VERIFY : true,
    'METAKPIS_IMPACT_DB'     => dirname(__DIR__, 3) . '/impact-data/impact.sqlite', // pour reprendre l'ID client Google du défi Impact
    'METAKPIS_ECOM_ENV'      => dirname(__DIR__, 3) . '/ecom/.env', // ecom.dehini.com : on reprend son app Meta (META_APP_ID / META_APP_SECRET)
];
foreach ($defaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
date_default_timezone_set(METAKPIS_TIMEZONE);

const SMK_LANGS = ['fr', 'en'];

function smk_lang($l): string
{
    return in_array($l, SMK_LANGS, true) ? $l : 'fr';
}

/** URL publique du dossier /metakpis/ (avec la barre finale). */
function site_url(): string
{
    if (METAKPIS_SITE_URL !== '') return rtrim(METAKPIS_SITE_URL, '/') . '/';
    if (PHP_SAPI === 'cli') return 'https://dehini.com/metakpis/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host  = $_SERVER['HTTP_HOST'] ?? 'dehini.com';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/metakpis/api.php')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname(METAKPIS_DB_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de la base : ' . $dir);
    }
    $pdo = new PDO('sqlite:' . METAKPIS_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

/** Schéma compatible avec les vieilles versions de SQLite des hébergeurs mutualisés. */
function migrate(PDO $pdo): void
{
    $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) {
        $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            pass TEXT NOT NULL DEFAULT '',
            google_sub TEXT,
            lang TEXT NOT NULL DEFAULT 'fr',
            company TEXT NOT NULL DEFAULT '',
            share INTEGER NOT NULL DEFAULT 1,
            created INTEGER NOT NULL,
            last_seen INTEGER NOT NULL DEFAULT 0,
            rhash TEXT,
            rexp INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS users_google ON users(google_sub);
        CREATE TABLE IF NOT EXISTS sessions (
            hash TEXT PRIMARY KEY,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf TEXT NOT NULL,
            exp INTEGER NOT NULL
        );
        -- Jetons de l'extension Chrome (un par navigateur connecté). Seul le hash est gardé.
        CREATE TABLE IF NOT EXISTS ext_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hash TEXT NOT NULL UNIQUE,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            label TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            last_used INTEGER NOT NULL DEFAULT 0,
            version TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS ext_tokens_u ON ext_tokens(uid);
        -- Connexion à Meta (Facebook Login) : jeton longue durée chiffré.
        CREATE TABLE IF NOT EXISTS meta_conn (
            uid INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            meta_id TEXT NOT NULL,
            meta_name TEXT NOT NULL DEFAULT '',
            token TEXT NOT NULL,
            expires INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS accounts (
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            act TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            currency TEXT NOT NULL DEFAULT '',
            updated INTEGER NOT NULL,
            PRIMARY KEY (uid, act)
        );
        -- Analyses partagées par l'extension (seulement si users.share = 1).
        CREATE TABLE IF NOT EXISTS scans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            act TEXT NOT NULL DEFAULT '',
            level TEXT NOT NULL,
            since TEXT NOT NULL DEFAULT '',
            until TEXT NOT NULL DEFAULT '',
            currency TEXT NOT NULL DEFAULT '',
            nrows INTEGER NOT NULL DEFAULT 0,
            spend REAL,
            totals TEXT NOT NULL DEFAULT '{}',
            counts TEXT NOT NULL DEFAULT '{}',
            data TEXT NOT NULL DEFAULT '[]',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS scans_u ON scans(uid, created);
        CREATE TABLE IF NOT EXISTS activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,
            act TEXT NOT NULL DEFAULT '',
            detail TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS activity_u ON activity(uid, created);
        -- Campagnes proposées par l'extension et créées sur Meta (en pause, puis publiées).
        CREATE TABLE IF NOT EXISTS proposals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            act TEXT NOT NULL,
            name TEXT NOT NULL,
            status TEXT NOT NULL,
            campaign_id TEXT NOT NULL DEFAULT '',
            adset_id TEXT NOT NULL DEFAULT '',
            ad_id TEXT NOT NULL DEFAULT '',
            creative_id TEXT NOT NULL DEFAULT '',
            budget REAL,
            currency TEXT NOT NULL DEFAULT '',
            payload TEXT NOT NULL DEFAULT '{}',
            error TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS proposals_u ON proposals(uid, created);
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
function meta_del(string $k): void
{
    db()->prepare('DELETE FROM meta WHERE k = ?')->execute([$k]);
}

/** Réglages modifiables depuis l'admin (table meta, préfixe « cfg. »). */
function setting(string $k): string
{
    return meta_get('cfg.' . $k) ?? '';
}
function setting_set(string $k, string $v): void
{
    meta_set('cfg.' . $k, $v);
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

function str_clean($s, int $max): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '');
    $s = preg_replace("/\r\n?/", "\n", $s) ?? '';
    return mb_substr($s, 0, $max);
}

/* ------------------------------------------------------------ chiffrement */

/**
 * Clé de chiffrement des jetons Meta : générée au premier passage dans le dossier
 * de la base (hors public_html), jamais dans Git ni dans la base elle-même.
 */
function secret_key(): string
{
    static $k = null;
    if ($k !== null) return $k;
    $f = dirname(METAKPIS_DB_PATH) . '/secret.key';
    $raw = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
        $raw = bin2hex(random_bytes(32));
        if (@file_put_contents($f, $raw, LOCK_EX) === false) throw new RuntimeException('secret.key illisible');
        @chmod($f, 0600);
    }
    return $k = hex2bin($raw);
}
function seal(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('chiffrement impossible');
    return base64_encode($iv . $tag . $c);
}
function unseal(string $box): ?string
{
    $raw = base64_decode($box, true);
    if ($raw === false || strlen($raw) < 29) return null;
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? null : $p;
}

/* ------------------------------------------------------------- HTTP sortant */

/**
 * Requête sortante (Google, Meta) : [code HTTP, corps, en-têtes en minuscules].
 * $post = null pour un GET, sinon tableau envoyé en application/x-www-form-urlencoded.
 */
function http_req(string $url, array $query = [], ?array $post = null, int $timeout = 30): array
{
    if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    $headers = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) $headers[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($line);
            },
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) throw new RuntimeException('HTTP : ' . curl_error($ch));
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body, $headers];
    }
    $http = ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"];
    if ($post !== null) {
        $http['method'] = 'POST';
        $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $http['content'] = http_build_query($post);
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    if ($body === false) throw new RuntimeException('HTTP : échec');
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) $code = (int) $m[1];
        elseif (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [$code, $body, $headers];
}

/* ------------------------------------------------------------------- email */

function mail_from(): string
{
    if (METAKPIS_MAIL_FROM !== '') return METAKPIS_MAIL_FROM;
    if (METAKPIS_SMTP_USER !== '' && strpos(METAKPIS_SMTP_USER, '@') !== false) return METAKPIS_SMTP_USER;
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'dehini.com';
    return 'metakpis@' . preg_replace('/^www\./', '', (string) $host);
}

function encode_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Envoie un email texte + HTML (SMTP si configuré, sinon mail()). Lève une exception en cas d'échec. */
function send_mail(string $to, string $subject, string $html, string $text): void
{
    $from = mail_from();
    $domain = substr(strrchr($from, '@') ?: '@dehini.com', 1);
    $b = 'b_' . token(12);
    $headers = implode("\r\n", [
        'From: ' . encode_header(METAKPIS_MAIL_FROM_NAME) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . token(16) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ]);
    $body = '--' . $b . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n--" . $b . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n--" . $b . "--\r\n";
    if (METAKPIS_SMTP_HOST === '') {
        if (!@mail($to, encode_header($subject), $body, $headers, '-f' . $from)) throw new RuntimeException('mail() a échoué');
        return;
    }
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool) METAKPIS_SMTP_VERIFY, 'verify_peer_name' => (bool) METAKPIS_SMTP_VERIFY,
        'allow_self_signed' => !METAKPIS_SMTP_VERIFY, 'peer_name' => METAKPIS_SMTP_HOST,
    ]]);
    $secure = METAKPIS_SMTP_SECURE;
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . METAKPIS_SMTP_HOST . ':' . (int) METAKPIS_SMTP_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
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
        if (METAKPIS_SMTP_USER !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(METAKPIS_SMTP_USER), [334]);
            $cmd(base64_encode(METAKPIS_SMTP_PASS), [235]);
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
