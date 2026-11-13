/*
 * Maillage — landing page d'inscription (dehini.com/maillage/) : menu, animation du réseau en
 * fond, chiffres et carte en direct, formulaire d'inscription, démonstration, conditions.
 */
(function () {
  'use strict';
  const { html, icon, api } = ML;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Icônes posées dans la page : <i data-ico="map-pin"></i>
  $$('[data-ico]').forEach((el) => { el.outerHTML = icon(el.dataset.ico); });

  // Page entièrement statique (pas de contenu d'utilisateur) : traduction en bloc.
  ML.translateDom(document.body);
  $('#lang-slot').outerHTML = ML.langToggle('on-dark');

  /* ------------------------------------------------------------------ menu */
  const nav = $('#nav');
  const onScroll = () => nav.classList.toggle('is-solid', window.scrollY > 24);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  ML.actions.nav = (el) => { const open = document.body.classList.toggle('nav-open'); el.setAttribute('aria-expanded', open); };
  $('#nav-links').addEventListener('click', (e) => { if (e.target.closest('a')) { document.body.classList.remove('nav-open'); $('.nav-burger').setAttribute('aria-expanded', 'false'); } });

  /* ------------------------------------------------ apparition au défilement */
  if ('IntersectionObserver' in window && !calm) {
    const io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px' });
    $$('.reveal').forEach((el, i) => { el.style.transitionDelay = (i % 3) * 70 + 'ms'; io.observe(el); });
  } else $$('.reveal').forEach((el) => el.classList.add('is-in'));

  /* ----------------------------------------- réseau animé en fond du bandeau */
  (function mesh() {
    const cv = $('#mesh');
    const ctx = cv.getContext('2d');
    let W = 0; let H = 0; let pts = []; let raf = 0; let visible = true;
    const mouse = { x: -999, y: -999 };
    const size = () => {
      const r = cv.parentElement.getBoundingClientRect();
      const dpr = Math.min(2, window.devicePixelRatio || 1);
      W = r.width; H = r.height;
      cv.width = W * dpr; cv.height = H * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      const n = Math.max(18, Math.min(70, Math.round((W * H) / 21000)));
      pts = Array.from({ length: n }, (_, i) => ({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - 0.5) * 0.22, vy: (Math.random() - 0.5) * 0.22, hot: i % 7 === 0 }));
    };
    const draw = () => {
      ctx.clearRect(0, 0, W, H);
      const D = Math.min(170, W / 4.5);
      for (let i = 0; i < pts.length; i++) {
        const a = pts[i];
        a.x += a.vx; a.y += a.vy;
        if (a.x < -20) a.x = W + 20; if (a.x > W + 20) a.x = -20;
        if (a.y < -20) a.y = H + 20; if (a.y > H + 20) a.y = -20;
        for (let j = i + 1; j < pts.length; j++) {
          const b = pts[j];
          const d = Math.hypot(a.x - b.x, a.y - b.y);
          if (d < D) {
            ctx.strokeStyle = 'rgba(255,255,255,' + (0.11 * (1 - d / D)).toFixed(3) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
          }
        }
        const dm = Math.hypot(a.x - mouse.x, a.y - mouse.y);
        if (dm < 190) {
          ctx.strokeStyle = 'rgba(255,138,101,' + (0.5 * (1 - dm / 190)).toFixed(3) + ')';
          ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
        }
        ctx.fillStyle = a.hot ? 'rgba(255,110,70,.9)' : 'rgba(255,255,255,.34)';
        ctx.beginPath(); ctx.arc(a.x, a.y, a.hot ? 2.6 : 1.7, 0, 6.2832); ctx.fill();
      }
    };
    const loop = () => { draw(); raf = visible && !document.hidden && !calm ? requestAnimationFrame(loop) : 0; };
    const start = () => { if (!raf) loop(); };
    size(); start();
    window.addEventListener('resize', ML.debounce(() => { size(); if (calm) draw(); }, 150));
    document.addEventListener('visibilitychange', start);
    if ('IntersectionObserver' in window) new IntersectionObserver(([en]) => { visible = en.isIntersecting; start(); }).observe(cv);
    cv.parentElement.addEventListener('pointermove', (e) => { const r = cv.getBoundingClientRect(); mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; });
    cv.parentElement.addEventListener('pointerleave', () => { mouse.x = -999; mouse.y = -999; });
  })();

  /* ----------------------------------------------- chiffres et listes en direct */
  const countUp = (el, to) => {
    if (calm || to < 2) { el.textContent = ML.fmt.num(to); return; }
    const t0 = performance.now();
    const tick = (t) => { const k = Math.min(1, (t - t0) / 900); el.textContent = ML.fmt.num(Math.round(to * (1 - Math.pow(1 - k, 3)))); if (k < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  };
  ML.bootstrap().then((boot) => {
    const zones = boot.zones.slice().sort((a, b) => ML.zoneName(a).localeCompare(ML.zoneName(b), ML.lang === 'ar' ? 'ar' : 'fr'));
    $('#s-zone').insertAdjacentHTML('beforeend', html`${zones.map((z) => html`<option value="${z.code}">${ML.zoneName(z)}</option>`)}`);
    $('#s-sector').insertAdjacentHTML('beforeend', html`${boot.categories.map((c) => html`<option value="${c.key}">${c.label}</option>`)}`);
    if (!boot.config.demo) $$('[data-act="demo"]').forEach((b) => { b.hidden = true; });
    const c = boot.counts;
    if (c.stores > 0) {
      const stats = { stores: c.stores, brands: boot.brands.length, products: c.products, zones: c.zones };
      $('#stats').hidden = false;
      $$('[data-stat]').forEach((el) => countUp(el, stats[el.dataset.stat] || 0));
      liveMap();
    } else $('#en-direct').hidden = true;
  }).catch(() => { $('#en-direct').hidden = true; });
  api('me').then((me) => {
    if (!me.user) return;
    $$('.nav-login, .nav-login-d').forEach((a) => { a.textContent = ML.t('Mon espace'); });
  }).catch(() => {});

  /* ------------------------------------- carte en direct (chargée à l'approche) */
  function liveMap() {
    const box = $('#live-map');
    const load = (tag, attrs) => new Promise((resolve, reject) => { const el = Object.assign(document.createElement(tag), attrs); el.onload = resolve; el.onerror = reject; document.head.appendChild(el); });
    const run = async () => {
      try {
        await Promise.all([load('link', { rel: 'stylesheet', href: ML.base + 'vendor/leaflet/leaflet.css' }), load('link', { rel: 'stylesheet', href: ML.base + 'vendor/markercluster/MarkerCluster.css' })]);
        await load('script', { src: ML.base + 'vendor/leaflet/leaflet.js' });
        await load('script', { src: ML.base + 'vendor/markercluster/leaflet.markercluster.js' });
        const stores = (await api('stores')).stores;
        box.innerHTML = '';
        const map = ML.makeMap(box, { wheel: false });
        const cluster = ML.clusters().addTo(map);
        stores.forEach((s) => {
          cluster.addLayer(L.marker([s.lat, s.lng], { icon: ML.pin(ML.TYPE_COLORS[s.type]), title: s.name })
            .bindPopup(html`<b>${s.name}</b><br><span class="muted">${s.address || s.city}</span><br><a class="link" href="explorer/#/pdv/${s.id}">${ML.t('Voir le point de vente')}</a>`.toString()));
        });
        map.fitBounds(L.latLngBounds(stores.map((s) => [s.lat, s.lng])).pad(0.1), { maxZoom: 12, animate: false });
        $('#live-count').textContent = ML.fmt.plural(stores.length, 'point de vente', 'points de vente') + ' ' + ML.t('en ligne');
      } catch (e) {
        box.innerHTML = html`<div class="live-wait"><p>${ML.t("La carte n'a pas pu se charger.")} <a class="link" href="explorer/">${ML.t('Ouvrir la plateforme')}</a></p></div>`;
      }
    };
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver(([en]) => { if (en.isIntersecting) { io.disconnect(); run(); } }, { rootMargin: '500px' });
      io.observe(box);
    } else run();
  }

  /* ----------------------------------------------------------- inscription */
  $$('[data-kind]').forEach((a) => a.addEventListener('click', () => { const r = $(`input[name=kind][value="${a.dataset.kind}"]`); if (r) r.checked = true; }));
  ML.forms.signup = async (f) => {
    const d = ML.formData(f);
    const btn = $('button[type=submit]', f);
    const err = $('[data-error]', f);
    const stop = (msg, field) => { err.textContent = msg; err.hidden = false; if (field) f[field].focus(); };
    if (d.company.trim().length < 2) return stop(ML.t('Indiquez le nom de votre entreprise.'), 'company');
    if (d.name.trim().length < 2) return stop(ML.t('Indiquez votre nom.'), 'name');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(d.email.trim())) return stop(ML.t('Vérifiez votre adresse email.'), 'email');
    if (d.password.length < 8) return stop(ML.t('Le mot de passe doit contenir au moins 8 caractères.'), 'password');
    if (!d.accept) return stop(ML.t('Merci d\'accepter les conditions d\'utilisation.'));
    ML.busy(btn, true);
    try {
      await api('signup', { body: d });
      location.href = 'espace/';
    } catch (e) {
      ML.busy(btn, false);
      if (e.code === 'exists') { err.innerHTML = html`${ML.t('Un compte existe déjà avec cette adresse.')} <a class="link" href="espace/">${ML.t('Se connecter')}</a>`; err.hidden = false; } else ML.fail(e, f);
    }
  };
  ML.actions.demo = async (el) => {
    ML.busy(el, true);
    try { await api('demo_login', { body: {} }); location.href = 'espace/'; } catch (e) { ML.busy(el, false); ML.fail(e); }
  };

})();
