<?php
/**
 * Faux PayPal pour les tests (servi par tests/studioma/router.php sous /fake-paypal/, jamais en production) :
 * jeton OAuth, produits, formules, abonnements (création, lecture, « revise », résiliation), vérification des
 * webhooks, au format de l'API REST de PayPal (v1). Pages et outils propres aux tests :
 *   GET  /fake-paypal/approve?sub=I-…[&cancel=1]  accord (ou abandon) du payeur, puis retour vers return_url / cancel_url
 *   GET  /fake-paypal/__state                     état complet (appels reçus compris)
 *   POST /fake-paypal/__set {sub?, patch?, flags?} modifie un abonnement (échec de paiement, suspension…) ou un drapeau
 *   POST /fake-paypal/__sale {sub}                 simule un prélèvement réussi et rend la vente (pour PAYMENT.SALE.*)
 *   POST /fake-paypal/__create {plan_id, custom_id} abonnement créé ailleurs que par Studioma (déjà actif)
 * Webhooks : signature de test = HMAC-SHA256(« fake-paypal-signing », id|heure|webhook_id|sha256(événement brut)).
 */
declare(strict_types=1);

header('Content-Type: application/json');
$stateFile = dirname((string) $_SERVER['DOCUMENT_ROOT']) . '/fake-paypal.json';
$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$st = json_decode((string) stream_get_contents($fp), true) ?: ['n' => 0, 'tokens' => [], 'products' => [], 'plans' => [], 'subs' => [], 'sales' => [], 'calls' => [], 'flags' => []];
$save = function () use (&$st, $fp): void { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($st)); fflush($fp); };
$send = function (int $code, ?array $body) use ($save, $fp): void { $save(); flock($fp, LOCK_UN); http_response_code($code); if ($body !== null) echo json_encode($body, JSON_UNESCAPED_SLASHES); exit; };
$err = function (int $code, string $name, string $issue, string $desc) use ($send): void {
    $send($code, ['name' => $name, 'message' => $desc, 'debug_id' => 'fake', 'details' => [['issue' => $issue, 'description' => $desc]]]);
};

const PLANS = ['P-SOLOMONTH0000000000000000' => [1200, 'MONTH'], 'P-SOLOYEAR00000000000000000' => [12000, 'YEAR'],
    'P-TEAMMONTH0000000000000000' => [2900, 'MONTH'], 'P-TEAMYEAR00000000000000000' => [29000, 'YEAR']];
const CLIENT = 'test-client-id-0123456789';
const SECRET = 'test-secret-0123456789abcdef';
const WEBHOOK = 'WHTEST0123456789';

$path = substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen('/fake-paypal'));
$method = $_SERVER['REQUEST_METHOD'];
$input = (string) file_get_contents('php://input');
$body = json_decode($input, true) ?: [];
$st['calls'][] = $method . ' ' . $path;
$origin = 'http://' . $_SERVER['HTTP_HOST'];
$iso = function (int $t): string { return gmdate('Y-m-d\TH:i:s\Z', $t); };
$next = function (string $p): string { return isset(PLANS[$p]) && PLANS[$p][1] === 'YEAR' ? '+1 year' : '+1 month'; };
$seq = function (string $prefix, int $len) use (&$st): string { $st['n']++; return $prefix . str_pad((string) $st['n'], $len, '0', STR_PAD_LEFT); };
// Horodatage strictement croissant (comparé par Studioma pour ignorer un état plus ancien).
$stamp = function () use (&$st, $iso): string { $st['clock'] = max(time(), (int) ($st['clock'] ?? 0) + 1); return $iso($st['clock']); };

/* ---- outils des tests (sans authentification) ---- */
if ($method === 'GET' && $path === '/__state') $send(200, $st);
if ($method === 'POST' && $path === '/__set') {
    foreach ((array) ($body['flags'] ?? []) as $k => $v) $st['flags'][$k] = $v;
    if (isset($body['sub'])) {
        $s = $st['subs'][$body['sub']] ?? null;
        if (!$s) $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'Unknown subscription.');
        $s = array_replace_recursive($s, (array) ($body['patch'] ?? []));
        if (array_key_exists('next_billing_time', (array) ($body['patch']['billing_info'] ?? [])) && $body['patch']['billing_info']['next_billing_time'] === null) unset($s['billing_info']['next_billing_time']);
        $s['update_time'] = $stamp();
        if (isset($body['patch']['status'])) $s['status_update_time'] = $s['update_time'];
        $st['subs'][$s['id']] = $s;
        $send(200, $s);
    }
    $send(200, ['flags' => $st['flags']]);
}
if ($method === 'POST' && $path === '/__sale') {
    $s = $st['subs'][$body['sub'] ?? ''] ?? null;
    if (!$s) $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'Unknown subscription.');
    $amount = number_format(PLANS[$s['plan_id']][0] / 100, 2, '.', '');
    $sale = ['id' => $seq('SALE', 13), 'state' => 'completed', 'amount' => ['total' => $amount, 'currency' => 'USD', 'details' => ['subtotal' => $amount]],
        'billing_agreement_id' => $s['id'], 'custom' => $s['custom_id'] ?? '', 'create_time' => $iso(time()), 'update_time' => $iso(time())];
    $st['sales'][$sale['id']] = $sale;
    $send(200, $sale);
}
if ($method === 'POST' && $path === '/__create') {
    $now = time();
    $s = ['id' => $seq('I-EXT', 12), 'status' => 'ACTIVE', 'plan_id' => (string) ($body['plan_id'] ?? ''), 'custom_id' => (string) ($body['custom_id'] ?? ''),
        'start_time' => $iso($now), 'create_time' => $iso($now), 'update_time' => $stamp(), 'subscriber' => ['email_address' => 'someone@paypal.example', 'payer_id' => 'PAYEREXT'],
        'billing_info' => ['next_billing_time' => $iso(strtotime($next((string) ($body['plan_id'] ?? '')), $now)), 'failed_payments_count' => 0]];
    $st['subs'][$s['id']] = $s;
    $send(200, $s);
}
/* ---- page d'accord du payeur (navigateur) ---- */
if ($method === 'GET' && $path === '/approve') {
    $s = $st['subs'][$_GET['sub'] ?? ''] ?? null;
    if (!$s) $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'Unknown subscription.');
    $ctx = $s['pending_context'] ?? $s['context'];
    if (!empty($_GET['cancel'])) {
        $save(); flock($fp, LOCK_UN);
        header('Location: ' . $ctx['cancel_url'] . (strpos($ctx['cancel_url'], '?') === false ? '?' : '&') . 'token=EC-FAKE');
        http_response_code(302);
        exit;
    }
    $now = time();
    if ($s['status'] === 'APPROVAL_PENDING') {
        $s['status'] = 'ACTIVE';
        $s['start_time'] = $iso($now);
        $s['subscriber']['payer_id'] = 'PAYER' . strtoupper(substr(md5($s['id']), 0, 8));
        $s['billing_info'] = ['next_billing_time' => $iso(strtotime($next($s['plan_id']), $now)), 'failed_payments_count' => 0,
            'last_payment' => ['amount' => ['currency_code' => 'USD', 'value' => number_format(PLANS[$s['plan_id']][0] / 100, 2, '.', '')], 'time' => $iso($now)]];
        $s['status_update_time'] = $stamp();
    }
    if (!empty($s['pending_plan'])) {
        $s['plan_id'] = $s['pending_plan'];   // nouvelle formule ; nouveau prix au prochain renouvellement
        unset($s['pending_plan'], $s['pending_context']);
    }
    $s['update_time'] = $stamp();
    $st['subs'][$s['id']] = $s;
    $save(); flock($fp, LOCK_UN);
    header('Location: ' . $ctx['return_url'] . (strpos($ctx['return_url'], '?') === false ? '?' : '&') . 'subscription_id=' . $s['id'] . '&ba_token=BA-FAKE&token=EC-FAKE');
    http_response_code(302);
    exit;
}

/* ---- API ---- */
if ($method === 'POST' && $path === '/v1/oauth2/token') {
    $basic = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    [$user, $pw] = array_pad(explode(':', str_starts_with($basic, 'Basic ') ? (string) base64_decode(substr($basic, 6)) : '', 2), 2, '');
    if ($user !== CLIENT || $pw !== SECRET || $input !== 'grant_type=client_credentials') {
        $send(401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']);
    }
    $t = 'fake-token-' . bin2hex(random_bytes(6));
    $st['tokens'][] = $t;
    $send(200, ['scope' => 'https://uri.paypal.com/services/subscriptions', 'access_token' => $t, 'token_type' => 'Bearer', 'expires_in' => 32400]);
}
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer (fake-token-\w+)$/', $auth, $m) || !in_array($m[1], $st['tokens'], true)) $send(401, ['error' => 'invalid_token', 'error_description' => 'Token signature verification failed']);
if (!empty($st['flags']['down'])) $err(503, 'SERVICE_UNAVAILABLE', 'SERVICE_UNAVAILABLE', 'Service unavailable.');

if ($method === 'POST' && $path === '/v1/notifications/verify-webhook-signature') {
    // Le corps de l'événement est lu tel qu'envoyé par Studioma (il doit être identique à celui reçu de PayPal).
    $at = strpos($input, '"webhook_event":');
    $raw = $at === false ? '' : substr($input, $at + 16, -1);
    $ok = $raw !== '' && is_array(json_decode($raw, true)) && ($body['webhook_id'] ?? '') === WEBHOOK && ($body['auth_algo'] ?? '') !== '' && ($body['cert_url'] ?? '') !== ''
        && hash_equals(hash_hmac('sha256', ($body['transmission_id'] ?? '') . '|' . ($body['transmission_time'] ?? '') . '|' . WEBHOOK . '|' . hash('sha256', $raw), 'fake-paypal-signing'), (string) ($body['transmission_sig'] ?? ''));
    $st['verifications'][] = $ok;
    $send(200, ['verification_status' => $ok ? 'SUCCESS' : 'FAILURE']);
}
if ($method === 'POST' && $path === '/v1/catalogs/products') {
    if (($body['name'] ?? '') === '' || ($body['type'] ?? '') !== 'SERVICE') $err(400, 'INVALID_REQUEST', 'INVALID_PARAMETER_VALUE', 'Invalid product.');
    $p = ['id' => $seq('PROD-', 17), 'name' => $body['name'], 'type' => $body['type'], 'category' => $body['category'] ?? ''];
    $st['products'][$p['id']] = $p;
    $send(201, $p);
}
if ($method === 'POST' && $path === '/v1/billing/plans') {
    if (!isset($st['products'][$body['product_id'] ?? ''])) $err(400, 'INVALID_REQUEST', 'INVALID_PARAMETER_VALUE', 'Unknown product.');
    $p = $body + ['id' => $seq('P-NEW', 21)];
    $st['plans'][$p['id']] = $p;
    $send(201, ['id' => $p['id'], 'product_id' => $p['product_id'], 'name' => $p['name'], 'status' => $p['status'] ?? 'ACTIVE']);
}
if ($method === 'POST' && $path === '/v1/billing/subscriptions') {
    $pid = (string) ($body['plan_id'] ?? '');
    if (!isset(PLANS[$pid])) $err(422, 'UNPROCESSABLE_ENTITY', 'INVALID_PLAN', 'The plan does not exist.');
    $ctx = $body['application_context'] ?? [];
    if (($ctx['return_url'] ?? '') === '' || ($ctx['cancel_url'] ?? '') === '') $err(400, 'INVALID_REQUEST', 'MISSING_REQUIRED_PARAMETER', 'return_url and cancel_url are required.');
    $now = time();
    $s = ['id' => $seq('I-', 12), 'status' => 'APPROVAL_PENDING', 'plan_id' => $pid, 'custom_id' => (string) ($body['custom_id'] ?? ''),
        'subscriber' => ['email_address' => (string) ($body['subscriber']['email_address'] ?? '')], 'create_time' => $iso($now), 'update_time' => $stamp(),
        'context' => $ctx, 'request_id' => (string) ($_SERVER['HTTP_PAYPAL_REQUEST_ID'] ?? '')];
    $st['subs'][$s['id']] = $s;
    $send(201, ['id' => $s['id'], 'status' => $s['status'], 'create_time' => $s['create_time'], 'links' => [
        ['href' => $origin . '/fake-paypal/approve?sub=' . $s['id'], 'rel' => 'approve', 'method' => 'GET'],
        ['href' => $origin . '/fake-paypal/v1/billing/subscriptions/' . $s['id'], 'rel' => 'self', 'method' => 'GET']]]);
}
if (preg_match('~^/v1/billing/subscriptions/(I-[A-Z0-9]+)(/revise|/cancel)?$~', $path, $m)) {
    $s = $st['subs'][$m[1]] ?? null;
    if (!$s) $err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'The specified resource does not exist.');
    $public = function (array $s): array { unset($s['context'], $s['pending_context'], $s['pending_plan'], $s['request_id']); return $s; };
    if ($method === 'GET' && empty($m[2])) $send(200, $public($s));
    if ($method === 'POST' && ($m[2] ?? '') === '/cancel') {
        if (($body['reason'] ?? '') === '') $err(400, 'INVALID_REQUEST', 'MISSING_REQUIRED_PARAMETER', 'reason is required.');
        if (!in_array($s['status'], ['ACTIVE', 'SUSPENDED'], true)) $err(422, 'UNPROCESSABLE_ENTITY', 'SUBSCRIPTION_STATUS_INVALID', 'Invalid subscription status for cancel action.');
        $s['status'] = 'CANCELLED';
        unset($s['billing_info']['next_billing_time']);
        $s['status_update_time'] = $s['update_time'] = $stamp();
        $st['subs'][$s['id']] = $s;
        $send(204, null);
    }
    if ($method === 'POST' && ($m[2] ?? '') === '/revise') {
        $pid = (string) ($body['plan_id'] ?? '');
        if (!isset(PLANS[$pid])) $err(422, 'UNPROCESSABLE_ENTITY', 'INVALID_PLAN', 'The plan does not exist.');
        if ($s['status'] !== 'ACTIVE') $err(422, 'UNPROCESSABLE_ENTITY', 'SUBSCRIPTION_STATUS_INVALID', 'Invalid subscription status for revise action.');
        $st['last_revise'] = $pid;
        if (!empty($st['flags']['no_consent'])) {
            $s['plan_id'] = $pid;   // paiement par carte enregistrée : pas d'accord à redemander
            $s['update_time'] = $stamp();
            $st['subs'][$s['id']] = $s;
            $send(200, ['plan_id' => $pid, 'plan_overridden' => false, 'links' => []]);
        }
        $s['pending_plan'] = $pid;
        $s['pending_context'] = $body['application_context'] ?? $s['context'];
        $st['subs'][$s['id']] = $s;
        $send(200, ['plan_id' => $pid, 'plan_overridden' => false, 'links' => [['href' => $origin . '/fake-paypal/approve?sub=' . $s['id'], 'rel' => 'approve', 'method' => 'GET']]]);
    }
    $err(405, 'METHOD_NOT_SUPPORTED', 'METHOD_NOT_SUPPORTED', 'Method not supported.');
}
$err(404, 'RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID', 'Unknown endpoint ' . $method . ' ' . $path);
