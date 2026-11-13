/*
 * Maillage — recherche d'un produit par photo (plateforme publique) :
 *   #/photo                 photo ou image → description proposée (modifiable) → produit trouvé,
 *                           produits similaires, ou recherche active (Radar)
 *   #/recherche/<jeton>     suivi d'une recherche active et réponses des points de vente
 *
 * Tout se fait dans la plateforme, sans service extérieur : la photo est comparée à celles du
 * catalogue (serveur) et reconnue par le modèle d'image exécuté dans le navigateur (vision.js).
 * La description proposée n'est jamais présentée comme vérifiée (le client la corrige), et un
 * produit n'est annoncé « trouvé » qu'avec la raison affichée (photo identique, nom identique).
 * Le reste est « similaire ».
 */
(function () {
  'use strict';
  const { html, icon, fmt, color } = ML;
  const X = ML.X;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const EMPTY = () => ({ category: '', name: '', brand: '', details: '', terms: [], confidence: '' });
  // L'image choisie et l'avancement vivent le temps de la visite (une photo ne se garde pas d'une visite à l'autre).
  const V = { blob: null, url: '', info: EMPTY(), auto: false, src: '', alts: [], proposed: '', vision: '', res: null, phase: 'start', editing: true };

  const placeText = (o) => ML.placeLabel(o);
  const myResearches = () => ML.ls.get('researches', []);

  /* ------------------------------------------------------ préparation de l'image */
  /** Réduit la photo (1280 px au plus, JPEG) avant l'envoi : rapide même sur un réseau lent. */
  async function prepare(file) {
    if (!file) throw new Error(ML.t('Choisissez une image.'));
    if (file.type && !/^image\//.test(file.type)) throw new Error(ML.t('Ce fichier n\'est pas une image. Choisissez une photo (JPG, PNG ou WebP).'));
    let src;
    try {
      src = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (e) {
      src = await new Promise((resolve, reject) => {
        const im = new Image();
        im.onload = () => resolve(im);
        im.onerror = () => reject(new Error(ML.t('Cette image n\'a pas pu être lue. Essayez-en une autre.')));
        im.src = URL.createObjectURL(file);
      });
    }
    const w = src.width || src.naturalWidth;
    const h = src.height || src.naturalHeight;
    if (Math.min(w, h) < 64) throw new Error(ML.t('Cette image est trop petite. Choisissez une photo plus grande.'));
    const k = Math.min(1, 1280 / Math.max(w, h));
    const cv = document.createElement('canvas');
    cv.width = Math.max(1, Math.round(w * k));
    cv.height = Math.max(1, Math.round(h * k));
    const ctx = cv.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, cv.width, cv.height);
    ctx.drawImage(src, 0, 0, cv.width, cv.height);
    const blob = await new Promise((resolve) => cv.toBlob(resolve, 'image/jpeg', 0.86));
    if (!blob) throw new Error(ML.t('Cette image n\'a pas pu être lue. Essayez-en une autre.'));
    return blob;
  }

  /** Appareil photo de l'ordinateur (webcam). Sur téléphone, le champ « capture » ouvre l'appareil photo du système. */
  function webcam() {
    return new Promise((resolve) => {
      let stream = null;
      let shot = null;
      const m = ML.modal({
        title: ML.t('Prendre une photo'),
        body: html`<div class="vs-cam"><video autoplay playsinline muted></video><p class="muted" data-cam-msg>${ML.t('Autorisez l\'accès à la caméra, puis cadrez le produit.')}</p></div>`,
        foot: html`<button class="btn btn-line" type="button" data-close>${ML.t('Annuler')}</button><button class="btn btn-primary" type="button" data-snap disabled>${icon('camera')}${ML.t('Prendre la photo')}</button>`,
        onClose: () => { if (stream) stream.getTracks().forEach((t) => t.stop()); resolve(shot); },
      });
      const video = $('video', m.el);
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then((s) => {
        stream = s;
        if (!document.body.contains(m.el)) { s.getTracks().forEach((t) => t.stop()); return; }
        video.srcObject = s;
        $('[data-snap]', m.el).disabled = false;
        $('[data-cam-msg]', m.el).textContent = ML.t('Cadrez le produit, bien éclairé, puis prenez la photo.');
      }).catch(() => { shot = false; m.close(); });
      $('[data-snap]', m.el).addEventListener('click', () => {
        const cv = document.createElement('canvas');
        cv.width = video.videoWidth || 1280;
        cv.height = video.videoHeight || 720;
        cv.getContext('2d').drawImage(video, 0, 0, cv.width, cv.height);
        cv.toBlob((b) => { shot = b; m.close(); }, 'image/jpeg', 0.9);
      });
    });
  }

  /* ------------------------------------------------------------------ étapes */
  function reset() {
    if (V.url) URL.revokeObjectURL(V.url);
    Object.assign(V, { blob: null, url: '', info: EMPTY(), auto: false, src: '', alts: [], proposed: '', vision: '', res: null, phase: 'start', editing: true });
  }

  async function take(file) {
    const err = $('[data-vs-error]');
    if (err) err.hidden = true;
    let blob;
    try { blob = await prepare(file); } catch (e) {
      if (err) { err.textContent = e.message; err.hidden = false; } else ML.toast(e.message, 'bad');
      return;
    }
    reset();
    V.blob = blob;
    V.url = URL.createObjectURL(blob);
    V.phase = 'analyzing';
    paint();
    const fd = new FormData();
    fd.append('file', blob, 'photo.jpg');
    const vision = ML.Vision && ML.Vision.supported()
      ? ML.Vision.analyze(blob, progress).catch((e) => { console.warn('vision', e); return null; })
      : Promise.resolve(null);
    let cat = null;
    try {
      cat = await X.api('visual_identify', { body: fd });
    } catch (e) {
      if (e instanceof X.Stale) return;
      // Image refusée par le serveur (format, taille) : retour au choix de l'image, avec le motif.
      if (e.status === 400) { reset(); paint(); const box = $('[data-vs-error]'); if (box) { box.textContent = e.message; box.hidden = false; } return; }
    }
    const seen = await vision;
    if (V.blob !== blob || V.phase !== 'analyzing') return; // autre image choisie, ou le client n'a pas attendu
    propose(cat, seen);
    V.phase = 'edit';
    V.editing = true;
    paint();
  }

  /** Avancement du premier téléchargement du modèle d'image. */
  function progress(x) {
    const el = $('[data-vs-progress]');
    if (!el) return;
    el.hidden = false;
    el.querySelector('i').style.width = Math.round(x * 100) + '%';
    el.querySelector('span').textContent = ML.t('Préparation de la reconnaissance d\'image (une seule fois) :') + ' ' + Math.round(x * 100) + ' %';
  }

  /**
   * Choisit la proposition : une fiche du catalogue dont la photo est identique ou très
   * ressemblante, sinon ce que reconnaît le modèle d'image, sinon rien (le client décrit le produit).
   */
  function propose(cat, seen) {
    const ar = ML.lang === 'ar';
    V.vision = seen ? (seen.other && !seen.brand ? 'none' : 'ok') : (ML.Vision && ML.Vision.supported() ? 'fail' : 'off');
    if (cat && cat.auto && cat.info.confidence !== 'low') {
      Object.assign(V, { auto: true, src: 'catalog', info: cat.info, alts: [], proposed: cat.info.name });
      return;
    }
    const info = EMPTY();
    let alts = [];
    if (seen && V.vision === 'ok') {
      const top = seen.types[0];
      const nm = (t) => (ar ? t.ar : t.fr);
      if (seen.brand && seen.brand.fr) {
        info.name = ar ? seen.brand.ar : seen.brand.fr;
        info.category = seen.brand.cat;
        info.confidence = 'medium';
      } else if (top && top.p >= 0.35) {
        info.name = nm(top);
        info.category = top.cat;
        info.confidence = top.p >= 0.7 ? 'high' : 'medium';
      } else {
        // Pas de type net : le rayon, si les types probables s'y accordent.
        const by = {};
        seen.types.slice(0, 8).forEach((t) => { by[t.cat] = (by[t.cat] || 0) + t.p; });
        const best = Object.entries(by).sort((a, b) => b[1] - a[1])[0];
        if (best && best[1] >= 0.5) { info.category = best[0]; info.confidence = 'low'; }
      }
      if (seen.brand) {
        info.brand = seen.brand.name;
        if (!info.category) info.category = seen.brand.cat;
      }
      if (seen.color && info.name) info.details = (ar ? 'اللون: ' : 'Couleur : ') + (ar ? seen.color.ar : seen.color.fr);
      // Les mots de recherche valent dans les deux langues (le catalogue peut être en français ou en arabe).
      const used = seen.types.find((t) => nm(t) === info.name) || (seen.brand && seen.brand.fr ? { fr: seen.brand.fr, ar: seen.brand.ar } : null);
      info.terms = used ? [used.fr, used.ar] : [];
      alts = seen.types.filter((t) => t.p >= 0.05 && nm(t) !== info.name).slice(0, 4).map((t) => ({ name: nm(t), cat: t.cat, fr: t.fr, ar: t.ar }));
    }
    if (info.name || info.category || info.brand) {
      Object.assign(V, { auto: true, src: 'vision', info, alts, proposed: info.name });
    } else if (cat && cat.auto) {
      Object.assign(V, { auto: true, src: 'catalog', info: cat.info, alts: [], proposed: cat.info.name });
    } else {
      Object.assign(V, { auto: false, src: '', info, alts: [], proposed: '' });
    }
  }

  async function search() {
    const f = $('[data-form="vs-info"]');
    if (f) {
      const d = ML.formData(f);
      // Les mots de recherche proposés ne valent que pour le nom proposé : un nom corrigé les annule.
      const same = V.proposed && ML.norm(d.name) === ML.norm(V.proposed);
      V.info = { category: d.category, name: d.name.trim(), brand: d.brand.trim(), details: d.details.trim(), terms: same ? V.info.terms || [] : [], confidence: V.info.confidence };
    }
    V.phase = 'searching';
    V.editing = false;
    paint();
    const fd = new FormData();
    fd.append('file', V.blob, 'photo.jpg');
    ['category', 'name', 'brand', 'details'].forEach((k) => fd.append(k, V.info[k] || ''));
    fd.append('terms', JSON.stringify(V.info.terms || []));
    if (ML.loc) { fd.append('lat', ML.loc.lat); fd.append('lng', ML.loc.lng); fd.append('zone', ML.loc.zone || ''); }
    try {
      V.res = await X.api('visual_search', { body: fd });
      V.phase = 'results';
    } catch (e) {
      if (e instanceof X.Stale) return;
      V.phase = 'edit';
      V.editing = true;
      paint();
      ML.fail(e, $('[data-form="vs-info"]'));
      return;
    }
    paint();
    const res = $('[data-results]');
    if (res && window.matchMedia('(max-width: 899px)').matches) res.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ----------------------------------------------------------------- affichage */
  const startHtml = () => html`
    <div class="page vs">
      <div class="page-head"><h1>${ML.t('Trouver un produit avec une photo')}</h1><p class="muted">${ML.t('Photographiez le produit ou choisissez une image : nous cherchons où il est disponible et vous proposons les produits les plus proches.')}</p></div>
      <div class="vs-drop card" data-drop>
        <span class="vs-drop-ic">${icon('scan-search')}</span>
        <div class="vs-drop-btns">
          <button class="btn btn-primary btn-lg" type="button" data-act="vs-camera">${icon('camera')}${ML.t('Prendre une photo')}</button>
          <button class="btn btn-line btn-lg" type="button" data-act="vs-pick">${icon('image')}${ML.t('Choisir une image')}</button>
        </div>
        <p class="hint muted vs-drop-hint">${ML.t('JPG, PNG ou WebP. Sur ordinateur, vous pouvez aussi glisser une image ici ou la coller.')}</p>
        <p class="form-error" data-vs-error hidden></p>
        <input type="file" accept="image/*" capture="environment" hidden data-vs-file="camera" aria-label="${ML.t('Prendre une photo')}">
        <input type="file" accept="image/jpeg,image/png,image/webp,image/*" hidden data-vs-file="pick" aria-label="${ML.t('Choisir une image')}">
      </div>
      <ol class="vs-how">
        <li><b>1</b><span><strong>${ML.t('Votre photo')}</strong>${ML.t('Le produit bien visible, de préférence seul et bien éclairé.')}</span></li>
        <li><b>2</b><span><strong>${ML.t('La description')}</strong>${ML.t('La plateforme reconnaît le produit et propose son rayon, son nom et sa couleur : vous les corrigez si besoin.')}</span></li>
        <li><b>3</b><span><strong>${ML.t('Le résultat')}</strong>${ML.t('Le produit et ses points de vente, des produits similaires, ou une recherche active dans le réseau.')}</span></li>
      </ol>
      ${myResearches().length ? html`<p class="vs-mine"><a class="btn btn-soft" href="#/suivi">${icon('target')}${ML.t('Suivre mes recherches actives')}</a></p>` : ''}
      <p class="vs-privacy muted">${icon('lock', 'ico-sm')}<span>${ML.t('Votre image sert à cette recherche et n\'est pas conservée, sauf si vous lancez une recherche active.')}${' ' + ML.t('Elle est analysée sur la plateforme et dans votre navigateur, sans service extérieur.')}</span></p>
    </div>`;

  /** Bandeau au-dessus de la description : d'où elle vient et ce qu'elle vaut. */
  function infoNotice() {
    const check = ML.t('Corrigez ce qui ne correspond pas : la recherche utilise votre photo et ces informations.');
    if (V.src === 'catalog' && V.info.confidence === 'low') return html`<p class="notice">${icon('scan-search')}<span><b>${ML.t('Rayon proposé d\'après des produits ressemblants du catalogue.')}</b> ${ML.t('Vérifiez-le, puis indiquez le nom et les détails du produit.')}</span></p>`;
    if (V.src === 'catalog') return html`<p class="notice">${icon('scan-search')}<span><b>${ML.t('Description proposée d\'après une photo ressemblante du catalogue, à vérifier.')}</b> ${check}</span></p>`;
    if (V.src === 'vision' && !V.info.name) return html`<p class="notice">${icon('scan-search')}<span><b>${ML.t('Rayon proposé d\'après l\'image.')}</b> ${ML.t('Vérifiez-le, puis indiquez le nom et les détails du produit.')}</span></p>`;
    if (V.src === 'vision' && V.info.confidence !== 'high') return html`<p class="notice is-warn">${icon('triangle-alert')}<span><b>${ML.t('Reconnaissance incertaine, à vérifier.')}</b> ${check}</span></p>`;
    if (V.src === 'vision') return html`<p class="notice">${icon('scan-search')}<span><b>${ML.t('Description proposée par la reconnaissance d\'image, à vérifier.')}</b> ${check}</span></p>`;
    if (V.vision === 'none') return html`<p class="notice is-warn">${icon('triangle-alert')}<span><b>${ML.t('Nous n\'avons pas reconnu de produit sur cette image.')}</b> ${ML.t('Décrivez-le ci-dessous, ou essayez une autre photo.')}</span></p>`;
    if (V.vision === 'fail') return html`<p class="notice is-warn">${icon('triangle-alert')}<span><b>${ML.t('La reconnaissance d\'image n\'a pas pu se lancer sur cet appareil.')}</b> ${ML.t('Décrivez le produit : la recherche utilise votre description et la ressemblance de votre photo avec celles du catalogue.')}</span></p>`;
    return html`<p class="notice">${icon('pencil')}<span>${ML.t('Décrivez le produit : la recherche utilise votre description et la ressemblance de votre photo avec celles du catalogue.')}</span></p>`;
  }

  /** Autres possibilités reconnues : un geste pour remplacer le nom et le rayon proposés. */
  const altsHtml = () => (V.alts.length ? html`
    <div class="vs-alts"><span class="muted">${V.info.name ? ML.t('Ce n\'est pas ça ? Peut-être :') : ML.t('Est-ce l\'un de ces produits ?')}</span>${V.alts.map((a, i) => html`<button class="chip" type="button" data-act="vs-alt" data-i="${i}">${a.name}</button>`)}</div>` : '');

  const infoForm = () => html`
    <form class="stack" data-form="vs-info" novalidate>
      <div class="row between row-wrap"><h2 class="card-title">${ML.t('Informations du produit')}</h2>${V.auto ? html`<span class="badge badge-info">${icon('scan-search')}${V.src === 'catalog' ? ML.t('Proposition du catalogue') : ML.t('Proposition de la reconnaissance d\'image')}</span>` : ''}</div>
      ${infoNotice()}
      <div class="form-grid cols-2">
        <div class="field"><label for="v-cat">${ML.t('Rayon')}</label><select class="select" id="v-cat" name="category"><option value="">${ML.t('Je ne sais pas')}</option>${ML.boot.categories.map((c) => html`<option value="${c.key}" ${V.info.category === c.key ? ML.raw('selected') : ''}>${c.label}</option>`)}</select></div>
        <div class="field"><label for="v-brand">${ML.t('Marque')} <span class="dim">(${ML.t('si vous la connaissez')})</span></label><input class="input" id="v-brand" name="brand" maxlength="60" autocomplete="off" value="${V.info.brand}"></div>
        <div class="field span-2"><label for="v-name">${ML.t('Nom du produit')}</label><input class="input" id="v-name" name="name" maxlength="120" autocomplete="off" placeholder="${ML.t('Ex. chaussure de running bleue')}" value="${V.info.name}">${altsHtml()}</div>
        <div class="field span-2"><label for="v-details">${ML.t('Détails')} <span class="dim">(${ML.t('couleur, matière, modèle, taille…')})</span></label><textarea class="textarea" id="v-details" name="details" rows="3" maxlength="600">${V.info.details}</textarea></div>
      </div>
      <p class="form-error" data-error hidden></p>
      <div class="row row-wrap">
        <button class="btn btn-primary btn-lg" type="submit">${icon('search')}${ML.t('Chercher le produit')}</button>
        <button class="btn btn-ghost" type="button" data-act="vs-new">${ML.t('Essayer une autre image')}</button>
      </div>
    </form>`;

  const infoSummary = () => html`
    <div class="vs-summary">
      <div class="grow">
        <span class="label">${ML.t('Vous cherchez')}</span>
        <p><b>${V.info.name || ML.t('Produit de la photo')}</b>${V.info.brand ? html` · ${V.info.brand}` : ''}${V.info.category ? html` <span class="muted">· ${ML.cats[V.info.category] || ''}</span>` : ''}</p>
        ${V.info.details ? html`<p class="muted vs-summary-details">${V.info.details}</p>` : ''}
      </div>
      <button class="btn btn-line btn-sm" type="button" data-act="vs-edit">${icon('pencil', 'ico-sm')}${ML.t('Modifier les informations')}</button>
    </div>`;

  const EVIDENCE = () => ({
    photo: ML.t('Photo identique à celle du catalogue'),
    name: ML.t('Nom identique à celui du catalogue'),
  });
  const WHY = () => ({ photo: ML.t('Photo ressemblante'), name: ML.t('Nom proche'), brand: ML.t('Même marque'), category: ML.t('Même rayon') });

  function stockLine(st) {
    const vs = st.variants || [];
    const low = vs.length === 1 && vs[0].low;
    return html`<span class="stock ${low ? 'is-low' : 'is-ok'}">${icon(low ? 'triangle-alert' : 'circle-check', 'ico-sm')}${low ? (vs[0].left === 1 ? ML.t('Dernière pièce') : ML.t('Plus que') + ' ' + vs[0].left) : ML.t('En stock')}</span>`;
  }
  const nearList = (p) => (p.near.length ? html`
    <ul class="vs-near" data-near>${p.near.map((st) => html`
      <li>
        <div class="grow"><b>${st.name}</b><small class="muted">${st.address || st.city}</small><span class="scard-tags">${X.openBadge(st)}${stockLine(st)}</span></div>
        <div class="vs-near-end">${st.km != null ? html`<span class="km">${fmt.km(st.km)}</span>` : ''}<span class="row">${X.contactButtons(st, true)}</span></div>
      </li>`)}</ul>
    ${p.near_total > p.near.length ? html`<p class="muted vs-near-more">${ML.t('et')} ${fmt.plural(p.near_total - p.near.length, 'autre point de vente', 'autres points de vente')}</p>` : ''}`
    : html`<p class="notice is-warn" data-near>${icon('triangle-alert')}<span>${ML.t('Aucun point de vente ne l\'a en stock pour le moment. Vous pouvez le commander depuis sa fiche ou lancer une recherche active.')}</span></p>`);

  function exactHtml(p, evidence) {
    const ev = EVIDENCE();
    return html`
      <section class="vs-exact card" data-exact>
        <div class="vs-exact-head">
          <span class="badge badge-good vs-badge">${icon('circle-check')}${ML.t('Produit trouvé')}</span>
          ${evidence.map((e) => html`<span class="badge badge-line">${ev[e] || e}</span>`)}
        </div>
        <div class="vs-exact-body">
          ${ML.visual(p, { cls: 'vs-pv' })}
          <div class="vs-exact-info">
            <a class="p-brand" href="#/marque/${p.brand.slug}">${ML.mono(p.brand.name, p.brand.color, p.brand.logo, 'mono-sm')}<span>${p.brand.name}</span>${p.demo ? html`<span class="badge">${ML.t('Démo')}</span>` : ''}</a>
            <h2>${p.name}</h2>
            <p class="vs-price"><b class="price">${p.price_max > p.price ? ML.t('dès ') : ''}${fmt.price(p.price)}</b></p>
            ${p.about ? html`<p class="muted vs-about">${p.about}</p>` : ''}
            <p class="vs-src">${icon('shield-check', 'ico-sm')}${ML.t('Informations du catalogue de la marque')}</p>
          </div>
        </div>
        <div class="vs-block">
          <div class="row between row-wrap"><h3>${ML.t('Où le trouver')}</h3><span class="muted">${p.near_total ? fmt.plural(p.near_total, 'point de vente', 'points de vente') + (ML.loc ? ' · ' + ML.t('du plus proche au plus loin') : '') : ''}</span></div>
          ${nearList(p)}
        </div>
        <div class="vs-block vs-maker">
          ${ML.mono(p.brand.name, p.brand.color, p.brand.logo)}
          <div class="grow"><span class="label">${ML.t('Marque / fabricant')}</span><p><b>${p.brand.name}</b>${p.brand.company && p.brand.company !== p.brand.name ? html` <span class="muted">· ${p.brand.company}</span>` : ''}</p>${p.brand.tagline ? html`<p class="muted">${p.brand.tagline}</p>` : ''}</div>
        </div>
        <div class="vs-actions">
          <a class="btn btn-primary" href="#/produit/${p.slug}">${icon('eye')}${ML.t('Voir la fiche produit')}</a>
          <button class="btn btn-line" type="button" data-act="vs-near">${icon('map-pin')}${ML.t('Points de vente proches')}</button>
          <a class="btn btn-line" href="#/marque/${p.brand.slug}">${icon('factory')}${ML.t('Voir la marque')}</a>
          <button class="btn btn-line" type="button" data-act="vs-similar">${icon('package-search')}${ML.t('Produits similaires')}</button>
        </div>
      </section>`;
  }

  function similarHtml(list, hasExact) {
    const why = WHY();
    // Une seule raison par carte, la plus parlante : photo, puis nom, marque, rayon.
    const tag = (p) => (why[['photo', 'name', 'brand', 'category'].find((w) => (p.why || []).includes(w))] || '');
    return html`
      <section class="vs-similar" data-similar>
        <div class="section-head"><div><h2>${hasExact ? ML.t('Produits similaires') : ML.t('Produits les plus proches')}</h2><p class="muted">${hasExact ? ML.t('D\'autres produits du même genre sur la plateforme.') : ML.t('Ce ne sont pas forcément le produit de votre photo : comparez avant de commander.')}</p></div></div>
        <div class="pgrid">${list.map((p) => html`<div class="vs-sim">${X.productCard(p)}${tag(p) ? html`<span class="vs-tag">${tag(p)}</span>` : ''}</div>`)}</div>
      </section>`;
  }

  const radarCard = (strong) => html`
    <section class="vs-radar card ${strong ? 'is-strong' : ''}">
      <span class="vs-radar-ic">${icon('target')}</span>
      <div class="grow">
        <h3>${strong ? ML.t('Lancez une recherche active') : ML.t('Ce n\'est pas le bon produit ?')}</h3>
        <p class="muted">${ML.t('Le Radar envoie votre photo et votre description aux points de vente proches de chez vous, puis à tout le réseau et aux marques concernées. Vous suivez les réponses avec un numéro.')}</p>
        <div class="row row-wrap">
          <button class="btn ${strong ? 'btn-primary' : 'btn-dark'}" type="button" data-act="vs-research">${icon('target')}${ML.t('Lancer une recherche active (Radar)')}</button>
          ${myResearches().length ? html`<a class="btn btn-ghost" href="#/suivi">${ML.t('Suivre mes recherches')}</a>` : ''}
        </div>
      </div>
    </section>`;

  function resultsHtml() {
    if (V.phase === 'searching') return html`<div class="card vs-wait"><div class="spinner"></div><p>${ML.t('Recherche sur la plateforme…')}</p></div>`;
    if (V.phase !== 'results' || !V.res) return '';
    const r = V.res;
    const cat = V.info.category;
    return html`
      ${r.exact ? exactHtml(r.exact, r.evidence) : r.similar.length
        ? html`<p class="notice is-warn" data-notfound>${icon('search-x')}<span><b>${ML.t('Nous n\'avons pas trouvé ce produit exact sur la plateforme.')}</b> ${ML.t('Voici les produits qui s\'en rapprochent le plus.')}</span></p>`
        : html`<div class="card" data-nothing><div class="empty">${icon('search-x')}<h3>${ML.t('Aucun produit correspondant sur la plateforme')}</h3><p>${ML.t('Ni ce produit ni un produit proche n\'est enregistré pour le moment. Lancez une recherche active : les marques et les points de vente du réseau vous répondent.')}</p>${cat ? html`<a class="btn btn-line btn-sm" href="${ML.href('/produits', { cat })}">${ML.t('Parcourir le rayon')} ${ML.cats[cat] || ''}</a>` : ''}</div></div>`}
      ${r.similar.length ? similarHtml(r.similar, !!r.exact) : ''}
      ${radarCard(!r.exact)}
      <p class="vs-again"><button class="btn btn-ghost" type="button" data-act="vs-new">${icon('refresh-cw')}${ML.t('Nouvelle recherche')}</button></p>`;
  }

  function paint() {
    if (V.phase === 'start' || !V.blob) { X.view().innerHTML = startHtml(); bindDrop(); return; }
    X.view().innerHTML = html`
      <div class="page vs">
        <div class="vs-work">
          <aside class="vs-photo card">
            <img src="${V.url}" alt="${ML.t('Votre photo')}">
            <button class="btn btn-line btn-sm btn-block" type="button" data-act="vs-new">${icon('image')}${ML.t('Autre image')}</button>
          </aside>
          <div class="vs-main">
            <section class="card card-pad" data-info>${V.phase === 'analyzing'
              ? html`<div class="vs-wait"><div class="spinner"></div><p>${ML.t('Reconnaissance du produit…')}</p><p class="vs-progress" data-vs-progress hidden><span></span><b><i></i></b><button class="btn btn-ghost btn-sm" type="button" data-act="vs-skip">${ML.t('Ne pas attendre : décrire le produit moi-même')}</button></p></div>`
              : V.editing ? infoForm() : infoSummary()}</section>
            <div data-results>${resultsHtml()}</div>
          </div>
        </div>
      </div>`;
  }

  /* ---------------------------------------------------------------- actions */
  function bindDrop() {
    const zone = $('[data-drop]');
    if (!zone) return;
    $$('[data-vs-file]', zone).forEach((inp) => inp.addEventListener('change', () => { if (inp.files[0]) take(inp.files[0]); inp.value = ''; }));
    zone.addEventListener('dragover', (e) => { e.preventDefault(); zone.classList.add('is-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('is-over'));
    zone.addEventListener('drop', (e) => { e.preventDefault(); zone.classList.remove('is-over'); const f = e.dataTransfer && e.dataTransfer.files[0]; if (f) take(f); });
  }
  const onPaste = (e) => {
    if (V.phase !== 'start' || document.querySelector('.modal-wrap')) return;
    const item = Array.from((e.clipboardData && e.clipboardData.items) || []).find((i) => i.kind === 'file' && /^image\//.test(i.type));
    if (item) { e.preventDefault(); take(item.getAsFile()); }
  };

  // Le modèle d'image se télécharge pendant que le client choisit ou cadre sa photo.
  const warm = () => { if (ML.Vision && ML.Vision.supported()) ML.Vision.load().catch(() => {}); };
  ML.actions['vs-pick'] = () => { warm(); $('[data-vs-file="pick"]').click(); };
  ML.actions['vs-camera'] = async () => {
    warm();
    // Téléphone et tablette : l'appareil photo du système. Ordinateur : la webcam, dans la page.
    const desktop = window.matchMedia('(pointer: fine)').matches && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
    if (!desktop) { $('[data-vs-file="camera"]').click(); return; }
    const shot = await webcam();
    if (shot) take(shot);
    else if (shot === false) { ML.toast(ML.t('Caméra indisponible : choisissez plutôt une image.'), 'bad'); $('[data-vs-file="pick"]').click(); }
  };
  ML.actions['vs-new'] = () => { reset(); paint(); window.scrollTo(0, 0); };
  ML.actions['vs-edit'] = () => { V.editing = true; V.phase = 'edit'; paint(); const n = $('#v-name'); if (n) n.focus(); };
  ML.forms['vs-info'] = () => search();
  // Premier téléchargement du modèle sur un réseau lent : le client peut décrire le produit sans attendre.
  ML.actions['vs-skip'] = () => {
    if (V.phase !== 'analyzing') return;
    Object.assign(V, { phase: 'edit', editing: true, auto: false, src: '', vision: '', alts: [], info: EMPTY() });
    paint();
  };
  ML.actions['vs-alt'] = (el) => {
    const a = V.alts[+el.dataset.i];
    if (!a) return;
    const name = $('#v-name');
    const prev = { name: name.value, cat: $('#v-cat').value };
    name.value = a.name;
    $('#v-cat').value = a.cat;
    V.proposed = a.name;
    V.info.terms = [a.fr, a.ar];
    // Le choix précédent devient à son tour une possibilité.
    const was = V.alts.findIndex((x) => x === a);
    V.alts.splice(was, 1, prev.name ? { name: prev.name, cat: prev.cat, fr: prev.name, ar: prev.name } : a);
    if (!prev.name) V.alts.splice(was, 1);
    const box = $('.vs-alts');
    if (box) box.outerHTML = String(altsHtml());
    name.focus();
  };
  ML.actions['vs-similar'] = () => { const s = $('[data-similar]'); if (s) s.scrollIntoView({ behavior: 'smooth', block: 'start' }); else ML.toast(ML.t('Aucun produit similaire pour le moment.')); };
  ML.actions['vs-near'] = async () => {
    if (!ML.loc) await ML.pickLocation();
    const n = $('[data-near]');
    if (n) n.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  /** La position a changé : les points de vente du produit trouvé sont reclassés (sans refaire la recherche). */
  const onLoc = async () => {
    const p = V.res && V.res.exact;
    if (!p || V.phase !== 'results') return;
    try {
      const res = await X.api('availability', { params: { product: p.id, lat: ML.loc ? ML.loc.lat : '', lng: ML.loc ? ML.loc.lng : '', zone: ML.loc ? ML.loc.zone : '' } });
      p.near = res.stores.slice(0, 4);
      p.near_total = res.stores.length;
      paint();
    } catch (e) { /* la liste reste telle quelle */ }
  };

  /* ---------------------------------------------- recherche active (Radar) */
  ML.actions['vs-research'] = () => {
    const me = ML.ls.get('me', {});
    const m = ML.modal({
      title: ML.t('Recherche active (Radar)'),
      body: html`
        <form class="order-form" data-form="vs-research" novalidate>
          <div class="order-sum"><img class="vs-thumb" src="${V.url}" alt=""><div class="grow"><b>${V.info.name || ML.t('Produit de la photo')}</b><br><span class="muted">${[V.info.brand, ML.cats[V.info.category]].filter(Boolean).join(' · ')}</span></div></div>
          <div class="form-grid cols-2">
            ${V.info.name ? '' : html`<div class="field span-2"><label for="rs-name">${ML.t('Nom du produit')}</label><input class="input" id="rs-name" name="pname" maxlength="120" required placeholder="${ML.t('Ex. chaussure de running bleue')}"></div>`}
            <div class="field"><label for="rs-cname">${ML.t('Nom et prénom')}</label><input class="input" id="rs-cname" name="cname" autocomplete="name" required maxlength="80" value="${me.name || ''}"></div>
            <div class="field"><label for="rs-phone">${ML.t('Téléphone')}</label><input class="input" id="rs-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="05 00 00 00 00" value="${me.phone || ''}"></div>
            ${ML.placeFields({ prefix: 'rs', zone: me.zone || (ML.loc && ML.loc.zone) || '', required: true, address: false })}
            <div class="field span-2"><label for="rs-email">${ML.t('Email')} <span class="dim">(${ML.t('facultatif, pour être prévenu des réponses')})</span></label><input class="input" id="rs-email" name="email" type="email" autocomplete="email" value="${me.email || ''}"></div>
          </div>
          <label class="check"><input type="checkbox" name="call_ok" checked><span>${ML.t('Les points de vente qui ont le produit peuvent m\'appeler.')}</span></label>
          <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit" data-submit>${icon('target')}${ML.t('Lancer la recherche')}</button>
          <p class="order-legal">${ML.t('Votre photo, votre description et votre commune sont montrées aux points de vente et aux marques du réseau. Votre téléphone n\'est donné qu\'à ceux qui répondent avoir le produit, si vous l\'acceptez.')}</p>
        </form>`,
    });
    const form = $('form', m.el);
    ML.bindPlace(form, me.commune || '');
    ML.forms['vs-research'] = async (f) => {
      const d = ML.formData(f);
      const name = V.info.name || (d.pname || '').trim();
      if (name.length < 2) { ML.fail({ message: ML.t('Indiquez le nom du produit recherché.') }, f); return; }
      if (!d.zone || !d.commune) { ML.fail({ message: ML.t('Choisissez votre wilaya puis votre commune.') }, f); return; }
      const btn = $('[data-submit]', f);
      ML.busy(btn, true);
      const fd = new FormData();
      fd.append('file', V.blob, 'photo.jpg');
      Object.entries({ name, category: V.info.category, brand: V.info.brand, details: V.info.details, cname: d.cname, phone: d.phone, email: d.email, zone: d.zone, commune: d.commune,
        call_ok: d.call_ok ? '1' : '', auto: V.auto ? '1' : '', website: d.website }).forEach(([k, v]) => fd.append(k, v || ''));
      try {
        const res = await X.api('research_create', { body: fd });
        ML.ls.set('me', Object.assign({}, ML.ls.get('me', {}), { name: d.cname, phone: d.phone, email: d.email, zone: d.zone, commune: d.commune }));
        const mine = myResearches();
        mine.unshift({ ref: res.ref, token: res.token, name, at: Math.round(Date.now() / 1000) });
        ML.ls.set('researches', mine.slice(0, 20));
        $('.modal-head h2', m.el).textContent = ML.t('Recherche lancée');
        $('.modal-body', m.el).innerHTML = html`
          <div class="order-done">
            <div class="done-mark">${icon('target')}</div>
            <p class="done-ref">${ML.t('N°')} <b>${res.ref}</b></p>
            <p>${ML.t('Votre demande est dans le Radar du réseau. Dès qu\'un point de vente ou une marque a votre produit, sa réponse apparaît dans le suivi.')}</p>
            <div class="row row-wrap" style="justify-content:center">
              <a class="btn btn-primary" href="#/recherche/${res.token}" data-close>${icon('target')}${ML.t('Suivre ma recherche')}</a>
              <button class="btn btn-line" type="button" data-close>${ML.t('Continuer')}</button>
            </div>
          </div>`;
      } catch (e) {
        if (!(e instanceof X.Stale)) ML.fail(e, f);
      }
      ML.busy(btn, false);
    };
  };

  /* ------------------------------------------------- suivi d'une recherche */
  const R_STATUS = () => ({
    open: { label: ML.t('Recherche en cours'), cls: 'badge-brand', icon: 'target' },
    found: { label: ML.t('Produit trouvé'), cls: 'badge-good', icon: 'circle-check' },
    closed: { label: ML.t('Recherche arrêtée'), cls: '', icon: 'x' },
    expired: { label: ML.t('Recherche terminée'), cls: '', icon: 'clock' },
  });

  function reachText(r) {
    const n = r.reach.stores;
    const pts = fmt.plural(n, 'point de vente', 'points de vente');
    const next = r.reach.next_at ? ' ' + ML.t('Sans réponse, elle s\'élargit vers') + ' ' + new Intl.DateTimeFormat(ML.lang === 'ar' ? 'ar-DZ' : 'fr-FR', { hour: '2-digit', minute: '2-digit' }).format(new Date(r.reach.next_at * 1000)) + '.' : '';
    if (r.reach.level === 1) return ML.t('Proposée aux points de vente de votre commune') + ' (' + pts + ').' + next;
    if (r.reach.level === 2) return ML.t('Proposée aux points de vente de votre wilaya') + ' (' + pts + ').' + next;
    if (r.reach.level === 3) return ML.t('Proposée à tout le réseau') + ' (' + pts + ') ' + ML.t('et aux marques concernées.');
    return ML.t('Votre demande est enregistrée et visible des marques concernées. Elle sera proposée aux points de vente de votre région dès leur arrivée sur la plateforme.');
  }

  function researchDetail(r, auth) {
    const st = R_STATUS()[r.status] || R_STATUS().open;
    const open = r.status === 'open';
    const n = r.replies.length;
    const steps = [
      [true, ML.t('Recherche lancée'), fmt.time(r.created)],
      [r.reach.level > 0 || !open, ML.t('Diffusée dans le réseau'), ''],
      [n > 0, n ? fmt.plural(n, 'réponse reçue', 'réponses reçues') : ML.t('En attente d\'une réponse'), ''],
      [!open, open ? ML.t('Recherche terminée') : st.label, open ? '' : fmt.time(r.updated)],
    ];
    const now = steps.map((s) => s[0]).lastIndexOf(true);
    X.view().innerHTML = html`
      <div class="page track vs-track">
        <a class="back" href="#/suivi">${icon('arrow-left')}${ML.t('Suivi')}</a>
        <div class="track-head">
          <div><p class="eyebrow">${ML.t('Recherche active')}</p><h1>${r.ref}</h1></div>
          <span class="badge ${st.cls}">${icon(st.icon)}${st.label}</span>
        </div>
        ${open && !n ? html`<div class="notice is-radar">${icon('target')}<div><b>${ML.t('Le réseau cherche votre produit.')}</b><br>${reachText(r)}</div></div>` : ''}
        ${open && n ? html`<div class="notice is-good">${icon('circle-check')}<div><b>${fmt.plural(n, 'réponse', 'réponses')} :</b> ${ML.t('un point de vente ou une marque indique avoir votre produit. Vérifiez avec lui avant de vous déplacer.')}</div></div>` : ''}
        ${r.status === 'expired' ? html`<div class="notice">${icon('clock')}<div>${ML.t('Cette recherche est arrivée à son terme. Vous pouvez en lancer une nouvelle.')}</div></div>` : ''}
        <div class="track-grid">
          <div class="stack">
            <div class="card card-pad vs-wanted">
              ${r.image ? html`<img src="${ML.media(r.image, 480)}" alt="${ML.t('Votre photo')}">` : ML.visual({ name: r.name, category: r.category }, { cls: 'vs-pv' })}
              <div class="grow">
                <span class="label">${ML.t('Vous cherchez')}</span>
                <p><b>${r.name}</b>${r.brand ? html` · ${r.brand}` : ''}</p>
                <p class="muted">${ML.cats[r.category] || ''}</p>
                ${r.details ? html`<p class="vs-summary-details">${r.details}</p>` : ''}
                <p class="muted">${icon('map-pin', 'ico-sm')} ${placeText(r)}</p>
              </div>
            </div>
            <div class="card card-pad">
              <h2 class="card-title">${ML.t('Avancement')}</h2>
              <ol class="steps">${steps.map(([done, label, at], i) => html`<li class="${done ? 'is-done' : ''} ${i === now ? 'is-now' : ''}"><i>${done ? icon('check', 'ico-sm') : ''}</i><div><b>${label}</b>${at ? html`<small>${at}</small>` : ''}</div></li>`)}</ol>
              ${open && auth ? html`<div class="row row-wrap" style="margin-top:14px"><button class="btn btn-primary btn-sm" type="button" data-act="rs-close" data-found="1">${icon('check', 'ico-sm')}${ML.t('J\'ai trouvé mon produit')}</button><button class="btn btn-ghost btn-sm" type="button" data-act="rs-close" data-found="">${ML.t('Arrêter la recherche')}</button></div>` : ''}
            </div>
          </div>
          <div class="card card-pad">
            <div class="card-head"><h2 class="card-title">${ML.t('Réponses du réseau')}</h2>${open ? html`<button class="btn btn-ghost btn-sm" type="button" data-act="rs-refresh">${icon('refresh-cw', 'ico-sm')}${ML.t('Actualiser')}</button>` : ''}</div>
            ${n ? html`<ul class="vs-replies">${r.replies.map((rp) => html`
              <li>
                <div class="row between row-wrap"><b>${rp.store ? rp.store.name : rp.company}</b><small class="dim">${fmt.ago(rp.created)}</small></div>
                <p class="muted">${rp.store ? ML.joinList([rp.store.address, placeText(rp.store)]) : ML.t(rp.kind === 'brand' ? 'Marque' : 'Point de vente')}${rp.store && rp.store.name !== rp.company ? ' · ' + rp.company : ''}</p>
                ${rp.price != null ? html`<p><b class="price">${fmt.price(rp.price)}</b> <span class="muted">${ML.t('prix indiqué par le point de vente')}</span></p>` : ''}
                ${rp.message ? html`<p class="onote">${rp.message}</p>` : ''}
                ${rp.product ? html`<a class="vs-reply-product" href="#/produit/${rp.product.slug}">${ML.visual(rp.product, { cls: 'pv-xs' })}<span class="grow"><b>${rp.product.name}</b><small class="muted">${rp.product.brand.name} · ${fmt.price(rp.product.price)}</small></span>${icon('chevron-right', 'ico-sm')}</a>` : ''}
                ${rp.store ? html`<div class="row row-wrap">${X.contactButtons(rp.store, true)}${rp.store.listed ? html`<a class="btn btn-soft btn-sm" href="#/pdv/${rp.store.id}">${ML.t('Voir le point de vente')}</a>` : ''}</div>` : ''}
              </li>`)}</ul>`
              : html`<div class="empty">${icon('target')}<h3>${open ? ML.t('Pas encore de réponse') : ML.t('Aucune réponse reçue')}</h3><p>${open ? ML.t('Les réponses des points de vente et des marques apparaissent ici. Gardez votre numéro de recherche pour revenir.') : ''}</p></div>`}
            ${!open ? html`<p style="margin-top:14px"><a class="btn btn-line btn-sm" href="#/photo">${icon('camera', 'ico-sm')}${ML.t('Nouvelle recherche par photo')}</a></p>` : ''}
          </div>
        </div>
      </div>`;
    const params = auth && auth.token ? { token: auth.token } : auth ? { ref: auth.ref, phone: auth.phone } : null;
    const reload = async () => { if (!params) return; try { researchDetail((await X.api('research_get', { params })).research, auth); } catch (e) { /* prochain essai */ } };
    ML.actions['rs-refresh'] = reload;
    ML.actions['rs-close'] = async (el) => {
      const found = el.dataset.found === '1';
      if (!found && !await ML.confirm(ML.t('Arrêter cette recherche ? Les points de vente ne la verront plus.'), { ok: ML.t('Arrêter la recherche'), danger: true })) return;
      ML.busy(el, true);
      try {
        researchDetail((await X.api('research_close', { body: Object.assign({ found }, params) })).research, auth);
        ML.toast(found ? ML.t('Tant mieux ! Votre recherche est close.') : ML.t('Recherche arrêtée.'), 'good');
      } catch (e) { if (!(e instanceof X.Stale)) ML.fail(e); ML.busy(el, false); }
    };
    // Les réponses arrivent pendant qu'on regarde : la page se met à jour toute seule.
    clearInterval(researchDetail.timer);
    if (open && params) researchDetail.timer = setInterval(() => { if (!document.hidden && !document.querySelector('.modal-wrap')) reload(); }, 30000);
    X.S.cleanup = () => clearInterval(researchDetail.timer);
  }

  /* ------------------------------------------------------------------ routes */
  X.navOf.recherche = 'suivi';
  X.routes.photo = async () => {
    document.addEventListener('paste', onPaste);
    document.addEventListener('mlg:loc', onLoc);
    X.S.cleanup = () => { document.removeEventListener('paste', onPaste); document.removeEventListener('mlg:loc', onLoc); };
    // Une recherche en attente de réponse quand on a quitté la page ne peut pas reprendre : on revient à la description.
    if (V.phase === 'analyzing' || V.phase === 'searching') { V.phase = V.blob ? 'edit' : 'start'; V.editing = true; }
    paint();
  };
  X.routes.recherche = async (r) => {
    const token = r.parts[1] || '';
    if (!/^[a-f0-9]{40}$/.test(token)) { ML.go('/suivi', {}, true); return; }
    X.loading();
    try {
      researchDetail((await X.api('research_get', { params: { token } })).research, { token });
    } catch (e) {
      if (e instanceof X.Stale) return;
      X.notFound(ML.t('Cette recherche est introuvable'));
    }
  };
  /** Suivi par numéro (RS-…) et téléphone, depuis la page Suivi. */
  X.researchByRef = async (ref, phone) => {
    const res = await X.api('research_get', { params: { ref, phone } });
    researchDetail(res.research, { ref, phone });
  };
})();
