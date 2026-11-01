<?php /** Notre histoire. */ $lang = current_lang(); ?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<section class="wrap story-hero">
  <div class="story-copy">
    <p class="eyebrow reveal"><?= e(tr(content('global')['tagline'] ?? [], $lang)) ?></p>
    <h1 class="display reveal"><?= e(tr($c['title'] ?? [], $lang)) ?></h1>
    <p class="lead reveal"><?= e(tr($c['intro'] ?? [], $lang)) ?></p>
  </div>
  <?php if (!empty($c['image'])): ?><div class="story-img reveal"><?= picture($c['image'], ['sizes' => '(min-width: 960px) 45vw, 100vw', 'lazy' => false]) ?></div><?php endif; ?>
</section>
<section class="section">
  <div class="wrap narrow prose prose-lg reveal"><?= markdown(tr($c['body'] ?? [], $lang)) ?></div>
</section>
<?php if (!empty($c['values'])): ?>
<section class="section section-sand">
  <div class="wrap">
    <ul class="values">
      <?php foreach ($c['values'] as $i => $v): ?>
      <li class="reveal" style="--d:<?= $i * 80 ?>ms"><span class="value-n">0<?= $i + 1 ?></span><h2><?= e(tr($v['title'] ?? [], $lang)) ?></h2><p><?= e(tr($v['text'] ?? [], $lang)) ?></p></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>
<section class="section final-cta"><div class="wrap final-in reveal">
  <h2 class="display-sm"><?= e(tr(content('home')['final_title'] ?? [], $lang)) ?></h2>
  <a class="btn btn-primary btn-lg" href="<?= e(url('boutique')) ?>"><?= e(t('discover')) ?></a>
</div></section>
