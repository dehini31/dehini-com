<?php
/**
 * API de Stats Meta KPIs — dehini.com/metakpis/
 *
 * Réponses JSON ({ok:true,…} ou {error:"code"}) ; les codes sont traduits côté page.
 * Toute écriture (POST) exige l'en-tête X-Requested-With: metakpis.
 * Deux façons d'être connecté :
 *   - le site : cookie de session + en-tête X-CSRF ;
 *   - l'extension Chrome : en-tête Authorization: Bearer <jeton de l'extension>.
 *
 * Site     GET  me | profile
 *          POST signup {name,email,password,lang} | login {email,password} | google {credential,lang}
 *          POST logout | forgot {email,lang} | reset {token,password}
 *          POST prefs {name,company,lang,share} | ext_token {label} | ext_revoke {id}
 *          POST meta_disconnect | delete_shared | delete_account {password|confirm}
 * Les deux GET  whoami
 *          POST scan {act,level,since,until,currency,totals,counts,rows}  (enregistré seulement si partage activé)
 *          POST accounts | export {act,since,until,level,parts} | source {act,ad_id}
 *          POST create {act,source_ad_id,campaign,adset,ad} | proposal_status {id,status}
 * Admin    GET  adm_overview | adm_settings   POST adm_settings_save | adm_user_delete {id}
 *          (réservé à la session de /admin, comme le défi Impact)
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/google.php';
require __DIR__ . '/lib/graph.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

const SID_COOKIE = 'smk_sid';
const SESSION_DAYS = 60;
const EXT_VERSION = '2.0.0';

$action = (string) ($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

function out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function ok(array $d = []): void
{
    out(['ok' => true] + $d);
}
function fail(string $code, int $http = 400): void
{
    out(['error' => $code], $http);
}

try {
    db();
} catch (Throwable $e) {
    error_log('metakpis db: ' . $e->getMessage());
    fail('db', 500);
}

if ($isPost && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'metakpis') fail('generic', 403);

function input_json(): array
{
    static $d = null;
    if ($d !== null) return $d;
    $raw = file_get_contents('php://input');
    $d = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
    if (!is_array($d)) $d = [];
    return $d;
}
function ip_hash(): string
{
    return substr(hash('sha256', install_salt() . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 32);
}
/** Au plus $max actions « $key » par fenêtre de $window secondes (par IP, ou par compte si $who). */
function rate(string $key, int $max, int $window, string $who = ''): void
{
    $k = $key . '|' . ($who !== '' ? $who : ip_hash());
    $now = time();
    $pdo = db();
    $s = $pdo->prepare('SELECT n, reset FROM rate WHERE k = ?');
    $s->execute([$k]);
    $row = $s->fetch();
    if (!$row || (int) $row['reset'] < $now) {
        $pdo->prepare('INSERT OR REPLACE INTO rate (k, n, reset) VALUES (?, 1, ?)')->execute([$k, $now + $window]);
    } else {
        if ((int) $row['n'] >= $max) fail('rate', 429);
        $pdo->prepare('UPDATE rate SET n = n + 1 WHERE k = ?')->execute([$k]);
    }
    if (random_int(1, 100) === 1) $pdo->prepare('DELETE FROM rate WHERE reset < ?')->execute([$now]);
}

/* --------------------------------------------------------------- sessions */

function cookie_opts(int $expires): array
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/metakpis/api.php')), '/') . '/';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ['expires' => $expires, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
}

/** Compte connecté par le cookie du site (ou null). */
function site_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $raw = (string) ($_COOKIE[SID_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return null;
    $hash = hash('sha256', $raw);
    $s = db()->prepare('SELECT u.*, s.csrf AS s_csrf, s.exp AS s_exp FROM sessions s JOIN users u ON u.id = s.uid WHERE s.hash = ? AND s.exp > ?');
    $s->execute([$hash, time()]);
    $u = $s->fetch();
    if (!$u) return null;
    $fresh = time() + SESSION_DAYS * 86400;
    if ((int) $u['s_exp'] < $fresh - 86400) {
        db()->prepare('UPDATE sessions SET exp = ? WHERE hash = ?')->execute([$fresh, $hash]);
        setcookie(SID_COOKIE, $raw, cookie_opts($fresh));
    }
    return $cache = $u;
}

/** Compte connecté par le jeton de l'extension (ou null). */
function ext_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower((string) $k) === 'authorization') $h = (string) $v;
        }
    }
    if ($h === '') $h = 'Bearer ' . (string) ($_SERVER['HTTP_X_SMK_TOKEN'] ?? ''); // hébergeurs qui retirent Authorization
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/', trim($h), $m)) return null;
    $s = db()->prepare('SELECT u.*, t.id AS t_id, t.last_used AS t_used FROM ext_tokens t JOIN users u ON u.id = t.uid WHERE t.hash = ?');
    $s->execute([hash('sha256', $m[1])]);
    $u = $s->fetch();
    if (!$u) return null;
    if ((int) $u['t_used'] < time() - 300) {
        $ver = substr(preg_replace('/[^0-9.]/', '', (string) ($_SERVER['HTTP_X_SMK_VERSION'] ?? '')), 0, 12);
        db()->prepare('UPDATE ext_tokens SET last_used = ?, version = ? WHERE id = ?')->execute([time(), $ver, $u['t_id']]);
    }
    return $cache = $u;
}

function start_session(int $uid): string
{
    $raw = token(24);
    $csrf = token(16);
    $exp = time() + SESSION_DAYS * 86400;
    db()->prepare('INSERT INTO sessions (hash, uid, csrf, exp) VALUES (?, ?, ?, ?)')->execute([hash('sha256', $raw), $uid, $csrf, $exp]);
    db()->prepare('DELETE FROM sessions WHERE exp < ?')->execute([time()]);
    setcookie(SID_COOKIE, $raw, cookie_opts($exp));
    return $csrf;
}
function end_session(): void
{
    $raw = (string) ($_COOKIE[SID_COOKIE] ?? '');
    if ($raw !== '') db()->prepare('DELETE FROM sessions WHERE hash = ?')->execute([hash('sha256', $raw)]);
    setcookie(SID_COOKIE, '', cookie_opts(time() - 3600));
}

/** Compte du site, avec vérification du jeton CSRF pour une écriture. */
function require_site_user(): array
{
    global $isPost;
    $u = site_user();
    if (!$u) fail('auth', 401);
    if ($isPost && !hash_equals((string) $u['s_csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('auth', 401);
    return $u;
}
/** Extension (jeton) ou site (cookie + CSRF). */
function require_any_user(): array
{
    $u = ext_user();
    if ($u) return $u;
    return require_site_user();
}
function touch_user(array $u): void
{
    if ((int) $u['last_seen'] < time() - 600) db()->prepare('UPDATE users SET last_seen = ? WHERE id = ?')->execute([time(), $u['id']]);
}
function user_row(int $uid): array
{
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$uid]);
    return $s->fetch() ?: [];
}
function log_activity(int $uid, string $kind, string $act = '', string $detail = ''): void
{
    db()->prepare('INSERT INTO activity (uid, kind, act, detail, created) VALUES (?, ?, ?, ?, ?)')->execute([$uid, $kind, $act, mb_substr($detail, 0, 300), time()]);
}

function public_user(array $u): array
{
    return [
        'id' => (int) $u['id'], 'name' => (string) $u['name'], 'email' => (string) $u['email'], 'lang' => (string) $u['lang'],
        'company' => (string) $u['company'], 'share' => (bool) $u['share'], 'google' => !empty($u['google_sub']),
        'has_pass' => (string) $u['pass'] !== '', 'created' => (int) $u['created'],
    ];
}
function meta_view(int $uid): array
{
    $c = meta_conn($uid);
    return ['ready' => meta_ready(), 'connected' => (bool) $c, 'name' => $c ? (string) $c['meta_name'] : '',
        'expires' => $c ? (int) $c['expires'] : 0, 'since' => $c ? (int) $c['created'] : 0];
}
function signed_in(array $u, string $csrf, array $extra = []): void
{
    ok(['user' => public_user($u), 'csrf' => $csrf] + $extra);
}

/* ---------------------------------------------------------- outils divers */

function num_or_null($v, float $max = 1e13): ?float
{
    if (!is_int($v) && !is_float($v)) return null;
    $f = (float) $v;
    return is_finite($f) && abs($f) <= $max ? round($f, 4) : null;
}
function clean_numbers($a, array $keys): array
{
    $o = [];
    foreach ($keys as $k) $o[$k] = num_or_null(is_array($a) ? ($a[$k] ?? null) : null);
    return $o;
}
const TOTAL_KEYS = ['spend', 'impressions', 'linkClicks', 'purchases', 'value', 'cpa', 'roas', 'ctrLink', 'cpcLink', 'cpm', 'freqMax', 'lpvRate', 'hook', 'hold', 'waste', 'wastePct'];
const ROW_KEYS = ['spend', 'impressions', 'reach', 'frequency', 'cpm', 'linkClicks', 'ctrLink', 'cpcLink', 'purchases', 'cpa', 'value', 'roas', 'lpvRate', 'hook', 'hold'];

function scan_view(array $s, bool $withRows): array
{
    $v = [
        'id' => (int) $s['id'], 'act' => (string) $s['act'], 'level' => (string) $s['level'], 'since' => (string) $s['since'],
        'until' => (string) $s['until'], 'currency' => (string) $s['currency'], 'rows' => (int) $s['nrows'],
        'totals' => json_decode((string) $s['totals'], true) ?: new stdClass(), 'counts' => json_decode((string) $s['counts'], true) ?: new stdClass(),
        'created' => (int) $s['created'], 'account' => (string) ($s['account_name'] ?? ''),
    ];
    if ($withRows) {
        $rows = json_decode((string) $s['data'], true) ?: [];
        $win = array_values(array_filter($rows, function ($r) { return ($r['v'] ?? '') === 'win'; }));
        usort($win, function ($a, $b) { return ($b['roas'] ?? -1) <=> ($a['roas'] ?? -1) ?: ($a['cpa'] ?? 1e12) <=> ($b['cpa'] ?? 1e12); });
        $lose = array_values(array_filter($rows, function ($r) { return ($r['v'] ?? '') === 'lose'; }));
        usort($lose, function ($a, $b) { return ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0); });
        $v['best'] = array_slice($win, 0, 5);
        $v['worst'] = array_slice($lose, 0, 5);
    }
    return $v;
}
function proposal_view(array $p): array
{
    $pl = json_decode((string) $p['payload'], true) ?: [];
    return [
        'id' => (int) $p['id'], 'act' => (string) $p['act'], 'name' => (string) $p['name'], 'status' => (string) $p['status'],
        'campaign_id' => (string) $p['campaign_id'], 'adset_id' => (string) $p['adset_id'], 'ad_id' => (string) $p['ad_id'],
        'budget' => $p['budget'] === null ? null : (float) $p['budget'], 'currency' => (string) $p['currency'],
        'error' => (string) $p['error'], 'created' => (int) $p['created'], 'updated' => (int) $p['updated'],
        'url' => $p['campaign_id'] !== '' ? ads_manager_url((string) $p['act'], (string) $p['campaign_id']) : '',
        'summary' => $pl['summary'] ?? new stdClass(),
    ];
}

function require_admin(): void
{
    global $isPost;
    session_name('dehini_admin');
    session_start();
    $okAdmin = !empty($_SESSION['admin']) && !empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) < 12 * 3600;
    if (!$okAdmin) fail('auth', 401);
    if ($isPost && (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? '')))) fail('auth', 403);
    session_write_close();
}

/* ============================================================== actions */

$b = $isPost ? input_json() : [];
$pdo = db();

switch ($action) {

    /* ------------------------------------------------------------ site */

    case 'me': {
        $u = site_user();
        if ($u) touch_user($u);
        ok(['user' => $u ? public_user($u) : null, 'csrf' => $u ? $u['s_csrf'] : null,
            'config' => ['google' => google_client_id() ?: null, 'meta' => meta_ready(), 'ext_version' => EXT_VERSION]]);
        break;
    }

    case 'signup': {
        if (!$isPost) fail('generic', 405);
        rate('signup', 8, 3600);
        $name = str_clean($b['name'] ?? '', 60);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $pass = (string) ($b['password'] ?? '');
        if ($name === '') fail('bad_name');
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email');
        if (strlen($pass) < 8 || strlen($pass) > 200) fail('weak_pass');
        $s = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $s->execute([$email]);
        if ($s->fetchColumn()) fail('email_taken', 409);
        $pdo->prepare('INSERT INTO users (email, name, pass, lang, created, last_seen) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), smk_lang($b['lang'] ?? 'fr'), time(), time()]);
        $uid = (int) $pdo->lastInsertId();
        log_activity($uid, 'signup', '', 'email');
        signed_in(user_row($uid), start_session($uid), ['created' => true]);
        break;
    }

    case 'login': {
        if (!$isPost) fail('generic', 405);
        rate('login', 12, 900);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $s = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $s->execute([$email]);
        $u = $s->fetch();
        if ($u && (string) $u['pass'] === '' && $u['google_sub']) fail('use_google', 401);
        if (!$u || !password_verify((string) ($b['password'] ?? ''), (string) $u['pass'])) {
            usleep(350000);
            fail('bad_login', 401);
        }
        if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash((string) $b['password'], PASSWORD_DEFAULT), $u['id']]);
        }
        $pdo->prepare('UPDATE users SET last_seen = ? WHERE id = ?')->execute([time(), $u['id']]);
        signed_in($u, start_session((int) $u['id']));
        break;
    }

    case 'google': {
        if (!$isPost) fail('generic', 405);
        rate('google', 20, 900);
        $cid = google_client_id();
        if ($cid === '') fail('google_off');
        $c = google_verify((string) ($b['credential'] ?? ''), $cid);
        if (!$c) fail('google_bad', 401);
        $sub = (string) $c['sub'];
        $email = strtolower(trim((string) $c['email']));
        $created = false;
        $s = $pdo->prepare('SELECT * FROM users WHERE google_sub = ?');
        $s->execute([$sub]);
        $u = $s->fetch();
        if (!$u) {
            $s = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $s->execute([$email]);
            $u = $s->fetch();
            if ($u) {
                $pdo->prepare('UPDATE users SET google_sub = ? WHERE id = ?')->execute([$sub, $u['id']]);
            } else {
                if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email');
                $name = str_clean($c['name'] ?? ($c['given_name'] ?? ''), 60);
                if ($name === '') $name = str_clean(strstr($email, '@', true), 60);
                $pdo->prepare("INSERT INTO users (email, name, pass, google_sub, lang, created, last_seen) VALUES (?, ?, '', ?, ?, ?, ?)")
                    ->execute([$email, $name, $sub, smk_lang($b['lang'] ?? 'fr'), time(), time()]);
                $u = ['id' => (int) $pdo->lastInsertId()];
                $created = true;
                log_activity((int) $u['id'], 'signup', '', 'google');
            }
            $u = user_row((int) $u['id']);
        }
        $pdo->prepare('UPDATE users SET last_seen = ? WHERE id = ?')->execute([time(), $u['id']]);
        signed_in($u, start_session((int) $u['id']), ['created' => $created]);
        break;
    }

    case 'logout': {
        if (!$isPost) fail('generic', 405);
        end_session();
        ok();
        break;
    }

    case 'forgot': {
        if (!$isPost) fail('generic', 405);
        rate('forgot', 5, 3600);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $s = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $s->execute([$email]);
        $u = $s->fetch();
        if ($u) {
            $raw = token();
            $pdo->prepare('UPDATE users SET rhash = ?, rexp = ? WHERE id = ?')->execute([hash('sha256', $raw), time() + 3600, $u['id']]);
            $l = smk_lang($b['lang'] ?? $u['lang']);
            $href = site_url() . '?lang=' . $l . '&reset=' . rawurlencode($raw) . '#login';
            $fr = $l === 'fr';
            $subj = $fr ? 'Stats Meta KPIs — choisir un nouveau mot de passe' : 'Stats Meta KPIs — choose a new password';
            $line = $fr ? 'Pour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure) :' : 'To choose a new password, open this link (valid for 1 hour):';
            $ign = $fr ? "Si vous n'avez rien demandé, ignorez cet email." : "If you didn't ask for this, ignore this email.";
            $btn = $fr ? 'Choisir un mot de passe' : 'Choose a password';
            $html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#101828;max-width:520px;margin:auto;padding:24px">'
                . '<p style="font-weight:700;font-size:18px;margin:0 0 16px">Stats Meta KPIs</p><p>' . htmlspecialchars($line) . '</p>'
                . '<p><a href="' . htmlspecialchars($href) . '" style="display:inline-block;background:#0B1B33;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;font-weight:700">' . $btn . '</a></p>'
                . '<p style="color:#667085;font-size:13px">' . htmlspecialchars($ign) . '</p></div>';
            try {
                send_mail((string) $u['email'], $subj, $html, $line . "\n" . $href . "\n\n" . $ign);
            } catch (Throwable $e) {
                error_log('metakpis reset mail: ' . $e->getMessage());
            }
        }
        ok(); // même réponse que le compte existe ou non
        break;
    }

    case 'reset': {
        if (!$isPost) fail('generic', 405);
        rate('reset', 10, 3600);
        $tok = (string) ($b['token'] ?? '');
        $pass = (string) ($b['password'] ?? '');
        $s = $pdo->prepare('SELECT * FROM users WHERE rhash = ? AND rexp > ?');
        $s->execute([hash('sha256', $tok), time()]);
        $u = $s->fetch();
        if (!$u || $tok === '') fail('bad_token');
        if (strlen($pass) < 8 || strlen($pass) > 200) fail('weak_pass');
        $uid = (int) $u['id'];
        $pdo->prepare('UPDATE users SET pass = ?, rhash = NULL, rexp = 0 WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
        $pdo->prepare('DELETE FROM sessions WHERE uid = ?')->execute([$uid]);
        signed_in(user_row($uid), start_session($uid));
        break;
    }

    case 'profile': {
        $u = require_site_user();
        touch_user($u);
        $uid = (int) $u['id'];
        $s = $pdo->prepare('SELECT id, label, created, last_used, version FROM ext_tokens WHERE uid = ? ORDER BY last_used DESC, created DESC');
        $s->execute([$uid]);
        $tokens = array_map(function ($t) {
            return ['id' => (int) $t['id'], 'label' => (string) $t['label'], 'created' => (int) $t['created'], 'last_used' => (int) $t['last_used'], 'version' => (string) $t['version']];
        }, $s->fetchAll());
        // Dernière analyse par compte publicitaire et par niveau (90 derniers jours).
        $s = $pdo->prepare('SELECT s.*, a.name AS account_name FROM scans s LEFT JOIN accounts a ON a.uid = s.uid AND a.act = s.act
            WHERE s.uid = ? AND s.created > ? AND s.id IN (SELECT MAX(id) FROM scans WHERE uid = ? GROUP BY act, level) ORDER BY s.created DESC LIMIT 24');
        $s->execute([$uid, time() - 90 * 86400, $uid]);
        $scans = array_map(function ($r) { return scan_view($r, true); }, $s->fetchAll());
        $s = $pdo->prepare('SELECT * FROM proposals WHERE uid = ? ORDER BY created DESC LIMIT 30');
        $s->execute([$uid]);
        $props = array_map('proposal_view', $s->fetchAll());
        $s = $pdo->prepare('SELECT kind, act, detail, created FROM activity WHERE uid = ? ORDER BY id DESC LIMIT 20');
        $s->execute([$uid]);
        $activity = $s->fetchAll();
        $count = function (string $sql) use ($pdo, $uid): int {
            $q = $pdo->prepare($sql);
            $q->execute([$uid]);
            return (int) $q->fetchColumn();
        };
        $s = $pdo->prepare('SELECT act, name, currency FROM accounts WHERE uid = ? ORDER BY updated DESC');
        $s->execute([$uid]);
        ok([
            'user' => public_user($u), 'meta' => meta_view($uid), 'tokens' => $tokens, 'scans' => $scans, 'proposals' => $props,
            'activity' => $activity, 'accounts' => $s->fetchAll(),
            'stats' => [
                'scans' => $count('SELECT COUNT(*) FROM scans WHERE uid = ?'),
                'exports' => $count("SELECT COUNT(*) FROM activity WHERE uid = ? AND kind = 'export'"),
                'proposals' => $count("SELECT COUNT(*) FROM proposals WHERE uid = ? AND status IN ('paused','active')"),
                'accounts' => $count('SELECT COUNT(DISTINCT act) FROM scans WHERE uid = ?'),
            ],
        ]);
        break;
    }

    case 'prefs': {
        $u = require_site_user();
        $sets = [];
        $vals = [];
        if (isset($b['name'])) {
            $n = str_clean($b['name'], 60);
            if ($n === '') fail('bad_name');
            $sets[] = 'name = ?';
            $vals[] = $n;
        }
        if (isset($b['company'])) { $sets[] = 'company = ?'; $vals[] = str_clean($b['company'], 80); }
        if (isset($b['lang'])) { $sets[] = 'lang = ?'; $vals[] = smk_lang($b['lang']); }
        if (array_key_exists('share', $b)) { $sets[] = 'share = ?'; $vals[] = empty($b['share']) ? 0 : 1; }
        if ($sets) {
            $vals[] = $u['id'];
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }
        ok(['user' => public_user(user_row((int) $u['id']))]);
        break;
    }

    case 'ext_token': {
        $u = require_site_user();
        rate('ext_token', 20, 3600, 'u' . $u['id']);
        $raw = token(32);
        $label = str_clean($b['label'] ?? '', 80);
        $pdo->prepare('INSERT INTO ext_tokens (hash, uid, label, created, last_used) VALUES (?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $raw), $u['id'], $label !== '' ? $label : 'Chrome', time(), time()]);
        // Au plus 10 navigateurs connectés : les plus anciens sont déconnectés.
        $pdo->prepare('DELETE FROM ext_tokens WHERE uid = ? AND id NOT IN (SELECT id FROM ext_tokens WHERE uid = ? ORDER BY last_used DESC, id DESC LIMIT 10)')
            ->execute([$u['id'], $u['id']]);
        log_activity((int) $u['id'], 'ext_connect');
        ok(['token' => $raw, 'user' => public_user($u)]);
        break;
    }

    case 'ext_revoke': {
        $u = require_site_user();
        $pdo->prepare('DELETE FROM ext_tokens WHERE id = ? AND uid = ?')->execute([(int) ($b['id'] ?? 0), $u['id']]);
        ok();
        break;
    }

    case 'meta_disconnect': {
        $u = require_site_user();
        $c = meta_conn((int) $u['id']);
        if ($c && meta_ready()) {
            $t = unseal((string) $c['token']);
            if ($t) {
                // Retire aussi l'autorisation côté Meta (l'app disparaît des « Intégrations professionnelles »).
                try { graph('POST', 'me/permissions', ['method' => 'delete'], $t); } catch (Throwable $e) { }
            }
        }
        $pdo->prepare('DELETE FROM meta_conn WHERE uid = ?')->execute([$u['id']]);
        ok();
        break;
    }

    case 'delete_shared': {
        $u = require_site_user();
        $pdo->prepare('DELETE FROM scans WHERE uid = ?')->execute([$u['id']]);
        ok();
        break;
    }

    case 'delete_account': {
        $u = require_site_user();
        $okDel = (string) $u['pass'] === '' ? !empty($b['confirm']) : password_verify((string) ($b['password'] ?? ''), (string) $u['pass']);
        if (!$okDel) {
            usleep(350000);
            fail('bad_login', 401);
        }
        // sessions, jetons, connexion Meta, analyses, campagnes, activité : ON DELETE CASCADE
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
        setcookie(SID_COOKIE, '', cookie_opts(time() - 3600));
        ok();
        break;
    }

    /* ------------------------------------------------------- extension */

    case 'whoami': {
        $u = require_any_user();
        touch_user($u);
        ok(['user' => public_user($u), 'meta' => meta_view((int) $u['id']), 'site' => site_url(), 'ext_version' => EXT_VERSION]);
        break;
    }

    case 'ext_logout': {
        // Bouton « Déconnecter cette extension » de la popup : le jeton est supprimé côté serveur.
        if (!$isPost) fail('generic', 405);
        $u = ext_user();
        if ($u) $pdo->prepare('DELETE FROM ext_tokens WHERE id = ?')->execute([$u['t_id']]);
        ok();
        break;
    }

    case 'scan': {
        if (!$isPost) fail('generic', 405);
        $u = require_any_user();
        $uid = (int) $u['id'];
        if (!(int) $u['share']) ok(['stored' => false]);
        rate('scan', 120, 3600, 'u' . $uid);
        $level = in_array($b['level'] ?? '', ['campaign', 'adset', 'ad'], true) ? (string) $b['level'] : fail('bad_level');
        $act = preg_match('/^\d{3,20}$/', (string) ($b['act'] ?? '')) ? (string) $b['act'] : '';
        $date = function ($d): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) ? (string) $d : ''; };
        $rows = [];
        foreach (array_slice(is_array($b['rows'] ?? null) ? $b['rows'] : [], 0, 500) as $r) {
            if (!is_array($r)) continue;
            $rows[] = ['id' => substr(preg_replace('/[^0-9A-Za-z_]/', '', (string) ($r['id'] ?? '')), 0, 30), 'name' => str_clean($r['name'] ?? '', 200),
                'v' => in_array($r['v'] ?? '', ['win', 'opt', 'lose', 'ins'], true) ? $r['v'] : 'ins'] + clean_numbers($r, ROW_KEYS);
        }
        $totals = clean_numbers($b['totals'] ?? [], TOTAL_KEYS);
        $counts = [];
        foreach (['win', 'opt', 'lose', 'ins'] as $k) $counts[$k] = max(0, (int) (($b['counts'] ?? [])[$k] ?? 0));
        $since = $date($b['since'] ?? '');
        $until = $date($b['until'] ?? '');
        // Une même analyse relancée remplace la précédente (même compte, niveau et période, moins d'une heure).
        $pdo->prepare('DELETE FROM scans WHERE uid = ? AND act = ? AND level = ? AND since = ? AND until = ? AND created > ?')
            ->execute([$uid, $act, $level, $since, $until, time() - 3600]);
        $pdo->prepare('INSERT INTO scans (uid, act, level, since, until, currency, nrows, spend, totals, counts, data, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $act, $level, $since, $until, str_clean($b['currency'] ?? '', 8), count($rows), $totals['spend'],
                json_encode($totals), json_encode($counts), json_encode($rows, JSON_UNESCAPED_UNICODE), time()]);
        // On garde les 300 dernières analyses par compte utilisateur.
        $pdo->prepare('DELETE FROM scans WHERE uid = ? AND id NOT IN (SELECT id FROM scans WHERE uid = ? ORDER BY id DESC LIMIT 300)')->execute([$uid, $uid]);
        ok(['stored' => true]);
        break;
    }

    case 'accounts': {
        $u = require_any_user();
        $uid = (int) $u['id'];
        $tok = meta_token($uid);
        try {
            $rows = graph_all('me/adaccounts', ['fields' => 'account_id,name,currency,account_status', 'limit' => 200], $tok, 600);
        } catch (GraphError $e) {
            graph_fail($e, $uid);
        }
        $ins = $pdo->prepare('INSERT OR REPLACE INTO accounts (uid, act, name, currency, updated) VALUES (?, ?, ?, ?, ?)');
        $list = [];
        foreach ($rows as $r) {
            $id = (string) ($r['account_id'] ?? '');
            if ($id === '') continue;
            $ins->execute([$uid, $id, str_clean($r['name'] ?? '', 120), (string) ($r['currency'] ?? ''), time()]);
            $list[] = ['id' => $id, 'name' => (string) ($r['name'] ?? ''), 'currency' => (string) ($r['currency'] ?? ''), 'status' => (int) ($r['account_status'] ?? 0)];
        }
        ok(['accounts' => $list]);
        break;
    }

    case 'export': {
        if (!$isPost) fail('generic', 405);
        $u = require_any_user();
        $uid = (int) $u['id'];
        rate('export', 40, 3600, 'u' . $uid);
        $act = valid_act($b['act'] ?? '');
        $since = valid_date($b['since'] ?? '');
        $until = valid_date($b['until'] ?? '');
        if ($since > $until) fail('bad_date');
        $level = in_array($b['level'] ?? '', ['account', 'campaign', 'adset', 'ad'], true) ? (string) $b['level'] : 'campaign';
        $parts = array_values(array_intersect(['age', 'gender', 'placement', 'copy'], (array) ($b['parts'] ?? [])));
        if (!$parts) fail('bad_parts');
        $tok = meta_token($uid);
        @set_time_limit(180);
        $res = [];
        $warnings = [];
        try {
            $acc = account_info($uid, $act, $tok);
            $bd = ['age' => 'age', 'gender' => 'gender', 'placement' => 'publisher_platform,platform_position'];
            foreach ($parts as $p) {
                if ($p !== 'copy') {
                    $res[$p] = insights($act, $tok, $level, $since, $until, $bd[$p], 4000);
                    continue;
                }
                // Textes : les publicités qui ont dépensé sur la période, avec leurs KPI.
                $ads = array_values(array_filter(insights($act, $tok, 'ad', $since, $until, '', 2000), function ($r) { return $r['spend'] > 0 || $r['impressions'] > 0; }));
                $ads = array_slice($ads, 0, 300);
                // Les KPI des publicités sont déjà là : si Meta refuse la lecture des textes, l'export continue sans eux.
                $det = [];
                try {
                    $det = ads_details(array_column($ads, 'ad_id'), $tok);
                } catch (GraphError $e) {
                    if (in_array((int) ($e->err['code'] ?? 0), [4, 17, 32, 190, 613, 80000, 80004], true)) throw $e;
                    $warnings[] = 'copy: ' . $e->userMessage();
                }
                foreach ($ads as &$r) {
                    $d = $det[$r['ad_id']] ?? [];
                    $tx = creative_texts(is_array($d['creative'] ?? null) ? $d['creative'] : []);
                    $r['status'] = (string) ($d['effective_status'] ?? '');
                    $r['objective'] = (string) ($d['campaign']['objective'] ?? '');
                    $r['creative_id'] = (string) ($d['creative']['id'] ?? '');
                    $r['text'] = $tx;
                }
                unset($r);
                $res['copy'] = $ads;
            }
        } catch (GraphError $e) {
            graph_fail($e, $uid);
        }
        log_activity($uid, 'export', $act, implode(',', $parts) . ' · ' . $level . ' · ' . $since . ' → ' . $until);
        ok(['account' => $acc, 'since' => $since, 'until' => $until, 'level' => $level, 'parts' => $res, 'warnings' => $warnings]);
        break;
    }

    case 'source': {
        if (!$isPost) fail('generic', 405);
        $u = require_any_user();
        $uid = (int) $u['id'];
        $act = valid_act($b['act'] ?? '');
        $adId = valid_id($b['ad_id'] ?? '');
        $tok = meta_token($uid);
        try {
            $acc = account_info($uid, $act, $tok);
            $src = source_ad($adId, $tok);
        } catch (GraphError $e) {
            graph_fail($e, $uid);
        }
        if ((string) ($src['account_id'] ?? '') !== $act) fail('bad_id');
        $obj = (string) ($src['campaign']['objective'] ?? '');
        ok([
            'account' => $acc,
            'ad' => ['id' => $adId, 'name' => (string) ($src['name'] ?? '')],
            'campaign' => ['id' => (string) ($src['campaign']['id'] ?? ''), 'name' => (string) ($src['campaign']['name'] ?? ''),
                'objective' => OBJECTIVE_MAP[$obj] ?? $obj, 'smart' => (string) ($src['campaign']['smart_promotion_type'] ?? '')],
            'adset' => ['id' => (string) ($src['adset']['id'] ?? ''), 'name' => (string) ($src['adset']['name'] ?? ''),
                'optimization_goal' => (string) ($src['adset']['optimization_goal'] ?? ''),
                'targeting' => targeting_summary(is_array($src['adset']['targeting'] ?? null) ? $src['adset']['targeting'] : [])],
            'creative' => creative_texts(is_array($src['creative'] ?? null) ? $src['creative'] : []) + ['id' => (string) ($src['creative']['id'] ?? '')],
        ]);
        break;
    }

    case 'create': {
        if (!$isPost) fail('generic', 405);
        $u = require_any_user();
        $uid = (int) $u['id'];
        rate('create', 20, 3600, 'u' . $uid);
        $act = valid_act($b['act'] ?? '');
        $adId = valid_id($b['source_ad_id'] ?? '');
        $c = is_array($b['campaign'] ?? null) ? $b['campaign'] : [];
        $as = is_array($b['adset'] ?? null) ? $b['adset'] : [];
        $ad = is_array($b['ad'] ?? null) ? $b['ad'] : [];
        $cname = str_clean($c['name'] ?? '', 250);
        $budget = is_numeric($c['daily_budget'] ?? null) ? (float) $c['daily_budget'] : 0.0;
        if ($cname === '') fail('bad_name');
        if (!($budget > 0) || $budget > 1e9) fail('bad_budget');
        $tok = meta_token($uid);
        try {
            $acc = account_info($uid, $act, $tok);
            $src = source_ad($adId, $tok);
        } catch (GraphError $e) {
            graph_fail($e, $uid);
        }
        if ((string) ($src['account_id'] ?? '') !== $act) fail('bad_id');
        $sAdset = is_array($src['adset'] ?? null) ? $src['adset'] : [];
        $sCamp = is_array($src['campaign'] ?? null) ? $src['campaign'] : [];
        $sCr = is_array($src['creative'] ?? null) ? $src['creative'] : [];
        $obj = (string) ($sCamp['objective'] ?? '');
        $obj = OBJECTIVE_MAP[$obj] ?? $obj;
        if ($obj === '') fail('bad_source');
        $targeting = build_targeting(is_array($sAdset['targeting'] ?? null) ? $sAdset['targeting'] : [], $as);
        $texts = creative_texts($sCr);
        $spec = $texts['editable'] ? build_story_spec(is_array($sCr['object_story_spec'] ?? null) ? $sCr['object_story_spec'] : [], $ad) : null;
        $summary = [
            'objective' => $obj, 'source_ad' => (string) ($src['name'] ?? ''), 'age_min' => $targeting['age_min'], 'age_max' => $targeting['age_max'],
            'age_range' => $targeting['age_range'] ?? null, 'genders' => $targeting['genders'] ?? [], 'advantage_audience' => !empty($as['advantage_audience']),
            'placements' => empty($targeting['publisher_platforms']) ? 'auto' : array_merge(...array_map(function ($p) use ($targeting) {
                return array_map(function ($x) use ($p) { return $p . ':' . $x; }, $targeting[$p . '_positions'] ?? []);
            }, $targeting['publisher_platforms'])),
            'texts_changed' => (bool) $spec, 'primary_text' => mb_substr(str_clean($ad['primary_text'] ?? '', 5000), 0, 300), 'title' => str_clean($ad['title'] ?? '', 255),
        ];
        $pdo->prepare('INSERT INTO proposals (uid, act, name, status, budget, currency, payload, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $act, $cname, 'creating', $budget, $acc['currency'], json_encode(['summary' => $summary, 'input' => $b], JSON_UNESCAPED_UNICODE), time(), time()]);
        $pid = (int) $pdo->lastInsertId();
        $ids = ['campaign_id' => '', 'adset_id' => '', 'creative_id' => '', 'ad_id' => ''];
        try {
            $cp = ['name' => $cname, 'objective' => $obj, 'status' => 'PAUSED', 'buying_type' => 'AUCTION',
                'special_ad_categories' => array_values((array) ($sCamp['special_ad_categories'] ?? [])),
                'daily_budget' => minor_units($budget, $acc['currency']), 'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP'];
            $ids['campaign_id'] = (string) graph('POST', 'act_' . $act . '/campaigns', $cp, $tok)['id'];
            $ap = ['name' => str_clean($as['name'] ?? '', 250) ?: $cname, 'campaign_id' => $ids['campaign_id'], 'status' => 'PAUSED',
                'targeting' => $targeting, 'optimization_goal' => (string) ($sAdset['optimization_goal'] ?? 'OFFSITE_CONVERSIONS'),
                'billing_event' => (string) ($sAdset['billing_event'] ?? 'IMPRESSIONS')];
            foreach (['promoted_object', 'attribution_spec'] as $k) {
                if (!empty($sAdset[$k])) $ap[$k] = $sAdset[$k];
            }
            if (!empty($sAdset['destination_type']) && $sAdset['destination_type'] !== 'UNDEFINED') $ap['destination_type'] = $sAdset['destination_type'];
            if (!empty($sAdset['is_dynamic_creative'])) $ap['is_dynamic_creative'] = true;
            $ids['adset_id'] = (string) graph('POST', 'act_' . $act . '/adsets', $ap, $tok)['id'];
            if ($spec) {
                $ids['creative_id'] = (string) graph('POST', 'act_' . $act . '/adcreatives', ['name' => $cname . ' · creative', 'object_story_spec' => $spec], $tok)['id'];
            } else {
                $ids['creative_id'] = (string) ($sCr['id'] ?? '');
            }
            $ids['ad_id'] = (string) graph('POST', 'act_' . $act . '/ads', ['name' => str_clean($ad['name'] ?? '', 250) ?: $cname,
                'adset_id' => $ids['adset_id'], 'creative' => ['creative_id' => $ids['creative_id']], 'status' => 'PAUSED'], $tok)['id'];
        } catch (GraphError $e) {
            // Création incomplète : on supprime ce qui a été créé pour ne rien laisser à moitié sur le compte.
            if ($ids['campaign_id'] !== '') {
                try { graph('POST', $ids['campaign_id'], ['status' => 'DELETED'], $tok); } catch (Throwable $e2) { }
            }
            $pdo->prepare("UPDATE proposals SET status = 'failed', error = ?, updated = ? WHERE id = ?")->execute([mb_substr($e->userMessage(), 0, 500), time(), $pid]);
            graph_fail($e, $uid);
        }
        $pdo->prepare("UPDATE proposals SET status = 'paused', campaign_id = ?, adset_id = ?, creative_id = ?, ad_id = ?, updated = ? WHERE id = ?")
            ->execute([$ids['campaign_id'], $ids['adset_id'], $ids['creative_id'], $ids['ad_id'], time(), $pid]);
        log_activity($uid, 'campaign_create', $act, $cname);
        $s = $pdo->prepare('SELECT * FROM proposals WHERE id = ?');
        $s->execute([$pid]);
        ok(['proposal' => proposal_view($s->fetch()), 'texts_changed' => (bool) $spec, 'creative_mode' => $texts['mode']]);
        break;
    }

    case 'proposal_status': {
        if (!$isPost) fail('generic', 405);
        $u = require_any_user();
        $uid = (int) $u['id'];
        $to = ($b['status'] ?? '') === 'active' ? 'ACTIVE' : (($b['status'] ?? '') === 'paused' ? 'PAUSED' : fail('bad_status'));
        $s = $pdo->prepare('SELECT * FROM proposals WHERE id = ? AND uid = ?');
        $s->execute([(int) ($b['id'] ?? 0), $uid]);
        $p = $s->fetch();
        if (!$p || $p['campaign_id'] === '') fail('not_found', 404);
        $tok = meta_token($uid);
        try {
            // Publier : la publicité et l'ensemble d'abord, la campagne en dernier (elle déclenche la diffusion).
            $order = $to === 'ACTIVE' ? ['ad_id', 'adset_id', 'campaign_id'] : ['campaign_id', 'adset_id', 'ad_id'];
            foreach ($order as $k) {
                if ($p[$k] !== '') graph('POST', (string) $p[$k], ['status' => $to], $tok);
            }
        } catch (GraphError $e) {
            graph_fail($e, $uid);
        }
        $st = $to === 'ACTIVE' ? 'active' : 'paused';
        $pdo->prepare('UPDATE proposals SET status = ?, updated = ? WHERE id = ?')->execute([$st, time(), $p['id']]);
        log_activity($uid, $st === 'active' ? 'campaign_publish' : 'campaign_pause', (string) $p['act'], (string) $p['name']);
        $p['status'] = $st;
        $p['updated'] = time();
        ok(['proposal' => proposal_view($p)]);
        break;
    }

    /* ------------------------------------------------------------ admin */

    case 'adm_overview': {
        require_admin();
        $one = function (string $sql, array $a = []) use ($pdo) {
            $s = $pdo->prepare($sql);
            $s->execute($a);
            return $s->fetchColumn();
        };
        $since30 = time() - 30 * 86400;
        $users = $pdo->query('SELECT u.id, u.name, u.email, u.company, u.lang, u.share, u.created, u.last_seen, u.google_sub IS NOT NULL AS google,
            (SELECT COUNT(*) FROM ext_tokens t WHERE t.uid = u.id) AS ext, (SELECT MAX(last_used) FROM ext_tokens t WHERE t.uid = u.id) AS ext_used,
            (SELECT COUNT(*) FROM meta_conn m WHERE m.uid = u.id) AS meta, (SELECT COUNT(*) FROM scans s WHERE s.uid = u.id) AS scans,
            (SELECT COUNT(*) FROM proposals p WHERE p.uid = u.id AND p.status IN (\'paused\',\'active\')) AS proposals
            FROM users u ORDER BY u.created DESC LIMIT 500')->fetchAll();
        // Données partagées : moyennes des KPI des analyses (30 jours), par devise.
        $agg = [];
        $s = $pdo->prepare('SELECT currency, totals, counts, level FROM scans WHERE created > ?');
        $s->execute([$since30]);
        foreach ($s->fetchAll() as $r) {
            $c = (string) $r['currency'] ?: '?';
            $t = json_decode((string) $r['totals'], true) ?: [];
            $k = json_decode((string) $r['counts'], true) ?: [];
            $a = &$agg[$c];
            $a['scans'] = ($a['scans'] ?? 0) + 1;
            foreach (['spend', 'purchases', 'value', 'waste'] as $f) $a[$f] = ($a[$f] ?? 0) + (float) ($t[$f] ?? 0);
            foreach (['cpa', 'roas', 'ctrLink', 'cpm'] as $f) {
                if (isset($t[$f]) && is_numeric($t[$f])) { $a['sum_' . $f] = ($a['sum_' . $f] ?? 0) + (float) $t[$f]; $a['n_' . $f] = ($a['n_' . $f] ?? 0) + 1; }
            }
            foreach (['win', 'opt', 'lose', 'ins'] as $f) $a[$f] = ($a[$f] ?? 0) + (int) ($k[$f] ?? 0);
            unset($a);
        }
        $currencies = [];
        foreach ($agg as $cur => $a) {
            $row = ['currency' => $cur, 'scans' => $a['scans'], 'spend' => round($a['spend'], 2), 'purchases' => (int) $a['purchases'],
                'waste' => round($a['waste'], 2), 'win' => $a['win'], 'opt' => $a['opt'], 'lose' => $a['lose'], 'ins' => $a['ins']];
            foreach (['cpa', 'roas', 'ctrLink', 'cpm'] as $f) $row['avg_' . $f] = !empty($a['n_' . $f]) ? round($a['sum_' . $f] / $a['n_' . $f], 2) : null;
            $currencies[] = $row;
        }
        ok([
            'counts' => [
                'users' => (int) $one('SELECT COUNT(*) FROM users'),
                'users30' => (int) $one('SELECT COUNT(*) FROM users WHERE created > ?', [$since30]),
                'active30' => (int) $one('SELECT COUNT(*) FROM users WHERE last_seen > ?', [$since30]),
                'ext' => (int) $one('SELECT COUNT(DISTINCT uid) FROM ext_tokens'),
                'meta' => (int) $one('SELECT COUNT(*) FROM meta_conn'),
                'sharing' => (int) $one('SELECT COUNT(*) FROM users WHERE share = 1'),
                'scans30' => (int) $one('SELECT COUNT(*) FROM scans WHERE created > ?', [$since30]),
                'exports30' => (int) $one("SELECT COUNT(*) FROM activity WHERE kind = 'export' AND created > ?", [$since30]),
                'campaigns' => (int) $one("SELECT COUNT(*) FROM proposals WHERE status IN ('paused','active')"),
                'published' => (int) $one("SELECT COUNT(*) FROM proposals WHERE status = 'active'"),
                'failed' => (int) $one("SELECT COUNT(*) FROM proposals WHERE status = 'failed'"),
            ],
            'currencies' => $currencies, 'users' => $users,
            'errors' => $pdo->query("SELECT p.error, p.created, u.email FROM proposals p JOIN users u ON u.id = p.uid WHERE p.status = 'failed' ORDER BY p.id DESC LIMIT 20")->fetchAll(),
        ]);
        break;
    }

    case 'adm_settings': {
        require_admin();
        ok(['google_client_id' => setting('google_client_id'), 'google_effective' => google_client_id(),
            'meta_app_id' => trim(setting('meta_app_id')), 'meta_effective' => meta_app_id(), 'meta_source' => meta_app_source(),
            'meta_secret_set' => trim(setting('meta_app_secret')) !== '', 'meta_effective_secret' => meta_app_secret() !== '', 'meta_config_id' => meta_config_id(),
            'graph_version' => graph_ver(), 'redirect_uri' => meta_redirect_uri(), 'site' => site_url()]);
        break;
    }

    case 'adm_settings_save': {
        require_admin();
        $g = trim((string) ($b['google_client_id'] ?? ''));
        if ($g !== '' && !preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $g)) fail('bad_google');
        setting_set('google_client_id', $g);
        $app = trim((string) ($b['meta_app_id'] ?? ''));
        if ($app !== '' && !preg_match('/^\d{5,25}$/', $app)) fail('bad_meta_app');
        setting_set('meta_app_id', $app);
        $sec = trim((string) ($b['meta_app_secret'] ?? ''));
        if ($sec !== '') {
            if (!preg_match('/^[a-f0-9]{32}$/i', $sec)) fail('bad_meta_secret');
            setting_set('meta_app_secret', $sec);
        }
        if (!empty($b['meta_secret_clear'])) setting_set('meta_app_secret', '');
        $cfgId = trim((string) ($b['meta_config_id'] ?? ''));
        if ($cfgId !== '' && !preg_match('/^\d{5,25}$/', $cfgId)) fail('bad_meta_config');
        setting_set('meta_config_id', $cfgId);
        $ver = trim((string) ($b['graph_version'] ?? ''));
        setting_set('graph_version', preg_match('/^v\d{2}\.0$/', $ver) ? $ver : '');
        ok();
        break;
    }

    case 'adm_user_delete': {
        require_admin();
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        ok();
        break;
    }

    default:
        fail('generic', 404);
}
