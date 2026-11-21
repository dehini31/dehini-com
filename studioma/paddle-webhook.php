<?php
/**
 * Notifications de Paddle (webhooks) : abonnements et paiements. Adresse à déclarer dans Paddle ›
 * Developer tools › Notifications : https://dehini.com/studioma/paddle-webhook.php — événements subscription.*
 * et transaction.completed, transaction.paid, transaction.payment_failed, transaction.past_due.
 * La signature est vérifiée avec STM_PADDLE_WEBHOOK_SECRET ; le traitement est dans lib/paddle.php.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/plans.php';
require __DIR__ . '/lib/billing.php';
require __DIR__ . '/lib/paddle.php';

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
    [$code, $body] = paddle_webhook_handle($raw, (string) ($_SERVER['HTTP_PADDLE_SIGNATURE'] ?? ''));
} catch (Throwable $e) {
    error_log('studioma paddle webhook: ' . $e->getMessage());
    [$code, $body] = [500, ['error' => 'server']];
}
http_response_code($code);
echo json_encode($body);
