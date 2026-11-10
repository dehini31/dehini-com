<?php
/**
 * Comptes et sessions de Maillage : réponses JSON, limites anti-abus, cookie de session,
 * session de démonstration en lecture seule et accès du propriétaire du site (/admin).
 */
declare(strict_types=1);

const SID_COOKIE = 'mlg_sid';
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
    out(['error' => $code, 'message' => $msg], $http);
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

/**
 * Au plus $max actions « $key » par fenêtre de $window secondes (par IP, ou par $who).
 * $soft : au lieu de refuser, retourne false (pour ce qu'on peut simplement ne pas compter).
 */
function rate(string $key, int $max, int $window, string $who = '', bool $soft = false): bool
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
        if ((int) $row['n'] >= $max) {
            if ($soft) return false;
            fail('Trop de tentatives. Réessayez dans quelques minutes.', 429, 'rate');
        }
        $pdo->prepare('UPDATE rate SET n = n + 1 WHERE k = ?')->execute([$k]);
    }
    if (random_int(1, 100) === 1) $pdo->prepare('DELETE FROM rate WHERE reset < ?')->execute([$now]);
    return true;
}

/* --------------------------------------------------------------- sessions */

function cookie_opts(int $expires): array
{
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/maillage/api.php')), '/') . '/';
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ['expires' => $expires, 'path' => $path, 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'];
}

/** Compte connecté (avec son entreprise), ou null. */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $cache = null;
    $raw = (string) ($_COOKIE[SID_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return null;
    $hash = hash('sha256', $raw);
    $s = db()->prepare('SELECT u.*, s.csrf AS s_csrf, s.exp AS s_exp, s.ro AS s_ro, s.adm AS s_adm,
            c.kind AS c_kind, c.name AS c_name, c.slug AS c_slug, c.status AS c_status, c.demo AS c_demo
        FROM sessions s JOIN users u ON u.id = s.uid JOIN companies c ON c.id = u.company_id
        WHERE s.hash = ? AND s.exp > ?');
    $s->execute([$hash, time()]);
    $u = $s->fetch();
    if (!$u) return null;
    // Les sessions de démonstration et d'administration ne sont pas prolongées.
    if (!(int) $u['s_ro'] && !(int) $u['s_adm']) {
        $fresh = time() + SESSION_DAYS * 86400;
        if ((int) $u['s_exp'] < $fresh - 86400) {
            db()->prepare('UPDATE sessions SET exp = ? WHERE hash = ?')->execute([$fresh, $hash]);
            setcookie(SID_COOKIE, $raw, cookie_opts($fresh));
        }
    }
    return $cache = $u;
}

function start_session(int $uid, bool $readonly = false, bool $admin = false): string
{
    $raw = token(24);
    $csrf = token(16);
    $exp = time() + ($readonly ? 4 * 3600 : ($admin ? 12 * 3600 : SESSION_DAYS * 86400));
    db()->prepare('INSERT INTO sessions (hash, uid, csrf, exp, ro, adm) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $raw), $uid, $csrf, $exp, $readonly ? 1 : 0, $admin ? 1 : 0]);
    db()->prepare('DELETE FROM sessions WHERE exp < ?')->execute([time()]);
    // Une session temporaire (démo, administration) disparaît à la fermeture du navigateur.
    setcookie(SID_COOKIE, $raw, cookie_opts($readonly || $admin ? 0 : $exp));
    return $csrf;
}
function end_session(): void
{
    $raw = (string) ($_COOKIE[SID_COOKIE] ?? '');
    if ($raw !== '') db()->prepare('DELETE FROM sessions WHERE hash = ?')->execute([hash('sha256', $raw)]);
    setcookie(SID_COOKIE, '', cookie_opts(time() - 3600));
}

/**
 * Compte connecté obligatoire. Pour une écriture : jeton CSRF vérifié, et refus dans la
 * session de démonstration (lecture seule).
 */
function require_user(): array
{
    global $isPost;
    $u = current_user();
    if (!$u) fail('Votre session a expiré. Reconnectez-vous.', 401, 'auth');
    if ($isPost) {
        if (!hash_equals((string) $u['s_csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Votre session a expiré. Reconnectez-vous.', 401, 'auth');
        if ((int) $u['s_ro']) fail('La démonstration est en lecture seule. Créez votre espace pour gérer votre réseau.', 403, 'demo');
    }
    if ((int) $u['last_seen'] < time() - 600 && !(int) $u['s_ro'] && !(int) $u['s_adm']) {
        db()->prepare('UPDATE users SET last_seen = ? WHERE id = ?')->execute([time(), $u['id']]);
    }
    return $u;
}

/** Propriétaire du site : la session de /admin (même mot de passe), comme les autres projets. */
function require_admin(): void
{
    global $isPost;
    session_name('dehini_admin');
    session_start();
    $okAdmin = !empty($_SESSION['admin']) && !empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) < 12 * 3600;
    $csrf = (string) ($_SESSION['csrf'] ?? '');
    session_write_close();
    if (!$okAdmin) fail('Connectez-vous d\'abord à /admin.', 401, 'auth');
    if ($isPost && ($csrf === '' || !hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? '')))) fail('Session expirée.', 403, 'auth');
}

function company_row(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM companies WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch() ?: null;
}

function me_view(array $u, string $csrf): array
{
    $c = company_row((int) $u['company_id']) ?? [];
    return [
        'user' => [
            'id' => (int) $u['id'], 'name' => (string) $u['name'], 'email' => (string) $u['email'], 'phone' => (string) $u['phone'],
        ],
        'company' => company_view($c),
        'csrf' => $csrf,
        'readonly' => (bool) ($u['s_ro'] ?? 0),
        'admin' => (bool) ($u['s_adm'] ?? 0),
    ];
}
function company_view(array $c): array
{
    return [
        'id' => (int) ($c['id'] ?? 0), 'kind' => (string) ($c['kind'] ?? ''), 'name' => (string) ($c['name'] ?? ''),
        'slug' => (string) ($c['slug'] ?? ''), 'about' => (string) ($c['about'] ?? ''), 'logo' => (string) ($c['logo'] ?? ''),
        'phone' => (string) ($c['phone'] ?? ''), 'email' => (string) ($c['email'] ?? ''), 'website' => (string) ($c['website'] ?? ''),
        'wilaya' => (string) ($c['wilaya'] ?? ''), 'city' => (string) ($c['city'] ?? ''), 'sector' => (string) ($c['sector'] ?? ''),
        'status' => (string) ($c['status'] ?? ''), 'demo' => (bool) ($c['demo'] ?? 0), 'created' => (int) ($c['created'] ?? 0),
    ];
}

/** Crée une entreprise et son premier compte. Retourne [id de l'entreprise, id du compte]. */
function account_create(array $d, bool $demo = false): array
{
    $pdo = db();
    $now = time();
    $pdo->prepare('INSERT INTO companies (kind, name, slug, phone, email, wilaya, city, sector, size, status, demo, about, created)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $d['kind'], $d['company'], unique_slug('companies', $d['company']), $d['phone'], $d['email'], $d['wilaya'], $d['city'],
        $d['sector'] ?? '', $d['size'] ?? '', $d['status'], $demo ? 1 : 0, $d['about'] ?? '', $d['created'] ?? $now,
    ]);
    $cid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO users (company_id, email, name, phone, pass, created) VALUES (?, ?, ?, ?, ?, ?)')->execute([
        $cid, $d['email'], $d['name'], $d['phone'], $d['password'] !== '' ? password_hash($d['password'], PASSWORD_DEFAULT) : '', $d['created'] ?? $now,
    ]);
    return [$cid, (int) $pdo->lastInsertId()];
}

/** Journal du réseau (voir la table activity). */
function log_activity(int $cid, string $kind, string $detail, int $storeId = 0, int $aboutCid = 0, ?int $at = null): void
{
    db()->prepare('INSERT INTO activity (company_id, about_cid, store_id, kind, detail, created) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$cid, $aboutCid === $cid ? 0 : $aboutCid, $storeId, $kind, mb_substr($detail, 0, 240), $at ?? time()]);
}
