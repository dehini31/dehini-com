<?php
/**
 * Accès à l'administration. Le propriétaire se connecte avec le mot de passe de /admin
 * (admin/config.php, déjà en place sur dehini.com) ; l'équipe (gestionnaire, confirmatrice…)
 * avec un compte email + mot de passe créé dans l'admin.
 *
 * Rôles : owner (tout), manager (commandes, produits, contenus, statistiques), agent (commandes).
 */
declare(strict_types=1);

const ADMIN_SESSION = 'swb_admin';
const ADMIN_MAX_AGE = 12 * 3600;  // durée maximale d'une session
const ADMIN_IDLE = 4 * 3600;      // déconnexion après 4 h sans activité

const ROLE_RIGHTS = [
    'owner'   => ['orders', 'dashboard', 'catalog', 'content', 'shipping', 'meta', 'settings', 'team', 'backup'],
    'manager' => ['orders', 'dashboard', 'catalog', 'content', 'shipping'],
    'agent'   => ['orders'],
];

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(ADMIN_SESSION);
    session_set_cookie_params([
        'lifetime' => 0, 'path' => base_path() . '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** Session admin valide ? (sans en ouvrir une pour les visiteurs ordinaires). */
function admin_session_ok(): bool
{
    if (empty($_COOKIE[ADMIN_SESSION])) return false;
    admin_session_start();
    $ok = admin_user() !== null;
    session_write_close();
    return $ok;
}

function admin_user(): ?array
{
    $s = $_SESSION['swb'] ?? null;
    if (!is_array($s)) return null;
    $now = time();
    if ($now - (int) $s['t'] > ADMIN_MAX_AGE || $now - (int) $s['seen'] > ADMIN_IDLE) return null;
    if (($s['role'] ?? '') !== 'owner') {
        // Un compte désactivé ou supprimé perd l'accès immédiatement.
        $q = db()->prepare('SELECT active, role FROM admins WHERE id = ?');
        $q->execute([(int) $s['id']]);
        $r = $q->fetch();
        if (!$r || !(int) $r['active']) return null;
        $s['role'] = (string) $r['role'];
    }
    return $s;
}

function admin_can(?array $u, string $right): bool
{
    return $u !== null && in_array($right, ROLE_RIGHTS[$u['role']] ?? [], true);
}

function owner_password_hash(): string
{
    if (defined('SWB_OWNER_PASSWORD_HASH') && SWB_OWNER_PASSWORD_HASH !== '') return (string) SWB_OWNER_PASSWORD_HASH;
    if (!defined('ADMIN_PASSWORD_HASH') && is_file(SWB_ADMIN_CONFIG)) {
        try { require_once SWB_ADMIN_CONFIG; } catch (Throwable $e) { return ''; }
    }
    return defined('ADMIN_PASSWORD_HASH') ? (string) ADMIN_PASSWORD_HASH : '';
}

/** Connexion : retourne l'utilisateur ou un message d'erreur. */
function admin_login(string $email, string $pass): array
{
    $key = 'login:' . ip_hash();
    if (!rate_ok($key, 8, 900)) return ['error' => 'Trop d\'essais. Réessayez dans 15 minutes.'];
    usleep(250000);
    $user = null;
    if ($email === '') {
        $h = owner_password_hash();
        if ($h === '') return ['error' => 'Mot de passe du propriétaire non configuré (admin/config.php de dehini.com, voir README).'];
        if ($pass !== '' && password_verify($pass, $h)) $user = ['id' => 0, 'role' => 'owner', 'name' => 'Propriétaire', 'email' => ''];
    } else {
        $q = db()->prepare('SELECT * FROM admins WHERE email = ? COLLATE NOCASE');
        $q->execute([mb_strtolower(trim($email))]);
        $r = $q->fetch();
        if ($r && (int) $r['active'] && password_verify($pass, (string) $r['pass'])) {
            $user = ['id' => (int) $r['id'], 'role' => (string) $r['role'], 'name' => (string) $r['name'], 'email' => (string) $r['email']];
            db()->prepare('UPDATE admins SET last_login = ? WHERE id = ?')->execute([time(), $user['id']]);
            if (password_needs_rehash((string) $r['pass'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admins SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $user['id']]);
            }
        } else {
            password_verify($pass, '$2y$10$0s2STciRPWyWxWDV2zUA.eMq9h6E0/9xwZsJt4IRmblZMKLr.QRAq'); // temps constant
        }
    }
    if (!$user) return ['error' => 'Identifiants incorrects.'];
    rate_clear($key);
    admin_session_start();
    session_regenerate_id(true);
    $_SESSION['swb'] = $user + ['t' => time(), 'seen' => time(), 'csrf' => token(24)];
    return ['user' => $_SESSION['swb']];
}

function admin_logout(): void
{
    admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        setcookie(ADMIN_SESSION, '', ['expires' => time() - 3600, 'path' => base_path() . '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}
