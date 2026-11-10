<?php
/**
 * Espace entreprise de Maillage : marques, produits et déclinaisons, points de vente,
 * disponibilité par magasin, réseau (marque ↔ distributeur), commandes à traiter et vue
 * d'ensemble. Chaque fonction vérifie que l'objet appartient bien à l'entreprise connectée.
 * Les erreurs de saisie lèvent InvalidArgumentException avec un message affichable.
 */
declare(strict_types=1);

const MAX_BRANDS = 30;
const MAX_PRODUCTS = 3000;
const MAX_VARIANTS = 80;
const MAX_STORES = 600;
const MAX_IMAGES = 8;

function bad(string $msg): void
{
    throw new InvalidArgumentException($msg);
}

/* ------------------------------------------------------------------ marques */

function own_row(string $table, int $cid, int $id): ?array
{
    if (!in_array($table, ['brands', 'products', 'stores'], true)) throw new InvalidArgumentException('table');
    $s = db()->prepare("SELECT * FROM $table WHERE id = ? AND company_id = ?");
    $s->execute([$id, $cid]);
    return $s->fetch() ?: null;
}

function brands_list(int $cid): array
{
    $s = db()->prepare("SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id AND p.status <> 'archived') AS n_products
        FROM brands b WHERE b.company_id = ? ORDER BY b.name COLLATE NOCASE");
    $s->execute([$cid]);
    return array_map(function ($b) { return brand_view($b); }, $s->fetchAll());
}

function brand_save(int $cid, array $b): array
{
    $pdo = db();
    $id = (int) ($b['id'] ?? 0);
    $name = line_clean($b['name'] ?? '', 60);
    if (mb_strlen($name) < 2) bad('Donnez un nom à la marque.');
    $cat = isset(MLG_CATEGORIES[$b['category'] ?? '']) ? (string) $b['category'] : 'autre';
    $logo = (string) ($b['logo'] ?? '');
    if ($logo !== '' && !media_owned($logo, $cid)) $logo = '';
    $fields = [$name, line_clean($b['tagline'] ?? '', 120), str_clean($b['about'] ?? '', 2000), $logo, hex_color($b['color'] ?? ''), $cat];
    if ($id) {
        $old = own_row('brands', $cid, $id);
        if (!$old) bad('Marque introuvable.');
        $pdo->prepare('UPDATE brands SET name = ?, tagline = ?, about = ?, logo = ?, color = ?, category = ? WHERE id = ?')->execute(array_merge($fields, [$id]));
        if ($old['logo'] !== '' && $old['logo'] !== $logo) media_delete((string) $old['logo'], $cid);
        if ($old['name'] !== $name) products_reindex($cid, $id);
    } else {
        $n = $pdo->prepare('SELECT COUNT(*) FROM brands WHERE company_id = ?');
        $n->execute([$cid]);
        if ((int) $n->fetchColumn() >= MAX_BRANDS) bad('Nombre maximal de marques atteint.');
        $pdo->prepare('INSERT INTO brands (name, tagline, about, logo, color, category, company_id, slug, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($fields, [$cid, unique_slug('brands', $name), time()]));
        $id = (int) $pdo->lastInsertId();
        log_activity($cid, 'brand', 'Marque ajoutée : ' . $name);
    }
    return brand_view(own_row('brands', $cid, $id));
}

function brand_delete(int $cid, int $id): void
{
    $b = own_row('brands', $cid, $id);
    if (!$b) bad('Marque introuvable.');
    $n = db()->prepare('SELECT COUNT(*) FROM products WHERE brand_id = ?');
    $n->execute([$id]);
    if ((int) $n->fetchColumn() > 0) bad('Cette marque a encore des produits. Supprimez-les ou changez leur marque d\'abord.');
    db()->prepare('DELETE FROM brands WHERE id = ?')->execute([$id]);
    if ($b['logo'] !== '') media_delete((string) $b['logo'], $cid);
}

/* ----------------------------------------------------------------- produits */

/** Recalcule le texte de recherche des produits d'une marque (après un changement de nom). */
function products_reindex(int $cid, int $brandId): void
{
    $pdo = db();
    $s = $pdo->prepare('SELECT p.*, b.name AS b_name FROM products p JOIN brands b ON b.id = p.brand_id WHERE p.company_id = ? AND p.brand_id = ?');
    $s->execute([$cid, $brandId]);
    $rows = $s->fetchAll();
    $vs = variants_of(array_column($rows, 'id'));
    $u = $pdo->prepare('UPDATE products SET search = ? WHERE id = ?');
    foreach ($rows as $p) $u->execute([product_search_text($p, (string) $p['b_name'], $vs[(int) $p['id']] ?? []), $p['id']]);
}

function dash_products(int $cid): array
{
    $pdo = db();
    $s = $pdo->prepare("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color FROM products p JOIN brands b ON b.id = p.brand_id
        WHERE p.company_id = ? ORDER BY p.updated DESC, p.id DESC");
    $s->execute([$cid]);
    $rows = $s->fetchAll();
    $vs = variants_of(array_column($rows, 'id'));
    $stock = [];
    $q = $pdo->prepare("SELECT v.product_id AS pid, COALESCE(SUM(k.qty), 0) AS qty, COUNT(DISTINCT CASE WHEN k.qty > 0 THEN k.store_id END) AS stores
        FROM stock k JOIN variants v ON v.id = k.variant_id JOIN products p ON p.id = v.product_id WHERE p.company_id = ? GROUP BY v.product_id");
    $q->execute([$cid]);
    foreach ($q as $r) $stock[(int) $r['pid']] = $r;
    $out = [];
    foreach ($rows as $p) {
        $card = product_card($p, $vs[(int) $p['id']] ?? [], (int) ($stock[(int) $p['id']]['stores'] ?? 0));
        $card['status'] = (string) $p['status'];
        $card['variants'] = count($vs[(int) $p['id']] ?? []);
        $card['qty'] = (int) ($stock[(int) $p['id']]['qty'] ?? 0);
        $card['updated'] = (int) $p['updated'];
        $out[] = $card;
    }
    return $out;
}

function dash_product(int $cid, int $id): ?array
{
    $p = own_row('products', $cid, $id);
    if (!$p) return null;
    $vs = variants_of([$id])[$id] ?? [];
    return [
        'id' => $id, 'brand_id' => (int) $p['brand_id'], 'name' => (string) $p['name'], 'slug' => (string) $p['slug'],
        'category' => (string) $p['category'], 'about' => (string) $p['about'], 'price' => (float) $p['price'],
        'images' => array_values(array_filter(jdec($p['images']), 'is_string')), 'specs' => array_values(array_filter(jdec($p['specs']), 'is_array')),
        'status' => (string) $p['status'],
        'variants' => array_map(function ($v) {
            return ['id' => (int) $v['id'], 'sku' => (string) $v['sku'], 'size' => (string) $v['size'], 'color' => (string) $v['color'],
                'hex' => (string) $v['color_hex'], 'price' => $v['price'] !== null ? (float) $v['price'] : null];
        }, $vs),
    ];
}

function price_in($v): ?float
{
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) bad('Prix invalide.');
    $f = round((float) $v, 2);
    if ($f < 0 || $f > 1e9) bad('Prix invalide.');
    return $f;
}

function product_save(int $cid, array $b): array
{
    $pdo = db();
    $id = (int) ($b['id'] ?? 0);
    $old = $id ? own_row('products', $cid, $id) : null;
    if ($id && !$old) bad('Produit introuvable.');
    $brand = own_row('brands', $cid, (int) ($b['brand_id'] ?? 0));
    if (!$brand) bad('Choisissez une marque.');
    $name = line_clean($b['name'] ?? '', 120);
    if (mb_strlen($name) < 2) bad('Donnez un nom au produit.');
    $price = price_in($b['price'] ?? null);
    if ($price === null) bad('Indiquez le prix.');
    $cat = isset(MLG_CATEGORIES[$b['category'] ?? '']) ? (string) $b['category'] : 'autre';
    $status = ($b['status'] ?? '') === 'draft' ? 'draft' : 'active';

    $images = [];
    foreach ((array) ($b['images'] ?? []) as $im) {
        if (is_string($im) && media_owned($im, $cid) && !in_array($im, $images, true)) $images[] = $im;
    }
    $images = array_slice($images, 0, MAX_IMAGES);
    $specs = [];
    foreach ((array) ($b['specs'] ?? []) as $sp) {
        if (!is_array($sp)) continue;
        $k = line_clean($sp['k'] ?? '', 40);
        $v = line_clean($sp['v'] ?? '', 120);
        if ($k !== '' && $v !== '') $specs[] = ['k' => $k, 'v' => $v];
    }
    $specs = array_slice($specs, 0, 20);

    $variants = [];
    $seen = [];
    foreach ((array) ($b['variants'] ?? []) as $v) {
        if (!is_array($v)) continue;
        $row = [
            'id' => (int) ($v['id'] ?? 0), 'sku' => line_clean($v['sku'] ?? '', 40), 'size' => line_clean($v['size'] ?? '', 30),
            'color' => line_clean($v['color'] ?? '', 30), 'hex' => hex_color($v['hex'] ?? ''), 'price' => price_in($v['price'] ?? null),
        ];
        $key = norm($row['size']) . '|' . norm($row['color']);
        if (isset($seen[$key])) bad('Deux déclinaisons ont la même taille et la même couleur.');
        $seen[$key] = true;
        $variants[] = $row;
    }
    if (count($variants) > MAX_VARIANTS) bad('Trop de déclinaisons (' . MAX_VARIANTS . ' au maximum).');
    if (!$variants) $variants[] = ['id' => 0, 'sku' => '', 'size' => '', 'color' => '', 'hex' => '', 'price' => null];
    if (count($variants) > 1 && isset($seen['|'])) bad('Chaque déclinaison doit avoir une taille ou une couleur.');

    if (!$id) {
        $n = $pdo->prepare('SELECT COUNT(*) FROM products WHERE company_id = ?');
        $n->execute([$cid]);
        if ((int) $n->fetchColumn() >= MAX_PRODUCTS) bad('Nombre maximal de produits atteint.');
    }
    $now = time();
    tx_begin();
    try {
        if ($id) {
            $pdo->prepare('UPDATE products SET brand_id = ?, name = ?, category = ?, about = ?, price = ?, images = ?, specs = ?, status = ?, updated = ? WHERE id = ?')
                ->execute([$brand['id'], $name, $cat, str_clean($b['about'] ?? '', 3000), $price, json_encode($images), json_encode($specs, JSON_UNESCAPED_UNICODE), $status, $now, $id]);
        } else {
            $pdo->prepare('INSERT INTO products (company_id, brand_id, name, slug, category, about, price, images, specs, status, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cid, $brand['id'], $name, unique_slug('products', $name), $cat, str_clean($b['about'] ?? '', 3000), $price, json_encode($images), json_encode($specs, JSON_UNESCAPED_UNICODE), $status, $now, $now]);
            $id = (int) $pdo->lastInsertId();
        }
        $existing = [];
        foreach (variants_of([$id])[$id] ?? [] as $v) $existing[(int) $v['id']] = true;
        $keep = [];
        $up = $pdo->prepare('UPDATE variants SET sku = ?, size = ?, color = ?, color_hex = ?, price = ?, pos = ? WHERE id = ?');
        $ins = $pdo->prepare('INSERT INTO variants (product_id, sku, size, color, color_hex, price, pos) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($variants as $pos => $v) {
            if ($v['id'] && isset($existing[$v['id']])) {
                $up->execute([$v['sku'], $v['size'], $v['color'], $v['hex'], $v['price'], $pos, $v['id']]);
                $keep[$v['id']] = true;
            } else {
                $ins->execute([$id, $v['sku'], $v['size'], $v['color'], $v['hex'], $v['price'], $pos]);
                $keep[(int) $pdo->lastInsertId()] = true;
            }
        }
        $del = $pdo->prepare('DELETE FROM variants WHERE id = ?');
        foreach (array_keys($existing) as $vid) {
            if (!isset($keep[$vid])) $del->execute([$vid]);
        }
        $row = own_row('products', $cid, $id);
        $pdo->prepare('UPDATE products SET search = ? WHERE id = ?')->execute([product_search_text($row, (string) $brand['name'], variants_of([$id])[$id] ?? []), $id]);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    if ($old) {
        foreach (array_diff(array_filter(jdec($old['images']), 'is_string'), $images) as $gone) media_delete($gone, $cid);
    } else {
        log_activity($cid, 'product', 'Produit ajouté : ' . $name);
    }
    return dash_product($cid, $id);
}

function product_delete(int $cid, int $id): void
{
    $p = own_row('products', $cid, $id);
    if (!$p) bad('Produit introuvable.');
    db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]); // les lignes de commande gardent leur copie
    foreach (array_filter(jdec($p['images']), 'is_string') as $im) media_delete($im, $cid);
}

/* ---------------------------------------------------------- points de vente */

function dash_stores(int $cid): array
{
    $pdo = db();
    $s = $pdo->prepare("SELECT s.*, c.name AS c_name, c.kind AS c_kind, c.demo AS c_demo FROM stores s JOIN companies c ON c.id = s.company_id
        WHERE s.company_id = ? AND s.status <> 'archived' ORDER BY s.name COLLATE NOCASE");
    $s->execute([$cid]);
    $refs = [];
    $q = $pdo->prepare('SELECT k.store_id AS sid, COUNT(*) AS refs, SUM(CASE WHEN k.qty > 0 THEN 1 ELSE 0 END) AS instock
        FROM stock k JOIN stores s ON s.id = k.store_id WHERE s.company_id = ? GROUP BY k.store_id');
    $q->execute([$cid]);
    foreach ($q as $r) $refs[(int) $r['sid']] = $r;
    $open = [];
    $q = $pdo->prepare("SELECT store_id AS sid, COUNT(*) AS n FROM orders WHERE seller_cid = ? AND status IN ('pending','confirmed','ready','shipped') GROUP BY store_id");
    $q->execute([$cid]);
    foreach ($q as $r) $open[(int) $r['sid']] = (int) $r['n'];
    $out = [];
    foreach ($s as $st) {
        $v = store_view($st);
        unset($v['brands']);
        $v['refs'] = (int) ($refs[(int) $st['id']]['refs'] ?? 0);
        $v['instock'] = (int) ($refs[(int) $st['id']]['instock'] ?? 0);
        $v['open_orders'] = $open[(int) $st['id']] ?? 0;
        $out[] = $v;
    }
    return $out;
}

function store_save(int $cid, array $b): array
{
    $pdo = db();
    $id = (int) ($b['id'] ?? 0);
    if ($id && !own_row('stores', $cid, $id)) bad('Point de vente introuvable.');
    $name = line_clean($b['name'] ?? '', 80);
    if (mb_strlen($name) < 2) bad('Donnez un nom au point de vente.');
    $lat = coord($b['lat'] ?? null, 90);
    $lng = coord($b['lng'] ?? null, 180);
    if ($lat === null || $lng === null || ($lat == 0.0 && $lng == 0.0)) bad('Placez le point de vente sur la carte.');
    $zone = zone_code($b['wilaya'] ?? '');
    if ($zone === '') $zone = zone_nearest($lat, $lng);
    $city = line_clean($b['city'] ?? '', 80);
    if ($city === '') bad('Indiquez la ville.');
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    if ($email !== '' && !valid_email($email)) bad('Adresse email invalide.');
    $pickup = !empty($b['pickup']) ? 1 : 0;
    $delivery = !empty($b['delivery']) ? 1 : 0;
    $reserve = !empty($b['reserve']) ? 1 : 0;
    $fields = [
        $name, isset(MLG_STORE_TYPES[$b['type'] ?? '']) ? (string) $b['type'] : 'magasin', line_clean($b['address'] ?? '', 200), $city, $zone,
        round($lat, 6), round($lng, 6), phone_clean($b['phone'] ?? ''), $email, json_encode(hours_clean($b['hours'] ?? [])),
        $pickup, $delivery, $reserve, ($b['status'] ?? '') === 'closed' ? 'closed' : 'active',
    ];
    if ($id) {
        $pdo->prepare('UPDATE stores SET name = ?, type = ?, address = ?, city = ?, wilaya = ?, lat = ?, lng = ?, phone = ?, email = ?, hours = ?, pickup = ?, delivery = ?, reserve = ?, status = ? WHERE id = ?')
            ->execute(array_merge($fields, [$id]));
    } else {
        $n = $pdo->prepare("SELECT COUNT(*) FROM stores WHERE company_id = ? AND status <> 'archived'");
        $n->execute([$cid]);
        if ((int) $n->fetchColumn() >= MAX_STORES) bad('Nombre maximal de points de vente atteint.');
        $pdo->prepare('INSERT INTO stores (name, type, address, city, wilaya, lat, lng, phone, email, hours, pickup, delivery, reserve, status, company_id, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($fields, [$cid, time()]));
        $id = (int) $pdo->lastInsertId();
        log_activity($cid, 'store', 'Point de vente ajouté : ' . $name . ' · ' . $city, $id);
    }
    foreach (dash_stores($cid) as $v) {
        if ($v['id'] === $id) return $v;
    }
    return [];
}

/** Supprime un point de vente ; s'il a déjà reçu des commandes, il est archivé pour garder l'historique. */
function store_delete(int $cid, int $id): void
{
    $pdo = db();
    $st = own_row('stores', $cid, $id);
    if (!$st) bad('Point de vente introuvable.');
    $n = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE store_id = ? AND status IN ('pending','confirmed','ready','shipped')");
    $n->execute([$id]);
    if ((int) $n->fetchColumn() > 0) bad('Ce point de vente a des commandes en cours. Terminez-les ou annulez-les d\'abord.');
    $n = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE store_id = ?');
    $n->execute([$id]);
    if ((int) $n->fetchColumn() > 0) {
        $pdo->prepare("UPDATE stores SET status = 'archived' WHERE id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM stock WHERE store_id = ?')->execute([$id]);
    } else {
        $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([$id]);
    }
}

/* ------------------------------------------------------------------- réseau */

/** Entreprises partenaires actives (ids). */
function partner_cids(int $cid, string $kind): array
{
    $sql = $kind === 'brand'
        ? "SELECT dist_cid FROM partnerships WHERE brand_cid = ? AND status = 'active'"
        : "SELECT brand_cid FROM partnerships WHERE dist_cid = ? AND status = 'active'";
    $s = db()->prepare($sql);
    $s->execute([$cid]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/** Entreprises dont ce compte peut référencer les produits : la sienne + les marques qu'il distribue. */
function stockable_cids(int $cid, string $kind): array
{
    return array_merge([$cid], $kind === 'distributor' ? partner_cids($cid, $kind) : []);
}

/** Points de vente du réseau : les siens + (pour une marque) ceux des distributeurs qui référencent ses produits. */
function network_stores(int $cid, string $kind): array
{
    $pdo = db();
    $s = $pdo->prepare("SELECT s.*, c.name AS c_name, c.kind AS c_kind, c.demo AS c_demo FROM stores s JOIN companies c ON c.id = s.company_id
        WHERE s.company_id = ? AND s.status = 'active'");
    $s->execute([$cid]);
    $rows = $s->fetchAll();
    if ($kind === 'brand' && ($partners = partner_cids($cid, $kind))) {
        $in = implode(',', $partners);
        $q = $pdo->prepare("SELECT DISTINCT s.*, c.name AS c_name, c.kind AS c_kind, c.demo AS c_demo
            FROM stores s JOIN companies c ON c.id = s.company_id
            JOIN stock k ON k.store_id = s.id JOIN variants v ON v.id = k.variant_id JOIN products p ON p.id = v.product_id
            WHERE s.company_id IN ($in) AND s.status = 'active' AND p.company_id = ?");
        $q->execute([$cid]);
        $rows = array_merge($rows, $q->fetchAll());
    }
    return $rows;
}

function network_get(int $cid, string $kind): array
{
    $pdo = db();
    $mine = $kind === 'brand' ? 'brand_cid' : 'dist_cid';
    $other = $kind === 'brand' ? 'dist_cid' : 'brand_cid';
    $s = $pdo->prepare("SELECT p.*, c.id AS c_id, c.name AS c_name, c.kind AS c_kind, c.city AS c_city, c.wilaya AS c_wilaya, c.logo AS c_logo, c.about AS c_about, c.demo AS c_demo, c.status AS c_status
        FROM partnerships p JOIN companies c ON c.id = p.$other WHERE p.$mine = ? ORDER BY p.status, c.name COLLATE NOCASE");
    $s->execute([$cid]);
    $partners = [];
    $linked = [];
    foreach ($s as $p) {
        $oc = (int) $p['c_id'];
        $linked[$oc] = true;
        $brandCid = $kind === 'brand' ? $cid : $oc;
        $distCid = $kind === 'brand' ? $oc : $cid;
        $st = $pdo->prepare("SELECT COUNT(DISTINCT k.store_id) AS stores, COUNT(*) AS refs, SUM(CASE WHEN k.qty > 0 THEN 1 ELSE 0 END) AS instock
            FROM stock k JOIN stores s ON s.id = k.store_id JOIN variants v ON v.id = k.variant_id JOIN products pr ON pr.id = v.product_id
            WHERE s.company_id = ? AND pr.company_id = ? AND s.status = 'active'");
        $st->execute([$distCid, $brandCid]);
        $agg = $st->fetch() ?: [];
        $partners[] = [
            'id' => (int) $p['id'], 'status' => (string) $p['status'], 'mine' => (int) $p['initiator'] === $cid, 'since' => (int) $p['updated'],
            'company' => ['id' => $oc, 'name' => (string) $p['c_name'], 'kind' => (string) $p['c_kind'], 'city' => (string) $p['c_city'],
                'zone' => zone_name((string) $p['c_wilaya']), 'logo' => (string) $p['c_logo'], 'about' => (string) $p['c_about'], 'demo' => (bool) $p['c_demo']],
            'stores' => (int) ($agg['stores'] ?? 0), 'refs' => (int) ($agg['refs'] ?? 0), 'instock' => (int) ($agg['instock'] ?? 0),
        ];
    }
    // Annuaire : les entreprises validées de l'autre type avec lesquelles on n'est pas encore lié.
    // Les entreprises de démonstration ne se proposent qu'entre elles.
    $dir = [];
    $demo = (int) (company_row($cid)['demo'] ?? 0);
    $q = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM stores s WHERE s.company_id = c.id AND s.status = 'active') AS n_stores,
            (SELECT COUNT(*) FROM products p WHERE p.company_id = c.id AND p.status = 'active') AS n_products
        FROM companies c WHERE c.kind = ? AND c.status = 'active' AND c.id <> ? AND c.demo = ? ORDER BY c.name COLLATE NOCASE LIMIT 300");
    $q->execute([$kind === 'brand' ? 'distributor' : 'brand', $cid, $demo]);
    foreach ($q as $c) {
        if (isset($linked[(int) $c['id']])) continue;
        $dir[] = ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'kind' => (string) $c['kind'], 'city' => (string) $c['city'],
            'zone' => zone_name((string) $c['wilaya']), 'logo' => (string) $c['logo'], 'about' => (string) $c['about'], 'demo' => (bool) $c['demo'],
            'stores' => (int) $c['n_stores'], 'products' => (int) $c['n_products']];
    }
    return ['partners' => $partners, 'directory' => $dir, 'activity' => activity_feed($cid, 60)];
}

function activity_feed(int $cid, int $limit): array
{
    $s = db()->prepare("SELECT a.*, c.name AS c_name, s.name AS s_name FROM activity a
        LEFT JOIN companies c ON c.id = a.company_id LEFT JOIN stores s ON s.id = a.store_id
        WHERE a.company_id = ? OR a.about_cid = ? ORDER BY a.created DESC, a.id DESC LIMIT " . max(1, min(200, $limit)));
    $s->execute([$cid, $cid]);
    $out = [];
    $seen = [];
    foreach ($s as $a) {
        // Une même commande est journalisée pour le point de vente et pour la marque : on n'en montre qu'une.
        $key = $a['kind'] . '|' . $a['created'] . '|' . $a['store_id'] . '|' . substr((string) $a['detail'], 0, 24);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['kind' => (string) $a['kind'], 'detail' => (string) $a['detail'], 'created' => (int) $a['created'],
            'company' => (string) ($a['c_name'] ?? ''), 'own' => (int) $a['company_id'] === $cid, 'store' => (string) ($a['s_name'] ?? '')];
    }
    return $out;
}

function partner_request(int $cid, string $kind, int $otherId): void
{
    $pdo = db();
    $o = company_row($otherId);
    $me = company_row($cid);
    if (!$o || $o['status'] !== 'active' || $o['kind'] === $kind || $otherId === $cid || (int) $o['demo'] !== (int) $me['demo']) bad('Entreprise introuvable.');
    if ($me['status'] !== 'active') bad('Votre espace doit d\'abord être validé pour proposer un partenariat.');
    $brand = $kind === 'brand' ? $cid : $otherId;
    $dist = $kind === 'brand' ? $otherId : $cid;
    $s = $pdo->prepare('SELECT * FROM partnerships WHERE brand_cid = ? AND dist_cid = ?');
    $s->execute([$brand, $dist]);
    $p = $s->fetch();
    $now = time();
    if ($p) {
        if ($p['status'] === 'active') bad('Vous travaillez déjà ensemble.');
        if ($p['status'] === 'pending' && (int) $p['initiator'] !== $cid) { partner_respond($cid, $kind, (int) $p['id'], true); return; }
        $pdo->prepare("UPDATE partnerships SET status = 'pending', initiator = ?, updated = ? WHERE id = ?")->execute([$cid, $now, $p['id']]);
    } else {
        $pdo->prepare("INSERT INTO partnerships (brand_cid, dist_cid, status, initiator, created, updated) VALUES (?, ?, 'pending', ?, ?, ?)")->execute([$brand, $dist, $cid, $now, $now]);
    }
    log_activity($cid, 'partner', 'Demande de partenariat envoyée à ' . $o['name'], 0, $otherId);
    if ($o['email'] !== '') {
        try_mail((string) $o['email'], $me['name'] . ' souhaite travailler avec vous sur ' . MLG_NAME, 'Nouvelle demande de partenariat', [
            $me['name'] . ($kind === 'brand' ? ' vous propose de distribuer ses produits.' : ' souhaite distribuer vos produits dans ses points de vente.'),
            'Acceptez la demande pour partager le catalogue et la disponibilité en magasin.',
        ], 'Voir la demande', site_url() . 'espace/#/reseau');
    }
}

function partner_find(int $cid, string $kind, int $id): array
{
    $col = $kind === 'brand' ? 'brand_cid' : 'dist_cid';
    $s = db()->prepare("SELECT * FROM partnerships WHERE id = ? AND $col = ?");
    $s->execute([$id, $cid]);
    $p = $s->fetch();
    if (!$p) bad('Partenariat introuvable.');
    return $p;
}

function partner_respond(int $cid, string $kind, int $id, bool $accept): void
{
    $p = partner_find($cid, $kind, $id);
    if ($p['status'] !== 'pending' || (int) $p['initiator'] === $cid) bad('Cette demande n\'attend pas votre réponse.');
    $other = (int) ($kind === 'brand' ? $p['dist_cid'] : $p['brand_cid']);
    if ($accept) {
        db()->prepare("UPDATE partnerships SET status = 'active', updated = ? WHERE id = ?")->execute([time(), $id]);
        $o = company_row($other);
        log_activity($cid, 'partner', 'Partenariat activé avec ' . ($o['name'] ?? ''), 0, $other);
    } else {
        db()->prepare('DELETE FROM partnerships WHERE id = ?')->execute([$id]);
    }
}

/** Met fin à un partenariat : les produits de la marque sont retirés des magasins du distributeur. */
function partner_remove(int $cid, string $kind, int $id): void
{
    $p = partner_find($cid, $kind, $id);
    $pdo = db();
    $pdo->prepare('DELETE FROM stock WHERE store_id IN (SELECT id FROM stores WHERE company_id = ?)
        AND variant_id IN (SELECT v.id FROM variants v JOIN products pr ON pr.id = v.product_id WHERE pr.company_id = ?)')->execute([$p['dist_cid'], $p['brand_cid']]);
    $pdo->prepare('DELETE FROM partnerships WHERE id = ?')->execute([$id]);
}

/* ------------------------------------------------------------ disponibilité */

/** Produits référençables dans un point de vente, avec la quantité actuelle de chaque déclinaison (null = non référencée). */
function stock_get(int $cid, string $kind, int $storeId): array
{
    $pdo = db();
    if (!own_row('stores', $cid, $storeId)) bad('Point de vente introuvable.');
    $in = implode(',', stockable_cids($cid, $kind));
    $rows = $pdo->query("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.name AS c_name
        FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id
        WHERE p.company_id IN ($in) AND p.status = 'active' AND c.status IN ('active', 'pending') ORDER BY b.name COLLATE NOCASE, p.name COLLATE NOCASE")->fetchAll();
    $vs = variants_of(array_column($rows, 'id'));
    $qty = [];
    $q = $pdo->prepare('SELECT variant_id, qty FROM stock WHERE store_id = ?');
    $q->execute([$storeId]);
    foreach ($q as $r) $qty[(int) $r['variant_id']] = (int) $r['qty'];
    $out = [];
    foreach ($rows as $p) {
        $imgs = jdec($p['images']);
        $out[] = [
            'id' => (int) $p['id'], 'name' => (string) $p['name'], 'brand' => (string) $p['b_name'], 'color' => (string) $p['b_color'],
            'image' => (string) ($imgs[0] ?? ''), 'category' => (string) $p['category'], 'own' => (int) $p['company_id'] === $cid, 'company' => (string) $p['c_name'],
            'variants' => array_map(function ($v) use ($qty) {
                return ['id' => (int) $v['id'], 'size' => (string) $v['size'], 'color' => (string) $v['color'], 'hex' => (string) $v['color_hex'],
                    'sku' => (string) $v['sku'], 'qty' => $qty[(int) $v['id']] ?? null];
            }, $vs[(int) $p['id']] ?? []),
        ];
    }
    return $out;
}

/** Enregistre des quantités : [{variant_id, qty}] ; qty null retire la déclinaison du magasin. Retourne le nombre de lignes modifiées. */
function stock_save(int $cid, string $kind, int $storeId, array $items): int
{
    $pdo = db();
    $store = own_row('stores', $cid, $storeId);
    if (!$store) bad('Point de vente introuvable.');
    $allowed = [];
    $in = implode(',', stockable_cids($cid, $kind));
    foreach ($pdo->query("SELECT v.id, p.company_id FROM variants v JOIN products p ON p.id = v.product_id WHERE p.company_id IN ($in)") as $r) $allowed[(int) $r['id']] = (int) $r['company_id'];
    $now = time();
    $n = 0;
    $about = [];
    tx_begin();
    try {
        $del = $pdo->prepare('DELETE FROM stock WHERE store_id = ? AND variant_id = ?');
        $set = $pdo->prepare('INSERT OR REPLACE INTO stock (store_id, variant_id, qty, updated) VALUES (?, ?, ?, ?)');
        foreach (array_slice($items, 0, 5000) as $it) {
            if (!is_array($it)) continue;
            $vid = (int) ($it['variant_id'] ?? 0);
            if (!isset($allowed[$vid])) continue;
            if (!array_key_exists('qty', $it) || $it['qty'] === null || $it['qty'] === '') {
                $del->execute([$storeId, $vid]);
            } else {
                if (!is_numeric($it['qty'])) continue;
                $set->execute([$storeId, $vid, max(0, min(99999, (int) $it['qty'])), $now]);
            }
            $n++;
            $about[$allowed[$vid]] = ($about[$allowed[$vid]] ?? 0) + 1;
        }
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    foreach ($about as $pc => $k) {
        log_activity($cid, 'stock', 'Disponibilité mise à jour : ' . $k . ' référence' . ($k > 1 ? 's' : '') . ' · ' . $store['name'], $storeId, $pc);
    }
    return $n;
}

/* ---------------------------------------------------------------- commandes */

function dash_orders(int $cid, array $f): array
{
    $where = 'o.seller_cid = ?';
    $args = [$cid];
    $status = (string) ($f['status'] ?? 'open');
    if ($status === 'open') $where .= " AND o.status IN ('pending','confirmed','ready','shipped')";
    elseif (isset(ORDER_STATUSES[$status])) { $where .= ' AND o.status = ?'; $args[] = $status; }
    if ((int) ($f['store'] ?? 0)) { $where .= ' AND o.store_id = ?'; $args[] = (int) $f['store']; }
    $q = line_clean($f['q'] ?? '', 60);
    if ($q !== '') {
        $where .= " AND (o.ref LIKE ? ESCAPE '\\' OR o.cname LIKE ? ESCAPE '\\' OR o.cphone LIKE ? ESCAPE '\\')";
        $like = '%' . like_escape($q) . '%';
        array_push($args, $like, $like, $like);
    }
    $s = db()->prepare("SELECT o.*, s.name AS s_name, s.city AS s_city,
            (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS n_items,
            (SELECT SUM(i.qty) FROM order_items i WHERE i.order_id = o.id) AS n_units,
            (SELECT i.name FROM order_items i WHERE i.order_id = o.id ORDER BY i.id LIMIT 1) AS first_item
        FROM orders o LEFT JOIN stores s ON s.id = o.store_id WHERE $where ORDER BY o.created DESC, o.id DESC LIMIT 300");
    $s->execute($args);
    $out = [];
    foreach ($s as $o) {
        $out[] = [
            'id' => (int) $o['id'], 'ref' => (string) $o['ref'], 'kind' => (string) $o['kind'], 'mode' => (string) $o['mode'], 'status' => (string) $o['status'],
            'total' => (float) $o['total'], 'created' => (int) $o['created'], 'expires' => (int) $o['expires'], 'name' => (string) $o['cname'], 'phone' => (string) $o['cphone'],
            'store' => (string) ($o['s_name'] ?? ''), 'city' => (string) ($o['s_city'] ?? ''), 'items' => (int) $o['n_items'], 'units' => (int) $o['n_units'], 'first' => (string) $o['first_item'],
        ];
    }
    $c = db()->prepare("SELECT status, COUNT(*) AS n FROM orders WHERE seller_cid = ? GROUP BY status");
    $c->execute([$cid]);
    $counts = array_fill_keys(array_keys(ORDER_STATUSES), 0);
    foreach ($c as $r) $counts[$r['status']] = (int) $r['n'];
    return ['orders' => $out, 'counts' => $counts];
}

function own_order(int $cid, int $id): ?array
{
    $s = db()->prepare('SELECT * FROM orders WHERE id = ? AND seller_cid = ?');
    $s->execute([$id, $cid]);
    return $s->fetch() ?: null;
}

/* ----------------------------------------------------------- vue d'ensemble */

function dash_overview(int $cid, string $kind): array
{
    $pdo = db();
    $one = function (string $sql, array $args) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($args);
        return $s->fetchColumn();
    };
    $since = strtotime('today') - 29 * 86400;
    $own = (int) $one("SELECT COUNT(*) FROM stores WHERE company_id = ? AND status = 'active'", [$cid]);
    $steps = [
        'brand' => (int) $one('SELECT COUNT(*) FROM brands WHERE company_id = ?', [$cid]) > 0,
        'product' => (int) $one("SELECT COUNT(*) FROM products WHERE company_id = ?", [$cid]) > 0,
        'store' => (int) $one("SELECT COUNT(*) FROM stores WHERE company_id = ? AND status <> 'archived'", [$cid]) > 0,
        'stock' => (int) $one('SELECT COUNT(*) FROM stock k JOIN stores s ON s.id = k.store_id WHERE s.company_id = ?', [$cid]) > 0,
        'partner' => count(partner_cids($cid, $kind)) > 0,
    ];
    $rev = (float) $one("SELECT COALESCE(SUM(i.price * i.qty), 0) FROM order_items i JOIN orders o ON o.id = i.order_id
        WHERE o.created >= ? AND o.status <> 'cancelled' AND (o.seller_cid = ? OR i.product_cid = ?)", [$since, $cid, $cid]);
    $orders30 = (int) $one("SELECT COUNT(DISTINCT o.id) FROM orders o JOIN order_items i ON i.order_id = o.id
        WHERE o.created >= ? AND (o.seller_cid = ? OR i.product_cid = ?)", [$since, $cid, $cid]);
    $scope = implode(',', array_merge([$cid], $kind === 'distributor' ? partner_cids($cid, $kind) : []));
    $searches30 = (int) $one("SELECT COUNT(*) FROM searches s WHERE s.created >= ? AND EXISTS (SELECT 1 FROM search_hits h WHERE h.search_id = s.id AND h.company_id IN ($scope))", [$since]);

    // Commandes par jour sur 14 jours (courbe de la vue d'ensemble).
    $days = [];
    $start = strtotime('today') - 13 * 86400;
    for ($i = 0; $i < 14; $i++) $days[date('Y-m-d', $start + $i * 86400)] = 0;
    $s = $pdo->prepare("SELECT DISTINCT o.id, o.created FROM orders o JOIN order_items i ON i.order_id = o.id WHERE o.created >= ? AND (o.seller_cid = ? OR i.product_cid = ?)");
    $s->execute([$start, $cid, $cid]);
    foreach ($s as $r) {
        $d = date('Y-m-d', (int) $r['created']);
        if (isset($days[$d])) $days[$d]++;
    }

    $low = [];
    $s = $pdo->prepare("SELECT k.qty, s.name AS s_name, s.id AS sid, p.name AS p_name, v.size, v.color FROM stock k
        JOIN stores s ON s.id = k.store_id JOIN variants v ON v.id = k.variant_id JOIN products p ON p.id = v.product_id
        WHERE s.company_id = ? AND s.status = 'active' AND k.qty <= 2 ORDER BY k.qty, k.updated DESC LIMIT 8");
    $s->execute([$cid]);
    foreach ($s as $r) {
        $low[] = ['store' => (string) $r['s_name'], 'store_id' => (int) $r['sid'], 'product' => (string) $r['p_name'],
            'option' => trim($r['size'] . ' ' . $r['color']), 'qty' => (int) $r['qty']];
    }
    $recent = dash_orders($cid, ['status' => 'all']);
    return [
        'tiles' => [
            'to_process' => (int) $one("SELECT COUNT(*) FROM orders WHERE seller_cid = ? AND status = 'pending'", [$cid]),
            'in_progress' => (int) $one("SELECT COUNT(*) FROM orders WHERE seller_cid = ? AND status IN ('confirmed','ready','shipped')", [$cid]),
            'orders30' => $orders30, 'revenue30' => $rev, 'searches30' => $searches30,
            'stores' => $own, 'network_stores' => count(network_stores($cid, $kind)),
            'products' => (int) $one("SELECT COUNT(*) FROM products WHERE company_id = ? AND status = 'active'", [$cid]),
            'low_stock' => (int) $one("SELECT COUNT(*) FROM stock k JOIN stores s ON s.id = k.store_id WHERE s.company_id = ? AND s.status = 'active' AND k.qty <= 2", [$cid]),
        ],
        'steps' => $steps,
        'trend' => ['days' => array_keys($days), 'orders' => array_values($days)],
        'low' => $low,
        'recent' => array_slice($recent['orders'], 0, 6),
        'activity' => activity_feed($cid, 10),
    ];
}

/* --------------------------------------------------------------- entreprise */

function company_save(int $cid, array $b): array
{
    $old = company_row($cid);
    $name = line_clean($b['name'] ?? '', 80);
    if (mb_strlen($name) < 2) bad('Indiquez le nom de l\'entreprise.');
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    if ($email !== '' && !valid_email($email)) bad('Adresse email invalide.');
    $web = line_clean($b['website'] ?? '', 200);
    if ($web !== '' && !preg_match('~^https?://[^\s<>"\']+$~i', $web)) {
        $web = preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(/[^\s<>"\']*)?$~i', $web) ? 'https://' . $web : '';
    }
    $logo = (string) ($b['logo'] ?? '');
    if ($logo !== '' && !media_owned($logo, $cid)) $logo = '';
    db()->prepare('UPDATE companies SET name = ?, about = ?, logo = ?, phone = ?, email = ?, website = ?, wilaya = ?, city = ?, sector = ? WHERE id = ?')->execute([
        $name, str_clean($b['about'] ?? '', 1500), $logo, phone_clean($b['phone'] ?? ''), $email, $web, zone_code($b['wilaya'] ?? ''),
        line_clean($b['city'] ?? '', 80), isset(MLG_CATEGORIES[$b['sector'] ?? '']) ? (string) $b['sector'] : '', $cid,
    ]);
    if ($old && $old['logo'] !== '' && $old['logo'] !== $logo) media_delete((string) $old['logo'], $cid);
    return company_view(company_row($cid) ?? []);
}

/** Supprime une entreprise et tout ce qui lui appartient (administration). */
function company_delete(int $cid): void
{
    $pdo = db();
    tx_begin();
    try {
        $pdo->prepare('DELETE FROM order_events WHERE order_id IN (SELECT id FROM orders WHERE seller_cid = ?)')->execute([$cid]);
        $pdo->prepare('DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE seller_cid = ?)')->execute([$cid]);
        $pdo->prepare('DELETE FROM orders WHERE seller_cid = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM search_hits WHERE company_id = ?')->execute([$cid]);
        $pdo->prepare('DELETE FROM activity WHERE company_id = ? OR about_cid = ?')->execute([$cid, $cid]);
        $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$cid]); // cascade : comptes, marques, produits, magasins, stock, partenariats
        $pdo->exec('DELETE FROM searches WHERE id NOT IN (SELECT search_id FROM search_hits)');
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    media_purge($cid);
}
