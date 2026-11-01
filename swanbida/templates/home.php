<?php
/** Accueil : présentation, produit phare, offre spéciale, collections, emballage, avantages, guides. */
$lang = current_lang();
$fp = $featured;
$fUrl = $fp ? product_url($fp) : url('boutique');
$fTier2 = null;
if ($fp) foreach ($fp['tiers'] as $tt) if ($tt['qty'] > 1 && $tt['compare'] > $tt['price']) { $fTier2 = $tt; break; }
$testimonials = array_values(array_filter((array) ($home['testimonials'] ?? []), fn($x) => tr($x['text'] ?? [], $lang) !== ''));
$packImgs = $fp ? $fp['images'] : [];
?>
<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <p class="eyebrow reveal"><?= e(tr($home['hero_eyebrow'] ?? [], $lang)) ?></p>
      <h1 class="display reveal"><?= e(tr($home['hero_title'] ?? [], $lang)) ?></h1>
      <p class="lead reveal"><?= e(tr($home['hero_text'] ?? [], $lang)) ?></p>
      <div class="hero-actions reveal">
        <a class="btn btn-primary btn-lg" href="<?= e($fUrl) ?>#commander" data-cta="hero"><?= e(t('order_now_long')) ?> <?= icon('arrow', 'ico ico-dir') ?></a>
        <a class="btn btn-ghost btn-lg" href="<?= e($fUrl) ?>"><?= e(tr($home['hero_cta'] ?? [], $lang) ?: t('discover')) ?></a>
      </div>
      <ul class="hero-trust reveal">
        <li><?= icon('cash') ?><?= e(t('trust_cod')) ?></li>
        <li><?= icon('truck') ?><?= e(t('trust_delivery')) ?></li>
        <li><?= icon('shield') ?><?= e(t('trust_check')) ?></li>
      </ul>
    </div>
    <div class="hero-visual reveal">
      <div class="hero-frame">
        <?= picture($home['hero_image'] ?? ($fp ? product_main_image($fp) : null), ['sizes' => '(min-width: 960px) 50vw, 100vw', 'lazy' => false, 'priority' => true, 'class' => 'hero-img']) ?>
      </div>
      <?php if ($fp && !empty($fp['highlights'])): ?>
      <ul class="hero-badges" aria-hidden="true">
        <?php foreach (array_slice($fp['highlights'], 0, 2) as $i => $hl): ?><li class="hb hb-<?= $i ?>"><?= e(tr($hl, $lang)) ?></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($fp): $t1 = $fp['tiers'][0]; ?>
<section class="section featured">
  <div class="wrap featured-grid">
    <div class="featured-media reveal">
      <div class="mosaic">
        <?php foreach (array_slice($packImgs, 1, 3) as $i => $im): ?>
        <a href="<?= e($fUrl) ?>" class="mosaic-<?= $i ?>" tabindex="-1" aria-hidden="true"><?= picture($im, ['sizes' => '(min-width: 960px) 25vw, 50vw']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="featured-copy">
      <p class="eyebrow reveal"><?= e(t('featured_title')) ?></p>
      <h2 class="h2 reveal"><a href="<?= e($fUrl) ?>"><?= e(tr($fp['name'], $lang)) ?></a></h2>
      <p class="reveal"><?= e(tr($fp['short'], $lang)) ?></p>
      <h3 class="h-small reveal"><?= e(t('pack_contains')) ?></h3>
      <ul class="contents-list reveal">
        <?php foreach ($fp['contents'] as $c): ?><li><?= icon('check') ?><span><?= e(tr($c, $lang)) ?></span></li><?php endforeach; ?>
      </ul>
      <div class="featured-buy reveal">
        <?= price_html($t1['price'], $t1['compare'], 'price-lg') ?>
        <a class="btn btn-primary btn-lg" href="<?= e($fUrl) ?>#commander" data-cta="featured"><?= e(t('order_now_long')) ?></a>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($fTier2): ?>
<section class="offer-band">
  <div class="wrap offer-in reveal">
    <div>
      <p class="eyebrow eyebrow-light"><?= e(t('special_offer')) ?></p>
      <h2 class="h2"><?= e(tr($home['offer_title'] ?? [], $lang)) ?></h2>
      <p><?= e(tr($home['offer_text'] ?? [], $lang)) ?></p>
    </div>
    <div class="offer-price">
      <span class="offer-big"><?= e(money($fTier2['price'], $lang)) ?></span>
      <s><?= e(money($fTier2['compare'], $lang)) ?></s>
      <a class="btn btn-light btn-lg" href="<?= e($fUrl) ?>?offre=<?= (int) $fTier2['qty'] ?>#commander" data-cta="offer"><?= e(t('see_offer')) ?></a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (count($products) > 1): ?>
<section class="section">
  <div class="wrap">
    <div class="section-head reveal"><h2 class="h2"><?= e(t('nav_shop')) ?></h2><a class="link-arrow" href="<?= e(url('boutique')) ?>"><?= e(t('all_products')) ?> <?= icon('arrow', 'ico ico-dir') ?></a></div>
    <div class="grid-cards"><?php foreach ($products as $p) require __DIR__ . '/_card.php'; ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($fp && $fp['packaging']): ?>
<section class="section section-sand">
  <div class="wrap">
    <div class="section-head center reveal">
      <h2 class="h2"><?= e(tr($home['packaging_title'] ?? [], $lang)) ?></h2>
      <p class="lead"><?= e(tr($home['packaging_text'] ?? [], $lang)) ?></p>
    </div>
    <ol class="steps">
      <?php foreach ($fp['packaging'] as $i => $st): ?>
      <li class="step reveal" style="--d:<?= $i * 90 ?>ms">
        <span class="step-ico"><?= icon(['box', 'bag', 'ribbon'][$i] ?? 'sparkle') ?></span>
        <span class="step-n"><?= $i + 1 ?></span>
        <h3><?= e(tr($st['title'], $lang)) ?></h3>
        <p><?= e(tr($st['text'], $lang)) ?></p>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>
<?php endif; ?>

<?php if ($collections): ?>
<section class="section">
  <div class="wrap">
    <div class="section-head reveal"><h2 class="h2"><?= e(t('collections_title')) ?></h2></div>
    <div class="grid-collections">
      <?php foreach ($collections as $c): ?>
      <a class="collection reveal" href="<?= e(url('boutique/' . $c['slug'])) ?>">
        <?= picture($c['image'] ?? null, ['sizes' => '(min-width: 960px) 45vw, 92vw']) ?>
        <span class="collection-label"><b><?= e(tr($c['name'], $lang)) ?></b><small><?= e(tr($c['description'], $lang)) ?></small></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($home['benefits'])): ?>
<section class="section section-white">
  <div class="wrap">
    <div class="section-head center reveal"><h2 class="h2"><?= e(t('benefits_title')) ?></h2></div>
    <ul class="benefits">
      <?php foreach ($home['benefits'] as $i => $b): ?>
      <li class="benefit reveal" style="--d:<?= $i * 70 ?>ms"><span class="benefit-ico"><?= icon((string) ($b['icon'] ?? 'sparkle')) ?></span><h3><?= e(tr($b['title'] ?? [], $lang)) ?></h3><p><?= e(tr($b['text'] ?? [], $lang)) ?></p></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php if ($testimonials): ?>
<section class="section">
  <div class="wrap">
    <div class="section-head center reveal"><h2 class="h2"><?= e(t('testimonials_title')) ?></h2></div>
    <div class="testimonials">
      <?php foreach ($testimonials as $tm): ?>
      <figure class="testimonial reveal">
        <?php $r = max(0, min(5, (int) ($tm['rating'] ?? 0))); if ($r): ?><div class="stars" aria-label="<?= $r ?>/5"><?= str_repeat(icon('star'), $r) ?></div><?php endif; ?>
        <blockquote><?= e(tr($tm['text'], $lang)) ?></blockquote>
        <figcaption><?= e((string) ($tm['name'] ?? '')) ?><?= !empty($tm['city']) ? ' · ' . e((string) $tm['city']) : '' ?></figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($articles): ?>
<section class="section">
  <div class="wrap">
    <div class="section-head reveal"><h2 class="h2"><?= e(t('guides_title')) ?></h2><a class="link-arrow" href="<?= e(url('guides')) ?>"><?= e(t('all_guides')) ?> <?= icon('arrow', 'ico ico-dir') ?></a></div>
    <div class="grid-articles"><?php foreach ($articles as $a) require __DIR__ . '/_article_card.php'; ?></div>
  </div>
</section>
<?php endif; ?>

<section class="section final-cta">
  <div class="wrap final-in reveal">
    <h2 class="display-sm"><?= e(tr($home['final_title'] ?? [], $lang)) ?></h2>
    <p class="lead"><?= e(tr($home['final_text'] ?? [], $lang)) ?></p>
    <a class="btn btn-primary btn-lg" href="<?= e($fUrl) ?>#commander" data-cta="final"><?= e(t('order_now_long')) ?></a>
  </div>
</section>
