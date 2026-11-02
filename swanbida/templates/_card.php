<?php
/** Carte produit (accueil, boutique, suggestions). Attend $p. */
$lang = current_lang();
$t1 = $p['tiers'][0];
$saving = best_saving($p);
$st = stock_state($p);
$img = product_main_image($p);
$img2 = $p['images'][1] ?? null;
?>
<article class="card reveal">
  <a class="card-media" href="<?= e(product_url($p)) ?>" tabindex="-1" aria-hidden="true">
    <?= picture($img, ['sizes' => '(min-width: 1100px) 360px, (min-width: 640px) 45vw, 92vw', 'class' => 'card-img']) ?>
    <?php if ($img2): ?><?= picture($img2, ['sizes' => '(min-width: 1100px) 360px, (min-width: 640px) 45vw, 92vw', 'class' => 'card-img card-img-alt', 'alt' => '']) ?><?php endif; ?>
    <?php if (free_delivery() && $st !== 'out'): ?><span class="ship-tag ship-tag-sm"><?= icon('truck') ?><?= e(t('free_delivery')) ?></span><?php endif; ?>
    <?php if ($st === 'out'): ?><span class="tag tag-muted"><?= e(t('out_of_stock')) ?></span>
    <?php elseif ($saving > 0): ?><span class="tag"><?= e(t('special_offer')) ?></span><?php endif; ?>
  </a>
  <div class="card-body">
    <h3 class="card-title"><a href="<?= e(product_url($p)) ?>"><?= e(tr($p['name'], $lang)) ?></a></h3>
    <?php if (!empty($p['highlights'])): ?>
    <ul class="chips"><?php foreach (array_slice($p['highlights'], 0, 3) as $hl): ?><li><?= e(tr($hl, $lang)) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <div class="card-price"><?= price_html($t1['price'], $t1['compare']) ?></div>
    <?php foreach ($p['tiers'] as $tt): if ($tt['qty'] > 1 && $tt['compare'] > $tt['price']): ?>
    <p class="card-offer"><?= icon('gift') ?> <span><?= e(tr($tt['label'], $lang)) ?> : <b><?= e(money($tt['price'], $lang)) ?></b> <?= e(t('instead_of', ['x' => money($tt['compare'], $lang)])) ?></span></p>
    <?php break; endif; endforeach; ?>
    <a class="btn btn-primary btn-block" href="<?= e(product_url($p)) ?>#commander" data-cta="card"><?= e($st === 'out' ? t('view_product') : t('order_now_long')) ?></a>
  </div>
</article>
