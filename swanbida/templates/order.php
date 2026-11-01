<?php
/** Commande : remerciement juste après l'envoi, puis suivi. N'envoie AUCUN événement de conversion. */
$lang = current_lang();
$flow = ['new', 'confirmed', 'preparing', 'shipped', 'delivered'];
$cur = array_search($o['status'], $flow, true);
$wa = whatsapp_link(t('wa_order_prefill', ['ref' => $o['ref']]));
?>
<section class="wrap narrow order-page">
  <?php if ($fresh && $o['status'] === 'new'): ?>
  <div class="thanks">
    <span class="thanks-ico"><?= icon('check') ?></span>
    <h1 class="display-sm"><?= e(t('thanks_title')) ?></h1>
    <p class="lead"><?= e(t('thanks_text', ['phone' => $o['phone']])) ?></p>
  </div>
  <?php else: ?>
  <h1 class="display-sm"><?= e(t('order_number')) ?> <?= e($o['ref']) ?></h1>
  <?php endif; ?>

  <div class="ref-box">
    <span><?= e(t('order_number')) ?></span>
    <b dir="ltr"><?= e($o['ref']) ?></b>
    <small><?= e(t('thanks_keep')) ?></small>
  </div>

  <?php if (in_array($o['status'], ['cancelled', 'returned'], true)): ?>
  <p class="status-pill status-<?= e($o['status']) ?>"><?= e(t('status')) ?> : <?= e(status_label($o['status'])) ?></p>
  <?php else: ?>
  <ol class="timeline" aria-label="<?= e(t('status')) ?>">
    <?php foreach ($flow as $i => $s): $done = $cur !== false && $i <= $cur; ?>
    <li class="<?= $done ? 'done' : '' ?><?= $cur === $i ? ' current' : '' ?>"><span class="tl-dot"><?= $done ? icon('check') : '' ?></span><span><?= e(status_label($s)) ?></span></li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <?php if ($o['tracking'] !== ''): ?><p class="tracking"><?= e(t('carrier')) ?> : <b><?= e($o['carrier'] ?: '—') ?></b> · <?= e(t('tracking_number')) ?> : <b dir="ltr"><?= e($o['tracking']) ?></b></p><?php endif; ?>

  <div class="order-card">
    <h2><?= e(t('order_summary')) ?></h2>
    <?php foreach ($o['items'] as $it): ?>
    <div class="oc-row"><span><?= e(tr($it['label'] ?? [], $lang)) ?> – <?= e(tr($it['name'] ?? [], $lang)) ?></span><b><?= e(money((int) $it['price'], $lang)) ?></b></div>
    <?php endforeach; ?>
    <div class="oc-row"><span><?= e(t('shipping')) ?></span><b><?= e($o['shipping'] ? money($o['shipping'], $lang) : t('shipping_free')) ?></b></div>
    <div class="oc-row oc-total"><span><?= e(t('total')) ?></span><b><?= e(money($o['total'], $lang)) ?></b></div>
    <dl class="oc-meta">
      <div><dt><?= e(t('customer')) ?></dt><dd><?= e($o['name']) ?> · <span dir="ltr"><?= e($o['phone']) ?></span></dd></div>
      <div><dt><?= e(t('delivery_to')) ?></dt><dd><?= e($o['commune']) ?>, <?= e($o['wilaya_name']) ?><br><?= e($o['address']) ?></dd></div>
      <div><dt><?= e(t('order_date')) ?></dt><dd><?= e(date_long($o['created'])) ?></dd></div>
    </dl>
  </div>

  <?php if ($o['status'] === 'new'): ?>
  <div class="next-steps">
    <h2><?= e(t('next_steps')) ?></h2>
    <ol><li><?= icon('phone') ?><?= e(t('step_call')) ?></li><li><?= icon('box') ?><?= e(t('step_ship')) ?></li><li><?= icon('cash') ?><?= e(t('step_pay')) ?></li></ol>
  </div>
  <?php endif; ?>
  <div class="order-actions">
    <?php if ($wa !== ''): ?><a class="btn btn-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?= icon('whatsapp') ?> <?= e(t('write_whatsapp')) ?></a><?php endif; ?>
    <a class="btn btn-ghost" href="<?= e(url('boutique')) ?>"><?= e(t('continue_shopping')) ?></a>
  </div>
</section>
