<?php
/**
 * Connexion Meta Ads (Facebook Login) pour Stats Meta KPIs.
 *   meta.php?start=1          → redirige vers Facebook (compte du site connecté requis)
 *   meta.php?code=…&state=…   → retour de Facebook : jeton longue durée chiffré en base
 * Retourne toujours sur le profil : ./?meta=ok|denied|error|unconfigured#profile
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/graph.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function back(string $status): void
{
    header('Location: ' . site_url() . '?meta=' . rawurlencode($status) . '#profile', true, 302);
    exit;
}
// graph.php s'attend à ces deux fonctions (définies dans api.php pour l'API JSON).
function out(array $d, int $code = 200): void
{
    back('error');
}
function fail(string $code, int $http = 400): void
{
    back($code === 'meta_unconfigured' ? 'unconfigured' : 'error');
}

/** Compte du site connecté (même cookie que api.php). */
function cookie_uid(): int
{
    $raw = (string) ($_COOKIE['smk_sid'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return 0;
    $s = db()->prepare('SELECT uid FROM sessions WHERE hash = ? AND exp > ?');
    $s->execute([hash('sha256', $raw), time()]);
    return (int) $s->fetchColumn();
}

try {
    db();
} catch (Throwable $e) {
    back('error');
}
$uid = cookie_uid();
if (!$uid) {
    header('Location: ' . site_url() . '#login', true, 302);
    exit;
}
if (!meta_ready()) back('unconfigured');

if (isset($_GET['start'])) {
    // Ménage des états OAuth expirés.
    foreach (db()->query("SELECT k, v FROM meta WHERE k LIKE 'oauth.%'")->fetchAll() as $r) {
        $d = json_decode((string) $r['v'], true);
        if (!is_array($d) || ($d['exp'] ?? 0) < time()) meta_del((string) $r['k']);
    }
    $state = token(16);
    meta_set('oauth.' . $state, (string) json_encode(['uid' => $uid, 'exp' => time() + 900]));
    header('Location: ' . meta_login_url($state), true, 302);
    exit;
}

$state = (string) ($_GET['state'] ?? '');
$saved = preg_match('/^[a-f0-9]{32}$/', $state) ? json_decode((string) meta_get('oauth.' . $state), true) : null;
if ($state !== '') meta_del('oauth.' . $state);
if (!is_array($saved) || (int) ($saved['uid'] ?? 0) !== $uid || ($saved['exp'] ?? 0) < time()) back('error');
if (!empty($_GET['error']) || empty($_GET['code'])) back('denied');

try {
    $m = meta_exchange((string) $_GET['code']);
} catch (Throwable $e) {
    error_log('metakpis meta oauth: ' . $e->getMessage());
    back('error');
}
$pdo = db();
$pdo->prepare('DELETE FROM meta_conn WHERE uid = ?')->execute([$uid]);
$pdo->prepare('INSERT INTO meta_conn (uid, meta_id, meta_name, token, expires, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?)')
    ->execute([$uid, $m['id'], str_clean($m['name'], 120), seal($m['token']), $m['expires'], time(), time()]);
$pdo->prepare('INSERT INTO activity (uid, kind, act, detail, created) VALUES (?, ?, ?, ?, ?)')->execute([$uid, 'meta_connect', '', str_clean($m['name'], 120), time()]);
// Comptes publicitaires (nom, devise) pour le profil.
try {
    $ins = $pdo->prepare('INSERT OR REPLACE INTO accounts (uid, act, name, currency, updated) VALUES (?, ?, ?, ?, ?)');
    foreach (graph_all('me/adaccounts', ['fields' => 'account_id,name,currency', 'limit' => 200], $m['token'], 600) as $r) {
        if (!empty($r['account_id'])) $ins->execute([$uid, (string) $r['account_id'], str_clean($r['name'] ?? '', 120), (string) ($r['currency'] ?? ''), time()]);
    }
} catch (Throwable $e) {
    error_log('metakpis adaccounts: ' . $e->getMessage());
}
back('ok');
