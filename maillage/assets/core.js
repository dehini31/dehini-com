/*
 * Maillage — outils partagés par toutes les pages : appels à l'API, gabarits HTML échappés,
 * formats (prix, distances, dates), position du visiteur, carte Leaflet, fenêtres et messages.
 *
 * Sécurité : tout texte inséré dans la page passe par le gabarit html`…`, qui échappe chaque
 * valeur ; seules les valeurs marquées raw() (du HTML construit ici même) sont insérées telles quelles.
 * Aucun script en ligne ni attribut on…="" : les clics passent par data-act (voir ML.actions).
 */
(function () {
  'use strict';

  const BASE = new URL('..', document.currentScript.src).href; // …/maillage/
  const ML = window.ML = { base: BASE, csrf: '', boot: null, actions: {}, changes: {}, inputs: {}, forms: {} };

  /* ------------------------------------------------------------ gabarits */
  const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  const esc = ML.esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ESC[c]);
  class Raw { constructor(s) { this.s = s; } toString() { return this.s; } }
  const raw = ML.raw = (s) => new Raw(String(s == null ? '' : s));
  const piece = (v) => (v instanceof Raw ? v.s : (v === false || v == null || v === true ? '' : esc(v)));
  const html = ML.html = (strings, ...vals) => {
    let out = strings[0];
    vals.forEach((v, i) => { out += (Array.isArray(v) ? v.map(piece).join('') : piece(v)) + strings[i + 1]; });
    return new Raw(out);
  };
  /** Couleur sûre pour un attribut style (les couleurs viennent de la base). */
  const color = ML.color = (c, fallback) => (/^#[0-9a-f]{6}$/i.test(c || '') ? c : (fallback || '#94a3b8'));

  ML.icon = (name, cls) => {
    const body = (window.ML_ICONS || {})[name];
    return raw(`<svg class="ico${cls ? ' ' + cls : ''}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${body || ''}</svg>`);
  };
  const icon = ML.icon;

  ML.logo = (cls) => html`<span class="logo ${cls || ''}"><svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#ff5a2b"/><path d="M9 21 16 9.5 23 21" stroke="#fff" stroke-width="2.3" fill="none" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 21h14" stroke="#fff" stroke-width="2.3" stroke-linecap="round" opacity=".5"/><circle cx="16" cy="9.5" r="3" fill="#fff"/><circle cx="9" cy="21" r="3" fill="#fff"/><circle cx="23" cy="21" r="3" fill="#fff"/></svg>Maillage</span>`;

  /* ------------------------------------------------------------------ API */
  class ApiError extends Error {
    constructor(code, message, status) { super(message); this.code = code; this.status = status || 0; }
  }
  ML.ApiError = ApiError;
  ML.api = async (action, opts) => {
    opts = opts || {};
    const url = new URL('api.php', BASE);
    url.searchParams.set('action', action);
    Object.entries(opts.params || {}).forEach(([k, v]) => { if (v !== '' && v != null && v !== false) url.searchParams.set(k, v === true ? '1' : v); });
    const init = { credentials: 'same-origin', headers: {} };
    if (opts.body !== undefined) {
      init.method = 'POST';
      init.headers['X-Requested-With'] = 'maillage';
      if (ML.csrf) init.headers['X-CSRF'] = ML.csrf;
      if (opts.body instanceof FormData) init.body = opts.body;
      else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body || {}); }
    }
    let res; let data;
    try {
      res = await fetch(url, init);
      data = await res.json();
    } catch (e) {
      throw new ApiError('network', 'Connexion impossible. Vérifiez votre réseau et réessayez.');
    }
    if (!res.ok || data.error) throw new ApiError(data.error || 'server', data.message || 'Une erreur est survenue.', res.status);
    return data;
  };
  /** Données de départ (réglages, zones, rayons, marques) — chargées une fois par page. */
  ML.bootstrap = async () => {
    if (!ML.boot) {
      ML.boot = await ML.api('bootstrap');
      ML.zones = {};
      ML.boot.zones.forEach((z) => { ML.zones[z.code] = z; });
      ML.cats = {};
      ML.boot.categories.forEach((c) => { ML.cats[c.key] = c.label; });
    }
    return ML.boot;
  };

  /* -------------------------------------------------------------- formats */
  const nf = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
  const nf1 = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 });
  const fmt = ML.fmt = {
    num: (v) => nf.format(v || 0),
    dec: (v) => nf1.format(v || 0),
    price: (v) => nf.format(Math.round(v || 0)) + ' ' + ((ML.boot && ML.boot.config.currency) || 'DA'),
    /** 1 284 · 12,9 k · 4,2 M */
    compact: (v) => {
      v = v || 0;
      const a = Math.abs(v);
      if (a >= 1e6) return nf1.format(v / 1e6) + ' M';
      if (a >= 1e4) return nf1.format(v / 1e3) + ' k';
      return nf.format(v);
    },
    km: (km) => {
      if (km == null) return '';
      if (km < 1) return Math.max(50, Math.round(km * 20) * 50) + ' m';
      if (km < 10) return nf1.format(km) + ' km';
      return nf.format(km) + ' km';
    },
    date: (ts) => new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(ts * 1000)),
    day: (ts) => new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' }).format(new Date(ts * 1000)),
    time: (ts) => new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(ts * 1000)),
    ago: (ts) => {
      const s = Math.max(0, Date.now() / 1000 - ts);
      if (s < 90) return 'à l\'instant';
      if (s < 3600) return 'il y a ' + Math.round(s / 60) + ' min';
      if (s < 86400) return 'il y a ' + Math.round(s / 3600) + ' h';
      if (s < 172800) return 'hier';
      if (s < 7 * 86400) return 'il y a ' + Math.round(s / 86400) + ' j';
      return fmt.day(ts);
    },
    plural: (n, one, many) => nf.format(n) + ' ' + (n > 1 ? (many || one + 's') : one),
  };
  ML.debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
  ML.norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
  ML.media = (path, w) => (path ? BASE + path + '-' + (w || 480) + '.webp' : '');
  ML.initials = (name) => String(name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

  const ls = ML.ls = {
    get: (k, d) => { try { const v = localStorage.getItem('mlg.' + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: (k, v) => { try { localStorage.setItem('mlg.' + k, JSON.stringify(v)); } catch (e) { /* stockage indisponible */ } },
    del: (k) => { try { localStorage.removeItem('mlg.' + k); } catch (e) { /* idem */ } },
  };

  /* ------------------------------------------------------------- horaires */
  const DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  const DAY_KEYS = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const toMin = (t) => { const [h, m] = String(t || '0:0').split(':'); return (+h) * 60 + (+m); };
  /** Jour et minute « maintenant » dans le fuseau de la plateforme. */
  const nowLocal = () => {
    try {
      const parts = new Intl.DateTimeFormat('en-GB', { timeZone: (ML.boot && ML.boot.config.tz) || 'Africa/Algiers', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(new Date());
      const p = {};
      parts.forEach((x) => { p[x.type] = x.value; });
      return { day: DAY_KEYS[p.weekday], min: (+p.hour % 24) * 60 + (+p.minute) };
    } catch (e) {
      const d = new Date();
      return { day: d.getDay(), min: d.getHours() * 60 + d.getMinutes() };
    }
  };
  /** { open: true|false|null, label } */
  ML.openState = (h) => {
    if (!h || !h.days || !h.days.length) return { open: null, label: '' };
    const { day, min } = nowLocal();
    const o = toMin(h.open); const c = toMin(h.close);
    if (h.days.includes(day) && min >= o && min < c) return { open: true, label: ML.t('Ouvert · ferme à') + ' ' + h.close };
    for (let i = 0; i < 8; i++) {
      const d = (day + i) % 7;
      if (h.days.includes(d) && (i > 0 || min < o)) {
        return { open: false, label: ML.t('Fermé · ouvre') + ' ' + (i === 0 ? '' : i === 1 ? ML.t('demain') + ' ' : ML.DAYS[d] + ' ') + ML.t('à') + ' ' + h.open };
      }
    }
    return { open: false, label: ML.t('Fermé') };
  };
  ML.hoursText = (h) => {
    if (!h || !h.days || !h.days.length) return '';
    const closed = [0, 1, 2, 3, 4, 5, 6].filter((d) => !h.days.includes(d));
    const days = closed.length === 0 ? ML.t('Tous les jours') : closed.length <= 2 ? ML.t('Tous les jours sauf') + ' ' + closed.map((d) => ML.DAYS[d]).join(' ' + ML.t('et') + ' ') : h.days.map((d) => ML.DAYS[d]).join(' ');
    return days + ' · ' + h.open + ' – ' + h.close;
  };
  ML.DAYS = DAYS;

  /* ------------------------------------------------------------- position */
  ML.dist = (a, b) => {
    const p = Math.PI / 180;
    const x = 0.5 - Math.cos((b.lat - a.lat) * p) / 2 + Math.cos(a.lat * p) * Math.cos(b.lat * p) * (1 - Math.cos((b.lng - a.lng) * p)) / 2;
    return 12742 * Math.asin(Math.sqrt(Math.max(0, Math.min(1, x))));
  };
  ML.nearestZone = (pt) => {
    let best = null; let bd = Infinity;
    (ML.boot ? ML.boot.zones : []).forEach((z) => { const d = ML.dist(pt, z); if (d < bd) { bd = d; best = z; } });
    return best;
  };
  ML.loc = ls.get('loc', null);
  ML.setLoc = (loc) => {
    ML.loc = loc;
    if (loc) ls.set('loc', loc); else ls.del('loc');
    document.dispatchEvent(new CustomEvent('mlg:loc'));
  };
  /** Paramètres de position à joindre aux recherches. */
  ML.locParams = () => (ML.loc ? { lat: ML.loc.precise ? ML.loc.lat : '', lng: ML.loc.precise ? ML.loc.lng : '', zone: ML.loc.zone || '' } : {});
  ML.locate = () => new Promise((resolve, reject) => {
    if (!navigator.geolocation) { reject(new Error('La géolocalisation n\'est pas disponible sur cet appareil.')); return; }
    navigator.geolocation.getCurrentPosition((pos) => {
      const pt = { lat: pos.coords.latitude, lng: pos.coords.longitude };
      const z = ML.nearestZone(pt);
      const loc = { lat: pt.lat, lng: pt.lng, label: ML.t('Ma position'), zone: z ? z.code : '', precise: true };
      ML.setLoc(loc);
      resolve(loc);
    }, (err) => reject(new Error(err.code === 1 ? 'Position refusée. Choisissez plutôt votre ville.' : 'Position introuvable. Choisissez plutôt votre ville.')), { enableHighAccuracy: false, timeout: 9000, maximumAge: 300000 });
  });
  /** Fenêtre « Où êtes-vous ? » : ma position ou une ville de la liste. */
  ML.pickLocation = () => new Promise((resolve) => {
    const zones = ML.boot.zones.slice().sort((a, b) => a.name.localeCompare(b.name, 'fr'));
    const list = (q) => {
      const n = ML.norm(q);
      const found = zones.filter((z) => !n || ML.norm(z.name).includes(n) || z.code === n);
      return found.length ? html`${found.map((z) => html`<button class="loc-item" type="button" data-zone="${z.code}">${icon('map-pin')}<span>${z.name}</span><small class="dim">${z.code}</small></button>`)}` : html`<p class="muted" style="padding:14px 4px">Aucune ville ne correspond.</p>`;
    };
    const m = ML.modal({
      title: 'Où êtes-vous ?',
      body: html`
        <p class="muted" style="margin-bottom:14px">Pour classer les points de vente du plus proche au plus loin.</p>
        <button class="btn btn-dark btn-block" type="button" data-geo>${icon('locate-fixed')} Utiliser ma position</button>
        <p class="form-error" data-err hidden style="margin-top:10px"></p>
        <div class="input-icon" style="margin:16px 0 8px">${icon('search')}<input class="input" type="search" placeholder="Chercher une ville…" autocomplete="off" data-q></div>
        <div class="loc-list" data-list>${list('')}</div>
        ${ML.loc ? html`<button class="btn btn-ghost btn-block" type="button" data-clear style="margin-top:8px">Ne plus utiliser ma position</button>` : ''}`,
      onClose: () => resolve(ML.loc),
    });
    const el = m.el;
    el.querySelector('[data-q]').addEventListener('input', (e) => { el.querySelector('[data-list]').innerHTML = list(e.target.value); });
    el.addEventListener('click', async (e) => {
      const z = e.target.closest('[data-zone]');
      if (z) {
        const zone = ML.zones[z.dataset.zone];
        ML.setLoc({ lat: zone.lat, lng: zone.lng, label: zone.name, zone: zone.code, precise: false });
        m.close();
      } else if (e.target.closest('[data-geo]')) {
        const b = e.target.closest('[data-geo]');
        b.classList.add('is-busy');
        try { await ML.locate(); m.close(); } catch (err) {
          const box = el.querySelector('[data-err]');
          box.textContent = err.message; box.hidden = false;
        }
        b.classList.remove('is-busy');
      } else if (e.target.closest('[data-clear]')) {
        ML.setLoc(null);
        m.close();
      }
    });
  });

  /* ---------------------------------------------------- fenêtres, messages */
  ML.modal = (o) => {
    const wrap = document.createElement('div');
    wrap.className = 'modal-wrap';
    wrap.innerHTML = html`
      <div class="modal ${o.wide ? 'modal-wide' : ''}" role="dialog" aria-modal="true" aria-label="${o.title || ''}">
        <div class="modal-head"><h2>${o.title || ''}</h2><button class="btn btn-ghost btn-icon btn-sm" type="button" data-close aria-label="Fermer">${icon('x')}</button></div>
        <div class="modal-body">${o.body || ''}</div>
        ${o.foot ? html`<div class="modal-foot">${o.foot}</div>` : ''}
      </div>`;
    const prev = document.activeElement;
    let closed = false;
    const close = () => {
      if (closed) return;
      closed = true;
      wrap.remove();
      document.removeEventListener('keydown', onKey);
      if (!document.querySelector('.modal-wrap')) document.documentElement.style.overflow = '';
      if (prev && prev.focus) prev.focus({ preventScroll: true });
      if (o.onClose) o.onClose();
    };
    const onKey = (e) => { if (e.key === 'Escape' && wrap === Array.from(document.querySelectorAll('.modal-wrap')).pop()) close(); };
    wrap.addEventListener('mousedown', (e) => { if (e.target === wrap) close(); });
    wrap.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) close(); });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);
    document.documentElement.style.overflow = 'hidden';
    const first = wrap.querySelector('[autofocus], .modal-body input:not([type=hidden]), .modal-body select, .modal-body textarea');
    if (first && window.matchMedia('(min-width: 720px)').matches) first.focus({ preventScroll: true });
    else wrap.querySelector('[data-close]').focus({ preventScroll: true });
    return { el: wrap, close };
  };
  /** Ferme les fenêtres ouvertes (appelé à chaque changement de page). */
  ML.closeModals = () => document.querySelectorAll('.modal-wrap').forEach((w) => { const b = w.querySelector('[data-close]'); if (b) b.click(); });
  ML.confirm = (message, o) => new Promise((resolve) => {
    o = o || {};
    let answer = false;
    const m = ML.modal({
      title: o.title || ML.t('Confirmer'),
      body: html`<p>${message}</p>`,
      foot: html`<button class="btn btn-line" type="button" data-close>${ML.t('Annuler')}</button><button class="btn ${o.danger ? 'btn-danger' : 'btn-primary'}" type="button" data-yes>${o.ok || ML.t('Confirmer')}</button>`,
      onClose: () => resolve(answer),
    });
    m.el.querySelector('[data-yes]').addEventListener('click', () => { answer = true; m.close(); });
  });
  ML.toast = (message, kind) => {
    let box = document.getElementById('toasts');
    if (!box) { box = document.createElement('div'); box.id = 'toasts'; box.setAttribute('role', 'status'); box.setAttribute('aria-live', 'polite'); document.body.appendChild(box); }
    const t = document.createElement('div');
    t.className = 'toast' + (kind ? ' is-' + kind : '');
    t.innerHTML = html`${kind === 'good' ? icon('circle-check') : kind === 'bad' ? icon('triangle-alert') : ''}<span>${message}</span>`;
    box.appendChild(t);
    setTimeout(() => t.remove(), kind === 'bad' ? 6000 : 3600);
  };
  /** Affiche l'erreur d'un appel à l'API (dans la zone d'erreur d'un formulaire, sinon en message). */
  ML.fail = (err, form) => {
    const box = form && form.querySelector('[data-error]');
    const msg = (err && err.message) || 'Une erreur est survenue.';
    if (box) { box.textContent = msg; box.hidden = false; box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } else ML.toast(msg, 'bad');
  };
  ML.busy = (btn, on) => { if (btn) { btn.classList.toggle('is-busy', on); btn.disabled = !!on; } };
  ML.formData = (form) => {
    const o = {};
    Array.from(form.elements).forEach((el) => {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox') o[el.name] = el.checked;
      else if (el.type === 'radio') { if (el.checked) o[el.name] = el.value; } else o[el.name] = el.value;
    });
    return o;
  };

  /* --------------------------------------------- actions (data-act, data-form…) */
  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-act]');
    if (!el || el.disabled) return;
    const fn = ML.actions[el.dataset.act];
    if (fn) { if (el.tagName === 'A' || el.tagName === 'BUTTON') e.preventDefault(); fn(el, e); }
  });
  document.addEventListener('change', (e) => {
    const el = e.target.closest('[data-change]');
    if (el && ML.changes[el.dataset.change]) ML.changes[el.dataset.change](el, e);
  });
  document.addEventListener('input', (e) => {
    const el = e.target.closest('[data-input]');
    if (el && ML.inputs[el.dataset.input]) ML.inputs[el.dataset.input](el, e);
  });
  document.addEventListener('submit', (e) => {
    const el = e.target.closest('[data-form]');
    if (!el) return;
    e.preventDefault();
    const err = el.querySelector('[data-error]');
    if (err) err.hidden = true;
    if (ML.forms[el.dataset.form]) ML.forms[el.dataset.form](el, e);
  });

  /* ---------------------------------------------------------------- routes */
  ML.route = () => {
    const h = location.hash.replace(/^#/, '') || '/';
    const i = h.indexOf('?');
    const path = i < 0 ? h : h.slice(0, i);
    const query = {};
    new URLSearchParams(i < 0 ? '' : h.slice(i + 1)).forEach((v, k) => { query[k] = v; });
    return { path, query, parts: path.split('/').filter(Boolean).map((p) => { try { return decodeURIComponent(p); } catch (e) { return p; } }) };
  };
  ML.href = (path, query) => {
    const q = new URLSearchParams();
    Object.entries(query || {}).forEach(([k, v]) => { if (v !== '' && v != null && v !== false) q.set(k, v); });
    const s = q.toString();
    return '#' + path + (s ? '?' + s : '');
  };
  ML.go = (path, query, replace) => {
    const h = ML.href(path, query);
    if (replace) history.replaceState(null, '', h); else location.hash = h;
    if (replace) window.dispatchEvent(new HashChangeEvent('hashchange'));
  };

  /* ------------------------------------------------------------------ carte */
  ML.TYPE_COLORS = { magasin: '#ff5a2b', succursale: '#0e9384', distributeur: '#344054' };
  ML.makeMap = (el, o) => {
    o = o || {};
    const c = ML.boot.config;
    const map = L.map(el, { zoomControl: false, scrollWheelZoom: o.wheel !== false, attributionControl: true, minZoom: 4, maxZoom: 18, worldCopyJump: true })
      .setView(o.center || c.center, o.zoom || c.zoom);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer(c.tiles, { attribution: c.attr, maxZoom: 19 }).addTo(map);
    map.attributionControl.setPrefix(false);
    return map;
  };
  ML.pin = (c, cls) => L.divIcon({
    className: '',
    html: `<div class="pin ${cls || ''}" style="--c:${color(c, '#ff5a2b')}"><svg viewBox="0 0 34 42" aria-hidden="true"><path class="pin-body" d="M17 1.5C8.4 1.5 1.5 8.3 1.5 16.8 1.5 27.6 17 40.5 17 40.5s15.5-12.9 15.5-23.7C32.5 8.3 25.6 1.5 17 1.5z"/><circle class="pin-dot" cx="17" cy="16.5" r="5.6"/></svg></div>`,
    iconSize: [34, 42], iconAnchor: [17, 41], popupAnchor: [0, -38],
  });
  ML.mePin = () => L.divIcon({ className: '', html: '<div class="pin-me"></div>', iconSize: [20, 20], iconAnchor: [10, 10] });
  ML.clusters = () => L.markerClusterGroup({
    showCoverageOnHover: false, maxClusterRadius: 44, spiderfyOnMaxZoom: true,
    iconCreateFunction: (c) => L.divIcon({ className: '', html: `<div class="cluster">${c.getChildCount()}</div>`, iconSize: [42, 42], iconAnchor: [21, 21] }),
  });
  /** Lien d'itinéraire vers un point (depuis la position du visiteur si elle est connue). */
  ML.routeUrl = (pt) => 'https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=' + (ML.loc && ML.loc.precise ? ML.loc.lat + '%2C' + ML.loc.lng : '') + '%3B' + pt.lat + '%2C' + pt.lng;

  /* ------------------------------------------------------ visuels de produit */
  const KEY_ICONS = [
    [/chaussur|basket|sneaker|sandale|botte/, 'footprints'], [/sac\b|sac a dos|cartable|valise/, 'backpack'], [/gourde|bouteille/, 'cup-soda'],
    [/verre|tasse|mug|\bthe\b/, 'glass-water'], [/\blit\b|parure|drap|couette|oreiller|coussin|plaid/, 'bed-double'], [/serviette|bain|peignoir/, 'bath'],
    [/bougie|parfum/, 'flame'], [/tapis|rideau|nappe|voilage/, 'blinds'], [/bouilloire|cafe|cafetiere/, 'coffee'],
    [/mixeur|robot|friteuse|grille|four\b|cuisin/, 'cooking-pot'], [/aspirateur|fer a repasser|ventilateur|clim/, 'fan'],
    [/ecouteur|casque|enceinte|audio/, 'headphones'], [/telephone|smartphone|tablette/, 'smartphone'], [/montre/, 'watch'],
  ];
  const CAT_ICONS = { mode: 'shirt', maison: 'sofa', electro: 'plug', 'high-tech': 'smartphone', beaute: 'sparkles', alimentation: 'utensils', bricolage: 'hammer', auto: 'car', enfants: 'blocks', autre: 'package' };
  ML.productIcon = (p) => {
    const n = ML.norm(p.name);
    const hit = KEY_ICONS.find(([re]) => re.test(n));
    return hit ? hit[1] : (CAT_ICONS[p.category] || 'package');
  };
  /** Photo du produit, ou vignette générée (teinte de la couleur choisie, sinon de la marque). */
  ML.visual = (p, o) => {
    o = o || {};
    const img = o.image !== undefined ? o.image : (p.image || (p.images && p.images[0]) || '');
    if (img) return html`<div class="pv ${o.cls || ''}"><img src="${ML.media(img, o.w || 480)}" alt="${p.name}" loading="lazy" decoding="async"></div>`;
    const c = color(o.tint || (p.colors && p.colors[0] && p.colors[0].hex) || (p.brand && p.brand.color), '#94a3b8');
    return html`<div class="pv ${o.cls || ''}" style="--c:${c}">${icon(ML.productIcon(p))}</div>`;
  };
  ML.mono = (name, c, logo, cls) => html`<span class="mono ${cls || ''}" style="--c:${color(c, '#101828')}">${logo ? html`<img src="${ML.media(logo, 480)}" alt="">` : ML.initials(name)}</span>`;

  ML.STATUS = {
    pending: { label: 'En attente', cls: 'badge-warn', icon: 'clock' },
    confirmed: { label: 'Confirmée', cls: 'badge-info', icon: 'check' },
    ready: { label: 'Prête au retrait', cls: 'badge-brand', icon: 'package-check' },
    shipped: { label: 'En livraison', cls: 'badge-brand', icon: 'truck' },
    done: { label: 'Terminée', cls: 'badge-good', icon: 'circle-check' },
    cancelled: { label: 'Annulée', cls: 'badge-bad', icon: 'x' },
  };
  ML.statusLabel = (o) => {
    if (o.status === 'done') return o.mode === 'delivery' ? ML.t('Livrée') : ML.t('Retirée');
    return ML.STATUS[o.status] ? ML.t(ML.STATUS[o.status].label) : o.status;
  };
  ML.statusBadge = (o) => {
    const s = ML.STATUS[o.status] || { cls: '', icon: 'circle' };
    return html`<span class="badge ${s.cls}">${icon(s.icon)}${ML.statusLabel(o)}</span>`;
  };
  ML.kindLabel = (o) => (o.kind === 'reservation' ? ML.t('Réservation') : o.mode === 'delivery' ? ML.t('Livraison') : ML.t('Retrait en magasin'));
})();
