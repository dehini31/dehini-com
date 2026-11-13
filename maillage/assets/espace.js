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
  const { html, icon, fmt, color } = ML;
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const E = ML.E = { me: null, views: {}, cleanup: null, seq: 0, $, $$ };
  class Stale extends Error {}
  /**
   * Appel à l'API lié à la page affichée : si l'on a changé de page avant la réponse, la suite
   * est abandonnée (sinon une vue lente écraserait celle qu'on vient d'ouvrir).
   */
  const api = E.api = async (action, opts) => {
    const my = E.seq;
    const res = await ML.api(action, opts);
    if (my !== E.seq) throw new Stale();
    return res;
  };
  const KINDS = () => ({ brand: ML.t('Entreprise / marque'), distributor: ML.t('Distributeur'), pos: ML.t('Point de vente') });
  /** Compte limité à un point de vente (créé par l'entreprise dans la fiche du magasin). */
  const isStore = E.isStore = () => !!(E.me && E.me.user.store_id);
  const STORE_NAV = () => [
    ['', 'layout-dashboard', ML.t('Vue d\'ensemble')],
    ['radar', 'target', ML.t('Radar')],
    ['commandes', 'receipt-text', ML.t('Commandes')],
    ['disponibilite', 'boxes', ML.t('Disponibilité')],
    ['points-de-vente', 'store', ML.t('Mon point de vente')],
    ['parametres', 'settings', ML.t('Mon compte')],
  ];
  const NAV = () => (isStore() ? STORE_NAV() : [
    ['', 'layout-dashboard', ML.t('Vue d\'ensemble')],
    ['commandes', 'receipt-text', ML.t('Commandes')],
    ['radar', 'target', ML.t('Radar')],
    ['catalogue', 'package', ML.t('Catalogue')],
    ['points-de-vente', 'store', ML.t('Points de vente')],
    ['disponibilite', 'boxes', ML.t('Disponibilité')],
    ['reseau', 'waypoints', ML.t('Réseau')],
    ['analyses', 'chart-column', ML.t('Analyses')],
    ['parametres', 'settings', ML.t('Paramètres')],
  ]);
  const view = E.view = () => $('#view');
  E.loading = () => { view().innerHTML = '<div class="loading"><div class="spinner"></div></div>'; };
  E.head = (title, sub, actions) => html`<div class="vhead"><div><h1>${title}</h1>${sub ? html`<p class="muted">${sub}</p>` : ''}</div>${actions ? html`<div class="vhead-actions">${actions}</div>` : ''}</div>`;
  const isBrand = E.isBrand = () => E.me.company.kind === 'brand';
  /** Appel d'écriture : affiche l'erreur (dont « lecture seule » en démonstration) et retourne null. */
  E.send = async (action, body, form) => {
    try { return await api(action, { body }); } catch (err) {
      if (err instanceof Stale) return null;
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
        <h1>${ML.t('Bon retour')}</h1>
        <p class="muted">${ML.t('Connectez-vous pour gérer votre réseau de points de vente.')}</p>
        <form class="stack" data-form="login">
          <div class="field"><label for="l-email">${ML.t('Email')}</label><input class="input" id="l-email" name="email" type="email" autocomplete="username" required></div>
          <div class="field"><label for="l-pass">${ML.t('Mot de passe')}</label><input class="input" id="l-pass" name="password" type="password" autocomplete="current-password" required></div>
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit">${ML.t('Se connecter')}</button>
        </form>
        <p class="auth-links"><a class="link" href="#/oubli">${ML.t('Mot de passe oublié ?')}</a></p>
        <div class="auth-alt">
          <a class="btn btn-line btn-block" href="../#inscription">${ML.t('Créer mon espace')}</a>
          <button class="btn btn-ghost btn-block" type="button" data-act="demo">${icon('play')}${ML.t('Explorer la démonstration')}</button>
        </div>`,
      forgot: html`
        <h1>${ML.t('Mot de passe oublié')}</h1>
        <p class="muted">${ML.t('Indiquez l\'email de votre compte : vous recevrez un lien pour en choisir un nouveau.')}</p>
        <form class="stack" data-form="forgot">
          <div class="field"><label for="f-email">${ML.t('Email')}</label><input class="input" id="f-email" name="email" type="email" autocomplete="username" required></div>
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit">${ML.t('Recevoir le lien')}</button>
        </form>
        <p class="auth-links"><a class="link" href="#/">${ML.t('Revenir à la connexion')}</a></p>`,
      reset: html`
        <h1>${ML.t('Nouveau mot de passe')}</h1>
        <p class="muted">${ML.t('Choisissez un mot de passe d\'au moins 8 caractères.')}</p>
        <form class="stack" data-form="reset">
          <div class="field"><label for="r-pass">${ML.t('Nouveau mot de passe')}</label><input class="input" id="r-pass" name="password" type="password" autocomplete="new-password" minlength="8" required></div>
          <p class="form-error" data-error hidden></p>
          <button class="btn btn-primary btn-lg btn-block" type="submit">${ML.t('Enregistrer et me connecter')}</button>
        </form>`,
    };
    $('#app').innerHTML = html`
      <div class="auth">
        <div class="auth-card">
          <div class="row between"><a href="../" aria-label="Maillage, accueil">${ML.logo()}</a>${ML.langToggle()}</div>
          <div data-auth>${forms[mode] || forms.login}</div>
        </div>
        <aside class="auth-side" aria-hidden="true">
          <div class="auth-quote">
            <p>${ML.t('Chaque point de vente devient un point de données : ce qui est cherché, ce qui est disponible, ce qui se vend.')}</p>
            <ul><li>${icon('map-pin')}${ML.t('Carte interactive de votre réseau')}</li><li>${icon('boxes')}${ML.t('Disponibilité par magasin')}</li><li>${icon('chart-column')}${ML.t('Demande par produit et par zone')}</li></ul>
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
        $('[data-auth]').innerHTML = html`<h1>${ML.t('Vérifiez vos emails')}</h1><p class="muted">${ML.t('Si un compte existe avec cette adresse, un lien vient de lui être envoyé. Il est valable une heure.')}</p><p class="auth-links"><a class="link" href="#/">${ML.t('Revenir à la connexion')}</a></p>`;
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
      ? html`<div class="banner is-demo">${icon('eye')}<span><b>${ML.t('Démonstration en lecture seule.')}</b> ${ML.t("Explorez librement : rien n'est enregistré.")}</span><a class="btn btn-white btn-sm" href="../#inscription">${ML.t('Créer mon espace')}</a></div>`
      : E.me.admin ? html`<div class="banner is-admin">${icon('shield-check')}<span>${ML.t("Espace ouvert depuis l'administration : vous agissez au nom de")} <b>${c.name}</b>.</span></div>`
        : c.status === 'pending' ? html`<div class="banner">${icon('hourglass')}<span><b>${ML.t('Inscription en cours de validation.')}</b> ${ML.t('Préparez votre réseau dès maintenant : il apparaîtra sur la plateforme dès la validation.')}</span></div>`
          : c.status === 'suspended' ? html`<div class="banner is-bad">${icon('triangle-alert')}<span><b>${ML.t('Espace suspendu.')}</b> ${ML.t("Votre réseau n'apparaît plus sur la plateforme. Contactez-nous pour le rétablir.")}</span></div>` : '';
    $('#app').innerHTML = html`
      <div class="app">
        <aside class="side" aria-label="Menu">
          <div class="side-top"><a href="#/">${ML.logo('on-dark')}</a>${ML.langToggle('on-dark')}<button class="btn btn-glass btn-icon btn-sm side-close" type="button" data-act="menu" aria-label="Fermer le menu">${icon('x')}</button></div>
          ${isStore() ? html`<a class="side-co" href="#/points-de-vente">${ML.mono(E.me.store ? E.me.store.name : c.name, '#0e9384')}<span class="grow"><b class="truncate">${E.me.store ? E.me.store.name : ''}</b><small class="truncate">${ML.t('Point de vente')} · ${c.name}</small></span></a>`
            : html`<a class="side-co" href="#/parametres">${ML.mono(c.name, '#ff5a2b', c.logo)}<span class="grow"><b class="truncate" data-co-name>${c.name}</b><small>${KINDS()[c.kind] || ''}</small></span></a>`}
          <nav class="side-nav">${NAV().map(([path, ic, label]) => html`<a href="#/${path}" data-nav="${path}">${icon(ic)}<span>${label}</span>${path === 'commandes' ? html`<em data-todo hidden></em>` : path === 'radar' ? html`<em class="is-radar" data-radar hidden></em>` : ''}</a>`)}</nav>
          <div class="side-foot">
            <a href="../explorer/" target="_blank" rel="noopener">${icon('external-link')}<span>${ML.t('Voir la plateforme')}</span></a>
            <button type="button" data-act="logout">${icon('log-out')}<span>${E.me.readonly ? ML.t('Quitter la démonstration') : ML.t('Se déconnecter')}</span></button>
          </div>
        </aside>
        <div class="main">
          <header class="mtop"><button class="btn btn-ghost btn-icon" type="button" data-act="menu" aria-label="Ouvrir le menu">${icon('menu')}</button><a href="#/">${ML.logo()}</a><a class="btn btn-ghost btn-icon" href="#/commandes" aria-label="${ML.t('Commandes')}">${icon('receipt-text')}</a></header>
          ${banner}
          <main id="view" tabindex="-1"></main>
        </div>
        <nav class="mtab" aria-label="Navigation">
          <a href="#/" data-nav="">${icon('layout-dashboard')}<span>${ML.t('Accueil')}</span></a>
          <a href="#/commandes" data-nav="commandes">${icon('receipt-text')}<span>${ML.t('Commandes')}</span><em data-todo hidden></em></a>
          <a href="#/radar" data-nav="radar">${icon('target')}<span>${ML.t('Radar')}</span><em class="is-radar" data-radar hidden></em></a>
          <a href="#/disponibilite" data-nav="disponibilite">${icon('boxes')}<span>${ML.t('Stock')}</span></a>
          <button type="button" data-act="menu">${icon('menu')}<span>${ML.t('Menu')}</span></button>
        </nav>
        <div class="side-veil" data-act="menu"></div>
      </div>`;
  }
  ML.actions.menu = () => document.body.classList.toggle('side-open');
  ML.actions.logout = async () => { try { await api('logout', { body: {} }); } catch (e) { /* session déjà close */ } location.href = E.me.readonly ? '../' : './'; };
  E.todo = (n) => $$('[data-todo]').forEach((el) => { el.hidden = !n; el.textContent = n > 99 ? '99+' : n; });
  E.radarBadge = (n) => $$('[data-radar]').forEach((el) => { el.hidden = !n; el.textContent = n > 99 ? '99+' : n; });

  async function route() {
    const r = ML.route();
    const name = r.parts[0] || '';
    const my = ++E.seq;
    ML.closeModals();
    document.body.classList.remove('side-open');
    if (E.cleanup) { try { E.cleanup(); } catch (e) { /* vue déjà retirée */ } E.cleanup = null; }
    $$('[data-nav]').forEach((a) => a.classList.toggle('is-on', a.dataset.nav === name));
    window.scrollTo(0, 0);
    const allowed = !isStore() || STORE_NAV().some(([p]) => p === name);
    const v = (allowed && E.views[name]) || E.views[''];
    try {
      await v(r);
    } catch (e) {
      if (my !== E.seq || e instanceof Stale) return;
      if (e.code === 'auth') { location.reload(); return; }
      view().innerHTML = html`<div class="empty">${icon('wifi-off')}<h3>${ML.t('Chargement impossible')}</h3><p>${e.message}</p><button class="btn btn-dark" data-act="retry">${ML.t('Réessayer')}</button></div>`;
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
  E.activityList = (list) => (list.length ? html`<ul class="feed">${list.map((a) => html`<li><span class="feed-ic">${icon(ACT_ICONS[a.kind] || 'circle')}</span><span class="grow">${a.detail}${a.own ? '' : html`<small class="muted"> · ${a.company}</small>`}</span><small class="dim nowrap">${fmt.ago(a.created)}</small></li>`)}</ul>` : html`<p class="muted">${ML.t("L'activité de votre réseau apparaîtra ici.")}</p>`);

  const radarBanner = (orders, searches) => (orders || searches ? html`<a class="radar-banner" href="#/radar">${icon('target')}<span><b>${[orders ? fmt.plural(orders, 'commande à proximité', 'commandes à proximité') : '', searches ? fmt.plural(searches, 'recherche de client', 'recherches de clients') : ''].filter(Boolean).join(' · ')}</b><small>${orders ? ML.t('Des clients près de vos points de vente attendent : le premier point de vente qui prend la commande la traite.') : ML.t('Des clients cherchent un produit : répondez si vous l\'avez.')}</small></span>${icon('chevron-right', 'ico-sm')}</a>` : '');

  /** Vue d'ensemble d'un compte limité à un point de vente. */
  async function storeHome() {
    E.loading();
    const d = await api('overview');
    const t = d.tiles;
    E.todo(t.to_process);
    E.radarBadge(t.radar + (t.research || 0));
    const s = d.store || {};
    const tile = (label, value, sub, href, extra) => html`<a class="tile ${extra || ''}" href="${href}"><span class="tile-label">${label}</span><b class="tile-value">${value}</b>${sub ? html`<span class="tile-sub">${sub}</span>` : ''}</a>`;
    view().innerHTML = html`
      ${E.head(s.name || ML.t('Mon point de vente'), ML.joinList([ML.communeLabel(s.commune, s.commune_ar), ML.storeZone(s)]),
        html`<a class="btn btn-line" href="#/disponibilite">${icon('boxes')}${ML.t('Mettre à jour le stock')}</a><a class="btn btn-primary" href="#/commandes">${icon('receipt-text')}${ML.t('Commandes')}${t.to_process ? ' · ' + t.to_process : ''}</a>`)}
      ${s.status === 'unlocated' ? html`<a class="notice" href="#/points-de-vente">${icon('map-pin')}<span><b>${ML.t('Placez votre point de vente sur la carte.')}</b> ${ML.t('Il apparaîtra sur la carte publique, avec vos horaires et votre téléphone.')}</span></a>` : ''}
      ${radarBanner(t.radar, t.research || 0)}
      <div class="tiles is-6">
        ${tile(ML.t('Commandes à traiter'), fmt.num(t.to_process), t.in_progress ? fmt.plural(t.in_progress, 'en cours', 'en cours') : ML.t('Rien en attente'), '#/commandes', t.to_process ? 'is-hot' : '')}
        ${tile(ML.t('Radar'), fmt.num(t.radar + (t.research || 0)), t.research ? fmt.plural(t.research, 'recherche de client', 'recherches de clients') : ML.t('Commandes à proximité'), '#/radar', t.radar || t.research ? 'is-hot' : '')}
        ${tile(ML.t('Commandes · 30 jours'), fmt.num(t.orders30), fmt.compact(t.revenue30) + ' ' + ML.boot.config.currency, '#/commandes?s=all')}
        ${tile(ML.t('Références en stock'), fmt.num(t.instock) + ' / ' + fmt.num(t.refs), t.low_stock ? fmt.plural(t.low_stock, 'référence', 'références') + ' ' + ML.t('à réapprovisionner') : ML.t('Stock à jour'), '#/disponibilite')}
      </div>
      <div class="cols">
        <section class="card card-pad">
          <div class="card-head"><h2 class="card-title">${ML.t('Dernières commandes')}</h2><a class="link" href="#/commandes?s=all">${ML.t('Tout voir')}</a></div>
          ${d.recent.length ? html`<ul class="olist">${d.recent.map(orderRow)}</ul>` : html`<div class="empty">${icon('receipt-text')}<h3>${ML.t('Pas encore de commande')}</h3><p>${ML.t('Les commandes de vos clients et celles que vous prenez sur le radar apparaissent ici.')}</p></div>`}
        </section>
        ${d.low.length ? html`<section class="card card-pad">
          <div class="card-head"><h2 class="card-title">${ML.t('Stock à surveiller')}</h2><a class="link" href="#/disponibilite">${ML.t('Mettre à jour')}</a></div>
          <ul class="lowlist">${d.low.slice(0, 6).map((l) => html`<li><span class="grow"><b>${l.product}</b>${l.option ? html` <span class="muted">· ${l.option}</span>` : ''}</span><span class="badge ${l.qty === 0 ? 'badge-bad' : 'badge-warn'}">${l.qty === 0 ? ML.t('Rupture') : ML.t('Reste') + ' ' + l.qty}</span></li>`)}</ul>
        </section>` : ''}
      </div>`;
  }

  E.views[''] = async () => {
    if (isStore()) return storeHome();
    E.loading();
    const d = await api('overview');
    const t = d.tiles;
    E.todo(t.to_process);
    E.radarBadge(t.radar + (t.research || 0));
    const brand = isBrand();
    const pos = E.me.company.kind === 'pos';
    const steps = brand
      ? [['brand', ML.t('Créer votre marque'), ML.t('Nom, couleur et présentation.'), '#/catalogue?t=marques'], ['product', ML.t('Ajouter un produit'), ML.t('Photos, prix, tailles et couleurs.'), '#/catalogue/produit/nouveau'], ['store', ML.t('Ajouter un point de vente'), ML.t('Adresse, horaires et position sur la carte.'), '#/points-de-vente/nouveau'], ['stock', ML.t('Indiquer la disponibilité'), ML.t('Ce qui est en stock, magasin par magasin.'), '#/disponibilite']]
      : pos ? [['located', ML.t('Placer votre point de vente sur la carte'), ML.t('Pour apparaître sur la carte publique.'), '#/points-de-vente'], ['partner', ML.t('Travailler avec des marques'), ML.t('Demandez à référencer leur catalogue.'), '#/reseau'], ['stock', ML.t('Indiquer la disponibilité'), ML.t('Ce que vous avez en stock : le radar vous propose les commandes proches.'), '#/disponibilite']]
        : [['store', ML.t('Ajouter un point de vente'), ML.t('Adresse, horaires et position sur la carte.'), '#/points-de-vente/nouveau'], ['partner', ML.t('Rejoindre le réseau d\'une marque'), ML.t('Demandez à distribuer son catalogue.'), '#/reseau'], ['stock', ML.t('Indiquer la disponibilité'), ML.t('Ce qui est en stock, magasin par magasin.'), '#/disponibilite']];
    const done = steps.filter(([k]) => d.steps[k]).length;
    const next = steps.find(([k]) => !d.steps[k]);
    const tile = (label, value, sub, href, extra) => html`<a class="tile ${extra || ''}" href="${href}"><span class="tile-label">${label}</span><b class="tile-value">${value}</b>${sub ? html`<span class="tile-sub">${sub}</span>` : ''}</a>`;
    view().innerHTML = html`
      ${E.head(E.me.readonly ? ML.t('Bienvenue dans la démonstration') : ML.t('Bonjour') + ' ' + E.me.user.name.split(' ')[0], new Intl.DateTimeFormat(ML.lang === 'ar' ? 'ar-DZ' : 'fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date()),
        html`<a class="btn btn-line" href="#/disponibilite">${icon('boxes')}${ML.t('Mettre à jour le stock')}</a><a class="btn btn-primary" href="#/commandes">${icon('receipt-text')}${ML.t('Commandes')}${t.to_process ? ' · ' + t.to_process : ''}</a>`)}
      ${done < steps.length ? html`
        <section class="card card-pad onboard">
          <div class="onboard-head">
            <div><h2 class="card-title">${ML.t('Mettez votre réseau en ligne')}</h2><p class="card-sub">${done} ${ML.t(done > 1 ? 'étapes' : 'étape')} ${ML.t('sur')} ${steps.length} ${ML.t(done > 1 ? 'terminées' : 'terminée')}</p></div>
            <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="${steps.length}" aria-valuenow="${done}"><i style="width:${(done / steps.length) * 100}%"></i></div>
          </div>
          <ol class="onboard-steps">${steps.map(([k, title, sub, href], i) => html`<li class="${d.steps[k] ? 'is-done' : ''} ${next && next[0] === k ? 'is-next' : ''}"><a href="${href}"><i>${d.steps[k] ? icon('check', 'ico-sm') : i + 1}</i><span><b>${title}</b><small>${sub}</small></span>${icon('chevron-right', 'ico-sm')}</a></li>`)}</ol>
        </section>` : ''}
      ${radarBanner(t.radar, t.research || 0)}
      <div class="tiles is-6">
        ${tile(ML.t('Commandes à traiter'), fmt.num(t.to_process), t.in_progress ? fmt.plural(t.in_progress, 'en cours', 'en cours') : ML.t('Rien en attente'), '#/commandes', t.to_process ? 'is-hot' : '')}
        <a class="tile" href="#/analyses"><span class="tile-label">${ML.t('Commandes · 30 jours')}</span><b class="tile-value">${fmt.num(t.orders30)}</b><span class="tile-sub">${ML.charts.spark(d.trend.orders, ML.charts.C.orders)}<span class="sr">${ML.t('Évolution sur 14 jours')}</span></span></a>
        ${tile(ML.t('Chiffre d\'affaires · 30 jours'), fmt.compact(t.revenue30) + ' ' + ML.boot.config.currency, ML.t('Hors commandes annulées'), '#/analyses')}
        ${tile(ML.t('Recherches · 30 jours'), fmt.compact(t.searches30), ML.t('Sur vos produits'), '#/analyses')}
        ${tile(ML.t('Points de vente'), fmt.num(brand ? t.network_stores : t.stores), brand && t.network_stores > t.stores ? ML.t('dont') + ' ' + (t.network_stores - t.stores) + ' ' + ML.t('chez vos distributeurs') : ML.t('Actifs sur la carte'), '#/points-de-vente')}
        ${tile(ML.t('Produits en ligne'), fmt.num(t.products), t.low_stock ? fmt.plural(t.low_stock, 'référence', 'références') + ' ' + ML.t('à réapprovisionner') : ML.t('Stock à jour'), t.products ? '#/disponibilite' : '#/catalogue')}
      </div>
      <div class="cols">
        <section class="card card-pad">
          <div class="card-head"><h2 class="card-title">${ML.t('Dernières commandes')}</h2><a class="link" href="#/commandes?s=all">${ML.t('Tout voir')}</a></div>
          ${d.recent.length ? html`<ul class="olist">${d.recent.map(orderRow)}</ul>` : html`<div class="empty">${icon('receipt-text')}<h3>${ML.t('Pas encore de commande')}</h3><p>${ML.t('Dès qu\'un client commande ou réserve dans l\'un de vos points de vente, elle apparaît ici.')}</p></div>`}
        </section>
        <div class="stack">
          ${d.low.length ? html`<section class="card card-pad">
            <div class="card-head"><h2 class="card-title">${ML.t('Stock à surveiller')}</h2><a class="link" href="#/disponibilite">${ML.t('Mettre à jour')}</a></div>
            <ul class="lowlist">${d.low.slice(0, 6).map((l) => html`<li><span class="grow"><b>${l.product}</b>${l.option ? html` <span class="muted">· ${l.option}</span>` : ''}<small class="muted">${l.store}</small></span><span class="badge ${l.qty === 0 ? 'badge-bad' : 'badge-warn'}">${l.qty === 0 ? ML.t('Rupture') : ML.t('Reste') + ' ' + l.qty}</span></li>`)}</ul>
          </section>` : ''}
          <section class="card card-pad">
            <div class="card-head"><h2 class="card-title">${ML.t('Activité du réseau')}</h2><a class="link" href="#/reseau">${ML.t('Journal')}</a></div>
            ${E.activityList(d.activity)}
          </section>
        </div>
      </div>`;
  };

  /* ============================================================ COMMANDES */
  const O_TABS = () => [['open', ML.t('En cours')], ['pending', ML.t('En attente')], ['confirmed', ML.t('Confirmées')], ['ready', ML.t('Prêtes')], ['shipped', ML.t('En livraison')], ['done', ML.t('Terminées')], ['cancelled', ML.t('Annulées')], ['all', ML.t('Toutes')]];
  const NEXT_LABEL = (o, s) => ({ confirmed: ML.t('Confirmer'), ready: o.kind === 'reservation' ? ML.t('Article mis de côté') : ML.t('Prête au retrait'), shipped: ML.t('Expédiée'), done: o.mode === 'delivery' ? ML.t('Livrée') : ML.t('Retirée par le client'), cancelled: ML.t('Annuler') }[s]);

  E.views.commandes = async (r) => {
    const F = { status: r.query.s || 'open', store: r.query.pdv || '', q: r.query.q || '' };
    const stores = (await api('dash_stores')).stores;
    view().innerHTML = html`
      ${E.head(ML.t('Commandes'), ML.t('Commandes et réservations reçues dans vos points de vente.'))}
      <div class="toolbar">
        <div class="chips" data-tabs></div>
        <div class="toolbar-row">
          <form class="input-icon grow" data-form="oq" role="search">${icon('search')}<input class="input input-sm" type="search" name="q" placeholder="${ML.t('N° de commande, client, téléphone…')}" value="${F.q}" aria-label="${ML.t('Chercher une commande')}"></form>
          ${stores.length > 1 ? html`<select class="select input-sm" data-change="ostore" aria-label="${ML.t('Point de vente')}"><option value="">${ML.t('Tous les points de vente')}</option>${stores.map((s) => html`<option value="${s.id}" ${String(s.id) === F.store ? ML.raw('selected') : ''}>${s.name}</option>`)}</select>` : ''}
        </div>
      </div>
      <div data-list><div class="loading"><div class="spinner"></div></div></div>`;
    const load = async () => {
      const d = await api('orders', { params: { status: F.status, store: F.store, q: F.q } });
      const c = d.counts;
      const n = { open: c.pending + c.confirmed + c.ready + c.shipped, all: Object.values(c).reduce((a, b) => a + b, 0) };
      E.todo(d.todo);
      $('[data-tabs]').innerHTML = html`${O_TABS().map(([k, label]) => html`<button class="chip ${F.status === k ? 'is-on' : ''}" type="button" data-act="otab" data-k="${k}">${label}<small>${fmt.num(n[k] != null ? n[k] : c[k])}</small></button>`)}`;
      $('[data-list]').innerHTML = d.orders.length ? html`
        <div class="tbl-wrap card"><table class="tbl tbl-orders">
          <thead><tr><th>${ML.t('Commande')}</th><th>${ML.t('Client')}</th><th>${ML.t('Article')}</th><th>${ML.t('Point de vente')}</th><th class="r">${ML.t('Total')}</th><th>${ML.t('Statut')}</th></tr></thead>
          <tbody>${d.orders.map((o) => html`<tr class="is-link" data-act="order-open" data-id="${o.id}" tabindex="0">
            <td data-c="ref"><b>${o.ref}</b><small class="muted">${icon(o.radar ? 'target' : o.kind === 'reservation' ? 'bookmark-check' : o.mode === 'delivery' ? 'truck' : 'shopping-bag', 'ico-sm')}${o.radar ? ML.t('Radar') : ML.kindLabel(o)} · ${fmt.ago(o.created)}</small></td>
            <td data-c="client">${o.name}<small class="muted">${o.phone}</small></td>
            <td data-c="article">${o.first}${o.units > 1 ? html` <span class="muted">× ${o.units}</span>` : ''}</td>
            <td data-c="store">${o.waiting ? html`<span class="muted">${ML.t('Sur le radar')}</span>` : o.store}</td>
            <td data-c="total" class="r num"><b>${fmt.price(o.total)}</b></td>
            <td data-c="status">${o.waiting ? html`<span class="badge badge-brand">${icon('target')}${ML.t('Sur le radar')}</span>` : ML.statusBadge(o)}</td>
          </tr>`)}</tbody></table></div>`
        : html`<div class="card"><div class="empty">${icon('receipt-text')}<h3>${F.q ? ML.t('Aucune commande ne correspond') : F.status === 'open' ? ML.t('Aucune commande en cours') : ML.t('Aucune commande ici')}</h3><p>${F.status === 'open' && !F.q ? ML.t("Tout est traité. Les nouvelles commandes arrivent ici dès qu'un client valide sa demande.") : ML.t("Changez de filtre pour en voir d'autres.")}</p></div></div>`;
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
    try { o = (await api('order_get', { params: { id } })).order; } catch (e) { if (!(e instanceof Stale)) ML.fail(e); return; }
    const body = (ord) => html`
      <div class="odetail">
        <div class="row row-wrap between"><span class="row">${ord.waiting ? html`<span class="badge badge-brand">${icon('target')}${ML.t('Sur le radar')}</span>` : ML.statusBadge(ord)}${ord.radar ? html`<span class="badge badge-line">${icon('target')}${ML.t('Commande radar')}</span>` : ''}</span><span class="muted">${ML.kindLabel(ord)} · ${fmt.time(ord.created)}</span></div>
        ${ord.waiting ? html`<p class="notice is-radar">${icon('target')}<span>${ML.t("Aucun point de vente ne l'a encore prise : elle est proposée à ceux qui ont l'article, du plus proche du client au plus loin. Vous pouvez l'annuler si vous ne pouvez pas la servir.")}</span></p>` : ''}
        ${ord.foreign && ord.store ? html`<p class="notice">${icon('store')}<span>${ML.t('Prise en charge par')} <b>${ord.store.name}</b>. ${ML.t('Ce point de vente la traite et vous suivez son avancement ici.')}</span></p>` : ''}
        ${ord.kind === 'reservation' && ord.expires && ord.next.length ? html`<p class="notice">${icon('clock')}<span>${ML.t("Article à garder de côté jusqu'au")} <b>${fmt.time(ord.expires)}</b>. ${ML.t('Passé ce délai, la réservation est annulée et le stock libéré.')}</span></p>` : ''}
        <ul class="lines">${ord.items.map((i) => html`<li><span>${i.qty} × <b>${i.name}</b>${[i.size, i.color].filter(Boolean).length ? html`<small class="muted"> · ${[i.size, i.color].filter(Boolean).join(' · ')}</small>` : ''}<small class="muted"> · ${i.brand}</small></span><b>${fmt.price(i.price * i.qty)}</b></li>`)}<li class="total"><span>${ML.t('Total à encaisser')} ${ord.mode === 'delivery' ? ML.t('à la livraison') : ML.t('en magasin')}</span><b>${fmt.price(ord.total)}</b></li></ul>
        <div class="ogrid">
          <div><span class="label">${ML.t('Client')}</span><p><b>${ord.name}</b><br><a class="link" href="tel:${ord.phone}">${ord.phone}</a>${ord.email ? html`<br><a class="link" href="mailto:${ord.email}">${ord.email}</a>` : ''}</p></div>
          <div><span class="label">${ord.mode === 'delivery' ? ML.t('Livraison') : ML.t('Retrait')}</span><p>${ord.delivery ? html`${ord.delivery.address ? html`${ord.delivery.address}<br>` : ''}<b>${ML.communeLabel(ord.delivery.commune || ord.delivery.city, ord.delivery.commune_ar)}</b>${ord.delivery.wilaya && ML.zones[ord.delivery.wilaya] ? ' · ' + ML.zoneName(ML.zones[ord.delivery.wilaya]) : ord.delivery.zone ? ' · ' + ord.delivery.zone : ''}${ord.delivery.address ? '' : html`<br><small class="muted">${ML.t('Adresse à préciser au téléphone')}</small>`}` : html`${ord.store ? ord.store.name : ''}<br><span class="muted">${ord.store ? ord.store.city : ''}</span>`}</p></div>
        </div>
        ${ord.note ? html`<div><span class="label">${ML.t('Message du client')}</span><p class="onote">${ord.note}</p></div>` : ''}
        <div><span class="label">${ML.t('Historique')}</span><ul class="ohist">${ord.events.map((e) => html`<li><i></i><span><b>${ML.statusLabel({ status: e.status, mode: ord.mode })}</b>${e.note ? html` — ${e.note}` : ''}<small class="muted">${fmt.time(e.created)} · ${e.actor === 'client' ? ML.t('client') : e.actor === 'system' ? ML.t('automatique') : ML.t('point de vente')}</small></span></li>`)}</ul></div>
      </div>`;
    const foot = (ord) => (ord.next.length ? html`${ord.can_release ? html`<button class="btn btn-line" type="button" data-release>${icon('target')}${ML.t('Remettre sur le radar')}</button>` : ''}${ord.next.filter((s) => s === 'cancelled').map(() => html`<button class="btn btn-danger" type="button" data-next="cancelled">${ML.t('Annuler la')} ${ord.kind === 'reservation' ? ML.t('réservation') : ML.t('commande')}</button>`)}${ord.next.filter((s) => s !== 'cancelled').map((s) => html`<button class="btn btn-primary" type="button" data-next="${s}">${icon('check')}${NEXT_LABEL(ord, s)}</button>`)}` : html`<button class="btn btn-line" type="button" data-close>${ML.t('Fermer')}</button>`);
    const m = ML.modal({ title: (o.kind === 'reservation' ? ML.t('Réservation') : ML.t('Commande')) + ' ' + o.ref, body: body(o), foot: foot(o), wide: true });
    m.el.addEventListener('click', async (e) => {
      const rel = e.target.closest('[data-release]');
      if (rel) {
        if (!await ML.confirm(ML.t('Remettre cette commande sur le radar ? Le stock est rétabli et la commande est proposée aux autres points de vente proches du client.'), { ok: ML.t('Remettre sur le radar') })) return;
        ML.busy(rel, true);
        const ok = await E.send('radar_release', { id: o.id });
        ML.busy(rel, false);
        if (!ok) return;
        m.close();
        ML.toast(ML.t('Commande remise sur le radar.'), 'good');
        if (E.afterOrder) E.afterOrder(); else E.reload();
        return;
      }
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
      ML.toast(ML.t('Statut mis à jour :') + ' ' + ML.t(ML.statusLabel(o)).toLowerCase(), 'good');
      if (E.afterOrder) E.afterOrder(); else E.reload();
    });
  }
  ML.actions['order-open'] = (el) => openOrder(+el.dataset.id);
  E.openOrder = openOrder;
  const askReason = () => new Promise((resolve) => {
    let answer = null;
    const m = ML.modal({
      title: ML.t('Annuler la commande'),
      body: html`<p class="muted" style="margin-bottom:12px">${ML.t('Les articles reviennent en stock. Le client voit ce motif dans son suivi.')}</p><div class="field"><label for="c-reason">${ML.t('Motif')}</label><select class="select" id="c-reason"><option>${ML.t('Client injoignable')}</option><option>${ML.t('Annulée à la demande du client')}</option><option>${ML.t('Article indisponible')}</option><option>${ML.t('Autre')}</option></select></div>`,
      foot: html`<button class="btn btn-line" type="button" data-close>${ML.t('Revenir')}</button><button class="btn btn-danger" type="button" data-yes>${ML.t("Confirmer l'annulation")}</button>`,
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
    const co = (c) => html`${ML.mono(c.name, c.kind === 'brand' ? '#2a78d6' : '#344054', c.logo)}<span class="grow"><b>${c.name}${c.demo ? html` <span class="badge">${ML.t('Démo')}</span>` : ''}</b><small class="muted">${[c.city, c.zone && c.zone !== c.city ? c.zone : ''].filter(Boolean).join(' · ') || KINDS()[c.kind]}</small></span>`;
    view().innerHTML = html`
      ${E.head(ML.t('Réseau'), brand ? ML.t("Les distributeurs qui vendent vos produits, et l'activité de leurs points de vente.") : ML.t('Les marques dont vous distribuez le catalogue.'))}
      ${incoming.length ? html`<section class="card card-pad"><h2 class="card-title">${ML.t('Demandes reçues')}</h2><ul class="plist">${incoming.map((p) => html`<li>${co(p.company)}<span class="row"><button class="btn btn-line btn-sm" data-act="p-respond" data-id="${p.id}" data-ok="0">${ML.t('Refuser')}</button><button class="btn btn-primary btn-sm" data-act="p-respond" data-id="${p.id}" data-ok="1">${icon('check', 'ico-sm')}${ML.t('Accepter')}</button></span></li>`)}</ul></section>` : ''}
      <div class="cols">
        <div class="stack">
          <section class="card card-pad">
            <h2 class="card-title">${brand ? ML.t('Vos distributeurs') : ML.t('Vos marques')}</h2>
            ${active.length || outgoing.length ? html`<ul class="plist">
              ${active.map((p) => html`<li>${co(p.company)}<span class="pstats"><span><b>${fmt.num(p.stores)}</b> ${ML.t(p.stores > 1 ? 'points de vente' : 'point de vente')}</span><span><b>${fmt.num(p.refs)}</b> ${ML.t(p.refs > 1 ? 'références' : 'référence')}</span><span>${p.refs ? ML.charts.meter(Math.round((p.instock / p.refs) * 100)) : ''}</span></span><button class="btn btn-ghost btn-sm" data-act="p-remove" data-id="${p.id}" data-name="${p.company.name}" aria-label="${ML.t('Mettre fin au partenariat avec')} ${p.company.name}">${icon('unlink', 'ico-sm')}</button></li>`)}
              ${outgoing.map((p) => html`<li>${co(p.company)}<span class="badge badge-warn">${icon('clock')}${ML.t('En attente de réponse')}</span><button class="btn btn-ghost btn-sm" data-act="p-remove" data-id="${p.id}" data-name="${p.company.name}" data-pending="1">${ML.t('Annuler')}</button></li>`)}
            </ul>` : html`<div class="empty">${icon('handshake')}<h3>${brand ? ML.t('Pas encore de distributeur') : ML.t('Pas encore de marque')}</h3><p>${brand ? ML.t('Invitez un distributeur : il pourra indiquer vos produits disponibles dans ses magasins.') : ML.t('Demandez à distribuer une marque pour référencer son catalogue dans vos magasins.')}</p></div>`}
          </section>
          <section class="card card-pad">
            <div class="card-head"><div><h2 class="card-title">${brand ? ML.t('Distributeurs sur la plateforme') : ML.t('Marques sur la plateforme')}</h2><p class="card-sub">${brand ? ML.t('Proposez-leur de distribuer votre catalogue.') : ML.t('Demandez à référencer leurs produits.')}</p></div></div>
            ${d.directory.length ? html`<ul class="plist">${d.directory.map((c) => html`<li>${co(c)}<span class="muted nowrap">${brand ? fmt.plural(c.stores, 'point de vente', 'points de vente') : fmt.plural(c.products, 'produit')}</span><button class="btn btn-soft btn-sm" data-act="p-request" data-id="${c.id}">${icon('plus', 'ico-sm')}${brand ? ML.t('Inviter') : ML.t('Demander')}</button></li>`)}</ul>` : html`<p class="muted">${brand ? ML.t('Aucun autre distributeur inscrit pour le moment.') : ML.t('Aucune autre marque inscrite pour le moment.')}</p>`}
          </section>
        </div>
        <section class="card card-pad">
          <h2 class="card-title">${ML.t('Journal du réseau')}</h2>
          <p class="card-sub" style="margin-bottom:12px">${ML.t('Commandes, mises à jour de stock et nouveaux points de vente.')}</p>
          ${E.activityList(d.activity)}
        </section>
      </div>`;
    ML.actions['p-request'] = async (el) => { ML.busy(el, true); if (await E.send('partner_request', { company_id: +el.dataset.id })) { ML.toast(ML.t('Demande envoyée.'), 'good'); E.reload(); } else ML.busy(el, false); };
    ML.actions['p-respond'] = async (el) => { ML.busy(el, true); if (await E.send('partner_respond', { id: +el.dataset.id, accept: el.dataset.ok === '1' })) { ML.toast(el.dataset.ok === '1' ? ML.t('Partenariat activé.') : ML.t('Demande refusée.'), 'good'); E.reload(); } else ML.busy(el, false); };
    ML.actions['p-remove'] = async (el) => {
      const pending = el.dataset.pending === '1';
      if (!await ML.confirm(pending ? ML.t('Annuler la demande envoyée à') + ' ' + el.dataset.name + ' ?' : ML.t('Mettre fin au partenariat avec') + ' ' + el.dataset.name + ML.t(' ? Les produits de la marque seront retirés des magasins du distributeur.'), { ok: pending ? ML.t('Annuler la demande') : ML.t('Mettre fin'), danger: true })) return;
      if (await E.send('partner_remove', { id: +el.dataset.id })) E.reload();
    };
  };

  /* ================================================================ RADAR */
  const RANKS = () => ({ 1: ML.t('Votre commune'), 2: ML.t('Votre wilaya'), 3: ML.t('Plus loin') });
  const placeOf = (o) => ML.placeLabel(o);
  const itemOf = (i) => html`<b>${i.qty > 1 ? i.qty + ' × ' : ''}${i.name}</b><small class="muted">${[i.size, i.color].filter(Boolean).join(' · ')}${i.brand ? ' · ' + i.brand : ''}</small>`;

  E.views.radar = async () => {
    E.loading();
    const load = async () => {
      const d = await api('radar');
      const searches = d.research || [];
      E.radarBadge(d.nearby.length + searches.filter((x) => !x.replied).length);
      const step = d.step_min;
      const company = !isStore();
      const levels = html`
        <ol class="radar-levels">
          <li><i>1</i><span><b>${ML.t('Sa commune')}</b><small>${ML.t('tout de suite')}</small></span></li>
          <li><i>2</i><span><b>${ML.t('Sa wilaya')}</b><small>${step ? ML.t('après') + ' ' + step + ' ' + ML.t('min') : ML.t('tout de suite')}</small></span></li>
          <li><i>3</i><span><b>${ML.t('Tous les points de vente')}</b><small>${step ? ML.t('après') + ' ' + 2 * step + ' ' + ML.t('min') : ML.t('tout de suite')}</small></span></li>
        </ol>`;
      const card = (o) => html`
        <li class="rcard rank-${o.rank}">
          <div class="rcard-top">
            ${ML.visual({ name: o.item.name, category: o.item.category, image: o.item.image }, { cls: 'pv-row' })}
            <span class="grow rcard-item">${itemOf(o.item)}</span>
            <b class="num rcard-total">${fmt.price(o.total)}</b>
          </div>
          <div class="rcard-meta">
            <span class="badge ${o.rank === 1 ? 'badge-good' : o.rank === 2 ? 'badge-info' : ''}">${icon('map-pin')}${RANKS()[o.rank]}</span>
            <span class="rcard-place">${icon('truck', 'ico-sm')}${placeOf(o)}</span>
            <small class="dim">${o.ref} · ${fmt.ago(o.created)}</small>
          </div>
          <div class="rcard-act">
            ${o.stores.length > 1 ? html`<select class="select input-sm" data-claim-store="${o.id}" aria-label="${ML.t('Point de vente qui prend la commande')}">${o.stores.map((st) => html`<option value="${st.id}">${st.name} · ${RANKS()[st.rank]} · ${ML.t('stock')} ${st.qty}</option>`)}</select>`
              : html`<span class="muted rcard-store">${icon('store', 'ico-sm')}${o.stores[0].name} · ${ML.t('stock')} ${o.stores[0].qty}</span>`}
            <button class="btn btn-primary" type="button" data-act="claim" data-id="${o.id}" data-store="${o.stores[0].id}">${icon('check')}${ML.t('Prendre la commande')}</button>
          </div>
        </li>`;
      const recv = (o) => {
        let state;
        if (o.waiting) {
          state = o.level ? html`<span class="badge badge-brand">${icon('target')}${ML.t('Sur le radar')} · ${o.level === 1 ? ML.t('sa commune') : o.level === 2 ? ML.t('sa wilaya') : ML.t('partout')}</span>`
            : html`<span class="badge badge-warn">${icon('triangle-alert')}${ML.t('Aucun point de vente n\'a l\'article')}</span>`;
        } else if (o.store) {
          state = html`${ML.statusBadge(o)}<small class="muted">${o.store.own ? '' : ML.t('par') + ' '}${o.store.name}${o.store.own ? '' : ' (' + o.store.company + ')'}</small>`;
        } else state = ML.statusBadge(o);
        return html`<li><button class="orow" type="button" data-act="order-open" data-id="${o.id}">
          <span class="orow-ic">${icon(o.waiting ? 'target' : 'truck')}</span>
          <span class="grow">${itemOf(o.item)}<small class="muted">${o.ref} · ${o.name} · ${placeOf(o)}</small></span>
          <span class="orow-end">${state}<small class="dim">${fmt.ago(o.created)}</small></span>
        </button></li>`;
      };
      const wanted = (r) => html`
        <li class="rcard rs ${r.replied ? 'is-replied' : ''} ${r.stores.length ? 'rank-' + r.rank : ''}">
          <div class="rcard-top">
            ${r.image ? html`<button class="rs-img" type="button" data-act="rs-zoom" data-src="${ML.media(r.image, 1200)}" aria-label="${ML.t('Agrandir la photo')}"><img src="${ML.media(r.image, 480)}" alt="" loading="lazy"></button>` : ML.visual({ name: r.name, category: r.category }, { cls: 'pv-row' })}
            <span class="grow rcard-item"><b>${r.name}</b><small class="muted">${[r.brand, ML.cats[r.category]].filter(Boolean).join(' · ')}</small>${r.details ? html`<small class="rs-details">${r.details}</small>` : ''}</span>
          </div>
          <div class="rcard-meta">
            ${r.stores.length ? html`<span class="badge ${r.rank === 1 ? 'badge-good' : r.rank === 2 ? 'badge-info' : ''}">${icon('map-pin')}${RANKS()[r.rank]}</span>` : html`<span class="badge badge-line">${icon('tag')}${ML.t('Votre rayon')}</span>`}
            <span class="rcard-place">${icon('map-pin', 'ico-sm')}${placeOf(r)}</span>
            <small class="dim">${r.ref} · ${fmt.ago(r.created)}${r.replies ? ' · ' + fmt.plural(r.replies, 'réponse', 'réponses') : ''}</small>
          </div>
          ${r.replied ? html`
            <p class="rs-replied">${icon('circle-check', 'ico-sm')}<span>${ML.t('Réponse envoyée')} · ${r.contact ? html`<b>${r.contact.name}</b> <a class="link" href="tel:${r.contact.phone}">${r.contact.phone}</a>` : ML.t('le client vous contactera')}</span></p>
            <div class="rcard-act"><button class="btn btn-ghost btn-sm" type="button" data-act="rs-dismiss" data-id="${r.id}">${ML.t('Retirer ma réponse')}</button><button class="btn btn-line btn-sm" type="button" data-act="rs-reply" data-id="${r.id}">${ML.t('Modifier ma réponse')}</button></div>`
            : html`<div class="rcard-act"><button class="btn btn-ghost" type="button" data-act="rs-dismiss" data-id="${r.id}">${ML.t('Je ne l\'ai pas')}</button><button class="btn btn-primary" type="button" data-act="rs-reply" data-id="${r.id}">${icon('check')}${ML.t('J\'ai ce produit')}</button></div>`}
        </li>`;
      const waiting = d.received.filter((o) => o.waiting);
      view().innerHTML = html`
        ${E.head(ML.t('Radar'), ML.t('Les commandes passées sans choisir de point de vente (fiche produit, page de vente) sont proposées aux points de vente qui ont l\'article, du plus proche du client au plus loin. Le premier qui la prend la traite.'))}
        <section class="card card-pad radar-how">${levels}</section>
        <section class="card card-pad">
          <div class="card-head"><div><h2 class="card-title">${ML.t('À proximité')}</h2><p class="card-sub">${company ? ML.t("Commandes que vos points de vente peuvent prendre (l'article est en stock).") : ML.t("Commandes que votre point de vente peut prendre (l'article est en stock).")}</p></div><button class="btn btn-ghost btn-sm" type="button" data-act="radar-refresh">${icon('refresh-cw', 'ico-sm')}${ML.t('Actualiser')}</button></div>
          ${d.nearby.length ? html`<ul class="rlist">${d.nearby.map(card)}</ul>` : html`<div class="empty">${icon('target')}<h3>${ML.t('Aucune commande à proximité pour le moment')}</h3><p>${ML.t("Gardez votre stock à jour : dès qu'un client proche commande un article que vous avez, la commande apparaît ici (et par email).")}</p><a class="btn btn-line btn-sm" href="#/disponibilite">${ML.t('Mettre à jour le stock')}</a></div>`}
        </section>
        <section class="card card-pad" data-research>
          <div class="card-head"><div><h2 class="card-title">${ML.t('Recherches actives des clients')}</h2><p class="card-sub">${ML.t("Des clients n'ont pas trouvé un produit et le cherchent dans le réseau, photo à l'appui. Si vous l'avez, répondez : ils voient votre réponse tout de suite.")}</p></div></div>
          ${searches.length ? html`<ul class="rlist">${searches.map(wanted)}</ul>` : html`<p class="muted">${ML.t('Aucune recherche de client pour le moment.')}</p>`}
        </section>
        ${company && (d.received.length || isBrand()) ? html`<section class="card card-pad">
          <div class="card-head"><div><h2 class="card-title">${ML.t('Commandes reçues par vos pages')}</h2><p class="card-sub">${waiting.length ? fmt.plural(waiting.length, 'commande', 'commandes') + ' ' + ML.t('encore sur le radar') + ' · ' : ''}${ML.t('Fiches produit et pages de vente de vos produits.')}</p></div><a class="link" href="#/catalogue">${ML.t('Pages de vente')}</a></div>
          ${d.received.length ? html`<ul class="olist olist-radar">${d.received.map(recv)}</ul>` : html`<p class="muted">${ML.t('Partagez la page de vente de vos produits (Catalogue → un produit → Page de vente) : les commandes reçues apparaissent ici.')}</p>`}
        </section>` : ''}`;
      E.radarSearches = searches;
    };
    ML.actions['radar-refresh'] = () => load().catch(() => {});
    ML.actions['rs-zoom'] = (el) => ML.modal({ title: ML.t('Photo du client'), wide: true, body: html`<img class="rs-zoom" src="${el.dataset.src}" alt="">` });
    ML.actions['rs-dismiss'] = async (el) => {
      ML.busy(el, true);
      if (await E.send('research_dismiss', { id: +el.dataset.id })) { ML.toast(ML.t('Recherche écartée.'), 'good'); load().catch(() => {}); } else ML.busy(el, false);
    };
    ML.actions['rs-reply'] = async (el) => {
      const r = (E.radarSearches || []).find((x) => x.id === +el.dataset.id);
      if (!r) return;
      ML.busy(el, true);
      let stores = [];
      try { stores = (await api('dash_stores')).stores; } catch (e) { ML.busy(el, false); if (!(e instanceof Stale)) ML.fail(e); return; }
      ML.busy(el, false);
      const own = isStore();
      const pre = (r.replied && r.replied.store_id) || (r.stores[0] && r.stores[0].id) || (stores[0] && stores[0].id) || 0;
      const m = ML.modal({
        title: ML.t('J\'ai ce produit'),
        body: html`
          <form class="stack" data-form="rs-reply" novalidate>
            <div class="order-sum">${r.image ? html`<img class="rs-thumb" src="${ML.media(r.image, 480)}" alt="">` : ML.visual({ name: r.name, category: r.category }, { cls: 'pv-xs' })}<div class="grow"><b>${r.name}</b><br><span class="muted">${placeOf(r)}</span></div></div>
            ${own || !stores.length ? '' : html`<div class="field"><label for="rr-store">${ML.t('Point de vente qui a le produit')}</label><select class="select" id="rr-store" name="store_id">${stores.map((st) => html`<option value="${st.id}" ${st.id === pre ? ML.raw('selected') : ''}>${st.name}</option>`)}${isBrand() ? html`<option value="0">${ML.t('Aucun en particulier (réponse de la marque)')}</option>` : ''}</select></div>`}
            <div class="field"><label for="rr-product">${ML.t('Fiche du catalogue')} <span class="dim">(${ML.t('facultatif')})</span></label><select class="select" id="rr-product" name="product_id"><option value="0">${ML.t('Chargement…')}</option></select><span class="hint muted">${ML.t('Le client pourra ouvrir la fiche et commander.')}</span></div>
            <div class="form-grid cols-2">
              <div class="field"><label for="rr-price">${ML.t('Prix (')}${ML.boot.config.currency}) <span class="dim">(${ML.t('facultatif')})</span></label><input class="input" id="rr-price" name="price" type="number" min="0" step="any" inputmode="decimal" value="${r.replied && r.replied.price != null ? r.replied.price : ''}"></div>
            </div>
            <div class="field"><label for="rr-msg">${ML.t('Message au client')} <span class="dim">(${ML.t('facultatif')})</span></label><textarea class="textarea" id="rr-msg" name="message" rows="2" maxlength="300" placeholder="${ML.t('Ex. disponible en magasin, taille 42')}">${r.replied ? r.replied.message : ''}</textarea></div>
            <p class="form-error" data-error hidden></p>
          </form>`,
        foot: html`<button class="btn btn-line" type="button" data-close>${ML.t('Annuler')}</button><button class="btn btn-primary" type="button" data-send>${icon('check')}${ML.t('Envoyer ma réponse')}</button>`,
      });
      const form = $('form', m.el);
      // Fiches proposées : celles que le point de vente choisi peut référencer (ou le catalogue de la marque).
      const fill = async () => {
        const sel = $('#rr-product', form);
        const sid = own ? 0 : +((form.store_id || {}).value || 0);
        let list = [];
        try {
          if (own || sid) list = (await ML.api('stock', { params: { store: sid } })).products.map((p) => ({ id: p.id, name: p.name, brand: p.brand }));
          else list = (await ML.api('dash_products')).products.map((p) => ({ id: p.id, name: p.name, brand: p.brand.name }));
        } catch (e) { list = []; }
        const cur = r.replied ? r.replied.product_id : 0;
        sel.innerHTML = html`<option value="0">${ML.t('Aucune fiche (produit hors catalogue)')}</option>${list.map((p) => html`<option value="${p.id}" ${p.id === cur ? ML.raw('selected') : ''}>${p.name} — ${p.brand}</option>`)}`;
      };
      if (form.store_id) form.store_id.addEventListener('change', fill);
      fill();
      const send = async () => {
        const d = ML.formData(form);
        const btn = $('[data-send]', m.el);
        ML.busy(btn, true);
        const res = await E.send('research_reply', { id: r.id, store_id: +(d.store_id || 0), product_id: +(d.product_id || 0), price: d.price, message: d.message }, form);
        ML.busy(btn, false);
        if (!res) return;
        m.close();
        ML.toast(ML.t('Réponse envoyée : le client la voit dans son suivi.'), 'good');
        load().catch(() => {});
      };
      $('[data-send]', m.el).addEventListener('click', send);
      ML.forms['rs-reply'] = send;
    };
    ML.actions.claim = async (el) => {
      const sel = $(`[data-claim-store="${el.dataset.id}"]`);
      const storeId = sel ? +sel.value : +el.dataset.store;
      ML.busy(el, true);
      const res = await E.send('radar_claim', { id: +el.dataset.id, store_id: storeId });
      ML.busy(el, false);
      if (!res) { load().catch(() => {}); return; }
      ML.toast(ML.t('Commande prise : appelez le client pour confirmer la livraison.'), 'good');
      await load();
      openOrder(res.order.id);
    };
    // Les commandes arrivent pendant qu'on regarde : la liste se met à jour toute seule.
    const timer = setInterval(() => { if (!document.hidden && !document.querySelector('.modal-wrap')) load().catch(() => {}); }, 30000);
    E.afterOrder = () => load().catch(() => {});
    E.cleanup = () => { clearInterval(timer); E.afterOrder = null; };
    await load();
  };

  /* =========================================================== PARAMÈTRES */
  E.upload = async (file) => {
    const fd = new FormData();
    fd.append('file', file);
    const res = await E.send('upload', fd);
    return res ? res.path : null;
  };
  E.views.parametres = async () => {
    if (isStore()) return accountView();
    const c = E.me.company;
    const u = E.me.user;
    const zones = ML.boot.zones.slice().sort((a, b) => ML.zoneName(a).localeCompare(ML.zoneName(b), ML.lang === 'ar' ? 'ar' : 'fr'));
    let logo = c.logo;
    view().innerHTML = html`
      ${E.head(ML.t('Paramètres'), ML.t("Votre entreprise telle qu'elle apparaît sur la plateforme, et votre compte."))}
      <div class="cols">
        <form class="card card-pad stack" data-form="company">
          <h2 class="card-title">${ML.t('Entreprise')}</h2>
          <div class="logo-edit"><span data-logo>${ML.mono(c.name, '#ff5a2b', logo, 'mono-lg')}</span><div><label class="btn btn-line btn-sm">${icon('upload', 'ico-sm')}${ML.t('Changer le logo')}<input type="file" accept="image/jpeg,image/png,image/webp" hidden data-change="co-logo"></label><p class="hint muted">${ML.t('JPG, PNG ou WebP, carré de préférence.')}</p></div></div>
          <div class="form-grid cols-2">
            <div class="field span-2"><label for="c-name">${ML.t('Nom')}</label><input class="input" id="c-name" name="name" required value="${c.name}"></div>
            <div class="field span-2"><label for="c-about">${ML.t('Présentation')}</label><textarea class="textarea" id="c-about" name="about" maxlength="1500">${c.about}</textarea></div>
            <div class="field"><label for="c-phone">${ML.t('Téléphone')}</label><input class="input" id="c-phone" name="phone" type="tel" value="${c.phone}"></div>
            <div class="field"><label for="c-email">${ML.t('Email de contact')}</label><input class="input" id="c-email" name="email" type="email" value="${c.email}"><span class="hint">${ML.t('Reçoit les nouvelles commandes.')}</span></div>
            <div class="field"><label for="c-city">${ML.t('Ville')}</label><input class="input" id="c-city" name="city" value="${c.city}"></div>
            <div class="field"><label for="c-zone">${ML.t('Wilaya')}</label><select class="select" id="c-zone" name="wilaya"><option value="">—</option>${zones.map((z) => html`<option value="${z.code}" ${c.wilaya === z.code ? ML.raw('selected') : ''}>${ML.zoneName(z)}</option>`)}</select></div>
            <div class="field"><label for="c-sector">${ML.t('Secteur')}</label><select class="select" id="c-sector" name="sector"><option value="">—</option>${ML.boot.categories.map((k) => html`<option value="${k.key}" ${c.sector === k.key ? ML.raw('selected') : ''}>${k.label}</option>`)}</select></div>
            <div class="field"><label for="c-web">${ML.t('Site web')}</label><input class="input" id="c-web" name="website" placeholder="https://" value="${c.website}"></div>
          </div>
          <p class="form-error" data-error hidden></p>
          <div><button class="btn btn-primary" type="submit">${ML.t('Enregistrer')}</button></div>
        </form>
        <div class="stack">${accountForms(u)}</div>
      </div>`;
    ML.changes['co-logo'] = async (el) => {
      if (!el.files[0]) return;
      const p = await E.upload(el.files[0]);
      if (p) { logo = p; $('[data-logo]').innerHTML = ML.mono(c.name, '#ff5a2b', logo, 'mono-lg'); }
      el.value = '';
    };
    ML.forms.company = async (f) => {
      const res = await E.send('company_save', Object.assign(ML.formData(f), { logo }), f);
      if (res) { E.me.company = res.company; $$('[data-co-name]').forEach((n) => { n.textContent = res.company.name; }); ML.toast(ML.t('Entreprise enregistrée.'), 'good'); }
    };
    bindAccount();
  };
  const accountForms = (u) => html`
    <form class="card card-pad stack" data-form="account">
      <h2 class="card-title">${ML.t('Votre compte')}</h2>
      <div class="field"><label for="a-name">${ML.t('Nom')}</label><input class="input" id="a-name" name="name" required value="${u.name}"></div>
      <div class="field"><label for="a-email">${ML.t('Email de connexion')}</label><input class="input" id="a-email" name="email" type="email" required value="${u.email}"></div>
      <div class="field"><label for="a-phone">${ML.t('Téléphone')}</label><input class="input" id="a-phone" name="phone" type="tel" value="${u.phone}"></div>
      <p class="form-error" data-error hidden></p>
      <div><button class="btn btn-line" type="submit">${ML.t('Enregistrer')}</button></div>
    </form>
    <form class="card card-pad stack" data-form="password">
      <h2 class="card-title">${ML.t('Mot de passe')}</h2>
      <div class="field"><label for="p-cur">${ML.t('Mot de passe actuel')}</label><input class="input" id="p-cur" name="current" type="password" autocomplete="current-password"></div>
      <div class="field"><label for="p-new">${ML.t('Nouveau mot de passe')}</label><input class="input" id="p-new" name="password" type="password" autocomplete="new-password" minlength="8" required><span class="hint">${ML.t('8 caractères au minimum.')}</span></div>
      <p class="form-error" data-error hidden></p>
      <div><button class="btn btn-line" type="submit">${ML.t('Changer le mot de passe')}</button></div>
    </form>`;
  function bindAccount() {
    ML.forms.account = async (f) => { const res = await E.send('account_save', ML.formData(f), f); if (res) { E.me.user = Object.assign({}, E.me.user, res.user); ML.toast(ML.t('Compte enregistré.'), 'good'); } };
    ML.forms.password = async (f) => { if (await E.send('password', ML.formData(f), f)) { f.reset(); ML.toast(ML.t('Mot de passe changé.'), 'good'); } };
  }
  function accountView() {
    view().innerHTML = html`
      ${E.head(ML.t('Mon compte'), ML.t("Vos identifiants de connexion. Les informations de l'entreprise sont gérées par") + ' ' + E.me.company.name + '.')}
      <div class="cols">${accountForms(E.me.user)}</div>`;
    bindAccount();
  }

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
      document.getElementById('app').innerHTML = html`<div class="empty" style="padding-top:120px">${icon('wifi-off')}<h3>${ML.t("L'espace est momentanément indisponible")}</h3><p>${e.message}</p></div>`;
    }
  })();
})();
