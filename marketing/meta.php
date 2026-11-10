<?php
/**
 * Connexion « Se connecter avec Facebook » du Marketing Dashboard.
 *
 *   meta.php?start=1          → envoie vers Facebook (autorisations en lecture seule)
 *   meta.php?start=instagram  → envoie vers Instagram (« Se connecter avec Instagram »)
 *   meta.php?code=…&state=…   → retour de Facebook ou d'Instagram : jeton longue durée enregistré chiffré
 *
 * Réservé à la session de /admin, comme le tableau de bord. L'adresse de cette page doit être
 * ajoutée dans l'app Meta : Facebook Login › Paramètres › URI de redirection OAuth valides.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/meta.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function back(string $what): void
{
    header('Location: ./#connexions/meta/' . $what, true, 302);
    exit;
}

session_name('dehini_admin');
session_start();
$okAdmin = !empty($_SESSION['admin']) && !empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) < 12 * 3600;
if (!$okAdmin) {
    header('Location: ./', true, 302);
    exit;
}

try {
    db();
    if (isset($_GET['start'])) {
        $ig = $_GET['start'] === 'instagram';
        if ($ig && !mk_meta_public()['ig_app_ready']) back('sans-app-instagram');
        if (!$ig && mk_meta_app_source() === '') back('sans-app');
        $state = bin2hex(random_bytes(16));
        $_SESSION['mk_meta_state'] = $state;
        $_SESSION['mk_meta_flow'] = $ig ? 'instagram' : 'facebook';
        session_write_close();
        header('Location: ' . ($ig ? mk_meta_ig_login_url($state) : mk_meta_login_url($state)), true, 302);
        exit;
    }
    $expected = (string) ($_SESSION['mk_meta_state'] ?? '');
    $flow = (string) ($_SESSION['mk_meta_flow'] ?? 'facebook');
    unset($_SESSION['mk_meta_state'], $_SESSION['mk_meta_flow']);
    session_write_close();
    if (isset($_GET['error'])) back('annule'); // l'utilisateur a refusé ou fermé la fenêtre
    $code = (string) ($_GET['code'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    if ($code === '' || $expected === '' || !hash_equals($expected, $state)) back('expire');
    if ($flow === 'instagram') {
        mk_meta_ig_exchange($code);
        back('instagram-connecte');
    }
    mk_meta_exchange($code);
    mk_meta_put(['oauth_error' => '']);
    back('connecte');
} catch (MkMetaError $e) {
    mk_meta_put(['oauth_error' => $e->userMessage()]);
    back('erreur');
} catch (Throwable $e) {
    error_log('[marketing/meta] ' . $e->getMessage());
    try { mk_meta_put(['oauth_error' => $e->getMessage()]); } catch (Throwable $x) {}
    back('erreur');
}
