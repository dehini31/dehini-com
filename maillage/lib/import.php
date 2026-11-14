<?php
/**
 * Import de la « base Algérie » : marques, entreprises, points de vente, produits et offres en
 * ligne relevés dans des sources publiques (lib/data/import/algerie.json, préparé par
 * tools/import/prepare_algerie.py). Lancé depuis l'administration.
 *
 * Les entreprises importées sont des « fiches publiques » (companies.listing = 1) : sans compte,
 * affichées comme non gérées par l'entreprise, sans stock ni prix présentés comme confirmés.
 * L'import peut être relancé : chaque ligne est retrouvée par son identifiant dans la source
 * (colonne ext) et mise à jour, sans doublon ; ce qu'une entreprise a modifié elle-même dans son
 * espace (fiche revendiquée, listing = 0) n'est pas écrasé.
 */
declare(strict_types=1);

const IMPORT_ALGERIE = MLG_ROOT . '/lib/data/import/algerie.json';

function import_algerie_data(): array
{
    $d = is_file(IMPORT_ALGERIE) ? json_decode((string) file_get_contents(IMPORT_ALGERIE), true) : null;
    if (!is_array($d) || empty($d['products'])) throw new RuntimeException('Fichier de la base Algérie introuvable.');
    return $d;
}

/** Ce que contient le fichier, et ce qui est déjà sur le site. */
function import_algerie_status(): array
{
    $d = import_algerie_data();
    $pdo = db();
    $one = function (string $sql) use ($pdo): int { return (int) $pdo->query($sql)->fetchColumn(); };
    return [
        'file' => ['companies' => count($d['companies']), 'brands' => count($d['brands']), 'stores' => count($d['stores']),
            'products' => count($d['products']), 'offers' => count($d['offers']), 'checked' => (string) ($d['checked'] ?? '')],
        'site' => ['companies' => $one("SELECT COUNT(*) FROM companies WHERE ext LIKE 'DZ-%'"),
            'products' => $one("SELECT COUNT(*) FROM products WHERE ext LIKE 'P%' AND ext <> ''"),
            'stores' => $one("SELECT COUNT(*) FROM stores WHERE ext LIKE 'S%' AND ext <> ''")],
        'last' => meta_get('import.algerie'),
    ];
}

/** Photo de la source → media/c<id>/<id stable> (WebP 480 et 1200) ; '' si illisible. */
function import_photo(int $cid, string $file): string
{
    if (!preg_match('/^P\d{3}\.jpg$/', $file)) return '';
    $src = dirname(IMPORT_ALGERIE) . '/algerie/' . $file;
    if (!is_file($src)) return '';
    $id = substr(hash('sha256', 'algerie/' . $file), 0, 16);
    $path = 'media/c' . $cid . '/' . $id;
    if (is_file(MLG_ROOT . '/' . $path . '-480.webp') && is_file(MLG_ROOT . '/' . $path . '-1200.webp')) return $path;
    try {
        $im = image_open($src, 64);
        image_write($im, media_dir($cid), $id);
        imagedestroy($im);
    } catch (Throwable $e) {
        error_log('maillage import photo ' . $file . ': ' . $e->getMessage());
        return '';
    }
    return $path;
}

/** Lance (ou relance) l'import. Retourne le nombre de lignes créées et mises à jour. */
function import_algerie(): array
{
    $d = import_algerie_data();
    @set_time_limit(600);
    $pdo = db();
    $now = time();
    $n = ['created' => 0, 'updated' => 0, 'photos' => 0, 'skipped' => 0];
    $find = function (string $table, string $ext) use ($pdo): ?array {
        $s = $pdo->prepare("SELECT * FROM $table WHERE ext = ? LIMIT 1");
        $s->execute([$ext]);
        return $s->fetch() ?: null;
    };

    tx_begin();
    try {
        $res = import_algerie_rows($d, $find, $now, $n);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    meta_set('import.algerie', json_encode($res));
    return $res;
}

function import_algerie_rows(array $d, callable $find, int $now, array $n): array
{
    $pdo = db();
    // Entreprises
    $cids = [];
    $managed = [];
    foreach ($d['companies'] as $c) {
        $kind = in_array($c['kind'] ?? '', ['brand', 'distributor', 'pos'], true) ? $c['kind'] : 'distributor';
        $row = $find('companies', (string) $c['ext']);
        $vals = [line_clean($c['name'], 80), phone_clean(explode('/', (string) $c['phone'])[0]), strtolower(line_clean($c['email'], 190)),
            line_clean($c['website'] ?: ($c['online'] ?? ''), 200), (string) ($c['wilaya'] ?? ''), line_clean($c['city'] ?? '', 80),
            line_clean($c['src'], 300), (string) $c['checked']];
        if ($row) {
            $cids[$c['ext']] = (int) $row['id'];
            if (!(int) $row['listing']) { $managed[(int) $row['id']] = true; $n['skipped']++; continue; }
            $pdo->prepare('UPDATE companies SET name = ?, phone = ?, email = ?, website = ?, wilaya = ?, city = ?, src = ?, checked = ? WHERE id = ?')
                ->execute(array_merge($vals, [$row['id']]));
            $n['updated']++;
        } else {
            $pdo->prepare("INSERT INTO companies (kind, name, slug, phone, email, website, wilaya, city, src, checked, status, listing, ext, created)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1, ?, ?)")
                ->execute([$kind, $vals[0], unique_slug('companies', $vals[0]), $vals[1], $vals[2], $vals[3], $vals[4], $vals[5], $vals[6], $vals[7], $c['ext'], $now]);
            $cids[$c['ext']] = (int) $pdo->lastInsertId();
            $n['created']++;
        }
    }

    // Marques
    $bids = [];
    foreach ($d['brands'] as $b) {
        $cid = $cids[$b['company']] ?? 0;
        if (!$cid) continue;
        $row = $find('brands', (string) $b['ext']);
        $name = line_clean($b['name'], 60);
        $cat = 'autre';
        foreach ($d['products'] as $p) { if ($p['brand'] === $b['ext']) { $cat = $p['category']; break; } }
        if ($row) {
            $bids[$b['ext']] = (int) $row['id'];
            if (isset($managed[(int) $row['company_id']])) continue;
            $pdo->prepare('UPDATE brands SET name = ?, website = ?, color = ?, about = ?, category = ? WHERE id = ?')
                ->execute([$name, line_clean($b['website'], 200), hex_color($b['color']), str_clean($b['notes'], 600), $cat, $row['id']]);
        } else {
            $pdo->prepare('INSERT INTO brands (company_id, name, slug, about, color, category, website, ext, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$cid, $name, unique_slug('brands', $name), str_clean($b['notes'], 600), hex_color($b['color']), $cat, line_clean($b['website'], 200), $b['ext'], $now]);
            $bids[$b['ext']] = (int) $pdo->lastInsertId();
        }
    }

    // Points de vente physiques (les boutiques en ligne sont des liens, voir les offres).
    $sids = [];
    foreach ($d['stores'] as $st) {
        $cid = $cids[$st['company']] ?? 0;
        if (!$cid) continue;
        $row = $find('stores', (string) $st['ext']);
        $commune = commune_guess((string) $st['wilaya'], (string) $st['commune']) ?: (string) $st['commune'];
        $vals = [line_clean($st['name'], 80), line_clean($st['address'], 200), line_clean($st['city'], 80), (string) $st['wilaya'], $commune,
            (float) $st['lat'], (float) $st['lng'], phone_clean(explode('/', (string) $st['phone'])[0]), strtolower(line_clean($st['email'], 190)),
            line_clean($st['src'], 300), (string) $st['checked'], in_array($st['precision'], ['lieu', 'rue', 'commune'], true) ? $st['precision'] : 'commune'];
        if ($row) {
            $sids[$st['ext']] = (int) $row['id'];
            if (isset($managed[(int) $row['company_id']])) continue;
            $pdo->prepare('UPDATE stores SET name = ?, address = ?, city = ?, wilaya = ?, commune = ?, lat = ?, lng = ?, phone = ?, email = ?, src = ?, checked = ?, precision = ? WHERE id = ?')
                ->execute(array_merge($vals, [$row['id']]));
        } else {
            // Ni retrait ni réservation ni livraison : rien ne se commande dans un magasin qui ne gère pas sa fiche.
            $pdo->prepare("INSERT INTO stores (company_id, name, address, city, wilaya, commune, lat, lng, phone, email, src, checked, precision, type, pickup, delivery, reserve, status, ext, created)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'magasin', 0, 0, 0, 'active', ?, ?)")
                ->execute(array_merge([$cid], $vals, [$st['ext'], $now]));
            $sids[$st['ext']] = (int) $pdo->lastInsertId();
        }
    }
    $ins = $pdo->prepare('INSERT OR IGNORE INTO store_brands (store_id, brand_id) VALUES (?, ?)');
    foreach ($d['store_brands'] as $r) {
        if (isset($sids[$r['store']], $bids[$r['brand']])) $ins->execute([$sids[$r['store']], $bids[$r['brand']]]);
    }

    // Produits (une déclinaison sans stock : la disponibilité n'est pas connue).
    $pids = [];
    foreach ($d['products'] as $p) {
        $cid = $cids[$p['company']] ?? 0;
        $bid = $bids[$p['brand']] ?? 0;
        if (!$cid || !$bid) continue;
        $row = $find('products', (string) $p['ext']);
        if ($row && isset($managed[(int) $row['company_id']])) { $pids[$p['ext']] = (int) $row['id']; continue; }
        $img = $p['photo'] !== '' ? import_photo($cid, (string) $p['photo']) : '';
        if ($img !== '') $n['photos']++;
        $images = $img !== '' ? [$img] : [];
        $specs = [];
        foreach ((array) $p['specs'] as $sp) $specs[] = ['k' => line_clean($sp['k'], 40), 'v' => line_clean($sp['v'], 120)];
        $name = line_clean($p['name'], 120);
        $cat = isset(MLG_CATEGORIES[$p['category']]) ? $p['category'] : 'autre';
        $vals = [$bid, $name, $cat, str_clean($p['about'], 3000), json_encode($images), json_encode($specs, JSON_UNESCAPED_UNICODE),
            line_clean($p['src'], 300), line_clean($p['official'], 300), (string) $p['checked'], $now];
        if ($row) {
            $id = (int) $row['id'];
            $pdo->prepare("UPDATE products SET brand_id = ?, name = ?, category = ?, about = ?, images = ?, specs = ?, src = ?, official = ?, checked = ?, updated = ?, status = 'active' WHERE id = ?")
                ->execute(array_merge($vals, [$id]));
        } else {
            $pdo->prepare("INSERT INTO products (company_id, brand_id, name, slug, category, about, price, images, specs, src, official, checked, status, ext, created, updated)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, 'active', ?, ?, ?)")
                ->execute([$cid, $bid, $name, unique_slug('products', $name), $cat, $vals[3], $vals[4], $vals[5], $vals[6], $vals[7], $vals[8], $p['ext'], $now, $now]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO variants (product_id, sku, size, color, color_hex, price, pos) VALUES (?, '', '', '', '', NULL, 0)")->execute([$id]);
        }
        $pids[$p['ext']] = $id;
        $s = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $s->execute([$id]);
        $brandName = (string) $pdo->query('SELECT name FROM brands WHERE id = ' . $bid)->fetchColumn();
        $pdo->prepare('UPDATE products SET search = ? WHERE id = ?')->execute([product_search_text($s->fetch(), $brandName, variants_of([$id])[$id] ?? []), $id]);
        try { vis_index_product($id, $images); } catch (Throwable $e) { error_log('maillage signatures: ' . $e->getMessage()); }
    }

    // Offres en ligne
    foreach ($d['offers'] as $o) {
        $pid = $pids[$o['product']] ?? 0;
        $cid = $cids[$o['company']] ?? 0;
        if (!$pid || !$cid || !preg_match('~^https?://~', (string) $o['url'])) continue;
        $row = $find('offers', (string) $o['ext']);
        $vals = [$pid, $cid, line_clean($o['url'], 400), line_clean($o['status'], 60), (string) $o['checked']];
        if ($row) {
            $pdo->prepare('UPDATE offers SET product_id = ?, company_id = ?, url = ?, status = ?, checked = ? WHERE id = ?')->execute(array_merge($vals, [$row['id']]));
        } else {
            $pdo->prepare('INSERT INTO offers (product_id, company_id, url, status, checked, ext) VALUES (?, ?, ?, ?, ?, ?)')->execute(array_merge($vals, [$o['ext']]));
        }
    }
    return $n + ['products' => count($pids), 'stores' => count($sids), 'at' => $now];
}
