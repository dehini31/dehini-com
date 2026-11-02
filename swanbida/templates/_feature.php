<?php
/**
 * Bloc « pack » partagé (accueil et fiche produit) : mosaïque de photos, contenu du pack,
 * prix d'un pack (fixe) et bouton. Attend $fp (produit), $fLink (lien vers la fiche, ou '' sur
 * la fiche elle-même), $fCta (lien du bouton) et $fEyebrow (sur-titre, peut être vide).
 */
$lang = current_lang();
$t1 = $fp['tiers'][0];
$mosaic = array_slice($fp['images'], 1, 3);
?>
<section class="section featured">
  <div class="wrap featured-grid">
    <?php if ($mosaic): ?>
    <div class="featured-media reveal">
      <div class="mosaic">
        <?php foreach ($mosaic as $i => $im): ?>
        <?php if ($fLink !== ''): ?><a href="<?= e($fLink) ?>" class="mosaic-<?= $i ?>" tabindex="-1" aria-hidden="true"><?= picture($im, ['sizes' => '(min-width: 960px) 25vw, 50vw']) ?></a>
        <?php else: ?><div class="mosaic-<?= $i ?>"><?= picture($im, ['sizes' => '(min-width: 960px) 25vw, 50vw']) ?></div><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="featured-copy">
      <?php if ($fEyebrow !== ''): ?><p class="eyebrow reveal"><?= e($fEyebrow) ?></p><?php endif; ?>
      <h2 class="h2 reveal"><?php if ($fLink !== ''): ?><a href="<?= e($fLink) ?>"><?= e(tr($fp['name'], $lang)) ?></a><?php else: ?><?= e(tr($fp['name'], $lang)) ?><?php endif; ?></h2>
      <?php if (tr($fp['short'], $lang) !== ''): ?><p class="reveal"><?= e(tr($fp['short'], $lang)) ?></p><?php endif; ?>
      <?php if ($fp['contents']): ?>
      <h3 class="h-small reveal"><?= e(t('pack_contains')) ?></h3>
      <ul class="contents-list reveal">
        <?php foreach ($fp['contents'] as $c): ?><li><?= icon('check') ?><span><?= e(tr($c, $lang)) ?></span></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <div class="featured-buy reveal">
        <?= price_html($t1['price'], $t1['compare'], 'price-lg') ?>
        <a class="btn btn-primary btn-lg" href="<?= e($fCta) ?>" data-cta="featured"><?= e(t('order_now_long')) ?></a>
      </div>
    </div>
  </div>
</section>
