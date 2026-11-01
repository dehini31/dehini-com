<?php
/**
 * Commandes en paiement à la livraison : validation serveur, calcul du montant, numéro unique,
 * stock, doublons, changements de statut (avec leurs effets sur le stock et sur Meta).
 */
declare(strict_types=1);

/** Alphabet des numéros de commande : lisible au téléphone (sans 0/O, 1/I/L). */
const REF_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

function order_new_ref(): string
{
    $pdo = db();
    for ($i = 0; $i < 20; $i++) {
        $r = 'SW-';
        for ($j = 0; $j < 6; $j++) $r .= REF_ALPHABET[random_int(0, strlen(REF_ALPHABET) - 1)];
        $s = $pdo->prepare('SELECT 1 FROM orders WHERE ref = ?');
        $s->execute([$r]);
        if (!$s->fetchColumn()) return $r;
    }
    throw new RuntimeException('Impossible de générer un numéro de commande');
}

function normalize_ref(string $r): string
{
    $r = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $r) ?? '');
    if (strpos($r, 'SW') === 0) $r = substr($r, 2);
    $r = strtr($r, ['O' => '0', 'I' => '1', 'L' => '1']);
    return preg_match('/^[' . REF_ALPHABET . ']{6}$/', $r) ? 'SW-' . $r : '';
}

function order_by_ref(string $ref): ?array
{
    $s = db()->prepare('SELECT * FROM orders WHERE ref = ?');
    $s->execute([$ref]);
    $r = $s->fetch();
    return $r ? order_decode($r) : null;
}

function order_by_id(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ? order_decode($r) : null;
}

function order_decode(array $r): array
{
    $r['items'] = json_decode((string) $r['items'], true) ?: [];
    $r['attribution'] = json_decode((string) $r['attribution'], true) ?: [];
    foreach (['id', 'qty', 'subtotal', 'shipping', 'total', 'stock_reserved', 'purchase_sent', 'delivered_sent', 'created', 'updated',
        'confirmed_at', 'shipped_at', 'delivered_at', 'closed_at'] as $k) $r[$k] = (int) $r[$k];
    $r['duplicate_of'] = $r['duplicate_of'] === null ? null : (int) $r['duplicate_of'];
    return $r;
}

/** Résumé lisible des articles (« 2 packs – SWANBIDA – Pack Serviette Blanc »). */
function order_items_text(array $o, string $lang = 'fr'): string
{
    $parts = [];
    foreach ($o['items'] as $it) {
        $label = tr($it['label'] ?? [], $lang) ?: ($it['qty'] . '×');
        $parts[] = $label . ' – ' . tr($it['name'] ?? [], $lang);
    }
    return implode(', ', $parts);
}

function shipping_fee(array $wilaya, string $delivery): int
{
    return $delivery === 'desk' ? (int) $wilaya['fee_desk'] : (int) $wilaya['fee_home'];
}

/** Informations d'attribution publicitaire acceptées (clé => longueur max). */
const ATTR_KEYS = [
    'utm_source' => 120, 'utm_medium' => 120, 'utm_campaign' => 200, 'utm_content' => 200, 'utm_term' => 200, 'utm_id' => 64,
    'campaign_id' => 32, 'adset_id' => 32, 'ad_id' => 32, 'placement' => 64, 'site_source_name' => 32, 'fbclid' => 500,
    'landing' => 500, 'referrer' => 300, 'ts' => 13,
];

function clean_attribution($a): array
{
    $out = [];
    if (!is_array($a)) return $out;
    foreach (ATTR_KEYS as $k => $max) {
        if (!isset($a[$k]) || is_array($a[$k])) continue;
        $v = one_line((string) $a[$k], $max);
        if ($v === '') continue;
        if (in_array($k, ['campaign_id', 'adset_id', 'ad_id', 'ts'], true) && !ctype_digit($v)) continue;
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Valide et enregistre une commande. $in : données du formulaire ; $ctx : contexte de la requête
 * (ip, ua, fbp, fbc, vid, url). Retourne ['order' => …, 'existing' => bool] ou ['error' => clé, 'field' => …].
 */
function order_create(array $in, array $ctx): array
{
    $pdo = db();
    if (trim((string) ($in['hp'] ?? '')) !== '') return ['error' => 'err_generic'];

    $clientKey = preg_match('/^[A-Za-z0-9_-]{16,64}$/', (string) ($in['key'] ?? '')) ? (string) $in['key'] : null;
    if ($clientKey) {
        $s = $pdo->prepare('SELECT * FROM orders WHERE client_key = ?');
        $s->execute([$clientKey]);
        if ($r = $s->fetch()) return ['order' => order_decode($r), 'existing' => true];
    }

    // Validation champ par champ (toujours refaite ici, jamais confiée au navigateur).
    $name = one_line($in['name'] ?? '', 80);
    if (mb_strlen($name) < 3 || preg_match_all('/\p{L}/u', $name) < 3) return ['error' => 'err_name', 'field' => 'name'];
    $phone = normalize_phone((string) ($in['phone'] ?? ''));
    if ($phone === '') return ['error' => 'err_phone', 'field' => 'phone'];
    $w = wilaya_get(preg_replace('/\D/', '', (string) ($in['wilaya'] ?? '')) ?? '');
    if (!$w) return ['error' => 'err_wilaya', 'field' => 'wilaya'];
    if (!(int) $w['active']) return ['error' => 'err_wilaya_off', 'field' => 'wilaya'];
    $communeIn = one_line($in['commune'] ?? '', 80);
    $commune = commune_match($w['code'], $communeIn);
    $customCommune = false;
    if ($commune === '') {
        // Commune absente de la liste (nouvelle commune, orthographe…) : acceptée telle quelle, signalée dans l'admin.
        if (mb_strlen($communeIn) < 2) return ['error' => 'err_commune', 'field' => 'commune'];
        $commune = $communeIn;
        $customCommune = true;
    }
    $address = one_line($in['address'] ?? '', 250);
    if (mb_strlen($address) < 4) return ['error' => 'err_address', 'field' => 'address'];
    $note = str_clean($in['note'] ?? '', 500);
    $delivery = ($in['delivery'] ?? 'home') === 'desk' && setting('desk_enabled') === '1' ? 'desk' : 'home';

    $p = product_by_id((int) ($in['product'] ?? 0));
    if (!$p || $p['status'] !== 'active') return ['error' => 'err_generic'];
    $tier = product_tier($p, (int) ($in['qty'] ?? 0));
    if (!$tier) return ['error' => 'err_tier', 'field' => 'qty'];
    $qty = $tier['qty'];
    $backorder = setting('allow_backorder') === '1';
    if ($p['stock'] !== null && !$backorder && $p['stock'] < $qty) return ['error' => 'err_stock', 'field' => 'qty'];

    // Double envoi (double clic, retour arrière) : même téléphone, même produit, même offre en 10 min.
    $s = $pdo->prepare("SELECT * FROM orders WHERE phone = ? AND created > ? AND status = 'new' ORDER BY id DESC LIMIT 1");
    $s->execute([$phone, time() - 600]);
    if ($r = $s->fetch()) {
        $prev = order_decode($r);
        $it = $prev['items'][0] ?? [];
        if ((int) ($it['pid'] ?? 0) === $p['id'] && (int) ($it['qty'] ?? 0) === $qty && $prev['wilaya'] === $w['code']) {
            return ['order' => $prev, 'existing' => true];
        }
    }

    $iph = $ctx['iph'] ?? '';
    $max = max(1, (int) setting('order_rate_ip'));
    if ($iph !== '' && !rate_ok('order:' . $iph, $max, 3600)) return ['error' => 'err_rate'];
    if (!rate_ok('order-phone:' . $phone, 5, 3600)) return ['error' => 'err_rate'];

    // Commande possible en doublon (même téléphone et même produit récemment) : signalée, pas bloquée.
    $dup = null;
    $hours = max(0, (int) setting('duplicate_hours'));
    if ($hours > 0) {
        $s = $pdo->prepare("SELECT id, items FROM orders WHERE phone = ? AND created > ? AND status NOT IN ('cancelled') ORDER BY id DESC");
        $s->execute([$phone, time() - $hours * 3600]);
        foreach ($s->fetchAll() as $r) {
            foreach (json_decode((string) $r['items'], true) ?: [] as $it) {
                if ((int) ($it['pid'] ?? 0) === $p['id']) { $dup = (int) $r['id']; break 2; }
            }
        }
    }

    $shipping = shipping_fee($w, $delivery);
    $subtotal = $tier['price'];
    $total = $subtotal + $shipping;
    $attr = clean_attribution($in['attr'] ?? []);
    $fbclid = $attr['fbclid'] ?? '';
    $fbc = (string) ($ctx['fbc'] ?? '');
    if ($fbc === '' && $fbclid !== '') $fbc = 'fb.1.' . ((int) ($attr['ts'] ?? 0) ?: time() * 1000) . '.' . $fbclid;
    $items = [[
        'pid' => $p['id'], 'slug' => $p['slug'], 'sku' => $p['sku'] ?: ('P' . $p['id']), 'name' => $p['name'],
        'qty' => $qty, 'label' => $tier['label'], 'price' => $tier['price'], 'compare' => $tier['compare'],
        'unit' => (int) round($tier['price'] / $qty),
    ]];
    $now = time();
    $ref = order_new_ref();
    $lang = in_array($in['lang'] ?? '', SWB_LANGS, true) ? $in['lang'] : 'fr';

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $reserved = 0;
        if ($p['stock'] !== null) {
            $u = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?' . ($backorder ? '' : ' AND stock >= ?'));
            $u->execute($backorder ? [$qty, $p['id']] : [$qty, $p['id'], $qty]);
            if ($u->rowCount() === 0) {
                $pdo->exec('ROLLBACK');
                return ['error' => 'err_stock', 'field' => 'qty'];
            }
            $reserved = 1;
        }
        $pdo->prepare('INSERT INTO orders (ref, token, client_key, status, name, phone, wilaya, wilaya_name, commune, address, note, delivery, items, qty,
                subtotal, shipping, total, lang, utm_source, utm_medium, utm_campaign, utm_content, utm_term, campaign_id, adset_id, ad_id, placement,
                fbclid, attribution, fbp, fbc, vid, ip, ua, source_url, iph, stock_reserved, duplicate_of, lead_event, created, updated)
                VALUES (?, ?, ?, \'new\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $ref, token(16), $clientKey, $name, $phone, $w['code'], $w['fr'], $commune, $address, $note, $delivery,
                json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $qty, $subtotal, $shipping, $total, $lang,
                $attr['utm_source'] ?? '', $attr['utm_medium'] ?? '', $attr['utm_campaign'] ?? '', $attr['utm_content'] ?? '', $attr['utm_term'] ?? '',
                $attr['campaign_id'] ?? '', $attr['adset_id'] ?? '', $attr['ad_id'] ?? '', $attr['placement'] ?? '', $fbclid,
                json_encode($attr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                one_line($ctx['fbp'] ?? '', 120), one_line($fbc, 600), one_line($ctx['vid'] ?? '', 64), (string) ($ctx['ip'] ?? ''),
                one_line($ctx['ua'] ?? '', 400), one_line($ctx['url'] ?? '', 500), $iph, $reserved, $dup, 'lead.' . $ref, $now, $now,
            ]);
        $oid = (int) $pdo->lastInsertId();
        $detail = 'Commande reçue sur le site (' . strtoupper($lang) . ')' . ($customCommune ? ' — commune saisie à la main' : '') . ($dup ? ' — doublon possible de la commande #' . $dup : '');
        order_log($oid, 'create', '', 'new', $detail, 'site');
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    daily_inc('lead');
    return ['order' => order_by_id($oid), 'existing' => false];
}

function order_log(int $oid, string $kind, string $from, string $to, string $detail, string $actor): void
{
    db()->prepare('INSERT INTO order_log (oid, kind, from_status, to_status, detail, actor, created) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$oid, $kind, $from, $to, mb_substr($detail, 0, 1000), mb_substr($actor, 0, 80), time()]);
}

/**
 * Change le statut d'une commande et applique ses effets :
 *  - annulée / retournée : le stock réservé est rendu ; revenir à un statut actif le réserve à nouveau ;
 *  - confirmée (ou plus loin) : événement Meta « Purchase » si c'est le déclencheur choisi (une seule fois) ;
 *  - livrée : événement Meta de livraison (distinct de Purchase), une seule fois.
 */
function order_set_status(array $o, string $status, string $actor, string $note = ''): array
{
    if (!in_array($status, ORDER_STATUSES, true)) throw new InvalidArgumentException('statut inconnu');
    $from = $o['status'];
    if ($from === $status && $note === '') return $o;
    $pdo = db();
    $now = time();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $sets = ['status = ?', 'updated = ?'];
        $args = [$status, $now];
        // Stock : rendu à l'annulation / au retour, repris si la commande redevient active.
        if (in_array($status, RELEASED_STATUSES, true) && $o['stock_reserved']) {
            foreach ($o['items'] as $it) {
                $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL')->execute([(int) $it['qty'], (int) $it['pid']]);
            }
            $sets[] = 'stock_reserved = 0';
        } elseif (!in_array($status, RELEASED_STATUSES, true) && !$o['stock_reserved'] && in_array($from, RELEASED_STATUSES, true)) {
            foreach ($o['items'] as $it) {
                $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL')->execute([(int) $it['qty'], (int) $it['pid']]);
            }
            $sets[] = 'stock_reserved = 1';
        }
        if (in_array($status, CONFIRMED_STATUSES, true) && !$o['confirmed_at']) { $sets[] = 'confirmed_at = ?'; $args[] = $now; }
        if (in_array($status, ['shipped', 'delivered'], true) && !$o['shipped_at']) { $sets[] = 'shipped_at = ?'; $args[] = $now; }
        if ($status === 'delivered' && !$o['delivered_at']) { $sets[] = 'delivered_at = ?'; $args[] = $now; }
        if (in_array($status, RELEASED_STATUSES, true)) { $sets[] = 'closed_at = ?'; $args[] = $now; }
        $args[] = $o['id'];
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        order_log($o['id'], 'status', $from, $status, $note, $actor);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    $o = order_by_id($o['id']);
    meta_on_status($o, $from);
    return order_by_id($o['id']);
}

/**
 * Modification d'une commande depuis l'admin (client, adresse, offre, livraison, suivi, notes).
 * Le montant est toujours recalculé côté serveur.
 */
function order_update(array $o, array $in, string $actor): array
{
    $changes = [];
    $f = [];
    if (array_key_exists('name', $in)) {
        $v = one_line($in['name'], 80);
        if (mb_strlen($v) < 2) throw new InvalidArgumentException('Nom trop court');
        $f['name'] = $v;
    }
    if (array_key_exists('phone', $in)) {
        $v = normalize_phone((string) $in['phone']);
        if ($v === '') throw new InvalidArgumentException('Téléphone invalide');
        $f['phone'] = $v;
    }
    $w = null;
    if (array_key_exists('wilaya', $in)) {
        $w = wilaya_get((string) $in['wilaya']);
        if (!$w) throw new InvalidArgumentException('Wilaya inconnue');
        $f['wilaya'] = $w['code'];
        $f['wilaya_name'] = $w['fr'];
    }
    if (array_key_exists('commune', $in)) {
        $v = one_line($in['commune'], 80);
        $code = $f['wilaya'] ?? $o['wilaya'];
        $f['commune'] = commune_match($code, $v) ?: $v;
    }
    foreach (['address' => 250, 'carrier' => 60, 'tracking' => 80] as $k => $max) {
        if (array_key_exists($k, $in)) $f[$k] = one_line($in[$k], $max);
    }
    foreach (['note' => 500, 'admin_note' => 2000] as $k => $max) {
        if (array_key_exists($k, $in)) $f[$k] = str_clean($in[$k], $max);
    }
    if (array_key_exists('delivery', $in)) $f['delivery'] = $in['delivery'] === 'desk' ? 'desk' : 'home';

    $items = $o['items'];
    $qtyChange = 0;
    if (array_key_exists('qty', $in) && $items) {
        $p = product_by_id((int) $items[0]['pid']);
        $tier = $p ? product_tier($p, (int) $in['qty']) : null;
        if (!$tier) throw new InvalidArgumentException('Offre inconnue');
        if ($tier['qty'] !== (int) $items[0]['qty']) {
            $qtyChange = $tier['qty'] - (int) $items[0]['qty'];
            $items[0] = array_merge($items[0], ['qty' => $tier['qty'], 'label' => $tier['label'], 'price' => $tier['price'],
                'compare' => $tier['compare'], 'unit' => (int) round($tier['price'] / $tier['qty'])]);
            $f['items'] = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $f['qty'] = $tier['qty'];
            $f['subtotal'] = $tier['price'];
        }
    }
    $subtotal = $f['subtotal'] ?? $o['subtotal'];
    if (array_key_exists('shipping', $in) && $in['shipping'] !== '' && $in['shipping'] !== null) {
        $f['shipping'] = max(0, min(100000, (int) $in['shipping']));
    } elseif (isset($f['wilaya']) || isset($f['delivery'])) {
        $ww = $w ?: wilaya_get($o['wilaya']);
        if ($ww) $f['shipping'] = shipping_fee($ww, $f['delivery'] ?? $o['delivery']);
    }
    $f['total'] = $subtotal + ($f['shipping'] ?? $o['shipping']);

    foreach ($f as $k => $v) {
        if ((string) ($o[$k] ?? '') !== (string) $v && !in_array($k, ['items', 'wilaya_name', 'total'], true)) $changes[] = $k;
    }
    if (!$changes && !isset($f['items'])) return $o;
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        if ($qtyChange && $o['stock_reserved']) {
            $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock IS NOT NULL')->execute([$qtyChange, (int) $items[0]['pid']]);
        }
        $f['updated'] = time();
        $sql = 'UPDATE orders SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?';
        $pdo->prepare($sql)->execute(array_merge(array_values($f), [$o['id']]));
        $labels = ['name' => 'nom', 'phone' => 'téléphone', 'wilaya' => 'wilaya', 'commune' => 'commune', 'address' => 'adresse', 'carrier' => 'transporteur',
            'tracking' => 'n° de suivi', 'note' => 'remarque client', 'admin_note' => 'note interne', 'delivery' => 'mode de livraison', 'qty' => 'offre',
            'subtotal' => 'montant', 'shipping' => 'frais de livraison'];
        $txt = implode(', ', array_unique(array_map(fn($k) => $labels[$k] ?? $k, $changes ?: ['qty'])));
        order_log($o['id'], 'edit', '', '', 'Modifié : ' . $txt, $actor);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return order_by_id($o['id']);
}

/** Ce que le client voit de sa commande (page de remerciement et suivi). */
function order_public(array $o, string $lang): array
{
    $steps = [];
    $log = db()->prepare("SELECT to_status, created FROM order_log WHERE oid = ? AND kind IN ('create', 'status') ORDER BY id");
    $log->execute([$o['id']]);
    foreach ($log->fetchAll() as $r) {
        if ($r['to_status'] !== '') $steps[] = ['status' => $r['to_status'], 't' => (int) $r['created']];
    }
    return [
        'ref' => $o['ref'], 'status' => $o['status'], 'created' => $o['created'], 'name' => $o['name'], 'phone' => phone_display($o['phone']),
        'wilaya' => $o['wilaya'], 'wilaya_name' => wilaya_label($o['wilaya'], $lang), 'commune' => commune_label($o['wilaya'], $o['commune'], $lang),
        'address' => $o['address'], 'items' => $o['items'], 'subtotal' => $o['subtotal'], 'shipping' => $o['shipping'], 'total' => $o['total'],
        'carrier' => $o['carrier'], 'tracking' => $o['tracking'], 'steps' => $steps, 'delivery' => $o['delivery'],
    ];
}

function wilaya_label(string $code, string $lang): string
{
    $w = wilaya_get($code);
    if (!$w) return $code;
    return $code . ' – ' . ($lang === 'ar' && $w['ar'] !== '' ? $w['ar'] : $w['fr']);
}

function commune_label(string $wilaya, string $commune, string $lang): string
{
    if ($lang !== 'ar') return $commune;
    $s = db()->prepare('SELECT ar FROM communes WHERE wilaya = ? AND fr = ? LIMIT 1');
    $s->execute([$wilaya, $commune]);
    $v = $s->fetchColumn();
    return $v !== false && $v !== '' ? (string) $v : $commune;
}

/** Lien WhatsApp vers le client, avec le message de confirmation pré-rempli. */
function order_whatsapp_link(array $o): string
{
    $lang = $o['lang'] === 'ar' ? 'ar' : 'fr';
    $tpl = setting('wa_template_' . $lang);
    $msg = strtr($tpl, [
        '{name}' => $o['name'], '{ref}' => $o['ref'], '{items}' => order_items_text($o, $lang), '{total}' => money($o['total'], $lang),
        '{wilaya}' => wilaya_label($o['wilaya'], $lang), '{commune}' => commune_label($o['wilaya'], $o['commune'], $lang),
        '{address}' => $o['address'], '{phone}' => phone_display($o['phone']),
    ]);
    return 'https://wa.me/' . phone_e164($o['phone']) . '?text=' . rawurlencode($msg);
}
