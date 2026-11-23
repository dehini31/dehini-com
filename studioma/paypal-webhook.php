<?php
/**
 * Notifications de PayPal (webhooks) : abonnements et paiements. Adresse à déclarer dans l'application PayPal
 * (developer.paypal.com › Apps & Credentials › l'application › Webhooks) : https://dehini.com/studioma/paypal-webhook.php
 * — événements BILLING.SUBSCRIPTION.* (ACTIVATED, UPDATED, CANCELLED, SUSPENDED, EXPIRED, PAYMENT.FAILED…) et
 * PAYMENT.SALE.COMPLETED, PAYMENT.SALE.REFUNDED, PAYMENT.SALE.REVERSED. L'identifiant du webhook va dans
 * STM_PAYPAL_WEBHOOK_ID ; chaque notification est vérifiée par PayPal ; le traitement est dans lib/paypal.php.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/plans.php';
require __DIR__ . '/lib/billing.php';
require __DIR__ . '/lib/paypal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo '{"error":"method"}';
    exit;
}
try {
    db();
    $raw = (string) file_get_contents('php://input');
    $headers = [];
    foreach (['PAYPAL-AUTH-ALGO', 'PAYPAL-CERT-URL', 'PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-TRANSMISSION-TIME'] as $h) {
        $headers[$h] = (string) ($_SERVER['HTTP_' . str_replace('-', '_', $h)] ?? '');
    }
    [$code, $body] = strlen($raw) > 200000 ? [413, ['error' => 'too large']] : paypal_webhook_handle($raw, $headers);
} catch (Throwable $e) {
    error_log('studioma paypal webhook: ' . $e->getMessage());
    [$code, $body] = [500, ['error' => 'server']];
}
http_response_code($code);
echo json_encode($body);
