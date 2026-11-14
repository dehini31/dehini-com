/*
 * Tests de bout en bout de Maillage (Playwright / Chromium), sur le faux hébergement de run.sh :
 *   inscription d'une marque depuis la landing → produit, point de vente, stock → validation par
 *   le propriétaire → recherche et réservation côté client (mobile) → traitement de la commande →
 *   annulation par le client → distributeur et partenariat → analyses → démonstration en lecture
 *   seule → radar (commande sans point de vente, niveaux commune / wilaya / partout, prise et
 *   remise par le point de vente) → recherche par photo (identification interne, apprentissage) et recherche
 *   active → accès privé d'un point de vente → inscription d'un point de
 *   vente indépendant → cloisonnement entre entreprises, contenu saisi non exécuté, fichiers internes interdits.
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
  await bp.selectOption('#s-zone', '16');
  await bp.waitForFunction(() => !document.querySelector('#s-commune').disabled);
  const storeErr = async (re) => { await bp.click('.editor button[type=submit]'); await bp.waitForFunction((src) => new RegExp(src).test(document.querySelector('.editor [data-error]').textContent), re.source); return true; };
  ok(await storeErr(/Placez le point de vente/), 'position sur la carte obligatoire');
  await bp.waitForSelector('#map.leaflet-container');
  await bp.click('#map', { position: { x: 220, y: 160 } });
  ok(/Position/.test(await bp.locator('[data-pos]').innerText()), 'épingle placée d\'un clic sur la carte');
  ok(await storeErr(/commune/), 'commune du point de vente obligatoire');
  await bp.selectOption('#s-commune', 'Alger Centre');
  ok(await storeErr(/email de connexion/), 'accès du point de vente (email et mot de passe) demandé à la création');
  await bp.fill('#s-login', 'alger@atelier-test.example');
  await bp.fill('#s-pass', 'magasin-solide');
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
  const inStock = () => bp.evaluate(() => Array.from(document.querySelectorAll('.stock-rows .badge')).filter((b) => b.textContent.trim() === 'En stock').length);
  await bp.waitForFunction(() => Array.from(document.querySelectorAll('.stock-rows .badge')).filter((b) => b.textContent.trim() === 'En stock').length === 6, null, { timeout: 8000 }).catch(() => {});
  ok(await inStock() === 6, 'stock enregistré : 6 déclinaisons en stock', { inStock: await inStock(), toast: await bp.locator('.toast').allInnerTexts() });
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
  const dstore = await call(dp, 'store_save', { name: 'Relais Essai Oran', type: 'distributeur', commune: 'Oran', wilaya: '31', lat: 35.699, lng: -0.636, address: 'Centre', hours: { open: '00:00', close: '23:59', days: [0, 1, 2, 3, 4, 5, 6] }, pickup: true, reserve: true, login_email: 'oran@relais-essai.example', login_password: 'magasin-oran-1' });
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


  /* ------------------------------------------------------------------- radar */
  step('Radar : commande sans point de vente, proposée au plus proche');
  const communes = await call(cp, 'communes', undefined, { zone: '16' });
  ok(communes.communes && communes.communes.some((c) => c[0] === 'Bab Ezzouar' && /[؀-ۿ]/.test(c[1])), 'communes de la wilaya (noms français et arabes)');
  const slug = (await call(cp, 'products', undefined, { q: 'horizon' })).items[0].slug;
  await cp.goto(B + 'explorer/#/produit/' + slug);
  await cp.waitForSelector('[data-act="cod"]');
  ok(true, 'fiche produit : commande livrée sans choisir de magasin proposée');
  const offer = await cp.context().newPage();
  watch(offer, 'page de vente');
  await offer.goto(B + 'offre/?p=' + slug, { waitUntil: 'load' });
  await offer.waitForSelector('.cod-form');
  await noOverflow(offer, 'page de vente (mobile)');
  await offer.click('[data-act="of-size"][data-v="41"]');
  await offer.click('[data-act="of-color"][data-v="Bleu"]');
  ok(/41 · Bleu/.test(await offer.locator('#c-variant option:checked').innerText()), 'la déclinaison choisie dans la page passe dans le formulaire');
  await offer.fill('#c-name', 'Samir Radar');
  await offer.fill('#c-phone', '0662 33 44 55');
  await offer.click('.cod-form [data-submit]');
  ok(/wilaya puis la commune/.test(await offer.locator('.cod-form [data-error]').innerText()), 'wilaya et commune de livraison obligatoires');
  await offer.selectOption('#c-zone', '16');
  await offer.waitForFunction(() => !document.querySelector('#c-commune').disabled);
  await offer.selectOption('#c-commune', 'Bab Ezzouar');
  await offer.click('.cod-form [data-submit]');
  await offer.waitForSelector('.order-done');
  const rref = (await offer.locator('.done-ref b').innerText()).trim();
  ok(/^ML-/.test(rref), 'commande radar enregistrée sans adresse (facultative) : ' + rref);
  await offer.screenshot({ path: path.join(SHOTS, '10-page-de-vente.png') });
  await offer.click('.order-done a.btn-primary');
  await offer.waitForSelector('.notice.is-radar');
  ok(true, 'suivi : le client voit que l\'on cherche le point de vente le plus proche');
  const rtoken = offer.url().split('/suivi/')[1];

  let br = await call(bp, 'radar');
  const near = br.nearby.find((o) => o.ref === rref);
  ok(near && near.rank === 2 && near.level === 2, 'proposée d\'abord au magasin de la marque (même wilaya, pas de magasin dans la commune)', near);
  ok(near && near.stores.length === 1 && near.stores[0].id === storeId, 'avec le magasin qui a l\'article en stock');
  ok(br.received.some((o) => o.ref === rref && o.waiting), 'la marque la voit parmi les commandes reçues par ses pages');
  let dr = await call(dp, 'radar');
  ok(!dr.nearby.some((o) => o.ref === rref), 'pas encore proposée au distributeur d\'une autre wilaya');
  ok(mails().some((m) => m.to === 'alger@atelier-test.example' && m.subject.includes(rref)), 'email « commande à proximité » au point de vente');
  ok(br.nearby.every((o) => o.phone === undefined && o.name === undefined), 'coordonnées du client cachées avant la prise en charge');

  await op.reload({ waitUntil: 'load' });
  await op.waitForSelector('#a-radar');
  await op.fill('#a-radar', '0');
  await op.click('[data-form="settings"] button[type=submit]');
  await op.waitForSelector('.toast');
  dr = await call(dp, 'radar');
  ok(dr.nearby.some((o) => o.ref === rref && o.rank === 3), 'délai à 0 : proposée à tous les points de vente qui ont l\'article');
  await op.fill('#a-radar', '30');
  await op.click('[data-form="settings"] button[type=submit]');

  step('Point de vente : espace privé, prise de la commande radar');
  const storeCtx = await browser.newContext({ ...devices['iPhone 13'] });
  const sp = await storeCtx.newPage();
  watch(sp, 'point de vente');
  await sp.goto(B + 'espace/', { waitUntil: 'load' });
  await sp.fill('#l-email', 'alger@atelier-test.example');
  await sp.fill('#l-pass', 'magasin-solide');
  await sp.click('[data-form="login"] button[type=submit]');
  await sp.waitForSelector('.side-nav');
  ok(await sp.locator('.side-nav a').count() === 6, 'menu limité au point de vente (6 entrées)');
  ok(/Atelier Test Alger/.test(await sp.locator('.side-co').innerText()), 'espace au nom du point de vente');
  ok((await call(sp, 'dash_products')).status === 403, 'catalogue réservé au compte de l\'entreprise');
  ok((await call(sp, 'network')).status === 403, 'réseau réservé au compte de l\'entreprise');
  ok((await call(sp, 'analytics', undefined, { days: 30 })).status === 403, 'analyses réservées au compte de l\'entreprise');
  const spStores = await call(sp, 'dash_stores');
  ok(spStores.stores.length === 1 && spStores.stores[0].id === storeId && spStores.stores[0].login_email === undefined, 'il ne voit que sa fiche (sans les identifiants)');
  const before41 = (await call(bp, 'stock', undefined, { store: storeId })).products[0].variants.find((v) => v.size === '41' && v.color === 'Bleu').qty;
  await sp.goto(B + 'espace/#/radar');
  await sp.waitForSelector('.rcard');
  await noOverflow(sp, 'radar (mobile)');
  await sp.screenshot({ path: path.join(SHOTS, '11-radar-magasin.png'), fullPage: true });
  await sp.click('.rcard [data-act="claim"]');
  await sp.waitForSelector('.odetail');
  ok(/Samir Radar/.test(await sp.locator('.odetail').innerText()) && /Bab Ezzouar/.test(await sp.locator('.odetail').innerText()), 'prise en charge : coordonnées et commune du client affichées');
  const after41 = (await call(bp, 'stock', undefined, { store: storeId })).products[0].variants.find((v) => v.size === '41' && v.color === 'Bleu').qty;
  ok(after41 === before41 - 1, 'le stock du magasin baisse à la prise en charge', [before41, after41]);
  let tr = await call(cp, 'track', undefined, { token: rtoken });
  ok(tr.order.status === 'confirmed' && tr.order.store && tr.order.store.name === 'Atelier Test Alger', 'le client voit le point de vente qui a pris sa commande');
  dr = await call(dp, 'radar');
  ok(!dr.nearby.some((o) => o.ref === rref), 'une commande prise disparaît du radar des autres');
  const twice = await call(dp, 'radar_claim', { id: tr.order.id, store_id: dstore.store.id });
  ok(twice.error && /vient d'être prise/.test(twice.message), 'un second point de vente ne peut plus la prendre');

  await sp.click('.modal [data-release]');
  await sp.locator('.modal-wrap').last().locator('[data-yes]').click();
  await sp.waitForSelector('.rcard');
  tr = await call(cp, 'track', undefined, { token: rtoken });
  ok(tr.order.waiting && tr.order.status === 'pending', 'remise sur le radar : la commande attend de nouveau un point de vente');
  ok((await call(bp, 'stock', undefined, { store: storeId })).products[0].variants.find((v) => v.size === '41' && v.color === 'Bleu').qty === before41, 'remise sur le radar : stock rétabli');
  await sp.click('.rcard [data-act="claim"]');
  await sp.waitForSelector('.modal-foot [data-next="shipped"]');
  await sp.click('.modal-foot [data-next="shipped"]');
  await sp.waitForSelector('.modal-foot [data-next="done"]');
  await sp.click('.modal-foot [data-next="done"]');
  await sp.waitForFunction(() => !document.querySelector('.modal-foot [data-next]'));
  tr = await call(cp, 'track', undefined, { token: rtoken });
  ok(tr.order.status === 'done', 'commande radar livrée par le point de vente');
  br = await call(bp, 'radar');
  ok(br.received.some((o) => o.ref === rref && o.store && o.store.own && o.status === 'done'), 'la marque suit la commande jusqu\'à la livraison');
  const otherOrder = (await call(dp, 'orders', undefined, { status: 'all' })).orders;
  ok(!otherOrder.length || (await call(sp, 'order_get', undefined, { id: otherOrder[0].id })).status === 404, 'un point de vente ne voit pas les commandes des autres');

  /* ------------------------------------------- point de vente indépendant */
  step('Point de vente indépendant : landing dédiée et choix des marques');
  const posCtx = await browser.newContext({ ...devices['iPhone 13'] });
  const pp = await posCtx.newPage();
  watch(pp, 'landing points de vente');
  await pp.goto(B + 'point-de-vente/', { waitUntil: 'load' });
  await pp.waitForSelector('.pos-brand');
  await noOverflow(pp, 'landing des points de vente (mobile)');
  ok(await pp.locator('.pos-brand').count() === 1 && /<b>/.test(await pp.locator('.pos-brand b').innerText()), 'seules les vraies marques sont proposées, nom affiché comme du texte');
  await pp.fill('#s-company', 'Boutique Essai');
  await pp.fill('#s-name', 'Lamia Boutique');
  await pp.fill('#s-phone', '0770 44 55 66');
  await pp.fill('#s-email', 'lamia@boutique-essai.example');
  await pp.selectOption('#pv-zone', '16');
  await pp.waitForFunction(() => !document.querySelector('#pv-commune').disabled);
  await pp.selectOption('#pv-commune', 'Bab Ezzouar');
  await pp.click('.pos-brand');
  await pp.fill('#s-pass', 'boutique-solide');
  await pp.check('[data-form="pos"] input[name=accept]');
  await pp.screenshot({ path: path.join(SHOTS, '12-inscription-point-de-vente.png'), fullPage: true });
  await pp.click('[data-form="pos"] button[type=submit]');
  await pp.waitForURL(/\/espace\//);
  await pp.waitForSelector('.onboard');
  const pme = await call(pp, 'me');
  ok(pme.company.kind === 'pos' && pme.company.status === 'pending', 'point de vente inscrit, en attente de validation');
  const pst = (await call(pp, 'dash_stores')).stores;
  ok(pst.length === 1 && pst[0].status === 'unlocated' && pst[0].commune === 'Bab Ezzouar', 'sa fiche est créée dans sa commune, position à confirmer');
  const bnet = await call(bp, 'network');
  ok(bnet.partners.some((x) => x.status === 'pending' && !x.mine && x.company.name === 'Boutique Essai'), 'la marque choisie reçoit sa demande de partenariat');

  /* ------------------------------------------------------ recherche par photo */
  step('Recherche par photo : identification interne, produit trouvé, similaires');
  const fd = async (action, file, fields) => cp.evaluate(async ({ action, fields, bytes }) => {
    const f = new FormData();
    f.append('file', new Blob([new Uint8Array(bytes)], { type: 'image/jpeg' }), 'p.jpg');
    Object.entries(fields || {}).forEach(([k, v]) => f.append(k, v));
    try { return await ML.api(action, { body: f }); } catch (e) { return { error: e.code, message: e.message, status: e.status }; }
  }, { action, fields, bytes: Array.from(fs.readFileSync(path.join(DIR, file))) });
  await cp.goto(B + 'explorer/#/photo');
  await cp.waitForSelector('[data-drop]');
  await noOverflow(cp, 'recherche par photo (mobile)');
  ok(await cp.locator('.tabbar a[data-nav="photo"].is-on').count() === 1, 'entrée « Photo » dans la navigation');
  await cp.setInputFiles('[data-vs-file="pick"]', path.join(DIR, 'faux.jpg'));
  await cp.waitForSelector('[data-vs-error]:not([hidden])');
  ok(true, 'fichier qui n\'est pas une image refusé avec un message');
  // Photo identique à celle d'une fiche : la plateforme propose sa description, à vérifier.
  await cp.setInputFiles('[data-vs-file="pick"]', path.join(DIR, 'photo.png'));
  await cp.waitForSelector('[data-form="vs-info"] .badge-info');
  ok((await cp.inputValue('#v-name')).length > 2 && await cp.inputValue('#v-cat') !== '', 'photo du catalogue reconnue : rayon et nom proposés, modifiables');
  ok(/à vérifier/.test(await cp.locator('[data-form="vs-info"] .notice').innerText()), 'la proposition est présentée comme non vérifiée');
  await cp.screenshot({ path: path.join(SHOTS, '13-photo-description.png'), fullPage: true });
  await cp.click('[data-form="vs-info"] button[type=submit]');
  await cp.waitForSelector('[data-exact]');
  ok(/Photo identique/.test(await cp.locator('.vs-exact-head').innerText()), 'photo identique à celle du catalogue : produit trouvé, avec la raison affichée');
  ok(/<img/.test(await cp.locator('[data-exact] h2').innerText()) && await cp.locator('[data-exact] img[src=x]').count() === 0, 'nom du produit trouvé affiché comme du texte');
  ok(await cp.locator('[data-near] li').count() === 2, 'points de vente qui l\'ont en stock');
  ok(await cp.locator('.vs-actions .btn').count() === 4 && await cp.locator('[data-act="vs-research"]').count() === 1, 'boutons : fiche, points de vente, marque, similaires, recherche active');
  await noOverflow(cp, 'résultat de la recherche par photo (mobile)');
  await cp.screenshot({ path: path.join(SHOTS, '14-photo-resultat.png'), fullPage: true });

  // Reconnaissance d'image dans le navigateur : le vrai modèle tourne (WebAssembly, CSP, types de fichiers).
  const real = await cp.evaluate(async (bytes) => {
    const blob = new Blob([new Uint8Array(bytes)], { type: 'image/jpeg' });
    const t0 = performance.now();
    const r = await ML.Vision.analyze(blob);
    return { n: r.types.length, sum: r.types.reduce((a, t) => a + t.p, 0), top: r.types[0], ms: performance.now() - t0 };
  }, Array.from(fs.readFileSync(path.join(DIR, 'photo2.jpg'))));
  ok(real.n > 100 && real.sum > 0 && real.sum <= 1.001 && real.top.fr && real.top.ar && real.top.cat, 'reconnaissance d\'image exécutée dans le navigateur, sans service extérieur (' + Math.round(real.ms) + ' ms)', real);
  // Le comportement de la page avec ce que reconnaît le modèle (résultats fixés pour le test).
  const visionStub = (r) => cp.evaluate((r) => { ML.Vision.analyze = async () => r; }, r);
  const tryPhoto = async () => {
    await cp.click('[data-act="vs-new"]');
    await cp.waitForSelector('[data-drop]');
    await cp.setInputFiles('[data-vs-file="pick"]', path.join(DIR, 'photo2.jpg'));
    await cp.waitForSelector('[data-form="vs-info"]');
  };
  await visionStub({ types: [{ fr: 'Boisson gazeuse', ar: 'مشروب غازي', cat: 'alimentation', p: 0.62 }, { fr: 'Eau minérale', ar: 'مياه معدنية', cat: 'alimentation', p: 0.3 }], other: false,
    brand: { name: 'Selecto', cat: 'alimentation', fr: 'Boisson gazeuse', ar: 'مشروب غازي' }, color: { fr: 'transparent', ar: 'شفاف' } });
  await tryPhoto();
  ok(await cp.inputValue('#v-name') === 'Boisson gazeuse' && await cp.inputValue('#v-brand') === 'Selecto' && await cp.inputValue('#v-cat') === 'alimentation' && /transparent/.test(await cp.inputValue('#v-details')),
    'photo reconnue : rayon, nom, marque et couleur remplis, modifiables');
  ok(/Proposition de la reconnaissance/.test(await cp.locator('[data-form="vs-info"] .badge-info').innerText()) && /à vérifier/.test(await cp.locator('[data-form="vs-info"] .notice').innerText()), 'présentés comme une proposition à vérifier');
  await cp.click('.vs-alts .chip');
  ok(await cp.inputValue('#v-name') === 'Eau minérale' && await cp.locator('.vs-alts .chip').innerText() === 'Boisson gazeuse', 'autre possibilité choisie en un geste');
  await cp.screenshot({ path: path.join(SHOTS, '13b-photo-reconnue.png'), fullPage: true });
  await visionStub({ types: [{ fr: 'Sel de table', ar: 'ملح الطعام', cat: 'alimentation', p: 0.24 }, { fr: 'Épices', ar: 'توابل', cat: 'alimentation', p: 0.16 }, { fr: 'Sauce', ar: 'صلصة', cat: 'alimentation', p: 0.12 }], other: false, brand: null, color: null });
  await tryPhoto();
  ok(await cp.inputValue('#v-name') === '' && await cp.inputValue('#v-cat') === 'alimentation' && await cp.locator('.vs-alts .chip').count() === 3, 'reconnaissance incertaine : le rayon seulement, et les produits possibles à choisir');
  await visionStub({ types: [{ fr: 'Chemise', ar: 'قميص', cat: 'mode', p: 0.1 }], other: true, brand: null, color: null });
  await tryPhoto();
  ok(await cp.inputValue('#v-name') === '' && /pas reconnu de produit/.test(await cp.locator('[data-form="vs-info"] .notice').innerText()), 'pas un produit : message clair, le client décrit le produit');
  await visionStub(null);
  await cp.evaluate(() => { ML.Vision.analyze = async () => { throw new Error('test'); }; });
  await tryPhoto();
  ok(await cp.locator('[data-form="vs-info"] .badge-info').count() === 0 && await cp.inputValue('#v-name') === '' && /pas pu se lancer/.test(await cp.locator('[data-form="vs-info"] .notice').innerText()), 'reconnaissance indisponible : le client décrit le produit');
  const unknown = await fd('visual_identify', 'photo2.jpg', {});
  ok(unknown.auto === false && unknown.info.name === '', 'comparaison au catalogue : aucune fiche proposée sans ressemblance', unknown);
  const pname = (await fd('visual_identify', 'photo.png', {})).info.name;
  const simName = pname.replace(/<[^>]*>/g, ' ').split(/\s+/).filter((w) => w.length > 3).slice(0, 2).join(' ');
  const sim = await fd('visual_search', 'photo2.jpg', { name: simName + ' modèle' });
  ok(sim.exact === null && sim.similar.some((x) => x.id === productId), 'nom proche, photo différente : présenté comme similaire, pas comme le produit', { simName, sim });

  step('Recherche active (Radar) : lancement, réponse d\'un point de vente, suivi');
  await cp.fill('#v-name', 'Perceuse à percussion');
  await cp.selectOption('#v-cat', 'bricolage');
  await cp.fill('#v-details', '');
  await cp.click('[data-form="vs-info"] button[type=submit]');
  await cp.waitForSelector('[data-nothing]');
  ok(/Aucun produit correspondant/.test(await cp.locator('[data-nothing]').innerText()), 'ni produit ni alternative : message clair');
  await cp.click('[data-act="vs-research"]');
  await cp.waitForSelector('[data-form="vs-research"]');
  await cp.fill('#rs-cname', 'Karim Client');
  await cp.fill('#rs-phone', '0661 22 33 44');
  await cp.fill('#rs-email', 'karim@client.example');
  await cp.waitForFunction(() => !document.querySelector('#rs-commune').disabled);
  ok(await cp.inputValue('#rs-zone') === '16' && await cp.inputValue('#rs-commune') === 'Bab Ezzouar', 'wilaya et commune reprises de la dernière commande du client');
  await cp.selectOption('#rs-commune', 'Alger Centre');
  const noPlace = await fd('research_create', 'photo2.jpg', { name: 'Perceuse', cname: 'Karim', phone: '0661223344' });
  ok(noPlace.error && /wilaya/.test(noPlace.message), 'wilaya et commune obligatoires pour lancer une recherche');
  await cp.click('[data-form="vs-research"] [data-submit]');
  await cp.waitForSelector('.order-done');
  const sref = (await cp.locator('.done-ref b').innerText()).trim();
  ok(/^RS-[A-Z2-9]{6}$/.test(sref), 'recherche active lancée : ' + sref);
  await cp.click('.order-done a.btn-primary');
  await cp.waitForSelector('.vs-track');
  ok(/votre commune/.test(await cp.locator('.notice.is-radar').innerText()), 'suivi : proposée d\'abord aux points de vente de la commune');
  const stoken = cp.url().split('/recherche/')[1];
  ok(mails().some((m) => m.to === 'alger@atelier-test.example' && /Un client cherche/.test(m.subject)), 'email au point de vente de la commune');
  ok(mails().some((m) => m.to === 'karim@client.example' && m.subject.includes(sref)), 'email de confirmation au client');

  let rr = await call(bp, 'radar');
  const mineR = rr.research.find((x) => x.ref === sref);
  ok(mineR && mineR.rank === 1 && mineR.image && mineR.contact === null && mineR.name === 'Perceuse à percussion', 'dans le Radar du point de vente de la commune, sans les coordonnées du client', mineR);
  ok(!(await call(dp, 'radar')).research.some((x) => x.ref === sref), 'pas encore proposée au point de vente d\'une autre wilaya');
  ok((await call(sp, 'radar')).research.some((x) => x.ref === sref), 'visible aussi depuis l\'accès privé du magasin');
  await bp.goto(B + 'espace/#/radar');
  await bp.waitForSelector('[data-research] .rcard');
  ok(await bp.locator('.side-nav [data-radar]').innerText() === '1', 'pastille du Radar : 1 recherche en attente');
  await bp.click('[data-research] [data-act="rs-reply"]');
  await bp.waitForSelector('[data-form="rs-reply"]');
  await bp.waitForFunction(() => document.querySelectorAll('#rr-product option').length === 2);
  await bp.selectOption('#rr-product', String(productId));
  await bp.fill('#rr-price', '8900');
  await bp.fill('#rr-msg', 'Disponible en <b>magasin</b>');
  await bp.screenshot({ path: path.join(SHOTS, '15-radar-reponse.png') });
  await bp.click('.modal [data-send]');
  await bp.waitForSelector('.rs-replied');
  ok(/Karim Client/.test(await bp.locator('.rs-replied').innerText()), 'après la réponse : le client (qui l\'a accepté) peut être appelé');
  ok(mails().some((m) => m.to === 'karim@client.example' && /Une réponse/.test(m.subject)), 'email au client : une réponse est arrivée');
  await cp.click('[data-act="rs-refresh"]');
  await cp.waitForSelector('.vs-replies li');
  const reply = await cp.locator('.vs-replies li').innerText();
  ok(/Atelier Test Alger/.test(reply) && /8.900/.test(reply.replace(/\s/g, '.')) && /<b>magasin<\/b>/.test(reply), 'le client voit la réponse : magasin, prix, message (affiché comme du texte)');
  ok(await cp.locator('.vs-reply-product').count() === 1, 'avec la fiche du produit proposé');
  await noOverflow(cp, 'suivi d\'une recherche (mobile)');
  await cp.screenshot({ path: path.join(SHOTS, '16-recherche-suivi.png'), fullPage: true });
  const pubR = await call(cp, 'research_get', undefined, { ref: sref, phone: '0661223344' });
  ok(pubR.research && !/0661223344|karim@client/.test(JSON.stringify(pubR)), 'suivi par numéro + téléphone, sans exposer les coordonnées');
  ok((await call(cp, 'research_get', undefined, { ref: sref, phone: '0550000000' })).status === 404, 'suivi refusé avec un mauvais téléphone');
  await cp.goto(B + 'explorer/#/suivi');
  await cp.waitForSelector('.mine');
  ok((await cp.locator('.card').last().innerText()).includes(sref), 'la recherche figure dans « Suivi »');
  await cp.goto(B + 'explorer/#/recherche/' + stoken);
  await cp.waitForSelector('[data-act="rs-close"][data-found="1"]');
  await cp.click('[data-act="rs-close"][data-found="1"]');
  await cp.waitForSelector('.track-head .badge-good');
  rr = await call(bp, 'radar');
  ok(!rr.research.some((x) => x.ref === sref), 'recherche close par le client : elle quitte le Radar');
  ok(fs.readdirSync(path.join(DIR, 'public_html/maillage/media/r')).length === 2, 'seule la photo de la recherche lancée est conservée');
  // Apprentissage : la recherche trouvée rattache la photo du client à la fiche proposée par le point de vente.
  const learned = await fd('visual_identify', 'photo2.jpg', {});
  ok(learned.auto === true && learned.info.name === pname && learned.info.confidence === 'medium', 'la plateforme apprend : la même photo propose désormais ce produit', learned);
  const learnedS = await fd('visual_search', 'photo2.jpg', { name: 'autre chose' });
  ok(learnedS.exact === null && learnedS.similar.some((x) => x.id === productId), 'une photo apprise rapproche le produit sans le déclarer « trouvé »', learnedS);

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
  const demoRadar = await call(mp, 'radar');
  ok(demoRadar.research.length > 0 && demoRadar.research.every((x) => !/Perceuse/.test(x.name)), 'la démonstration ne voit que ses recherches d\'exemple, jamais celles des vrais clients');
  ok(ro.error === 'demo' && ro.status === 403, 'aucune écriture possible en démonstration');
  for (const r of ['commandes', 'radar', 'catalogue', 'points-de-vente', 'disponibilite', 'reseau', 'analyses', 'analyses?t=demande', 'analyses?t=performance', 'parametres']) {
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

  /* --------------------------------------------- base Algérie (fiches publiques) */
  step('Base Algérie : import des fiches publiques');
  await op.waitForSelector('[data-import] [data-act="import"]');
  await op.click('[data-import] [data-act="import"]');
  await op.waitForFunction(() => /Sur le site/.test((document.querySelector('[data-import]') || {}).textContent || ''), null, { timeout: 120000 });
  const imp1 = (await call(op, 'adm_overview')).import;
  ok(imp1.site.products === 100 && imp1.site.stores === 24 && imp1.site.companies === 25, 'import : 100 produits, 24 points de vente, 25 entreprises', imp1.site);
  const again = await call(op, 'adm_import', {});
  ok(again.import.site.products === 100 && again.result.created === 0, 'relance de l\'import : mise à jour, sans doublon', again.result);
  const galaxy = (await call(cp, 'products', undefined, { q: 'galaxy s25 ultra' })).items[0];
  ok(galaxy && galaxy.listing && galaxy.price === 0 && galaxy.stores === 0 && /^media\//.test(galaxy.image), 'produit importé : fiche publique avec photo, sans prix ni stock inventés', galaxy);
  await cp.goto(B + 'explorer/#/produit/' + galaxy.slug);
  await cp.waitForSelector('.p-info .notice.is-public');
  ok(/Prix non communiqué/.test(await cp.locator('[data-price]').innerText()) && await cp.locator('[data-act="cod"]').count() === 0, 'page produit : prix non communiqué, pas de commande vers un magasin non géré');
  await cp.waitForSelector('.av-maybe li');
  ok(/stock à confirmer/i.test(await cp.locator('.av-maybe').innerText()) && await cp.locator('.offers li a[target=_blank][rel*=noopener]').count() === 1, 'magasins qui vendent la marque (stock à confirmer) et fiche du vendeur en ligne');
  await noOverflow(cp, 'produit importé (mobile)');
  const kiabi = (await call(cp, 'stores')).stores.find((x) => x.name === 'Kiabi Es Senia');
  ok(kiabi && kiabi.listing && !kiabi.pickup && !kiabi.reserve && kiabi.commune === 'El Kerma' && kiabi.zone, 'point de vente importé : commune et wilaya reconnues, ni retrait ni réservation', kiabi);
  await cp.goto(B + 'explorer/#/pdv/' + kiabi.id);
  await cp.waitForSelector('.store-main .notice.is-public');
  ok(true, 'page du point de vente : mention « fiche publique »');
  const seen = await fd('visual_identify', 'public_html/maillage/lib/data/import/algerie/P003.jpg', {});
  ok(seen.auto && /Galaxy S25 Ultra/.test(seen.info.name), 'recherche par photo : la photo importée retrouve le produit', seen.info);

  /* ---------------------------------------------------------------- sécurité */
  step('Fichiers internes et requêtes forgées');
  const status = (u, o) => cp.evaluate(async ({ u, o }) => (await fetch(u, o)).status, { u: B + u, o });
  ok(await status('lib/core.php') === 403, 'lib/ interdit');
  ok(await status('config.php') === 403, 'config.php interdit');
  ok(await status('lib/data/import/algerie.json') === 403 && await status('lib/data/import/algerie/P001.jpg') === 403, 'données d\'import non servies directement');
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
