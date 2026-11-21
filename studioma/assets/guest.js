/*
 * Studioma — un Studio ouvert par un lien de partage, sans compte (#/s/<jeton>[/card/<id>]).
 *
 * Le tableau et le panneau d'une carte sont ceux des membres (board.js, card.js) : SM.guest porte le
 * jeton du lien, et le serveur dit ce que l'invité peut faire (rien par défaut). Ici : la porte d'un lien
 * privé, la vérification de l'adresse (code à 6 chiffres ou lien magique reçu par email), la demande de
 * droits, et l'en-tête de l'invité. Lien d'une seule carte (share.card) : la carte s'ouvre directement, le tableau
 * ne montre qu'elle, et les textes parlent de la carte.
 */
(function () {
  'use strict';
  const { html, icon } = SM;
  const G = SM.guestState = { token: '', share: null, reopen: false };
  const ROLE = { viewer: ['eye', 'View only'], commenter: ['comment', 'Commenter'], editor: ['edit', 'Editor'] };
  /** Parcours de vérification : open (lien privé), verify (invité déjà autorisé), request (demande de droits). */
  const flow = { mode: 'open', email: '', role: 'editor', note: '', target: null };
  const byName = () => (G.share && G.share.by ? G.share.by.name : 'the owner');
  const isCard = () => !!(G.share && G.share.card);
  const what = () => (isCard() ? 'this card' : 'this Studio');

  SM.route(/^\/s\/([A-Za-z0-9_-]{32})(?:\/card\/(\d+))?$/, async (m, ctx) => {
    const token = m[1];
    SM.guest = token;
    document.body.classList.add('guest-mode');
    SM.onLeave(() => { SM.guest = null; document.body.classList.remove('guest-mode'); });
    // Lien magique reçu par email : il vérifie l'adresse, puis disparaît de la barre d'adresse.
    const v = SM.query().get('v');
    if (v) {
      history.replaceState(null, '', '#/s/' + token + (m[2] ? '/card/' + m[2] : ''));
      try {
        const r = await SM.post('share_verify', { link: v });
        verified(r, true);
      } catch (e) { SM.toast(e.message, 'error'); }
      if (!ctx.alive()) return;
    }
    SM.set(ctx.view, html`<div class="studio"><div class="studio-loading">${SM.loading(3)}</div></div>`);
    let open;
    try { open = await SM.get('share_open'); } catch (e) { if (ctx.alive()) gonePage(ctx.view, e); return; }
    if (!ctx.alive()) return;
    // Membre du Studio connecté à son compte : le Studio normal.
    if (open.member) { SM.guest = null; SM.go('/studio/' + open.member + (m[2] ? '/card/' + m[2] : ''), true); return; }
    G.token = token;
    G.share = open.share;
    document.title = (open.share.card ? open.share.card.title : open.share.studio) + ' · Studioma';
    if (open.share.kind === 'private' && !open.guest.verified) { gate(ctx.view); return; }
    // Lien d'une carte : elle s'ouvre directement.
    const cardId = +m[2] || (open.share.card ? open.share.card.id : 0);
    if (open.share.card && !m[2]) history.replaceState(null, '', '#/s/' + token + '/card/' + cardId);
    await SM.board.open(open.share.studio_id, 'board', cardId, ctx);
  }, { public: true, wide: true, update: (m, prev) => {
    if (m[1] !== prev[1] || !SM.board.data || G.reopen) { G.reopen = false; return false; }
    if (m[2]) SM.cardPanel.open(+m[2]); else SM.cardPanel.close(true);
    return true;
  } });
  /** Recharge tout l'écran (après une vérification, une déconnexion ou un accès retiré). */
  const reopen = () => { G.reopen = true; SM.navigate(); };

  function gonePage(view, e) {
    const lock = e.status === 401;
    SM.set(view, html`<div class="guest-gate panel">
      <span class="gate-ico">${icon(lock ? 'lock' : 'link')}</span>
      <h1>${lock ? 'Verify your email' : 'This link doesn\'t work'}</h1>
      <p class="muted">${e.message}</p>
      ${lock ? html`<button class="btn btn-primary" data-act="guest-reopen">Continue</button>` : html`<a class="btn btn-soft" href="${SM.base}">Discover Studioma</a>`}
    </div>`);
  }
  /** Le lien a cessé de fonctionner pendant la visite (désactivé, régénéré, accès retiré…). */
  SM.guestGone = (e) => {
    if (e.status === 401) { if (!G.reopen) { SM.toast(`Your access to ${what()} changed.`, 'error'); reopen(); } return; }
    SM.cardPanel.close(true);
    gonePage(SM.$('#view'), e);
  };
  SM.actions['guest-reopen'] = () => reopen();

  /* ------------------------------------------------- porte d'un lien privé */
  function gate(view) {
    const s = G.share;
    flow.mode = 'open';
    SM.board.data = null;
    SM.set(view, html`<div class="guest-gate panel">
      <span class="gate-ico">${icon('lock')}</span>
      <h1>${s.card ? s.card.title : s.studio}</h1>
      <p class="muted">${s.card ? 'A card shared privately' : 'Shared privately'}${s.by ? html` by <b>${s.by.name}</b>` : ''}${s.card ? '' : s.project ? html` · project ${s.project.name}` : ''}</p>
      <p>Enter your email address: if it's on the list of people ${s.card ? 'this card' : 'this Studio'} is shared with, you'll receive a code to open it.</p>
      <div id="gate-flow" class="gate-flow"></div>
      <p class="muted small">${icon('shield')}No account or password: the code proves this address is yours.</p>
    </div>`);
    flow.target = SM.$('#gate-flow');
    step('email');
  }

  /* ----------------------------------------- vérification de l'adresse email */
  const roleChoice = (current) => html`<div class="choice" role="radiogroup" aria-label="Access you need">
      ${current === 'commenter' ? '' : html`<label class="choice-opt"><input type="radio" name="role" value="commenter" ${flow.role === 'commenter' ? 'checked' : ''}><span><b>${icon('comment')}Comment & approve</b><small>Leave feedback and approve the content you're asked to review, without changing it.</small></span></label>`}
      <label class="choice-opt"><input type="radio" name="role" value="editor" ${flow.role === 'editor' || current === 'commenter' ? 'checked' : ''}><span><b>${icon('edit')}Edit</b><small>Change the content ${byName()} opens to you.</small></span></label>
    </div>
    <label class="field"><span>Message to ${byName()} <em>optional</em></span><textarea class="input" name="note" rows="2" maxlength="500" placeholder="Why do you need it?">${flow.note}</textarea></label>`;
  function step(which) {
    const el = flow.target;
    if (!el || !document.contains(el)) return;
    if (which === 'email') {
      SM.set(el, html`<form class="stack" data-form="guest-email">
        ${flow.mode === 'request' ? roleChoice('') : ''}
        <label class="field"><span>Your email address</span><input class="input" type="email" name="email" required autocomplete="email" placeholder="name@company.com" value="${flow.email}"></label>
        <p class="form-error" data-error hidden></p>
        <button class="btn btn-primary btn-block">${icon('mail')}${flow.mode === 'request' ? 'Verify my email and send the request' : 'Send me a code'}</button>
      </form>`);
    } else {
      SM.set(el, html`<form class="stack" data-form="guest-code">
        <p>We sent a 6-digit code to <b>${flow.email}</b>${G.share && G.share.kind === 'private' && flow.mode === 'open' ? ' if this address is on the list' : ''}. Enter it below, or open the link in the email. It's valid for 15 minutes.</p>
        <label class="field"><span>Verification code</span><input class="input input-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required placeholder="123456" aria-label="Verification code"></label>
        <p class="form-error" data-error hidden></p>
        <button class="btn btn-primary btn-block">${icon('check')}Verify</button>
        <div class="row"><button type="button" class="btn btn-ghost btn-sm" data-act="guest-resend">Send a new code</button><span class="grow"></span><button type="button" class="btn btn-ghost btn-sm" data-act="guest-change-email">Use another email</button></div>
      </form>`);
    }
    const input = SM.$('input[name=code], input[name=email]', el);
    if (input) input.focus();
  }
  async function sendCode() {
    await SM.post('share_code', { email: flow.email, request: flow.mode === 'request' ? flow.role : '', note: flow.mode === 'request' ? flow.note : '' });
  }
  SM.forms['guest-email'] = async (form, data) => {
    flow.email = data.email.trim();
    if (flow.mode === 'request') { flow.role = data.role || 'editor'; flow.note = data.note || ''; }
    await sendCode();
    step('code');
  };
  SM.forms['guest-code'] = async (form, data) => {
    const r = await SM.post('share_verify', { email: flow.email, code: data.code });
    SM.closeModals();
    verified(r, false);
    reopen();
  };
  SM.actions['guest-resend'] = async (el) => {
    SM.busy(el, true);
    const r = await SM.try(sendCode());
    SM.busy(el, false);
    if (r !== null) SM.toast('A new code is on its way to ' + flow.email);
  };
  SM.actions['guest-change-email'] = () => step('email');
  function verified(r, viaLink) {
    if (r.requested === 'pending') SM.toast(`Email verified — your request was sent to ${byName()}`);
    else if (r.requested === 'already') SM.toast('Email verified — you already have this access');
    else SM.toast(viaLink ? 'Email verified' : `Welcome, ${r.guest.email}`);
  }

  /* ------------------------------------------------------- demande de droits */
  const flowModal = (title, sub) => {
    const m = SM.modal(html`${SM.modalHead(title, sub)}<div class="modal-body" id="guest-flow"></div>`, { size: 's' });
    flow.target = SM.$('#guest-flow', m.el);
    return m;
  };
  SM.actions['guest-request'] = () => {
    const g = SM.board.data.studio.guest;
    flow.mode = 'request';
    flow.role = 'editor';
    flowModal('Request editing permission', `${byName()} receives your request by email and decides what you can do with ${what()}. Nothing changes until then.`);
    if (!g.verified) { step('email'); return; }
    SM.set(flow.target, html`<form class="stack" data-form="guest-ask">
      ${roleChoice(g.role)}
      <p class="muted small">You'll ask as <b>${g.email}</b>.</p>
      <p class="form-error" data-error hidden></p>
      <button class="btn btn-primary btn-block">${icon('send')}Send the request</button></form>`);
  };
  SM.forms['guest-ask'] = async (form, data) => {
    await SM.post('share_request', { role: data.role || 'editor', note: data.note || '' });
    SM.closeModals();
    SM.toast(`Request sent to ${byName()}. You'll get an email when they answer.`);
    SM.board.reload();
  };
  SM.actions['guest-verify'] = () => {
    flow.mode = 'verify';
    flowModal('Verify your email', 'If you were given access, confirm your address with a one-time code to use it.');
    step('email');
  };
  SM.actions['guest-menu'] = (el) => {
    const g = SM.board.data.studio.guest;
    const setTheme = (t) => () => SM.applyTheme(t);
    const theme = SM.ls.get('theme', 'auto');
    SM.menu(el, [
      { head: g.email },
      { label: 'Sign out', hint: 'Back to the public view', icon: 'logout', act: async () => { if (await SM.try(SM.post('share_leave'))) { SM.toast('Signed out'); reopen(); } } },
      { sep: true }, { head: 'Appearance' },
      { label: 'Light', icon: 'sun', checked: theme === 'light', act: setTheme('light') },
      { label: 'Dark', icon: 'moon', checked: theme === 'dark', act: setTheme('dark') },
      { label: 'Match my device', icon: 'monitor', checked: theme === 'auto', act: setTheme('auto') },
    ], { align: 'right', width: 240 });
  };

  /* ---------------------------------------------------------- en-tête */
  function status(g) {
    if (g.pending) return [icon('clock'), html`Your request for <b>${ROLE[g.pending][1].toLowerCase()}</b> access was sent to ${byName()}. You'll receive an email when they answer.`];
    if (g.card) {
      if (g.role === 'editor') return [icon('edit'), 'You can edit this card.'];
      if (g.role === 'commenter') return [icon('comment'), g.comments ? 'You can comment on this card, and approve it when you\'re asked.' : 'You can approve this card when you\'re asked.'];
      if (g.verified) return [icon('eye'), html`Signed in as <b>${g.email}</b> — view only.`];
      return [icon('eye'), 'Read-only view of a shared card. To comment or edit, request permission — no account needed.'];
    }
    if (g.role === 'editor') return [icon('edit'), g.limited ? html`You can edit the cards marked ${icon('edit', 'ico-inline')} — the rest is read-only.` : 'You can edit, add and move cards.'];
    if (g.role === 'commenter') return [icon('comment'), 'You can comment, and approve the cards sent to you for approval.'];
    if (g.verified) return [icon('eye'), html`Signed in as <b>${g.email}</b> — view only.`];
    return [icon('eye'), 'Read-only view. To comment or edit, request permission — no account needed.'];
  }
  SM.guestHead = (d) => {
    const s = d.studio;
    const g = s.guest;
    const [ic, text] = status(g);
    const by = G.share && G.share.by;
    return html`
      <header class="studio-head guest-head">
        <div class="crumbs">${g.card ? html`<span class="chip chip-guest">${icon('link')}Shared card</span><b class="crumb-text crumb-strong">${(G.share.card || {}).title || ''}</b>`
          : html`${SM.projectMark(s.project, 'pmark-s')}<span class="crumb-text">${s.project.name}</span><span class="crumb-sep">/</span><b class="crumb-text crumb-strong">${s.name}</b>`}
          <span class="chip chip-guest-role">${icon(ROLE[g.role][0])}${ROLE[g.role][1]}</span></div>
        <span class="grow"></span>
        ${g.pending ? html`<span class="chip chip-pending">${icon('clock')}Request pending</span>`
          : g.role !== 'editor' ? html`<button class="btn btn-primary btn-sm guest-ask" data-act="guest-request">${icon('hand')}<span class="lg">Request editing permission</span><span class="sm">Request edit</span></button>` : ''}
        ${g.verified ? html`<button class="guest-me" data-act="guest-menu" title="${g.email}" aria-label="Guest access of ${g.email}">${SM.avatar({ name: g.email }, 'av-s')}<span>${g.email}</span>${icon('chevron-down')}</button>`
          : html`<button class="btn btn-ghost btn-sm" data-act="guest-verify" title="Already given access? Verify your email to use it">${icon('mail')}<span>Sign in with email</span></button>`}
      </header>
      <div class="guest-note">${ic}<span class="grow">${text}${g.card ? ' Only this card is shared with you.' : g.levels_limited ? ' Only part of this Studio is shared.' : ''}</span><span class="guest-by">${location.protocol === 'https:' ? html`<span class="guest-secure" title="Encrypted connection and private link">${icon('lock')}Secure link</span>` : ''}${by ? html`Shared by ${SM.avatar(by, 'av-s')}<b>${by.name}</b>` : ''}</span></div>
      ${g.card ? html`<div class="studio-bar"><span class="grow"></span><button class="btn btn-soft btn-sm" data-act="guest-open-card">${icon('external')}Open the card</button></div>` : html`<div class="studio-bar"><span class="grow"></span><div class="board-tools">
        <label class="search-in">${icon('search')}<input class="input input-sm" placeholder="Filter cards…" value="${SM.board.filter.q}" data-input="board-q" aria-label="Filter cards"></label>
        <button class="btn btn-soft btn-sm${SM.board.filter.assignee || SM.board.filter.platform ? ' is-on' : ''}" data-act="filter-menu">${icon('filter')}<span>Filter</span></button>
      </div></div>`}`;
  };
  SM.actions['guest-open-card'] = () => { if (G.share && G.share.card) SM.go('/s/' + G.token + '/card/' + G.share.card.id); };
})();
