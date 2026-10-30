<?php
/**
 * API du défi Impact — dehini.com/impact/
 *
 * Toutes les réponses sont en JSON ({ok:true,…} ou {error:"code"}) ; les codes
 * d'erreur sont traduits côté page. Les écritures (POST) exigent l'en-tête
 * X-Requested-With: impact, et pour un compte connecté le jeton X-CSRF.
 *
 *   GET  me                       session courante -> {user|null, csrf, unread}
 *   GET  config                   connexion Google, moyens de soutien, mur des soutiens
 *   GET  questions                questions actives (cache navigateur par ETag)
 *   GET  stats                    compteurs publics, répartition par question, pays, profils
 *   POST answers   {device,set,reset?,tz}  enregistre des réponses (compte ou appareil)
 *   GET  pull                     réponses + récits du compte connecté
 *   POST signup    {name,email,password,lang,opt_daily,opt_digest,device,stories}
 *   POST login     {email,password,device,stories}
 *   POST google    {credential,device,stories,lang}   connexion / inscription avec Google
 *   POST logout | prefs {name,lang,opt_daily,opt_digest,opt_msg} | resend_verify | delete_account {password|confirm}
 *   POST forgot    {email,lang}   |  reset {token,password}
 *   GET  verify    ?token=        lien de confirmation (redirige vers la page)
 *   GET  feed      ?offset&limit&lang          derniers récits publiés
 *   GET  picks     ?q=1,2,3&lang               un récit au hasard par question
 *   GET  stories   ?q=&exclude=&limit&lang     « voir plus » sur une question
 *   POST story_save {qid,exp,imp,author,anon,public,lang,id?} (compte requis) | story_delete {id}
 *   POST react {id,v:1|-1|0,device} | report {id}
 *   GET  inbox | thread ?id=|?sid=   conversations privées (auteur du récit <-> un lecteur)
 *   POST msg_send {tid|sid,body} | thread_block {tid,on}
 *   POST support_start {amount,name,email,phone,message,public,lang}  -> URL de paiement SofizPay
 *        (retour et notification de paiement : support.php)
 *   GET|POST unsub ?type=daily|digest|msg|all&token=   page de désabonnement
 *   mod_* : administration (récits, questions, soutiens, réglages), réservée à la session de /admin
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/google.php';
require __DIR__ . '/lib/sofizpay.php';

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
        'opt_msg' => (bool) $u['opt_msg'], 'google' => !empty($u['google_sub']), 'has_pass' => (string) $u['pass'] !== '',
    ];
}

/** Réponse commune après une connexion (mot de passe, Google, lien de réinitialisation). */
function signed_in(array $u, string $csrf, array $extra = []): void
{
    $uid = (int) $u['id'];
    ok(['user' => public_user($u), 'csrf' => $csrf, 'answers' => user_answers($uid), 'stories' => own_stories($uid),
        'reactions' => user_reactions('u' . $uid), 'unread' => unread_count($uid)] + $extra);
}

function user_row(int $uid): array
{
    $s = db()->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$uid]);
    return $s->fetch() ?: [];
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
    $s = $pdo->prepare('SELECT cc, seen FROM people WHERE pid = ?');
    $s->execute(['d' . $device]);
    if ($p = $s->fetch()) {
        $pdo->prepare('INSERT OR REPLACE INTO people (pid, cc, seen) VALUES (?, ?, ?)')->execute(['u' . $uid, $p['cc'], $p['seen']]);
        $pdo->prepare('DELETE FROM people WHERE pid = ?')->execute(['d' . $device]);
    }
    // « J'aime / je n'aime pas » donnés sans compte : ils passent au compte (sans compter deux fois).
    $s = $pdo->prepare('SELECT sid, v FROM reactions WHERE pid = ?');
    $s->execute(['d' . $device]);
    $has = $pdo->prepare('SELECT 1 FROM reactions WHERE sid = ? AND pid = ?');
    foreach ($s->fetchAll() as $r) {
        $has->execute([$r['sid'], 'u' . $uid]);
        if ($has->fetchColumn()) react_adjust((int) $r['sid'], (int) $r['v'], 0);
        else $pdo->prepare('UPDATE reactions SET pid = ? WHERE sid = ? AND pid = ?')->execute(['u' . $uid, $r['sid'], 'd' . $device]);
    }
    $pdo->prepare('DELETE FROM reactions WHERE pid = ?')->execute(['d' . $device]);
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

const STORY_PUBLIC_COLS = 'id, qid, uid, author, exp, imp, lang, likes, dislikes, created';

function story_row(array $r, bool $own = false): array
{
    $o = [
        'id' => (int) $r['id'], 'qid' => (int) $r['qid'], 'author' => (string) $r['author'],
        'exp' => (string) $r['exp'], 'imp' => (string) $r['imp'], 'lang' => (string) $r['lang'],
        'likes' => (int) $r['likes'], 'dislikes' => (int) ($r['dislikes'] ?? 0), 'created' => (int) $r['created'],
    ];
    if ($own) {
        $o['status'] = (string) $r['status'];
    } else {
        // dm : on peut écrire à l'auteur (récit lié à un compte, qui n'est pas celui du lecteur).
        $me = current_user();
        $uid = (int) ($r['uid'] ?? 0);
        $o['dm'] = $uid > 0 && (!$me || (int) $me['id'] !== $uid);
        if ($me && (int) $me['id'] === $uid) $o['self'] = true;
    }
    return $o;
}

/* --------------------------------------------------- j'aime / je n'aime pas */

/** Met à jour les compteurs d'un récit quand une réaction passe de $old à $new (1, -1 ou 0). */
function react_adjust(int $sid, int $old, int $new): void
{
    $dl = ($new === 1 ? 1 : 0) - ($old === 1 ? 1 : 0);
    $dd = ($new === -1 ? 1 : 0) - ($old === -1 ? 1 : 0);
    if ($dl || $dd) db()->prepare('UPDATE stories SET likes = MAX(0, likes + ?), dislikes = MAX(0, dislikes + ?) WHERE id = ?')->execute([$dl, $dd, $sid]);
}

/** Réactions d'un participant : objet {id du récit: 1|-1}. */
function user_reactions(string $pid): object
{
    $s = db()->prepare('SELECT sid, v FROM reactions WHERE pid = ?');
    $s->execute([$pid]);
    $out = [];
    foreach ($s as $r) $out[(string) $r['sid']] = (int) $r['v'];
    return (object) $out;
}

/** Retire toutes les réactions d'un participant (suppression du compte). */
function drop_reactions(string $pid): void
{
    $s = db()->prepare('SELECT sid, v FROM reactions WHERE pid = ?');
    $s->execute([$pid]);
    foreach ($s->fetchAll() as $r) react_adjust((int) $r['sid'], (int) $r['v'], 0);
    db()->prepare('DELETE FROM reactions WHERE pid = ?')->execute([$pid]);
}

/* ------------------------------------------------- conversations privées */

/** Messages non lus, toutes conversations confondues. */
function unread_count(int $uid): int
{
    $s = db()->prepare('SELECT COUNT(*) FROM messages m JOIN threads t ON t.id = m.tid
        WHERE m.uid != ? AND ((t.owner = ? AND m.id > t.owner_read) OR (t.guest = ? AND m.id > t.guest_read))');
    $s->execute([$uid, $uid, $uid]);
    return (int) $s->fetchColumn();
}

/** Récit auquel ce lecteur peut écrire (publié, lié à un compte, pas le sien). */
function dm_story(int $sid, int $uid): array
{
    $s = db()->prepare("SELECT id, qid, uid, author, exp FROM stories WHERE id = ? AND status = 'published'");
    $s->execute([$sid]);
    $st = $s->fetch();
    if (!$st) fail('not_found', 404);
    if (!$st['uid']) fail('no_dm');
    if ((int) $st['uid'] === $uid) fail('own');
    return $st;
}

/** Conversation dont l'utilisateur est l'un des deux participants (sinon : introuvable). */
function thread_row(int $tid, int $uid): array
{
    $s = db()->prepare('SELECT t.*, s.qid, s.exp, s.author, ug.name AS guest_name
        FROM threads t JOIN stories s ON s.id = t.sid JOIN users ug ON ug.id = t.guest
        WHERE t.id = ? AND (t.owner = ? OR t.guest = ?)');
    $s->execute([$tid, $uid, $uid]);
    $t = $s->fetch();
    if (!$t) fail('not_found', 404);
    return $t;
}

/**
 * Vue d'une conversation pour l'un de ses participants. L'auteur voit le prénom du
 * lecteur ; le lecteur voit le nom choisi pour le récit (vide = auteur anonyme),
 * jamais le nom du compte de l'auteur.
 */
function thread_view(array $t, int $uid): array
{
    $role = (int) $t['owner'] === $uid ? 'owner' : 'guest';
    $read = (int) $t[$role . '_read'];
    $s = db()->prepare('SELECT COUNT(*) FROM messages WHERE tid = ? AND uid != ? AND id > ?');
    $s->execute([$t['id'], $uid, $read]);
    $unread = (int) $s->fetchColumn();
    $s = db()->prepare('SELECT uid, body, created FROM messages WHERE tid = ? ORDER BY id DESC LIMIT 1');
    $s->execute([$t['id']]);
    $last = $s->fetch();
    $blocked = (int) $t['blocked'];
    return [
        'id' => (int) $t['id'], 'sid' => (int) $t['sid'], 'qid' => (int) $t['qid'], 'role' => $role,
        'other' => $role === 'owner' ? (string) $t['guest_name'] : (string) $t['author'],
        'exp' => mb_strimwidth((string) $t['exp'], 0, 180, '…'),
        'last' => $last ? ['body' => mb_strimwidth((string) $last['body'], 0, 140, '…'), 'mine' => (int) $last['uid'] === $uid, 'at' => (int) $last['created']] : null,
        'unread' => $unread, 'updated' => (int) $t['updated'],
        'blocked' => $blocked ? ($blocked === $uid ? 'me' : 'other') : null,
    ];
}

/** Prévient le destinataire par email (adresse confirmée, au plus un email par conversation et par demi-heure). */
function notify_message(array $t, int $from, string $body): void
{
    $toOwner = (int) $t['owner'] !== $from;
    $to = user_row($toOwner ? (int) $t['owner'] : (int) $t['guest']);
    $col = $toOwner ? 'owner_mailed' : 'guest_mailed';
    if (!$to || !$to['verified'] || !$to['opt_msg'] || (int) $t[$col] > time() - 1800) return;
    $l = impact_lang($to['lang']);
    $to['lang'] = $l;
    $name = $toOwner ? (string) $t['guest_name'] : ((string) $t['author'] !== '' ? (string) $t['author'] : ms($l, 'msg_author'));
    $question = qtext(questions(true)[(int) $t['qid']] ?? [], $l);
    try {
        [$subj, $html, $text] = compose_message($to, $name, $question, $body, (int) $t['id'], $toOwner);
        send_mail((string) $to['email'], $subj, $html, $text, unsub_url($to, 'msg'));
        db()->prepare("UPDATE threads SET $col = ? WHERE id = ?")->execute([time(), $t['id']]);
    } catch (Throwable $e) {
        error_log('impact message mail: ' . $e->getMessage());
    }
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
    $q = qtext(questions(true)[(int) $story['qid']] ?? [], 'fr');
    $url = site_url() . 'admin.html#recits';
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
    $type = in_array($_GET['type'] ?? '', ['daily', 'digest', 'msg', 'all'], true) ? $_GET['type'] : 'all';
    $u = null;
    if (preg_match('/^[a-f0-9]{48}$/', $tok)) {
        $s = db()->prepare('SELECT * FROM users WHERE utoken = ?');
        $s->execute([$tok]);
        $u = $s->fetch() ?: null;
    }
    $l = $u ? impact_lang($u['lang']) : impact_lang($_GET['lang'] ?? 'fr');
    $done = false;
    if ($u && $isPost) {
        $sql = ['daily' => 'opt_daily = 0', 'digest' => 'opt_digest = 0', 'msg' => 'opt_msg = 0',
            'all' => 'opt_daily = 0, opt_digest = 0, opt_msg = 0'][$type];
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

/** Réglages affichés dans l'admin (connexion Google, SofizPay, moyens de soutien manuels). */
function settings_view(): array
{
    $u = parse_url(site_url());
    $origin = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? 'dehini.com') . (isset($u['port']) ? ':' . $u['port'] : '');
    return [
        'google_client_id' => setting('google_client_id'), 'google_in_config' => IMPACT_GOOGLE_CLIENT_ID !== '',
        'origin' => $origin,
        'sofiz_account' => sofiz_account(), 'sofiz_on' => setting('sofiz_on') === '1', 'sofiz_sandbox' => sofiz_sandbox(),
        'sofiz_ready' => sofiz_active(), 'webhook' => site_url() . 'support.php?hook=1',
        'methods' => support_methods(),
    ];
}

function donation_view(array $d): array
{
    return [
        'id' => (int) $d['id'], 'amount' => (int) $d['amount'], 'name' => (string) $d['name'], 'email' => (string) $d['email'],
        'phone' => (string) $d['phone'], 'message' => (string) $d['message'], 'public' => (bool) $d['public'],
        'lang' => (string) $d['lang'], 'status' => (string) $d['status'], 'note' => (string) $d['note'],
        'created' => (int) $d['created'], 'paid' => (int) $d['paid'], 'cib' => (string) $d['cib'],
    ];
}

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
        ok(['user' => $u ? public_user($u) : null, 'csrf' => $u ? $u['s_csrf'] : null, 'unread' => $u ? unread_count((int) $u['id']) : 0]);
        break;
    }

    case 'config': {
        // Mur des soutiens : prénom et initiale seulement, et uniquement pour qui l'a accepté.
        $wall = [];
        foreach ($pdo->query("SELECT name FROM donations WHERE status = 'paid' AND public = 1 ORDER BY paid DESC LIMIT 24") as $r) {
            $w = preg_split('/\s+/u', trim((string) $r['name'])) ?: [];
            $wall[] = $w[0] . (count($w) > 1 ? ' ' . mb_substr((string) end($w), 0, 1) . '.' : '');
        }
        ok([
            'google'  => google_client_id() ?: null,
            'support' => [
                'card' => sofiz_active(), 'test' => sofiz_active() && sofiz_sandbox(),
                'min' => DON_MIN, 'max' => DON_MAX, 'amounts' => DON_AMOUNTS, 'methods' => support_methods(),
                'count' => (int) $pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'paid'")->fetchColumn(),
                'wall' => $wall,
            ],
        ]);
        break;
    }

    case 'questions': {
        // Questions actives : le navigateur garde sa copie tant que l'admin n'a rien changé.
        $etag = '"q' . questions_version() . '"';
        header('Cache-Control: no-cache');
        header('ETag: ' . $etag);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        $items = [];
        foreach (questions() as $q) $items[] = ['id' => $q['id'], 'cat' => $q['cat'], 'fr' => $q['fr'], 'ar' => $q['ar'], 'en' => $q['en']];
        if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');
        ok(['v' => questions_version(), 'items' => $items]);
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
        // Impact mondial : pays des participants [code, participants, positif, aucun, négatif]…
        $countries = [];
        foreach ($pdo->query("SELECT p.cc AS cc, COUNT(DISTINCT a.pid) AS n, SUM(a.v = 1) AS pos, SUM(a.v = 0) AS non, SUM(a.v = -1) AS neg
            FROM answers a JOIN people p ON p.pid = a.pid WHERE p.cc != '' GROUP BY p.cc ORDER BY n DESC, pos DESC LIMIT 40") as $r) {
            $countries[] = [(string) $r['cc'], (int) $r['n'], (int) $r['pos'], (int) $r['non'], (int) $r['neg']];
        }
        // … et profils : combien de participants (5 réponses ou plus) ont 0-10 %, 10-20 %… d'impacts positifs.
        $profiles = array_fill(0, 10, 0);
        foreach ($pdo->query('SELECT MIN(9, (p * 10) / n) AS b, COUNT(*) AS c FROM (SELECT SUM(v = 1) AS p, COUNT(*) AS n FROM answers GROUP BY pid HAVING COUNT(*) >= 5) GROUP BY b') as $r) {
            $profiles[(int) $r['b']] = (int) $r['c'];
        }
        $data = [
            'participants' => (int) $pdo->query('SELECT COUNT(DISTINCT pid) FROM answers')->fetchColumn(),
            'answers'      => (int) $pdo->query('SELECT COUNT(*) FROM answers')->fetchColumn(),
            'stories'      => (int) $pdo->query("SELECT COUNT(*) FROM stories WHERE status = 'published'")->fetchColumn(),
            'countries'    => (int) $pdo->query("SELECT COUNT(DISTINCT p.cc) FROM people p WHERE p.cc != '' AND EXISTS (SELECT 1 FROM answers a WHERE a.pid = p.pid)")->fetchColumn(),
            'world'        => $countries,
            'profiles'     => $profiles,
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
        if (!is_array($set) || count($set) > 1500) fail('generic');
        $qs = questions();
        $maxT = (int) (microtime(true) * 1000) + 60000;
        $pdo->beginTransaction();
        if (!empty($b['reset'])) $pdo->prepare('DELETE FROM answers WHERE pid = ?')->execute([$pid]);
        $cc = tz_country($b['tz'] ?? '');
        if ($cc !== '') $pdo->prepare('INSERT OR REPLACE INTO people (pid, cc, seen) VALUES (?, ?, ?)')->execute([$pid, $cc, time()]);
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
        $uid = (int) $u['id'];
        ok(['user' => public_user($u), 'answers' => user_answers($uid), 'stories' => own_stories($uid),
            'reactions' => user_reactions('u' . $uid), 'unread' => unread_count($uid)]);
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
        $u = user_row($uid);
        $mailed = true;
        try {
            [$subj, $html, $text] = compose_welcome($u);
            send_mail($u['email'], $subj, $html, $text);
        } catch (Throwable $e) {
            $mailed = false;
            error_log('impact welcome: ' . $e->getMessage());
        }
        signed_in($u, $csrf, ['mailed' => $mailed]);
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
        if ($u && (string) $u['pass'] === '' && $u['google_sub']) fail('use_google', 401); // compte créé avec Google
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
        signed_in($u, $csrf);
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
                // Même adresse, confirmée par Google : les deux façons de se connecter mènent au même compte.
                $pdo->prepare('UPDATE users SET google_sub = ?, verified = 1, vtoken = NULL WHERE id = ?')->execute([$sub, $u['id']]);
            } else {
                if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email');
                $name = str_clean((string) ($c['given_name'] ?? ($c['name'] ?? '')), 40);
                if ($name === '') $name = str_clean((string) strstr($email, '@', true), 40);
                $pdo->prepare("INSERT INTO users (email, name, pass, lang, created, verified, utoken, opt_daily, opt_digest, google_sub) VALUES (?, ?, '', ?, ?, 1, ?, ?, ?, ?)")
                    ->execute([$email, $name, impact_lang($b['lang'] ?? 'fr'), time(), token(),
                        array_key_exists('opt_daily', $b) && empty($b['opt_daily']) ? 0 : 1,
                        array_key_exists('opt_digest', $b) && empty($b['opt_digest']) ? 0 : 1, $sub]);
                $u = ['id' => (int) $pdo->lastInsertId()];
                $created = true;
            }
            $u = user_row((int) $u['id']);
        }
        $uid = (int) $u['id'];
        merge_device($uid, $b['device'] ?? '');
        claim_stories($uid, $b['stories'] ?? []);
        $csrf = start_session($uid);
        signed_in($u, $csrf, ['created' => $created]);
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
        foreach (['opt_daily', 'opt_digest', 'opt_msg'] as $k) {
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
        $csrf = start_session($uid);
        signed_in(user_row($uid), $csrf);
        break;
    }

    case 'delete_account': {
        $u = require_user();
        // Compte créé avec Google (sans mot de passe) : la session et le jeton CSRF suffisent, avec une confirmation.
        $okDel = (string) $u['pass'] === '' ? !empty($b['confirm']) : password_verify((string) ($b['password'] ?? ''), $u['pass']);
        if (!$okDel) {
            usleep(350000);
            fail('bad_login', 401);
        }
        drop_reactions('u' . $u['id']);
        $pdo->prepare('DELETE FROM answers WHERE pid = ?')->execute(['u' . $u['id']]);
        $pdo->prepare('DELETE FROM people WHERE pid = ?')->execute(['u' . $u['id']]);
        $pdo->prepare('UPDATE donations SET uid = NULL WHERE uid = ?')->execute([$u['id']]);
        // sessions, récits, conversations et messages : ON DELETE CASCADE
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$u['id']]);
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
        if (!$u) fail('login_required', 401); // partager une expérience demande un compte
        if (!empty($b['website'])) fail('generic'); // champ piège invisible : robot
        rate('story', 30, 3600);
        $qid = (int) ($b['qid'] ?? 0);
        if (!isset(questions(true)[$qid])) fail('generic');
        $exp = str_clean((string) ($b['exp'] ?? ''), 1500);
        $imp = str_clean((string) ($b['imp'] ?? ''), 1000);
        if (mb_strlen($exp) < 15 || mb_strlen($imp) < 10) fail('too_short');
        $author = empty($b['anon']) ? str_clean((string) ($b['author'] ?? ''), 40) : '';
        $lang = impact_lang($b['lang'] ?? 'fr');
        $status = empty($b['public']) ? 'private'
            : ((IMPACT_MODERATION === 'manual' || looks_spammy($exp . ' ' . $imp)) ? 'pending' : 'published');
        $now = time();
        $id = (int) ($b['id'] ?? 0);
        if (!$id) {
            // Un seul récit par question et par compte : on met à jour l'existant.
            $s = $pdo->prepare('SELECT id FROM stories WHERE uid = ? AND qid = ?');
            $s->execute([$u['id'], $qid]);
            $id = (int) ($s->fetchColumn() ?: 0);
        }
        if ($id) {
            $st = story_for_edit($id, $u, $b['token'] ?? null);
            if ($st['status'] === 'hidden' && $status === 'published') $status = 'pending';
            $pdo->prepare('UPDATE stories SET qid = ?, author = ?, exp = ?, imp = ?, lang = ?, status = ?, updated = ? WHERE id = ?')
                ->execute([$qid, $author, $exp, $imp, $lang, $status, $now, $id]);
        } else {
            $pdo->prepare('INSERT INTO stories (qid, uid, author, exp, imp, lang, status, ehash, iph, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$qid, $u['id'], $author, $exp, $imp, $lang, $status, hash('sha256', token(16)), ip_hash(), $now, $now]);
            $id = (int) $pdo->lastInsertId();
        }
        $s = $pdo->prepare('SELECT * FROM stories WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        if ($status === 'pending') notify_moderator($row);
        meta_set('stats_cache', '');
        ok(['story' => story_row($row, true)]);
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

    case 'react': {
        if (!$isPost) fail('generic', 405);
        $u = user_for_write();
        $device = $b['device'] ?? '';
        if (!$u && !valid_device($device)) fail('generic');
        $pid = $u ? 'u' . $u['id'] : 'd' . $device;
        $id = (int) ($b['id'] ?? 0);
        $v = (int) ($b['v'] ?? 0);
        if (!in_array($v, [-1, 0, 1], true)) fail('generic');
        rate('react', 300, 3600);
        // Sans compte, rien n'empêche d'inventer des appareils : on limite par connexion et par récit.
        // (Pas pour les comptes : une seule réaction par personne, et beaucoup partagent la même IP mobile.)
        if (!$u) rate('react:' . $id, 20, 86400);
        $s = $pdo->prepare("SELECT uid FROM stories WHERE id = ? AND status = 'published'");
        $s->execute([$id]);
        $st = $s->fetch();
        if (!$st) fail('not_found', 404);
        if ($u && (int) $st['uid'] === (int) $u['id']) fail('own');
        $pdo->beginTransaction();
        $s = $pdo->prepare('SELECT v FROM reactions WHERE sid = ? AND pid = ?');
        $s->execute([$id, $pid]);
        $old = (int) ($s->fetchColumn() ?: 0);
        if ($v === 0) $pdo->prepare('DELETE FROM reactions WHERE sid = ? AND pid = ?')->execute([$id, $pid]);
        else $pdo->prepare('INSERT OR REPLACE INTO reactions (sid, pid, v) VALUES (?, ?, ?)')->execute([$id, $pid, $v]);
        react_adjust($id, $old, $v);
        $pdo->commit();
        $s = $pdo->prepare('SELECT likes, dislikes FROM stories WHERE id = ?');
        $s->execute([$id]);
        $c = $s->fetch();
        ok(['likes' => (int) $c['likes'], 'dislikes' => (int) $c['dislikes'], 'v' => $v]);
        break;
    }

    /* ------------------------------------------ conversations privées */

    case 'inbox': {
        $u = require_user();
        $uid = (int) $u['id'];
        $s = $pdo->prepare('SELECT t.*, s.qid, s.exp, s.author, ug.name AS guest_name
            FROM threads t JOIN stories s ON s.id = t.sid JOIN users ug ON ug.id = t.guest
            WHERE t.owner = ? OR t.guest = ? ORDER BY t.updated DESC LIMIT 100');
        $s->execute([$uid, $uid]);
        $items = array_map(function ($t) use ($uid) { return thread_view($t, $uid); }, $s->fetchAll());
        ok(['items' => $items, 'unread' => unread_count($uid)]);
        break;
    }

    case 'thread': {
        $u = require_user();
        $uid = (int) $u['id'];
        $tid = (int) ($_GET['id'] ?? 0);
        if (!$tid) {
            // Le lecteur ouvre la conversation depuis un récit : elle n'existe peut-être pas encore.
            $sid = (int) ($_GET['sid'] ?? 0);
            $s = $pdo->prepare('SELECT id FROM threads WHERE sid = ? AND guest = ?');
            $s->execute([$sid, $uid]);
            $tid = (int) ($s->fetchColumn() ?: 0);
            if (!$tid) {
                $st = dm_story($sid, $uid);
                ok(['thread' => null, 'messages' => [], 'story' => ['id' => (int) $st['id'], 'qid' => (int) $st['qid'],
                    'author' => (string) $st['author'], 'exp' => mb_strimwidth((string) $st['exp'], 0, 180, '…')]]);
            }
        }
        $t = thread_row($tid, $uid);
        $s = $pdo->prepare('SELECT id, uid, body, created FROM messages WHERE tid = ? ORDER BY id DESC LIMIT 300');
        $s->execute([$tid]);
        $msgs = array_reverse(array_map(function ($m) use ($uid) {
            return ['id' => (int) $m['id'], 'mine' => (int) $m['uid'] === $uid, 'body' => (string) $m['body'], 'at' => (int) $m['created']];
        }, $s->fetchAll()));
        $role = (int) $t['owner'] === $uid ? 'owner' : 'guest';
        if ($msgs) {
            $pdo->prepare("UPDATE threads SET {$role}_read = ? WHERE id = ?")->execute([end($msgs)['id'], $tid]);
            $t[$role . '_read'] = end($msgs)['id'];
        }
        ok(['thread' => thread_view($t, $uid), 'messages' => $msgs, 'unread' => unread_count($uid)]);
        break;
    }

    case 'msg_send': {
        if (!$isPost) fail('generic', 405);
        $u = user_for_write();
        if (!$u) fail('login_required', 401); // une conversation privée demande un compte
        $uid = (int) $u['id'];
        $body = str_clean((string) ($b['body'] ?? ''), 1000);
        if (mb_strlen($body) < 2) fail('too_short');
        rate('msg', 60, 3600);
        $s = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE uid = ? AND created > ?');
        $s->execute([$uid, time() - 3600]);
        if ((int) $s->fetchColumn() >= 40) fail('rate', 429);
        $now = time();
        $tid = (int) ($b['tid'] ?? 0);
        if (!$tid) {
            // Seul le lecteur ouvre une conversation ; l'auteur du récit y répond.
            $st = dm_story((int) ($b['sid'] ?? 0), $uid);
            $pdo->prepare('INSERT OR IGNORE INTO threads (sid, owner, guest, created, updated) VALUES (?, ?, ?, ?, ?)')
                ->execute([$st['id'], $st['uid'], $uid, $now, $now]);
            $s = $pdo->prepare('SELECT id FROM threads WHERE sid = ? AND guest = ?');
            $s->execute([$st['id'], $uid]);
            $tid = (int) $s->fetchColumn();
        }
        $t = thread_row($tid, $uid);
        if ((int) $t['blocked']) fail('blocked', 403);
        $pdo->prepare('INSERT INTO messages (tid, uid, body, created) VALUES (?, ?, ?, ?)')->execute([$tid, $uid, $body, $now]);
        $mid = (int) $pdo->lastInsertId();
        $role = (int) $t['owner'] === $uid ? 'owner' : 'guest';
        $pdo->prepare("UPDATE threads SET updated = ?, {$role}_read = ? WHERE id = ?")->execute([$now, $mid, $tid]);
        notify_message($t, $uid, $body);
        ok(['thread' => thread_view(thread_row($tid, $uid), $uid), 'message' => ['id' => $mid, 'mine' => true, 'body' => $body, 'at' => $now]]);
        break;
    }

    case 'thread_block': {
        if (!$isPost) fail('generic', 405);
        $u = require_user();
        $uid = (int) $u['id'];
        $t = thread_row((int) ($b['tid'] ?? 0), $uid);
        // Chacun peut bloquer la conversation ; seul celui qui l'a bloquée peut la rouvrir.
        if (!empty($b['on'])) $pdo->prepare('UPDATE threads SET blocked = ? WHERE id = ? AND blocked = 0')->execute([$uid, $t['id']]);
        else $pdo->prepare('UPDATE threads SET blocked = 0 WHERE id = ? AND blocked = ?')->execute([$t['id'], $uid]);
        ok(['thread' => thread_view(thread_row((int) $t['id'], $uid), $uid)]);
        break;
    }

    /* ------------------------------------------------------- soutien */

    case 'support_start': {
        if (!$isPost) fail('generic', 405);
        if (!sofiz_active()) fail('pay_off');
        $u = user_for_write();
        rate('support', 10, 3600);
        $amount = (int) ($b['amount'] ?? 0);
        if ($amount < DON_MIN || $amount > DON_MAX) fail('bad_amount');
        $name = str_clean((string) ($b['name'] ?? ''), 60);
        if ($name === '') fail('bad_name');
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email');
        $phone = sofiz_phone((string) ($b['phone'] ?? ''));
        if (strlen($phone) < 10 || strlen($phone) > 16) fail('bad_phone');
        $lang = impact_lang($b['lang'] ?? 'fr');
        $ref = token(12);
        $pdo->prepare('INSERT INTO donations (ref, amount, name, email, phone, message, public, lang, uid, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ref, $amount, $name, $email, $phone, str_clean((string) ($b['message'] ?? ''), 300),
                empty($b['public']) ? 0 : 1, $lang, $u ? $u['id'] : null, time()]);
        $id = (int) $pdo->lastInsertId();
        $res = sofiz_create([
            'amount' => $amount, 'name' => $name, 'phone' => $phone, 'email' => $email, 'lang' => $lang,
            'return_url'  => site_url() . 'support.php?r=' . $ref,
            'webhook_url' => site_url() . 'support.php?hook=1',
            'reference'   => 'DON-' . $id,
            'memo'        => 'Soutien Défi Impact',
        ]);
        if (!$res['ok']) {
            $pdo->prepare("UPDATE donations SET status = 'failed', note = ? WHERE id = ?")->execute([mb_substr('Création refusée : ' . ($res['message'] ?? ''), 0, 200), $id]);
            fail('pay_unavailable', 502);
        }
        $pdo->prepare('UPDATE donations SET tx = ?, cib = ? WHERE id = ?')->execute([$res['transaction_id'], $res['cib_transaction_id'], $id]);
        ok(['url' => $res['payment_url']]);
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
        $qs = questions(true);
        $items = array_map(function ($r) use ($qs) {
            return story_row($r, true) + ['reports' => (int) $r['reports'], 'email' => (string) ($r['email'] ?? ''),
                'question' => qtext($qs[(int) $r['qid']] ?? [], 'fr')];
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

    /* ------------------------------------------------- questions (admin) */

    case 'mod_questions': {
        require_admin();
        $n = [];
        foreach ($pdo->query('SELECT qid, v, COUNT(*) AS n FROM answers GROUP BY qid, v') as $r) $n[(int) $r['qid']][(int) $r['v'] + 1] = (int) $r['n'];
        $st = [];
        foreach ($pdo->query('SELECT qid, COUNT(*) AS n FROM stories GROUP BY qid') as $r) $st[(int) $r['qid']] = (int) $r['n'];
        $items = [];
        foreach (questions(true) as $q) {
            $d = $n[$q['id']] ?? [];
            $items[] = $q + ['n' => [(int) ($d[0] ?? 0), (int) ($d[1] ?? 0), (int) ($d[2] ?? 0)], 'stories' => $st[$q['id']] ?? 0];
        }
        ok(['items' => $items]);
        break;
    }

    case 'mod_q_save': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $cat = (string) ($b['cat'] ?? '');
        if (!in_array($cat, IMPACT_CATS, true)) fail('Univers invalide.');
        $t = [];
        foreach (IMPACT_LANGS as $l) $t[$l] = str_clean((string) ($b[$l] ?? ''), 300);
        if ($t['fr'] === '' && $t['ar'] === '' && $t['en'] === '') fail('Écris la question dans au moins une langue.');
        $active = array_key_exists('active', $b) && empty($b['active']) ? 0 : 1;
        $id = (int) ($b['id'] ?? 0);
        if ($id) {
            $s = $pdo->prepare('UPDATE questions SET cat = ?, fr = ?, ar = ?, en = ?, active = ?, updated = ? WHERE id = ?');
            $s->execute([$cat, $t['fr'], $t['ar'], $t['en'], $active, time(), $id]);
            if (!$s->rowCount()) fail('Question introuvable.', 404);
        } else {
            $pos = (int) $pdo->query('SELECT COALESCE(MAX(pos), 0) + 1 FROM questions')->fetchColumn();
            $pdo->prepare('INSERT INTO questions (cat, fr, ar, en, active, pos, updated) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cat, $t['fr'], $t['ar'], $t['en'], $active, $pos, time()]);
            $id = (int) $pdo->lastInsertId();
        }
        questions_changed();
        $s = $pdo->prepare('SELECT id, cat, fr, ar, en, active FROM questions WHERE id = ?');
        $s->execute([$id]);
        $item = $s->fetch();
        $item['id'] = (int) $item['id'];
        $item['active'] = (int) $item['active'];
        ok(['item' => $item]);
        break;
    }

    case 'mod_q_delete': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $id = (int) ($b['id'] ?? 0);
        // Suppression définitive : la question, ses réponses et ses récits.
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM digest_seen WHERE sid IN (SELECT id FROM stories WHERE qid = ?)')->execute([$id]);
        $pdo->prepare('DELETE FROM stories WHERE qid = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM answers WHERE qid = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
        $pdo->commit();
        questions_changed();
        ok();
        break;
    }

    /* ------------------------------------------- soutiens et réglages (admin) */

    case 'mod_settings': {
        require_admin();
        ok(['settings' => settings_view()]);
        break;
    }

    case 'mod_settings_save': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        if (array_key_exists('google_client_id', $b)) {
            $g = trim((string) $b['google_client_id']);
            if ($g !== '' && !preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $g)) {
                fail('Identifiant client Google invalide : il ressemble à 1234567890-abcdef.apps.googleusercontent.com');
            }
            setting_set('google_client_id', $g);
        }
        if (array_key_exists('sofiz_account', $b)) {
            $a = strtoupper((string) preg_replace('/\s+/', '', (string) $b['sofiz_account']));
            if ($a !== '' && !preg_match(SOFIZ_ACCOUNT_RE, $a)) {
                fail('Identifiant SofizPay invalide : il commence par « G » et compte 56 caractères (vous en avez saisi ' . strlen($a) . ').');
            }
            setting_set('sofiz_account', $a);
        }
        if (array_key_exists('sofiz_sandbox', $b)) setting_set('sofiz_sandbox', empty($b['sofiz_sandbox']) ? '0' : '1');
        if (array_key_exists('sofiz_on', $b)) {
            if (!empty($b['sofiz_on']) && !preg_match(SOFIZ_ACCOUNT_RE, sofiz_account())) {
                fail("Renseignez d'abord l'identifiant du compte SofizPay.");
            }
            setting_set('sofiz_on', empty($b['sofiz_on']) ? '0' : '1');
        }
        if (array_key_exists('methods', $b)) {
            if (!is_array($b['methods']) || count($b['methods']) > 10) fail('10 moyens de soutien au maximum.');
            $clean = [];
            foreach ($b['methods'] as $m) {
                if (!is_array($m)) continue;
                $type = in_array($m['type'] ?? '', SUPPORT_TYPES, true) ? $m['type'] : 'other';
                $value = str_clean((string) ($m['value'] ?? ''), 120);
                $link = trim((string) ($m['link'] ?? ''));
                if ($value === '' && $link === '') fail('Chaque moyen de soutien a besoin d\'un identifiant ou d\'un lien.');
                if ($link !== '' && (!preg_match('~^https://~i', $link) || !filter_var($link, FILTER_VALIDATE_URL) || strlen($link) > 200)) {
                    fail('Lien invalide : il doit commencer par https:// (ex. https://paypal.me/votre-nom).');
                }
                $clean[] = ['type' => $type, 'holder' => str_clean((string) ($m['holder'] ?? ''), 80), 'value' => $value,
                    'link' => $link, 'note' => str_clean((string) ($m['note'] ?? ''), 200)];
            }
            setting_set('support_methods', (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        ok(['settings' => settings_view()]);
        break;
    }

    case 'mod_donations': {
        require_admin();
        $status = (string) ($_GET['status'] ?? '');
        $where = in_array($status, ['paid', 'pending', 'failed'], true) ? 'WHERE status = ' . $pdo->quote($status) : '';
        $items = array_map('donation_view', $pdo->query("SELECT * FROM donations $where ORDER BY id DESC LIMIT 200")->fetchAll());
        $totals = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'sum' => 0];
        foreach ($pdo->query('SELECT status, COUNT(*) AS n, SUM(amount) AS s FROM donations GROUP BY status') as $r) {
            if (isset($totals[$r['status']])) $totals[$r['status']] = (int) $r['n'];
            if ($r['status'] === 'paid') $totals['sum'] = (int) $r['s'];
        }
        ok(['items' => $items, 'totals' => $totals]);
        break;
    }

    case 'mod_donation_check': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $s = $pdo->prepare('SELECT * FROM donations WHERE id = ?');
        $s->execute([(int) ($b['id'] ?? 0)]);
        $d = $s->fetch();
        if (!$d) fail('Soutien introuvable.', 404);
        donation_confirm($d);
        $s->execute([(int) $d['id']]);
        ok(['item' => donation_view($s->fetch())]);
        break;
    }

    default:
        fail('not_found', 404);
}
