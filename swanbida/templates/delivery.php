<?php /** Livraison et commande : modalités, paiement à la réception, vérification, échanges et retours. */ $lang = current_lang();
$fees = array_values(array_filter($wilayas, fn($w) => (int) $w['fee_home'] > 0 || (int) $w['fee_desk'] > 0 || $w['delay'] !== ''));
$desk = setting('desk_enabled') === '1'; ?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<section class="page-head wrap">
  <h1 class="display-sm"><?= e(tr($c['title'] ?? [], $lang)) ?></h1>
  <p class="lead"><?= e(tr($c['intro'] ?? [], $lang)) ?></p>
  <ul class="trust trust-row">
    <li><?= icon('cash') ?><span><?= e(t('trust_cod')) ?></span></li>
    <li><?= icon('truck') ?><span><?= e(t('trust_delivery')) ?></span></li>
    <li><?= icon('shield') ?><span><?= e(t('trust_check')) ?></span></li>
    <li><?= icon('refresh') ?><span><?= e(t('trust_exchange')) ?></span></li>
  </ul>
</section>
<section class="section">
  <div class="wrap narrow">
    <?php foreach ($c['sections'] ?? [] as $i => $s): ?>
    <article class="info-block reveal">
      <h2><span class="ib-n"><?= $i + 1 ?></span><?= e(tr($s['title'] ?? [], $lang)) ?></h2>
      <div class="prose"><?= markdown(tr($s['text'] ?? [], $lang)) ?></div>
    </article>
    <?php endforeach; ?>
    <?php if ($fees): ?>
    <article class="info-block reveal">
      <h2><?= e(t('shipping')) ?></h2>
      <div class="table-scroll"><table class="specs fees">
        <thead><tr><th><?= e(t('f_wilaya')) ?></th><th><?= e(t('delivery_home')) ?></th><?php if ($desk): ?><th><?= e(t('delivery_desk')) ?></th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($wilayas as $w): ?><tr><th scope="row"><?= e($w['code'] . ' – ' . ($lang === 'ar' && $w['ar'] !== '' ? $w['ar'] : $w['fr'])) ?></th><td><?= e((int) $w['fee_home'] ? money((int) $w['fee_home'], $lang) : t('shipping_free')) ?><?= $w['delay'] !== '' ? ' · ' . e($w['delay']) : '' ?></td><?php if ($desk): ?><td><?= e((int) $w['fee_desk'] ? money((int) $w['fee_desk'], $lang) : t('shipping_free')) ?></td><?php endif; ?></tr><?php endforeach; ?></tbody>
      </table></div>
    </article>
    <?php endif; ?>
    <p class="center"><a class="btn btn-ghost" href="<?= e(url('suivi')) ?>"><?= e(t('nav_track')) ?></a></p>
  </div>
</section>
