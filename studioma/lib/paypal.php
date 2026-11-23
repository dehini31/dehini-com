<?php
/**
 * Paiement des abonnements avec PayPal (API REST « Subscriptions » v1, sans SDK : pas de gestionnaire de paquets ici).
 *
 * - Souscription : le serveur crée l'abonnement PayPal (formule P-… de config.php, custom_id « stm:<compte> ») et
 *   renvoie le lien d'accord de PayPal ; le membre paie sur paypal.com (compte PayPal ou carte), puis revient sur
 *   studioma/pay/. Aucun script de PayPal n'est chargé sur Studioma, aucune donnée de carte ne passe par nous.
 * - La formule n'est JAMAIS activée par le retour du navigateur : seulement par l'état lu chez PayPal de serveur à
 *   serveur — réponse de l'API (GET de l'abonnement) ou webhook dont la signature est vérifiée par PayPal.
 * - Pas de prorata chez PayPal : une montée (formule supérieure) s'applique dès que PayPal la confirme, le nouveau
 *   prix au renouvellement suivant ; une descente garde la formule payée jusqu'à la fin de la période (prev_plan).
 * - Résiliation : définitive chez PayPal ; la formule reste jusqu'à la fin de la période payée (cancel_at).
 * - Webhooks : chaque événement est vérifié par l'API de PayPal (verify-webhook-signature), traité une seule fois
 *   (id de l'événement) ; l'abonnement est toujours relu chez PayPal (l'ordre d'arrivée n'a donc pas d'importance).
 *
 * Réglages : voir lib/billing.php (STM_PAYPAL_*). STM_PAYPAL_API_BASE ne sert qu'aux tests (faux PayPal).
 */
declare(strict_types=1);

class PayPalError extends RuntimeException
{
    /** @var string */
    public $paypalCode;
    public function __construct(string $message, string $code = '', int $http = 0)
    {
        parent::__construct($message, $http);
        $this->paypalCode = $code;
    }
}

function paypal_base(): string
{
    $override = (string) billing_cfg('PAYPAL_API_BASE');
    if ($override !== '') return rtrim($override, '/');
    return billing_env() === 'production' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}
/** Requête HTTP vers PayPal ; retourne [code HTTP, corps brut]. */
function paypal_http(string $method, string $url, array $headers, ?string $body, ?string $userpwd = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($userpwd !== null) curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new PayPalError('PayPal could not be reached (' . $err . ').', 'network');
    return [$http, (string) $raw];
}
/** Jeton d'accès OAuth (identifiant client + secret), gardé en mémoire le temps de la requête seulement. */
function paypal_token(): string
{
    static $cache = [];
    $id = (string) billing_cfg('PAYPAL_CLIENT_ID');
    $secret = (string) billing_cfg('PAYPAL_SECRET');
    if ($id === '' || $secret === '') throw new PayPalError('PayPal is not configured.', 'not_configured');
    $k = paypal_base() . '|' . $id;
    if (isset($cache[$k]) && $cache[$k][1] > time() + 30) return $cache[$k][0];
    [$http, $raw] = paypal_http('POST', paypal_base() . '/v1/oauth2/token', ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], 'grant_type=client_credentials', $id . ':' . $secret);
    $d = json_decode($raw, true);
    $token = is_array($d) ? (string) ($d['access_token'] ?? '') : '';
    if ($http >= 400 || $token === '') {
        // Jamais la réponse brute dans le message (elle pourrait contenir des identifiants).
        throw new PayPalError($http === 401 ? 'PayPal refused the app credentials (check STM_PAYPAL_CLIENT_ID, STM_PAYPAL_SECRET and STM_PAYPAL_ENV).' : 'PayPal sign-in failed (' . $http . ').', 'auth', $http);
    }
    $cache[$k] = [$token, time() + max(60, (int) ($d['expires_in'] ?? 300))];
    return $token;
}
/**
 * Appel à l'API de PayPal ; retourne le corps décodé ([] pour une réponse vide, 204). $raw remplace le corps JSON
 * (webhook vérifié tel que reçu) ; $requestId rend une création idempotente (PayPal-Request-Id).
 */
function paypal_api(string $method, string $path, ?array $body = null, ?string $raw = null, string $requestId = ''): array
{
    $headers = ['Authorization: Bearer ' . paypal_token(), 'Accept: application/json'];
    if ($body !== null || $raw !== null) $headers[] = 'Content-Type: application/json';
    if ($requestId !== '') $headers[] = 'PayPal-Request-Id: ' . $requestId;
    [$http, $res] = paypal_http($method, paypal_base() . $path, $headers, $raw ?? ($body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES) : null));
    $d = $res === '' ? [] : json_decode($res, true);
    if ($http >= 400 || !is_array($d)) {
        $e = is_array($d) ? $d : [];
        $detail = (string) ($e['details'][0]['description'] ?? ($e['message'] ?? ('PayPal answered ' . $http . '.')));
        throw new PayPalError($detail, (string) ($e['details'][0]['issue'] ?? ($e['name'] ?? 'http_' . $http)), $http);
    }
    return $d;
}
function paypal_ts($iso): int
{
    return is_string($iso) && $iso !== '' ? (int) strtotime($iso) : 0;
}
/** Montant PayPal (« 12.00 ») en centimes. */
function paypal_cents($value): int
{
    return (int) round((float) $value * 100);
}
/** Lien « rel » d'une réponse PayPal (approve, self…). */
function paypal_link(array $d, string $rel): string
{
    foreach ((array) ($d['links'] ?? []) as $l) if (is_array($l) && ($l['rel'] ?? '') === $rel) return (string) ($l['href'] ?? '');
    return '';
}
/** Le lien d'accord renvoyé par PayPal mène bien chez PayPal (ou au faux PayPal des tests). */
function paypal_approve_ok(string $url): bool
{
    $override = (string) billing_cfg('PAYPAL_API_BASE');
    if ($override !== '') {
        $o = parse_url($override);
        return isset($o['scheme'], $o['host']) && str_starts_with($url, $o['scheme'] . '://' . $o['host'] . (isset($o['port']) ? ':' . $o['port'] : '') . '/');
    }
    return (bool) preg_match('~^https://www\.(sandbox\.)?paypal\.com/~', $url);
}
function paypal_sub_id_ok(string $id): bool
{
    return (bool) preg_match('/^I-[A-Z0-9]{6,40}$/', $id);
}
function paypal_get_subscription(string $id): array
{
    if (!paypal_sub_id_ok($id)) throw new RuntimeException('Subscription without a valid id.');
    return paypal_api('GET', '/v1/billing/subscriptions/' . rawurlencode($id));
}

/* ------------------------------------------------ synchronisation d'un abonnement */

/** Compte Studioma d'un abonnement : celui qui l'a créé chez nous, sinon custom_id « stm:<id> ». */
function paypal_owner(array $d): int
{
    $row = row("SELECT uid FROM subscriptions WHERE provider = 'paypal' AND provider_sub_id = ?", [(string) ($d['id'] ?? '')]);
    if ($row) return (int) $row['uid'];
    if (preg_match('/^stm:(\d{1,12})$/', (string) ($d['custom_id'] ?? ''), $m) && user_by_id((int) $m[1])) return (int) $m[1];
    return 0;
}
/** État Studioma d'un abonnement PayPal. */
function paypal_status(array $d): string
{
    switch ((string) ($d['status'] ?? '')) {
        case 'ACTIVE':
            // Paiement en échec que PayPal réessaie : délai de grâce (STM_PAST_DUE_GRACE_DAYS).
            return (int) ($d['billing_info']['failed_payments_count'] ?? 0) > 0 ? 'past_due' : 'active';
        case 'SUSPENDED':
            return 'paused';
        case 'CANCELLED':
        case 'EXPIRED':
            return 'canceled';
        default:
            return 'pending';   // APPROVAL_PENDING, APPROVED : pas encore payé, aucun accès
    }
}
/**
 * Enregistre l'état d'un abonnement PayPal (données vérifiées : réponse de l'API ou webhook vérifié). Retourne
 * « applied » ou « stale » (plus ancien que l'état connu) ; lève une exception si l'abonnement est inexploitable.
 * $expectUid : le compte qui le demande (retour du paiement) — un autre compte est refusé.
 */
function paypal_sync_subscription(array $d, int $expectUid = 0): string
{
    $subId = (string) ($d['id'] ?? '');
    if (!paypal_sub_id_ok($subId)) throw new RuntimeException('Subscription without a valid id.');
    $existing = row("SELECT * FROM subscriptions WHERE provider = 'paypal' AND provider_sub_id = ?", [$subId]);
    $updated = (string) ($d['status_update_time'] ?? ($d['update_time'] ?? ''));
    if (isset($d['update_time']) && strcmp((string) $d['update_time'], $updated) > 0) $updated = (string) $d['update_time'];
    if ($existing && $existing['provider_updated'] !== '' && $updated !== '' && strcmp($updated, $existing['provider_updated']) < 0) return 'stale';
    $uid = paypal_owner($d);
    if (!$uid) throw new RuntimeException('Subscription ' . $subId . ' belongs to no Studioma account (unknown custom_id).');
    if ($expectUid && $uid !== $expectUid) throw new RuntimeException('Subscription ' . $subId . ' belongs to another account.');
    $pp = billing_price_plan((string) ($d['plan_id'] ?? ''));
    if (!$pp) throw new RuntimeException('Subscription ' . $subId . ' uses a plan that is not configured in Studioma.');
    [$plan, $interval] = $pp;
    $status = paypal_status($d);
    $now = time();
    $bi = is_array($d['billing_info'] ?? null) ? $d['billing_info'] : [];
    $periodEnd = paypal_ts($bi['next_billing_time'] ?? null);
    if (!$periodEnd && $existing) $periodEnd = (int) $existing['period_end'];   // résilié : PayPal ne donne plus d'échéance
    $periodStart = paypal_ts($bi['last_payment']['time'] ?? null) ?: paypal_ts($d['start_time'] ?? null);
    if (!$periodStart && $existing) $periodStart = (int) $existing['period_start'];
    $pastDue = $status === 'past_due' ? ($existing && $existing['status'] === 'past_due' && (int) $existing['past_due_since'] ? (int) $existing['past_due_since'] : $now) : 0;
    // Descente sans prorata : la formule supérieure déjà payée reste jusqu'à la fin de la période en cours.
    $prevPlan = $existing ? (string) ($existing['prev_plan'] ?? '') : '';
    $prevUntil = $existing ? (int) ($existing['prev_until'] ?? 0) : 0;
    if ($existing && $existing['plan'] !== $plan && in_array($existing['status'], ['active', 'past_due'], true)) {
        $oldRank = plan_def((string) $existing['plan'])['rank'];
        $newRank = plan_def($plan)['rank'];
        if ($newRank < $oldRank) {
            if (!($prevUntil > $now && plan_def($prevPlan)['rank'] >= $oldRank)) { $prevPlan = (string) $existing['plan']; $prevUntil = (int) $existing['period_end']; }
        } elseif ($newRank > $oldRank) {
            $prevPlan = '';
            $prevUntil = 0;
        }
    }
    $data = [
        'uid' => $uid, 'provider' => 'paypal', 'env' => billing_env(), 'provider_sub_id' => $subId,
        'provider_customer_id' => (string) ($d['subscriber']['payer_id'] ?? ($existing['provider_customer_id'] ?? '')),
        'plan' => $plan, 'interval' => $interval, 'status' => $status,
        // Montant : prix Studioma de la formule (les formules PayPal sont créées à partir de lib/plans.php).
        'amount' => (int) plan_def($plan)['price'][$interval], 'currency' => 'USD',
        'period_start' => $periodStart, 'period_end' => $periodEnd,
        'cancel_at' => $status === 'canceled' ? $periodEnd : 0,
        'canceled_at' => $status === 'canceled' ? ($existing && (int) $existing['canceled_at'] ? (int) $existing['canceled_at'] : (paypal_ts($d['status_update_time'] ?? null) ?: $now)) : 0,
        'past_due_since' => $pastDue, 'prev_plan' => $prevPlan, 'prev_until' => $prevUntil, 'provider_updated' => $updated, 'updated' => $now,
    ];
    if ($existing) {
        q('UPDATE subscriptions SET ' . implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($data))) . ' WHERE id = ?', array_merge(array_values($data), [$existing['id']]));
    } else {
        // Un deuxième abonnement payant actif pour le même compte est signalé (jamais facturé deux fois par nous).
        $dup = val("SELECT provider_sub_id FROM subscriptions WHERE uid = ? AND provider = 'paypal' AND env = ? AND status IN ('active', 'past_due') AND provider_sub_id <> ?", [$uid, billing_env(), $subId]);
        insert('subscriptions', $data + ['created' => $now, 'note' => $dup && in_array($status, ['active', 'past_due'], true) ? 'Duplicate of ' . $dup . ' — check in PayPal' : '']);
    }
    billing_cache_clear();
    return 'applied';
}
/** Relit un abonnement chez PayPal et l'enregistre. */
function paypal_refresh(string $subId, int $expectUid = 0): string
{
    $d = paypal_get_subscription($subId);
    $r = 'applied';
    tx(function () use ($d, $expectUid, &$r): void { $r = paypal_sync_subscription($d, $expectUid); });
    return $r;
}

/* ----------------------------------------------------------------- paiements */

/** Compte d'un paiement : par l'abonnement (billing_agreement_id), sinon par custom. */
function paypal_payment_owner(array $res): int
{
    $sub = (string) ($res['billing_agreement_id'] ?? '');
    if ($sub !== '') {
        $uid = (int) (val("SELECT uid FROM subscriptions WHERE provider = 'paypal' AND provider_sub_id = ?", [$sub]) ?? 0);
        if ($uid) return $uid;
    }
    if (preg_match('/^stm:(\d{1,12})$/', (string) ($res['custom'] ?? ''), $m) && user_by_id((int) $m[1])) return (int) $m[1];
    return 0;
}
/** Enregistre un paiement réussi, remboursé ou annulé (événements PAYMENT.SALE.*). */
function paypal_record_sale(array $res, string $type): int
{
    $isRefund = $type === 'PAYMENT.SALE.REFUNDED';
    $saleId = (string) ($isRefund ? ($res['sale_id'] ?? '') : ($res['id'] ?? ''));
    if (!preg_match('/^[A-Z0-9]{6,40}$/', $saleId)) throw new RuntimeException('Payment without a valid id.');
    $status = $type === 'PAYMENT.SALE.COMPLETED' ? 'completed' : ($isRefund ? 'refunded' : ($type === 'PAYMENT.SALE.REVERSED' ? 'reversed' : 'denied'));
    $known = row('SELECT * FROM billing_transactions WHERE provider_txn_id = ?', [$saleId]);
    if ($known) {
        // Un remboursement ou une annulation n'est jamais remplacé par un « payé » arrivé en retard.
        if ($status === 'completed' && $known['status'] !== 'completed') return (int) $known['uid'];
        q('UPDATE billing_transactions SET status = ? WHERE provider_txn_id = ?', [$status, $saleId]);
        return (int) $known['uid'];
    }
    $uid = paypal_payment_owner($res);
    if (!$uid) throw new RuntimeException('Payment ' . $saleId . ' belongs to no Studioma account (unknown subscription).');
    $amount = $res['amount']['total'] ?? ($res['amount']['value'] ?? 0);
    insert('billing_transactions', [
        'uid' => $uid, 'env' => billing_env(), 'provider_txn_id' => $saleId, 'provider_sub_id' => (string) ($res['billing_agreement_id'] ?? ''),
        'status' => $status, 'amount' => paypal_cents($amount), 'currency' => (string) ($res['amount']['currency'] ?? ($res['amount']['currency_code'] ?? 'USD')),
        'origin' => 'subscription', 'billed_at' => paypal_ts($res['create_time'] ?? null) ?: time(), 'created' => time(),
    ]);
    return $uid;
}
/** Échec d'un prélèvement (BILLING.SUBSCRIPTION.PAYMENT.FAILED) : une ligne par événement. */
function paypal_record_failure(array $sub, string $eventId, int $uid): void
{
    $f = $sub['billing_info']['last_failed_payment'] ?? [];
    q('INSERT OR IGNORE INTO billing_transactions (uid, env, provider_txn_id, provider_sub_id, status, amount, currency, origin, billed_at, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $uid, billing_env(), 'failed:' . $eventId, (string) ($sub['id'] ?? ''), 'payment_failed', paypal_cents($f['amount']['value'] ?? 0),
        (string) ($f['amount']['currency_code'] ?? 'USD'), 'subscription', paypal_ts($f['time'] ?? null) ?: time(), time(),
    ]);
}

/* --------------------------------------------------------------- webhooks */

/**
 * Signature d'une notification, vérifiée par PayPal (POST /v1/notifications/verify-webhook-signature) avec
 * STM_PAYPAL_WEBHOOK_ID. Le corps reçu est renvoyé tel quel (pas de ré-encodage qui changerait la signature).
 * Retourne true / false ; lève PayPalError si PayPal ne répond pas (la notification sera renvoyée).
 */
function paypal_webhook_verified(string $raw, array $h): bool
{
    $need = ['auth_algo' => 'PAYPAL-AUTH-ALGO', 'cert_url' => 'PAYPAL-CERT-URL', 'transmission_id' => 'PAYPAL-TRANSMISSION-ID',
        'transmission_sig' => 'PAYPAL-TRANSMISSION-SIG', 'transmission_time' => 'PAYPAL-TRANSMISSION-TIME'];
    $fields = [];
    foreach ($need as $k => $name) {
        $v = trim((string) ($h[$name] ?? ''));
        if ($v === '' || strlen($v) > 2000) return false;
        $fields[$k] = $v;
    }
    $hook = (string) billing_cfg('PAYPAL_WEBHOOK_ID');
    if ($hook === '' || !is_array(json_decode($raw, true))) return false;
    // Le certificat de signature est toujours hébergé par PayPal (sinon : refusé sans même interroger PayPal).
    $cert = parse_url($fields['cert_url']);
    $override = (string) billing_cfg('PAYPAL_API_BASE');
    if ($override === '' && (($cert['scheme'] ?? '') !== 'https' || !preg_match('/(^|\.)paypal\.com$/', (string) ($cert['host'] ?? '')))) return false;
    $fields['webhook_id'] = $hook;
    $body = rtrim(json_encode($fields, JSON_UNESCAPED_SLASHES), '}') . ',"webhook_event":' . $raw . '}';
    $r = paypal_api('POST', '/v1/notifications/verify-webhook-signature', null, $body);
    return ($r['verification_status'] ?? '') === 'SUCCESS';
}
/** Traite une notification PayPal ; retourne [code HTTP, réponse]. PayPal réessaie tant que la réponse n'est pas 2xx. */
function paypal_webhook_handle(string $raw, array $headers): array
{
    try {
        if (!paypal_webhook_verified($raw, $headers)) return [401, ['error' => 'invalid signature']];
    } catch (PayPalError $e) {
        error_log('studioma paypal webhook verification: ' . $e->paypalCode . ' ' . $e->getMessage());
        return [503, ['error' => 'verification unavailable']];
    }
    $e = json_decode($raw, true);
    $eventId = (string) ($e['id'] ?? '');
    $type = (string) ($e['event_type'] ?? '');
    $res = $e['resource'] ?? null;
    if ($eventId === '' || $type === '' || !is_array($res)) return [400, ['error' => 'malformed event']];
    $prev = row("SELECT * FROM billing_events WHERE provider = 'paypal' AND event_id = ?", [$eventId]);
    if ($prev && in_array($prev['status'], ['processed', 'ignored', 'stale', 'unmatched'], true)) return [200, ['ok' => true, 'duplicate' => true]];
    if (!$prev) {
        insert('billing_events', ['provider' => 'paypal', 'event_id' => $eventId, 'type' => $type, 'occurred' => (string) ($e['create_time'] ?? ''),
            'status' => 'received', 'payload' => mb_substr($raw, 0, 20000), 'received' => time()]);
    }
    $status = 'ignored';
    $error = '';
    $uid = 0;
    try {
        if (strpos($type, 'BILLING.SUBSCRIPTION.') === 0) {
            // L'abonnement est relu chez PayPal : l'état reçu peut être ancien, l'état lu est toujours le dernier.
            $sub = paypal_get_subscription((string) ($res['id'] ?? ''));
            tx(function () use ($sub, $type, $eventId, &$status, &$uid): void {
                $status = paypal_sync_subscription($sub) === 'stale' ? 'stale' : 'processed';
                $uid = paypal_owner($sub);
                if ($type === 'BILLING.SUBSCRIPTION.PAYMENT.FAILED') paypal_record_failure($sub, $eventId, $uid);
            });
        } elseif (in_array($type, ['PAYMENT.SALE.COMPLETED', 'PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.REVERSED', 'PAYMENT.SALE.DENIED'], true)) {
            $sub = (string) ($res['billing_agreement_id'] ?? '');
            $fresh = $type === 'PAYMENT.SALE.COMPLETED' && paypal_sub_id_ok($sub) ? paypal_get_subscription($sub) : null;
            tx(function () use ($res, $type, $fresh, &$status, &$uid): void {
                // Paiement d'un abonnement inconnu de Studioma : l'abonnement est d'abord enregistré s'il est à nous.
                if ($fresh) paypal_sync_subscription($fresh);
                $uid = paypal_record_sale($res, $type);
                $status = 'processed';
            });
        }
    } catch (PayPalError $ex) {
        tx_rollback();
        $error = mb_substr($ex->getMessage(), 0, 500);
        $status = $ex->paypalCode === 'RESOURCE_NOT_FOUND' || $ex->paypalCode === 'INVALID_RESOURCE_ID' ? 'unmatched' : 'failed';
        error_log('studioma paypal webhook ' . $eventId . ': ' . $ex->paypalCode . ' ' . $error);
    } catch (Throwable $ex) {
        tx_rollback();
        $error = mb_substr($ex->getMessage(), 0, 500);
        // Abonnement ou paiement sans compte connu : réessayer ne changerait rien (journalisé pour l'administration).
        $status = strpos($error, 'belongs to no Studioma account') !== false || strpos($error, 'not configured in Studioma') !== false ? 'unmatched' : 'failed';
        error_log('studioma paypal webhook ' . $eventId . ': ' . $error);
    }
    q("UPDATE billing_events SET status = ?, error = ?, uid = ? WHERE provider = 'paypal' AND event_id = ?", [$status, $error, $uid, $eventId]);
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
/** Abonnement PayPal en cours du compte (celui qu'on modifie ou résilie), ou null. */
function billing_active_sub(int $uid): ?array
{
    return row("SELECT * FROM subscriptions WHERE uid = ? AND provider = 'paypal' AND env = ? AND status IN ('active', 'past_due', 'paused') ORDER BY id DESC LIMIT 1", [$uid, billing_env()]);
}
function billing_paypal_call(callable $fn)
{
    try {
        return $fn();
    } catch (ApiFail $e) {
        throw $e;
    } catch (PayPalError $e) {
        error_log('studioma paypal: ' . $e->paypalCode . ' ' . $e->getMessage());
        fail('PayPal answered: ' . $e->getMessage(), 502, 'billing');
    } catch (RuntimeException $e) {
        // Abonnement d'un autre compte, formule inconnue… : rien n'est enregistré.
        error_log('studioma paypal: ' . $e->getMessage());
        fail('This PayPal subscription can\'t be used with your account. If you were charged, contact us and we will sort it out.', 409, 'billing_mismatch');
    }
    return null;
}
/** Adresses de retour depuis PayPal (page studioma/pay/, qui renvoie vers l'application). */
function paypal_context(): array
{
    return ['brand_name' => 'Studioma', 'locale' => 'en-US', 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'SUBSCRIBE_NOW',
        'return_url' => site_url() . 'pay/?paypal=return', 'cancel_url' => site_url() . 'pay/?paypal=cancel'];
}
/**
 * POST billing_checkout {plan, interval} : abonnement PayPal créé par le serveur pour le compte connecté ; retourne
 * le lien d'accord de PayPal. La formule ne change qu'une fois l'abonnement actif chez PayPal (billing_confirm, webhook).
 */
function api_billing_checkout(array $b, array $u): array
{
    billing_need_available();
    [$plan, $interval, $planId] = billing_plan_in($b);
    $uid = (int) $u['id'];
    rate('billing_checkout', 20, 3600, 'u' . $uid);
    if (billing_active_sub($uid)) fail('You already have a subscription. Change your plan from Settings › Plan & billing instead.', 409, 'has_subscription');
    // Abonnement résilié dont la période payée court encore : pas de second paiement pour la même période.
    $paidUntil = (int) (val("SELECT MAX(cancel_at) FROM subscriptions WHERE uid = ? AND provider = 'paypal' AND env = ? AND status = 'canceled' AND cancel_at > ?", [$uid, billing_env(), time()]) ?? 0);
    if ($paidUntil) fail('Your current plan is paid until ' . gmdate('j F Y', $paidUntil) . '. You can subscribe again from that day.', 409, 'paid_until');
    $d = billing_paypal_call(function () use ($u, $uid, $planId): array {
        return paypal_api('POST', '/v1/billing/subscriptions', [
            'plan_id' => $planId,
            'custom_id' => 'stm:' . $uid,
            'subscriber' => ['email_address' => (string) $u['email']],
            'application_context' => paypal_context(),
        ], null, 'stm-sub-' . $uid . '-' . bin2hex(random_bytes(8)));
    });
    $id = (string) ($d['id'] ?? '');
    $approve = paypal_link($d, 'approve');
    if (!paypal_sub_id_ok($id) || !paypal_approve_ok($approve)) fail('PayPal didn\'t return a checkout. Please try again.', 502, 'billing');
    $now = time();
    // Abonnement « en attente » : relie l'abonnement PayPal au compte, sans donner aucun accès.
    q("INSERT OR IGNORE INTO subscriptions (uid, provider, env, provider_sub_id, plan, interval, status, amount, currency, created, updated) VALUES (?, 'paypal', ?, ?, ?, ?, 'pending', ?, 'USD', ?, ?)",
        [$uid, billing_env(), $id, $plan, $interval, (int) plan_def($plan)['price'][$interval], $now, $now]);
    return ['subscription_id' => $id, 'checkout_url' => $approve];
}
/**
 * POST billing_confirm {subscription_id?} : au retour de PayPal, relit chez PayPal l'abonnement du compte (le dernier
 * créé, ou celui indiqué) et l'enregistre. C'est la réponse de PayPal, pas le retour du navigateur, qui active.
 */
function api_billing_confirm(array $b, array $u): array
{
    billing_need_available();
    $uid = (int) $u['id'];
    rate('billing_confirm', 60, 3600, 'u' . $uid);
    $sid = (string) ($b['subscription_id'] ?? '');
    if ($sid !== '' && !paypal_sub_id_ok($sid)) fail('Unknown subscription.');
    $row = $sid !== ''
        ? row("SELECT * FROM subscriptions WHERE provider = 'paypal' AND provider_sub_id = ? AND uid = ?", [$sid, $uid])
        : row("SELECT * FROM subscriptions WHERE uid = ? AND provider = 'paypal' AND env = ? AND status IN ('pending', 'active', 'past_due', 'paused') ORDER BY updated DESC, id DESC LIMIT 1", [$uid, billing_env()]);
    if (!$row) fail('There is no PayPal subscription to confirm.', 404, 'no_subscription');
    billing_paypal_call(function () use ($row, $uid): void { paypal_refresh((string) $row['provider_sub_id'], $uid); });
    return api_billing([], $u);
}
/**
 * POST billing_change {plan, interval} : changement de formule ou de rythme (« revise » chez PayPal). PayPal demande
 * souvent l'accord du payeur : on renvoie alors son lien. Le nouveau prix s'applique au renouvellement suivant ;
 * une montée vaut dès que PayPal la confirme, une descente à la fin de la période payée (choix du propriétaire).
 */
function api_billing_change(array $b, array $u): array
{
    billing_need_available();
    [$plan, $interval, $planId] = billing_plan_in($b);
    $uid = (int) $u['id'];
    rate('billing_change', 20, 3600, 'u' . $uid);
    $sub = billing_active_sub($uid);
    if (!$sub) fail('You don\'t have a paid subscription yet. Choose a plan to subscribe.', 409, 'no_subscription');
    if ($sub['plan'] === $plan && $sub['interval'] === $interval) fail('This is already your plan.');
    $d = billing_paypal_call(function () use ($sub, $planId): array {
        $ctx = paypal_context();
        unset($ctx['user_action']);
        return paypal_api('POST', '/v1/billing/subscriptions/' . rawurlencode((string) $sub['provider_sub_id']) . '/revise', ['plan_id' => $planId, 'application_context' => $ctx]);
    });
    $approve = paypal_link($d, 'approve');
    if ($approve !== '') {
        if (!paypal_approve_ok($approve)) fail('PayPal didn\'t return a valid approval link. Please try again.', 502, 'billing');
        return ['approval' => true, 'checkout_url' => $approve];
    }
    // Pas d'accord nécessaire (carte enregistrée chez PayPal) : l'état est relu tout de suite.
    billing_paypal_call(function () use ($sub, $uid): void { paypal_refresh((string) $sub['provider_sub_id'], $uid); });
    return ['approval' => false] + api_billing([], $u);
}
/** POST billing_cancel : résiliation chez PayPal (définitive) ; la formule reste jusqu'à la fin de la période payée. */
function api_billing_cancel(array $b, array $u): array
{
    billing_need_available();
    $uid = (int) $u['id'];
    $sub = billing_active_sub($uid);
    if (!$sub) fail('You don\'t have a paid subscription.', 409, 'no_subscription');
    billing_paypal_call(function () use ($sub, $uid): void {
        paypal_api('POST', '/v1/billing/subscriptions/' . rawurlencode((string) $sub['provider_sub_id']) . '/cancel', ['reason' => 'Canceled by the subscriber in Studioma settings.']);
        paypal_refresh((string) $sub['provider_sub_id'], $uid);
    });
    return api_billing([], $u);
}

/* ------------------------------------------------------ administration */

/**
 * POST admin_paypal_setup : crée chez PayPal (environnement configuré) le produit « Studioma » et ses quatre
 * formules aux prix de lib/plans.php, en dollars, sans taxe ajoutée (choix du propriétaire), renouvelées sans fin,
 * suspendues après 3 échecs de paiement. Rien n'est enregistré ici : l'administration recopie les identifiants
 * P-… dans config.php. Refusé si les quatre formules sont déjà réglées (pas de doublon par erreur).
 */
function api_admin_paypal_setup(array $b, array $a): array
{
    if ((string) billing_cfg('PAYPAL_CLIENT_ID') === '' || (string) billing_cfg('PAYPAL_SECRET') === '') fail('Add STM_PAYPAL_CLIENT_ID and STM_PAYPAL_SECRET to studioma/config.php first.', 409, 'not_configured');
    $set = array_filter(array_merge(...array_map('array_values', array_values(billing_prices()))));
    if (count($set) === 4) fail('The four PayPal plans are already set in config.php.', 409, 'already_set');
    rate('admin_paypal_setup', 5, 3600, 'admin');
    $out = billing_paypal_call(function (): array {
        $product = paypal_api('POST', '/v1/catalogs/products', ['name' => 'Studioma', 'description' => 'Studioma subscription (content planning and client collaboration).',
            'type' => 'SERVICE', 'category' => 'SOFTWARE', 'home_url' => site_url()], null, 'stm-product-' . bin2hex(random_bytes(8)));
        $pid = (string) ($product['id'] ?? '');
        if ($pid === '') throw new PayPalError('PayPal did not return a product.', 'product');
        $lines = [];
        foreach (['solo', 'team'] as $plan) {
            foreach (['month', 'year'] as $interval) {
                $def = plan_def($plan);
                $price = number_format($def['price'][$interval] / 100, 2, '.', '');
                $p = paypal_api('POST', '/v1/billing/plans', [
                    'product_id' => $pid, 'name' => 'Studioma ' . $def['name'] . ' — ' . ($interval === 'month' ? 'monthly' : 'yearly'), 'status' => 'ACTIVE',
                    'description' => $def['name'] . ' plan, billed ' . ($interval === 'month' ? 'monthly' : 'yearly') . '.',
                    'billing_cycles' => [['frequency' => ['interval_unit' => strtoupper($interval), 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1,
                        'total_cycles' => 0, 'pricing_scheme' => ['fixed_price' => ['value' => $price, 'currency_code' => 'USD']]]],
                    'payment_preferences' => ['auto_bill_outstanding' => true, 'setup_fee_failure_action' => 'CANCEL', 'payment_failure_threshold' => 3],
                ], null, 'stm-plan-' . $plan . '-' . $interval . '-' . bin2hex(random_bytes(8)));
                $id = (string) ($p['id'] ?? '');
                if (!preg_match('/^P-[A-Z0-9]{8,40}$/', $id)) throw new PayPalError('PayPal did not return a plan ID.', 'plan');
                $lines[] = ['setting' => billing_plan_setting($plan, $interval), 'id' => $id, 'price' => '$' . $price . '/' . $interval];
            }
        }
        return ['product_id' => $pid, 'plans' => $lines];
    });
    admin_log('paypal_setup', billing_env(), 'product ' . $out['product_id']);
    return $out + ['env' => billing_env(), 'config' => implode("\n", array_map(function (array $l): string { return "define('" . $l['setting'] . "', '" . $l['id'] . "');"; }, $out['plans']))];
}
