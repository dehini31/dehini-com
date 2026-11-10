<?php
/**
 * Analyses commerciales d'une entreprise sur une période : recherches et commandes, produits
 * et zones les plus demandés, performance des points de vente et des distributeurs,
 * opportunités d'expansion (zones où l'on cherche ses produits sans point de vente proche).
 *
 * Périmètre :
 *  - commandes : celles passées dans ses points de vente + les lignes de ses produits vendues
 *    ailleurs (chez un distributeur) ;
 *  - recherches : celles qui ont porté sur ses produits (pour un distributeur : sur les
 *    produits des marques qu'il distribue également).
 */
declare(strict_types=1);

function analytics(int $cid, string $kind, int $days): array
{
    $pdo = db();
    $days = in_array($days, [7, 30, 90], true) ? $days : 30;
    $today = strtotime('today');
    $from = $today - ($days - 1) * 86400;
    $prev = $from - $days * 86400;
    $dayKeys = [];
    for ($i = 0; $i < $days; $i++) $dayKeys[] = date('Y-m-d', $from + $i * 86400);
    $zero = array_fill_keys($dayKeys, 0);

    $scope = array_merge([$cid], $kind === 'distributor' ? partner_cids($cid, $kind) : []);
    $scopeIn = implode(',', $scope);

    /* --- points de vente du réseau ------------------------------------------- */
    $stores = [];
    foreach (network_stores($cid, $kind) as $s) {
        $stores[(int) $s['id']] = [
            'id' => (int) $s['id'], 'name' => (string) $s['name'], 'city' => (string) $s['city'], 'wilaya' => (string) $s['wilaya'],
            'lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'company' => (string) $s['c_name'], 'company_id' => (int) $s['company_id'],
            'own' => (int) $s['company_id'] === $cid, 'type' => (string) $s['type'],
            'orders' => 0, 'revenue' => 0.0, 'done' => 0, 'cancelled' => 0, 'confirm_sum' => 0, 'confirm_n' => 0,
            'refs' => 0, 'instock' => 0, 'views' => 0, 'contacts' => 0,
        ];
    }
    $storeZone = [];
    foreach ($pdo->query('SELECT id, wilaya FROM stores') as $r) $storeZone[(int) $r['id']] = (string) $r['wilaya'];

    /* --- commandes ----------------------------------------------------------- */
    $s = $pdo->prepare("SELECT o.id, o.status, o.kind, o.mode, o.store_id, o.seller_cid, o.wilaya, o.created,
            i.product_id, i.name, i.brand, i.size, i.color, i.price, i.qty
        FROM orders o JOIN order_items i ON i.order_id = o.id
        WHERE o.created >= ? AND (o.seller_cid = ? OR i.product_cid = ?)");
    $s->execute([$prev, $cid, $cid]);
    $orders = [];       // commande → infos + montant du périmètre
    $prodAgg = [];
    foreach ($s as $r) {
        $oid = (int) $r['id'];
        if (!isset($orders[$oid])) {
            $orders[$oid] = ['status' => $r['status'], 'kind' => $r['kind'], 'mode' => $r['mode'], 'store' => (int) $r['store_id'],
                'zone' => $r['mode'] === 'delivery' && $r['wilaya'] !== '' ? (string) $r['wilaya'] : ($storeZone[(int) $r['store_id']] ?? ''),
                'created' => (int) $r['created'], 'amount' => 0.0];
        }
        $amount = (float) $r['price'] * (int) $r['qty'];
        $orders[$oid]['amount'] += $amount;
        if ((int) $r['created'] >= $from && $r['status'] !== 'cancelled') {
            $pid = (int) $r['product_id'];
            if (!isset($prodAgg[$pid])) $prodAgg[$pid] = ['id' => $pid, 'name' => (string) $r['name'], 'brand' => (string) $r['brand'], 'qty' => 0, 'revenue' => 0.0, 'searches' => 0, 'views' => 0];
            $prodAgg[$pid]['qty'] += (int) $r['qty'];
            $prodAgg[$pid]['revenue'] += $amount;
        }
    }

    $serOrders = $zero;
    $serRevenue = $zero;
    $cur = ['orders' => 0, 'revenue' => 0.0, 'paid' => 0];
    $old = ['orders' => 0, 'revenue' => 0.0, 'paid' => 0];
    $statusCount = array_fill_keys(array_keys(ORDER_STATUSES), 0);
    $modeCount = ['pickup' => 0, 'delivery' => 0, 'reservation' => 0];
    $zoneAgg = [];
    $zoneInit = function (string $code) use (&$zoneAgg): void {
        if ($code !== '' && !isset($zoneAgg[$code])) $zoneAgg[$code] = ['searches' => 0, 'orders' => 0, 'revenue' => 0.0, 'products' => []];
    };
    $curIds = [];
    foreach ($orders as $oid => $o) {
        $live = $o['status'] !== 'cancelled';
        if ($o['created'] < $from) {
            $old['orders']++;
            if ($live) { $old['revenue'] += $o['amount']; $old['paid']++; }
            continue;
        }
        $curIds[] = $oid;
        $cur['orders']++;
        $d = date('Y-m-d', $o['created']);
        if (isset($serOrders[$d])) $serOrders[$d]++;
        $statusCount[$o['status']] = ($statusCount[$o['status']] ?? 0) + 1;
        $modeCount[$o['kind'] === 'reservation' ? 'reservation' : $o['mode']]++;
        if ($live) {
            $cur['revenue'] += $o['amount'];
            $cur['paid']++;
            if (isset($serRevenue[$d])) $serRevenue[$d] += $o['amount'];
        }
        if ($o['zone'] !== '') {
            $zoneInit($o['zone']);
            $zoneAgg[$o['zone']]['orders']++;
            if ($live) $zoneAgg[$o['zone']]['revenue'] += $o['amount'];
        }
        if (isset($stores[$o['store']])) {
            $st = &$stores[$o['store']];
            $st['orders']++;
            if ($live) $st['revenue'] += $o['amount'];
            if ($o['status'] === 'done') $st['done']++;
            if ($o['status'] === 'cancelled') $st['cancelled']++;
            unset($st);
        }
    }
    // Délai de confirmation : entre la commande et sa première confirmation.
    foreach (array_chunk($curIds, 400) as $chunk) {
        $q = $pdo->query("SELECT order_id, MIN(created) AS t FROM order_events WHERE status = 'confirmed' AND order_id IN (" . implode(',', $chunk) . ') GROUP BY order_id');
        foreach ($q as $r) {
            $o = $orders[(int) $r['order_id']];
            if (isset($stores[$o['store']])) {
                $stores[$o['store']]['confirm_sum'] += max(0, (int) $r['t'] - $o['created']);
                $stores[$o['store']]['confirm_n']++;
            }
        }
    }

    /* --- recherches ---------------------------------------------------------- */
    $productNames = [];
    foreach ($pdo->query("SELECT id, name FROM products WHERE company_id IN ($scopeIn)") as $r) $productNames[(int) $r['id']] = (string) $r['name'];

    $s = $pdo->prepare("SELECT s.* FROM searches s WHERE s.created >= ?
        AND EXISTS (SELECT 1 FROM search_hits h WHERE h.search_id = s.id AND h.company_id IN ($scopeIn))");
    $s->execute([$prev]);
    $serSearch = $zero;
    $curS = ['n' => 0, 'none' => 0];
    $oldS = ['n' => 0, 'none' => 0];
    $terms = [];
    $missing = [];
    $sizes = [];
    $colors = [];
    $located = [];   // recherches localisées de la période : [lat, lng, zone, product_id]
    $missAdd = function (string $label, string $where) use (&$missing): void {
        $k = norm($label) . '|' . $where;
        if (!isset($missing[$k])) $missing[$k] = ['label' => $label, 'scope' => $where, 'n' => 0];
        $missing[$k]['n']++;
    };
    foreach ($s as $r) {
        if ((int) $r['created'] < $from) {
            $oldS['n']++;
            if ((int) $r['results'] === 0) $oldS['none']++;
            continue;
        }
        $curS['n']++;
        $d = date('Y-m-d', (int) $r['created']);
        if (isset($serSearch[$d])) $serSearch[$d]++;
        $pid = (int) $r['product_id'];
        $pname = $productNames[$pid] ?? '';
        if ($r['q'] !== '') {
            if (!isset($terms[$r['q']])) $terms[$r['q']] = ['q' => (string) $r['q'], 'n' => 0, 'results' => 0];
            $terms[$r['q']]['n']++;
            $terms[$r['q']]['results'] += (int) $r['results'];
        }
        if ($r['size'] !== '') { $k = norm($r['size']); $sizes[$k] = ['label' => (string) $r['size'], 'n' => ($sizes[$k]['n'] ?? 0) + 1]; }
        if ($r['color'] !== '') { $k = norm($r['color']); $colors[$k] = ['label' => (string) $r['color'], 'n' => ($colors[$k]['n'] ?? 0) + 1]; }
        if ((int) $r['results'] === 0) {
            $curS['none']++;
            $label = $r['q'] !== '' ? (string) $r['q'] : $pname;
            $opt = trim($r['size'] . ' ' . $r['color']);
            if ($label !== '') $missAdd($label . ($opt !== '' && $r['q'] === '' ? ' · ' . $opt : ''), 'own');
        }
        if ($pid && isset($prodAgg[$pid])) $prodAgg[$pid]['searches']++;
        elseif ($pid && $pname !== '') $prodAgg[$pid] = ['id' => $pid, 'name' => $pname, 'brand' => '', 'qty' => 0, 'revenue' => 0.0, 'searches' => 1, 'views' => 0];
        $zone = (string) $r['wilaya'];
        if ($zone !== '') {
            $zoneInit($zone);
            $zoneAgg[$zone]['searches']++;
            if ($pname !== '') $zoneAgg[$zone]['products'][$pname] = ($zoneAgg[$zone]['products'][$pname] ?? 0) + 1;
            $z = zones()[$zone] ?? null;
            $located[] = [$r['lat'] !== null ? (float) $r['lat'] : (float) ($z['lat'] ?? 0), $r['lng'] !== null ? (float) $r['lng'] : (float) ($z['lng'] ?? 0), $zone];
        }
    }
    // Demandes restées sans réponse sur toute la plateforme (aucune entreprise concernée).
    $s = $pdo->prepare("SELECT s.q, COUNT(*) AS n FROM searches s JOIN search_hits h ON h.search_id = s.id AND h.company_id = 0
        WHERE s.created >= ? AND s.results = 0 AND s.q <> '' GROUP BY s.q ORDER BY n DESC LIMIT 12");
    $s->execute([$from]);
    foreach ($s as $r) {
        $k = norm($r['q']) . '|platform';
        $missing[$k] = ['label' => (string) $r['q'], 'scope' => 'platform', 'n' => (int) $r['n']];
    }

    /* --- fiches consultées ---------------------------------------------------- */
    $fromDay = date('Y-m-d', $from);
    if ($productNames) {
        $q = $pdo->prepare("SELECT ref_id, SUM(n) AS n FROM views WHERE kind = 'product' AND day >= ? GROUP BY ref_id");
        $q->execute([$fromDay]);
        foreach ($q as $r) {
            $pid = (int) $r['ref_id'];
            if (!isset($productNames[$pid])) continue;
            if (!isset($prodAgg[$pid])) $prodAgg[$pid] = ['id' => $pid, 'name' => $productNames[$pid], 'brand' => '', 'qty' => 0, 'revenue' => 0.0, 'searches' => 0, 'views' => 0];
            $prodAgg[$pid]['views'] += (int) $r['n'];
        }
    }
    if ($stores) {
        $q = $pdo->prepare("SELECT kind, ref_id, SUM(n) AS n FROM views WHERE kind IN ('store','call','route') AND day >= ? GROUP BY kind, ref_id");
        $q->execute([$fromDay]);
        foreach ($q as $r) {
            $sid = (int) $r['ref_id'];
            if (!isset($stores[$sid])) continue;
            if ($r['kind'] === 'store') $stores[$sid]['views'] += (int) $r['n'];
            else $stores[$sid]['contacts'] += (int) $r['n'];
        }
        // Taux de disponibilité : déclinaisons en stock / déclinaisons référencées (les produits du périmètre).
        $in = implode(',', array_keys($stores));
        $q = $pdo->query("SELECT k.store_id AS sid, COUNT(*) AS refs, SUM(CASE WHEN k.qty > 0 THEN 1 ELSE 0 END) AS instock
            FROM stock k JOIN variants v ON v.id = k.variant_id JOIN products p ON p.id = v.product_id
            WHERE k.store_id IN ($in) AND p.company_id IN ($scopeIn) GROUP BY k.store_id");
        foreach ($q as $r) {
            $stores[(int) $r['sid']]['refs'] = (int) $r['refs'];
            $stores[(int) $r['sid']]['instock'] = (int) $r['instock'];
        }
    }

    /* --- zones et expansion --------------------------------------------------- */
    $nearest = function (float $lat, float $lng) use ($stores): array {
        $best = null;
        $bd = null;
        foreach ($stores as $st) {
            $d = haversine($lat, $lng, $st['lat'], $st['lng']);
            if ($bd === null || $d < $bd) { $bd = $d; $best = $st['name']; }
        }
        return [$bd, $best];
    };
    $storesByZone = [];
    foreach ($stores as $st) $storesByZone[$st['wilaya']] = ($storesByZone[$st['wilaya']] ?? 0) + 1;
    foreach (array_keys($storesByZone) as $code) $zoneInit((string) $code);
    $cover = (float) MLG_COVER_KM;
    $covered = 0;
    foreach ($located as $l) {
        [$d] = $nearest($l[0], $l[1]);
        if ($d !== null && $d <= $cover) $covered++;
    }
    $zonesOut = [];
    $expansion = [];
    foreach ($zoneAgg as $code => $z) {
        $info = zones()[$code] ?? null;
        if (!$info) continue;
        [$km, $near] = $nearest((float) $info['lat'], (float) $info['lng']);
        $n = $storesByZone[$code] ?? 0;
        arsort($z['products']);
        $top = (string) (array_key_first($z['products']) ?? '');
        $row = [
            'code' => (string) $code, 'name' => (string) $info['name'], 'lat' => (float) $info['lat'], 'lng' => (float) $info['lng'],
            'searches' => $z['searches'], 'orders' => $z['orders'], 'revenue' => round($z['revenue'], 2), 'stores' => $n,
            'nearest_km' => $km !== null ? round($km) : null, 'nearest' => $near, 'top' => $top,
        ];
        $zonesOut[] = $row;
        if ($n === 0 && $z['searches'] > 0 && ($km === null || $km > $cover)) $expansion[] = $row;
    }
    usort($zonesOut, function ($a, $b) { return ($b['searches'] + $b['orders']) <=> ($a['searches'] + $a['orders']) ?: strcmp($a['name'], $b['name']); });
    usort($expansion, function ($a, $b) { return $b['searches'] <=> $a['searches'] ?: ($b['nearest_km'] ?? 0) <=> ($a['nearest_km'] ?? 0); });

    /* --- mise en forme -------------------------------------------------------- */
    $storesOut = [];
    foreach ($stores as $st) {
        $storesOut[] = [
            'id' => $st['id'], 'name' => $st['name'], 'city' => $st['city'], 'zone' => zone_name($st['wilaya']), 'company' => $st['company'],
            'company_id' => $st['company_id'], 'own' => $st['own'], 'lat' => $st['lat'], 'lng' => $st['lng'],
            'orders' => $st['orders'], 'revenue' => round($st['revenue'], 2),
            'done_rate' => $st['orders'] ? round($st['done'] / $st['orders'] * 100) : null,
            'cancel_rate' => $st['orders'] ? round($st['cancelled'] / $st['orders'] * 100) : null,
            'confirm_h' => $st['confirm_n'] ? round($st['confirm_sum'] / $st['confirm_n'] / 3600, 1) : null,
            'availability' => $st['refs'] ? round($st['instock'] / $st['refs'] * 100) : null,
            'refs' => $st['refs'], 'views' => $st['views'], 'contacts' => $st['contacts'],
        ];
    }
    usort($storesOut, function ($a, $b) { return $b['revenue'] <=> $a['revenue'] ?: $b['orders'] <=> $a['orders'] ?: strcmp($a['name'], $b['name']); });

    $partners = [];
    foreach ($stores as $st) {
        $k = $st['company_id'];
        if (!isset($partners[$k])) $partners[$k] = ['id' => $k, 'name' => $st['company'], 'own' => $st['own'], 'stores' => 0, 'orders' => 0, 'revenue' => 0.0, 'refs' => 0, 'instock' => 0, 'done' => 0, 'cancelled' => 0];
        $partners[$k]['stores']++;
        foreach (['orders', 'revenue', 'refs', 'instock', 'done', 'cancelled'] as $f) $partners[$k][$f] += $st[$f];
    }
    $partnersOut = [];
    foreach ($partners as $p) {
        $partnersOut[] = [
            'id' => $p['id'], 'name' => $p['name'], 'own' => $p['own'], 'stores' => $p['stores'], 'orders' => $p['orders'], 'revenue' => round($p['revenue'], 2),
            'availability' => $p['refs'] ? round($p['instock'] / $p['refs'] * 100) : null,
            'done_rate' => $p['orders'] ? round($p['done'] / $p['orders'] * 100) : null,
        ];
    }
    usort($partnersOut, function ($a, $b) { return $b['revenue'] <=> $a['revenue'] ?: strcmp($a['name'], $b['name']); });

    $products = array_values($prodAgg);
    usort($products, function ($a, $b) { return ($b['qty'] * 3 + $b['searches']) <=> ($a['qty'] * 3 + $a['searches']) ?: strcmp($a['name'], $b['name']); });
    foreach ($products as &$p) $p['revenue'] = round($p['revenue'], 2);
    unset($p);

    $termsOut = array_values($terms);
    usort($termsOut, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['q'], $b['q']); });
    $termsOut = array_map(function ($t) { return ['q' => $t['q'], 'n' => $t['n'], 'results' => round($t['results'] / max(1, $t['n']), 1)]; }, array_slice($termsOut, 0, 12));
    $missOut = array_values($missing);
    usort($missOut, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['label'], $b['label']); });
    $rank = function (array $a): array {
        $a = array_values($a);
        usort($a, function ($x, $y) { return $y['n'] <=> $x['n'] ?: strnatcasecmp($x['label'], $y['label']); });
        return array_slice($a, 0, 10);
    };

    $basket = function (array $p): ?float { return $p['paid'] ? round($p['revenue'] / $p['paid'], 2) : null; };
    $pct = function (array $p): ?float { return $p['n'] ? round($p['none'] / $p['n'] * 100, 1) : null; };
    return [
        'days' => $days, 'from' => $from, 'to' => $today + 86399, 'cover_km' => $cover, 'kind' => $kind,
        'tiles' => [
            'searches' => ['v' => $curS['n'], 'prev' => $oldS['n']],
            'orders' => ['v' => $cur['orders'], 'prev' => $old['orders']],
            'revenue' => ['v' => round($cur['revenue'], 2), 'prev' => round($old['revenue'], 2)],
            'basket' => ['v' => $basket($cur), 'prev' => $basket($old)],
            'noresult' => ['v' => $pct($curS), 'prev' => $pct($oldS)],
        ],
        'series' => ['days' => $dayKeys, 'searches' => array_values($serSearch), 'orders' => array_values($serOrders), 'revenue' => array_map(function ($v) { return round($v, 2); }, array_values($serRevenue))],
        'terms' => $termsOut,
        'missing' => array_slice($missOut, 0, 12),
        'status' => $statusCount,
        'modes' => $modeCount,
        'products' => array_slice($products, 0, 12),
        'sizes' => $rank($sizes),
        'colors' => $rank($colors),
        'zones' => array_slice($zonesOut, 0, 58),
        'stores' => $storesOut,
        'partners' => $partnersOut,
        'expansion' => array_slice($expansion, 0, 12),
        'coverage' => ['located' => count($located), 'covered' => $covered, 'share' => $located ? round($covered / count($located) * 100) : null],
    ];
}
