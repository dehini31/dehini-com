<?php /** Liste des guides. */ ?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<section class="page-head wrap">
  <h1 class="display-sm"><?= e(t('guides_title')) ?></h1>
  <p class="lead"><?= e(t('guides_intro')) ?></p>
</section>
<section class="wrap section-tight">
  <?php if ($articles): ?><div class="grid-articles"><?php foreach ($articles as $a) require __DIR__ . '/_article_card.php'; ?></div>
  <?php else: ?><p class="empty">—</p><?php endif; ?>
</section>
