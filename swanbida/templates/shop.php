<?php
/** Boutique : toutes les collections ou une collection, avec tri et filtre de disponibilité. */
$lang = current_lang();
$base = $collection ? 'boutique/' . $collection['slug'] : 'boutique';
$sortLabels = ['recommandes' => t('sort_reco'), 'prix-croissant' => t('sort_price_asc'), 'prix-decroissant' => t('sort_price_desc'), 'nouveautes' => t('sort_new')];
?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<section class="page-head wrap">
  <h1 class="display-sm"><?= e($collection ? tr($collection['name'], $lang) : t('nav_shop')) ?></h1>
  <?php if ($collection && tr($collection['description'], $lang) !== ''): ?><p class="lead"><?= e(tr($collection['description'], $lang)) ?></p><?php endif; ?>
</section>
<section class="wrap shop">
  <?php if ($collections): ?>
  <nav class="col-tabs" aria-label="<?= e(t('collections')) ?>">
    <a href="<?= e(url('boutique')) ?>"<?= !$collection ? ' aria-current="page"' : '' ?>><?= e(t('all_products')) ?></a>
    <?php foreach ($collections as $c): ?><a href="<?= e(url('boutique/' . $c['slug'])) ?>"<?= $collection && $collection['id'] === $c['id'] ? ' aria-current="page"' : '' ?>><?= e(tr($c['name'], $lang)) ?></a><?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <form class="shop-tools" method="get" action="<?= e(url($base)) ?>" data-autosubmit>
    <p class="muted"><?= e(t('n_products', ['n' => count($products)])) ?></p>
    <label class="check"><input type="checkbox" name="dispo" value="1"<?= $stockOnly ? ' checked' : '' ?>> <?= e(t('in_stock_only')) ?></label>
    <label class="select-inline"><span><?= e(t('sort')) ?></span>
      <select name="tri"><?php foreach ($sortLabels as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $sortKey ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </label>
    <noscript><button class="btn btn-sm btn-ghost" type="submit"><?= e(t('filter')) ?></button></noscript>
  </form>
  <?php if ($products): ?>
  <div class="grid-cards"><?php foreach ($products as $p) require __DIR__ . '/_card.php'; ?></div>
  <?php else: ?>
  <p class="empty"><?= e(t('empty_shop')) ?></p>
  <?php endif; ?>
</section>
