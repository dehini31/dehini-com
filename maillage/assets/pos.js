/*
 * Maillage — landing page des points de vente (dehini.com/maillage/point-de-vente/) : présentation
 * du radar, inscription d'un point de vente indépendant (wilaya, commune, marques choisies).
 */
(function () {
  'use strict';
  const { html, icon, api } = ML;
  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  $$('[data-ico]').forEach((el) => { el.outerHTML = icon(el.dataset.ico); });
  // Page statique (les noms des marques sont ajoutés après) : traduction en bloc.
  ML.translateDom(document.body);
  $('#lang-slot').outerHTML = ML.langToggle('on-dark');

  const nav = $('#nav');
  const onScroll = () => nav.classList.toggle('is-solid', window.scrollY > 24);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  ML.actions.nav = (el) => { const open = document.body.classList.toggle('nav-open'); el.setAttribute('aria-expanded', open); };
  $('#nav-links').addEventListener('click', (e) => { if (e.target.closest('a')) { document.body.classList.remove('nav-open'); $('.nav-burger').setAttribute('aria-expanded', 'false'); } });

  if ('IntersectionObserver' in window && !calm) {
    const io = new IntersectionObserver((entries) => entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }), { rootMargin: '0px 0px -8% 0px' });
    $$('.reveal').forEach((el, i) => { el.style.transitionDelay = (i % 3) * 70 + 'ms'; io.observe(el); });
  } else $$('.reveal').forEach((el) => el.classList.add('is-in'));

  const form = $('[data-form="pos"]');
  ML.bootstrap().then((boot) => {
    $('[data-place]', form).innerHTML = ML.placeFields({ prefix: 'pv', required: true, addressLabel: ML.t('Adresse du magasin') });
    ML.bindPlace(form);
    // Les marques de démonstration ne sont pas proposées : un vrai point de vente ne peut pas les rejoindre.
    const brands = boot.brands.filter((b) => !b.company.demo);
    $('[data-brands]').innerHTML = brands.length
      ? html`${brands.map((b) => html`<label class="pos-brand"><input type="checkbox" name="brand" value="${b.id}"><span>${ML.mono(b.name, b.color, b.logo, 'mono-sm')}<b>${b.name}</b><small>${ML.cats[b.category] || ''}</small></span></label>`)}`
      : html`<p class="hint">${ML.t('Les premières marques arrivent : vous pourrez les choisir depuis votre espace (Réseau).')}</p>`;
    const c = boot.counts;
    if (c.stores > 0) {
      const stats = { stores: c.stores, brands: boot.brands.length, products: c.products, zones: c.zones };
      $('#stats').hidden = false;
      $$('[data-stat]').forEach((el) => { el.textContent = ML.fmt.num(stats[el.dataset.stat] || 0); });
    }
    if (!boot.config.demo) $$('[data-act="demo-store"]').forEach((b) => { b.hidden = true; });
  }).catch(() => {
    $('[data-brands]').innerHTML = html`<p class="hint">${ML.t('Vous pourrez choisir vos marques depuis votre espace.')}</p>`;
  });
  api('me').then((me) => {
    if (!me.user) return;
    $$('.nav-login, .nav-login-d').forEach((a) => { a.textContent = ML.t('Mon espace'); });
  }).catch(() => {});

  ML.forms.pos = async (f) => {
    const d = ML.formData(f);
    const btn = $('button[type=submit]', f);
    const err = $('[data-error]', f);
    const stop = (msg, field) => { err.textContent = msg; err.hidden = false; if (field && f[field]) f[field].focus(); };
    if (d.company.trim().length < 2) return stop(ML.t('Indiquez le nom de votre point de vente.'), 'company');
    if (d.name.trim().length < 2) return stop(ML.t('Indiquez votre nom.'), 'name');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(d.email.trim())) return stop(ML.t('Vérifiez votre adresse email.'), 'email');
    if (!d.zone || !d.commune) return stop(ML.t('Choisissez la wilaya puis la commune de votre point de vente.'), 'zone');
    if (d.password.length < 8) return stop(ML.t('Le mot de passe doit contenir au moins 8 caractères.'), 'password');
    if (!d.accept) return stop(ML.t('Merci d\'accepter les conditions d\'utilisation.'));
    const brands = $$('input[name=brand]:checked', f).map((el) => +el.value);
    ML.busy(btn, true);
    try {
      await api('signup', { body: {
        kind: 'pos', company: d.company, name: d.name, phone: d.phone, email: d.email, password: d.password, accept: true,
        wilaya: d.zone, commune: d.commune, address: d.address, brands, website: d.website,
      } });
      location.href = '../espace/';
    } catch (e) {
      ML.busy(btn, false);
      if (e.code === 'exists') { err.innerHTML = html`${ML.t('Un compte existe déjà avec cette adresse.')} <a class="link" href="../espace/">${ML.t('Se connecter')}</a>`; err.hidden = false; } else ML.fail(e, f);
    }
  };
  ML.actions['demo-store'] = async (el) => {
    ML.busy(el, true);
    try { await api('demo_login', { body: { kind: 'store' } }); location.href = '../espace/'; } catch (e) { ML.busy(el, false); ML.fail(e); }
  };
})();
