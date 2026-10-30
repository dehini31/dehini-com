<?php
/**
 * Paiements de soutien par carte (SofizPay) : retour de la page de paiement et
 * notification serveur à serveur.
 *
 *   GET       support.php?r=<ref>   la personne revient du paiement → vérification → retour sur la page
 *   GET|POST  support.php?hook=1    notification de SofizPay → vérification → JSON
 *
 * Rien de ce qui arrive ici n'est cru sur parole : donation_confirm() interroge SofizPay
 * et contrôle le montant et le compte destinataire avant de marquer le don comme payé.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/sofizpay.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function donation_by(string $col, $v): ?array
{
    $s = db()->prepare("SELECT * FROM donations WHERE $col = ?");
    $s->execute([$v]);
    return $s->fetch() ?: null;
}

if (isset($_GET['hook'])) {
    $in = $_POST + $_GET;
    $json = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($json)) $in += $json;
    $don = null;
    if (preg_match('/^DON-(\d+)$/', (string) ($in['invoice_id'] ?? ($in['reference'] ?? '')), $m)) $don = donation_by('id', (int) $m[1]);
    if (!$don) {
        $cib = (string) ($in['cib_transaction_id'] ?? ($in['order_number'] ?? ''));
        if ($cib !== '') $don = donation_by('cib', $cib);
    }
    if ($don) donation_confirm($don);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['received' => true]);
    exit;
}

$ref = (string) ($_GET['r'] ?? '');
$don = preg_match('/^[a-f0-9]{24}$/', $ref) ? donation_by('ref', $ref) : null;
$st = $don ? donation_confirm($don) : 'failed';
$res = ['paid' => 'ok', 'pending' => 'wait'][$st] ?? 'ko';
header('Location: ./?lang=' . ($don ? impact_lang($don['lang']) : 'fr') . '&don=' . $res . '#soutenir', true, 302);
