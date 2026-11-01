<?php
/** Aides d'affichage des pages publiques : adresses, images, icônes, prix, gabarit. */
declare(strict_types=1);

/** Adresse interne d'une page : « produit/x » → /swanbida/produit/x (ou /swanbida/ar/produit/x). */
function url(string $route = '', ?string $lang = null): string
{
    $lang = $lang ?? current_lang();
    $route = trim($route, '/');
    $prefix = $lang === 'ar' ? 'ar/' : '';
    return rel_url($prefix . $route);
}

function page_abs(string $route = '', ?string $lang = null): string
{
    return site_origin() . url($route, $lang);
}

/** Fichier d'une image dans une largeur donnée. */
function img_file(array $img, int $w, string $ext = 'webp'): string
{
    return rel_url($img['src'] . '-' . $w . '.' . $ext);
}

/** URL absolue de l'aperçu JPEG (partage Facebook / WhatsApp) d'une image. */
function img_og(?array $img): string
{
    if ($img && is_file(SWB_ROOT . '/' . $img['src'] . '-og.jpg')) return site_origin() . rel_url($img['src'] . '-og.jpg');
    return abs_url('assets/img/og-default.jpg');
}

function img_srcset(array $img, string $ext = 'webp'): string
{
    $parts = [];
    foreach ($img['widths'] ?? IMG_WIDTHS as $w) $parts[] = img_file($img, (int) $w, $ext) . ' ' . min((int) $w, (int) $img['w']) . 'w';
    return implode(', ', $parts);
}

/**
 * <picture> responsive (AVIF si disponible, WebP sinon).
 * $o : sizes, class, alt, lazy (bool), priority (bool), width/height facultatifs.
 */
function picture(?array $img, array $o = []): string
{
    if (!$img) return '';
    $alt = $o['alt'] ?? tr($img['alt'] ?? [], current_lang());
    $sizes = $o['sizes'] ?? '100vw';
    $lazy = $o['lazy'] ?? true;
    $mid = in_array(960, $img['widths'] ?? [], true) ? 960 : (int) end($img['widths']);
    $attrs = ' width="' . (int) $img['w'] . '" height="' . (int) $img['h'] . '"';
    $attrs .= $lazy ? ' loading="lazy" decoding="async"' : ' decoding="async"' . (!empty($o['priority']) ? ' fetchpriority="high"' : '');
    if (!empty($o['class'])) $attrs .= ' class="' . e($o['class']) . '"';
    $h = '<picture>';
    if (!empty($img['avif'])) $h .= '<source type="image/avif" srcset="' . e(img_srcset($img, 'avif')) . '" sizes="' . e($sizes) . '">';
    $h .= '<img src="' . e(img_file($img, $mid)) . '" srcset="' . e(img_srcset($img)) . '" sizes="' . e($sizes) . '" alt="' . e($alt) . '"' . $attrs . '>';
    return $h . '</picture>';
}

function price_html(int $price, int $compare = 0, string $cls = ''): string
{
    $lang = current_lang();
    $h = '<span class="price ' . e($cls) . '"><span class="price-now">' . e(money($price, $lang)) . '</span>';
    if ($compare > $price) $h .= ' <s class="price-old">' . e(money($compare, $lang)) . '</s>';
    return $h . '</span>';
}

/** Icônes en SVG inline (aucune requête supplémentaire). */
function icon(string $name, string $cls = 'ico'): string
{
    static $paths = [
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'chevron' => '<path d="M9 6l6 6-6 6"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'check' => '<path d="M5 12.5l4.2 4.2L19 7"/>',
        'cash' => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6.5 9.5v5M17.5 9.5v5"/>',
        'truck' => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.6"/><circle cx="17.5" cy="17.5" r="1.6"/>',
        'shield' => '<path d="M12 3l7 3v5c0 4.6-3 8.3-7 10-4-1.7-7-5.4-7-10V6z"/><path d="M8.8 12.2l2.2 2.2 4.4-4.6"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.5-4.5L4 8M4 4v4h4M4 13a8 8 0 0 0 14.5 4.5L20 16M20 20v-4h-4"/>',
        'gift' => '<rect x="3.5" y="9" width="17" height="11.5" rx="1.5"/><path d="M2.5 9h19v-3h-19zM12 6v14.5M12 6C9.5 6 7 5.2 7 3.6 7 2 9.4 2.2 12 6zM12 6c2.5 0 5-.8 5-2.4 0-1.6-2.4-1.4-5 2.4z"/>',
        'cotton' => '<path d="M12 21v-6M8.5 12.5a3.5 3.5 0 1 1 1.2-6.8 3.5 3.5 0 0 1 4.6 0 3.5 3.5 0 1 1 1.2 6.8 3.5 3.5 0 0 1-7 0z"/>',
        'layers' => '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 12.5l9 5 9-5M3 16.5l9 5 9-5"/>',
        'box' => '<path d="M3.5 7.5L12 3l8.5 4.5v9L12 21l-8.5-4.5z"/><path d="M3.5 7.5L12 12l8.5-4.5M12 12v9"/>',
        'bag' => '<path d="M5 8h14l-1 12.5H6z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        'ribbon' => '<path d="M12 9c-2-4-7-5-7-2s4 3 7 2zm0 0c2-4 7-5 7-2s-4 3-7 2zM12 9l-3.5 11M12 9l3.5 11"/>',
        'whatsapp' => '<path d="M4 20l1.2-4A8 8 0 1 1 8 18.8z"/><path d="M9 8.8c0 3.2 2.9 6.2 6.2 6.2l1.3-1.6-2-1-1 1c-1.2-.4-2.5-1.7-2.9-2.9l1-1-1-2L9 8.8z"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5l8.5 6.5 8.5-6.5"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'facebook' => '<path d="M14 8.5V7c0-.8.6-1.5 1.5-1.5H17V2.5h-2.5A4.5 4.5 0 0 0 10 7v1.5H7.5v3H10V21h3.5v-9.5H16l.5-3z"/>',
        'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".9"/>',
        'tiktok' => '<path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5M14 3c.4 2.8 2.2 4.6 5 5"/>',
        'play' => '<path d="M8 5.5v13l11-6.5z"/>',
        'zoom' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8M10.5 7.5v6M7.5 10.5h6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.8-4.8"/>',
        'star' => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
        'leaf' => '<path d="M5 19c0-8 5-13 15-14-1 10-6 15-14 15M5 19l7-7"/>',
        'sparkle' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.5 2.5M15.5 15.5L18 18M6 18l2.5-2.5M15.5 8.5L18 6"/>',
    ];
    $p = $paths[$name] ?? $paths['sparkle'];
    $fill = in_array($name, ['facebook', 'play', 'star'], true) ? ' fill="currentColor" stroke="none"' : '';
    return '<svg class="' . e($cls) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"' . $fill . '>' . $p . '</svg>';
}

/** Logo SWANBIDA (cygne + nom). */
function logo_svg(string $cls = 'logo'): string
{
    return '<svg class="' . e($cls) . '" viewBox="0 0 214 40" role="img" aria-label="SWANBIDA">'
        . '<g class="logo-swan" transform="translate(-7 -4.5) scale(.5)">'
        . '<path d="M30 66 C 32 82, 68 86, 90 52 C 80 60, 66 60, 57 55 C 48 50, 38 54, 30 66 Z"/>'
        . '<path class="logo-neck" d="M33 64 C 25 54, 27 42, 38 34 C 46 28, 48 21, 42 16.5 C 37 13, 31 15.5, 30 21"/>'
        . '<path d="M30.5 19 L 22 25.5 L 31.5 24.5 Z"/></g>'
        . '<text x="46" y="28.5" class="logo-word">SWANBIDA</text></svg>';
}

function whatsapp_number(): string
{
    $n = phone_e164((string) (content('global')['whatsapp'] ?? ''));
    return $n;
}

function whatsapp_link(string $text = ''): string
{
    $n = whatsapp_number();
    if ($n === '') return '';
    return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/** Rendu d'une page : gabarit dans templates/, puis mise en page commune. */
function render(string $template, array $vars = [], int $code = 200): void
{
    $lang = current_lang();
    $page = ($vars['page'] ?? []) + [
        'route' => '', 'langs' => SWB_LANGS, 'title' => 'SWANBIDA', 'description' => '', 'image' => null, 'type' => 'website',
        'noindex' => false, 'schema' => [], 'body' => '', 'js' => [], 'breadcrumbs' => [], 'sticky' => null,
    ];
    $vars['page'] = $page;
    extract($vars, EXTR_SKIP);
    ob_start();
    require SWB_ROOT . '/templates/' . $template . '.php';
    $content = (string) ob_get_clean();
    ob_start();
    require SWB_ROOT . '/templates/layout.php';
    $html = (string) ob_get_clean();
    finish_and_run($html, 'text/html; charset=utf-8', $code);
}

/** Fil d'Ariane visible (le JSON-LD correspondant est ajouté par seo.php). */
function breadcrumbs_html(array $items): string
{
    if (!$items) return '';
    $h = '<nav class="crumbs" aria-label="' . e(t('breadcrumb')) . '"><ol>';
    $n = count($items);
    foreach ($items as $i => [$label, $route]) {
        $h .= $i === $n - 1 ? '<li aria-current="page">' . e($label) . '</li>' : '<li><a href="' . e(url($route)) . '">' . e($label) . '</a></li>';
    }
    return $h . '</ol></nav>';
}

function product_url(array $p, ?string $lang = null): string
{
    return url('produit/' . $p['slug'], $lang);
}
