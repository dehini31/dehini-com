<?php
/**
 * Recherche active : un client qui n'a pas trouvé un produit (recherche par photo) demande au
 * réseau de le chercher. C'est le prolongement du radar des commandes (radar.php) :
 *   - la demande (photo, description, commune du client) apparaît dans le Radar des points de
 *     vente, dans le même ordre : ceux de sa commune, puis de sa wilaya, puis tous, avec le même
 *     délai d'élargissement ; les marques du rayon concerné la voient tout de suite ;
 *   - un point de vente ou une marque répond « j'ai ce produit » (magasin, fiche du catalogue,
 *     prix, message) ou l'écarte ;
 *   - le client suit sa recherche comme une commande (numéro RS-XXXXXX + téléphone, ou lien) et
 *     voit les réponses ; il la clôt quand il a trouvé.
 * Son téléphone n'est montré qu'aux comptes qui ont répondu, et seulement s'il l'a accepté.
 */
declare(strict_types=1);

const RESEARCH_STATUSES = ['open' => 'Recherche en cours', 'found' => 'Produit trouvé', 'closed' => 'Recherche arrêtée', 'expired' => 'Recherche terminée'];

function research_ref(): string
{
    $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $s = db()->prepare('SELECT 1 FROM research WHERE ref = ?');
    for ($i = 0; $i < 20; $i++) {
        $ref = 'RS-';
        for ($j = 0; $j < 6; $j++) $ref .= $abc[random_int(0, 31)];
        $s->execute([$ref]);
        if (!$s->fetchColumn()) return $ref;
    }
    throw new RuntimeException('ref');
}

/** Range la photo d'une recherche dans media/r/ (ré-encodée, sans métadonnées). Retourne son chemin sans suffixe. */
function research_image($im): string
{
    $dir = MLG_ROOT . '/media/r';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Dossier media/ impossible à créer');
    $id = bin2hex(random_bytes(8));
    image_write($im, $dir, $id);
    return 'media/r/' . $id;
}
function research_image_delete(string $path): void
{
    if (!preg_match('~^media/r/[a-f0-9]{16}$~', $path)) return;
    foreach (MEDIA_WIDTHS as $tw) @unlink(MLG_ROOT . '/' . $path . '-' . $tw . '.webp');
}

/**
 * Lance une recherche. $b : name, category, brand, details, cname, phone, email, zone, commune,
 * call_ok. Retourne ['research' => ligne, 'token' => jeton de suivi].
 */
function research_create(array $b, string $image, bool $auto, bool $demo = false, ?int $at = null): array
{
    $pdo = db();
    $now = $at ?? time();
    $info = vis_info_clean($b);
    if (mb_strlen($info['name']) < 2) throw new InvalidArgumentException('Indiquez le nom du produit recherché.');
    $cname = line_clean($b['cname'] ?? '', 80);
    $phone = phone_clean($b['phone'] ?? '');
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    if (mb_strlen($cname) < 2) throw new InvalidArgumentException('Indiquez votre nom.');
    if (!phone_ok($phone)) throw new InvalidArgumentException('Indiquez un numéro de téléphone valide.');
    if ($email !== '' && !valid_email($email)) throw new InvalidArgumentException('Adresse email invalide.');
    $zone = zone_code($b['zone'] ?? '');
    $commune = $zone !== '' ? commune_clean($zone, $b['commune'] ?? '') : '';
    if ($zone === '' || $commune === '') throw new InvalidArgumentException('Choisissez votre wilaya puis votre commune.');
    $token = token(20);
    $ref = research_ref();
    $pdo->prepare('INSERT INTO research (ref, thash, name, category, brand, details, image, auto, cname, cphone, cemail, call_ok, wilaya, commune, status, demo, created, updated, expires)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $ref, hash('sha256', $token), $info['name'], $info['category'] !== '' ? $info['category'] : 'autre', $info['brand'], $info['details'], $image, $auto ? 1 : 0,
        $cname, $phone, $email, !empty($b['call_ok']) ? 1 : 0, $zone, $commune, 'open', $demo ? 1 : 0, $now, $now, $now + (int) MLG_RESEARCH_DAYS * 86400,
    ]);
    $s = $pdo->prepare('SELECT * FROM research WHERE id = ?');
    $s->execute([(int) $pdo->lastInsertId()]);
    return ['research' => $s->fetch(), 'token' => $token];
}

/** Points de vente en ligne (vrais, ou de la démonstration pour une recherche d'exemple). */
function research_stores(bool $demo): array
{
    static $cache = [];
    $k = $demo ? 1 : 0;
    if (!isset($cache[$k])) {
        $cache[$k] = db()->query("SELECT s.id, s.company_id, s.name, s.wilaya, s.commune, s.email, s.phone, c.name AS c_name, c.email AS c_email, c.kind AS c_kind
            FROM stores s JOIN companies c ON c.id = s.company_id
            WHERE s.status IN ('active', 'unlocated') AND c.status = 'active' AND c.demo = $k")->fetchAll();
    }
    return $cache[$k];
}

/** Diffusion d'une recherche : niveau atteint (1 commune, 2 wilaya, 3 tout le réseau), points de vente par niveau, prochain élargissement. */
function research_state(array $r, ?int $now = null): array
{
    $now = $now ?? time();
    $counts = [1 => 0, 2 => 0, 3 => 0];
    foreach (research_stores((bool) $r['demo']) as $st) $counts[radar_rank($st, (string) $r['wilaya'], (string) $r['commune'])]++;
    $base = $counts[1] ? 1 : ($counts[2] ? 2 : ($counts[3] ? 3 : 0));
    $step = radar_step();
    $level = 0;
    $next = 0;
    if ($base) {
        $level = $step > 0 ? min(3, $base + intdiv(max(0, $now - (int) $r['created']), $step)) : 3;
        if ($level < 3) $next = (int) $r['created'] + ($level - $base + 1) * $step;
    }
    $reached = 0;
    for ($i = 1; $i <= $level; $i++) $reached += $counts[$i];
    return ['level' => $level, 'counts' => $counts, 'next_at' => $next, 'reached' => $reached];
}

/** Une marque voit les recherches de ses rayons (ou sans rayon précis). */
function research_brand_match(int $cid, string $category): bool
{
    if ($category === '' || $category === 'autre') return true;
    $s = db()->prepare('SELECT 1 FROM brands WHERE company_id = ? AND category = ? UNION SELECT 1 FROM products WHERE company_id = ? AND category = ? LIMIT 1');
    $s->execute([$cid, $category, $cid, $category]);
    return (bool) $s->fetchColumn();
}

/** Points de vente du compte qu'une recherche a atteints : [['id', 'name', 'rank'], …] (le plus proche d'abord). */
function research_my_stores(array $r, array $state, int $cid, int $storeId): array
{
    $mine = [];
    foreach (research_stores((bool) $r['demo']) as $st) {
        if ((int) $st['company_id'] !== $cid || ($storeId && (int) $st['id'] !== $storeId)) continue;
        $rank = radar_rank($st, (string) $r['wilaya'], (string) $r['commune']);
        if ($rank <= $state['level']) $mine[] = ['id' => (int) $st['id'], 'name' => (string) $st['name'], 'rank' => $rank];
    }
    usort($mine, function ($a, $b) { return $a['rank'] <=> $b['rank'] ?: $a['id'] <=> $b['id']; });
    return $mine;
}

/**
 * Recherches ouvertes visibles par un compte (entreprise, ou un seul point de vente), celles
 * qu'il a écartées en moins. Les coordonnées du client n'y figurent qu'après une réponse.
 */
function research_nearby(int $cid, string $kind, int $storeId = 0, int $onlyId = 0): array
{
    $pdo = db();
    $demo = (int) (company_row($cid)['demo'] ?? 0);
    $replies = [];
    $s = $pdo->prepare('SELECT * FROM research_replies WHERE company_id = ?' . ($storeId ? ' AND store_id = ' . $storeId : '') . ' ORDER BY id');
    $s->execute([$cid]);
    foreach ($s as $rp) $replies[(int) $rp['research_id']][] = $rp;
    $total = [];
    foreach ($pdo->query("SELECT research_id, COUNT(*) AS n FROM research_replies WHERE kind = 'have' GROUP BY research_id") as $t) $total[(int) $t['research_id']] = (int) $t['n'];
    $q = $pdo->prepare("SELECT * FROM research WHERE status = 'open' AND demo = ?" . ($onlyId ? ' AND id = ' . $onlyId : '') . ' ORDER BY created DESC, id DESC LIMIT 200');
    $q->execute([$demo]);
    $out = [];
    $now = time();
    foreach ($q as $r) {
        $mineReplies = $replies[(int) $r['id']] ?? [];
        $have = null;
        $none = false;
        foreach ($mineReplies as $rp) {
            if ($rp['kind'] === 'have') $have = $rp; else $none = true;
        }
        if ($none && !$have) continue;
        $state = research_state($r, $now);
        $stores = research_my_stores($r, $state, $cid, $storeId);
        $asBrand = !$storeId && $kind === 'brand' && research_brand_match($cid, (string) $r['category']);
        if (!$stores && !$asBrand && !$have) continue;
        $v = [
            'id' => (int) $r['id'], 'ref' => (string) $r['ref'], 'created' => (int) $r['created'], 'name' => (string) $r['name'], 'category' => (string) $r['category'],
            'brand' => (string) $r['brand'], 'details' => (string) $r['details'], 'image' => (string) $r['image'], 'auto' => (bool) $r['auto'],
            'wilaya' => (string) $r['wilaya'], 'zone' => zone_name((string) $r['wilaya']), 'commune' => (string) $r['commune'],
            'commune_ar' => commune_ar((string) $r['wilaya'], (string) $r['commune']),
            'rank' => $stores ? $stores[0]['rank'] : 3, 'stores' => $stores, 'replies' => $total[(int) $r['id']] ?? 0, 'replied' => null, 'contact' => null,
        ];
        if ($have) {
            $v['replied'] = ['store_id' => (int) $have['store_id'], 'product_id' => (int) $have['product_id'], 'price' => $have['price'] !== null ? (float) $have['price'] : null,
                'message' => (string) $have['message'], 'created' => (int) $have['created']];
            if ((int) $r['call_ok']) $v['contact'] = ['name' => (string) $r['cname'], 'phone' => (string) $r['cphone']];
        }
        $out[] = $v;
    }
    usort($out, function ($a, $b) { return ($a['replied'] ? 1 : 0) <=> ($b['replied'] ? 1 : 0) ?: $a['rank'] <=> $b['rank'] ?: $b['created'] <=> $a['created']; });
    return $out;
}

/** Recherches qui attendent encore une réponse de ce compte (pastille du menu). */
function research_count(int $cid, string $kind, int $storeId = 0): int
{
    $n = 0;
    foreach (research_nearby($cid, $kind, $storeId) as $r) { if (!$r['replied']) $n++; }
    return $n;
}

/**
 * Réponse « j'ai ce produit ». $b : store_id, product_id (fiche du catalogue, facultative), price,
 * message. Retourne la recherche telle que ce compte la voit maintenant.
 */
function research_reply(int $cid, string $kind, int $rid, array $b, int $onlyStore = 0): array
{
    $pdo = db();
    $seen = research_nearby($cid, $kind, $onlyStore, $rid);
    if (!$seen) throw new InvalidArgumentException('Cette recherche n\'est plus ouverte.');
    $sid = $onlyStore ?: (int) ($b['store_id'] ?? 0);
    if ($sid && !own_row('stores', $cid, $sid)) throw new InvalidArgumentException('Point de vente introuvable.');
    $pid = (int) ($b['product_id'] ?? 0);
    if ($pid) {
        $s = $pdo->prepare("SELECT company_id FROM products WHERE id = ? AND status = 'active'");
        $s->execute([$pid]);
        $pc = (int) $s->fetchColumn();
        if (!$pc || !in_array($pc, stockable_cids($cid, $kind), true)) throw new InvalidArgumentException('Ce produit ne fait pas partie de votre catalogue.');
    }
    $price = price_in($b['price'] ?? null);
    $now = time();
    tx_begin();
    try {
        // Une réponse par compte (ou par point de vente pour un accès magasin) : la nouvelle remplace l'ancienne.
        $pdo->prepare('DELETE FROM research_replies WHERE research_id = ? AND company_id = ?' . ($onlyStore ? ' AND store_id = ' . $onlyStore : ''))->execute([$rid, $cid]);
        $pdo->prepare("INSERT INTO research_replies (research_id, company_id, store_id, product_id, kind, price, message, created) VALUES (?, ?, ?, ?, 'have', ?, ?, ?)")
            ->execute([$rid, $cid, $sid, $pid, $price, str_clean($b['message'] ?? '', 300), $now]);
        $pdo->prepare('UPDATE research SET updated = ? WHERE id = ?')->execute([$now, $rid]);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
    log_activity($cid, 'radar', 'Réponse à la recherche ' . $seen[0]['ref'] . ' · ' . $seen[0]['name'], $sid, 0, $now);
    return research_nearby($cid, $kind, $onlyStore, $rid)[0];
}

/** Écarte une recherche (« je ne l'ai pas ») : elle disparaît du Radar de ce compte ; une réponse déjà donnée est retirée. */
function research_dismiss(int $cid, int $rid, int $onlyStore = 0): void
{
    $pdo = db();
    tx_begin();
    try {
        $pdo->prepare('DELETE FROM research_replies WHERE research_id = ? AND company_id = ?' . ($onlyStore ? ' AND store_id = ' . $onlyStore : ''))->execute([$rid, $cid]);
        $pdo->prepare("INSERT INTO research_replies (research_id, company_id, store_id, kind, created) VALUES (?, ?, ?, 'none', ?)")->execute([$rid, $cid, $onlyStore, time()]);
        tx_commit();
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
}

/** Ce que voit le client : état, diffusion et réponses (sans ses propres coordonnées). */
function research_view(array $r): array
{
    $pdo = db();
    $state = $r['status'] === 'open' ? research_state($r) : ['level' => 0, 'counts' => [1 => 0, 2 => 0, 3 => 0], 'next_at' => 0, 'reached' => 0];
    $s = $pdo->prepare("SELECT rp.*, c.name AS c_name, c.kind AS c_kind, c.status AS c_status, s.name AS s_name, s.address AS s_address, s.city AS s_city, s.commune AS s_commune,
            s.wilaya AS s_wilaya, s.phone AS s_phone, s.lat AS s_lat, s.lng AS s_lng, s.hours AS s_hours, s.status AS s_status
        FROM research_replies rp JOIN companies c ON c.id = rp.company_id LEFT JOIN stores s ON s.id = rp.store_id
        WHERE rp.research_id = ? AND rp.kind = 'have' ORDER BY rp.created");
    $s->execute([(int) $r['id']]);
    $rows = $s->fetchAll();
    $cards = [];
    $pids = array_values(array_filter(array_map(function ($x) { return (int) $x['product_id']; }, $rows)));
    if ($pids) {
        $in = implode(',', $pids);
        $products = $pdo->query("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo, c.listing AS c_listing
            FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id
            WHERE p.id IN ($in) AND p.status = 'active' AND c.status = 'active'")->fetchAll();
        $variants = variants_of($pids);
        $counts = product_store_counts();
        foreach ($products as $p) $cards[(int) $p['id']] = product_card($p, $variants[(int) $p['id']] ?? [], $counts[(int) $p['id']] ?? 0);
    }
    $replies = [];
    foreach ($rows as $rp) {
        if ($rp['c_status'] !== 'active') continue;
        $store = null;
        if ((int) $rp['store_id'] && $rp['s_name'] !== null) {
            $store = ['id' => (int) $rp['store_id'], 'name' => (string) $rp['s_name'], 'address' => (string) $rp['s_address'], 'city' => (string) $rp['s_city'],
                'commune' => (string) $rp['s_commune'], 'commune_ar' => commune_ar((string) $rp['s_wilaya'], (string) $rp['s_commune']), 'wilaya' => (string) $rp['s_wilaya'],
                'zone' => zone_name((string) $rp['s_wilaya']), 'phone' => (string) $rp['s_phone'], 'lat' => (float) $rp['s_lat'], 'lng' => (float) $rp['s_lng'],
                'hours' => hours_clean(jdec($rp['s_hours'])), 'listed' => $rp['s_status'] === 'active'];
        }
        $replies[] = ['company' => (string) $rp['c_name'], 'kind' => (string) $rp['c_kind'], 'store' => $store, 'product' => $cards[(int) $rp['product_id']] ?? null,
            'price' => $rp['price'] !== null ? (float) $rp['price'] : null, 'message' => (string) $rp['message'], 'created' => (int) $rp['created']];
    }
    return [
        'id' => (int) $r['id'], 'ref' => (string) $r['ref'], 'status' => (string) $r['status'], 'created' => (int) $r['created'], 'updated' => (int) $r['updated'], 'expires' => (int) $r['expires'],
        'name' => (string) $r['name'], 'category' => (string) $r['category'], 'brand' => (string) $r['brand'], 'details' => (string) $r['details'], 'image' => (string) $r['image'],
        'auto' => (bool) $r['auto'], 'wilaya' => (string) $r['wilaya'], 'zone' => zone_name((string) $r['wilaya']), 'commune' => (string) $r['commune'],
        'commune_ar' => commune_ar((string) $r['wilaya'], (string) $r['commune']),
        'reach' => ['level' => $state['level'], 'stores' => $state['reached'], 'next_at' => $state['next_at']],
        'replies' => $replies,
    ];
}

/** Recherche retrouvée par son lien de suivi, ou par son numéro et le téléphone donné. */
function research_find(string $token, string $ref, string $phone): ?array
{
    $pdo = db();
    if (preg_match('/^[a-f0-9]{40}$/', $token)) {
        $s = $pdo->prepare('SELECT * FROM research WHERE thash = ?');
        $s->execute([hash('sha256', $token)]);
        return $s->fetch() ?: null;
    }
    $s = $pdo->prepare('SELECT * FROM research WHERE ref = ?');
    $s->execute([strtoupper(mb_substr($ref, 0, 12))]);
    $r = $s->fetch() ?: null;
    if ($r && (phone_key($phone) === '' || phone_key($phone) !== phone_key((string) $r['cphone']))) return null;
    return $r;
}

/** Le client clôt sa recherche : « found » (il a trouvé) ou « closed » (il arrête). */
function research_close(array $r, string $status): array
{
    if ($r['status'] !== 'open') throw new InvalidArgumentException('Cette recherche est déjà terminée.');
    $status = $status === 'found' ? 'found' : 'closed';
    db()->prepare('UPDATE research SET status = ?, updated = ? WHERE id = ? AND status = ?')->execute([$status, time(), $r['id'], 'open']);
    $r['status'] = $status;
    $r['updated'] = time();
    // Trouvée, et les réponses désignent une seule fiche du catalogue : la photo du client est rattachée à ce produit.
    if ($status === 'found' && $r['image'] !== '') {
        $s = db()->prepare("SELECT DISTINCT product_id FROM research_replies WHERE research_id = ? AND kind = 'have' AND product_id > 0");
        $s->execute([$r['id']]);
        $pids = $s->fetchAll(PDO::FETCH_COLUMN);
        if (count($pids) === 1) {
            try { vis_learn((int) $pids[0], (string) $r['image']); } catch (Throwable $e) { error_log('maillage signatures: ' . $e->getMessage()); }
        }
    }
    return $r;
}

/** Recherches arrivées à leur terme, et photos des recherches closes depuis un mois. Au plus toutes les 30 minutes. */
function research_expire(): void
{
    $last = (int) (meta_get('research_run') ?? 0);
    if ($last > time() - 1800) return;
    meta_set('research_run', (string) time());
    $pdo = db();
    $pdo->prepare("UPDATE research SET status = 'expired', updated = ? WHERE status = 'open' AND expires > 0 AND expires < ?")->execute([time(), time()]);
    $s = $pdo->prepare("SELECT id, image FROM research WHERE status <> 'open' AND image <> '' AND updated < ? LIMIT 50");
    $s->execute([time() - 30 * 86400]);
    foreach ($s->fetchAll() as $r) {
        research_image_delete((string) $r['image']);
        $pdo->prepare("UPDATE research SET image = '' WHERE id = ?")->execute([$r['id']]);
    }
}

/* ---------------------------------------------------------------- emails */

function research_track_url(string $token): string
{
    return site_url() . 'explorer/#/recherche/' . $token;
}

/** Prévient les points de vente du premier niveau et les marques du rayon, et confirme au client. */
function research_notify_created(array $res): void
{
    $r = $res['research'];
    if ((int) $r['demo']) return;
    $state = research_state($r);
    $where = $r['commune'] . ', ' . zone_name((string) $r['wilaya']);
    $what = $r['name'] . ((string) $r['brand'] !== '' ? ' — ' . $r['brand'] : '');
    $lines = [$what, (string) $r['details'] !== '' ? (string) $r['details'] : (MLG_CATEGORIES[$r['category']] ?? ''), 'Client : ' . $where,
        'Si vous avez ce produit, répondez depuis votre Radar : le client voit votre réponse tout de suite.'];
    $sent = [];
    $n = 0;
    foreach (research_stores(false) as $st) {
        if ($n >= 15) break;
        if (radar_rank($st, (string) $r['wilaya'], (string) $r['commune']) > $state['level']) continue;
        $to = store_contact($st);
        if ($to === '' || isset($sent[$to])) continue;
        $sent[$to] = true;
        $n++;
        try_mail($to, 'Un client cherche : ' . $r['name'], 'Un client cherche un produit près de chez vous', $lines, 'Ouvrir le radar', site_url() . 'espace/#/radar');
    }
    $n = 0;
    foreach (db()->query("SELECT id, email FROM companies WHERE kind = 'brand' AND status = 'active' AND demo = 0 AND email <> ''") as $c) {
        if ($n >= 10) break;
        $to = (string) $c['email'];
        if (isset($sent[$to]) || !research_brand_match((int) $c['id'], (string) $r['category'])) continue;
        $sent[$to] = true;
        $n++;
        try_mail($to, 'Un client cherche : ' . $r['name'], 'Un client cherche un produit de votre rayon', $lines, 'Ouvrir le radar', site_url() . 'espace/#/radar');
    }
    if ((string) $r['cemail'] !== '') {
        try_mail((string) $r['cemail'], 'Votre recherche ' . $r['ref'], 'Votre recherche est lancée', [
            'Numéro : ' . $r['ref'],
            $what,
            'Nous la transmettons aux points de vente proches de chez vous et aux marques concernées. Vous recevez un email à chaque réponse.',
        ], 'Suivre ma recherche', research_track_url($res['token']));
    }
}

/** Prévient le client d'une réponse (s'il a laissé son email). Le lien de suivi n'est pas renvoyé : seul son hash est gardé. */
function research_notify_reply(int $rid, int $cid, int $storeId): void
{
    $pdo = db();
    $s = $pdo->prepare('SELECT * FROM research WHERE id = ?');
    $s->execute([$rid]);
    $r = $s->fetch();
    if (!$r || (int) $r['demo'] || (string) $r['cemail'] === '') return;
    $who = (string) (company_row($cid)['name'] ?? '');
    if ($storeId) {
        $q = $pdo->prepare('SELECT name, city, phone FROM stores WHERE id = ?');
        $q->execute([$storeId]);
        if ($st = $q->fetch()) $who = $st['name'] . ' (' . $st['city'] . ')' . ((string) $st['phone'] !== '' ? ' · ' . $st['phone'] : '');
    }
    try_mail((string) $r['cemail'], 'Une réponse à votre recherche ' . $r['ref'], 'Un point de vente a votre produit', [
        $r['name'],
        $who . ' indique avoir ce produit.',
        'Pour voir sa réponse, ouvrez « Suivi » sur la plateforme et saisissez le numéro ' . $r['ref'] . ' avec votre téléphone.',
    ], 'Voir la réponse', site_url() . 'explorer/#/suivi');
}
