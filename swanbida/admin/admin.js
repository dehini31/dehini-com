/* Administration SWANBIDA — application d'une page, sans dépendance. */
(function () {
  'use strict';

  /* ================================================================ outils */
  var API = 'api.php';
  var state = { user: null, csrf: '', maxId: 0, pending: 0, seen: 0 };
  var app = document.getElementById('app');

  function h(tag, attrs) {
    var el = document.createElement(tag);
    if (attrs) for (var k in attrs) {
      var v = attrs[k];
      if (v == null || v === false) continue;
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k === 'text') el.textContent = v;
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), v);
      else if (k === 'value') el.value = v;
      else if (k === 'checked') el.checked = !!v;
      else el.setAttribute(k, v === true ? '' : v);
    }
    for (var i = 2; i < arguments.length; i++) add(el, arguments[i]);
    return el;
  }
  function add(el, c) {
    if (c == null || c === false) return;
    if (Array.isArray(c)) { c.forEach(function (x) { add(el, x); }); return; }
    el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); return el; }
  function money(n) { return Math.round(n || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' DA'; }
  function pct(a, b) { return b ? Math.round(a * 1000 / b) / 10 + ' %' : '—'; }
  function dt(t) { if (!t) return ''; var d = new Date(t * 1000); return d.toLocaleDateString('fr-FR') + ' ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); }
  function day(t) { return new Date(t * 1000).toLocaleDateString('fr-FR'); }
  function ago(t) {
    var s = Math.floor(Date.now() / 1000 - t);
    if (s < 60) return 'à l\'instant'; if (s < 3600) return 'il y a ' + Math.floor(s / 60) + ' min';
    if (s < 86400) return 'il y a ' + Math.floor(s / 3600) + ' h'; return day(t);
  }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function bi(v) { return v && typeof v === 'object' ? v : { fr: '', ar: '' }; }
  function toast(msg, err) {
    var t = h('div', { class: 'toast' + (err ? ' err' : '') }, msg);
    document.getElementById('toasts').appendChild(t);
    setTimeout(function () { t.remove(); }, err ? 7000 : 4000);
  }
  var ICONS = {
    phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
    wa: '<path d="M4 20l1.2-4A8 8 0 1 1 8 18.8z"/><path d="M9 8.8c0 3.2 2.9 6.2 6.2 6.2l1.3-1.6-2-1-1 1c-1.2-.4-2.5-1.7-2.9-2.9l1-1-1-2L9 8.8z"/>',
    up: '<path d="M6 15l6-6 6 6"/>', down: '<path d="M6 9l6 6 6-6"/>', x: '<path d="M6 6l12 12M18 6L6 18"/>',
    ext: '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    dl: '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>', bell: '<path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>'
  };
  function icon(n) { var s = document.createElementNS('http://www.w3.org/2000/svg', 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('class', 'icon'); s.innerHTML = ICONS[n] || ''; return s; }
  var SWAN = '<svg viewBox="0 0 100 100"><g fill="currentColor" stroke="currentColor"><path d="M30 66 C 32 82, 68 86, 90 52 C 80 60, 66 60, 57 55 C 48 50, 38 54, 30 66 Z"/><path d="M33 64 C 25 54, 27 42, 38 34 C 46 28, 48 21, 42 16.5 C 37 13, 31 15.5, 30 21" fill="none" stroke-width="6.2" stroke-linecap="round"/><path d="M30.5 19 L 22 25.5 L 31.5 24.5 Z"/></g></svg>';

  var STATUS = {
    new: 'Nouvelle', confirmed: 'Confirmée', preparing: 'En préparation', shipped: 'Expédiée',
    delivered: 'Livrée', cancelled: 'Annulée', returned: 'Retournée'
  };
  var FLOW = ['new', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled', 'returned'];
  function badge(st) { return h('span', { class: 'badge s-' + st }, STATUS[st] || st); }

  function api(action, opt) {
    opt = opt || {};
    var url = API + '?action=' + encodeURIComponent(action);
    if (opt.query) for (var k in opt.query) if (opt.query[k] !== '' && opt.query[k] != null) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opt.query[k]);
    var init = { credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
    if (opt.body !== undefined || opt.form) {
      init.method = 'POST';
      init.headers['X-CSRF'] = state.csrf;
      if (opt.form) init.body = opt.form;
      else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opt.body); }
    }
    return fetch(url, init).then(function (r) {
      return r.json().catch(function () { return { error: 'Réponse invalide du serveur (' + r.status + ').' }; }).then(function (j) {
        if (r.status === 401 && action !== 'login' && action !== 'session') { state.user = null; renderLogin('Votre session a expiré.'); throw new Error('auth'); }
        if (!r.ok || j.error) { var e = new Error(j.error || ('Erreur ' + r.status)); e.data = j; throw e; }
        return j;
      });
    });
  }
  function fail(e) { if (e && e.message !== 'auth') toast(e.message || String(e), true); }
  function can(r) { return state.user && state.user.rights.indexOf(r) !== -1; }

  /* ============================================================ connexion */
  function renderLogin(msg) {
    stopPoll();
    clear(app);
    // Par défaut : connexion du propriétaire (mot de passe seul). Le champ email n'apparaît que pour
    // l'équipe, sinon le navigateur y glisse un identifiant enregistré et bloque l'envoi.
    var team = sessionStorage.getItem('swb_team') === '1';
    var err = h('p', { class: 'err' }, msg || '');
    var email = h('input', { type: 'text', inputmode: 'email', autocomplete: team ? 'username' : 'off', autocapitalize: 'off', spellcheck: 'false', placeholder: 'prenom@exemple.com' });
    var pass = h('input', { type: 'password', autocomplete: 'current-password', required: true });
    var btn = h('button', { class: 'btn btn-p btn-lg', type: 'submit', style: 'width:100%' }, 'Se connecter');
    var emailRow = h('label', { class: 'f', hidden: !team }, 'Email', email);
    var hint = h('span', { class: 'hint' });
    var toggle = h('a', { href: '#', class: 'small', onclick: function (e) { e.preventDefault(); setMode(!team); } });
    function setMode(t) {
      team = t;
      try { sessionStorage.setItem('swb_team', t ? '1' : '0'); } catch (x) { /* navigation privée */ }
      emailRow.hidden = !t;
      email.setAttribute('autocomplete', t ? 'username' : 'off');
      if (!t) email.value = '';
      hint.textContent = t ? 'Le mot de passe de votre compte d\'équipe' : 'Le mot de passe propriétaire de la boutique';
      toggle.textContent = t ? '← Connexion du propriétaire' : 'Membre de l\'équipe ? Se connecter avec un email';
      err.textContent = msg || '';
      (t ? email : pass).focus();
    }
    var f = h('form', {
      novalidate: true,
      onsubmit: function (e) {
        e.preventDefault(); err.textContent = '';
        var mail = team ? email.value.trim() : '';
        if (team && mail.indexOf('@') === -1) { err.textContent = 'Entrez l\'email de votre compte d\'équipe.'; email.focus(); return; }
        if (!pass.value) { err.textContent = 'Entrez le mot de passe.'; pass.focus(); return; }
        btn.disabled = true;
        api('login', { body: { email: mail, password: pass.value } }).then(function (j) {
          state.user = j.user; state.csrf = j.csrf; start();
        }).catch(function (e) { err.textContent = e.message; btn.disabled = false; pass.select(); });
      }
    },
      h('div', { class: 'logo', html: SWAN + 'SWANBIDA' }),
      h('p', null, 'Administration de la boutique'),
      emailRow,
      h('label', { class: 'f' }, 'Mot de passe', hint, pass),
      btn, err, h('p', { style: 'margin:14px 0 0' }, toggle));
    app.appendChild(h('div', { class: 'login' }, f));
    setMode(team);
  }

  /* ================================================================ coque */
  var NAV = [
    ['tableau', 'Tableau de bord', 'dashboard'], ['commandes', 'Commandes', 'orders'], ['produits', 'Produits & stock', 'catalog'],
    ['collections', 'Collections', 'catalog'], ['contenus', 'Contenus', 'content'], ['guides', 'Guides', 'content'],
    ['livraison', 'Livraison', 'shipping'], ['meta', 'Meta & suivi', 'meta'], ['reglages', 'Réglages', 'settings'], ['compte', 'Mon compte', 'orders']
  ];
  var mainEl, navEl;
  function shell() {
    clear(app);
    navEl = h('nav', { class: 'nav' });
    mainEl = h('main', { class: 'main' });
    var side = h('aside', { class: 'side' },
      h('a', { class: 'brand', href: '#/' + (can('dashboard') ? 'tableau' : 'commandes'), html: SWAN + 'SWANBIDA' }), navEl,
      h('div', { class: 'side-foot' }, h('div', null, state.user.name + ' · ' + ({ owner: 'propriétaire', manager: 'gestion', agent: 'confirmation' }[state.user.role])),
        h('a', { href: '../', target: '_blank' }, 'Voir la boutique'), ' · ',
        h('a', { href: '#', onclick: function (e) { e.preventDefault(); api('logout', { body: {} }).finally(function () { renderLogin(); }); } }, 'Déconnexion')));
    app.appendChild(h('div', { class: 'shell' }, side, mainEl));
    drawNav();
  }
  function drawNav() {
    if (!navEl) return;
    clear(navEl);
    var cur = (location.hash.replace(/^#\/?/, '').split('/')[0]) || '';
    NAV.forEach(function (n) {
      if (!can(n[2])) return;
      if (n[0] === 'compte' && state.user.role === 'owner') return;
      navEl.appendChild(h('a', { href: '#/' + n[0], class: cur === n[0] ? 'on' : '' }, n[1],
        n[0] === 'commandes' && state.pending ? h('span', { class: 'count', title: 'Commandes à confirmer' }, state.pending) : null));
    });
  }
  function page(title, sub, actions) {
    clear(mainEl);
    mainEl.appendChild(h('div', { class: 'top' }, h('div', null, h('h1', null, title), sub ? h('div', { class: 'sub' }, sub) : null), actions ? h('div', { class: 'row' }, actions) : null));
    window.scrollTo(0, 0);
    return mainEl;
  }

  /* ======================================================= notifications */
  var pollTimer = null, audioCtx = null;
  function beep() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.18].forEach(function (d, i) {
        var o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.frequency.value = i ? 1320 : 880; o.connect(g); g.connect(audioCtx.destination);
        g.gain.setValueAtTime(0.0001, audioCtx.currentTime + d); g.gain.exponentialRampToValueAtTime(0.25, audioCtx.currentTime + d + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + d + 0.16);
        o.start(audioCtx.currentTime + d); o.stop(audioCtx.currentTime + d + 0.18);
      });
    } catch (e) { /* son indisponible */ }
  }
  function poll(first) {
    api('poll', { query: { since: state.maxId } }).then(function (j) {
      state.pending = j.pending;
      if (!first && j.items.length && j.max_id > state.maxId) {
        var n = j.items.filter(function (o) { return o.id > state.maxId; });
        n.forEach(function (o) {
          toast('🦢 Nouvelle commande ' + o.ref + ' — ' + o.name + ' — ' + money(o.total));
          if (window.Notification && Notification.permission === 'granted') {
            try { var no = new Notification('Nouvelle commande ' + o.ref, { body: o.name + ' · ' + o.wilaya_name + ' · ' + money(o.total), tag: o.ref }); no.onclick = function () { window.focus(); location.hash = '#/commandes/' + o.id; }; } catch (e) { /* */ }
          }
        });
        if (n.length) { beep(); if (/^#\/commandes\/?$/.test(location.hash) || location.hash === '') route(); }
      }
      state.maxId = Math.max(state.maxId, j.max_id);
      document.title = (state.pending ? '(' + state.pending + ') ' : '') + 'Administration — SWANBIDA';
      drawNav();
    }).catch(function () { /* hors ligne : on réessaiera */ });
  }
  function startPoll() { stopPoll(); poll(true); pollTimer = setInterval(function () { poll(false); }, 25000); }
  function stopPoll() { if (pollTimer) clearInterval(pollTimer); pollTimer = null; }

  /* ================================================================ routes */
  function route() {
    if (!state.user) return;
    var parts = location.hash.replace(/^#\/?/, '').split('/');
    var r = parts[0] || (can('dashboard') ? 'tableau' : 'commandes');
    drawNav();
    var views = {
      tableau: viewDashboard, commandes: parts[1] ? function () { viewOrder(+parts[1]); } : viewOrders,
      produits: parts[1] ? function () { viewProduct(parts[1]); } : viewProducts, collections: viewCollections, contenus: viewContent,
      guides: parts[1] ? function () { viewArticle(parts[1]); } : viewArticles, livraison: viewShipping, meta: viewMeta, reglages: viewSettings, compte: viewAccount
    };
    var need = { tableau: 'dashboard', commandes: 'orders', produits: 'catalog', collections: 'catalog', contenus: 'content', guides: 'content', livraison: 'shipping', meta: 'meta', reglages: 'settings', compte: 'orders' };
    if (!views[r] || !can(need[r])) { location.hash = '#/' + (can('dashboard') ? 'tableau' : 'commandes'); return; }
    views[r]();
  }
  window.addEventListener('hashchange', route);
  function start() { shell(); startPoll(); if (!location.hash) location.hash = '#/' + (can('dashboard') ? 'tableau' : 'commandes'); else route(); }

  /* ======================================================= tableau de bord */
  function viewDashboard() {
    var days = +(sessionStorage.getItem('swb_days') || 30);
    var sel = h('select', { style: 'width:auto', onchange: function () { sessionStorage.setItem('swb_days', sel.value); viewDashboard(); } },
      [1, 7, 30, 90, 365].map(function (d) { return h('option', { value: d, selected: d === days ? true : null }, d === 1 ? "Aujourd'hui" : d + ' derniers jours'); }));
    var m = page('Tableau de bord', null, sel);
    var body = h('div', null, h('p', { class: 'muted' }, 'Chargement…'));
    m.appendChild(body);
    api('stats', { query: { days: days } }).then(function (s) {
      clear(body);
      var k = s.kpi;
      if (!s.capi_ready) body.appendChild(h('div', { class: 'alert alert-w' }, 'Le suivi Meta côté serveur (Conversions API) n\'est pas encore configuré. ', can('meta') ? h('a', { href: '#/meta' }, 'Configurer') : null));
      if (s.capi_last_error) body.appendChild(h('div', { class: 'alert alert-d small' }, 'Dernière erreur Meta : ' + s.capi_last_error));
      if (s.notify_last_error) body.appendChild(h('div', { class: 'alert alert-d small' }, 'Dernière erreur de notification : ' + s.notify_last_error));
      var decided = k.n - k.pending;
      body.appendChild(h('div', { class: 'grid g4' },
        kpi(k.n, 'Commandes reçues', money(k.v) + ' demandés'),
        kpi(k.pending, 'À confirmer', k.pending ? h('a', { href: '#/commandes', onclick: function () { sessionStorage.setItem('swb_of', JSON.stringify({ status: 'new' })); } }, 'Traiter maintenant') : 'Rien en attente'),
        kpi(k.confirmed, 'Confirmées', pct(k.confirmed, decided) + ' des commandes traitées'),
        kpi(k.delivered, 'Livrées', money(k.delivered_v) + ' encaissés'),
        kpi(k.n ? money(k.v / k.n) : '—', 'Panier moyen', null),
        kpi(k.shipped, 'Expédiées', pct(k.delivered, k.shipped) + ' livrées'),
        kpi(k.cancelled, 'Annulées', pct(k.cancelled, k.n)),
        kpi(k.returned, 'Retournées', pct(k.returned, k.shipped) + ' des expédiées')
      ));
      // Graphique
      var c = h('div', { class: 'card' }, h('h2', null, 'Commandes par jour'), chart(s.series),
        h('p', { class: 'small muted' }, '■ clair : commandes reçues · ■ vert : confirmées (par jour de commande)'));
      body.appendChild(c);
      // Tunnel
      var f = s.funnel;
      var steps = [['Visites', f.visit], ['Pages vues', f.pageview], ['Vues produit', f.view_content], ['Ajouts (offre choisie)', f.add_to_cart],
        ['Débuts de commande', f.initiate_checkout], ['Commandes (Lead)', k.n], ['Confirmées (Purchase)', k.confirmed], ['Livrées', k.delivered]];
      var maxF = Math.max.apply(null, steps.map(function (x) { return x[1] || 0; }).concat([1]));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Tunnel de conversion'),
        h('div', { class: 'funnel' }, steps.map(function (st, i) {
          var prev = i ? steps[i - 1][1] : 0;
          return h('div', { class: 'fr' }, h('span', null, st[0]), h('div', { class: 'track' }, h('div', { class: 'fill', style: 'width:' + Math.max(1, (st[1] || 0) * 100 / maxF) + '%' })),
            h('span', { class: 'small' }, h('b', null, st[1] || 0), i ? ' · ' + pct(st[1], prev) : ''));
        })),
        h('p', { class: 'small muted', style: 'margin-top:10px' }, 'Purchase est compté à la confirmation ' + (s.purchase_trigger === 'delivered' ? '— réglage actuel : à la livraison.' : 'téléphonique de la commande (réglage modifiable dans Meta & suivi).'))));
      // Campagnes et publicités
      body.appendChild(h('div', { class: 'grid g2' },
        groupTable('Campagnes (Meta Ads)', s.campaigns, 'campaign'), groupTable('Publicités', s.ads, 'ad')));
      body.appendChild(h('div', { class: 'grid g2' },
        groupTable('Sources (utm_source)', s.sources, 'source'), groupTable('Wilayas', s.wilayas, 'wilaya')));
      var cap = {};
      s.capi.forEach(function (r) { cap[r.name] = cap[r.name] || {}; cap[r.name][r.status] = +r.n; });
      body.appendChild(h('div', { class: 'grid g2' },
        h('div', { class: 'card' }, h('h2', null, 'Événements Meta envoyés par le serveur'),
          Object.keys(cap).length ? h('table', { class: 'tbl' }, h('thead', null, h('tr', null, h('th', null, 'Événement'), h('th', { class: 'n' }, 'Envoyés'), h('th', { class: 'n' }, 'En attente'), h('th', { class: 'n' }, 'Échecs'))),
            h('tbody', null, Object.keys(cap).map(function (n) { var x = cap[n]; return h('tr', null, h('td', null, n), h('td', { class: 'n' }, x.sent || 0), h('td', { class: 'n' }, x.pending || 0), h('td', { class: 'n' }, (x.failed || 0) + (x.skipped || 0))); })))
            : h('p', { class: 'muted' }, 'Aucun événement de commande sur la période.'),
          h('p', { class: 'small muted', style: 'margin-top:8px' }, 'Navigation doublée côté serveur : ' + s.capi_browser.ok + ' envoyés, ' + s.capi_browser.fail + ' échecs.')),
        h('div', { class: 'card' }, h('h2', null, 'Stock'),
          s.low_stock.length ? h('ul', null, s.low_stock.map(function (p) { return h('li', null, h('a', { href: '#/produits/' + p.id }, p.name), ' : ', h('b', null, p.stock), ' restant(s)'); }))
            : h('p', { class: 'muted' }, 'Aucune alerte de stock.'))));
    }).catch(fail);
  }
  function kpi(v, label, sub) { return h('div', { class: 'kpi' }, h('span', null, label), h('b', null, v), sub ? h('small', null, sub) : null); }
  function chart(series) {
    var W = 700, H = 190, pad = 22, n = series.length, max = Math.max.apply(null, series.map(function (d) { return d.orders; }).concat([4]));
    var bw = (W - pad) / n;
    var svg = '<svg class="chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">';
    series.forEach(function (d, i) {
      var x = pad + i * bw + bw * 0.12, w = Math.max(1, bw * 0.76), h1 = (H - 30) * d.orders / max, h2 = (H - 30) * d.confirmed / max;
      svg += '<rect class="bar" x="' + x + '" y="' + (H - 18 - h1) + '" width="' + w + '" height="' + h1 + '"><title>' + d.d + ' : ' + d.orders + ' commandes, ' + d.confirmed + ' confirmées</title></rect>';
      svg += '<rect class="bar2" x="' + (x + w * 0.2) + '" y="' + (H - 18 - h2) + '" width="' + (w * 0.6) + '" height="' + h2 + '"/>';
      if (n <= 31 && (i % Math.ceil(n / 10) === 0) || n > 31 && i % Math.ceil(n / 8) === 0) svg += '<text class="ax" x="' + (x + w / 2) + '" y="' + (H - 4) + '" text-anchor="middle">' + d.d.slice(5).split('-').reverse().join('/') + '</text>';
    });
    svg += '<text class="ax" x="2" y="12">' + max + '</text></svg>';
    return h('div', { html: svg });
  }
  function groupTable(title, rows, kind) {
    return h('div', { class: 'card' }, h('h2', null, title), rows.length ? h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
      h('thead', null, h('tr', null, h('th', null, kind === 'wilaya' ? 'Wilaya' : 'Nom / ID'), h('th', { class: 'n' }, 'Cmd'), h('th', { class: 'n' }, 'Confirm.'), h('th', { class: 'n' }, 'Livrées'), h('th', { class: 'n' }, 'CA livré'))),
      h('tbody', null, rows.map(function (r) {
        var lbl = r.g === '' || r.g == null ? '(aucune)' : (kind === 'wilaya' ? r.g + ' ' + (r.label || '') : (r.label && r.label !== r.g ? r.label + ' · ' + r.g : r.g));
        var q = {}; q[kind] = r.g || '';
        return h('tr', null, h('td', null, r.g ? h('a', { href: '#/commandes', onclick: function () { sessionStorage.setItem('swb_of', JSON.stringify(q)); } }, lbl) : lbl),
          h('td', { class: 'n' }, r.n), h('td', { class: 'n' }, r.confirmed + ' (' + pct(r.confirmed, r.n) + ')'), h('td', { class: 'n' }, r.delivered), h('td', { class: 'n' }, money(r.delivered_v)));
      })))) : h('p', { class: 'muted' }, 'Aucune donnée sur la période.'));
  }

  /* ============================================================= commandes */
  var geoCache = null;
  function geo() {
    if (geoCache) return Promise.resolve(geoCache);
    return fetch('../api.php?action=geo&v=' + Date.now(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) { geoCache = j.w; return geoCache; });
  }
  function viewOrders() {
    var f = {};
    try { f = JSON.parse(sessionStorage.getItem('swb_of') || '{}') || {}; } catch (e) { f = {}; }
    f.page = f.page || 1;
    var save = function () { sessionStorage.setItem('swb_of', JSON.stringify(f)); };
    var exportBtn = h('a', { class: 'btn', href: '#', onclick: function (e) { e.preventDefault(); var q = Object.assign({}, f); delete q.page; var u = API + '?action=orders_export'; for (var k in q) if (q[k]) u += '&' + k + '=' + encodeURIComponent(q[k]); location.href = u; } }, icon('dl'), 'Exporter (Excel / CSV)');
    var notifBtn = window.Notification && Notification.permission !== 'granted' ? h('button', { class: 'btn', onclick: function () { Notification.requestPermission().then(function () { notifBtn.remove(); beep(); }); } }, icon('bell'), 'Activer les alertes') : null;
    var m = page('Commandes', 'Les nouvelles commandes apparaissent toutes seules, avec un signal sonore.', [notifBtn, exportBtn]);
    var tabs = h('div', { class: 'tabs' });
    var q = h('input', { type: 'search', placeholder: 'N°, nom, téléphone, commune, n° de suivi…', value: f.q || '' });
    var wSel = h('select', null, h('option', { value: '' }, 'Toutes les wilayas'));
    var from = h('input', { type: 'date', value: f.from || '', title: 'Du' }), to = h('input', { type: 'date', value: f.to || '', title: 'Au' });
    var more = h('select', null, h('option', { value: '' }, 'Toutes les sources'), h('option', { value: 'dup' }, 'Doublons possibles'), h('option', { value: 'facebook' }, 'utm_source = facebook'), h('option', { value: 'instagram' }, 'utm_source = instagram'), h('option', { value: '(direct)' }, 'Sans source (direct)'));
    more.value = f.dup ? 'dup' : (f.source || '');
    var deb = null;
    q.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(function () { f.q = q.value; f.page = 1; save(); load(); }, 300); });
    [wSel, from, to, more].forEach(function (el) { el.addEventListener('change', function () { f.wilaya = wSel.value; f.from = from.value; f.to = to.value; f.dup = more.value === 'dup' ? 1 : ''; f.source = more.value !== 'dup' ? more.value : ''; f.page = 1; save(); load(); }); });
    geo().then(function (w) { w.forEach(function (x) { wSel.appendChild(h('option', { value: x.c }, x.c + ' – ' + x.fr)); }); wSel.value = f.wilaya || ''; });
    var extra = h('div');
    m.appendChild(tabs);
    m.appendChild(h('div', { class: 'filters' }, h('div', { class: 'wide' }, q), wSel, from, to, more));
    m.appendChild(extra);
    var list = h('div', { class: 'olist' });
    var pager = h('div', { class: 'pager' });
    var selected = {};
    var bulk = h('div', { class: 'bulk', hidden: true });
    m.appendChild(list); m.appendChild(pager); m.appendChild(bulk);
    function load() {
      clear(extra);
      if (f.campaign || f.ad || f.product) extra.appendChild(h('div', { class: 'alert alert-ok small' }, 'Filtre : ' + (f.campaign ? 'campagne ' + f.campaign : f.ad ? 'publicité ' + f.ad : 'produit ' + f.product) + ' ',
        h('a', { href: '#', onclick: function (e) { e.preventDefault(); delete f.campaign; delete f.ad; delete f.product; save(); load(); } }, '(retirer)')));
      api('orders', { query: f }).then(function (j) {
        clear(tabs);
        var all = 0; for (var s in j.counts) all += j.counts[s];
        [[''].concat(['Toutes', all])].concat(FLOW.map(function (s) { return [s, STATUS[s], j.counts[s]]; })).forEach(function (t) {
          tabs.appendChild(h('button', { class: (f.status || '') === t[0] ? 'on' : '', onclick: function () { f.status = t[0]; f.page = 1; save(); load(); } }, t[1], h('span', { class: 'n' }, t[2])));
        });
        clear(list); selected = {}; drawBulk();
        if (!j.items.length) list.appendChild(h('p', { class: 'muted' }, 'Aucune commande.'));
        j.items.forEach(function (o) {
          var cb = h('input', { type: 'checkbox', onclick: function (e) { e.stopPropagation(); }, onchange: function () { if (cb.checked) selected[o.id] = 1; else delete selected[o.id]; drawBulk(); } });
          var row = h('a', { class: 'orow' + (o.status === 'new' ? ' unread' : ''), href: '#/commandes/' + o.id },
            cb,
            h('div', null,
              h('div', { class: 'l1' }, h('span', { class: 'ref' }, o.ref), badge(o.status), o.dup ? h('span', { class: 'badge b-dup' }, 'doublon ?') : null, h('span', { class: 'small muted' }, ago(o.created)), h('span', { class: 'tot' }, money(o.total))),
              h('div', { class: 'l2' }, h('b', null, o.name), ' · ', h('span', { dir: 'ltr' }, o.phone_display), ' · ', o.commune + ', ' + o.wilaya + ' ' + o.wilaya_name),
              h('div', { class: 'l3' }, o.items + (o.source ? ' · ' + o.source + (o.campaign ? ' / ' + o.campaign : '') : '') + (o.note ? ' · 📝 ' + o.note : '') + (o.admin_note ? ' · 🔒 ' + o.admin_note : ''))
            ));
          if (o.status === 'new') {
            row.appendChild(h('div', { class: 'acts' },
              h('a', { class: 'btn btn-sm', href: 'tel:' + o.phone, onclick: function (e) { e.stopPropagation(); } }, icon('phone'), 'Appeler'),
              h('button', { class: 'btn btn-sm btn-p', onclick: function (e) { e.preventDefault(); e.stopPropagation(); setStatus(o.id, 'confirmed').then(load); } }, 'Confirmer'),
              h('button', { class: 'btn btn-sm btn-d', onclick: function (e) { e.preventDefault(); e.stopPropagation(); if (confirm('Annuler la commande ' + o.ref + ' ?')) setStatus(o.id, 'cancelled').then(load); } }, 'Annuler')));
          }
          list.appendChild(row);
        });
        clear(pager);
        if (j.pages > 1) {
          pager.appendChild(h('button', { class: 'btn btn-sm', disabled: j.page <= 1 ? true : null, onclick: function () { f.page--; save(); load(); } }, '‹ Précédent'));
          pager.appendChild(h('span', null, 'Page ' + j.page + ' / ' + j.pages + ' · ' + j.total + ' commandes'));
          pager.appendChild(h('button', { class: 'btn btn-sm', disabled: j.page >= j.pages ? true : null, onclick: function () { f.page++; save(); load(); } }, 'Suivant ›'));
        } else pager.appendChild(h('span', null, j.total + ' commande(s)'));
      }).catch(fail);
    }
    function drawBulk() {
      var ids = Object.keys(selected);
      bulk.hidden = !ids.length;
      clear(bulk);
      if (!ids.length) return;
      var s = h('select', null, FLOW.map(function (x) { return h('option', { value: x }, STATUS[x]); }));
      bulk.appendChild(h('span', null, ids.length + ' sélectionnée(s) →'));
      bulk.appendChild(s);
      bulk.appendChild(h('button', { class: 'btn btn-sm', onclick: function () {
        if (!confirm('Passer ' + ids.length + ' commande(s) au statut « ' + STATUS[s.value] + ' » ?')) return;
        api('order_bulk_status', { body: { ids: ids.map(Number), status: s.value } }).then(function (j) { toast(j.n + ' commande(s) mise(s) à jour'); load(); }).catch(fail);
      } }, 'Appliquer'));
    }
    load();
  }
  function setStatus(id, st, note) {
    return api('order_status', { body: { id: id, status: st, note: note || '' } }).then(function () { toast('Statut : ' + STATUS[st]); poll(false); }).catch(fail);
  }

  function viewOrder(id) {
    var m = page('Commande', null, h('a', { class: 'btn', href: '#/commandes' }, '‹ Retour à la liste'));
    var body = h('div', null, h('p', { class: 'muted' }, 'Chargement…'));
    m.appendChild(body);
    Promise.all([api('order', { query: { id: id } }), geo()]).then(function (res) {
      var j = res[0], o = j.order, w = res[1];
      clear(body);
      mainEl.querySelector('h1').textContent = 'Commande ' + o.ref;
      mainEl.querySelector('.top > div').appendChild(h('div', { class: 'sub' }, 'Reçue le ' + dt(o.created) + ' · ' + (o.lang === 'ar' ? 'site arabe' : 'site français')));
      if (j.dup) body.appendChild(h('div', { class: 'alert alert-w' }, 'Doublon possible : le même téléphone a déjà commandé ce produit (', h('a', { href: '#/commandes/' + j.dup.id }, j.dup.ref), ', ' + STATUS[j.dup.status] + ', ' + dt(j.dup.created) + ').'));
      // Actions rapides
      var noteIn = h('input', { type: 'text', placeholder: 'Commentaire facultatif (ex. client injoignable, rappeler à 18 h)' });
      var bar = h('div', { class: 'status-bar' });
      FLOW.forEach(function (s) {
        bar.appendChild(h('button', { class: (o.status === s ? 'cur ' : '') + (s === nextOf(o.status) ? 'go' : ''), onclick: function () {
          if (s === o.status && !noteIn.value) return;
          if ((s === 'cancelled' || s === 'returned') && !confirm('Passer la commande au statut « ' + STATUS[s] + ' » ? Le stock sera remis à jour.')) return;
          setStatus(o.id, s, noteIn.value).then(function () { viewOrder(id); });
        } }, STATUS[s]));
      });
      body.appendChild(h('div', { class: 'card' },
        h('div', { class: 'row', style: 'margin-bottom:12px' }, badge(o.status), h('b', { class: 'grow' }, money(o.total) + ' à encaisser'),
          h('a', { class: 'btn', href: 'tel:' + j.tel }, icon('phone'), j.phone_display), h('a', { class: 'btn btn-wa', href: j.wa, target: '_blank', rel: 'noopener' }, icon('wa'), 'WhatsApp')),
        h('h3', null, 'Changer le statut'), bar, h('div', { style: 'margin-top:10px' }, noteIn),
        h('p', { class: 'small muted', style: 'margin-top:8px' }, 'Confirmer la commande envoie l\'événement Meta « Purchase » (une seule fois). « Livrée » envoie l\'événement de livraison.')));
      var det = h('div', { class: 'detail' });
      body.appendChild(det);
      // Formulaire client
      var F = {};
      function inp(k, label, type) { F[k] = h('input', { type: type || 'text', value: o[k] == null ? '' : o[k] }); return h('label', { class: 'f' }, label, F[k]); }
      F.wilaya = h('select', null, w.map(function (x) { return h('option', { value: x.c }, x.c + ' – ' + x.fr); }));
      if (!w.some(function (x) { return x.c === o.wilaya; })) F.wilaya.appendChild(h('option', { value: o.wilaya }, o.wilaya + ' – ' + o.wilaya_name));
      F.wilaya.value = o.wilaya;
      var dl = h('datalist', { id: 'communes-dl' });
      function fillCommunes() { clear(dl); var x = w.filter(function (y) { return y.c === F.wilaya.value; })[0]; (x ? x.m : []).forEach(function (c) { dl.appendChild(h('option', { value: c[0] })); }); }
      F.wilaya.addEventListener('change', fillCommunes); fillCommunes();
      F.commune = h('input', { type: 'text', value: o.commune, list: 'communes-dl' });
      F.qty = h('select', null, j.tiers.map(function (t) { return h('option', { value: t.qty }, t.label + ' — ' + money(t.price)); }));
      F.qty.value = o.qty;
      F.delivery = h('select', null, h('option', { value: 'home' }, 'À domicile'), h('option', { value: 'desk' }, 'Au bureau (stop desk)'));
      F.delivery.value = o.delivery;
      F.shipping = h('input', { type: 'number', min: 0, value: o.shipping });
      F.note = h('textarea', { rows: 2 }, o.note);
      F.admin_note = h('textarea', { rows: 2 }, o.admin_note);
      var left = h('div', null,
        h('div', { class: 'card' }, h('h2', null, 'Client et livraison'),
          h('div', { class: 'grid g2' }, inp('name', 'Nom et prénom'), inp('phone', 'Téléphone', 'tel')),
          h('div', { class: 'grid g2' }, h('label', { class: 'f' }, 'Wilaya', F.wilaya), h('label', { class: 'f' }, 'Commune', F.commune, dl)),
          inp('address', 'Adresse'),
          h('div', { class: 'grid g3' }, h('label', { class: 'f' }, 'Offre', F.qty), h('label', { class: 'f' }, 'Mode de livraison', F.delivery), h('label', { class: 'f' }, 'Frais de livraison (DA)', F.shipping)),
          h('div', { class: 'grid g2' }, inp('carrier', 'Transporteur'), inp('tracking', 'N° de suivi')),
          h('label', { class: 'f' }, 'Remarque du client', F.note),
          h('label', { class: 'f' }, 'Note interne (invisible pour le client)', F.admin_note),
          h('button', { class: 'btn btn-p', onclick: function () {
            var fields = {};
            ['name', 'phone', 'wilaya', 'commune', 'address', 'qty', 'delivery', 'shipping', 'carrier', 'tracking', 'note', 'admin_note'].forEach(function (k) { fields[k] = F[k].value; });
            if (+fields.shipping === o.shipping && fields.wilaya === o.wilaya && fields.delivery === o.delivery) delete fields.shipping;
            api('order_update', { body: { id: o.id, fields: fields } }).then(function () { toast('Commande enregistrée'); viewOrder(id); }).catch(fail);
          } }, 'Enregistrer les modifications')),
        h('div', { class: 'card' }, h('h2', null, 'Historique'),
          (function () {
            var ni = h('input', { type: 'text', placeholder: 'Ajouter une note à l\'historique…' });
            return h('div', { class: 'row', style: 'margin-bottom:12px' }, h('div', { class: 'grow' }, ni), h('button', { class: 'btn', onclick: function () {
              if (!ni.value.trim()) return; api('order_note', { body: { id: o.id, note: ni.value } }).then(function () { viewOrder(id); }).catch(fail);
            } }, 'Ajouter'));
          })(),
          h('ul', { class: 'log' }, j.log.map(function (l) {
            var t = l.kind === 'status' ? (STATUS[l.from_status] || '—') + ' → ' + STATUS[l.to_status] : l.kind === 'create' ? 'Commande créée' : l.kind === 'meta' ? 'Meta' : l.kind === 'note' ? 'Note' : 'Modification';
            return h('li', null, h('time', null, dt(l.created) + ' · ' + l.actor), h('b', null, t), l.detail ? h('div', null, l.detail) : null);
          }))));
      var it = o.items[0] || {};
      var attr = o.attribution || {};
      var right = h('div', null,
        h('div', { class: 'card' }, h('h2', null, 'Montant'), h('div', { class: 'amounts' },
          h('div', null, h('span', null, (it.label && it.label.fr) || (o.qty + ' ×') + ' – ' + ((it.name && it.name.fr) || '')), h('b', null, money(o.subtotal))),
          h('div', null, h('span', null, 'Livraison ' + (o.delivery === 'desk' ? '(bureau)' : '(domicile)')), h('b', null, o.shipping ? money(o.shipping) : 'gratuite')),
          h('div', { class: 't' }, h('span', null, 'Total à encaisser'), h('span', null, money(o.total)))),
          h('p', { class: 'small', style: 'margin-top:10px' }, h('a', { href: j.customer_url, target: '_blank', rel: 'noopener' }, 'Page de suivi du client ', icon('ext')))),
        h('div', { class: 'card' }, h('h2', null, 'Origine publicitaire'),
          (o.utm_source || o.campaign_id || o.fbclid || attr.referrer) ? h('dl', { class: 'kv' },
            row('Source', o.utm_source), row('Support', o.utm_medium), row('Campagne', o.utm_campaign), row('ID campagne', o.campaign_id), row('Ensemble (utm_term)', o.utm_term),
            row('ID ensemble', o.adset_id), row('Publicité (utm_content)', o.utm_content), row('ID publicité', o.ad_id), row('Placement', o.placement),
            row('Clic Meta (fbclid)', o.fbclid ? 'oui' : ''), row('Cookie _fbp', o.fbp ? 'oui' : ''), row('Page d\'arrivée', attr.landing), row('Provenance', attr.referrer), row('Page de commande', o.source_url))
            : h('p', { class: 'muted' }, 'Visite directe (aucun paramètre publicitaire).')),
        h('div', { class: 'card' }, h('h2', null, 'Événements Meta (serveur)'),
          j.events.length ? h('table', { class: 'tbl' }, h('tbody', null, j.events.map(function (e) {
            return h('tr', null, h('td', null, h('b', null, e.name), h('div', { class: 'small muted mono' }, e.event_id)), h('td', null, h('span', { class: 'badge b-' + e.status }, { sent: 'envoyé', pending: 'en attente', failed: 'échec', skipped: 'non envoyé' }[e.status] + (+e.test ? ' (test)' : '')),
              e.error ? h('div', { class: 'small', style: 'color:var(--danger)' }, e.error) : null), h('td', { class: 'small muted' }, dt(+e.sent || +e.created)));
          }))) : h('p', { class: 'muted' }, 'Aucun événement.'),
          h('p', { class: 'small muted', style: 'margin-top:8px' }, 'Lead : à la création. Purchase : à la confirmation (ou à la livraison selon le réglage). Le navigateur n\'envoie jamais Purchase.')));
      det.appendChild(left); det.appendChild(right);
    }).catch(fail);
    function row(k, v) { return v ? [h('dt', null, k), h('dd', null, v)] : null; }
  }
  function nextOf(s) { return { new: 'confirmed', confirmed: 'preparing', preparing: 'shipped', shipped: 'delivered' }[s]; }

  /* ============================================================== éditeurs */
  function counter(input, max) {
    var c = h('span', { class: 'counter' });
    var upd = function () { var n = input.value.length; c.textContent = n + ' / ' + max; c.classList.toggle('over', n > max); };
    input.addEventListener('input', upd); upd();
    return c;
  }
  /** Champ bilingue lié à obj[key] = {fr, ar}. */
  function biField(obj, key, label, opt) {
    opt = opt || {};
    obj[key] = bi(obj[key]);
    var mk = function (l) {
      var el = opt.multi ? h('textarea', { rows: opt.rows || 4, dir: l === 'ar' ? 'rtl' : 'ltr' }) : h('input', { type: 'text', dir: l === 'ar' ? 'rtl' : 'ltr' });
      el.value = obj[key][l] || '';
      if (opt.ph) el.placeholder = opt.ph[l] || '';
      el.addEventListener('input', function () { obj[key][l] = el.value; });
      return el;
    };
    var fr = mk('fr'), ar = mk('ar');
    return h('div', { class: 'bi' }, h('div', { class: 'lbl' }, h('span', null, label, opt.hint ? h('span', { class: 'hint' }, ' — ' + opt.hint) : null), opt.max ? h('span', null, counter(fr, opt.max), ' · ', counter(ar, opt.max)) : null),
      h('div', { class: 'pair' }, h('div', { class: 'lang', 'data-l': 'FR' }, fr), h('div', { class: 'lang', 'data-l': 'AR' }, ar)));
  }
  function textField(obj, key, label, opt) {
    opt = opt || {};
    var el = opt.multi ? h('textarea', { rows: opt.rows || 3 }) : h('input', { type: opt.type || 'text' });
    el.value = obj[key] == null ? '' : obj[key];
    if (opt.ph) el.placeholder = opt.ph;
    el.addEventListener('input', function () { obj[key] = opt.type === 'number' ? (el.value === '' ? '' : +el.value) : el.value; });
    return h('label', { class: 'f' }, label, opt.hint ? h('span', { class: 'hint' }, opt.hint) : null, el);
  }
  function checkField(obj, key, label) {
    var el = h('input', { type: 'checkbox', checked: !!obj[key] && obj[key] !== '0', onchange: function () { obj[key] = el.checked; } });
    return h('label', { class: 'check' }, el, label);
  }
  /** Liste éditable (ajout, suppression, réordonnancement). */
  function listEditor(arr, render, make, addLabel) {
    var wrap = h('div');
    function draw() {
      clear(wrap);
      arr.forEach(function (item, i) {
        var tools = h('div', { class: 'list-tools' },
          h('button', { type: 'button', title: 'Monter', disabled: i === 0 ? true : null, onclick: function () { arr.splice(i - 1, 0, arr.splice(i, 1)[0]); draw(); } }, icon('up')),
          h('button', { type: 'button', title: 'Descendre', disabled: i === arr.length - 1 ? true : null, onclick: function () { arr.splice(i + 1, 0, arr.splice(i, 1)[0]); draw(); } }, icon('down')),
          h('button', { type: 'button', title: 'Supprimer', onclick: function () { arr.splice(i, 1); draw(); } }, icon('x')));
        wrap.appendChild(h('div', { class: 'list-item' }, tools, render(item, i, draw)));
      });
      wrap.appendChild(h('button', { type: 'button', class: 'btn btn-sm', onclick: function () { arr.push(make()); draw(); } }, '+ ' + addLabel));
    }
    draw();
    return wrap;
  }
  function upload(file, kind) {
    var fd = new FormData(); fd.append('file', file);
    return api('upload', { query: { kind: kind || 'image' }, form: fd });
  }
  function imgUrl(im, w) { return '../' + im.src + '-' + (w || 480) + '.webp'; }
  /** Une image (couverture, visuel d'accueil…). */
  function imageField(obj, key, label) {
    var wrap = h('div', { style: 'margin-bottom:14px' });
    function draw() {
      clear(wrap);
      wrap.appendChild(h('div', { class: 'lbl', style: 'font-weight:600;font-size:.85rem;margin-bottom:6px' }, label));
      var im = obj[key];
      var file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', hidden: true, onchange: function () {
        if (!file.files[0]) return; toast('Envoi de la photo…');
        upload(file.files[0]).then(function (j) { j.image.alt = im && im.alt ? im.alt : { fr: '', ar: '' }; obj[key] = j.image; draw(); toast('Photo prête — pensez à enregistrer'); }).catch(fail);
      } });
      if (im) {
        im.alt = bi(im.alt);
        wrap.appendChild(h('div', { class: 'thumb', style: 'max-width:260px' }, h('img', { src: imgUrl(im), alt: '' }), h('div', { class: 'tb' },
          (function () { var a = h('input', { type: 'text', placeholder: 'Texte alternatif (FR)', value: im.alt.fr }); a.oninput = function () { im.alt.fr = a.value; }; return a; })(),
          (function () { var a = h('input', { type: 'text', dir: 'rtl', placeholder: 'النص البديل (AR)', value: im.alt.ar }); a.oninput = function () { im.alt.ar = a.value; }; return a; })(),
          h('div', { class: 'row' }, h('button', { type: 'button', class: 'btn btn-sm', onclick: function () { file.click(); } }, 'Remplacer'),
            h('button', { type: 'button', class: 'btn btn-sm btn-d', onclick: function () { obj[key] = null; draw(); } }, 'Retirer')))));
      } else wrap.appendChild(h('button', { type: 'button', class: 'btn', onclick: function () { file.click(); } }, '+ Choisir une photo'));
      wrap.appendChild(file);
    }
    draw();
    return wrap;
  }
  /** Galerie de photos d'un produit (plusieurs, ordre, textes alternatifs). */
  function gallery(arr) {
    var wrap = h('div');
    function draw() {
      clear(wrap);
      var grid = h('div', { class: 'thumbs' });
      arr.forEach(function (im, i) {
        im.alt = bi(im.alt);
        var afr = h('input', { type: 'text', placeholder: 'Texte alternatif (FR)', value: im.alt.fr }); afr.oninput = function () { im.alt.fr = afr.value; };
        var aar = h('input', { type: 'text', dir: 'rtl', placeholder: 'النص البديل (AR)', value: im.alt.ar }); aar.oninput = function () { im.alt.ar = aar.value; };
        grid.appendChild(h('div', { class: 'thumb' }, h('img', { src: imgUrl(im), alt: '' }), h('div', { class: 'tb' }, i === 0 ? h('span', { class: 'badge s-confirmed' }, 'Photo principale') : null, afr, aar,
          h('div', { class: 'row' },
            h('button', { type: 'button', class: 'btn btn-sm', title: 'Avant', disabled: i === 0 ? true : null, onclick: function () { arr.splice(i - 1, 0, arr.splice(i, 1)[0]); draw(); } }, '‹'),
            h('button', { type: 'button', class: 'btn btn-sm', title: 'Après', disabled: i === arr.length - 1 ? true : null, onclick: function () { arr.splice(i + 1, 0, arr.splice(i, 1)[0]); draw(); } }, '›'),
            h('button', { type: 'button', class: 'btn btn-sm btn-d', onclick: function () { arr.splice(i, 1); draw(); } }, 'Retirer')))));
      });
      wrap.appendChild(grid);
      var file = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', multiple: true, hidden: true, onchange: function () { send(file.files); } });
      var drop = h('div', { class: 'drop', style: 'margin-top:10px' }, 'Glissez vos photos ici ou ', h('a', { href: '#', onclick: function (e) { e.preventDefault(); file.click(); } }, 'choisissez des fichiers'),
        h('div', { class: 'small' }, 'JPG, PNG ou WebP, 15 Mo max. Converties automatiquement en WebP (480, 960 et 1600 px).'), file);
      drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('over'); });
      drop.addEventListener('dragleave', function () { drop.classList.remove('over'); });
      drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('over'); send(e.dataTransfer.files); });
      wrap.appendChild(drop);
    }
    function send(files) {
      var list = Array.prototype.slice.call(files || []);
      if (!list.length) return;
      toast('Envoi de ' + list.length + ' photo(s)…');
      list.reduce(function (p, f) { return p.then(function () { return upload(f).then(function (j) { arr.push(j.image); draw(); }); }); }, Promise.resolve())
        .then(function () { toast('Photos ajoutées — pensez à enregistrer'); }).catch(fail);
    }
    draw();
    return wrap;
  }
  function videos(arr) {
    var wrap = h('div');
    function draw() {
      clear(wrap);
      arr.forEach(function (v, i) {
        v.title = bi(v.title);
        wrap.appendChild(h('div', { class: 'list-item' }, h('div', { class: 'row' }, h('b', { class: 'grow' }, v.type === 'youtube' ? 'YouTube : ' + v.src : 'Vidéo : ' + v.src.split('/').pop()),
          h('button', { type: 'button', class: 'btn btn-sm btn-d', onclick: function () { arr.splice(i, 1); draw(); } }, 'Retirer')), biField(v, 'title', 'Titre (accessibilité)')));
      });
      var yt = h('input', { type: 'url', placeholder: 'Lien YouTube (https://youtu.be/…)' });
      var file = h('input', { type: 'file', accept: 'video/mp4,video/webm', hidden: true, onchange: function () {
        if (!file.files[0]) return; toast('Envoi de la vidéo… (peut prendre un moment)');
        upload(file.files[0], 'video').then(function (j) { arr.push(j.video); draw(); toast('Vidéo ajoutée — pensez à enregistrer'); }).catch(fail);
      } });
      wrap.appendChild(h('div', { class: 'row' }, h('div', { class: 'grow' }, yt), h('button', { type: 'button', class: 'btn btn-sm', onclick: function () {
        var m = yt.value.match(/(?:youtu\.be\/|v=|embed\/|shorts\/)([A-Za-z0-9_-]{11})/); if (!m) { toast('Lien YouTube invalide', true); return; }
        arr.push({ type: 'youtube', src: m[1], title: { fr: '', ar: '' } }); draw();
      } }, '+ YouTube'), h('button', { type: 'button', class: 'btn btn-sm', onclick: function () { file.click(); } }, '+ Fichier MP4'), file));
    }
    draw();
    return wrap;
  }

  /* ============================================================== produits */
  function viewProducts() {
    var m = page('Produits & stock', 'Prix, offres, photos, caractéristiques et stock.', h('a', { class: 'btn btn-p', href: '#/produits/nouveau' }, '+ Nouveau produit'));
    var body = h('div', { class: 'card' }, h('p', { class: 'muted' }, 'Chargement…'));
    m.appendChild(body);
    api('products').then(function (j) {
      clear(body);
      body.appendChild(h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
        h('thead', null, h('tr', null, h('th', null, ''), h('th', null, 'Produit'), h('th', null, 'État'), h('th', { class: 'n' }, 'Prix'), h('th', null, 'Stock'), h('th', null, ''))),
        h('tbody', null, j.items.map(function (p) {
          var st = h('input', { type: 'number', value: p.stock == null ? '' : p.stock, placeholder: 'non suivi' });
          return h('tr', null,
            h('td', null, p.image ? h('img', { class: 'pimg', src: imgUrl(p.image), alt: '' }) : null),
            h('td', null, h('a', { href: '#/produits/' + p.id }, h('b', null, p.name)), h('div', { class: 'small muted' }, '/produit/' + p.slug)),
            h('td', null, h('span', { class: 'badge ' + (p.status === 'active' ? 's-delivered' : 'b-skipped') }, p.status === 'active' ? 'En ligne' : 'Brouillon')),
            h('td', { class: 'n' }, money(p.price)),
            h('td', null, h('div', { class: 'row' }, st, h('button', { class: 'btn btn-sm', onclick: function () {
              api('product_stock', { body: { id: p.id, stock: st.value } }).then(function (r) { toast('Stock : ' + (r.stock == null ? 'non suivi' : r.stock)); }).catch(fail);
            } }, 'OK')), p.stock != null && p.stock <= p.stock_alert ? h('div', { class: 'small', style: 'color:var(--warn)' }, 'Stock bas') : null),
            h('td', null, h('a', { class: 'btn btn-sm', href: p.url, target: '_blank', rel: 'noopener' }, 'Voir')));
        })))));
      body.appendChild(h('p', { class: 'small muted', style: 'margin-top:10px' }, 'Le stock est décompté à chaque commande et remis en stock si la commande est annulée ou retournée. Laissez vide pour ne pas suivre le stock.'));
    }).catch(fail);
  }
  function viewProduct(idOrNew) {
    var isNew = idOrNew === 'nouveau';
    var m = page(isNew ? 'Nouveau produit' : 'Produit', null, h('a', { class: 'btn', href: '#/produits' }, '‹ Produits'));
    var body = h('div', null, h('p', { class: 'muted' }, 'Chargement…'));
    m.appendChild(body);
    (isNew ? api('product_new') : api('product', { query: { id: idOrNew } })).then(function (j) {
      var p = clone(j.product);
      clear(body);
      if (!isNew) mainEl.querySelector('h1').textContent = p.name.fr || p.name.ar;
      p.seo = p.seo || {}; p.seo.title = bi(p.seo.title); p.seo.description = bi(p.seo.description);
      p.stock = p.stock == null ? '' : p.stock;
      var cols = h('div', null, j.collections.map(function (c) {
        var cb = h('input', { type: 'checkbox', checked: p.collections.indexOf(c.id) !== -1, onchange: function () {
          p.collections = p.collections.filter(function (x) { return x !== c.id; }); if (cb.checked) p.collections.push(c.id);
        } });
        return h('label', { class: 'check', style: 'margin-inline-end:14px' }, cb, c.name.fr || c.name.ar);
      }));
      var statusSel = h('select', { onchange: function () { p.status = statusSel.value; } }, h('option', { value: 'active' }, 'En ligne'), h('option', { value: 'draft' }, 'Brouillon (invisible)'));
      statusSel.value = p.status;
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Général'),
        h('div', { class: 'grid g3' }, h('label', { class: 'f' }, 'État', statusSel), textField(p, 'sku', 'Référence (SKU)', { hint: 'Identifiant envoyé à Meta' }), textField(p, 'slug', 'Adresse (slug)', { hint: '/produit/…' })),
        h('div', { class: 'grid g3' }, textField(p, 'pos', 'Ordre d\'affichage', { type: 'number' }), textField(p, 'stock', 'Stock (unités)', { type: 'number', hint: 'Vide = non suivi' }), textField(p, 'stock_alert', 'Alerte stock bas', { type: 'number' })),
        checkField(p, 'featured', 'Mis en avant'), h('h3', null, 'Collections'), cols));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Textes'),
        biField(p, 'name', 'Nom', { max: 120 }), biField(p, 'tagline', 'Accroche', { max: 200 }), biField(p, 'short', 'Résumé', { multi: true, rows: 3 }),
        biField(p, 'description', 'Description', { multi: true, rows: 9, hint: '## titre, **gras**, - liste' }),
        h('h3', null, 'Points forts (pastilles)'), listEditor(p.highlights, function (it) { return pairInputs(it); }, function () { return { fr: '', ar: '' }; }, 'Ajouter un point fort')));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Offres et prix'),
        h('p', { class: 'small muted' }, 'Chaque offre est proposée dans le formulaire de commande (ex. 1 pack, 2 packs). Le montant de la commande est calculé par le serveur à partir de ces prix.'),
        listEditor(p.tiers, function (t, i, redraw) {
          var def = h('input', { type: 'radio', name: 'deftier', checked: !!t['default'], onchange: function () { p.tiers.forEach(function (x) { x['default'] = false; }); t['default'] = true; } });
          t.label = bi(t.label); t.badge = bi(t.badge);
          return h('div', null, h('div', { class: 'grid g3' }, textField(t, 'qty', 'Quantité (unités)', { type: 'number' }), textField(t, 'price', 'Prix total (DA)', { type: 'number' }), textField(t, 'compare', 'Prix barré (DA)', { type: 'number', hint: '0 = aucun' })),
            biField(t, 'label', 'Libellé'), biField(t, 'badge', 'Badge', { hint: 'ex. Offre spéciale' }), h('label', { class: 'check' }, def, 'Offre sélectionnée par défaut'));
        }, function () { return { qty: p.tiers.length + 1, price: 0, compare: 0, label: { fr: '', ar: '' }, badge: { fr: '', ar: '' }, 'default': false }; }, 'Ajouter une offre')));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Photos'), gallery(p.images), h('h3', null, 'Vidéos'), videos(p.videos)));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Contenu du pack'), listEditor(p.contents, function (it) { return pairInputs(it); }, function () { return { fr: '', ar: '' }; }, 'Ajouter un élément')));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Caractéristiques techniques'), listEditor(p.specs, function (s) { return h('div', null, biField(s, 'label', 'Caractéristique'), biField(s, 'value', 'Valeur')); },
        function () { return { label: { fr: '', ar: '' }, value: { fr: '', ar: '' } }; }, 'Ajouter une caractéristique')));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Expérience d\'emballage'), listEditor(p.packaging, function (s) { return h('div', null, biField(s, 'title', 'Titre'), biField(s, 'text', 'Texte', { multi: true, rows: 2 })); },
        function () { return { title: { fr: '', ar: '' }, text: { fr: '', ar: '' } }; }, 'Ajouter une étape')));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Référencement (Google)'), h('p', { class: 'small muted' }, 'Vide = nom et résumé du produit.'),
        biField(p.seo, 'title', 'Titre de la page', { max: 65 }), biField(p.seo, 'description', 'Description', { multi: true, rows: 2, max: 160 })));
      var saveBtn = h('button', { class: 'btn btn-p btn-lg', onclick: function () {
        saveBtn.disabled = true;
        api('product_save', { body: { product: p } }).then(function (r) { toast('Produit enregistré'); if (isNew) location.hash = '#/produits/' + r.id; else viewProduct(r.id); })
          .catch(fail).finally(function () { saveBtn.disabled = false; });
      } }, 'Enregistrer');
      body.appendChild(h('div', { class: 'sticky-save' },
        !isNew ? h('button', { class: 'btn btn-d', onclick: function () { if (confirm('Supprimer définitivement ce produit ? (Préférez « Brouillon » pour le masquer.)')) api('product_delete', { body: { id: p.id } }).then(function () { location.hash = '#/produits'; }).catch(fail); } }, 'Supprimer') : null,
        !isNew ? h('a', { class: 'btn', href: '../produit/' + p.slug, target: '_blank', rel: 'noopener' }, 'Aperçu') : null, saveBtn));
    }).catch(fail);
  }
  /** Deux champs FR / AR pour un objet {fr, ar} d'une liste. */
  function pairInputs(it) {
    var fr = h('input', { type: 'text', value: it.fr || '', placeholder: 'Français' }); fr.oninput = function () { it.fr = fr.value; };
    var ar = h('input', { type: 'text', dir: 'rtl', value: it.ar || '', placeholder: 'العربية' }); ar.oninput = function () { it.ar = ar.value; };
    return h('div', { class: 'bi', style: 'margin:0' }, h('div', { class: 'pair' }, h('div', { class: 'lang', 'data-l': 'FR' }, fr), h('div', { class: 'lang', 'data-l': 'AR' }, ar)));
  }

  /* =========================================================== collections */
  function viewCollections() {
    var m = page('Collections', 'Regroupent les produits dans la boutique (ex. Packs de bain, Idées cadeaux).');
    var body = h('div');
    m.appendChild(body);
    api('collections').then(function (j) {
      var items = clone(j.items);
      items.forEach(function (c) { body.appendChild(colCard(c)); });
      body.appendChild(h('button', { class: 'btn btn-p', onclick: function () {
        body.insertBefore(colCard({ id: 0, slug: '', active: 1, pos: items.length + 1, name: { fr: '', ar: '' }, description: { fr: '', ar: '' }, image: null, seo: {} }), body.lastChild);
      } }, '+ Nouvelle collection'));
    }).catch(fail);
    function colCard(c) {
      c.seo = c.seo || {}; c.seo.title = bi(c.seo.title); c.seo.description = bi(c.seo.description);
      return h('details', { class: 'box', open: !c.id ? true : null }, h('summary', null, (c.name.fr || 'Nouvelle collection') + (c.id ? ' · ' + c.count + ' produit(s)' : '') + (c.active ? '' : ' · masquée')),
        h('div', null, biField(c, 'name', 'Nom'), biField(c, 'description', 'Description', { multi: true, rows: 2 }),
          h('div', { class: 'grid g3' }, textField(c, 'slug', 'Adresse (slug)', { hint: '/boutique/…' }), textField(c, 'pos', 'Ordre', { type: 'number' }), h('div', null, checkField(c, 'active', 'Visible'))),
          imageField(c, 'image', 'Photo'), biField(c.seo, 'title', 'Titre Google', { max: 65 }), biField(c.seo, 'description', 'Description Google', { multi: true, rows: 2, max: 160 }),
          h('div', { class: 'row' }, h('button', { class: 'btn btn-p', onclick: function () { api('collection_save', { body: { collection: c } }).then(function () { toast('Collection enregistrée'); viewCollections(); }).catch(fail); } }, 'Enregistrer'),
            c.id ? h('button', { class: 'btn btn-d', onclick: function () { if (confirm('Supprimer cette collection ? Les produits ne sont pas supprimés.')) api('collection_delete', { body: { id: c.id } }).then(viewCollections).catch(fail); } }, 'Supprimer') : null)));
    }
  }

  /* ============================================================= contenus */
  var SECTIONS = [
    ['global', 'Général : bandeau, coordonnées, réseaux sociaux', function (c, prods) {
      return [biField(c, 'announcement', 'Bandeau d\'annonce (haut de page)'), biField(c, 'tagline', 'Signature'), biField(c, 'footer_about', 'Présentation (pied de page)', { multi: true, rows: 2 }),
        h('div', { class: 'grid g3' }, textField(c, 'whatsapp', 'WhatsApp', { ph: '0661 23 45 67' }), textField(c, 'phone', 'Téléphone', { ph: '0661 23 45 67' }), textField(c, 'email', 'Email', { type: 'email' })),
        h('div', { class: 'grid g3' }, textField(c, 'facebook', 'Facebook', { ph: 'https://facebook.com/…' }), textField(c, 'instagram', 'Instagram', { ph: 'https://instagram.com/…' }), textField(c, 'tiktok', 'TikTok', { ph: 'https://tiktok.com/@…' })),
        biField(c, 'address', 'Adresse / zone'), biField(c, 'hours', 'Horaires')];
    }],
    ['home', 'Accueil', function (c, prods) {
      var sel = h('select', { onchange: function () { c.featured = sel.value; } }, prods.map(function (p) { return h('option', { value: p.slug }, p.name); }));
      sel.value = c.featured || '';
      c.benefits = c.benefits || []; c.testimonials = c.testimonials || [];
      return [biField(c, 'hero_eyebrow', 'Sur-titre'), biField(c, 'hero_title', 'Grand titre'), biField(c, 'hero_text', 'Texte d\'introduction', { multi: true, rows: 2 }), biField(c, 'hero_cta', 'Bouton secondaire'),
        imageField(c, 'hero_image', 'Visuel principal'), h('label', { class: 'f' }, 'Produit mis en avant', sel),
        biField(c, 'offer_title', 'Offre spéciale : titre'), biField(c, 'offer_text', 'Offre spéciale : texte', { multi: true, rows: 2 }),
        biField(c, 'packaging_title', 'Emballage : titre'), biField(c, 'packaging_text', 'Emballage : texte'),
        h('h3', null, 'Avantages (icônes : cotton, layers, gift, cash, truck, shield, refresh, leaf, sparkle)'),
        listEditor(c.benefits, function (b) { return h('div', null, textField(b, 'icon', 'Icône'), biField(b, 'title', 'Titre'), biField(b, 'text', 'Texte')); }, function () { return { icon: 'sparkle', title: { fr: '', ar: '' }, text: { fr: '', ar: '' } }; }, 'Ajouter un avantage'),
        h('h3', null, 'Avis clients (uniquement de vrais avis)'),
        listEditor(c.testimonials, function (t) { return h('div', null, h('div', { class: 'grid g3' }, textField(t, 'name', 'Prénom'), textField(t, 'city', 'Ville'), textField(t, 'rating', 'Note /5', { type: 'number' })), biField(t, 'text', 'Avis', { multi: true, rows: 2 })); },
          function () { return { name: '', city: '', rating: 5, text: { fr: '', ar: '' } }; }, 'Ajouter un avis'),
        biField(c, 'final_title', 'Bas de page : titre'), biField(c, 'final_text', 'Bas de page : texte', { multi: true, rows: 2 })];
    }],
    ['story', 'Notre histoire', function (c) {
      c.values = c.values || [];
      return [biField(c, 'title', 'Titre'), biField(c, 'intro', 'Introduction', { multi: true, rows: 2 }), imageField(c, 'image', 'Photo'), biField(c, 'body', 'Texte', { multi: true, rows: 12, hint: '## titre, **gras**' }),
        h('h3', null, 'Valeurs'), listEditor(c.values, function (v) { return h('div', null, biField(v, 'title', 'Valeur'), biField(v, 'text', 'Texte')); }, function () { return { title: { fr: '', ar: '' }, text: { fr: '', ar: '' } }; }, 'Ajouter une valeur')];
    }],
    ['delivery', 'Livraison et commande', function (c) {
      c.sections = c.sections || [];
      return [biField(c, 'title', 'Titre'), biField(c, 'intro', 'Introduction', { multi: true, rows: 2 }),
        h('h3', null, 'Rubriques (aussi affichées en questions sur les fiches produit)'),
        listEditor(c.sections, function (s) { return h('div', null, biField(s, 'title', 'Titre'), biField(s, 'text', 'Texte', { multi: true, rows: 4 })); }, function () { return { title: { fr: '', ar: '' }, text: { fr: '', ar: '' } }; }, 'Ajouter une rubrique')];
    }],
    ['contact', 'Contact', function (c) { return [biField(c, 'title', 'Titre'), biField(c, 'intro', 'Introduction', { multi: true, rows: 2 }), h('p', { class: 'small muted' }, 'Les coordonnées se règlent dans « Général ».')]; }],
    ['privacy', 'Confidentialité', function (c) { return [biField(c, 'title', 'Titre'), biField(c, 'body', 'Texte', { multi: true, rows: 14 })]; }],
    ['seo', 'Référencement des pages (titres et descriptions Google)', function (c) {
      var labels = { home: 'Accueil', shop: 'Boutique', story: 'Notre histoire', delivery: 'Livraison', contact: 'Contact', guides: 'Guides', track: 'Suivi de commande' };
      return Object.keys(labels).map(function (k) {
        c[k] = c[k] || {};
        return h('div', { class: 'list-item' }, h('b', null, labels[k]), biField(c[k], 'title', 'Titre', { max: 65 }), biField(c[k], 'description', 'Description', { multi: true, rows: 2, max: 160 }));
      });
    }]
  ];
  function viewContent() {
    var m = page('Contenus', 'Textes et visuels des pages, en français et en arabe.');
    var body = h('div');
    m.appendChild(body);
    api('content').then(function (j) {
      SECTIONS.forEach(function (s, i) {
        var data = clone(j.content[s[0]] || {});
        var inner = h('div', null, s[2](data, j.products), h('div', { class: 'row', style: 'margin-top:8px' }, h('button', { class: 'btn btn-p', onclick: function () {
          api('content_save', { body: { section: s[0], data: data } }).then(function () { toast('« ' + s[1] + ' » enregistré'); }).catch(fail);
        } }, 'Enregistrer cette section')));
        body.appendChild(h('details', { class: 'box', open: i === 0 ? true : null }, h('summary', null, s[1]), inner));
      });
    }).catch(fail);
  }

  /* ================================================================ guides */
  function viewArticles() {
    var m = page('Guides', 'Articles de conseils (référencement Google).', h('a', { class: 'btn btn-p', href: '#/guides/nouveau' }, '+ Nouveau guide'));
    var body = h('div', { class: 'card' });
    m.appendChild(body);
    api('articles').then(function (j) {
      body.appendChild(h('table', { class: 'tbl' }, h('tbody', null, j.items.map(function (a) {
        return h('tr', null, h('td', null, h('a', { href: '#/guides/' + a.id }, h('b', null, a.title || '(sans titre)')), h('div', { class: 'small muted' }, day(a.published) + ' · ' + (a.langs.join(' + ').toUpperCase() || 'aucune langue complète'))),
          h('td', null, h('span', { class: 'badge ' + (a.status === 'published' ? 's-delivered' : 'b-skipped') }, a.status === 'published' ? 'Publié' : 'Brouillon')),
          h('td', null, h('a', { class: 'btn btn-sm', href: a.url, target: '_blank', rel: 'noopener' }, 'Voir')));
      }))));
    }).catch(fail);
  }
  function viewArticle(id) {
    var m = page(id === 'nouveau' ? 'Nouveau guide' : 'Guide', 'Un guide apparaît dans une langue dès que son titre et son texte y sont écrits.', h('a', { class: 'btn', href: '#/guides' }, '‹ Guides'));
    var body = h('div');
    m.appendChild(body);
    api('article', { query: { id: id === 'nouveau' ? 0 : id } }).then(function (j) {
      var a = clone(j.article);
      a.seo = a.seo || {}; a.seo.title = bi(a.seo.title); a.seo.description = bi(a.seo.description);
      var st = h('select', { onchange: function () { a.status = st.value; } }, h('option', { value: 'published' }, 'Publié'), h('option', { value: 'draft' }, 'Brouillon'));
      st.value = a.status;
      var d = new Date((a.published || Date.now() / 1000) * 1000);
      var date = h('input', { type: 'date', value: d.toISOString().slice(0, 10), onchange: function () { a.published = Math.floor(new Date(date.value + 'T09:00:00').getTime() / 1000); } });
      body.appendChild(h('div', { class: 'card' }, h('div', { class: 'grid g3' }, h('label', { class: 'f' }, 'État', st), h('label', { class: 'f' }, 'Date de publication', date), textField(a, 'slug', 'Adresse (slug)', { hint: '/guides/…' })),
        biField(a, 'title', 'Titre', { max: 110 }), biField(a, 'excerpt', 'Résumé', { multi: true, rows: 2 }), imageField(a, 'cover', 'Photo de couverture'),
        biField(a, 'body', 'Texte', { multi: true, rows: 16, hint: '## titre, ### sous-titre, **gras**, - liste, [lien](https://…)' }),
        biField(a.seo, 'title', 'Titre Google', { max: 65 }), biField(a.seo, 'description', 'Description Google', { multi: true, rows: 2, max: 160 })));
      body.appendChild(h('div', { class: 'sticky-save' },
        a.id ? h('button', { class: 'btn btn-d', onclick: function () { if (confirm('Supprimer ce guide ?')) api('article_delete', { body: { id: a.id } }).then(function () { location.hash = '#/guides'; }).catch(fail); } }, 'Supprimer') : null,
        h('button', { class: 'btn btn-p btn-lg', onclick: function () { api('article_save', { body: { article: a } }).then(function (r) { toast('Guide enregistré'); location.hash = '#/guides/' + r.id; if (String(r.id) === String(id)) viewArticle(r.id); }).catch(fail); } }, 'Enregistrer')));
    }).catch(fail);
  }

  /* ============================================================= livraison */
  function viewShipping() {
    var m = page('Livraison', 'Wilayas desservies, frais de livraison et communes. Les frais s\'ajoutent automatiquement au total de la commande.');
    var body = h('div');
    m.appendChild(body);
    api('wilayas').then(function (j) {
      var home = h('input', { type: 'number', min: 0, placeholder: 'Domicile (DA)' }), desk = h('input', { type: 'number', min: 0, placeholder: 'Bureau (DA)' }), delay = h('input', { type: 'text', placeholder: 'Délai (ex. 24–48 h)' });
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Appliquer à toutes les wilayas'),
        h('div', { class: 'grid g4' }, home, desk, delay, h('button', { class: 'btn btn-p', onclick: function () {
          if (!confirm('Appliquer ces valeurs à toutes les wilayas ?')) return;
          api('wilayas_bulk', { body: { fee_home: home.value, fee_desk: desk.value, delay: delay.value } }).then(function () { toast('Wilayas mises à jour'); geoCache = null; viewShipping(); }).catch(fail);
        } }, 'Appliquer')),
        h('p', { class: 'small muted', style: 'margin-top:8px' }, 'Laissez un champ vide pour ne pas le modifier. 0 = livraison gratuite. La livraison au bureau du transporteur s\'active dans Réglages (' + (j.desk_enabled ? 'activée' : 'désactivée') + ').')));
      var tb = h('tbody');
      j.items.forEach(function (w) {
        var x = { code: w.code, fr: w.fr, ar: w.ar, fee_home: +w.fee_home, fee_desk: +w.fee_desk, delay: w.delay, active: +w.active === 1 };
        var fh = h('input', { type: 'number', min: 0, value: x.fee_home }), fd = h('input', { type: 'number', min: 0, value: x.fee_desk }), dl = h('input', { type: 'text', value: x.delay, style: 'min-width:110px' });
        var ac = h('input', { type: 'checkbox', checked: x.active });
        var btn = h('button', { class: 'btn btn-sm', onclick: function () {
          x.fee_home = fh.value; x.fee_desk = fd.value; x.delay = dl.value; x.active = ac.checked;
          api('wilaya_save', { body: x }).then(function () { toast(x.code + ' ' + x.fr + ' enregistrée'); geoCache = null; }).catch(fail);
        } }, 'OK');
        tb.appendChild(h('tr', null, h('td', null, h('b', null, w.code), ' ', w.fr, h('div', { class: 'small muted', dir: 'rtl' }, w.ar)), h('td', null, fh), h('td', null, fd), h('td', null, dl), h('td', null, ac),
          h('td', null, h('a', { href: '#', onclick: function (e) { e.preventDefault(); editCommunes(w); } }, w.nc + ' communes')), h('td', null, btn)));
      });
      body.appendChild(h('div', { class: 'card' }, h('div', { class: 'scroll' }, h('table', { class: 'tbl' },
        h('thead', null, h('tr', null, h('th', null, 'Wilaya'), h('th', null, 'Domicile (DA)'), h('th', null, 'Bureau (DA)'), h('th', null, 'Délai'), h('th', null, 'Active'), h('th', null, 'Communes'), h('th', null, ''))), tb))));
      var nw = { code: '', fr: '', ar: '', fee_home: 0, fee_desk: 0, delay: '', active: true };
      body.appendChild(h('details', { class: 'box' }, h('summary', null, 'Ajouter une wilaya (ex. nouvelles wilayas 59 à 69)'), h('div', null,
        h('div', { class: 'grid g3' }, textField(nw, 'code', 'Code', { ph: '59' }), textField(nw, 'fr', 'Nom (FR)'), textField(nw, 'ar', 'Nom (AR)')),
        h('button', { class: 'btn btn-p', onclick: function () { api('wilaya_save', { body: nw }).then(function () { toast('Wilaya ajoutée — ajoutez ses communes'); geoCache = null; viewShipping(); }).catch(fail); } }, 'Ajouter'))));
    }).catch(fail);
    function editCommunes(w) {
      api('communes', { query: { wilaya: w.code } }).then(function (j) {
        var ta = h('textarea', { rows: 18, class: 'mono' });
        ta.value = j.items.map(function (c) { return c.fr + (c.ar ? ' | ' + c.ar : ''); }).join('\n');
        var box = h('div', { class: 'card' }, h('h2', null, 'Communes — ' + w.code + ' ' + w.fr), h('p', { class: 'small muted' }, 'Une commune par ligne : « Nom français | الاسم بالعربية ».'), ta,
          h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { class: 'btn btn-p', onclick: function () {
            api('communes_save', { body: { wilaya: w.code, text: ta.value } }).then(function (r) { toast(r.n + ' communes enregistrées'); geoCache = null; viewShipping(); }).catch(fail);
          } }, 'Enregistrer'), h('button', { class: 'btn', onclick: function () { box.remove(); } }, 'Fermer')));
        mainEl.insertBefore(box, mainEl.children[1]);
        window.scrollTo(0, 0);
      }).catch(fail);
    }
  }

  /* ========================================================== Meta & suivi */
  function viewMeta() {
    var m = page('Meta & suivi', 'Pixel Meta et Conversions API (côté serveur), avec déduplication par event_id.');
    var body = h('div');
    m.appendChild(body);
    api('meta').then(function (j) {
      var s = j.settings;
      s.token = ''; s.token_clear = false;
      if (j.last_error) body.appendChild(h('div', { class: 'alert alert-d small' }, 'Dernière erreur : ' + j.last_error));
      body.appendChild(h('div', { class: 'alert ' + (j.ready ? 'alert-ok' : 'alert-w') }, j.ready ? 'Pixel et Conversions API actifs.' : 'Renseignez l\'ID du pixel et le jeton d\'accès Conversions API pour activer le suivi serveur.'));
      var trig = h('select', { onchange: function () { s.purchase_trigger = trig.value; } },
        h('option', { value: 'confirmed' }, 'À la confirmation de la commande (recommandé)'), h('option', { value: 'delivered' }, 'À la livraison'));
      trig.value = s.purchase_trigger;
      var tokIn = h('input', { type: 'password', autocomplete: 'off', placeholder: s.token_set ? 'Jeton enregistré (' + s.token_hint + ') — laisser vide pour le garder' : 'EAAB…' });
      tokIn.oninput = function () { s.token = tokIn.value; };
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Configuration'),
        h('div', { class: 'grid g2' }, s.pixel_locked ? h('p', null, 'ID du pixel : ' + s.pixel_id + ' (défini dans config.php)') : textField(s, 'pixel_id', 'ID du pixel (jeu de données)', { hint: 'Gestionnaire d\'événements → Paramètres' }),
          s.token_locked ? h('p', null, 'Jeton défini dans config.php') : h('label', { class: 'f' }, 'Jeton d\'accès Conversions API', h('span', { class: 'hint' }, 'Gestionnaire d\'événements → Paramètres → Générer un jeton d\'accès. Stocké chiffré.'), tokIn)),
        h('div', { class: 'grid g3' }, textField(s, 'test_code', 'Code de test (facultatif)', { hint: 'TEST12345 — à vider après les essais' }), textField(s, 'graph_version', 'Version de l\'API Graph'), textField(s, 'delivered_event', 'Nom de l\'événement « livrée »')),
        h('label', { class: 'f' }, 'Quand envoyer « Purchase » ?', h('span', { class: 'hint' }, 'Confirmation = le client a confirmé au téléphone : signal rapide pour l\'optimisation des publicités. Livraison = vente encaissée, mais signal plus tardif.'), trig),
        checkField(s, 'capi_enabled', 'Activer l\'envoi serveur (Conversions API)'),
        checkField(s, 'capi_browser', 'Doubler aussi PageView, ViewContent, AddToCart, InitiateCheckout et Contact côté serveur'),
        textField(s, 'fb_domain_verification', 'Vérification du domaine Meta (facebook-domain-verification)', { hint: 'Contenu de la balise meta fournie par Meta Business' }),
        h('div', { class: 'row' }, h('button', { class: 'btn btn-p', onclick: function () { api('meta_save', { body: s }).then(function () { toast('Réglages Meta enregistrés'); viewMeta(); }).catch(fail); } }, 'Enregistrer'),
          s.token_set ? h('button', { class: 'btn btn-d', onclick: function () { if (confirm('Supprimer le jeton enregistré ?')) { s.token_clear = true; api('meta_save', { body: s }).then(viewMeta).catch(fail); } } }, 'Supprimer le jeton') : null,
          h('button', { class: 'btn', onclick: function () {
            api('meta_test', { body: { test_code: s.test_code } }).then(function (r) { toast('Événement test reçu par Meta (' + (r.response && r.response.events_received) + '). Vérifiez l\'onglet « Tester les événements ».'); }).catch(fail);
          } }, 'Envoyer un événement test'))));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Règles de suivi'),
        h('table', { class: 'tbl' }, h('tbody', null,
          tr2('PageView', 'Chaque page — navigateur + serveur, même event_id'), tr2('ViewContent', 'Fiche produit — navigateur + serveur'),
          tr2('AddToCart', 'Choix d\'une offre ou clic sur « Commander » — navigateur + serveur'), tr2('InitiateCheckout', 'Première saisie dans le formulaire — navigateur + serveur'),
          tr2('Lead', 'Demande de commande enregistrée (une fois, event_id lead.<n°>) — navigateur + serveur'),
          tr2('Purchase', 'Commande confirmée par l\'équipe (ou livrée selon le réglage) — serveur uniquement, une seule fois (purchase.<n°>)'),
          tr2(s.delivered_event, 'Commande livrée — serveur uniquement (delivered.<n°>)'),
          tr2('Contact', 'Clic WhatsApp / téléphone — navigateur + serveur'))),
        h('p', { class: 'small muted', style: 'margin-top:8px' }, 'La page de remerciement n\'envoie aucun événement de conversion : l\'ouvrir ou la rafraîchir ne peut ni dupliquer un Lead ni créer un Purchase.')));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Paramètres d\'URL à coller dans Ads Manager'),
        h('p', { class: 'small muted' }, 'Publicité → Destination → Paramètres d\'URL. Chaque commande enregistre alors la campagne, l\'ensemble, la publicité et le placement.'),
        h('div', { class: 'code' }, j.url_params), h('button', { class: 'btn btn-sm', style: 'margin-top:8px', onclick: function () { navigator.clipboard.writeText(j.url_params).then(function () { toast('Copié'); }); } }, 'Copier')));
      var cnt = {};
      j.counts.forEach(function (r) { cnt[r.status] = (cnt[r.status] || 0) + +r.n; });
      var retryAll = j.events.filter(function (e) { return e.status === 'failed'; }).map(function (e) { return +e.id; });
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Journal des événements serveur'),
        h('p', { class: 'small' }, 'Envoyés : ' + (cnt.sent || 0) + ' · en attente : ' + (cnt.pending || 0) + ' · échecs : ' + (cnt.failed || 0) + ' · non envoyés (non configuré) : ' + (cnt.skipped || 0)),
        retryAll.length ? h('button', { class: 'btn btn-sm', onclick: function () { api('capi_retry', { body: { ids: retryAll } }).then(function () { toast('Nouvel essai lancé'); setTimeout(viewMeta, 1500); }).catch(fail); } }, 'Réessayer les échecs affichés') : null,
        h('div', { class: 'scroll' }, h('table', { class: 'tbl' }, h('thead', null, h('tr', null, h('th', null, 'Date'), h('th', null, 'Événement'), h('th', null, 'Commande'), h('th', null, 'État'))),
          h('tbody', null, j.events.map(function (e) {
            return h('tr', null, h('td', { class: 'small' }, dt(+e.created)), h('td', null, h('b', null, e.name), h('div', { class: 'small muted mono' }, e.event_id)),
              h('td', null, e.oid ? h('a', { href: '#/commandes/' + e.oid }, e.ref || '#' + e.oid) : ''),
              h('td', null, h('span', { class: 'badge b-' + e.status }, e.status + (+e.test ? ' (test)' : '')), e.error ? h('div', { class: 'small', style: 'color:var(--danger)' }, e.error) : null));
          }))))));
    }).catch(fail);
    function tr2(a, b) { return h('tr', null, h('td', null, h('b', null, a)), h('td', null, b)); }
  }

  /* ============================================================== réglages */
  function viewSettings() {
    var m = page('Réglages', 'Notifications, commandes, référencement, équipe et sauvegardes.');
    var body = h('div');
    m.appendChild(body);
    api('settings').then(function (j) {
      var s = j.settings;
      ['allow_backorder', 'desk_enabled'].forEach(function (k) { s[k] = s[k] === '1'; });
      s.telegram_token = ''; s.telegram_clear = false;
      if (j.last_error) body.appendChild(h('div', { class: 'alert alert-d small' }, 'Dernière erreur de notification : ' + j.last_error));
      var tg = h('input', { type: 'password', autocomplete: 'off', placeholder: j.settings.telegram_set ? 'Jeton enregistré — laisser vide pour le garder' : '123456789:AA…' });
      tg.oninput = function () { s.telegram_token = tg.value; };
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Notifications de nouvelles commandes'),
        textField(s, 'notify_emails', 'Emails à prévenir (jusqu\'à 5, séparés par des virgules)', { hint: 'Envoi : ' + j.smtp }),
        h('div', { class: 'grid g2' }, h('label', { class: 'f' }, 'Bot Telegram : jeton', h('span', { class: 'hint' }, 'Créé avec @BotFather. Stocké chiffré.'), tg),
          textField(s, 'notify_telegram_chat', 'Telegram : identifiant du chat', { hint: 'Ex. 123456789 ou -100… pour un groupe' })),
        h('p', { class: 'small muted' }, 'L\'administration ouverte émet aussi un signal sonore et une notification du navigateur à chaque commande.'),
        h('div', { class: 'row' }, h('button', { class: 'btn', onclick: function () {
          api('settings_save', { body: s }).then(function () { return api('notify_test', { body: {} }); }).then(function (r) { toast('Telegram : ' + r.result.telegram + ' · Email : ' + r.result.email); }).catch(fail);
        } }, 'Enregistrer et tester'))));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Commandes'),
        h('div', { class: 'grid g3' }, textField(s, 'duplicate_hours', 'Signaler un doublon si même téléphone dans les… (heures)', { type: 'number' }),
          textField(s, 'order_rate_ip', 'Commandes max par heure et par connexion', { type: 'number' }), textField(s, 'return_days', 'Délai d\'échange affiché à Google (jours)', { type: 'number' })),
        checkField(s, 'desk_enabled', 'Proposer la livraison au bureau du transporteur (stop desk)'),
        checkField(s, 'allow_backorder', 'Accepter les commandes quand le stock est épuisé'),
        h('h3', null, 'Message WhatsApp de confirmation'), h('p', { class: 'small muted' }, 'Variables : {name} {ref} {items} {total} {wilaya} {commune} {address} {phone}'),
        textField(s, 'wa_template_fr', 'Français', { multi: true, rows: 3 }),
        (function () { var t = textField(s, 'wa_template_ar', 'Arabe', { multi: true, rows: 3 }); t.querySelector('textarea').setAttribute('dir', 'rtl'); return t; })()));
      body.appendChild(h('div', { class: 'card' }, h('h2', null, 'Google Search Console'),
        textField(s, 'gsc_verification', 'Code de vérification (balise meta google-site-verification)', { hint: 'Collez la balise ou seulement son contenu. Propriété « préfixe d\'URL » : ' + location.origin + location.pathname.replace(/admin\/.*$/, '') }),
        h('p', { class: 'small muted' }, 'Sitemap à déclarer : ', h('span', { class: 'mono' }, location.origin + location.pathname.replace(/admin\/.*$/, '') + 'sitemap.xml'))));
      body.appendChild(h('div', { class: 'sticky-save' }, h('button', { class: 'btn btn-p btn-lg', onclick: function () { api('settings_save', { body: s }).then(function () { toast('Réglages enregistrés'); }).catch(fail); } }, 'Enregistrer les réglages')));
      if (can('team')) body.appendChild(teamCard());
      if (can('backup')) body.appendChild(backupCard(j));
      body.appendChild(h('p', { class: 'small muted' }, 'Version ' + j.version + ' · données : ' + j.data_dir + ' · dernier passage du cron : ' + (j.cron_last ? dt(j.cron_last) : 'jamais (voir README)')));
    }).catch(fail);
  }
  function teamCard() {
    var card = h('div', { class: 'card' }, h('h2', null, 'Équipe'), h('p', { class: 'muted' }, 'Chargement…'));
    api('team').then(function (j) {
      clear(card);
      card.appendChild(h('h2', null, 'Équipe'));
      card.appendChild(h('p', { class: 'small muted' }, 'Gestion : commandes, produits, contenus, livraison et statistiques. Confirmation : commandes uniquement. Le propriétaire se connecte avec le mot de passe de /admin.'));
      j.items.forEach(function (u) { card.appendChild(member(u)); });
      card.appendChild(member({ id: 0, email: '', name: '', role: 'agent', active: 1 }));
    }).catch(fail);
    function member(u) {
      var x = { id: +u.id, email: u.email, name: u.name, role: u.role, active: +u.active === 1, password: '' };
      var role = h('select', { onchange: function () { x.role = role.value; } }, h('option', { value: 'agent' }, 'Confirmation'), h('option', { value: 'manager' }, 'Gestion'));
      role.value = x.role;
      return h('div', { class: 'list-item' }, h('b', null, x.id ? x.name + (u.last_login ? ' · dernière connexion ' + ago(+u.last_login) : '') : 'Nouveau membre'),
        h('div', { class: 'grid g4', style: 'margin-top:8px' }, textField(x, 'name', 'Nom'), textField(x, 'email', 'Email', { type: 'email' }), h('label', { class: 'f' }, 'Rôle', role),
          textField(x, 'password', x.id ? 'Nouveau mot de passe' : 'Mot de passe', { type: 'password', hint: '10 caractères min.' })),
        x.id ? checkField(x, 'active', 'Accès actif') : null,
        h('div', { class: 'row' }, h('button', { class: 'btn btn-sm btn-p', onclick: function () { api('team_save', { body: x }).then(function () { toast('Équipe mise à jour'); viewSettings(); }).catch(fail); } }, x.id ? 'Enregistrer' : 'Créer l\'accès'),
          x.id ? h('button', { class: 'btn btn-sm btn-d', onclick: function () { if (confirm('Supprimer l\'accès de ' + x.name + ' ?')) api('team_delete', { body: { id: x.id } }).then(viewSettings).catch(fail); } }, 'Supprimer') : null));
    }
    return card;
  }
  function backupCard(j) {
    return h('div', { class: 'card' }, h('h2', null, 'Sauvegardes'),
      h('p', { class: 'small muted' }, 'Une sauvegarde automatique de la base est faite chaque jour par le cron (30 jours conservés, hors de public_html). Dernière : ' + (j.last_backup ? dt(j.last_backup) : 'aucune') + '.'),
      h('div', { class: 'row' }, h('a', { class: 'btn', href: API + '?action=backup_db' }, icon('dl'), 'Télécharger la base'), h('a', { class: 'btn', href: API + '?action=backup_zip' }, icon('dl'), 'Base + photos (zip)'),
        h('button', { class: 'btn', onclick: function () { api('backup_now', { body: {} }).then(function () { toast('Sauvegarde créée'); viewSettings(); }).catch(fail); } }, 'Sauvegarder maintenant')),
      j.backups.length ? h('ul', { class: 'small', style: 'margin-top:10px' }, j.backups.slice(0, 10).map(function (b) { return h('li', null, h('a', { href: API + '?action=backup_get&name=' + encodeURIComponent(b.name) }, b.name), ' · ' + Math.round(b.size / 1024) + ' Ko'); })) : null);
  }
  function viewAccount() {
    var m = page('Mon compte', state.user.name + ' · ' + state.user.email);
    var x = { current: '', new: '' };
    m.appendChild(h('div', { class: 'card', style: 'max-width:480px' }, h('h2', null, 'Changer mon mot de passe'), textField(x, 'current', 'Mot de passe actuel', { type: 'password' }), textField(x, 'new', 'Nouveau mot de passe (10 caractères min.)', { type: 'password' }),
      h('button', { class: 'btn btn-p', onclick: function () { api('me_password', { body: x }).then(function () { toast('Mot de passe changé'); }).catch(fail); } }, 'Enregistrer')));
  }

  /* ============================================================ démarrage */
  api('session').then(function (j) { if (!j.user) { renderLogin(); return; } state.user = j.user; state.csrf = j.csrf; start(); }).catch(function () { renderLogin(); });
})();
