<?php /** Page introuvable. */ ?>
<section class="wrap narrow nf">
  <p class="nf-code">404</p>
  <h1 class="display-sm"><?= e(t('nf_title')) ?></h1>
  <p class="lead"><?= e(t('nf_text')) ?></p>
  <a class="btn btn-primary btn-lg" href="<?= e(url('')) ?>"><?= e(t('back_home')) ?></a>
</section>
<?php if ($products): ?><section class="section"><div class="wrap"><div class="grid-cards"><?php foreach ($products as $p) require __DIR__ . '/_card.php'; ?></div></div></section><?php endif; ?>
