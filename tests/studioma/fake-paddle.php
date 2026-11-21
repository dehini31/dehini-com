<?php
/**
 * Faux Paddle Billing pour les tests (servi par tests/studioma/router.php sous /fake-paddle/, jamais en
 * production) : clients, transactions, abonnements, résiliation, portail, au format de l'API Paddle v1.
 * POST /fake-paddle/__complete {transaction_id} simule le paiement réussi d'une transaction : il crée
 * l'abonnement et rend les objets que les tests envoient ensuite, signés, au webhook de Studioma.
 */
declare(strict_types=1);

header('Content-Type: application/json');
$stateFile = dirname((string) $_SERVER['DOCUMENT_ROOT']) . '/fake-paddle.json';
$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$st = json_decode((string) stream_get_contents($fp), true) ?: ['n' => 0, 'customers' => [], 'transactions' => [], 'subscriptions' => [], 'calls' => []];
$save = function () use (&$st, $fp): void { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($st)); fflush($fp); };
$send = function (int $code, array $body) use ($save, $fp): void { $save(); flock($fp, LOCK_UN); http_response_code($code); echo json_encode($body); exit; };
$err = function (int $code, string $c, string $detail) use ($send): void { $send($code, ['error' => ['type' => 'request_error', 'code' => $c, 'detail' => $detail]]); };

$path = substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen('/fake-paddle'));
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$st['calls'][] = $method . ' ' . $path;
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($path !== '/__complete' && $path !== '/__state' && $auth !== 'Bearer test-paddle-api-key') $err(403, 'forbidden', 'Invalid API key.');

const PRICES = ['pri_solo_month' => [1200, 'month'], 'pri_solo_year' => [12000, 'year'], 'pri_team_month' => [2900, 'month'], 'pri_team_year' => [29000, 'year']];
$id = function (string $p) use (&$st): string { $st['n']++; return $p . '_' . str_pad((string) $st['n'], 26, '0', STR_PAD_LEFT); };
$stamp = function () use (&$st): string { $st['n']++; return gmdate('Y-m-d\TH:i:s') . '.' . sprintf('%06d', $st['n']) . 'Z'; };
$price = function (string $pid): array {
    if (!isset(PRICES[$pid])) return [];
    return ['id' => $pid, 'billing_cycle' => ['interval' => PRICES[$pid][1], 'frequency' => 1], 'unit_price' => ['amount' => (string) PRICES[$pid][0], 'currency_code' => 'USD']];
};
$period = function (string $pid, int $from): array {
    $end = strtotime(PRICES[$pid][1] === 'year' ? '+1 year' : '+1 month', $from);
    return ['starts_at' => gmdate('Y-m-d\TH:i:s\Z', $from), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end)];
};

if ($method === 'GET' && $path === '/__state') $send(200, $st);
if ($method === 'GET' && $path === '/customers') {
    $email = strtolower((string) ($_GET['email'] ?? ''));
    $send(200, ['data' => array_values(array_filter($st['customers'], function (array $c) use ($email): bool { return $c['email'] === $email; }))]);
}
if ($method === 'POST' && $path === '/customers') {
    $c = ['id' => $id('ctm'), 'email' => strtolower((string) ($body['email'] ?? '')), 'name' => (string) ($body['name'] ?? '')];
    $st['customers'][$c['id']] = $c;
    $send(201, ['data' => $c]);
}
if ($method === 'POST' && $path === '/transactions') {
    $pid = (string) ($body['items'][0]['price_id'] ?? '');
    if (!$price($pid)) $err(400, 'invalid_field', 'Unknown price.');
    if (!isset($st['customers'][$body['customer_id'] ?? ''])) $err(400, 'invalid_field', 'Unknown customer.');
    $t = ['id' => $id('txn'), 'status' => 'ready', 'customer_id' => $body['customer_id'], 'custom_data' => $body['custom_data'] ?? null, 'currency_code' => 'USD',
        'items' => [['price' => $price($pid), 'quantity' => 1]], 'origin' => 'web', 'subscription_id' => null,
        'details' => ['totals' => ['grand_total' => (string) PRICES[$pid][0]]], 'billed_at' => null];
    $t['checkout'] = ['url' => ($body['checkout']['url'] ?? 'https://pay.example/') . '?_ptxn=' . $t['id']];
    $st['transactions'][$t['id']] = $t;
    $send(201, ['data' => $t]);
}
if ($method === 'POST' && $path === '/__complete') {
    $t = $st['transactions'][$body['transaction_id'] ?? ''] ?? null;
    if (!$t) $err(404, 'not_found', 'Unknown transaction.');
    $pid = $t['items'][0]['price']['id'];
    $now = time();
    $s = ['id' => $id('sub'), 'status' => 'active', 'customer_id' => $t['customer_id'], 'currency_code' => 'USD', 'custom_data' => $t['custom_data'],
        'items' => [['status' => 'active', 'quantity' => 1, 'price' => $price($pid)]], 'current_billing_period' => $period($pid, $now),
        'scheduled_change' => null, 'canceled_at' => null, 'created_at' => $stamp(), 'updated_at' => $stamp()];
    $st['subscriptions'][$s['id']] = $s;
    $t['status'] = 'completed';
    $t['subscription_id'] = $s['id'];
    $t['billed_at'] = gmdate('Y-m-d\TH:i:s\Z', $now);
    $st['transactions'][$t['id']] = $t;
    $send(200, ['subscription' => $s, 'transaction' => $t]);
}
if (preg_match('~^/subscriptions/(sub_\w+)(/cancel)?$~', $path, $m)) {
    $s = $st['subscriptions'][$m[1]] ?? null;
    if (!$s) $err(404, 'entity_not_found', 'Subscription not found.');
    if ($method === 'GET') $send(200, ['data' => $s]);
    if ($method === 'POST' && !empty($m[2])) {
        $s['scheduled_change'] = ['action' => 'cancel', 'effective_at' => $s['current_billing_period']['ends_at'], 'resume_at' => null];
    } elseif ($method === 'PATCH') {
        if (array_key_exists('scheduled_change', $body) && $body['scheduled_change'] === null) $s['scheduled_change'] = null;
        if (isset($body['items'][0]['price_id'])) {
            $pid = (string) $body['items'][0]['price_id'];
            if (!$price($pid)) $err(400, 'invalid_field', 'Unknown price.');
            if (!in_array($body['proration_billing_mode'] ?? '', ['prorated_immediately', 'prorated_next_billing_period'], true)) $err(400, 'invalid_field', 'proration_billing_mode required.');
            $s['items'][0]['price'] = $price($pid);
            $st['last_proration'] = $body['proration_billing_mode'];
        }
    } else {
        $err(405, 'method', 'Method not allowed.');
    }
    $s['updated_at'] = $stamp();
    $st['subscriptions'][$s['id']] = $s;
    $send(200, ['data' => $s]);
}
if ($method === 'POST' && preg_match('~^/customers/(ctm_\w+)/portal-sessions$~', $path, $m)) {
    if (!isset($st['customers'][$m[1]])) $err(404, 'entity_not_found', 'Customer not found.');
    $send(201, ['data' => ['id' => $id('cpls'), 'urls' => ['general' => ['overview' => 'https://customer-portal.paddle.example/' . $m[1]]]]]);
}
$err(404, 'not_found', 'Unknown endpoint ' . $method . ' ' . $path);
