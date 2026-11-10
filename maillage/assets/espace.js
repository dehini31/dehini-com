/*
 * Maillage — espace entreprise (dehini.com/maillage/espace/) : connexion, cadre, vue d'ensemble,
 * commandes, réseau et paramètres. Le catalogue, les points de vente et la disponibilité sont
 * dans espace-catalog.js ; les analyses dans espace-analytics.js.
 *
 *   #/                      vue d'ensemble          #/commandes            commandes à traiter
 *   #/catalogue             produits et marques     #/points-de-vente      magasins
 *   #/disponibilite         stock par magasin       #/reseau               partenaires et journal
 *   #/analyses              analyses commerciales   #/parametres           entreprise et compte
 */
(function () {
  'use strict';
  const { html, icon, fmt, api, color } = ML;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const E = ML.E = { me: null, views: {}, cleanup: null, $, $$ };
  const KINDS = { brand: 'Entreprise / marque', distributor: 'Distributeur' };
  const NAV = [
    ['', 'layout-dashboard', 'Vue d\'ensemble'],
    ['commandes', 'receipt-text', 'Commandes'],
    ['catalogue', 'package', 'Catalogue'],
    ['points-de-vente', 'store', 'Points de vente'],
    ['disponibilite', 'boxes', 'Disponibilité'],
    ['reseau', 'waypoints', 'Réseau'],
    ['analyses', 'chart-column', 'Analyses'],
    ['parametres', 'settings', 'Paramètres'],
  ];
  const view = E.view = () => $('#view');
  E.loading = () => { view().innerHTML = '<div class="loading"><div class="spinner"></div></div>'; };
  E.head = (title, sub, actions) => html`<div class="vhead"><div><h1>${title}</h1>${sub ? html`<p class="muted">${sub}</p>` : ''}</div>${actions ? html`<div class="vhead-actions">${actions}</div>` : ''}</div>`;
  const isBrand = E.isBrand = () => E.me.company.kind === 'brand';
  /** Appel d'écriture : affiche l'erreur (dont « lecture seule » en démonstration) et retourne null. */
  E.send = async (action, body, form) => {
    try { return await api(action, { body }); } catch (err) {
      if (err.code === 'auth') { location.reload(); return null; }
      ML.fail(err, form);
      return null;
    }
  };

  /* ============================================================ CONNEXION */
  function authView(mode, token) {
    document.body.className = 'es es-auth';
    const forms = {
      login: html`
        <h1>Bon retour</h1>
        <p class="muted">Connectez-vous pour gérer votre réseau de points de vente.</p>
        <form class="stack" data-form="login">
          <div class="field"><label for="l-email">Email</label><input class="input" id="l-email" name="email" type="email" autocomplete="username" required></div>
          <div class="field"><label for="l-pass">Mot de passe</label><input class="input" id="l-pass" name="password" type="password" autocomplete="current-password" required></div>
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Se connecter</button>
        </form>
        <p class="auth-links"><a class="link" href="#/oubli">Mot de passe oublié ?</a></p>
        <div class="auth-alt">
          <a class="btn btn-line btn-block" href="../#inscription">Créer mon espace</a>
          <button class="btn btn-ghost btn-block" type="button" data-act="demo">${icon('play')}Explorer la démonstration</button>
        </div>`,
      forgot: html`
        <h1>Mot de passe oublié</h1>
        <p class="muted">Indiquez l'email de votre compte : vous recevrez un lien pour en choisir un nouveau.</p>
        <form class="stack" data-form="forgot">
          <div class="field"><label for="f-email">Email</label><input class="input" id="f-email" name="email" type="email" autocomplete="username" required></div>
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Recevoir le lien</button>
        </form>
        <p class="auth-links"><a class="link" href="#/">Revenir à la connexion</a></p>`,
      reset: html`
        <h1>Nouveau mot de passe</h1>
        <p class="muted">Choisissez un mot de passe d'au moins 8 caractères.</p>
        <form class="stack" data-form="reset">
          <div class="field"><label for="r-pass">Nouveau mot de passe</label><input class="input" id="r-pass" name="password" type="password" autocomplete="new-password" minlength="8" required></div>
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit">Enregistrer et me connecter</button>
        </form>`,
    };
    $('#app').innerHTML = html`
      <div class="auth">
        <div class="auth-card">
          <a href="../" aria-label="Maillage, accueil">${ML.logo()}</a>
          <div data-auth>${forms[mode] || forms.login}</div>
        </div>
        <aside class="auth-side" aria-hidden="true">
          <div class="auth-quote">
            <p>Chaque point de vente devient un point de données : ce qui est cherché, ce qui est disponible, ce qui se vend.</p>
            <ul><li>${icon('map-pin')}Carte interactive de votre réseau</li><li>${icon('boxes')}Disponibilité par magasin</li><li>${icon('chart-column')}Demande par produit et par zone</li></ul>
          </div>
        </aside>
      </div>`;
    ML.forms.login = async (f) => {
      const b = $('button[type=submit]', f);
      ML.busy(b, true);
      try { await api('login', { body: ML.formData(f) }); location.hash = '#/'; location.reload(); } catch (e) { ML.fail(e, f); ML.busy(b, false); }
    };
    ML.forms.forgot = async (f) => {
      const b = $('button[type=submit]', f);
      ML.busy(b, true);
      try {
        await api('forgot', { body: ML.formData(f) });
        $('[data-auth]').innerHTML = html`<h1>Vérifiez vos emails</h1><p class="muted">Si un compte existe avec cette adresse, un lien vient de lui être envoyé. Il est valable une heure.</p><p class="auth-links"><a class="link" href="#/">Revenir à la connexion</a></p>`;
      } catch (e) { ML.fail(e, f); ML.busy(b, false); }
    };
    ML.forms.reset = async (f) => {
      const b = $('button[type=submit]', f);
      ML.busy(b, true);
      try { await api('reset', { body: { token, password: f.password.value } }); location.hash = '#/'; location.reload(); } catch (e) { ML.fail(e, f); ML.busy(b, false); }
    };
    ML.actions.demo = async (el) => {
      ML.busy(el, true);
      try { await api('demo_login', { body: {} }); location.hash = '#/'; location.reload(); } catch (e) { ML.fail(e); ML.busy(el, false); }
    };
  }

  /* ================================================================ CADRE */
  function shell() {
    const c = E.me.company;
    document.body.className = 'es';
    const banner = E.me.readonly
      ? html`<div class="banner is-demo">${icon('eye')}<span><b>Démonstration en lecture seule.</b> Explorez librement : rien n'est enregistré.</span><a class="btn btn-white btn-sm" href="../#inscription">Créer mon espace</a></div>`
      : E.me.admin ? html`<div class="banner is-admin">${icon('shield-check')}<span>Espace ouvert depuis l'administration : vous agissez au nom de <b>${c.name}</b>.</span></div>`
        : c.status === 'pending' ? html`<div class="banner">${icon('hourglass')}<span><b>Inscription en cours de validation.</b> Préparez votre réseau dès maintenant : il apparaîtra sur la plateforme dès la validation.</span></div>`
          : c.status === 'suspended' ? html`<div class="banner is-bad">${icon('triangle-alert')}<span><b>Espace suspendu.</b> Votre réseau n'apparaît plus sur la plateforme. Contactez-nous pour le rétablir.</span></div>` : '';
    $('#app').innerHTML = html`
      <div class="app">
        <aside class="side" aria-label="Menu">
          <div class="side-top"><a href="#/">${ML.logo('on-dark')}</a><button class="btn btn-glass btn-icon btn-sm side-close" type="button" data-act="menu" aria-label="Fermer le menu">${icon('x')}</button></div>
          <a class="side-co" href="#/parametres">${ML.mono(c.name, '#ff5a2b', c.logo)}<span class="grow"><b class="truncate" data-co-name>${c.name}</b><small>${KINDS[c.kind] || ''}</small></span></a>
          <nav class="side-nav">${NAV.map(([path, ic, label]) => html`<a href="#/${path}" data-nav="${path}">${icon(ic)}<span>${label}</span>${path === 'commandes' ? html`<em data-todo hidden></em>` : ''}</a>`)}</nav>
          <div class="side-foot">
            <a href="../explorer/" target="_blank" rel="noopener">${icon('external-link')}<span>Voir la plateforme</span></a>
            <button type="button" data-act="logout">${icon('log-out')}<span>${E.me.readonly ? 'Quitter la démonstration' : 'Se déconnecter'}</span></button>
          </div>
        </aside>
        <div class="main">
          <header class="mtop"><button class="btn btn-ghost btn-icon" type="button" data-act="menu" aria-label="Ouvrir le menu">${icon('menu')}</button><a href="#/">${ML.logo()}</a><a class="btn btn-ghost btn-icon" href="#/commandes" aria-label="Commandes">${icon('receipt-text')}</a></header>
          ${banner}
          <main id="view" tabindex="-1"></main>
        </div>
        <nav class="mtab" aria-label="Navigation">
          <a href="#/" data-nav="">${icon('layout-dashboard')}<span>Accueil</span></a>
          <a href="#/commandes" data-nav="commandes">${icon('receipt-text')}<span>Commandes</span><em data-todo hidden></em></a>
          <a href="#/disponibilite" data-nav="disponibilite">${icon('boxes')}<span>Stock</span></a>
          <a href="#/analyses" data-nav="analyses">${icon('chart-column')}<span>Analyses</span></a>
          <button type="button" data-act="menu">${icon('menu')}<span>Menu</span></button>
        </nav>
        <div class="side-veil" data-act="menu"></div>
      </div>`;
  }
  ML.actions.menu = () => document.body.classList.toggle('side-open');
  ML.actions.logout = async () => { try { await api('logout', { body: {} }); } catch (e) { /* session déjà close */ } location.href = E.me.readonly ? '../' : './'; };
  E.todo = (n) => $$('[data-todo]').forEach((el) => { el.hidden = !n; el.textContent = n > 99 ? '99+' : n; });

  async function route() {
    const r = ML.route();
    const name = r.parts[0] || '';
    document.body.classList.remove('side-open');
    if (E.cleanup) { try { E.cleanup(); } catch (e) { /* vue déjà retirée */ } E.cleanup = null; }
    $$('[data-nav]').forEach((a) => a.classList.toggle('is-on', a.dataset.nav === name));
    window.scrollTo(0, 0);
    const v = E.views[name] || E.views[''];
    try {
      await v(r);
    } catch (e) {
      if (e.code === 'auth') { location.reload(); return; }
      view().innerHTML = html`<div class="empty">${icon('wifi-off')}<h3>Chargement impossible</h3><p>${e.message}</p><button class="btn btn-dark" data-act="retry">Réessayer</button></div>`;
    }
  }
  ML.actions.retry = () => route();
  E.reload = route;

  /* ====================================================== VUE D'ENSEMBLE */
  const orderRow = (o) => html`
    <li><button class="orow" type="button" data-act="order-open" data-id="${o.id}">
      <span class="orow-ic">${icon(o.kind === 'reservation' ? 'bookmark-check' : o.mode === 'delivery' ? 'truck' : 'shopping-bag')}</span>
      <span class="grow"><b>${o.ref} · ${o.name}</b><small class="muted truncate">${o.first}${o.items > 1 ? ' + ' + (o.items - 1) : ''} · ${o.store}</small></span>
      <span class="orow-end">${ML.statusBadge(o)}<small class="dim">${fmt.ago(o.created)}</small></span>
    </button></li>`;
  const ACT_ICONS = { order: 'shopping-bag', status: 'refresh-cw', stock: 'boxes', store: 'store', product: 'package', brand: 'tag', partner: 'handshake' };
  E.activityList = (list) => (list.length ? html`<ul class="feed">${list.map((a) => html`<li><span class="feed-ic">${icon(ACT_ICONS[a.kind] || 'circle')}</span><span class="grow">${a.detail}${a.own ? '' : html`<small class="muted"> · ${a.company}</small>`}</span><small class="dim nowrap">${fmt.ago(a.created)}</small></li>`)}</ul>` : html`<p class="muted">L'activité de votre réseau apparaîtra ici.</p>`);

  E.views[''] = async () => {
    E.loading();
    const d = await api('overview');
    const t = d.tiles;
    E.todo(t.to_process);
    const brand = isBrand();
    const steps = brand
      ? [['brand', 'Créer votre marque', 'Nom, couleur et présentation.', '#/catalogue?t=marques'], ['product', 'Ajouter un produit', 'Photos, prix, tailles et couleurs.', '#/catalogue/produit/nouveau'], ['store', 'Ajouter un point de vente', 'Adresse, horaires et position sur la carte.', '#/points-de-vente/nouveau'], ['stock', 'Indiquer la disponibilité', 'Ce qui est en stock, magasin par magasin.', '#/disponibilite']]
      : [['store', 'Ajouter un point de vente', 'Adresse, horaires et position sur la carte.', '#/points-de-vente/nouveau'], ['partner', 'Rejoindre le réseau d\'une marque', 'Demandez à distribuer son catalogue.', '#/reseau'], ['stock', 'Indiquer la disponibilité', 'Ce qui est en stock, magasin par magasin.', '#/disponibilite']];
    const done = steps.filter(([k]) => d.steps[k]).length;
    const next = steps.find(([k]) => !d.steps[k]);
    const tile = (label, value, sub, href, extra) => html`<a class="tile ${extra || ''}" href="${href}"><span class="tile-label">${label}</span><b class="tile-value">${value}</b>${sub ? html`<span class="tile-sub">${sub}</span>` : ''}</a>`;
    view().innerHTML = html`
      ${E.head(E.me.readonly ? 'Bienvenue dans la démonstration' : 'Bonjour ' + E.me.user.name.split(' ')[0], new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date()),
        html`<a class="btn btn-line" href="#/disponibilite">${icon('boxes')}Mettre à jour le stock</a><a class="btn btn-primary" href="#/commandes">${icon('receipt-text')}Commandes${t.to_process ? ' · ' + t.to_process : ''}</a>`)}
      ${done < steps.length ? html`
        <section class="card card-pad onboard">
          <div class="onboard-head">
            <div><h2 class="card-title">Mettez votre réseau en ligne</h2><p class="card-sub">${done} étape${done > 1 ? 's' : ''} sur ${steps.length} terminée${done > 1 ? 's' : ''}</p></div>
            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="${steps.length}" aria-valuenow="${done}"><i style="width:${(done / steps.length) * 100}%"></i></div>
          </div>
          <ol class="onboard-steps">${steps.map(([k, title, sub, href], i) => html`<li class="${d.steps[k] ? 'is-done' : ''} ${next && next[0] === k ? 'is-next' : ''}"><a href="${href}"><i>${d.steps[k] ? icon('check', 'ico-sm') : i + 1}</i><span><b>${title}</b><small>${sub}</small></span>${icon('chevron-right', 'ico-sm')}</a></li>`)}</ol>
        </section>` : ''}
      <div class="tiles is-6">
        ${tile('Commandes à traiter', fmt.num(t.to_process), t.in_progress ? fmt.plural(t.in_progress, 'en cours', 'en cours') : 'Rien en attente', '#/commandes', t.to_process ? 'is-hot' : '')}
        <a class="tile" href="#/analyses"><span class="tile-label">Commandes · 30 jours</span><b class="tile-value">${fmt.num(t.orders30)}</b><span class="tile-sub">${ML.charts.spark(d.trend.orders, ML.charts.C.orders)}<span class="sr">Évolution sur 14 jours</span></span></a>
        ${tile('Chiffre d\'affaires · 30 jours', fmt.compact(t.revenue30) + ' ' + ML.boot.config.currency, 'Hors commandes annulées', '#/analyses')}
        ${tile('Recherches · 30 jours', fmt.compact(t.searches30), 'Sur vos produits', '#/analyses')}
        ${tile('Points de vente', fmt.num(brand ? t.network_stores : t.stores), brand && t.network_stores > t.stores ? 'dont ' + (t.network_stores - t.stores) + ' chez vos distributeurs' : 'Actifs sur la carte', '#/points-de-vente')}
        ${tile('Produits en ligne', fmt.num(t.products), t.low_stock ? fmt.plural(t.low_stock, 'référence', 'références') + ' à réapprovisionner' : 'Stock à jour', t.products ? '#/disponibilite' : '#/catalogue')}
      </div>
      <div class="cols">
        <section class="card card-pad">
          <div class="card-head"><h2 class="card-title">Dernières commandes</h2><a class="link" href="#/commandes?s=all">Tout voir</a></div>
          ${d.recent.length ? html`<ul class="olist">${d.recent.map(orderRow)}</ul>` : html`<div class="empty">${icon('receipt-text')}<h3>Pas encore de commande</h3><p>Dès qu'un client commande ou réserve dans l'un de vos points de vente, elle apparaît ici.</p></div>`}
        </section>
        <div class="stack">
          ${d.low.length ? html`<section class="card card-pad">
            <div class="card-head"><h2 class="card-title">Stock à surveiller</h2><a class="link" href="#/disponibilite">Mettre à jour</a></div>
            <ul class="lowlist">${d.low.slice(0, 6).map((l) => html`<li><span class="grow"><b>${l.product}</b>${l.option ? html` <span class="muted">· ${l.option}</span>` : ''}<small class="muted">${l.store}</small></span><span class="badge ${l.qty === 0 ? 'badge-bad' : 'badge-warn'}">${l.qty === 0 ? 'Rupture' : 'Reste ' + l.qty}</span></li>`)}</ul>
          </section>` : ''}
          <section class="card card-pad">
            <div class="card-head"><h2 class="card-title">Activité du réseau</h2><a class="link" href="#/reseau">Journal</a></div>
            ${E.activityList(d.activity)}
          </section>
        </div>
      </div>`;
  };

  /* ============================================================ COMMANDES */
  const O_TABS = [['open', 'En cours'], ['pending', 'En attente'], ['confirmed', 'Confirmées'], ['ready', 'Prêtes'], ['shipped', 'En livraison'], ['done', 'Terminées'], ['cancelled', 'Annulées'], ['all', 'Toutes']];
  const NEXT_LABEL = (o, s) => ({ confirmed: 'Confirmer', ready: o.kind === 'reservation' ? 'Article mis de côté' : 'Prête au retrait', shipped: 'Expédiée', done: o.mode === 'delivery' ? 'Livrée' : 'Retirée par le client', cancelled: 'Annuler' }[s]);

  E.views.commandes = async (r) => {
    const F = { status: r.query.s || 'open', store: r.query.pdv || '', q: r.query.q || '' };
    const stores = (await api('dash_stores')).stores;
    view().innerHTML = html`
      ${E.head('Commandes', 'Commandes et réservations reçues dans vos points de vente.')}
      <div class="toolbar">
        <div class="chips" data-tabs></div>
        <div class="toolbar-row">
          <form class="input-icon grow" data-form="oq" role="search">${icon('search')}<input class="input input-sm" type="search" name="q" placeholder="N° de commande, client, téléphone…" value="${F.q}" aria-label="Chercher une commande"></form>
          ${stores.length > 1 ? html`<select class="select input-sm" data-change="ostore" aria-label="Point de vente"><option value="">Tous les points de vente</option>${stores.map((s) => html`<option value="${s.id}" ${String(s.id) === F.store ? ML.raw('selected') : ''}>${s.name}</option>`)}</select>` : ''}
        </div>
      </div>
      <div data-list><div class="loading"><div class="spinner"></div></div></div>`;
    const load = async () => {
      const d = await api('orders', { params: { status: F.status, store: F.store, q: F.q } });
      const c = d.counts;
      const n = { open: c.pending + c.confirmed + c.ready + c.shipped, all: Object.values(c).reduce((a, b) => a + b, 0) };
      E.todo(c.pending);
      $('[data-tabs]').innerHTML = html`${O_TABS.map(([k, label]) => html`<button class="chip ${F.status === k ? 'is-on' : ''}" type="button" data-act="otab" data-k="${k}">${label}<small>${fmt.num(n[k] != null ? n[k] : c[k])}</small></button>`)}`;
      $('[data-list]').innerHTML = d.orders.length ? html`
        <div class="tbl-wrap card"><table class="tbl tbl-orders">
          <thead><tr><th>Commande</th><th>Client</th><th>Article</th><th>Point de vente</th><th class="r">Total</th><th>Statut</th></tr></thead>
          <tbody>${d.orders.map((o) => html`<tr class="is-link" data-act="order-open" data-id="${o.id}" tabindex="0">
            <td data-l="Commande"><b>${o.ref}</b><small class="muted">${icon(o.kind === 'reservation' ? 'bookmark-check' : o.mode === 'delivery' ? 'truck' : 'shopping-bag', 'ico-sm')}${ML.kindLabel(o)} · ${fmt.ago(o.created)}</small></td>
            <td data-l="Client">${o.name}<small class="muted">${o.phone}</small></td>
            <td data-l="Article">${o.first}${o.units > 1 ? html` <span class="muted">× ${o.units}</span>` : ''}</td>
            <td data-l="Point de vente">${o.store}</td>
            <td data-l="Total" class="r num"><b>${fmt.price(o.total)}</b></td>
            <td data-l="Statut">${ML.statusBadge(o)}</td>
          </tr>`)}</tbody></table></div>`
        : html`<div class="card"><div class="empty">${icon('receipt-text')}<h3>${F.q ? 'Aucune commande ne correspond' : F.status === 'open' ? 'Aucune commande en cours' : 'Aucune commande ici'}</h3><p>${F.status === 'open' && !F.q ? 'Tout est traité. Les nouvelles commandes arrivent ici dès qu\'un client valide sa demande.' : 'Changez de filtre pour en voir d\'autres.'}</p></div></div>`;
    };
    ML.actions.otab = (el) => { F.status = el.dataset.k; history.replaceState(null, '', ML.href('/commandes', { s: F.status })); load(); };
    ML.changes.ostore = (el) => { F.store = el.value; load(); };
    ML.forms.oq = (f) => { F.q = f.q.value.trim(); load(); };
    E.afterOrder = load;
    E.cleanup = () => { E.afterOrder = null; };
    await load();
    if (r.query.id) openOrder(+r.query.id);
  };
  document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches && e.target.matches('tr.is-link')) e.target.click(); });

  async function openOrder(id) {
    let o;
    try { o = (await api('order_get', { params: { id } })).order; } catch (e) { ML.fail(e); return; }
    const body = (ord) => html`
      <div class="odetail">
        <div class="row row-wrap between">${ML.statusBadge(ord)}<span class="muted">${ML.kindLabel(ord)} · ${fmt.time(ord.created)}</span></div>
        ${ord.kind === 'reservation' && ord.expires && ord.next.length ? html`<p class="notice">${icon('clock')}<span>Article à garder de côté jusqu'au <b>${fmt.time(ord.expires)}</b>. Passé ce délai, la réservation est annulée et le stock libéré.</span></p>` : ''}
        <ul class="lines">${ord.items.map((i) => html`<li><span>${i.qty} × <b>${i.name}</b>${[i.size, i.color].filter(Boolean).length ? html`<small class="muted"> · ${[i.size, i.color].filter(Boolean).join(' · ')}</small>` : ''}<small class="muted"> · ${i.brand}</small></span><b>${fmt.price(i.price * i.qty)}</b></li>`)}<li class="total"><span>Total à encaisser ${ord.mode === 'delivery' ? 'à la livraison' : 'en magasin'}</span><b>${fmt.price(ord.total)}</b></li></ul>
        <div class="ogrid">
          <div><span class="label">Client</span><p><b>${ord.name}</b><br><a class="link" href="tel:${ord.phone}">${ord.phone}</a>${ord.email ? html`<br><a class="link" href="mailto:${ord.email}">${ord.email}</a>` : ''}</p></div>
          <div><span class="label">${ord.mode === 'delivery' ? 'Livraison' : 'Retrait'}</span><p>${ord.delivery ? html`${ord.delivery.address}<br>${ord.delivery.city}${ord.delivery.zone ? ' · ' + ord.delivery.zone : ''}` : html`${ord.store ? ord.store.name : ''}<br><span class="muted">${ord.store ? ord.store.city : ''}</span>`}</p></div>
        </div>
        ${ord.note ? html`<div><span class="label">Message du client</span><p class="onote">${ord.note}</p></div>` : ''}
        <div><span class="label">Historique</span><ul class="ohist">${ord.events.map((e) => html`<li><i></i><span><b>${ML.statusLabel({ status: e.status, mode: ord.mode })}</b>${e.note ? html` — ${e.note}` : ''}<small class="muted">${fmt.time(e.created)} · ${e.actor === 'client' ? 'client' : e.actor === 'system' ? 'automatique' : 'point de vente'}</small></span></li>`)}</ul></div>
      </div>`;
    const foot = (ord) => (ord.next.length ? html`${ord.next.filter((s) => s === 'cancelled').map(() => html`<button class="btn btn-danger" type="button" data-next="cancelled">Annuler la ${ord.kind === 'reservation' ? 'réservation' : 'commande'}</button>`)}${ord.next.filter((s) => s !== 'cancelled').map((s) => html`<button class="btn btn-primary" type="button" data-next="${s}">${icon('check')}${NEXT_LABEL(ord, s)}</button>`)}` : html`<button class="btn btn-line" type="button" data-close>Fermer</button>`);
    const m = ML.modal({ title: (o.kind === 'reservation' ? 'Réservation ' : 'Commande ') + o.ref, body: body(o), foot: foot(o), wide: true });
    m.el.addEventListener('click', async (e) => {
      const b = e.target.closest('[data-next]');
      if (!b) return;
      const status = b.dataset.next;
      let note = '';
      if (status === 'cancelled') {
        const reason = await askReason();
        if (reason === null) return;
        note = reason;
      }
      ML.busy(b, true);
      const res = await E.send('order_status', { id: o.id, status, note });
      ML.busy(b, false);
      if (!res) return;
      o = res.order;
      $('.modal-body', m.el).innerHTML = body(o);
      $('.modal-foot', m.el).innerHTML = foot(o);
      ML.toast('Statut mis à jour : ' + ML.statusLabel(o).toLowerCase(), 'good');
      if (E.afterOrder) E.afterOrder(); else E.reload();
    });
  }
  ML.actions['order-open'] = (el) => openOrder(+el.dataset.id);
  const askReason = () => new Promise((resolve) => {
    let answer = null;
    const m = ML.modal({
      title: 'Annuler la commande',
      body: html`<p class="muted" style="margin-bottom:12px">Les articles reviennent en stock. Le client voit ce motif dans son suivi.</p><div class="field"><label for="c-reason">Motif</label><select class="select" id="c-reason"><option>Client injoignable</option><option>Annulée à la demande du client</option><option>Article indisponible</option><option>Autre</option></select></div>`,
      foot: html`<button class="btn btn-line" type="button" data-close>Revenir</button><button class="btn btn-danger" type="button" data-yes>Confirmer l'annulation</button>`,
      onClose: () => resolve(answer),
    });
    $('[data-yes]', m.el).addEventListener('click', () => { answer = $('#c-reason', m.el).value; m.close(); });
  });

  /* =============================================================== RÉSEAU */
  E.views.reseau = async () => {
    E.loading();
    const d = await api('network');
    const brand = isBrand();
    const incoming = d.partners.filter((p) => p.status === 'pending' && !p.mine);
    const outgoing = d.partners.filter((p) => p.status === 'pending' && p.mine);
    const active = d.partners.filter((p) => p.status === 'active');
    const co = (c) => html`${ML.mono(c.name, c.kind === 'brand' ? '#2a78d6' : '#344054', c.logo)}<span class="grow"><b>${c.name}${c.demo ? html` <span class="badge">Démo</span>` : ''}</b><small class="muted">${[c.city, c.zone && c.zone !== c.city ? c.zone : ''].filter(Boolean).join(' · ') || KINDS[c.kind]}</small></span>`;
    view().innerHTML = html`
      ${E.head('Réseau', brand ? 'Les distributeurs qui vendent vos produits, et l\'activité de leurs points de vente.' : 'Les marques dont vous distribuez le catalogue.')}
      ${incoming.length ? html`<section class="card card-pad"><h2 class="card-title">Demandes reçues</h2><ul class="plist">${incoming.map((p) => html`<li>${co(p.company)}<span class="row"><button class="btn btn-line btn-sm" data-act="p-respond" data-id="${p.id}" data-ok="0">Refuser</button><button class="btn btn-primary btn-sm" data-act="p-respond" data-id="${p.id}" data-ok="1">${icon('check', 'ico-sm')}Accepter</button></span></li>`)}</ul></section>` : ''}
      <div class="cols">
        <div class="stack">
          <section class="card card-pad">
            <h2 class="card-title">${brand ? 'Vos distributeurs' : 'Vos marques'}</h2>
            ${active.length || outgoing.length ? html`<ul class="plist">
              ${active.map((p) => html`<li>${co(p.company)}<span class="pstats"><span><b>${fmt.num(p.stores)}</b> point${p.stores > 1 ? 's' : ''} de vente</span><span><b>${fmt.num(p.refs)}</b> référence${p.refs > 1 ? 's' : ''}</span><span>${p.refs ? ML.charts.meter(Math.round((p.instock / p.refs) * 100)) : ''}</span></span><button class="btn btn-ghost btn-sm" data-act="p-remove" data-id="${p.id}" data-name="${p.company.name}" aria-label="Mettre fin au partenariat avec ${p.company.name}">${icon('unlink', 'ico-sm')}</button></li>`)}
              ${outgoing.map((p) => html`<li>${co(p.company)}<span class="badge badge-warn">${icon('clock')}En attente de réponse</span><button class="btn btn-ghost btn-sm" data-act="p-remove" data-id="${p.id}" data-name="${p.company.name}" data-pending="1">Annuler</button></li>`)}
            </ul>` : html`<div class="empty">${icon('handshake')}<h3>${brand ? 'Pas encore de distributeur' : 'Pas encore de marque'}</h3><p>${brand ? 'Invitez un distributeur : il pourra indiquer vos produits disponibles dans ses magasins.' : 'Demandez à distribuer une marque pour référencer son catalogue dans vos magasins.'}</p></div>`}
          </section>
          <section class="card card-pad">
            <div class="card-head"><div><h2 class="card-title">${brand ? 'Distributeurs sur la plateforme' : 'Marques sur la plateforme'}</h2><p class="card-sub">${brand ? 'Proposez-leur de distribuer votre catalogue.' : 'Demandez à référencer leurs produits.'}</p></div></div>
            ${d.directory.length ? html`<ul class="plist">${d.directory.map((c) => html`<li>${co(c)}<span class="muted nowrap">${brand ? fmt.plural(c.stores, 'point de vente', 'points de vente') : fmt.plural(c.products, 'produit')}</span><button class="btn btn-soft btn-sm" data-act="p-request" data-id="${c.id}">${icon('plus', 'ico-sm')}${brand ? 'Inviter' : 'Demander'}</button></li>`)}</ul>` : html`<p class="muted">${brand ? 'Aucun autre distributeur inscrit pour le moment.' : 'Aucune autre marque inscrite pour le moment.'}</p>`}
          </section>
        </div>
        <section class="card card-pad">
          <h2 class="card-title">Journal du réseau</h2>
          <p class="card-sub" style="margin-bottom:12px">Commandes, mises à jour de stock et nouveaux points de vente.</p>
          ${E.activityList(d.activity)}
        </section>
      </div>`;
    ML.actions['p-request'] = async (el) => { ML.busy(el, true); if (await E.send('partner_request', { company_id: +el.dataset.id })) { ML.toast('Demande envoyée.', 'good'); E.reload(); } else ML.busy(el, false); };
    ML.actions['p-respond'] = async (el) => { ML.busy(el, true); if (await E.send('partner_respond', { id: +el.dataset.id, accept: el.dataset.ok === '1' })) { ML.toast(el.dataset.ok === '1' ? 'Partenariat activé.' : 'Demande refusée.', 'good'); E.reload(); } else ML.busy(el, false); };
    ML.actions['p-remove'] = async (el) => {
      const pending = el.dataset.pending === '1';
      if (!await ML.confirm(pending ? 'Annuler la demande envoyée à ' + el.dataset.name + ' ?' : 'Mettre fin au partenariat avec ' + el.dataset.name + ' ? Les produits de la marque seront retirés des magasins du distributeur.', { ok: pending ? 'Annuler la demande' : 'Mettre fin', danger: true })) return;
      if (await E.send('partner_remove', { id: +el.dataset.id })) E.reload();
    };
  };

  /* =========================================================== PARAMÈTRES */
  E.upload = async (file) => {
    const fd = new FormData();
    fd.append('file', file);
    const res = await E.send('upload', fd);
    return res ? res.path : null;
  };
  E.views.parametres = async () => {
    const c = E.me.company;
    const u = E.me.user;
    const zones = ML.boot.zones.slice().sort((a, b) => a.name.localeCompare(b.name, 'fr'));
    let logo = c.logo;
    view().innerHTML = html`
      ${E.head('Paramètres', 'Votre entreprise telle qu\'elle apparaît sur la plateforme, et votre compte.')}
      <div class="cols">
        <form class="card card-pad stack" data-form="company">
          <h2 class="card-title">Entreprise</h2>
          <div class="logo-edit"><span data-logo>${ML.mono(c.name, '#ff5a2b', logo, 'mono-lg')}</span><div><label class="btn btn-line btn-sm">${icon('upload', 'ico-sm')}Changer le logo<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-change="co-logo"></label><p class="hint muted">JPG, PNG ou WebP, carré de préférence.</p></div></div>
          <div class="form-grid cols-2">
            <div class="field span-2"><label for="c-name">Nom</label><input class="input" id="c-name" name="name" required value="${c.name}"></div>
            <div class="field span-2"><label for="c-about">Présentation</label><textarea class="textarea" id="c-about" name="about" maxlength="1500">${c.about}</textarea></div>
            <div class="field"><label for="c-phone">Téléphone</label><input class="input" id="c-phone" name="phone" type="tel" value="${c.phone}"></div>
            <div class="field"><label for="c-email">Email de contact</label><input class="input" id="c-email" name="email" type="email" value="${c.email}"><span class="hint">Reçoit les nouvelles commandes.</span></div>
            <div class="field"><label for="c-city">Ville</label><input class="input" id="c-city" name="city" value="${c.city}"></div>
            <div class="field"><label for="c-zone">Wilaya</label><select class="select" id="c-zone" name="wilaya"><option value="">—</option>${zones.map((z) => html`<option value="${z.code}" ${c.wilaya === z.code ? ML.raw('selected') : ''}>${z.name}</option>`)}</select></div>
            <div class="field"><label for="c-sector">Secteur</label><select class="select" id="c-sector" name="sector"><option value="">—</option>${ML.boot.categories.map((k) => html`<option value="${k.key}" ${c.sector === k.key ? ML.raw('selected') : ''}>${k.label}</option>`)}</select></div>
            <div class="field"><label for="c-web">Site web</label><input class="input" id="c-web" name="website" placeholder="https://" value="${c.website}"></div>
          </div>
          <p class="form-error" data-error hidden></p>
          <div><button class="btn btn-primary" type="submit">Enregistrer</button></div>
        </form>
        <div class="stack">
          <form class="card card-pad stack" data-form="account">
            <h2 class="card-title">Votre compte</h2>
            <div class="field"><label for="a-name">Nom</label><input class="input" id="a-name" name="name" required value="${u.name}"></div>
            <div class="field"><label for="a-email">Email de connexion</label><input class="input" id="a-email" name="email" type="email" required value="${u.email}"></div>
            <div class="field"><label for="a-phone">Téléphone</label><input class="input" id="a-phone" name="phone" type="tel" value="${u.phone}"></div>
            <p class="form-error" data-error hidden></p>
            <div><button class="btn btn-line" type="submit">Enregistrer</button></div>
          </form>
          <form class="card card-pad stack" data-form="password">
            <h2 class="card-title">Mot de passe</h2>
            <div class="field"><label for="p-cur">Mot de passe actuel</label><input class="input" id="p-cur" name="current" type="password" autocomplete="current-password"></div>
            <div class="field"><label for="p-new">Nouveau mot de passe</label><input class="input" id="p-new" name="password" type="password" autocomplete="new-password" minlength="8" required><span class="hint">8 caractères au minimum.</span></div>
            <p class="form-error" data-error hidden></p>
            <div><button class="btn btn-line" type="submit">Changer le mot de passe</button></div>
          </form>
        </div>
      </div>`;
    ML.changes['co-logo'] = async (el) => {
      if (!el.files[0]) return;
      const p = await E.upload(el.files[0]);
      if (p) { logo = p; $('[data-logo]').innerHTML = ML.mono(c.name, '#ff5a2b', logo, 'mono-lg'); }
      el.value = '';
    };
    ML.forms.company = async (f) => {
      const res = await E.send('company_save', Object.assign(ML.formData(f), { logo }), f);
      if (res) { E.me.company = res.company; $$('[data-co-name]').forEach((n) => { n.textContent = res.company.name; }); ML.toast('Entreprise enregistrée.', 'good'); }
    };
    ML.forms.account = async (f) => { const res = await E.send('account_save', ML.formData(f), f); if (res) { E.me.user = res.user; ML.toast('Compte enregistré.', 'good'); } };
    ML.forms.password = async (f) => { if (await E.send('password', ML.formData(f), f)) { f.reset(); ML.toast('Mot de passe changé.', 'good'); } };
  };

  /* ------------------------------------------------------------ démarrage */
  (async function init() {
    try {
      await ML.bootstrap();
      const me = await api('me');
      const r = ML.route();
      if (!me.user) {
        const mode = r.parts[0] === 'reset' ? 'reset' : r.parts[0] === 'oubli' ? 'forgot' : 'login';
        authView(mode, r.parts[1] || '');
        window.addEventListener('hashchange', () => { const x = ML.route(); authView(x.parts[0] === 'reset' ? 'reset' : x.parts[0] === 'oubli' ? 'forgot' : 'login', x.parts[1] || ''); });
        return;
      }
      E.me = me;
      ML.csrf = me.csrf;
      shell();
      window.addEventListener('hashchange', route);
      route();
    } catch (e) {
      document.getElementById('app').innerHTML = html`<div class="empty" style="padding-top:120px">${icon('wifi-off')}<h3>L'espace est momentanément indisponible</h3><p>${e.message}</p></div>`;
    }
  })();
})();
