<?php /** Guide. */ $lang = current_lang(); $body = tr($a['body'], $lang); ?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<article class="article">
  <header class="wrap narrow article-head">
    <p class="meta"><?= e(t('published_on', ['d' => date_long($a['published'])])) ?> · <?= e(t('min_read', ['n' => reading_minutes($body)])) ?></p>
    <h1 class="display-sm"><?= e(tr($a['title'], $lang)) ?></h1>
    <p class="lead"><?= e(tr($a['excerpt'], $lang)) ?></p>
  </header>
  <?php if (!empty($a['cover'])): ?><div class="wrap article-cover"><?= picture($a['cover'], ['sizes' => '(min-width: 960px) 880px, 100vw', 'lazy' => false]) ?></div><?php endif; ?>
  <div class="wrap narrow prose prose-lg"><?= markdown($body) ?></div>
</article>
<?php if ($products): ?>
<section class="section section-white"><div class="wrap">
  <h2 class="h2 reveal"><?= e(t('featured_title')) ?></h2>
  <div class="grid-cards"><?php foreach ($products as $p) require __DIR__ . '/_card.php'; ?></div>
</div></section>
<?php endif; ?>
<?php if ($more): ?>
<section class="section"><div class="wrap">
  <div class="section-head"><h2 class="h2"><?= e(t('guides_title')) ?></h2><a class="link-arrow" href="<?= e(url('guides')) ?>"><?= e(t('all_guides')) ?> <?= icon('arrow', 'ico ico-dir') ?></a></div>
  <div class="grid-articles"><?php foreach ($more as $a) require __DIR__ . '/_article_card.php'; ?></div>
</div></section>
<?php endif; ?>
