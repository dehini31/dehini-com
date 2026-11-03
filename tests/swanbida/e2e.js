/**
 * Test de bout en bout de SWANBIDA dans Chromium (Playwright) :
 *  - commande mobile en français puis en arabe, depuis une publicité Meta (paramètres d'URL) ;
 *  - événements Pixel (PageView, ViewContent, AddToCart, InitiateCheckout, Lead) avec event_id,
 *    et mêmes event_id reçus par la fausse Conversions API (déduplication) ;
 *  - aucune conversion à l'ouverture / au rafraîchissement de la page de remerciement ;
 *  - administration : connexion, confirmation de la commande → Purchase côté serveur uniquement ;
 *  - arabe langue principale (/), français sous /fr/ ;
 *  - commandes abandonnées : formulaire commencé puis quitté → alerte Telegram (cron), relance et
 *    création de la commande depuis l'admin (Lead serveur avec l'attribution de la publicité).
 *
 * Prérequis : serveurs lancés (voir tests/swanbida/README.md).
 *   node tests/swanbida/e2e.js <dossier du faux hébergement> [dossier des captures]
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');
const { execFileSync } = require('child_process');
const path = require('path');

const DIR = process.argv[2];
const SHOTS = process.argv[3] || path.join(DIR, 'shots');
const BASE = 'http://127.0.0.1:8090/swanbida';
const FAKE = path.join(DIR, 'fake.jsonl');
fs.mkdirSync(SHOTS, { recursive: true });

let failures = 0;
function check(cond, msg) { console.log((cond ? '  ✔ ' : '  ✘ ') + msg); if (!cond) failures++; }
function fakeEvents() {
  if (!fs.existsSync(FAKE)) return [];
  return fs.readFileSync(FAKE, 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l))
    .filter(r => r.path.includes('/events')).flatMap(r => r.body.data.map(d => ({ name: d.event_name, id: d.event_id, test: r.body.test_event_code, ud: d.user_data, cd: d.custom_data })));
}
const sleep = ms => new Promise(r => setTimeout(r, ms));

// Faux fbevents.js : garde chaque appel fbq(...) dans window.__fbq pour les vérifier.
const FBQ_STUB = `(function(){var f=window.fbq;window.__fbq=(f.queue||[]).map(function(a){return [].slice.call(a)});f.callMethod=function(){window.__fbq.push([].slice.call(arguments))};document.cookie='_fbp=fb.1.'+Date.now()+'.987654321; path=/';})();`;

async function newPage(browser, opts) {
  const ctx = await browser.newContext({ ...devices['iPhone 13'], locale: 'fr-FR', ...opts });
  await ctx.route('https://connect.facebook.net/**', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: FBQ_STUB }));
  await ctx.route('https://www.facebook.com/**', r => r.fulfill({ status: 204, body: '' }));
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (m.type() === 'error' && !/fonts\.g/.test(m.text())) errors.push(m.text()); });
  return { ctx, page, errors };
}
async function fbqCalls(page) { return page.evaluate(() => (window.__fbq || []).filter(c => c[0] === 'track').map(c => ({ name: c[1], id: c[3] && c[3].eventID, data: c[2] }))); }

async function order(browser, lang) {
  const ar = lang === 'ar';
  console.log(`\n▶ Commande mobile (${lang}) depuis une publicité`);
  const { ctx, page, errors } = await newPage(browser, ar ? { locale: 'ar-DZ' } : {});
  const ad = '?utm_source=facebook&utm_medium=paid&utm_campaign=Lancement%20Pack&utm_content=Video%201&campaign_id=1202000000001&adset_id=1202000000002&ad_id=1202000000003&fbclid=IwAR0e2eTest' + lang;
  await page.goto(BASE + (ar ? '' : '/fr') + '/produit/pack-serviette-blanc' + ad, { waitUntil: 'networkidle' });
  await sleep(1300);
  let calls = await fbqCalls(page);
  check(calls.some(c => c.name === 'PageView' && /^pv\./.test(c.id)), 'PageView navigateur avec eventID');
  check(calls.some(c => c.name === 'ViewContent' && /^vc\./.test(c.id) && c.data.currency === 'DZD'), 'ViewContent navigateur (DZD)');
  check(await page.evaluate(() => document.documentElement.dir) === (ar ? 'rtl' : 'ltr'), 'sens d\'écriture ' + (ar ? 'RTL' : 'LTR'));
  check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'aucun défilement horizontal');
  await page.screenshot({ path: path.join(SHOTS, `product-${lang}-top.png`) });

  // Logo : « SWANBIDA » à droite du cygne, sans chevaucher le bouton de langue (même en arabe).
  const logo = await page.evaluate(() => {
    const svg = document.querySelector('.site-header .logo'), t = svg.querySelector('text').getBoundingClientRect();
    const sw = svg.querySelector('.logo-swan').getBoundingClientRect(), l = document.querySelector('.lang-switch').getBoundingClientRect();
    return { afterSwan: t.left >= sw.right - 2, clear: t.right <= l.left || t.left >= l.right };
  });
  check(logo.afterSwan && logo.clear, 'logo : nom écrit après le cygne, sans chevauchement');

  // Galerie : chaque miniature affiche la bonne photo, puis glissement au doigt.
  const total = await page.$$eval('.g-thumb', t => t.length);
  const count = async () => (await page.textContent('[data-count]')).trim();
  const seen = [];
  for (const i of [1, 2, 0, total - 1, 1]) {
    await page.click(`.g-thumb[data-go="${i}"]`);
    await sleep(800);
    seen.push(await count());
  }
  const want = [2, 3, 1, total, 2].map(n => `${n} / ${total}`);
  check(JSON.stringify(seen) === JSON.stringify(want), `galerie : miniatures → ${seen.join(', ')}`);
  await page.evaluate(() => { const t = document.querySelector('[data-track]'); t.scrollBy({ left: (getComputedStyle(t).direction === 'rtl' ? -1 : 1) * t.clientWidth, behavior: 'instant' }); });
  await sleep(500);
  check((await count()) === `3 / ${total}`, 'galerie : glisser passe à la photo suivante');
  // Ordre VISUEL du compteur (le texte du DOM peut être juste et l'affichage inversé en arabe).
  const visualOrder = await page.evaluate(() => {
    const node = document.querySelector('[data-count]').firstChild, txt = node.textContent, r = document.createRange();
    r.setStart(node, 0); r.setEnd(node, 1); const first = r.getBoundingClientRect().left;
    r.setStart(node, txt.length - 1); r.setEnd(node, txt.length); const last = r.getBoundingClientRect().left;
    return first < last;
  });
  check(visualOrder, 'galerie : compteur affiché « 3 / 4 » et non « 4 / 3 » à l\'écran');
  // Titre au-dessus de la galerie sur mobile, badge « Livraison gratuite » dans le coin de la photo.
  const layout = await page.evaluate(() => {
    const t = document.querySelector('.pdp-title').getBoundingClientRect(), g = document.querySelector('.g-main').getBoundingClientRect();
    const tag = document.querySelector('.g-main .ship-tag');
    const tr = tag && tag.getBoundingClientRect();
    return { titleAbove: t.bottom <= g.top + 1, tagIn: !!tr && tr.top >= g.top && tr.top < g.top + 40 && tr.left >= g.left && tr.right <= g.right };
  });
  check(layout.titleAbove, 'fiche produit : titre au-dessus de la photo sur mobile');
  check(layout.tagIn, 'fiche produit : badge « Livraison gratuite » dans le coin de la photo');
  // Bandeau d'offre : choisit l'offre 2 packs.
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.click('.offer-ribbon');
  await sleep(700);
  check(await page.$eval('input[name="qty"][value="2"]', i => i.checked), 'bandeau « Offre spéciale » : sélectionne 2 packs');
  const fixed = await page.evaluate(() => ({
    main: document.querySelector('.pdp-price .price-now').textContent.replace(/\D/g, ''),
    total: document.querySelector('[data-sum-total]').textContent.replace(/\D/g, ''),
  }));
  check(fixed.main === '7750' && fixed.total === '13500', `prix principal fixe (${fixed.main}) quand l'offre 2 packs est choisie (total ${fixed.total})`);
  // Blocs repris de l'accueil, identiques.
  const blocks = await page.evaluate(() => ({
    mosaic: document.querySelectorAll('.featured .mosaic > *').length,
    contents: document.querySelectorAll('.featured .contents-list li').length,
    band: !!document.querySelector('.offer-band [data-pick]'),
    pack: document.querySelectorAll('.section-sand .steps .step').length,
  }));
  check(blocks.mosaic >= 1 && blocks.contents === 5 && blocks.band && blocks.pack === 3, `fiche produit : blocs de l'accueil (mosaïque ${blocks.mosaic}, contenu ${blocks.contents}, offre, emballage ${blocks.pack})`);
  await page.click('.tier:nth-of-type(1) .tier-box');
  await page.click('.offer-band [data-pick]');
  await sleep(800);
  check(await page.$eval('input[name="qty"][value="2"]', i => i.checked), 'grand bandeau « Offre spéciale » : sélectionne 2 packs et remonte au formulaire');
  await page.click('.tier:nth-of-type(1) .tier-box');

  // Barre « Commander » collante après défilement.
  await page.evaluate(() => window.scrollTo(0, 2600));
  await sleep(500);
  const stickyOn = await page.evaluate(() => { const s = document.querySelector('[data-sticky]'); return !!s && s.classList.contains('is-on'); });
  check(stickyOn, 'barre « Commander » visible en bas de l\'écran hors du formulaire');
  await page.click('[data-sticky] a');
  await sleep(900);

  // Choix de l'offre 2 packs → AddToCart
  await page.click('.tier:nth-of-type(2) .tier-box');
  check((await page.textContent('[data-sum-total]')).replace(/\s/g, '').startsWith('13'), 'total mis à jour pour 2 packs');
  // Envoi vide → erreurs
  await page.click('[data-submit]');
  check(await page.isVisible('[data-err="name"]:not(:empty)'), 'erreur affichée si le nom est vide');
  await page.fill('#f-name', ar ? 'كريمة بن صالح' : 'Nadia Belkacem');
  await page.fill('#f-phone', ar ? '05 51 22 33 44' : '07 70 12 34 56');
  await page.selectOption('#f-wilaya', '16');
  await page.waitForFunction(() => { const s = document.querySelector('#f-commune-sel'); return s && !s.disabled && s.options.length > 10; });
  const communeVal = await page.evaluate(() => [...document.querySelectorAll('#f-commune-sel option')].find(o => o.value === 'Bab Ezzouar').value);
  await page.selectOption('#f-commune-sel', communeVal);
  check(!(await page.$('#f-address')), 'formulaire : plus de champ « adresse détaillée »');
  await page.screenshot({ path: path.join(SHOTS, `form-${lang}.png`), fullPage: false });
  const [resp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('action=order')),
    page.click('[data-submit]'),
  ]);
  const body = await resp.json();
  check(body.ok && /^SW-[A-Z0-9]{6}$/.test(body.ref), 'commande enregistrée : ' + body.ref);
  // Lead navigateur juste avant la redirection
  calls = await fbqCalls(page).catch(() => []);
  await page.waitForURL(/\/commande\/SW-/, { timeout: 5000 });
  check(page.url().includes(body.ref), 'redirection vers la page de remerciement');
  await sleep(1200);
  const thanksCalls = await fbqCalls(page);
  check(!thanksCalls.some(c => c.name === 'Lead' || c.name === 'Purchase'), 'page de remerciement : ni Lead ni Purchase côté navigateur');
  await page.screenshot({ path: path.join(SHOTS, `thanks-${lang}.png`), fullPage: true });
  await page.reload({ waitUntil: 'networkidle' });
  await sleep(1200);
  const reloadCalls = await fbqCalls(page);
  check(!reloadCalls.some(c => c.name === 'Lead' || c.name === 'Purchase'), 'rafraîchissement : toujours aucune conversion');

  const ev = fakeEvents();
  const lead = ev.filter(e => e.name === 'Lead' && e.id === 'lead.' + body.ref);
  check(lead.length === 1, 'Lead serveur unique avec event_id lead.' + body.ref);
  check(lead[0] && lead[0].cd.value === 13500 && lead[0].cd.num_items === 2, 'Lead serveur : valeur 13 500 DZD, 2 articles');
  check(lead[0] && lead[0].ud.fbc && lead[0].ud.fbc.endsWith('IwAR0e2eTest' + lang), 'Lead serveur : fbc construit depuis le fbclid de la publicité');
  check(lead[0] && lead[0].ud.fbp && lead[0].ud.ph && lead[0].ud.ct && lead[0].ud.st, 'Lead serveur : fbp + téléphone, commune et wilaya hachés');
  const browserIds = calls.map(c => c.id).filter(Boolean);
  for (const n of ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout']) {
    const b = calls.find(c => c.name === n);
    check(b && ev.some(e => e.name === n && e.id === b.id), `${n} : même event_id côté navigateur et serveur (${b && b.id})`);
  }
  check(!ev.some(e => e.name === 'Purchase' && e.id === 'purchase.' + body.ref), 'aucun Purchase avant confirmation');
  check(errors.length === 0, 'aucune erreur JavaScript' + (errors.length ? ' : ' + errors.join(' | ') : ''));
  await ctx.close();
  return body.ref;
}

async function home(browser, lang) {
  console.log(`\n▶ Accueil mobile (${lang})`);
  const { ctx, page, errors } = await newPage(browser, lang === 'ar' ? { locale: 'ar-DZ' } : {});
  await page.goto(BASE + (lang === 'ar' ? '/' : '/fr/'), { waitUntil: 'networkidle' });
  const L = await page.evaluate(() => ({
    lang: document.documentElement.lang, dir: document.documentElement.dir,
    xdef: (document.querySelector('link[hreflang="x-default"]') || {}).href || '',
    sw: (document.querySelector('.lang-switch') || {}).href || '',
  }));
  check(L.lang.startsWith(lang) && L.dir === (lang === 'ar' ? 'rtl' : 'ltr'), `langue : ${lang === 'ar' ? '/ = arabe (RTL)' : '/fr/ = français (LTR)'}`);
  check(L.xdef === BASE + '/', 'hreflang x-default → version arabe (/)');
  check(lang === 'ar' ? L.sw.endsWith('/swanbida/fr/') : L.sw.endsWith('/swanbida/'), 'bouton de langue → ' + L.sw.replace(BASE, ''));
  if (lang === 'ar') {
    const old = await ctx.request.get(BASE + '/ar/produit/pack-serviette-blanc?utm_source=facebook', { maxRedirects: 0 });
    check(old.status() === 301 && old.headers().location.endsWith('/swanbida/produit/pack-serviette-blanc?utm_source=facebook'), 'ancienne adresse /ar/… → 301 vers /… (paramètres gardés)');
  }
  const r = await page.evaluate(() => {
    const h = document.querySelector('.hero-head h1').getBoundingClientRect(), f = document.querySelector('.hero-frame').getBoundingClientRect();
    const tag = document.querySelector('.hero-visual .ship-tag');
    return { titleAbove: h.bottom <= f.top + 1, tag: !!tag && getComputedStyle(tag).display !== 'none', cardTag: !!document.querySelector('.card .ship-tag') || !document.querySelector('.card') };
  });
  check(r.titleAbove, 'accueil : titre au-dessus du visuel sur mobile');
  check(r.tag, 'accueil : badge « Livraison gratuite » sur le visuel');
  check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'accueil : aucun défilement horizontal');
  await page.screenshot({ path: path.join(SHOTS, `home-${lang}-top.png`) });
  check(errors.length === 0, 'accueil : aucune erreur JavaScript' + (errors.length ? ' : ' + errors.join(' | ') : ''));
  await ctx.close();
}

async function admin(browser, ref) {
  console.log('\n▶ Administration (mobile) : confirmation de ' + ref);
  const { ctx, page, errors } = await newPage(browser);
  await page.goto(BASE + '/admin/', { waitUntil: 'networkidle' });
  check(await page.isHidden('.login label:has(input[inputmode=email])'), 'connexion : champ Email masqué par défaut (propriétaire)');
  // Compte d'équipe : un texte sans @ (pré-rempli par le navigateur) est signalé, sans blocage du navigateur.
  await page.click('.login a:has-text("Membre de l")');
  await page.fill('input[inputmode=email]', 'dehini.com');
  await page.fill('input[type=password]', 'test-owner-pass');
  await page.click('button[type=submit]');
  check((await page.textContent('.err')).includes('email'), 'mode équipe : « dehini.com » refusé avec un message clair');
  await page.click('.login a:has-text("propriétaire")');
  check(await page.isHidden('.login label:has(input[inputmode=email])'), 'retour au mode propriétaire');
  // Côté serveur : un « email » sans @ envoyé avec le mot de passe du propriétaire ouvre quand même la session.
  const srv = await page.evaluate(() => fetch('api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'dehini.com', password: 'test-owner-pass' }) }).then(r => r.json()));
  check(srv.ok && srv.user && srv.user.role === 'owner', 'serveur : « dehini.com » + mot de passe /admin → propriétaire');
  await page.evaluate(() => fetch('api.php?action=logout', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }));
  await page.fill('input[type=password]', 'mauvais');
  await page.click('button[type=submit]');
  await page.waitForSelector('.err:not(:empty)');
  check(true, 'mauvais mot de passe refusé');
  await page.fill('input[type=password]', 'test-owner-pass');
  await page.click('button[type=submit]');
  await page.waitForSelector('.kpi');
  errors.length = 0; // le 401 du mauvais mot de passe, volontaire, ne compte pas
  await sleep(500);
  await page.screenshot({ path: path.join(SHOTS, 'admin-dashboard-mobile.png'), fullPage: true });
  await page.goto(BASE + '/admin/#/commandes');
  await page.waitForSelector('.orow');
  check(await page.isVisible(`.orow:has-text("${ref}")`), 'la commande apparaît dans la liste');
  await page.click(`.orow:has-text("${ref}") .ref`);
  await page.waitForSelector('.status-bar');
  await page.screenshot({ path: path.join(SHOTS, 'admin-order-mobile.png'), fullPage: true });
  await page.click('.status-bar button:has-text("Confirmée")');
  await sleep(1500);
  const ev = fakeEvents().filter(e => e.name === 'Purchase' && e.id === 'purchase.' + ref);
  check(ev.length === 1, 'Purchase envoyé une fois à la confirmation (event_id purchase.' + ref + ')');
  check(ev[0] && ev[0].cd.order_id === ref && ev[0].cd.value === 13500, 'Purchase : order_id et valeur de la commande');
  await page.click('.status-bar button:has-text("Confirmée")').catch(() => {});
  await page.click('.status-bar button:has-text("En préparation")');
  await sleep(1200);
  check(fakeEvents().filter(e => e.name === 'Purchase' && e.id === 'purchase.' + ref).length === 1, 'pas de second Purchase en changeant encore de statut');
  check(errors.length === 0, 'aucune erreur JavaScript dans l\'admin' + (errors.length ? ' : ' + errors.join(' | ') : ''));
  await ctx.close();

  // Bureau : captures larges.
  const d = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const p = await d.newPage();
  await p.goto(BASE + '/admin/', { waitUntil: 'networkidle' });
  await p.fill('input[type=password]', 'test-owner-pass');
  await p.click('button[type=submit]');
  await p.waitForSelector('.kpi');
  await sleep(600);
  await p.screenshot({ path: path.join(SHOTS, 'admin-dashboard-desktop.png'), fullPage: true });
  for (const [h, n] of [['#/produits/1', 'admin-product'], ['#/meta', 'admin-meta'], ['#/livraison', 'admin-shipping'], ['#/contenus', 'admin-content'], ['#/reglages', 'admin-settings']]) {
    await p.goto(BASE + '/admin/' + h);
    await sleep(900);
    await p.screenshot({ path: path.join(SHOTS, n + '.png'), fullPage: true });
  }
  // Compte d'équipe « Confirmation » créé par le propriétaire, puis connexion avec son email.
  const created = await p.evaluate(async () => {
    const s = await fetch('api.php?action=session').then(r => r.json());
    return fetch('api.php?action=team_save', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': s.csrf },
      body: JSON.stringify({ email: 'agent@example.com', name: 'Agent Test', role: 'agent', password: 'agent-pass-123' }) }).then(r => r.json());
  });
  check(created.ok, 'propriétaire : création d\'un compte « Confirmation »');
  await d.close();
  const a = await browser.newContext({ ...devices['iPhone 13'] });
  const ap = await a.newPage();
  await ap.goto(BASE + '/admin/', { waitUntil: 'networkidle' });
  await ap.click('.login a:has-text("Membre de l")');
  await ap.fill('input[inputmode=email]', 'agent@example.com');
  await ap.fill('input[type=password]', 'agent-pass-123');
  await ap.click('button[type=submit]');
  await ap.waitForSelector('.orow');
  const nav = await ap.$$eval('.nav a', as => as.map(x => x.textContent));
  check(nav.some(t => t.startsWith('Commandes')) && !nav.some(t => /Meta|Réglages|Produits/.test(t)), 'équipe : connexion par email, accès limité aux commandes');
  const denied = await ap.evaluate(() => fetch('api.php?action=meta').then(r => r.status));
  check(denied === 403, 'équipe : Meta & réglages refusés par le serveur (403)');
  await a.close();
}

async function adminLogin(browser, opts) {
  const ctx = await browser.newContext(opts || { ...devices['iPhone 13'] });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(BASE + '/admin/', { waitUntil: 'networkidle' });
  await page.fill('input[type=password]', 'test-owner-pass');
  await page.click('button[type=submit]');
  await page.waitForSelector('.kpi');
  const call = (action, body, query) => page.evaluate(async ([action, body, query]) => {
    const s = await fetch('api.php?action=session').then(r => r.json());
    const qs = query ? '&' + new URLSearchParams(query) : '';
    const init = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': s.csrf }, body: JSON.stringify(body) };
    return fetch('api.php?action=' + action + qs, init).then(r => r.json());
  }, [action, body, query]);
  return { ctx, page, errors, call };
}
function telegrams() {
  if (!fs.existsSync(FAKE)) return [];
  return fs.readFileSync(FAKE, 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)).filter(r => r.path.startsWith('/bot')).map(r => r.body.text || '');
}

async function abandoned(browser) {
  console.log('\n▶ Commande abandonnée : formulaire commencé, quitté, puis récupéré depuis l\'admin');
  const A = await adminLogin(browser);
  // Telegram configuré (fausse API) pour recevoir l'alerte du cron.
  const st = (await A.call('settings')).settings;
  Object.assign(st, { telegram_token: '1234567890:AAHtestTokenForSwanbidaE2E_abc', notify_telegram_chat: '424242', notify_abandoned: true, abandon_minutes: 20 });
  check((await A.call('settings_save', st)).ok, 'réglages : Telegram + alerte des commandes abandonnées');

  // Le client arrive d'une publicité (site arabe), choisit 2 packs, tape nom, téléphone, wilaya… puis s'en va.
  const { ctx, page, errors } = await newPage(browser, { locale: 'ar-DZ' });
  await page.goto(BASE + '/produit/pack-serviette-blanc?utm_source=facebook&utm_campaign=Relance&campaign_id=1202000000009&ad_id=1202000000099&fbclid=IwAR0abandon', { waitUntil: 'networkidle' });
  await page.click('.tier:nth-of-type(2) .tier-box');
  await page.fill('#f-name', 'سمير حداد');
  const noPhone = page.waitForResponse(r => r.url().includes('action=draft'), { timeout: 2500 }).then(() => true).catch(() => false);
  check(!(await noPhone), 'rien n\'est enregistré tant que le téléphone n\'est pas valide');
  const saved = page.waitForResponse(r => r.url().includes('action=draft'));
  await page.fill('#f-phone', '0661 98 76 54');
  const r1 = await saved;
  check((await r1.json()).ok, 'téléphone saisi → formulaire enregistré automatiquement');
  const saved2 = page.waitForResponse(r => r.url().includes('action=draft'));
  await page.selectOption('#f-wilaya', '31');
  await saved2;
  check(errors.length === 0, 'aucune erreur JavaScript côté client' + (errors.length ? ' : ' + errors.join(' | ') : ''));
  await ctx.close();

  let live = await A.call('drafts', undefined, { tab: 'live' });
  const d = live.items.find(x => x.name === 'سمير حداد');
  check(d && d.qty === 2 && d.total === 13500 && d.wilaya === '31' && d.source === 'facebook', 'admin : « En cours de saisie » avec offre, total, wilaya et source');
  check(d && /^https:\/\/wa\.me\/213661987654\?text=/.test(d.wa) && decodeURIComponent(d.wa).includes('سمير حداد'), 'lien WhatsApp de relance en arabe avec le prénom');
  const todo0 = await A.call('drafts', undefined, { tab: 'todo' });
  check(!todo0.items.some(x => x.name === 'سمير حداد'), 'pas encore « À rappeler » (moins de 20 min)');
  check(![...live.items, ...todo0.items].some(x => x.name === 'Nadia Belkacem' || x.name === 'كريمة بن صالح'), 'les commandes déjà envoyées ne sont pas dans les abandonnées');

  // Une cliente commence (formulaire enregistré), puis envoie sa commande : elle sort des abandonnées.
  const B = await newPage(browser, { locale: 'ar-DZ' });
  await B.page.goto(BASE + '/produit/pack-serviette-blanc', { waitUntil: 'networkidle' });
  const sv = B.page.waitForResponse(r => r.url().includes('action=draft'));
  await B.page.fill('#f-phone', '0555 11 22 33');
  await sv;
  await B.page.fill('#f-name', 'لينا');
  await B.page.selectOption('#f-wilaya', '16');
  await B.page.waitForFunction(() => { const s = document.querySelector('#f-commune-sel'); return s && !s.disabled && s.options.length > 10; });
  await B.page.selectOption('#f-commune-sel', 'Bab Ezzouar');
  const [or] = await Promise.all([B.page.waitForResponse(r => r.url().includes('action=order')), B.page.click('[data-submit]')]);
  const lina = await or.json();
  await B.page.waitForURL(/\/commande\/SW-/);
  await B.ctx.close();
  const conv0 = await A.call('drafts', undefined, { tab: 'converted' });
  const l = conv0.items.find(x => x.phone.endsWith('555112233'));
  check(l && l.status === 'converted' && !l.recovered && l.order_ref === lina.ref, 'formulaire commencé puis envoyé → « commandée par le client » (' + lina.ref + '), pas de relance');

  // 25 minutes plus tard (horloge avancée dans la base) : le cron prévient l'équipe, une seule fois.
  const db = path.join(DIR, 'swanbida-data', 'swanbida.sqlite');
  execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec("UPDATE drafts SET updated = updated - 1500, created = created - 1500 WHERE status = \'open\'");', db]);
  const cron = path.join(DIR, 'public_html', 'swanbida', 'cron.php');
  const out = execFileSync('php', [cron, '--verbose'], { encoding: 'utf8' });
  check(/Commandes abandonnées signalées : 1 /.test(out), 'cron : 1 commande abandonnée signalée');
  const tg = telegrams().filter(t => t.includes('Commande abandonnée'));
  check(tg.length === 1 && tg[0].includes('سمير حداد') && /13\s?500\sDA/.test(tg[0]) && tg[0].includes(BASE + '/admin/#/abandonnees'), 'Telegram : nom, montant et lien vers l\'admin');
  execFileSync('php', [cron, '--verbose'], { encoding: 'utf8' });
  check(telegrams().filter(t => t.includes('Commande abandonnée')).length === 1, 'cron relancé : pas de seconde alerte');

  // Admin : menu avec compteur, appel → « contactée », puis création de la commande.
  const p = A.page;
  await p.goto(BASE + '/admin/#/abandonnees');
  await p.waitForSelector('.drow');
  await sleep(400);
  check((await p.textContent('.nav a[href="#/abandonnees"]')).replace(/\D/g, '') === '1', 'menu « Abandonnées » avec le compteur 1');
  const row = p.locator('.drow', { hasText: 'سمير حداد' });
  check(await row.isVisible(), 'onglet « À rappeler » : la commande abandonnée apparaît');
  await p.screenshot({ path: path.join(SHOTS, 'admin-abandoned-mobile.png'), fullPage: true });
  await row.locator('input[type=text]').fill('Rappeler après 18 h');
  await row.locator('button:has-text("Enregistrer la note")').click();
  await sleep(500);
  await row.locator('button:has-text("Contactée")').click();
  await sleep(700);
  check(!(await p.locator('.drow', { hasText: 'سمير حداد' }).count()), '« Contactée » : sort de « À rappeler »');
  await p.click('.tabs button:has-text("Contactées")');
  await p.waitForSelector('.drow:has-text("سمير حداد")');
  const row2 = p.locator('.drow', { hasText: 'سمير حداد' });
  check((await row2.locator('input[type=text]').inputValue()) === 'Rappeler après 18 h', 'note interne gardée');
  await row2.locator('button:has-text("Créer la commande")').click();
  await row2.locator('.dconv select').first().waitFor();
  await row2.locator('.dconv input[list]').fill('Oran');
  await row2.locator('button:has-text("Enregistrer la commande")').click();
  await p.waitForURL(/#\/commandes\/\d+$/);
  await p.waitForSelector('.status-bar');
  const ref = (await p.textContent('h1')).replace('Commande ', '').trim();
  check(/^SW-[A-Z0-9]{6}$/.test(ref), 'commande créée depuis la relance : ' + ref);
  check(await p.isVisible('.log :text("Créée depuis une commande abandonnée")'), 'historique : « créée depuis une commande abandonnée »');
  await sleep(800);
  const lead = fakeEvents().filter(e => e.name === 'Lead' && e.id === 'lead.' + ref);
  check(lead.length === 1 && lead[0].cd.value === 13500 && lead[0].ud.fbc && lead[0].ud.fbc.endsWith('IwAR0abandon'), 'Lead serveur avec la valeur et le clic publicitaire d\'origine (fbc)');
  const conv = await A.call('drafts', undefined, { tab: 'converted' });
  const c = conv.items.find(x => x.name === 'سمير حداد');
  check(c && c.recovered === 1 && c.order_ref === ref, 'abandonnée → « Récupérée », liée à ' + ref);
  const stats = await A.call('stats', undefined, { days: 30 });
  check(stats.abandoned && +stats.abandoned.recovered === 1 && +stats.abandoned.recovered_v === 13500, 'tableau de bord : 1 récupérée, 13 500 DA');
  const again = await A.call('draft_convert', { id: c.id, fields: {} });
  check(again.ref === ref, 'double clic : pas de seconde commande');
  check(A.errors.length === 0, 'aucune erreur JavaScript dans l\'admin' + (A.errors.length ? ' : ' + A.errors.join(' | ') : ''));
  await A.ctx.close();
}

(async () => {
  const browser = await chromium.launch();
  try {
    await home(browser, 'fr');
    await home(browser, 'ar');
    const refFr = await order(browser, 'fr');
    await order(browser, 'ar');
    await admin(browser, refFr);
    await abandoned(browser);
  } catch (e) {
    console.error(e);
    failures++;
  } finally {
    await browser.close();
  }
  console.log(failures ? `\n${failures} vérification(s) en échec` : '\nToutes les vérifications sont passées.');
  process.exit(failures ? 1 : 0);
})();
