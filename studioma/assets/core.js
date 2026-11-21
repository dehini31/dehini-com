/*
 * Studioma — socle de l'application : appels à l'API, gabarits HTML échappés, formats, routeur,
 * fenêtres, menus, messages, glisser-déposer (souris et tactile), texte mis en forme, envois.
 *
 * Sécurité : tout texte inséré dans la page passe par le gabarit html`…`, qui échappe chaque
 * valeur ; seules les valeurs marquées raw() (du HTML construit ici même) sont insérées telles
 * quelles. Aucun script en ligne ni attribut on…="" : les clics passent par data-act (SM.actions).
 */
(function () {
  'use strict';

  const BASE = new URL('..', document.currentScript.src).href; // …/studioma/
  const SM = window.SM = { base: BASE, csrf: '', me: null, companies: [], counts: {}, ref: {}, actions: {}, forms: {}, changes: {}, inputs: {} };

  /* ------------------------------------------------------------ gabarits */
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const esc = SM.esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ESC[c]);
  class Raw { constructor(s) { this.s = s; } toString() { return this.s; } }
  const raw = SM.raw = (s) => new Raw(String(s == null ? '' : s));
  const piece = (v) => (v instanceof Raw ? v.s : (v === false || v == null || v === true ? '' : esc(v)));
  const html = SM.html = (strings, ...vals) => {
    let out = strings[0];
    vals.forEach((v, i) => { out += (Array.isArray(v) ? v.map(piece).join('') : piece(v)) + strings[i + 1]; });
    return new Raw(out);
  };
  SM.icon = (name, cls) => raw(`<svg class="ico${cls ? ' ' + cls : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${(window.SM_ICONS || {})[name] || ''}</svg>`);
  const icon = SM.icon;
  SM.logo = (cls) => raw(`<span class="logo ${cls || ''}"><svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#5b4bff"/><rect x="7" y="8" width="5" height="16" rx="2.5" fill="#fff"/><rect x="13.5" y="8" width="5" height="11" rx="2.5" fill="#fff" opacity=".75"/><rect x="20" y="8" width="5" height="7" rx="2.5" fill="#ff7a59"/></svg><b>Studioma</b></span>`);
  SM.$ = (sel, root) => (root || document).querySelector(sel);
  SM.$$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  SM.set = (el, content) => { if (el) el.innerHTML = String(content); return el; };

  /* ------------------------------------------------------------------ API */
  class ApiError extends Error {
    constructor(code, message, status, data) { super(message); this.code = code; this.status = status || 0; this.data = data || {}; }
  }
  SM.ApiError = ApiError;
  /** Erreur de saisie attendue : affichée dans le formulaire, sans trace dans la console. */
  class UserError extends Error {}
  SM.UserError = UserError;
  /**
   * Lien de partage ouvert (#/s/<jeton>) : SM.guest porte son jeton, envoyé (en-tête X-Share) avec les
   * seules actions qu'un invité peut faire ; le serveur vérifie tout le reste.
   */
  SM.guest = null;
  const GUEST_ACTIONS = new Set(['share_open', 'share_code', 'share_verify', 'share_request', 'share_leave', 'board', 'card_get', 'card_save', 'card_create', 'card_move', 'check_save', 'check_delete', 'comment_add', 'comment_delete', 'approval_decide']);
  SM.api = async (action, opts) => {
    opts = opts || {};
    const url = new URL('api.php', BASE);
    url.searchParams.set('action', action);
    Object.entries(opts.params || {}).forEach(([k, v]) => { if (v !== '' && v != null && v !== false) url.searchParams.set(k, v === true ? '1' : v); });
    const init = { credentials: 'same-origin', headers: {} };
    if (SM.guest && GUEST_ACTIONS.has(action)) init.headers['X-Share'] = SM.guest;
    if (opts.body !== undefined) {
      init.method = 'POST';
      init.headers['X-Requested-With'] = 'studioma';
      if (SM.csrf) init.headers['X-CSRF'] = SM.csrf;
      if (opts.body instanceof FormData) init.body = opts.body;
      else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body || {}); }
    }
    let res; let data;
    try {
      res = await fetch(url, init);
      data = await res.json();
    } catch (e) {
      throw new ApiError('network', 'Connection lost. Check your network and try again.');
    }
    if (!res.ok || data.error) {
      if (data.error === 'auth' && SM.me && SM.onAuthLost) SM.onAuthLost();
      // Conditions d'utilisation pas encore acceptées (compte créé avec Google) : la fenêtre d'acceptation s'ouvre.
      if (data.error === 'terms' && SM.onTermsRequired) SM.onTermsRequired();
      const err = new ApiError(data.error || 'server', data.message || 'Something went wrong.', res.status, data);
      // Limite de la formule sur une écriture : invitation à changer de formule (assets/billing.js), pas de message en double.
      if (data.error === 'plan_limit' && opts.body !== undefined && SM.onPlanLimit) { SM.onPlanLimit(data); err.shown = true; }
      throw err;
    }
    // Événements Meta de l'action (le serveur envoie les mêmes, avec le même event_id) : assets/meta.js.
    if (data._meta && window.StmMeta) window.StmMeta.fire(data._meta);
    // Parcours du créateur : une réalisation ou un niveau à célébrer (assets/journey.js).
    if (data._journey && SM.onJourney) SM.onJourney(data._journey);
    return data;
  };
  SM.get = (action, params) => SM.api(action, { params });
  SM.post = (action, body) => SM.api(action, { body: body || {} });
  /** Exécute une écriture ; en cas d'échec, affiche le message et retourne null. */
  SM.try = async (promise) => {
    try { return await promise; } catch (e) { if (!e.shown) SM.toast(e.message, 'error'); return null; }
  };
  /** Envoi d'un fichier avec progression (0 à 1). */
  SM.upload = (kind, file, extra, onProgress) => new Promise((resolve, reject) => {
    const max = (SM.ref.max_upload_mb || 64) * 1048576;
    if (file.size > max) { reject(new ApiError('invalid', `“${file.name}” is too large (${SM.ref.max_upload_mb || 64} MB maximum).`)); return; }
    const fd = new FormData();
    fd.append('kind', kind);
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, v));
    fd.append('file', file);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', new URL('api.php?action=upload', BASE));
    xhr.setRequestHeader('X-Requested-With', 'studioma');
    xhr.setRequestHeader('X-CSRF', SM.csrf);
    xhr.upload.onprogress = (e) => { if (onProgress && e.lengthComputable) onProgress(e.loaded / e.total); };
    xhr.onerror = () => reject(new ApiError('network', 'Connection lost during the upload.'));
    xhr.onload = () => {
      let data = {};
      try { data = JSON.parse(xhr.responseText); } catch (e) { /* réponse illisible */ }
      if (xhr.status >= 200 && xhr.status < 300 && data.ok) resolve(data.file);
      else reject(new ApiError(data.error || 'server', data.message || (xhr.status === 413 ? 'This file is too large.' : 'The upload failed.'), xhr.status));
    };
    xhr.send(fd);
  });
  SM.fileUrl = (token, small, download) => (token ? `${BASE}api.php?action=file&t=${token}${small ? '&s=1' : ''}${download ? '&dl=1' : ''}${SM.guest ? '&share=' + SM.guest : ''}` : '');

  /* -------------------------------------------------------------- formats */
  const nf = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
  const nf1 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 1 });
  const dfmt = (o) => new Intl.DateTimeFormat('en-GB', o);
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const parseYmd = (s) => { const [y, m, d] = String(s).slice(0, 10).split('-').map(Number); return new Date(y, m - 1, d); };
  const fmt = SM.fmt = {
    num: (v) => nf.format(v || 0),
    compact: (v) => { v = v || 0; const a = Math.abs(v); return a >= 1e6 ? nf1.format(v / 1e6) + 'M' : a >= 1e4 ? nf1.format(v / 1e3) + 'k' : nf.format(v); },
    plural: (n, one, many) => nf.format(n) + ' ' + (n === 1 ? one : (many || one + 's')),
    size: (b) => (b >= 1048576 ? nf1.format(b / 1048576) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'),
    date: (ts) => dfmt({ day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(ts * 1000)),
    time: (ts) => dfmt({ day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(ts * 1000)),
    hour: (ts) => dfmt({ hour: '2-digit', minute: '2-digit' }).format(new Date(ts * 1000)),
    ago: (ts) => {
      const s = Math.max(0, Date.now() / 1000 - ts);
      if (s < 60) return 'just now';
      if (s < 3600) return Math.round(s / 60) + ' min ago';
      if (s < 86400) return Math.round(s / 3600) + ' h ago';
      if (s < 172800) return 'yesterday';
      if (s < 7 * 86400) return Math.round(s / 86400) + ' days ago';
      return fmt.date(ts);
    },
    ymd, parseYmd,
    today: () => ymd(new Date()),
    /** « 2026-12-20 » → « 20 Dec » (avec l'année si ce n'est pas l'année en cours). */
    day: (s) => { const d = parseYmd(s); return dfmt(d.getFullYear() === new Date().getFullYear() ? { day: 'numeric', month: 'short' } : { day: 'numeric', month: 'short', year: 'numeric' }).format(d); },
    /** « 2026-12-22T18:30 » → « 22 Dec, 18:30 ». */
    datetime: (s) => (s ? fmt.day(s) + (s.length > 10 ? ', ' + s.slice(11, 16) : '') : ''),
    /** État d'une échéance : overdue | today | soon (3 jours) | later. */
    due: (s) => {
      if (!s) return '';
      const diff = Math.round((parseYmd(s) - parseYmd(fmt.today())) / 86400000);
      return diff < 0 ? 'overdue' : diff === 0 ? 'today' : diff <= 3 ? 'soon' : 'later';
    },
  };
  SM.debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  SM.initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
  const ls = SM.ls = {
    get: (k, d) => { try { const v = localStorage.getItem('stm.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: (k, v) => { try { localStorage.setItem('stm.' + k, JSON.stringify(v)); } catch (e) { /* stockage indisponible */ } },
    del: (k) => { try { localStorage.removeItem('stm.' + k); } catch (e) { /* idem */ } },
  };

  /** Photo d'un compte, ou ses initiales sur une couleur stable. */
  SM.avatar = (u, cls) => {
    if (!u) return raw(`<span class="avatar ${cls || ''} av-none">${icon('user')}</span>`);
    return u.avatar
      ? html`<span class="avatar ${cls || ''}" title="${u.name}"><img src="${SM.fileUrl(u.avatar, true)}" alt="" loading="lazy"></span>`
      : html`<span class="avatar ${cls || ''} av-c${(u.id || 0) % 8}" title="${u.name}">${SM.initials(u.name)}</span>`;
  };
  SM.logoBox = (c, cls) => (c && c.logo
    ? html`<span class="avatar av-sq ${cls || ''}"><img src="${SM.fileUrl(c.logo, true)}" alt="" loading="lazy"></span>`
    : html`<span class="avatar av-sq ${cls || ''} av-c${((c && c.id) || 0) % 8}">${SM.initials(c && c.name)}</span>`);
  SM.avatars = (users, max) => {
    const list = users.filter(Boolean);
    const shown = list.slice(0, max || 4);
    return html`<span class="avatars">${shown.map((u) => SM.avatar(u, 'av-s'))}${list.length > shown.length ? html`<span class="avatar av-s av-more">+${list.length - shown.length}</span>` : ''}</span>`;
  };

  /** Texte simple → HTML : liens cliquables, @mentions et #mots-clés mis en valeur, retours à la ligne gardés. */
  SM.linkify = (text) => raw(esc(text)
    .replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>')
    .replace(/(^|[\s(])@([a-z0-9][a-z0-9._-]{1,29})/gi, (m, pre, h) => `${pre}<a class="mention" href="#/u/${h.toLowerCase().replace(/\.$/, '')}">@${h}</a>`)
    .replace(/(^|[\s(])#([\p{L}\p{N}_]{2,40})/gu, '$1<a class="hashtag" href="#/discover?q=$2">#$2</a>')
    .replace(/\n/g, '<br>'));

  /* ------------------------------------------------- texte mis en forme */
  const RICH_TAGS = { P: 1, BR: 1, B: 1, STRONG: 1, I: 1, EM: 1, U: 1, S: 1, H2: 1, H3: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, A: 1, CODE: 1, PRE: 1, HR: 1 };
  /** Ne garde que les balises autorisées, sans attribut (le serveur fait de même à l'enregistrement). */
  SM.richClean = (dirty) => {
    const doc = new DOMParser().parseFromString('<div>' + String(dirty || '') + '</div>', 'text/html');
    const walk = (node) => {
      let out = '';
      node.childNodes.forEach((n) => {
        if (n.nodeType === 3) { out += esc(n.nodeValue); return; }
        if (n.nodeType !== 1 || /^(SCRIPT|STYLE|IFRAME|OBJECT)$/.test(n.tagName)) return;
        const tag = n.tagName === 'DIV' ? 'P' : n.tagName;
        const inner = walk(n);
        if (!RICH_TAGS[tag]) { out += inner; return; }
        if (tag === 'BR' || tag === 'HR') { out += `<${tag.toLowerCase()}>`; return; }
        if (tag === 'A') {
          const href = n.getAttribute('href') || '';
          out += /^(https?:\/\/|mailto:)/i.test(href) ? `<a href="${esc(href)}" target="_blank" rel="noopener noreferrer">${inner}</a>` : inner;
          return;
        }
        out += `<${tag.toLowerCase()}>${inner}</${tag.toLowerCase()}>`;
      });
      return out;
    };
    return walk(doc.body.firstChild);
  };
  SM.rich = (dirty) => raw(SM.richClean(dirty));
  /** Éditeur de texte mis en forme. La valeur se lit avec SM.editorValue(el). */
  SM.editor = (value, opts) => {
    opts = opts || {};
    const btn = (cmd, ic, label, arg) => html`<button type="button" class="ed-btn" data-act="ed-cmd" data-cmd="${cmd}" data-arg="${arg || ''}" title="${label}" aria-label="${label}">${icon(ic)}</button>`;
    return html`<div class="editor ${opts.cls || ''}">
      <div class="ed-bar">${btn('bold', 'bold', 'Bold')}${btn('italic', 'italic', 'Italic')}${btn('underline', 'underline', 'Underline')}<i></i>${btn('formatBlock', 'heading', 'Heading', 'h2')}${btn('insertUnorderedList', 'list', 'Bullet list')}${btn('insertOrderedList', 'list-ol', 'Numbered list')}${btn('formatBlock', 'quote', 'Quote', 'blockquote')}<i></i>${btn('createLink', 'link', 'Link')}</div>
      <div class="ed-body rich" contenteditable="true" role="textbox" aria-multiline="true" data-rich="${opts.name || 'body'}" data-placeholder="${opts.placeholder || 'Write here…'}">${SM.rich(value)}</div>
    </div>`;
  };
  SM.editorValue = (el) => {
    const v = SM.richClean(el.innerHTML);
    return v.replace(/<(p|br)>|<\/p>|&nbsp;|\s/g, '') === '' ? '' : v;
  };
  SM.actions['ed-cmd'] = (el) => {
    const body = el.closest('.editor').querySelector('.ed-body');
    body.focus();
    let arg = el.dataset.arg || null;
    if (el.dataset.cmd === 'createLink') {
      arg = window.prompt('Link address (https://…)') || '';
      if (!/^https?:\/\//i.test(arg)) return;
    }
    if (el.dataset.cmd === 'formatBlock' && document.queryCommandValue('formatBlock').toLowerCase() === arg) arg = 'p';
    document.execCommand(el.dataset.cmd, false, arg);
    body.dispatchEvent(new Event('input', { bubbles: true }));
  };

  /* -------------------------------------------------------------- routeur */
  const routes = [];
  let leave = [];
  let navToken = 0;
  SM.route = (pattern, handler, opts) => routes.push({ pattern, handler, opts: opts || {} });
  SM.onLeave = (fn) => leave.push(fn);
  SM.path = () => (location.hash.replace(/^#/, '') || '/');
  SM.go = (path, replace) => {
    const target = '#' + path;
    if (location.hash === target) { SM.navigate(); return; }
    if (replace) history.replaceState(null, '', target), SM.navigate();
    else location.hash = target;
  };
  SM.query = () => new URLSearchParams(SM.path().split('?')[1] || '');
  /** Vrai tant que l'écran en cours est encore celui affiché (après une attente réseau). */
  SM.alive = (token) => token === navToken;
  SM.navigate = async () => {
    const full = SM.path();
    const path = full.split('?')[0].replace(/\/+$/, '') || '/';
    const match = routes.map((r) => ({ r, m: path.match(r.pattern) })).find((x) => x.m);
    if (SM.beforeRoute && SM.beforeRoute(path, match ? match.r.opts : {}) === false) return;
    // Un même écran peut gérer un simple changement d'adresse (ex. ouvrir une carte) sans se redessiner.
    if (match && SM.current && SM.current.r === match.r && match.r.opts.update && match.r.opts.update(match.m, SM.current.m) !== false) { SM.current.m = match.m; return; }
    leave.forEach((fn) => { try { fn(); } catch (e) { /* nettoyage au mieux */ } });
    leave = [];
    SM.closeMenus();
    const token = ++navToken;
    SM.current = match || null;
    const view = SM.$('#view');
    if (!match) { SM.set(view, SM.empty('compass', 'Page not found', 'This page doesn\'t exist or has moved.', html`<a class="btn btn-primary" href="#/">Back to home</a>`)); return; }
    document.body.classList.toggle('is-wide', !!match.r.opts.wide);
    if (SM.afterRoute) SM.afterRoute(path, match.r.opts);
    window.scrollTo(0, 0);
    try {
      await match.r.handler(match.m, { token, view, alive: () => SM.alive(token) });
    } catch (e) {
      if (!SM.alive(token)) return;
      if (e instanceof ApiError && e.code === 'auth') return;
      if (e instanceof ApiError && e.code === 'plan_limit' && SM.planGate) { SM.set(view, SM.planGate(e.data)); return; }
      SM.set(view, e instanceof ApiError && e.status === 404
        ? SM.empty('lock', 'Nothing here', e.message, html`<a class="btn btn-primary" href="#/">Back to home</a>`)
        : SM.empty('alert', 'Something went wrong', e.message || 'Please try again.', html`<button class="btn btn-primary" data-act="reload">Try again</button>`));
      if (!(e instanceof ApiError)) console.error(e);
    }
  };
  SM.actions.reload = () => SM.navigate();
  window.addEventListener('hashchange', () => SM.navigate());

  /* ------------------------------------------------------ états et blocs */
  SM.empty = (ic, title, text, action) => html`<div class="empty"><span class="empty-ico">${icon(ic)}</span><h3>${title}</h3>${text ? html`<p>${text}</p>` : ''}${action || ''}</div>`;
  SM.loading = (n) => raw('<div class="skeletons">' + '<div class="skeleton"></div>'.repeat(n || 3) + '</div>');
  SM.spinner = () => raw('<span class="spinner" aria-label="Loading"></span>');
  SM.busy = (btn, on) => { if (!btn) return; btn.disabled = on; btn.classList.toggle('is-busy', on); };

  /* --------------------------------------------------- événements délégués */
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-act]');
    if (!el || el.disabled) return;
    const fn = SM.actions[el.dataset.act];
    if (!fn) return;
    if (el.tagName === 'A' || el.tagName === 'BUTTON' || el.dataset.stop !== undefined) e.preventDefault();
    fn(el, e);
  });
  document.addEventListener('submit', (e) => {
    const form = e.target.closest('form[data-form]');
    if (!form) return;
    e.preventDefault();
    const fn = SM.forms[form.dataset.form];
    if (!fn) return;
    const btn = form.querySelector('button[type=submit], button:not([type])');
    if (btn && btn.disabled) return;
    const err = form.querySelector('[data-error]');
    if (err) err.hidden = true;
    SM.busy(btn, true);
    Promise.resolve(fn(form, SM.formData(form), e)).catch((ex) => {
      if (ex.shown) return;   // déjà expliqué (fenêtre « changer de formule »)
      if (err) { err.textContent = ex.message; err.hidden = false; } else SM.toast(ex.message, 'error');
      if (!(ex instanceof ApiError) && !(ex instanceof UserError)) console.error(ex);
    }).finally(() => SM.busy(btn, false));
  });
  document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-change]');
    if (el && SM.changes[el.dataset.change]) SM.changes[el.dataset.change](el, e);
  });
  document.addEventListener('input', (e) => {
    const el = e.target.closest('[data-input]');
    if (el && SM.inputs[el.dataset.input]) SM.inputs[el.dataset.input](el, e);
  });
  /** Valeurs d'un formulaire : champs simples, cases « nom[] » (tableaux), listes de mots (.chips) et éditeurs. */
  SM.formData = (form) => {
    const out = {};
    new FormData(form).forEach((v, k) => {
      if (v instanceof File) return;
      if (k.endsWith('[]')) (out[k.slice(0, -2)] = out[k.slice(0, -2)] || []).push(v);
      else out[k] = v;
    });
    SM.$$('input[type=checkbox][name]', form).forEach((c) => { if (!c.name.endsWith('[]')) out[c.name] = c.checked; else out[c.name.slice(0, -2)] = out[c.name.slice(0, -2)] || []; });
    SM.$$('.chips[data-chips]', form).forEach((c) => { out[c.dataset.chips] = SM.chips.value(c); });
    SM.$$('[data-rich]', form).forEach((r) => { out[r.dataset.rich] = SM.editorValue(r); });
    return out;
  };

  /* ------------------------------------------------------------- messages */
  SM.toast = (msg, kind, action) => {
    let box = SM.$('#toasts');
    if (!box) { box = document.createElement('div'); box.id = 'toasts'; box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
    const t = document.createElement('div');
    t.className = 'toast' + (kind ? ' toast-' + kind : '');
    t.innerHTML = String(html`${icon(kind === 'error' ? 'alert' : kind === 'handoff' ? 'zap' : 'check')}<span>${msg}</span>${action ? html`<a href="${action.href}" class="toast-link">${action.label}</a>` : ''}`);
    box.appendChild(t);
    setTimeout(() => { t.classList.add('is-out'); setTimeout(() => t.remove(), 300); }, kind === 'error' ? 6000 : 3800);
  };

  /* ------------------------------------------------------------- fenêtres */
  const modals = [];
  /**
   * Ouvre une fenêtre. opts : size (s | m | l | xl), cls, onClose, side (panneau latéral), locked (ne se ferme ni
   * par Échap ni par un clic à côté : il faut choisir un des boutons).
   */
  SM.modal = (content, opts) => {
    opts = opts || {};
    const wrap = document.createElement('div');
    wrap.className = 'overlay' + (opts.side ? ' overlay-side' : '');
    wrap.innerHTML = `<div class="${opts.side ? 'drawer' : 'modal modal-' + (opts.size || 'm')} ${opts.cls || ''}" role="dialog" aria-modal="true" tabindex="-1">${content}</div>`;
    document.body.appendChild(wrap);
    document.body.classList.add('has-modal');
    const prev = document.activeElement;
    const m = {
      el: wrap.firstChild, wrap, opts,
      close(silent) {
        const i = modals.indexOf(m);
        if (i < 0) return;
        modals.splice(i, 1);
        wrap.remove();
        if (!modals.length) document.body.classList.remove('has-modal');
        if (prev && prev.focus && document.contains(prev)) prev.focus();
        if (!silent && opts.onClose) opts.onClose();
      },
    };
    let downOnWrap = false;
    wrap.addEventListener('mousedown', (e) => { downOnWrap = e.target === wrap; });
    wrap.addEventListener('click', (e) => { if (e.target === wrap && downOnWrap && !opts.locked) m.close(); });
    modals.push(m);
    const focus = m.el.querySelector('[autofocus]') || (opts.side ? null : m.el.querySelector('input:not([type=hidden]), textarea, select'));
    (focus || m.el).focus({ preventScroll: true });
    return m;
  };
  SM.topModal = () => modals[modals.length - 1] || null;
  SM.closeModals = () => { modals.slice().forEach((m) => m.close(true)); };
  SM.actions['modal-close'] = (el) => { const m = modals.find((x) => x.wrap.contains(el)); if (m) m.close(); };
  // Tabulation : le focus reste dans la fenêtre ouverte.
  document.addEventListener('keydown', (e) => {
    const m = SM.topModal();
    if (e.key !== 'Tab' || !m) return;
    const items = SM.$$('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [contenteditable="true"], [tabindex="0"]', m.el).filter((el) => el.offsetParent !== null);
    if (!items.length) { e.preventDefault(); return; }
    const first = items[0];
    const last = items[items.length - 1];
    if (!m.el.contains(document.activeElement)) { e.preventDefault(); first.focus(); }
    else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (SM.closeMenus()) return;
    if (document.body.classList.contains('is-dragging')) return;
    const m = SM.topModal();
    if (m) { e.preventDefault(); if (!m.opts.locked) m.close(); }
  });
  SM.modalHead = (title, sub) => html`<header class="modal-head"><div><h2>${title}</h2>${sub ? html`<p class="muted">${sub}</p>` : ''}</div><button class="btn-icon" data-act="modal-close" aria-label="Close">${icon('x')}</button></header>`;
  /** Demande de confirmation. opts : title, text, ok, danger, type (texte à retaper pour confirmer). */
  SM.confirm = (opts) => new Promise((resolve) => {
    let done = false;
    const m = SM.modal(html`${SM.modalHead(opts.title)}
      <form class="modal-body" data-form="confirm-ok">
        ${opts.text ? html`<p>${opts.text}</p>` : ''}${opts.body || ''}
        ${opts.type ? html`<label class="field"><span>Type <b>${opts.type}</b> to confirm</span><input class="input" name="typed" autocomplete="off" autofocus></label>` : ''}
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">${opts.cancel || 'Cancel'}</button><button type="submit" class="btn ${opts.danger ? 'btn-danger' : 'btn-primary'}" ${opts.type ? '' : 'autofocus'}>${opts.ok || 'Confirm'}</button></footer>
      </form>`, { size: 's', onClose: () => { if (!done) resolve(false); } });
    m.submit = async (data) => {
      if (opts.type && data.typed.trim() !== opts.type) throw new UserError('The text doesn\'t match.');
      done = true;
      m.close(true);
      resolve(opts.values ? data : true);
    };
  });
  SM.forms['confirm-ok'] = (form, data) => SM.topModal().submit(data);
  /** Demande une courte saisie ; retourne le texte ou null. */
  SM.prompt = (opts) => new Promise((resolve) => {
    let done = false;
    const m = SM.modal(html`${SM.modalHead(opts.title)}
      <form class="modal-body" data-form="confirm-ok">
        <label class="field"><span>${opts.label || ''}</span><input class="input" name="value" value="${opts.value || ''}" maxlength="${opts.max || 80}" placeholder="${opts.placeholder || ''}" autocomplete="off" autofocus></label>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button type="submit" class="btn btn-primary">${opts.ok || 'Save'}</button></footer>
      </form>`, { size: 's', onClose: () => { if (!done) resolve(null); } });
    m.submit = async (data) => {
      if (!data.value.trim()) throw new UserError('This field is required.');
      done = true;
      m.close(true);
      resolve(data.value.trim());
    };
  });

  /* ---------------------------------------------------------------- menus */
  let openMenu = null;
  SM.closeMenus = () => { if (!openMenu) return false; openMenu.el.remove(); if (openMenu.anchor) openMenu.anchor.classList.remove('is-open'); openMenu = null; return true; };
  /**
   * Menu déroulant près d'un élément. items : { label, icon, html, hint, act, href, danger, checked, disabled }
   * | { sep: true } | { head: 'Titre' }. opts : search (champ de filtre), keep (reste ouvert après un choix),
   * align ('right'), width.
   */
  SM.menu = (anchor, items, opts) => {
    opts = opts || {};
    if (openMenu && openMenu.anchor === anchor) { SM.closeMenus(); return null; }
    SM.closeMenus();
    const el = document.createElement('div');
    el.className = 'menu' + (opts.cls ? ' ' + opts.cls : '');
    el.setAttribute('role', 'menu');
    if (opts.width) el.style.width = opts.width + 'px';
    const draw = (filter) => {
      const f = (filter || '').toLowerCase();
      const body = items.map((it, i) => {
        if (it.sep) return f ? '' : raw('<div class="menu-sep"></div>');
        if (it.head) return f ? '' : html`<div class="menu-head">${it.head}</div>`;
        if (f && !String(it.label).toLowerCase().includes(f)) return '';
        const inner = html`${it.html || (it.icon ? icon(it.icon) : '')}<span class="menu-label">${it.label}${it.hint ? html`<small>${it.hint}</small>` : ''}</span>${it.checked ? icon('check', 'menu-check') : ''}`;
        return it.href
          ? html`<a class="menu-item" role="menuitem" href="${it.href}" data-i="${i}">${inner}</a>`
          : html`<button type="button" class="menu-item${it.danger ? ' is-danger' : ''}${it.checked ? ' is-checked' : ''}" role="menuitem" data-i="${i}" ${it.disabled ? 'disabled' : ''}>${inner}</button>`;
      });
      SM.set(el.querySelector('.menu-list'), html`${body}`.s || '<div class="menu-none">Nothing found</div>');
    };
    el.innerHTML = (opts.search ? '<div class="menu-search"><input class="input" placeholder="Search…" aria-label="Search"></div>' : '') + '<div class="menu-list"></div>';
    draw('');
    document.body.appendChild(el);
    const place = () => {
      const r = anchor.getBoundingClientRect();
      const w = el.offsetWidth; const h = el.offsetHeight;
      let left = opts.align === 'right' ? r.right - w : r.left;
      left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
      let top = r.bottom + 6;
      if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
      el.style.left = left + 'px';
      el.style.top = top + 'px';
    };
    place();
    anchor.classList.add('is-open');
    openMenu = { el, anchor };
    const search = el.querySelector('.menu-search input');
    if (search) { search.focus(); search.addEventListener('input', () => { draw(search.value); place(); }); }
    el.addEventListener('click', (e) => {
      const b = e.target.closest('.menu-item');
      if (!b) return;
      const it = items[+b.dataset.i];
      if (it.href) { SM.closeMenus(); return; }
      if (opts.keep) { it.checked = !it.checked; if (it.act) it.act(it); draw(search ? search.value : ''); return; }
      SM.closeMenus();
      if (it.act) it.act(it);
    });
    return openMenu;
  };
  document.addEventListener('mousedown', (e) => { if (openMenu && !openMenu.el.contains(e.target) && !openMenu.anchor.contains(e.target)) SM.closeMenus(); }, true);
  window.addEventListener('resize', () => SM.closeMenus());

  /* --------------------------------------------------- listes de mots-clés */
  SM.chips = {
    /** Champ de mots-clés (compétences, tags…). opts : suggest (propositions), placeholder, max. */
    render: (name, values, opts) => {
      opts = opts || {};
      const id = 'dl-' + name;
      return html`<div class="chips" data-chips="${name}" data-max="${opts.max || 20}" ${opts.change ? html`data-change="${opts.change}"` : ''}>
        ${(values || []).map((v) => SM.chips.item(v))}
        <input class="chips-in" list="${id}" placeholder="${opts.placeholder || 'Add…'}" maxlength="${opts.len || 40}" aria-label="${opts.placeholder || 'Add'}">
        ${opts.suggest ? html`<datalist id="${id}">${opts.suggest.map((s) => html`<option value="${s}">`)}</datalist>` : ''}
      </div>`;
    },
    item: (v) => html`<span class="chip chip-x" data-v="${v}">${v}<button type="button" data-act="chip-x" aria-label="Remove ${v}">${icon('x')}</button></span>`,
    value: (el) => SM.$$('.chip-x', el).map((c) => c.dataset.v),
    add: (el, input) => {
      const v = input.value.replace(/,/g, ' ').trim().replace(/\s+/g, ' ');
      input.value = '';
      if (!v) return;
      const cur = SM.chips.value(el);
      if (cur.some((c) => c.toLowerCase() === v.toLowerCase()) || cur.length >= +el.dataset.max) return;
      input.insertAdjacentHTML('beforebegin', String(SM.chips.item(v)));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    },
  };
  SM.actions['chip-x'] = (btn) => { const el = btn.closest('.chips'); btn.closest('.chip-x').remove(); el.dispatchEvent(new Event('change', { bubbles: true })); };
  document.addEventListener('keydown', (e) => {
    const input = e.target.closest && e.target.closest('.chips-in');
    if (!input) return;
    const el = input.closest('.chips');
    // Entrée n'envoie jamais le formulaire depuis ce champ : elle ajoute le mot en cours.
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); if (input.value.trim()) SM.chips.add(el, input); }
    if (e.key === 'Backspace' && !input.value) { const last = SM.$$('.chip-x', el).pop(); if (last) { last.remove(); el.dispatchEvent(new Event('change', { bubbles: true })); } }
  });
  // Choix d'une proposition de la liste, ou sortie du champ : le mot est ajouté.
  document.addEventListener('input', (e) => {
    const input = e.target.closest && e.target.closest('.chips-in');
    if (!input || !input.list) return;
    if (Array.from(input.list.options).some((o) => o.value === input.value)) SM.chips.add(input.closest('.chips'), input);
  });
  document.addEventListener('focusout', (e) => {
    const input = e.target.closest && e.target.closest('.chips-in');
    if (input && input.value.trim()) SM.chips.add(input.closest('.chips'), input);
  });
  document.addEventListener('click', (e) => { const c = e.target.closest('.chips'); if (c && e.target === c) c.querySelector('.chips-in').focus(); });

  /* -------------------------------------------------------- glisser-déposer */
  /**
   * Rend des éléments réordonnables et déplaçables d'un conteneur à l'autre, à la souris comme au
   * doigt (appui long). cfg : root, item (sélecteur), handle (poignée, facultatif), container
   * (sélecteur des zones de dépôt), axis ('y' ou 'x'), enabled(item), over(el) → conteneur de repli,
   * onDrop(item, conteneur, index, conteneurDeDépart). Retourne une fonction qui désactive le tout.
   */
  SM.drag = (cfg) => {
    let st = null;
    const isTouch = (e) => e.pointerType === 'touch' || e.pointerType === 'pen';
    const begin = () => {
      const r = st.item.getBoundingClientRect();
      st.active = true;
      st.dx = st.x - r.left;
      st.dy = st.y - r.top;
      st.ghost = st.item.cloneNode(true);
      st.ghost.classList.add('drag-ghost');
      Object.assign(st.ghost.style, { width: r.width + 'px', height: r.height + 'px', left: r.left + 'px', top: r.top + 'px' });
      st.ph = document.createElement('div');
      st.ph.className = 'drag-ph ' + (cfg.phClass || '');
      st.ph.style.height = r.height + 'px';
      if (cfg.axis === 'x') st.ph.style.width = r.width + 'px';
      st.from = st.item.parentNode;
      st.item.after(st.ph);
      st.item.classList.add('drag-src');
      document.body.appendChild(st.ghost);
      document.body.classList.add('is-dragging');
      if (navigator.vibrate && st.touch) { try { navigator.vibrate(12); } catch (e) { /* non géré */ } }
      st.cont = st.from;
      loop();
    };
    const place = () => {
      const under = document.elementFromPoint(st.x, st.y);
      if (!under) return;
      let cont = under.closest(cfg.container);
      if (!cont && cfg.over) cont = cfg.over(under);
      if (!cont || !cfg.root.contains(cont)) return;
      const items = Array.from(cont.children).filter((el) => el.matches(cfg.item) && el !== st.item);
      const pos = cfg.axis === 'x' ? st.x : st.y;
      let idx = items.length;
      for (let i = 0; i < items.length; i++) {
        const r = items[i].getBoundingClientRect();
        if (pos < (cfg.axis === 'x' ? r.left + r.width / 2 : r.top + r.height / 2)) { idx = i; break; }
      }
      if (idx < items.length) cont.insertBefore(st.ph, items[idx]);
      else if (items.length) items[items.length - 1].after(st.ph);
      else cont.prepend(st.ph);
      if (st.cont !== cont) { if (st.cont) st.cont.classList.remove('drag-over'); cont.classList.add('drag-over'); st.cont = cont; }
    };
    /** Fait défiler ce qui est sous le pointeur quand il approche d'un bord. */
    const autoscroll = () => {
      const edge = 56; const speed = 16;
      let el = document.elementFromPoint(st.x, st.y);
      while (el && el !== document.body) {
        const cs = getComputedStyle(el);
        const r = el.getBoundingClientRect();
        if (/(auto|scroll)/.test(cs.overflowY) && el.scrollHeight > el.clientHeight + 1) {
          if (st.y < r.top + edge) el.scrollTop -= speed; else if (st.y > r.bottom - edge) el.scrollTop += speed;
        }
        if (/(auto|scroll)/.test(cs.overflowX) && el.scrollWidth > el.clientWidth + 1) {
          if (st.x < r.left + edge) el.scrollLeft -= speed; else if (st.x > r.right - edge) el.scrollLeft += speed;
        }
        el = el.parentElement;
      }
      if (st.y < edge) window.scrollBy(0, -speed); else if (st.y > window.innerHeight - edge) window.scrollBy(0, speed);
    };
    const loop = () => {
      if (!st || !st.active) return;
      st.ghost.style.transform = `translate(${st.x - st.dx - parseFloat(st.ghost.style.left)}px, ${st.y - st.dy - parseFloat(st.ghost.style.top)}px) rotate(${cfg.axis === 'x' ? 0 : 1.5}deg)`;
      autoscroll();
      place();
      st.raf = requestAnimationFrame(loop);
    };
    const finish = (drop) => {
      const s = st;
      st = null;
      if (!s) return;
      clearTimeout(s.timer);
      cancelAnimationFrame(s.raf);
      window.removeEventListener('pointermove', onMove, true);
      window.removeEventListener('pointerup', onUp, true);
      window.removeEventListener('pointercancel', onCancel, true);
      window.removeEventListener('keydown', onKey, true);
      if (!s.active) return;
      document.body.classList.remove('is-dragging');
      s.ghost.remove();
      if (s.cont) s.cont.classList.remove('drag-over');
      s.item.classList.remove('drag-src');
      const cont = s.ph.parentNode;
      const index = cont ? Array.from(cont.children).filter((el) => el.matches(cfg.item) && el !== s.item).filter((el) => s.ph.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_PRECEDING).length : 0;
      const start = Array.from(s.from.children).filter((el) => el.matches(cfg.item)).indexOf(s.item);
      s.ph.remove();
      // Le clic qui suit le relâchement ne doit pas ouvrir l'élément déplacé.
      const eat = (e) => { e.stopPropagation(); e.preventDefault(); };
      window.addEventListener('click', eat, true);
      setTimeout(() => window.removeEventListener('click', eat, true), 60);
      if (drop && cont && !(cont === s.from && index === start)) cfg.onDrop(s.item, cont, index, s.from);
    };
    const onMove = (e) => {
      if (!st) return;
      st.x = e.clientX; st.y = e.clientY;
      if (st.active) return;
      const moved = Math.hypot(st.x - st.sx, st.y - st.sy);
      if (st.touch) { if (moved > 10) finish(false); } else if (moved > 5) begin();
    };
    const onUp = () => finish(true);
    const onCancel = () => finish(false);
    const onKey = (e) => { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); } };
    const onDown = (e) => {
      if (st || (e.button !== undefined && e.button !== 0) || e.ctrlKey || e.metaKey || e.shiftKey) return;
      if (e.target.closest('input, textarea, select, button, a[href], [contenteditable="true"], [data-nodrag]')) return;
      if (cfg.handle && !e.target.closest(cfg.handle)) return;
      const item = e.target.closest(cfg.item);
      if (!item || !cfg.root.contains(item) || (cfg.enabled && !cfg.enabled(item))) return;
      st = { item, sx: e.clientX, sy: e.clientY, x: e.clientX, y: e.clientY, touch: isTouch(e), active: false };
      if (st.touch) st.timer = setTimeout(() => { if (st && !st.active) begin(); }, 260);
      else e.preventDefault();   // pas de sélection de texte pendant le geste
      window.addEventListener('pointermove', onMove, true);
      window.addEventListener('pointerup', onUp, true);
      window.addEventListener('pointercancel', onCancel, true);
      window.addEventListener('keydown', onKey, true);
    };
    // Au doigt : une fois le déplacement commencé, la page ne doit plus défiler.
    const onTouchMove = (e) => { if (st && st.active) e.preventDefault(); };
    const onContext = (e) => { if (st && st.touch) e.preventDefault(); };
    cfg.root.addEventListener('pointerdown', onDown);
    cfg.root.addEventListener('touchmove', onTouchMove, { passive: false });
    cfg.root.addEventListener('contextmenu', onContext);
    return () => {
      finish(false);
      cfg.root.removeEventListener('pointerdown', onDown);
      cfg.root.removeEventListener('touchmove', onTouchMove);
      cfg.root.removeEventListener('contextmenu', onContext);
    };
  };

  /* ----------------------------------------------------------------- thème */
  SM.applyTheme = (theme) => {
    const t = theme || ls.get('theme', 'auto');
    ls.set('theme', t);
    const dark = t === 'dark' || (t === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  };
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => SM.applyTheme());
  SM.applyTheme();

  SM.copy = async (text, done) => {
    try { await navigator.clipboard.writeText(text); } catch (e) {
      const ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); } catch (e2) { /* copie impossible */ }
      ta.remove();
    }
    SM.toast(done || 'Link copied');
  };
})();
