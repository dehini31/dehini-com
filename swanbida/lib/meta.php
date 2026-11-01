<?php
/**
 * Meta Pixel + Conversions API (CAPI).
 *
 * Règles de suivi (modèle « paiement à la livraison ») :
 *  - PageView, ViewContent, AddToCart, InitiateCheckout, Contact : envoyés par le navigateur (pixel)
 *    ET par le serveur avec le même event_id → Meta n'en garde qu'un (déduplication).
 *  - Lead : la demande de commande. Envoyé une seule fois quand la commande est créée (navigateur +
 *    serveur, event_id « lead.<n° commande> »). Jamais à l'affichage de la page de remerciement.
 *  - Purchase : uniquement côté serveur, quand l'équipe CONFIRME la commande (statut « confirmée » ou
 *    plus loin), ou à la livraison si l'admin le choisit. Une seule fois par commande
 *    (event_id « purchase.<n° commande> »). Rafraîchir une page ne peut donc jamais le déclencher.
 *  - Livraison : événement personnalisé distinct (« OrderDelivered » par défaut) au statut « livrée ».
 */
declare(strict_types=1);

const BROWSER_EVENTS = ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Contact'];

function pixel_id(): string
{
    $id = defined('SWB_META_PIXEL_ID') && SWB_META_PIXEL_ID !== '' ? (string) SWB_META_PIXEL_ID : setting('pixel_id');
    return preg_match('/^\d{5,20}$/', $id) ? $id : '';
}

function capi_token(): string
{
    return secret_setting('capi_token', 'SWB_META_CAPI_TOKEN');
}

function capi_ready(): bool
{
    return pixel_id() !== '' && capi_token() !== '' && setting('capi_enabled') === '1';
}

function graph_version(): string
{
    $v = setting('graph_version');
    return preg_match('/^v\d{1,2}\.\d$/', $v) ? $v : 'v24.0';
}

function sha(string $v): string
{
    return hash('sha256', $v);
}

/** Normalisation Meta : minuscules, sans espaces ni ponctuation (villes, wilayas). */
function meta_norm_place(string $s): string
{
    $s = mb_strtolower(trim($s));
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('Latin-ASCII', $s);
        if (is_string($t)) $s = $t;
    }
    return preg_replace('/[^\p{L}\p{N}]/u', '', $s) ?? '';
}

function meta_norm_name(string $s): string
{
    $s = mb_strtolower(trim($s));
    return preg_replace('/[^\p{L}\p{N}\-]/u', '', $s) ?? '';
}

/** Données d'identification d'une commande (hachées SHA-256, sauf IP / navigateur / fbp / fbc). */
function capi_user_from_order(array $o): array
{
    $u = ['country' => [sha('dz')]];
    $ph = phone_e164($o['phone']);
    if ($ph !== '') $u['ph'] = [sha($ph)];
    $parts = preg_split('/\s+/u', trim($o['name'])) ?: [];
    if ($parts) {
        $fn = meta_norm_name((string) array_shift($parts));
        if ($fn !== '') $u['fn'] = [sha($fn)];
        $ln = meta_norm_name(implode('', $parts));
        if ($ln !== '') $u['ln'] = [sha($ln)];
    }
    $ct = meta_norm_place($o['commune']);
    if ($ct !== '') $u['ct'] = [sha($ct)];
    $st = meta_norm_place($o['wilaya_name']);
    if ($st !== '') $u['st'] = [sha($st)];
    $ext = [];
    if ($o['vid'] !== '') $ext[] = sha($o['vid']);
    if ($ph !== '') $ext[] = sha('ph:' . $ph);
    if ($ext) $u['external_id'] = $ext;
    if ($o['ip'] !== '') $u['client_ip_address'] = $o['ip'];
    if ($o['ua'] !== '') $u['client_user_agent'] = $o['ua'];
    if ($o['fbp'] !== '') $u['fbp'] = $o['fbp'];
    if ($o['fbc'] !== '') $u['fbc'] = $o['fbc'];
    return $u;
}

function capi_custom_from_order(array $o): array
{
    $contents = [];
    $ids = [];
    $names = [];
    foreach ($o['items'] as $it) {
        $contents[] = ['id' => (string) $it['sku'], 'quantity' => (int) $it['qty'], 'item_price' => (int) $it['unit']];
        $ids[] = (string) $it['sku'];
        $names[] = tr($it['name'] ?? [], 'fr');
    }
    $c = [
        'currency' => 'DZD', 'value' => (int) $o['total'], 'content_type' => 'product', 'content_ids' => $ids,
        'contents' => $contents, 'num_items' => (int) $o['qty'], 'order_id' => $o['ref'], 'content_name' => implode(', ', $names),
        'delivery_category' => 'home_delivery',
    ];
    return $c;
}

/** Ajoute un événement serveur lié à une commande dans la file d'envoi (une seule fois par nom + event_id). */
function capi_queue(array $o, string $name, string $eventId, ?int $eventTime = null): void
{
    $ready = capi_ready();
    $event = [
        'event_name' => $name,
        'event_time' => $eventTime ?? time(),
        'event_id' => $eventId,
        'action_source' => 'website',
        'event_source_url' => $o['source_url'] !== '' ? $o['source_url'] : abs_url(''),
        'user_data' => capi_user_from_order($o),
        'custom_data' => capi_custom_from_order($o),
    ];
    try {
        db()->prepare('INSERT INTO capi_events (oid, name, event_id, payload, status, error, created, next_try) VALUES (?, ?, ?, ?, ?, ?, ?, 0)')
            ->execute([$o['id'], $name, $eventId, json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $ready ? 'pending' : 'skipped', $ready ? '' : 'Pixel ou jeton Conversions API non configuré', time()]);
    } catch (PDOException $e) {
        return; // déjà en file : jamais deux fois le même événement
    }
    if ($ready) after_response(fn() => capi_process(5));
}

/** Envoie une liste d'événements à Meta. Retourne [ok, message, réponse brute]. */
function capi_send(array $events, ?string $testCode = null): array
{
    $pixel = pixel_id();
    $token = capi_token();
    if ($pixel === '' || $token === '') return [false, 'Pixel ou jeton non configuré', ''];
    $body = ['data' => $events];
    $test = $testCode ?? setting('test_code');
    if ($test !== '') $body['test_event_code'] = $test;
    $url = rtrim(SWB_GRAPH_URL, '/') . '/' . graph_version() . '/' . $pixel . '/events?access_token=' . rawurlencode($token);
    try {
        [$code, $res] = http_post_json($url, $body, 12);
    } catch (Throwable $e) {
        return [false, $e->getMessage(), ''];
    }
    $j = json_decode($res, true);
    if ($code >= 200 && $code < 300 && is_array($j) && isset($j['events_received'])) return [true, '', $res];
    $msg = is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : ('HTTP ' . $code);
    if (is_array($j) && isset($j['error']['error_user_msg'])) $msg .= ' — ' . $j['error']['error_user_msg'];
    return [false, $msg, mb_substr($res, 0, 2000)];
}

const CAPI_BACKOFF = [60, 300, 1800, 7200, 21600, 86400];

/** Envoie les événements en attente (après la réponse, et par le cron en secours). */
function capi_process(int $limit = 20): int
{
    if (!capi_ready()) return 0;
    $pdo = db();
    $now = time();
    $rows = $pdo->prepare("SELECT * FROM capi_events WHERE status = 'pending' AND next_try <= ? ORDER BY id LIMIT " . (int) $limit);
    $rows->execute([$now]);
    $sent = 0;
    foreach ($rows->fetchAll() as $r) {
        // Réserve la ligne pour éviter un double envoi par deux processus simultanés.
        $claim = $pdo->prepare("UPDATE capi_events SET next_try = ? WHERE id = ? AND status = 'pending' AND next_try = ?");
        $claim->execute([$now + 120, $r['id'], $r['next_try']]);
        if ($claim->rowCount() === 0) continue;
        $event = json_decode((string) $r['payload'], true) ?: [];
        if ((int) ($event['event_time'] ?? 0) < $now - 7 * 86400) {
            $pdo->prepare("UPDATE capi_events SET status = 'failed', error = ? WHERE id = ?")->execute(['Événement de plus de 7 jours : refusé par Meta', $r['id']]);
            continue;
        }
        $test = setting('test_code');
        [$ok, $err, $res] = capi_send([$event]);
        $att = (int) $r['attempts'] + 1;
        if ($ok) {
            $pdo->prepare("UPDATE capi_events SET status = 'sent', attempts = ?, sent = ?, error = '', response = ?, test = ? WHERE id = ?")
                ->execute([$att, time(), mb_substr($res, 0, 1000), $test !== '' ? 1 : 0, $r['id']]);
            if ($r['oid']) order_log((int) $r['oid'], 'meta', '', '', 'Meta : « ' . $r['name'] . ' » envoyé (event_id ' . $r['event_id'] . ')' . ($test !== '' ? ' — mode test' : ''), 'système');
            $sent++;
        } else {
            $final = $att >= count(CAPI_BACKOFF);
            $pdo->prepare('UPDATE capi_events SET status = ?, attempts = ?, next_try = ?, error = ?, response = ? WHERE id = ?')
                ->execute([$final ? 'failed' : 'pending', $att, time() + CAPI_BACKOFF[min($att - 1, count(CAPI_BACKOFF) - 1)], mb_substr($err, 0, 500), mb_substr($res, 0, 1000), $r['id']]);
            meta_set('capi_last_error', date('Y-m-d H:i') . ' — ' . $r['name'] . ' : ' . $err);
        }
    }
    return $sent;
}

/**
 * Effets Meta d'un changement de statut (appelé après l'enregistrement).
 * Purchase part une seule fois, au premier passage par un statut confirmé (ou à la livraison).
 */
function meta_on_status(array $o, string $from): void
{
    $trigger = setting('purchase_trigger') === 'delivered' ? 'delivered' : 'confirmed';
    $pdo = db();
    if (in_array($o['status'], CONFIRMED_STATUSES, true)) {
        if ($trigger === 'confirmed' && !$o['purchase_sent']) {
            $pdo->prepare('UPDATE orders SET purchase_sent = 1 WHERE id = ? AND purchase_sent = 0')->execute([$o['id']]);
            capi_queue($o, 'Purchase', 'purchase.' . $o['ref']);
        } elseif ($trigger === 'delivered' && !$o['purchase_sent'] && $o['status'] !== 'delivered') {
            // Déclencheur « livrée » : on signale quand même la confirmation, sous un nom distinct.
            capi_queue($o, 'OrderConfirmed', 'confirmed.' . $o['ref']);
        }
    }
    if ($o['status'] === 'delivered') {
        if ($trigger === 'delivered' && !$o['purchase_sent']) {
            $pdo->prepare('UPDATE orders SET purchase_sent = 1 WHERE id = ? AND purchase_sent = 0')->execute([$o['id']]);
            capi_queue($o, 'Purchase', 'purchase.' . $o['ref']);
        }
        if (!$o['delivered_sent']) {
            $pdo->prepare('UPDATE orders SET delivered_sent = 1 WHERE id = ? AND delivered_sent = 0')->execute([$o['id']]);
            $name = preg_match('/^[A-Za-z][A-Za-z0-9_]{1,39}$/', setting('delivered_event')) ? setting('delivered_event') : 'OrderDelivered';
            capi_queue($o, $name, 'delivered.' . $o['ref']);
        }
    }
    if (in_array($o['status'], RELEASED_STATUSES, true) && $o['purchase_sent']) {
        order_log($o['id'], 'meta', '', '', 'Purchase avait déjà été envoyé à Meta : il reste compté côté Meta (Meta ne permet pas de l\'annuler).', 'système');
    }
}

/** Lead d'une commande qui vient d'être créée (même event_id que l'événement navigateur). */
function meta_on_order_created(array $o): void
{
    capi_queue($o, 'Lead', $o['lead_event'] !== '' ? $o['lead_event'] : 'lead.' . $o['ref'], $o['created']);
}

/**
 * Événement de navigation reçu du navigateur (même event_id que le pixel) : renvoyé tel quel côté
 * serveur avec l'IP, le navigateur et les cookies Meta du visiteur. Pas de file d'attente : un échec
 * est seulement compté (le pixel l'a déjà envoyé côté navigateur).
 */
function capi_browser_event(string $name, string $eventId, string $url, array $custom, array $ctx): void
{
    if (!capi_ready() || setting('capi_browser') !== '1') return;
    $u = [];
    if (($ctx['ip'] ?? '') !== '') $u['client_ip_address'] = $ctx['ip'];
    if (($ctx['ua'] ?? '') !== '') $u['client_user_agent'] = $ctx['ua'];
    if (($ctx['fbp'] ?? '') !== '') $u['fbp'] = $ctx['fbp'];
    if (($ctx['fbc'] ?? '') !== '') $u['fbc'] = $ctx['fbc'];
    if (($ctx['vid'] ?? '') !== '') $u['external_id'] = [sha($ctx['vid'])];
    if (!isset($u['client_user_agent'])) return; // Meta exige le navigateur pour un événement « website »
    $event = [
        'event_name' => $name, 'event_time' => time(), 'event_id' => $eventId, 'action_source' => 'website',
        'event_source_url' => $url, 'user_data' => $u,
    ];
    if ($custom) $event['custom_data'] = $custom;
    after_response(function () use ($event) {
        [$ok, $err] = capi_send([$event]);
        daily_inc($ok ? 'capi_ok' : 'capi_fail');
        if (!$ok) meta_set('capi_last_error', date('Y-m-d H:i') . ' — ' . $event['event_name'] . ' : ' . $err);
    });
}

/** Cookie _fbc à partir du fbclid de l'URL (format Meta fb.1.<horodatage ms>.<fbclid>). */
function fbc_from_request(): string
{
    $c = (string) ($_COOKIE['_fbc'] ?? '');
    if (preg_match('/^fb\.\d\.\d+\..+$/', $c)) return mb_substr($c, 0, 600);
    return '';
}

function fbp_from_request(): string
{
    $c = (string) ($_COOKIE['_fbp'] ?? '');
    return preg_match('/^fb\.\d\.\d+\.\d+$/', $c) ? $c : '';
}

/** Paramètres d'URL à coller dans Ads Manager (Publicité → Paramètres d'URL). */
function recommended_url_params(): string
{
    return 'utm_source=facebook&utm_medium=paid&utm_campaign={{campaign.name}}&utm_term={{adset.name}}&utm_content={{ad.name}}'
        . '&campaign_id={{campaign.id}}&adset_id={{adset.id}}&ad_id={{ad.id}}&placement={{placement}}&site_source_name={{site_source_name}}';
}
