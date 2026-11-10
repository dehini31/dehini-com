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
  const TABS = [['activite', 'Recherches et commandes'], ['demande', 'Produits et zones'], ['performance', 'Performance'], ['expansion', 'Expansion']];
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
      delta = Math.abs(d) < 0.5 ? html`<span class="delta">Stable</span>` : html`<span class="delta ${good ? 'is-good' : 'is-bad'}">${icon(up ? 'trending-up' : 'trending-down', 'ico-sm')}${up ? '+' : '−'}${fmt.dec(Math.abs(d))} %</span>`;
    }
    return html`<div class="tile"><span class="tile-label">${label}</span><b class="tile-value">${cur == null ? '—' : f(cur)}</b><span class="tile-sub">${delta}<span class="dim">${o.sub || 'vs période précédente'}</span></span></div>`;
  }

  E.views.analyses = async (r) => {
    const tab = TABS.some(([k]) => k === r.query.t) ? r.query.t : 'activite';
    const days = [7, 30, 90].includes(+r.query.j) ? +r.query.j : 30;
    E.view().innerHTML = html`
      ${E.head('Analyses', 'Ce que cherchent vos clients, ce qui se vend, et où votre réseau peut grandir.')}
      <div class="filters">
        <div class="seg" role="group" aria-label="Période">${[7, 30, 90].map((d) => html`<a class="${d === days ? 'is-on' : ''}" href="${ML.href('/analyses', { t: tab, j: d })}" aria-current="${d === days}">${d} jours</a>`)}</div>
        <span class="muted" data-range></span>
      </div>
      <div class="tabs" role="tablist">${TABS.map(([k, label]) => html`<a role="tab" aria-selected="${k === tab}" class="${k === tab ? 'is-on' : ''}" href="${ML.href('/analyses', { t: k, j: days })}">${label}</a>`)}</div>
      <div data-body><div class="loading"><div class="spinner"></div></div></div>`;
    const d = await api('analytics', { params: { days } });
    $('[data-range]').textContent = 'Du ' + fmt.day(d.from) + ' au ' + fmt.day(d.to);
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
        ${tile('Recherches', t.searches.v, t.searches.prev)}
        ${tile('Commandes', t.orders.v, t.orders.prev)}
        ${tile('Chiffre d\'affaires', t.revenue.v, t.revenue.prev, { format: moneyShort })}
        ${tile('Panier moyen', t.basket.v, t.basket.prev, { format: money })}
        ${tile('Recherches sans résultat', t.noresult.v, t.noresult.prev, { format: (v) => fmt.dec(v) + ' %', lowerIsBetter: true })}
      </div>
      <div class="grid-2">
        ${CH.card({ title: 'Recherches par jour', sub: 'Recherches portant sur vos produits (catalogue, disponibilité, carte).', draw: (p) => CH.timeSeries(p, { kind: 'line', labels: s.days, values: s.searches, color: C.searches, name: 'Recherches' }), head: ['Jour', 'Recherches'], rows: s.days.map((day, i) => [CH.dayLabel(day), fmt.num(s.searches[i])]).reverse(), align: ['', 'r'] })}
        ${CH.card({ title: 'Commandes par jour', sub: 'Commandes et réservations reçues.', draw: (p) => CH.timeSeries(p, { kind: 'columns', labels: s.days, values: s.orders, color: C.orders, name: 'Commandes' }), head: ['Jour', 'Commandes', 'Chiffre d\'affaires'], rows: s.days.map((day, i) => [CH.dayLabel(day), fmt.num(s.orders[i]), money(s.revenue[i])]).reverse(), align: ['', 'r', 'r'] })}
        ${CH.card({ title: 'Chiffre d\'affaires par jour', sub: 'Hors commandes annulées.', draw: (p) => CH.timeSeries(p, { kind: 'line', labels: s.days, values: s.revenue, color: C.revenue, name: 'Chiffre d\'affaires', format: money }), head: ['Jour', 'Chiffre d\'affaires'], rows: s.days.map((day, i) => [CH.dayLabel(day), money(s.revenue[i])]).reverse(), align: ['', 'r'] })}
        ${CH.card({ title: 'Mots les plus recherchés', sub: 'Dans le catalogue, quand vos produits ressortent.', draw: (p) => CH.bars(p, { items: d.terms.slice(0, 8).map((x) => ({ label: x.q, value: x.n })), color: C.searches }), head: ['Recherche', 'Fois', 'Résultats (moy.)'], rows: d.terms.map((x) => [x.q, fmt.num(x.n), fmt.dec(x.results)]), align: ['', 'r', 'r'] })}
        ${CH.card({ title: 'Où en sont les commandes', sub: 'Répartition par statut sur la période.', draw: (p) => CH.segments(p, { parts: [{ label: 'Terminées', value: d.status.done, color: '#0ca30c' }, { label: 'En cours', value: inProgress, color: '#7b8494' }, { label: 'En attente', value: d.status.pending, color: '#fab219' }, { label: 'Annulées', value: d.status.cancelled, color: '#d03b3b' }] }), head: ['Statut', 'Commandes'], rows: [['Terminées', fmt.num(d.status.done)], ['En cours', fmt.num(inProgress)], ['En attente', fmt.num(d.status.pending)], ['Annulées', fmt.num(d.status.cancelled)]], align: ['', 'r'] })}
        ${CH.card({ title: 'Comment les clients récupèrent leurs achats', sub: 'Retrait, livraison ou réservation.', draw: (p) => CH.segments(p, { parts: [{ label: 'Retrait en magasin', value: d.modes.pickup, color: C.searches }, { label: 'Livraison', value: d.modes.delivery, color: C.orders }, { label: 'Réservation', value: d.modes.reservation, color: C.revenue }] }), head: ['Mode', 'Commandes'], rows: [['Retrait en magasin', fmt.num(d.modes.pickup)], ['Livraison', fmt.num(d.modes.delivery)], ['Réservation', fmt.num(d.modes.reservation)]], align: ['', 'r'] })}
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">Demandes restées sans réponse</h3><p class="card-sub">Ce que les visiteurs ont cherché sans rien trouver : autant de produits à ajouter ou à réapprovisionner.</p></div></div>
        ${d.missing.length ? html`<ul class="miss">${d.missing.map((m) => html`<li><span class="grow"><b>${m.label}</b></span><span class="badge ${m.scope === 'own' ? 'badge-brand' : ''}">${m.scope === 'own' ? 'Dans votre catalogue' : 'Sur toute la plateforme'}</span><b class="num">${fmt.num(m.n)} ×</b></li>`)}</ul>` : html`<p class="muted">Toutes les recherches ont trouvé une réponse sur cette période.</p>`}
      </section>`;
  }

  /* ------------------------------------------------------ produits et zones */
  function demand(el, d) {
    const top = d.products.slice(0, 8);
    const bySearch = d.products.slice().sort((a, b) => b.searches - a.searches).filter((p) => p.searches > 0).slice(0, 8);
    const zones = d.zones.filter((z) => z.searches + z.orders > 0);
    el.innerHTML = html`
      <div class="grid-2">
        ${CH.card({ title: 'Produits les plus commandés', sub: 'Unités commandées ou réservées (hors annulations).', draw: (p) => CH.bars(p, { items: top.filter((x) => x.qty > 0).map((x) => ({ label: x.name, value: x.qty })), color: C.orders }), head: ['Produit', 'Unités', 'Chiffre d\'affaires', 'Recherches', 'Fiches vues'], rows: d.products.map((x) => [x.name, fmt.num(x.qty), money(x.revenue), fmt.num(x.searches), fmt.num(x.views)]), align: ['', 'r', 'r', 'r', 'r'] })}
        ${CH.card({ title: 'Produits les plus recherchés', sub: 'Recherches « où le trouver » sur la fiche du produit.', draw: (p) => CH.bars(p, { items: bySearch.map((x) => ({ label: x.name, value: x.searches })), color: C.searches }), head: ['Produit', 'Recherches', 'Fiches vues', 'Unités commandées'], rows: bySearch.map((x) => [x.name, fmt.num(x.searches), fmt.num(x.views), fmt.num(x.qty)]), align: ['', 'r', 'r', 'r'] })}
        ${CH.card({ title: 'Tailles les plus demandées', sub: 'Tailles choisies dans les recherches.', draw: (p) => CH.bars(p, { items: d.sizes.map((x) => ({ label: x.label, value: x.n })), color: C.searches }), head: ['Taille', 'Recherches'], rows: d.sizes.map((x) => [x.label, fmt.num(x.n)]), align: ['', 'r'] })}
        ${CH.card({ title: 'Couleurs les plus demandées', sub: 'Couleurs choisies dans les recherches.', draw: (p) => CH.bars(p, { items: d.colors.map((x) => ({ label: x.label, value: x.n })), color: C.searches }), head: ['Couleur', 'Recherches'], rows: d.colors.map((x) => [x.label, fmt.num(x.n)]), align: ['', 'r'] })}
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">Demande par zone</h3><p class="card-sub">Chaque cercle est une wilaya : plus il est grand, plus on y cherche vos produits. Les épingles sont vos points de vente.</p></div></div>
        <div class="zone-grid">
          <div class="zone-map"><div id="zmap"></div><div class="map-legend"><span><i class="lg-circle" style="--c:${C.searches}"></i>Recherches</span><span><i class="lg-pin"></i>Point de vente</span></div></div>
          ${CH.table(['Zone', 'Recherches', 'Commandes', 'Chiffre d\'affaires', 'Points de vente'], zones.slice(0, 14).map((z) => [z.name, fmt.num(z.searches), fmt.num(z.orders), money(z.revenue), z.stores ? fmt.num(z.stores) : html`<span class="badge badge-warn">Aucun</span>`]), ['', 'r', 'r', 'r', 'r'])}
        </div>
      </section>`;
    const map = ML.makeMap($('#zmap'), { wheel: false });
    const max = Math.max(1, ...zones.map((z) => z.searches));
    const pts = [];
    zones.filter((z) => z.searches > 0).forEach((z) => {
      L.circleMarker([z.lat, z.lng], { radius: 6 + Math.sqrt(z.searches / max) * 26, color: '#fff', weight: 2, fillColor: C.searches, fillOpacity: 0.55 })
        .addTo(map).bindTooltip(html`<b>${z.name}</b><br>${fmt.plural(z.searches, 'recherche')} · ${fmt.plural(z.orders, 'commande')}`.toString(), { direction: 'top' });
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
    el.innerHTML = !stores.length ? html`<div class="card"><div class="empty">${icon('store')}<h3>Pas encore de point de vente à analyser</h3><p>Ajoutez vos magasins et leur stock : leurs résultats apparaîtront ici.</p><a class="btn btn-primary" href="#/points-de-vente/nouveau">Ajouter un point de vente</a></div></div>` : html`
      <div class="tiles">
        <div class="tile"><span class="tile-label">Points de vente actifs</span><b class="tile-value">${fmt.num(stores.length)}</b><span class="tile-sub dim">${brand ? fmt.plural(stores.filter((s) => !s.own).length, 'chez un distributeur', 'chez des distributeurs') : 'Dans votre réseau'}</span></div>
        <div class="tile"><span class="tile-label">Meilleur point de vente</span><b class="tile-value tile-text">${best && best.revenue ? best.name : '—'}</b><span class="tile-sub dim">${best && best.revenue ? moneyShort(best.revenue) + ' · ' + Math.round((best.revenue / Math.max(1, totalRev)) * 100) + ' % du total' : 'Aucune vente sur la période'}</span></div>
        <div class="tile"><span class="tile-label">Disponibilité moyenne</span><b class="tile-value">${avgAvail == null ? '—' : avgAvail + ' %'}</b><span class="tile-sub dim">Références en stock / références suivies</span></div>
        <div class="tile"><span class="tile-label">Confirmation la plus lente</span><b class="tile-value">${slow ? fmt.dec(slow.confirm_h) + ' h' : '—'}</b><span class="tile-sub dim">${slow ? slow.name : 'Aucune commande confirmée'}</span></div>
      </div>
      ${CH.card({ title: 'Chiffre d\'affaires par point de vente', sub: brand ? 'Ventes de vos produits, dans vos magasins et chez vos distributeurs.' : 'Ventes de vos magasins.', draw: (p) => CH.bars(p, { items: stores.filter((s) => s.revenue > 0).slice(0, 10).map((s) => ({ label: s.name, value: s.revenue, sub: s.own ? '' : s.company })), color: C.revenue, format: money }), head: ['Point de vente', 'Chiffre d\'affaires', 'Commandes'], rows: stores.map((s) => [s.name, money(s.revenue), fmt.num(s.orders)]), align: ['', 'r', 'r'] })}
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">Tableau de bord des points de vente</h3><p class="card-sub">Cliquez sur un titre de colonne pour trier.</p></div></div>
        <div data-perf></div>
      </section>
      ${brand && d.partners.length > 1 ? html`<section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">Par distributeur</h3><p class="card-sub">Vos magasins comparés à ceux de chaque distributeur.</p></div></div>
        ${CH.table(['Réseau', 'Points de vente', 'Commandes', 'Chiffre d\'affaires', 'Menées à terme', 'Disponibilité'], d.partners.map((p) => [html`<b>${p.name}</b>${p.own ? html` <span class="badge">Vos magasins</span>` : ''}`, fmt.num(p.stores), fmt.num(p.orders), money(p.revenue), CH.meter(p.done_rate), CH.meter(p.availability)]), ['', 'r', 'r', 'r', '', ''])}
      </section>` : ''}`;
    if (!stores.length) return;
    const COLS = [['name', 'Point de vente'], ['orders', 'Commandes'], ['revenue', 'Chiffre d\'affaires'], ['done_rate', 'Menées à terme'], ['cancel_rate', 'Annulées'], ['confirm_h', 'Confirmation'], ['availability', 'Disponibilité'], ['views', 'Intérêt']];
    let sort = 'revenue'; let dir = -1;
    const paint = () => {
      const rows = stores.slice().sort((a, b) => {
        const x = a[sort]; const y = b[sort];
        if (sort === 'name') return String(x).localeCompare(String(y), 'fr') * -dir;
        return ((x == null ? -Infinity : x) - (y == null ? -Infinity : y)) * dir;
      });
      $('[data-perf]').innerHTML = html`<div class="tbl-wrap"><table class="tbl tbl-perf">
        <thead><tr>${COLS.map(([k, label], i) => html`<th class="${i > 0 && i < 3 ? 'r' : ''}" aria-sort="${sort === k ? (dir < 0 ? 'descending' : 'ascending') : 'none'}"><button type="button" data-act="perf-sort" data-k="${k}">${label}${sort === k ? icon(dir < 0 ? 'arrow-down' : 'arrow-up', 'ico-sm') : ''}</button></th>`)}</tr></thead>
        <tbody>${rows.map((s) => html`<tr>
          <td><b>${s.name}</b><small class="muted">${s.city}${s.own ? '' : ' · ' + s.company}</small></td>
          <td class="r num">${fmt.num(s.orders)}</td>
          <td class="r num">${money(s.revenue)}</td>
          <td>${CH.meter(s.done_rate)}</td>
          <td>${CH.meter(s.cancel_rate, 'bad')}</td>
          <td class="num">${s.confirm_h == null ? html`<span class="dim">—</span>` : fmt.dec(s.confirm_h) + ' h'}</td>
          <td>${CH.meter(s.availability)}</td>
          <td class="num">${fmt.plural(s.views, 'vue')}<small class="muted">${fmt.num(s.contacts)} appels et itinéraires</small></td>
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
        <div class="tile"><span class="tile-label">Demande couverte</span><b class="tile-value">${cov.share == null ? '—' : cov.share + ' %'}</b><span class="tile-sub dim">des recherches localisées à moins de ${fmt.num(d.cover_km)} km d'un point de vente</span></div>
        <div class="tile"><span class="tile-label">Zones à conquérir</span><b class="tile-value">${fmt.num(ex.length)}</b><span class="tile-sub dim">wilayas où l'on cherche vos produits, sans point de vente proche</span></div>
        <div class="tile"><span class="tile-label">Recherches hors couverture</span><b class="tile-value">${fmt.num(lost)}</b><span class="tile-sub dim">sur ${fmt.num(cov.located)} recherches localisées</span></div>
      </div>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">Carte des opportunités</h3><p class="card-sub">En orange, les zones où la demande existe sans point de vente à moins de ${fmt.num(d.cover_km)} km. En bleu, la demande déjà couverte.</p></div></div>
        <div class="zone-map is-tall"><div id="xmap"></div><div class="map-legend"><span><i class="lg-circle" style="--c:${C.orders}"></i>À conquérir</span><span><i class="lg-circle" style="--c:${C.searches}"></i>Couverte</span><span><i class="lg-pin"></i>Point de vente</span></div></div>
      </section>
      <section class="card card-pad">
        <div class="card-head"><div><h3 class="card-title">Où ouvrir ou recruter en priorité</h3><p class="card-sub">Classées par nombre de recherches sur la période.</p></div></div>
        ${ex.length ? html`<ol class="opps">${ex.map((z, i) => html`
          <li>
            <span class="opp-rank">${i + 1}</span>
            <div class="grow">
              <h4>${z.name}</h4>
              <p class="muted">${fmt.plural(z.searches, 'recherche')}${z.top ? html` · surtout <b>${z.top}</b>` : ''}${z.nearest ? html` · point de vente le plus proche : ${z.nearest} (${fmt.num(z.nearest_km)} km)` : ' · aucun point de vente dans votre réseau'}</p>
            </div>
            <a class="btn btn-soft btn-sm" href="${brand ? '#/reseau' : '#/points-de-vente/nouveau'}">${icon(brand ? 'handshake' : 'plus', 'ico-sm')}${brand ? 'Trouver un distributeur' : 'Ouvrir un point de vente'}</a>
          </li>`)}</ol>` : html`<div class="empty">${icon('target')}<h3>${cov.located ? 'Votre réseau couvre la demande' : 'Pas encore assez de recherches localisées'}</h3><p>${cov.located ? 'Toutes les zones où l\'on cherche vos produits ont un point de vente à proximité.' : 'Dès que des visiteurs chercheront vos produits en indiquant leur ville, les zones à fort potentiel apparaîtront ici.'}</p></div>`}
      </section>`;
    const map = ML.makeMap($('#xmap'), { wheel: false });
    const open = new Set(ex.map((z) => z.code));
    const zones = d.zones.filter((z) => z.searches > 0);
    const max = Math.max(1, ...zones.map((z) => z.searches));
    const pts = [];
    zones.forEach((z) => {
      const isOpen = open.has(z.code);
      L.circleMarker([z.lat, z.lng], { radius: 6 + Math.sqrt(z.searches / max) * 26, color: '#fff', weight: 2, fillColor: isOpen ? C.orders : C.searches, fillOpacity: isOpen ? 0.7 : 0.4 })
        .addTo(map).bindTooltip(html`<b>${z.name}</b> — ${isOpen ? 'à conquérir' : 'couverte'}<br>${fmt.plural(z.searches, 'recherche')}${z.nearest_km != null ? html` · point de vente à ${fmt.num(z.nearest_km)} km` : ''}`.toString(), { direction: 'top' });
      pts.push([z.lat, z.lng]);
    });
    d.stores.forEach((s) => { L.marker([s.lat, s.lng], { icon: ML.pin(s.own ? '#ff5a2b' : '#344054', 'pin-sm'), title: s.name, interactive: false, keyboard: false }).addTo(map); pts.push([s.lat, s.lng]); });
    if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.12), { maxZoom: 9, animate: false });
    E.cleanup = () => map.remove();
  }
})();
