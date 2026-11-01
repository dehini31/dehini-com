<?php
/** Catalogue : produits, collections, guides, wilayas et communes. */
declare(strict_types=1);

/** Produit prêt à l'emploi : colonnes + document JSON, offres triées par quantité. */
function product_from_row(array $r): array
{
    $d = json_decode((string) $r['data'], true) ?: [];
    $p = $d + [
        'name' => [], 'tagline' => [], 'short' => [], 'description' => [], 'highlights' => [], 'contents' => [], 'specs' => [],
        'packaging' => [], 'tiers' => [], 'images' => [], 'videos' => [], 'seo' => [],
    ];
    $p['id'] = (int) $r['id'];
    $p['slug'] = (string) $r['slug'];
    $p['sku'] = (string) $r['sku'];
    $p['status'] = (string) $r['status'];
    $p['featured'] = (int) $r['featured'];
    $p['pos'] = (int) $r['pos'];
    $p['price'] = (int) $r['price'];
    $p['compare_price'] = (int) $r['compare_price'];
    $p['stock'] = $r['stock'] === null ? null : (int) $r['stock'];
    $p['stock_alert'] = (int) $r['stock_alert'];
    $p['created'] = (int) $r['created'];
    $p['updated'] = (int) $r['updated'];
    $tiers = [];
    foreach ((array) $p['tiers'] as $t) {
        $q = (int) ($t['qty'] ?? 0);
        $pr = (int) ($t['price'] ?? 0);
        if ($q < 1 || $pr < 1) continue;
        $tiers[$q] = [
            'qty' => $q, 'price' => $pr, 'compare' => (int) ($t['compare'] ?? 0), 'default' => !empty($t['default']),
            'label' => (array) ($t['label'] ?? []), 'badge' => (array) ($t['badge'] ?? []),
        ];
    }
    ksort($tiers);
    if (!$tiers) $tiers[1] = ['qty' => 1, 'price' => max(1, $p['price']), 'compare' => $p['compare_price'], 'default' => true, 'label' => [], 'badge' => []];
    $p['tiers'] = array_values($tiers);
    if (!array_filter($p['tiers'], fn($t) => $t['default'])) $p['tiers'][0]['default'] = true;
    return $p;
}

function product_by_slug(string $slug, bool $withDrafts = false): ?array
{
    $s = db()->prepare('SELECT * FROM products WHERE slug = ?' . ($withDrafts ? '' : " AND status = 'active'"));
    $s->execute([$slug]);
    $r = $s->fetch();
    return $r ? product_from_row($r) : null;
}

function product_by_id(int $id): ?array
{
    $s = db()->prepare('SELECT * FROM products WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ? product_from_row($r) : null;
}

/**
 * Produits en ligne. $opt : collection (id), sort (reco|price-asc|price-desc|new), stock (bool), limit.
 * @return array<int, array>
 */
function products_list(array $opt = []): array
{
    $sql = "SELECT p.* FROM products p";
    $args = [];
    if (!empty($opt['collection'])) {
        $sql .= ' JOIN product_collections pc ON pc.pid = p.id AND pc.cid = ?';
        $args[] = (int) $opt['collection'];
    }
    $sql .= " WHERE p.status = 'active'";
    if (!empty($opt['stock'])) $sql .= ' AND (p.stock IS NULL OR p.stock > 0)';
    if (!empty($opt['exclude'])) {
        $sql .= ' AND p.id <> ?';
        $args[] = (int) $opt['exclude'];
    }
    $order = [
        'price-asc' => 'p.price ASC, p.pos', 'price-desc' => 'p.price DESC, p.pos', 'new' => 'p.created DESC',
    ][$opt['sort'] ?? ''] ?? 'p.featured DESC, p.pos, p.id';
    $sql .= ' ORDER BY ' . $order;
    if (!empty($opt['limit'])) $sql .= ' LIMIT ' . (int) $opt['limit'];
    $s = db()->prepare($sql);
    $s->execute($args);
    return array_map('product_from_row', $s->fetchAll());
}

/** État du stock : untracked | in | low | out. */
function stock_state(array $p): string
{
    if ($p['stock'] === null) return 'untracked';
    if ($p['stock'] <= 0) return setting('allow_backorder') === '1' ? 'in' : 'out';
    return $p['stock'] <= $p['stock_alert'] ? 'low' : 'in';
}

function product_tier(array $p, int $qty): ?array
{
    foreach ($p['tiers'] as $t) {
        if ($t['qty'] === $qty) return $t;
    }
    return null;
}

function default_tier(array $p): array
{
    foreach ($p['tiers'] as $t) {
        if ($t['default']) return $t;
    }
    return $p['tiers'][0];
}

/** Meilleure économie proposée par les offres (pour les badges). */
function best_saving(array $p): int
{
    $best = 0;
    foreach ($p['tiers'] as $t) {
        if ($t['compare'] > $t['price']) $best = max($best, $t['compare'] - $t['price']);
    }
    return $best;
}

function product_main_image(array $p): ?array
{
    return $p['images'][0] ?? null;
}

/* ---------------------------------------------------------------- collections */

function collection_from_row(array $r): array
{
    $d = json_decode((string) $r['data'], true) ?: [];
    return $d + ['name' => [], 'description' => [], 'image' => null, 'seo' => []] + [
        'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'active' => (int) $r['active'], 'pos' => (int) $r['pos'],
        'count' => isset($r['n']) ? (int) $r['n'] : 0, 'updated' => (int) $r['updated'],
    ];
}

/** Collections en ligne qui contiennent au moins un produit en ligne. */
function collections_list(bool $all = false): array
{
    $sql = "SELECT c.*, (SELECT COUNT(*) FROM product_collections pc JOIN products p ON p.id = pc.pid AND p.status = 'active' WHERE pc.cid = c.id) AS n
            FROM collections c" . ($all ? '' : ' WHERE c.active = 1') . ' ORDER BY c.pos, c.id';
    $rows = array_map('collection_from_row', db()->query($sql)->fetchAll());
    return $all ? $rows : array_values(array_filter($rows, fn($c) => $c['count'] > 0));
}

function collection_by_slug(string $slug): ?array
{
    $s = db()->prepare('SELECT * FROM collections WHERE slug = ? AND active = 1');
    $s->execute([$slug]);
    $r = $s->fetch();
    return $r ? collection_from_row($r) : null;
}

function product_collection_ids(int $pid): array
{
    $s = db()->prepare('SELECT cid FROM product_collections WHERE pid = ?');
    $s->execute([$pid]);
    return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/* -------------------------------------------------------------------- guides */

function article_from_row(array $r): array
{
    $d = json_decode((string) $r['data'], true) ?: [];
    return $d + ['title' => [], 'excerpt' => [], 'body' => [], 'cover' => null, 'seo' => []] + [
        'id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'status' => (string) $r['status'],
        'published' => (int) $r['published'], 'updated' => (int) $r['updated'], 'created' => (int) $r['created'],
    ];
}

/** Un guide existe dans une langue si son titre et son texte y sont écrits. */
function article_has_lang(array $a, string $lang): bool
{
    return trim((string) ($a['title'][$lang] ?? '')) !== '' && trim((string) ($a['body'][$lang] ?? '')) !== '';
}

function articles_list(string $lang, int $limit = 0): array
{
    $rows = db()->query("SELECT * FROM articles WHERE status = 'published' AND published <= " . time() . ' ORDER BY published DESC, id DESC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $a = article_from_row($r);
        if (!article_has_lang($a, $lang)) continue;
        $out[] = $a;
        if ($limit && count($out) >= $limit) break;
    }
    return $out;
}

function article_by_slug(string $slug): ?array
{
    $s = db()->prepare("SELECT * FROM articles WHERE slug = ? AND status = 'published' AND published <= ?");
    $s->execute([$slug, time()]);
    $r = $s->fetch();
    return $r ? article_from_row($r) : null;
}

/* ----------------------------------------------------------- wilayas, communes */

function wilaya_get(string $code): ?array
{
    $s = db()->prepare('SELECT * FROM wilayas WHERE code = ?');
    $s->execute([$code]);
    $r = $s->fetch();
    return $r ?: null;
}

/** Nom canonique (français) de la commune si elle appartient à la wilaya, sinon ''. */
function commune_match(string $wilaya, string $name): string
{
    $name = one_line($name, 80);
    if ($name === '') return '';
    $s = db()->prepare('SELECT fr FROM communes WHERE wilaya = ? AND (fr = ? COLLATE NOCASE OR ar = ?) LIMIT 1');
    $s->execute([$wilaya, $name, $name]);
    $v = $s->fetchColumn();
    return $v === false ? '' : (string) $v;
}

function commune_count(string $wilaya): int
{
    $s = db()->prepare('SELECT COUNT(*) FROM communes WHERE wilaya = ?');
    $s->execute([$wilaya]);
    return (int) $s->fetchColumn();
}

/** Wilayas et communes pour le formulaire (mis en cache par le navigateur). */
function geo_payload(): array
{
    $w = db()->query('SELECT * FROM wilayas WHERE active = 1 ORDER BY pos, code')->fetchAll();
    $c = db()->query('SELECT wilaya, fr, ar FROM communes ORDER BY wilaya, fr')->fetchAll();
    $by = [];
    foreach ($c as $r) $by[$r['wilaya']][] = [$r['fr'], $r['ar']];
    $out = [];
    foreach ($w as $r) {
        $out[] = ['c' => $r['code'], 'fr' => $r['fr'], 'ar' => $r['ar'], 'h' => (int) $r['fee_home'], 'd' => (int) $r['fee_desk'],
            'dl' => $r['delay'], 'm' => $by[$r['code']] ?? []];
    }
    return $out;
}

function geo_version(): string
{
    return meta_get('geo_v') ?? '1';
}

/** Plus petits frais de livraison à domicile (pour l'affichage et Schema.org). */
function min_shipping_fee(): int
{
    return (int) db()->query('SELECT COALESCE(MIN(fee_home), 0) FROM wilayas WHERE active = 1')->fetchColumn();
}

function max_shipping_fee(): int
{
    return (int) db()->query('SELECT COALESCE(MAX(fee_home), 0) FROM wilayas WHERE active = 1')->fetchColumn();
}

function active_wilaya_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM wilayas WHERE active = 1')->fetchColumn();
}
