<?php
/**
 * API de Studioma — dehini.com/studioma/
 *
 * Réponses JSON : {ok:true,…} ou {error:"code", message:"texte affichable"}.
 * Toute écriture (POST) exige l'en-tête X-Requested-With: studioma ; les écritures d'un compte
 * connecté exigent en plus l'en-tête X-CSRF (reçu à la connexion). Chaque action est une fonction
 * api_<action>(données, compte) rangée dans lib/ ; la table ROUTES ci-dessous dit pour chacune
 * la méthode attendue et si un compte connecté est obligatoire.
 *
 * Les droits (rôles d'un projet ou d'un Studio) sont vérifiés dans chaque action, côté serveur :
 * voir lib/access.php.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/access.php';
require __DIR__ . '/lib/files.php';
require __DIR__ . '/lib/studio.php';
require __DIR__ . '/lib/cards.php';
require __DIR__ . '/lib/collab.php';
require __DIR__ . '/lib/social.php';
require __DIR__ . '/lib/talent.php';

/** action => [méthode, accès] — accès : public (compte facultatif) | user (compte obligatoire). */
const ROUTES = [
    // Comptes
    'me' => ['GET', 'public'], 'signup' => ['POST', 'public'], 'login' => ['POST', 'public'], 'logout' => ['POST', 'public'],
    'forgot' => ['POST', 'public'], 'reset' => ['POST', 'public'], 'password' => ['POST', 'user'], 'account_save' => ['POST', 'user'],
    'account_delete' => ['POST', 'user'], 'counts' => ['GET', 'user'],
    // Projets et Studios
    'workspace' => ['GET', 'user'], 'project_get' => ['GET', 'user'], 'project_save' => ['POST', 'user'], 'project_archive' => ['POST', 'user'],
    'project_delete' => ['POST', 'user'], 'studio_create' => ['POST', 'user'], 'studio_save' => ['POST', 'user'], 'studio_archive' => ['POST', 'user'],
    'studio_delete' => ['POST', 'user'], 'board' => ['GET', 'user'], 'studio_archived' => ['GET', 'user'], 'studio_activity' => ['GET', 'user'],
    'studio_stats' => ['GET', 'user'], 'mywork' => ['GET', 'user'],
    // Niveaux et colonnes
    'level_save' => ['POST', 'user'], 'level_move' => ['POST', 'user'], 'level_duplicate' => ['POST', 'user'], 'level_archive' => ['POST', 'user'],
    'level_delete' => ['POST', 'user'], 'column_save' => ['POST', 'user'], 'column_move' => ['POST', 'user'], 'column_principal' => ['POST', 'user'],
    'column_final' => ['POST', 'user'], 'column_duplicate' => ['POST', 'user'], 'column_archive' => ['POST', 'user'], 'column_delete' => ['POST', 'user'],
    // Cartes
    'card_create' => ['POST', 'user'], 'card_get' => ['GET', 'user'], 'card_save' => ['POST', 'user'], 'card_move' => ['POST', 'user'],
    'card_handoff' => ['POST', 'user'], 'card_duplicate' => ['POST', 'user'], 'card_archive' => ['POST', 'user'], 'card_delete' => ['POST', 'user'],
    'cards_bulk' => ['POST', 'user'], 'check_save' => ['POST', 'user'], 'check_delete' => ['POST', 'user'], 'comment_add' => ['POST', 'user'],
    'comment_delete' => ['POST', 'user'], 'upload' => ['POST', 'user'], 'attach_delete' => ['POST', 'user'], 'file' => ['GET', 'public'],
    'approval_request' => ['POST', 'user'], 'approval_decide' => ['POST', 'user'], 'approval_cancel' => ['POST', 'user'],
    // Équipe
    'members' => ['GET', 'user'], 'member_role' => ['POST', 'user'], 'member_remove' => ['POST', 'user'], 'invite_create' => ['POST', 'user'],
    'invite_revoke' => ['POST', 'user'], 'invite_info' => ['GET', 'public'], 'invite_accept' => ['POST', 'user'], 'invite_decline' => ['POST', 'user'],
    'notifications' => ['GET', 'user'], 'notifs_read' => ['POST', 'user'],
    // Réseau
    'profile_get' => ['GET', 'public'], 'profile_save' => ['POST', 'user'], 'feed' => ['GET', 'public'], 'post_create' => ['POST', 'user'],
    'post_delete' => ['POST', 'user'], 'post_like' => ['POST', 'user'], 'post_save' => ['POST', 'user'], 'post_comments' => ['GET', 'public'],
    'post_comment' => ['POST', 'user'], 'post_comment_delete' => ['POST', 'user'], 'follow' => ['POST', 'user'], 'connect' => ['POST', 'user'],
    'disconnect' => ['POST', 'user'], 'network' => ['GET', 'user'], 'search' => ['GET', 'public'],
    'portfolio_save' => ['POST', 'user'], 'portfolio_delete' => ['POST', 'user'], 'portfolio_from_studio' => ['POST', 'user'],
    'portfolio_authorize' => ['POST', 'user'], 'portfolio_confirm_ask' => ['POST', 'user'], 'portfolio_confirm' => ['POST', 'user'],
    'requests' => ['GET', 'user'], 'recommend' => ['POST', 'user'], 'recommend_set' => ['POST', 'user'],
    // Entreprises, opportunités, talents, messages
    'company_get' => ['GET', 'public'], 'company_save' => ['POST', 'user'], 'company_member' => ['POST', 'user'],
    'opps' => ['GET', 'public'], 'opp_get' => ['GET', 'public'], 'opp_save' => ['POST', 'user'], 'opp_delete' => ['POST', 'user'],
    'opp_apply' => ['POST', 'user'], 'opp_application' => ['POST', 'user'], 'talent' => ['GET', 'user'], 'talent_save' => ['POST', 'user'],
    'threads' => ['GET', 'user'], 'thread' => ['GET', 'user'], 'message_send' => ['POST', 'user'],
];

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    db();
} catch (Throwable $e) {
    error_log('studioma db: ' . $e->getMessage());
    fail('The service is temporarily unavailable.', 500, 'server');
}

/* ----------------------------------------------------------------- comptes */

/** Listes de référence envoyées une fois à l'application. */
function reference(): array
{
    return [
        'platforms' => STM_PLATFORMS, 'content_types' => STM_CONTENT_TYPES, 'skills' => STM_SKILLS, 'industries' => STM_INDUSTRIES,
        'languages' => STM_LANGUAGES, 'contracts' => STM_CONTRACTS, 'modes' => STM_WORK_MODES, 'availability' => STM_AVAILABILITY,
        'colors' => STM_COLORS, 'priorities' => STM_PRIORITIES, 'roles' => ROLE_LABELS, 'relations' => RELATIONS,
        'sizes' => COMPANY_SIZES, 'app_statuses' => APP_STATUSES, 'metrics' => METRIC_KEYS, 'templates' => templates_view(),
        'max_upload_mb' => (int) STM_MAX_UPLOAD_MB, 'name' => STM_NAME,
    ];
}
function api_me(array $b, ?array $u): array
{
    $out = $u ? me_view($u, (string) $u['s_csrf']) : ['user' => null];
    if (!empty($b['ref'])) $out['ref'] = reference();
    return $out;
}
function api_signup(array $b, ?array $u): array
{
    rate('signup', 8, 3600);
    $kind = ($b['kind'] ?? '') === 'company' ? 'company' : 'individual';
    $name = line_clean($b['name'] ?? '', 60);
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    if (mb_strlen($name) < 2) fail('Enter your name.');
    if (!valid_email($email)) fail('Enter a valid email address.');
    $password = password_in($b['password'] ?? '');
    $company = line_clean($b['company'] ?? '', 80);
    if ($kind === 'company' && mb_strlen($company) < 2) fail('Enter the name of your company.');
    if (empty($b['accept'])) fail('Please accept the terms to create your account.');
    if (val('SELECT 1 FROM users WHERE email = ?', [$email])) fail('An account already exists with this email. Sign in instead.', 409, 'exists');
    $uid = tx(function () use ($email, $name, $password, $kind, $company): int {
        $uid = account_create($email, $name, $password, $kind);
        if ($kind === 'company') company_create(['name' => $company], $uid);
        return $uid;
    });
    $csrf = start_session($uid);
    mail_later($email, 'Welcome to ' . STM_NAME, 'Welcome, ' . $name . '!', [
        'Your account is ready. ' . ($kind === 'company' ? 'Set up your company page, create your first Studio and invite your team.' : 'Complete your profile, create your first Studio and invite your collaborators.'),
        'Create together. Grow professionally. Get discovered.',
    ], 'Open ' . STM_NAME, app_url());
    return me_view(user_by_id($uid), $csrf);
}
function api_login(array $b, ?array $u): array
{
    rate('login', 12, 900);
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    $t = row('SELECT * FROM users WHERE email = ?', [$email]);
    $pass = is_string($b['password'] ?? null) ? $b['password'] : '';
    if (!$t || $t['pass'] === '' || !password_verify($pass, (string) $t['pass'])) fail('Wrong email or password.', 401, 'credentials');
    if (password_needs_rehash((string) $t['pass'], PASSWORD_DEFAULT)) q('UPDATE users SET pass = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $t['id']]);
    return me_view($t, start_session((int) $t['id']));
}
function api_logout(array $b, ?array $u): array
{
    end_session();
    return [];
}
function api_forgot(array $b, ?array $u): array
{
    rate('forgot', 5, 3600);
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    $t = row('SELECT * FROM users WHERE email = ?', [$email]);
    if ($t) {
        $raw = token(24);
        q('UPDATE users SET rhash = ?, rexp = ? WHERE id = ?', [hash('sha256', $raw), time() + 3600, $t['id']]);
        mail_later($email, 'Reset your ' . STM_NAME . ' password', 'Reset your password', [
            'Someone asked to reset the password of your account. This link is valid for one hour.',
            'If it wasn\'t you, ignore this email: your password stays the same.',
        ], 'Choose a new password', app_url('reset/' . $raw));
    }
    // Même réponse que le compte existe ou non.
    return [];
}
function api_reset(array $b, ?array $u): array
{
    rate('reset', 10, 3600);
    $raw = (string) ($b['token'] ?? '');
    $t = preg_match('/^[a-f0-9]{48}$/', $raw) ? row('SELECT * FROM users WHERE rhash = ? AND rexp > ?', [hash('sha256', $raw), time()]) : null;
    if (!$t) fail('This link has expired. Ask for a new one.', 410, 'gone');
    $password = password_in($b['password'] ?? '');
    q('UPDATE users SET pass = ?, rhash = NULL, rexp = 0 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $t['id']]);
    q('DELETE FROM sessions WHERE uid = ?', [$t['id']]);   // les anciennes sessions sont fermées
    return me_view($t, start_session((int) $t['id']));
}
function api_password(array $b, array $u): array
{
    rate('password', 8, 900, 'u' . $u['id']);
    if (!password_verify(is_string($b['current'] ?? null) ? $b['current'] : '', (string) $u['pass'])) fail('Your current password is not correct.');
    $password = password_in($b['password'] ?? '');
    q('UPDATE users SET pass = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    q('DELETE FROM sessions WHERE uid = ? AND hash <> ?', [$u['id'], hash('sha256', (string) ($_COOKIE[SID_COOKIE] ?? ''))]);
    return [];
}
function api_account_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    if (isset($b['email'])) {
        $email = strtolower(line_clean($b['email'], 190));
        if (!valid_email($email)) fail('Enter a valid email address.');
        if ($email !== $u['email']) {
            if (!password_verify(is_string($b['current'] ?? null) ? $b['current'] : '', (string) $u['pass'])) fail('Enter your current password to change your email.');
            if (val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $uid])) fail('Another account already uses this email.');
            q('UPDATE users SET email = ? WHERE id = ?', [$email, $uid]);
        }
    }
    if (isset($b['settings']) && is_array($b['settings'])) {
        $s = user_settings($u);
        if (isset($b['settings']['theme'])) $s['theme'] = in_array($b['settings']['theme'], ['light', 'dark', 'auto'], true) ? $b['settings']['theme'] : 'auto';
        foreach (['email_notifs', 'show_activity'] as $k) {
            if (isset($b['settings'][$k])) $s[$k] = (bool) $b['settings'][$k];
        }
        q('UPDATE users SET settings = ? WHERE id = ?', [jenc($s), $uid]);
    }
    return me_view(user_by_id($uid), (string) $u['s_csrf']);
}
/**
 * Suppression du compte. Refusée tant que la personne est la seule propriétaire d'un projet ou
 * d'une entreprise partagés avec d'autres : il faut d'abord nommer un autre propriétaire.
 */
function api_account_delete(array $b, array $u): array
{
    $uid = (int) $u['id'];
    if (!password_verify(is_string($b['password'] ?? null) ? $b['password'] : '', (string) $u['pass'])) fail('Your password is not correct.');
    $solo = [];
    foreach (rows("SELECT p.* FROM projects p JOIN project_members m ON m.project_id = p.id WHERE m.uid = ? AND m.role = 'owner'", [$uid]) as $p) {
        if (owners_count((int) $p['id']) > 1) continue;
        if ((int) val('SELECT COUNT(*) FROM project_members WHERE project_id = ?', [$p['id']]) > 1) fail('You are the only owner of “' . $p['name'] . '”. Name another owner or delete the project first.');
        $solo[] = (int) $p['id'];
    }
    foreach (rows("SELECT c.* FROM companies c JOIN company_members m ON m.company_id = c.id WHERE m.uid = ? AND m.role = 'owner'", [$uid]) as $c) {
        if ((int) val("SELECT COUNT(*) FROM company_members WHERE company_id = ? AND role = 'owner'", [$c['id']]) > 1) continue;
        if ((int) val('SELECT COUNT(*) FROM company_members WHERE company_id = ?', [$c['id']]) > 1) fail('You are the only owner of ' . $c['name'] . '. Name another owner first.');
        q('DELETE FROM companies WHERE id = ?', [$c['id']]);
    }
    tx(function () use ($uid, $solo): void {
        foreach ($solo as $pid) {
            foreach (rows('SELECT id FROM studios WHERE project_id = ?', [$pid]) as $s) studio_destroy((int) $s['id']);
            q('DELETE FROM projects WHERE id = ?', [$pid]);
        }
        q('UPDATE cards SET assignee = 0 WHERE assignee = ?', [$uid]);
        q('DELETE FROM card_members WHERE uid = ?', [$uid]);
        foreach (rows("SELECT * FROM files WHERE uid = ? AND scope <> 'studio'", [$uid]) as $f) file_delete($f);
        q('DELETE FROM users WHERE id = ?', [$uid]);
    });
    setcookie(SID_COOKIE, '', cookie_opts(time() - 3600));
    return [];
}

/* ---------------------------------------------------------------- routeur */

$route = ROUTES[$action] ?? null;
if (!$route) fail('Unknown action.', 404, 'notfound');
if ($route[0] === 'POST' && !$isPost) fail('Method not allowed.', 405, 'method');
if ($isPost && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'studioma') fail('Request refused.', 403, 'forbidden');

try {
    $user = $route[1] === 'user' ? require_user() : current_user();
    // Les envois de fichier arrivent en multipart : leurs champs sont dans $_POST.
    $data = !$isPost ? $_GET : ($action === 'upload' ? $_POST : input_json());
    $fn = 'api_' . $action;
    ok($fn($data, $user));
} catch (PDOException $e) {
    tx_rollback();
    error_log('studioma ' . $action . ': ' . $e->getMessage());
    $busy = stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false;
    out(['error' => $busy ? 'busy' : 'server', 'message' => $busy ? 'The service is busy. Please try again.' : 'Something went wrong. Please try again.'], $busy ? 503 : 500);
} catch (Throwable $e) {
    tx_rollback();
    error_log('studioma ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'server', 'message' => 'Something went wrong. Please try again.'], 500);
}
