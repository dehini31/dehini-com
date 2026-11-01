<?php
/**
 * API publique de la boutique.
 *   GET  ?action=geo             wilayas, frais de livraison et communes (mis en cache)
 *   POST ?action=order           nouvelle commande (JSON ou formulaire classique sans JavaScript)
 *   POST ?action=t               événement de navigation (doublé côté serveur vers Meta, compteurs du tunnel)
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/media.php';
require __DIR__ . '/lib/markdown.php';
require __DIR__ . '/lib/orders.php';
require __DIR__ . '/lib/meta.php';
require __DIR__ . '/lib/notify.php';
require __DIR__ . '/lib/view.php';

security_headers();
header('X-Robots-Tag: noindex');
$action = (string) ($_GET['action'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** Contexte de la requête transmis à Meta (IP, navigateur, cookies _fbp / _fbc, visiteur). */
function request_ctx(array $b = []): array
{
    $fbp = fbp_from_request();
    if ($fbp === '' && preg_match('/^fb\.\d\.\d+\.\d+$/', (string) ($b['fbp'] ?? ''))) $fbp = (string) $b['fbp'];
    $fbc = fbc_from_request();
    if ($fbc === '' && preg_match('/^fb\.\d\.\d+\..{10,500}$/', (string) ($b['fbc'] ?? ''))) $fbc = (string) $b['fbc'];
    $vid = preg_match('/^[a-f0-9]{32}$/', (string) ($_COOKIE['swb_vid'] ?? '')) ? (string) $_COOKIE['swb_vid'] : '';
    return ['ip' => client_ip(), 'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400), 'fbp' => $fbp, 'fbc' => $fbc,
        'vid' => $vid, 'iph' => ip_hash()];
}

/** L'adresse de la page doit appartenir à la boutique. */
function own_url(string $u): string
{
    $u = mb_substr(trim($u), 0, 500);
    return strpos($u, site_origin() . base_path() . '/') === 0 ? $u : abs_url('');
}

try {
    switch ($action) {

        case 'geo': {
            header('Cache-Control: public, max-age=86400');
            json_out(['v' => geo_version(), 'w' => geo_payload()]);
        }

        case 'order': {
            if ($method !== 'POST') json_out(['error' => 'method'], 405);
            $isJson = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false;
            $in = $isJson ? input_json() : $_POST;
            if (!$isJson && isset($in['attr']) && is_string($in['attr'])) $in['attr'] = json_decode($in['attr'], true) ?: [];
            $lang = in_array($in['lang'] ?? '', SWB_LANGS, true) ? (string) $in['lang'] : 'fr';
            current_lang($lang);
            $ctx = request_ctx($in);
            $ctx['url'] = own_url((string) ($in['url'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
            $r = order_create($in, $ctx);
            if (isset($r['error'])) {
                if (!$isJson) {
                    // Sans JavaScript : retour à la fiche produit avec le message.
                    header('Content-Type: text/html; charset=utf-8');
                    http_response_code(422);
                    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:sans-serif;padding:2rem;max-width:36rem;margin:auto"><p>'
                        . e(t($r['error'])) . '</p><p><a href="javascript:history.back()">←</a></p></body>';
                    exit;
                }
                json_out(['error' => $r['error'], 'field' => $r['field'] ?? null, 'message' => t($r['error'])], 422);
            }
            $o = $r['order'];
            if (!$r['existing']) {
                meta_on_order_created($o);
                after_response(function () use ($o) {
                    notify_new_order($o);
                    foreach ($o['items'] as $it) {
                        $p = product_by_id((int) $it['pid']);
                        if ($p && $p['stock'] !== null && $p['stock'] <= $p['stock_alert']) notify_low_stock($p);
                    }
                });
            }
            $thanks = url('commande/' . $o['ref'], $o['lang']) . '?k=' . $o['token'];
            if (!$isJson) {
                header('Location: ' . $thanks, true, 303);
                run_after_tasks();
                exit;
            }
            $it = $o['items'][0];
            json_out([
                'ok' => true, 'ref' => $o['ref'], 'url' => $thanks, 'existing' => $r['existing'],
                // Le navigateur envoie « Lead » avec CE même event_id : Meta déduplique avec l'envoi serveur.
                'lead' => $r['existing'] ? null : [
                    'event_id' => $o['lead_event'], 'value' => $o['total'], 'currency' => 'DZD', 'content_ids' => [(string) $it['sku']],
                    'content_type' => 'product', 'num_items' => $o['qty'], 'contents' => [['id' => (string) $it['sku'], 'quantity' => (int) $it['qty']]],
                ],
            ]);
        }

        case 't': {
            if ($method !== 'POST') json_out(['error' => 'method'], 405);
            $b = input_json();
            $name = (string) ($b['e'] ?? '');
            $eid = (string) ($b['id'] ?? '');
            if (!in_array($name, BROWSER_EVENTS, true) || !preg_match('/^[a-z]{2,4}\.[A-Za-z0-9]{6,40}$/', $eid)) json_out(['error' => 'bad'], 400);
            if (!rate_ok('t:' . ip_hash(), 300, 600)) json_out(['error' => 'rate'], 429);
            $map = ['PageView' => 'pageview', 'ViewContent' => 'view_content', 'AddToCart' => 'add_to_cart', 'InitiateCheckout' => 'initiate_checkout', 'Contact' => 'contact'];
            daily_inc($map[$name]);
            if ($name === 'PageView' && !empty($b['n'])) daily_inc('visit');
            $custom = [];
            if (in_array($name, ['ViewContent', 'AddToCart', 'InitiateCheckout'], true) && !empty($b['p'])) {
                $p = product_by_id((int) $b['p']);
                if ($p) {
                    $tier = product_tier($p, (int) ($b['q'] ?? 0)) ?: default_tier($p);
                    $sku = $p['sku'] ?: 'P' . $p['id'];
                    $custom = ['currency' => 'DZD', 'value' => $tier['price'], 'content_type' => 'product', 'content_ids' => [$sku],
                        'content_name' => tr($p['name'], 'fr'), 'contents' => [['id' => $sku, 'quantity' => $tier['qty']]], 'num_items' => $tier['qty']];
                }
            }
            capi_browser_event($name, $eid, own_url((string) ($b['u'] ?? '')), $custom, request_ctx($b));
            json_out(['ok' => true]);
        }

        default:
            json_out(['error' => 'unknown'], 404);
    }
} catch (Throwable $e) {
    error_log('[swanbida] api ' . $action . ' : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'server', 'message' => t('err_generic')], 500);
}
