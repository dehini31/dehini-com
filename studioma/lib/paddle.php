<?php
/**
 * Paiement des abonnements avec Paddle Billing (API REST v1, sans SDK : pas de gestionnaire de paquets ici).
 *
 * - Paiement : le serveur crée une transaction Paddle liée au compte connecté (client Paddle + custom_data) ; la
 *   page studioma/pay/ ouvre le paiement hébergé de Paddle (Paddle.js). Aucune donnée de carte ne passe par nous.
 * - La formule n'est JAMAIS activée par le retour du navigateur : seulement par les données de Paddle reçues de
 *   serveur à serveur — webhook signé (studioma/paddle-webhook.php) ou réponse de l'API à un appel du serveur.
 * - Webhooks : signature HMAC vérifiée (« Paddle-Signature »), chaque événement traité une seule fois (event_id),
 *   un événement plus ancien que l'état connu de l'abonnement est ignoré (updated_at), échecs journalisés.
 *
 * Réglages : voir lib/billing.php (STM_PADDLE_*). STM_PADDLE_API_BASE ne sert qu'aux tests (faux Paddle).
 */
declare(strict_types=1);

class PaddleError extends RuntimeException
{
    /** @var string */
    public $paddleCode;
    public function __construct(string $message, string $code = '', int $http = 0)
    {
        parent::__construct($message, $http);
        $this->paddleCode = $code;
    }
}

function paddle_base(): string
{
    $override = (string) billing_cfg('PADDLE_API_BASE');
    if ($override !== '') return rtrim($override, '/');
    return billing_env() === 'production' ? 'https://api.paddle.com' : 'https://sandbox-api.paddle.com';
}
/** Appel à l'API de Paddle ; retourne « data ». Lève PaddleError (message de Paddle) en cas d'échec. */
function paddle_api(string $method, string $path, ?array $body = null): array
{
    $key = (string) billing_cfg('PADDLE_API_KEY');
    if ($key === '') throw new PaddleError('Paddle is not configured.', 'not_configured');
    $ch = curl_init(paddle_base() . $path);
    $headers = ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Paddle-Version: 1'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new PaddleError('Paddle could not be reached (' . $err . ').', 'network');
    $d = json_decode((string) $raw, true);
    if ($http >= 400 || !is_array($d) || isset($d['error'])) {
        $e = is_array($d) ? ($d['error'] ?? []) : [];
        throw new PaddleError((string) ($e['detail'] ?? ('Paddle answered ' . $http . '.')), (string) ($e['code'] ?? 'http_' . $http), $http);
    }
    return is_array($d['data'] ?? null) ? $d['data'] : [];
}
function paddle_ts($iso): int
{
    return is_string($iso) && $iso !== '' ? (int) strtotime($iso) : 0;
}

/* ------------------------------------------------------- client du compte */

/** Client Paddle du compte (créé au premier paiement, retrouvé par son adresse s'il existe déjà chez Paddle). */
function paddle_customer(array $u): string
{
    $env = billing_env();
    $id = (string) (val('SELECT customer_id FROM billing_customers WHERE uid = ? AND env = ?', [$u['id'], $env]) ?? '');
    if ($id !== '') return $id;
    $email = (string) $u['email'];
    $found = paddle_api('GET', '/customers?email=' . rawurlencode($email));
    if (isset($found[0]['id'])) {
        $id = (string) $found[0]['id'];
    } else {
        $id = (string) (paddle_api('POST', '/customers', ['email' => $email, 'name' => mb_substr((string) $u['name'], 0, 100)])['id'] ?? '');
    }
    if ($id === '') throw new PaddleError('Paddle did not return a customer.', 'customer');
    // Un client Paddle n'appartient qu'à un compte (une même adresse ne peut pas servir deux comptes).
    $other = val('SELECT uid FROM billing_customers WHERE env = ? AND customer_id = ? AND uid <> ?', [$env, $id, $u['id']]);
    if ($other) throw new PaddleError('This email address is already used by another Studioma account for billing.', 'customer_conflict');
    q('INSERT OR IGNORE INTO billing_customers (uid, env, customer_id, created) VALUES (?, ?, ?, ?)', [$u['id'], $env, $id, time()]);
    return $id;
}

/* ------------------------------------------------ synchronisation d'un abonnement */

/** Compte Studioma d'un objet Paddle : d'abord par le client Paddle créé par nous, sinon par custom_data. */
function paddle_owner(array $d): int
{
    $cid = (string) ($d['customer_id'] ?? '');
    if ($cid !== '') {
        $uid = (int) (val('SELECT uid FROM billing_customers WHERE env = ? AND customer_id = ?', [billing_env(), $cid]) ?? 0);
        if ($uid) return $uid;
    }
    $uid = (int) (($d['custom_data']['stm_uid'] ?? 0));
    return $uid > 0 && user_by_id($uid) ? $uid : 0;
}
/**
 * Enregistre l'état d'un abonnement Paddle (données vérifiées : webhook signé ou réponse de l'API). Retourne
 * « applied », « stale » (plus ancien que l'état connu) ; lève une exception si l'abonnement est inexploitable.
 */
function paddle_sync_subscription(array $d): string
{
    $subId = (string) ($d['id'] ?? '');
    if (!preg_match('/^sub_[A-Za-z0-9]+$/', $subId)) throw new RuntimeException('Subscription without a valid id.');
    $existing = row("SELECT * FROM subscriptions WHERE provider = 'paddle' AND provider_sub_id = ?", [$subId]);
    $updated = (string) ($d['updated_at'] ?? '');
    if ($existing && $existing['provider_updated'] !== '' && $updated !== '' && strcmp($updated, $existing['provider_updated']) < 0) return 'stale';
    $uid = $existing ? (int) $existing['uid'] : paddle_owner($d);
    if (!$uid) throw new RuntimeException('Subscription ' . $subId . ' belongs to no Studioma account (unknown customer).');
    $item = $d['items'][0] ?? [];
    $price = $item['price'] ?? [];
    $pp = billing_price_plan((string) ($price['id'] ?? ''));
    if (!$pp) throw new RuntimeException('Subscription ' . $subId . ' uses a price that is not configured in Studioma.');
    [$plan, $interval] = $pp;
    $status = in_array($d['status'] ?? '', ['trialing', 'active', 'past_due', 'paused', 'canceled'], true) ? (string) $d['status'] : 'canceled';
    $now = time();
    $pastDue = $status === 'past_due' ? ($existing && $existing['status'] === 'past_due' && (int) $existing['past_due_since'] ? (int) $existing['past_due_since'] : $now) : 0;
    $sc = $d['scheduled_change'] ?? null;
    $data = [
        'uid' => $uid, 'provider' => 'paddle', 'env' => billing_env(), 'provider_sub_id' => $subId, 'provider_customer_id' => (string) ($d['customer_id'] ?? ''),
        'plan' => $plan, 'interval' => $interval, 'status' => $status,
        'amount' => (int) ($price['unit_price']['amount'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1)), 'currency' => (string) ($d['currency_code'] ?? ($price['unit_price']['currency_code'] ?? '')),
        'period_start' => paddle_ts($d['current_billing_period']['starts_at'] ?? null), 'period_end' => paddle_ts($d['current_billing_period']['ends_at'] ?? null),
        'cancel_at' => is_array($sc) && ($sc['action'] ?? '') === 'cancel' ? paddle_ts($sc['effective_at'] ?? null) : 0,
        'canceled_at' => paddle_ts($d['canceled_at'] ?? null), 'past_due_since' => $pastDue, 'provider_updated' => $updated, 'updated' => $now,
    ];
    if ($existing) {
        q('UPDATE subscriptions SET ' . implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($data))) . ' WHERE id = ?', array_merge(array_values($data), [$existing['id']]));
    } else {
        // Un deuxième abonnement payant actif pour le même compte est signalé (jamais facturé deux fois par nous).
        $dup = val("SELECT provider_sub_id FROM subscriptions WHERE uid = ? AND provider = 'paddle' AND env = ? AND status IN ('trialing', 'active', 'past_due') AND provider_sub_id <> ?", [$uid, billing_env(), $subId]);
        insert('subscriptions', $data + ['created' => $now, 'note' => $dup ? 'Duplicate of ' . $dup . ' — check in Paddle' : '']);
    }
    if ($status !== 'canceled' && $data['provider_customer_id'] !== '') {
        q('INSERT OR IGNORE INTO billing_customers (uid, env, customer_id, created) VALUES (?, ?, ?, ?)', [$uid, billing_env(), $data['provider_customer_id'], $now]);
    }
    billing_cache_clear();
    return 'applied';
}
/** Enregistre un paiement (ou son échec) d'une transaction Paddle. */
function paddle_record_transaction(array $d, string $type): void
{
    $txn = (string) ($d['id'] ?? '');
    if (!preg_match('/^txn_[A-Za-z0-9]+$/', $txn)) throw new RuntimeException('Transaction without a valid id.');
    $uid = paddle_owner($d);
    if (!$uid) throw new RuntimeException('Transaction ' . $txn . ' belongs to no Studioma account (unknown customer).');
    $status = $type === 'transaction.payment_failed' ? 'payment_failed' : (string) ($d['status'] ?? 'completed');
    $row = [
        'uid' => $uid, 'env' => billing_env(), 'provider_sub_id' => (string) ($d['subscription_id'] ?? ''), 'status' => $status,
        'amount' => (int) ($d['details']['totals']['grand_total'] ?? 0), 'currency' => (string) ($d['currency_code'] ?? ''),
        'origin' => (string) ($d['origin'] ?? ''), 'billed_at' => paddle_ts($d['billed_at'] ?? null) ?: time(),
    ];
    if (val('SELECT 1 FROM billing_transactions WHERE provider_txn_id = ?', [$txn])) {
        // Un paiement réussi n'est jamais remplacé par un échec arrivé plus tard (désordre des événements).
        if ($status === 'payment_failed') q("UPDATE billing_transactions SET status = ? WHERE provider_txn_id = ? AND status NOT IN ('completed', 'paid')", [$status, $txn]);
        else q('UPDATE billing_transactions SET status = ?, amount = ?, currency = ?, billed_at = ? WHERE provider_txn_id = ?', [$status, $row['amount'], $row['currency'], $row['billed_at'], $txn]);
        return;
    }
    insert('billing_transactions', $row + ['provider_txn_id' => $txn, 'created' => time()]);
}

/* --------------------------------------------------------------- webhooks */

/** Signature « Paddle-Signature: ts=…;h1=… » : HMAC-SHA256 de « ts:corps » avec le secret, horodatage récent. */
function paddle_signature_ok(string $raw, string $header, string $secret, int $tolerance = 300): bool
{
    if ($secret === '' || $header === '') return false;
    $ts = 0;
    $hashes = [];
    foreach (explode(';', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 'ts') $ts = (int) $v;
        if ($k === 'h1') $hashes[] = $v;
    }
    if (!$ts || !$hashes || abs(time() - $ts) > $tolerance) return false;
    $expected = hash_hmac('sha256', $ts . ':' . $raw, $secret);
    foreach ($hashes as $h) if (hash_equals($expected, $h)) return true;
    return false;
}
/** Traite une notification Paddle ; retourne [code HTTP, réponse]. Paddle réessaie tant que la réponse n'est pas 2xx. */
function paddle_webhook_handle(string $raw, string $signature): array
{
    if (!paddle_signature_ok($raw, $signature, (string) billing_cfg('PADDLE_WEBHOOK_SECRET'))) return [401, ['error' => 'invalid signature']];
    $e = json_decode($raw, true);
    $eventId = is_array($e) ? (string) ($e['event_id'] ?? '') : '';
    $type = is_array($e) ? (string) ($e['event_type'] ?? '') : '';
    if ($eventId === '' || $type === '' || !is_array($e['data'] ?? null)) return [400, ['error' => 'malformed event']];
    $prev = row("SELECT * FROM billing_events WHERE provider = 'paddle' AND event_id = ?", [$eventId]);
    if ($prev && in_array($prev['status'], ['processed', 'ignored', 'stale', 'unmatched'], true)) return [200, ['ok' => true, 'duplicate' => true]];
    if (!$prev) {
        insert('billing_events', ['provider' => 'paddle', 'event_id' => $eventId, 'type' => $type, 'occurred' => (string) ($e['occurred_at'] ?? ''),
            'status' => 'received', 'payload' => mb_substr($raw, 0, 20000), 'received' => time()]);
    }
    $status = 'ignored';
    $error = '';
    $uid = 0;
    try {
        tx(function () use ($e, $type, &$status, &$uid): void {
            $d = $e['data'];
            if (strpos($type, 'subscription.') === 0) {
                $status = paddle_sync_subscription($d) === 'stale' ? 'stale' : 'processed';
                $uid = (int) (val("SELECT uid FROM subscriptions WHERE provider = 'paddle' AND provider_sub_id = ?", [(string) $d['id']]) ?? 0);
            } elseif (in_array($type, ['transaction.completed', 'transaction.paid', 'transaction.payment_failed', 'transaction.past_due'], true)) {
                paddle_record_transaction($d, $type);
                $uid = paddle_owner($d);
                $status = 'processed';
            }
        });
    } catch (Throwable $ex) {
        tx_rollback();
        $error = mb_substr($ex->getMessage(), 0, 500);
        // Abonnement ou paiement sans compte connu : réessayer ne changerait rien (journalisé pour l'administration).
        $status = strpos($error, 'belongs to no Studioma account') !== false || strpos($error, 'not configured in Studioma') !== false ? 'unmatched' : 'failed';
        error_log('studioma paddle webhook ' . $eventId . ': ' . $error);
    }
    q("UPDATE billing_events SET status = ?, error = ?, uid = ? WHERE provider = 'paddle' AND event_id = ?", [$status, $error, $uid, $eventId]);
    if ($status === 'failed') return [500, ['error' => 'processing failed']];
    return [200, ['ok' => true, 'status' => $status]];
}

/* ------------------------------------------------------------- API (compte) */

function billing_need_available(): void
{
    if (!billing_available()) fail('Online payment isn\'t available yet. Paid plans will open soon — your Free plan keeps working.', 503, 'billing_unavailable');
}
function billing_plan_in(array $b): array
{
    $plan = (string) ($b['plan'] ?? '');
    $interval = (string) ($b['interval'] ?? 'month');
    if (!in_array($plan, ['solo', 'team'], true)) fail('Choose the Solo or the Team plan.');
    if (!in_array($interval, ['month', 'year'], true)) fail('Choose monthly or yearly billing.');
    return [$plan, $interval, billing_prices()[$plan][$interval]];
}
/** Abonnement Paddle en cours du compte (celui qu'on modifie), ou null. */
function billing_paddle_sub(int $uid): ?array
{
    return row("SELECT * FROM subscriptions WHERE uid = ? AND provider = 'paddle' AND env = ? AND status IN ('trialing', 'active', 'past_due', 'paused') ORDER BY id DESC LIMIT 1", [$uid, billing_env()]);
}
function billing_paddle_call(callable $fn)
{
    try {
        return $fn();
    } catch (PaddleError $e) {
        error_log('studioma paddle: ' . $e->paddleCode . ' ' . $e->getMessage());
        fail('The payment service answered: ' . $e->getMessage(), 502, 'billing');
    }
    return null;
}
/**
 * POST billing_checkout {plan, interval} : transaction Paddle liée au compte connecté ; retourne l'adresse de la page
 * de paiement (studioma/pay/?_ptxn=…). La formule ne change qu'à la confirmation de Paddle (webhook).
 */
function api_billing_checkout(array $b, array $u): array
{
    billing_need_available();
    [$plan, $interval, $price] = billing_plan_in($b);
    $uid = (int) $u['id'];
    rate('billing_checkout', 20, 3600, 'u' . $uid);
    if (billing_paddle_sub($uid)) fail('You already have a subscription. Change your plan from Settings › Plan & billing instead.', 409, 'has_subscription');
    $txn = billing_paddle_call(function () use ($u, $uid, $price, $plan, $interval): array {
        return paddle_api('POST', '/transactions', [
            'items' => [['price_id' => $price, 'quantity' => 1]],
            'customer_id' => paddle_customer($u),
            'collection_mode' => 'automatic',
            'custom_data' => ['stm_uid' => (string) $uid, 'stm_plan' => $plan, 'stm_interval' => $interval],
            'checkout' => ['url' => site_url() . 'pay/'],
        ]);
    });
    $id = (string) ($txn['id'] ?? '');
    if (!preg_match('/^txn_[A-Za-z0-9]+$/', $id)) fail('The payment service didn\'t return a checkout. Please try again.', 502, 'billing');
    return ['transaction_id' => $id, 'checkout_url' => site_url() . 'pay/?_ptxn=' . $id];
}
/**
 * POST billing_change {plan, interval} : changement de formule ou de rythme d'un abonnement en cours, avec le
 * prorata calculé par Paddle (immédiat pour une montée, sur la prochaine facture pour une descente).
 */
function api_billing_change(array $b, array $u): array
{
    billing_need_available();
    [$plan, $interval, $price] = billing_plan_in($b);
    $uid = (int) $u['id'];
    $sub = billing_paddle_sub($uid);
    if (!$sub) fail('You don\'t have a paid subscription yet. Choose a plan to subscribe.', 409, 'no_subscription');
    if ($sub['plan'] === $plan && $sub['interval'] === $interval) fail('This is already your plan.');
    $up = plan_def($plan)['rank'] > plan_def($sub['plan'])['rank'] || ($plan === $sub['plan'] && $interval === 'year');
    $d = billing_paddle_call(function () use ($sub, $price, $up): array {
        return paddle_api('PATCH', '/subscriptions/' . rawurlencode((string) $sub['provider_sub_id']), [
            'items' => [['price_id' => $price, 'quantity' => 1]],
            'proration_billing_mode' => $up ? 'prorated_immediately' : 'prorated_next_billing_period',
        ]);
    });
    tx(function () use ($d): void { paddle_sync_subscription($d); });
    return api_billing([], $u);
}
/** POST billing_cancel : fin de l'abonnement à la fin de la période payée (formule Free ensuite ; rien n'est supprimé). */
function api_billing_cancel(array $b, array $u): array
{
    billing_need_available();
    $sub = billing_paddle_sub((int) $u['id']);
    if (!$sub) fail('You don\'t have a paid subscription.', 409, 'no_subscription');
    $d = billing_paddle_call(function () use ($sub): array {
        return paddle_api('POST', '/subscriptions/' . rawurlencode((string) $sub['provider_sub_id']) . '/cancel', ['effective_from' => 'next_billing_period']);
    });
    tx(function () use ($d): void { paddle_sync_subscription($d); });
    return api_billing([], $u);
}
/** POST billing_resume : annule la résiliation programmée (l'abonnement continue). */
function api_billing_resume(array $b, array $u): array
{
    billing_need_available();
    $sub = billing_paddle_sub((int) $u['id']);
    if (!$sub || !(int) $sub['cancel_at']) fail('Your subscription isn\'t scheduled to end.', 409, 'no_cancel');
    $d = billing_paddle_call(function () use ($sub): array {
        return paddle_api('PATCH', '/subscriptions/' . rawurlencode((string) $sub['provider_sub_id']), ['scheduled_change' => null]);
    });
    tx(function () use ($d): void { paddle_sync_subscription($d); });
    return api_billing([], $u);
}
/** POST billing_portal : lien vers l'espace client Paddle (moyen de paiement, factures). */
function api_billing_portal(array $b, array $u): array
{
    billing_need_available();
    $cid = (string) (val('SELECT customer_id FROM billing_customers WHERE uid = ? AND env = ?', [$u['id'], billing_env()]) ?? '');
    if ($cid === '') fail('There is no billing account yet: subscribe to a plan first.', 409, 'no_customer');
    $sub = billing_paddle_sub((int) $u['id']);
    $d = billing_paddle_call(function () use ($cid, $sub): array {
        return paddle_api('POST', '/customers/' . rawurlencode($cid) . '/portal-sessions', $sub ? ['subscription_ids' => [(string) $sub['provider_sub_id']]] : []);
    });
    $url = (string) ($d['urls']['general']['overview'] ?? '');
    if (!preg_match('~^https?://~', $url)) fail('The payment service didn\'t return a portal link.', 502, 'billing');
    return ['url' => $url];
}
