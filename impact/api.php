<?php
/**
 * API du défi Impact — dehini.com/impact/
 *
 * Toutes les réponses sont en JSON ({ok:true,…} ou {error:"code"}) ; les codes
 * d'erreur sont traduits côté page. Les écritures (POST) exigent l'en-tête
 * X-Requested-With: impact, et pour un compte connecté le jeton X-CSRF.
 *
 *   GET  me                       session courante -> {user|null, csrf}
 *   GET  stats                    compteurs publics + répartition par question
 *   POST answers   {device,set,reset?}   enregistre des réponses (compte ou appareil)
 *   GET  pull                     réponses + récits du compte connecté
 *   POST signup    {name,email,password,lang,opt_daily,opt_digest,device,stories}
 *   POST login     {email,password,device,stories}
 *   POST logout | prefs {name,lang,opt_daily,opt_digest} | resend_verify | delete_account {password}
 *   POST forgot    {email,lang}   |  reset {token,password}
 *   GET  verify    ?token=        lien de confirmation (redirige vers la page)
 *   GET  feed      ?offset&limit&lang          derniers récits publiés
 *   GET  picks     ?q=1,2,3&lang               un récit au hasard par question
 *   GET  stories   ?q=&exclude=&limit&lang     « voir plus » sur une question
 *   POST story_save {qid,exp,imp,author,public,lang,id?,token?} | story_delete {id,token?}
 *   POST like {id,undo?} | report {id}
 *   GET|POST unsub ?type=daily|digest|all&token=   page de désabonnement
 *   mod_* : modération, réservée à la session de /admin (même mot de passe)
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

const SID_COOKIE = 'impact_sid';
const SESSION_DAYS = 60;
const STATUSES = ['published', 'pending', 'private', 'hidden'];

$action = (string) ($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    db();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'db', 'detail' => $e->getMessage()]);
    exit;
}

/* Les deux liens des emails renvoient des pages, pas du JSON. */
if ($action === 'verify') page_verify();
if ($action === 'unsub') page_unsub($isPost);

header('Content-Type: application/json; charset=utf-8');

if ($isPost && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'impact') fail('generic', 403);

/* ------------------------------------------------------------------ outils */

function out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

/** Limite simple par IP : au plus $max actions « $key » par fenêtre de $window secondes. */
function rate(string $key, int $max, int $window): void
{
    $k = $key . '|' . ip_hash();
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

function valid_device($d): bool
{
    return is_string($d) && preg_match('/^[a-f0-9]{32}$/', $d) === 1;
}
function int_list(string $s, int $max): array
{
    $out = [];
    foreach (explode(',', $s) as $p) {
        if (ctype_digit($p)) $out[(int) $p] = (int) $p;
        if (count($out) >= $max) break;
    }
    return array_values($out);
}

/* --------------------------------------------------------------- sessions */

function cookie_opts(int $expires): array
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/impact/api.php')), '/') . '/';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ['expires' => $expires, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
}

/** Utilisateur connecté (ou null). Prolonge la session quand elle est utilisée. */
function current_user(): ?array
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

/** Pour une écriture : si un compte est connecté, son jeton CSRF doit accompagner la requête. */
function user_for_write(): ?array
{
    $u = current_user();
    if ($u && !hash_equals((string) $u['s_csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('auth', 401);
    return $u;
}
function require_user(): array
{
    global $isPost;
    $u = $isPost ? user_for_write() : current_user();
    if (!$u) fail('auth', 401);
    return $u;
}

function public_user(array $u): array
{
    return [
        'name' => $u['name'], 'email' => $u['email'], 'lang' => $u['lang'],
        'verified' => (bool) $u['verified'], 'opt_daily' => (bool) $u['opt_daily'], 'opt_digest' => (bool) $u['opt_digest'],
    ];
}

/* ------------------------------------------------------------ réponses */

function save_answer(string $pid, int $qid, int $v, int $t): void
{
    $pdo = db();
    $pdo->prepare('INSERT OR IGNORE INTO answers (pid, qid, v, t) VALUES (?, ?, ?, ?)')->execute([$pid, $qid, $v, $t]);
    $pdo->prepare('UPDATE answers SET v = ?, t = ? WHERE pid = ? AND qid = ? AND t < ?')->execute([$v, $t, $pid, $qid, $t]);
}

/** Objet {qid: [valeur, horodatage ms]} — toujours un objet JSON, même vide. */
function user_answers(int $uid): object
{
    $s = db()->prepare('SELECT qid, v, t FROM answers WHERE pid = ?');
    $s->execute(['u' . $uid]);
    $out = [];
    foreach ($s as $r) $out[(string) $r['qid']] = [(int) $r['v'], (int) $r['t']];
    return (object) $out;
}

/** Rattache au compte les réponses données sans compte depuis cet appareil. */
function merge_device(int $uid, $device): void
{
    if (!valid_device($device)) return;
    $pdo = db();
    $s = $pdo->prepare('SELECT qid, v, t FROM answers WHERE pid = ?');
    $s->execute(['d' . $device]);
    $rows = $s->fetchAll();
    foreach ($rows as $r) save_answer('u' . $uid, (int) $r['qid'], (int) $r['v'], (int) $r['t']);
    $pdo->prepare('DELETE FROM answers WHERE pid = ?')->execute(['d' . $device]);
}

/** Rattache au compte les récits publiés sans compte (prouvés par leur jeton d'édition). */
function claim_stories(int $uid, $list): void
{
    if (!is_array($list)) return;
    $s = db()->prepare('UPDATE stories SET uid = ? WHERE id = ? AND uid IS NULL AND ehash = ?');
    foreach (array_slice($list, 0, 200) as $it) {
        if (!is_array($it) || empty($it['id']) || !is_string($it['token'] ?? null)) continue;
        $s->execute([$uid, (int) $it['id'], hash('sha256', $it['token'])]);
    }
}

/* ------------------------------------------------------------- récits */

const STORY_PUBLIC_COLS = 'id, qid, author, exp, imp, lang, likes, created';

function story_row(array $r, bool $own = false): array
{
    $o = [
        'id' => (int) $r['id'], 'qid' => (int) $r['qid'], 'author' => (string) $r['author'],
        'exp' => (string) $r['exp'], 'imp' => (string) $r['imp'], 'lang' => (string) $r['lang'],
        'likes' => (int) $r['likes'], 'created' => (int) $r['created'],
    ];
    if ($own) $o['status'] = (string) $r['status'];
    return $o;
}

function own_stories(int $uid): array
{
    $s = db()->prepare('SELECT * FROM stories WHERE uid = ? ORDER BY id');
    $s->execute([$uid]);
    return array_map(function ($r) { return story_row($r, true); }, $s->fetchAll());
}

function looks_spammy(string $s): bool
{
    return preg_match('~(https?://|www\.|\b[\w.-]+\.(com|net|org|info|ru|xyz|top|io|link|click)\b|@\w+\.\w+|(\d[\s.-]?){9,})~iu', $s) === 1;
}

function notify_moderator(array $story): void
{
    if (IMPACT_NOTIFY_EMAIL === '') return;
    $q = questions()[(int) $story['qid']]['fr'] ?? '';
    $url = site_url() . 'moderation.html';
    $text = "Un récit attend une validation sur le défi Impact.\n\nQuestion : $q\n\n« {$story['exp']} »\n\nImpact positif : {$story['imp']}\n\nModérer : $url";
    $html = mail_layout('fr', 'Récit à valider', 'Un récit attend une validation',
        '<p style="margin:0 0 12px"><b>' . h($q) . '</b></p><p style="margin:0 0 12px">' . ugc((string) $story['exp']) . '</p>'
        . '<p style="margin:0;color:#079455">' . ugc((string) $story['imp']) . '</p>' . mail_button($url, 'Ouvrir la modération'));
    try {
        send_mail(IMPACT_NOTIFY_EMAIL, 'Défi Impact — récit à valider', $html, $text);
    } catch (Throwable $e) {
        error_log('impact notify: ' . $e->getMessage());
    }
}

/** Le récit $id peut-il être modifié par cet utilisateur / ce jeton ? */
function story_for_edit(int $id, ?array $user, $tok): array
{
    $s = db()->prepare('SELECT * FROM stories WHERE id = ?');
    $s->execute([$id]);
    $st = $s->fetch();
    if (!$st) fail('not_found', 404);
    $byUser = $user && (int) $st['uid'] === (int) $user['id'];
    $byToken = is_string($tok) && $tok !== '' && hash_equals((string) $st['ehash'], hash('sha256', $tok));
    if (!$byUser && !$byToken) fail('forbidden', 403);
    return $st;
}

/* ------------------------------------------------------- pages (emails) */

function page_verify(): void
{
    $tok = (string) ($_GET['token'] ?? '');
    $okv = false;
    if (preg_match('/^[a-f0-9]{48}$/', $tok)) {
        $s = db()->prepare('UPDATE users SET verified = 1, vtoken = NULL WHERE vtoken = ?');
        $s->execute([$tok]);
        $okv = $s->rowCount() > 0;
    }
    header('Location: ./?verified=' . ($okv ? '1' : '0'), true, 302);
    exit;
}

function page_unsub(bool $isPost): void
{
    header('Content-Type: text/html; charset=utf-8');
    $tok = (string) ($_GET['token'] ?? '');
    $type = in_array($_GET['type'] ?? '', ['daily', 'digest', 'all'], true) ? $_GET['type'] : 'all';
    $u = null;
    if (preg_match('/^[a-f0-9]{48}$/', $tok)) {
        $s = db()->prepare('SELECT * FROM users WHERE utoken = ?');
        $s->execute([$tok]);
        $u = $s->fetch() ?: null;
    }
    $l = $u ? impact_lang($u['lang']) : impact_lang($_GET['lang'] ?? 'fr');
    $done = false;
    if ($u && $isPost) {
        $sql = ['daily' => 'opt_daily = 0', 'digest' => 'opt_digest = 0', 'all' => 'opt_daily = 0, opt_digest = 0'][$type];
        db()->prepare("UPDATE users SET $sql WHERE id = ?")->execute([$u['id']]);
        $done = true;
    }
    $dir = $l === 'ar' ? 'rtl' : 'ltr';
    $font = mail_font($l);
    if (!$u) $body = '<p>' . h(ms($l, 'unsub_bad')) . '</p>';
    elseif ($done) $body = '<p>' . h(ms($l, 'unsub_done')) . '</p>';
    else $body = '<p style="font-size:1.15rem;font-weight:700">' . h(ms($l, 'unsub_q_' . $type)) . '</p>'
        . '<form method="post"><button style="margin-top:8px;padding:13px 26px;border:0;border-radius:999px;background:#0b1020;color:#fff;font:700 15px ' . $font . ';cursor:pointer">'
        . h(ms($l, 'unsub_confirm')) . '</button></form>';
    echo '<!DOCTYPE html><html lang="' . $l . '" dir="' . $dir . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h(ms($l, 'unsub_title')) . ' — ' . h(ms($l, 'brand')) . '</title></head>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#f0f1f4;font:16px/1.6 ' . $font . ';color:#20283f;padding:16px;box-sizing:border-box">'
        . '<main style="max-width:440px;width:100%;background:#fff;border-radius:22px;padding:32px;box-shadow:0 8px 30px -12px rgba(11,16,32,.2)">'
        . '<p style="margin:0 0 6px;font-weight:800;color:#0b1020">' . h(ms($l, 'brand')) . '</p>'
        . '<h1 style="margin:0 0 16px;font-size:1.4rem;color:#0b1020">' . h(ms($l, 'unsub_title')) . '</h1>' . $body
        . '<p style="margin:22px 0 0"><a href="./?lang=' . $l . '" style="color:#2f5bff">' . h(ms($l, 'back')) . '</a></p>'
        . '</main></body></html>';
    exit;
}

/* --------------------------------------------------------- modération */

function require_admin(): void
{
    global $isPost;
    session_name('dehini_admin');
    session_start();
    $okAdmin = !empty($_SESSION['admin']) && !empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) < 12 * 3600;
    if (!$okAdmin) fail('auth', 401);
    if ($isPost && (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? '')))) {
        fail('auth', 403);
    }
    session_write_close();
}

/* ============================================================== actions */

$b = $isPost ? input_json() : [];
$pdo = db();

switch ($action) {

    case 'me': {
        $u = current_user();
        ok(['user' => $u ? public_user($u) : null, 'csrf' => $u ? $u['s_csrf'] : null]);
        break;
    }

    case 'stats': {
        $cached = meta_get('stats_cache');
        if ($cached) {
            $c = json_decode($cached, true);
            if (is_array($c) && ($c['at'] ?? 0) > time() - 60) ok($c['data']);
        }
        $q = [];
        foreach (questions() as $id => $_) $q[(string) $id] = [0, 0, 0, 0];
        foreach ($pdo->query('SELECT qid, v, COUNT(*) AS n FROM answers GROUP BY qid, v') as $r) {
            if (isset($q[(string) $r['qid']])) $q[(string) $r['qid']][(int) $r['v'] + 1] = (int) $r['n'];
        }
        foreach ($pdo->query("SELECT qid, COUNT(*) AS n FROM stories WHERE status = 'published' GROUP BY qid") as $r) {
            if (isset($q[(string) $r['qid']])) $q[(string) $r['qid']][3] = (int) $r['n'];
        }
        $data = [
            'participants' => (int) $pdo->query('SELECT COUNT(DISTINCT pid) FROM answers')->fetchColumn(),
            'answers'      => (int) $pdo->query('SELECT COUNT(*) FROM answers')->fetchColumn(),
            'stories'      => (int) $pdo->query("SELECT COUNT(*) FROM stories WHERE status = 'published'")->fetchColumn(),
            'q'            => (object) $q,
        ];
        meta_set('stats_cache', (string) json_encode(['at' => time(), 'data' => $data]));
        ok($data);
        break;
    }

    case 'answers': {
        if (!$isPost) fail('generic', 405);
        $u = user_for_write();
        $device = $b['device'] ?? '';
        if (!$u && !valid_device($device)) fail('generic');
        $pid = $u ? 'u' . $u['id'] : 'd' . $device;
        rate('answers', 900, 3600);
        $set = $b['set'] ?? [];
        if (!is_array($set) || count($set) > 300) fail('generic');
        $qs = questions();
        $maxT = (int) (microtime(true) * 1000) + 60000;
        $pdo->beginTransaction();
        if (!empty($b['reset'])) $pdo->prepare('DELETE FROM answers WHERE pid = ?')->execute([$pid]);
        foreach ($set as $qid => $pair) {
            $qid = (int) $qid;
            if (!isset($qs[$qid])) continue;
            if ($pair === null) {
                $pdo->prepare('DELETE FROM answers WHERE pid = ? AND qid = ?')->execute([$pid, $qid]);
                continue;
            }
            if (!is_array($pair) || count($pair) < 2) continue;
            $v = (int) $pair[0];
            if (!in_array($v, [-1, 0, 1], true)) continue;
            save_answer($pid, $qid, $v, min((int) $pair[1], $maxT));
        }
        $pdo->commit();
        ok();
        break;
    }

    case 'pull': {
        $u = require_user();
        ok(['user' => public_user($u), 'answers' => user_answers((int) $u['id']), 'stories' => own_stories((int) $u['id'])]);
        break;
    }

    case 'signup': {
        if (!$isPost) fail('generic', 405);
        rate('signup', 8, 3600);
        $name = str_clean((string) ($b['name'] ?? ''), 40);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $pass = (string) ($b['password'] ?? '');
        if ($name === '') fail('bad_name');
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email');
        if (strlen($pass) < 8 || strlen($pass) > 200) fail('weak_pass');
        $s = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $s->execute([$email]);
        if ($s->fetchColumn()) fail('email_taken', 409);
        $lang = impact_lang($b['lang'] ?? 'fr');
        $pdo->prepare('INSERT INTO users (email, name, pass, lang, created, vtoken, utoken, opt_daily, opt_digest) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), $lang, time(), token(), token(),
                empty($b['opt_daily']) ? 0 : 1, empty($b['opt_digest']) ? 0 : 1]);
        $uid = (int) $pdo->lastInsertId();
        merge_device($uid, $b['device'] ?? '');
        claim_stories($uid, $b['stories'] ?? []);
        $csrf = start_session($uid);
        $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $s->execute([$uid]);
        $u = $s->fetch();
        $mailed = true;
        try {
            [$subj, $html, $text] = compose_welcome($u);
            send_mail($u['email'], $subj, $html, $text);
        } catch (Throwable $e) {
            $mailed = false;
            error_log('impact welcome: ' . $e->getMessage());
        }
        ok(['user' => public_user($u), 'csrf' => $csrf, 'mailed' => $mailed,
            'answers' => user_answers($uid), 'stories' => own_stories($uid)]);
        break;
    }

    case 'login': {
        if (!$isPost) fail('generic', 405);
        rate('login', 12, 900);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $pass = (string) ($b['password'] ?? '');
        $s = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $s->execute([$email]);
        $u = $s->fetch();
        if (!$u || !password_verify($pass, $u['pass'])) {
            usleep(350000);
            fail('bad_login', 401);
        }
        if (password_needs_rehash($u['pass'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
        }
        $uid = (int) $u['id'];
        merge_device($uid, $b['device'] ?? '');
        claim_stories($uid, $b['stories'] ?? []);
        $csrf = start_session($uid);
        ok(['user' => public_user($u), 'csrf' => $csrf, 'answers' => user_answers($uid), 'stories' => own_stories($uid)]);
        break;
    }

    case 'logout': {
        if (!$isPost) fail('generic', 405);
        end_session();
        ok();
        break;
    }

    case 'prefs': {
        $u = require_user();
        $sets = [];
        $vals = [];
        if (isset($b['name'])) {
            $n = str_clean((string) $b['name'], 40);
            if ($n === '') fail('bad_name');
            $sets[] = 'name = ?';
            $vals[] = $n;
        }
        if (isset($b['lang'])) { $sets[] = 'lang = ?'; $vals[] = impact_lang($b['lang']); }
        foreach (['opt_daily', 'opt_digest'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = empty($b[$k]) ? 0 : 1; }
        }
        if ($sets) {
            $vals[] = $u['id'];
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }
        $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $s->execute([$u['id']]);
        ok(['user' => public_user($s->fetch())]);
        break;
    }

    case 'resend_verify': {
        $u = require_user();
        if ($u['verified']) ok(['user' => public_user($u)]);
        rate('verify', 4, 3600);
        if (empty($u['vtoken'])) {
            $u['vtoken'] = token();
            $pdo->prepare('UPDATE users SET vtoken = ? WHERE id = ?')->execute([$u['vtoken'], $u['id']]);
        }
        try {
            [$subj, $html, $text] = compose_welcome($u);
            send_mail($u['email'], $subj, $html, $text);
        } catch (Throwable $e) {
            error_log('impact verify: ' . $e->getMessage());
            fail('mail', 502);
        }
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
            $u['lang'] = impact_lang($b['lang'] ?? $u['lang']);
            try {
                [$subj, $html, $text] = compose_reset($u, $raw);
                send_mail($u['email'], $subj, $html, $text);
            } catch (Throwable $e) {
                error_log('impact reset: ' . $e->getMessage());
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
        // Le lien a été reçu par email : l'adresse est donc confirmée.
        $pdo->prepare('UPDATE users SET pass = ?, rhash = NULL, rexp = 0, verified = 1, vtoken = NULL WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $uid]);
        $pdo->prepare('DELETE FROM sessions WHERE uid = ?')->execute([$uid]);
        $u['verified'] = 1;
        $csrf = start_session($uid);
        ok(['user' => public_user($u), 'csrf' => $csrf, 'answers' => user_answers($uid), 'stories' => own_stories($uid)]);
        break;
    }

    case 'delete_account': {
        $u = require_user();
        if (!password_verify((string) ($b['password'] ?? ''), $u['pass'])) {
            usleep(350000);
            fail('bad_login', 401);
        }
        $pdo->prepare('DELETE FROM answers WHERE pid = ?')->execute(['u' . $u['id']]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]); // sessions, récits : ON DELETE CASCADE
        setcookie(SID_COOKIE, '', cookie_opts(time() - 3600));
        ok();
        break;
    }

    case 'feed': {
        $limit = max(1, min(12, (int) ($_GET['limit'] ?? 9)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $s = $pdo->prepare('SELECT ' . STORY_PUBLIC_COLS . " FROM stories WHERE status = 'published' ORDER BY created DESC, id DESC LIMIT ? OFFSET ?");
        $s->execute([$limit, $offset]);
        $items = array_map('story_row', $s->fetchAll());
        $total = (int) $pdo->query("SELECT COUNT(*) FROM stories WHERE status = 'published'")->fetchColumn();
        ok(['items' => $items, 'total' => $total]);
        break;
    }

    case 'picks': {
        $ids = int_list((string) ($_GET['q'] ?? ''), 150);
        $lang = impact_lang($_GET['lang'] ?? 'fr');
        $u = current_user();
        $notMine = $u ? ' AND (uid IS NULL OR uid != ' . (int) $u['id'] . ')' : '';
        $one = $pdo->prepare('SELECT ' . STORY_PUBLIC_COLS . " FROM stories WHERE status = 'published' AND qid = ?$notMine ORDER BY (lang = ?) DESC, RANDOM() LIMIT 1");
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM stories WHERE status = 'published' AND qid = ?$notMine");
        $items = [];
        $counts = [];
        foreach ($ids as $qid) {
            $one->execute([$qid, $lang]);
            $r = $one->fetch();
            $items[(string) $qid] = $r ? story_row($r) : null;
            $cnt->execute([$qid]);
            $counts[(string) $qid] = (int) $cnt->fetchColumn();
        }
        ok(['items' => (object) $items, 'counts' => (object) $counts]);
        break;
    }

    case 'stories': {
        $qid = (int) ($_GET['q'] ?? 0);
        $limit = max(1, min(5, (int) ($_GET['limit'] ?? 1)));
        $lang = impact_lang($_GET['lang'] ?? 'fr');
        $ex = int_list((string) ($_GET['exclude'] ?? ''), 200);
        $u = current_user();
        $where = "status = 'published' AND qid = ?" . ($u ? ' AND (uid IS NULL OR uid != ' . (int) $u['id'] . ')' : '')
            . ($ex ? ' AND id NOT IN (' . implode(',', $ex) . ')' : '');
        $s = $pdo->prepare('SELECT ' . STORY_PUBLIC_COLS . " FROM stories WHERE $where ORDER BY (lang = ?) DESC, RANDOM() LIMIT ?");
        $s->execute([$qid, $lang, $limit]);
        $items = array_map('story_row', $s->fetchAll());
        $c = $pdo->prepare("SELECT COUNT(*) FROM stories WHERE $where");
        $c->execute([$qid]);
        ok(['items' => $items, 'left' => max(0, (int) $c->fetchColumn() - count($items))]);
        break;
    }

    case 'story_save': {
        if (!$isPost) fail('generic', 405);
        $u = user_for_write();
        if (!empty($b['website'])) fail('generic'); // champ piège invisible : robot
        rate('story', 30, 3600);
        $qid = (int) ($b['qid'] ?? 0);
        if (!isset(questions()[$qid])) fail('generic');
        $exp = str_clean((string) ($b['exp'] ?? ''), 1500);
        $imp = str_clean((string) ($b['imp'] ?? ''), 1000);
        if (mb_strlen($exp) < 15 || mb_strlen($imp) < 10) fail('too_short');
        $author = empty($b['anon']) ? str_clean((string) ($b['author'] ?? ''), 40) : '';
        $lang = impact_lang($b['lang'] ?? 'fr');
        $status = empty($b['public']) ? 'private'
            : ((IMPACT_MODERATION === 'manual' || looks_spammy($exp . ' ' . $imp)) ? 'pending' : 'published');
        $now = time();
        $id = (int) ($b['id'] ?? 0);
        if (!$id && $u) {
            // Un seul récit par question et par compte : on met à jour l'existant.
            $s = $pdo->prepare('SELECT id FROM stories WHERE uid = ? AND qid = ?');
            $s->execute([$u['id'], $qid]);
            $id = (int) ($s->fetchColumn() ?: 0);
        }
        $tok = null;
        if ($id) {
            $st = story_for_edit($id, $u, $b['token'] ?? null);
            if ($st['status'] === 'hidden' && $status === 'published') $status = 'pending';
            $pdo->prepare('UPDATE stories SET qid = ?, author = ?, exp = ?, imp = ?, lang = ?, status = ?, updated = ? WHERE id = ?')
                ->execute([$qid, $author, $exp, $imp, $lang, $status, $now, $id]);
        } else {
            $tok = token(16);
            $pdo->prepare('INSERT INTO stories (qid, uid, author, exp, imp, lang, status, ehash, iph, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$qid, $u ? $u['id'] : null, $author, $exp, $imp, $lang, $status, hash('sha256', $tok), ip_hash(), $now, $now]);
            $id = (int) $pdo->lastInsertId();
        }
        $s = $pdo->prepare('SELECT * FROM stories WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        if ($status === 'pending') notify_moderator($row);
        meta_set('stats_cache', '');
        ok(['story' => story_row($row, true) + ($tok ? ['token' => $tok] : [])]);
        break;
    }

    case 'story_delete': {
        if (!$isPost) fail('generic', 405);
        $u = user_for_write();
        $st = story_for_edit((int) ($b['id'] ?? 0), $u, $b['token'] ?? null);
        $pdo->prepare('DELETE FROM stories WHERE id = ?')->execute([$st['id']]);
        $pdo->prepare('DELETE FROM digest_seen WHERE sid = ?')->execute([$st['id']]);
        meta_set('stats_cache', '');
        ok();
        break;
    }

    case 'like': {
        if (!$isPost) fail('generic', 405);
        $id = (int) ($b['id'] ?? 0);
        $undo = !empty($b['undo']);
        rate(($undo ? 'unlike:' : 'like:') . $id, 1, 30 * 86400);
        $pdo->prepare($undo
            ? "UPDATE stories SET likes = likes - 1 WHERE id = ? AND likes > 0 AND status = 'published'"
            : "UPDATE stories SET likes = likes + 1 WHERE id = ? AND status = 'published'")->execute([$id]);
        $s = $pdo->prepare('SELECT likes FROM stories WHERE id = ?');
        $s->execute([$id]);
        ok(['likes' => (int) $s->fetchColumn()]);
        break;
    }

    case 'report': {
        if (!$isPost) fail('generic', 405);
        $id = (int) ($b['id'] ?? 0);
        rate('report', 20, 3600);
        rate('report:' . $id, 1, 30 * 86400);
        $pdo->prepare("UPDATE stories SET reports = reports + 1, status = CASE WHEN reports + 1 >= 3 AND status = 'published' THEN 'hidden' ELSE status END WHERE id = ?")
            ->execute([$id]);
        $s = $pdo->prepare('SELECT * FROM stories WHERE id = ?');
        $s->execute([$id]);
        $st = $s->fetch();
        if ($st && $st['status'] === 'hidden' && (int) $st['reports'] === 3) notify_moderator($st);
        ok();
        break;
    }

    /* ---------------------------------------------------- modération */

    case 'mod_list': {
        require_admin();
        $status = (string) ($_GET['status'] ?? 'pending');
        $where = in_array($status, STATUSES, true) ? 'WHERE s.status = ' . $pdo->quote($status) : '';
        if ($status === 'reported') $where = 'WHERE s.reports > 0';
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $rows = $pdo->query("SELECT s.*, u.email AS email FROM stories s LEFT JOIN users u ON u.id = s.uid $where ORDER BY s.created DESC LIMIT 50 OFFSET $offset")->fetchAll();
        $qs = questions();
        $items = array_map(function ($r) use ($qs) {
            return story_row($r, true) + ['reports' => (int) $r['reports'], 'email' => (string) ($r['email'] ?? ''),
                'question' => $qs[(int) $r['qid']]['fr'] ?? ''];
        }, $rows);
        $counts = ['reported' => (int) $pdo->query('SELECT COUNT(*) FROM stories WHERE reports > 0')->fetchColumn()];
        foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM stories GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];
        ok(['items' => $items, 'counts' => $counts,
            'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'verified' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE verified = 1')->fetchColumn(),
            'participants' => (int) $pdo->query('SELECT COUNT(DISTINCT pid) FROM answers')->fetchColumn()]);
        break;
    }

    case 'mod_set': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $st = (string) ($b['status'] ?? '');
        if (!in_array($st, ['published', 'hidden', 'pending'], true)) fail('generic');
        $pdo->prepare('UPDATE stories SET status = ?, reports = CASE WHEN ? = \'published\' THEN 0 ELSE reports END WHERE id = ?')
            ->execute([$st, $st, (int) ($b['id'] ?? 0)]);
        meta_set('stats_cache', '');
        ok();
        break;
    }

    case 'mod_delete': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $pdo->prepare('DELETE FROM stories WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        meta_set('stats_cache', '');
        ok();
        break;
    }

    default:
        fail('not_found', 404);
}
