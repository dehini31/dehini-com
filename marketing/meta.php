<?php
/**
 * Connexion « Se connecter avec Facebook » du Marketing Dashboard.
 *
 *   meta.php?start=1          → envoie vers Facebook (autorisations en lecture seule)
 *   meta.php?code=…&state=…   → retour de Facebook : jeton longue durée enregistré chiffré
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
        if (mk_meta_app_source() === '') back('sans-app');
        $state = bin2hex(random_bytes(16));
        $_SESSION['mk_meta_state'] = $state;
        session_write_close();
        header('Location: ' . mk_meta_login_url($state), true, 302);
        exit;
    }
    $expected = (string) ($_SESSION['mk_meta_state'] ?? '');
    unset($_SESSION['mk_meta_state']);
    session_write_close();
    if (isset($_GET['error'])) back('annule'); // l'utilisateur a refusé ou fermé la fenêtre
    $code = (string) ($_GET['code'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    if ($code === '' || $expected === '' || !hash_equals($expected, $state)) back('expire');
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
