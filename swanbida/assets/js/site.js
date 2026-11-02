/* SWANBIDA — interactions du site : menu, animations, galerie, formulaire de commande, suivi Meta.
   Aucune dépendance. Chaque événement Meta part avec un event_id unique, envoyé aussi au serveur
   (Conversions API) pour que Meta déduplique. Purchase n'est JAMAIS envoyé par le navigateur. */
(function () {
  'use strict';
  var S = window.SWB || {};
  var doc = document;
  var $ = function (s, r) { return (r || doc).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || doc).querySelectorAll(s)); };
  var AR = S.lang === 'ar';
  var I = S.i18n || {};

  function rid(n) {
    var a = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', out = '', buf = new Uint8Array(n);
    (window.crypto || window.msCrypto).getRandomValues(buf);
    for (var i = 0; i < n; i++) out += a[buf[i] % a.length];
    return out;
  }
  function getCookie(n) {
    var m = doc.cookie.match('(?:^|; )' + n.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }
  function setCookie(n, v, days) {
    doc.cookie = n + '=' + encodeURIComponent(v) + '; Max-Age=' + days * 86400 + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }
  function store(k, v) {
    try { if (v === undefined) return JSON.parse(localStorage.getItem(k) || 'null'); localStorage.setItem(k, JSON.stringify(v)); } catch (e) { return null; }
  }
  function money(n) {
    var s = Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    return s + (AR ? ' دج' : ' DA');
  }
  function fmt(s, vars) { return String(s || '').replace(/\{(\w+)\}/g, function (_, k) { return vars[k] != null ? vars[k] : ''; }); }

  /* ------------------------------------------------ attribution publicitaire */
  var ATTR_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id', 'campaign_id', 'adset_id', 'ad_id', 'placement', 'site_source_name', 'fbclid'];
  var attrStore = (function () {
    var st = store('swb_attr') || {};
    if (st.ts && Date.now() - st.ts > 30 * 864e5) st = {};
    var q = new URLSearchParams(location.search), got = {}, any = false;
    ATTR_KEYS.forEach(function (k) { var v = q.get(k); if (v) { got[k] = v.slice(0, 500); any = true; } });
    if (!got.utm_source && q.get('fbclid')) { got.utm_source = 'facebook'; }
    if (any) {
      got.landing = location.href.split('#')[0].slice(0, 500);
      got.referrer = (doc.referrer || '').slice(0, 300);
      got.ts = String(Date.now());
      st.last = got;
      if (!st.first) st.first = got;
      st.ts = Date.now();
      store('swb_attr', st);
    }
    if (got.fbclid && !getCookie('_fbc')) setCookie('_fbc', 'fb.1.' + Date.now() + '.' + got.fbclid, 90);
    return st;
  })();
  function attribution() {
    var a = attrStore.last ? Object.assign({}, attrStore.last) : {};
    if (!a.referrer && doc.referrer && doc.referrer.indexOf(location.host) === -1) a.referrer = doc.referrer.slice(0, 300);
    return a;
  }

  /* ------------------------------------------------------------- suivi Meta */
  var ABBR = { PageView: 'pv', ViewContent: 'vc', AddToCart: 'atc', InitiateCheckout: 'ic', Contact: 'ct', Lead: 'lead' };
  function beacon(data) {
    var body = JSON.stringify(data), url = S.api + '?action=t';
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' }))) return;
    } catch (e) { /* repli */ }
    try { fetch(url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } }); } catch (e) { /* hors ligne */ }
  }
  function track(name, custom, extra, eventId) {
    var id = eventId || (ABBR[name] + '.' + rid(16));
    if (S.pixel && window.fbq) { try { window.fbq('track', name, custom || {}, { eventID: id }); } catch (e) { /* bloqué */ } }
    var b = { e: name, id: id, u: location.href, fbp: getCookie('_fbp'), fbc: getCookie('_fbc') };
    if (extra) for (var k in extra) b[k] = extra[k];
    beacon(b);
    return id;
  }
  var once = {};
  function trackOnce(name, custom, extra) { if (once[name]) return; once[name] = 1; track(name, custom, extra); }
  function productCustom(q) {
    var P = S.product; if (!P) return {};
    var t = P.tiers.filter(function (x) { return x.qty === q; })[0] || P.tiers[0];
    return { content_ids: [P.sku], content_type: 'product', content_name: P.name, value: t.price, currency: 'DZD', contents: [{ id: P.sku, quantity: t.qty }], num_items: t.qty };
  }

  // PageView : le pixel l'a déjà envoyé dans <head> avec S.pv ; on le double côté serveur.
  // Petit délai pour laisser le pixel poser le cookie _fbp lors d'une première visite.
  var firstInSession = (function () { try { if (sessionStorage.getItem('swb_s')) return 0; sessionStorage.setItem('swb_s', '1'); return 1; } catch (e) { return 0; } })();
  setTimeout(function () {
    beacon({ e: 'PageView', id: S.pv, u: location.href, n: firstInSession, fbp: getCookie('_fbp'), fbc: getCookie('_fbc') });
    if (S.page === 'product' && S.product) {
      track('ViewContent', productCustom(S.product.default), { p: S.product.id, q: S.product.default });
    }
  }, 900);

  doc.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-contact]');
    if (a) trackOnce('Contact', { content_category: a.getAttribute('data-contact') });
  });

  /* ------------------------------------------------------- en-tête et menu */
  var header = $('.site-header');
  if (header) {
    var onScroll = function () { header.classList.toggle('is-stuck', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }
  var drawer = $('#drawer'), menuBtn = $('[data-menu]');
  function closeMenu() { if (!drawer) return; drawer.hidden = true; doc.body.style.overflow = ''; menuBtn && menuBtn.setAttribute('aria-expanded', 'false'); }
  if (drawer && menuBtn) {
    menuBtn.addEventListener('click', function () {
      drawer.hidden = false; doc.body.style.overflow = 'hidden'; menuBtn.setAttribute('aria-expanded', 'true');
      var f = $('a, button', drawer); f && f.focus();
    });
    drawer.addEventListener('click', function (e) { if (e.target === drawer || e.target.closest('[data-menu-close]') || e.target.closest('a')) closeMenu(); });
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
  }

  /* ----------------------------------------------------- apparition douce */
  var reveals = $$('.reveal');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-in'); });
  }

  /* --------------------------------------------------------------- galerie */
  var gal = $('[data-gallery]');
  if (gal) {
    var trackEl = $('[data-track]', gal), slides = $$('.g-slide', gal), thumbs = $$('.g-thumb', gal), count = $('[data-count]', gal), cur = 0;
    var go = function (i) {
      if (!slides.length) return;
      i = (i + slides.length) % slides.length;
      // Écart mesuré à l'écran : juste dans les deux sens d'écriture (en arabe, scrollLeft est négatif).
      var delta = slides[i].getBoundingClientRect().left - trackEl.getBoundingClientRect().left;
      trackEl.scrollTo({ left: trackEl.scrollLeft + delta, behavior: 'smooth' });
      setCur(i);
    };
    var setCur = function (i) {
      cur = i;
      thumbs.forEach(function (t, k) { t.classList.toggle('is-on', k === i); t.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
      if (count) count.textContent = (i + 1) + ' / ' + slides.length;
      var th = thumbs[i], tc = th && th.parentNode;
      if (tc && tc.scrollWidth > tc.clientWidth) {
        var d = th.getBoundingClientRect().left - tc.getBoundingClientRect().left - (tc.clientWidth - th.offsetWidth) / 2;
        tc.scrollTo({ left: tc.scrollLeft + d, behavior: 'smooth' });
      }
      $$('video', gal).forEach(function (v, k) { if (!v.closest('.g-slide').isSameNode(slides[i])) v.pause(); });
    };
    if ('IntersectionObserver' in window) {
      var gio = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting && en.intersectionRatio > 0.6) setCur(slides.indexOf(en.target)); });
      }, { root: trackEl, threshold: [0.6] });
      slides.forEach(function (s) { gio.observe(s); });
    }
    var prev = $('[data-prev]', gal), next = $('[data-next]', gal);
    prev && prev.addEventListener('click', function () { go(cur - 1); });
    next && next.addEventListener('click', function () { go(cur + 1); });
    thumbs.forEach(function (t) { t.addEventListener('click', function () { go(+t.getAttribute('data-go')); }); });
    trackEl.addEventListener('keydown', function (e) {
      var fwd = AR ? 'ArrowLeft' : 'ArrowRight', back = AR ? 'ArrowRight' : 'ArrowLeft';
      if (e.key === fwd) { e.preventDefault(); go(cur + 1); }
      if (e.key === back) { e.preventDefault(); go(cur - 1); }
    });
    $$('[data-yt]', gal).forEach(function (b) {
      b.addEventListener('click', function () {
        var f = doc.createElement('iframe');
        f.src = 'https://www.youtube-nocookie.com/embed/' + b.getAttribute('data-yt') + '?autoplay=1&rel=0&playsinline=1';
        f.allow = 'autoplay; encrypted-media; picture-in-picture'; f.allowFullscreen = true; f.title = 'YouTube';
        b.replaceWith(f);
      });
    });
    // Visionneuse plein écran.
    var lb = $('[data-lightbox]');
    if (lb) {
      var lbTrack = $('[data-lb-track]', lb), lbSlides = $$('[data-lb-slide]', lb), lbCount = $('[data-lb-count]', lb), lastFocus = null;
      var lbIdx = 0;
      var lbShow = function (i) {
        lbSlides.forEach(function (s) { var img = $('img', s); if (!img.src) img.src = img.getAttribute('data-src'); s.classList.remove('zoomed'); });
        lb.hidden = false; doc.body.style.overflow = 'hidden';
        var target = lbSlides.filter(function (s) { return +s.getAttribute('data-lb-slide') === i; })[0] || lbSlides[0];
        lbIdx = lbSlides.indexOf(target);
        requestAnimationFrame(function () { lbTrack.scrollLeft += target.getBoundingClientRect().left - lbTrack.getBoundingClientRect().left; });
        if (lbCount) lbCount.textContent = fmt(I.photo_n, { n: lbIdx + 1, t: lbSlides.length });
        $('[data-lb-close]', lb).focus();
      };
      var lbClose = function () { lb.hidden = true; doc.body.style.overflow = ''; lastFocus && lastFocus.focus(); };
      $$('[data-open]', gal).forEach(function (b) {
        b.addEventListener('click', function () { lastFocus = b; lbShow(+b.getAttribute('data-open')); });
      });
      $('[data-lb-close]', lb).addEventListener('click', lbClose);
      lb.addEventListener('keydown', function (e) { if (e.key === 'Escape') lbClose(); });
      lbSlides.forEach(function (s) {
        $('img', s).addEventListener('click', function () { s.classList.toggle('zoomed'); });
      });
      if ('IntersectionObserver' in window) {
        var lio = new IntersectionObserver(function (entries) {
          entries.forEach(function (en) { if (en.isIntersecting && en.intersectionRatio > 0.6) { lbIdx = lbSlides.indexOf(en.target); lbCount.textContent = fmt(I.photo_n, { n: lbIdx + 1, t: lbSlides.length }); } });
        }, { root: lbTrack, threshold: [0.6] });
        lbSlides.forEach(function (s) { lio.observe(s); });
      }
    }
  }

  /* ---------------------------------------------------- formulaire de commande */
  var form = $('[data-order-form]');
  if (form) initOrderForm(form);

  function initOrderForm(form) {
    var P = S.product || {};
    var tierInputs = $$('input[name="qty"]', form);
    var wSel = $('[data-wilaya]', form), cInput = $('[data-commune]', form), submit = $('[data-submit]', form);
    var errBox = $('[data-form-err]', form), sending = false, geo = null, geoPromise = null, cSel = null;
    var keyEl = $('[data-key]', form);
    var fe = function (n) { return form.elements.namedItem(n); };
    keyEl.value = rid(24);
    window.addEventListener('pageshow', function (e) { if (e.persisted) { keyEl.value = rid(24); setBusy(false); } });

    function tierQty() { var c = tierInputs.filter(function (i) { return i.checked; })[0]; return c ? +c.value : 0; }
    function tierPrice() { var c = tierInputs.filter(function (i) { return i.checked; })[0]; return c ? +c.getAttribute('data-price') : 0; }
    function tierCompare() { var c = tierInputs.filter(function (i) { return i.checked; })[0]; return c ? +c.getAttribute('data-compare') : 0; }
    function delivery() { var d = $('input[name="delivery"]:checked', form); return d ? d.value : 'home'; }
    function shipping() {
      var o = wSel.options[wSel.selectedIndex];
      if (!o || !o.value) return null;
      return +(o.getAttribute(delivery() === 'desk' ? 'data-d' : 'data-h') || 0);
    }
    function refresh() {
      var sub = tierPrice(), ship = shipping(), tot = sub + (ship || 0), cmp = tierCompare();
      $('[data-sum-sub]', form).textContent = money(sub);
      if (ship !== null) $('[data-sum-ship]', form).textContent = ship ? money(ship) : I.shipping_free;
      $('[data-sum-total]', form).textContent = money(tot);
      $('[data-submit-total]', form).textContent = money(tot);
      var box = $('[data-price-box]');
      if (box) {
        $('.price-now', box).textContent = money(sub);
        var old = $('.price-old', box);
        if (cmp > sub) {
          if (!old) { old = doc.createElement('s'); old.className = 'price-old'; $('.price', box).appendChild(old); }
          old.textContent = money(cmp);
        } else if (old) old.remove();
        var sv = $('[data-save]', box);
        if (sv) { sv.hidden = !(cmp > sub); sv.textContent = fmt(I.save, { x: money(cmp - sub) }); }
      }
      var sticky = $('[data-sticky] .price-now'); if (sticky) sticky.textContent = money(sub);
    }
    tierInputs.forEach(function (i) {
      i.addEventListener('change', function () {
        refresh(); clearErr('qty');
        trackOnce('AddToCart', productCustom(tierQty()), { p: P.id, q: tierQty() });
      });
    });
    $$('input[name="delivery"]', form).forEach(function (i) { i.addEventListener('change', refresh); });

    // Communes : la liste dépend de la wilaya (chargée une seule fois, mise en cache par le navigateur).
    function loadGeo() {
      if (!geoPromise) geoPromise = fetch(S.geo, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        geo = {}; (j.w || []).forEach(function (w) { geo[w.c] = w; }); return geo;
      }).catch(function () { geoPromise = null; return null; });
      return geoPromise;
    }
    var otherVal = '__other__';
    function ensureCSel() {
      if (cSel) return;
      cSel = doc.createElement('select');
      cSel.id = 'f-commune-sel'; cSel.setAttribute('autocomplete', 'address-level2');
      cInput.parentNode.insertBefore(cSel, cInput);
      var lab = $('label[for="f-commune"]', form); if (lab) lab.setAttribute('for', 'f-commune-sel');
      cSel.addEventListener('change', function () {
        if (cSel.value === otherVal) { cInput.hidden = false; cInput.value = ''; cInput.focus(); }
        else { cInput.hidden = true; cInput.value = cSel.value; }
        clearErr('commune');
      });
    }
    function buildCommunes() {
      var w = geo && geo[wSel.value];
      if (!w || !w.m || !w.m.length) {
        if (cSel) { cSel.remove(); cSel = null; $('label[for="f-commune-sel"]', form) && $('label[for="f-commune-sel"]', form).setAttribute('for', 'f-commune'); }
        cInput.hidden = false; return;
      }
      ensureCSel();
      cSel.disabled = false;
      var list = w.m.slice().sort(function (a, b) { return (AR ? (a[1] || a[0]) : a[0]).localeCompare(AR ? (b[1] || b[0]) : b[0], AR ? 'ar' : 'fr'); });
      var html = '<option value="">' + esc(I.select_commune) + '</option>';
      list.forEach(function (c) { html += '<option value="' + esc(c[0]) + '">' + esc(AR && c[1] ? c[1] : c[0]) + '</option>'; });
      html += '<option value="' + otherVal + '">' + esc(I.other_commune) + '</option>';
      cSel.innerHTML = html;
      cInput.hidden = true; cInput.value = '';
    }
    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    wSel.addEventListener('change', function () {
      refresh(); clearErr('wilaya');
      loadGeo().then(function () { buildCommunes(); });
    });
    // Tant que la wilaya n'est pas choisie, la commune attend (liste désactivée).
    if (!wSel.value) {
      ensureCSel();
      cSel.disabled = true;
      cSel.innerHTML = '<option value="">' + esc(I.select_wilaya_first) + '</option>';
      cInput.hidden = true;
    } else {
      loadGeo().then(function () { buildCommunes(); refresh(); });
    }
    // Pré-chargement discret dès que le formulaire approche.
    if ('IntersectionObserver' in window) {
      var fio = new IntersectionObserver(function (en) { if (en[0].isIntersecting) { loadGeo(); fio.disconnect(); } }, { rootMargin: '400px' });
      fio.observe(form);
    } else loadGeo();

    // Début de commande : première saisie dans le formulaire.
    form.addEventListener('focusin', function (e) {
      if (e.target.matches('input[type="text"], input[type="tel"], select, textarea')) {
        trackOnce('AddToCart', productCustom(tierQty()), { p: P.id, q: tierQty() });
        trackOnce('InitiateCheckout', productCustom(tierQty()), { p: P.id, q: tierQty() });
      }
    });
    $$('input, select, textarea', form).forEach(function (el) {
      el.addEventListener('input', function () { clearErr(el.name); });
    });
    // Bandeau « Offre spéciale » : sélectionne l'offre puis descend au formulaire.
    $$('[data-pick]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        var r = $('input[name="qty"][value="' + a.getAttribute('data-pick') + '"]', form);
        if (!r) return;
        e.preventDefault();
        r.checked = true;
        r.dispatchEvent(new Event('change', { bubbles: true }));
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
    // Boutons « Commander » de la page : ajout au panier, puis défilement vers le formulaire.
    $$('a[href="#commander"]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        trackOnce('AddToCart', productCustom(tierQty()), { p: P.id, q: tierQty() });
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function () { var f = $('#f-name'); f && f.focus({ preventScroll: true }); }, 600);
      });
    });
    if (location.hash === '#commander') {
      setTimeout(function () { form.scrollIntoView({ block: 'start' }); }, 50);
    }

    function normPhone(p) {
      p = String(p || '').replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/[^\d+]/g, '');
      if (p.indexOf('+213') === 0) p = '0' + p.slice(4); else if (p.indexOf('00213') === 0) p = '0' + p.slice(5); else if (p.indexOf('213') === 0 && p.length >= 12) p = '0' + p.slice(3);
      if (p.length === 9 && /^[567]/.test(p)) p = '0' + p;
      return /^0[567]\d{8}$/.test(p) || /^0[2-49]\d{7}$/.test(p) ? p : '';
    }
    function setErr(name, msg) {
      var p = $('[data-err="' + name + '"]', form);
      if (p) { p.textContent = msg; p.closest('.field') && p.closest('.field').classList.add('has-err'); }
    }
    function clearErr(name) {
      var p = $('[data-err="' + name + '"]', form);
      if (p && p.textContent) { p.textContent = ''; p.closest('.field') && p.closest('.field').classList.remove('has-err'); }
      errBox.hidden = true;
    }
    function communeValue() {
      if (cSel && cSel.value && cSel.value !== otherVal) return cSel.value;
      return cInput.value.trim();
    }
    function validate() {
      var bad = [], name = fe('name').value.trim(), letters = (name.match(/\p{L}/gu) || []).length;
      if (name.length < 3 || letters < 3) { setErr('name', I.err_name); bad.push('name'); }
      if (!normPhone(fe('phone').value)) { setErr('phone', I.err_phone); bad.push('phone'); }
      if (!wSel.value) { setErr('wilaya', I.err_wilaya); bad.push('wilaya'); }
      if (communeValue().length < 2) { setErr('commune', I.err_commune); bad.push('commune'); }
      if (fe('address').value.trim().length < 4) { setErr('address', I.err_address); bad.push('address'); }
      return bad;
    }
    function setBusy(b) {
      sending = b; submit.disabled = b; submit.classList.toggle('is-loading', b);
      $('[data-submit-label]', form).textContent = b ? I.sending : I.submit;
    }
    function focusField(n) {
      var el = n === 'commune' ? (cSel && cInput.hidden ? cSel : cInput) : fe(n);
      if (el && el.focus) { el.focus({ preventScroll: true }); (el.closest('.field') || el).scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (sending) return;
      var bad = validate();
      if (bad.length) { focusField(bad[0]); form.classList.remove('form-shake'); void form.offsetWidth; form.classList.add('form-shake'); return; }
      trackOnce('InitiateCheckout', productCustom(tierQty()), { p: P.id, q: tierQty() });
      setBusy(true);
      var data = {
        product: +fe('product').value, qty: tierQty(), name: fe('name').value.trim(), phone: fe('phone').value.trim(), wilaya: wSel.value,
        commune: communeValue(), address: fe('address').value.trim(), note: fe('note') ? fe('note').value.trim() : '', delivery: delivery(),
        lang: S.lang, key: keyEl.value, hp: fe('hp').value, attr: attribution(), url: location.href.split('#')[0],
        fbp: getCookie('_fbp'), fbc: getCookie('_fbc')
      };
      fetch(S.api + '?action=order', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(data)
      }).then(function (r) { return r.json().catch(function () { return { error: 'server', message: I.err_generic }; }); }).then(function (r) {
        if (!r || !r.ok) {
          setBusy(false);
          if (r && r.field && $('[data-err="' + r.field + '"]', form)) { setErr(r.field, r.message); focusField(r.field); }
          else { errBox.textContent = (r && r.message) || I.err_generic; errBox.hidden = false; }
          return;
        }
        // Lead : une seule fois, à la création de la commande (même event_id que l'envoi serveur).
        if (r.lead && S.pixel && window.fbq) {
          try {
            window.fbq('track', 'Lead', { value: r.lead.value, currency: r.lead.currency, content_ids: r.lead.content_ids, content_type: 'product', num_items: r.lead.num_items, contents: r.lead.contents }, { eventID: r.lead.event_id });
          } catch (err) { /* bloqué */ }
        }
        saveOrder(r.ref, r.url);
        setTimeout(function () { location.href = r.url; }, S.pixel ? 450 : 0);
      }).catch(function () {
        setBusy(false);
        errBox.textContent = I.err_network; errBox.hidden = false;
      });
    });

    // Barre « Commander » collante (mobile) : visible quand le formulaire est hors de l'écran.
    var sticky = $('[data-sticky]');
    if (sticky && 'IntersectionObserver' in window) {
      sticky.hidden = false;
      var formVisible = false, pastTop = false;
      var upd = function () { sticky.classList.toggle('is-on', pastTop && !formVisible); };
      new IntersectionObserver(function (en) { formVisible = en[0].isIntersecting; upd(); }, { threshold: 0.05 }).observe(form);
      var anchor = $('.pdp-price') || form;
      new IntersectionObserver(function (en) { pastTop = !en[0].isIntersecting && en[0].boundingClientRect.top < 0; upd(); }).observe(anchor);
    }
    refresh();
  }

  /* --------------------------------------------- commandes de cet appareil */
  function saveOrder(ref, url) {
    var list = store('swb_orders') || [];
    if (!list.some(function (o) { return o.ref === ref; })) list.unshift({ ref: ref, url: url, t: Date.now() });
    store('swb_orders', list.slice(0, 10));
  }
  if (S.page === 'order' && S.order) saveOrder(S.order.ref, S.order.url);
  var mine = $('[data-my-orders]');
  if (mine) {
    var list = store('swb_orders') || [];
    if (list.length) {
      mine.hidden = false;
      $('ul', mine).innerHTML = list.map(function (o) {
        var d = new Date(o.t);
        return '<li><a href="' + o.url.replace(/"/g, '') + '"><b dir="ltr">' + o.ref.replace(/[^A-Z0-9-]/g, '') + '</b><span>' + d.toLocaleDateString(AR ? 'ar-DZ' : 'fr-FR') + '</span></a></li>';
      }).join('');
    }
  }

  /* ---------------------------------------------------------------- boutique */
  $$('[data-autosubmit]').forEach(function (f) {
    f.addEventListener('change', function () { f.submit(); });
  });
})();
