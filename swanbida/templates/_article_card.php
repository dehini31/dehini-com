<?php /** Carte d'un guide. Attend $a. */ $lang = current_lang(); ?>
<article class="acard reveal">
  <a class="acard-media" href="<?= e(url('guides/' . $a['slug'])) ?>" tabindex="-1" aria-hidden="true"><?= picture($a['cover'] ?? null, ['sizes' => '(min-width: 960px) 30vw, 92vw', 'alt' => '']) ?></a>
  <div class="acard-body">
    <p class="meta"><?= e(date_long($a['published'])) ?> · <?= e(t('min_read', ['n' => reading_minutes(tr($a['body'], $lang))])) ?></p>
    <h3><a href="<?= e(url('guides/' . $a['slug'])) ?>"><?= e(tr($a['title'], $lang)) ?></a></h3>
    <p><?= e(tr($a['excerpt'], $lang)) ?></p>
  </div>
</article>
