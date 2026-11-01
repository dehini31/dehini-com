<?php /** Contact : WhatsApp, téléphone, email, réseaux sociaux. */ $lang = current_lang();
$wa = whatsapp_link(t('wa_prefill', ['p' => 'SWANBIDA']));
$tel = normalize_phone((string) ($g['phone'] ?? ''));
$has = $wa !== '' || $tel !== '' || !empty($g['email']) || !empty($g['facebook']) || !empty($g['instagram']) || !empty($g['tiktok']); ?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<section class="page-head wrap">
  <h1 class="display-sm"><?= e(tr($c['title'] ?? [], $lang)) ?></h1>
  <p class="lead"><?= e(tr($c['intro'] ?? [], $lang)) ?></p>
</section>
<section class="wrap contact-grid">
  <?php if ($wa !== ''): ?>
  <a class="contact-card contact-wa reveal" href="<?= e($wa) ?>" target="_blank" rel="noopener" data-contact="whatsapp">
    <span class="cc-ico"><?= icon('whatsapp') ?></span><b><?= e(t('whatsapp')) ?></b><span dir="ltr"><?= e(phone_display((string) $g['whatsapp'])) ?></span><span class="cc-cta"><?= e(t('write_whatsapp')) ?> <?= icon('arrow', 'ico ico-dir') ?></span>
  </a>
  <?php endif; ?>
  <?php if ($tel !== ''): ?>
  <a class="contact-card reveal" href="tel:<?= e($tel) ?>" data-contact="phone">
    <span class="cc-ico"><?= icon('phone') ?></span><b><?= e(t('phone')) ?></b><span dir="ltr"><?= e(phone_display($tel)) ?></span><span class="cc-cta"><?= e(t('call_us')) ?> <?= icon('arrow', 'ico ico-dir') ?></span>
  </a>
  <?php endif; ?>
  <?php if (!empty($g['email'])): ?>
  <a class="contact-card reveal" href="mailto:<?= e($g['email']) ?>" data-contact="email">
    <span class="cc-ico"><?= icon('mail') ?></span><b><?= e(t('email')) ?></b><span><?= e($g['email']) ?></span><span class="cc-cta"><?= e(t('email')) ?> <?= icon('arrow', 'ico ico-dir') ?></span>
  </a>
  <?php endif; ?>
  <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok'] as $k => $label): if (!empty($g[$k])): ?>
  <a class="contact-card reveal" href="<?= e($g[$k]) ?>" target="_blank" rel="noopener">
    <span class="cc-ico"><?= icon($k) ?></span><b><?= e($label) ?></b><span><?= e(t('follow_us')) ?></span><span class="cc-cta"><?= e($label) ?> <?= icon('arrow', 'ico ico-dir') ?></span>
  </a>
  <?php endif; endforeach; ?>
  <?php if (!$has): ?><p class="empty"><?= e(t('contact_empty')) ?></p><?php endif; ?>
</section>
<section class="section"><div class="wrap narrow contact-info">
  <?php if (tr($g['hours'] ?? [], $lang) !== ''): ?><p><?= icon('clock') ?> <b><?= e(t('hours')) ?> :</b> <?= e(tr($g['hours'], $lang)) ?></p><?php endif; ?>
  <?php if (tr($g['address'] ?? [], $lang) !== ''): ?><p><?= icon('pin') ?> <b><?= e(t('address')) ?> :</b> <?= e(tr($g['address'], $lang)) ?></p><?php endif; ?>
  <p><a class="link-arrow" href="<?= e(url('suivi')) ?>"><?= e(t('nav_track')) ?> <?= icon('arrow', 'ico ico-dir') ?></a></p>
</div></section>
