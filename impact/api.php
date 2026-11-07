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
 *   POST support_start {amount,message,public,lang}  -> URL de paiement SofizPay (nom/email du
 *        compte connecté, ou valeurs neutres sans compte ; retour et notification : support.php)
 *   POST ping      {device,view}  signal de présence (admin : qui est en ligne en ce moment)
 *   GET  pros                     pages pro en ligne et questions auxquelles elles sont liées
 *   GET  pro_me | pro_suggest ?domain=&kw=   POST pro_save | pro_logo (fichier) | pro_questions_set {qids} | pro_delete
 *   POST subscribe {email,device,lang}   alertes par email sans compte (confirmation par lien)
 *   POST track {device,ev:[[pv,view]|[imp,pro,qid]|[view|web|wa|call|mail,pro]]}   compteurs d'affichage
 *   GET  pro_stats ?days=7|30|90   statistiques de la page pro du compte connecté
 *   GET  sub_confirm ?token= | sub_unsub ?token=   pages (confirmation, désabonnement)
 *   GET|POST unsub ?type=daily|digest|msg|all&token=   page de désabonnement
 *   mod_* : administration (statistiques, inscrits, récits, questions, soutiens, réglages), réservée
 *           à la session de /admin
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/google.php';
require __DIR__ . '/lib/sofizpay.php';
require __DIR__ . '/lib/pros.php';
require __DIR__ . '/lib/views.php';
require __DIR__ . '/lib/automations.php';
require __DIR__ . '/lib/campaigns.php';

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
if ($action === 'sub_confirm') page_sub_confirm();
if ($action === 'sub_unsub') page_sub_unsub($isPost);
if ($action === 'go') page_go();
if ($action === 'inv_unsub') page_inv_unsub($isPost);

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
        'opt_msg' => (bool) $u['opt_msg'], 'opt_tips' => (bool) $u['opt_tips'], 'opt_pro' => (bool) $u['opt_pro'],
        'google' => !empty($u['google_sub']), 'has_pass' => (string) $u['pass'] !== '',
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

/** Supprime un compte et tout ce qui lui appartient (par la personne elle-même ou depuis l'admin). */
function delete_user(int $uid): void
{
    $pdo = db();
    $pid = 'u' . $uid;
    drop_reactions($pid);
    $pdo->prepare('DELETE FROM answers WHERE pid = ?')->execute([$pid]);
    $pdo->prepare('DELETE FROM people WHERE pid = ?')->execute([$pid]);
    $pdo->prepare('DELETE FROM presence WHERE pid = ?')->execute([$pid]);
    $pdo->prepare('UPDATE donations SET uid = NULL WHERE uid = ?')->execute([$uid]);
    $pdo->prepare('DELETE FROM subscribers WHERE pid = ?')->execute([$pid]);
    $pdo->prepare('DELETE FROM auto_log WHERE uid = ?')->execute([$uid]);
    $s = $pdo->prepare('SELECT logo FROM pros WHERE uid = ?');
    $s->execute([$uid]);
    if ($logo = $s->fetchColumn()) pro_delete_logo((string) $logo);
    meta_set('pros_cache', '');
    // sessions, récits, page pro, conversations et messages : ON DELETE CASCADE
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
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
    $pdo->prepare('UPDATE subscribers SET pid = ? WHERE pid = ?')->execute(['u' . $uid, 'd' . $device]);
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

const STORY_PUBLIC_COLS = 'id, qid, uid, pro_id, author, exp, imp, lang, likes, dislikes, created';

function story_row(array $r, bool $own = false): array
{
    $o = [
        'id' => (int) $r['id'], 'qid' => (int) $r['qid'], 'author' => (string) $r['author'],
        'exp' => (string) $r['exp'], 'imp' => (string) $r['imp'], 'lang' => (string) $r['lang'],
        'likes' => (int) $r['likes'], 'dislikes' => (int) ($r['dislikes'] ?? 0), 'created' => (int) $r['created'],
    ];
    // Récit publié au nom d'une page pro : son nom, son logo et le lien vers sa page.
    $pros = pros_active();
    if (!empty($r['pro_id']) && isset($pros[(int) $r['pro_id']])) $o['pro'] = pro_public($pros[(int) $r['pro_id']]);
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

/** Récits personnels d'un compte (ceux publiés au nom de sa page pro sont dans son espace pro). */
function own_stories(int $uid): array
{
    $s = db()->prepare('SELECT * FROM stories WHERE uid = ? AND pro_id IS NULL ORDER BY id');
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
    $type = in_array($_GET['type'] ?? '', ['daily', 'digest', 'msg', 'tips', 'pro', 'all'], true) ? $_GET['type'] : 'all';
    $u = null;
    if (preg_match('/^[a-f0-9]{48}$/', $tok)) {
        $s = db()->prepare('SELECT * FROM users WHERE utoken = ?');
        $s->execute([$tok]);
        $u = $s->fetch() ?: null;
    }
    $l = $u ? impact_lang($u['lang']) : impact_lang($_GET['lang'] ?? 'fr');
    $done = false;
    if ($u && $isPost) {
        $sql = ['daily' => 'opt_daily = 0', 'digest' => 'opt_digest = 0', 'msg' => 'opt_msg = 0', 'tips' => 'opt_tips = 0', 'pro' => 'opt_pro = 0',
            'all' => 'opt_daily = 0, opt_digest = 0, opt_msg = 0, opt_tips = 0, opt_pro = 0'][$type];
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

/* ---------------------------------------- invitations (campagnes) */

/** Lien d'une invitation : le clic est noté, puis la personne arrive sur le défi (jamais ailleurs). */
function page_go(): void
{
    $t = (string) ($_GET['t'] ?? '');
    $to = (string) ($_GET['to'] ?? '');
    if (!preg_match('~^[?#][^\r\n\\\\]*$~', $to)) $to = '';
    if (preg_match('/^[a-f0-9]{48}$/', $t)) {
        db()->prepare('UPDATE campaign_rcpt SET clicked = ? WHERE token = ? AND clicked = 0')->execute([time(), $t]);
    }
    header('Location: ' . site_url() . $to, true, 302);
    exit;
}

/** Petite page HTML (désabonnements) dans le style des emails. */
function mail_page(string $l, string $title, string $body): void
{
    $font = mail_font($l);
    echo '<!DOCTYPE html><html lang="' . $l . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . ' — ' . h(ms($l, 'brand')) . '</title></head>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#eef0f4;font:16px/1.6 ' . $font . ';color:#20283f;padding:16px;box-sizing:border-box">'
        . '<main style="max-width:460px;width:100%;background:#fff;border-radius:24px;padding:32px;box-shadow:0 8px 30px -12px rgba(11,16,32,.2)">'
        . '<p style="margin:0 0 6px;font-weight:800;color:#0b1020">' . h(ms($l, 'brand')) . '</p>'
        . '<h1 style="margin:0 0 16px;font-size:1.4rem;color:#0b1020">' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

/** « Ne plus jamais recevoir d'invitation » : confirmation par un bouton (les robots de messagerie ouvrent les liens). */
function page_inv_unsub(bool $isPost): void
{
    header('Content-Type: text/html; charset=utf-8');
    $t = (string) ($_GET['t'] ?? '');
    $r = null;
    if (preg_match('/^[a-f0-9]{48}$/', $t)) {
        $s = db()->prepare('SELECT r.*, c.lang FROM campaign_rcpt r JOIN campaigns c ON c.id = r.cid WHERE r.token = ?');
        $s->execute([$t]);
        $r = $s->fetch() ?: null;
    }
    $l = $r ? impact_lang($r['lang']) : impact_lang($_GET['lang'] ?? 'fr');
    $back = '<p style="margin:22px 0 0"><a href="./?lang=' . $l . '" style="color:#2f5bff">' . h(cm($l, 'back')) . '</a></p>';
    if (!$r) mail_page($l, cm($l, 'u_title'), '<p>' . h(cm($l, 'u_bad')) . '</p>' . $back);
    if ($isPost) {
        db()->prepare('INSERT OR IGNORE INTO email_optout (email, at, source) VALUES (?, ?, ?)')->execute([$r['email'], time(), 'inv']);
        db()->prepare("UPDATE campaign_rcpt SET status = 'skipped', error = 'optout' WHERE email = ? AND status = 'queued'")->execute([$r['email']]);
        mail_page($l, cm($l, 'u_title'), '<p>' . h(cm($l, 'u_done')) . '</p>' . $back);
    }
    mail_page($l, cm($l, 'u_title'), '<p style="font-size:1.1rem;font-weight:700">' . h(cm($l, 'u_q', ['email' => $r['email']])) . '</p>'
        . '<form method="post"><button style="margin-top:8px;padding:13px 26px;border:0;border-radius:999px;background:#0b1020;color:#fff;font:700 15px ' . mail_font($l) . ';cursor:pointer">'
        . h(cm($l, 'u_ok')) . '</button></form>' . $back);
}

/* -------------------------------------------- abonnés sans compte */

function subscriber_by_token(): ?array
{
    $tok = (string) ($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $tok)) return null;
    $s = db()->prepare('SELECT * FROM subscribers WHERE token = ?');
    $s->execute([$tok]);
    return $s->fetch() ?: null;
}

/** Lien de l'email de confirmation : l'adresse est confirmée, retour sur le défi. */
function page_sub_confirm(): void
{
    $sub = subscriber_by_token();
    if ($sub && !$sub['confirmed']) {
        db()->prepare('UPDATE subscribers SET confirmed = 1, confirmed_at = ? WHERE id = ?')->execute([time(), $sub['id']]);
    }
    header('Location: ./?lang=' . ($sub ? impact_lang($sub['lang']) : 'fr') . '&sub=' . ($sub ? 'ok' : 'bad'), true, 302);
    exit;
}

/** Lien « ne plus recevoir » des alertes : confirmation par un bouton (les robots de messagerie ouvrent les liens). */
function page_sub_unsub(bool $isPost): void
{
    header('Content-Type: text/html; charset=utf-8');
    $sub = subscriber_by_token();
    $l = $sub ? impact_lang($sub['lang']) : impact_lang($_GET['lang'] ?? 'fr');
    $done = false;
    if ($sub && $isPost) {
        db()->prepare('DELETE FROM subscribers WHERE id = ?')->execute([$sub['id']]);
        $done = true;
    }
    $font = mail_font($l);
    if (!$sub && !$done) $body = '<p>' . h(ms($l, 'unsub_bad')) . '</p>';
    elseif ($done) $body = '<p>' . h(ms($l, 'sub_unsub_done')) . '</p>';
    else $body = '<p style="font-size:1.15rem;font-weight:700">' . h(ms($l, 'sub_unsub_q')) . '</p>'
        . '<p style="color:#6b7185;font-size:.9rem">' . h($sub['email']) . '</p>'
        . '<form method="post"><button style="margin-top:8px;padding:13px 26px;border:0;border-radius:999px;background:#0b1020;color:#fff;font:700 15px ' . $font . ';cursor:pointer">'
        . h(ms($l, 'unsub_confirm')) . '</button></form>';
    echo '<!DOCTYPE html><html lang="' . $l . '" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h(ms($l, 'unsub_title')) . ' — ' . h(ms($l, 'brand')) . '</title></head>'
        . '<body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#f0f1f4;font:16px/1.6 ' . $font . ';color:#20283f;padding:16px;box-sizing:border-box">'
        . '<main style="max-width:440px;width:100%;background:#fff;border-radius:22px;padding:32px;box-shadow:0 8px 30px -12px rgba(11,16,32,.2)">'
        . '<p style="margin:0 0 6px;font-weight:800;color:#0b1020">' . h(ms($l, 'brand')) . '</p>'
        . '<h1 style="margin:0 0 16px;font-size:1.4rem;color:#0b1020">' . h(ms($l, 'unsub_title')) . '</h1>' . $body
        . '<p style="margin:22px 0 0"><a href="./?lang=' . $l . '" style="color:#2f5bff">' . h(ms($l, 'back')) . '</a></p>'
        . '</main></body></html>';
    exit;
}

/* --------------------------------------------------------- pages pro */

/** Page pro du compte connecté, avec tout ce qu'il peut modifier. */
function own_pro(int $uid): ?array
{
    $s = db()->prepare('SELECT * FROM pros WHERE uid = ?');
    $s->execute([$uid]);
    return $s->fetch() ?: null;
}
/** Supprime une page pro, son logo et les expériences publiées en son nom (le compte et ses récits personnels restent). */
function delete_pro(array $p): void
{
    $pdo = db();
    $pdo->beginTransaction();
    foreach (['digest_seen', 'sub_seen'] as $t) $pdo->prepare("DELETE FROM $t WHERE sid IN (SELECT id FROM stories WHERE pro_id = ?)")->execute([$p['id']]);
    $pdo->prepare('DELETE FROM stories WHERE pro_id = ?')->execute([$p['id']]);
    $pdo->prepare('DELETE FROM pros WHERE id = ?')->execute([$p['id']]);
    $pdo->commit();
    pro_delete_logo((string) $p['logo']);
    meta_set('pros_cache', '');
    meta_set('stats_cache', '');
}

function pro_owner_view(array $p): array
{
    $s = db()->prepare('SELECT qid FROM pro_questions WHERE pro_id = ? ORDER BY qid');
    $s->execute([$p['id']]);
    $qids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    $s = db()->prepare('SELECT * FROM stories WHERE pro_id = ? ORDER BY id DESC');
    $s->execute([$p['id']]);
    return pro_public($p) + [
        'keywords' => (string) $p['keywords'], 'bio' => (string) $p['bio'], 'website' => (string) $p['website'],
        'phone' => (string) $p['phone'], 'email' => (string) $p['email'], 'status' => (string) $p['status'],
        'questions' => $qids, 'stories' => array_map(function ($r) { return story_row($r, true); }, $s->fetchAll()),
    ];
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

/* -------------------------------------------- inscrits (admin) */

const USER_FILTERS = [
    'all'        => '1',
    'verified'   => 'u.verified = 1',
    'unverified' => 'u.verified = 0',
    'google'     => 'u.google_sub IS NOT NULL',
    'daily'      => 'u.verified = 1 AND u.opt_daily = 1',
    'nomail'     => 'u.opt_daily = 0 AND u.opt_digest = 0 AND u.opt_msg = 0',
];

/** Clause WHERE et paramètres pour la liste des inscrits (filtre + recherche nom/email). */
function users_where(string $filter, string $q): array
{
    $where = [USER_FILTERS[$filter] ?? '1'];
    $args = [];
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . strtr(mb_strtolower($q), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $where[] = "(LOWER(u.email) LIKE ? ESCAPE '\\' OR LOWER(u.name) LIKE ? ESCAPE '\\')";
        $args[] = $like;
        $args[] = $like;
    }
    return [implode(' AND ', $where), $args];
}

/** Inscrits avec leur activité : réponses, récits et dernière visite connue. */
function users_query(string $where, array $args, int $limit, int $offset): array
{
    $sql = "SELECT u.id, u.name, u.email, u.lang, u.created, u.verified, u.opt_daily, u.opt_digest, u.opt_msg, u.opt_tips, u.opt_pro,
            u.google_sub IS NOT NULL AS google, u.pass != '' AS has_pass,
            (SELECT COUNT(*) FROM answers a WHERE a.pid = 'u' || u.id) AS answers,
            (SELECT COUNT(*) FROM stories s WHERE s.uid = u.id) AS stories,
            (SELECT MAX(exp) FROM sessions se WHERE se.uid = u.id) AS sexp,
            (SELECT seen FROM people pe WHERE pe.pid = 'u' || u.id) AS pseen,
            (SELECT seen FROM presence pr WHERE pr.pid = 'u' || u.id) AS rseen
        FROM users u WHERE $where ORDER BY u.created DESC, u.id DESC LIMIT $limit OFFSET $offset";
    $s = db()->prepare($sql);
    $s->execute($args);
    $out = [];
    foreach ($s->fetchAll() as $r) {
        // Une session est prolongée à chaque visite (au plus une fois par jour) : sa fin moins sa durée = dernière visite.
        $last = max((int) $r['created'], $r['sexp'] ? (int) $r['sexp'] - SESSION_DAYS * 86400 : 0, (int) $r['pseen'], (int) $r['rseen']);
        $out[] = [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'lang' => (string) $r['lang'],
            'created' => (int) $r['created'], 'last' => min($last, time()), 'verified' => (bool) $r['verified'],
            'google' => (bool) $r['google'], 'has_pass' => (bool) $r['has_pass'],
            'opt_daily' => (bool) $r['opt_daily'], 'opt_digest' => (bool) $r['opt_digest'], 'opt_msg' => (bool) $r['opt_msg'],
            'opt_tips' => (bool) $r['opt_tips'], 'opt_pro' => (bool) $r['opt_pro'],
            'answers' => (int) $r['answers'], 'stories' => (int) $r['stories'],
            'online' => (int) $r['rseen'] > time() - ONLINE_WINDOW,
        ];
    }
    return $out;
}

/** Cellule CSV sûre pour Excel : pas de formule déclenchée par un nom commençant par « = », « + »… */
function csv_cell($v): string
{
    $v = (string) $v;
    if ($v !== '' && strpos('=+-@' . "\t\r", $v[0]) !== false) $v = "'" . $v;
    return '"' . str_replace('"', '""', $v) . '"';
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
        foreach (['opt_daily', 'opt_digest', 'opt_msg', 'opt_tips', 'opt_pro'] as $k) {
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
        delete_user((int) $u['id']);
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
        $proId = null;
        if (!empty($b['as_pro'])) {
            $s = $pdo->prepare('SELECT id, name FROM pros WHERE uid = ?');
            $s->execute([$u['id']]);
            $pro = $s->fetch();
            if (!$pro) fail('no_pro');
            [$proId, $author] = [(int) $pro['id'], (string) $pro['name']];
        }
        $lang = impact_lang($b['lang'] ?? 'fr');
        $status = empty($b['public']) ? 'private'
            : ((IMPACT_MODERATION === 'manual' || looks_spammy($exp . ' ' . $imp)) ? 'pending' : 'published');
        $now = time();
        $id = (int) ($b['id'] ?? 0);
        if (!$id) {
            // Un seul récit par question et par compte (plus un au nom de sa page pro) : on met à jour l'existant.
            $s = $pdo->prepare('SELECT id FROM stories WHERE uid = ? AND qid = ? AND pro_id IS ?');
            $s->execute([$u['id'], $qid, $proId]);
            $id = (int) ($s->fetchColumn() ?: 0);
        }
        if ($id) {
            $st = story_for_edit($id, $u, $b['token'] ?? null);
            // Un récit personnel reste personnel, un récit de la page pro reste celui de la page.
            if ((int) ($st['pro_id'] ?? 0) !== (int) $proId) fail('generic');
            if ($st['status'] === 'hidden' && $status === 'published') $status = 'pending';
            // Date de publication : celle où le récit devient visible (alertes aux abonnés).
            $published = $status === 'published' ? ($st['status'] === 'published' ? (int) $st['published'] : $now) : 0;
            $pdo->prepare('UPDATE stories SET qid = ?, author = ?, exp = ?, imp = ?, lang = ?, status = ?, pro_id = ?, published = ?, updated = ? WHERE id = ?')
                ->execute([$qid, $author, $exp, $imp, $lang, $status, $proId, $published, $now, $id]);
        } else {
            $pdo->prepare('INSERT INTO stories (qid, uid, pro_id, author, exp, imp, lang, status, published, ehash, iph, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$qid, $u['id'], $proId, $author, $exp, $imp, $lang, $status, $status === 'published' ? $now : 0, hash('sha256', token(16)), ip_hash(), $now, $now]);
            $id = (int) $pdo->lastInsertId();
        }
        if ($proId) meta_set('pros_cache', '');
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

    case 'ping': {
        // Signal de présence : la page l'envoie toutes les ~45 s tant qu'elle est visible,
        // pour que l'admin sache combien de personnes sont sur le site en ce moment.
        if (!$isPost) fail('generic', 405);
        $u = user_for_write();
        $device = $b['device'] ?? '';
        if (!$u && !valid_device($device)) fail('generic');
        rate('ping', 50, 1800);
        $pid = $u ? 'u' . $u['id'] : 'd' . $device;
        $view = is_string($b['view'] ?? null) ? substr($b['view'], 0, 20) : '';
        $pdo->prepare('INSERT OR REPLACE INTO presence (pid, seen, view) VALUES (?, ?, ?)')->execute([$pid, time(), $view]);
        if (random_int(1, 50) === 1) $pdo->prepare('DELETE FROM presence WHERE seen < ?')->execute([time() - 3600]);
        ok();
        break;
    }

    /* ------------------------------------------------------ pages pro */

    case 'pros': {
        // Index public : petit, mis en cache 5 minutes (vidé à chaque modification d'une page pro).
        $c = json_decode((string) meta_get('pros_cache'), true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - 300) ok($c['data']);
        $data = ['pros' => array_values(array_map('pro_public', pros_active())), 'q' => (object) pros_by_question(),
            // Libellés des types et domaines [fr, ar, en] pour l'espace pro du défi.
            'kinds' => PRO_KINDS, 'domains' => array_map(function ($d) { return $d[0]; }, PRO_DOMAINS)];
        meta_set('pros_cache', (string) json_encode(['at' => time(), 'data' => $data]));
        ok($data);
        break;
    }

    case 'pro_me': {
        $u = require_user();
        $p = own_pro((int) $u['id']);
        ok(['pro' => $p ? pro_owner_view($p) : null]);
        break;
    }

    case 'pro_suggest': {
        require_user();
        $domain = (string) ($_GET['domain'] ?? '');
        if (!isset(PRO_DOMAINS[$domain])) $domain = 'autre';
        ok(['items' => pro_suggest($domain, mb_substr((string) ($_GET['kw'] ?? ''), 0, 200))]);
        break;
    }

    case 'pro_save': {
        if (!$isPost) fail('generic', 405);
        $u = require_user();
        rate('pro_save', 40, 3600);
        $name = str_clean((string) ($b['name'] ?? ''), 80);
        if (mb_strlen($name) < 2) fail('pro_name');
        $kind = (string) ($b['kind'] ?? '');
        $domain = (string) ($b['domain'] ?? '');
        if (!isset(PRO_KINDS[$kind]) || !isset(PRO_DOMAINS[$domain])) fail('generic');
        $web = trim((string) ($b['website'] ?? ''));
        if ($web !== '' && !preg_match('~^https?://~i', $web)) $web = 'https://' . $web;
        if ($web !== '' && (strlen($web) > 200 || !filter_var($web, FILTER_VALIDATE_URL))) fail('pro_web');
        $phone = trim((string) ($b['phone'] ?? ''));
        if ($phone !== '' && !preg_match('/^\+?[0-9 ().-]{6,24}$/', $phone)) fail('bad_phone');
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) fail('bad_email');
        $vals = [$name, $kind, $domain, str_clean((string) ($b['keywords'] ?? ''), 200), str_clean((string) ($b['city'] ?? ''), 60),
            str_clean((string) ($b['bio'] ?? ''), 800), $web, $phone, $email, impact_lang($b['lang'] ?? $u['lang']), time()];
        $p = own_pro((int) $u['id']);
        if ($p) {
            $pdo->prepare('UPDATE pros SET name = ?, kind = ?, domain = ?, keywords = ?, city = ?, bio = ?, website = ?, phone = ?, email = ?, lang = ?, updated = ? WHERE id = ?')
                ->execute(array_merge($vals, [$p['id']]));
            // Les récits publiés au nom de la page suivent son nouveau nom.
            $pdo->prepare('UPDATE stories SET author = ? WHERE pro_id = ?')->execute([$name, $p['id']]);
        } else {
            // L'adresse de la page est fixée à la création (les liens partagés restent valables).
            $pdo->prepare('INSERT INTO pros (name, kind, domain, keywords, city, bio, website, phone, email, lang, updated, uid, slug, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute(array_merge($vals, [$u['id'], pro_slug($name), time()]));
        }
        meta_set('pros_cache', '');
        ok(['pro' => pro_owner_view(own_pro((int) $u['id']))]);
        break;
    }

    case 'pro_logo': {
        if (!$isPost) fail('generic', 405);
        $u = require_user();
        rate('pro_logo', 20, 3600);
        $p = own_pro((int) $u['id']);
        if (!$p) fail('no_pro');
        try {
            $name = pro_store_logo($_FILES['logo'] ?? []);
        } catch (Throwable $e) {
            fail($e->getMessage() === 'too_big' ? 'logo_big' : 'logo_bad');
        }
        pro_delete_logo((string) $p['logo']);
        $pdo->prepare('UPDATE pros SET logo = ?, updated = ? WHERE id = ?')->execute([$name, time(), $p['id']]);
        meta_set('pros_cache', '');
        ok(['pro' => pro_owner_view(own_pro((int) $u['id']))]);
        break;
    }

    case 'pro_questions_set': {
        if (!$isPost) fail('generic', 405);
        $u = require_user();
        $p = own_pro((int) $u['id']);
        if (!$p) fail('no_pro');
        $qs = questions();
        $ids = [];
        foreach ((array) ($b['qids'] ?? []) as $id) if (isset($qs[(int) $id])) $ids[(int) $id] = 1;
        if (count($ids) > 60) fail('pro_too_many');
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM pro_questions WHERE pro_id = ?')->execute([$p['id']]);
        $ins = $pdo->prepare('INSERT INTO pro_questions (pro_id, qid) VALUES (?, ?)');
        foreach (array_keys($ids) as $id) $ins->execute([$p['id'], $id]);
        $pdo->commit();
        meta_set('pros_cache', '');
        ok(['pro' => pro_owner_view($p)]);
        break;
    }

    case 'pro_delete': {
        if (!$isPost) fail('generic', 405);
        $u = require_user();
        $p = own_pro((int) $u['id']);
        if (!$p) fail('no_pro');
        delete_pro($p);
        ok();
        break;
    }

    /* ------------------------------------------------ compteurs d'affichage */

    case 'track': {
        // Lot d'événements envoyé par le navigateur (défi et pages publiques) : pages vues, affichages
        // des pros, visites de leur page et clics sur leurs contacts. Pas de jeton CSRF : rien de
        // sensible, et l'en-tête X-Requested-With empêche déjà l'envoi depuis un autre site.
        if (!$isPost) fail('generic', 405);
        if (is_bot((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) ok(['n' => 0]);
        $u = current_user();
        $device = $b['device'] ?? '';
        if (!$u && !valid_device($device)) fail('generic');
        // Large : beaucoup de mobiles partagent la même adresse IP chez l'opérateur ; le plafond par
        // personne et par jour (track_events) protège déjà les chiffres.
        rate('track', 1200, 600);
        $n = track_events($u ? 'u' . $u['id'] : 'd' . $device, $u ? (int) $u['id'] : 0, (array) ($b['ev'] ?? []));
        ok(['n' => $n]);
        break;
    }

    case 'pro_stats': {
        $u = require_user();
        $p = own_pro((int) $u['id']);
        if (!$p) fail('no_pro');
        ok(pro_stats_view((int) $p['id'], (int) ($_GET['days'] ?? 30), impact_lang($_GET['lang'] ?? $u['lang'])));
        break;
    }

    /* --------------------------------------- alertes par email sans compte */

    case 'subscribe': {
        if (!$isPost) fail('generic', 405);
        $u = user_for_write();
        if ($u) {
            // Avec un compte : ce sont les emails « impacts positifs » du compte.
            $pdo->prepare('UPDATE users SET opt_digest = 1 WHERE id = ?')->execute([$u['id']]);
            ok(['account' => true, 'verified' => (bool) $u['verified']]);
        }
        rate('subscribe', 6, 3600);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('bad_email');
        $device = $b['device'] ?? '';
        if (!valid_device($device)) fail('generic');
        $lang = impact_lang($b['lang'] ?? 'fr');
        $s = $pdo->prepare('SELECT * FROM subscribers WHERE email = ?');
        $s->execute([$email]);
        $sub = $s->fetch();
        if ($sub) {
            $pdo->prepare('UPDATE subscribers SET pid = ?, lang = ? WHERE id = ?')->execute(['d' . $device, $lang, $sub['id']]);
        } else {
            $pdo->prepare('INSERT INTO subscribers (email, pid, lang, token, created) VALUES (?, ?, ?, ?, ?)')
                ->execute([$email, 'd' . $device, $lang, token(), time()]);
            $s->execute([$email]);
            $sub = $s->fetch();
        }
        $sub['lang'] = $lang;
        // Double confirmation : rien ne part avant le clic sur le lien (personne ne peut inscrire l'adresse d'un autre).
        if (!$sub['confirmed'] && (int) $sub['mailed'] < time() - 120) {
            try {
                [$subj, $html, $text] = compose_sub_confirm($sub);
                send_mail($email, $subj, $html, $text);
                $pdo->prepare('UPDATE subscribers SET mailed = ? WHERE id = ?')->execute([time(), $sub['id']]);
            } catch (Throwable $e) {
                error_log('impact subscribe: ' . $e->getMessage());
                fail('mail', 502);
            }
        }
        ok(['pending' => !$sub['confirmed']]);
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
        // Rien n'est demandé au donateur : le compte connecté fournit nom et email s'il existe,
        // sinon des valeurs neutres (la passerelle exige nom, email et téléphone).
        $name = $u ? str_clean((string) $u['name'], 60) : '';
        $email = $u ? (string) $u['email'] : '';
        $phone = '+213000000000';
        $lang = impact_lang($b['lang'] ?? 'fr');
        $ref = token(12);
        $pdo->prepare('INSERT INTO donations (ref, amount, name, email, phone, message, public, lang, uid, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ref, $amount, $name, $email, $phone, str_clean((string) ($b['message'] ?? ''), 300),
                $u && !empty($b['public']) ? 1 : 0, $lang, $u ? $u['id'] : null, time()]);
        $id = (int) $pdo->lastInsertId();
        $res = sofiz_create([
            'amount' => $amount, 'name' => $name !== '' ? $name : 'Soutien anonyme', 'phone' => $phone,
            'email' => $email !== '' ? $email : mail_from(), 'lang' => $lang,
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
        $pdo->prepare('UPDATE stories SET reports = CASE WHEN ? = \'published\' THEN 0 ELSE reports END,
            published = CASE WHEN ? = \'published\' AND status != \'published\' THEN ? WHEN ? != \'published\' THEN 0 ELSE published END,
            status = ? WHERE id = ?')
            ->execute([$st, $st, time(), $st, $st, (int) ($b['id'] ?? 0)]);
        meta_set('pros_cache', '');
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

    /* ------------------------------------- emails : automatisations et invitations */

    case 'mod_mail': {
        require_admin();
        $d30 = $pdo->prepare('SELECT COUNT(*) FROM auto_log WHERE kind = ? AND mailed = 1 AND sent > ?');
        $all = $pdo->prepare('SELECT COUNT(*) FROM auto_log WHERE kind = ? AND mailed = 1');
        $autos = [];
        foreach (AUTOMATIONS as $k => [$title, $when, $opt]) {
            $d30->execute([$k, time() - 30 * 86400]);
            $all->execute([$k]);
            $autos[] = ['kind' => $k, 'title' => $title, 'when' => $when, 'opt' => $opt, 'on' => auto_on($k), 'd30' => (int) $d30->fetchColumn(), 'total' => (int) $all->fetchColumn()];
        }
        $domains = [];
        foreach (CAMPAIGN_DOMAINS as $d) {
            $domains[] = ['key' => $d, 'label' => pro_label('domain', $d, 'fr'), 'subject' => ['fr' => camp_dom($d, 'fr', 0), 'ar' => camp_dom($d, 'ar', 0), 'en' => camp_dom($d, 'en', 0)]];
        }
        ok([
            'automations' => $autos, 'domains' => $domains,
            'particulier' => ['fr' => cm('fr', 'p_subject'), 'ar' => cm('ar', 'p_subject'), 'en' => cm('en', 'p_subject')],
            'settings' => ['test_to' => setting('mail_test_to') !== '' ? setting('mail_test_to') : IMPACT_NOTIFY_EMAIL, 'inv_per_hour' => camp_per_hour(),
                'inv_sign' => camp_sign(), 'from' => mail_from(), 'smtp' => IMPACT_SMTP_HOST !== ''],
            'campaigns' => camp_list(),
            'optouts' => (int) $pdo->query('SELECT COUNT(*) FROM email_optout')->fetchColumn(),
            'sent_hour' => (int) $pdo->query("SELECT COUNT(*) FROM campaign_rcpt WHERE status IN ('sent', 'failed') AND sent > " . (time() - 3600))->fetchColumn(),
        ]);
        break;
    }

    case 'mod_auto_set': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $k = (string) ($b['kind'] ?? '');
        if (!isset(AUTOMATIONS[$k])) fail('Scénario inconnu.');
        setting_set('auto_' . $k, empty($b['on']) ? '0' : '1');
        // Réactivé : ce qui a été atteint pendant la pause est noté sans envoi au prochain passage.
        if (!empty($b['on'])) setting_set('auto_base_' . $k, '');
        ok();
        break;
    }

    case 'mod_mail_preview':
    case 'mod_mail_test': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $l = impact_lang($b['lang'] ?? 'fr');
        if (($b['type'] ?? '') === 'auto') {
            if (!isset(AUTOMATIONS[(string) ($b['kind'] ?? '')])) fail('Scénario inconnu.');
            [$subject, $html, $text] = auto_sample((string) $b['kind'], $l, (string) ($b['to'] ?? 'vous@example.com'));
        } else {
            $aud = ($b['audience'] ?? '') === 'pro' ? 'pro' : 'particulier';
            $dom = (string) ($b['domain'] ?? '');
            if ($aud === 'pro' && !in_array($dom, CAMPAIGN_DOMAINS, true)) fail('Choisissez un domaine.');
            [$subject, $html, $text] = camp_compose(['audience' => $aud, 'domain' => $dom, 'lang' => $l, 'subject' => mb_substr((string) ($b['subject'] ?? ''), 0, 160),
                'note' => mb_substr((string) ($b['note'] ?? ''), 0, 1200), 'sign' => mb_substr((string) ($b['sign'] ?? ''), 0, 80)],
                ['email' => '', 'name' => mb_substr(trim((string) ($b['name'] ?? '')), 0, 40), 'token' => '']);
        }
        if ($action === 'mod_mail_preview') ok(['subject' => $subject, 'html' => $html, 'text' => $text]);
        $to = strtolower(trim((string) ($b['to'] ?? '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Adresse de test invalide.');
        setting_set('mail_test_to', $to);
        try {
            send_mail($to, '[Test] ' . $subject, $html, $text);
        } catch (Throwable $e) {
            fail("L'email n'est pas parti : " . $e->getMessage(), 502);
        }
        ok(['to' => $to]);
        break;
    }

    case 'mod_mail_settings': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        if (array_key_exists('inv_per_hour', $b)) setting_set('inv_per_hour', (string) max(1, min(500, (int) $b['inv_per_hour'])));
        if (array_key_exists('inv_sign', $b)) setting_set('inv_sign', mb_substr(trim((string) $b['inv_sign']), 0, 80));
        if (array_key_exists('test_to', $b)) {
            $to = strtolower(trim((string) $b['test_to']));
            if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Adresse de test invalide.');
            setting_set('mail_test_to', $to);
        }
        ok();
        break;
    }

    case 'mod_campaign_create': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        if (empty($b['consent'])) fail('Cochez la case : ces personnes peuvent être contactées.');
        try {
            $res = camp_create($b);
        } catch (InvalidArgumentException $e) {
            fail($e->getMessage());
        }
        ok($res);
        break;
    }

    case 'mod_campaign_set': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $st = (string) ($b['status'] ?? '');
        if (!in_array($st, ['sending', 'paused'], true)) fail('Statut invalide.');
        $s = $pdo->prepare("UPDATE campaigns SET status = ?, started = CASE WHEN started = 0 AND ? = 'sending' THEN ? ELSE started END WHERE id = ? AND status != 'done'");
        $s->execute([$st, $st, time(), (int) ($b['id'] ?? 0)]);
        if (!$s->rowCount()) fail('Campagne introuvable ou terminée.', 404);
        ok();
        break;
    }

    case 'mod_campaign_send': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $id = (int) ($b['id'] ?? 0);
        $s = $pdo->prepare('SELECT status FROM campaigns WHERE id = ?');
        $s->execute([$id]);
        if ($s->fetchColumn() !== 'sending') fail("Lancez d'abord l'envoi de cette campagne.");
        @set_time_limit(120);
        $n = camp_send(20, false, function () {}, $id);
        Smtp::close();
        ok(['sent' => $n]);
        break;
    }

    case 'mod_campaign_delete': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        ok();
        break;
    }

    case 'mod_campaign_rcpts': {
        require_admin();
        $s = $pdo->prepare("SELECT r.email, r.name, r.status, r.sent, r.clicked, r.error,
                EXISTS (SELECT 1 FROM users u WHERE u.email = r.email AND r.sent > 0 AND u.created >= r.sent) AS signup
            FROM campaign_rcpt r WHERE r.cid = ? ORDER BY r.id LIMIT 3000");
        $s->execute([(int) ($_GET['id'] ?? 0)]);
        ok(['items' => array_map(function ($r) {
            return ['email' => $r['email'], 'name' => $r['name'], 'status' => $r['status'], 'sent' => (int) $r['sent'], 'clicked' => (int) $r['clicked'],
                'error' => $r['error'], 'signup' => (bool) $r['signup']];
        }, $s->fetchAll())]);
        break;
    }

    case 'mod_optout_add': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        [$list] = camp_parse((string) ($b['emails'] ?? ''));
        $ins = $pdo->prepare('INSERT OR IGNORE INTO email_optout (email, at, source) VALUES (?, ?, ?)');
        foreach ($list as [$email]) $ins->execute([$email, time(), 'admin']);
        $pdo->exec("UPDATE campaign_rcpt SET status = 'skipped', error = 'optout' WHERE status = 'queued' AND email IN (SELECT email FROM email_optout)");
        ok(['added' => count($list)]);
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

    case 'mod_pros': {
        require_admin();
        $where = '1';
        $args = [];
        if (in_array($_GET['status'] ?? '', ['active', 'hidden'], true)) { $where .= ' AND p.status = ?'; $args[] = $_GET['status']; }
        if (($_GET['status'] ?? '') === 'featured') $where .= ' AND p.featured = 1';
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where .= " AND (LOWER(p.name) LIKE ? ESCAPE '\\' OR LOWER(u.email) LIKE ? ESCAPE '\\' OR LOWER(p.city) LIKE ? ESCAPE '\\')";
            $like = '%' . strtr(mb_strtolower($q), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            array_push($args, $like, $like, $like);
        }
        $s = $pdo->prepare("SELECT p.*, u.email AS owner,
                (SELECT COUNT(*) FROM pro_questions pq WHERE pq.pro_id = p.id) AS nq,
                (SELECT COUNT(*) FROM stories st WHERE st.pro_id = p.id AND st.status = 'published') AS ns
            FROM pros p JOIN users u ON u.id = p.uid WHERE $where ORDER BY p.featured DESC, p.created DESC LIMIT 300");
        $s->execute($args);
        $st30 = pros_stats_30();
        $items = array_map(function ($p) use ($st30) {
            return pro_public($p) + ['status' => (string) $p['status'], 'owner' => (string) $p['owner'], 'email' => (string) $p['email'],
                'stats' => $st30[(int) $p['id']] ?? ['imp' => 0, 'view' => 0, 'clicks' => 0, 'reach' => 0],
                'phone' => (string) $p['phone'], 'website' => (string) $p['website'], 'bio' => (string) $p['bio'],
                'questions' => (int) $p['nq'], 'stories' => (int) $p['ns'], 'created' => (int) $p['created']];
        }, $s->fetchAll());
        $counts = [];
        foreach (['all' => '1', 'active' => "status = 'active'", 'hidden' => "status = 'hidden'", 'featured' => 'featured = 1'] as $k => $c) {
            $counts[$k] = (int) $pdo->query("SELECT COUNT(*) FROM pros WHERE $c")->fetchColumn();
        }
        ok(['items' => $items, 'counts' => $counts]);
        break;
    }

    case 'mod_pro_set': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $id = (int) ($b['id'] ?? 0);
        $sets = [];
        $vals = [];
        if (isset($b['status'])) {
            if (!in_array($b['status'], ['active', 'hidden'], true)) fail('Statut invalide.');
            $sets[] = 'status = ?';
            $vals[] = $b['status'];
        }
        foreach (['verified', 'featured'] as $k) if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = empty($b[$k]) ? 0 : 1; }
        if (!$sets) fail('Rien à modifier.');
        $vals[] = $id;
        $s = $pdo->prepare('UPDATE pros SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $s->execute($vals);
        if (!$s->rowCount()) fail('Page pro introuvable.', 404);
        meta_set('pros_cache', '');
        ok();
        break;
    }

    case 'mod_pro_stats': {
        require_admin();
        $s = $pdo->prepare('SELECT id FROM pros WHERE id = ?');
        $s->execute([(int) ($_GET['id'] ?? 0)]);
        $id = (int) $s->fetchColumn();
        if (!$id) fail('Page pro introuvable.', 404);
        ok(pro_stats_view($id, (int) ($_GET['days'] ?? 30), 'fr'));
        break;
    }

    case 'mod_pro_delete': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $s = $pdo->prepare('SELECT * FROM pros WHERE id = ?');
        $s->execute([(int) ($b['id'] ?? 0)]);
        $p = $s->fetch();
        if (!$p) fail('Page pro introuvable.', 404);
        delete_pro($p);
        ok();
        break;
    }

    case 'mod_subs': {
        require_admin();
        $where = '1';
        $args = [];
        if (in_array($_GET['filter'] ?? '', ['confirmed', 'pending'], true)) $where = 's.confirmed = ' . ($_GET['filter'] === 'confirmed' ? 1 : 0);
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where .= " AND LOWER(s.email) LIKE ? ESCAPE '\\'";
            $args[] = '%' . strtr(mb_strtolower($q), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
        $s = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM answers a WHERE a.pid = s.pid AND a.v = -1) AS neg
            FROM subscribers s WHERE $where ORDER BY s.created DESC LIMIT 500");
        $s->execute($args);
        $items = array_map(function ($r) {
            return ['id' => (int) $r['id'], 'email' => (string) $r['email'], 'lang' => (string) $r['lang'], 'confirmed' => (bool) $r['confirmed'],
                'created' => (int) $r['created'], 'last_alert' => (int) $r['last_alert'], 'neg' => (int) $r['neg'],
                'account' => strncmp((string) $r['pid'], 'u', 1) === 0];
        }, $s->fetchAll());
        if (($_GET['format'] ?? '') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="abonnes-defi-impact-' . date('Y-m-d') . '.csv"');
            echo "\xEF\xBB\xBF" . implode(';', array_map('csv_cell', ['Email', 'Langue', 'Confirmé', 'Inscription', 'Dernière alerte', 'Réponses négatives'])) . "\r\n";
            foreach ($items as $r) {
                echo implode(';', array_map('csv_cell', [$r['email'], $r['lang'], $r['confirmed'] ? 'oui' : 'non', date('Y-m-d H:i', $r['created']),
                    $r['last_alert'] ? date('Y-m-d H:i', $r['last_alert']) : '', $r['neg']])) . "\r\n";
            }
            exit;
        }
        $counts = [];
        foreach (['all' => '1', 'confirmed' => 'confirmed = 1', 'pending' => 'confirmed = 0'] as $k => $c) {
            $counts[$k] = (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE $c")->fetchColumn();
        }
        ok(['items' => $items, 'counts' => $counts]);
        break;
    }

    case 'mod_sub_delete': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $pdo->prepare('DELETE FROM subscribers WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
        ok();
        break;
    }

    case 'mod_users': {
        require_admin();
        $filter = array_key_exists((string) ($_GET['filter'] ?? ''), USER_FILTERS) ? (string) $_GET['filter'] : 'all';
        [$where, $args] = users_where($filter, (string) ($_GET['q'] ?? ''));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $c = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where");
        $c->execute($args);
        $counts = [];
        foreach (USER_FILTERS as $k => $cond) $counts[$k] = (int) $pdo->query("SELECT COUNT(*) FROM users u WHERE $cond")->fetchColumn();
        ok(['items' => users_query($where, $args, 100, $offset), 'total' => (int) $c->fetchColumn(), 'counts' => $counts]);
        break;
    }

    case 'mod_users_csv': {
        require_admin();
        $filter = array_key_exists((string) ($_GET['filter'] ?? ''), USER_FILTERS) ? (string) $_GET['filter'] : 'all';
        [$where, $args] = users_where($filter, (string) ($_GET['q'] ?? ''));
        $rows = users_query($where, $args, 100000, 0);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="inscrits-defi-impact-' . date('Y-m-d') . '.csv"');
        $yn = function (bool $b): string { return $b ? 'oui' : 'non'; };
        $d = function (int $t): string { return $t ? date('Y-m-d H:i', $t) : ''; };
        // Point-virgule et BOM UTF-8 : s'ouvre directement dans Excel en français, accents compris.
        echo "\xEF\xBB\xBF" . implode(';', array_map('csv_cell', ['Nom', 'Email', 'Langue', 'Adresse confirmée', 'Google',
            'Question du jour', 'Impacts positifs', 'Avis de messages', 'Inscription', 'Dernière visite', 'Réponses', 'Récits'])) . "\r\n";
        foreach ($rows as $r) {
            echo implode(';', array_map('csv_cell', [$r['name'], $r['email'], $r['lang'], $yn($r['verified']), $yn($r['google']),
                $yn($r['opt_daily']), $yn($r['opt_digest']), $yn($r['opt_msg']), $d($r['created']), $d($r['last']),
                $r['answers'], $r['stories']])) . "\r\n";
        }
        exit;
    }

    case 'mod_user_set': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $u = user_row((int) ($b['id'] ?? 0));
        if (!$u) fail('Compte introuvable.', 404);
        $sets = [];
        $vals = [];
        if (array_key_exists('email', $b)) {
            $email = strtolower(trim((string) $b['email']));
            if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide.');
            if ($email !== $u['email']) {
                $s = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id != ?');
                $s->execute([$email, $u['id']]);
                if ($s->fetchColumn()) fail('Cette adresse est déjà utilisée par un autre compte.', 409);
                // Nouvelle adresse : elle devra être confirmée (les rappels ne partent qu'aux adresses confirmées).
                array_push($sets, 'email = ?', 'verified = 0', 'vtoken = ?');
                array_push($vals, $email, token());
            }
        }
        if (array_key_exists('name', $b)) {
            $n = str_clean((string) $b['name'], 40);
            if ($n === '') fail('Le prénom ne peut pas être vide.');
            $sets[] = 'name = ?';
            $vals[] = $n;
        }
        if (array_key_exists('verified', $b) && !in_array('verified = 0', $sets, true)) {
            $sets[] = empty($b['verified']) ? 'verified = 0' : 'verified = 1, vtoken = NULL';
        }
        foreach (['opt_daily', 'opt_digest', 'opt_msg', 'opt_tips', 'opt_pro'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = empty($b[$k]) ? 0 : 1; }
        }
        if ($sets) {
            $vals[] = $u['id'];
            $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        }
        [$where, $args] = ['u.id = ?', [(int) $u['id']]];
        ok(['item' => users_query($where, $args, 1, 0)[0]]);
        break;
    }

    case 'mod_user_resend': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $u = user_row((int) ($b['id'] ?? 0));
        if (!$u) fail('Compte introuvable.', 404);
        if ($u['verified']) fail('Cette adresse est déjà confirmée.');
        if (empty($u['vtoken'])) {
            $u['vtoken'] = token();
            $pdo->prepare('UPDATE users SET vtoken = ? WHERE id = ?')->execute([$u['vtoken'], $u['id']]);
        }
        try {
            [$subj, $html, $text] = compose_welcome($u);
            send_mail($u['email'], $subj, $html, $text);
        } catch (Throwable $e) {
            error_log('impact admin verify: ' . $e->getMessage());
            fail("L'email n'a pas pu partir : " . $e->getMessage(), 502);
        }
        ok();
        break;
    }

    case 'mod_user_delete': {
        if (!$isPost) fail('generic', 405);
        require_admin();
        $u = user_row((int) ($b['id'] ?? 0));
        if (!$u) fail('Compte introuvable.', 404);
        delete_user((int) $u['id']);
        meta_set('stats_cache', '');
        ok();
        break;
    }

    case 'mod_stats': {
        require_admin();
        $now = time();
        $onlineSince = $now - ONLINE_WINDOW;

        $online = ['total' => 0, 'accounts' => 0, 'visitors' => 0, 'views' => []];
        foreach ($pdo->query("SELECT pid, view FROM presence WHERE seen > $onlineSince") as $r) {
            $online['total']++;
            $online[strncmp((string) $r['pid'], 'u', 1) === 0 ? 'accounts' : 'visitors']++;
            $v = (string) $r['view'] !== '' ? (string) $r['view'] : 'autre';
            $online['views'][$v] = ($online['views'][$v] ?? 0) + 1;
        }
        arsort($online['views']);

        $accounts = [
            'total'    => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
            'verified' => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE verified = 1')->fetchColumn(),
            'google'   => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE google_sub IS NOT NULL')->fetchColumn(),
            'daily'    => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE opt_daily = 1')->fetchColumn(),
            'digest'   => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE opt_digest = 1')->fetchColumn(),
            'pros'     => (int) $pdo->query("SELECT COUNT(*) FROM pros WHERE status = 'active'")->fetchColumn(),
            'subscribers' => (int) $pdo->query('SELECT COUNT(*) FROM subscribers WHERE confirmed = 1')->fetchColumn(),
        ];

        $answersTotal = (int) $pdo->query('SELECT COUNT(*) FROM answers')->fetchColumn();
        $participants = (int) $pdo->query('SELECT COUNT(DISTINCT pid) FROM answers')->fetchColumn();
        $today = $now - $now % 86400;
        $engagement = [
            'participants'  => $participants,
            'answers'       => $answersTotal,
            'answers_today' => (int) $pdo->query("SELECT COUNT(*) FROM answers WHERE t / 1000 >= $today")->fetchColumn(),
            'answers_week'  => (int) $pdo->query('SELECT COUNT(*) FROM answers WHERE t / 1000 >= ' . ($now - 7 * 86400))->fetchColumn(),
        ];

        $cats = [];
        foreach (IMPACT_CATS as $c) $cats[$c] = ['questions' => 0, 'active' => 0, 'answers' => 0, 'pos' => 0];
        foreach ($pdo->query('SELECT cat, active, COUNT(*) AS n FROM questions GROUP BY cat, active') as $r) {
            if (!isset($cats[$r['cat']])) continue;
            $cats[$r['cat']]['questions'] += (int) $r['n'];
            if ($r['active']) $cats[$r['cat']]['active'] += (int) $r['n'];
        }
        foreach ($pdo->query('SELECT q.cat AS cat, COUNT(*) AS n, SUM(a.v = 1) AS pos FROM answers a JOIN questions q ON q.id = a.qid GROUP BY q.cat') as $r) {
            if (!isset($cats[$r['cat']])) continue;
            $cats[$r['cat']]['answers'] = (int) $r['n'];
            $cats[$r['cat']]['pos'] = (int) $r['pos'];
        }

        $stories = ['published' => 0, 'pending' => 0, 'hidden' => 0, 'private' => 0, 'likes' => 0, 'dislikes' => 0, 'reports' => 0];
        foreach ($pdo->query('SELECT status, COUNT(*) AS n, SUM(likes) AS l, SUM(dislikes) AS d, SUM(reports) AS r FROM stories GROUP BY status') as $r) {
            if (isset($stories[$r['status']])) $stories[$r['status']] = (int) $r['n'];
            $stories['likes'] += (int) $r['l'];
            $stories['dislikes'] += (int) $r['d'];
            $stories['reports'] += (int) $r['r'];
        }

        $messages = [
            'threads'  => (int) $pdo->query('SELECT COUNT(*) FROM threads')->fetchColumn(),
            'blocked'  => (int) $pdo->query('SELECT COUNT(*) FROM threads WHERE blocked != 0')->fetchColumn(),
            'messages' => (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
            'today'    => (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE created >= $today")->fetchColumn(),
        ];

        $countries = [];
        foreach ($pdo->query("SELECT cc, COUNT(*) AS n FROM people WHERE cc != '' GROUP BY cc ORDER BY n DESC LIMIT 10") as $r) {
            $countries[] = [(string) $r['cc'], (int) $r['n']];
        }
        $countriesTotal = (int) $pdo->query("SELECT COUNT(DISTINCT cc) FROM people WHERE cc != ''")->fetchColumn();

        $donations = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'sum' => 0];
        foreach ($pdo->query('SELECT status, COUNT(*) AS n, SUM(amount) AS s FROM donations GROUP BY status') as $r) {
            if (isset($donations[$r['status']])) $donations[$r['status']] = (int) $r['n'];
            if ($r['status'] === 'paid') $donations['sum'] = (int) $r['s'];
        }

        // Tendance des 14 derniers jours : nouveaux comptes et réponses, un point par jour.
        $days = [];
        for ($i = 13; $i >= 0; $i--) $days[] = gmdate('Y-m-d', $today - $i * 86400);
        $signupsByDay = array_fill_keys($days, 0);
        $answersByDay = array_fill_keys($days, 0);
        $since14 = $today - 13 * 86400;
        foreach ($pdo->query("SELECT strftime('%Y-%m-%d', created, 'unixepoch') AS d, COUNT(*) AS n FROM users WHERE created >= $since14 GROUP BY d") as $r) {
            if (isset($signupsByDay[$r['d']])) $signupsByDay[$r['d']] = (int) $r['n'];
        }
        foreach ($pdo->query("SELECT strftime('%Y-%m-%d', t / 1000, 'unixepoch') AS d, COUNT(*) AS n FROM answers WHERE t / 1000 >= $since14 GROUP BY d") as $r) {
            if (isset($answersByDay[$r['d']])) $answersByDay[$r['d']] = (int) $r['n'];
        }

        // Questions les plus répondues, les plus positives et les plus négatives (5 chacune, 8 réponses mini pour les classements).
        $qs = questions();
        $top = ['answered' => [], 'positive' => [], 'negative' => []];
        $rows = [];
        foreach ($pdo->query('SELECT qid, COUNT(*) AS n, SUM(v = 1) AS pos, SUM(v = -1) AS neg FROM answers GROUP BY qid') as $r) {
            if (!isset($qs[(int) $r['qid']])) continue;
            $rows[] = ['id' => (int) $r['qid'], 'n' => (int) $r['n'], 'pos' => (int) $r['pos'], 'neg' => (int) $r['neg']];
        }
        usort($rows, function ($a, $b) { return $b['n'] <=> $a['n']; });
        foreach (array_slice($rows, 0, 5) as $r) $top['answered'][] = ['id' => $r['id'], 'q' => qtext($qs[$r['id']], 'fr'), 'n' => $r['n']];
        $ranked = array_filter($rows, function ($r) { return $r['n'] >= 8; });
        usort($ranked, function ($a, $b) { return ($b['pos'] / $b['n']) <=> ($a['pos'] / $a['n']); });
        foreach (array_slice($ranked, 0, 5) as $r) $top['positive'][] = ['id' => $r['id'], 'q' => qtext($qs[$r['id']], 'fr'), 'n' => $r['n'], 'pct' => (int) round($r['pos'] / $r['n'] * 100)];
        usort($ranked, function ($a, $b) { return ($b['neg'] / $b['n']) <=> ($a['neg'] / $a['n']); });
        foreach (array_slice($ranked, 0, 5) as $r) $top['negative'][] = ['id' => $r['id'], 'q' => qtext($qs[$r['id']], 'fr'), 'n' => $r['n'], 'pct' => (int) round($r['neg'] / $r['n'] * 100)];

        ok([
            'online' => $online, 'accounts' => $accounts, 'engagement' => $engagement, 'cats' => $cats,
            'stories' => $stories, 'messages' => $messages, 'countries' => $countries, 'countries_total' => $countriesTotal,
            'donations' => $donations, 'trend' => ['days' => $days, 'signups' => array_values($signupsByDay), 'answers' => array_values($answersByDay)],
            'views' => site_views_view(),
            'top' => $top,
        ]);
        break;
    }

    default:
        fail('not_found', 404);
}
