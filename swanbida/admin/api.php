<?php
/**
 * API de l'administration SWANBIDA. Toute action demande une session valide ; les écritures
 * demandent en plus le jeton anti-CSRF (en-tête X-CSRF) et le droit correspondant au rôle.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/i18n.php';
require dirname(__DIR__) . '/lib/catalog.php';
require dirname(__DIR__) . '/lib/markdown.php';
require dirname(__DIR__) . '/lib/media.php';
require dirname(__DIR__) . '/lib/orders.php';
require dirname(__DIR__) . '/lib/meta.php';
require dirname(__DIR__) . '/lib/notify.php';
require dirname(__DIR__) . '/lib/view.php';
require dirname(__DIR__) . '/lib/auth.php';
require dirname(__DIR__) . '/lib/backup.php';
require dirname(__DIR__) . '/lib/seed.php';
require dirname(__DIR__) . '/lib/drafts.php';
current_lang('fr'); // l'administration est en français

security_headers(true);
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$action = (string) ($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

function fail(string $msg, int $code = 400): void
{
    json_out(['error' => $msg], $code);
}
function ok(array $extra = []): void
{
    json_out(['ok' => true] + $extra);
}

/** Vérifie la session, le droit et (pour une écriture) le jeton anti-CSRF. Retourne l'utilisateur. */
function need(string $right): array
{
    global $isPost;
    admin_session_start();
    $u = admin_user();
    if (!$u) fail('Session expirée, reconnectez-vous.', 401);
    if (!admin_can($u, $right)) fail('Accès refusé pour votre rôle.', 403);
    if ($isPost && !hash_equals((string) $u['csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) fail('Jeton de sécurité invalide, rechargez la page.', 403);
    $_SESSION['swb']['seen'] = time();
    session_write_close();
    return $u;
}

function actor(array $u): string
{
    return $u['role'] === 'owner' ? 'Propriétaire' : $u['name'];
}

function public_admin(array $u): array
{
    return ['name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'rights' => ROLE_RIGHTS[$u['role']] ?? []];
}

function bi($v, int $max = 200, bool $multi = false): array
{
    $v = is_array($v) ? $v : [];
    $out = [];
    foreach (SWB_LANGS as $l) $out[$l] = $multi ? str_clean($v[$l] ?? '', $max) : one_line($v[$l] ?? '', $max);
    return $out;
}

function bi_list($arr, int $max = 200): array
{
    $out = [];
    foreach (is_array($arr) ? $arr : [] as $x) {
        $b = bi($x, $max);
        if ($b['fr'] !== '' || $b['ar'] !== '') $out[] = $b;
    }
    return array_slice($out, 0, 40);
}

function day_start(string $d): int
{
    $t = DateTime::createFromFormat('!Y-m-d', $d);
    return $t ? $t->getTimestamp() : 0;
}

/** Filtres de la liste des commandes → [SQL WHERE, paramètres]. */
function order_filters(array $q, bool $withStatus = true): array
{
    $w = ['1 = 1'];
    $a = [];
    $st = (string) ($q['status'] ?? '');
    if ($withStatus && in_array($st, ORDER_STATUSES, true)) { $w[] = 'status = ?'; $a[] = $st; }
    $s = trim((string) ($q['q'] ?? ''));
    if ($s !== '') {
        $digits = preg_replace('/\D/', '', $s) ?? '';
        $ref = normalize_ref($s);
        $or = ['name LIKE ?', 'address LIKE ?', 'commune LIKE ?', 'ref LIKE ?', 'tracking LIKE ?'];
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $s) . '%';
        array_push($a, $like, $like, $like, $like, $like);
        foreach ($or as $i => $c) $or[$i] = $c . " ESCAPE '\\'";
        if (strlen($digits) >= 4) {
            $or[] = 'phone LIKE ?';
            $a[] = '%' . (strpos($digits, '213') === 0 ? substr($digits, 3) : ltrim($digits, '0')) . '%';
        }
        if ($ref !== '') { $or[] = 'ref = ?'; $a[] = $ref; }
        $w[] = '(' . implode(' OR ', $or) . ')';
    }
    if (!empty($q['wilaya'])) { $w[] = 'wilaya = ?'; $a[] = (string) $q['wilaya']; }
    if (!empty($q['from']) && ($t = day_start((string) $q['from']))) { $w[] = 'created >= ?'; $a[] = $t; }
    if (!empty($q['to']) && ($t = day_start((string) $q['to']))) { $w[] = 'created < ?'; $a[] = $t + 86400; }
    if (!empty($q['source'])) {
        if ($q['source'] === '(direct)') $w[] = "utm_source = ''";
        else { $w[] = 'utm_source = ?'; $a[] = (string) $q['source']; }
    }
    if (!empty($q['campaign'])) { $w[] = '(campaign_id = ? OR utm_campaign = ?)'; $a[] = (string) $q['campaign']; $a[] = (string) $q['campaign']; }
    if (!empty($q['ad'])) { $w[] = 'ad_id = ?'; $a[] = (string) $q['ad']; }
    if (!empty($q['product'])) { $w[] = 'items LIKE ?'; $a[] = '%"pid":' . (int) $q['product'] . ',%'; }
    if (!empty($q['dup'])) $w[] = 'duplicate_of IS NOT NULL';
    return [implode(' AND ', $w), $a];
}

function order_row(array $o): array
{
    return [
        'id' => $o['id'], 'ref' => $o['ref'], 'status' => $o['status'], 'name' => $o['name'], 'phone' => $o['phone'],
        'phone_display' => phone_display($o['phone']), 'wilaya' => $o['wilaya'], 'wilaya_name' => $o['wilaya_name'], 'commune' => $o['commune'],
        'address' => $o['address'], 'items' => order_items_text($o, 'fr'), 'qty' => $o['qty'], 'total' => $o['total'],
        'created' => $o['created'], 'source' => $o['utm_source'], 'campaign' => $o['utm_campaign'], 'dup' => $o['duplicate_of'],
        'note' => $o['note'], 'admin_note' => $o['admin_note'], 'tracking' => $o['tracking'], 'lang' => $o['lang'],
    ];
}

function product_admin(array $p): array
{
    $p['collections'] = product_collection_ids($p['id']);
    $p['url'] = page_abs('produit/' . $p['slug'], 'fr');
    return $p;
}

/** Nettoie un produit envoyé par l'éditeur de l'admin. */
function clean_product(array $in): array
{
    $tiers = [];
    foreach ((array) ($in['tiers'] ?? []) as $t) {
        $q = (int) ($t['qty'] ?? 0);
        $pr = (int) ($t['price'] ?? 0);
        if ($q < 1 || $q > 50 || $pr < 1 || $pr > 10000000) continue;
        $tiers[$q] = ['qty' => $q, 'price' => $pr, 'compare' => max(0, (int) ($t['compare'] ?? 0)), 'default' => !empty($t['default']),
            'label' => bi($t['label'] ?? [], 60), 'badge' => bi($t['badge'] ?? [], 60)];
    }
    ksort($tiers);
    if (!$tiers) throw new InvalidArgumentException('Ajoutez au moins une offre avec une quantité et un prix.');
    $specs = [];
    foreach ((array) ($in['specs'] ?? []) as $s) {
        $l = bi($s['label'] ?? [], 80);
        $v = bi($s['value'] ?? [], 200);
        if ($l['fr'] . $l['ar'] !== '') $specs[] = ['label' => $l, 'value' => $v];
    }
    $pack = [];
    foreach ((array) ($in['packaging'] ?? []) as $s) {
        $ti = bi($s['title'] ?? [], 120);
        if ($ti['fr'] . $ti['ar'] !== '') $pack[] = ['title' => $ti, 'text' => bi($s['text'] ?? [], 400, true)];
    }
    $imgs = [];
    foreach ((array) ($in['images'] ?? []) as $im) if ($c = clean_image($im)) $imgs[] = $c;
    $vids = [];
    foreach ((array) ($in['videos'] ?? []) as $v) if ($c = clean_video($v)) $vids[] = $c;
    $name = bi($in['name'] ?? [], 120);
    if ($name['fr'] === '' && $name['ar'] === '') throw new InvalidArgumentException('Le nom du produit est obligatoire.');
    return [
        'name' => $name, 'tagline' => bi($in['tagline'] ?? [], 200), 'short' => bi($in['short'] ?? [], 400, true),
        'description' => bi($in['description'] ?? [], 8000, true), 'highlights' => bi_list($in['highlights'] ?? [], 40),
        'contents' => bi_list($in['contents'] ?? [], 120), 'specs' => array_slice($specs, 0, 30), 'packaging' => array_slice($pack, 0, 6),
        'tiers' => array_values($tiers), 'images' => array_slice($imgs, 0, 20), 'videos' => array_slice($vids, 0, 6),
        'seo' => ['title' => bi($in['seo']['title'] ?? [], 120), 'description' => bi($in['seo']['description'] ?? [], 300)],
    ];
}

function unique_slug(string $table, string $slug, int $id): string
{
    $slug = slugify($slug) ?: 'element';
    $base = $slug;
    for ($i = 2; $i < 100; $i++) {
        $s = db()->prepare("SELECT id FROM $table WHERE slug = ? AND id <> ?");
        $s->execute([$slug, $id]);
        if (!$s->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
    }
    return $base . '-' . token(3);
}

/* ===================================================================== actions */

try {
    $b = $isPost ? input_json() : [];
    switch ($action) {

        /* ------------------------------------------------------------ session */
        case 'session': {
            admin_session_start();
            $u = admin_user();
            session_write_close();
            if (!$u) ok(['user' => null]);
            ok(['user' => public_admin($u), 'csrf' => $u['csrf']]);
        }
        case 'login': {
            if (!$isPost) fail('Méthode', 405);
            $mail = mb_strtolower(trim((string) ($b['email'] ?? '')));
            if (strpos($mail, '@') === false) $mail = ''; // texte pré-rempli par le navigateur : connexion du propriétaire
            $r = admin_login($mail, (string) ($b['password'] ?? ''));
            if (isset($r['error'])) fail($r['error'], 401);
            remember_site_url();
            ok(['user' => public_admin($r['user']), 'csrf' => $r['user']['csrf']]);
        }
        case 'logout': {
            admin_logout();
            ok();
        }
        case 'me_password': {
            $u = need('orders');
            if ($u['role'] === 'owner') fail('Le mot de passe du propriétaire se change sur le serveur (voir README).');
            $s = db()->prepare('SELECT pass FROM admins WHERE id = ?');
            $s->execute([$u['id']]);
            if (!password_verify((string) ($b['current'] ?? ''), (string) $s->fetchColumn())) fail('Mot de passe actuel incorrect.');
            $new = (string) ($b['new'] ?? '');
            if (mb_strlen($new) < 10) fail('Le nouveau mot de passe doit faire au moins 10 caractères.');
            db()->prepare('UPDATE admins SET pass = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
            ok();
        }

        /* ---------------------------------------------------------- tableau de bord */
        case 'stats': {
            need('dashboard');
            $days = max(1, min(365, (int) ($_GET['days'] ?? 30)));
            $since = strtotime('today') - ($days - 1) * 86400;
            $pdo = db();
            $q = fn(string $sql, array $a = []) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s; })();
            $k = $q("SELECT COUNT(*) n, COALESCE(SUM(total),0) v,
                    SUM(CASE WHEN status='new' THEN 1 ELSE 0 END) pending,
                    SUM(CASE WHEN confirmed_at>0 THEN 1 ELSE 0 END) confirmed, COALESCE(SUM(CASE WHEN confirmed_at>0 THEN total END),0) confirmed_v,
                    SUM(CASE WHEN shipped_at>0 THEN 1 ELSE 0 END) shipped,
                    SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) delivered, COALESCE(SUM(CASE WHEN status='delivered' THEN total END),0) delivered_v,
                    SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END) cancelled, SUM(CASE WHEN status='returned' THEN 1 ELSE 0 END) returned,
                    SUM(CASE WHEN duplicate_of IS NOT NULL THEN 1 ELSE 0 END) dups
                    FROM orders WHERE created >= ?", [$since])->fetch();
            $series = [];
            for ($i = 0; $i < $days; $i++) {
                $d = date('Y-m-d', $since + $i * 86400 + 3600);
                $series[$d] = ['d' => $d, 'orders' => 0, 'confirmed' => 0, 'delivered' => 0, 'value' => 0];
            }
            foreach ($q("SELECT created, confirmed_at, status, total FROM orders WHERE created >= ?", [$since]) as $r) {
                $d = date('Y-m-d', (int) $r['created']);
                if (!isset($series[$d])) continue;
                $series[$d]['orders']++;
                $series[$d]['value'] += (int) $r['total'];
                if ((int) $r['confirmed_at'] > 0) $series[$d]['confirmed']++;
                if ($r['status'] === 'delivered') $series[$d]['delivered']++;
            }
            $funnel = array_fill_keys(DAILY_KEYS, 0);
            foreach ($q('SELECT k, SUM(n) n FROM daily WHERE day >= ? GROUP BY k', [date('Y-m-d', $since)]) as $r) $funnel[$r['k']] = (int) $r['n'];
            $group = function (string $expr, string $label) use ($q, $since) {
                return $q("SELECT $expr AS g, $label AS label, COUNT(*) n, SUM(CASE WHEN confirmed_at>0 THEN 1 ELSE 0 END) confirmed,
                        SUM(CASE WHEN status='delivered' THEN 1 ELSE 0 END) delivered, COALESCE(SUM(CASE WHEN status='delivered' THEN total END),0) delivered_v,
                        SUM(CASE WHEN status IN ('cancelled','returned') THEN 1 ELSE 0 END) lost, COALESCE(SUM(total),0) v
                        FROM orders WHERE created >= ? GROUP BY g ORDER BY n DESC LIMIT 15", [$since])->fetchAll();
            };
            $capi = [];
            foreach ($q('SELECT name, status, COUNT(*) n FROM capi_events WHERE created >= ? GROUP BY name, status', [$since]) as $r) $capi[] = $r;
            $ab = $q("SELECT SUM(CASE WHEN status <> 'converted' AND updated <= ? THEN 1 ELSE 0 END) abandoned,
                    SUM(CASE WHEN recovered = 1 THEN 1 ELSE 0 END) recovered, COALESCE(SUM(CASE WHEN recovered = 1 THEN total END), 0) recovered_v,
                    SUM(CASE WHEN status = 'converted' AND recovered = 0 THEN 1 ELSE 0 END) self_done
                    FROM drafts WHERE created >= ?", [time() - abandon_after(), $since])->fetch();
            $low = [];
            foreach (db()->query("SELECT * FROM products WHERE stock IS NOT NULL AND stock <= stock_alert ORDER BY stock") as $r) {
                $p = product_from_row($r);
                $low[] = ['id' => $p['id'], 'name' => tr($p['name'], 'fr'), 'stock' => $p['stock']];
            }
            ok([
                'days' => $days, 'kpi' => array_map('intval', $k), 'series' => array_values($series), 'funnel' => $funnel,
                'campaigns' => $group("CASE WHEN campaign_id<>'' THEN campaign_id ELSE utm_campaign END", 'MAX(utm_campaign)'),
                'ads' => $group("CASE WHEN ad_id<>'' THEN ad_id ELSE utm_content END", 'MAX(utm_content)'),
                'sources' => $group("CASE WHEN utm_source<>'' THEN utm_source ELSE '(direct)' END", "''"),
                'wilayas' => $group('wilaya', 'MAX(wilaya_name)'),
                'capi' => $capi, 'capi_browser' => ['ok' => $funnel['capi_ok'] ?? 0, 'fail' => $funnel['capi_fail'] ?? 0],
                'capi_ready' => capi_ready(), 'capi_last_error' => meta_get('capi_last_error') ?? '', 'low_stock' => $low,
                'notify_last_error' => meta_get('notify_last_error') ?? '', 'purchase_trigger' => setting('purchase_trigger'),
                'abandoned' => array_map('intval', $ab ?: []),
            ]);
        }

        /* ---------------------------------------------------------------- commandes */
        case 'orders': {
            need('orders');
            [$where, $args] = order_filters($_GET);
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $per = 30;
            $s = db()->prepare("SELECT COUNT(*) FROM orders WHERE $where");
            $s->execute($args);
            $total = (int) $s->fetchColumn();
            $s = db()->prepare("SELECT * FROM orders WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
            $s->execute($args);
            $items = array_map(fn($r) => order_row(order_decode($r)), $s->fetchAll());
            [$w2, $a2] = order_filters($_GET, false);
            $s = db()->prepare("SELECT status, COUNT(*) n FROM orders WHERE $w2 GROUP BY status");
            $s->execute($a2);
            $counts = array_fill_keys(ORDER_STATUSES, 0);
            foreach ($s->fetchAll() as $r) $counts[$r['status']] = (int) $r['n'];
            ok(['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'counts' => $counts,
                'max_id' => (int) db()->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn()]);
        }
        case 'order': {
            need('orders');
            $o = order_by_id((int) ($_GET['id'] ?? 0));
            if (!$o) fail('Commande introuvable.', 404);
            $log = db()->prepare('SELECT * FROM order_log WHERE oid = ? ORDER BY id DESC');
            $log->execute([$o['id']]);
            $ev = db()->prepare('SELECT id, name, event_id, status, attempts, error, created, sent, test FROM capi_events WHERE oid = ? ORDER BY id');
            $ev->execute([$o['id']]);
            $dup = null;
            if ($o['duplicate_of']) {
                $d = order_by_id($o['duplicate_of']);
                if ($d) $dup = ['id' => $d['id'], 'ref' => $d['ref'], 'status' => $d['status'], 'created' => $d['created']];
            }
            $p = $o['items'] ? product_by_id((int) $o['items'][0]['pid']) : null;
            $view = $o;
            unset($view['token'], $view['iph'], $view['client_key']);
            $view['ip'] = $o['ip'] !== '' ? 'enregistrée' : '';
            ok([
                'order' => $view, 'phone_display' => phone_display($o['phone']), 'wa' => order_whatsapp_link($o), 'tel' => $o['phone'],
                'log' => $log->fetchAll(), 'events' => $ev->fetchAll(), 'dup' => $dup,
                'tiers' => $p ? array_map(fn($t) => ['qty' => $t['qty'], 'price' => $t['price'], 'label' => tr($t['label'], 'fr')], $p['tiers']) : [],
                'customer_url' => abs_url(lang_prefix($o['lang']) . 'commande/' . $o['ref']) . '?k=' . $o['token'],
            ]);
        }
        case 'order_status': {
            $u = need('orders');
            $o = order_by_id((int) ($b['id'] ?? 0));
            if (!$o) fail('Commande introuvable.', 404);
            $st = (string) ($b['status'] ?? '');
            if (!in_array($st, ORDER_STATUSES, true)) fail('Statut inconnu.');
            $o = order_set_status($o, $st, actor($u), one_line($b['note'] ?? '', 300));
            ok(['status' => $o['status']]);
        }
        case 'order_bulk_status': {
            $u = need('orders');
            $st = (string) ($b['status'] ?? '');
            if (!in_array($st, ORDER_STATUSES, true)) fail('Statut inconnu.');
            $n = 0;
            foreach (array_slice((array) ($b['ids'] ?? []), 0, 200) as $id) {
                $o = order_by_id((int) $id);
                if ($o && $o['status'] !== $st) { order_set_status($o, $st, actor($u), 'Changement groupé'); $n++; }
            }
            ok(['n' => $n]);
        }
        case 'order_update': {
            $u = need('orders');
            $o = order_by_id((int) ($b['id'] ?? 0));
            if (!$o) fail('Commande introuvable.', 404);
            $fields = array_intersect_key((array) ($b['fields'] ?? []), array_flip(['name', 'phone', 'wilaya', 'commune', 'address', 'note', 'admin_note',
                'delivery', 'qty', 'shipping', 'carrier', 'tracking']));
            try {
                order_update($o, $fields, actor($u));
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
            ok();
        }
        case 'order_note': {
            $u = need('orders');
            $o = order_by_id((int) ($b['id'] ?? 0));
            if (!$o) fail('Commande introuvable.', 404);
            $note = str_clean($b['note'] ?? '', 1000);
            if ($note === '') fail('Note vide.');
            order_log($o['id'], 'note', '', '', $note, actor($u));
            ok();
        }
        case 'orders_export': {
            need('orders');
            [$where, $args] = order_filters($_GET);
            $s = db()->prepare("SELECT * FROM orders WHERE $where ORDER BY id DESC LIMIT 20000");
            $s->execute($args);
            $labels = ['new' => 'Nouvelle', 'confirmed' => 'Confirmée', 'preparing' => 'En préparation', 'shipped' => 'Expédiée', 'delivered' => 'Livrée', 'cancelled' => 'Annulée', 'returned' => 'Retournée'];
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="swanbida-commandes-' . date('Y-m-d-His') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $cols = ['N° commande', 'Date', 'Statut', 'Client', 'Téléphone', 'Code wilaya', 'Wilaya', 'Commune', 'Adresse', 'Livraison', 'Produit', 'Quantité',
                'Sous-total (DA)', 'Frais de livraison (DA)', 'Total à encaisser (DA)', 'Transporteur', 'N° de suivi', 'Remarque client', 'Note interne',
                'Doublon possible de', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign_id', 'adset_id', 'ad_id', 'placement',
                'Confirmée le', 'Expédiée le', 'Livrée le', 'Langue'];
            fputcsv($out, $cols, ';');
            $dt = fn(int $t) => $t ? date('Y-m-d H:i', $t) : '';
            foreach ($s->fetchAll() as $r) {
                $o = order_decode($r);
                fputcsv($out, array_map(function ($v) {
                    $v = (string) $v;
                    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; // pas de formule exécutée par Excel
                }, [
                    $o['ref'], $dt($o['created']), $labels[$o['status']] ?? $o['status'], $o['name'], phone_display($o['phone']), $o['wilaya'], $o['wilaya_name'], $o['commune'],
                    $o['address'], $o['delivery'] === 'desk' ? 'Bureau' : 'Domicile', implode(', ', array_map(fn($i) => tr($i['name'] ?? [], 'fr'), $o['items'])),
                    $o['qty'], $o['subtotal'], $o['shipping'], $o['total'], $o['carrier'], $o['tracking'], $o['note'], $o['admin_note'],
                    $o['duplicate_of'] ? '#' . $o['duplicate_of'] : '', $o['utm_source'], $o['utm_medium'], $o['utm_campaign'], $o['utm_content'], $o['utm_term'],
                    $o['campaign_id'], $o['adset_id'], $o['ad_id'], $o['placement'], $dt($o['confirmed_at']), $dt($o['shipped_at']), $dt($o['delivered_at']), $o['lang'],
                ]), ';');
            }
            fclose($out);
            exit;
        }
        case 'poll': {
            need('orders');
            $since = (int) ($_GET['since'] ?? 0);
            $s = db()->prepare('SELECT * FROM orders WHERE id > ? ORDER BY id DESC LIMIT 20');
            $s->execute([$since]);
            $items = array_map(fn($r) => order_row(order_decode($r)), $s->fetchAll());
            ok(['items' => $items, 'max_id' => (int) db()->query('SELECT COALESCE(MAX(id),0) FROM orders')->fetchColumn(),
                'pending' => (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'new'")->fetchColumn(),
                'abandoned' => drafts_todo_count()]);
        }

        /* ------------------------------------------------------- commandes abandonnées */
        case 'drafts': {
            need('orders');
            $cut = time() - abandon_after();
            $tabs = [
                'todo' => ["status = 'open' AND updated <= ?", [$cut]],
                'live' => ["status = 'open' AND updated > ?", [$cut]],
                'contacted' => ["status = 'contacted'", []],
                'converted' => ["status = 'converted'", []],
                'dismissed' => ["status = 'dismissed'", []],
            ];
            $tab = isset($tabs[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'todo';
            [$w, $a] = $tabs[$tab];
            $s = trim((string) ($_GET['q'] ?? ''));
            if ($s !== '') {
                $digits = preg_replace('/\D/', '', $s) ?? '';
                $w .= ' AND (name LIKE ?' . (strlen($digits) >= 4 ? ' OR phone LIKE ?' : '') . ')';
                $a[] = '%' . $s . '%';
                if (strlen($digits) >= 4) $a[] = '%' . (strpos($digits, '213') === 0 ? substr($digits, 3) : ltrim($digits, '0')) . '%';
            }
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $st = db()->prepare("SELECT COUNT(*) FROM drafts WHERE $w");
            $st->execute($a);
            $total = (int) $st->fetchColumn();
            $st = db()->prepare("SELECT * FROM drafts WHERE $w ORDER BY updated DESC LIMIT 30 OFFSET " . (($page - 1) * 30));
            $st->execute($a);
            $items = [];
            foreach ($st->fetchAll() as $r) {
                $d = draft_decode($r);
                $p = $d['pid'] ? product_by_id($d['pid']) : null;
                $ref = '';
                if ($d['order_id']) { $o = order_by_id($d['order_id']); $ref = $o ? $o['ref'] : ''; }
                $items[] = [
                    'id' => $d['id'], 'status' => $d['status'], 'name' => $d['name'], 'phone' => $d['phone'], 'phone_display' => phone_display($d['phone']),
                    'wilaya' => $d['wilaya'], 'wilaya_name' => $d['wilaya'] !== '' ? wilaya_label($d['wilaya'], 'fr') : '', 'commune' => $d['commune'],
                    'note' => $d['note'], 'admin_note' => $d['admin_note'], 'qty' => $d['qty'], 'total' => $d['total'], 'items' => draft_items_text($d, 'fr'),
                    'lang' => $d['lang'], 'source' => $d['utm_source'], 'campaign' => $d['utm_campaign'], 'contacts' => $d['contacts'],
                    'contacted_at' => $d['contacted_at'], 'created' => $d['created'], 'updated' => $d['updated'], 'order_id' => $d['order_id'], 'order_ref' => $ref,
                    'recovered' => $d['recovered'], 'wa' => draft_whatsapp_link($d),
                    'tiers' => $p ? array_map(fn($t) => ['qty' => $t['qty'], 'price' => $t['price'], 'label' => tr($t['label'], 'fr')], $p['tiers']) : [],
                ];
            }
            $counts = [];
            foreach ($tabs as $k => [$cw, $ca]) {
                $st = db()->prepare("SELECT COUNT(*) FROM drafts WHERE $cw");
                $st->execute($ca);
                $counts[$k] = (int) $st->fetchColumn();
            }
            ok(['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / 30)), 'counts' => $counts,
                'tab' => $tab, 'minutes' => (int) setting('abandon_minutes')]);
        }
        case 'draft_update': {
            need('orders');
            $d = draft_by_id((int) ($b['id'] ?? 0));
            if (!$d) fail('Commande abandonnée introuvable.', 404);
            if (array_key_exists('admin_note', $b)) {
                db()->prepare('UPDATE drafts SET admin_note = ? WHERE id = ?')->execute([str_clean($b['admin_note'], 1000), $d['id']]);
            }
            $st = (string) ($b['status'] ?? '');
            if ($st !== '' && $d['status'] !== 'converted') {
                if (!in_array($st, ['open', 'contacted', 'dismissed'], true)) fail('Statut inconnu.');
                if ($st === 'contacted') {
                    db()->prepare("UPDATE drafts SET status = 'contacted', contacts = contacts + 1, contacted_at = ? WHERE id = ?")->execute([time(), $d['id']]);
                } else {
                    db()->prepare('UPDATE drafts SET status = ? WHERE id = ?')->execute([$st, $d['id']]);
                }
            }
            ok();
        }
        case 'draft_convert': {
            $u = need('orders');
            $d = draft_by_id((int) ($b['id'] ?? 0));
            if (!$d) fail('Commande abandonnée introuvable.', 404);
            $fields = array_intersect_key((array) ($b['fields'] ?? []), array_flip(['name', 'phone', 'wilaya', 'commune', 'qty', 'note']));
            $r = draft_convert($d, $fields, actor($u));
            if (isset($r['error'])) fail($r['error']);
            ok(['order_id' => $r['order']['id'], 'ref' => $r['order']['ref']]);
        }

        /* ---------------------------------------------------------------- catalogue */
        case 'products': {
            need('catalog');
            $rows = db()->query('SELECT * FROM products ORDER BY pos, id')->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $p = product_from_row($r);
                $out[] = ['id' => $p['id'], 'slug' => $p['slug'], 'name' => tr($p['name'], 'fr'), 'status' => $p['status'], 'price' => $p['price'],
                    'stock' => $p['stock'], 'stock_alert' => $p['stock_alert'], 'image' => $p['images'][0] ?? null, 'featured' => $p['featured'],
                    'url' => page_abs('produit/' . $p['slug'], 'fr')];
            }
            ok(['items' => $out]);
        }
        case 'product': {
            need('catalog');
            $p = product_by_id((int) ($_GET['id'] ?? 0));
            if (!$p) fail('Produit introuvable.', 404);
            ok(['product' => product_admin($p), 'collections' => collections_list(true)]);
        }
        case 'product_new': {
            need('catalog');
            $p = default_product();
            $p['name'] = ['fr' => '', 'ar' => ''];
            foreach (['tagline', 'short', 'description'] as $k) $p[$k] = ['fr' => '', 'ar' => ''];
            $p['images'] = [];
            $p['seo'] = ['title' => ['fr' => '', 'ar' => ''], 'description' => ['fr' => '', 'ar' => '']];
            ok(['product' => $p + ['id' => 0, 'slug' => '', 'sku' => '', 'status' => 'draft', 'featured' => 0, 'pos' => 10, 'stock' => null, 'stock_alert' => 5, 'collections' => []],
                'collections' => collections_list(true)]);
        }
        case 'product_save': {
            need('catalog');
            $in = (array) ($b['product'] ?? []);
            $id = (int) ($in['id'] ?? 0);
            try {
                $data = clean_product($in);
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
            $slug = unique_slug('products', (string) ($in['slug'] ?? '') ?: ($data['name']['fr'] ?: 'produit'), $id);
            $t1 = $data['tiers'][0];
            $stock = ($in['stock'] ?? '') === '' || $in['stock'] === null ? null : max(-1000, min(1000000, (int) $in['stock']));
            $cols = [
                'slug' => $slug, 'sku' => one_line($in['sku'] ?? '', 40), 'status' => ($in['status'] ?? '') === 'active' ? 'active' : 'draft',
                'featured' => !empty($in['featured']) ? 1 : 0, 'pos' => (int) ($in['pos'] ?? 0), 'price' => $t1['price'], 'compare_price' => $t1['compare'],
                'stock' => $stock, 'stock_alert' => max(0, (int) ($in['stock_alert'] ?? 5)),
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated' => time(),
            ];
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            if ($id) {
                if (!product_by_id($id)) { $pdo->exec('ROLLBACK'); fail('Produit introuvable.', 404); }
                $pdo->prepare('UPDATE products SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($cols))) . ' WHERE id = ?')
                    ->execute(array_merge(array_values($cols), [$id]));
            } else {
                $cols['created'] = time();
                $pdo->prepare('INSERT INTO products (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($cols));
                $id = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM product_collections WHERE pid = ?')->execute([$id]);
            foreach (array_unique(array_map('intval', (array) ($in['collections'] ?? []))) as $cid) {
                $pdo->prepare('INSERT OR IGNORE INTO product_collections (pid, cid) SELECT ?, id FROM collections WHERE id = ?')->execute([$id, $cid]);
            }
            $pdo->exec('COMMIT');
            meta_set('cache_v', (string) time());
            ok(['id' => $id, 'slug' => $slug]);
        }
        case 'product_delete': {
            need('catalog');
            $p = product_by_id((int) ($b['id'] ?? 0));
            if (!$p) fail('Produit introuvable.', 404);
            db()->prepare('DELETE FROM products WHERE id = ?')->execute([$p['id']]);
            foreach ($p['images'] as $im) image_delete_files($im);
            ok();
        }
        case 'product_stock': {
            $u = need('catalog');
            $p = product_by_id((int) ($b['id'] ?? 0));
            if (!$p) fail('Produit introuvable.', 404);
            if (array_key_exists('delta', $b)) {
                db()->prepare('UPDATE products SET stock = COALESCE(stock, 0) + ?, updated = ? WHERE id = ?')->execute([(int) $b['delta'], time(), $p['id']]);
            } else {
                $v = $b['stock'] ?? null;
                db()->prepare('UPDATE products SET stock = ?, updated = ? WHERE id = ?')->execute([$v === null || $v === '' ? null : (int) $v, time(), $p['id']]);
            }
            ok(['stock' => product_by_id($p['id'])['stock']]);
        }
        case 'upload': {
            need('catalog');
            if (empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) fail('Fichier trop lourd pour le serveur (limite PHP : ' . ini_get('post_max_size') . ').');
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
                $e = $f['error'] ?? UPLOAD_ERR_NO_FILE;
                fail(in_array($e, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Fichier trop lourd (limite : ' . ini_get('upload_max_filesize') . ').' : 'Envoi du fichier échoué.');
            }
            try {
                if (($_GET['kind'] ?? 'image') === 'video') ok(['video' => video_ingest($f['tmp_name'], (int) $f['size'])]);
                if ($f['size'] > 15 * 1024 * 1024) fail('Photo trop lourde (15 Mo maximum).');
                ok(['image' => image_ingest($f['tmp_name'])]);
            } catch (InvalidArgumentException $e) {
                fail($e->getMessage());
            }
        }
        case 'collections': {
            need('catalog');
            ok(['items' => collections_list(true)]);
        }
        case 'collection_save': {
            need('catalog');
            $in = (array) ($b['collection'] ?? []);
            $id = (int) ($in['id'] ?? 0);
            $name = bi($in['name'] ?? [], 80);
            if ($name['fr'] === '' && $name['ar'] === '') fail('Le nom est obligatoire.');
            $data = ['name' => $name, 'description' => bi($in['description'] ?? [], 400, true), 'image' => clean_image($in['image'] ?? null),
                'seo' => ['title' => bi($in['seo']['title'] ?? [], 120), 'description' => bi($in['seo']['description'] ?? [], 300)]];
            $slug = unique_slug('collections', (string) ($in['slug'] ?? '') ?: ($name['fr'] ?: 'collection'), $id);
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($id) {
                db()->prepare('UPDATE collections SET slug = ?, active = ?, pos = ?, data = ?, updated = ? WHERE id = ?')
                    ->execute([$slug, !empty($in['active']) ? 1 : 0, (int) ($in['pos'] ?? 0), $json, time(), $id]);
            } else {
                db()->prepare('INSERT INTO collections (slug, active, pos, data, created, updated) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$slug, !empty($in['active']) ? 1 : 0, (int) ($in['pos'] ?? 0), $json, time(), time()]);
                $id = (int) db()->lastInsertId();
            }
            meta_set('cache_v', (string) time());
            ok(['id' => $id, 'slug' => $slug]);
        }
        case 'collection_delete': {
            need('catalog');
            db()->prepare('DELETE FROM collections WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
            ok();
        }

        /* ------------------------------------------------------------------ contenus */
        case 'content': {
            need('content');
            $out = [];
            foreach (array_keys(default_content()) as $sec) $out[$sec] = content($sec);
            ok(['content' => $out, 'products' => array_map(fn($p) => ['slug' => $p['slug'], 'name' => tr($p['name'], 'fr')], products_list())]);
        }
        case 'content_save': {
            need('content');
            $sec = (string) ($b['section'] ?? '');
            if (!array_key_exists($sec, default_content())) fail('Section inconnue.');
            $data = $b['data'] ?? null;
            if (!is_array($data)) fail('Contenu invalide.');
            // Nettoyage générique : textes limités, images vérifiées, profondeur bornée.
            $clean = function ($v, int $depth = 0) use (&$clean) {
                if ($depth > 5) return null;
                if (is_array($v)) {
                    if (isset($v['src']) && isset($v['widths'])) return clean_image($v);
                    $o = [];
                    foreach (array_slice($v, 0, 60, true) as $k => $x) {
                        if (!is_int($k) && !preg_match('/^[a-z_]{1,40}$/', (string) $k)) continue;
                        $o[$k] = $clean($x, $depth + 1);
                    }
                    return $o;
                }
                if (is_bool($v) || is_int($v)) return $v;
                return str_clean((string) $v, 12000);
            };
            $data = $clean($data);
            if ($sec === 'global') {
                foreach (['facebook', 'instagram', 'tiktok'] as $k) {
                    if (!empty($data[$k]) && !preg_match('~^https://~', (string) $data[$k])) fail('Le lien ' . $k . ' doit commencer par https://');
                }
                foreach (['whatsapp', 'phone'] as $k) {
                    if (!empty($data[$k]) && normalize_phone((string) $data[$k]) === '') fail('Numéro ' . $k . ' invalide (ex. 0661 23 45 67).');
                }
                if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) fail('Email invalide.');
            }
            content_set($sec, $data);
            ok();
        }
        case 'articles': {
            need('content');
            $rows = db()->query('SELECT * FROM articles ORDER BY published DESC, id DESC')->fetchAll();
            ok(['items' => array_map(function ($r) {
                $a = article_from_row($r);
                return ['id' => $a['id'], 'slug' => $a['slug'], 'status' => $a['status'], 'published' => $a['published'], 'title' => tr($a['title'], 'fr'),
                    'langs' => array_values(array_filter(SWB_LANGS, fn($l) => article_has_lang($a, $l))), 'url' => page_abs('guides/' . $a['slug'], article_has_lang($a, 'fr') ? 'fr' : 'ar')];
            }, $rows)]);
        }
        case 'article': {
            need('content');
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) ok(['article' => ['id' => 0, 'slug' => '', 'status' => 'draft', 'published' => time(), 'title' => ['fr' => '', 'ar' => ''], 'excerpt' => ['fr' => '', 'ar' => ''],
                'body' => ['fr' => '', 'ar' => ''], 'cover' => null, 'seo' => ['title' => ['fr' => '', 'ar' => ''], 'description' => ['fr' => '', 'ar' => '']]]]);
            $s = db()->prepare('SELECT * FROM articles WHERE id = ?');
            $s->execute([$id]);
            $r = $s->fetch();
            if (!$r) fail('Guide introuvable.', 404);
            ok(['article' => article_from_row($r)]);
        }
        case 'article_save': {
            need('content');
            $in = (array) ($b['article'] ?? []);
            $id = (int) ($in['id'] ?? 0);
            $title = bi($in['title'] ?? [], 160);
            if ($title['fr'] === '' && $title['ar'] === '') fail('Le titre est obligatoire.');
            $data = ['title' => $title, 'excerpt' => bi($in['excerpt'] ?? [], 400, true), 'body' => bi($in['body'] ?? [], 30000, true),
                'cover' => clean_image($in['cover'] ?? null), 'seo' => ['title' => bi($in['seo']['title'] ?? [], 120), 'description' => bi($in['seo']['description'] ?? [], 300)]];
            $slug = unique_slug('articles', (string) ($in['slug'] ?? '') ?: ($title['fr'] ?: 'guide'), $id);
            $pub = (int) ($in['published'] ?? 0) ?: time();
            $status = ($in['status'] ?? '') === 'published' ? 'published' : 'draft';
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($id) {
                db()->prepare('UPDATE articles SET slug = ?, status = ?, published = ?, data = ?, updated = ? WHERE id = ?')->execute([$slug, $status, $pub, $json, time(), $id]);
            } else {
                db()->prepare('INSERT INTO articles (slug, status, published, data, created, updated) VALUES (?, ?, ?, ?, ?, ?)')->execute([$slug, $status, $pub, $json, time(), time()]);
                $id = (int) db()->lastInsertId();
            }
            ok(['id' => $id, 'slug' => $slug]);
        }
        case 'article_delete': {
            need('content');
            db()->prepare('DELETE FROM articles WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
            ok();
        }

        /* ----------------------------------------------------------------- livraison */
        case 'wilayas': {
            need('shipping');
            $rows = db()->query('SELECT w.*, (SELECT COUNT(*) FROM communes c WHERE c.wilaya = w.code) AS nc FROM wilayas w ORDER BY pos, code')->fetchAll();
            ok(['items' => $rows, 'desk_enabled' => setting('desk_enabled') === '1']);
        }
        case 'wilaya_save': {
            need('shipping');
            $code = preg_replace('/\D/', '', (string) ($b['code'] ?? '')) ?? '';
            if ($code === '' || strlen($code) > 3) fail('Code de wilaya invalide.');
            $code = str_pad($code, 2, '0', STR_PAD_LEFT);
            $fr = one_line($b['fr'] ?? '', 60);
            if ($fr === '') fail('Nom de la wilaya obligatoire.');
            $vals = [$fr, one_line($b['ar'] ?? '', 60), max(0, (int) ($b['fee_home'] ?? 0)), max(0, (int) ($b['fee_desk'] ?? 0)), one_line($b['delay'] ?? '', 60), !empty($b['active']) ? 1 : 0];
            if (wilaya_get($code)) {
                db()->prepare('UPDATE wilayas SET fr = ?, ar = ?, fee_home = ?, fee_desk = ?, delay = ?, active = ? WHERE code = ?')->execute(array_merge($vals, [$code]));
            } else {
                db()->prepare('INSERT INTO wilayas (fr, ar, fee_home, fee_desk, delay, active, code, pos) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($vals, [$code, (int) $code]));
            }
            meta_set('geo_v', (string) time());
            ok();
        }
        case 'wilayas_bulk': {
            need('shipping');
            $set = [];
            $args = [];
            foreach (['fee_home', 'fee_desk'] as $k) if (isset($b[$k]) && $b[$k] !== '') { $set[] = "$k = ?"; $args[] = max(0, (int) $b[$k]); }
            if (isset($b['delay']) && $b['delay'] !== '') { $set[] = 'delay = ?'; $args[] = one_line($b['delay'], 60); }
            if (!$set) fail('Rien à modifier.');
            $codes = array_values(array_filter(array_map(fn($c) => preg_replace('/\D/', '', (string) $c), (array) ($b['codes'] ?? []))));
            $sql = 'UPDATE wilayas SET ' . implode(', ', $set) . ($codes ? ' WHERE code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')' : '');
            db()->prepare($sql)->execute(array_merge($args, $codes));
            meta_set('geo_v', (string) time());
            ok();
        }
        case 'communes': {
            need('shipping');
            $s = db()->prepare('SELECT fr, ar FROM communes WHERE wilaya = ? ORDER BY fr');
            $s->execute([(string) ($_GET['wilaya'] ?? '')]);
            ok(['items' => $s->fetchAll()]);
        }
        case 'communes_save': {
            need('shipping');
            $w = wilaya_get((string) ($b['wilaya'] ?? ''));
            if (!$w) fail('Wilaya introuvable.', 404);
            $rows = [];
            foreach (preg_split('/\r?\n/', (string) ($b['text'] ?? '')) ?: [] as $line) {
                $p = array_map('trim', explode('|', $line, 2));
                $fr = one_line($p[0] ?? '', 80);
                if ($fr === '') continue;
                $rows[mb_strtolower($fr)] = [$fr, one_line($p[1] ?? '', 80)];
            }
            $pdo = db();
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->prepare('DELETE FROM communes WHERE wilaya = ?')->execute([$w['code']]);
            $ins = $pdo->prepare('INSERT INTO communes (wilaya, fr, ar) VALUES (?, ?, ?)');
            foreach ($rows as [$fr, $ar]) $ins->execute([$w['code'], $fr, $ar]);
            $pdo->exec('COMMIT');
            meta_set('geo_v', (string) time());
            ok(['n' => count($rows)]);
        }

        /* -------------------------------------------------------------- Meta & suivi */
        case 'meta': {
            need('meta');
            $counts = [];
            foreach (db()->query('SELECT name, status, COUNT(*) n FROM capi_events GROUP BY name, status') as $r) $counts[] = $r;
            $st = (string) ($_GET['status'] ?? '');
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $sql = 'SELECT e.id, e.oid, e.name, e.event_id, e.status, e.attempts, e.error, e.created, e.sent, e.test, o.ref FROM capi_events e LEFT JOIN orders o ON o.id = e.oid'
                . (in_array($st, ['pending', 'sent', 'failed', 'skipped'], true) ? ' WHERE e.status = ' . db()->quote($st) : '') . ' ORDER BY e.id DESC LIMIT 40 OFFSET ' . (($page - 1) * 40);
            $tok = capi_token();
            ok([
                'settings' => [
                    'pixel_id' => pixel_id(), 'pixel_locked' => defined('SWB_META_PIXEL_ID') && SWB_META_PIXEL_ID !== '',
                    'token_set' => $tok !== '', 'token_hint' => $tok !== '' ? '…' . substr($tok, -6) : '', 'token_locked' => defined('SWB_META_CAPI_TOKEN') && SWB_META_CAPI_TOKEN !== '',
                    'capi_enabled' => setting('capi_enabled') === '1', 'capi_browser' => setting('capi_browser') === '1', 'test_code' => setting('test_code'),
                    'graph_version' => graph_version(), 'purchase_trigger' => setting('purchase_trigger'), 'delivered_event' => setting('delivered_event'),
                    'fb_domain_verification' => setting('fb_domain_verification'),
                ],
                'ready' => capi_ready(), 'counts' => $counts, 'events' => db()->query($sql)->fetchAll(), 'page' => $page,
                'last_error' => meta_get('capi_last_error') ?? '', 'url_params' => recommended_url_params(),
            ]);
        }
        case 'meta_save': {
            need('meta');
            $pid = preg_replace('/\D/', '', (string) ($b['pixel_id'] ?? '')) ?? '';
            if ($pid !== '' && !preg_match('/^\d{5,20}$/', $pid)) fail('ID du pixel invalide (chiffres uniquement).');
            setting_set('pixel_id', $pid);
            if (isset($b['token']) && $b['token'] !== '') {
                $tok = trim((string) $b['token']);
                if (!preg_match('/^[A-Za-z0-9_\-]{30,600}$/', $tok)) fail('Jeton Conversions API invalide.');
                setting_set('capi_token', seal($tok));
            }
            if (!empty($b['token_clear'])) setting_set('capi_token', '');
            setting_set('capi_enabled', !empty($b['capi_enabled']) ? '1' : '0');
            setting_set('capi_browser', !empty($b['capi_browser']) ? '1' : '0');
            setting_set('test_code', one_line($b['test_code'] ?? '', 40));
            $gv = (string) ($b['graph_version'] ?? 'v24.0');
            setting_set('graph_version', preg_match('/^v\d{1,2}\.\d$/', $gv) ? $gv : 'v24.0');
            setting_set('purchase_trigger', ($b['purchase_trigger'] ?? '') === 'delivered' ? 'delivered' : 'confirmed');
            $de = (string) ($b['delivered_event'] ?? 'OrderDelivered');
            setting_set('delivered_event', preg_match('/^[A-Za-z][A-Za-z0-9_]{1,39}$/', $de) ? $de : 'OrderDelivered');
            setting_set('fb_domain_verification', one_line(preg_replace('/^.*content="([^"]+)".*$/s', '$1', (string) ($b['fb_domain_verification'] ?? '')) ?? '', 120));
            ok();
        }
        case 'meta_test': {
            need('meta');
            $code = one_line($b['test_code'] ?? setting('test_code'), 40);
            if ($code === '') fail('Indiquez le « code de test » affiché dans le Gestionnaire d\'événements (onglet Tester les événements).');
            if (pixel_id() === '' || capi_token() === '') fail('Enregistrez d\'abord l\'ID du pixel et le jeton.');
            $event = ['event_name' => 'PageView', 'event_time' => time(), 'event_id' => 'test.' . token(6), 'action_source' => 'website',
                'event_source_url' => abs_url(''), 'user_data' => ['client_ip_address' => client_ip() ?: '127.0.0.1', 'client_user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'SWANBIDA admin')]];
            [$okk, $err, $res] = capi_send([$event], $code);
            if (!$okk) fail('Meta a refusé l\'événement : ' . $err);
            ok(['response' => json_decode($res, true)]);
        }
        case 'capi_retry': {
            need('meta');
            $ids = array_map('intval', (array) ($b['ids'] ?? [$b['id'] ?? 0]));
            $s = db()->prepare("UPDATE capi_events SET status = 'pending', attempts = 0, next_try = 0, error = '' WHERE id = ? AND status IN ('failed','skipped','pending')");
            foreach ($ids as $id) $s->execute([$id]);
            // Un événement trop ancien est refusé par Meta : on rafraîchit son horodatage s'il est encore dans les 7 jours.
            after_response(fn() => capi_process(20));
            ok();
        }

        /* ----------------------------------------------------------------- réglages */
        case 'settings': {
            need('settings');
            $keys = ['notify_emails', 'gsc_verification', 'allow_backorder', 'duplicate_hours', 'desk_enabled', 'return_days',
                'wa_template_fr', 'wa_template_ar', 'order_rate_ip', 'abandon_minutes', 'notify_abandoned', 'wa_abandon_fr', 'wa_abandon_ar'];
            $out = [];
            foreach ($keys as $k) $out[$k] = setting($k);
            ok(['settings' => $out, 'smtp' => SWB_SMTP_HOST !== '' ? SWB_SMTP_HOST : 'fonction mail() de PHP', 'last_error' => meta_get('notify_last_error') ?? '',
                'last_backup' => (int) (meta_get('last_backup') ?? 0), 'backups' => admin_can(admin_user_cached(), 'backup') ? backups_list() : [],
                'version' => SWB_VERSION, 'data_dir' => SWB_DATA_DIR, 'cron_last' => (int) (meta_get('cron_last') ?? 0)]);
        }
        case 'settings_save': {
            need('settings');
            $emails = [];
            foreach (preg_split('/[\s,;]+/', (string) ($b['notify_emails'] ?? '')) ?: [] as $m) {
                if ($m === '' || strpos($m, '@') === false) continue; // texte pré-rempli par le navigateur (ex. un domaine) : ignoré en silence
                if (!filter_var($m, FILTER_VALIDATE_EMAIL)) fail('Email invalide : ' . $m);
                $emails[] = $m;
            }
            setting_set('notify_emails', implode(', ', array_slice($emails, 0, 5)));
            setting_set('gsc_verification', one_line(preg_replace('/^.*content="([^"]+)".*$/s', '$1', (string) ($b['gsc_verification'] ?? '')) ?? '', 120));
            setting_set('allow_backorder', !empty($b['allow_backorder']) ? '1' : '0');
            setting_set('desk_enabled', !empty($b['desk_enabled']) ? '1' : '0');
            setting_set('duplicate_hours', (string) max(0, min(720, (int) ($b['duplicate_hours'] ?? 24))));
            setting_set('return_days', (string) max(1, min(60, (int) ($b['return_days'] ?? 7))));
            setting_set('order_rate_ip', (string) max(1, min(100, (int) ($b['order_rate_ip'] ?? 8))));
            foreach (['wa_template_fr', 'wa_template_ar', 'wa_abandon_fr', 'wa_abandon_ar'] as $k) if (isset($b[$k])) setting_set($k, str_clean($b[$k], 1000));
            if (isset($b['abandon_minutes'])) setting_set('abandon_minutes', (string) max(1, min(1440, (int) $b['abandon_minutes'])));
            setting_set('notify_abandoned', !empty($b['notify_abandoned']) ? '1' : '0');
            meta_set('geo_v', (string) time());
            ok();
        }
        case 'notify_test': {
            need('settings');
            $res = [];
            $mails = notify_emails();
            if ($mails) {
                try {
                    foreach ($mails as $m) send_mail($m, 'SWANBIDA — test de notification', '<p style="font-family:Arial,sans-serif">Les notifications de nouvelles commandes arriveront ici.</p>', 'Les notifications de nouvelles commandes arriveront ici.');
                    $res['email'] = 'envoyé à ' . implode(', ', $mails);
                } catch (Throwable $e) {
                    $res['email'] = 'échec : ' . $e->getMessage();
                }
            } else $res['email'] = 'aucune adresse renseignée';
            ok(['result' => $res]);
        }

        /* ----------------------------------------------------------------- équipe */
        case 'team': {
            need('team');
            ok(['items' => db()->query('SELECT id, email, name, role, active, created, last_login FROM admins ORDER BY id')->fetchAll()]);
        }
        case 'team_save': {
            need('team');
            $id = (int) ($b['id'] ?? 0);
            $email = mb_strtolower(trim((string) ($b['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email invalide.');
            $name = one_line($b['name'] ?? '', 60);
            if ($name === '') fail('Nom obligatoire.');
            $role = in_array($b['role'] ?? '', ['manager', 'agent'], true) ? (string) $b['role'] : 'agent';
            $pass = (string) ($b['password'] ?? '');
            if ((!$id || $pass !== '') && mb_strlen($pass) < 10) fail('Mot de passe : 10 caractères minimum.');
            try {
                if ($id) {
                    db()->prepare('UPDATE admins SET email = ?, name = ?, role = ?, active = ? WHERE id = ?')->execute([$email, $name, $role, !empty($b['active']) ? 1 : 0, $id]);
                    if ($pass !== '') db()->prepare('UPDATE admins SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                } else {
                    db()->prepare('INSERT INTO admins (email, name, role, pass, active, created) VALUES (?, ?, ?, ?, 1, ?)')->execute([$email, $name, $role, password_hash($pass, PASSWORD_DEFAULT), time()]);
                }
            } catch (PDOException $e) {
                fail('Cet email est déjà utilisé.');
            }
            ok();
        }
        case 'team_delete': {
            need('team');
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
            ok();
        }

        /* -------------------------------------------------------------- sauvegardes */
        case 'backup_db': {
            need('backup');
            $tmp = backup_dir() . '/download-' . token(6) . '.sqlite';
            backup_snapshot($tmp);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="swanbida-' . date('Y-m-d-His') . '.sqlite"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
            @unlink($tmp);
            exit;
        }
        case 'backup_zip': {
            need('backup');
            if (!class_exists('ZipArchive')) fail('ZipArchive indisponible sur ce serveur : téléchargez la base seule.');
            @set_time_limit(300);
            $dir = backup_dir();
            $db = $dir . '/zip-' . token(6) . '.sqlite';
            backup_snapshot($db);
            $zipf = $dir . '/zip-' . token(6) . '.zip';
            $z = new ZipArchive();
            $z->open($zipf, ZipArchive::CREATE);
            $z->addFile($db, 'swanbida.sqlite');
            $media = SWB_ROOT . '/media';
            if (is_dir($media)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($media, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) if ($f->isFile()) $z->addFile($f->getPathname(), 'media/' . substr($f->getPathname(), strlen($media) + 1));
            }
            $z->addFromString('LISEZMOI.txt', "Sauvegarde SWANBIDA du " . date('d/m/Y H:i') . "\n\nswanbida.sqlite : à copier dans ~/swanbida-data/\nmedia/ : à copier dans ~/public_html/swanbida/media/\n");
            $z->close();
            @unlink($db);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="swanbida-sauvegarde-' . date('Y-m-d-His') . '.zip"');
            header('Content-Length: ' . filesize($zipf));
            readfile($zipf);
            @unlink($zipf);
            exit;
        }
        case 'backup_now': {
            need('backup');
            $f = backup_daily(true);
            ok(['file' => $f ? basename($f) : '', 'backups' => backups_list()]);
        }
        case 'backup_get': {
            need('backup');
            $name = basename((string) ($_GET['name'] ?? ''));
            $f = backup_dir() . '/' . $name;
            if (!preg_match('/^swanbida-\d{4}-\d{2}-\d{2}\.sqlite\.gz$/', $name) || !is_file($f)) fail('Sauvegarde introuvable.', 404);
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($f));
            readfile($f);
            exit;
        }

        default:
            fail('Action inconnue.', 404);
    }
} catch (Throwable $e) {
    error_log('[swanbida] admin ' . $action . ' : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('Erreur serveur : ' . $e->getMessage(), 500);
}

/** Utilisateur courant sans rouvrir la session (déjà vérifiée par need()). */
function admin_user_cached(): ?array
{
    return $_SESSION['swb'] ?? null;
}
