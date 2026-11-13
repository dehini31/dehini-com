/*
 * Maillage — page de vente d'un produit (dehini.com/maillage/offre/?p=<adresse du produit>) :
 * la page qu'une marque partage dans ses publicités. Photos, accroche, points forts, prix et
 * formulaire de commande livrée, payée à la réception. La commande part au radar : le point de
 * vente le plus proche du client qui a l'article la prend et le livre.
 */
(function () {
  'use strict';
  const { html, icon, fmt, color } = ML;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const view = () => $('#view');

  const slug = new URLSearchParams(location.search).get('p') || location.hash.replace(/^#\/?/, '');

  function fail(title, msg) {
    view().innerHTML = html`<div class="of-empty"><div class="empty">${icon('package-search')}<h3>${title}</h3><p>${msg || ''}</p><a class="btn btn-dark" href="../explorer/#/produits">${ML.t('Voir le catalogue')}</a></div></div>`;
  }

  async function render(p) {
    const L = p.landing || {};
    const title = L.headline || p.name;
    document.title = title + ' — ' + p.brand.name;
    const sizes = p.sizes;
    const colors = p.colors;
    const sel = { size: sizes.length === 1 ? sizes[0] : '', color: colors.length === 1 ? colors[0].name : '' };
    let shot = 0;
    const points = (L.points || []).length ? L.points : p.specs.slice(0, 4).map((s) => s.k + ' : ' + s.v);

    view().innerHTML = html`
      <header class="of-top">
        <span class="of-brand">${ML.mono(p.brand.name, p.brand.color, p.brand.logo, 'mono-sm')}<b>${p.brand.name}</b></span>
        ${ML.langToggle()}
      </header>
      <article class="of-page">
        <section class="of-hero">
          <div class="of-gallery">
            <div data-visual></div>
            ${p.images.length > 1 ? html`<div class="p-thumbs">${p.images.map((im, i) => html`<button type="button" class="${i === 0 ? 'is-on' : ''}" data-act="of-shot" data-i="${i}" aria-label="${ML.t('Photo')} ${i + 1}"><img src="${ML.media(im, 480)}" alt="" loading="lazy"></button>`)}</div>` : ''}
          </div>
          <div class="of-info">
            ${p.demo ? html`<span class="badge">${ML.t('Démo')}</span>` : ''}
            <h1>${title}</h1>
            ${L.headline && L.headline !== p.name ? html`<p class="of-name">${p.name}</p>` : ''}
            <p class="of-price"><b data-price></b></p>
            ${points.length ? html`<ul class="of-points">${points.map((pt) => html`<li>${icon('circle-check')}<span>${pt}</span></li>`)}</ul>` : ''}
            ${colors.length > 1 ? html`<div class="opt"><span class="label">${ML.t('Couleur')} <b data-color-name></b></span><div class="opt-row" data-colors></div></div>` : ''}
            ${sizes.length > 1 ? html`<div class="opt"><span class="label">${ML.t('Taille')}</span><div class="opt-row" data-sizes></div></div>` : ''}
            <a class="btn btn-primary btn-lg btn-block of-go" href="#commander">${icon('truck')}${ML.t('Commander maintenant')}</a>
            <ul class="of-trust">
              <li>${icon('shield-check')}<span>${ML.t('Paiement à la livraison')}</span></li>
              <li>${icon('store')}<span>${ML.t('Livré par le point de vente le plus proche')}</span></li>
              <li>${icon('phone')}<span>${ML.t('Confirmation par téléphone')}</span></li>
            </ul>
          </div>
        </section>

        ${L.pitch || p.about ? html`<section class="of-sec of-pitch"><p>${L.pitch || p.about}</p></section>` : ''}
        ${p.specs.length ? html`<section class="of-sec"><h2>${ML.t('Caractéristiques')}</h2><dl class="specs">${p.specs.map((s) => html`<div><dt>${s.k}</dt><dd>${s.v}</dd></div>`)}</dl></section>` : ''}

        <section class="of-sec of-order" id="commander">
          <h2>${ML.t('Votre commande')}</h2>
          <p class="muted">${ML.t('Remplissez le formulaire : vous payez à la réception, aucun compte à créer.')}</p>
          <div class="card card-pad" data-order>${ML.codForm(p, { size: sel.size, color: sel.color, email: false, submit: ML.t('Confirmer la commande') })}</div>
        </section>

        <section class="of-sec of-how">
          <h2>${ML.t('Comment ça se passe ?')}</h2>
          <ol>
            <li><b>1</b><span>${ML.t('Vous commandez ici en indiquant votre wilaya et votre commune.')}</span></li>
            <li><b>2</b><span>${ML.t('Le point de vente le plus proche de chez vous qui a l\'article vous appelle pour confirmer.')}</span></li>
            <li><b>3</b><span>${ML.t('Il vous livre ; vous payez à la réception.')}</span></li>
          </ol>
        </section>
      </article>
      <div class="of-bar"><span><small>${p.name}</small><b data-price></b></span><a class="btn btn-primary" href="#commander">${ML.t('Commander')}</a></div>
      <footer class="of-foot"><a href="../explorer/">${ML.t('Propulsé par')} ${ML.logo()}</a></footer>`;

    const form = $('[data-order] form');
    const priceNow = () => {
      const vs = p.variants.filter((v) => (!sel.size || v.size === sel.size) && (!sel.color || v.color === sel.color));
      const list = (vs.length ? vs : p.variants).map((v) => v.price);
      const lo = Math.min(...list); const hi = Math.max(...list);
      return (hi > lo ? ML.t('dès ') : '') + fmt.price(lo);
    };
    const tint = () => { const c = colors.find((x) => x.name === sel.color); return c ? c.hex : ''; };
    const paint = () => {
      $('[data-visual]').innerHTML = ML.visual(p, { image: p.images[shot] || '', tint: tint(), w: 1200, cls: 'pv-big' });
      $$('[data-price]').forEach((el) => { el.textContent = priceNow(); });
      const has = (size, col) => p.variants.some((v) => (!size || v.size === size) && (!col || v.color === col));
      if (colors.length > 1) {
        $('[data-color-name]').textContent = sel.color ? '· ' + sel.color : '';
        $('[data-colors]').innerHTML = html`${colors.map((c) => html`<button type="button" class="copt ${sel.color === c.name ? 'is-on' : ''} ${has(sel.size, c.name) ? '' : 'is-off'}" data-act="of-color" data-v="${c.name}" title="${c.name}" aria-label="${c.name}" aria-pressed="${sel.color === c.name}"><i style="--c:${color(c.hex)}"></i></button>`)}`;
      }
      if (sizes.length > 1) {
        $('[data-sizes]').innerHTML = html`${sizes.map((s) => html`<button type="button" class="chip ${sel.size === s ? 'is-on' : ''} ${has(s, sel.color) ? '' : 'is-off'}" data-act="of-size" data-v="${s}" aria-pressed="${sel.size === s}">${s}</button>`)}`;
      }
      if (form.selectVariant) form.selectVariant(sel.size, sel.color);
    };
    ML.actions['of-color'] = (el) => { sel.color = sel.color === el.dataset.v ? '' : el.dataset.v; paint(); };
    ML.actions['of-size'] = (el) => { sel.size = sel.size === el.dataset.v ? '' : el.dataset.v; paint(); };
    ML.actions['of-shot'] = (el) => { shot = +el.dataset.i; $$('.p-thumbs button').forEach((b, i) => b.classList.toggle('is-on', i === shot)); paint(); };
    ML.codBind(form, p, {
      onDone: (res) => {
        $('[data-order]').innerHTML = ML.codDone(res, '../explorer/#/suivi/');
        $('.of-bar').hidden = true;
        $('#commander').scrollIntoView({ behavior: 'smooth', block: 'start' });
      },
    });
    paint();
    // La barre du bas disparaît quand le formulaire est à l'écran.
    if ('IntersectionObserver' in window) {
      const bar = $('.of-bar');
      new IntersectionObserver((en) => en.forEach((e) => bar.classList.toggle('is-hidden', e.isIntersecting))).observe($('#commander'));
    }
    ML.api('event', { body: { kind: 'product', id: p.id } }).catch(() => {});
  }

  (async function init() {
    try {
      await ML.bootstrap();
    } catch (e) {
      fail(ML.t('La page est momentanément indisponible'), e.message);
      return;
    }
    if (!slug) { fail(ML.t('Produit introuvable')); return; }
    try {
      render((await ML.api('product', { params: { slug } })).product);
    } catch (e) {
      fail(e.status === 404 ? ML.t('Ce produit n\'est plus disponible') : ML.t('Chargement impossible'), e.status === 404 ? '' : e.message);
    }
  })();
})();
