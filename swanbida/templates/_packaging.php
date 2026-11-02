<?php
/** Section « L'expérience SWANBIDA, dès l'ouverture » partagée. Attend $pk (étapes), $pkTitle, $pkText. */
$lang = current_lang();
?>
<section class="section section-sand">
  <div class="wrap">
    <div class="section-head center reveal">
      <h2 class="h2"><?= e($pkTitle) ?></h2>
      <?php if ($pkText !== ''): ?><p class="lead"><?= e($pkText) ?></p><?php endif; ?>
    </div>
    <ol class="steps">
      <?php foreach ($pk as $i => $st): ?>
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
