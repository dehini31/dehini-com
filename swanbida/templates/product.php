<?php
/** Fiche produit : galerie photos / vidéos, prix et offres, formulaire de commande, détails. */
$lang = current_lang();
$tiers = $p['tiers'];
$sel = default_tier($p);
$want = (int) ($_GET['offre'] ?? 0);
foreach ($tiers as $tt) if ($tt['qty'] === $want) $sel = $tt;
$media = [];
foreach ($p['images'] as $im) $media[] = ['kind' => 'img', 'img' => $im];
foreach ($p['videos'] as $v) $media[] = ['kind' => 'video', 'v' => $v];
$nMedia = count($media);
$wilayas = db()->query('SELECT code, fr, ar, fee_home, fee_desk FROM wilayas WHERE active = 1 ORDER BY pos, code')->fetchAll();
$desk = setting('desk_enabled') === '1';
$allFree = max_shipping_fee() === 0 && (!$desk || (int) db()->query('SELECT COALESCE(MAX(fee_desk),0) FROM wilayas WHERE active = 1')->fetchColumn() === 0);
$wa = whatsapp_link(t('wa_prefill', ['p' => tr($p['name'], $lang)]));
$name = tr($p['name'], $lang);
$base = $tiers[0]; // prix principal affiché : toujours l'offre de base (1 pack)
$bundle = null;
foreach ($tiers as $tt) if ($tt['qty'] > 1 && $tt['compare'] > $tt['price']) { $bundle = $tt; break; }
$home = content('home');
$benefits = (array) ($home['benefits'] ?? []);
$testimonials = array_values(array_filter((array) ($home['testimonials'] ?? []), fn($x) => tr($x['text'] ?? [], $lang) !== ''));
?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<?php if ($p['status'] !== 'active'): ?><div class="wrap"><p class="notice">Aperçu administrateur : ce produit est en brouillon, invisible pour les visiteurs.</p></div><?php endif; ?>

<section class="wrap pdp">
  <div class="pdp-head">
    <h1 class="pdp-title"><?= e($name) ?></h1>
    <?php if (tr($p['tagline'], $lang) !== ''): ?><p class="pdp-tagline"><?= e(tr($p['tagline'], $lang)) ?></p><?php endif; ?>
  </div>

  <div class="pdp-gallery" data-gallery>
    <div class="g-main">
      <?php if ($allFree): ?><span class="ship-tag"><?= icon('truck') ?><?= e(t('free_delivery')) ?></span><?php endif; ?>
      <div class="g-track" data-track tabindex="0" aria-label="<?= e(t('gallery')) ?>">
        <?php foreach ($media as $i => $m): ?>
        <figure class="g-slide" data-slide="<?= $i ?>" aria-label="<?= e(t('photo_n', ['n' => $i + 1, 't' => $nMedia])) ?>">
          <?php if ($m['kind'] === 'img'): ?>
          <button type="button" class="g-zoom" data-open="<?= $i ?>" aria-label="<?= e(t('zoom')) ?>">
            <?= picture($m['img'], ['sizes' => '(min-width: 960px) 52vw, 100vw', 'lazy' => $i > 0, 'priority' => $i === 0, 'class' => 'g-img']) ?>
          </button>
          <?php elseif ($m['v']['type'] === 'youtube'): ?>
          <button type="button" class="g-yt" data-yt="<?= e($m['v']['src']) ?>" aria-label="<?= e(t('play_video')) ?>">
            <img src="https://i.ytimg.com/vi/<?= e($m['v']['src']) ?>/hqdefault.jpg" alt="<?= e(tr($m['v']['title'], $lang) ?: t('video_title')) ?>" loading="lazy" width="480" height="360">
            <span class="play"><?= icon('play') ?></span>
          </button>
          <?php else: ?>
          <video class="g-video" controls playsinline preload="metadata"<?= !empty($m['v']['poster']) ? ' poster="' . e(img_file($m['v']['poster'], 960)) . '"' : '' ?> src="<?= e(rel_url($m['v']['src'])) ?>" aria-label="<?= e(tr($m['v']['title'], $lang) ?: t('video_title')) ?>"></video>
          <?php endif; ?>
        </figure>
        <?php endforeach; ?>
      </div>
      <?php if ($nMedia > 1): ?>
      <button type="button" class="g-arrow g-prev" data-prev aria-label="<?= e(t('prev')) ?>"><?= icon('chevron', 'ico ico-flip') ?></button>
      <button type="button" class="g-arrow g-next" data-next aria-label="<?= e(t('next')) ?>"><?= icon('chevron') ?></button>
      <span class="g-count" data-count aria-hidden="true" dir="ltr">1 / <?= $nMedia ?></span>
      <?php endif; ?>
    </div>
    <?php if ($nMedia > 1): ?>
    <div class="g-thumbs" role="tablist" aria-label="<?= e(t('gallery')) ?>">
      <?php foreach ($media as $i => $m): ?>
      <button type="button" class="g-thumb<?= $i === 0 ? ' is-on' : '' ?>" data-go="<?= $i ?>" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="<?= e(t('photo_n', ['n' => $i + 1, 't' => $nMedia])) ?>">
        <?php if ($m['kind'] === 'img'): ?><img src="<?= e(img_file($m['img'], 480)) ?>" alt="" loading="lazy" width="96" height="96">
        <?php elseif ($m['v']['type'] === 'youtube'): ?><img src="https://i.ytimg.com/vi/<?= e($m['v']['src']) ?>/default.jpg" alt="" loading="lazy" width="96" height="96"><span class="mini-play"><?= icon('play') ?></span>
        <?php else: ?><?php if (!empty($m['v']['poster'])): ?><img src="<?= e(img_file($m['v']['poster'], 480)) ?>" alt="" loading="lazy" width="96" height="96"><?php endif; ?><span class="mini-play"><?= icon('play') ?></span><?php endif; ?>
      </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="pdp-info">
    <?php if ($p['highlights']): ?><ul class="chips chips-lg"><?php foreach ($p['highlights'] as $hl): ?><li><?= e(tr($hl, $lang)) ?></li><?php endforeach; ?></ul><?php endif; ?>

    <div class="pdp-price">
      <?= price_html($base['price'], $base['compare'], 'price-xl') ?>
      <?php if ($base['compare'] > $base['price']): ?><span class="save-badge"><?= e(t('save', ['x' => money($base['compare'] - $base['price'], $lang)])) ?></span><?php endif; ?>
    </div>
    <p class="stock stock-<?= e($state) ?>">
      <?php if ($state === 'out'): ?><?= e(t('out_of_stock')) ?>
      <?php elseif ($state === 'low'): ?><span class="dot"></span><?= e(t('low_stock', ['n' => (int) $p['stock']])) ?>
      <?php else: ?><span class="dot"></span><?= e(t('in_stock')) ?><?php endif; ?>
    </p>

    <?php if ($bundle && $state !== 'out'): ?>
    <a class="offer-ribbon" href="?offre=<?= (int) $bundle['qty'] ?>#commander" data-pick="<?= (int) $bundle['qty'] ?>">
      <span class="or-ico"><?= icon('gift') ?></span>
      <span class="or-txt"><b><?= e(t('special_offer')) ?> : <?= e(tr($bundle['label'], $lang)) ?> <?= e(t('for_price', ['x' => money($bundle['price'], $lang)])) ?></b>
        <small><?= e(t('instead_of', ['x' => money($bundle['compare'], $lang)])) ?> · <?= e(t('save', ['x' => money($bundle['compare'] - $bundle['price'], $lang)])) ?></small></span>
      <?= icon('arrow', 'ico ico-dir') ?>
    </a>
    <?php endif; ?>

    <ul class="trust">
      <li><?= icon('cash') ?><span><?= e(t('trust_cod')) ?></span></li>
      <li><?= icon('truck') ?><span><?= e(t('trust_delivery')) ?></span></li>
      <li><?= icon('shield') ?><span><?= e(t('trust_check')) ?></span></li>
      <li><?= icon('refresh') ?><span><?= e(t('trust_exchange')) ?></span></li>
    </ul>

    <?php if ($state === 'out'): ?>
    <div class="oos-box">
      <p><?= e(t('out_of_stock_text')) ?></p>
      <?php if ($wa !== ''): ?><a class="btn btn-wa btn-block" href="<?= e($wa) ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?= icon('whatsapp') ?> <?= e(t('write_whatsapp')) ?></a><?php endif; ?>
    </div>
    <?php else: ?>
    <form class="order-form" id="commander" method="post" action="<?= e(rel_url('api.php')) ?>?action=order" novalidate data-order-form>
      <div class="of-head">
        <h2><?= e(t('form_title')) ?></h2>
        <p><?= e(t('form_sub')) ?></p>
      </div>
      <input type="hidden" name="product" value="<?= (int) $p['id'] ?>">
      <input type="hidden" name="lang" value="<?= e($lang) ?>">
      <input type="hidden" name="key" value="<?= e(token(12)) ?>" data-key>
      <input type="hidden" name="attr" value="" data-attr>
      <div class="hp" aria-hidden="true"><label>Ne pas remplir <input type="text" name="hp" tabindex="-1" autocomplete="off"></label></div>

      <fieldset class="tiers">
        <legend><?= e(t('choose_pack')) ?></legend>
        <?php foreach ($tiers as $tt): $saving = $tt['compare'] > $tt['price'] ? $tt['compare'] - $tt['price'] : 0; ?>
        <label class="tier">
          <input type="radio" name="qty" value="<?= (int) $tt['qty'] ?>"<?= $tt['qty'] === $sel['qty'] ? ' checked' : '' ?> data-price="<?= (int) $tt['price'] ?>" data-compare="<?= (int) $tt['compare'] ?>">
          <span class="tier-box">
            <span class="tier-radio" aria-hidden="true"></span>
            <span class="tier-main">
              <b><?= e(tr($tt['label'], $lang) ?: ($tt['qty'] . ' ×')) ?></b>
              <?php if ($tt['qty'] > 1): ?><small><?= e(t('per_pack', ['x' => money((int) round($tt['price'] / $tt['qty']), $lang)])) ?></small><?php endif; ?>
            </span>
            <span class="tier-price"><b><?= e(money($tt['price'], $lang)) ?></b><?php if ($saving): ?><s><?= e(money($tt['compare'], $lang)) ?></s><?php endif; ?></span>
            <?php if (tr($tt['badge'], $lang) !== '' || $saving): ?><span class="tier-badge"><?= e(tr($tt['badge'], $lang) ?: t('save', ['x' => money($saving, $lang)])) ?></span><?php endif; ?>
          </span>
        </label>
        <?php endforeach; ?>
      </fieldset>

      <div class="field">
        <label for="f-name"><?= e(t('f_name')) ?></label>
        <input id="f-name" name="name" type="text" autocomplete="name" required minlength="3" maxlength="80" placeholder="<?= e(t('ph_name')) ?>" enterkeyhint="next">
        <p class="field-err" data-err="name"></p>
      </div>
      <div class="field">
        <label for="f-phone"><?= e(t('f_phone')) ?></label>
        <input id="f-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required maxlength="20" placeholder="<?= e(t('ph_phone')) ?>" dir="ltr" enterkeyhint="next">
        <p class="field-err" data-err="phone"></p>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="f-wilaya"><?= e(t('f_wilaya')) ?></label>
          <select id="f-wilaya" name="wilaya" required autocomplete="address-level1" data-wilaya>
            <option value=""><?= e(t('select_wilaya')) ?></option>
            <?php foreach ($wilayas as $w): ?><option value="<?= e($w['code']) ?>" data-h="<?= (int) $w['fee_home'] ?>" data-d="<?= (int) $w['fee_desk'] ?>"><?= e($w['code'] . ' – ' . ($lang === 'ar' && $w['ar'] !== '' ? $w['ar'] : $w['fr'])) ?></option><?php endforeach; ?>
          </select>
          <p class="field-err" data-err="wilaya"></p>
        </div>
        <div class="field">
          <label for="f-commune"><?= e(t('f_commune')) ?></label>
          <input id="f-commune" name="commune" type="text" required maxlength="80" autocomplete="address-level2" placeholder="<?= e(t('type_commune')) ?>" data-commune>
          <p class="field-err" data-err="commune"></p>
        </div>
      </div>
      <div class="field">
        <label for="f-address"><?= e(t('f_address')) ?></label>
        <input id="f-address" name="address" type="text" required maxlength="250" autocomplete="street-address" placeholder="<?= e(t('ph_address')) ?>" enterkeyhint="done">
        <p class="field-err" data-err="address"></p>
      </div>
      <?php if ($desk): ?>
      <fieldset class="field delivery-choice">
        <legend><?= e(t('f_delivery')) ?></legend>
        <label class="pill-radio"><input type="radio" name="delivery" value="home" checked> <span><?= icon('truck') ?><?= e(t('delivery_home')) ?></span></label>
        <label class="pill-radio"><input type="radio" name="delivery" value="desk"> <span><?= icon('box') ?><?= e(t('delivery_desk')) ?></span></label>
      </fieldset>
      <?php endif; ?>
      <details class="note-toggle">
        <summary><?= e(t('f_note')) ?></summary>
        <textarea name="note" rows="2" maxlength="500" placeholder="<?= e(t('ph_note')) ?>" aria-label="<?= e(t('f_note')) ?>"></textarea>
      </details>

      <div class="summary" aria-live="polite">
        <div><span><?= e(t('subtotal')) ?></span><b data-sum-sub><?= e(money($sel['price'], $lang)) ?></b></div>
        <div><span><?= e(t('shipping')) ?></span><b data-sum-ship><?= e($allFree ? t('shipping_free') : t('shipping_pending')) ?></b></div>
        <div class="summary-total"><span><?= e(t('total')) ?></span><b data-sum-total><?= e(money($sel['price'], $lang)) ?></b></div>
      </div>
      <p class="form-err" data-form-err role="alert" hidden></p>
      <button class="btn btn-primary btn-lg btn-block btn-submit" type="submit" data-submit><span data-submit-label><?= e(t('submit')) ?></span> · <span data-submit-total><?= e(money($sel['price'], $lang)) ?></span></button>
      <p class="form-note"><?= icon('shield') ?> <?= e(t('submit_note')) ?></p>
      <p class="form-privacy"><?= e(t('privacy_note')) ?> <a href="<?= e(url('confidentialite')) ?>"><?= e(t('privacy')) ?></a></p>
    </form>
    <?php if ($wa !== ''): ?>
    <p class="help-wa"><?= e(t('need_help')) ?> <a href="<?= e($wa) ?>" target="_blank" rel="noopener" data-contact="whatsapp"><?= icon('whatsapp') ?> <?= e(t('write_whatsapp')) ?></a></p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php
// Blocs repris de l'accueil, identiques : pack (photos, contenu, prix, bouton), offre spéciale, emballage.
$isFeatured = ($home['featured'] ?? '') === $p['slug'];
$fp = $p; $fLink = ''; $fCta = '#commander'; $fEyebrow = $isFeatured ? t('featured_title') : '';
require __DIR__ . '/_feature.php';
if ($bundle && $state !== 'out') {
    $ob = $bundle;
    $obTitle = $isFeatured && tr($home['offer_title'] ?? [], $lang) !== '' ? tr($home['offer_title'], $lang)
        : tr($bundle['label'], $lang) . ' ' . t('for_price', ['x' => money($bundle['price'], $lang)]) . ' ' . t('instead_of', ['x' => money($bundle['compare'], $lang)]);
    $obText = $isFeatured ? tr($home['offer_text'] ?? [], $lang) : t('save', ['x' => money($bundle['compare'] - $bundle['price'], $lang)]);
    $obHref = '?offre=' . (int) $bundle['qty'] . '#commander'; $obPick = (int) $bundle['qty'];
    require __DIR__ . '/_offer_band.php';
}
if ($p['packaging']) {
    $pk = $p['packaging'];
    $pkTitle = tr($home['packaging_title'] ?? [], $lang) ?: t('packaging_title');
    $pkText = tr($home['packaging_text'] ?? [], $lang);
    require __DIR__ . '/_packaging.php';
}
?>

<?php if ($benefits): ?>
<section class="section benefits-alt">
  <div class="wrap">
    <div class="section-head center reveal"><h2 class="h2"><?= e(t('benefits_title')) ?></h2></div>
    <ul class="benefits">
      <?php foreach ($benefits as $i => $b): ?>
      <li class="benefit reveal" style="--d:<?= $i * 70 ?>ms"><span class="benefit-ico"><?= icon((string) ($b['icon'] ?? 'sparkle')) ?></span><h3><?= e(tr($b['title'] ?? [], $lang)) ?></h3><p><?= e(tr($b['text'] ?? [], $lang)) ?></p></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php if ($p['specs']): ?>
<section class="section section-white">
  <div class="wrap narrow reveal">
    <h2 class="h2"><?= e(t('specs_title')) ?></h2>
    <table class="specs">
      <tbody>
      <?php foreach ($p['specs'] as $s): ?><tr><th scope="row"><?= e(tr($s['label'], $lang)) ?></th><td><?= e(tr($s['value'], $lang)) ?></td></tr><?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php if ($testimonials): ?>
<section class="section">
  <div class="wrap">
    <div class="section-head center reveal"><h2 class="h2"><?= e(t('testimonials_title')) ?></h2></div>
    <div class="testimonials">
      <?php foreach ($testimonials as $tm): ?>
      <figure class="testimonial reveal">
        <?php $r = max(0, min(5, (int) ($tm['rating'] ?? 0))); if ($r): ?><div class="stars" aria-label="<?= $r ?>/5"><?= str_repeat(icon('star'), $r) ?></div><?php endif; ?>
        <blockquote><?= e(tr($tm['text'], $lang)) ?></blockquote>
        <figcaption><?= e((string) ($tm['name'] ?? '')) ?><?= !empty($tm['city']) ? ' · ' . e((string) $tm['city']) : '' ?></figcaption>
      </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (tr($p['description'], $lang) !== ''): ?>
<section class="section">
  <div class="wrap narrow prose reveal">
    <h2 class="h2"><?= e(t('description_title')) ?></h2>
    <?= markdown(tr($p['description'], $lang)) ?>
  </div>
</section>
<?php endif; ?>

<?php if (!empty($delivery['sections'])): ?>
<section class="section section-white">
  <div class="wrap narrow">
    <h2 class="h2 reveal"><?= e(t('faq_title')) ?></h2>
    <div class="faq">
      <?php foreach ($delivery['sections'] as $sct): ?>
      <details class="reveal"><summary><?= e(tr($sct['title'], $lang)) ?><?= icon('chevron-down', 'ico faq-ico') ?></summary><div class="prose"><?= markdown(tr($sct['text'], $lang)) ?></div></details>
      <?php endforeach; ?>
    </div>
    <p class="center"><a class="link-arrow" href="<?= e(url('livraison')) ?>"><?= e(t('nav_delivery')) ?> <?= icon('arrow', 'ico ico-dir') ?></a></p>
  </div>
</section>
<?php endif; ?>

<?php if ($related): ?>
<section class="section">
  <div class="wrap">
    <h2 class="h2 reveal"><?= e(t('related')) ?></h2>
    <div class="grid-cards"><?php foreach ($related as $p) require __DIR__ . '/_card.php'; ?></div>
  </div>
</section>
<?php endif; ?>

<div class="lightbox" data-lightbox hidden>
  <div class="lb-top"><span data-lb-count></span><button type="button" class="icon-btn" data-lb-close aria-label="<?= e(t('close')) ?>"><?= icon('close') ?></button></div>
  <div class="lb-track" data-lb-track>
    <?php foreach ($media as $i => $m): if ($m['kind'] !== 'img') continue; ?>
    <figure class="lb-slide" data-lb-slide="<?= $i ?>"><img data-src="<?= e(img_file($m['img'], (int) end($m['img']['widths']))) ?>" alt="<?= e(tr($m['img']['alt'] ?? [], $lang)) ?>"></figure>
    <?php endforeach; ?>
  </div>
</div>
