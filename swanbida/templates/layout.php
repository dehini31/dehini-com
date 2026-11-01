<?php
/** Mise en page commune : <head> (SEO, hreflang, Open Graph, pixel Meta), en-tête, pied de page. */
$lang = current_lang();
$other = $lang === 'ar' ? 'fr' : 'ar';
$g = content('global');
$canonical = page_abs($page['route'], $lang);
$langs = $page['langs'];
$wa = whatsapp_link(t('wa_prefill', ['p' => 'SWANBIDA']));
$pixel = pixel_id();
$pv = 'pv.' . token(8);
$nav = [['nav_shop', 'boutique'], ['nav_story', 'notre-histoire'], ['nav_delivery', 'livraison'], ['nav_guides', 'guides'], ['nav_contact', 'contact']];
$here = $page['route'];
$featSlug = (string) (content('home')['featured'] ?? '');
$ctaHref = $featSlug !== '' && product_by_slug($featSlug) ? url('produit/' . $featSlug) . '#commander' : url('boutique');
$swb = [
    'base' => base_path() . '/', 'lang' => $lang, 'api' => rel_url('api.php'), 'pixel' => $pixel !== '' ? $pixel : null,
    'pv' => $pv, 'vid' => $GLOBALS['VID'] ?? '', 'currency' => 'DZD', 'geo' => rel_url('api.php') . '?action=geo&v=' . geo_version(),
    'desk' => setting('desk_enabled') === '1', 'wa' => whatsapp_number(), 'home' => url('', $lang),
    'i18n' => [
        'err_name' => t('err_name'), 'err_phone' => t('err_phone'), 'err_wilaya' => t('err_wilaya'), 'err_commune' => t('err_commune'),
        'err_address' => t('err_address'), 'err_generic' => t('err_generic'), 'err_network' => t('err_network'), 'sending' => t('sending'),
        'select_commune' => t('select_commune'), 'select_wilaya_first' => t('select_wilaya_first'), 'other_commune' => t('other_commune'),
        'shipping_free' => t('shipping_free'), 'submit' => t('submit'), 'photo_n' => t('photo_n'), 'close' => t('close'),
        'save' => t('save'), 'per_pack' => t('per_pack'), 'st_new' => t('st_new'), 'order_number' => t('order_number'),
    ],
] + $page['js'];
$dirAttr = $lang === 'ar' ? 'rtl' : 'ltr';
$fonts = $lang === 'ar'
    ? 'https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600&display=swap'
    : 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600&family=Jost:wght@400;500;600&display=swap';
?><!DOCTYPE html>
<html lang="<?= e(HREFLANG[$lang]) ?>" dir="<?= $dirAttr ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($page['title']) ?></title>
<?php if ($page['description'] !== ''): ?><meta name="description" content="<?= e($page['description']) ?>">
<?php endif; ?>
<?php if ($page['noindex']): ?><meta name="robots" content="noindex, follow">
<?php else: ?><meta name="robots" content="index, follow, max-image-preview:large">
<link rel="canonical" href="<?= e($canonical) ?>">
<?php foreach ($langs as $l): ?><link rel="alternate" hreflang="<?= e(HREFLANG[$l]) ?>" href="<?= e(page_abs($page['route'], $l)) ?>">
<?php endforeach; if (count($langs) > 1): ?><link rel="alternate" hreflang="x-default" href="<?= e(page_abs($page['route'], 'fr')) ?>">
<?php endif; endif; ?>
<meta name="theme-color" content="#faf7f1">
<meta property="og:site_name" content="SWANBIDA">
<meta property="og:type" content="<?= $page['type'] === 'product' ? 'product' : ($page['type'] === 'article' ? 'article' : 'website') ?>">
<meta property="og:title" content="<?= e($page['title']) ?>">
<?php if ($page['description'] !== ''): ?><meta property="og:description" content="<?= e($page['description']) ?>">
<?php endif; ?>
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:locale" content="<?= $lang === 'ar' ? 'ar_AR' : 'fr_FR' ?>">
<meta property="og:locale:alternate" content="<?= $lang === 'ar' ? 'fr_FR' : 'ar_AR' ?>">
<meta property="og:image" content="<?= e($page['image'] ?? abs_url('assets/img/og-default.jpg')) ?>">
<?php if (!empty($page['og_price'])): ?><meta property="product:price:amount" content="<?= (int) $page['og_price'] ?>">
<meta property="product:price:currency" content="DZD">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<?php if (setting('gsc_verification') !== ''): ?><meta name="google-site-verification" content="<?= e(setting('gsc_verification')) ?>">
<?php endif; if (setting('fb_domain_verification') !== ''): ?><meta name="facebook-domain-verification" content="<?= e(setting('fb_domain_verification')) ?>">
<?php endif; ?>
<link rel="icon" href="<?= e(rel_url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="icon" href="<?= e(rel_url('assets/img/favicon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= e(rel_url('assets/img/apple-touch-icon.png')) ?>">
<link rel="manifest" href="<?= e(rel_url('assets/site.webmanifest')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php if ($pixel !== ''): ?><link rel="preconnect" href="https://connect.facebook.net">
<?php endif; ?>
<?php if (!empty($page['preload'])): $pi = $page['preload']; ?><link rel="preload" as="image" imagesrcset="<?= e(img_srcset($pi)) ?>" imagesizes="(min-width: 960px) 50vw, 100vw" fetchpriority="high">
<?php endif; ?>
<link rel="stylesheet" href="<?= e($fonts) ?>">
<?php if ($lang === 'ar'): ?><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&amp;text=SWANBIDA&amp;display=swap">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<?= json_ld($page['schema'], $GLOBALS['NONCE']) ?>
<script nonce="<?= e($GLOBALS['NONCE']) ?>">document.documentElement.classList.add('js');window.SWB=<?= json_encode($swb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php if ($pixel !== ''): ?>
<script nonce="<?= e($GLOBALS['NONCE']) ?>">
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init',SWB.pixel,SWB.vid?{external_id:SWB.vid}:{});fbq('track','PageView',{},{eventID:SWB.pv});
</script>
<?php endif; ?>
<script src="<?= e(asset('assets/js/site.js')) ?>" defer></script>
</head>
<body class="<?= e($page['body']) ?>">
<?php if ($pixel !== ''): ?><noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=<?= e($pixel) ?>&amp;ev=PageView&amp;noscript=1"></noscript>
<?php endif; ?>
<a class="skip" href="#main"><?= e(t('skip')) ?></a>
<?php if (tr($g['announcement'] ?? [], $lang) !== ''): ?>
<div class="announce"><p><?= e(tr($g['announcement'], $lang)) ?></p></div>
<?php endif; ?>
<header class="site-header" id="top">
  <div class="wrap header-in">
    <button class="icon-btn menu-btn" type="button" aria-label="<?= e(t('menu')) ?>" aria-expanded="false" aria-controls="drawer" data-menu><?= icon('menu') ?></button>
    <a class="brand" href="<?= e(url('')) ?>" aria-label="<?= e(t('home_title_alt')) ?>"><?= logo_svg() ?></a>
    <nav class="main-nav" aria-label="<?= e(t('menu')) ?>">
      <?php foreach ($nav as [$k, $r]): ?><a href="<?= e(url($r)) ?>"<?= $here === $r || strpos($here, $r . '/') === 0 ? ' aria-current="page"' : '' ?>><?= e(t($k)) ?></a><?php endforeach; ?>
    </nav>
    <div class="header-actions">
      <?php if (in_array($other, $langs, true) || !$langs): ?>
      <a class="lang-switch" href="<?= e(url($langs ? $page['route'] : '', $other)) ?>" hreflang="<?= e(HREFLANG[$other]) ?>" lang="<?= e($other) ?>" title="<?= e(t('lang_other_label')) ?>"><?= e(t('lang_other')) ?></a>
      <?php endif; ?>
      <a class="btn btn-sm btn-primary header-cta" href="<?= e($page['body'] === 'page-product' ? '#commander' : $ctaHref) ?>" data-cta="header"><?= e(t('order_now')) ?></a>
    </div>
  </div>
</header>
<div class="drawer" id="drawer" hidden>
  <div class="drawer-panel" role="dialog" aria-modal="true" aria-label="<?= e(t('menu')) ?>">
    <div class="drawer-top"><?= logo_svg('logo logo-sm') ?><button class="icon-btn" type="button" data-menu-close aria-label="<?= e(t('close')) ?>"><?= icon('close') ?></button></div>
    <nav class="drawer-nav">
      <a href="<?= e(url('')) ?>"><?= e(t('nav_home')) ?></a>
      <?php foreach ($nav as [$k, $r]): ?><a href="<?= e(url($r)) ?>"><?= e(t($k)) ?></a><?php endforeach; ?>
      <a href="<?= e(url('suivi')) ?>"><?= e(t('nav_track')) ?></a>
    </nav>
    <?php if ($wa !== ''): ?><a class="btn btn-wa btn-block" href="<?= e($wa) ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?= icon('whatsapp') ?> <?= e(t('write_whatsapp')) ?></a><?php endif; ?>
  </div>
</div>
<main id="main"><?= $content ?></main>
<footer class="site-footer">
  <div class="wrap footer-grid">
    <div class="footer-brand">
      <?= logo_svg('logo logo-light') ?>
      <p class="footer-tag"><?= e(tr($g['tagline'] ?? [], $lang)) ?></p>
      <p><?= e(tr($g['footer_about'] ?? [], $lang)) ?></p>
    </div>
    <nav class="footer-col" aria-label="SWANBIDA">
      <h2><?= e(t('nav_shop')) ?></h2>
      <a href="<?= e(url('boutique')) ?>"><?= e(t('all_products')) ?></a>
      <?php foreach (collections_list() as $c): ?><a href="<?= e(url('boutique/' . $c['slug'])) ?>"><?= e(tr($c['name'], $lang)) ?></a><?php endforeach; ?>
      <a href="<?= e(url('guides')) ?>"><?= e(t('nav_guides')) ?></a>
    </nav>
    <nav class="footer-col" aria-label="<?= e(t('legal')) ?>">
      <h2><?= e(t('legal')) ?></h2>
      <a href="<?= e(url('notre-histoire')) ?>"><?= e(t('nav_story')) ?></a>
      <a href="<?= e(url('livraison')) ?>"><?= e(t('nav_delivery')) ?></a>
      <a href="<?= e(url('suivi')) ?>"><?= e(t('nav_track')) ?></a>
      <a href="<?= e(url('contact')) ?>"><?= e(t('nav_contact')) ?></a>
      <a href="<?= e(url('confidentialite')) ?>"><?= e(t('privacy')) ?></a>
    </nav>
    <div class="footer-col">
      <h2><?= e(t('follow_us')) ?></h2>
      <div class="socials">
        <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $k => $label): if (!empty($g[$k])): ?>
        <a href="<?= e($g[$k]) ?>" target="_blank" rel="noopener" aria-label="<?= e($label) ?>"><?= icon($k) ?></a>
        <?php endif; endforeach; ?>
        <?php if ($wa !== ''): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp" data-contact="whatsapp"><?= icon('whatsapp') ?></a><?php endif; ?>
      </div>
      <?php if (!empty($g['phone'])): ?><a href="tel:<?= e(normalize_phone((string) $g['phone']) ?: $g['phone']) ?>" data-contact="phone" dir="ltr"><?= e(phone_display((string) $g['phone'])) ?></a><?php endif; ?>
      <?php if (!empty($g['email'])): ?><a href="mailto:<?= e($g['email']) ?>"><?= e($g['email']) ?></a><?php endif; ?>
    </div>
  </div>
  <div class="wrap footer-bottom">
    <p>© <?= date('Y') ?> SWANBIDA. <?= e(t('rights')) ?></p>
    <a href="<?= e(url($langs ? $page['route'] : '', $other)) ?>" lang="<?= e($other) ?>"><?= e(t('lang_other')) ?></a>
  </div>
</footer>
<?php if (!empty($page['sticky'])): ?>
<div class="sticky-cta" data-sticky hidden>
  <div class="sticky-price"><?= price_html((int) $page['sticky']['price'], (int) $page['sticky']['compare']) ?></div>
  <a class="btn btn-primary" href="#commander" data-cta="sticky"><?= e(t('sticky_cta')) ?></a>
</div>
<?php elseif ($wa !== '' && $page['body'] !== 'page-order'): ?>
<a class="wa-float" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('write_whatsapp')) ?>" data-contact="whatsapp"><?= icon('whatsapp') ?></a>
<?php endif; ?>
</body>
</html>
