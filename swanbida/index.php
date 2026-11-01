<?php
/**
 * SWANBIDA — pages publiques (dehini.com/swanbida/). Toutes les adresses qui ne sont pas des
 * fichiers réels arrivent ici (voir .htaccess). Français sans préfixe, arabe sous /ar/.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/markdown.php';
require __DIR__ . '/lib/media.php';
require __DIR__ . '/lib/orders.php';
require __DIR__ . '/lib/meta.php';
require __DIR__ . '/lib/view.php';
require __DIR__ . '/lib/seo.php';
require __DIR__ . '/lib/auth.php';

security_headers();
$NONCE = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$NONCE' https://connect.facebook.net; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; "
    . "img-src 'self' data: https://*.facebook.com https://i.ytimg.com; connect-src 'self' https://*.facebook.com https://connect.facebook.net; "
    . "frame-src https://www.youtube-nocookie.com; media-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");

/* ------------------------------------------------------------------ adresse */

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$uri = rawurldecode($uri);
$base = base_path();
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$redirect = function (string $to, int $code = 301) use ($qs) {
    header('Location: ' . $to . ($qs !== '' ? '?' . $qs : ''), true, $code);
    exit;
};
if ($uri === $base) $redirect($base . '/');
$path = strpos($uri, $base . '/') === 0 ? substr($uri, strlen($base) + 1) : ltrim($uri, '/');
$lang = 'fr';
if ($path === 'ar') $redirect($base . '/ar/');
if (strpos($path, 'ar/') === 0) {
    $lang = 'ar';
    $path = substr($path, 3);
}
if ($path === 'index.php') $redirect($base . '/' . ($lang === 'ar' ? 'ar/' : ''));
if ($path !== '' && substr($path, -1) === '/') $redirect($base . '/' . ($lang === 'ar' ? 'ar/' : '') . rtrim($path, '/'));
current_lang($lang);

// Identifiant anonyme du visiteur (déduplication Meta, « external_id ») : un cookie, rien d'autre.
$VID = preg_match('/^[a-f0-9]{32}$/', (string) ($_COOKIE['swb_vid'] ?? '')) ? (string) $_COOKIE['swb_vid'] : token(16);
if (($_COOKIE['swb_vid'] ?? '') !== $VID) {
    setcookie('swb_vid', $VID, ['expires' => time() + 365 * 86400, 'path' => $base . '/', 'secure' => is_https(), 'httponly' => false, 'samesite' => 'Lax']);
}

/* ------------------------------------------------------------------- routes */

$seg = $path === '' ? [] : explode('/', $path);
try {
    route($seg, $lang);
} catch (Throwable $e) {
    error_log('[swanbida] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>SWANBIDA</title><p style="font-family:sans-serif;padding:2rem">Le site est momentanément indisponible. Réessayez dans un instant.</p>';
}

function route(array $seg, string $lang): void
{
    $n = count($seg);
    $a = $seg[0] ?? '';
    if ($n === 0) { page_home(); return; }
    if ($lang === 'fr' && $n === 1 && $a === 'sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo sitemap_xml();
        return;
    }
    if ($lang === 'fr' && $n === 1 && $a === 'robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo robots_txt();
        return;
    }
    if ($a === 'boutique' && $n <= 2) { page_shop($seg[1] ?? null); return; }
    if ($a === 'produit' && $n === 2) { page_product($seg[1]); return; }
    if ($a === 'guides' && $n <= 2) { $n === 1 ? page_guides() : page_article($seg[1]); return; }
    if ($a === 'commande' && $n === 2) { page_order($seg[1]); return; }
    if ($n === 1) {
        switch ($a) {
            case 'notre-histoire': page_story(); return;
            case 'livraison': page_delivery(); return;
            case 'contact': page_contact(); return;
            case 'suivi': page_track(); return;
            case 'confidentialite': page_privacy(); return;
        }
    }
    page_404();
}

/** Titre / description d'une page à partir des réglages SEO de l'admin. */
function seo_for(string $key): array
{
    $s = content('seo')[$key] ?? [];
    return ['title' => tr($s['title'] ?? [], current_lang()) ?: 'SWANBIDA', 'description' => tr($s['description'] ?? [], current_lang())];
}

function page_home(): void
{
    $home = content('home');
    $featured = product_by_slug((string) ($home['featured'] ?? '')) ?: (products_list(['limit' => 1])[0] ?? null);
    $products = products_list(['limit' => 8]);
    $seo = seo_for('home');
    render('home', [
        'home' => $home, 'featured' => $featured, 'products' => $products, 'collections' => collections_list(),
        'articles' => articles_list(current_lang(), 3),
        'page' => $seo + [
            'route' => '', 'body' => 'page-home', 'image' => img_og($home['hero_image'] ?? ($featured ? product_main_image($featured) : null)),
            'schema' => [schema_org(), schema_website()], 'preload' => $home['hero_image'] ?? null,
        ],
    ]);
}

function page_shop(?string $slug): void
{
    $lang = current_lang();
    $col = null;
    if ($slug !== null) {
        $col = collection_by_slug($slug);
        if (!$col) { page_404(); return; }
    }
    $sorts = ['recommandes' => 'reco', 'prix-croissant' => 'price-asc', 'prix-decroissant' => 'price-desc', 'nouveautes' => 'new'];
    $sortKey = isset($sorts[$_GET['tri'] ?? '']) ? (string) $_GET['tri'] : 'recommandes';
    $stock = ($_GET['dispo'] ?? '') === '1';
    $products = products_list(['collection' => $col['id'] ?? 0, 'sort' => $sorts[$sortKey], 'stock' => $stock]);
    $crumbs = [[t('nav_home'), ''], [t('nav_shop'), 'boutique']];
    if ($col) {
        $crumbs[] = [tr($col['name'], $lang), 'boutique/' . $col['slug']];
        $seo = [
            'title' => tr($col['seo']['title'] ?? [], $lang) ?: tr($col['name'], $lang) . ' – SWANBIDA',
            'description' => tr($col['seo']['description'] ?? [], $lang) ?: tr($col['description'], $lang),
        ];
    } else {
        $seo = seo_for('shop');
    }
    $route = $col ? 'boutique/' . $col['slug'] : 'boutique';
    $items = [];
    foreach ($products as $i => $p) $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => page_abs('produit/' . $p['slug'])];
    render('shop', [
        'collection' => $col, 'products' => $products, 'collections' => collections_list(), 'sortKey' => $sortKey, 'stockOnly' => $stock,
        'page' => $seo + [
            'route' => $route, 'body' => 'page-shop', 'breadcrumbs' => $crumbs,
            'image' => img_og($col['image'] ?? (isset($products[0]) ? product_main_image($products[0]) : null)),
            'noindex' => $stock || $sortKey !== 'recommandes',
            'schema' => [schema_org(), schema_breadcrumbs($crumbs), ['@type' => 'ItemList', 'itemListElement' => $items]],
        ],
    ]);
}

function page_product(string $slug): void
{
    $lang = current_lang();
    $preview = admin_session_ok();
    $p = product_by_slug($slug, $preview);
    if (!$p) { page_404(); return; }
    $cids = product_collection_ids($p['id']);
    $related = [];
    foreach ($cids as $cid) {
        foreach (products_list(['collection' => $cid, 'exclude' => $p['id'], 'limit' => 4]) as $r) $related[$r['id']] = $r;
    }
    if (!$related) foreach (products_list(['exclude' => $p['id'], 'limit' => 4]) as $r) $related[$r['id']] = $r;
    $crumbs = [[t('nav_home'), ''], [t('nav_shop'), 'boutique'], [tr($p['name'], $lang), 'produit/' . $p['slug']]];
    $tier = default_tier($p);
    $state = stock_state($p);
    render('product', [
        'p' => $p, 'related' => array_slice(array_values($related), 0, 4), 'state' => $state, 'delivery' => content('delivery'),
        'page' => [
            'title' => tr($p['seo']['title'] ?? [], $lang) ?: tr($p['name'], $lang) . ' – SWANBIDA',
            'description' => tr($p['seo']['description'] ?? [], $lang) ?: md_plain(tr($p['short'], $lang), 160),
            'route' => 'produit/' . $p['slug'], 'body' => 'page-product', 'type' => 'product', 'breadcrumbs' => $crumbs,
            'image' => img_og(product_main_image($p)), 'noindex' => $p['status'] !== 'active', 'preload' => product_main_image($p),
            'schema' => [schema_org(), schema_product($p), schema_breadcrumbs($crumbs)],
            'og_price' => $tier['price'],
            'js' => ['page' => 'product', 'product' => [
                'id' => $p['id'], 'sku' => $p['sku'] ?: 'P' . $p['id'], 'name' => tr($p['name'], 'fr'), 'state' => $state,
                'tiers' => array_map(fn($t) => ['qty' => $t['qty'], 'price' => $t['price'], 'compare' => $t['compare']], $p['tiers']),
                'default' => $tier['qty'], 'stock' => $p['stock'],
            ]],
            'sticky' => $state !== 'out' ? ['price' => $tier['price'], 'compare' => $tier['compare']] : null,
        ],
    ]);
}

function page_story(): void
{
    $c = content('story');
    $crumbs = [[t('nav_home'), ''], [t('nav_story'), 'notre-histoire']];
    render('story', ['c' => $c, 'page' => seo_for('story') + [
        'route' => 'notre-histoire', 'body' => 'page-story', 'breadcrumbs' => $crumbs, 'image' => img_og($c['image'] ?? null),
        'schema' => [schema_org(), schema_breadcrumbs($crumbs)],
    ]]);
}

function page_delivery(): void
{
    $c = content('delivery');
    $crumbs = [[t('nav_home'), ''], [t('nav_delivery'), 'livraison']];
    $wilayas = db()->query('SELECT * FROM wilayas WHERE active = 1 ORDER BY pos, code')->fetchAll();
    render('delivery', ['c' => $c, 'wilayas' => $wilayas, 'page' => seo_for('delivery') + [
        'route' => 'livraison', 'body' => 'page-delivery', 'breadcrumbs' => $crumbs,
        'schema' => [schema_org(), schema_breadcrumbs($crumbs)],
    ]]);
}

function page_contact(): void
{
    $crumbs = [[t('nav_home'), ''], [t('nav_contact'), 'contact']];
    render('contact', ['c' => content('contact'), 'g' => content('global'), 'page' => seo_for('contact') + [
        'route' => 'contact', 'body' => 'page-contact', 'breadcrumbs' => $crumbs,
        'schema' => [schema_org(), schema_breadcrumbs($crumbs), ['@type' => 'ContactPage', 'url' => page_abs('contact'), 'about' => ['@id' => abs_url('#organization')]]],
    ]]);
}

function page_privacy(): void
{
    $c = content('privacy');
    $crumbs = [[t('nav_home'), ''], [tr($c['title'], current_lang()), 'confidentialite']];
    render('privacy', ['c' => $c, 'page' => [
        'title' => tr($c['title'], current_lang()) . ' – SWANBIDA', 'description' => md_plain(tr($c['body'], current_lang()), 155),
        'route' => 'confidentialite', 'body' => 'page-privacy', 'breadcrumbs' => $crumbs, 'schema' => [schema_breadcrumbs($crumbs)],
    ]]);
}

function page_guides(): void
{
    $crumbs = [[t('nav_home'), ''], [t('nav_guides'), 'guides']];
    render('guides', ['articles' => articles_list(current_lang()), 'page' => seo_for('guides') + [
        'route' => 'guides', 'body' => 'page-guides', 'breadcrumbs' => $crumbs, 'schema' => [schema_org(), schema_breadcrumbs($crumbs)],
    ]]);
}

function page_article(string $slug): void
{
    $lang = current_lang();
    $a = article_by_slug($slug);
    if (!$a) { page_404(); return; }
    $langs = array_values(array_filter(SWB_LANGS, fn($l) => article_has_lang($a, $l)));
    if (!in_array($lang, $langs, true)) {
        // Guide pas encore traduit : on renvoie vers la langue où il existe.
        header('Location: ' . url('guides/' . $a['slug'], $langs[0] ?? 'fr'), true, 302);
        return;
    }
    $crumbs = [[t('nav_home'), ''], [t('nav_guides'), 'guides'], [tr($a['title'], $lang), 'guides/' . $a['slug']]];
    $more = array_values(array_filter(articles_list($lang, 4), fn($x) => $x['id'] !== $a['id']));
    render('article', ['a' => $a, 'more' => array_slice($more, 0, 3), 'products' => products_list(['limit' => 2]), 'page' => [
        'title' => tr($a['seo']['title'] ?? [], $lang) ?: tr($a['title'], $lang) . ' – SWANBIDA',
        'description' => tr($a['seo']['description'] ?? [], $lang) ?: tr($a['excerpt'], $lang),
        'route' => 'guides/' . $a['slug'], 'langs' => $langs, 'type' => 'article', 'body' => 'page-article', 'breadcrumbs' => $crumbs,
        'image' => img_og($a['cover'] ?? null), 'schema' => [schema_org(), schema_article($a), schema_breadcrumbs($crumbs)],
        'published' => $a['published'],
    ]]);
}

function page_order(string $refIn): void
{
    $ref = normalize_ref($refIn);
    $o = $ref !== '' ? order_by_ref($ref) : null;
    $k = (string) ($_GET['k'] ?? '');
    if (!$o || $k === '' || !hash_equals($o['token'], $k)) { page_404(); return; }
    header('Cache-Control: no-store');
    render('order', ['o' => order_public($o, current_lang()), 'fresh' => time() - $o['created'] < 3 * 3600, 'link' => url('commande/' . $o['ref']) . '?k=' . $o['token'], 'page' => [
        'title' => t('order_number') . ' ' . $o['ref'] . ' – SWANBIDA', 'description' => '', 'route' => 'commande/' . $o['ref'],
        'noindex' => true, 'body' => 'page-order', 'langs' => [], 'js' => ['page' => 'order', 'order' => ['ref' => $o['ref'], 'url' => url('commande/' . $o['ref']) . '?k=' . $o['token']]],
    ]]);
}

function page_track(): void
{
    $result = null;
    $error = '';
    $refIn = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $refIn = one_line($_POST['ref'] ?? '', 20);
        $ref = normalize_ref($refIn);
        $phone = normalize_phone((string) ($_POST['phone'] ?? ''));
        if (!rate_ok('track:' . ip_hash(), 15, 900)) {
            $error = t('err_rate');
        } else {
            $o = $ref !== '' && $phone !== '' ? order_by_ref($ref) : null;
            if ($o && hash_equals($o['phone'], $phone)) {
                header('Location: ' . url('commande/' . $o['ref']) . '?k=' . $o['token'], true, 303);
                return;
            }
            $error = t('track_notfound');
        }
    }
    $crumbs = [[t('nav_home'), ''], [t('nav_track'), 'suivi']];
    render('track', ['error' => $error, 'refIn' => $refIn, 'page' => seo_for('track') + [
        'route' => 'suivi', 'body' => 'page-track', 'breadcrumbs' => $crumbs, 'noindex' => true, 'js' => ['page' => 'track'],
    ]]);
}

function page_404(): void
{
    render('notfound', ['products' => products_list(['limit' => 2]), 'page' => [
        'title' => t('nf_title') . ' – SWANBIDA', 'route' => '', 'noindex' => true, 'langs' => [], 'body' => 'page-404',
    ]], 404);
}
