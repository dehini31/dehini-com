/*
 * Studioma — administration de la plateforme (studioma/admin/) : statistiques en direct, comptes,
 * modération, emails (campagnes, journal, réglages), système. S'appuie sur core.js (API, gabarits
 * échappés, routeur, fenêtres). Connexion : la session de l'administration du site (/admin) ; son jeton
 * CSRF accompagne chaque écriture. Le serveur vérifie tout (lib/admin.php).
 */
(function () {
  'use strict';
  const { html, raw, icon, fmt, $, $$ } = SM;
  const ADMIN = new URL('../admin/', SM.base).href;
  const APP = new URL('app/', SM.base).href;
  const POLL = 10000;
  const nf1 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
  const dayLabel = (d) => new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(d);
  const bytes = (b) => (b >= 1073741824 ? nf1.format(b / 1073741824) + ' GB' : b >= 1048576 ? nf1.format(b / 1048576) + ' MB' : b > 0 ? Math.max(1, Math.round(b / 1024)) + ' KB' : '0 KB');
  const pct = (a, b) => (b ? Math.round((a / b) * 100) + '%' : '—');
  const when = (ts) => (ts ? fmt.time(ts) : '—');

  /* ------------------------------------------------- session /admin */
  const adminCall = async (action, body) => {
    let data = {};
    let res = null;
    try {
      res = await fetch(ADMIN + 'api.php?action=' + action, body
        ? { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
        : { credentials: 'same-origin' });
      data = await res.json();
    } catch (e) { /* réseau ou réponse illisible */ }
    return { ok: !!(res && res.ok && data.ok), data };
  };
  let timers = [];
  const stopTimers = () => { timers.forEach(clearInterval); timers = []; };
  /** Rappelle fn toutes les ms millisecondes tant que l'écran reste affiché (et que l'onglet est visible). */
  const every = (fn, ms) => {
    const t = setInterval(() => { if (!document.hidden) fn(); }, ms);
    timers.push(t);
    SM.onLeave(() => { clearInterval(t); timers = timers.filter((x) => x !== t); });
  };
  document.addEventListener('visibilitychange', () => { if (!document.hidden && SM.current && SM.current.r.opts.refresh) SM.current.r.opts.refresh(); });

  async function boot() {
    const r = await adminCall('session');
    if (r.ok) start(r.data.csrf); else login();
  }
  function start(csrf) {
    SM.csrf = csrf;
    SM.me = { admin: true };
    shell();
    SM.navigate();
  }
  SM.onAuthLost = () => {
    SM.me = null;
    stopTimers();
    SM.closeModals();
    login('Your session has expired. Sign in again.');
  };
  function login(msg) {
    SM.set($('#app'), html`<div class="auth"><form class="panel auth-card" data-form="adm-login">
      ${SM.logo()}
      <h1>Studioma administration</h1>
      <p class="muted">Sign in with the dehini.com admin password. The same session also opens the site's back-office (/admin) for 12 hours.</p>
      ${msg ? html`<p class="notice notice-warn">${icon('info')}<span>${msg}</span></p>` : ''}
      <label class="field"><span>Password</span><input class="input" type="password" name="password" autocomplete="current-password" required></label>
      <p class="form-error" data-error hidden></p>
      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form></div>`);
    const f = $('input[name=password]');
    if (f) f.focus();
  }
  SM.forms['adm-login'] = async (form, data) => {
    const r = await adminCall('login', { password: data.password || '' });
    // Messages de /admin (en français) : le plus courant est traduit, comme le reste de cette interface.
    if (!r.ok) throw new SM.UserError(r.data.error === 'Mot de passe incorrect.' ? 'Wrong password.' : r.data.error || 'Sign-in failed. Try again.');
    start(r.data.csrf);
  };
  SM.actions['adm-logout'] = async () => {
    await adminCall('logout', {});
    SM.me = null;
    stopTimers();
    login();
  };

  /* ---------------------------------------------------------------- cadre */
  const NAV = [['#/', 'chart', 'Live overview'], ['#/accounts', 'users', 'Accounts'], ['#/studios', 'board', 'Studios'], ['#/content', 'shield', 'Moderation'], ['#/billing', 'zap', 'Billing'], ['#/promo', 'award', 'Launch promotion'], ['#/journey', 'trophy', 'Creator Journey'], ['#/emails', 'mail', 'Emails'], ['#/system', 'settings', 'System']];
  function shell() {
    SM.set($('#app'), html`<div class="app adm">
      <aside class="side">
        <a class="logo-link" href="#/">${SM.logo()}<span class="adm-badge">Admin</span></a>
        <nav class="nav" aria-label="Administration">
          ${NAV.map(([h, ic, l]) => html`<a class="nav-link" href="${h}" data-nav="${h}">${icon(ic)}<span>${l}</span></a>`)}
          <p class="nav-sep">Shortcuts</p>
          <a class="nav-link" href="${APP}" target="_blank" rel="noopener">${icon('external')}<span>Open Studioma</span></a>
          <a class="nav-link" href="${ADMIN}" target="_blank" rel="noopener">${icon('home')}<span>dehini.com admin</span></a>
        </nav>
        <div class="adm-foot">
          <button class="nav-link" data-act="adm-theme">${icon('moon')}<span>Light / dark</span></button>
          <button class="nav-link" data-act="adm-logout">${icon('logout')}<span>Sign out</span></button>
        </div>
      </aside>
      <div class="side-veil" data-act="adm-side"></div>
      <div class="main">
        <header class="top">
          <button class="btn-icon top-menu" data-act="adm-side" aria-label="Menu">${icon('menu')}</button>
          <span class="adm-title grow" id="adm-title"></span>
          <span class="live is-off" id="adm-live" aria-live="polite"><i></i><span>Paused</span></span>
        </header>
        <main class="view" id="view"></main>
      </div>
    </div>`);
  }
  SM.afterRoute = (path) => {
    $$('[data-nav]').forEach((a) => {
      const h = a.dataset.nav.slice(1);
      a.classList.toggle('is-active', h === '/' ? path === '/' : path === h || path.startsWith(h + '/'));
    });
    document.body.classList.remove('side-open');
    setLive(false);
  };
  const setTitle = (t) => { const el = $('#adm-title'); if (el) el.textContent = t; document.title = t + ' — Studioma Admin'; };
  function setLive(on, text) {
    const el = $('#adm-live');
    if (!el) return;
    el.classList.toggle('is-off', !on);
    el.lastElementChild.textContent = text || (on ? 'Live' : 'Paused');
  }
  SM.actions['adm-side'] = () => document.body.classList.toggle('side-open');
  SM.actions['adm-theme'] = () => SM.applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');

  const tile = (ic, label, value, sub, cls) => html`<div class="panel tile ${cls || ''}"><span>${icon(ic)}${label}</span><b>${value}</b>${sub ? html`<small>${sub}</small>` : ''}</div>`;
  const who = (u, sub) => (u
    ? html`<button class="who-btn" data-act="adm-user" data-id="${u.id}">${SM.avatar(u, 'av-s')}<span class="who-txt"><b>${u.name}</b>${sub ? html`<small>${sub}</small>` : ''}</span></button>`
    : html`<span class="who-btn">${SM.avatar(null, 'av-s')}<span class="who-txt"><b>Deleted account</b></span></span>`);
  const pager = (page, more, base) => (page > 0 || more ? html`<nav class="row end">
    ${page > 0 ? html`<a class="btn btn-sm" href="${base(page - 1)}">${icon('chevron-left')}Previous</a>` : ''}
    <span class="muted small">Page ${page + 1}</span>
    ${more ? html`<a class="btn btn-sm" href="${base(page + 1)}">Next${icon('chevron-right')}</a>` : ''}</nav>` : '');
  const qs = (o) => Object.entries(o).filter(([, v]) => v !== '' && v != null && v !== 0 && v !== 'all').map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&');

  /* ----------------------------------------------------------- graphiques */
  /** Échelle « ronde » : 2 graduations de pas 1, 2 ou 5 × 10ⁿ au-dessus du maximum. */
  const niceMax = (v) => {
    const half = Math.max(1, v / 2);
    const p = Math.pow(10, Math.floor(Math.log10(half)));
    return 2 * ([1, 2, 5, 10].find((k) => k * p >= half) * p);
  };
  const barPath = (x, base, w, h, r) => {
    r = Math.min(r, h, w / 2);
    return `M${x} ${base}V${base - h + r}Q${x} ${base - h} ${x + r} ${base - h}H${x + w - r}Q${x + w} ${base - h} ${x + w} ${base - h + r}V${base}Z`;
  };
  /** Colonnes sur 30 jours, une seule série (le titre la nomme) ; valeur au survol et tableau des valeurs. */
  function chart(title, values, from) {
    const W = 340, H = 132, L = 28, R = 4, T = 8, B = 20;
    const n = values.length;
    const max = niceMax(Math.max(...values, 1));
    const pw = W - L - R, ph = H - T - B, band = pw / n, bw = Math.min(24, Math.max(2, band - 2));
    const base = T + ph;
    const start = fmt.parseYmd(from);
    const day = (i) => { const d = new Date(start); d.setDate(d.getDate() + i); return d; };
    let s = '';
    [0, max / 2, max].forEach((t) => {
      const y = (base - (t / max) * ph).toFixed(1);
      s += `<line class="grid-line" x1="${L}" x2="${W - R}" y1="${y}" y2="${y}"/><text class="axis" x="${L - 6}" y="${(+y + 3).toFixed(1)}" text-anchor="end">${SM.esc(fmt.compact(t))}</text>`;
    });
    values.forEach((v, i) => {
      const x = L + i * band;
      const tip = SM.esc(dayLabel(day(i)) + (i === n - 1 ? ' (today)' : '') + '|' + fmt.num(v));
      s += `<rect class="hit" x="${x.toFixed(1)}" y="${T}" width="${band.toFixed(1)}" height="${ph}" data-tip="${tip}"/>`;
      s += v > 0 ? `<path class="bar" d="${barPath(+(x + (band - bw) / 2).toFixed(1), base, +bw.toFixed(1), Math.max(2, (v / max) * ph), 4)}"/>` : '<g/>';
    });
    s += `<text class="axis" x="${L}" y="${H - 5}">${SM.esc(dayLabel(day(0)))}</text><text class="axis" x="${W - R}" y="${H - 5}" text-anchor="end">Today</text>`;
    const total = values.reduce((a, b) => a + b, 0);
    return html`<section class="panel chart">
      <header><h3>${title}</h3><small>today <b>${fmt.num(values[n - 1])}</b> · 30 days ${fmt.num(total)}</small></header>
      ${raw(`<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${SM.esc(title + ' per day over the last 30 days: ' + total + ' in total, ' + values[n - 1] + ' today')}">${s}</svg>`)}
      <details><summary>Show values</summary><table>${values.map((v, i) => html`<tr><td>${dayLabel(day(i))}</td><td>${fmt.num(v)}</td></tr>`).reverse()}</table></details>
    </section>`;
  }
  // Une seule bulle pour tous les graphiques ; le texte est inséré avec textContent.
  let tipEl = null;
  document.addEventListener('pointermove', (e) => {
    const t = e.target && e.target.closest ? e.target.closest('[data-tip]') : null;
    if (!t) { if (tipEl) tipEl.hidden = true; return; }
    if (!tipEl) { tipEl = document.createElement('div'); tipEl.className = 'chart-tip'; document.body.appendChild(tipEl); }
    const [label, value] = t.getAttribute('data-tip').split('|');
    const b = document.createElement('b'); b.textContent = value;
    const l = document.createElement('span'); l.textContent = label;
    tipEl.replaceChildren(b, l);
    tipEl.hidden = false;
    const w = tipEl.offsetWidth, h = tipEl.offsetHeight;
    tipEl.style.left = Math.min(window.innerWidth - w - 8, Math.max(8, e.clientX - w / 2)) + 'px';
    tipEl.style.top = Math.max(8, e.clientY - h - 14) + 'px';
  });

  /* ------------------------------------------------------ vue d'ensemble */
  const FEED_ICONS = { signup: 'user-plus', post: 'edit', project: 'folder', studio: 'columns', card: 'board', message: 'message', opp: 'briefcase', apply: 'send', case: 'award', guest: 'globe' };
  /**
   * Regroupe les actions répétées d'une même personne qui se suivent (« added 10 cards to a Studio ») : le fil
   * reste lisible quand quelqu'un travaille beaucoup. Publications, offres et études de cas restent une à une.
   */
  const GROUPED = {
    card: (n) => `added ${n} cards to Studios`, message: (n) => `sent ${n} private messages`, apply: (n) => `applied to ${n} opportunities`,
    studio: (n) => `created ${n} Studios`, project: (n) => `created ${n} projects`, guest: (n) => `${n} guests joined shared Studios`,
  };
  const groupFeed = (list) => {
    const out = [];
    list.forEach((e) => {
      const last = out[out.length - 1];
      if (last && GROUPED[e.kind] && last.kind === e.kind && last.uid === e.uid && last.tLast - e.t < 86400) {
        last.n++;
        last.tLast = e.t;
        last.text = GROUPED[e.kind](last.n);
      } else out.push(Object.assign({}, e, { n: 1, tLast: e.t }));
    });
    return out;
  };
  const PAGES = {
    landing: 'Landing page', home: 'Home feed', discover: 'Discover', network: 'My Network', projects: 'Projects', project: 'a project', studios: 'Studios',
    studio: 'a Studio', 'shared-studio': 'a shared Studio (guest)', work: 'My work', opportunities: 'Opportunities', opportunity: 'an opportunity',
    talent: 'Talent', messages: 'Messages', notifications: 'Notifications', profile: 'their profile', settings: 'Settings', u: 'a profile', c: 'a company page',
    post: 'a post', signup: 'Sign up', login: 'Sign in', welcome: 'Getting started', team: 'Team', requests: 'Requests', join: 'an invitation', forgot: 'Password reset',
  };
  const pageName = (p) => PAGES[p] || p;
  /** Durée depuis le début de la visite. */
  const dur = (since) => { const m = Math.round((Date.now() / 1000 - since) / 60); return m < 1 ? 'less than a minute' : m < 60 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; };
  const SERIES = [['visitors', 'Visitors (unique per day)'], ['active', 'Active accounts'], ['signups', 'New accounts'], ['cards', 'Cards created'], ['posts', 'Posts'], ['messages', 'Private messages'], ['emails', 'Emails sent']];
  const TRANSPORT = { smtp: 'SMTP', mail: 'PHP mail()', log: 'Test log (not sent)' };

  SM.route(/^\/$/, async (m, ctx) => {
    setTitle('Live overview');
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Live overview</h1><p class="muted">Refreshes every 10 seconds while this tab is open. Server time zone.</p></div>
        <a class="btn btn-soft" href="#/accounts?filter=online">${icon('users')}<span>Everyone online</span></a></header>
      <div id="ov-tiles" class="tiles">${SM.loading(1)}</div>
      <div class="grid adm-2">
        <div class="stack-col">
          <section class="panel pad"><h2>${icon('zap')} Who's here now <span class="muted small">last 5 minutes</span></h2><div id="ov-online"></div></section>
          <section class="panel pad"><h2>${icon('mail')} Emails</h2><div id="ov-mail"></div></section>
        </div>
        <section class="panel pad feed-panel"><h2>${icon('history')} Live activity</h2><div id="ov-feed" class="feed" tabindex="0" aria-label="Live activity"></div></section>
      </div>
      <h2 class="section-title">${icon('chart')} Last 30 days</h2>
      <div id="ov-charts" class="charts"></div>
      <section class="panel pad"><h2>${icon('layers')} Platform totals</h2><div id="ov-totals" class="kv"></div></section>
    </div>`);
    let lastSeries = '';
    let lastFeed = '';
    const load = async () => {
      let d;
      try { d = await SM.get('admin_live'); } catch (e) { if (ctx.alive()) setLive(false, 'Offline — retrying'); return; }
      if (!ctx.alive()) return;
      const o = d.online, t = d.totals, td = d.today;
      SM.set($('#ov-tiles'), html`
        ${tile('zap', 'Visitors now', fmt.num(d.visitors.now), `${fmt.num(d.visitors.members)} signed in · ${fmt.num(d.visitors.now - d.visitors.members)} not signed in · ${fmt.num(d.visitors.today)} today`, d.visitors.now ? 'tile-ok' : '')}
        ${tile('user', 'Active today', fmt.num(d.active.day), `${fmt.num(d.active.week)} this week · ${fmt.num(d.active.month)} this month`)}
        ${tile('user-plus', 'New today', fmt.num(td.signups), `${fmt.num(t.new7)} in 7 days · ${fmt.num(t.new30)} in 30`)}
        ${tile('users', 'Accounts', fmt.num(t.users), `${pct(t.confirmed, t.users)} confirmed · ${fmt.num(t.suspended)} suspended`)}
        ${tile('board', 'Cards today', fmt.num(td.cards), `${fmt.plural(td.comments, 'comment')} · ${fmt.plural(td.posts, 'post')}`)}
        ${tile('send', 'Emails today', fmt.num(td.emails), td.email_failed ? `${fmt.num(td.email_failed)} failed` : 'no failure', td.email_failed ? 'tile-bad' : '')}`);
      const v = d.visitors;
      SM.set($('#ov-online'), v.list.length ? html`
          <div class="chips-row">${v.pages.map((p) => html`<span class="chip chip-soft">${pageName(p.page)} · ${p.n}</span>`)}</div>
          <div class="members">${v.list.map((x) => (x.user
            ? who(x.user, `on ${pageName(x.page)} · ${x.device} · here for ${dur(x.since)}`)
            : html`<span class="who-btn">${SM.avatar(null, 'av-s')}<span class="who-txt"><b>Visitor (not signed in)</b><small>on ${pageName(x.page)} · ${x.device} · here for ${dur(x.since)}</small></span></span>`))}</div>
          <p class="muted small">${fmt.plural(o.guests, 'guest')} on share links · ${fmt.num(o.ai)} via AI assistants · ${fmt.num(v.week)} unique visitors in 7 days</p>`
        : html`<p class="muted">Nobody is on Studioma right now.</p><p class="muted small">${fmt.num(v.today)} visitors today · ${fmt.num(v.week)} in 7 days</p>`);
      const feed = groupFeed(d.feed).slice(0, 25);
      const feedKey = JSON.stringify(feed.map((e) => [e.kind, e.uid, e.n, e.t]));
      if (feedKey !== lastFeed) {
        lastFeed = feedKey;
        SM.set($('#ov-feed'), feed.length ? html`${feed.map((e) => html`<div class="feed-row"><span class="feed-ico">${icon(FEED_ICONS[e.kind] || 'zap')}</span>
            <p>${e.user ? html`<a href="#" data-act="adm-user" data-id="${e.user.id}"><b>${e.user.name}</b></a> ` : ''}${e.text}</p><time>${fmt.ago(e.t)}</time></div>`)}`
          : html`<p class="muted">No activity yet.</p>`);
      }
      const key = JSON.stringify(d.series);
      if (key !== lastSeries) {
        lastSeries = key;
        SM.set($('#ov-charts'), html`${SERIES.map(([k, l]) => chart(l, d.series[k], d.series.from))}`);
      }
      const kv = [['Projects', t.projects], ['Studios', t.studios], ['Cards', t.cards], ['Delivered cards', t.delivered], ['Posts', t.posts], ['Open opportunities', t.opps],
        ['Applications', t.applications], ['Published case studies', t.cases], ['Private messages', t.messages], ['Company pages', t.companies], ['Company accounts', t.company_accounts],
        ['Google sign-in', t.google], ['Active share links', t.shares], ['Active guests', t.guests], ['AI connections', t.ai], ['Files', t.files]];
      SM.set($('#ov-totals'), html`${kv.map(([l, v]) => html`<div><span>${l}</span><b>${fmt.num(v)}</b></div>`)}<div><span>Storage</span><b>${bytes(t.bytes)}</b></div>`);
      const ml = d.mail;
      SM.set($('#ov-mail'), html`<div class="kv">
          <div><span>Sending</span><b>${TRANSPORT[ml.transport] || ml.transport}</b></div>
          <div><span>Last hour</span><b>${fmt.num(ml.hour)} / ${fmt.num(ml.per_hour)}</b></div>
          <div><span>Campaign queue</span><b>${fmt.num(ml.pending)}</b></div>
          <div><span>Failed (7 days)</span><b>${fmt.num(ml.failed7)}</b></div>
        </div>
        ${ml.last_error ? html`<p class="notice notice-warn">${icon('alert')}<span>Last failure ${fmt.ago(ml.last_error.at)}: ${ml.last_error.error}</span></p>` : ''}
        <a class="btn btn-sm btn-soft" href="#/emails">${icon('mail')}Open emails</a>`);
      setLive(true, 'Live · ' + new Date(d.now * 1000).toLocaleTimeString('en-GB'));
    };
    await load();
    if (ctx.alive()) every(load, POLL);
  }, { refresh: () => SM.navigate() });

  /* ---------------------------------------------------------------- comptes */
  const FILTERS = [['all', 'All'], ['online', 'Online now'], ['new', 'New (7 days)'], ['active', 'Active (30 days)'], ['inactive', 'Inactive 30+ days'],
    ['unconfirmed', 'Unconfirmed email'], ['individual', 'Individuals'], ['company', 'Companies'], ['google', 'Google'], ['suspended', 'Suspended']];
  const SORTS = [['new', 'Newest first'], ['old', 'Oldest first'], ['seen', 'Last seen'], ['name', 'Name']];
  SM.route(/^\/accounts$/, async (m, ctx) => {
    setTitle('Accounts');
    const q = SM.query();
    const st = { q: q.get('q') || '', filter: q.get('filter') || 'all', sort: q.get('sort') || 'new', page: +q.get('page') || 0 };
    const d = await SM.get('admin_users', st);
    if (!ctx.alive()) return;
    const link = (o) => '#/accounts' + ((s) => (s ? '?' + s : ''))(qs(Object.assign({}, st, { page: 0 }, o)));
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Accounts</h1><p class="muted">${fmt.plural(d.counts.all, 'account')} · click a row to open the full record and its actions.</p></div>
        <a class="btn" href="${SM.base}api.php?action=admin_users_csv" download>${icon('download')}<span>Export CSV</span></a></header>
      <form class="filters" data-form="adm-users-search">
        <label class="search-in grow">${icon('search')}<input class="input" name="q" value="${st.q}" placeholder="Search a name, an email or a username" autocomplete="off"></label>
        <select class="input" name="sort" data-change="adm-users-sort" aria-label="Sort">${SORTS.map(([k, l]) => html`<option value="${k}" ${st.sort === k ? 'selected' : ''}>${l}</option>`)}</select>
        <button class="btn btn-primary" type="submit">Search</button>
      </form>
      <div class="filters">${FILTERS.map(([k, l]) => html`<a class="btn btn-sm ${st.filter === k ? 'btn-soft is-on' : 'btn-ghost'}" href="${link({ filter: k })}">${l} <small>${fmt.num(d.counts[k])}</small></a>`)}</div>
      ${d.users.length ? html`<section class="panel table-wrap"><table class="table">
        <thead><tr><th>Account</th><th class="hide-s">Email</th><th class="hide-s">Joined</th><th>Last seen</th><th class="num hide-s">Projects</th><th>Status</th></tr></thead>
        <tbody>${d.users.map((u) => html`<tr class="is-click" data-act="adm-user" data-id="${u.id}">
          <td><button class="who" data-act="adm-user" data-id="${u.id}">${SM.avatar(u, 'av-s')}<span class="who-txt"><b>${u.name}</b><small>@${u.handle}${u.kind === 'company' ? ' · company' : ''}</small></span></button></td>
          <td class="hide-s"><span class="nowrap">${u.email}</span> ${u.email_ok ? html`<span title="Email confirmed" class="chip chip-ok">${icon('check')}</span>` : ''}</td>
          <td class="hide-s nowrap">${fmt.date(u.created)}</td>
          <td class="nowrap">${u.online ? html`<span class="on-dot"></span>online` : u.last_seen ? fmt.ago(u.last_seen) : '—'}</td>
          <td class="num hide-s">${fmt.num(u.projects)}</td>
          <td><span class="chips-row">${u.suspended ? html`<span class="chip chip-bad">Suspended</span>` : html`<span class="chip chip-ok">Active</span>`}${u.google ? html`<span class="chip">Google</span>` : ''}${u.news ? '' : html`<span class="chip chip-warn">No news</span>`}</span></td>
        </tr>`)}</tbody></table></section>
        ${pager(d.page, (d.page + 1) * d.per_page < d.total, (p) => '#/accounts?' + qs(Object.assign({}, st, { page: p })))}`
        : SM.empty('users', 'No account found', st.q ? 'Try another search or filter.' : 'No account matches this filter yet.')}
    </div>`);
  });
  SM.forms['adm-users-search'] = (form, data) => {
    const q = SM.query();
    SM.go('/accounts?' + qs({ q: data.q.trim(), filter: q.get('filter') || 'all', sort: data.sort }));
  };
  SM.changes['adm-users-sort'] = (el) => { const f = el.form; SM.forms['adm-users-search'](f, SM.formData(f)); };

  /* -------------------------------------------------- fiche d'un compte */
  let ud = null;   // fenêtre latérale de la fiche ouverte
  SM.actions['adm-user'] = (el) => openUser(+el.dataset.id);
  async function openUser(id) {
    if (ud) ud.close(true);
    ud = SM.modal(html`<div class="pad">${SM.spinner()}</div>`, { side: true, onClose: () => { ud = null; } });
    const me = ud;
    try {
      const d = await SM.get('admin_user', { id });
      if (ud === me) renderUser(d);
    } catch (e) {
      if (ud === me) SM.set(ud.el, html`${SM.modalHead('Account')}<div class="pad">${SM.empty('alert', 'Unavailable', e.message)}</div>`);
    }
  }
  const DAYS30 = () => { const out = []; const t = new Date(); for (let i = 29; i >= 0; i--) { const d = new Date(t); d.setDate(t.getDate() - i); out.push(fmt.ymd(d)); } return out; };
  function renderUser(d) {
    if (!ud) return;
    ud.data = d;
    const u = d.user, s = d.stats;
    const on = new Set(d.days);
    const stat = (l, v) => html`<div><span>${l}</span><b>${v}</b></div>`;
    const btn = (op, ic, label, cls) => html`<button class="btn btn-sm ${cls || ''}" data-act="adm-uop" data-op="${op}">${icon(ic)}${label}</button>`;
    SM.set(ud.el, html`
      <div class="ud-head">${SM.avatar(u, 'av-l')}
        <div class="grow"><h2>${u.name}</h2><span class="muted small">@${u.handle} · ${u.kind === 'company' ? 'company account' : 'individual'}${u.title ? ' · ' + u.title : ''}</span>
          <span class="small"><b>${u.email}</b> ${u.email_ok ? html`<span class="chip chip-ok">${icon('check')}confirmed</span>` : html`<span class="chip chip-warn">not confirmed</span>`}</span></div>
        <button class="btn-icon" data-act="modal-close" aria-label="Close">${icon('x')}</button></div>
      <div class="ud-body">
        ${u.suspended ? html`<p class="notice notice-warn">${icon('lock')}<span><b>Suspended ${fmt.ago(u.suspended)}.</b> ${u.suspend_note ? 'Reason: ' + u.suspend_note + '. ' : ''}Cannot sign in; profile and posts are hidden.</span></p>` : ''}
        <div class="chips-row">${u.online ? html`<span class="chip chip-ok"><span class="on-dot"></span>online</span>` : ''}${u.google ? html`<span class="chip">Google sign-in</span>` : ''}${u.has_password ? html`<span class="chip">Password</span>` : ''}${u.pro ? html`<span class="chip">In talent directory</span>` : ''}${u.location ? html`<span class="chip">${icon('pin')}${u.location}</span>` : ''}</div>
        <section><h3>Activity</h3><div class="kv">${stat('Joined', fmt.date(u.created))}${stat('Last seen', u.last_seen ? fmt.ago(u.last_seen) : '—')}${stat('Active days (30)', s.active_days)}${stat('Open sessions', s.sessions)}${stat('Terms accepted', u.terms_at ? fmt.date(u.terms_at) : 'not yet')}</div>
          <div class="days" title="Active days over the last 30 days" aria-label="Active on ${s.active_days} of the last 30 days">${DAYS30().map((day) => html`<i class="${on.has(day) ? 'on' : ''}" title="${day}"></i>`)}</div></section>
        <section><h3>Plan</h3>${planBlock(d.billing)}</section>
        <section><h3>Usage</h3><div class="kv">
          ${stat('Projects (owner)', `${s.projects} (${s.projects_owned})`)}${stat('Studios (direct)', s.studios)}${stat('Cards created', s.cards)}${stat('Card comments', s.comments)}
          ${stat('Posts', s.posts)}${stat('Messages sent', s.messages)}${stat('Case studies', s.cases)}${stat('Followers', s.followers)}${stat('Connections', s.connections)}${stat('Files', `${s.files} · ${bytes(s.bytes)}`)}
        </div><p class="muted small">Numbers only: project names, cards and messages stay private.</p></section>
        ${d.companies.length ? html`<section><h3>Companies</h3><div class="chips-row">${d.companies.map((c) => html`<a class="chip" href="${APP}#/c/${c.slug}" target="_blank" rel="noopener">${c.name} · ${c.role}</a>`)}</div></section>` : ''}
        ${d.ai.length ? html`<section><h3>AI assistants</h3>${d.ai.map((g) => html`<div class="list-row"><span class="grow"><b>${g.name}</b><small class="muted">${g.personal ? 'Personal token' : 'App (OAuth)'} · connected ${fmt.date(g.created)} · last used ${g.used ? fmt.ago(g.used) : 'never'}</small></span></div>`)}</section>` : ''}
        <section><h3>Actions</h3><div class="ud-actions">
          ${btn('email', 'mail', 'Email this person')}
          ${btn('send_reset', 'lock', 'Send a password reset link')}
          ${u.email_ok ? '' : btn('send_confirm', 'send', 'Resend confirmation link')}
          ${u.email_ok ? '' : btn('confirm_email', 'check', 'Mark email as confirmed')}
          ${btn('signout', 'logout', 'Sign out everywhere')}
          ${d.ai.length ? btn('revoke_ai', 'sparkles', 'Disconnect AI assistants') : ''}
          <a class="btn btn-sm" href="${APP}#/u/${u.handle}" target="_blank" rel="noopener">${icon('external')}Public profile</a>
        </div>
        <label class="switch-row"><span><b>News and announcements</b><small>Campaign emails from the administration.</small></span><input type="checkbox" class="switch" data-change="adm-news" ${u.settings.email_news ? 'checked' : ''}></label></section>
        <section class="danger-zone"><h3>Account status</h3><div class="ud-actions">
          ${u.suspended ? btn('unsuspend', 'restore', 'Lift the suspension', 'btn-soft') : btn('suspend', 'lock', 'Suspend account', 'is-danger')}
          ${btn('delete', 'trash', 'Delete account', 'is-danger')}
        </div></section>
        <section><h3>Emails received</h3>${d.emails.length ? html`<div class="table-wrap"><table class="table"><tbody>${d.emails.map((e) => html`<tr><td class="nowrap"><small>${fmt.time(e.created)}</small></td><td class="wrap">${e.subject}${e.error ? html`<br><small class="result-bad">${e.error}</small>` : ''}</td><td>${mailChip(e.status)}</td></tr>`)}</tbody></table></div>` : html`<p class="muted small">No email logged yet.</p>`}</section>
        ${d.log.length ? html`<section><h3>Administration history</h3>${d.log.map((l) => html`<div class="list-row"><span class="grow"><b>${l.action.replace(/_/g, ' ')}</b><small class="muted">${l.text}</small></span><small class="muted nowrap">${fmt.time(l.created)}</small></div>`)}</section>` : ''}
      </div>`);
  }
  /** Formule d'un compte, son usage et la formule offerte (fiche d'un compte). */
  const planBlock = (b) => {
    if (!b) return '';
    const s = b.subscription;
    const lim = (v) => (v == null ? '∞' : fmt.num(v));
    return html`<div class="kv">
        <div><span>Plan</span><b>${b.plan_name}${s && s.provider === 'manual' ? ' (offered)' : s && s.provider === 'promo' ? (s.status === 'trialing' ? ' (30-day free trial)' : ' (free year, referrals)') : ''}</b></div>
        ${s && s.provider === 'promo' ? html`<div><span>Launch offer until</span><b>${fmt.date(s.period_end)}</b></div>` : ''}
        <div><span>State</span><b>${s && s.cancel_at ? 'ends ' + fmt.date(s.cancel_at) : b.state}</b></div>
        ${s && (s.provider === 'paypal' || s.provider === 'paddle') ? html`<div><span>Billing</span><b>${'$' + s.amount / 100} / ${s.interval} · ${s.provider === 'paypal' ? 'PayPal' : 'Paddle'} ${s.env}</b></div><div><span>${s.cancel_at ? 'Paid until' : 'Renews'}</span><b>${s.period_end ? fmt.date(s.period_end) : '—'}</b></div>${s.prev_plan ? html`<div><span>Keeps ${s.prev_plan} until</span><b>${fmt.date(s.prev_until)}</b></div>` : ''}` : ''}
        ${s && s.provider === 'manual' ? html`<div><span>Offered until</span><b>${s.period_end ? fmt.date(s.period_end) : 'no end date'}</b></div>` : ''}
        <div><span>Projects</span><b>${b.usage.projects} / ${lim(b.limits.projects)}</b></div><div><span>Studios</span><b>${b.usage.studios} / ${lim(b.limits.studios)}</b></div>
        <div><span>Members</span><b>${b.usage.members} / ${lim(b.limits.members)}</b></div><div><span>Cards this month</span><b>${b.usage.cards_month} / ${lim(b.limits.cards_month)}</b></div>
      </div>
      ${b.over.length ? html`<p class="notice notice-warn">${icon('alert')}<span>Above the plan: ${b.over.map((o) => o.label + ' ' + o.used + '/' + o.max).join(', ')}</span></p>` : ''}
      <form class="row row-wrap" data-form="adm-plan"><select class="input input-sm" name="plan" aria-label="Plan">${['free', 'solo', 'team'].map((k) => html`<option value="${k}" ${k === (s && s.provider === 'manual' ? s.plan : 'free') ? 'selected' : ''}>${k === 'free' ? 'No offered plan' : 'Offer ' + k[0].toUpperCase() + k.slice(1)}</option>`)}</select>
        <input class="input input-sm" type="date" name="until" aria-label="Until (optional)" style="max-width:170px"><button class="btn btn-sm" type="submit">Apply</button></form>
      <p class="muted small">An offered plan is free for this account (no payment, not counted in revenue). Paid subscriptions are managed in PayPal (refunds from your PayPal business account).</p>`;
  };
  SM.forms['adm-plan'] = async (form, data) => {
    const r = await SM.post('admin_user_action', { id: ud.data.user.id, op: 'plan', plan: data.plan, until: data.until || '' });
    renderUser(r);
    SM.toast('Plan updated');
  };
  const mailChip = (s) => (s === 'sent' ? html`<span class="chip chip-ok">sent</span>` : html`<span class="chip chip-bad">failed</span>`);
  const DONE = {
    confirm_email: 'Email marked as confirmed', send_reset: 'Password reset link sent', send_confirm: 'Confirmation link sent', signout: 'Signed out everywhere',
    revoke_ai: 'AI assistants disconnected', suspend: 'Account suspended', unsuspend: 'Suspension lifted', news: 'Preference saved', email: 'Email sent',
  };
  const refreshAccounts = () => { if (/^\/accounts/.test(SM.path())) SM.navigate(); };
  async function userOp(op, extra) {
    if (!ud || !ud.data) return null;
    const r = await SM.try(SM.post('admin_user_action', Object.assign({ id: ud.data.user.id, op }, extra || {})));
    if (!r) return null;
    if (r.deleted) { ud.close(true); ud = null; SM.toast('Account deleted'); refreshAccounts(); return r; }
    renderUser(r);
    SM.toast(DONE[op] || 'Done');
    if (['suspend', 'unsuspend', 'confirm_email', 'news'].includes(op)) refreshAccounts();
    return r;
  }
  SM.actions['adm-uop'] = async (el) => {
    if (!ud || !ud.data) return;
    const u = ud.data.user;
    const op = el.dataset.op;
    let extra = {};
    if (op === 'email') { composeEmail(u); return; }
    if (op === 'suspend') {
      const r = await SM.confirm({ title: 'Suspend ' + u.name + '?', text: 'They are signed out everywhere and can no longer sign in (password, Google or AI assistants). Their profile and posts are hidden. Nothing is deleted: you can lift the suspension at any time.',
        body: html`<label class="field"><span>Reason <em>(only visible in the administration)</em></span><input class="input" name="note" maxlength="300" autocomplete="off"></label>`, ok: 'Suspend', danger: true, values: true });
      if (!r) return;
      extra = { note: r.note || '' };
    } else if (op === 'delete') {
      const ok = await SM.confirm({ title: 'Delete ' + u.name + '\'s account?', text: 'This permanently deletes the account, its profile, posts, messages and files, and the projects where they are the only member. It is refused while they are the only owner of a project or company shared with others.', type: u.email, ok: 'Delete permanently', danger: true });
      if (!ok) return;
      extra = { confirm: u.email };
    } else if (op === 'signout' || op === 'revoke_ai' || op === 'send_reset') {
      const text = { signout: 'Every open session of this account is closed. They can sign in again.', revoke_ai: 'Every AI assistant and personal token connected to this account stops working.', send_reset: 'A link to choose a new password (valid one hour) is emailed to ' + u.email + '.' }[op];
      if (!(await SM.confirm({ title: el.textContent.trim() + '?', text, ok: 'Confirm' }))) return;
    }
    SM.busy(el, true);
    try { await userOp(op, extra); } finally { SM.busy(el, false); }
  };
  SM.changes['adm-news'] = async (el) => { if (!(await userOp('news', { on: el.checked }))) el.checked = !el.checked; };
  function composeEmail(u) {
    SM.modal(html`${SM.modalHead('Email ' + u.name, 'Sent now to ' + u.email + ', from the Studioma address.')}
      <form class="modal-body stack" data-form="adm-user-email">
        <label class="field"><span>Subject</span><input class="input" name="subject" maxlength="150" required autofocus></label>
        <label class="field"><span>Message <em>blank line = new paragraph · {name} = first name</em></span><textarea class="input" name="message" rows="8" maxlength="5000" required>Hi {name},

</textarea></label>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button type="submit" class="btn btn-primary">${icon('send')}Send</button></footer>
      </form>`, { size: 'm' });
  }
  SM.forms['adm-user-email'] = async (form, data) => {
    const r = await SM.post('admin_user_action', { id: ud.data.user.id, op: 'email', subject: data.subject, message: data.message });
    SM.topModal().close(true);
    renderUser(r);
    SM.toast('Email sent');
  };

  /* ---------------------------------------------------------------- Studios */
  SM.route(/^\/studios$/, async (m, ctx) => {
    setTitle('Studios');
    const q = SM.query();
    const st = { q: q.get('q') || '', filter: q.get('filter') || 'active', sort: q.get('sort') || 'new', page: +q.get('page') || 0 };
    const d = await SM.get('admin_studios', st);
    if (!ctx.alive()) return;
    // « all » est un vrai filtre ici : pas de qs(), qui l'enlève.
    const sq = (o) => Object.entries(Object.assign({}, st, o)).filter(([, v]) => v !== '' && v !== 0).map(([k, v]) => k + '=' + encodeURIComponent(v)).join('&');
    const link = (o) => '#/studios?' + sq(Object.assign({ page: 0 }, o));
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Studios</h1><p class="muted">Every Studio created on Studioma: owner, team, cards and sharing. Card contents stay private.</p></div></header>
      <form class="filters" data-form="adm-studios-search">
        <label class="search-in grow">${icon('search')}<input class="input" name="q" value="${st.q}" placeholder="Search a Studio, a project or an owner" autocomplete="off"></label>
        <select class="input" name="sort" data-change="adm-studios-sort" aria-label="Sort">${[['new', 'Newest first'], ['activity', 'Last activity'], ['cards', 'Most cards']].map(([k, l]) => html`<option value="${k}" ${st.sort === k ? 'selected' : ''}>${l}</option>`)}</select>
        <button class="btn btn-primary" type="submit">Search</button>
      </form>
      <div class="filters">${[['active', 'Active'], ['archived', 'Archived'], ['all', 'All']].map(([k, l]) => html`<a class="btn btn-sm ${st.filter === k ? 'btn-soft is-on' : 'btn-ghost'}" href="${link({ filter: k })}">${l} <small>${fmt.num(d.counts[k])}</small></a>`)}</div>
      ${d.studios.length ? html`<section class="panel table-wrap"><table class="table">
        <thead><tr><th>Studio</th><th>Owner</th><th class="num">Cards</th><th class="num hide-s">Team</th><th class="hide-s">Shared</th><th class="hide-s">Created</th><th>Last activity</th></tr></thead>
        <tbody>${d.studios.map((s) => html`<tr class="is-click" data-act="adm-studio" data-id="${s.id}">
          <td><button class="who" data-act="adm-studio" data-id="${s.id}"><span class="list-ico">${icon('board')}</span><span class="who-txt"><b>${s.name}</b><small>${s.project}${s.company ? ' · company' : ''}${s.archived ? ' · archived' : ''}</small></span></button></td>
          <td>${s.owner ? html`<span class="nowrap">${s.owner.name}</span>` : '—'}</td>
          <td class="num">${fmt.num(s.cards)}${s.done ? html` <small>(${fmt.num(s.done)} done)</small>` : ''}</td>
          <td class="num hide-s">${fmt.num(s.members)}</td>
          <td class="hide-s">${s.shares ? html`<span class="chip chip-soft">${fmt.plural(s.shares, 'link')}${s.guests ? ' · ' + fmt.plural(s.guests, 'guest') : ''}</span>` : html`<small>no</small>`}</td>
          <td class="hide-s nowrap">${fmt.date(s.created)}</td>
          <td class="nowrap">${fmt.ago(s.updated)}</td>
        </tr>`)}</tbody></table></section>${pager(d.page, d.more, (p) => '#/studios?' + sq({ page: p }))}`
        : SM.empty('board', 'No Studio found', st.q ? 'Try another search.' : 'No Studio in this list yet.')}
    </div>`);
  });
  SM.forms['adm-studios-search'] = (form, data) => SM.go('/studios?filter=' + (SM.query().get('filter') || 'active') + '&sort=' + data.sort + (data.q.trim() ? '&q=' + encodeURIComponent(data.q.trim()) : ''));
  SM.changes['adm-studios-sort'] = (el) => SM.forms['adm-studios-search'](el.form, SM.formData(el.form));
  SM.actions['adm-studio'] = async (el) => {
    const md = SM.modal(html`<div class="pad">${SM.spinner()}</div>`, { side: true });
    let d;
    try { d = await SM.get('admin_studio', { id: +el.dataset.id }); } catch (e) { SM.set(md.el, html`${SM.modalHead('Studio')}<div class="pad">${SM.empty('alert', 'Unavailable', e.message)}</div>`); return; }
    const s = d.studio, k = d.stats;
    const stat = (l, val) => html`<div><span>${l}</span><b>${val}</b></div>`;
    SM.set(md.el, html`
      <div class="ud-head"><span class="list-ico">${icon('board')}</span><div class="grow"><h2>${s.name}</h2><span class="muted small">${s.project}${s.archived ? ' · archived' : ''}</span></div>
        <button class="btn-icon" data-act="modal-close" aria-label="Close">${icon('x')}</button></div>
      <div class="ud-body">
        <section><h3>Overview</h3><div class="kv">${stat('Created', fmt.date(s.created))}${stat('Last activity', fmt.ago(s.updated))}${stat('Cards', k.cards)}${stat('Delivered', k.done)}
          ${stat('New cards (7 days)', k.week)}${stat('Actions (7 days)', k.events7)}${stat('Comments', k.comments)}${stat('Files', k.files)}${stat('Archived cards', k.archived)}</div>
          <p class="muted small">Numbers only: card titles, scripts and comments stay private.</p></section>
        ${d.levels.length ? html`<section><h3>Levels</h3>${d.levels.map((l) => html`<div class="list-row"><span class="grow"><b>${l.icon} ${l.name}</b>${l.archived ? html`<small class="muted">archived</small>` : ''}</span><span class="chip">${fmt.plural(l.cards, 'card')}</span></div>`)}</section>` : ''}
        <section><h3>Team (${d.members.length})</h3>${d.members.map((x) => html`<div class="list-row">${x.user ? who(x.user, x.role + (x.scope === 'project' ? ' · whole project' : ' · this Studio') + ' · since ' + fmt.date(x.since)) : html`<span class="muted">Deleted account</span>`}</div>`)}</section>
        <section><h3>Sharing</h3>${d.shares.length ? d.shares.map((x) => html`<div class="list-row"><span class="grow"><b>${x.kind === 'public' ? 'Public link' : 'Private link'}</b><small class="muted">${x.active ? 'active' : 'turned off'} · ${fmt.plural(x.guests, 'guest')}</small></span></div>`) : html`<p class="muted small">Not shared outside the team.</p>`}</section>
      </div>`);
  };

  /* ---------------------------------------------------------- modération */
  const CONTENT = [['posts', 'Posts', 'edit'], ['comments', 'Comments', 'comment'], ['opps', 'Opportunities', 'briefcase'], ['companies', 'Companies', 'building'], ['cases', 'Case studies', 'award'], ['pubs', 'Card publications', 'sparkles']];
  SM.route(/^\/content$/, async (m, ctx) => {
    setTitle('Moderation');
    const q = SM.query();
    const st = { type: q.get('type') || 'posts', q: q.get('q') || '', page: +q.get('page') || 0 };
    const d = await SM.get('admin_content', st);
    if (!ctx.alive()) return;
    const link = (o) => '#/content?' + qs(Object.assign({}, st, o));
    const actions = (i) => {
      const b = (op, label, cls) => html`<button class="btn btn-sm ${cls || ''}" data-act="adm-cop" data-type="${d.type}" data-id="${i.id}" data-op="${op}">${label}</button>`;
      if (d.type === 'opps') return html`${i.status === 'open' ? b('close', 'Close') : ''}${b('delete', 'Delete', 'is-danger')}`;
      if (d.type === 'cases') return b('unpublish', 'Unpublish', 'is-danger');
      if (d.type === 'pubs') return html`${i.kind === 'opportunity' && i.status === 'open' ? b('close', 'Close') : ''}${i.kind === 'achievement' && i.status === 'published' ? b('unpublish', 'Unpublish') : ''}${b('delete', 'Delete', 'is-danger')}`;
      return b('delete', 'Delete', 'is-danger');
    };
    const meta = (i) => [fmt.time(i.created),
      d.type === 'posts' ? `${fmt.plural(i.likes, 'like')} · ${fmt.plural(i.comments, 'comment')}${i.media ? ' · ' + fmt.plural(i.media, 'media file') : ''}` : '',
      d.type === 'opps' ? `${i.company || 'No company'} · ${i.status} · ${fmt.plural(i.apps, 'application')}` : '',
      d.type === 'companies' ? `${fmt.plural(i.members, 'member')} · ${fmt.plural(i.opps, 'opportunity', 'opportunities')}` : '',
      d.type === 'cases' ? (i.source === 'studio' ? 'from a Studio' : 'manual') : '',
      d.type === 'pubs' ? (i.kind === 'achievement' ? 'Achievement' : `Opportunity · ${i.status} · ${fmt.plural(i.proposals, 'proposal')}`) : ''].filter(Boolean).join(' · ');
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Moderation</h1><p class="muted">Public content only. Private Studio content and messages are never shown here.</p></div></header>
      <nav class="tabs">${CONTENT.map(([k, l, ic]) => html`<a class="tab ${d.type === k ? 'is-active' : ''}" href="#/content?type=${k}">${icon(ic)}${l}</a>`)}</nav>
      <form class="filters" data-form="adm-content-search"><input type="hidden" name="type" value="${d.type}">
        <label class="search-in grow">${icon('search')}<input class="input" name="q" value="${st.q}" placeholder="Search" autocomplete="off"></label><button class="btn btn-primary" type="submit">Search</button></form>
      ${d.items.length ? html`<section class="panel list">${d.items.map((i) => html`<div class="list-row" data-row="${i.id}">
          <div class="grow">${i.user ? who(i.user, '@' + i.user.handle) : ''}
            ${i.title ? html`<b>${i.title}</b>` : ''}${i.text ? html`<p class="pre clamp-4">${i.text}</p>` : ''}<small class="muted">${meta(i)}</small></div>
          <div class="row">${i.link ? html`<a class="btn btn-sm btn-ghost" href="${APP}#/${i.link}" target="_blank" rel="noopener" title="Open in Studioma">${icon('external')}</a>` : ''}${actions(i)}</div>
        </div>`)}</section>${pager(d.page, d.more, (p) => link({ page: p }))}`
        : SM.empty('shield', 'Nothing here', st.q ? 'No result for this search.' : 'No content of this kind yet.')}
    </div>`);
  });
  SM.forms['adm-content-search'] = (form, data) => SM.go('/content?' + qs({ type: data.type, q: data.q.trim() }));
  SM.actions['adm-cop'] = async (el) => {
    const { type, id, op } = el.dataset;
    const what = { posts: 'post', comments: 'comment', opps: 'opportunity', companies: 'company page', cases: 'case study', pubs: 'publication' }[type];
    const text = op === 'close' ? 'It stops accepting applications; existing applications are kept.'
      : op === 'unpublish' ? (type === 'pubs' ? 'It disappears from the community and the author\'s profile. The card is not affected.' : 'It goes back to the author\'s drafts and disappears from their public profile.')
        : type === 'pubs' ? 'The publication, its proposals and its public file copies are deleted. The card is not affected.'
        : type === 'companies' ? 'The company page, its team list and its opportunities are deleted. Member accounts are kept.' : 'It is deleted permanently.';
    if (!(await SM.confirm({ title: (op === 'close' ? 'Close' : op === 'unpublish' ? 'Unpublish' : 'Delete') + ' this ' + what + '?', text, ok: op === 'close' ? 'Close' : op === 'unpublish' ? 'Unpublish' : 'Delete', danger: op !== 'close' }))) return;
    SM.busy(el, true);
    const r = await SM.try(SM.post('admin_content_action', { type, id: +id, op }));
    SM.busy(el, false);
    if (r) { SM.toast('Done'); SM.navigate(); }
  };

  /* ------------------------------------------------------------------ emails */
  const CAMP_STATUS = { draft: ['Draft', ''], sending: ['Sending', 'chip-soft'], paused: ['Paused', 'chip-warn'], sent: ['Sent', 'chip-ok'], cancelled: ['Cancelled', 'chip-bad'] };
  const emailTabs = (cur) => html`<nav class="tabs">${[['', 'Campaigns', 'send'], ['/log', 'Email log', 'list'], ['/settings', 'Sending settings', 'settings']].map(([p, l, ic]) => html`<a class="tab ${cur === p ? 'is-active' : ''}" href="#/emails${p}">${icon(ic)}${l}</a>`)}</nav>`;
  const emailHead = () => html`<header class="page-head"><div><h1>Emails</h1><p class="muted">Announcements to your members, every email sent by Studioma, and sending health.</p></div>
    <a class="btn btn-primary" href="#/emails/new">${icon('plus')}<span>New campaign</span></a></header>`;
  function campaignRow(c) {
    const [label, cls] = CAMP_STATUS[c.status] || [c.status, ''];
    const done = c.sent + c.failed + c.skipped;
    const b = (op, l, k) => html`<button class="btn btn-sm ${k || ''}" data-act="adm-camp" data-id="${c.id}" data-op="${op}">${l}</button>`;
    return html`<div class="camp" data-camp="${c.id}">
      <div class="camp-top"><div class="grow"><a href="#/emails/${c.id}"><b>${c.subject}</b></a><small class="muted">${c.audience_label} · created ${fmt.date(c.created)}${c.started ? ' · started ' + fmt.time(c.started) : ''}${c.finished ? ' · finished ' + fmt.time(c.finished) : ''}</small></div>
        <span class="chip ${cls}">${label}</span></div>
      ${c.status !== 'draft' ? html`<div class="camp-progress"><span class="progress"><i style="width:${c.total ? Math.round((done / c.total) * 100) : 0}%"></i></span>
        <span class="nowrap">${fmt.num(c.sent)} sent${c.failed ? ' · ' + fmt.num(c.failed) + ' failed' : ''}${c.skipped ? ' · ' + fmt.num(c.skipped) + ' skipped' : ''} / ${fmt.num(c.total)}</span></div>` : ''}
      <div class="row">${c.status === 'draft' ? html`<a class="btn btn-sm btn-soft" href="#/emails/${c.id}">${icon('edit')}Edit and send</a>${b('delete', 'Delete', 'is-danger')}` : ''}
        ${c.status === 'sending' ? html`${b('pause', 'Pause')}${b('cancel', 'Cancel', 'is-danger')}` : ''}
        ${c.status === 'paused' ? html`${b('resume', 'Resume', 'btn-soft')}${b('cancel', 'Cancel', 'is-danger')}` : ''}
        ${c.status === 'sent' || c.status === 'cancelled' ? html`<a class="btn btn-sm" href="#/emails/${c.id}">Details</a>${b('duplicate', 'Duplicate')}${b('delete', 'Delete', 'is-danger')}` : ''}</div>
    </div>`;
  }
  SM.route(/^\/emails$/, async (m, ctx) => {
    setTitle('Emails');
    const d = await SM.get('admin_mail');
    if (!ctx.alive()) return;
    const st = d.stats;
    const render = (campaigns) => SM.set($('#camps'), campaigns.length ? html`${campaigns.map(campaignRow)}` : SM.empty('send', 'No campaign yet', 'Write an announcement for your members: new features, events, tips.', html`<a class="btn btn-primary" href="#/emails/new">${icon('plus')}New campaign</a>`));
    SM.set(ctx.view, html`<div class="page">${emailHead()}${emailTabs('')}
      <div class="tiles">
        ${tile('send', 'Sent today', fmt.num(st.today), `${fmt.num(st.week)} in 7 days`)}
        ${tile('clock', 'Last hour', fmt.num(st.hour), `limit ${fmt.num(st.per_hour)} per hour`)}
        ${tile('inbox', 'Campaign queue', fmt.num(st.pending), st.pending ? 'sending in small batches' : 'nothing waiting')}
        ${tile('alert', 'Failed (7 days)', fmt.num(st.failed7), st.last_error ? 'last ' + fmt.ago(st.last_error.at) : 'no failure', st.failed7 ? 'tile-bad' : '')}
        ${tile('user', 'Unsubscribed from news', fmt.num(st.opted_out), 'never receive campaigns')}
      </div>
      ${st.pending ? html`<p class="notice">${icon('info')}<span>A campaign is sending: batches go out while this page is open${d.config.cron_last ? ' and every time the cron job runs' : ''}. ${d.config.cron_last ? '' : html`For sending without keeping this page open, add the <a href="#/emails/settings">cron job</a>.`}</span></p>` : ''}
      <section class="panel" id="camps"></section>
    </div>`);
    render(d.campaigns);
    if (!d.campaigns.some((c) => c.status === 'sending')) return;
    setLive(true, 'Sending…');
    const pump = async () => {
      let r;
      try { r = await SM.post('admin_pump'); } catch (e) { return; }
      if (!ctx.alive()) return;
      const byId = Object.fromEntries(r.campaigns.map((c) => [c.id, c]));
      d.campaigns = d.campaigns.map((c) => byId[c.id] || c);
      render(d.campaigns);
      if (!d.campaigns.some((c) => c.status === 'sending')) { setLive(false, 'Sent'); SM.navigate(); }
    };
    await pump();
    if (ctx.alive()) every(pump, 6000);
  });
  SM.actions['adm-camp'] = async (el) => {
    const { id, op } = el.dataset;
    const ask = { pause: null, resume: null, duplicate: null,
      cancel: ['Cancel this campaign?', 'Emails not sent yet are dropped. Emails already sent stay sent.'],
      delete: ['Delete this campaign?', 'Its record and statistics are deleted. Emails already sent are not affected.'] }[op];
    if (ask && !(await SM.confirm({ title: ask[0], text: ask[1], ok: op === 'cancel' ? 'Cancel campaign' : 'Delete', cancel: 'Keep', danger: true }))) return;
    SM.busy(el, true);
    const r = await SM.try(SM.post('admin_campaign_action', { id: +id, op }));
    SM.busy(el, false);
    if (!r) return;
    if (op === 'duplicate' && r.campaign) { SM.go('/emails/' + r.campaign.id); return; }
    SM.toast({ pause: 'Paused', resume: 'Sending again', cancel: 'Campaign cancelled', delete: 'Campaign deleted' }[op] || 'Done');
    if (/^\/emails\/\d+$/.test(SM.path()) && op === 'delete') SM.go('/emails'); else SM.navigate();
  };

  /* -------------------------------------------- composition d'une campagne */
  SM.route(/^\/emails\/(new|\d+)$/, async (m, ctx) => {
    setTitle('Campaign');
    const [mail, one] = await Promise.all([SM.get('admin_mail'), m[1] === 'new' ? null : SM.get('admin_campaign', { id: m[1] })]);
    if (!ctx.alive()) return;
    const c = one ? one.campaign : { id: 0, subject: '', title: '', body: 'Hi {name},\n\n', btn_label: '', btn_url: '', audience: 'all', status: 'draft' };
    const editable = c.status === 'draft';
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><a class="back small" href="#/emails">${icon('arrow-left')}Emails</a><h1>${editable ? (c.id ? 'Edit campaign' : 'New campaign') : c.subject}</h1>
        ${editable ? html`<p class="muted">Write once, everyone in the audience receives it with their first name. Sending is spread over time (at most ${fmt.num(mail.config.per_hour)} emails per hour).</p>` : ''}</div>
        ${!editable ? html`<button class="btn" data-act="adm-camp" data-id="${c.id}" data-op="duplicate">${icon('copy')}<span>Duplicate</span></button>` : ''}</header>
      ${!editable ? html`<div class="tiles">${tile('users', 'Recipients', fmt.num(c.total), c.audience_label)}${tile('check', 'Sent', fmt.num(c.sent), c.finished ? 'finished ' + fmt.time(c.finished) : (CAMP_STATUS[c.status] || [c.status])[0])}${tile('alert', 'Failed', fmt.num(c.failed), c.failed ? 'see below' : 'no failure', c.failed ? 'tile-bad' : '')}${tile('user', 'Skipped', fmt.num(c.skipped), 'unsubscribed, suspended or deleted')}</div>
        ${one.failures.length ? html`<section class="panel table-wrap"><table class="table"><thead><tr><th>Address</th><th>Error</th><th>When</th></tr></thead><tbody>${one.failures.map((f) => html`<tr><td>${f.email}</td><td class="wrap result-bad">${f.error}</td><td class="nowrap">${fmt.time(f.updated)}</td></tr>`)}</tbody></table></section>` : ''}` : ''}
      <div class="compose">
        <form class="panel pad" data-form="adm-campaign" data-input="adm-campaign" id="camp-form">
          <fieldset class="camp-fields" ${editable ? '' : 'disabled'}>
          <label class="field"><span>Subject <em>{name} = first name</em></span><input class="input" name="subject" maxlength="150" value="${c.subject}" required></label>
          <label class="field"><span>Heading <em>optional — the subject is used otherwise</em></span><input class="input" name="title" maxlength="150" value="${c.title}"></label>
          <label class="field"><span>Message <em>blank line = new paragraph</em></span><textarea class="input" name="body" maxlength="10000">${c.body}</textarea></label>
          <div class="field-row"><label class="field"><span>Button text <em>optional</em></span><input class="input" name="btn_label" maxlength="40" value="${c.btn_label}" placeholder="See what's new"></label>
            <label class="field"><span>Button link</span><input class="input" name="btn_url" maxlength="500" value="${c.btn_url}" placeholder="https://dehini.com/studioma/app/"></label></div>
          <label class="field"><span>Send to</span><select class="input" name="audience">${mail.audiences.map((a) => html`<option value="${a.key}" ${c.audience === a.key ? 'selected' : ''}>${a.label} — ${fmt.plural(a.count, 'person', 'people')}</option>`)}</select></label>
          </fieldset>
          ${editable ? html`<p class="notice">${icon('shield')}<span>Only accounts that kept news emails on receive it; suspended accounts never do. Each email carries a one-click unsubscribe link.</span></p>
          <p class="form-error" data-error hidden></p>
          <div class="row row-wrap"><button type="submit" class="btn btn-soft">${icon('check')}Save draft</button><button type="button" class="btn btn-ghost" data-act="adm-camp-test">${icon('mail')}Send me a test</button>
            <span class="grow"></span><button type="button" class="btn btn-primary" data-act="adm-camp-send">${icon('send')}<span id="camp-send">Send</span></button></div>` : ''}
        </form>
        <section class="panel pad"><h2>${icon('eye')} Preview</h2><p class="mail-subject muted">Subject: <b id="camp-subject"></b></p><div class="mail-preview" id="camp-preview"></div></section>
      </div>
    </div>`);
    // Campagne déjà envoyée : champs désactivés (absents d'un FormData), l'aperçu part des données reçues.
    campState = { id: c.id, editable, data: editable ? null : c };
    preview();
  });
  let campState = { id: 0, editable: false };
  const campFields = () => campState.data || SM.formData($('#camp-form'));
  const preview = SM.debounce(async () => {
    const form = $('#camp-form');
    if (!form) return;
    try {
      const r = await SM.post('admin_campaign_preview', campFields());
      if (!$('#camp-form')) return;
      $('#camp-subject').textContent = r.subject;
      SM.set($('#camp-preview'), raw(r.html));   // HTML construit par le serveur (mail_layout), chaque texte y est échappé
      const s = $('#camp-send');
      if (s) s.textContent = 'Send to ' + fmt.plural(r.count, 'person', 'people');
    } catch (e) {
      if (!$('#camp-form')) return;
      $('#camp-subject').textContent = '';
      SM.set($('#camp-preview'), html`<p class="pad muted">${e.message}</p>`);
    }
  }, 450);
  SM.inputs['adm-campaign'] = () => preview();
  async function saveCampaign() {
    const r = await SM.post('admin_campaign_save', Object.assign(campFields(), { id: campState.id }));
    campState.id = r.campaign.id;
    if (!/^\/emails\/\d+$/.test(SM.path())) history.replaceState(null, '', '#/emails/' + r.campaign.id);
    return r.campaign;
  }
  SM.forms['adm-campaign'] = async () => { await saveCampaign(); SM.toast('Draft saved'); };
  SM.actions['adm-camp-test'] = async (el) => {
    const to = await SM.prompt({ title: 'Send a test', label: 'Email address', value: SM.ls.get('adm_test_to', ''), placeholder: 'you@example.com', ok: 'Send test', max: 190 });
    if (!to) return;
    SM.ls.set('adm_test_to', to);
    SM.busy(el, true);
    try {
      await saveCampaign();
      const r = await SM.post('admin_campaign_test', { id: campState.id, to });
      SM.toast(r.sent ? 'Test sent to ' + to : 'The test failed: ' + r.error, r.sent ? '' : 'error');
    } catch (e) { SM.toast(e.message, 'error'); } finally { SM.busy(el, false); }
  };
  SM.actions['adm-camp-send'] = async (el) => {
    let c;
    try { c = await saveCampaign(); } catch (e) { SM.toast(e.message, 'error'); return; }
    const n = ($('#camp-send') || {}).textContent || '';
    if (!(await SM.confirm({ title: n + '?', text: '“' + c.subject + '” — ' + c.audience_label + '. Emails leave in small batches; you can pause or cancel while it sends. A sent email cannot be recalled.', ok: 'Send now' }))) return;
    SM.busy(el, true);
    const r = await SM.try(SM.post('admin_campaign_send', { id: c.id }));
    SM.busy(el, false);
    if (r) { SM.toast('Sending started'); SM.go('/emails'); }
  };

  /* ------------------------------------------------------------ journal */
  SM.route(/^\/emails\/log$/, async (m, ctx) => {
    setTitle('Email log');
    const q = SM.query();
    const st = { q: q.get('q') || '', status: q.get('status') || '', kind: q.get('kind') || '', page: +q.get('page') || 0 };
    const d = await SM.get('admin_mail_log', st);
    if (!ctx.alive()) return;
    const KINDS = [['', 'All kinds'], ['system', 'Studioma (automatic)'], ['campaign', 'Campaigns'], ['admin', 'Sent by the admin'], ['test', 'Tests']];
    SM.set(ctx.view, html`<div class="page">${emailHead()}${emailTabs('/log')}
      <form class="filters" data-form="adm-log-search">
        <label class="search-in grow">${icon('search')}<input class="input" name="q" value="${st.q}" placeholder="Search an address or a subject" autocomplete="off"></label>
        <select class="input" name="status" aria-label="Status">${[['', 'Any status'], ['sent', 'Sent'], ['failed', 'Failed']].map(([k, l]) => html`<option value="${k}" ${st.status === k ? 'selected' : ''}>${l}</option>`)}</select>
        <select class="input" name="kind" aria-label="Kind">${KINDS.map(([k, l]) => html`<option value="${k}" ${st.kind === k ? 'selected' : ''}>${l}</option>`)}</select>
        <button class="btn btn-primary" type="submit">Filter</button>
      </form>
      ${d.items.length ? html`<section class="panel table-wrap"><table class="table">
        <thead><tr><th>When</th><th>To</th><th>Subject</th><th class="hide-s">Kind</th><th>Status</th></tr></thead>
        <tbody>${d.items.map((e) => html`<tr><td class="nowrap"><small>${fmt.time(e.created)}</small></td><td class="nowrap">${e.email}</td>
          <td class="wrap">${e.subject}${e.error ? html`<br><small class="result-bad">${e.error}</small>` : ''}</td><td class="hide-s"><span class="chip">${e.kind}</span></td><td>${mailChip(e.status)}</td></tr>`)}</tbody></table></section>
        ${pager(d.page, d.more, (p) => '#/emails/log?' + qs(Object.assign({}, st, { page: p })))}`
        : SM.empty('mail', 'No email', 'Nothing matches these filters. Emails are kept for 180 days.')}
    </div>`);
  });
  SM.forms['adm-log-search'] = (form, data) => SM.go('/emails/log?' + qs({ q: data.q.trim(), status: data.status, kind: data.kind }));

  /* ------------------------------------------------------------ réglages */
  SM.route(/^\/emails\/settings$/, async (m, ctx) => {
    setTitle('Sending settings');
    const d = await SM.get('admin_mail');
    if (!ctx.alive()) return;
    const c = d.config;
    const SOURCE = { studioma: 'studioma/config.php', impact: 'Défi Impact settings (impact/config.php)', none: 'none — PHP mail() is used' };
    const cron = '/usr/local/bin/php ~/public_html/studioma/cron.php >/dev/null 2>&1';
    SM.set(ctx.view, html`<div class="page">${emailHead()}${emailTabs('/settings')}
      <div class="grid adm-2">
        <section class="panel pad"><h2>${icon('mail')} How emails are sent</h2><div class="kv">
          <div><span>Method</span><b>${TRANSPORT[c.transport] || c.transport}</b></div><div><span>Settings from</span><b>${SOURCE[c.source] || c.source}</b></div>
          <div><span>From</span><b>${c.from_name} &lt;${c.from}&gt;</b></div>
          ${c.transport === 'smtp' ? html`<div><span>Server</span><b>${c.host}:${c.port} (${c.secure || 'plain'})</b></div><div><span>Login</span><b>${c.user || '—'}</b></div>` : ''}
          <div><span>Hourly limit</span><b>${fmt.num(c.per_hour)} emails</b></div></div>
          <p class="muted small">Change these in <code>studioma/config.php</code> on the server (STM_SMTP_*, STM_MAIL_FROM, STM_MAIL_PER_HOUR). Passwords are never shown here.</p></section>
        <section class="panel pad"><h2>${icon('send')} Send a test email</h2>
          <form class="stack" data-form="adm-mail-test"><label class="field"><span>Address</span><input class="input" type="email" name="to" value="${SM.ls.get('adm_test_to', '')}" required placeholder="you@example.com"></label>
            <p class="form-error" data-error hidden></p><p id="test-result" aria-live="polite"></p>
            <div class="row"><button class="btn btn-primary" type="submit">${icon('send')}Send now</button></div></form>
          <p class="muted small">Sent immediately: any SMTP error is shown here and in the log.</p></section>
        <section class="panel pad span-2"><h2>${icon('clock')} Background sending (cron job)</h2>
          <p>${c.cron_last ? html`Last run <b>${fmt.ago(c.cron_last)}</b>.` : html`<b>Not set up yet.</b> Campaigns still go out while this administration is open${c.finish ? ' and after members\' requests' : ''}, but more slowly.`}</p>
          <p class="muted small">In cPanel › Cron Jobs, add this command every 5 minutes (common setting “Once per five minutes”). It sends queued campaign emails within the hourly limit, the grouped guest emails, and cleans expired sessions.</p>
          <div class="code"><pre>${cron}</pre><button class="btn-icon" data-act="adm-copy" data-text="${cron}" aria-label="Copy">${icon('copy')}</button></div></section>
      </div>
    </div>`);
  });
  SM.actions['adm-copy'] = (el) => SM.copy(el.dataset.text, 'Copied');
  SM.forms['adm-mail-test'] = async (form, data) => {
    SM.ls.set('adm_test_to', data.to);
    const r = await SM.post('admin_mail_test', { to: data.to });
    const out = $('#test-result');
    out.className = r.sent ? 'result-ok' : 'result-bad';
    out.textContent = r.sent ? (r.transport === 'log' ? 'Written to the test log (not really sent).' : 'Sent. Check the inbox (and the spam folder) of ' + data.to + '.') : 'Failed: ' + r.error;
  };

  /* ------------------------------------------------------------ abonnements */
  SM.route(/^\/billing$/, async (m, ctx) => {
    setTitle('Billing');
    const env = SM.query().get('env') === 'sandbox' ? 'sandbox' : 'production';
    const d = await SM.get('admin_billing', { env });
    if (!ctx.alive()) return;
    const M = d.metrics;
    const cur = (o) => { const k = Object.keys(o); return k.length ? k.map((c) => (c === 'USD' ? '$' : c + ' ') + (o[c] / 100).toLocaleString('en-US', { maximumFractionDigits: 0 })).join(' + ') : '$0'; };
    const STATUS = { active: 'chip-ok', trialing: 'chip-soft', past_due: 'chip-bad', paused: 'chip-warn', canceled: '', expired: '' };
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Billing</h1><p class="muted">Subscriptions from verified PayPal data. ${env === 'production' ? 'Live payments only.' : 'Sandbox (test) payments — never counted as revenue.'}</p></div>
        <nav class="seg-inline">${['production', 'sandbox'].map((e) => html`<a class="btn btn-sm ${env === e ? 'btn-soft is-on' : 'btn-ghost'}" href="#/billing${e === 'sandbox' ? '?env=sandbox' : ''}">${e === 'production' ? 'Live' : 'Sandbox'}</a>`)}</nav></header>
      ${!d.available ? html`<p class="notice notice-warn">${icon('alert')}<span><b>Online payment is off.</b> ${d.missing.length ? html`Missing settings in <code>studioma/config.php</code>: ${d.missing.join(', ')}. ` : ''}The pricing page shows paid plans as “Coming soon”.</span></p>` : html`<p class="notice notice-ok">${icon('check')}<span>Online payment is on (PayPal ${d.configured_env === 'production' ? 'live' : 'sandbox'}).</span></p>`}
      ${d.can_setup_plans ? html`<section class="panel pad" id="pp-setup"><h2>${icon('zap')} Create the PayPal plans</h2>
        <p>Your PayPal app is set (${d.configured_env === 'production' ? 'live' : 'sandbox'}). Studioma can create the product “Studioma” and its four plans in PayPal — Solo $12/month and $120/year, Team $29/month and $290/year, in US dollars, no tax added — then show the four lines to copy into <code>studioma/config.php</code>.</p>
        <button class="btn btn-primary" data-act="adm-pp-setup">${icon('zap')}Create the PayPal plans</button></section>` : ''}
      ${(d.problems || []).length ? html`<div class="notice notice-warn">${icon('alert')}<span><b>PayPal settings to fix</b> (payment stays closed until they are consistent):<ul class="notice-list">${d.problems.map((p) => html`<li>${p}</li>`)}</ul></span></div>` : ''}
      ${!d.enforced ? html`<p class="notice notice-warn">${icon('alert')}<span>Plan limits are switched off (STM_PLANS_ENFORCED = false).</span></p>` : ''}
      <div class="tiles">
        ${tile('users', 'Accounts', fmt.num(M.users), `${fmt.num(M.free)} on Free · ${fmt.num(M.complimentary)} offered`)}
        ${tile('zap', 'Paid subscribers', fmt.num(M.paid), `${fmt.num(M.solo)} Solo · ${fmt.num(M.team)} Team${M.trialing ? ' · ' + M.trialing + ' trialing' : ''}`, M.paid ? 'tile-ok' : '')}
        ${tile('chart', 'MRR', cur(M.mrr), 'yearly plans counted as 1/12')}
        ${tile('award', 'ARR', cur(M.arr), 'MRR × 12')}
        ${tile('user-plus', 'Conversions', fmt.num(M.converted), `${M.conversion_rate}% of accounts · ${fmt.num(M.converted_30)} in 30 days`)}
        ${tile('alert', 'Churn & risk', fmt.num(M.canceled_30), `canceled in 30 days · ${M.past_due} past due · ${M.scheduled_cancel} ending`, M.past_due ? 'tile-bad' : '')}
      </div>
      <section class="panel pad"><h2>${icon('layers')} Plan distribution</h2><div class="kv">
        <div><span>Solo monthly</span><b>${M.distribution.solo.month}</b></div><div><span>Solo yearly</span><b>${M.distribution.solo.year}</b></div>
        <div><span>Team monthly</span><b>${M.distribution.team.month}</b></div><div><span>Team yearly</span><b>${M.distribution.team.year}</b></div>
        <div><span>Collected (30 days)</span><b>${'$' + (M.revenue_30 / 100).toLocaleString('en-US')}</b></div><div><span>Canceled (all time)</span><b>${M.canceled_total}</b></div></div></section>
      <h2 class="section-title">${icon('list')} Subscriptions</h2>
      ${d.subscriptions.length ? html`<section class="panel table-wrap"><table class="table"><thead><tr><th>Account</th><th>Plan</th><th>State</th><th class="hide-s">Price</th><th class="hide-s">Period end</th><th class="hide-s">Source</th></tr></thead>
        <tbody>${d.subscriptions.map((s) => html`<tr${s.user ? html` class="is-click" data-act="adm-user" data-id="${s.user.id}"` : ''}><td>${s.user ? html`<b>${s.user.name}</b><br><small>${s.user.email}</small>` : 'Deleted account'}</td>
          <td>${s.plan} ${s.interval ? html`<small>· ${s.interval}</small>` : ''}</td><td><span class="chip ${STATUS[s.status] || ''}">${s.status}${s.cancel_at ? ' · ends ' + fmt.date(s.cancel_at) : ''}</span>${s.note ? html`<br><small class="result-bad">${s.note}</small>` : ''}</td>
          <td class="hide-s">${s.provider === 'manual' ? 'offered' : '$' + s.amount / 100}</td><td class="hide-s nowrap">${s.period_end ? fmt.date(s.period_end) : '—'}</td><td class="hide-s"><small>${s.provider === 'manual' ? 'admin' : (s.provider === 'paypal' ? 'PayPal ' : 'Paddle ') + (s.env === 'production' ? 'live' : s.env)}</small></td></tr>`)}</tbody></table></section>`
        : SM.empty('zap', 'No subscription yet', 'Paid subscriptions appear here as soon as PayPal confirms them.')}
      <h2 class="section-title">${icon('history')} PayPal notifications</h2>
      ${d.events.length ? html`<section class="panel table-wrap"><table class="table"><thead><tr><th>When</th><th>Event</th><th>Result</th></tr></thead>
        <tbody>${d.events.map((e) => html`<tr><td class="nowrap"><small>${fmt.time(e.received)}</small></td><td>${e.type}<br><small class="muted">${e.event_id}</small></td>
          <td><span class="chip ${e.status === 'processed' ? 'chip-ok' : e.status === 'failed' || e.status === 'unmatched' ? 'chip-bad' : ''}">${e.status}</span>${e.error ? html`<br><small class="result-bad">${e.error}</small>` : ''}</td></tr>`)}</tbody></table></section>`
        : html`<p class="muted">No notification received yet.</p>`}
      <p class="muted small">Webhook address for your PayPal app: <code>${d.webhook_url}</code> — events <code>BILLING.SUBSCRIPTION.*</code>, <code>PAYMENT.SALE.COMPLETED</code>, <code>PAYMENT.SALE.REFUNDED</code>, <code>PAYMENT.SALE.REVERSED</code>. Put the webhook ID shown by PayPal in <code>STM_PAYPAL_WEBHOOK_ID</code>.</p>
    </div>`);
  });
  /** Crée chez PayPal le produit et les quatre formules ; affiche les lignes à recopier dans config.php. */
  SM.actions['adm-pp-setup'] = async (el) => {
    if (!(await SM.confirm({ title: 'Create the PayPal plans?', text: 'Studioma creates one product and four subscription plans in your PayPal account (the environment set in config.php). Do this once.', ok: 'Create plans' }))) return;
    SM.busy(el, true);
    const r = await SM.try(SM.post('admin_paypal_setup'));
    SM.busy(el, false);
    if (!r) return;
    const box = SM.$('#pp-setup');
    if (box) SM.set(box, html`<h2>${icon('check')} PayPal plans created (${r.env === 'production' ? 'live' : 'sandbox'})</h2>
      <p>Copy these four lines into <code>studioma/config.php</code> on the server, then reload this page.</p>
      <pre class="code-block">${r.config}</pre>
      <ul class="notice-list">${r.plans.map((p) => html`<li>${p.setting} — ${p.price}</li>`)}</ul>`);
  };

  /* ------------------------------------------------- offre de lancement */
  const REASON = { same_network: 'Several sign-ups from the same network', burst: 'Many verified sign-ups within an hour', self: 'Same person' };
  SM.route(/^\/promo$/, async (m, ctx) => {
    setTitle('Launch promotion');
    const days = +SM.query().get('days') || 0;
    const d = await SM.get('admin_promo', { days });
    if (!ctx.alive()) return;
    const M = d.metrics;
    const PER = [[0, 'All time'], [7, '7 days'], [30, '30 days'], [90, '90 days']];
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Launch Promotion Analytics</h1><p class="muted">30 days of full access and “Invite ${d.goal} friends, get 1 year free” — no card, no payment. Real data from the database.</p></div>
        <nav class="seg-inline">${PER.map(([n, l]) => html`<a class="btn btn-sm ${days === n ? 'btn-soft is-on' : 'btn-ghost'}" href="#/promo${n ? '?days=' + n : ''}">${l}</a>`)}</nav></header>
      ${!d.enabled ? html`<p class="notice notice-warn">${icon('alert')}<span>The launch offer is off (STM_LAUNCH_PROMO = false): no new trial or referral reward. Access already granted continues.</span></p>` : ''}
      <h2 class="section-title">${icon('sparkles')} 30-day trial</h2>
      <div class="tiles">
        ${tile('users', 'Eligible users', fmt.num(M.eligible), `can still start the trial · ${fmt.num(M.users)} accounts`)}
        ${tile('zap', 'Trial activations', fmt.num(M.trials), days ? 'in the period' : 'all time', M.trials ? 'tile-ok' : '')}
        ${tile('clock', 'Active trials', fmt.num(M.trials_active), 'right now')}
        ${tile('history', 'Expired trials', fmt.num(M.trials_ended), 'back on Free (or another access)')}
      </div>
      <h2 class="section-title">${icon('link')} Referrals</h2>
      <div class="tiles">
        ${tile('external', 'Link clicks', fmt.num(M.clicks), 'opened referral links')}
        ${tile('eye', 'Unique visitors', fmt.num(M.visitors), 'distinct browsers')}
        ${tile('user-plus', 'Referred registrations', fmt.num(M.registrations), `conversion ${M.conversion}% of visitors`)}
        ${tile('check', 'Verified eligible referrals', fmt.num(M.verified), `${M.verify_rate}% of registrations`, M.verified ? 'tile-ok' : '')}
        ${tile('users', 'Users with ≥ 1 verified', fmt.num(M.referrers_1), `${fmt.num(M.referrers_goal)} reached ${d.goal}`)}
        ${tile('alert', 'Suspicious activity', fmt.num(M.review), `${fmt.num(M.rejected)} not eligible`, M.review ? 'tile-bad' : '')}
      </div>
      <h2 class="section-title">${icon('award')} One-year rewards</h2>
      <div class="tiles">
        ${tile('award', 'Rewards granted', fmt.num(M.rewards), days ? 'in the period' : 'all time', M.rewards ? 'tile-ok' : '')}
        ${tile('check-circle', 'Active rewards', fmt.num(M.rewards_active), 'right now')}
        ${tile('calendar', 'Scheduled', fmt.num(M.rewards_scheduled), 'start after a trial')}
        ${tile('history', 'Expired rewards', fmt.num(M.rewards_ended), 'back on Free (or another access)')}
      </div>
      <h2 class="section-title">${icon('shield')} Referrals to review <span class="count">${d.review.length}</span></h2>
      ${d.review.length ? html`<section class="panel table-wrap"><table class="table"><thead><tr><th>Referrer</th><th>New account</th><th>Why</th><th class="hide-s">Registered</th><th></th></tr></thead>
        <tbody>${d.review.map((r) => html`<tr><td><button class="who-btn" data-act="adm-user" data-id="${r.referrer.id}"><b>${r.referrer.name}</b><br><small>${r.referrer.email} · ${r.referrer.verified} verified</small></button></td>
          <td><button class="who-btn" data-act="adm-user" data-id="${r.referred.id}"><b>${r.referred.name}</b><br><small>${r.referred.email}</small></button></td>
          <td><span class="chip chip-warn">${REASON[r.reason] || r.reason}</span></td><td class="hide-s nowrap"><small>${fmt.time(r.registered_at)}</small></td>
          <td class="nowrap"><button class="btn btn-sm btn-soft" data-act="adm-ref" data-id="${r.id}" data-decision="approve">Approve</button> <button class="btn btn-sm is-danger" data-act="adm-ref" data-id="${r.id}" data-decision="reject">Reject</button></td></tr>`)}</tbody></table></section>`
        : html`<p class="muted">${icon('check')} Nothing to review. Sign-ups from the same network or in bursts appear here before they count.</p>`}
      ${d.top.length ? html`<h2 class="section-title">${icon('chart')} Top referrers</h2><section class="panel list">${d.top.map((t) => html`<div class="list-row"><button class="who-btn grow" data-act="adm-user" data-id="${t.uid}"><b>${t.name}</b> <small>@${t.handle}</small></button><span class="chip ${t.verified >= d.goal ? 'chip-ok' : ''}">${t.verified} verified</span></div>`)}</section>` : ''}
      <p class="muted small">${icon('lock')} No payment is ever involved: the trial and the free year are access periods, never PayPal subscriptions. Referral clicks store a random browser identifier, not IP addresses.</p>
    </div>`);
  });
  SM.actions['adm-ref'] = async (el) => {
    const approve = el.dataset.decision === 'approve';
    if (!(await SM.confirm({ title: approve ? 'Approve this referral?' : 'Reject this referral?', text: approve ? 'It counts toward the referrer\'s free year (granted automatically at the goal).' : 'It will never count. The new account itself is not affected.', ok: approve ? 'Approve' : 'Reject', danger: !approve }))) return;
    SM.busy(el, true);
    const r = await SM.try(SM.post('admin_promo_review', { id: +el.dataset.id, decision: el.dataset.decision }));
    SM.busy(el, false);
    if (r) { SM.toast(approve ? 'Approved' : 'Rejected'); SM.navigate(); }
  };

  const pad2 = (n) => String(n).padStart(2, '0');
  /* ------------------------------------------------------- parcours du créateur */
  // Indicateurs, règles (XP, limites du jour, critères, seuils des niveaux, réalisations actives), activité à examiner,
  // et fiche d'un compte : grand livre, réalisations, preuves, retrait et restitution avec raison (lib/journey.php).
  SM.route(/^\/journey$/, async (m, ctx) => {
    setTitle('Creator Journey');
    const d = await SM.get('admin_journey');
    if (!ctx.alive()) return;
    const M = d.metrics;
    const R = d.rules;
    const maxLv = Math.max(1, ...d.level_distribution.map((l) => l.users));
    const maxXp = Math.max(1, ...d.xp_distribution.map((l) => l.n));
    const bars = (list, key, label, max) => html`<div class="jbars">${list.map((x) => html`<div class="jbar"><span class="small">${label(x)}</span><span class="progress"><i style="width:${Math.round((x[key] / max) * 100)}%"></i></span><b class="small">${fmt.num(x[key])}</b></div>`)}</div>`;
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Creator Journey</h1><p class="muted">XP, verified achievements, Creator levels and public passports — computed by the server from recorded activity. Rules version ${R.v}.</p></div></header>
      <div class="tiles">
        ${tile('users', 'Participants', fmt.num(M.participants), 'accounts with XP')}
        ${tile('zap', 'Earning progress', fmt.num(M.active), `active in 30 days · ${fmt.num(M.xp_30)} XP`)}
        ${tile('award', 'Achievements granted', fmt.num(M.achievements), `${fmt.num(M.self)} self-reported (not verified)`, M.achievements ? 'tile-ok' : '')}
        ${tile('flag', 'Completed projects', fmt.num(M.projects), 'marked completed after checks')}
        ${tile('shield', 'Client-verified', fmt.num(M.client), 'Client Approved achievements')}
        ${tile('share', 'Sharing rate', M.share_rate + '%', `${fmt.num(M.shared)} achievements shared or public`)}
        ${tile('globe', 'Creator Passports', fmt.num(M.passports), `${fmt.num(M.passport_views)} views · ${fmt.num(M.verify_views)} verification views`)}
        ${tile('alert', 'Revoked XP records', fmt.num(M.revoked), 'kept in the ledger', M.revoked ? 'tile-bad' : '')}
      </div>
      <div class="grid adm-2">
        <section class="panel pad"><h2>${icon('layers')} Progression by level</h2>${bars(d.level_distribution, 'users', (x) => pad2(x.n) + ' ' + x.name, maxLv)}</section>
        <section class="panel pad"><h2>${icon('chart')} XP distribution</h2>${bars(d.xp_distribution, 'n', (x) => x.label + ' XP', maxXp)}</section>
      </div>
      <h2 class="section-title">${icon('alert')} Activity to review <span class="count">${d.flags.length}</span></h2>
      ${d.flags.length ? html`<section class="panel list">${d.flags.map((f) => html`<div class="list-row">${who(f.user)}<span class="grow small">${f.reason}</span><button class="btn btn-sm btn-soft" data-act="adm-journey-user" data-id="${f.uid}">Inspect</button></div>`)}</section>`
        : html`<p class="muted">${icon('check')} Nothing unusual. Repeated daily limits, very fast gains and series of approvals from one client appear here — nothing is penalized automatically.</p>`}
      <section class="panel pad stack"><h2>${icon('search')} Inspect an account</h2>
        <form class="row row-wrap" data-form="adm-journey-find"><label class="field"><span>Account number</span><input class="input" name="id" type="number" min="1" required></label><button class="btn btn-primary">Open</button></form>
        <div id="jr-user"></div></section>
      <form class="panel pad stack" data-form="adm-journey-rules">
        <h2>${icon('settings')} Rules</h2>
        <p class="muted small">Changes apply to new events only: XP already granted keeps its value and rule version. Levels are recalculated.</p>
        <div class="table-wrap"><table class="table"><thead><tr><th>Event</th><th>XP</th><th>Paid per day (0 = no limit)</th></tr></thead><tbody>
          ${R.events.map((e) => html`<tr><td>${e.label}</td><td><input class="input input-s" type="number" min="0" max="1000" name="ev_${e.key}_xp" value="${e.xp}" aria-label="${e.label} XP"></td>
            <td><input class="input input-s" type="number" min="0" max="1000" name="ev_${e.key}_cap" value="${e.cap}" aria-label="${e.label} daily limit"></td></tr>`)}</tbody></table></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Criterion</th><th>Value</th></tr></thead><tbody>
          ${R.params.map((p) => html`<tr><td>${p.label} <small class="muted">(${p.min}–${p.max}, default ${p.default})</small></td><td><input class="input input-s" type="number" min="${p.min}" max="${p.max}" name="pa_${p.key}" value="${p.value}" aria-label="${p.label}"></td></tr>`)}</tbody></table></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Level</th><th>Requirements</th></tr></thead><tbody>
          ${R.levels.filter((l) => Object.keys(l.req).length).map((l) => html`<tr><td><b>${pad2(l.n)} ${l.name}</b></td><td class="row row-wrap">${Object.entries(l.req).map(([k, v]) => html`<label class="field"><span class="small">${k}</span><input class="input input-s" type="number" min="1" name="lv_${l.n}_${k}" value="${v}"></label>`)}</td></tr>`)}</tbody></table></div>
        <div class="table-wrap"><table class="table"><thead><tr><th>Achievement</th><th>Verification</th><th>Granted</th><th>Revoked</th><th>Active</th></tr></thead><tbody>
          ${d.achievements.map((a) => html`<tr><td><b>${a.title}</b></td><td>${a.verification}</td><td>${fmt.num(a.granted)}</td><td>${fmt.num(a.revoked)}</td>
            <td><label class="check"><input type="checkbox" name="on_${a.aid}" ${a.active ? 'checked' : ''}><span>Active</span></label></td></tr>`)}</tbody></table></div>
        <div class="row"><button class="btn btn-primary">Save the rules</button></div>
      </form>
      ${d.log.length ? html`<h2 class="section-title">${icon('history')} Decisions</h2><section class="panel list">${d.log.map((l) => html`<div class="list-row"><span class="grow small"><b>${l.action}</b> ${l.target} — ${l.text}</span><small class="muted nowrap">${fmt.time(l.created)}</small></div>`)}</section>` : ''}
    </div>`);
    SM.journeyRules = R;
    SM.journeyAch = d.achievements;
  });
  SM.forms['adm-journey-rules'] = async (form, data) => {
    const R = SM.journeyRules;
    const body = { events: {}, params: {}, levels: {}, off: [] };
    R.events.forEach((e) => { body.events[e.key] = { xp: +data['ev_' + e.key + '_xp'], cap: +data['ev_' + e.key + '_cap'] }; });
    R.params.forEach((p) => { body.params[p.key] = +data['pa_' + p.key]; });
    R.levels.forEach((l) => Object.keys(l.req).forEach((k) => { (body.levels[l.n] = body.levels[l.n] || {})[k] = +data['lv_' + l.n + '_' + k]; }));
    SM.journeyAch.forEach((a) => { if (!data['on_' + a.aid]) body.off.push(a.aid); });
    await SM.post('admin_journey_rules', body);
    SM.toast('Rules saved');
    SM.navigate();
  };
  SM.forms['adm-journey-find'] = (form, data) => journeyUser(+data.id);
  SM.actions['adm-journey-user'] = (el) => { const f = SM.$('[data-form="adm-journey-find"] input'); if (f) f.value = el.dataset.id; journeyUser(+el.dataset.id); };
  async function journeyUser(id, preloaded) {
    const box = SM.$('#jr-user');
    if (!box) return;
    SM.set(box, SM.spinner());
    let d = preloaded;
    if (!d) { try { d = await SM.get('admin_journey_user', { id }); } catch (e) { SM.set(box, html`<p class="form-error">${e.message}</p>`); return; } }
    SM.set(box, html`<div class="stack">
      <div class="row">${who(d.user, d.user.email)}<span class="chip chip-soft">Level ${pad2(d.level.n)} — ${d.level.name}</span><span class="chip">${fmt.num(d.level.xp)} XP</span></div>
      <h3>Achievements</h3>
      ${d.achievements.length ? html`<div class="list">${d.achievements.map((a) => html`<div class="list-row"><span class="grow"><b>${a.title}</b> <span class="chip ${a.status === 'revoked' ? 'chip-bad' : a.status === 'self' ? 'chip-warn' : 'chip-ok'}">${a.label}</span>
          <small class="muted">${fmt.date(a.earned)} · ${a.visibility}${a.revoked_reason ? ' · revoked: ' + a.revoked_reason : ''}</small>
          ${a.evidence.map((e) => html`<small>${e.label} · ${fmt.date(e.at)}${e.project ? ' · ' + e.project : ''}${e.card ? ' › ' + e.card : ''}${e.client ? ' · ' + e.client : ''}</small>`)}</span>
          <button class="btn btn-sm ${a.status === 'revoked' ? 'btn-soft' : 'is-danger'}" data-act="adm-journey-revoke" data-type="achievement" data-id="${a.id}" data-restore="${a.status === 'revoked' ? 1 : ''}">${a.status === 'revoked' ? 'Restore' : 'Revoke'}</button></div>`)}</div>` : html`<p class="muted small">No achievement yet.</p>`}
      <h3>XP ledger <small class="muted">(last ${d.ledger.length})</small></h3>
      <div class="table-wrap"><table class="table"><thead><tr><th>When</th><th>Event</th><th>Key</th><th>XP</th><th>Note</th><th></th></tr></thead><tbody>
        ${d.ledger.map((x) => html`<tr class="${x.revoked > 0 ? 'is-muted' : ''}"><td class="nowrap"><small>${fmt.time(x.at)}</small></td><td>${x.kind}</td><td><small class="mono">${x.ikey}</small></td>
          <td>${x.revoked > 0 ? html`<s>${x.xp}</s>` : x.xp}</td><td><small>${x.revoked > 0 ? 'revoked: ' + x.revoked_reason : x.note}${x.rule_v > 1 ? ' · rules v' + x.rule_v : ''}</small></td>
          <td><button class="btn btn-sm ${x.revoked > 0 ? 'btn-soft' : 'btn-ghost'}" data-act="adm-journey-revoke" data-type="xp" data-id="${x.id}" data-restore="${x.revoked > 0 ? 1 : ''}">${x.revoked > 0 ? 'Restore' : 'Revoke'}</button></td></tr>`)}</tbody></table></div>
    </div>`);
  }
  SM.actions['adm-journey-revoke'] = async (el) => {
    const restore = !!el.dataset.restore;
    let reason = '';
    if (!restore) {
      reason = await SM.prompt({ title: el.dataset.type === 'xp' ? 'Revoke this XP record' : 'Revoke this achievement', label: 'Reason (kept in the audit log and shown to the member)', max: 300, ok: 'Revoke' });
      if (!reason) return;
    }
    const r = await SM.try(SM.post('admin_journey_revoke', { type: el.dataset.type, id: +el.dataset.id, reason, restore }));
    if (r) { SM.toast(restore ? 'Restored' : 'Revoked'); journeyUser(r.user.id, r); }
  };

  /* ---------------------------------------------------------------- système */
  SM.route(/^\/system$/, async (m, ctx) => {
    setTitle('System');
    const page = +SM.query().get('page') || 0;
    const d = await SM.get('admin_system', { page });
    if (!ctx.alive()) return;
    const s = d.system, i = d.integrations;
    const yes = (ok, txt) => html`<b class="${ok ? 'result-ok' : 'result-bad'}">${txt || (ok ? 'Yes' : 'No')}</b>`;
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>System</h1><p class="muted">Server, database, integrations and everything done in this administration.</p></div></header>
      <div class="grid adm-2">
        <section class="panel pad"><h2>${icon('monitor')} Server</h2><div class="kv">
          <div><span>PHP</span><b>${s.php}</b></div><div><span>SQLite</span><b>${s.sqlite}</b></div><div><span>Database schema</span><b>v${s.schema}</b></div>
          <div><span>Database size</span><b>${bytes(s.db_bytes)}</b></div><div><span>Uploaded files</span><b>${bytes(s.files_bytes)}</b></div><div><span>Free disk</span><b>${s.disk_free ? bytes(s.disk_free) : '—'}</b></div>
          <div><span>Upload limit</span><b>${s.upload_mb} MB</b></div><div><span>Time zone</span><b>${s.timezone}</b></div><div><span>Server time</span><b>${s.time}</b></div></div>
          <p class="muted small">${s.site}</p></section>
        <section class="panel pad"><h2>${icon('zap')} Integrations</h2><div class="kv">
          <div><span>Google sign-in</span>${yes(i.google, i.google ? 'On' : 'Off')}</div>
          <div><span>Meta pixel</span><b>${i.meta_pixel || '—'}</b></div>
          <div><span>Conversions API token</span>${yes(i.meta_token, i.meta_token ? 'Installed' : 'Missing')}</div>
          <div><span>Emails</span><b>${TRANSPORT[i.mail] || i.mail}</b></div>
          <div><span>AI connections</span><b>${fmt.num(i.mcp_grants)}</b></div>
          <div><span>Cron job</span>${yes(!!i.cron_last, i.cron_last ? fmt.ago(i.cron_last) : 'Not set up')}</div></div>
          ${i.meta_capi_ok ? html`<p class="small"><span class="result-ok">Last Conversions API success:</span> ${i.meta_capi_ok}</p>` : ''}
          ${i.meta_capi_error ? html`<p class="small"><span class="result-bad">Last Conversions API error:</span> ${i.meta_capi_error}</p>` : ''}</section>
      </div>
      <h2 class="section-title">${icon('history')} Administration log</h2>
      ${d.log.length ? html`<section class="panel table-wrap"><table class="table"><thead><tr><th>When</th><th>Action</th><th class="hide-s">Target</th><th>Details</th></tr></thead>
        <tbody>${d.log.map((l) => html`<tr><td class="nowrap"><small>${fmt.time(l.created)}</small></td><td class="nowrap"><b>${l.action.replace(/_/g, ' ')}</b></td>
          <td class="hide-s">${/^user:\d+$/.test(l.target) ? html`<a href="#" data-act="adm-user" data-id="${l.target.slice(5)}">${l.target}</a>` : l.target}</td><td class="wrap">${l.text}</td></tr>`)}</tbody></table></section>
        ${pager(d.page, d.more, (p) => '#/system?page=' + p)}` : SM.empty('history', 'Nothing yet', 'Suspensions, deletions, campaigns and exports will be listed here.')}
    </div>`);
  });

  boot();
})();
