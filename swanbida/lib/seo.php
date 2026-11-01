<?php
/** Référencement : données structurées Schema.org, sitemap.xml et robots.txt. */
declare(strict_types=1);

const HREFLANG = ['fr' => 'fr-DZ', 'ar' => 'ar-DZ'];

function schema_org(): array
{
    $g = content('global');
    $same = array_values(array_filter([$g['facebook'] ?? '', $g['instagram'] ?? '', $g['tiktok'] ?? ''], fn($u) => preg_match('~^https://~', (string) $u)));
    $org = [
        '@type' => 'Organization', '@id' => abs_url('#organization'), 'name' => 'SWANBIDA', 'url' => abs_url(''),
        'logo' => abs_url('assets/img/logo-512.png'), 'slogan' => tr($g['tagline'] ?? [], current_lang()),
    ];
    if ($same) $org['sameAs'] = $same;
    $phone = phone_e164((string) ($g['phone'] ?? '')) ?: whatsapp_number();
    if ($phone !== '' || !empty($g['email'])) {
        $cp = ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'areaServed' => 'DZ', 'availableLanguage' => ['French', 'Arabic']];
        if ($phone !== '') $cp['telephone'] = '+' . $phone;
        if (!empty($g['email'])) $cp['email'] = (string) $g['email'];
        $org['contactPoint'] = [$cp];
    }
    return $org;
}

function schema_website(): array
{
    return ['@type' => 'WebSite', '@id' => abs_url('#website'), 'name' => 'SWANBIDA', 'url' => abs_url(''),
        'inLanguage' => [HREFLANG['fr'], HREFLANG['ar']], 'publisher' => ['@id' => abs_url('#organization')]];
}

function schema_breadcrumbs(array $items): array
{
    $list = [];
    foreach ($items as $i => [$label, $route]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $label, 'item' => page_abs($route)];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

function schema_product(array $p): array
{
    $lang = current_lang();
    $images = [];
    foreach ($p['images'] as $img) $images[] = site_origin() . img_file($img, (int) end($img['widths']));
    $state = stock_state($p);
    $t1 = default_tier($p);
    foreach ($p['tiers'] as $t) {
        if ($t['qty'] === 1) { $t1 = $t; break; }
    }
    $offer = [
        '@type' => 'Offer', 'url' => page_abs('produit/' . $p['slug']), 'priceCurrency' => 'DZD', 'price' => (string) $t1['price'],
        'availability' => 'https://schema.org/' . ($state === 'out' ? 'OutOfStock' : 'InStock'),
        'itemCondition' => 'https://schema.org/NewCondition', 'seller' => ['@id' => abs_url('#organization')],
        'priceValidUntil' => date('Y-12-31', strtotime('+1 year')),
        'shippingDetails' => [
            '@type' => 'OfferShippingDetails',
            'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'DZ'],
            'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => (string) min_shipping_fee(), 'currency' => 'DZD'],
        ],
        'hasMerchantReturnPolicy' => [
            '@type' => 'MerchantReturnPolicy', 'applicableCountry' => 'DZ',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays' => max(1, (int) setting('return_days')),
        ],
    ];
    $props = [];
    foreach ($p['specs'] as $s) {
        $props[] = ['@type' => 'PropertyValue', 'name' => tr($s['label'], $lang), 'value' => tr($s['value'], $lang)];
    }
    $d = [
        '@type' => 'Product', '@id' => page_abs('produit/' . $p['slug']) . '#product', 'name' => tr($p['name'], $lang),
        'description' => md_plain(tr($p['short'], $lang) . ' ' . tr($p['description'], $lang), 4900),
        'image' => $images, 'brand' => ['@type' => 'Brand', 'name' => 'SWANBIDA'], 'offers' => $offer,
    ];
    if ($p['sku'] !== '') { $d['sku'] = $p['sku']; $d['mpn'] = $p['sku']; }
    if ($props) $d['additionalProperty'] = $props;
    foreach ($p['specs'] as $s) {
        $l = mb_strtolower(tr($s['label'], 'fr'));
        if ($l === 'matière') $d['material'] = tr($s['value'], $lang);
        if ($l === 'couleur') $d['color'] = tr($s['value'], $lang);
    }
    return $d;
}

function schema_article(array $a): array
{
    $lang = current_lang();
    $d = [
        '@type' => 'Article', 'headline' => mb_substr(tr($a['title'], $lang), 0, 110), 'inLanguage' => HREFLANG[$lang],
        'datePublished' => date('c', $a['published']), 'dateModified' => date('c', max($a['published'], $a['updated'])),
        'author' => ['@id' => abs_url('#organization')], 'publisher' => ['@id' => abs_url('#organization')],
        'mainEntityOfPage' => page_abs('guides/' . $a['slug']), 'description' => tr($a['excerpt'], $lang),
    ];
    if (!empty($a['cover'])) $d['image'] = [site_origin() . img_file($a['cover'], (int) end($a['cover']['widths']))];
    return $d;
}

function json_ld(array $nodes, string $nonce): string
{
    if (!$nodes) return '';
    $doc = ['@context' => 'https://schema.org', '@graph' => array_values($nodes)];
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return '<script type="application/ld+json" nonce="' . e($nonce) . '">' . $json . '</script>';
}

/** sitemap.xml : toutes les pages publiques, avec leurs versions française et arabe. */
function sitemap_xml(): string
{
    $entries = [];
    $add = function (string $route, int $mod, array $langs = SWB_LANGS, string $freq = 'weekly', string $prio = '0.6') use (&$entries) {
        $entries[] = compact('route', 'mod', 'langs', 'freq', 'prio');
    };
    $cv = (int) cache_version();
    $add('', max($cv, (int) db()->query('SELECT COALESCE(MAX(updated), 0) FROM products')->fetchColumn()), SWB_LANGS, 'daily', '1.0');
    $add('boutique', (int) db()->query('SELECT COALESCE(MAX(updated), 0) FROM products')->fetchColumn(), SWB_LANGS, 'daily', '0.9');
    foreach (products_list() as $p) $add('produit/' . $p['slug'], $p['updated'], SWB_LANGS, 'weekly', '0.9');
    foreach (collections_list() as $c) $add('boutique/' . $c['slug'], $c['updated'], SWB_LANGS, 'weekly', '0.7');
    foreach (['notre-histoire', 'livraison', 'contact', 'confidentialite'] as $r) $add($r, $cv, SWB_LANGS, 'monthly', '0.5');
    $add('guides', $cv, SWB_LANGS, 'weekly', '0.6');
    $seen = [];
    foreach (SWB_LANGS as $l) {
        foreach (articles_list($l) as $a) {
            if (isset($seen[$a['slug']])) continue;
            $seen[$a['slug']] = 1;
            $add('guides/' . $a['slug'], max($a['published'], $a['updated']), array_values(array_filter(SWB_LANGS, fn($x) => article_has_lang($a, $x))), 'monthly', '0.6');
        }
    }
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    foreach ($entries as $en) {
        foreach ($en['langs'] as $l) {
            $x .= "  <url>\n    <loc>" . e(page_abs($en['route'], $l)) . "</loc>\n";
            if ($en['mod'] > 0) $x .= '    <lastmod>' . date('Y-m-d', $en['mod']) . "</lastmod>\n";
            $x .= '    <changefreq>' . $en['freq'] . "</changefreq>\n    <priority>" . $en['prio'] . "</priority>\n";
            if (count($en['langs']) > 1) {
                foreach ($en['langs'] as $l2) {
                    $x .= '    <xhtml:link rel="alternate" hreflang="' . HREFLANG[$l2] . '" href="' . e(page_abs($en['route'], $l2)) . "\"/>\n";
                }
                $x .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . e(page_abs($en['route'], 'fr')) . "\"/>\n";
            }
            $x .= "  </url>\n";
        }
    }
    return $x . "</urlset>\n";
}

function robots_txt(): string
{
    $b = base_path();
    return "User-agent: *\nAllow: $b/\nDisallow: $b/admin/\nDisallow: $b/api.php\nDisallow: $b/commande/\nDisallow: $b/ar/commande/\n\nSitemap: " . abs_url('sitemap.xml') . "\n";
}
