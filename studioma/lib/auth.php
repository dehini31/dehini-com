<?php
/**
 * Comptes et sessions de Studioma : réponses JSON, limites anti-abus, cookie de session,
 * fiches publiques des comptes et des entreprises.
 */
declare(strict_types=1);

const SID_COOKIE = 'stm_sid';
const SESSION_DAYS = 30;

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
/** $msg est affiché tel quel à la personne ; $code sert au code de la page. */
function fail(string $msg, int $http = 400, string $code = 'invalid'): void
{
    tx_rollback();
    out(['error' => $code, 'message' => $msg], $http);
}
function not_found(string $what = 'This page'): void
{
    fail($what . ' doesn\'t exist or you don\'t have access to it.', 404, 'notfound');
}
function forbidden(string $msg = 'Your role doesn\'t allow this action.'): void
{
    fail($msg, 403, 'forbidden');
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

/** Au plus $max actions « $key » par fenêtre de $window secondes (par IP, ou par $who). */
function rate(string $key, int $max, int $window, string $who = ''): void
{
    $k = $key . '|' . ($who !== '' ? $who : ip_hash());
    $now = time();
    $r = row('SELECT n, reset FROM rate WHERE k = ?', [$k]);
    if (!$r || (int) $r['reset'] < $now) {
        q('INSERT OR REPLACE INTO rate (k, n, reset) VALUES (?, 1, ?)', [$k, $now + $window]);
    } else {
        if ((int) $r['n'] >= $max) fail('Too many attempts. Please try again in a few minutes.', 429, 'rate');
        q('UPDATE rate SET n = n + 1 WHERE k = ?', [$k]);
    }
    if (random_int(1, 100) === 1) q('DELETE FROM rate WHERE reset < ?', [$now]);
}

/* --------------------------------------------------------------- sessions */

function cookie_opts(int $expires): array
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/studioma/api.php')), '/') . '/';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ['expires' => $expires, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
}

/** Compte connecté, ou null. */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $raw = (string) ($_COOKIE[SID_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return null;
    $hash = hash('sha256', $raw);
    $u = row('SELECT u.*, s.csrf AS s_csrf, s.exp AS s_exp FROM sessions s JOIN users u ON u.id = s.uid WHERE s.hash = ? AND s.exp > ?', [$hash, time()]);
    if (!$u) return null;
    $u['id'] = (int) $u['id'];
    $fresh = time() + SESSION_DAYS * 86400;
    if ((int) $u['s_exp'] < $fresh - 86400) {
        q('UPDATE sessions SET exp = ? WHERE hash = ?', [$fresh, $hash]);
        setcookie(SID_COOKIE, $raw, cookie_opts($fresh));
    }
    if ((int) $u['last_seen'] < time() - 600) q('UPDATE users SET last_seen = ? WHERE id = ?', [time(), $u['id']]);
    return $cache = $u;
}
function start_session(int $uid): string
{
    $raw = token(24);
    $csrf = token(16);
    $exp = time() + SESSION_DAYS * 86400;
    q('INSERT INTO sessions (hash, uid, csrf, exp) VALUES (?, ?, ?, ?)', [hash('sha256', $raw), $uid, $csrf, $exp]);
    q('DELETE FROM sessions WHERE exp < ?', [time()]);
    setcookie(SID_COOKIE, $raw, cookie_opts($exp));
    return $csrf;
}
function end_session(): void
{
    $raw = (string) ($_COOKIE[SID_COOKIE] ?? '');
    if ($raw !== '') q('DELETE FROM sessions WHERE hash = ?', [hash('sha256', $raw)]);
    setcookie(SID_COOKIE, '', cookie_opts(time() - 3600));
}
/** Compte connecté obligatoire ; pour une écriture, le jeton CSRF est vérifié. */
function require_user(): array
{
    global $isPost;
    $u = current_user();
    if (!$u) fail('Please sign in to continue.', 401, 'auth');
    if ($isPost && !hash_equals((string) $u['s_csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Your session has expired. Please sign in again.', 401, 'auth');
    return $u;
}
function password_in($p): string
{
    $p = is_string($p) ? $p : '';
    if (strlen($p) < 8) fail('Your password must be at least 8 characters long.');
    if (strlen($p) > 200) fail('This password is too long.');
    return $p;
}

/* ---------------------------------------------------------------- comptes */

/** Texte de recherche d'un compte (sans accents). */
function user_search_text(array $u): string
{
    $parts = [$u['name'], $u['handle'], $u['title'], $u['specialization'], $u['location'], $u['bio']];
    foreach (['skills', 'industries', 'languages'] as $k) $parts[] = implode(' ', jarr($u[$k]));
    foreach (jarr($u['platforms']) as $p) $parts[] = STM_PLATFORMS[$p] ?? '';
    return norm(implode(' ', $parts));
}
function user_reindex(int $uid): void
{
    $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
    if ($u) q('UPDATE users SET search = ? WHERE id = ?', [user_search_text($u), $uid]);
}
/** Crée un compte ; retourne son id. */
function account_create(string $email, string $name, string $password, string $kind): int
{
    $now = time();
    $uid = insert('users', [
        'email' => $email, 'name' => $name, 'handle' => unique_slug('users', 'handle', $name),
        // Compte créé avec Google : pas de mot de passe ('' ne correspond à aucun mot de passe).
        'pass' => $password === '' ? '' : password_hash($password, PASSWORD_DEFAULT), 'kind' => $kind, 'pro' => $kind === 'company' ? 0 : 1,
        'created' => $now, 'last_seen' => $now,
    ]);
    user_reindex($uid);
    return $uid;
}

/** Fiche courte d'un compte (auteur d'une publication, membre, commentaire…). */
function user_card(?array $u): ?array
{
    if (!$u) return null;
    return [
        'id' => (int) $u['id'], 'name' => (string) $u['name'], 'handle' => (string) $u['handle'],
        'title' => (string) $u['title'], 'avatar' => (string) $u['avatar'],
    ];
}
/** Fiches courtes de plusieurs comptes : [id => fiche]. */
function user_cards(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $out = [];
    foreach (rows('SELECT id, name, handle, title, avatar FROM users WHERE id IN (' . marks($ids) . ')', $ids) as $u) $out[(int) $u['id']] = user_card($u);
    return $out;
}
function user_by_id(int $id): ?array
{
    return $id > 0 ? row('SELECT * FROM users WHERE id = ?', [$id]) : null;
}
function company_card(?array $c): ?array
{
    if (!$c) return null;
    return [
        'id' => (int) $c['id'], 'name' => (string) $c['name'], 'slug' => (string) $c['slug'],
        'tagline' => (string) $c['tagline'], 'logo' => (string) $c['logo'], 'industry' => (string) $c['industry'],
        'location' => (string) $c['location'],
    ];
}
function company_cards(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $out = [];
    foreach (rows('SELECT * FROM companies WHERE id IN (' . marks($ids) . ')', $ids) as $c) $out[(int) $c['id']] = company_card($c);
    return $out;
}
/** Rôle du compte dans une entreprise : owner | admin | member | ''. */
function company_role(int $uid, int $cid): string
{
    if ($cid <= 0) return '';
    return (string) (val('SELECT role FROM company_members WHERE company_id = ? AND uid = ?', [$cid, $uid]) ?? '');
}
function company_admin(int $uid, int $cid): bool
{
    return in_array(company_role($uid, $cid), ['owner', 'admin'], true);
}
/** Entreprises du compte (avec son rôle). */
function my_companies(int $uid): array
{
    $out = [];
    foreach (rows('SELECT c.*, m.role AS m_role FROM company_members m JOIN companies c ON c.id = m.company_id WHERE m.uid = ? ORDER BY c.name', [$uid]) as $c) {
        $out[] = company_card($c) + ['role' => (string) $c['m_role'], 'admin' => in_array($c['m_role'], ['owner', 'admin'], true)];
    }
    return $out;
}

function user_settings(array $u): array
{
    return jarr($u['settings']) + ['theme' => 'auto', 'email_notifs' => true, 'show_activity' => false];
}
/** Tout ce que l'application doit savoir sur le compte connecté. */
function me_view(array $u, string $csrf): array
{
    return [
        'user' => user_card($u) + [
            'email' => (string) $u['email'], 'email_ok' => (int) ($u['email_ok'] ?? 0) === 1, 'kind' => (string) $u['kind'], 'pro' => (bool) $u['pro'],
            'google' => (string) ($u['google_sub'] ?? '') !== '', 'has_password' => (string) $u['pass'] !== '',
            'settings' => user_settings($u),
            'onboarded' => $u['title'] !== '' || jarr($u['skills']) !== [],
        ],
        'companies' => my_companies((int) $u['id']),
        'csrf' => $csrf,
        'counts' => me_counts((int) $u['id']),
    ];
}
/** Pastilles du menu : notifications et messages non lus, invitations en attente. */
function me_counts(int $uid): array
{
    // Invitations envoyées par email : comptées seulement si l'adresse est confirmée (voir invite_email()).
    $email = invite_email(row('SELECT email, email_ok FROM users WHERE id = ?', [$uid]) ?? []);
    return [
        'notifs' => (int) val('SELECT COUNT(*) FROM notifications WHERE uid = ? AND seen = 0', [$uid]),
        'messages' => (int) val('SELECT COUNT(*) FROM threads WHERE (a = ? AND last_id > a_read) OR (b = ? AND last_id > b_read)', [$uid, $uid]),
        'invites' => (int) val("SELECT COUNT(*) FROM invites WHERE status = 'pending' AND expires > ? AND (uid = ? OR (email <> '' AND email = ?))", [time(), $uid, $email]),
    ];
}

/**
 * Notification dans l'application, doublée d'un email pour les sujets importants ($email) si la
 * personne ne l'a pas désactivé. $link : écran de l'application (ex. « studio/3/card/12 »).
 */
function notify(int $uid, int $actor, string $kind, string $text, string $link = '', bool $email = false): void
{
    if ($uid <= 0 || $uid === $actor) return;
    insert('notifications', ['uid' => $uid, 'actor' => $actor, 'kind' => $kind, 'text' => mb_substr($text, 0, 300), 'link' => $link, 'created' => time()]);
    if (!$email) return;
    $to = user_by_id($uid);
    if (!$to || !user_settings($to)['email_notifs']) return;
    $who = $actor > 0 ? (string) val('SELECT name FROM users WHERE id = ?', [$actor]) : STM_NAME;
    $line = trim($who . ' ' . $text);
    mail_later((string) $to['email'], mb_substr($line, 0, 120), 'You have a new update', [$line, 'You can turn these emails off in Settings.'], 'Open ' . STM_NAME, app_url($link));
}
