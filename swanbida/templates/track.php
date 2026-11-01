<?php /** Suivi de commande (numéro + téléphone). */ ?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<section class="wrap narrow track-page">
  <h1 class="display-sm"><?= e(t('track_title')) ?></h1>
  <p class="lead"><?= e(t('track_intro')) ?></p>
  <form class="track-form" method="post" action="<?= e(url('suivi')) ?>">
    <div class="field"><label for="t-ref"><?= e(t('f_ref')) ?></label><input id="t-ref" name="ref" type="text" required maxlength="20" autocomplete="off" autocapitalize="characters" placeholder="SW-XXXXXX" value="<?= e($refIn) ?>" dir="ltr"></div>
    <div class="field"><label for="t-phone"><?= e(t('f_phone')) ?></label><input id="t-phone" name="phone" type="tel" inputmode="tel" required maxlength="20" autocomplete="tel" dir="ltr" placeholder="<?= e(t('ph_phone')) ?>"></div>
    <?php if ($error !== ''): ?><p class="form-err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <button class="btn btn-primary btn-lg btn-block" type="submit"><?= e(t('track_btn')) ?></button>
  </form>
  <div class="my-orders" data-my-orders hidden><h2><?= e(t('my_orders')) ?></h2><ul></ul></div>
</section>
