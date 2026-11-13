<?php
/**
 * Temps réel : Meta appelle cette adresse à chaque nouveau message (Messenger, Instagram) et à chaque
 * nouveau commentaire (Facebook, Instagram). Elle est publique (Meta n'a pas de session), mais :
 *   - GET  : vérification de l'adresse par Meta (hub.verify_token = jeton gardé par le tableau de bord) ;
 *   - POST : chaque envoi doit être signé avec la clé secrète de l'app (en-tête X-Hub-Signature-256),
 *            sinon il est refusé. Rien n'est jamais renvoyé à Meta, à part « OK ».
 * L'abonnement se fait depuis Connexions › Temps réel.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/meta.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    db();
    $st = mk_meta_state();
} catch (Throwable $e) {
    http_response_code(500);
    exit('Erreur');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    // PHP remplace les points des paramètres par des « _ » : hub.mode → hub_mode.
    $mode = (string) ($_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    if ($mode === 'subscribe' && $st['wh_token'] !== '' && hash_equals((string) $st['wh_token'], $token)) {
        exit((string) preg_replace('/[^0-9A-Za-z_.\-]/', '', (string) ($_GET['hub_challenge'] ?? '')));
    }
    http_response_code(403);
    exit('Interdit');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}
$raw = (string) file_get_contents('php://input', false, null, 0, 2000000);
if (!mk_meta_wh_signed($raw, (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) {
    http_response_code(403);
    exit('Signature invalide');
}
$d = json_decode($raw, true);
if (!is_array($d)) {
    http_response_code(400);
    exit('Illisible');
}
ignore_user_abort(true);
@set_time_limit(60);
try {
    mk_meta_webhook_handle($d);
} catch (Throwable $e) {
    error_log('[marketing/webhook] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
echo 'OK';
