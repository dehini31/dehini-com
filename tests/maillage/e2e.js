/*
 * Tests de bout en bout de Maillage (Playwright / Chromium), sur le faux hébergement de run.sh :
 *   inscription d'une marque depuis la landing → produit, point de vente, stock → validation par
 *   le propriétaire → recherche et réservation côté client (mobile) → traitement de la commande →
 *   annulation par le client → distributeur et partenariat → analyses → démonstration en lecture
 *   seule → cloisonnement entre entreprises, contenu saisi non exécuté, fichiers internes interdits.
 *   node tests/maillage/e2e.js <adresse> <dossier>
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');

const [ORIGIN, DIR] = process.argv.slice(2);
const B = ORIGIN + '/maillage/';
const SHOTS = path.join(DIR, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra) : '')); } };
const step = (t) => console.log('\n' + t);
const errors = [];
const watch = (page, name) => {
  page.on('pageerror', (e) => errors.push(name + ' : ' + e.message));
  // Les refus de l'API (400, 401, 404, 409…) sont provoqués et vérifiés un par un plus bas ; ici on
  // guette les erreurs de script et les fichiers de la plateforme qui ne se chargent pas.
  page.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|tile\.openstreetmap|fonts\.g|net::ERR_/.test(m.text() + ' ' + (m.location().url || ''))) errors.push(name + ' : ' + m.text()); });
  page.on('response', (r) => { const u = r.url(); if (r.status() >= 400 && u.startsWith(ORIGIN) && !/api\.php|\/(lib|config)/.test(u)) errors.push(name + ' : ' + r.status() + ' ' + u); });
  page.on('dialog', (d) => { errors.push(name + ' : boîte de dialogue « ' + d.message() + ' »'); d.dismiss(); });
};
/** Appel direct à l'API dans le contexte d'une page (mêmes cookies). */
const call = (page, action, body, params) => page.evaluate(async ({ action, body, params }) => {
  try { return await ML.api(action, { body, params }); } catch (e) { return { error: e.code, message: e.message, status: e.status }; }
}, { action, body, params });
const noOverflow = async (page, label) => ok(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'pas de défilement horizontal : ' + label);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);

(async () => {
  const browser = await chromium.launch();
  const desk = { viewport: { width: 1366, height: 860 } };

  /* ------------------------------------------------------------ landing */
  step('Landing et inscription d\'une marque');
  const brandCtx = await browser.newContext(desk);
  const bp = await brandCtx.newPage();
  watch(bp, 'marque');
  const csp = [];
  bp.on('console', (m) => { if (/Content Security Policy/i.test(m.text())) csp.push(m.text()); });
  await bp.goto(B, { waitUntil: 'load' });
  await bp.waitForSelector('#stats:not([hidden])');
  ok(+(await bp.textContent('[data-stat="stores"]')).replace(/\D/g, '') >= 0, 'chiffres en direct affichés');
  ok(await bp.locator('h1').innerText().then((t) => /réseau intelligent/.test(t)), 'titre de la landing');
  await bp.screenshot({ path: path.join(SHOTS, '01-landing.png') });
  await bp.click('a[data-kind="brand"]');
  await bp.fill('#s-company', 'Atelier <b>Test</b>');
  await bp.fill('#s-name', 'Nadia Essai');
  await bp.fill('#s-phone', '0550 11 22 33');
  await bp.fill('#s-email', 'nadia@atelier-test.example');
  await bp.selectOption('#s-zone', '16');
  await bp.selectOption('#s-sector', 'mode');
  await bp.fill('#s-pass', 'court');
  await bp.click('.signup-form button[type=submit]');
  ok(await bp.locator('.signup-form [data-error]').isVisible(), 'mot de passe trop court refusé');
  await bp.fill('#s-pass', 'motdepasse-solide');
  await bp.click('.signup-form button[type=submit]');
  ok(/conditions/.test(await bp.locator('.signup-form [data-error]').innerText()), 'conditions à accepter');
  await bp.check('.signup-form input[name=accept]');
  await bp.click('.signup-form button[type=submit]');
  await bp.waitForURL(/\/espace\//);
  await bp.waitForSelector('.onboard');
  ok(await bp.locator('.banner').innerText().then((t) => /en cours de validation/.test(t)), 'espace créé, en attente de validation');
  ok(await bp.locator('[data-co-name]').first().innerText() === 'Atelier <b>Test</b>', 'nom saisi affiché tel quel (non interprété)');
  ok(await bp.locator('.onboard-steps li.is-done').count() === 1, 'la marque est déjà créée (1re étape faite)');
  ok(csp.length === 0, 'aucune violation de la politique de sécurité (CSP)', csp.slice(0, 2));
  ok(mails().some((m) => m.to === 'nadia@atelier-test.example' && /Bienvenue/.test(m.subject)), 'email de bienvenue');

  /* -------------------------------------------------- produit, magasin, stock */
  step('Catalogue, point de vente et disponibilité');
  await bp.goto(B + 'espace/#/catalogue/produit/nouveau');
  await bp.fill('#p-name', 'Baskets Horizon <img src=x onerror=alert(1)>');
  await bp.fill('#p-price', '8900');
  await bp.fill('#p-about', 'Une paire légère pour tous les jours.');
  await bp.fill('[data-gsizes]', '40, 41, 42');
  await bp.click('[data-act="gcolor"][data-n="Noir"]');
  await bp.click('[data-act="gcolor"][data-n="Bleu"]');
  await bp.click('[data-act="vgen"]');
  ok(await bp.locator('.tbl-edit tbody tr').count() === 6, '6 déclinaisons créées (3 tailles × 2 couleurs)');
  await bp.click('[data-act="spec-add"]');
  await bp.fill('.spec-row input[data-f="k"]', 'Semelle');
  await bp.fill('.spec-row input[data-f="v"]', 'Caoutchouc');
  await bp.setInputFiles('input[data-change="photos"]', path.join(DIR, 'photo.png'));
  await bp.waitForSelector('.photo img');
  await bp.click('.editor button[type=submit]');
  await bp.waitForURL(/produit\/\d+/);
  const productId = +bp.url().match(/produit\/(\d+)/)[1];
  ok(productId > 0, 'produit enregistré');
  ok(fs.readdirSync(path.join(DIR, 'public_html/maillage/media')).some((d) => /^c\d+$/.test(d)), 'photo ré-encodée dans media/');

  await bp.goto(B + 'espace/#/points-de-vente/nouveau');
  await bp.fill('#s-name', 'Atelier Test Alger');
  await bp.fill('#s-address', '12 rue des Essais');
  await bp.fill('#s-city', 'Alger');
  await bp.selectOption('#s-zone', '16');
  await bp.click('.editor button[type=submit]');
  await bp.waitForSelector('.editor [data-error]:not([hidden])');
  ok(/Placez le point de vente/.test(await bp.locator('.editor [data-error]').innerText()), 'position sur la carte obligatoire');
  await bp.waitForSelector('#map.leaflet-container');
  await bp.click('#map', { position: { x: 220, y: 160 } });
  ok(/Position/.test(await bp.locator('[data-pos]').innerText()), 'épingle placée d\'un clic sur la carte');
  await bp.click('label.switch:has(input[name=delivery])');
  await bp.click('.editor button[type=submit]');
  await bp.waitForURL(/#\/points-de-vente$/);
  await bp.waitForSelector('.slist li');
  ok(await bp.locator('.slist li').count() === 1, 'point de vente enregistré');
  // Position connue pour la suite des tests (Alger centre).
  const stores0 = await call(bp, 'dash_stores');
  const storeId = stores0.stores[0].id;
  await call(bp, 'store_save', Object.assign({}, stores0.stores[0], { lat: 36.7538, lng: 3.0588 }));

  await bp.goto(B + 'espace/#/disponibilite');
  await bp.waitForSelector('.stock-card');
  ok(await bp.locator('.stock-rows li').count() === 6, 'les 6 déclinaisons sont proposées au stock');
  await bp.click('[data-act="st-all"][data-q="10"]');
  ok(/6/.test(await bp.locator('[data-nchanges]').innerText()), '6 modifications en attente');
  await bp.click('[data-act="st-save"]');
  await bp.waitForSelector('.stock-rows .badge-good');
  ok(await bp.locator('.stock-rows .badge-good').count() === 6, 'stock enregistré : 6 déclinaisons en stock');
  await bp.screenshot({ path: path.join(SHOTS, '02-stock.png'), fullPage: true });

  /* ------------------------------------------------------------ validation */
  step('Validation par le propriétaire du site');
  const pub = await browser.newContext({ ...devices['iPhone 13'] });
  const cp = await pub.newPage();
  watch(cp, 'client');
  await cp.goto(B + 'explorer/#/produits?q=horizon', { waitUntil: 'load' });
  await cp.waitForSelector('[data-grid] .empty');
  ok(true, 'avant validation : le produit n\'est pas public');

  const ownerCtx = await browser.newContext(desk);
  const op = await ownerCtx.newPage();
  watch(op, 'propriétaire');
  await op.goto(B + 'admin.html', { waitUntil: 'load' });
  await op.waitForSelector('.empty');
  ok(/Connexion requise/.test(await op.locator('.empty h3').innerText()), 'administration fermée sans session /admin');
  const login = await op.evaluate(async (o) => (await fetch(o + '/admin/api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password: 'test-owner-pass' }) })).json(), ORIGIN);
  ok(login.ok === true, 'connexion du propriétaire à /admin');
  await op.reload({ waitUntil: 'load' });
  await op.waitForSelector('.tiles');
  ok(/1/.test(await op.locator('.tile.is-hot .tile-value').innerText()), '1 inscription à valider');
  await op.screenshot({ path: path.join(SHOTS, '03-admin.png'), fullPage: true });
  await op.click('button[data-do="activate"]');
  await op.waitForSelector('.tbl .badge-good');
  ok(mails().some((m) => /validé/.test(m.subject)), 'email « espace validé » envoyé à l\'entreprise');

  /* ----------------------------------------------------------------- client */
  step('Client : recherche, disponibilité, réservation');
  await cp.goto(B + 'explorer/', { waitUntil: 'load' });
  await cp.waitForSelector('.scard');
  await noOverflow(cp, 'carte (mobile)');
  ok(await cp.locator('.leaflet-marker-icon').count() > 0, 'épingles sur la carte');
  await cp.evaluate(() => ML.setLoc({ lat: 36.76, lng: 3.05, label: 'Alger', zone: '16', precise: true }));
  await cp.fill('[data-input="mapq"]', 'Atelier Test');
  await cp.waitForFunction(() => document.querySelectorAll('.scard').length === 1);
  ok(/Atelier Test Alger/.test(await cp.locator('.scard h3').innerText()), 'point de vente trouvé par son nom');
  ok(/km|m/.test(await cp.locator('.scard .km').innerText()), 'distance affichée');
  await cp.goto(B + 'explorer/#/produits?q=baskets horizon');
  await cp.waitForSelector('.pcard');
  ok(await cp.locator('.pcard').count() === 1, 'produit trouvé dans le catalogue');
  ok(await cp.locator('.pcard img[src=x]').count() === 0 && /<img/.test(await cp.locator('.pcard h3').innerText()), 'nom du produit affiché comme du texte');
  await cp.click('.pcard');
  await cp.waitForSelector('.av-item');
  await noOverflow(cp, 'fiche produit (mobile)');
  await cp.click('[data-act="psize"][data-v="42"]');
  await cp.click('[data-act="pcolor"][data-v="Bleu"]');
  await cp.waitForFunction(() => /42/.test(document.querySelector('.av-item .stock').textContent));
  ok(/En stock · 42 · Bleu/.test(await cp.locator('.av-item .stock').innerText()), 'disponibilité de la taille et de la couleur choisies');
  await cp.screenshot({ path: path.join(SHOTS, '04-produit.png'), fullPage: true });
  await cp.click('.av-item [data-kind="reservation"]');
  await cp.waitForSelector('.order-form');
  await cp.fill('#o-name', 'Karim Client');
  await cp.fill('#o-phone', '0661 22 33 44');
  await cp.fill('#o-email', 'karim@client.example');
  await cp.click('.order-form [data-step="1"]');
  ok(/17.800/.test((await cp.locator('[data-total]').innerText()).replace(/\s/g, '.')), 'total recalculé pour 2 articles');
  await cp.screenshot({ path: path.join(SHOTS, '05-commande.png') });
  await cp.click('.order-form [data-submit]');
  await cp.waitForSelector('.order-done');
  const ref1 = (await cp.locator('.done-ref b').innerText()).trim();
  ok(/^ML-[A-Z2-9]{6}$/.test(ref1), 'réservation enregistrée : ' + ref1);
  await cp.click('.order-done a.btn-primary');
  await cp.waitForSelector('.steps');
  ok(await cp.locator('.steps li.is-now b').innerText() === 'Demande reçue', 'suivi : demande reçue');
  await noOverflow(cp, 'suivi (mobile)');
  const m1 = mails();
  ok(m1.some((m) => m.to === 'karim@client.example' && m.subject.includes(ref1)), 'email de confirmation au client');
  ok(m1.some((m) => m.to === 'nadia@atelier-test.example' && /Nouvelle réservation/.test(m.subject)), 'email au point de vente');

  /* -------------------------------------------------------------- traitement */
  step('Point de vente : traitement de la commande');
  await bp.goto(B + 'espace/#/commandes');
  await bp.waitForSelector('.tbl-orders tbody tr');
  ok(await bp.locator('[data-todo]').first().innerText() === '1', 'pastille « 1 commande à traiter »');
  let st = await call(bp, 'stock', undefined, { store: storeId });
  const v42 = st.products[0].variants.find((v) => v.size === '42' && v.color === 'Bleu');
  ok(v42.qty === 8, 'stock mis de côté dès la réservation (10 → 8)', v42.qty);
  await bp.click('.tbl-orders tbody tr');
  await bp.waitForSelector('.odetail');
  ok(/Karim Client/.test(await bp.locator('.odetail').innerText()), 'coordonnées du client visibles par le point de vente');
  await bp.click('.modal-foot [data-next="confirmed"]');
  await bp.waitForSelector('.modal-foot [data-next="ready"]');
  await bp.click('.modal-foot [data-next="ready"]');
  await bp.waitForSelector('.modal-foot [data-next="done"]');
  await bp.click('.modal-foot [data-next="done"]');
  await bp.waitForFunction(() => !document.querySelector('.modal-foot [data-next]'));
  ok(/Retirée/.test(await bp.locator('.odetail .badge').first().innerText()), 'commande menée à terme : retirée');
  await bp.screenshot({ path: path.join(SHOTS, '06-commande-traitee.png') });
  await cp.reload({ waitUntil: 'load' });
  await cp.waitForSelector('.steps');
  ok(await cp.locator('.steps li.is-now b').innerText() === 'Retirée', 'suivi du client à jour : retirée');
  ok(mails().filter((m) => m.to === 'karim@client.example').length >= 4, 'emails de suivi envoyés au client');

  step('Client : commande en livraison puis annulation');
  const avail = await call(cp, 'availability', undefined, { product: productId, size: '40', color: 'Noir' });
  const o2 = await call(cp, 'order', { store_id: storeId, kind: 'order', mode: 'delivery', items: [{ variant_id: avail.stores[0].variants[0].id, qty: 3 }], name: 'Lina Test', phone: '0770112233', address: '5 rue de la Paix', city: 'Blida', zone: '09' });
  ok(/^ML-/.test(o2.ref || ''), 'commande en livraison enregistrée', o2);
  const tooMany = await call(cp, 'order', { store_id: storeId, kind: 'order', mode: 'pickup', items: [{ variant_id: avail.stores[0].variants[0].id, qty: 9 }], name: 'Lina Test', phone: '0770112233' });
  ok(tooMany.error && /Stock insuffisant/.test(tooMany.message), 'commande au-delà du stock refusée');
  const wrongPhone = await call(cp, 'track', undefined, { ref: o2.ref, phone: '0660000000' });
  ok(wrongPhone.status === 404, 'suivi refusé avec un mauvais téléphone');
  const goodPhone = await call(cp, 'track', undefined, { ref: o2.ref.toLowerCase(), phone: '+213 770 11 22 33' });
  ok(goodPhone.order && goodPhone.order.ref === o2.ref && goodPhone.order.phone === undefined, 'suivi par numéro + téléphone (sans exposer le téléphone)');
  await cp.goto(B + 'explorer/#/suivi/' + o2.token);
  await cp.waitForSelector('[data-act="cancel"]');
  await cp.click('[data-act="cancel"]');
  await cp.click('.modal [data-yes]');
  await cp.waitForSelector('.notice.is-bad');
  st = await call(bp, 'stock', undefined, { store: storeId });
  ok(st.products[0].variants.find((v) => v.size === '40' && v.color === 'Noir').qty === 10, 'annulation par le client : stock rétabli');

  /* ------------------------------------------------------------ distributeur */
  step('Distributeur et partenariat');
  const distCtx = await browser.newContext(desk);
  const dp = await distCtx.newPage();
  watch(dp, 'distributeur');
  await dp.goto(B, { waitUntil: 'load' });
  const d1 = await call(dp, 'signup', { kind: 'distributor', company: 'Relais Essai', name: 'Omar Distrib', email: 'omar@relais-essai.example', phone: '0555000111', password: 'motdepasse-solide', wilaya: '31', accept: true });
  ok(d1.company && d1.company.status === 'pending', 'distributeur inscrit, en attente');
  const dup = await call(dp, 'signup', { kind: 'distributor', company: 'Autre', name: 'Omar', email: 'omar@relais-essai.example', phone: '0555000111', password: 'motdepasse-solide', accept: true });
  ok(dup.error === 'exists', 'email déjà utilisé refusé');
  await dp.goto(B + 'espace/', { waitUntil: 'load' });
  await dp.waitForSelector('.side-nav');
  let req = await call(dp, 'partner_request', { company_id: 0 });
  ok(req.error, 'partenariat impossible tant que l\'espace n\'est pas validé');
  await op.reload({ waitUntil: 'load' });
  await op.waitForSelector('button[data-do="activate"]');
  await op.click('button[data-do="activate"]');
  await op.waitForFunction(() => !document.querySelector('button[data-do="activate"]'));
  const dstore = await call(dp, 'store_save', { name: 'Relais Essai Oran', type: 'distributeur', city: 'Oran', wilaya: '31', lat: 35.699, lng: -0.636, address: 'Centre', hours: { open: '00:00', close: '23:59', days: [0, 1, 2, 3, 4, 5, 6] }, pickup: true, reserve: true });
  ok(dstore.store && dstore.store.id > 0, 'point de vente du distributeur créé');
  let dstock = await call(dp, 'stock', undefined, { store: dstore.store.id });
  ok(dstock.products.length === 0, 'sans partenariat : aucun produit de la marque à référencer');
  await dp.goto(B + 'espace/#/reseau');
  await dp.waitForSelector('[data-act="p-request"]');
  await dp.click('[data-act="p-request"]');
  await dp.waitForSelector('.badge-warn');
  await bp.goto(B + 'espace/#/reseau');
  await bp.waitForSelector('[data-act="p-respond"][data-ok="1"]');
  await bp.screenshot({ path: path.join(SHOTS, '07-reseau.png'), fullPage: true });
  await bp.click('[data-act="p-respond"][data-ok="1"]');
  await bp.waitForSelector('.pstats');
  dstock = await call(dp, 'stock', undefined, { store: dstore.store.id });
  ok(dstock.products.length === 1 && dstock.products[0].own === false, 'partenariat accepté : le catalogue de la marque est référençable');
  const saved = await call(dp, 'stock_save', { store_id: dstore.store.id, items: dstock.products[0].variants.map((v) => ({ variant_id: v.id, qty: 5 })) });
  ok(saved.saved === 6, 'stock du distributeur enregistré');
  const both = await call(cp, 'availability', undefined, { product: productId, lat: 35.7, lng: -0.63 });
  ok(both.stores.length === 2 && both.stores[0].name === 'Relais Essai Oran', 'le produit est disponible dans 2 points de vente, le plus proche d\'abord');
  const foreign = await call(dp, 'stock_save', { store_id: storeId, items: [{ variant_id: v42.id, qty: 99 }] });
  ok(foreign.error, 'un distributeur ne peut pas modifier le stock d\'un magasin de la marque');
  const steal = await call(dp, 'product_save', { id: productId, brand_id: 1, name: 'Piraté', price: 1 });
  ok(steal.error, 'un compte ne peut pas modifier le produit d\'une autre entreprise');
  const peek = await call(dp, 'order_get', undefined, { id: 1 });
  ok(peek.status === 404 || peek.error, 'un compte ne voit pas les commandes d\'une autre entreprise');

  /* ---------------------------------------------------------------- analyses */
  step('Analyses de la marque');
  await bp.goto(B + 'espace/#/analyses');
  await bp.waitForSelector('.ch-svg');
  const an = await call(bp, 'analytics', undefined, { days: 30 });
  ok(an.tiles.orders.v === 2, 'analyses : 2 commandes sur la période', an.tiles.orders);
  ok(an.tiles.revenue.v === 17800, 'analyses : chiffre d\'affaires hors commande annulée', an.tiles.revenue);
  ok(an.tiles.searches.v >= 2, 'analyses : recherches comptées', an.tiles.searches);
  ok(an.stores.length === 2 && an.stores.some((s) => !s.own), 'analyses : le magasin du distributeur fait partie du réseau');
  await bp.goto(B + 'espace/#/analyses?t=expansion');
  await bp.waitForSelector('#xmap.leaflet-container');
  await bp.screenshot({ path: path.join(SHOTS, '08-analyses.png'), fullPage: true });
  await noOverflow(bp, 'analyses (ordinateur)');

  /* ------------------------------------------------------------ démonstration */
  step('Démonstration en lecture seule');
  const demoCtx = await browser.newContext({ ...devices['iPhone 13'] });
  const mp = await demoCtx.newPage();
  watch(mp, 'démo');
  await mp.goto(B + 'espace/', { waitUntil: 'load' });
  await mp.click('[data-act="demo"]');
  await mp.waitForSelector('.banner.is-demo');
  await mp.waitForSelector('.tiles');
  await noOverflow(mp, 'vue d\'ensemble (mobile)');
  await mp.screenshot({ path: path.join(SHOTS, '09-demo-mobile.png') });
  const ro = await call(mp, 'stock_save', { store_id: 1, items: [] });
  ok(ro.error === 'demo' && ro.status === 403, 'aucune écriture possible en démonstration');
  for (const r of ['commandes', 'catalogue', 'points-de-vente', 'disponibilite', 'reseau', 'analyses', 'analyses?t=demande', 'analyses?t=performance', 'parametres']) {
    await mp.goto(B + 'espace/#/' + r);
    await mp.waitForTimeout(700);
    await noOverflow(mp, r + ' (mobile)');
  }

  /* ------------------------------------------------- mot de passe, administration */
  step('Mot de passe oublié, espace ouvert depuis l\'administration, fin de la démonstration');
  const fresh = await browser.newContext(desk);
  const fp = await fresh.newPage();
  watch(fp, 'mot de passe');
  await fp.goto(B + 'espace/#/oubli', { waitUntil: 'load' });
  await fp.fill('#f-email', 'omar@relais-essai.example');
  await fp.click('[data-form="forgot"] button[type=submit]');
  await fp.waitForFunction(() => /Vérifiez vos emails/.test(document.body.textContent));
  const link = (mails().reverse().find((m) => /Nouveau mot de passe/.test(m.subject)) || { text: '' }).text.match(/#\/reset\/([a-f0-9]{48})/);
  ok(!!link, 'lien de réinitialisation envoyé par email');
  await fp.goto(B + 'espace/#/reset/' + (link ? link[1] : 'x'));
  await fp.waitForSelector('#r-pass');
  await fp.fill('#r-pass', 'nouveau-motdepasse');
  await fp.click('[data-form="reset"] button[type=submit]');
  await fp.waitForSelector('.side-nav');
  ok(await fp.locator('[data-co-name]').first().innerText() === 'Relais Essai', 'nouveau mot de passe enregistré, compte connecté');
  const old = await call(dp, 'overview');
  ok(old.status === 401, 'les anciennes sessions du compte sont fermées');
  const badLogin = await call(cp, 'login', { email: 'omar@relais-essai.example', password: 'motdepasse-solide' });
  ok(badLogin.error === 'credentials', 'l\'ancien mot de passe ne fonctionne plus');

  const [tab] = await Promise.all([ownerCtx.waitForEvent('page'), op.locator('button[data-act="open"]').first().click()]);
  await tab.waitForSelector('.banner.is-admin');
  ok(/au nom de/.test(await tab.locator('.banner.is-admin').innerText()), 'le propriétaire ouvre l\'espace d\'une entreprise');
  await tab.close();
  await op.click('button[data-act="demo"][data-do="delete"]');
  await op.click('.modal [data-yes]');
  await op.waitForSelector('button[data-act="demo"][data-do="seed"]');
  const boot = await call(cp, 'bootstrap');
  ok(boot.config.demo === false && boot.brands.length === 1 && boot.counts.stores === 2, 'démonstration supprimée : il ne reste que les entreprises réelles', boot.counts);
  const noDemo = await call(cp, 'demo_login', {});
  ok(noDemo.status === 404, 'plus de démonstration proposée');

  /* ---------------------------------------------------------------- sécurité */
  step('Fichiers internes et requêtes forgées');
  const status = (u, o) => cp.evaluate(async ({ u, o }) => (await fetch(u, o)).status, { u: B + u, o });
  ok(await status('lib/core.php') === 403, 'lib/ interdit');
  ok(await status('config.php') === 403, 'config.php interdit');
  ok(await status('api.php?action=logout', { method: 'POST' }) === 403, 'écriture refusée sans l\'en-tête X-Requested-With');
  ok(await status('api.php?action=overview') === 401, 'espace entreprise fermé sans session');
  ok(await status('api.php?action=adm_overview') === 401, 'administration fermée sans session');
  const csrf = await bp.evaluate(async (b) => (await fetch(b + 'api.php?action=brand_delete', { method: 'POST', headers: { 'X-Requested-With': 'maillage', 'Content-Type': 'application/json' }, body: '{"id":1}' })).status, B);
  ok(csrf === 401, 'écriture refusée sans jeton CSRF');

  await browser.close();
  step('Erreurs JavaScript');
  ok(errors.length === 0, 'aucune erreur dans les pages', errors.slice(0, 6));
  console.log(failed ? `\n${failed} vérification(s) en échec` : '\nTout est vert.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
