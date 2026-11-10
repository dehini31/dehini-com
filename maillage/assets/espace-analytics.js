/*
 * Maillage — espace entreprise : analyses commerciales.
 *   Recherches et commandes · Produits et zones · Performance du réseau · Expansion
 * Un seul filtre (la période) en haut : tous les graphiques portent sur la même tranche.
 */
(function () {
  'use strict';
  const { html, icon, fmt } = ML;
  const E = ML.E;
  const api = (action, opts) => E.api(action, opts);
  const { $ } = E;
  const CH = ML.charts;
  const C = CH.C;
  const TABS = () => [['activite', ML.t('Recherches et commandes')], ['demande', ML.t('Produits et zones')], ['performance', ML.t('Performance')], ['expansion', ML.t('Expansion')]];
  const money = (v) => fmt.price(v);
  const moneyShort = (v) => fmt.compact(v) + ' ' + ML.boot.config.currency;

  /** Tuile : libellé, valeur, écart avec la période précédente (couleur = sens × « plus, c'est mieux ? »). */
  function tile(label, cur, prev, o) {
    o = o || {};
    const f = o.format || fmt.num;
    let delta = '';
    if (cur != null && prev != null && prev !== 0) {
      const d = ((cur - prev) / Math.abs(prev)) * 100;
      const up = d >= 0;
      const good = o.lowerIsBetter ? !up : up;
      delta = Math.abs(d) < 0.5 ? html`<span class="delta">${ML.t('Stable')}</span>` : html`<span class="delta ${good ? 'is-good' : 'is-bad'}">${icon(up ? 'trending-up' : 'trending-down', 'ico-sm')}${up ? '+' : '−'}${fmt.dec(Math.abs(d))} %</span>`;
    }
    return html`<div class="tile"><span class="tile-label">${label}</span><b class="tile-value">${cur == null ? '—' : f(cur)}</b><span class="tile-sub">${delta}<span class="dim">${o.sub || ML.t('vs période précédente')}</span></span></div>`;
  }

  E.views.analyses = async (r) => {
    const tab = TABS().some(([k]) => k === r.query.t) ? r.query.t : 'activite';
    const days = [7, 30, 90].includes(+r.query.j) ? +r.query.j : 30;
    E.view().innerHTML = html`
      ${E.head(ML.t('Analyses'), ML.t('Ce que cherchent vos clients, ce qui se vend, et où votre réseau peut grandir.'))}
      <div class="filters">
        <div class="seg" role="group" aria-label="${ML.t('Période')}">${[7, 30, 90].map((d) => html`<a class="${d === days ? 'is-on' : ''}" href="${ML.href('/analyses', { t: tab, j: d })}" aria-current="${d === days}">${d} ${ML.t('jours')}</a>`)}</div>
        <span class="muted" data-range></span>
      </div>
      <div class="tabs" role="tablist">${TABS().map(([k, label]) => html`<a role="tab" aria-selected="${k === tab}" class="${k === tab ? 'is-on' : ''}" href="${ML.href('/analyses', { t: k, j: days })}">${label}</a>`)}</div>
      <div data-body><div class="loading"><div class="spinner"></div></div></div>`;
    const d = await api('analytics', { params: { days } });
    $('[data-range]').textContent = ML.t('Du') + ' ' + fmt.day(d.from) + ' ' + ML.t('au') + ' ' + fmt.day(d.to);
    const body = $('[data-body]');
    if (tab === 'activite') activity(body, d);
    else if (tab === 'demande') demand(body, d);
    else if (tab === 'performance') performance(body, d);
    else expansion(body, d);
  };

  /* ------------------------------------------------ recherches et commandes */
  function activity(el, d) {
    const t = d.tiles;
    const s = d.series;
    const inProgress = d.status.confirmed + d.status.ready + d.status.shipped;
    el.innerHTML = html`
      <div class="tiles">
        ${tile(ML.t('Recherches'), t.searches.v, t.searches.prev)}
        ${tile(ML.t('Commandes'), t.orders.v, t.orders.prev)}
        ${tile(ML.t("Chiffre d'affaires"), t.revenue.v, t.revenue.prev, { format: moneyShort })}
        ${tile(ML.t('Panier moyen'), t.basket.v, t.basket.prev, { format: money })}
        ${tile(ML.t('Recherches sans résultat'), t.noresult.v, t.noresult.prev, { format: (v) => fmt.dec(v) + ' %', lowerIsBetter: true })}
      </div>
      <div class="grid-2">
        ${CH.card({ title: ML.t('Recherches par jour'), sub: ML.t('Recherches portant sur vos produits (catalogue, disponibilité, carte).'), draw: (p) => CH.timeSeries(p, { kind: 'line', labels: s.days, values: s.searches, color: C.searches, name: ML.t('Recherches') }), head: [ML.t('Jour'), ML.t('Recherches')], rows: s.days.map((day, i) => [CH.dayLabel(day), fmt.num(s.searches[i])]).reverse(), align: ['', 'r'] })}
        ${CH.card({ title: ML.t('Commandes par jour'), sub: ML.t('Commandes et réservations reçues.'), draw: (p) => CH.timeSeries(p, { kind: 'columns', labels: s.days, values: s.orders, color: C.orders, name: ML.t('Commandes') }), head: [ML.t('Jour'), ML.t('Commandes'), ML.t("Chiffre d'affaires")], rows: s.days.map((day, i) => [CH.dayLabel(day), fmt.num(s.orders[i]), money(s.revenue[i])]).reverse(), align: ['', 'r', 'r'] })}
        ${CH.card({ title: ML.t("Chiffre d'affaires par jour"), sub: ML.t('Hors commandes annulées.'), draw: (p) => CH.timeSeries(p, { kind: 'line', labels: s.days, values: s.revenue, color: C.revenue, name: ML.t("Chiffre d'affaires"), format: money }), head: [ML.t('Jour'), ML.t("Chiffre d'affaires")], rows: s.days.map((day, i) => [CH.dayLabel(day), money(s.revenue[i])]).reverse(), align: ['', 'r'] })}
        ${CH.card({ title: ML.t('Mots les plus recherchés'), sub: ML.t('Dans le catalogue, quand vos produits ressortent.'), draw: (p) => CH.bars(p, { items: d.terms.slice(0, 8).map((x) => ({ label: x.q, value: x.n })), color: C.searches }), head: [ML.t('Recherche'), ML.t('Fois'), ML.t('Résultats (moy.)')], rows: d.terms.map((x) => [x.q, fmt.num(x.n), fmt.dec(x.results)]), align: ['', 'r', 'r'] })}
        ${CH.card({ title: ML.t('Où en sont les commandes'), sub: ML.t('Répartition par statut sur la période.'), draw: (p) => CH.segments(p, { parts: [{ label: ML.t('Terminées'), value: d.status.done, color: '#0ca30c' }, { label: ML.t('En cours'), value: inProgress, color: '#7b8494' }, { label: ML.t('En attente'), value: d.status.pending, color: '#fab219' }, { label: ML.t('Annulées'), value: d.status.cancelled, color: '#d03b3b' }] }), head: [ML.t('Statut'), ML.t('Commandes')], rows: [[ML.t('Terminées'), fmt.num(d.status.done)], [ML.t('En cours'), fmt.num(inProgress)], [ML.t('En attente'), fmt.num(d.status.pending)], [ML.t('Annulées'), fmt.num(d.status.cancelled)]], align: ['', 'r'] })}
        ${CH.card({ title: ML.t('Comment les clients récupèrent leurs achats'), sub: ML.t('Retrait, livraison ou réservation.'), draw: (p) => CH.segments(p, { parts: [{ label: ML.t('Retrait en magasin'), value: d.modes.pickup, color: C.searches }, { label: ML.t('Livraison'), value: d.modes.delivery, color: C.orders }, { label: ML.t('Réservation'), value: d.modes.reservation, color: C.revenue }] }), head: [ML.t('Mode'), ML.t('Commandes')], rows: [[ML.t('Retrait en magasin'), fmt.num(d.modes.pickup)], [ML.t('Livraison'), fmt.num(d.modes.delivery)], [ML.t('Réservation'), fmt.num(d.modes.reservation)]], align: ['', 'r'] })}
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">${ML.t('Demandes restées sans réponse')}</h3><p class="card-sub">${ML.t('Ce que les visiteurs ont cherché sans rien trouver : autant de produits à ajouter ou à réapprovisionner.')}</p></div></div>
        ${d.missing.length ? html`<ul class="miss">${d.missing.map((m) => html`<li><span class="grow"><b>${m.label}</b></span><span class="badge ${m.scope === 'own' ? 'badge-brand' : ''}">${m.scope === 'own' ? ML.t('Dans votre catalogue') : ML.t('Sur toute la plateforme')}</span><b class="num">${fmt.num(m.n)} ×</b></li>`)}</ul>` : html`<p class="muted">${ML.t('Toutes les recherches ont trouvé une réponse sur cette période.')}</p>`}
      </section>`;
  }

  /* ------------------------------------------------------ produits et zones */
  function demand(el, d) {
    const top = d.products.slice(0, 8);
    const bySearch = d.products.slice().sort((a, b) => b.searches - a.searches).filter((p) => p.searches > 0).slice(0, 8);
    const zones = d.zones.filter((z) => z.searches + z.orders > 0);
    el.innerHTML = html`
      <div class="grid-2">
        ${CH.card({ title: ML.t('Produits les plus commandés'), sub: ML.t('Unités commandées ou réservées (hors annulations).'), draw: (p) => CH.bars(p, { items: top.filter((x) => x.qty > 0).map((x) => ({ label: x.name, value: x.qty })), color: C.orders }), head: [ML.t('Produit'), ML.t('Unités'), ML.t("Chiffre d'affaires"), ML.t('Recherches'), ML.t('Fiches vues')], rows: d.products.map((x) => [x.name, fmt.num(x.qty), money(x.revenue), fmt.num(x.searches), fmt.num(x.views)]), align: ['', 'r', 'r', 'r', 'r'] })}
        ${CH.card({ title: ML.t('Produits les plus recherchés'), sub: ML.t('Recherches « où le trouver » sur la fiche du produit.'), draw: (p) => CH.bars(p, { items: bySearch.map((x) => ({ label: x.name, value: x.searches })), color: C.searches }), head: [ML.t('Produit'), ML.t('Recherches'), ML.t('Fiches vues'), ML.t('Unités commandées')], rows: bySearch.map((x) => [x.name, fmt.num(x.searches), fmt.num(x.views), fmt.num(x.qty)]), align: ['', 'r', 'r', 'r'] })}
        ${CH.card({ title: ML.t('Tailles les plus demandées'), sub: ML.t('Tailles choisies dans les recherches.'), draw: (p) => CH.bars(p, { items: d.sizes.map((x) => ({ label: x.label, value: x.n })), color: C.searches }), head: [ML.t('Taille'), ML.t('Recherches')], rows: d.sizes.map((x) => [x.label, fmt.num(x.n)]), align: ['', 'r'] })}
        ${CH.card({ title: ML.t('Couleurs les plus demandées'), sub: ML.t('Couleurs choisies dans les recherches.'), draw: (p) => CH.bars(p, { items: d.colors.map((x) => ({ label: x.label, value: x.n })), color: C.searches }), head: [ML.t('Couleur'), ML.t('Recherches')], rows: d.colors.map((x) => [x.label, fmt.num(x.n)]), align: ['', 'r'] })}
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">${ML.t('Demande par zone')}</h3><p class="card-sub">${ML.t('Chaque cercle est une wilaya : plus il est grand, plus on y cherche vos produits. Les épingles sont vos points de vente.')}</p></div></div>
        <div class="zone-grid">
          <div class="zone-map"><div id="zmap"></div><div class="map-legend"><span><i class="lg-circle" style="--c:${C.searches}"></i>${ML.t('Recherches')}</span><span><i class="lg-pin"></i>${ML.t('Point de vente')}</span></div></div>
          ${CH.table([ML.t('Zone'), ML.t('Recherches'), ML.t('Commandes'), ML.t("Chiffre d'affaires"), ML.t('Points de vente')], zones.slice(0, 14).map((z) => [ML.zoneName(ML.zones[z.code] || z), fmt.num(z.searches), fmt.num(z.orders), money(z.revenue), z.stores ? fmt.num(z.stores) : html`<span class="badge badge-warn">${ML.t('Aucun')}</span>`]), ['', 'r', 'r', 'r', 'r'])}
        </div>
      </section>`;
    const map = ML.makeMap($('#zmap'), { wheel: false });
    const max = Math.max(1, ...zones.map((z) => z.searches));
    const pts = [];
    zones.filter((z) => z.searches > 0).forEach((z) => {
      L.circleMarker([z.lat, z.lng], { radius: 6 + Math.sqrt(z.searches / max) * 26, color: '#fff', weight: 2, fillColor: C.searches, fillOpacity: 0.55 })
        .addTo(map).bindTooltip(html`<b>${ML.zoneName(ML.zones[z.code] || z)}</b><br>${fmt.plural(z.searches, 'recherche')} · ${fmt.plural(z.orders, 'commande')}`.toString(), { direction: 'top' });
      pts.push([z.lat, z.lng]);
    });
    d.stores.forEach((s) => { L.marker([s.lat, s.lng], { icon: ML.pin(s.own ? '#ff5a2b' : '#344054', 'pin-sm'), title: s.name, interactive: false, keyboard: false }).addTo(map); pts.push([s.lat, s.lng]); });
    if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.15), { maxZoom: 9, animate: false });
    E.cleanup = () => map.remove();
  }

  /* ----------------------------------------------------------- performance */
  function performance(el, d) {
    const brand = d.kind === 'brand';
    const stores = d.stores;
    const totalRev = stores.reduce((a, s) => a + s.revenue, 0);
    const best = stores[0];
    const avail = stores.filter((s) => s.availability != null);
    const avgAvail = avail.length ? Math.round(avail.reduce((a, s) => a + s.availability, 0) / avail.length) : null;
    const slow = stores.filter((s) => s.confirm_h != null).sort((a, b) => b.confirm_h - a.confirm_h)[0];
    el.innerHTML = !stores.length ? html`<div class="card"><div class="empty">${icon('store')}<h3>${ML.t('Pas encore de point de vente à analyser')}</h3><p>${ML.t('Ajoutez vos magasins et leur stock : leurs résultats apparaîtront ici.')}</p><a class="btn btn-primary" href="#/points-de-vente/nouveau">${ML.t('Ajouter un point de vente')}</a></div></div>` : html`
      <div class="tiles">
        <div class="tile"><span class="tile-label">${ML.t('Points de vente actifs')}</span><b class="tile-value">${fmt.num(stores.length)}</b><span class="tile-sub dim">${brand ? fmt.plural(stores.filter((s) => !s.own).length, 'chez un distributeur', 'chez des distributeurs') : ML.t('Dans votre réseau')}</span></div>
        <div class="tile"><span class="tile-label">${ML.t('Meilleur point de vente')}</span><b class="tile-value tile-text">${best && best.revenue ? best.name : '—'}</b><span class="tile-sub dim">${best && best.revenue ? moneyShort(best.revenue) + ' · ' + Math.round((best.revenue / Math.max(1, totalRev)) * 100) + ' ' + ML.t('% du total') : ML.t('Aucune vente sur la période')}</span></div>
        <div class="tile"><span class="tile-label">${ML.t('Disponibilité moyenne')}</span><b class="tile-value">${avgAvail == null ? '—' : avgAvail + ' %'}</b><span class="tile-sub dim">${ML.t('Références en stock / références suivies')}</span></div>
        <div class="tile"><span class="tile-label">${ML.t('Confirmation la plus lente')}</span><b class="tile-value">${slow ? fmt.dec(slow.confirm_h) + ' ' + ML.t('h') : '—'}</b><span class="tile-sub dim">${slow ? slow.name : ML.t('Aucune commande confirmée')}</span></div>
      </div>
      ${CH.card({ title: ML.t("Chiffre d'affaires par point de vente"), sub: brand ? ML.t('Ventes de vos produits, dans vos magasins et chez vos distributeurs.') : ML.t('Ventes de vos magasins.'), draw: (p) => CH.bars(p, { items: stores.filter((s) => s.revenue > 0).slice(0, 10).map((s) => ({ label: s.name, value: s.revenue, sub: s.own ? '' : s.company })), color: C.revenue, format: money }), head: [ML.t('Point de vente'), ML.t("Chiffre d'affaires"), ML.t('Commandes')], rows: stores.map((s) => [s.name, money(s.revenue), fmt.num(s.orders)]), align: ['', 'r', 'r'] })}
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">${ML.t('Tableau de bord des points de vente')}</h3><p class="card-sub">${ML.t('Cliquez sur un titre de colonne pour trier.')}</p></div></div>
        <div data-perf></div>
      </section>
      ${brand && d.partners.length > 1 ? html`<section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">${ML.t('Par distributeur')}</h3><p class="card-sub">${ML.t('Vos magasins comparés à ceux de chaque distributeur.')}</p></div></div>
        ${CH.table([ML.t('Réseau'), ML.t('Points de vente'), ML.t('Commandes'), ML.t("Chiffre d'affaires"), ML.t('Menées à terme'), ML.t('Disponibilité')], d.partners.map((p) => [html`<b>${p.name}</b>${p.own ? html` <span class="badge">${ML.t('Vos magasins')}</span>` : ''}`, fmt.num(p.stores), fmt.num(p.orders), money(p.revenue), CH.meter(p.done_rate), CH.meter(p.availability)]), ['', 'r', 'r', 'r', '', ''])}
      </section>` : ''}`;
    if (!stores.length) return;
    const COLS = () => [['name', ML.t('Point de vente')], ['orders', ML.t('Commandes')], ['revenue', ML.t("Chiffre d'affaires")], ['done_rate', ML.t('Menées à terme')], ['cancel_rate', ML.t('Annulées')], ['confirm_h', ML.t('Confirmation')], ['availability', ML.t('Disponibilité')], ['views', ML.t('Intérêt')]];
    let sort = 'revenue'; let dir = -1;
    const paint = () => {
      const rows = stores.slice().sort((a, b) => {
        const x = a[sort]; const y = b[sort];
        if (sort === 'name') return String(x).localeCompare(String(y), 'fr') * -dir;
        return ((x == null ? -Infinity : x) - (y == null ? -Infinity : y)) * dir;
      });
      $('[data-perf]').innerHTML = html`<div class="tbl-wrap"><table class="tbl tbl-perf">
        <thead><tr>${COLS().map(([k, label], i) => html`<th class="${i > 0 && i < 3 ? 'r' : ''}" aria-sort="${sort === k ? (dir < 0 ? 'descending' : 'ascending') : 'none'}"><button type="button" data-act="perf-sort" data-k="${k}">${label}${sort === k ? icon(dir < 0 ? 'arrow-down' : 'arrow-up', 'ico-sm') : ''}</button></th>`)}</tr></thead>
        <tbody>${rows.map((s) => html`<tr>
          <td><b>${s.name}</b><small class="muted">${s.city}${s.own ? '' : ' · ' + s.company}</small></td>
          <td class="r num">${fmt.num(s.orders)}</td>
          <td class="r num">${money(s.revenue)}</td>
          <td>${CH.meter(s.done_rate)}</td>
          <td>${CH.meter(s.cancel_rate, 'bad')}</td>
          <td class="num">${s.confirm_h == null ? html`<span class="dim">—</span>` : fmt.dec(s.confirm_h) + ' ' + ML.t('h')}</td>
          <td>${CH.meter(s.availability)}</td>
          <td class="num">${fmt.plural(s.views, 'vue')}<small class="muted">${fmt.num(s.contacts)} ${ML.t('appels et itinéraires')}</small></td>
        </tr>`)}</tbody></table></div>`;
    };
    ML.actions['perf-sort'] = (b) => { if (sort === b.dataset.k) dir = -dir; else { sort = b.dataset.k; dir = -1; } paint(); };
    paint();
  }

  /* -------------------------------------------------------------- expansion */
  function expansion(el, d) {
    const brand = d.kind === 'brand';
    const ex = d.expansion;
    const cov = d.coverage;
    const lost = ex.reduce((a, z) => a + z.searches, 0);
    el.innerHTML = html`
      <div class="tiles">
        <div class="tile"><span class="tile-label">${ML.t('Demande couverte')}</span><b class="tile-value">${cov.share == null ? '—' : cov.share + ' %'}</b><span class="tile-sub dim">${ML.t('des recherches localisées à moins de')} ${fmt.num(d.cover_km)} ${ML.t("km d'un point de vente")}</span></div>
        <div class="tile"><span class="tile-label">${ML.t('Zones à conquérir')}</span><b class="tile-value">${fmt.num(ex.length)}</b><span class="tile-sub dim">${ML.t("wilayas où l'on cherche vos produits, sans point de vente proche")}</span></div>
        <div class="tile"><span class="tile-label">${ML.t('Recherches hors couverture')}</span><b class="tile-value">${fmt.num(lost)}</b><span class="tile-sub dim">${ML.t('sur')} ${fmt.num(cov.located)} ${ML.t('recherches localisées')}</span></div>
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">${ML.t('Carte des opportunités')}</h3><p class="card-sub">${ML.t('En orange, les zones où la demande existe sans point de vente à moins de')} ${fmt.num(d.cover_km)} ${ML.t('km. En bleu, la demande déjà couverte.')}</p></div></div>
        <div class="zone-map is-tall"><div id="xmap"></div><div class="map-legend"><span><i class="lg-circle" style="--c:${C.orders}"></i>${ML.t('À conquérir')}</span><span><i class="lg-circle" style="--c:${C.searches}"></i>${ML.t('Couverte')}</span><span><i class="lg-pin"></i>${ML.t('Point de vente')}</span></div></div>
      </section>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">${ML.t('Où ouvrir ou recruter en priorité')}</h3><p class="card-sub">${ML.t('Classées par nombre de recherches sur la période.')}</p></div></div>
        ${ex.length ? html`<ol class="opps">${ex.map((z, i) => html`
          <li>
            <span class="opp-rank">${i + 1}</span>
            <div class="grow">
              <h4>${ML.zoneName(ML.zones[z.code] || z)}</h4>
              <p class="muted">${fmt.plural(z.searches, 'recherche')}${z.top ? html` · ${ML.t('surtout')} <b>${z.top}</b>` : ''}${z.nearest ? html` · ${ML.t('point de vente le plus proche :')} ${z.nearest} (${fmt.num(z.nearest_km)} km)` : ' · ' + ML.t('aucun point de vente dans votre réseau')}</p>
            </div>
            <a class="btn btn-soft btn-sm" href="${brand ? '#/reseau' : '#/points-de-vente/nouveau'}">${icon(brand ? 'handshake' : 'plus', 'ico-sm')}${brand ? ML.t('Trouver un distributeur') : ML.t('Ouvrir un point de vente')}</a>
          </li>`)}</ol>` : html`<div class="empty">${icon('target')}<h3>${cov.located ? ML.t('Votre réseau couvre la demande') : ML.t('Pas encore assez de recherches localisées')}</h3><p>${cov.located ? ML.t("Toutes les zones où l'on cherche vos produits ont un point de vente à proximité.") : ML.t('Dès que des visiteurs chercheront vos produits en indiquant leur ville, les zones à fort potentiel apparaîtront ici.')}</p></div>`}
      </section>`;
    const map = ML.makeMap($('#xmap'), { wheel: false });
    const open = new Set(ex.map((z) => z.code));
    const zones = d.zones.filter((z) => z.searches > 0);
    const max = Math.max(1, ...zones.map((z) => z.searches));
    const pts = [];
    zones.forEach((z) => {
      const isOpen = open.has(z.code);
      L.circleMarker([z.lat, z.lng], { radius: 6 + Math.sqrt(z.searches / max) * 26, color: '#fff', weight: 2, fillColor: isOpen ? C.orders : C.searches, fillOpacity: isOpen ? 0.7 : 0.4 })
        .addTo(map).bindTooltip(html`<b>${ML.zoneName(ML.zones[z.code] || z)}</b> — ${isOpen ? ML.t('à conquérir') : ML.t('couverte')}<br>${fmt.plural(z.searches, 'recherche')}${z.nearest_km != null ? html` · ${ML.t('point de vente à')} ${fmt.num(z.nearest_km)} km` : ''}`.toString(), { direction: 'top' });
      pts.push([z.lat, z.lng]);
    });
    d.stores.forEach((s) => { L.marker([s.lat, s.lng], { icon: ML.pin(s.own ? '#ff5a2b' : '#344054', 'pin-sm'), title: s.name, interactive: false, keyboard: false }).addTo(map); pts.push([s.lat, s.lng]); });
    if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.12), { maxZoom: 9, animate: false });
    E.cleanup = () => map.remove();
  }
})();
