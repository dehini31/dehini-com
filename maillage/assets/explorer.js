/*
 * Maillage — plateforme publique (dehini.com/maillage/explorer/) :
 *   #/carte                 carte interactive des points de vente (recherche, marque, type, distance)
 *   #/pdv/<id>              fiche d'un point de vente et produits disponibles sur place
 *   #/produits              catalogue (recherche, rayon, marque, taille, couleur, prix)
 *   #/produit/<adresse>     fiche produit et « où le trouver » ; commande ou réservation
 *   #/marques, #/marque/…   pages des marques
 *   #/suivi[/<jeton>]       suivi d'une commande
 */
(function () {
  'use strict';
  const { html, icon, fmt, color } = ML;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const TYPES = () => ({ magasin: ML.t('Magasin'), succursale: ML.t('Succursale'), distributeur: ML.t('Distributeur') });
  const S = { stores: null, storeById: {}, brandById: {}, brandBySlug: {}, cleanup: null, current: '', logged: {}, seq: 0 };
  class Stale extends Error {}
  /** Appel lié à la page affichée : abandonné si l'on a changé de page avant la réponse. */
  const api = async (action, opts) => {
    const my = S.seq;
    const res = await ML.api(action, opts);
    if (my !== S.seq) throw new Stale();
    return res;
  };

  /* ------------------------------------------------------------ données */
  async function loadStores() {
    if (!S.stores) {
      S.stores = (await api('stores')).stores;
      S.stores.forEach((s) => { S.storeById[s.id] = s; });
    }
    return S.stores;
  }
  const kmTo = (s) => (ML.loc ? ML.dist(ML.loc, s) : null);
  /** Compte une recherche une seule fois par visite (retour arrière, rechargement…). */
  const once = (key) => { if (S.logged[key]) return false; S.logged[key] = true; return true; };
  const hit = (kind, id) => { if (once('v' + kind + id)) api('event', { body: { kind, id } }).catch(() => {}); };

  /* -------------------------------------------------------------- cadre */
  function shell() {
    document.body.insertAdjacentHTML('afterbegin', html`
      <header class="topbar">
        <a class="logo-link" href="../" aria-label="Maillage, accueil">${ML.logo()}</a>
        <nav class="topnav" aria-label="Navigation principale">
          <a href="#/carte" data-nav="carte">${ML.t('Carte')}</a>
          <a href="#/produits" data-nav="produits">${ML.t('Produits')}</a>
          <a href="#/marques" data-nav="marques">${ML.t('Marques')}</a>
          <a href="#/suivi" data-nav="suivi">${ML.t('Suivi de commande')}</a>
        </nav>
        <button class="loc-chip" type="button" data-act="loc" aria-label="Changer de position">${icon('map-pin')}<span data-loc-label></span></button>
        ${ML.langToggle()}<a class="btn btn-line btn-sm pro-link" href="../espace/">${ML.t('Espace pro')}</a>
      </header>`);
    document.body.insertAdjacentHTML('beforeend', html`
      <nav class="tabbar" aria-label="Navigation">
        <a href="#/carte" data-nav="carte">${icon('map')}<span>${ML.t('Carte')}</span></a>
        <a href="#/produits" data-nav="produits">${icon('shopping-bag')}<span>${ML.t('Produits')}</span></a>
        <a href="#/marques" data-nav="marques">${icon('tag')}<span>${ML.t('Marques')}</span></a>
        <a href="#/suivi" data-nav="suivi">${icon('package-check')}<span>${ML.t('Suivi')}</span></a>
      </nav>`);
    paintLoc();
  }
  function paintLoc() {
    $$('[data-loc-label]').forEach((el) => { el.textContent = ML.loc ? ML.loc.label : ML.t('Ma ville'); });
    $$('.loc-chip').forEach((el) => el.classList.toggle('is-set', !!ML.loc));
  }
  ML.actions.loc = () => ML.pickLocation();
  const setNav = (name) => $$('[data-nav]').forEach((a) => a.classList.toggle('is-on', a.dataset.nav === name));
  const view = () => $('#view');
  const loading = () => { view().innerHTML = '<div class="loading"><div class="spinner"></div></div>'; };
  const notFound = (msg) => {
    view().innerHTML = html`<div class="page"><div class="empty">${icon('search-x')}<h3>${msg || ML.t('Page introuvable')}</h3><p>${ML.t('Le lien a peut-être changé.')}</p><a class="btn btn-dark" href="#/carte">${ML.t('Revenir à la carte')}</a></div></div>`;
  };

  /* ------------------------------------------------------------- routeur */
  async function route() {
    const r = ML.route();
    const name = r.parts[0] || 'carte';
    // Le catalogue se met à jour sans être reconstruit (le champ de recherche garde le curseur).
    if (name === 'produits' && S.current === 'produits' && S.catalogUpdate) { S.catalogUpdate(r.query); return; }
    const my = ++S.seq;
    ML.closeModals();
    if (S.cleanup) { try { S.cleanup(); } catch (e) { /* vue déjà retirée */ } S.cleanup = null; }
    S.catalogUpdate = null;
    S.current = name;
    document.body.classList.toggle('is-map', name === 'carte');
    window.scrollTo(0, 0);
    try {
      if (name === 'carte') { setNav('carte'); await mapView(r.query); }
      else if (name === 'pdv') { setNav('carte'); await storeView(+r.parts[1]); }
      else if (name === 'produits') { setNav('produits'); await catalogView(r.query); }
      else if (name === 'produit') { setNav('produits'); await productView(r.parts[1] || ''); }
      else if (name === 'marques') { setNav('marques'); await brandsView(); }
      else if (name === 'marque') { setNav('marques'); await brandView(r.parts[1] || ''); }
      else if (name === 'suivi') { setNav('suivi'); await trackView(r.parts[1] || ''); }
      else notFound();
    } catch (e) {
      if (my !== S.seq || e instanceof Stale) return;
      if (e.status === 404) notFound(e.message);
      else view().innerHTML = html`<div class="page"><div class="empty">${icon('wifi-off')}<h3>${ML.t('Chargement impossible')}</h3><p>${e.message}</p><button class="btn btn-dark" data-act="reload">${ML.t('Réessayer')}</button></div></div>`;
    }
  }
  ML.actions.reload = () => route();

  /* --------------------------------------------------- morceaux communs */
  const openBadge = (s) => {
    const st = ML.openState(s.hours);
    if (st.open === null) return '';
    return html`<span class="open ${st.open ? 'is-open' : 'is-closed'}"><i></i>${st.label}</span>`;
  };
  const typeBadge = (s) => html`<span class="badge badge-line"><span class="type-dot" style="--c:${color(ML.TYPE_COLORS[s.type])}"></span>${TYPES()[s.type] || s.type}</span>`;
  const demoBadge = (on) => (on ? html`<span class="badge" title="${ML.t('Donnée de démonstration')}">${ML.t('Démo')}</span>` : '');
  const services = (s) => html`
    ${s.pickup ? html`<span class="svc">${icon('shopping-bag')}${ML.t('Retrait')}</span>` : ''}
    ${s.reserve ? html`<span class="svc">${icon('bookmark-check')}${ML.t('Réservation')}</span>` : ''}
    ${s.delivery ? html`<span class="svc">${icon('truck')}${ML.t('Livraison')}</span>` : ''}`;
  const contactButtons = (s, small) => html`
    ${s.phone ? html`<a class="btn btn-line ${small ? 'btn-sm' : ''}" href="tel:${s.phone}" data-track="call" data-id="${s.id}">${icon('phone')}${ML.t('Appeler')}</a>` : ''}
    <a class="btn btn-line ${small ? 'btn-sm' : ''}" href="${ML.routeUrl(s)}" target="_blank" rel="noopener" data-track="route" data-id="${s.id}">${icon('navigation')}${ML.t('Itinéraire')}</a>`;
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-track]');
    if (t) api('event', { body: { kind: t.dataset.track, id: +t.dataset.id } }).catch(() => {});
  });

  const productCard = (p) => html`
    <a class="pcard" href="#/produit/${p.slug}">
      ${ML.visual(p)}
      <div class="pcard-body">
        <span class="pcard-brand">${p.brand.name}${p.demo ? html` <span class="badge">${ML.t('Démo')}</span>` : ''}</span>
        <h3>${p.name}</h3>
        <div class="pcard-row">
          <b class="price">${p.price_max > p.price ? ML.t('dès ') : ''}${fmt.price(p.price)}</b>
          ${p.colors.length ? html`<span class="swatches">${p.colors.slice(0, 5).map((c) => html`<i class="swatch" style="--c:${color(c.hex)}" title="${c.name}"></i>`)}${p.colors.length > 5 ? html`<small>+${p.colors.length - 5}</small>` : ''}</span>` : ''}
        </div>
        <span class="pcard-stock ${p.stores ? '' : 'is-out'}">${icon(p.stores ? 'store' : 'circle-slash')}${p.stores ? ML.t('Dans ') + fmt.plural(p.stores, 'point de vente', 'points de vente') : ML.t('Indisponible pour le moment')}</span>
      </div>
    </a>`;

  /* ================================================================ CARTE */
  async function mapView(query) {
    loading();
    const stores = await loadStores();
    const F = { q: '', brand: 0, type: '', open: false, delivery: false };
    if (query.marque && S.brandBySlug[query.marque]) F.brand = S.brandBySlug[query.marque].id;
    const brands = ML.boot.brands.filter((b) => b.stores > 0);

    view().innerHTML = html`
      <div class="mapview">
        <div class="mv-filters">
          <div class="mv-search">
            <div class="input-icon grow">${icon('search')}<input class="input" type="search" placeholder="${ML.t('Magasin, marque, ville…')}" autocomplete="off" data-input="mapq" aria-label="${ML.t('Chercher un point de vente')}"></div>
            <button class="btn btn-dark btn-icon" type="button" data-act="near" aria-label="${ML.t('Autour de moi')}" title="${ML.t('Autour de moi')}">${icon('locate-fixed')}</button>
          </div>
          <div class="chips" data-brands role="group" aria-label="Filtrer par marque"></div>
          <div class="chips" data-flags role="group" aria-label="Filtrer par type"></div>
        </div>
        <div class="mv-map">
          <div id="map" aria-label="${ML.t('Carte des points de vente')}"></div>
          <div class="map-legend">${Object.keys(TYPES()).map((tk) => html`<span><i style="--c:${ML.TYPE_COLORS[tk]}"></i>${TYPES()[tk]}</span>`)}</div>
        </div>
        <div class="mv-results">
          <p class="mv-count" data-count aria-live="polite"></p>
          <ul class="mv-list" data-list></ul>
        </div>
      </div>`;

    const map = ML.makeMap($('#map'));
    const cluster = ML.clusters().addTo(map);
    const markers = {};
    let meMarker = null;
    let shown = [];
    let active = 0;

    const chips = () => {
      $('[data-brands]').innerHTML = html`
        <button class="chip ${F.brand ? '' : 'is-on'}" type="button" data-act="mbrand" data-id="0">${ML.t('Toutes les marques')}</button>
        ${brands.map((b) => html`<button class="chip ${F.brand === b.id ? 'is-on' : ''}" type="button" data-act="mbrand" data-id="${b.id}"><span class="dot" style="--c:${color(b.color)}"></span>${b.name}</button>`)}`;
      $('[data-flags]').innerHTML = html`
        ${Object.keys(TYPES()).map((tk) => html`<button class="chip ${F.type === tk ? 'is-on' : ''}" type="button" data-act="mtype" data-id="${tk}"><span class="dot" style="--c:${ML.TYPE_COLORS[tk]}"></span>${TYPES()[tk]}</button>`)}
        <button class="chip ${F.open ? 'is-on' : ''}" type="button" data-act="mflag" data-id="open">${icon('clock', 'ico-sm')}${ML.t('Ouvert maintenant')}</button>
        <button class="chip ${F.delivery ? 'is-on' : ''}" type="button" data-act="mflag" data-id="delivery">${icon('truck', 'ico-sm')}${ML.t('Livraison')}</button>`;
    };

    const filter = () => {
      const q = ML.norm(F.q);
      const tokens = q.split(/\s+/).filter(Boolean);
      return stores.filter((s) => {
        if (F.brand && !s.brands.includes(F.brand)) return false;
        if (F.type && s.type !== F.type) return false;
        if (F.delivery && !s.delivery) return false;
        if (F.open && ML.openState(s.hours).open !== true) return false;
        if (tokens.length) {
          const hay = ML.norm([s.name, s.city, s.zone, ML.storeZone(s), s.address, s.company.name, s.brands.map((id) => (S.brandById[id] || {}).name).join(' ')].join(' '));
          if (!tokens.every((t) => hay.includes(t))) return false;
        }
        return true;
      }).map((s) => Object.assign({}, s, { km: kmTo(s) }))
        .sort((a, b) => (a.km != null && b.km != null ? a.km - b.km : a.city.localeCompare(b.city, 'fr') || a.name.localeCompare(b.name, 'fr')));
    };

    const card = (s) => html`
      <li class="scard ${active === s.id ? 'is-on' : ''}" data-store="${s.id}">
        <div class="scard-top">
          <div class="grow">
            <h3>${s.name}</h3>
            <p class="scard-addr">${s.address || s.city}${ML.storeZone(s) && ML.storeZone(s) !== s.city ? ', ' + ML.storeZone(s) : ''}</p>
          </div>
          ${s.km != null ? html`<span class="km">${fmt.km(s.km)}</span>` : ''}
        </div>
        <div class="scard-tags">${typeBadge(s)}${openBadge(s)}${demoBadge(s.company.demo)}</div>
        <div class="scard-brands">${s.brands.slice(0, 4).map((id) => (S.brandById[id] ? html`<span class="bchip"><i style="--c:${color(S.brandById[id].color)}"></i>${S.brandById[id].name}</span>` : ''))}</div>
        <div class="scard-actions">
          ${contactButtons(s, true)}
          <a class="btn btn-soft btn-sm" href="#/pdv/${s.id}">${ML.t('Voir')}${icon('arrow-right', 'ico-sm')}</a>
        </div>
      </li>`;

    const popup = (s) => html`<b>${s.name}</b><br><span class="muted">${s.address || s.city}</span><br>${openBadge(s)}<br><a class="link" href="#/pdv/${s.id}">${ML.t('Voir le point de vente')}</a>`.toString();

    const paint = (fit) => {
      shown = filter();
      cluster.clearLayers();
      Object.keys(markers).forEach((k) => delete markers[k]);
      const pinColor = F.brand && S.brandById[F.brand] ? S.brandById[F.brand].color : null;
      shown.forEach((s) => {
        const m = L.marker([s.lat, s.lng], { icon: ML.pin(pinColor || ML.TYPE_COLORS[s.type]), title: s.name, alt: s.name, keyboard: true });
        m.bindPopup(popup(s));
        m.on('click', () => select(s.id, false));
        markers[s.id] = m;
        cluster.addLayer(m);
      });
      const where = ML.loc ? ' · ' + ML.t('du plus proche au plus loin de') + ' ' + (ML.loc.precise ? ML.t('votre position') : ML.loc.label) : '';
      $('[data-count]').textContent = shown.length ? fmt.plural(shown.length, 'point de vente', 'points de vente') + where : '';
      $('[data-list]').innerHTML = shown.length ? html`${shown.slice(0, 60).map(card)}` : html`
        <li class="empty">${icon('map-pin-off')}<h3>${ML.t('Aucun point de vente ne correspond')}</h3><p>${ML.t('Essayez une autre ville ou retirez un filtre.')}</p><button class="btn btn-line btn-sm" data-act="mreset">${ML.t('Tout afficher')}</button></li>`;
      if (meMarker) { map.removeLayer(meMarker); meMarker = null; }
      if (ML.loc) meMarker = L.marker([ML.loc.lat, ML.loc.lng], { icon: ML.mePin(), interactive: false, keyboard: false, zIndexOffset: -100 }).addTo(map);
      if (fit) {
        if (ML.loc && shown.length) {
          // La position et les trois points de vente les plus proches.
          const b = L.latLngBounds([[ML.loc.lat, ML.loc.lng]]);
          shown.slice(0, 3).forEach((s) => b.extend([s.lat, s.lng]));
          map.fitBounds(b.pad(0.25), { maxZoom: 13, animate: false });
        } else if (shown.length) {
          map.fitBounds(L.latLngBounds(shown.map((s) => [s.lat, s.lng])).pad(0.12), { maxZoom: 13, animate: false });
        }
      }
    };

    const select = (id, fly) => {
      active = id;
      $$('.scard').forEach((el) => el.classList.toggle('is-on', +el.dataset.store === id));
      const m = markers[id];
      if (!m) return;
      if (fly) {
        cluster.zoomToShowLayer(m, () => m.openPopup());
        if (window.matchMedia('(max-width: 899px)').matches) $('.mv-map').scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        const el = $(`.scard[data-store="${id}"]`);
        if (el && window.matchMedia('(min-width: 900px)').matches) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      }
      hit('store', id);
    };

    const logBrand = () => {
      if (!F.brand) return;
      const key = 'm' + F.brand + (ML.loc ? ML.loc.zone : '');
      if (!once(key)) return;
      api('log_map', { body: Object.assign({ brand: F.brand, results: shown.length, nearest_km: shown.length && shown[0].km != null ? shown[0].km : null }, ML.locParams()) }).catch(() => {});
    };

    ML.inputs.mapq = ML.debounce((el) => { F.q = el.value; paint(true); }, 220);
    ML.actions.mbrand = (el) => { F.brand = +el.dataset.id; chips(); paint(true); logBrand(); history.replaceState(null, '', ML.href('/carte', { marque: F.brand ? S.brandById[F.brand].slug : '' })); };
    ML.actions.mtype = (el) => { F.type = F.type === el.dataset.id ? '' : el.dataset.id; chips(); paint(true); };
    ML.actions.mflag = (el) => { F[el.dataset.id] = !F[el.dataset.id]; chips(); paint(true); };
    ML.actions.mreset = () => { F.q = ''; F.brand = 0; F.type = ''; F.open = false; F.delivery = false; $('[data-input="mapq"]').value = ''; chips(); paint(true); };
    ML.actions.near = async (el) => {
      ML.busy(el, true);
      try { await ML.locate(); } catch (e) { ML.toast(e.message, 'bad'); ML.pickLocation(); }
      ML.busy(el, false);
    };
    const onList = (e) => {
      const li = e.target.closest('.scard');
      if (li && !e.target.closest('a, button')) select(+li.dataset.store, true);
    };
    $('[data-list]').addEventListener('click', onList);
    const onLoc = () => { paintLoc(); paint(true); logBrand(); };
    document.addEventListener('mlg:loc', onLoc);
    S.cleanup = () => { document.removeEventListener('mlg:loc', onLoc); map.remove(); };

    chips();
    paint(true);
    logBrand();
    setTimeout(() => map.invalidateSize(), 60);
  }

  /* ====================================================== POINT DE VENTE */
  async function storeView(id) {
    loading();
    await loadStores();
    const s = S.storeById[id];
    if (!s) { notFound(ML.t('Ce point de vente n\'est plus en ligne')); return; }
    hit('store', id);
    const km = kmTo(s);
    view().innerHTML = html`
      <div class="page">
        <a class="back" href="#/carte">${icon('arrow-left')}${ML.t('Carte des points de vente')}</a>
        <div class="store-top">
          <div class="store-main card card-pad">
            <div class="scard-tags">${typeBadge(s)}${openBadge(s)}${demoBadge(s.company.demo)}</div>
            <h1>${s.name}</h1>
            <p class="muted">${s.company.name}</p>
            <ul class="facts">
              <li>${icon('map-pin')}<span>${s.address}${ML.storeZone(s) ? html`<br><span class="muted">${s.city}${ML.storeZone(s) !== s.city ? ' · ' + ML.storeZone(s) : ''}</span>` : ''}${km != null ? html`<br><b>${ML.t('à')} ${fmt.km(km)}</b>` : ''}</span></li>
              ${s.phone ? html`<li>${icon('phone')}<a class="link" href="tel:${s.phone}" data-track="call" data-id="${s.id}">${s.phone}</a></li>` : ''}
              ${ML.hoursText(s.hours) ? html`<li>${icon('clock')}<span>${ML.hoursText(s.hours)}</span></li>` : ''}
              <li>${icon('check')}<span class="svcs">${services(s)}</span></li>
            </ul>
            <div class="row row-wrap">${contactButtons(s)}</div>
            ${s.brands.length ? html`<div class="store-brands"><span class="label">${ML.t('Marques sur place')}</span><div class="chips wrap">${s.brands.map((b) => (S.brandById[b] ? html`<a class="chip" href="#/marque/${S.brandById[b].slug}"><span class="dot" style="--c:${color(S.brandById[b].color)}"></span>${S.brandById[b].name}</a>` : ''))}</div></div>` : ''}
          </div>
          <div class="store-map card"><div id="map"></div></div>
        </div>
        <section class="section">
          <div class="section-head">
            <h2>${ML.t('Disponible dans ce point de vente')}</h2>
            <form class="input-icon" data-form="storeq" role="search">${icon('search')}<input class="input" type="search" name="q" placeholder="${ML.t('Chercher un produit ici…')}" autocomplete="off" aria-label="${ML.t('Chercher un produit dans ce point de vente')}"></form>
          </div>
          <div data-grid><div class="loading"><div class="spinner"></div></div></div>
        </section>
      </div>`;
    const map = ML.makeMap($('#map'), { center: [s.lat, s.lng], zoom: 14, wheel: false });
    L.marker([s.lat, s.lng], { icon: ML.pin(ML.TYPE_COLORS[s.type]), title: s.name }).addTo(map);
    if (ML.loc) L.marker([ML.loc.lat, ML.loc.lng], { icon: ML.mePin(), interactive: false }).addTo(map);
    S.cleanup = () => map.remove();
    const load = async (q) => {
      const res = await api('products', { params: { store: id, q, instock: 1 } });
      $('[data-grid]').innerHTML = res.items.length ? html`<div class="pgrid">${res.items.map(productCard)}</div>` : html`<div class="empty">${icon('package-search')}<h3>${q ? ML.t('Aucun produit ne correspond') : ML.t('Pas encore de produit en stock ici')}</h3><p>${q ? ML.t('Essayez un autre mot.') : ML.t('Appelez le point de vente pour connaître son assortiment.')}</p></div>`;
    };
    ML.forms.storeq = (f) => load(f.q.value.trim());
    await load('');
  }

  /* ============================================================ CATALOGUE */
  const SORTS = () => ({ '': ML.t('Pertinence'), new: ML.t('Nouveautés'), price_asc: ML.t('Prix croissant'), price_desc: ML.t('Prix décroissant') });
  async function catalogView(query) {
    view().innerHTML = html`
      <div class="page catalog">
        <div class="cat-hero">
          <h1>${ML.t('Trouvez un produit')} <em>${ML.t('près de chez vous')}</em></h1>
          <form class="cat-search" data-form="catq" role="search">
            <div class="input-icon grow">${icon('search')}<input class="input" type="search" name="q" placeholder="${ML.t('Chaussure de running, bouilloire, parure de lit…')}" autocomplete="off" data-input="catq" aria-label="${ML.t('Chercher un produit')}"></div>
            <button class="btn btn-primary" type="submit">${ML.t('Chercher')}</button>
          </form>
        </div>
        <div class="cat-body">
          <aside class="facets" data-facets aria-label="${ML.t('Filtres')}"></aside>
          <section class="cat-main">
            <div class="cat-bar">
              <p class="cat-count" data-count aria-live="polite"></p>
              <div class="row">
                <button class="btn btn-line btn-sm cat-filter-btn" type="button" data-act="facets">${icon('sliders-horizontal', 'ico-sm')}${ML.t('Filtres')}<span data-nfilters></span></button>
                <select class="select input-sm" data-change="sort" aria-label="${ML.t('Trier')}">${Object.keys(SORTS()).map((k) => html`<option value="${k}">${SORTS()[k]}</option>`)}</select>
              </div>
            </div>
            <div class="chips wrap" data-active></div>
            <div data-grid></div>
          </section>
        </div>
      </div>`;
    let Q = {};
    let facets = null;
    let seq = 0;
    const setQ = (patch, replace) => {
      const next = Object.assign({}, Q, patch, patch.page ? {} : { page: '' });
      ML.go('/produits', next, replace);
    };
    const facetHtml = () => {
      if (!facets) return '';
      const group = (title, body) => html`<div class="facet"><h3>${title}</h3>${body}</div>`;
      return html`
        <div class="facets-head"><b>${ML.t('Filtres')}</b><button class="btn btn-ghost btn-icon btn-sm" type="button" data-act="facets-close" aria-label="${ML.t('Fermer les filtres')}">${icon('x')}</button></div>
        ${group(ML.t('Rayon'), html`<div class="facet-list">${facets.categories.map((c) => html`<button class="facet-opt ${Q.cat === c.key ? 'is-on' : ''}" type="button" data-act="facet" data-k="cat" data-v="${c.key}"><span>${ML.cats[c.key] || c.label}</span><small>${c.n}</small></button>`)}</div>`)}
        ${facets.brands.length > 1 || Q.marque ? group(ML.t('Marque'), html`<div class="facet-list">${facets.brands.map((b) => html`<button class="facet-opt ${Q.marque === String(b.id) ? 'is-on' : ''}" type="button" data-act="facet" data-k="marque" data-v="${b.id}"><span>${b.name}</span><small>${b.n}</small></button>`)}</div>`) : ''}
        ${facets.sizes.length ? group(ML.t('Taille'), html`<div class="chips wrap">${facets.sizes.map((s) => html`<button class="chip ${ML.norm(Q.taille) === ML.norm(s.label) ? 'is-on' : ''}" type="button" data-act="facet" data-k="taille" data-v="${s.label}">${s.label}</button>`)}</div>`) : ''}
        ${facets.colors.length ? group(ML.t('Couleur'), html`<div class="chips wrap">${facets.colors.map((c) => html`<button class="chip ${ML.norm(Q.couleur) === ML.norm(c.label) ? 'is-on' : ''}" type="button" data-act="facet" data-k="couleur" data-v="${c.label}"><i class="swatch" style="--c:${color(c.hex)}"></i>${c.label}</button>`)}</div>`) : ''}
        ${group(ML.t('Prix') + ' (' + ML.boot.config.currency + ')', html`<form class="price-range" data-form="price"><input class="input input-sm" type="number" name="pmin" min="0" inputmode="numeric" placeholder="${ML.lang === 'ar' ? 'حد أدنى' : 'Min'}" value="${Q.pmin || ''}" aria-label="${ML.lang === 'ar' ? 'الحد الأدنى' : 'Prix minimum'}"><span>–</span><input class="input input-sm" type="number" name="pmax" min="0" inputmode="numeric" placeholder="${ML.lang === 'ar' ? 'حد أقصى' : 'Max'}" value="${Q.pmax || ''}" aria-label="${ML.lang === 'ar' ? 'الحد الأقصى' : 'Prix maximum'}"><button class="btn btn-line btn-sm" type="submit">OK</button></form>`)}
        <label class="switch facet-switch"><input type="checkbox" data-change="stock" ${Q.stock ? ML.raw('checked') : ''}><i></i>${ML.t('En stock uniquement')}</label>
        <button class="btn btn-dark btn-block facets-apply" type="button" data-act="facets-close">${ML.t('Voir les résultats')}</button>`;
    };
    const activeChips = () => {
      const a = [];
      if (Q.cat) a.push(['cat', ML.cats[Q.cat] || Q.cat]);
      if (Q.marque && S.brandById[Q.marque]) a.push(['marque', S.brandById[Q.marque].name]);
      if (Q.taille) a.push(['taille', ML.t('Taille') + ' ' + Q.taille]);
      if (Q.couleur) a.push(['couleur', Q.couleur]);
      if (Q.pmin || Q.pmax) a.push(['prix', (Q.pmin || '0') + ' – ' + (Q.pmax || '…') + ' ' + ML.boot.config.currency]);
      if (Q.stock) a.push(['stock', ML.t('En stock')]);
      $('[data-nfilters]').textContent = a.length ? ' · ' + a.length : '';
      return a.length ? html`${a.map(([k, label]) => html`<button class="chip is-on" type="button" data-act="unfacet" data-k="${k}">${label}${icon('x', 'ico-sm')}</button>`)}<button class="chip" type="button" data-act="unfacet" data-k="all">${ML.t('Tout effacer')}</button>` : '';
    };
    const update = async (query, log) => {
      Q = query;
      const my = ++seq;
      const input = $('[data-input="catq"]');
      if (input && document.activeElement !== input) input.value = Q.q || '';
      $('[data-change="sort"]').value = Q.tri || '';
      const grid = $('[data-grid]');
      grid.classList.add('is-loading');
      const sig = JSON.stringify([Q.q, Q.cat, Q.marque, Q.taille, Q.couleur]);
      const res = await api('products', { params: Object.assign({
        q: Q.q, category: Q.cat, brand: Q.marque, size: Q.taille, color: Q.couleur, pmin: Q.pmin, pmax: Q.pmax, instock: Q.stock ? 1 : '', sort: Q.tri, page: Q.page,
        log: (Q.q || Q.taille || Q.couleur) && once('c' + sig) ? 1 : '',
      }, ML.locParams()) });
      if (my !== seq) return;
      facets = res.facets;
      grid.classList.remove('is-loading');
      $('[data-facets]').innerHTML = facetHtml();
      $('[data-active]').innerHTML = activeChips();
      $('[data-count]').textContent = res.total ? fmt.plural(res.total, 'produit') + (Q.q ? ' ' + ML.t('pour « ') + Q.q + ML.t(' »') : '') : '';
      if (!res.items.length) {
        grid.innerHTML = html`<div class="empty">${icon('package-search')}<h3>${ML.t("Aucun produit ne correspond à « ") + Q.q + ML.t(" »")}</h3><p>${ML.t('Vérifiez l\'orthographe, essayez un mot plus général ou retirez un filtre. Votre recherche est transmise aux marques : elle les aide à étoffer leur offre.')}</p><button class="btn btn-line btn-sm" data-act="unfacet" data-k="all">${ML.t('Tout effacer')}</button></div>`;
        return;
      }
      grid.innerHTML = html`
        <div class="pgrid">${res.items.map(productCard)}</div>
        ${res.pages > 1 ? html`<nav class="pager" aria-label="Pages">
          <button class="btn btn-line btn-sm" type="button" data-act="page" data-p="${res.page - 1}" ${res.page <= 1 ? ML.raw('disabled') : ''}>${icon('arrow-left', 'ico-sm')}${ML.t('Précédent')}</button>
          <span class="muted">${ML.t('Page')} ${res.page} ${ML.t('sur')} ${res.pages}</span>
          <button class="btn btn-line btn-sm" type="button" data-act="page" data-p="${res.page + 1}" ${res.page >= res.pages ? ML.raw('disabled') : ''}>${ML.t('Suivant')}${icon('arrow-right', 'ico-sm')}</button>
        </nav>` : ''}`;
    };
    ML.inputs.catq = ML.debounce((el) => setQ({ q: el.value.trim() }, true), 450);
    ML.forms.catq = (f) => { setQ({ q: f.q.value.trim() }, true); f.q.blur(); };
    ML.forms.price = (f) => { setQ({ pmin: f.pmin.value, pmax: f.pmax.value }); closeFacets(); };
    ML.changes.sort = (el) => setQ({ tri: el.value });
    ML.changes.stock = (el) => setQ({ stock: el.checked ? 1 : '' });
    ML.actions.facet = (el) => { const k = el.dataset.k; setQ({ [k]: ML.norm(Q[k]) === ML.norm(el.dataset.v) ? '' : el.dataset.v }); };
    ML.actions.unfacet = (el) => {
      const k = el.dataset.k;
      if (k === 'all') ML.go('/produits', {});
      else if (k === 'prix') setQ({ pmin: '', pmax: '' });
      else setQ({ [k]: '' });
    };
    ML.actions.page = (el) => { setQ({ page: el.dataset.p }); window.scrollTo({ top: 0, behavior: 'smooth' }); };
    const closeFacets = () => document.body.classList.remove('facets-open');
    ML.actions.facets = () => document.body.classList.toggle('facets-open');
    ML.actions['facets-close'] = closeFacets;
    S.catalogUpdate = (q) => { closeFacets(); update(q).catch((e) => { if (!(e instanceof Stale)) ML.fail(e); }); };
    S.cleanup = closeFacets;
    await update(query);
  }

  /* ============================================================== PRODUIT */
  async function productView(slug) {
    loading();
    const p = (await api('product', { params: { slug } })).product;
    hit('product', p.id);
    const sizes = p.sizes;
    const colors = p.colors;
    const sel = { size: sizes.length === 1 ? sizes[0] : '', color: colors.length === 1 ? colors[0].name : '' };
    let found = [];
    let map = null;
    let layer = null;
    let seq = 0;
    let shot = 0;

    const tint = () => { const c = colors.find((x) => x.name === sel.color); return c ? c.hex : ''; };
    const priceNow = () => {
      const vs = p.variants.filter((v) => (!sel.size || v.size === sel.size) && (!sel.color || v.color === sel.color));
      const list = (vs.length ? vs : p.variants).map((v) => v.price);
      const lo = Math.min(...list); const hi = Math.max(...list);
      return (hi > lo ? ML.t('dès ') : '') + fmt.price(lo);
    };

    view().innerHTML = html`
      <div class="page product">
        <nav class="crumbs" aria-label="Fil d'Ariane"><a href="#/produits">${ML.t('Produits')}</a>${icon('chevron-right', 'ico-sm')}<a href="${ML.href('/produits', { cat: p.category })}">${ML.cats[p.category] || ML.t('Rayon')}</a>${icon('chevron-right', 'ico-sm')}<span>${p.name}</span></nav>
        <div class="p-top">
          <div class="p-gallery">
            <div data-visual></div>
            ${p.images.length > 1 ? html`<div class="p-thumbs">${p.images.map((im, i) => html`<button type="button" class="${i === 0 ? 'is-on' : ''}" data-act="shot" data-i="${i}" aria-label="Photo ${i + 1}"><img src="${ML.media(im, 480)}" alt="" loading="lazy"></button>`)}</div>` : ''}
          </div>
          <div class="p-info">
            <a class="p-brand" href="#/marque/${p.brand.slug}">${ML.mono(p.brand.name, p.brand.color, p.brand.logo, 'mono-sm')}<span>${p.brand.name}</span>${demoBadge(p.demo)}</a>
            <h1>${p.name}</h1>
            <p class="p-price" data-price></p>
            ${colors.length ? html`<div class="opt"><span class="label">${ML.t('Couleur')} <b data-color-name></b></span><div class="opt-row" data-colors></div></div>` : ''}
            ${sizes.length ? html`<div class="opt"><span class="label">${ML.t('Taille')}</span><div class="opt-row" data-sizes></div></div>` : ''}
            <div class="p-ctas">
              <button class="btn btn-primary btn-lg" type="button" data-act="cod">${icon('truck')}${ML.t('Commander · livraison à domicile')}</button>
              <a class="btn btn-line btn-lg" href="#ou">${icon('map-pin')}${ML.t('Voir où le trouver')}</a>
            </div>
            <p class="p-cod-note">${icon('shield-check', 'ico-sm')}${ML.t('Paiement à la livraison · le point de vente le plus proche de chez vous vous livre')}</p>
            ${p.about ? html`<p class="p-about">${p.about}</p>` : ''}
            ${p.specs.length ? html`<dl class="specs">${p.specs.map((s) => html`<div><dt>${s.k}</dt><dd>${s.v}</dd></div>`)}</dl>` : ''}
          </div>
        </div>
        <section class="section" id="ou">
          <div class="section-head">
            <div><h2>${ML.t('Où le trouver')}</h2><p class="muted" data-avail-sub></p></div>
            <button class="loc-chip is-big" type="button" data-act="loc">${icon('map-pin')}<span data-loc-label></span>${icon('chevron-down', 'ico-sm')}</button>
          </div>
          <div class="avail">
            <ul class="av-list" data-avail></ul>
            <div class="av-map card"><div id="map"></div></div>
          </div>
        </section>
      </div>`;
    paintLoc();

    const paintOptions = () => {
      $('[data-visual]').innerHTML = ML.visual(p, { image: p.images[shot] || '', tint: tint(), w: 1200, cls: 'pv-big' });
      $('[data-price]').textContent = priceNow();
      const has = (size, col) => p.variants.some((v) => (!size || v.size === size) && (!col || v.color === col));
      if (colors.length) {
        $('[data-color-name]').textContent = sel.color ? '· ' + sel.color : '';
        $('[data-colors]').innerHTML = html`${colors.map((c) => html`<button type="button" class="copt ${sel.color === c.name ? 'is-on' : ''} ${has(sel.size, c.name) ? '' : 'is-off'}" data-act="pcolor" data-v="${c.name}" title="${c.name}" aria-label="${c.name}" aria-pressed="${sel.color === c.name}"><i style="--c:${color(c.hex)}"></i></button>`)}`;
      }
      if (sizes.length) {
        $('[data-sizes]').innerHTML = html`${sizes.map((s) => html`<button type="button" class="chip ${sel.size === s ? 'is-on' : ''} ${has(s, sel.color) ? '' : 'is-off'}" data-act="psize" data-v="${s}" aria-pressed="${sel.size === s}">${s}</button>`)}`;
      }
    };

    const stockLine = (st) => {
      const vs = st.variants;
      if (vs.length === 1) {
        const v = vs[0];
        const opt = [v.size, v.color].filter(Boolean).join(' · ');
        return html`<span class="stock ${v.low ? 'is-low' : 'is-ok'}">${icon(v.low ? 'triangle-alert' : 'circle-check', 'ico-sm')}${v.low ? (v.left === 1 ? ML.t('Dernière pièce') : ML.t('Plus que') + ' ' + v.left) : ML.t('En stock')}${opt ? ' · ' + opt : ''}</span>`;
      }
      const sz = Array.from(new Set(vs.map((v) => v.size).filter(Boolean)));
      const cl = Array.from(new Set(vs.map((v) => v.color).filter(Boolean)));
      return html`<span class="stock is-ok">${icon('circle-check', 'ico-sm')}${ML.t('En stock')}</span>
        ${sz.length && !sel.size ? html`<span class="av-opts">${ML.t('Tailles :')} ${sz.join(', ')}</span>` : ''}
        ${cl.length && !sel.color ? html`<span class="av-opts">${ML.t('Couleurs :')} ${cl.join(', ')}</span>` : ''}`;
    };

    const paintAvail = () => {
      const what = [sel.size && ML.t('taille') + ' ' + sel.size, sel.color && sel.color.toLowerCase()].filter(Boolean).join(', ');
      $('[data-avail-sub]').textContent = found.length
        ? fmt.plural(found.length, 'point de vente', 'points de vente') + (what ? ' ' + ML.t('pour :') + ' ' + what : '') + (ML.loc ? ' · ' + ML.t('du plus proche au plus loin') : '')
        : '';
      $('[data-avail]').innerHTML = found.length ? html`${found.map((st) => html`
        <li class="av-item" data-store="${st.id}">
          <div class="av-main">
            <div class="row between"><h3>${st.name}</h3>${st.km != null ? html`<span class="km">${fmt.km(st.km)}</span>` : ''}</div>
            <p class="scard-addr">${st.address || st.city}</p>
            <div class="scard-tags">${openBadge(st)}${stockLine(st)}</div>
          </div>
          <div class="av-actions">
            ${st.reserve ? html`<button class="btn btn-soft btn-sm" type="button" data-act="order" data-store="${st.id}" data-kind="reservation">${icon('bookmark-check', 'ico-sm')}${ML.t('Réserver')}</button>` : ''}
            ${st.pickup || st.delivery ? html`<button class="btn btn-primary btn-sm" type="button" data-act="order" data-store="${st.id}" data-kind="order">${icon('shopping-bag', 'ico-sm')}${ML.t('Commander')}</button>` : ''}
            <a class="btn btn-ghost btn-sm" href="#/pdv/${st.id}" aria-label="${ML.t('Voir')} ${st.name}">${icon('arrow-right', 'ico-sm')}</a>
          </div>
        </li>`)}` : html`
        <li class="empty">${icon('map-pin-off')}<h3>${ML.t('Indisponible')}${what ? ML.t(' dans cette déclinaison') : ML.t(' pour le moment')}</h3>
          <p>${what ? ML.t("Aucun point de vente n\'a cette taille ou cette couleur en stock. Essayez une autre déclinaison.") : ML.t("Aucun point de vente n\'a ce produit en stock aujourd\'hui.")}</p>
          ${what ? html`<button class="btn btn-line btn-sm" data-act="pclear">${ML.t('Voir toutes les déclinaisons')}</button>` : ''}</li>`;
      if (!map) return;
      layer.clearLayers();
      const pts = [];
      found.forEach((st) => {
        const m = L.marker([st.lat, st.lng], { icon: ML.pin(p.brand.color || ML.TYPE_COLORS[st.type]), title: st.name });
        m.bindPopup(html`<b>${st.name}</b><br><span class="muted">${st.address || st.city}</span>${st.km != null ? html`<br>${ML.t('à')} ${fmt.km(st.km)}` : ''}`.toString());
        layer.addLayer(m);
        pts.push([st.lat, st.lng]);
      });
      if (ML.loc) { L.marker([ML.loc.lat, ML.loc.lng], { icon: ML.mePin(), interactive: false }).addTo(layer); }
      if (pts.length) {
        const near = ML.loc ? [[ML.loc.lat, ML.loc.lng]].concat(pts.slice(0, 3)) : pts;
        map.fitBounds(L.latLngBounds(near).pad(0.25), { maxZoom: 13, animate: false });
      }
    };

    const loadAvail = async () => {
      const my = ++seq;
      const sig = 'a' + p.id + '|' + sel.size + '|' + sel.color + '|' + (ML.loc ? ML.loc.zone : '');
      const res = await api('availability', { params: { product: p.id, size: sel.size, color: sel.color, lat: ML.loc ? ML.loc.lat : '', lng: ML.loc ? ML.loc.lng : '', zone: ML.loc ? ML.loc.zone : '', log: once(sig) ? 1 : '' } });
      if (my !== seq) return;
      found = res.stores;
      paintAvail();
    };

    ML.actions.pcolor = (el) => { sel.color = sel.color === el.dataset.v && colors.length > 1 ? '' : el.dataset.v; paintOptions(); loadAvail(); };
    ML.actions.psize = (el) => { sel.size = sel.size === el.dataset.v && sizes.length > 1 ? '' : el.dataset.v; paintOptions(); loadAvail(); };
    ML.actions.pclear = () => { if (sizes.length > 1) sel.size = ''; if (colors.length > 1) sel.color = ''; paintOptions(); loadAvail(); };
    ML.actions.shot = (el) => { shot = +el.dataset.i; $$('.p-thumbs button').forEach((b, i) => b.classList.toggle('is-on', i === shot)); paintOptions(); };
    ML.actions.order = (el) => {
      const st = found.find((x) => x.id === +el.dataset.store);
      if (st) orderSheet(p, st, el.dataset.kind, () => loadAvail());
    };
    ML.actions.cod = () => codSheet(p, sel);
    const onLoc = () => { paintLoc(); loadAvail(); };
    document.addEventListener('mlg:loc', onLoc);

    map = ML.makeMap($('#map'), { wheel: false });
    layer = L.layerGroup().addTo(map);
    S.cleanup = () => { document.removeEventListener('mlg:loc', onLoc); map.remove(); };
    paintOptions();
    await loadAvail();
    setTimeout(() => map.invalidateSize(), 60);
  }

  /* ============================================ COMMANDE / RÉSERVATION */
  function orderSheet(p, st, kind, onDone) {
    const me = ML.ls.get('me', {});
    const modes = [];
    if (st.reserve) modes.push(['reservation', ML.t('Réserver'), ML.t('Mis de côté') + ' ' + ML.boot.config.reservation_hours + ' ' + ML.t('h · paiement en magasin'), 'bookmark-check']);
    if (st.pickup) modes.push(['pickup', ML.t('Commander · retrait en magasin'), ML.t('Paiement au retrait'), 'shopping-bag']);
    if (st.delivery) modes.push(['delivery', ML.t('Commander · livraison'), ML.t('Paiement à la livraison'), 'truck']);
    let mode = kind === 'reservation' && st.reserve ? 'reservation' : (st.pickup ? 'pickup' : (st.delivery ? 'delivery' : modes[0][0]));
    const vlabel = (v) => [v.size, v.color].filter(Boolean).join(' · ') || ML.t('Modèle unique');

    const m = ML.modal({
      title: ML.t('Votre demande'),
      body: html`
        <form class="order-form" data-form="order" novalidate>
          <div class="order-sum">
            ${ML.visual(p, { cls: 'pv-xs' })}
            <div class="grow"><b>${p.name}</b><br><span class="muted">${st.name} · ${st.city}</span></div>
          </div>
          <div class="form-grid cols-2">
            <div class="field">
              <label for="o-variant">${ML.t('Déclinaison')}</label>
              <select class="select" id="o-variant" name="variant">${st.variants.map((v) => html`<option value="${v.id}">${vlabel(v)}${v.low ? ' — ' + ML.t('plus que') + ' ' + v.left : ''}</option>`)}</select>
            </div>
            <div class="field">
              <span class="label" id="o-qty-label">${ML.t('Quantité')}</span>
              <div class="stepper" role="group" aria-labelledby="o-qty-label">
                <button type="button" data-step="-1" aria-label="${ML.t('Moins')}">${icon('minus')}</button>
                <output name="qtyout" aria-live="polite">1</output>
                <button type="button" data-step="1" aria-label="${ML.t('Plus')}">${icon('plus')}</button>
              </div>
            </div>
          </div>
          <fieldset class="modes"><legend class="label">${ML.t('Comment le récupérer ?')}</legend>
            ${modes.map(([k, t, d, ic]) => html`<label class="mode"><input type="radio" name="mode" value="${k}" ${k === mode ? ML.raw('checked') : ''}><span class="mode-box">${icon(ic)}<span><b>${t}</b><small>${d}</small></span></span></label>`)}
          </fieldset>
          <div class="form-grid cols-2">
            <div class="field"><label for="o-name">${ML.t('Nom et prénom')}</label><input class="input" id="o-name" name="name" autocomplete="name" required value="${me.name || ''}"></div>
            <div class="field"><label for="o-phone">${ML.t('Téléphone')}</label><input class="input" id="o-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required placeholder="05 00 00 00 00" value="${me.phone || ''}"></div>
            <div class="field span-2"><label for="o-email">${ML.t('Email')} <span class="dim">(${ML.t('facultatif, pour recevoir le suivi')})</span></label><input class="input" id="o-email" name="email" type="email" autocomplete="email" value="${me.email || ''}"></div>
          </div>
          <div class="form-grid cols-2" data-delivery hidden>
            ${ML.placeFields({ prefix: 'o', zone: me.zone || (ML.loc && ML.loc.zone) || '', addressLabel: ML.t('Adresse de livraison'), addressValue: me.address || '' })}
          </div>
          <div class="field"><label for="o-note">${ML.t('Message au point de vente')} <span class="dim">(${ML.t('facultatif')})</span></label><textarea class="textarea" id="o-note" name="note" rows="2" maxlength="400"></textarea></div>
          <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
          <div class="order-total"><span>${ML.t('Total')}</span><b data-total></b></div>
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit" data-submit></button>
          <p class="order-legal">${ML.t('Aucun paiement en ligne. Le point de vente vous confirme la demande par téléphone.')}</p>
        </form>`,
    });
    const form = $('form', m.el);
    let qty = 1;
    const variant = () => st.variants.find((v) => v.id === +form.variant.value) || st.variants[0];
    const paint = () => {
      const v = variant();
      qty = Math.max(1, Math.min(qty, v.max));
      form.qtyout.value = qty;
      $('[data-total]', form).textContent = fmt.price(v.price * qty);
      $('[data-delivery]', form).hidden = mode !== 'delivery';
      $('[data-submit]', form).textContent = mode === 'reservation' ? ML.t('Confirmer la réservation') : ML.t('Confirmer la commande');
      $('[data-step="-1"]', form).disabled = qty <= 1;
      $('[data-step="1"]', form).disabled = qty >= v.max;
    };
    form.addEventListener('click', (e) => { const b = e.target.closest('[data-step]'); if (b) { qty += +b.dataset.step; paint(); } });
    form.addEventListener('change', (e) => { if (e.target.name === 'mode') mode = e.target.value; paint(); });
    ML.bindPlace(form, me.commune || '');
    paint();

    ML.forms.order = async (f) => {
      const d = ML.formData(f);
      if (mode === 'delivery' && (!d.zone || !d.commune)) { ML.fail({ message: ML.t('Choisissez la wilaya puis la commune de livraison.') }, f); return; }
      const btn = $('[data-submit]', f);
      ML.busy(btn, true);
      try {
        const res = await api('order', { body: {
          store_id: st.id, kind: mode === 'reservation' ? 'reservation' : 'order', mode: mode === 'delivery' ? 'delivery' : 'pickup',
          items: [{ variant_id: +d.variant, qty }], name: d.name, phone: d.phone, email: d.email, address: d.address, commune: d.commune, zone: d.zone, note: d.note, website: d.website,
        } });
        const keep = mode === 'delivery' ? { zone: d.zone, commune: d.commune, address: d.address } : {};
        ML.ls.set('me', Object.assign({}, ML.ls.get('me', {}), { name: d.name, phone: d.phone, email: d.email }, keep));
        const mine = ML.ls.get('orders', []);
        mine.unshift({ ref: res.ref, token: res.token, name: p.name, at: Math.round(Date.now() / 1000) });
        ML.ls.set('orders', mine.slice(0, 20));
        const isRes = mode === 'reservation';
        $('.modal-head h2', m.el).textContent = isRes ? ML.t('Réservation enregistrée') : ML.t('Commande enregistrée');
        $('.modal-body', m.el).innerHTML = html`
          <div class="order-done">
            <div class="done-mark">${icon('check')}</div>
            <p class="done-ref">${ML.t('N°')} <b>${res.ref}</b></p>
            <p>${isRes
              ? ML.t('Votre article est mis de côté pendant') + ' ' + ML.boot.config.reservation_hours + ' ' + ML.t('heures chez') + ' ' + st.name + '. ' + ML.t('Le magasin vous appelle pour confirmer.')
              : mode === 'delivery' ? st.name + ' ' + ML.t('vous appelle pour confirmer la livraison. Vous payez à la réception.') : st.name + ' ' + ML.t('vous prévient dès que votre commande est prête. Vous payez au retrait.')}</p>
            <div class="row row-wrap" style="justify-content:center">
              <a class="btn btn-primary" href="#/suivi/${res.token}" data-close>${icon('package-check')}${isRes ? ML.t('Suivre ma réservation') : ML.t('Suivre ma commande')}</a>
              <button class="btn btn-line" type="button" data-close>${ML.t('Continuer')}</button>
            </div>
          </div>`;
        if (onDone) onDone();
      } catch (err) {
        if (!(err instanceof Stale)) ML.fail(err, f);
      }
      ML.busy(btn, false);
    };
  }

  /* ================================== COMMANDE LIVRÉE SANS CHOISIR DE MAGASIN */
  function codSheet(p, sel) {
    const m = ML.modal({
      title: ML.t('Commander · livraison à domicile'),
      body: html`
        <div class="order-sum">${ML.visual(p, { cls: 'pv-xs' })}<div class="grow"><b>${p.name}</b><br><span class="muted">${p.brand.name}</span></div></div>
        <div style="margin-top:16px">${ML.codForm(p, { size: sel.size, color: sel.color, email: true })}</div>`,
    });
    ML.codBind($('form', m.el), p, {
      onDone: (res) => {
        $('.modal-head h2', m.el).textContent = ML.t('Commande enregistrée');
        $('.modal-body', m.el).innerHTML = ML.codDone(res, '#/suivi/');
      },
    });
  }

  /* ================================================================ MARQUES */
  async function brandsView() {
    const brands = ML.boot.brands;
    view().innerHTML = html`
      <div class="page">
        <div class="page-head"><h1>${ML.t('Les marques du réseau')}</h1><p class="muted">${ML.t('Leur catalogue, leurs points de vente et la disponibilité de leurs produits.')}</p></div>
        ${brands.length ? html`<div class="bgrid">${brands.map((b) => html`
          <a class="bcard" href="#/marque/${b.slug}" style="--c:${color(b.color, '#101828')}">
            <div class="bcard-band"></div>
            <div class="bcard-body">
              ${ML.mono(b.name, b.color, b.logo, 'mono-lg')}
              <h2>${b.name}${b.company.demo ? html` <span class="badge">${ML.t('Démo')}</span>` : ''}</h2>
              <p class="muted">${b.tagline || ML.cats[b.category] || ''}</p>
              <div class="bcard-stats"><span>${icon('package', 'ico-sm')}${fmt.plural(b.products, 'produit')}</span><span>${icon('store', 'ico-sm')}${fmt.plural(b.stores, 'point de vente', 'points de vente')}</span></div>
            </div>
          </a>`)}</div>` : html`<div class="empty">${icon('tag')}<h3>${ML.t('Les premières marques arrivent')}</h3><p>${ML.t('Vous êtes une marque ou un distributeur ? Rejoignez le réseau.')}</p><a class="btn btn-primary" href="../#inscription">${ML.t('Créer mon espace')}</a></div>`}
      </div>`;
  }

  async function brandView(slug) {
    loading();
    const b = S.brandBySlug[slug] || (await api('brand', { params: { slug } })).brand;
    hit('brand', b.id);
    view().innerHTML = html`
      <div class="page">
        <a class="back" href="#/marques">${icon('arrow-left')}${ML.t('Toutes les marques')}</a>
        <div class="brand-hero" style="--c:${color(b.color, '#101828')}">
          ${ML.mono(b.name, b.color, b.logo, 'mono-lg brand-mono')}
          <div class="grow">
            <h1>${b.name}${demoBadge(b.company.demo)}</h1>
            ${b.tagline ? html`<p class="brand-tag">${b.tagline}</p>` : ''}
            ${b.about ? html`<p class="brand-about">${b.about}</p>` : ''}
          </div>
          <div class="brand-side">
            <div class="brand-stats"><span><b>${fmt.num(b.products)}</b>${ML.t('produits')}</span><span><b>${fmt.num(b.stores)}</b>${ML.t('points de vente')}</span></div>
            <a class="btn btn-white" href="${ML.href('/carte', { marque: b.slug })}">${icon('map')}${ML.t('Voir sur la carte')}</a>
          </div>
        </div>
        <section class="section">
          <div class="section-head">
            <h2>${ML.t('Le catalogue')}</h2>
            <form class="input-icon" data-form="brandq" role="search">${icon('search')}<input class="input" type="search" name="q" placeholder="${ML.t('Chercher dans')} ${b.name}…" autocomplete="off" aria-label="${ML.t('Chercher un produit de la marque')}"></form>
          </div>
          <div data-grid><div class="loading"><div class="spinner"></div></div></div>
        </section>
      </div>`;
    const load = async (q) => {
      const res = await api('products', { params: Object.assign({ brand: b.id, q, log: q && once('b' + b.id + q) ? 1 : '' }, ML.locParams()) });
      $('[data-grid]').innerHTML = res.items.length ? html`<div class="pgrid">${res.items.map(productCard)}</div>${res.total > res.items.length ? html`<p class="more"><a class="btn btn-line" href="${ML.href('/produits', { marque: b.id, q })}">${ML.t('Voir les')} ${fmt.num(res.total)} ${ML.t('produits')}</a></p>` : ''}`
        : html`<div class="empty">${icon('package-search')}<h3>${q ? ML.t('Aucun produit ne correspond') : ML.t('Le catalogue arrive bientôt')}</h3><p>${q ? ML.t('Essayez un autre mot.') : ''}</p></div>`;
    };
    ML.forms.brandq = (f) => load(f.q.value.trim());
    await load('');
  }

  /* ================================================================== SUIVI */
  /** Adresse de livraison : adresse (facultative), commune, wilaya. */
  const deliveryText = (d) => ML.joinList([d.address, ML.communeLabel(d.commune || d.city, d.commune_ar), d.wilaya && ML.zones[d.wilaya] ? ML.zoneName(ML.zones[d.wilaya]) : d.zone]);

  function steps(o) {
    const list = o.mode === 'delivery'
      ? [['pending', ML.t('Demande reçue')], ['confirmed', ML.t('Confirmée')], ['shipped', ML.t('En livraison')], ['done', ML.t('Livrée')]]
      : [['pending', ML.t('Demande reçue')], ['confirmed', ML.t('Confirmée')], ['ready', o.kind === 'reservation' ? ML.t('Mise de côté') : ML.t('Prête au retrait')], ['done', ML.t('Retirée')]];
    if (o.radar) list.splice(1, 0, ['store', ML.t('Point de vente trouvé')]);
    const at = {};
    o.events.forEach((e) => { if (!at[e.status] || e.status !== 'pending') at[e.status] = e.created; });
    if (o.radar && !o.waiting) at.store = at.confirmed;
    // Une commande radar sans point de vente reste à la première étape ; prise en charge, elle passe l'étape « trouvé ».
    const idx = o.waiting ? 0 : list.findIndex(([k]) => k === o.status);
    return html`<ol class="steps">${list.map(([k, label], i) => html`<li class="${o.status !== 'cancelled' && i <= idx ? 'is-done' : ''} ${i === idx ? 'is-now' : ''}"><i>${o.status !== 'cancelled' && i <= idx ? icon('check', 'ico-sm') : ''}</i><div><b>${label}</b>${at[k] ? html`<small>${fmt.time(at[k])}</small>` : ''}</div></li>`)}</ol>`;
  }

  async function trackView(token) {
    const mine = ML.ls.get('orders', []);
    const formHtml = html`
      <div class="page track">
        <div class="page-head"><h1>${ML.t('Suivre une commande')}</h1><p class="muted">${ML.t('Avec le numéro reçu à la validation et le téléphone donné au point de vente.')}</p></div>
        <div class="track-grid">
          <form class="card card-pad stack" data-form="track">
            <div class="field"><label for="t-ref">${ML.t('Numéro de commande')}</label><input class="input" id="t-ref" name="ref" placeholder="ML-XXXXXX" autocapitalize="characters" autocomplete="off" required></div>
            <div class="field"><label for="t-phone">${ML.t('Téléphone')}</label><input class="input" id="t-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required></div>
            <p class="form-error" data-error hidden></p>
            <button class="btn btn-dark btn-block" type="submit">${icon('search')}${ML.t('Voir où elle en est')}</button>
          </form>
          <div class="card card-pad">
            <h2 class="card-title">${ML.t('Mes demandes sur cet appareil')}</h2>
            ${mine.length ? html`<ul class="mine">${mine.map((o) => html`<li><a href="#/suivi/${o.token}"><span><b>${o.ref}</b><small class="muted">${o.name}</small></span><span class="dim">${fmt.day(o.at)}</span>${icon('chevron-right', 'ico-sm')}</a></li>`)}</ul>`
              : html`<p class="muted" style="margin-top:8px">${ML.t('Vos commandes et réservations passées depuis cet appareil apparaîtront ici.')}</p>`}
          </div>
        </div>
      </div>`;
    const detail = (o, tok) => {
      const what = o.kind === 'reservation' ? ML.t('réservation') : ML.t('commande');
      view().innerHTML = html`
        <div class="page track">
          <a class="back" href="#/suivi">${icon('arrow-left')}${ML.t('Suivi')}</a>
          <div class="track-head">
            <div><p class="eyebrow">${ML.kindLabel(o)}</p><h1>${o.ref}</h1></div>
            ${ML.statusBadge(o)}
          </div>
          ${o.status === 'cancelled' ? html`<div class="notice is-bad">${icon('circle-x')}<div><b>${ML.t('Cette')} ${what} ${ML.t('est annulée.')}</b>${(o.events[o.events.length - 1] || {}).note ? html`<br>${o.events[o.events.length - 1].note}` : ''}</div></div>` : ''}
          ${o.kind === 'reservation' && o.expires && ['pending', 'confirmed', 'ready'].includes(o.status) ? html`<div class="notice">${icon('clock')}<div>${ML.t('Article mis de côté jusqu\'au')} <b>${fmt.time(o.expires)}</b>.</div></div>` : ''}
          ${o.waiting ? html`<div class="notice is-radar">${icon('target')}<div><b>${ML.t('Nous cherchons le point de vente le plus proche de chez vous.')}</b><br>${ML.t("Votre commande est proposée aux points de vente de votre commune, puis de votre wilaya, qui ont l'article en stock. Le premier qui la prend vous appelle.")}</div></div>` : ''}
          <div class="track-grid">
            <div class="card card-pad">
              <h2 class="card-title">${ML.t('Avancement')}</h2>
              ${steps(o)}
              ${tok && o.status === 'pending' ? html`<button class="btn btn-danger btn-sm" type="button" data-act="cancel" style="margin-top:14px">${ML.t('Annuler ma')} ${what}</button>` : ''}
            </div>
            <div class="stack">
              <div class="card card-pad">
                <h2 class="card-title">${ML.t('Détail')}</h2>
                <ul class="lines">${o.items.map((i) => html`<li><span>${i.qty} × ${i.name}${[i.size, i.color].filter(Boolean).length ? html`<small class="muted"> · ${[i.size, i.color].filter(Boolean).join(' · ')}</small>` : ''}</span><b>${fmt.price(i.price * i.qty)}</b></li>`)}<li class="total"><span>${ML.t('Total à payer')} ${o.mode === 'delivery' ? ML.t('à la livraison') : ML.t('en magasin')}</span><b>${fmt.price(o.total)}</b></li></ul>
              </div>
              ${o.store ? html`<div class="card card-pad">
                <h2 class="card-title">${o.mode === 'delivery' ? ML.t('Expédiée par') : ML.t('À retirer chez')}</h2>
                <p style="margin-top:6px"><b>${o.store.name}</b><br><span class="muted">${o.store.address}</span></p>
                ${o.delivery ? html`<p class="muted" style="margin-top:8px">${icon('truck', 'ico-sm')} ${ML.t('Livraison')} : ${deliveryText(o.delivery)}</p>` : ''}
                <div class="row row-wrap" style="margin-top:12px">${contactButtons(Object.assign({ id: o.store.id }, o.store), true)}</div>
              </div>` : o.delivery ? html`<div class="card card-pad">
                <h2 class="card-title">${ML.t('Livraison')}</h2>
                <p style="margin-top:6px">${deliveryText(o.delivery)}</p>
                <p class="muted" style="margin-top:6px">${ML.t('Paiement à la réception.')}</p>
              </div>` : ''}
            </div>
          </div>
        </div>`;
      ML.actions.cancel = async (el) => {
        if (!await ML.confirm(ML.t('Annuler cette') + ' ' + what + ML.t(" ? L'article sera remis en vente."), { ok: ML.t('Oui, annuler'), danger: true })) return;
        ML.busy(el, true);
        try { detail((await api('order_cancel', { body: { token: tok } })).order, tok); ML.toast(ML.t('Votre') + ' ' + what + ' ' + ML.t('est annulée.'), 'good'); } catch (e) { ML.fail(e); ML.busy(el, false); }
      };
    };
    ML.forms.track = async (f) => {
      const btn = $('button[type=submit]', f);
      ML.busy(btn, true);
      try { detail((await api('track', { params: { ref: f.ref.value.trim(), phone: f.phone.value.trim() } })).order, ''); } catch (e) { ML.fail(e, f); }
      ML.busy(btn, false);
    };
    if (token) {
      loading();
      try { detail((await api('track', { params: { token } })).order, token); return; } catch (e) { ML.toast(ML.t('Cette commande est introuvable.'), 'bad'); }
    }
    view().innerHTML = formHtml;
  }

  /* ------------------------------------------------------------ démarrage */
  (async function init() {
    shell();
    try {
      await ML.bootstrap();
    } catch (e) {
      view().innerHTML = html`<div class="page"><div class="empty">${icon('wifi-off')}<h3>${ML.t('La plateforme est momentanément indisponible')}</h3><p>${e.message}</p></div></div>`;
      return;
    }
    ML.boot.brands.forEach((b) => { S.brandById[b.id] = b; S.brandBySlug[b.slug] = b; });
    document.addEventListener('mlg:loc', paintLoc);
    window.addEventListener('hashchange', route);
    route();
  })();
})();
