/*
 * Studioma — l'application : démarrage, menu principal, connexion et inscription, premiers pas,
 * notifications, réglages, demandes d'autorisation. Les écrans du Studio sont dans studio.js et
 * card.js, ceux du réseau dans social.js et talent.js.
 */
(function () {
  'use strict';
  const { html, raw, icon } = SM;

  /* --------------------------------------------------------------- session */
  SM.setMe = (r) => {
    SM.me = r.user || null;
    SM.companies = r.companies || [];
    SM.counts = r.counts || {};
    if (r.csrf) SM.csrf = r.csrf;
    if (SM.me) SM.applyTheme(SM.me.settings.theme);
    if (window.StmMeta && SM.ref) window.StmMeta.setup(SM.ref.meta_pixel, SM.me);
  };
  SM.adminCompanies = () => SM.companies.filter((c) => c.admin);
  SM.onAuthLost = () => { SM.me = null; SM.csrf = ''; SM.closeModals(); shell(); SM.go('/login'); };

  const NAV = [
    { key: 'home', path: '/', label: 'Home', icon: 'home' },
    { key: 'discover', path: '/discover', label: 'Discover', icon: 'compass' },
    { key: 'network', path: '/network', label: 'My Network', icon: 'users' },
    { sep: 'Workspace' },
    { key: 'projects', path: '/projects', label: 'Projects', icon: 'folder' },
    { key: 'studios', path: '/studios', label: 'Studios', icon: 'board' },
    { key: 'opportunities', path: '/opportunities', label: 'Opportunities', icon: 'briefcase' },
    { sep: 'You' },
    { key: 'messages', path: '/messages', label: 'Messages', icon: 'message', count: 'messages' },
    { key: 'notifications', path: '/notifications', label: 'Notifications', icon: 'bell', count: 'notifs' },
    { key: 'profile', path: '/profile', label: 'Profile', icon: 'user' },
    { key: 'settings', path: '/settings', label: 'Settings', icon: 'settings' },
  ];
  const COMPANY_NAV = [
    { sep: 'Company' },
    { key: 'talent', path: '/talent', label: 'Talent', icon: 'award' },
    { key: 'team', path: '/team', label: 'Team', icon: 'building' },
  ];
  /** Rubrique du menu à laquelle appartient une adresse. */
  const sectionOf = (path) => {
    const seg = path.split('/')[1] || '';
    return ({ '': 'home', post: 'home', discover: 'discover', network: 'network', projects: 'projects', project: 'projects', studios: 'studios', studio: 'studios', work: 'studios',
      opportunities: 'opportunities', opportunity: 'opportunities', messages: 'messages', notifications: 'notifications', requests: 'notifications', settings: 'settings',
      talent: 'talent', team: 'team', c: 'team', profile: 'profile' })[seg] || (seg === 'u' && SM.me && path === '/u/' + SM.me.handle ? 'profile' : '');
  };
  const badge = (n) => (n > 0 ? html`<span class="nav-badge">${n > 99 ? '99+' : n}</span>` : '');
  const navLink = (n) => (n.sep
    ? html`<div class="nav-sep">${n.sep}</div>`
    : html`<a class="nav-link" href="#${n.path}" data-nav="${n.key}">${icon(n.icon)}<span>${n.label}</span>${n.count ? html`<span data-count="${n.count}">${badge(SM.counts[n.count])}</span>` : ''}</a>`);

  /** Dessine le cadre de l'application selon que la personne est connectée ou non. */
  function shell() {
    const app = SM.$('#app');
    if (!SM.me) {
      SM.set(app, html`<div class="public">
        <header class="public-top"><a href="${SM.base}" class="logo-link">${SM.logo()}</a><span class="grow"></span>
          <a class="btn btn-ghost" href="#/login">Sign in</a><a class="btn btn-primary" href="#/signup">Join Studioma</a></header>
        <main id="view" class="view"></main>
      </div>`);
      return;
    }
    const items = NAV.concat(SM.adminCompanies().length ? COMPANY_NAV : []);
    SM.set(app, html`<div class="app">
      <aside class="side" id="side">
        <a href="#/" class="logo-link">${SM.logo()}</a>
        <nav class="nav" aria-label="Main">${items.map(navLink)}</nav>
        <button class="side-user" data-act="user-menu">${SM.avatar(SM.me, 'av-m')}<span class="side-user-name"><b>${SM.me.name}</b><small>@${SM.me.handle}</small></span>${icon('chevron-down')}</button>
      </aside>
      <div class="side-veil" data-act="side-close"></div>
      <div class="main">
        <header class="top">
          <button class="btn-icon top-menu" data-act="side-open" aria-label="Menu">${icon('menu')}</button>
          <form class="top-search" data-form="search" role="search">${icon('search')}<input name="q" class="input" placeholder="Search people, companies, posts…" aria-label="Search" autocomplete="off"></form>
          <span class="grow"></span>
          <button class="btn btn-primary top-create" data-act="create-menu">${icon('plus')}<span>Create</span></button>
          <a class="btn-icon top-ico" href="#/messages" aria-label="Messages" title="Messages">${icon('message')}<span data-count="messages">${badge(SM.counts.messages)}</span></a>
          <a class="btn-icon top-ico" href="#/notifications" aria-label="Notifications" title="Notifications">${icon('bell')}<span data-count="notifs">${badge(SM.counts.notifs)}</span></a>
          <button class="top-avatar" data-act="user-menu" aria-label="Account">${SM.avatar(SM.me, 'av-m')}</button>
        </header>
        <main id="view" class="view"></main>
      </div>
      <nav class="tabbar" aria-label="Quick navigation">
        <a href="#/" data-nav="home">${icon('home')}<span>Home</span></a>
        <a href="#/discover" data-nav="discover">${icon('compass')}<span>Discover</span></a>
        <a href="#/studios" data-nav="studios">${icon('board')}<span>Studios</span></a>
        <a href="#/notifications" data-nav="notifications">${icon('bell')}<span>Alerts</span><span data-count="notifs">${badge(SM.counts.notifs)}</span></a>
        <a href="#/profile" data-nav="profile">${icon('user')}<span>Profile</span></a>
      </nav>
    </div>`);
  }
  SM.shell = shell;
  SM.refreshCounts = (counts) => {
    if (counts) SM.counts = counts;
    SM.$$('[data-count]').forEach((el) => SM.set(el, badge(SM.counts[el.dataset.count])));
  };
  SM.afterRoute = (path) => {
    if (window.StmMeta) window.StmMeta.page();   // PageView (jamais sur un écran à clé secrète)
    const key = sectionOf(path);
    SM.$$('[data-nav]').forEach((a) => a.classList.toggle('is-active', a.dataset.nav === key));
    document.body.classList.remove('side-open');
  };
  SM.actions['side-open'] = () => document.body.classList.add('side-open');
  SM.actions['side-close'] = () => document.body.classList.remove('side-open');
  SM.actions['user-menu'] = (el) => {
    const theme = SM.ls.get('theme', 'auto');
    const setTheme = (t) => () => { SM.applyTheme(t); SM.post('account_save', { settings: { theme: t } }).then((r) => { SM.me.settings = r.user.settings; }).catch(() => {}); };
    SM.menu(el, [
      { label: 'View my profile', icon: 'user', href: '#/profile' },
      { label: 'Settings', icon: 'settings', href: '#/settings' },
      { label: 'AI assistants', icon: 'sparkles', href: '#/settings/ai' },
      { label: 'Publication requests', icon: 'shield', href: '#/requests' },
      { sep: true }, { head: 'Appearance' },
      { label: 'Light', icon: 'sun', checked: theme === 'light', act: setTheme('light') },
      { label: 'Dark', icon: 'moon', checked: theme === 'dark', act: setTheme('dark') },
      { label: 'Match my device', icon: 'monitor', checked: theme === 'auto', act: setTheme('auto') },
      { sep: true },
      { label: 'Sign out', icon: 'logout', act: async () => { await SM.try(SM.post('logout')); SM.me = null; SM.csrf = ''; shell(); SM.go('/login'); } },
    ], { align: 'right', width: 230 });
  };
  SM.actions['create-menu'] = (el) => {
    const items = [
      { label: 'Post', hint: 'Share an update with your network', icon: 'edit', act: () => SM.composePost() },
      { label: 'Project', hint: 'A brand, a client or a campaign', icon: 'folder', act: () => SM.projectDialog() },
      { label: 'Studio', hint: 'A workspace inside a project', icon: 'board', act: () => SM.studioDialog() },
      { label: 'Case study', hint: 'Showcase your work on your profile', icon: 'award', act: () => SM.portfolioDialog() },
    ];
    if (SM.adminCompanies().length) items.push({ label: 'Opportunity', hint: 'Find talent for your company', icon: 'briefcase', act: () => SM.oppDialog() });
    SM.menu(el, items, { align: 'right', width: 290, cls: 'menu-rich' });
  };
  SM.forms.search = (form, data) => { if (data.q.trim()) SM.go('/discover?q=' + encodeURIComponent(data.q.trim())); };

  /* ------------------------------------------------------------- démarrage */
  const PUBLIC_ONLY = /^\/(login|signup|forgot|reset\/)/;
  SM.beforeRoute = (path, opts) => {
    if (!SM.me && !opts.public) {
      if (path !== '/') sessionStorage.setItem('stm.next', SM.path());
      SM.go('/login', true);
      return false;
    }
    if (SM.me && PUBLIC_ONLY.test(path + '/')) { SM.go('/', true); return false; }
    return true;
  };
  const afterAuth = (r, fallback) => {
    SM.setMe(r);
    shell();
    const next = sessionStorage.getItem('stm.next');
    sessionStorage.removeItem('stm.next');
    SM.go(next || fallback || '/');
  };
  SM.boot = async () => {
    try {
      const r = await SM.get('me', { ref: 1 });
      SM.ref = r.ref;
      SM.setMe(r);
    } catch (e) {
      SM.set(SM.$('#app'), SM.empty('alert', 'Studioma is unavailable', e.message, html`<button class="btn btn-primary" data-act="boot">Try again</button>`));
      return;
    }
    shell();
    SM.navigate();
    setInterval(() => { if (SM.me && !document.hidden) SM.get('counts').then((r) => SM.refreshCounts(r.counts)).catch(() => {}); }, 30000);
  };
  SM.actions.boot = () => SM.boot();

  /* --------------------------------------------- Continuer avec Google */
  /**
   * Bouton « Continue with Google » (Google Identity Services). Google affiche son bouton dans une
   * petite fenêtre et nous rend un jeton d'identité signé, vérifié ensuite par le serveur.
   * Sans ID client configuré, ou si Google ne répond pas, le bouton n'apparaît pas.
   */
  const G = SM.google = { loading: null, ready: false, cb: null };
  G.enabled = () => !!(SM.ref && SM.ref.google_client_id);
  G.load = () => {
    if (window.google && google.accounts && google.accounts.id) return Promise.resolve();
    if (!G.loading) {
      G.loading = new Promise((ok, ko) => {
        const sc = document.createElement('script');
        sc.src = 'https://accounts.google.com/gsi/client';
        sc.async = true;
        sc.onload = ok;
        sc.onerror = () => { G.loading = null; ko(new Error('gsi')); };
        document.head.appendChild(sc);
      });
    }
    return G.loading;
  };
  /** Dessine le bouton dans le bloc [data-google] de la page ; onCredential(jeton) après le choix du compte. */
  G.mount = async (text, onCredential) => {
    const block = SM.$('[data-google]');
    if (!block || !G.enabled()) return;
    block.hidden = false;
    try { await G.load(); } catch (e) { block.hidden = true; return; }
    const box = SM.$('.google-btn', block);
    if (!box || !document.contains(box)) return;
    G.cb = onCredential;
    if (!G.ready) {
      google.accounts.id.initialize({ client_id: SM.ref.google_client_id, callback: (r) => { if (G.cb) G.cb(r.credential); }, ux_mode: 'popup', auto_select: false, cancel_on_tap_outside: true });
      G.ready = true;
    }
    box.replaceChildren();
    google.accounts.id.renderButton(box, { type: 'standard', theme: document.documentElement.dataset.theme === 'dark' ? 'filled_black' : 'outline', size: 'large', shape: 'pill',
      text, logo_alignment: 'center', locale: 'en', width: Math.min(400, Math.max(220, Math.round(box.getBoundingClientRect().width || 320))) });
  };
  const googleBlock = (note) => html`<div class="google-block" data-google hidden><div class="google-btn"></div>${note ? html`<p class="muted small center">${note}</p>` : ''}<div class="or"><span>or with your email</span></div></div>`;
  /** Connexion ou inscription avec le jeton reçu de Google. */
  const googleSignIn = async (credential, extra, form) => {
    const err = form && SM.$('[data-error]', form);
    if (err) err.hidden = true;
    try {
      const r = await SM.post('google', Object.assign({ credential }, extra || {}));
      afterAuth(r, r.created ? '/welcome' : '/');
      if (r.password_reset) SM.toast('Your account is now linked to Google. For your security, its old password was removed — choose a new one in Settings if you want one.');
      else if (!r.created) SM.toast('Signed in with Google');
    } catch (e) {
      if (err) { err.textContent = e.message; err.hidden = false; } else SM.toast(e.message, 'error');
    }
  };

  /* ------------------------------------------------- connexion, inscription */
  const authCard = (title, sub, body) => html`<div class="auth"><div class="auth-card panel">
      <a href="${SM.base}" class="logo-link">${SM.logo('logo-l')}</a>
      <h1>${title}</h1><p class="muted">${sub}</p>${body}
    </div><p class="auth-tag">Create together. Grow professionally. Get discovered.</p></div>`;
  const field = (label, name, type, extra) => html`<label class="field"><span>${label}</span><input class="input" name="${name}" type="${type || 'text'}" ${raw(extra || '')}></label>`;

  SM.route(/^\/login$/, (m, ctx) => {
    SM.set(ctx.view, authCard('Welcome back', 'Sign in to your workspace.', html`
      ${googleBlock('New here? Continuing with Google creates your account.')}
      <form data-form="login" class="stack">
        ${field('Email', 'email', 'email', 'autocomplete="email" required autofocus')}
        ${field('Password', 'password', 'password', 'autocomplete="current-password" required')}
        <p class="form-error" data-error hidden></p>
        <button class="btn btn-primary btn-block">Sign in</button>
      </form>
      <p class="auth-alt"><a href="#/forgot">Forgot your password?</a></p>
      <p class="auth-alt">New to Studioma? <a href="#/signup">Create your account</a></p>`));
    G.mount('continue_with', (credential) => googleSignIn(credential, {}, SM.$('form[data-form="login"]')));
  }, { public: true });
  SM.forms.login = async (form, data) => afterAuth(await SM.post('login', data));

  SM.route(/^\/signup$/, (m, ctx) => {
    const kind = SM.query().get('as') === 'company' ? 'company' : 'individual';
    SM.set(ctx.view, authCard('Create your account', 'Free, in less than a minute.', html`
      <form data-form="signup" class="stack">
        <div class="seg" role="radiogroup" aria-label="Account type">
          <label><input type="radio" name="kind" value="individual" ${kind === 'individual' ? 'checked' : ''} data-change="signup-kind"><span>${icon('user')}<b>Individual</b><small>Community manager, creator, freelancer</small></span></label>
          <label><input type="radio" name="kind" value="company" ${kind === 'company' ? 'checked' : ''} data-change="signup-kind"><span>${icon('building')}<b>Company</b><small>Brand, agency, organization</small></span></label>
        </div>
        <div data-company ${kind === 'company' ? '' : 'hidden'}>${field('Company name', 'company', 'text', 'maxlength="80" autocomplete="organization" data-input="signup-lock"')}</div>
        <label class="check terms" data-terms><input type="checkbox" name="accept" data-change="signup-lock"><span>I agree to use Studioma respectfully and to share only content I am allowed to share.</span></label>
        <p class="terms-hint" data-terms-hint role="alert" hidden></p>
        <div class="google-block is-locked" data-google hidden>
          <div class="google-wrap"><div class="google-btn"></div>
            <button type="button" class="google-gate" data-act="signup-gate" aria-label="Sign up with Google"></button></div>
          <p class="google-lock" data-google-lock>${icon('lock')}<span></span></p>
          <div class="or"><span>or with your email</span></div>
        </div>
        ${field('Your name', 'name', 'text', 'maxlength="60" autocomplete="name" required')}
        ${field('Email', 'email', 'email', 'autocomplete="email" required')}
        ${field('Password', 'password', 'password', 'autocomplete="new-password" minlength="8" required placeholder="At least 8 characters"')}
        <p class="form-error" data-error hidden></p>
        <button class="btn btn-primary btn-block">Create my account</button>
      </form>
      <p class="auth-alt">Already have an account? <a href="#/login">Sign in</a></p>`));
    signupLock(SM.$('form[data-form="signup"]'));
    G.mount('signup_with', (credential) => {
      const form = SM.$('form[data-form="signup"]');
      const d = SM.formData(form);
      // Filet de sécurité (le bouton reste verrouillé tant qu'il manque quelque chose).
      if (signupMissing(d)) { signupNudge(form); return; }
      return googleSignIn(credential, { kind: d.kind, company: d.company }, form);
    });
  }, { public: true });
  /*
   * Inscription : le bouton Google (dans un cadre de Google, qu'on ne peut pas intercepter) est couvert d'un
   * verrou tant que les conditions ne sont pas acceptées (et le nom de l'entreprise saisi). Un clic sur le verrou
   * fait trembler et surligne ce qui manque, au lieu d'ouvrir la fenêtre de Google pour rien.
   */
  const signupMissing = (d) => (d.kind === 'company' && (d.company || '').trim().length < 2 ? 'company' : !d.accept ? 'accept' : '');
  function signupLock(form) {
    if (!form) return;
    const d = SM.formData(form);
    const missing = signupMissing(d);
    const block = SM.$('[data-google]', form);
    if (block) {
      block.classList.toggle('is-locked', !!missing);
      const btn = SM.$('.google-btn', block);
      if (btn) btn.inert = !!missing;   // ni clic ni tabulation vers le bouton de Google tant qu'il est verrouillé
      SM.$('.google-gate', block).hidden = !missing;
      const lock = SM.$('[data-google-lock]', block);
      lock.hidden = !missing;
      lock.lastElementChild.textContent = missing === 'company' ? 'Enter your company name and tick the box above to sign up with Google' : 'Tick the box above to sign up with Google';
    }
    // Ce qui vient d'être corrigé n'est plus signalé.
    if (d.accept) SM.$('[data-terms]', form).classList.remove('is-attention');
    const company = SM.$('[data-company] .field', form);
    if (company && missing !== 'company') company.classList.remove('is-attention');
    const hint = SM.$('[data-terms-hint]', form);
    if (hint && missing !== hint.dataset.for) hint.hidden = true;
  }
  /** Montre ce qui manque : le champ tremble, se colore, reçoit le focus, et un message dit quoi faire. */
  function signupNudge(form) {
    const missing = signupMissing(SM.formData(form));
    const target = missing === 'company' ? SM.$('[data-company] .field', form) : SM.$('[data-terms]', form);
    if (!target) return;
    const hint = SM.$('[data-terms-hint]', form);
    hint.textContent = missing === 'company' ? 'Enter the name of your company first.' : 'Tick this box to accept the terms — then continue with Google or your email.';
    hint.dataset.for = missing;
    hint.hidden = false;
    target.after(hint);
    target.classList.remove('is-nudge');
    void target.offsetWidth;   // relance l'animation à chaque clic
    target.classList.add('is-nudge', 'is-attention');
    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
    const input = SM.$(missing === 'company' ? 'input[name=company]' : 'input[name=accept]', form);
    if (input) input.focus({ preventScroll: true });
  }
  SM.actions['signup-gate'] = (el) => signupNudge(el.closest('form'));
  SM.changes['signup-lock'] = (el) => signupLock(el.form);
  SM.inputs['signup-lock'] = (el) => signupLock(el.form);
  SM.changes['signup-kind'] = (el) => { SM.$('[data-company]', el.form).hidden = el.value !== 'company'; signupLock(el.form); };
  SM.forms.signup = async (form, data) => {
    if (signupMissing(data)) { signupNudge(form); return; }
    afterAuth(await SM.post('signup', data), '/welcome');
  };

  SM.route(/^\/forgot$/, (m, ctx) => {
    SM.set(ctx.view, authCard('Reset your password', 'We will email you a link to choose a new one.', html`
      <form data-form="forgot" class="stack">${field('Email', 'email', 'email', 'autocomplete="email" required autofocus')}
        <p class="form-error" data-error hidden></p><button class="btn btn-primary btn-block">Send the link</button></form>
      <p class="auth-alt"><a href="#/login">Back to sign in</a></p>`));
  }, { public: true });
  SM.forms.forgot = async (form, data) => {
    await SM.post('forgot', data);
    SM.set(form, html`<div class="notice notice-ok">${icon('mail')}<span>If an account exists for <b>${data.email}</b>, the link is on its way. It is valid for one hour.</span></div>`);
  };
  SM.route(/^\/reset\/([a-f0-9]{48})$/, (m, ctx) => {
    SM.set(ctx.view, authCard('Choose a new password', 'You will be signed in right after.', html`
      <form data-form="reset" class="stack"><input type="hidden" name="token" value="${m[1]}">
        ${field('New password', 'password', 'password', 'autocomplete="new-password" minlength="8" required autofocus placeholder="At least 8 characters"')}
        <p class="form-error" data-error hidden></p><button class="btn btn-primary btn-block">Save and sign in</button></form>`));
  }, { public: true });
  SM.forms.reset = async (form, data) => afterAuth(await SM.post('reset', data));

  /* ------------------------------------------------ confirmation de l'adresse */
  /**
   * Lien reçu par email : il faut être connecté au compte qui l'a demandé (sinon la personne dont on
   * aurait pris l'adresse confirmerait le compte d'un autre en cliquant).
   */
  SM.route(/^\/confirm\/([a-f0-9]{48})$/, async (m, ctx) => {
    if (!SM.me) {
      sessionStorage.setItem('stm.next', '/confirm/' + m[1]);
      SM.set(ctx.view, authCard('Confirm your email', 'Sign in to the account that uses this address to confirm it.', html`
        <a class="btn btn-primary btn-block" href="#/login">Sign in to confirm</a>
        <p class="auth-alt">Didn't create a Studioma account? Ignore the email: the address stays unconfirmed. If someone created one with your address, take it back with <a href="#/forgot">Forgot your password?</a></p>`));
      return;
    }
    let r = null;
    let error = '';
    try { r = await SM.post('email_confirm', { token: m[1] }); } catch (e) { error = e.message; }
    if (!ctx.alive()) return;
    if (r) { SM.setMe(r); SM.refreshCounts(); }
    SM.set(ctx.view, authCard(r ? 'Email confirmed' : 'Link not valid', r ? 'You\'re all set.' : 'This confirmation link can\'t be used.', r
      ? html`<div class="notice notice-ok" data-email-confirmed>${icon('check')}<span><b>${SM.me.email}</b> is confirmed. Invitations to projects and Studios sent to this address now appear in your workspace.</span></div>
        <a class="btn btn-primary btn-block" href="#/projects">Open my projects</a>`
      : html`<div class="notice notice-warn">${icon('alert')}<span>${error}</span></div>
        <a class="btn btn-primary btn-block" href="#/settings">Go to Settings</a>`));
  }, { public: true });

  /* ------------------------------------------------------------ invitation */
  SM.route(/^\/join\/([a-f0-9]{32})$/, async (m, ctx) => {
    const token = m[1];
    const inv = await SM.get('invite_info', { token });
    if (!ctx.alive()) return;
    const where = inv.studio ? html`the Studio <b>${inv.studio.name}</b> in <b>${inv.project ? inv.project.name : ''}</b>` : html`the project <b>${inv.project ? inv.project.name : ''}</b>`;
    let body;
    if (!inv.valid) {
      body = html`<div class="notice notice-warn">${icon('alert')}<span>This invitation ${inv.status === 'expired' ? 'has expired' : inv.status === 'accepted' ? 'was already used' : 'is no longer valid'}. Ask ${inv.by ? inv.by.name : 'the team'} for a new one.</span></div><a class="btn btn-primary btn-block" href="#/">Open Studioma</a>`;
    } else if (!SM.me) {
      sessionStorage.setItem('stm.next', '/join/' + token);
      body = html`<a class="btn btn-primary btn-block" href="#/signup">Create my account to join</a><a class="btn btn-soft btn-block" href="#/login">I already have an account</a>`;
    } else if (inv.member) {
      body = html`<div class="notice notice-ok">${icon('check')}<span>You are already a member.</span></div><a class="btn btn-primary btn-block" href="#${inv.studio ? '/studio/' + inv.studio.id : '/project/' + inv.project.id}">Open it</a>`;
    } else {
      body = html`<button class="btn btn-primary btn-block" data-act="invite-accept" data-token="${token}">Accept and join</button>
        ${inv.mine ? html`<button class="btn btn-ghost btn-block" data-act="invite-decline" data-token="${token}">Decline</button>` : ''}`;
    }
    SM.set(ctx.view, html`<div class="auth"><div class="auth-card panel">
      <div class="invite-who">${SM.avatar(inv.by, 'av-l')}</div>
      <h1>You're invited</h1>
      <p class="muted"><b>${inv.by ? inv.by.name : 'Someone'}</b> invites you to join ${where} as <b>${inv.role_label}</b>.</p>
      ${inv.message ? html`<blockquote class="invite-msg">${inv.message}</blockquote>` : ''}
      <div class="stack">${body}</div>
    </div></div>`);
  }, { public: true });
  SM.actions['invite-accept'] = async (el) => {
    SM.busy(el, true);
    const r = await SM.try(SM.post('invite_accept', { token: el.dataset.token }));
    SM.busy(el, false);
    if (!r) return;
    SM.toast('Welcome aboard!');
    SM.get('counts').then((c) => SM.refreshCounts(c.counts)).catch(() => {});
    SM.go(r.studio_id ? '/studio/' + r.studio_id : '/project/' + r.project_id);
  };
  SM.actions['invite-decline'] = async (el) => {
    await SM.try(SM.post('invite_decline', { token: el.dataset.token }));
    SM.get('counts').then((c) => SM.refreshCounts(c.counts)).catch(() => {});
    if (SM.path().startsWith('/join/')) SM.go('/projects'); else SM.navigate();
  };

  /* ---------------------------------------------------------- premiers pas */
  SM.toggles = (name, options, selected) => html`<div class="toggles">${Object.entries(options).map(([k, label]) => html`<label class="toggle"><input type="checkbox" name="${name}[]" value="${k}" ${(selected || []).includes(k) ? 'checked' : ''}><span>${label}</span></label>`)}</div>`;
  SM.route(/^\/welcome$/, async (m, ctx) => {
    const company = SM.me.kind === 'company' && SM.adminCompanies()[0];
    const step1 = company
      ? html`<form data-form="welcome-company" class="stack"><input type="hidden" name="id" value="${company.id}"><input type="hidden" name="name" value="${company.name}">
          <label class="field"><span>What does ${company.name} do?</span><input class="input" name="tagline" maxlength="140" placeholder="e.g. Handmade home décor, shipped everywhere" autofocus></label>
          <div class="field-row"><label class="field"><span>Industry</span><select class="input" name="industry"><option value="">Choose…</option>${SM.ref.industries.map((i) => html`<option>${i}</option>`)}</select></label>
          <label class="field"><span>Location</span><input class="input" name="location" maxlength="80" placeholder="City, country"></label></div>
          <p class="form-error" data-error hidden></p>
          <div class="row between"><a href="#/welcome?step=2" class="muted">Skip for now</a><button class="btn btn-primary">Continue ${icon('arrow-right')}</button></div></form>`
      : html`<form data-form="welcome-profile" class="stack">
          <label class="field"><span>Your professional title</span><input class="input" name="title" maxlength="100" placeholder="e.g. Freelance community manager" autofocus></label>
          <div class="field"><span>Your skills</span>${SM.chips.render('skills', [], { suggest: SM.ref.skills, placeholder: 'Add a skill and press Enter' })}</div>
          <div class="field"><span>Platforms you work on</span>${SM.toggles('platforms', SM.ref.platforms, [])}</div>
          <p class="form-error" data-error hidden></p>
          <div class="row between"><a href="#/welcome?step=2" class="muted">Skip for now</a><button class="btn btn-primary">Continue ${icon('arrow-right')}</button></div></form>`;
    const step2 = html`<form data-form="welcome-studio" class="stack">
        <div class="field-row"><label class="field"><span>Project — a brand, a client or a campaign</span><input class="input" name="name" maxlength="80" required placeholder="e.g. ${company ? company.name : 'My brand'}" value="${company ? company.name : ''}" autofocus></label>
        <label class="field"><span>Studio name</span><input class="input" name="studio_name" maxlength="80" value="Content Studio" required></label></div>
        ${company ? html`<input type="hidden" name="company_id" value="${company.id}">` : ''}
        <div class="field"><span>Workflow</span>${SM.templatePicker('content')}</div>
        <p class="form-error" data-error hidden></p>
        <div class="row between"><a href="#/" class="muted">I'll do it later</a><button class="btn btn-primary">Create my Studio ${icon('arrow-right')}</button></div></form>`;
    const step = SM.query().get('step') === '2' ? 2 : 1;
    SM.set(ctx.view, html`<div class="welcome"><div class="welcome-card panel">
      <div class="steps"><span class="${step === 1 ? 'is-on' : 'is-done'}">1</span><i></i><span class="${step === 2 ? 'is-on' : ''}">2</span></div>
      ${step === 1
        ? html`<h1>Welcome, ${SM.me.name.split(' ')[0]} 👋</h1><p class="muted">${company ? 'Tell professionals what your company is about.' : 'Tell companies and collaborators what you do. You can refine your profile later.'}</p>${step1}`
        : html`<h1>Create your first Studio</h1><p class="muted">A Studio is your private workspace: ideas become scripts, scripts become content, content gets published.</p>${step2}`}
    </div></div>`);
  });
  SM.forms['welcome-profile'] = async (form, data) => {
    await SM.post('profile_save', Object.assign({ name: SM.me.name, handle: SM.me.handle, pro: true, availability: 'open' }, data));
    SM.me.title = data.title;
    SM.go('/welcome?step=2');
  };
  SM.forms['welcome-company'] = async (form, data) => { await SM.post('company_save', data); SM.go('/welcome?step=2'); };
  SM.forms['welcome-studio'] = async (form, data) => {
    const r = await SM.post('project_save', data);
    SM.toast('Your Studio is ready');
    SM.go('/studio/' + r.studio_id);
  };

  /* --------------------------------------------------------- notifications */
  const NOTIF_ICONS = { assigned: 'user-plus', mention: 'comment', comment: 'comment', approval: 'shield', handoff: 'zap', invite: 'mail', post: 'heart', network: 'users', portfolio: 'award', opp: 'briefcase', guest: 'globe' };
  SM.route(/^\/notifications$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow"><header class="page-head"><h1>Notifications</h1></header>${SM.loading(4)}</div>`);
    const r = await SM.get('notifications');
    if (!ctx.alive()) return;
    SM.refreshCounts(r.counts);
    const unread = r.notifications.some((n) => !n.seen);
    SM.set(ctx.view, html`<div class="page page-narrow">
      <header class="page-head"><h1>Notifications</h1>${unread ? html`<button class="btn btn-soft btn-sm" data-act="notifs-read">${icon('check')}Mark all as read</button>` : ''}</header>
      ${r.notifications.length ? html`<div class="panel list">${r.notifications.map((n) => html`
        <a class="notif${n.seen ? '' : ' is-new'}" href="#/${n.link}" data-act="notif-open" data-id="${n.id}" data-link="${n.link}">
          <span class="notif-av">${SM.avatar(n.actor || (n.guest ? { name: n.guest } : null), 'av-m')}<i class="notif-kind k-${n.kind}">${icon(NOTIF_ICONS[n.kind] || 'bell')}</i></span>
          <span class="notif-text"><span><b>${n.actor ? n.actor.name : n.guest ? n.guest + ' (guest)' : 'Studioma'}</b> ${n.text}</span><small>${SM.fmt.ago(n.created)}</small></span>
        </a>`)}</div>`
        : SM.empty('bell', 'You\'re all caught up', 'Assignments, mentions, approvals and invitations will show up here.')}
    </div>`);
  });
  SM.actions['notif-open'] = (el) => {
    if (el.classList.contains('is-new')) SM.post('notifs_read', { id: +el.dataset.id }).then((r) => SM.refreshCounts(r.counts)).catch(() => {});
    if (el.dataset.link) SM.go('/' + el.dataset.link);
  };
  SM.actions['notifs-read'] = async () => { const r = await SM.try(SM.post('notifs_read', {})); if (r) { SM.refreshCounts(r.counts); SM.navigate(); } };

  /* ---------------------------------------------- demandes d'autorisation */
  SM.route(/^\/requests$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow"><header class="page-head"><h1>Publication requests</h1></header>${SM.loading(2)}</div>`);
    const r = await SM.get('requests');
    if (!ctx.alive()) return;
    const item = (p, kind) => html`<article class="panel req">
      <header class="row">${SM.avatar(p.author, 'av-m')}<div class="grow"><b>${p.author ? p.author.name : ''}</b>
        <p class="muted small">${kind === 'auth' ? html`wants to publish this case study on their public profile. It comes from the project <b>${p.project_name}</b>; you answer as ${p.as === 'client' ? 'the client' : 'a project owner'}.` : 'asks you to confirm that these results are accurate.'}</p></div></header>
      <h3>${p.title}</h3>
      ${p.results ? html`<p>${p.results}</p>` : ''}
      ${p.metrics.length ? html`<div class="metrics">${p.metrics.map((x) => html`<span class="metric"><b>${x.value}</b>${x.label}</span>`)}</div>` : ''}
      ${p.card_titles && p.card_titles.length ? html`<p class="muted small">Based on: ${p.card_titles.join(' · ')}</p>` : ''}
      ${p.media.length ? html`<div class="thumbs">${p.media.map((f) => html`<img src="${SM.fileUrl(f.token, true)}" alt="" loading="lazy">`)}</div>` : ''}
      <footer class="row end"><button class="btn btn-ghost" data-act="${kind === 'auth' ? 'req-auth' : 'req-confirm'}" data-id="${p.id}" data-yes="0">Decline</button>
        <button class="btn btn-primary" data-act="${kind === 'auth' ? 'req-auth' : 'req-confirm'}" data-id="${p.id}" data-yes="1">${icon('check')}${kind === 'auth' ? 'Authorize publication' : 'Confirm results'}</button></footer>
    </article>`;
    SM.set(ctx.view, html`<div class="page page-narrow">
      <header class="page-head"><div><h1>Publication requests</h1><p class="muted">Studio work stays private. Nothing reaches a public profile without the agreement of the project owner — and of the client when there is one.</p></div></header>
      ${r.authorizations.length || r.confirmations.length
        ? html`${r.authorizations.map((p) => item(p, 'auth'))}${r.confirmations.map((p) => item(p, 'confirm'))}`
        : SM.empty('shield', 'No pending request', 'When a teammate wants to showcase work from one of your projects, you will decide here.')}
    </div>`);
  });
  SM.actions['req-auth'] = async (el) => {
    const r = await SM.try(SM.post('portfolio_authorize', { id: +el.dataset.id, approve: el.dataset.yes === '1' }));
    if (r) { SM.toast(r.status === 'published' ? 'Authorized: the case study is now public' : r.status === 'declined' ? 'Request declined' : 'Authorized'); SM.navigate(); }
  };
  SM.actions['req-confirm'] = async (el) => {
    const r = await SM.try(SM.post('portfolio_confirm', { id: +el.dataset.id, confirm: el.dataset.yes === '1' }));
    if (r) { SM.toast(el.dataset.yes === '1' ? 'Results confirmed' : 'Request declined'); SM.navigate(); }
  };

  /* --------------------------------------------------------------- réglages */
  SM.route(/^\/settings$/, async (m, ctx) => {
    const s = SM.me.settings;
    const sw = (name, label, hint, on) => html`<label class="switch-row"><span><b>${label}</b><small>${hint}</small></span><input type="checkbox" class="switch" data-change="setting" data-name="${name}" ${on ? 'checked' : ''}></label>`;
    SM.set(ctx.view, html`<div class="page page-narrow">
      <header class="page-head"><h1>Settings</h1></header>
      <section class="panel pad"><h2>Appearance</h2>
        <div class="seg seg-3">${[['light', 'sun', 'Light'], ['dark', 'moon', 'Dark'], ['auto', 'monitor', 'Match device']].map(([k, ic, l]) => html`
          <label><input type="radio" name="theme" value="${k}" data-change="theme" ${SM.ls.get('theme', 'auto') === k ? 'checked' : ''}><span>${icon(ic)}<b>${l}</b></span></label>`)}</div>
      </section>
      <a class="panel pad ai-link" href="#/settings/ai">${icon('sparkles')}<span class="grow"><b>AI assistants</b><small class="muted">Let Claude, ChatGPT or Gemini manage your Studios for you.</small></span>${icon('chevron-right')}</a>
      <section class="panel pad"><h2>Notifications & privacy</h2>
        ${sw('email_notifs', 'Email me about important updates', 'Assignments, mentions, approval requests and invitations.', s.email_notifs)}
        ${sw('email_news', 'Studioma news and announcements', 'New features and occasional news from the Studioma team. Every email has a one-click unsubscribe link.', s.email_news !== false)}
        ${sw('show_activity', 'Show my Studio activity on my profile', 'Only totals (delivered pieces, number of projects). Never a project name, a client or a card.', s.show_activity)}
        <p class="muted small">Your listing in the talent directory is managed from your <a href="#/profile">profile</a>.</p>
      </section>
      <section class="panel pad" id="signin-methods"><h2>Sign-in methods</h2>
        <div class="method">
          <span class="method-ico">${raw(GOOGLE_G)}</span>
          <div class="grow"><b>Google</b><small class="muted">${SM.me.google ? 'Connected — sign in with one click.' : 'Sign in with one click, without a password.'}</small></div>
          ${SM.me.google ? html`<button class="btn btn-ghost btn-sm" data-act="google-unlink" ${SM.me.has_password ? '' : raw('disabled title="Choose a password first: it would be your only way to sign in."')}>Disconnect</button>` : ''}
        </div>
        ${!SM.me.google && G.enabled() ? html`<div class="google-block" data-google hidden><div class="google-btn"></div></div><p class="form-error" data-google-error hidden></p>` : ''}
        ${SM.me.google && !SM.me.has_password ? html`<p class="muted small">Your account has no password yet. Choose one below if you also want to sign in with your email.</p>` : ''}
        <hr>
        <form data-form="account-password" class="stack">
          <b>${SM.me.has_password ? 'Change your password' : 'Choose a password'}</b>
          <div class="field-row">${SM.me.has_password ? field('Current password', 'current', 'password', 'autocomplete="current-password" required') : ''}${field(SM.me.has_password ? 'New password' : 'Password', 'password', 'password', 'autocomplete="new-password" minlength="8" required placeholder="At least 8 characters"')}</div>
          <p class="form-error" data-error hidden></p><div><button class="btn btn-soft">${SM.me.has_password ? 'Change password' : 'Save password'}</button></div>
        </form>
      </section>
      <section class="panel pad" id="account-email"><h2>Email</h2>
        ${SM.me.email_ok
          ? html`<p class="email-state">${icon('check')}<span><b>${SM.me.email}</b> is confirmed.</span></p>`
          : html`<div class="notice notice-warn" data-email-unconfirmed>${icon('mail')}<span><b>Your email address isn't confirmed yet.</b> Invitations to projects and Studios sent to ${SM.me.email} will appear in your workspace once you confirm it. Until then, open them with the link in the invitation email.</span></div>
            <div data-confirm-box><button class="btn btn-soft btn-sm" data-act="email-confirm-send">${icon('send')}Send me a confirmation link</button></div>`}
        <form data-form="account-email" class="stack">
          <div class="field-row">${field('Email', 'email', 'email', `value="${SM.esc(SM.me.email)}" required`)}${SM.me.has_password ? field('Current password', 'current', 'password', 'autocomplete="current-password" placeholder="Required to change your email"') : ''}</div>
          ${SM.me.has_password ? '' : html`<p class="muted small">Choose a password first to change your email: it confirms changes to your account.</p>`}
          <p class="form-error" data-error hidden></p><div><button class="btn btn-soft">Update email</button></div>
        </form>
      </section>
      <section class="panel pad"><h2>Company pages</h2>
        ${SM.companies.length ? html`<div class="list">${SM.companies.map((c) => html`<a class="list-row" href="#/c/${c.slug}">${SM.logoBox(c, 'av-m')}<span class="grow"><b>${c.name}</b><small class="muted">${SM.ref.roles[c.role] || c.role}</small></span>${icon('chevron-right')}</a>`)}</div>` : html`<p class="muted">Publish opportunities, discover talent and manage projects as a brand or an agency.</p>`}
        <div><button class="btn btn-soft" data-act="company-new">${icon('plus')}Create a company page</button></div>
      </section>
      <section class="panel pad danger-zone"><h2>Delete my account</h2>
        <p class="muted">Your profile, posts and personal projects are removed for good. Projects you share must first get another owner.</p>
        <div><button class="btn btn-danger" data-act="account-delete">Delete my account</button></div>
      </section>
    </div>`);
    if (!SM.me.google) {
      G.mount('continue_with', async (credential) => {
        const err = SM.$('[data-google-error]');
        try {
          SM.setMe(await SM.post('google_link', { credential }));
          SM.toast('Google account connected');
          SM.navigate();
        } catch (e) { if (err) { err.textContent = e.message; err.hidden = false; } }
      });
    }
  });
  /** Logo « G » de Google (couleurs officielles), pour la ligne « Google » des réglages. */
  const GOOGLE_G = '<svg viewBox="0 0 48 48" width="20" height="20" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/></svg>';
  SM.actions['google-unlink'] = async () => {
    if (!await SM.confirm({ title: 'Disconnect Google?', text: 'You will sign in with your email and password from now on. You can connect Google again at any time.', ok: 'Disconnect' })) return;
    const r = await SM.try(SM.post('google_unlink', {}));
    if (r) { SM.setMe(r); SM.toast('Google disconnected'); SM.navigate(); }
  };
  SM.changes.theme = (el) => { SM.applyTheme(el.value); SM.post('account_save', { settings: { theme: el.value } }).then((r) => { SM.me.settings = r.user.settings; }).catch(() => {}); };
  SM.changes.setting = async (el) => {
    const r = await SM.try(SM.post('account_save', { settings: { [el.dataset.name]: el.checked } }));
    if (r) { SM.me.settings = r.user.settings; SM.toast('Saved'); } else el.checked = !el.checked;
  };
  SM.forms['account-email'] = async (form, data) => {
    const before = SM.me.email;
    const r = await SM.post('account_save', data);
    SM.setMe(r);
    if (form.current) form.current.value = '';
    if (r.user.email === before) { SM.toast('Your email is unchanged'); return; }
    SM.toast('Email updated — check ' + r.user.email + ' to confirm it');
    SM.navigate();
  };
  SM.actions['email-confirm-send'] = async (el) => {
    SM.busy(el, true);
    const r = await SM.try(SM.post('email_confirm_send', {}));
    SM.busy(el, false);
    if (!r) return;
    SM.toast('Confirmation link sent');
    SM.set(el.closest('[data-confirm-box]'), html`<p class="muted small" data-confirm-sent>Link sent to <b>${r.email}</b>. Open it while signed in to this account — it is valid for 2 days.</p>`);
  };
  SM.forms['account-password'] = async (form, data) => {
    const first = !SM.me.has_password;
    await SM.post('password', data);
    form.reset();
    SM.me.has_password = true;
    SM.toast(first ? 'Password saved' : 'Password changed');
    if (first) SM.navigate();
  };
  SM.actions['account-delete'] = async () => {
    const data = await SM.confirm({ title: 'Delete your account?', text: 'This cannot be undone.', ok: 'Delete my account', danger: true, values: true,
      body: SM.me.has_password
        ? html`<label class="field"><span>Your password</span><input class="input" type="password" name="password" autocomplete="current-password" autofocus></label>`
        : html`<label class="field"><span>Type your email address (${SM.me.email}) to confirm</span><input class="input" type="email" name="confirm" autocomplete="off" autofocus></label>` });
    if (!data) return;
    const r = await SM.try(SM.post('account_delete', SM.me.has_password ? { password: data.password } : { confirm: data.confirm }));
    if (r) { SM.me = null; SM.csrf = ''; shell(); SM.go('/signup'); SM.toast('Your account has been deleted'); }
  };

  document.addEventListener('DOMContentLoaded', () => SM.boot());
})();
