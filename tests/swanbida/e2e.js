/**
 * Test de bout en bout de SWANBIDA dans Chromium (Playwright) :
 *  - commande mobile en français puis en arabe, depuis une publicité Meta (paramètres d'URL) ;
 *  - événements Pixel (PageView, ViewContent, AddToCart, InitiateCheckout, Lead) avec event_id,
 *    et mêmes event_id reçus par la fausse Conversions API (déduplication) ;
 *  - aucune conversion à l'ouverture / au rafraîchissement de la page de remerciement ;
 *  - administration : connexion, confirmation de la commande → Purchase côté serveur uniquement.
 *
 * Prérequis : serveurs lancés (voir tests/swanbida/README.md).
 *   node tests/swanbida/e2e.js <dossier du faux hébergement> [dossier des captures]
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');
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
  await page.goto(BASE + (ar ? '/ar' : '') + '/produit/pack-serviette-blanc' + ad, { waitUntil: 'networkidle' });
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
  await page.fill('#f-address', ar ? 'حي 1000 مسكن، عمارة 3' : 'Cité 1000 logements, bât. 3');
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
  await page.goto(BASE + (lang === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
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

(async () => {
  const browser = await chromium.launch();
  try {
    await home(browser, 'fr');
    await home(browser, 'ar');
    const refFr = await order(browser, 'fr');
    await order(browser, 'ar');
    await admin(browser, refFr);
  } catch (e) {
    console.error(e);
    failures++;
  } finally {
    await browser.close();
  }
  console.log(failures ? `\n${failures} vérification(s) en échec` : '\nToutes les vérifications sont passées.');
  process.exit(failures ? 1 : 0);
})();
