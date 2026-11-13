<?php
/**
 * Radar : une commande passée depuis la page d'un produit (ou sa page de vente), sans choisir de
 * point de vente, est proposée aux points de vente qui ont l'article en stock, du plus proche
 * du client au plus loin :
 *   1. ceux de sa commune, 2. ceux de sa wilaya, 3. tous les autres.
 * Elle démarre au premier niveau où un point de vente existe, puis s'élargit d'un niveau toutes
 * les « radar_step_min » minutes tant que personne ne l'a prise (0 = tout le monde tout de
 * suite). Le premier point de vente qui la prend la traite ; son stock baisse à ce moment-là.
 * Rien n'est calculé par une tâche planifiée : le niveau se déduit de l'heure de la commande.
 */
declare(strict_types=1);

const RADAR_MAX_OPEN = 300;

/** Délai (secondes) avant d'élargir une commande au niveau suivant. */
function radar_step(): int
{
    $min = setting('radar_step_min', (string) (int) MLG_RADAR_STEP_MIN);
    return max(0, min(1440, (int) $min)) * 60;
}

/** Proximité d'un point de vente pour une commande : 1 même commune, 2 même wilaya, 3 ailleurs. */
function radar_rank(array $store, string $zone, string $commune): int
{
    if ($zone === '' || (string) $store['wilaya'] !== $zone) return 3;
    return $commune !== '' && commune_key((string) $store['commune']) === commune_key($commune) ? 1 : 2;
}

/** Points de vente en ligne qui ont la déclinaison en quantité suffisante, avec leur proximité (rank). */
function radar_candidates(int $variantId, int $qty, string $zone, string $commune): array
{
    $s = db()->prepare("SELECT s.*, c.name AS c_name, c.email AS c_email, c.kind AS c_kind, c.demo AS c_demo, k.qty AS k_qty
        FROM stock k JOIN stores s ON s.id = k.store_id JOIN companies c ON c.id = s.company_id
        WHERE k.variant_id = ? AND k.qty >= ? AND s.status IN ('active', 'unlocated') AND c.status = 'active'");
    $s->execute([$variantId, max(1, $qty)]);
    $out = [];
    foreach ($s as $st) {
        $st['rank'] = radar_rank($st, $zone, $commune);
        $out[] = $st;
    }
    usort($out, function ($a, $b) { return $a['rank'] <=> $b['rank'] ?: $a['id'] <=> $b['id']; });
    return $out;
}

function radar_item(int $orderId): ?array
{
    $s = db()->prepare('SELECT i.*, p.images AS p_images, p.category AS p_category FROM order_items i LEFT JOIN products p ON p.id = i.product_id WHERE i.order_id = ? ORDER BY i.id LIMIT 1');
    $s->execute([$orderId]);
    return $s->fetch() ?: null;
}

/**
 * État d'une commande radar encore libre : candidats, niveau atteint (0 = aucun point de vente
 * n'a l'article), nombre de points de vente par niveau et heure du prochain élargissement.
 */
function radar_state(array $o, ?array $item = null, ?int $now = null): array
{
    $now = $now ?? time();
    $item = $item ?? radar_item((int) $o['id']);
    $cands = $item ? radar_candidates((int) $item['variant_id'], (int) $item['qty'], (string) $o['wilaya'], (string) $o['commune']) : [];
    $counts = [1 => 0, 2 => 0, 3 => 0];
    foreach ($cands as $c) $counts[$c['rank']]++;
    $base = $cands ? (int) $cands[0]['rank'] : 0;
    $step = radar_step();
    $level = 0;
    $next = 0;
    if ($base) {
        $level = $step > 0 ? min(3, $base + intdiv(max(0, $now - (int) $o['created']), $step)) : 3;
        if ($level < 3) $next = (int) $o['created'] + ($level - $base + 1) * $step;
    }
    return ['item' => $item, 'cands' => $cands, 'counts' => $counts, 'base' => $base, 'level' => $level, 'next_at' => $next];
}

/** Commandes radar que personne n'a encore prises (les plus récentes d'abord). */
function radar_open(): array
{
    return db()->query("SELECT * FROM orders WHERE radar = 1 AND store_id = 0 AND status = 'pending' ORDER BY created DESC, id DESC LIMIT " . RADAR_MAX_OPEN)->fetchAll();
}

function radar_item_view(?array $i): array
{
    if (!$i) return ['name' => '', 'brand' => '', 'size' => '', 'color' => '', 'qty' => 0, 'price' => 0.0, 'image' => '', 'category' => 'autre'];
    $imgs = jdec($i['p_images'] ?? '[]');
    return [
        'name' => (string) $i['name'], 'brand' => (string) $i['brand'], 'size' => (string) $i['size'], 'color' => (string) $i['color'],
        'qty' => (int) $i['qty'], 'price' => (float) $i['price'], 'image' => is_string($imgs[0] ?? null) ? $imgs[0] : '', 'category' => (string) ($i['p_category'] ?? 'autre'),
    ];
}

/**
 * Commandes radar visibles par les points de vente d'une entreprise (ou par un seul, $storeId) :
 * celles dont le niveau atteint leur proximité. Les coordonnées du client ne sont pas données
 * avant la prise en charge.
 */
function radar_nearby(int $cid, int $storeId = 0): array
{
    $out = [];
    $now = time();
    foreach (radar_open() as $o) {
        $st = radar_state($o, null, $now);
        if (!$st['level']) continue;
        $mine = [];
        foreach ($st['cands'] as $c) {
            if ((int) $c['company_id'] !== $cid || ($storeId && (int) $c['id'] !== $storeId) || $c['rank'] > $st['level']) continue;
            $mine[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'rank' => (int) $c['rank'], 'qty' => (int) $c['k_qty']];
        }
        if (!$mine) continue;
        $out[] = [
            'id' => (int) $o['id'], 'ref' => (string) $o['ref'], 'created' => (int) $o['created'], 'total' => (float) $o['total'],
            'wilaya' => (string) $o['wilaya'], 'zone' => zone_name((string) $o['wilaya']), 'commune' => (string) $o['commune'],
            'commune_ar' => commune_ar((string) $o['wilaya'], (string) $o['commune']),
            'rank' => $mine[0]['rank'], 'level' => $st['level'], 'next_at' => $st['next_at'],
            'item' => radar_item_view($st['item']), 'stores' => $mine, 'own' => (int) $o['brand_cid'] === $cid,
        ];
    }
    usort($out, function ($a, $b) { return $a['rank'] <=> $b['rank'] ?: $b['created'] <=> $a['created']; });
    return $out;
}

/** Nombre de commandes radar à proximité (pastille du menu). */
function radar_count(int $cid, int $storeId = 0): int
{
    return count(radar_nearby($cid, $storeId));
}

/** Commandes reçues par les pages d'une entreprise (sa marque) et ce qu'elles sont devenues. */
function radar_received(int $cid, int $limit = 120): array
{
    $s = db()->prepare("SELECT o.*, s.name AS s_name, s.city AS s_city, c.name AS sc_name FROM orders o
        LEFT JOIN stores s ON s.id = o.store_id LEFT JOIN companies c ON c.id = o.seller_cid
        WHERE o.radar = 1 AND o.brand_cid = ? ORDER BY o.created DESC, o.id DESC LIMIT " . max(1, min(300, $limit)));
    $s->execute([$cid]);
    $out = [];
    $now = time();
    foreach ($s as $o) {
        $waiting = !(int) $o['store_id'] && $o['status'] === 'pending';
        $item = radar_item((int) $o['id']);
        $v = [
            'id' => (int) $o['id'], 'ref' => (string) $o['ref'], 'created' => (int) $o['created'], 'status' => (string) $o['status'], 'mode' => (string) $o['mode'],
            'kind' => (string) $o['kind'], 'total' => (float) $o['total'], 'name' => (string) $o['cname'], 'phone' => (string) $o['cphone'],
            'wilaya' => (string) $o['wilaya'], 'zone' => zone_name((string) $o['wilaya']), 'commune' => (string) $o['commune'],
            'commune_ar' => commune_ar((string) $o['wilaya'], (string) $o['commune']),
            'item' => radar_item_view($item), 'waiting' => $waiting, 'claimed' => (int) $o['claimed'],
            'store' => (int) $o['store_id'] ? ['name' => (string) ($o['s_name'] ?? ''), 'city' => (string) ($o['s_city'] ?? ''), 'company' => (string) ($o['sc_name'] ?? ''), 'own' => (int) $o['seller_cid'] === $cid] : null,
        ];
        if ($waiting) {
            $st = radar_state($o, $item, $now);
            $v['level'] = $st['level'];
            $v['counts'] = $st['counts'];
            $v['next_at'] = $st['next_at'];
        }
        $out[] = $v;
    }
    return $out;
}

/**
 * Crée une commande radar. $b : variant_id, qty, name, phone, email, zone, commune, address
 * (facultative), note. Livraison, paiement à la réception. Retourne ['order', 'token', 'state'].
 */
function radar_create(array $b, ?int $at = null): array
{
    $pdo = db();
    $now = $at ?? time();
    $name = line_clean($b['name'] ?? '', 80);
    $phone = phone_clean($b['phone'] ?? '');
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    if (mb_strlen($name) < 2) throw new InvalidArgumentException('Indiquez votre nom.');
    if (!phone_ok($phone)) throw new InvalidArgumentException('Indiquez un numéro de téléphone valide.');
    if ($email !== '' && !valid_email($email)) throw new InvalidArgumentException('Adresse email invalide.');
    $place = delivery_place($b);
    $qty = max(1, min(10, (int) ($b['qty'] ?? 1)));

    $q = $pdo->prepare("SELECT v.*, p.name AS p_name, p.price AS p_price, p.company_id AS p_cid, p.brand_id, p.status AS p_status, b.name AS b_name, c.status AS c_status
        FROM variants v JOIN products p ON p.id = v.product_id JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id WHERE v.id = ?");
    $q->execute([(int) ($b['variant_id'] ?? 0)]);
    $v = $q->fetch();
    if (!$v || $v['p_status'] !== 'active' || $v['c_status'] !== 'active') throw new InvalidArgumentException('Cet article n\'est plus disponible.');
    $price = $v['price'] !== null ? (float) $v['price'] : (float) $v['p_price'];
    $cid = (int) $v['p_cid'];

    $token = token(20);
    tx_begin();
    try {
        $ref = order_ref();
        $pdo->prepare("INSERT INTO orders (ref, thash, kind, mode, store_id, seller_cid, status, total, cname, cphone, cemail, address, city, commune, wilaya, note, created, updated, expires, radar, brand_cid)
            VALUES (?, ?, 'order', 'delivery', 0, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)")->execute([
            $ref, hash('sha256', $token), $cid, $price * $qty, $name, $phone, $email, $place['address'], $place['commune'], $place['commune'], $place['zone'],
            str_clean($b['note'] ?? '', 500), $now, $now, $cid,
        ]);
        $oid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO order_items (order_id, product_id, variant_id, product_cid, brand_id, name, brand, size, color, price, qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$oid, $v['product_id'], $v['id'], $cid, $v['brand_id'], $v['p_name'], $v['b_name'], $v['size'], $v['color'], $price, $qty]);
        $pdo->prepare("INSERT INTO order_events (order_id, status, note, actor, created) VALUES (?, 'pending', '', 'client', ?)")->execute([$oid, $now]);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    log_activity($cid, 'radar', 'Commande ' . $ref . ' reçue par le radar · ' . $v['p_name'] . ' · ' . $place['commune'], 0, 0, $now);
    $s = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $s->execute([$oid]);
    $o = $s->fetch();
    return ['order' => $o, 'token' => $token, 'state' => radar_state($o, null, $now)];
}

/**
 * Un point de vente prend une commande radar : son stock baisse, la commande devient la sienne
 * (confirmée). Si un autre l'a prise entre-temps, InvalidArgumentException.
 */
function radar_claim(int $cid, int $orderId, int $storeId, ?int $at = null): array
{
    $pdo = db();
    $now = $at ?? time();
    $s = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND radar = 1");
    $s->execute([$orderId]);
    $o = $s->fetch();
    if (!$o) throw new InvalidArgumentException('Commande introuvable.');
    if ((int) $o['store_id'] || $o['status'] !== 'pending') throw new InvalidArgumentException('Cette commande vient d\'être prise par un autre point de vente.');
    $st = radar_state($o, null, $now);
    $store = null;
    foreach ($st['cands'] as $c) {
        if ((int) $c['id'] === $storeId && (int) $c['company_id'] === $cid && $c['rank'] <= $st['level']) { $store = $c; break; }
    }
    if (!$store) throw new InvalidArgumentException('Ce point de vente ne peut pas prendre cette commande : vérifiez qu\'il a l\'article en stock.');
    $item = $st['item'];
    tx_begin();
    try {
        $take = $pdo->prepare('UPDATE stock SET qty = qty - ?, updated = ? WHERE store_id = ? AND variant_id = ? AND qty >= ?');
        $take->execute([$item['qty'], $now, $storeId, $item['variant_id'], $item['qty']]);
        if ($take->rowCount() !== 1) {
            tx_rollback();
            throw new InvalidArgumentException('Stock insuffisant dans ce point de vente pour prendre la commande.');
        }
        $u = $pdo->prepare("UPDATE orders SET store_id = ?, seller_cid = ?, status = 'confirmed', claimed = ?, updated = ? WHERE id = ? AND radar = 1 AND store_id = 0 AND status = 'pending'");
        $u->execute([$storeId, $cid, $now, $now, $orderId]);
        if ($u->rowCount() !== 1) {
            tx_rollback();
            throw new InvalidArgumentException('Cette commande vient d\'être prise par un autre point de vente.');
        }
        $pdo->prepare("INSERT INTO order_events (order_id, status, note, actor, created) VALUES (?, 'confirmed', ?, 'store', ?)")
            ->execute([$orderId, 'Prise en charge par ' . $store['name'], $now]);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    log_activity($cid, 'radar', 'Commande ' . $o['ref'] . ' prise en charge · ' . $store['name'], $storeId, (int) $o['brand_cid'], $now);
    $s->execute([$orderId]);
    return ['order' => $s->fetch(), 'store' => $store];
}

/**
 * Le point de vente qui avait pris une commande radar la remet à disposition (avant expédition) :
 * son stock est rétabli et la commande repart vers les points de vente les plus proches.
 */
function radar_release(array $o, string $note, ?int $at = null): array
{
    $pdo = db();
    $now = $at ?? time();
    if (!(int) $o['radar'] || !(int) $o['store_id'] || $o['status'] !== 'confirmed') throw new InvalidArgumentException('Cette commande ne peut plus être remise sur le radar.');
    $storeName = (string) ($pdo->query('SELECT name FROM stores WHERE id = ' . (int) $o['store_id'])->fetchColumn() ?: '');
    tx_begin();
    try {
        $u = $pdo->prepare("UPDATE orders SET store_id = 0, seller_cid = brand_cid, status = 'pending', claimed = 0, updated = ? WHERE id = ? AND status = 'confirmed' AND store_id = ?");
        $u->execute([$now, $o['id'], $o['store_id']]);
        if ($u->rowCount() !== 1) {
            tx_rollback();
            throw new InvalidArgumentException('Cette commande vient d\'être modifiée. Rechargez la page.');
        }
        $back = $pdo->prepare('UPDATE stock SET qty = qty + ?, updated = ? WHERE store_id = ? AND variant_id = ?');
        foreach (order_items((int) $o['id']) as $i) $back->execute([$i['qty'], $now, $o['store_id'], $i['variant_id']]);
        $pdo->prepare("INSERT INTO order_events (order_id, status, note, actor, created) VALUES (?, 'pending', ?, 'store', ?)")
            ->execute([$o['id'], trim('Remise sur le radar par ' . $storeName . ($note !== '' ? ' — ' . str_clean($note, 200) : '')), $now]);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    log_activity((int) $o['seller_cid'], 'radar', 'Commande ' . $o['ref'] . ' remise sur le radar · ' . $storeName, (int) $o['store_id'], (int) $o['brand_cid'], $now);
    $s = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $s->execute([$o['id']]);
    return $s->fetch();
}

/* ---------------------------------------------------------------- emails */

/** Email d'un point de vente : le sien, sinon celui de son compte, sinon celui de l'entreprise. */
function store_contact(array $store): string
{
    if ((string) $store['email'] !== '') return (string) $store['email'];
    $s = db()->prepare('SELECT email FROM users WHERE store_id = ? ORDER BY id LIMIT 1');
    $s->execute([(int) $store['id']]);
    $e = (string) ($s->fetchColumn() ?: '');
    return $e !== '' ? $e : (string) ($store['c_email'] ?? '');
}

/** Prévient les points de vente du premier niveau, l'entreprise qui a reçu la commande, et confirme au client. */
function radar_notify_created(array $res): void
{
    $o = $res['order'];
    $st = $res['state'];
    $item = radar_item_view($st['item']);
    $opt = trim($item['size'] . ' ' . $item['color']);
    $line = $item['qty'] . ' × ' . $item['name'] . ($opt !== '' ? ' (' . $opt . ')' : '') . ' — ' . number_format((float) $o['total'], 0, ',', ' ') . ' ' . MLG_CURRENCY;
    $where = $o['commune'] . ', ' . zone_name((string) $o['wilaya']);
    $sent = [];
    $n = 0;
    foreach ($st['cands'] as $c) {
        if ($c['rank'] > $st['level'] || (int) $c['c_demo'] || $n >= 15) continue;
        $to = store_contact($c);
        if ($to === '' || isset($sent[$to])) continue;
        $sent[$to] = true;
        $n++;
        try_mail($to, 'Commande à proximité ' . $o['ref'] . ' — ' . $c['name'], 'Une commande près de chez vous', [
            $line,
            'Livraison : ' . $where . ($c['rank'] === 1 ? ' (votre commune)' : ($c['rank'] === 2 ? ' (votre wilaya)' : '')),
            'Le premier point de vente qui la prend la traite. Les coordonnées du client s\'affichent dès la prise en charge.',
        ], 'Ouvrir le radar', site_url() . 'espace/#/radar');
    }
    $co = company_row((int) $o['brand_cid']);
    if ($co && !(int) $co['demo'] && (string) $co['email'] !== '' && !isset($sent[(string) $co['email']])) {
        try_mail((string) $co['email'], 'Nouvelle commande radar ' . $o['ref'], 'Nouvelle commande reçue', [
            $line,
            'Client : ' . $o['cname'] . ' · ' . $o['cphone'] . ' · ' . $where,
            $st['level'] ? 'Elle est proposée aux points de vente les plus proches qui ont l\'article en stock.' : 'Aucun point de vente n\'a cet article en stock pour le moment : mettez la disponibilité à jour ou traitez-la directement.',
        ], 'Suivre la commande', site_url() . 'espace/#/radar');
    }
    if ($o['cemail'] !== '') {
        try_mail((string) $o['cemail'], 'Votre commande ' . $o['ref'], 'Nous avons bien reçu votre commande', [
            'Numéro : ' . $o['ref'],
            $line,
            'Le point de vente le plus proche de chez vous vous appelle pour confirmer la livraison. Vous payez à la réception.',
        ], 'Suivre ma commande', order_track_url($res['token']));
    }
}

function radar_notify_claimed(array $res): void
{
    $o = $res['order'];
    $store = $res['store'];
    if ($o['cemail'] !== '') {
        try_mail((string) $o['cemail'], 'Votre commande ' . $o['ref'] . ' est prise en charge', 'Votre commande est prise en charge', [
            'Numéro : ' . $o['ref'],
            $store['name'] . ' prépare votre commande et vous appelle pour la livraison.' . ((string) $store['phone'] !== '' ? ' Téléphone : ' . $store['phone'] : ''),
        ], 'Suivre ma commande', site_url() . 'explorer/#/suivi');
    }
    $co = company_row((int) $o['brand_cid']);
    if ($co && (int) $co['id'] !== (int) $o['seller_cid'] && !(int) $co['demo'] && (string) $co['email'] !== '') {
        try_mail((string) $co['email'], 'Commande ' . $o['ref'] . ' prise en charge par ' . $store['name'], 'Commande prise en charge', [
            $store['name'] . ' (' . $store['c_name'] . ') a pris la commande ' . $o['ref'] . '.',
        ], 'Voir la commande', site_url() . 'espace/#/radar');
    }
}
