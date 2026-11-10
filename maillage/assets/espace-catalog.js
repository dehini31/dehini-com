/*
 * Maillage — espace entreprise : catalogue (produits, déclinaisons, marques), points de vente
 * (fiche et position sur la carte) et disponibilité des produits magasin par magasin.
 */
(function () {
  'use strict';
  const { html, icon, fmt, color } = ML;
  const E = ML.E;
  const api = (action, opts) => E.api(action, opts);
  const { $, $$ } = E;
  const view = E.view;
  const TYPES = { magasin: 'Magasin', succursale: 'Succursale', distributeur: 'Distributeur' };
  const PALETTE = [['Noir', '#1b1b1f'], ['Blanc', '#f5f5f4'], ['Gris', '#9ca3af'], ['Bleu', '#2563eb'], ['Bleu nuit', '#1e293b'], ['Vert', '#15803d'], ['Rouge', '#dc2626'], ['Orange', '#f97316'], ['Jaune', '#facc15'], ['Rose', '#ec4899'], ['Violet', '#7c3aed'], ['Marron', '#7c4a2d'], ['Beige', '#d9c7a8']];

  /* ============================================================ CATALOGUE */
  E.views.catalogue = async (r) => {
    if (r.parts[1] === 'produit') return productEditor(r.parts[2] || 'nouveau');
    E.loading();
    const d = await api('dash_products');
    const tab = r.query.t === 'marques' ? 'marques' : 'produits';
    const F = { q: '', brand: '' };
    view().innerHTML = html`
      ${E.head('Catalogue', 'Vos marques, vos produits et leurs déclinaisons (tailles, couleurs).',
        tab === 'produits' ? html`<a class="btn btn-primary" href="#/catalogue/produit/nouveau">${icon('plus')}Nouveau produit</a>` : html`<button class="btn btn-primary" type="button" data-act="brand-edit" data-id="0">${icon('plus')}Nouvelle marque</button>`)}
      <div class="tabs" role="tablist">
        <a role="tab" aria-selected="${tab === 'produits'}" class="${tab === 'produits' ? 'is-on' : ''}" href="#/catalogue">Produits <small>${d.products.length}</small></a>
        <a role="tab" aria-selected="${tab === 'marques'}" class="${tab === 'marques' ? 'is-on' : ''}" href="#/catalogue?t=marques">Marques <small>${d.brands.length}</small></a>
      </div>
      <div data-body></div>`;

    const paintProducts = () => {
      const q = ML.norm(F.q);
      const list = d.products.filter((p) => (!F.brand || String(p.brand.id) === F.brand) && (!q || ML.norm(p.name + ' ' + p.brand.name).includes(q)));
      $('[data-rows]').innerHTML = list.length ? html`${list.map((p) => html`
        <a class="prow" href="#/catalogue/produit/${p.id}">
          ${ML.visual(p, { cls: 'pv-row' })}
          <span class="grow"><b>${p.name}</b><small class="muted">${p.brand.name} · ${ML.cats[p.category] || ''}</small></span>
          <span class="prow-meta"><b class="num">${fmt.price(p.price)}</b><small class="muted">${fmt.plural(p.variants, 'déclinaison')}</small></span>
          <span class="prow-meta">${p.stores ? html`<span class="badge badge-good">${icon('store')}${fmt.plural(p.stores, 'magasin')}</span>` : html`<span class="badge">Pas en stock</span>`}${p.status === 'draft' ? html`<span class="badge badge-warn">Brouillon</span>` : ''}</span>
          ${icon('chevron-right', 'ico-sm')}
        </a>`)}` : html`<div class="empty">${icon('package-search')}<h3>Aucun produit ne correspond</h3></div>`;
    };
    if (tab === 'produits') {
      $('[data-body]').innerHTML = d.products.length ? html`
        <div class="toolbar"><div class="toolbar-row">
          <div class="input-icon grow">${icon('search')}<input class="input input-sm" type="search" placeholder="Chercher un produit…" data-input="pq" aria-label="Chercher un produit"></div>
          ${d.brands.length > 1 ? html`<select class="select input-sm" data-change="pbrand" aria-label="Marque"><option value="">Toutes les marques</option>${d.brands.map((b) => html`<option value="${b.id}">${b.name}</option>`)}</select>` : ''}
        </div></div>
        <div class="card plist-card" data-rows></div>`
        : html`<div class="card"><div class="empty">${icon('package')}<h3>Votre catalogue est vide</h3><p>${d.brands.length ? 'Ajoutez votre premier produit : photos, prix, tailles et couleurs.' : 'Créez d\'abord une marque, puis ajoutez vos produits.'}</p>${d.brands.length ? html`<a class="btn btn-primary" href="#/catalogue/produit/nouveau">${icon('plus')}Ajouter un produit</a>` : html`<button class="btn btn-primary" type="button" data-act="brand-edit" data-id="0">${icon('plus')}Créer une marque</button>`}</div></div>`;
      if (d.products.length) paintProducts();
      ML.inputs.pq = (el) => { F.q = el.value; paintProducts(); };
      ML.changes.pbrand = (el) => { F.brand = el.value; paintProducts(); };
    } else {
      $('[data-body]').innerHTML = d.brands.length ? html`<div class="bgrid">${d.brands.map((b) => html`
        <article class="card brandcard" style="--c:${color(b.color, '#101828')}">
          <div class="brandcard-band"></div>
          <div class="card-pad">
            <div class="row">${ML.mono(b.name, b.color, b.logo)}<div class="grow"><h3 class="card-title">${b.name}</h3><p class="card-sub">${b.tagline || ML.cats[b.category] || ''}</p></div></div>
            <p class="muted" style="margin:12px 0">${fmt.plural(b.products, 'produit')}</p>
            <div class="row row-wrap"><button class="btn btn-line btn-sm" type="button" data-act="brand-edit" data-id="${b.id}">${icon('pencil', 'ico-sm')}Modifier</button><a class="btn btn-ghost btn-sm" href="../explorer/#/marque/${b.slug}" target="_blank" rel="noopener">${icon('external-link', 'ico-sm')}Page publique</a></div>
          </div>
        </article>`)}</div>`
        : html`<div class="card"><div class="empty">${icon('tag')}<h3>Pas encore de marque</h3><p>Une marque regroupe vos produits et dispose de sa page publique.</p><button class="btn btn-primary" type="button" data-act="brand-edit" data-id="0">${icon('plus')}Créer une marque</button></div></div>`;
    }
    ML.actions['brand-edit'] = (el) => brandForm(d.brands.find((b) => b.id === +el.dataset.id) || null);
  };

  function brandForm(b) {
    let logo = b ? b.logo : '';
    const m = ML.modal({
      title: b ? 'Modifier la marque' : 'Nouvelle marque',
      body: html`
        <form class="stack" data-form="brand" id="brand-form">
          <div class="logo-edit"><span data-logo>${ML.mono(b ? b.name : 'M', b ? b.color : '#101828', logo, 'mono-lg')}</span><div><label class="btn btn-line btn-sm">${icon('upload', 'ico-sm')}Logo<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-file></label><p class="hint muted">Facultatif. Sans logo, les initiales s'affichent sur la couleur.</p></div></div>
          <div class="form-grid cols-2">
            <div class="field"><label for="b-name">Nom</label><input class="input" id="b-name" name="name" required maxlength="60" value="${b ? b.name : ''}"></div>
            <div class="field"><label for="b-color">Couleur</label><input class="input input-color" id="b-color" name="color" type="color" value="${color(b && b.color, '#2563eb')}"></div>
            <div class="field span-2"><label for="b-tag">Signature <span class="dim">(une phrase)</span></label><input class="input" id="b-tag" name="tagline" maxlength="120" value="${b ? b.tagline : ''}"></div>
            <div class="field span-2"><label for="b-cat">Rayon principal</label><select class="select" id="b-cat" name="category">${ML.boot.categories.map((k) => html`<option value="${k.key}" ${b && b.category === k.key ? ML.raw('selected') : ''}>${k.label}</option>`)}</select></div>
            <div class="field span-2"><label for="b-about">Présentation</label><textarea class="textarea" id="b-about" name="about" maxlength="2000">${b ? b.about : ''}</textarea></div>
          </div>
          <p class="form-error" data-error hidden></p>
        </form>`,
      foot: html`${b ? html`<button class="btn btn-danger" type="button" data-del style="margin-right:auto">Supprimer</button>` : ''}<button class="btn btn-line" type="button" data-close>Annuler</button><button class="btn btn-primary" type="submit" form="brand-form">Enregistrer</button>`,
    });
    $('[data-file]', m.el).addEventListener('change', async (e) => {
      if (!e.target.files[0]) return;
      const p = await E.upload(e.target.files[0]);
      if (p) { logo = p; $('[data-logo]', m.el).innerHTML = ML.mono('', '#fff', logo, 'mono-lg'); }
    });
    ML.forms.brand = async (f) => {
      const res = await E.send('brand_save', Object.assign(ML.formData(f), { id: b ? b.id : 0, logo }), f);
      if (res) { m.close(); ML.toast('Marque enregistrée.', 'good'); E.reload(); }
    };
    const del = $('[data-del]', m.el);
    if (del) del.addEventListener('click', async () => {
      if (!await ML.confirm('Supprimer la marque « ' + b.name + ' » ?', { ok: 'Supprimer', danger: true })) return;
      if (await E.send('brand_delete', { id: b.id })) { m.close(); E.reload(); }
    });
  }

  /* ---------------------------------------------------- fiche d'un produit */
  async function productEditor(idOrNew) {
    E.loading();
    const brands = (await api('brands')).brands;
    if (!brands.length) {
      view().innerHTML = html`${E.head('Nouveau produit')}<div class="card"><div class="empty">${icon('tag')}<h3>Créez d'abord une marque</h3><p>Chaque produit appartient à une marque.</p><a class="btn btn-primary" href="#/catalogue?t=marques">Créer une marque</a></div></div>`;
      return;
    }
    const isNew = idOrNew === 'nouveau';
    const p = isNew ? { id: 0, brand_id: brands[0].id, name: '', category: brands[0].category || 'autre', about: '', price: '', images: [], specs: [], status: 'active', variants: [] }
      : (await api('dash_product', { params: { id: idOrNew } })).product;
    const st = { images: p.images.slice(), variants: p.variants.map((v) => Object.assign({}, v)), specs: p.specs.map((s) => Object.assign({}, s)) };
    if (!st.variants.length) st.variants.push({ id: 0, size: '', color: '', hex: '', sku: '', price: null });
    const single = () => st.variants.length === 1 && !st.variants[0].size && !st.variants[0].color;

    view().innerHTML = html`
      <a class="back" href="#/catalogue">${icon('arrow-left')}Catalogue</a>
      ${E.head(isNew ? 'Nouveau produit' : p.name, isNew ? '' : html`<a class="link" href="../explorer/#/produit/${p.slug}" target="_blank" rel="noopener">Voir la fiche publique</a>`)}
      <form class="editor" data-form="product" novalidate>
        <div class="editor-main stack">
          <section class="card card-pad stack">
            <h2 class="card-title">Informations</h2>
            <div class="form-grid cols-2">
              <div class="field span-2"><label for="p-name">Nom du produit</label><input class="input" id="p-name" name="name" required maxlength="120" value="${p.name}"></div>
              <div class="field"><label for="p-brand">Marque</label><select class="select" id="p-brand" name="brand_id">${brands.map((b) => html`<option value="${b.id}" ${p.brand_id === b.id ? ML.raw('selected') : ''}>${b.name}</option>`)}</select></div>
              <div class="field"><label for="p-cat">Rayon</label><select class="select" id="p-cat" name="category">${ML.boot.categories.map((k) => html`<option value="${k.key}" ${p.category === k.key ? ML.raw('selected') : ''}>${k.label}</option>`)}</select></div>
              <div class="field"><label for="p-price">Prix (${ML.boot.config.currency})</label><input class="input" id="p-price" name="price" type="number" min="0" step="any" inputmode="decimal" required value="${p.price}"></div>
              <div class="field"><label for="p-status">Visibilité</label><select class="select" id="p-status" name="status"><option value="active" ${p.status === 'active' ? ML.raw('selected') : ''}>En ligne</option><option value="draft" ${p.status === 'draft' ? ML.raw('selected') : ''}>Brouillon (masqué)</option></select></div>
              <div class="field span-2"><label for="p-about">Description</label><textarea class="textarea" id="p-about" name="about" maxlength="3000">${p.about}</textarea></div>
            </div>
          </section>
          <section class="card card-pad stack">
            <div><h2 class="card-title">Tailles et couleurs</h2><p class="card-sub">Chaque déclinaison a son stock dans chaque magasin. Laissez vide pour un modèle unique.</p></div>
            <div class="vgen">
              <div class="field"><label for="g-sizes">Tailles <span class="dim">(séparées par des virgules)</span></label><input class="input" id="g-sizes" placeholder="S, M, L, XL  ou  39, 40, 41" data-gsizes></div>
              <div class="field"><span class="label">Couleurs</span><div class="chips wrap" data-gcolors>${PALETTE.map(([n, h]) => html`<button class="chip" type="button" data-act="gcolor" data-n="${n}" data-h="${h}" aria-pressed="false"><i class="swatch" style="--c:${h}"></i>${n}</button>`)}</div></div>
              <div><button class="btn btn-line btn-sm" type="button" data-act="vgen">${icon('wand-sparkles', 'ico-sm')}Créer les déclinaisons</button></div>
            </div>
            <div data-variants></div>
          </section>
          <section class="card card-pad stack">
            <div><h2 class="card-title">Caractéristiques</h2><p class="card-sub">Matière, dimensions, puissance, garantie… Elles servent aussi à la recherche.</p></div>
            <div data-specs></div>
            <div><button class="btn btn-line btn-sm" type="button" data-act="spec-add">${icon('plus', 'ico-sm')}Ajouter une caractéristique</button></div>
          </section>
        </div>
        <aside class="editor-side stack">
          <section class="card card-pad stack">
            <div><h2 class="card-title">Photos</h2><p class="card-sub">Jusqu'à 8. La première sert de vignette.</p></div>
            <div class="photos" data-photos></div>
            <label class="drop">${icon('image-plus')}<span>Ajouter des photos</span><small>JPG, PNG ou WebP · 8 Mo max</small><input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden data-change="photos"></label>
          </section>
          <section class="card card-pad stack">
            <p class="form-error" data-error hidden></p>
            <button class="btn btn-primary btn-lg btn-block" type="submit">${icon('check')}Enregistrer</button>
            ${isNew ? '' : html`<a class="btn btn-line btn-block" href="#/disponibilite">${icon('boxes')}Gérer la disponibilité</a><button class="btn btn-danger btn-block" type="button" data-act="p-del">Supprimer le produit</button>`}
          </section>
        </aside>
      </form>`;

    const paintPhotos = () => {
      $('[data-photos]').innerHTML = html`${st.images.map((im, i) => html`<div class="photo"><img src="${ML.media(im, 480)}" alt="Photo ${i + 1}">${i === 0 ? html`<span class="badge badge-dark">Vignette</span>` : html`<button type="button" class="photo-btn is-first" data-act="ph-first" data-i="${i}" aria-label="Mettre en premier">${icon('arrow-left', 'ico-sm')}</button>`}<button type="button" class="photo-btn" data-act="ph-del" data-i="${i}" aria-label="Retirer la photo">${icon('x', 'ico-sm')}</button></div>`)}`;
    };
    const paintVariants = () => {
      $('[data-variants]').innerHTML = single() ? html`<p class="muted">Modèle unique (pas de taille ni de couleur). <button class="link" type="button" data-act="v-add">Ajouter une déclinaison à la main</button></p>` : html`
        <div class="tbl-wrap"><table class="tbl tbl-edit">
          <thead><tr><th>Taille</th><th>Couleur</th><th></th><th>Référence</th><th>Prix particulier</th><th></th></tr></thead>
          <tbody>${st.variants.map((v, i) => html`<tr data-i="${i}">
            <td><input class="input input-sm" data-f="size" value="${v.size}" aria-label="Taille" maxlength="30"></td>
            <td><input class="input input-sm" data-f="color" value="${v.color}" aria-label="Couleur" maxlength="30"></td>
            <td><input class="input input-sm input-color" type="color" data-f="hex" value="${color(v.hex, '#cccccc')}" aria-label="Teinte"></td>
            <td><input class="input input-sm" data-f="sku" value="${v.sku}" aria-label="Référence" maxlength="40"></td>
            <td><input class="input input-sm" type="number" min="0" step="any" data-f="price" value="${v.price == null ? '' : v.price}" placeholder="Prix de base" aria-label="Prix particulier"></td>
            <td><button class="btn btn-ghost btn-icon btn-sm" type="button" data-act="v-del" data-i="${i}" aria-label="Supprimer la déclinaison">${icon('trash-2', 'ico-sm')}</button></td>
          </tr>`)}</tbody></table></div>
        <div><button class="btn btn-ghost btn-sm" type="button" data-act="v-add">${icon('plus', 'ico-sm')}Ajouter une ligne</button></div>`;
    };
    const paintSpecs = () => {
      $('[data-specs]').innerHTML = st.specs.length ? html`${st.specs.map((s, i) => html`<div class="spec-row" data-i="${i}"><input class="input input-sm" data-f="k" value="${s.k}" placeholder="Matière" aria-label="Caractéristique" maxlength="40"><input class="input input-sm" data-f="v" value="${s.v}" placeholder="100 % coton" aria-label="Valeur" maxlength="120"><button class="btn btn-ghost btn-icon btn-sm" type="button" data-act="spec-del" data-i="${i}" aria-label="Supprimer">${icon('trash-2', 'ico-sm')}</button></div>`)}` : '';
    };
    // Les champs des tableaux sont lus au fil de la saisie.
    const onField = (e) => {
      const f = e.target.dataset.f;
      if (!f) return;
      const tr = e.target.closest('[data-i]');
      const list = tr.classList.contains('spec-row') ? st.specs : st.variants;
      const item = list[+tr.dataset.i];
      if (item) item[f] = f === 'price' ? (e.target.value === '' ? null : e.target.value) : e.target.value;
    };
    view().addEventListener('input', onField);
    E.cleanup = () => view().removeEventListener('input', onField);
    const paletteHex = (name) => { const hit = PALETTE.find(([n]) => ML.norm(n) === ML.norm(name)); return hit ? hit[1] : ''; };
    const picked = {};
    ML.actions.gcolor = (el) => { const on = el.getAttribute('aria-pressed') !== 'true'; el.setAttribute('aria-pressed', on); el.classList.toggle('is-on', on); if (on) picked[el.dataset.n] = el.dataset.h; else delete picked[el.dataset.n]; };
    ML.actions.vgen = () => {
      const sizes = $('[data-gsizes]').value.split(/[,;\n]/).map((s) => s.trim()).filter(Boolean);
      const cols = Object.entries(picked);
      if (!sizes.length && !cols.length) { ML.toast('Saisissez des tailles ou choisissez des couleurs.', 'bad'); return; }
      if (single()) st.variants = [];
      const has = (s, c) => st.variants.some((v) => ML.norm(v.size) === ML.norm(s) && ML.norm(v.color) === ML.norm(c));
      (sizes.length ? sizes : ['']).forEach((s) => (cols.length ? cols : [['', '']]).forEach(([c, h]) => { if (!has(s, c)) st.variants.push({ id: 0, size: s, color: c, hex: h, sku: '', price: null }); }));
      paintVariants();
    };
    ML.actions['v-add'] = () => { if (single()) st.variants = []; st.variants.push({ id: 0, size: '', color: '', hex: '', sku: '', price: null }); paintVariants(); };
    ML.actions['v-del'] = (el) => { st.variants.splice(+el.dataset.i, 1); if (!st.variants.length) st.variants.push({ id: 0, size: '', color: '', hex: '', sku: '', price: null }); paintVariants(); };
    ML.actions['spec-add'] = () => { st.specs.push({ k: '', v: '' }); paintSpecs(); const rows = $$('.spec-row input[data-f=k]'); rows[rows.length - 1].focus(); };
    ML.actions['spec-del'] = (el) => { st.specs.splice(+el.dataset.i, 1); paintSpecs(); };
    ML.actions['ph-del'] = (el) => { st.images.splice(+el.dataset.i, 1); paintPhotos(); };
    ML.actions['ph-first'] = (el) => { const [im] = st.images.splice(+el.dataset.i, 1); st.images.unshift(im); paintPhotos(); };
    ML.changes.photos = async (el) => {
      const drop = el.closest('.drop');
      drop.classList.add('is-busy');
      for (const file of Array.from(el.files).slice(0, 8 - st.images.length)) {
        const path = await E.upload(file);
        if (path) { st.images.push(path); paintPhotos(); }
      }
      drop.classList.remove('is-busy');
      el.value = '';
    };
    ML.forms.product = async (f) => {
      const d = ML.formData(f);
      const btn = $('button[type=submit]', f);
      ML.busy(btn, true);
      const res = await E.send('product_save', {
        id: p.id, name: d.name, brand_id: +d.brand_id, category: d.category, price: d.price, status: d.status, about: d.about,
        images: st.images, specs: st.specs.filter((s) => s.k && s.v),
        variants: single() ? [{ id: st.variants[0].id || 0 }] : st.variants.map((v) => ({ id: v.id || 0, size: v.size, color: v.color, hex: v.color ? (v.hex || paletteHex(v.color)) : '', sku: v.sku, price: v.price })),
      }, f);
      ML.busy(btn, false);
      if (!res) return;
      ML.toast('Produit enregistré.', 'good');
      if (isNew) ML.go('/catalogue/produit/' + res.product.id); else E.reload();
    };
    ML.actions['p-del'] = async () => {
      if (!await ML.confirm('Supprimer « ' + p.name + ' » ? Il disparaît du catalogue et de tous les magasins.', { ok: 'Supprimer', danger: true })) return;
      if (await E.send('product_delete', { id: p.id })) { ML.toast('Produit supprimé.', 'good'); ML.go('/catalogue'); }
    };
    paintPhotos(); paintVariants(); paintSpecs();
  }

  /* ====================================================== POINTS DE VENTE */
  E.views['points-de-vente'] = async (r) => {
    if (r.parts[1]) return storeEditor(r.parts[1]);
    E.loading();
    const stores = (await api('dash_stores')).stores;
    view().innerHTML = html`
      ${E.head('Points de vente', 'Vos magasins, succursales et points de distribution sur la carte.', html`<a class="btn btn-primary" href="#/points-de-vente/nouveau">${icon('plus')}Nouveau point de vente</a>`)}
      ${stores.length ? html`
        <div class="split">
          <ul class="card slist">${stores.map((s) => html`
            <li>
              <span class="type-pin" style="--c:${ML.TYPE_COLORS[s.type]}">${icon('map-pin', 'ico-sm')}</span>
              <span class="grow"><b>${s.name}</b><small class="muted">${TYPES[s.type]} · ${s.city}${s.zone && s.zone !== s.city ? ', ' + s.zone : ''}</small>
                <span class="slist-tags">${s.status === 'closed' ? html`<span class="badge badge-warn">Masqué</span>` : ''}${s.refs ? html`<span class="badge ${s.instock < s.refs * 0.6 ? 'badge-warn' : 'badge-good'}">${s.instock}/${s.refs} en stock</span>` : html`<span class="badge">Stock à renseigner</span>`}${s.open_orders ? html`<span class="badge badge-brand">${fmt.plural(s.open_orders, 'commande')} en cours</span>` : ''}</span></span>
              <span class="slist-actions"><a class="btn btn-line btn-sm" href="#/disponibilite?pdv=${s.id}">${icon('boxes', 'ico-sm')}Stock</a><a class="btn btn-ghost btn-icon btn-sm" href="#/points-de-vente/${s.id}" aria-label="Modifier ${s.name}">${icon('pencil', 'ico-sm')}</a></span>
            </li>`)}</ul>
          <div class="card split-map"><div id="map"></div></div>
        </div>` : html`<div class="card"><div class="empty">${icon('store')}<h3>Aucun point de vente</h3><p>Ajoutez votre premier magasin : il apparaîtra sur la carte publique avec ses horaires et ses coordonnées.</p><a class="btn btn-primary" href="#/points-de-vente/nouveau">${icon('plus')}Ajouter un point de vente</a></div></div>`}`;
    if (!stores.length) return;
    const map = ML.makeMap($('#map'), { wheel: false });
    const pts = stores.map((s) => {
      L.marker([s.lat, s.lng], { icon: ML.pin(ML.TYPE_COLORS[s.type]), title: s.name }).addTo(map).bindPopup(html`<b>${s.name}</b><br><a class="link" href="#/points-de-vente/${s.id}">Modifier</a>`.toString());
      return [s.lat, s.lng];
    });
    map.fitBounds(L.latLngBounds(pts).pad(0.2), { maxZoom: 13, animate: false });
    E.cleanup = () => map.remove();
  };

  async function storeEditor(idOrNew) {
    E.loading();
    const isNew = idOrNew === 'nouveau';
    const all = isNew ? [] : (await api('dash_stores')).stores;
    const s = isNew ? { id: 0, name: '', type: E.isBrand() ? 'magasin' : 'distributeur', address: '', city: '', wilaya: '', lat: null, lng: null, phone: '', email: '', hours: { open: '09:00', close: '19:00', days: [0, 1, 2, 3, 4, 6] }, pickup: true, delivery: false, reserve: true, status: 'active' }
      : all.find((x) => String(x.id) === String(idOrNew));
    if (!s) { view().innerHTML = html`<div class="empty">${icon('store')}<h3>Point de vente introuvable</h3><a class="btn btn-dark" href="#/points-de-vente">Revenir à la liste</a></div>`; return; }
    const zones = ML.boot.zones.slice().sort((a, b) => a.name.localeCompare(b.name, 'fr'));
    const pos = { lat: s.lat, lng: s.lng };
    const days = new Set(s.hours.days);
    const ORDER = [6, 0, 1, 2, 3, 4, 5]; // la semaine commence le samedi
    view().innerHTML = html`
      <a class="back" href="#/points-de-vente">${icon('arrow-left')}Points de vente</a>
      ${E.head(isNew ? 'Nouveau point de vente' : s.name)}
      <form class="editor" data-form="store" novalidate>
        <div class="editor-main stack">
          <section class="card card-pad stack">
            <h2 class="card-title">Identité</h2>
            <div class="form-grid cols-2">
              <div class="field"><label for="s-name">Nom</label><input class="input" id="s-name" name="name" required maxlength="80" placeholder="Ex. Boutique Centre-ville" value="${s.name}"></div>
              <div class="field"><label for="s-type">Type</label><select class="select" id="s-type" name="type">${Object.keys(TYPES).map((k) => html`<option value="${k}" ${s.type === k ? ML.raw('selected') : ''}>${TYPES[k]}</option>`)}</select></div>
              <div class="field"><label for="s-phone">Téléphone</label><input class="input" id="s-phone" name="phone" type="tel" inputmode="tel" value="${s.phone}"></div>
              <div class="field"><label for="s-email">Email <span class="dim">(reçoit les commandes)</span></label><input class="input" id="s-email" name="email" type="email" value="${s.email}"></div>
            </div>
          </section>
          <section class="card card-pad stack">
            <div><h2 class="card-title">Adresse et position</h2><p class="card-sub">Cliquez sur la carte ou déplacez l'épingle pour placer le point de vente.</p></div>
            <div class="form-grid cols-2">
              <div class="field span-2"><label for="s-address">Adresse</label><input class="input" id="s-address" name="address" maxlength="200" value="${s.address}"></div>
              <div class="field"><label for="s-city">Ville</label><input class="input" id="s-city" name="city" required maxlength="80" value="${s.city}"></div>
              <div class="field"><label for="s-zone">Wilaya</label><select class="select" id="s-zone" name="wilaya" data-change="szone"><option value="">Choisir…</option>${zones.map((z) => html`<option value="${z.code}" ${s.wilaya === z.code ? ML.raw('selected') : ''}>${z.name}</option>`)}</select></div>
            </div>
            <div class="row row-wrap"><button class="btn btn-line btn-sm" type="button" data-act="s-find">${icon('search', 'ico-sm')}Trouver l'adresse sur la carte</button><button class="btn btn-line btn-sm" type="button" data-act="s-here">${icon('locate-fixed', 'ico-sm')}Je suis sur place</button><span class="muted" data-pos></span></div>
            <div class="pick-map"><div id="map"></div></div>
          </section>
          <section class="card card-pad stack">
            <h2 class="card-title">Horaires</h2>
            <div class="row row-wrap"><div class="field"><label for="s-open">Ouverture</label><input class="input" id="s-open" name="open" type="time" value="${s.hours.open}"></div><div class="field"><label for="s-close">Fermeture</label><input class="input" id="s-close" name="close" type="time" value="${s.hours.close}"></div></div>
            <div class="field"><span class="label">Jours d'ouverture</span><div class="chips wrap">${ORDER.map((d) => html`<button class="chip ${days.has(d) ? 'is-on' : ''}" type="button" data-act="s-day" data-d="${d}" aria-pressed="${days.has(d)}">${ML.DAYS[d]}</button>`)}</div></div>
          </section>
        </div>
        <aside class="editor-side stack">
          <section class="card card-pad stack">
            <h2 class="card-title">Services</h2>
            <label class="switch"><input type="checkbox" name="pickup" ${s.pickup ? ML.raw('checked') : ''}><i></i>Retrait en magasin</label>
            <label class="switch"><input type="checkbox" name="reserve" ${s.reserve ? ML.raw('checked') : ''}><i></i>Réservation (mise de côté)</label>
            <label class="switch"><input type="checkbox" name="delivery" ${s.delivery ? ML.raw('checked') : ''}><i></i>Livraison</label>
            <hr class="sep">
            <label class="switch"><input type="checkbox" name="visible" ${s.status !== 'closed' ? ML.raw('checked') : ''}><i></i>Visible sur la carte publique</label>
          </section>
          <section class="card card-pad stack">
            <p class="form-error" data-error hidden></p>
            <button class="btn btn-primary btn-lg btn-block" type="submit">${icon('check')}Enregistrer</button>
            ${isNew ? '' : html`<a class="btn btn-line btn-block" href="#/disponibilite?pdv=${s.id}">${icon('boxes')}Gérer la disponibilité</a><button class="btn btn-danger btn-block" type="button" data-act="s-del">Supprimer</button>`}
          </section>
        </aside>
      </form>`;

    const start = pos.lat != null ? [pos.lat, pos.lng] : null;
    const map = ML.makeMap($('#map'), start ? { center: start, zoom: 15 } : {});
    let marker = null;
    const place = (lat, lng, fly) => {
      pos.lat = lat; pos.lng = lng;
      if (!marker) {
        marker = L.marker([lat, lng], { icon: ML.pin('#ff5a2b'), draggable: true, autoPan: true }).addTo(map);
        marker.on('dragend', () => { const ll = marker.getLatLng(); place(ll.lat, ll.lng); });
      } else marker.setLatLng([lat, lng]);
      if (fly) map.setView([lat, lng], Math.max(map.getZoom(), 15));
      $('[data-pos]').textContent = 'Position : ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
    };
    if (start) place(start[0], start[1]);
    map.on('click', (e) => place(e.latlng.lat, e.latlng.lng));
    E.cleanup = () => map.remove();
    setTimeout(() => map.invalidateSize(), 60);

    ML.changes.szone = (el) => { const z = ML.zones[el.value]; if (z && pos.lat == null) map.setView([z.lat, z.lng], 12); };
    ML.actions['s-day'] = (el) => { const d = +el.dataset.d; if (days.has(d)) days.delete(d); else days.add(d); el.classList.toggle('is-on', days.has(d)); el.setAttribute('aria-pressed', days.has(d)); };
    ML.actions['s-here'] = (el) => {
      if (!navigator.geolocation) { ML.toast('La géolocalisation n\'est pas disponible.', 'bad'); return; }
      ML.busy(el, true);
      navigator.geolocation.getCurrentPosition((p) => { ML.busy(el, false); place(p.coords.latitude, p.coords.longitude, true); }, () => { ML.busy(el, false); ML.toast('Position introuvable. Placez l\'épingle à la main.', 'bad'); }, { enableHighAccuracy: true, timeout: 10000 });
    };
    // Recherche d'adresse : une seule requête à Nominatim (OpenStreetMap), au clic uniquement.
    ML.actions['s-find'] = async (el) => {
      const f = el.closest('form');
      const zone = ML.zones[f.wilaya.value];
      const q = [f.address.value, f.city.value, zone ? zone.name : ''].filter(Boolean).join(', ');
      if (q.length < 3) { ML.toast('Saisissez d\'abord l\'adresse ou la ville.', 'bad'); return; }
      ML.busy(el, true);
      try {
        const res = await fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&accept-language=fr&q=' + encodeURIComponent(q));
        const hits = await res.json();
        if (hits && hits[0]) place(+hits[0].lat, +hits[0].lon, true);
        else if (zone) { map.setView([zone.lat, zone.lng], 12); ML.toast('Adresse introuvable : la carte est centrée sur la wilaya, placez l\'épingle à la main.'); } else ML.toast('Adresse introuvable. Placez l\'épingle à la main.', 'bad');
      } catch (e) {
        if (zone) map.setView([zone.lat, zone.lng], 12);
        ML.toast('Recherche indisponible. Placez l\'épingle à la main.', 'bad');
      }
      ML.busy(el, false);
    };
    ML.forms.store = async (f) => {
      const d = ML.formData(f);
      const btn = $('button[type=submit]', f);
      ML.busy(btn, true);
      const res = await E.send('store_save', {
        id: s.id, name: d.name, type: d.type, phone: d.phone, email: d.email, address: d.address, city: d.city, wilaya: d.wilaya,
        lat: pos.lat, lng: pos.lng, hours: { open: d.open, close: d.close, days: Array.from(days) },
        pickup: d.pickup, delivery: d.delivery, reserve: d.reserve, status: d.visible ? 'active' : 'closed',
      }, f);
      ML.busy(btn, false);
      if (!res) return;
      ML.toast('Point de vente enregistré.', 'good');
      ML.go('/points-de-vente');
    };
    ML.actions['s-del'] = async () => {
      if (!await ML.confirm('Supprimer « ' + s.name + ' » ? Il disparaît de la carte.', { ok: 'Supprimer', danger: true })) return;
      if (await E.send('store_delete', { id: s.id })) { ML.toast('Point de vente supprimé.', 'good'); ML.go('/points-de-vente'); }
    };
  }

  /* ========================================================= DISPONIBILITÉ */
  E.views.disponibilite = async (r) => {
    E.loading();
    const stores = (await api('dash_stores')).stores;
    if (!stores.length) {
      view().innerHTML = html`${E.head('Disponibilité')}<div class="card"><div class="empty">${icon('boxes')}<h3>Ajoutez d'abord un point de vente</h3><p>La disponibilité se renseigne magasin par magasin.</p><a class="btn btn-primary" href="#/points-de-vente/nouveau">${icon('plus')}Ajouter un point de vente</a></div></div>`;
      return;
    }
    let sid = stores.some((s) => String(s.id) === r.query.pdv) ? +r.query.pdv : stores[0].id;
    let products = [];
    const changes = new Map(); // id de déclinaison → quantité (null = retirée du magasin)
    const F = { q: '', show: 'all' };
    view().innerHTML = html`
      ${E.head('Disponibilité', 'Ce que les clients trouvent en stock, magasin par magasin.')}
      <div class="toolbar">
        <div class="toolbar-row">
          <select class="select" data-change="st-store" aria-label="Point de vente">${stores.map((s) => html`<option value="${s.id}" ${s.id === sid ? ML.raw('selected') : ''}>${s.name}</option>`)}</select>
          <div class="input-icon grow">${icon('search')}<input class="input" type="search" placeholder="Chercher un produit…" data-input="st-q" aria-label="Chercher un produit"></div>
        </div>
        <div class="chips" data-shows></div>
      </div>
      <div data-stock></div>
      <div class="savebar" data-savebar hidden><span data-nchanges></span><div class="row"><button class="btn btn-glass btn-sm" type="button" data-act="st-cancel">Annuler</button><button class="btn btn-primary btn-sm" type="button" data-act="st-save">${icon('check', 'ico-sm')}Enregistrer</button></div></div>`;

    const qtyOf = (v) => (changes.has(v.id) ? changes.get(v.id) : v.qty);
    const state = (q) => (q == null ? 'none' : q === 0 ? 'out' : q <= 2 ? 'low' : 'ok');
    const paintBar = () => {
      $('[data-savebar]').hidden = !changes.size;
      $('[data-nchanges]').textContent = fmt.plural(changes.size, 'modification') + ' non enregistrée' + (changes.size > 1 ? 's' : '');
    };
    const paint = () => {
      const q = ML.norm(F.q);
      const count = { all: 0, out: 0, low: 0, none: 0 };
      products.forEach((p) => p.variants.forEach((v) => { count.all++; const s = state(qtyOf(v)); if (count[s] != null) count[s]++; }));
      $('[data-shows]').innerHTML = html`${[['all', 'Tout'], ['out', 'En rupture'], ['low', 'Stock limité'], ['none', 'Non référencé']].map(([k, label]) => html`<button class="chip ${F.show === k ? 'is-on' : ''}" type="button" data-act="st-show" data-k="${k}">${label}<small>${count[k]}</small></button>`)}`;
      const list = products.map((p) => Object.assign({}, p, { rows: p.variants.filter((v) => F.show === 'all' || state(qtyOf(v)) === F.show) }))
        .filter((p) => p.rows.length && (!q || ML.norm(p.name + ' ' + p.brand).includes(q)));
      $('[data-stock]').innerHTML = !products.length ? html`<div class="card"><div class="empty">${icon('package')}<h3>Aucun produit à référencer</h3><p>${E.isBrand() ? 'Ajoutez d\'abord des produits à votre catalogue.' : 'Rejoignez le réseau d\'une marque pour référencer son catalogue, ou ajoutez vos propres produits.'}</p><a class="btn btn-primary" href="${E.isBrand() ? '#/catalogue/produit/nouveau' : '#/reseau'}">${E.isBrand() ? 'Ajouter un produit' : 'Voir les marques'}</a></div></div>`
        : !list.length ? html`<div class="card"><div class="empty">${icon('search-x')}<h3>Rien à afficher avec ce filtre</h3></div></div>`
          : html`${list.map((p) => html`
            <section class="card stock-card">
              <header class="stock-head">
                ${ML.visual({ name: p.name, category: p.category, image: p.image, brand: { color: p.color } }, { cls: 'pv-row' })}
                <span class="grow"><b>${p.name}</b><small class="muted">${p.brand}${p.own ? '' : ' · catalogue de ' + p.company}</small></span>
                <span class="stock-quick"><button class="btn btn-line btn-sm" type="button" data-act="st-all" data-p="${p.id}" data-q="10">Tout en stock</button><button class="btn btn-ghost btn-sm" type="button" data-act="st-all" data-p="${p.id}" data-q="0">Tout en rupture</button></span>
              </header>
              <ul class="stock-rows">${p.rows.map((v) => {
                const qv = qtyOf(v);
                const st = state(qv);
                return html`<li class="${changes.has(v.id) ? 'is-changed' : ''}">
                  <span class="grow stock-name">${v.color ? html`<i class="swatch" style="--c:${color(v.hex)}"></i>` : ''}<span>${[v.size, v.color].filter(Boolean).join(' · ') || 'Modèle unique'}</span>${v.sku ? html`<small class="dim">${v.sku}</small>` : ''}</span>
                  <span class="badge ${st === 'ok' ? 'badge-good' : st === 'low' ? 'badge-warn' : st === 'out' ? 'badge-bad' : ''}">${st === 'ok' ? 'En stock' : st === 'low' ? 'Limité' : st === 'out' ? 'Rupture' : 'Non référencé'}</span>
                  ${qv == null ? html`<button class="btn btn-soft btn-sm" type="button" data-act="st-set" data-v="${v.id}" data-q="0">${icon('plus', 'ico-sm')}Référencer</button>` : html`
                    <span class="qty"><button type="button" data-act="st-step" data-v="${v.id}" data-d="-1" aria-label="Moins" ${qv <= 0 ? ML.raw('disabled') : ''}>${icon('minus', 'ico-sm')}</button><input type="number" min="0" max="99999" inputmode="numeric" value="${qv}" data-input="st-qty" data-v="${v.id}" aria-label="Quantité en stock"><button type="button" data-act="st-step" data-v="${v.id}" data-d="1" aria-label="Plus">${icon('plus', 'ico-sm')}</button></span>
                    <button class="btn btn-ghost btn-icon btn-sm" type="button" data-act="st-set" data-v="${v.id}" data-q="" aria-label="Retirer du magasin" title="Retirer du magasin">${icon('x', 'ico-sm')}</button>`}
                </li>`;
              })}</ul>
            </section>`)}`;
      paintBar();
    };
    const find = (vid) => { for (const p of products) { const v = p.variants.find((x) => x.id === vid); if (v) return v; } return null; };
    const setQty = (vid, q) => {
      const v = find(vid);
      if (!v) return;
      if (q === v.qty) changes.delete(vid); else changes.set(vid, q);
    };
    const load = async () => {
      $('[data-stock]').innerHTML = '<div class="loading"><div class="spinner"></div></div>';
      products = (await api('stock', { params: { store: sid } })).products;
      changes.clear();
      paint();
    };
    ML.changes['st-store'] = async (el) => {
      if (changes.size && !await ML.confirm('Changer de point de vente sans enregistrer les modifications ?', { ok: 'Changer' })) { el.value = sid; return; }
      sid = +el.value; history.replaceState(null, '', ML.href('/disponibilite', { pdv: sid })); load();
    };
    ML.inputs['st-q'] = ML.debounce((el) => { F.q = el.value; paint(); }, 200);
    ML.actions['st-show'] = (el) => { F.show = el.dataset.k; paint(); };
    ML.actions['st-step'] = (el) => { const vid = +el.dataset.v; setQty(vid, Math.max(0, (qtyOf(find(vid)) || 0) + (+el.dataset.d))); paint(); };
    ML.actions['st-set'] = (el) => { setQty(+el.dataset.v, el.dataset.q === '' ? null : +el.dataset.q); paint(); };
    ML.actions['st-all'] = (el) => {
      const p = products.find((x) => x.id === +el.dataset.p);
      const target = +el.dataset.q;
      p.variants.forEach((v) => { const cur = qtyOf(v); setQty(v.id, target === 0 ? 0 : (cur == null || cur === 0 ? target : cur)); });
      paint();
    };
    // La quantité saisie au clavier ne redessine pas la liste (le champ garderait mal le curseur).
    ML.inputs['st-qty'] = (el) => {
      const n = el.value === '' ? 0 : Math.max(0, Math.min(99999, Math.round(+el.value) || 0));
      setQty(+el.dataset.v, n);
      el.closest('li').classList.toggle('is-changed', changes.has(+el.dataset.v));
      paintBar();
    };
    ML.actions['st-cancel'] = () => { changes.clear(); paint(); };
    ML.actions['st-save'] = async (el) => {
      ML.busy(el, true);
      const res = await E.send('stock_save', { store_id: sid, items: Array.from(changes, ([variant_id, qty]) => ({ variant_id, qty })) });
      ML.busy(el, false);
      if (!res) return;
      ML.toast(fmt.plural(res.saved, 'référence') + ' mise' + (res.saved > 1 ? 's' : '') + ' à jour.', 'good');
      load();
    };
    const guard = (e) => { if (changes.size) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', guard);
    E.cleanup = () => window.removeEventListener('beforeunload', guard);
    await load();
  };
})();
