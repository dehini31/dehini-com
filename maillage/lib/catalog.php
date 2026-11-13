<?php
/**
 * Côté public de Maillage : points de vente, marques, catalogue, disponibilité d'un produit,
 * et enregistrement des recherches (la matière des analyses commerciales).
 * Seules les entreprises validées (status = active) apparaissent ici.
 */
declare(strict_types=1);

const CATALOG_PER_PAGE = 24;

function jdec(?string $s): array
{
    $d = json_decode((string) $s, true);
    return is_array($d) ? $d : [];
}

/** Horaires : {open:"09:00", close:"19:00", days:[0..6]} (0 = dimanche). */
function hours_clean($h): array
{
    $h = is_array($h) ? $h : [];
    $t = function ($v, string $def): string {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $def;
    };
    $days = [];
    foreach ((array) ($h['days'] ?? []) as $d) {
        if (is_numeric($d) && (int) $d >= 0 && (int) $d <= 6) $days[(int) $d] = true;
    }
    ksort($days);
    return ['open' => $t($h['open'] ?? null, '09:00'), 'close' => $t($h['close'] ?? null, '19:00'), 'days' => array_keys($days)];
}

function store_view(array $s, array $brandIds = []): array
{
    return [
        'id' => (int) $s['id'], 'name' => (string) $s['name'], 'type' => (string) $s['type'],
        'address' => (string) $s['address'], 'city' => (string) $s['city'], 'wilaya' => (string) $s['wilaya'],
        'commune' => (string) ($s['commune'] ?? ''), 'commune_ar' => commune_ar((string) $s['wilaya'], (string) ($s['commune'] ?? '')),
        'zone' => zone_name((string) $s['wilaya']),
        'lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'phone' => (string) $s['phone'], 'email' => (string) $s['email'],
        'hours' => hours_clean(jdec($s['hours'])),
        'pickup' => (bool) $s['pickup'], 'delivery' => (bool) $s['delivery'], 'reserve' => (bool) $s['reserve'],
        'status' => (string) $s['status'],
        'company' => ['id' => (int) $s['company_id'], 'name' => (string) ($s['c_name'] ?? ''), 'kind' => (string) ($s['c_kind'] ?? ''), 'demo' => (bool) ($s['c_demo'] ?? 0)],
        'brands' => array_values(array_map('intval', $brandIds)),
    ];
}

/** Marques présentes dans chaque point de vente : celles de son entreprise + celles dont il référence des produits. */
function store_brand_map(): array
{
    $map = [];
    $sql = "SELECT DISTINCT k.store_id AS sid, p.brand_id AS bid
            FROM stock k JOIN variants v ON v.id = k.variant_id
            JOIN products p ON p.id = v.product_id AND p.status = 'active'
            JOIN companies pc ON pc.id = p.company_id AND pc.status = 'active'
            UNION
            SELECT s.id AS sid, b.id AS bid FROM stores s JOIN brands b ON b.company_id = s.company_id";
    foreach (db()->query($sql) as $r) $map[(int) $r['sid']][] = (int) $r['bid'];
    return $map;
}

function public_stores(): array
{
    $map = store_brand_map();
    $out = [];
    $q = db()->query("SELECT s.*, c.name AS c_name, c.kind AS c_kind, c.demo AS c_demo
        FROM stores s JOIN companies c ON c.id = s.company_id
        WHERE s.status = 'active' AND c.status = 'active' ORDER BY s.name");
    foreach ($q as $s) $out[] = store_view($s, $map[(int) $s['id']] ?? []);
    return $out;
}

function public_store(int $id): ?array
{
    $s = db()->prepare("SELECT s.*, c.name AS c_name, c.kind AS c_kind, c.demo AS c_demo
        FROM stores s JOIN companies c ON c.id = s.company_id
        WHERE s.id = ? AND s.status = 'active' AND c.status = 'active'");
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return null;
    $map = store_brand_map();
    return store_view($r, $map[$id] ?? []);
}

function brand_view(array $b, int $nStores = 0): array
{
    return [
        'id' => (int) $b['id'], 'name' => (string) $b['name'], 'slug' => (string) $b['slug'], 'tagline' => (string) $b['tagline'],
        'about' => (string) $b['about'], 'logo' => (string) $b['logo'], 'color' => (string) $b['color'], 'category' => (string) $b['category'],
        'company' => ['id' => (int) $b['company_id'], 'name' => (string) ($b['c_name'] ?? ''), 'demo' => (bool) ($b['c_demo'] ?? 0)],
        'products' => (int) ($b['n_products'] ?? 0), 'stores' => $nStores,
    ];
}

function public_brands(): array
{
    $count = [];
    $visible = [];
    foreach (db()->query("SELECT s.id FROM stores s JOIN companies c ON c.id = s.company_id WHERE s.status = 'active' AND c.status = 'active'") as $r) $visible[(int) $r['id']] = true;
    foreach (store_brand_map() as $sid => $bids) {
        if (!isset($visible[$sid])) continue;
        foreach ($bids as $bid) $count[$bid] = ($count[$bid] ?? 0) + 1;
    }
    $out = [];
    $q = db()->query("SELECT b.*, c.name AS c_name, c.demo AS c_demo,
            (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status = 'active') AS n_products
        FROM brands b JOIN companies c ON c.id = b.company_id WHERE c.status = 'active' ORDER BY b.name COLLATE NOCASE");
    foreach ($q as $b) $out[] = brand_view($b, $count[(int) $b['id']] ?? 0);
    return $out;
}

/* ---------------------------------------------------------------- produits */

/** Texte de recherche d'un produit (minuscules sans accents). */
function product_search_text(array $p, string $brandName, array $variants): string
{
    $parts = [$p['name'], $brandName, MLG_CATEGORIES[$p['category']] ?? '', $p['about']];
    foreach (jdec($p['specs'] ?? '[]') as $s) { $parts[] = ($s['k'] ?? '') . ' ' . ($s['v'] ?? ''); }
    foreach ($variants as $v) { $parts[] = ($v['size'] ?? '') . ' ' . ($v['color'] ?? '') . ' ' . ($v['sku'] ?? ''); }
    return mb_substr(norm(implode(' ', $parts)), 0, 4000);
}

function variants_of(array $productIds): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), 400) as $chunk) {
        $in = implode(',', $chunk);
        if ($in === '') continue;
        foreach (db()->query("SELECT * FROM variants WHERE product_id IN ($in) ORDER BY product_id, pos, id") as $v) $out[(int) $v['product_id']][] = $v;
    }
    return $out;
}

/** Nombre de points de vente visibles où chaque produit est en stock. */
function product_store_counts(): array
{
    $out = [];
    $sql = "SELECT v.product_id AS pid, COUNT(DISTINCT k.store_id) AS n
            FROM stock k JOIN variants v ON v.id = k.variant_id
            JOIN stores s ON s.id = k.store_id AND s.status = 'active'
            JOIN companies c ON c.id = s.company_id AND c.status = 'active'
            WHERE k.qty > 0 GROUP BY v.product_id";
    foreach (db()->query($sql) as $r) $out[(int) $r['pid']] = (int) $r['n'];
    return $out;
}

function variant_view(array $v, float $basePrice): array
{
    return [
        'id' => (int) $v['id'], 'sku' => (string) $v['sku'], 'size' => (string) $v['size'], 'color' => (string) $v['color'],
        'hex' => (string) $v['color_hex'], 'price' => $v['price'] !== null ? (float) $v['price'] : $basePrice,
    ];
}

function product_card(array $p, array $variants, int $nStores): array
{
    $base = (float) $p['price'];
    $prices = [];
    $colors = [];
    $sizes = [];
    foreach ($variants as $v) {
        $prices[] = $v['price'] !== null ? (float) $v['price'] : $base;
        if ($v['color'] !== '') $colors[norm($v['color'])] = ['name' => (string) $v['color'], 'hex' => (string) $v['color_hex']];
        if ($v['size'] !== '') $sizes[norm($v['size'])] = (string) $v['size'];
    }
    if (!$prices) $prices = [$base];
    $imgs = jdec($p['images']);
    return [
        'id' => (int) $p['id'], 'slug' => (string) $p['slug'], 'name' => (string) $p['name'], 'category' => (string) $p['category'],
        'brand' => ['id' => (int) $p['brand_id'], 'name' => (string) ($p['b_name'] ?? ''), 'slug' => (string) ($p['b_slug'] ?? ''), 'color' => (string) ($p['b_color'] ?? '')],
        'price' => min($prices), 'price_max' => max($prices), 'image' => (string) ($imgs[0] ?? ''),
        'colors' => array_values($colors), 'sizes' => array_values($sizes), 'stores' => $nStores,
        'demo' => (bool) ($p['c_demo'] ?? 0),
    ];
}

function like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * Recherche dans le catalogue public.
 * Filtres : q, brand (id), category, size, color, pmin, pmax, store (id), instock, sort, page.
 * Retourne les fiches, les facettes (rayons, marques, tailles, couleurs) et les entreprises concernées.
 */
function catalog_search(array $f): array
{
    $pdo = db();
    $tokens = array_slice(array_values(array_filter(explode(' ', norm((string) ($f['q'] ?? ''))), 'strlen')), 0, 6);
    $where = "p.status = 'active' AND c.status = 'active'";
    $args = [];
    foreach ($tokens as $t) {
        $where .= " AND p.search LIKE ? ESCAPE '\\'";
        $args[] = '%' . like_escape($t) . '%';
    }
    $s = $pdo->prepare("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo
        FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id WHERE $where");
    $s->execute($args);
    $rows = $s->fetchAll();

    $facCat = [];
    $facBrand = [];
    foreach ($rows as $p) {
        $facCat[$p['category']] = ($facCat[$p['category']] ?? 0) + 1;
        $bid = (int) $p['brand_id'];
        if (!isset($facBrand[$bid])) $facBrand[$bid] = ['id' => $bid, 'name' => (string) $p['b_name'], 'n' => 0];
        $facBrand[$bid]['n']++;
    }

    $brand = (int) ($f['brand'] ?? 0);
    $cat = (string) ($f['category'] ?? '');
    if ($brand) $rows = array_values(array_filter($rows, function ($p) use ($brand) { return (int) $p['brand_id'] === $brand; }));
    if ($cat !== '') $rows = array_values(array_filter($rows, function ($p) use ($cat) { return $p['category'] === $cat; }));

    $variants = variants_of(array_column($rows, 'id'));
    $counts = product_store_counts();
    $storeVariants = null;
    $store = (int) ($f['store'] ?? 0);
    if ($store) {
        $storeVariants = [];
        $q = $pdo->prepare('SELECT variant_id FROM stock WHERE store_id = ? AND qty > 0');
        $q->execute([$store]);
        foreach ($q as $r) $storeVariants[(int) $r['variant_id']] = true;
    }

    $facSize = [];
    $facColor = [];
    foreach ($rows as $p) {
        $seenS = [];
        $seenC = [];
        foreach ($variants[(int) $p['id']] ?? [] as $v) {
            if ($v['size'] !== '' && !isset($seenS[$k = norm($v['size'])])) {
                $seenS[$k] = true;
                if (!isset($facSize[$k])) $facSize[$k] = ['label' => (string) $v['size'], 'n' => 0];
                $facSize[$k]['n']++;
            }
            if ($v['color'] !== '' && !isset($seenC[$k = norm($v['color'])])) {
                $seenC[$k] = true;
                if (!isset($facColor[$k])) $facColor[$k] = ['label' => (string) $v['color'], 'hex' => (string) $v['color_hex'], 'n' => 0];
                $facColor[$k]['n']++;
            }
        }
    }

    $size = norm((string) ($f['size'] ?? ''));
    $color = norm((string) ($f['color'] ?? ''));
    $pmin = is_numeric($f['pmin'] ?? null) ? (float) $f['pmin'] : null;
    $pmax = is_numeric($f['pmax'] ?? null) ? (float) $f['pmax'] : null;
    $instock = !empty($f['instock']);
    $cards = [];
    $companies = [];
    foreach ($rows as $p) {
        $vs = $variants[(int) $p['id']] ?? [];
        $match = $vs;
        if ($size !== '') $match = array_filter($match, function ($v) use ($size) { return norm($v['size']) === $size; });
        if ($color !== '') $match = array_filter($match, function ($v) use ($color) { return norm($v['color']) === $color; });
        if ($storeVariants !== null) $match = array_filter($match, function ($v) use ($storeVariants) { return isset($storeVariants[(int) $v['id']]); });
        if (($size !== '' || $color !== '' || $storeVariants !== null) && !$match) continue;
        $n = $counts[(int) $p['id']] ?? 0;
        if ($instock && !$n) continue;
        $card = product_card($p, $vs, $n);
        if ($pmin !== null && $card['price_max'] < $pmin) continue;
        if ($pmax !== null && $card['price'] > $pmax) continue;
        $score = 0;
        $name = norm((string) $p['name']);
        $bn = norm((string) $p['b_name']);
        foreach ($tokens as $t) {
            if (strpos($name, $t) !== false) $score += 3;
            elseif (strpos($bn, $t) !== false) $score += 2;
            else $score += 1;
        }
        $card['_score'] = $score;
        $card['_created'] = (int) $p['created'];
        $cards[] = $card;
        $companies[(int) $p['company_id']] = true;
    }

    $sort = (string) ($f['sort'] ?? '');
    usort($cards, function ($a, $b) use ($sort) {
        if ($sort === 'price_asc') return $a['price'] <=> $b['price'] ?: $a['id'] <=> $b['id'];
        if ($sort === 'price_desc') return $b['price'] <=> $a['price'] ?: $a['id'] <=> $b['id'];
        if ($sort === 'new') return $b['_created'] <=> $a['_created'] ?: $b['id'] <=> $a['id'];
        return $b['_score'] <=> $a['_score'] ?: ($b['stores'] > 0) <=> ($a['stores'] > 0) ?: $b['_created'] <=> $a['_created'] ?: $b['id'] <=> $a['id'];
    });
    $total = count($cards);
    $page = max(1, (int) ($f['page'] ?? 1));
    $cards = array_slice($cards, ($page - 1) * CATALOG_PER_PAGE, CATALOG_PER_PAGE);
    foreach ($cards as &$c) unset($c['_score'], $c['_created']);
    unset($c);

    $cats = [];
    foreach ($facCat as $k => $n) $cats[] = ['key' => $k, 'label' => MLG_CATEGORIES[$k] ?? $k, 'n' => $n];
    usort($cats, function ($a, $b) { return $b['n'] <=> $a['n']; });
    $brands = array_values($facBrand);
    usort($brands, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']); });
    $sizes = array_values($facSize);
    usort($sizes, function ($a, $b) { return strnatcasecmp($a['label'], $b['label']); });
    $colors = array_values($facColor);
    usort($colors, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($a['label'], $b['label']); });

    return [
        'items' => $cards, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / CATALOG_PER_PAGE)),
        'facets' => ['categories' => $cats, 'brands' => array_slice($brands, 0, 40), 'sizes' => array_slice($sizes, 0, 40), 'colors' => array_slice($colors, 0, 30)],
        'companies' => array_keys($companies),
    ];
}

function public_product(string $slug): ?array
{
    $s = db()->prepare("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, b.logo AS b_logo,
            c.demo AS c_demo, c.name AS c_name
        FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id
        WHERE p.slug = ? AND p.status = 'active' AND c.status = 'active'");
    $s->execute([$slug]);
    $p = $s->fetch();
    if (!$p) return null;
    $vs = variants_of([(int) $p['id']])[(int) $p['id']] ?? [];
    $counts = product_store_counts();
    $card = product_card($p, $vs, $counts[(int) $p['id']] ?? 0);
    $card['about'] = (string) $p['about'];
    $card['images'] = array_values(array_filter(jdec($p['images']), 'is_string'));
    $card['specs'] = array_values(array_filter(jdec($p['specs']), 'is_array'));
    $card['variants'] = array_map(function ($v) use ($p) { return variant_view($v, (float) $p['price']); }, $vs);
    $card['brand']['logo'] = (string) $p['b_logo'];
    $card['company'] = ['id' => (int) $p['company_id'], 'name' => (string) $p['c_name']];
    $card['landing'] = landing_clean(jdec($p['landing'] ?? '{}'));
    return $card;
}

/**
 * Où trouver un produit : points de vente visibles qui l'ont en stock, avec les déclinaisons
 * correspondant à la taille / couleur demandée, triés du plus proche au plus loin.
 */
function availability(int $productId, string $size, string $color, ?float $lat, ?float $lng): array
{
    $size = norm($size);
    $color = norm($color);
    $s = db()->prepare("SELECT k.store_id, k.qty, v.id AS vid, v.size, v.color, v.color_hex, v.price, p.price AS base
        FROM stock k JOIN variants v ON v.id = k.variant_id JOIN products p ON p.id = v.product_id
        WHERE v.product_id = ? AND k.qty > 0 ORDER BY v.pos, v.id");
    $s->execute([$productId]);
    $byStore = [];
    foreach ($s as $r) {
        if ($size !== '' && norm($r['size']) !== $size) continue;
        if ($color !== '' && norm($r['color']) !== $color) continue;
        $byStore[(int) $r['store_id']][] = [
            'id' => (int) $r['vid'], 'size' => (string) $r['size'], 'color' => (string) $r['color'], 'hex' => (string) $r['color_hex'],
            'price' => $r['price'] !== null ? (float) $r['price'] : (float) $r['base'],
            'low' => (int) $r['qty'] < 5, 'left' => (int) $r['qty'] < 5 ? (int) $r['qty'] : null, 'max' => min((int) $r['qty'], 10),
        ];
    }
    if (!$byStore) return [];
    $in = implode(',', array_keys($byStore));
    $out = [];
    $q = db()->query("SELECT s.*, c.name AS c_name, c.kind AS c_kind, c.demo AS c_demo
        FROM stores s JOIN companies c ON c.id = s.company_id
        WHERE s.id IN ($in) AND s.status = 'active' AND c.status = 'active'");
    foreach ($q as $st) {
        $v = store_view($st);
        unset($v['brands']);
        $v['variants'] = $byStore[(int) $st['id']];
        $v['km'] = ($lat !== null && $lng !== null) ? round(haversine($lat, $lng, (float) $st['lat'], (float) $st['lng']), 1) : null;
        $out[] = $v;
    }
    usort($out, function ($a, $b) {
        if ($a['km'] !== null && $b['km'] !== null) return $a['km'] <=> $b['km'];
        return strcmp($a['city'] . $a['name'], $b['city'] . $b['name']);
    });
    return $out;
}

/* ------------------------------------------------------- suivi des recherches */

/**
 * Enregistre une recherche de visiteur et les entreprises concernées (0 = demande sans
 * réponse sur la plateforme). Les coordonnées sont arrondies à ~1 km.
 */
function log_search(string $kind, array $d, array $companyIds, ?int $at = null): void
{
    $lat = coord($d['lat'] ?? null, 90);
    $lng = coord($d['lng'] ?? null, 180);
    if ($lat === null || $lng === null) { $lat = null; $lng = null; }
    $zone = zone_code($d['zone'] ?? '');
    if ($zone === '' && $lat !== null) $zone = zone_nearest($lat, $lng);
    $pdo = db();
    $pdo->prepare('INSERT INTO searches (kind, q, brand_id, product_id, category, size, color, wilaya, lat, lng, results, nearest_km, created)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $kind, mb_substr(norm((string) ($d['q'] ?? '')), 0, 80), (int) ($d['brand'] ?? 0), (int) ($d['product'] ?? 0),
        isset(MLG_CATEGORIES[$d['category'] ?? '']) ? $d['category'] : '',
        line_clean($d['size'] ?? '', 30), line_clean($d['color'] ?? '', 30), $zone,
        $lat !== null ? round($lat, 2) : null, $lng !== null ? round($lng, 2) : null,
        (int) ($d['results'] ?? 0), isset($d['nearest_km']) && $d['nearest_km'] !== null ? round((float) $d['nearest_km'], 1) : null,
        $at ?? time(),
    ]);
    $sid = (int) $pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO search_hits (search_id, company_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $companyIds ?: [0])) as $cid) $ins->execute([$sid, $cid]);
}

/** Compteur du jour pour une fiche (product, store, brand) ou une action (call, route). */
function view_hit(string $kind, int $id, ?string $day = null, int $n = 1): void
{
    if ($id <= 0 || !in_array($kind, ['product', 'store', 'brand', 'call', 'route'], true)) return;
    $day = $day ?? date('Y-m-d');
    $pdo = db();
    $pdo->prepare('INSERT OR IGNORE INTO views (kind, ref_id, day, n) VALUES (?, ?, ?, 0)')->execute([$kind, $id, $day]);
    $pdo->prepare('UPDATE views SET n = n + ? WHERE kind = ? AND ref_id = ? AND day = ?')->execute([$n, $kind, $id, $day]);
}
