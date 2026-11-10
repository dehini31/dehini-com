<?php
/**
 * Commandes et réservations : création depuis la plateforme publique (le stock du point de
 * vente est mis de côté tout de suite), changements de statut par le point de vente, suivi
 * par le client et expiration des réservations non retirées.
 */
declare(strict_types=1);

const ORDER_STATUSES = [
    'pending'   => 'En attente',
    'confirmed' => 'Confirmée',
    'ready'     => 'Prête au retrait',
    'shipped'   => 'En cours de livraison',
    'done'      => 'Terminée',
    'cancelled' => 'Annulée',
];
/** Statuts où le stock reste mis de côté et où le point de vente a encore à faire. */
const ORDER_OPEN = ['pending', 'confirmed', 'ready', 'shipped'];

/** Étapes suivantes possibles selon le statut et le mode (retrait ou livraison). */
function order_next(string $status, string $mode): array
{
    switch ($status) {
        case 'pending':   return ['confirmed', 'cancelled'];
        case 'confirmed': return [$mode === 'delivery' ? 'shipped' : 'ready', 'cancelled'];
        case 'ready':     return ['done', 'cancelled'];
        case 'shipped':   return ['done', 'cancelled'];
    }
    return [];
}

function order_ref(): string
{
    $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $s = db()->prepare('SELECT 1 FROM orders WHERE ref = ?');
    for ($i = 0; $i < 20; $i++) {
        $ref = 'ML-';
        for ($j = 0; $j < 6; $j++) $ref .= $abc[random_int(0, 31)];
        $s->execute([$ref]);
        if (!$s->fetchColumn()) return $ref;
    }
    throw new RuntimeException('ref');
}

/**
 * Crée une commande ou une réservation. $b : store_id, kind (order|reservation), mode
 * (pickup|delivery), items [{variant_id, qty}], name, phone, email, address, city, zone, note.
 * Retourne ['order' => ligne, 'token' => jeton de suivi] ou lève InvalidArgumentException
 * (message affichable).
 */
function order_create(array $b, ?int $at = null): array
{
    $pdo = db();
    $now = $at ?? time();
    $kind = ($b['kind'] ?? '') === 'reservation' ? 'reservation' : 'order';
    $mode = ($b['mode'] ?? '') === 'delivery' ? 'delivery' : 'pickup';
    if ($kind === 'reservation') $mode = 'pickup';

    $s = $pdo->prepare("SELECT s.*, c.status AS c_status, c.name AS c_name, c.email AS c_email FROM stores s JOIN companies c ON c.id = s.company_id WHERE s.id = ?");
    $s->execute([(int) ($b['store_id'] ?? 0)]);
    $store = $s->fetch();
    if (!$store || $store['status'] !== 'active' || $store['c_status'] !== 'active') throw new InvalidArgumentException('Ce point de vente n\'est plus disponible.');
    if ($kind === 'reservation' && !(int) $store['reserve']) throw new InvalidArgumentException('Ce point de vente ne prend pas de réservation.');
    if ($mode === 'delivery' && !(int) $store['delivery']) throw new InvalidArgumentException('Ce point de vente ne livre pas.');
    if ($kind === 'order' && $mode === 'pickup' && !(int) $store['pickup']) throw new InvalidArgumentException('Ce point de vente ne propose pas le retrait en magasin.');

    $name = line_clean($b['name'] ?? '', 80);
    $phone = phone_clean($b['phone'] ?? '');
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    if (mb_strlen($name) < 2) throw new InvalidArgumentException('Indiquez votre nom.');
    if (!phone_ok($phone)) throw new InvalidArgumentException('Indiquez un numéro de téléphone valide.');
    if ($email !== '' && !valid_email($email)) throw new InvalidArgumentException('Adresse email invalide.');
    $address = '';
    $city = '';
    $zone = '';
    if ($mode === 'delivery') {
        $address = line_clean($b['address'] ?? '', 200);
        $city = line_clean($b['city'] ?? '', 80);
        $zone = zone_code($b['zone'] ?? '');
        if (mb_strlen($address) < 5 || $city === '' || $zone === '') throw new InvalidArgumentException('Indiquez l\'adresse de livraison complète (adresse, ville, wilaya).');
    }

    $items = [];
    foreach ((array) ($b['items'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $vid = (int) ($it['variant_id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($vid <= 0 || $qty <= 0) continue;
        $items[$vid] = min(20, ($items[$vid] ?? 0) + $qty);
    }
    if (!$items || count($items) > 20) throw new InvalidArgumentException('Choisissez un article.');

    $q = $pdo->prepare("SELECT v.*, p.name AS p_name, p.price AS p_price, p.company_id AS p_cid, p.brand_id, p.status AS p_status, b.name AS b_name, c.status AS c_status
        FROM variants v JOIN products p ON p.id = v.product_id JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id WHERE v.id = ?");
    $lines = [];
    $total = 0.0;
    foreach ($items as $vid => $qty) {
        $q->execute([$vid]);
        $v = $q->fetch();
        if (!$v || $v['p_status'] !== 'active' || $v['c_status'] !== 'active') throw new InvalidArgumentException('Un article n\'est plus disponible.');
        $price = $v['price'] !== null ? (float) $v['price'] : (float) $v['p_price'];
        $lines[] = ['v' => $v, 'qty' => $qty, 'price' => $price];
        $total += $price * $qty;
    }

    $token = token(20);
    $pdo->beginTransaction();
    try {
        $take = $pdo->prepare('UPDATE stock SET qty = qty - ?, updated = ? WHERE store_id = ? AND variant_id = ? AND qty >= ?');
        foreach ($lines as $l) {
            $take->execute([$l['qty'], $now, $store['id'], $l['v']['id'], $l['qty']]);
            if ($take->rowCount() !== 1) {
                $pdo->rollBack();
                throw new InvalidArgumentException('Stock insuffisant pour « ' . $l['v']['p_name'] . ' » dans ce point de vente. Choisissez une quantité plus petite ou un autre magasin.');
            }
        }
        $ref = order_ref();
        $pdo->prepare('INSERT INTO orders (ref, thash, kind, mode, store_id, seller_cid, status, total, cname, cphone, cemail, address, city, wilaya, note, created, updated, expires)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $ref, hash('sha256', $token), $kind, $mode, $store['id'], $store['company_id'], 'pending', $total, $name, $phone, $email,
            $address, $city, $zone, str_clean($b['note'] ?? '', 500), $now, $now,
            $kind === 'reservation' ? $now + (int) MLG_RESERVATION_HOURS * 3600 : 0,
        ]);
        $oid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, variant_id, product_cid, brand_id, name, brand, size, color, price, qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as $l) {
            $v = $l['v'];
            $ins->execute([$oid, $v['product_id'], $v['id'], $v['p_cid'], $v['brand_id'], $v['p_name'], $v['b_name'], $v['size'], $v['color'], $l['price'], $l['qty']]);
        }
        $pdo->prepare('INSERT INTO order_events (order_id, status, note, actor, created) VALUES (?, ?, ?, ?, ?)')->execute([$oid, 'pending', '', 'client', $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $label = ($kind === 'reservation' ? 'Réservation ' : 'Commande ') . $ref;
    $about = [];
    foreach ($lines as $l) $about[(int) $l['v']['p_cid']][] = $l['v']['p_name'];
    log_activity((int) $store['company_id'], 'order', $label . ' reçue · ' . $store['name'], (int) $store['id'], 0, $now);
    foreach ($about as $cid => $names) {
        if ($cid !== (int) $store['company_id']) log_activity((int) $store['company_id'], 'order', $label . ' · ' . implode(', ', array_slice($names, 0, 3)) . ' · ' . $store['name'], (int) $store['id'], $cid, $now);
    }
    $s = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $s->execute([$oid]);
    return ['order' => $s->fetch(), 'token' => $token, 'store' => $store];
}

function order_items(int $oid): array
{
    $s = db()->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
    $s->execute([$oid]);
    return array_map(function ($i) {
        return [
            'product_id' => (int) $i['product_id'], 'variant_id' => (int) $i['variant_id'], 'product_cid' => (int) $i['product_cid'],
            'name' => (string) $i['name'], 'brand' => (string) $i['brand'], 'size' => (string) $i['size'], 'color' => (string) $i['color'],
            'price' => (float) $i['price'], 'qty' => (int) $i['qty'],
        ];
    }, $s->fetchAll());
}
function order_events(int $oid): array
{
    $s = db()->prepare('SELECT status, note, actor, created FROM order_events WHERE order_id = ? ORDER BY id');
    $s->execute([$oid]);
    return array_map(function ($e) {
        return ['status' => (string) $e['status'], 'note' => (string) $e['note'], 'actor' => (string) $e['actor'], 'created' => (int) $e['created']];
    }, $s->fetchAll());
}

/** Vue d'une commande. $full : avec les coordonnées du client (espace entreprise). */
function order_view(array $o, bool $full = false): array
{
    $s = db()->prepare('SELECT id, name, address, city, wilaya, phone, lat, lng, hours FROM stores WHERE id = ?');
    $s->execute([(int) $o['store_id']]);
    $st = $s->fetch() ?: [];
    $v = [
        'id' => (int) $o['id'], 'ref' => (string) $o['ref'], 'kind' => (string) $o['kind'], 'mode' => (string) $o['mode'],
        'status' => (string) $o['status'], 'total' => (float) $o['total'], 'created' => (int) $o['created'], 'updated' => (int) $o['updated'],
        'expires' => (int) $o['expires'], 'name' => (string) $o['cname'],
        'store' => $st ? [
            'id' => (int) $st['id'], 'name' => (string) $st['name'], 'address' => (string) $st['address'], 'city' => (string) $st['city'],
            'zone' => zone_name((string) $st['wilaya']), 'phone' => (string) $st['phone'], 'lat' => (float) $st['lat'], 'lng' => (float) $st['lng'],
            'hours' => hours_clean(jdec($st['hours'])),
        ] : null,
        'items' => order_items((int) $o['id']),
        'events' => order_events((int) $o['id']),
        'next' => order_next((string) $o['status'], (string) $o['mode']),
        'delivery' => $o['mode'] === 'delivery' ? ['address' => (string) $o['address'], 'city' => (string) $o['city'], 'zone' => zone_name((string) $o['wilaya'])] : null,
    ];
    if ($full) {
        $v['phone'] = (string) $o['cphone'];
        $v['email'] = (string) $o['cemail'];
        $v['note'] = (string) $o['note'];
        $v['seller_cid'] = (int) $o['seller_cid'];
    }
    return $v;
}

/**
 * Change le statut d'une commande. Annuler remet les articles en stock.
 * $actor : store | client | system. Retourne la ligne mise à jour.
 */
function order_set_status(array $o, string $new, string $note, string $actor, ?int $at = null): array
{
    $pdo = db();
    $now = $at ?? time();
    if (!in_array($new, order_next((string) $o['status'], (string) $o['mode']), true)) {
        throw new InvalidArgumentException('Ce changement de statut n\'est pas possible.');
    }
    $pdo->beginTransaction();
    try {
        // Garde contre deux changements simultanés : la mise à jour ne passe que depuis l'ancien statut.
        $u = $pdo->prepare('UPDATE orders SET status = ?, updated = ? WHERE id = ? AND status = ?');
        $u->execute([$new, $now, $o['id'], $o['status']]);
        if ($u->rowCount() !== 1) {
            $pdo->rollBack();
            throw new InvalidArgumentException('Cette commande vient d\'être modifiée. Rechargez la page.');
        }
        if ($new === 'cancelled') {
            $back = $pdo->prepare('UPDATE stock SET qty = qty + ?, updated = ? WHERE store_id = ? AND variant_id = ?');
            foreach (order_items((int) $o['id']) as $i) $back->execute([$i['qty'], $now, $o['store_id'], $i['variant_id']]);
        }
        $pdo->prepare('INSERT INTO order_events (order_id, status, note, actor, created) VALUES (?, ?, ?, ?, ?)')
            ->execute([$o['id'], $new, str_clean($note, 300), $actor, $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $label = ($o['kind'] === 'reservation' ? 'Réservation ' : 'Commande ') . $o['ref'];
    log_activity((int) $o['seller_cid'], 'status', $label . ' : ' . mb_strtolower(order_status_label($new, (string) $o['mode'], (string) $o['kind'])), (int) $o['store_id'], 0, $now);
    $o['status'] = $new;
    $o['updated'] = $now;
    return $o;
}

/** Libellé affiché au client (« Terminée » devient « Retirée » ou « Livrée »). */
function order_status_label(string $status, string $mode, string $kind): string
{
    if ($status === 'done') return $mode === 'delivery' ? 'Livrée' : 'Retirée';
    if ($status === 'ready' && $kind === 'reservation') return 'Mise de côté, prête au retrait';
    return ORDER_STATUSES[$status] ?? $status;
}

/** Réservations non retirées à temps : annulées, et les articles reviennent en stock. Au plus toutes les 10 minutes. */
function orders_expire(): void
{
    $last = (int) (meta_get('expire_run') ?? 0);
    if ($last > time() - 600) return;
    meta_set('expire_run', (string) time());
    $s = db()->prepare("SELECT * FROM orders WHERE kind = 'reservation' AND expires > 0 AND expires < ? AND status IN ('pending', 'confirmed', 'ready') LIMIT 100");
    $s->execute([time()]);
    foreach ($s->fetchAll() as $o) {
        try {
            order_set_status($o, 'cancelled', 'Réservation expirée : l\'article n\'a pas été retiré à temps.', 'system');
        } catch (Throwable $e) {
            error_log('maillage expire: ' . $e->getMessage());
        }
    }
}

/* ---------------------------------------------------------------- emails */

function order_track_url(string $token): string
{
    return site_url() . 'explorer/#/suivi/' . $token;
}

function order_mail_lines(array $view): array
{
    $lines = [];
    foreach ($view['items'] as $i) {
        $opt = trim($i['size'] . ' ' . $i['color']);
        $lines[] = $i['qty'] . ' × ' . $i['name'] . ($opt !== '' ? ' (' . $opt . ')' : '') . ' — ' . number_format($i['price'] * $i['qty'], 0, ',', ' ') . ' ' . MLG_CURRENCY;
    }
    return $lines;
}

/** Prévient le point de vente (email du magasin, sinon de l'entreprise) et confirme au client. */
function order_notify_created(array $res): void
{
    $o = $res['order'];
    $store = $res['store'];
    $view = order_view($o, true);
    $what = $o['kind'] === 'reservation' ? 'réservation' : 'commande';
    $items = implode("\n", order_mail_lines($view));
    $to = (string) ($store['email'] ?: $store['c_email']);
    if ($to !== '') {
        try_mail($to, 'Nouvelle ' . $what . ' ' . $o['ref'] . ' — ' . $store['name'], 'Nouvelle ' . $what . ' à traiter', [
            $store['name'] . ' · ' . ($o['mode'] === 'delivery' ? 'Livraison' : 'Retrait en magasin'),
            $items,
            'Client : ' . $o['cname'] . ' · ' . $o['cphone'],
            'Total : ' . number_format((float) $o['total'], 0, ',', ' ') . ' ' . MLG_CURRENCY,
        ], 'Traiter la ' . $what, site_url() . 'espace/#/commandes');
    }
    if ($o['cemail'] !== '') {
        try_mail((string) $o['cemail'], 'Votre ' . $what . ' ' . $o['ref'], 'Nous avons bien reçu votre ' . $what, [
            'Numéro : ' . $o['ref'],
            $items,
            'Point de vente : ' . $store['name'] . ', ' . $store['address'] . ' ' . $store['city'],
            $o['kind'] === 'reservation'
                ? 'Votre article est mis de côté pendant ' . (int) MLG_RESERVATION_HOURS . ' heures. Le magasin vous confirme la réservation.'
                : 'Le point de vente vous confirme la commande. Le paiement se fait ' . ($o['mode'] === 'delivery' ? 'à la livraison.' : 'au retrait.'),
        ], 'Suivre ma ' . $what, order_track_url($res['token']));
    }
}

/** Prévient le client d'un changement de statut (s'il a laissé son email). Le lien de suivi n'est pas renvoyé : seul son hash est gardé. */
function order_notify_status(array $o): void
{
    if ($o['cemail'] === '') return;
    $what = $o['kind'] === 'reservation' ? 'réservation' : 'commande';
    $label = order_status_label((string) $o['status'], (string) $o['mode'], (string) $o['kind']);
    try_mail((string) $o['cemail'], 'Votre ' . $what . ' ' . $o['ref'] . ' : ' . mb_strtolower($label), 'Votre ' . $what . ' : ' . mb_strtolower($label), [
        'Numéro : ' . $o['ref'],
        'Pour la suivre, ouvrez « Suivi » sur la plateforme et saisissez ce numéro avec votre téléphone.',
    ], 'Suivre ma ' . $what, site_url() . 'explorer/#/suivi');
}
