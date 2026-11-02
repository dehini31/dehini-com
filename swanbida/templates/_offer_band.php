<?php
/**
 * Grand bandeau vert « Offre spéciale » partagé (accueil et fiche produit). Attend $ob (offre
 * groupée), $obTitle, $obText, $obHref et $obPick (quantité à sélectionner sur la fiche, ou 0).
 */
$lang = current_lang();
?>
<section class="offer-band">
  <div class="wrap offer-in reveal">
    <div>
      <p class="eyebrow eyebrow-light"><?= e(t('special_offer')) ?></p>
      <h2 class="h2"><?= e($obTitle) ?></h2>
      <?php if ($obText !== ''): ?><p><?= e($obText) ?></p><?php endif; ?>
    </div>
    <div class="offer-price">
      <span class="offer-big"><?= e(money($ob['price'], $lang)) ?></span>
      <s><?= e(money($ob['compare'], $lang)) ?></s>
      <a class="btn btn-light btn-lg" href="<?= e($obHref) ?>" data-cta="offer"<?= $obPick ? ' data-pick="' . (int) $obPick . '"' : '' ?>><?= e(t('see_offer')) ?></a>
    </div>
  </div>
</section>
