/*
 * Maillage — commande livrée, paiement à la réception, sans choisir de point de vente.
 * Partagé par la fiche produit (explorer) et la page de vente d'un produit (offre/) :
 * la commande part au « radar », qui la propose aux points de vente les plus proches du client
 * (sa commune, puis sa wilaya, puis tous ceux qui ont l'article).
 */
(function () {
  'use strict';
  const { html, icon, fmt } = ML;

  const vlabel = (v) => [v.size, v.color].filter(Boolean).join(' · ') || ML.t('Modèle unique');

  /**
   * Formulaire. o : size, color (déclinaison présélectionnée), email (afficher le champ email),
   * submit (texte du bouton).
   */
  ML.codForm = (p, o) => {
    o = o || {};
    const me = ML.ls.get('me', {});
    const vs = p.variants || [];
    const pre = vs.find((v) => (!o.size || v.size === o.size) && (!o.color || v.color === o.color)) || vs[0] || {};
    const zone = me.zone || (ML.loc && ML.loc.zone) || '';
    return html`
      <form class="order-form cod-form" novalidate>
        <div class="form-grid cols-2">
          ${vs.length > 1 ? html`<div class="field"><label for="c-variant">${ML.t('Déclinaison')}</label><select class="select" id="c-variant" name="variant">${vs.map((v) => html`<option value="${v.id}" ${v.id === pre.id ? ML.raw('selected') : ''}>${vlabel(v)}${v.price !== p.price ? ' — ' + fmt.price(v.price) : ''}</option>`)}</select></div>`
            : html`<input type="hidden" name="variant" value="${pre.id || ''}">`}
          <div class="field">
            <span class="label" id="c-qty-label">${ML.t('Quantité')}</span>
            <div class="stepper" role="group" aria-labelledby="c-qty-label">
              <button type="button" data-step="-1" aria-label="${ML.t('Moins')}">${icon('minus')}</button>
              <output name="qtyout" aria-live="polite">1</output>
              <button type="button" data-step="1" aria-label="${ML.t('Plus')}">${icon('plus')}</button>
            </div>
          </div>
        </div>
        <div class="form-grid cols-2">
          <div class="field"><label for="c-name">${ML.t('Nom et prénom')}</label><input class="input" id="c-name" name="name" autocomplete="name" required maxlength="80" value="${me.name || ''}"></div>
          <div class="field"><label for="c-phone">${ML.t('Téléphone')}</label><input class="input" id="c-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="05 00 00 00 00" value="${me.phone || ''}"></div>
          ${ML.placeFields({ prefix: 'c', zone, required: true, addressLabel: ML.t('Adresse de livraison'), addressValue: me.zone === zone ? (me.address || '') : '' })}
          ${o.email ? html`<div class="field span-2"><label for="c-email">${ML.t('Email')} <span class="dim">(${ML.t('facultatif, pour recevoir le suivi')})</span></label><input class="input" id="c-email" name="email" type="email" autocomplete="email" value="${me.email || ''}"></div>` : ''}
          <div class="field span-2"><label for="c-note">${ML.t('Message')} <span class="dim">(${ML.t('facultatif')})</span></label><textarea class="textarea" id="c-note" name="note" rows="2" maxlength="400"></textarea></div>
        </div>
        <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div class="order-total"><span>${ML.t('Total à payer à la livraison')}</span><b data-total></b></div>
        <p class="form-error" data-error hidden></p>
        <button class="btn btn-primary btn-lg btn-block" type="submit" data-submit>${icon('truck')}${o.submit || ML.t('Commander · paiement à la livraison')}</button>
        <p class="order-legal">${ML.t('Le point de vente le plus proche de chez vous vous appelle pour confirmer, puis vous livre. Vous payez à la réception.')}</p>
      </form>`;
  };

  /**
   * Branche le formulaire : quantité, total, communes, envoi. o.onDone(res) reçoit la réponse
   * ({ref, token, order}) ; la commande est gardée dans « Mes demandes » de l'appareil.
   */
  ML.codBind = (form, p, o) => {
    o = o || {};
    const me = ML.ls.get('me', {});
    let qty = 1;
    const vs = p.variants || [];
    const variant = () => vs.find((v) => v.id === +form.variant.value) || vs[0];
    const paint = () => {
      const v = variant();
      qty = Math.max(1, Math.min(qty, 10));
      form.qtyout.value = qty;
      form.querySelector('[data-total]').textContent = fmt.price((v ? v.price : p.price) * qty);
      form.querySelector('[data-step="-1"]').disabled = qty <= 1;
      form.querySelector('[data-step="1"]').disabled = qty >= 10;
    };
    form.addEventListener('click', (e) => { const b = e.target.closest('[data-step]'); if (b) { qty += +b.dataset.step; paint(); } });
    form.addEventListener('change', (e) => { if (e.target.name === 'variant') paint(); });
    ML.bindPlace(form, me.commune || '');
    paint();
    // Le formulaire doit refléter la déclinaison choisie ailleurs dans la page (pastilles de couleur, tailles).
    form.selectVariant = (size, color) => {
      const v = vs.find((x) => (!size || x.size === size) && (!color || x.color === color));
      if (v && form.variant.tagName === 'SELECT') { form.variant.value = v.id; paint(); }
    };
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const err = form.querySelector('[data-error]');
      err.hidden = true;
      const d = ML.formData(form);
      if (!d.zone || !d.commune) { ML.fail({ message: ML.t('Choisissez la wilaya puis la commune de livraison.') }, form); return; }
      const btn = form.querySelector('[data-submit]');
      ML.busy(btn, true);
      try {
        const res = await ML.api('order_radar', { body: {
          variant_id: +d.variant, qty, name: d.name, phone: d.phone, email: d.email || '', zone: d.zone, commune: d.commune, address: d.address, note: d.note, website: d.website,
        } });
        ML.ls.set('me', Object.assign({}, ML.ls.get('me', {}), { name: d.name, phone: d.phone, email: d.email || me.email || '', zone: d.zone, commune: d.commune, address: d.address }));
        const mine = ML.ls.get('orders', []);
        mine.unshift({ ref: res.ref, token: res.token, name: p.name, at: Math.round(Date.now() / 1000) });
        ML.ls.set('orders', mine.slice(0, 20));
        if (o.onDone) o.onDone(res);
      } catch (ex) {
        ML.fail(ex, form);
      }
      ML.busy(btn, false);
    });
  };

  /** Écran « commande enregistrée ». track : adresse de la page de suivi (sans le jeton). */
  ML.codDone = (res, track) => html`
    <div class="order-done">
      <div class="done-mark">${icon('check')}</div>
      <p class="done-ref">${ML.t('N°')} <b>${res.ref}</b></p>
      <p>${ML.t('Votre commande est transmise au point de vente le plus proche de chez vous qui a l\'article. Il vous appelle pour confirmer la livraison. Vous payez à la réception.')}</p>
      <div class="row row-wrap" style="justify-content:center">
        <a class="btn btn-primary" href="${(track || '#/suivi/') + res.token}" data-close>${icon('package-check')}${ML.t('Suivre ma commande')}</a>
      </div>
    </div>`;
})();
