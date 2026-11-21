/*
 * Parcours du créateur dans un navigateur (Playwright / Chromium) :
 *   accueil (« Your Next Milestone ») → menu « My Achievements » → tableau de bord (passeport, niveau, XP, onglets
 *   Verified / In Progress / Pending Verification, chronologie filtrée, niveaux, barème) → célébration discrète après une
 *   carte terminée (une seule fois, mouvement réduit respecté) → détails et preuves → partage (visibilité, lien copié,
 *   réseaux encodés, image PNG aux trois formats avec QR code, jamais « Verified » sur une déclaration) → page publique
 *   de vérification → réglages (passeport, visibilité) → passeport public → réalisation déclarée → projet marqué
 *   terminé → administration (indicateurs, fiche, retrait) → mobile ; aucune erreur, aucune violation de la CSP.
 *   node tests/studioma/journey-e2e.js <adresse> <dossier>
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const A = ORIGIN + '/studioma/app/';
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
const SHOTS = path.join(DIR, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 500) : '')); } };
const step = (t) => console.log('\n' + t);
const sql = (q) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, q]);
const one = (q) => JSON.parse(execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); echo json_encode($p->query($argv[2])->fetchColumn());', DB, q]).toString());
const errors = [];
const csp = [];
const watch = (page, name) => {
  page.on('pageerror', (e) => errors.push(name + ' : ' + e.message));
  page.on('console', (m) => {
    if (/Content Security Policy/i.test(m.text())) csp.push(name + ' : ' + m.text());
    else if (m.type() === 'error' && !/Failed to load resource|fonts\.g|net::ERR_/.test(m.text() + ' ' + (m.location().url || ''))) errors.push(name + ' : ' + m.text());
  });
  page.on('response', (r) => { const u = r.url(); if (r.status() >= 400 && u.startsWith(ORIGIN) && !/api\.php|\/(lib|config)|\/verify\/0|\/creator\/nobody/.test(u)) errors.push(name + ' : ' + r.status() + ' ' + u); });
  page.on('dialog', (d) => { errors.push(name + ' : boîte de dialogue « ' + d.message() + ' »'); d.dismiss(); });
};
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: false });
const noOverflow = async (page, label) => ok(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'pas de défilement horizontal : ' + label);
const toast = (page, re) => page.waitForFunction((src) => Array.from(document.querySelectorAll('.toast')).some((t) => new RegExp(src, 'i').test(t.textContent)), re.source, { timeout: 8000 }).then(() => true, () => false);

class Client {
  constructor() { this.jar = {}; this.csrf = ''; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  async call(action, body, params) {
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const init = { headers: { Cookie: this.cookies() } };
    if (body !== undefined) Object.assign(init, { method: 'POST', body: JSON.stringify(body) }), Object.assign(init.headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf });
    const res = await fetch(url, init);
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => { const [k, ...v] = c.split(';')[0].split('='); if (v.join('=')) this.jar[k] = v.join('='); });
    const d = await res.json();
    if (d.csrf) this.csrf = d.csrf;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
}
const BODY = '<p>A six-slide carousel for the spring collection, with captions, hashtags and alt texts.</p>';
/** Dimensions d'un fichier PNG (en-tête IHDR). */
const pngSize = (buf) => ({ sig: buf.slice(1, 4).toString(), w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) });

(async () => {
  step('Préparation (API) : une créatrice, un projet, des cartes, une cliente');
  const sara = new Client();
  await sara.post('signup', { name: 'Sara Creator', email: 'sara@test.example', password: 'a-solid-password', accept: true });
  sql('DELETE FROM rate');
  const saraId = (await sara.get('me')).user.id;
  let r = await sara.post('project_save', { name: 'Maison Lune', template: 'kanban', studio_name: 'Instagram' });
  const pid = r.id;
  const sid = r.studio_id;
  let b = await sara.get('board', { studio: sid });
  const todo = b.cols.find((c) => /To do/.test(c.name));
  const done = b.cols.find((c) => c.kind === 'done');
  const cards = [];
  for (let i = 0; i < 4; i++) {
    const id = (await sara.post('card_create', { col_id: todo.id, title: 'Post ' + (i + 1) })).card.id;
    await sara.post('card_save', { id, body: BODY });
    cards.push(id);
  }
  for (const id of cards.slice(0, 3)) await sara.post('card_move', { id, col_id: done.id, index: 0 });
  const clara = new Client();
  await clara.post('signup', { name: 'Clara Client', email: 'clara@brand.example', password: 'a-solid-password', accept: true });
  sql('DELETE FROM rate');
  const claraId = (await clara.get('me')).user.id;
  sql(`UPDATE users SET email_ok = 1 WHERE id IN (${saraId}, ${claraId})`);
  await sara.post('invite_create', { project_id: pid, uid: claraId, role: 'client' });
  await clara.post('invite_accept', { token: one('SELECT token FROM invites WHERE uid = ' + claraId) });
  await sara.post('approval_request', { card_id: cards[3], approver: claraId });   // en attente : « Pending Verification »
  await sara.get('journey');
  await sara.post('journey_seen');
  ok(one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${saraId} AND kind = 'done'`) === 3, 'trois cartes terminées inscrites');

  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 }, acceptDownloads: true });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
  const page = await ctx.newPage();
  watch(page, 'créatrice');
  await page.goto(A + '#/login');
  await page.fill('input[name=email]', 'sara@test.example');
  await page.fill('input[name=password]', 'a-solid-password');
  await page.click('form[data-form="login"] button.btn-primary');
  await page.waitForFunction(() => window.SM && SM.me);

  step('1. Accueil : « Your Next Milestone »');
  await page.goto(A + '#/');
  await page.waitForSelector('#ms-rail .milestone');
  let t = await page.locator('#ms-rail .milestone').innerText();
  ok(/Your Next Milestone/.test(t) && /Complete your first five cards/.test(t) && /3 \/ 5/.test(t), 'jalon réel : 3 / 5 cartes terminées', t);
  ok(await page.locator('#ms-rail .milestone a.btn').getAttribute('href') === '#/work', 'le bouton mène à une fonction existante (#/work)');
  await shot(page, 'j01-home-milestone');

  step('2. My Achievements');
  ok(await page.locator('.side a.nav-link[href="#/achievements"]:has-text("My Achievements")').count() === 1, 'entrée « My Achievements » dans le menu');
  await page.click('.side a.nav-link[href="#/achievements"]');
  await page.waitForSelector('.passport-card');
  t = await page.locator('.passport-card').innerText();
  ok(/CREATOR PASSPORT/i.test(t) && /Sara Creator/.test(t) && /Creative Professional/.test(t) && /Level 01 — Explorer/.test(t) && /\/ 150 XP/.test(t) && /Progress to Organizer: \d+%/.test(t), 'passeport : nom, titre, niveau, XP vers le prochain niveau', t);
  const xp = one(`SELECT SUM(xp) FROM xp_transactions WHERE uid = ${saraId}`);
  ok(t.includes(String(xp)), 'XP affichés = grand livre (' + xp + ')');
  ok(await page.locator('.side .nav-link.is-active[href="#/achievements"]').count() === 1, 'rubrique active dans le menu');
  ok(await page.locator('.tabs .tab:has-text("Verified")').count() === 1 && await page.locator('.tabs .tab:has-text("In Progress")').count() === 1 && await page.locator('.tabs .tab:has-text("Pending Verification")').count() === 1, 'onglets Verified / In Progress / Pending Verification');
  ok(await page.locator('.ach-card.ach-verified:has-text("First Studio")').count() === 1 && await page.locator('.ach-card.ach-verified:has-text("First Delivery")').count() === 1, 'onglet Verified : First Studio, First Delivery');
  ok(await page.locator('.ach-card.ach-verified .vchip:has-text("System Verified")').count() >= 2, 'étiquette « System Verified »');
  await page.click('.tabs .tab:has-text("Pending Verification")');
  await page.waitForSelector('.ach-card.ach-pending');
  ok(/Client Approved/.test(await page.locator('.ach-card.ach-pending').innerText()) && /Waiting for 1 client approval request/.test(await page.locator('.ach-card.ach-pending').innerText()), 'en attente : Client Approved (demande à la cliente)');
  await page.click('.tabs .tab:has-text("In Progress")');
  await page.waitForSelector('.ach-card.ach-progress');
  ok(await page.locator('.ach-card.ach-progress:has-text("Project Finisher") .progress').count() === 1, 'en cours : barre de progression');
  ok(/Explorer/.test(await page.locator('.levels').innerText()) && await page.locator('.levels li').count() === 6 && await page.locator('.criteria li').count() >= 2, 'six niveaux et critères du suivant');
  ok(/Complete an eligible card\s*\+10/.test(await page.locator('.xp-rules').innerText()), 'barème des XP');
  await page.waitForSelector('#tl-list .timeline li');
  ok(/Joined Studioma/.test(await page.locator('#tl-list').innerText()), 'chronologie : inscription');
  await page.check('input[name=tl][value=projects]', { force: true });
  await page.waitForFunction(() => !/Joined Studioma/.test(document.querySelector('#tl-list').textContent));
  ok(/Completed 3 eligible cards/.test(await page.locator('#tl-list').innerText()), 'filtre « Projects » : cartes terminées du mois');
  await page.click('.tabs .tab:has-text("Verified")');
  await page.waitForSelector('.ach-card.ach-verified');
  await shot(page, 'j02-achievements');

  step('3. Célébration discrète après une carte terminée');
  // La cliente approuve la carte en attente ; puis quatre cartes sont terminées depuis le navigateur (réponse → « _journey »).
  const appr = (await clara.get('card_get', { id: cards[3] })).card.approvals.find((a) => a.status === 'pending');
  await clara.post('approval_decide', { id: appr.id, decision: 'approved' });
  const more = [];
  for (let i = 5; i <= 8; i++) {
    const id = (await sara.post('card_create', { col_id: todo.id, title: 'Post ' + i })).card.id;
    await sara.post('card_save', { id, body: BODY });
    more.push(id);
  }
  await page.evaluate(async ([ids, col]) => { for (const id of ids) await SM.post('card_move', { id, col_id: col, index: 0 }); }, [more, done.id]);
  await page.waitForSelector('.celebrate', { timeout: 8000 });
  t = await page.locator('.celebrate').innerText();
  ok(/2 new milestones/i.test(t) && /Client Approved/.test(t) && /Level 02 — Organizer/.test(t), 'célébration regroupée : Client Approved et niveau 02 Organizer', t);
  ok(await page.locator('.celebrate[role=status][aria-live=polite]').count() === 1 && await page.locator('.overlay').count() === 0, 'non bloquante (pas de fenêtre), annoncée poliment');
  await shot(page, 'j03-celebration');
  await page.click('.celebrate button:has-text("Dismiss")');
  await page.waitForSelector('.celebrate', { state: 'detached' });
  await page.reload();
  await page.waitForSelector('.passport-card');
  await page.waitForTimeout(1200);
  ok(await page.locator('.celebrate').count() === 0, 'montrée une seule fois');
  ok(/Level 02 — Organizer/.test(await page.locator('.passport-card').innerText()), 'niveau 02 Organizer affiché');

  step('4. Détails et preuves');
  await page.click('.ach-card:has-text("First Delivery") a:has-text("View Details")');
  await page.waitForSelector('.ach-detail');
  t = await page.locator('.ach-detail').innerText();
  ok(/FIRST DELIVERY|First Delivery/.test(t) && /System Verified/.test(t) && /Completed card record/.test(t) && /Maison Lune › Instagram › Post/.test(t) && /\+10 XP/.test(t), 'vérification, preuve (projet › Studio › carte), XP', t.slice(0, 400));
  ok(/What this proves/.test(t) && /does not prove/.test(t) && /Achievement ID/.test(t), 'ce que la réalisation prouve et ne prouve pas, identifiant');
  ok(await page.locator('.evidence a:has-text("Open")').count() === 1, 'lien vers la carte (la créatrice y a accès)');
  await shot(page, 'j04-detail');

  step('5. Partager : visibilité, lien, réseaux, image PNG');
  await page.click('button:has-text("Share Achievement")');
  await page.waitForSelector('.share-ach');
  ok(/This achievement is private/.test(await page.locator('.share-ach').innerText()) && await page.locator('#png-qr').isDisabled(), 'privée : pas de lien, pas de QR code');
  await page.check('.share-ach input[name=vis][value=link]');
  await page.waitForSelector('.share-ach .ref-link input');
  const vurl = await page.locator('.share-ach .ref-link input').inputValue();
  ok(/\/studioma\/verify\/[a-f0-9]{16}$/.test(vurl), 'lien de vérification', vurl);
  await page.click('.share-ach [data-act="copy-link"]');
  ok((await page.evaluate(() => navigator.clipboard.readText())) === vurl, 'lien copié');
  const li = await page.locator('.share-ach a[data-share=linkedin]').getAttribute('href');
  const wa = await page.locator('.share-ach a[data-share=whatsapp]').getAttribute('href');
  ok(li === 'https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(vurl) && wa.startsWith('https://wa.me/?text=') && decodeURIComponent(wa.split('text=')[1]).includes(vurl), 'LinkedIn, WhatsApp : adresses correctement encodées');
  ok(await page.locator('.share-ach a[data-share=facebook]').count() === 1 && /^mailto:/.test(await page.locator('.share-ach a[data-share=email]').getAttribute('href')), 'Facebook et email');
  ok(await page.locator('#png-qr').isChecked(), 'QR code proposé une fois le lien actif');
  await page.waitForTimeout(400);
  const px = await page.evaluate(() => { const c = document.querySelector('#png-canvas'); const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data; let lit = 0; for (let i = 0; i < d.length; i += 4000) if (d[i] + d[i + 1] + d[i + 2] > 300) lit++; return { w: c.width, h: c.height, lit }; });
  ok(px.w === 1200 && px.h === 630 && px.lit > 5, 'aperçu dessiné (1200 × 630)', px);
  let dl = page.waitForEvent('download');
  await page.click('[data-act="png-download"]');
  let file = await (await dl).path();
  let size = pngSize(fs.readFileSync(file));
  ok(size.sig === 'PNG' && size.w === 1200 && size.h === 630, 'PNG téléchargé : 1200 × 630', size);
  await page.selectOption('#png-format', 'square');
  dl = page.waitForEvent('download');
  await page.click('[data-act="png-download"]');
  size = pngSize(fs.readFileSync(await (await dl).path()));
  ok(size.w === 1080 && size.h === 1080, 'format carré : 1080 × 1080', size);
  await page.selectOption('#png-format', 'story');
  await page.waitForTimeout(300);
  ok(await page.evaluate(() => document.querySelector('#png-canvas').height) === 1920, 'format story : 1080 × 1920');
  await shot(page, 'j05-share');
  // Les textes dessinés : jamais « Verified » pour une réalisation déclarée.
  const drawn = await page.evaluate(async () => {
    const texts = [];
    const orig = CanvasRenderingContext2D.prototype.fillText;
    CanvasRenderingContext2D.prototype.fillText = function (s, ...rest) { texts.push(String(s)); return orig.call(this, s, ...rest); };
    const c = document.createElement('canvas');
    await SM.drawAchievementCard(c, { title: 'Meetup talk', status: 'self', verification: 'self', label: 'Self Reported — not independently verified', icon: 'star', earned: 1790000000, xp: 0, url: '' }, { name: 'Sara Creator' }, 'square', false);
    const self = texts.splice(0);
    await SM.drawAchievementCard(c, { title: 'First Delivery', status: 'verified', verification: 'system', label: 'System Verified', icon: 'check-circle', earned: 1790000000, xp: 10, url: 'https://x.example/verify/0123456789abcdef' }, { name: 'Sara Creator' }, 'landscape', true);
    CanvasRenderingContext2D.prototype.fillText = orig;
    return { self, ver: texts };
  });
  ok(drawn.self.includes('SELF-REPORTED ACHIEVEMENT') && drawn.self.includes('Self Reported — not independently verified') && !drawn.self.some((s) => /^VERIFIED|System Verified|Client Verified/.test(s)), 'image d\'une déclaration : « Self Reported », jamais « Verified »', drawn.self);
  ok(drawn.ver.includes('VERIFIED ACHIEVEMENT') && drawn.ver.includes('System Verified') && drawn.ver.includes('Scan to verify') && drawn.ver.includes('Awarded to Sara Creator'), 'image vérifiée : libellé, nom, QR code', drawn.ver);
  await page.click('.modal [data-act="modal-close"]');

  step('6. Page publique de vérification');
  const pub = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const pp = await pub.newPage();
  watch(pp, 'public');
  await pp.goto(vurl);
  t = await pp.locator('main').innerText();
  ok(/First Delivery/.test(t) && /Verified/.test(t) && /Sara Creator/.test(t) && /Completed card record/.test(t) && !/Maison Lune|Instagram|Post 1|clara/.test(t), 'titre, état, créatrice, preuve — rien de privé');
  await shot(pp, 'j06-verify');
  const pv = await pp.goto(ORIGIN + '/studioma/verify/0123456789abcdef');
  ok(pv.status() === 404, 'identifiant inconnu : 404');

  step('7. Réglages : passeport et visibilité');
  await page.goto(A + '#/achievements/settings');
  await page.waitForSelector('form[data-form="passport-save"]');
  await page.check('form[data-form="passport-save"] input[name=enabled]');
  await page.fill('form[data-form="passport-save"] input[name=headline]', 'Social media manager for lifestyle brands');
  await page.click('form[data-form="passport-save"] button.btn-primary');
  ok(await toast(page, /Creator Passport is public/), 'passeport ouvert');
  await page.waitForSelector('a:has-text("View my passport")');
  const sel = page.locator('select[data-change="ach-vis"]').first();
  await sel.selectOption('passport');
  ok(await toast(page, /Shown on your Creator Passport/), 'visibilité changée en un geste');
  await shot(page, 'j07-settings');
  await pp.goto(ORIGIN + '/studioma/creator/sara-creator');
  t = await pp.locator('main').innerText();
  ok(/Sara Creator/.test(t) && /Social media manager for lifestyle brands/.test(t) && /Level 02 — Organizer/.test(t) && /Verified achievements/.test(t) && !/Maison Lune|clara@/.test(t), 'passeport public : nom, titre, niveau, chiffres, rien de privé');
  ok(await pp.locator('.pp-ach').count() === 1, 'seule la réalisation choisie apparaît');
  await shot(pp, 'j08-passport');
  await pub.close();

  step('8. Réalisation déclarée');
  await page.goto(A + '#/achievements');
  await page.waitForSelector('.passport-card');
  await page.click('[data-act="self-new"]');
  await page.fill('form[data-form="self-save"] input[name=title]', 'Speaker at Social Media Day');
  await page.fill('form[data-form="self-save"] textarea[name=body]', 'Talk about short-form video for small brands.');
  await page.click('form[data-form="self-save"] button.btn-primary');
  await page.waitForSelector('.ach-detail');
  t = await page.locator('.ach-detail').innerText();
  ok(/Self reported/.test(t) && /Self Reported — not independently verified/.test(t) && !/System Verified|Client Verified/.test(t), 'affichée « Self Reported — not independently verified »');

  step('9. Projet marqué terminé');
  await page.goto(A + '#/project/' + pid);
  await page.waitForSelector('.project-head');
  await page.click('[data-act="project-menu"]');
  await page.click('.menu button:has-text("Mark as completed")');
  await page.waitForSelector('.modal .criteria');
  ok(await page.locator('.modal [data-act="project-complete"]').isDisabled() && /hours ago/.test(await page.locator('.modal .criteria').innerText()), 'critères affichés ; bouton désactivé (projet trop récent, carte en attente)');
  await page.click('.modal [data-act="modal-close"]');
  sql(`UPDATE projects SET created = created - 3 * 86400 WHERE id = ${pid}`);
  await sara.post('card_move', { id: cards[3], col_id: done.id, index: 0 });
  await page.reload();
  await page.waitForSelector('.project-head');
  await page.click('[data-act="project-menu"]');
  await page.click('.menu button:has-text("Mark as completed")');
  await page.waitForSelector('.modal [data-act="project-complete"]:not([disabled])');
  await page.click('.modal [data-act="project-complete"]');
  ok(await toast(page, /Project completed/), 'projet terminé');
  await page.waitForSelector('.project-head .chip:has-text("Completed")');
  await page.waitForSelector('.celebrate', { timeout: 8000 }).catch(() => {});
  ok(/Project Finisher|first project is complete|new milestones/i.test(await page.locator('.celebrate').innerText().catch(() => '')), 'célébration : premier projet terminé');
  await shot(page, 'j09-project-completed');

  step('10. Administration');
  const actx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const ap = await actx.newPage();
  watch(ap, 'admin');
  await ap.goto(ORIGIN + '/admin/');
  await ap.fill('input[type=password]', 'test-owner-pass');
  await ap.press('input[type=password]', 'Enter');
  await ap.waitForTimeout(800);
  await ap.goto(ORIGIN + '/studioma/admin/#/journey');
  await ap.waitForSelector('h1:has-text("Creator Journey")');
  t = await ap.locator('#view').innerText();
  ok(/Participants/.test(t) && /Achievements granted/.test(t) && /Completed projects/.test(t) && /Sharing rate/.test(t) && /Creator Passports/.test(t) && /Progression by level/.test(t) && /XP distribution/.test(t), 'indicateurs et répartitions');
  await ap.fill('form[data-form="adm-journey-find"] input[name=id]', String(saraId));
  await ap.click('form[data-form="adm-journey-find"] button');
  await ap.waitForSelector('#jr-user table');
  ok(/First Delivery/.test(await ap.locator('#jr-user').innerText()) && await ap.locator('#jr-user tbody tr').count() > 5, 'fiche : réalisations et grand livre');
  await ap.locator('#jr-user tbody tr:has-text("first_project") [data-act="adm-journey-revoke"]').click();
  await ap.fill('.modal input[name=value]', 'Test of the revocation');
  await ap.click('.modal button[type=submit]');
  ok(await toast(ap, /Revoked/), 'ligne retirée avec une raison');
  ok(one(`SELECT revoked_reason FROM xp_transactions WHERE uid = ${saraId} AND kind = 'first_project'`) === 'Test of the revocation', 'raison enregistrée');
  await shot(ap, 'j10-admin');
  await actx.close();

  step('11. Mobile et mouvement réduit');
  const mctx = await browser.newContext(Object.assign({}, devices['iPhone 13'], { reducedMotion: 'reduce' }));
  const mp = await mctx.newPage();
  watch(mp, 'mobile');
  await mp.goto(A + '#/login');
  await mp.fill('input[name=email]', 'sara@test.example');
  await mp.fill('input[name=password]', 'a-solid-password');
  await mp.click('form[data-form="login"] button.btn-primary');
  await mp.waitForFunction(() => window.SM && SM.me);
  await mp.goto(A + '#/');
  await mp.waitForSelector('#ms-feed .milestone');
  ok(await mp.locator('#ms-feed .milestone').isVisible(), 'téléphone : le jalon apparaît dans le fil');
  await noOverflow(mp, 'accueil');
  await mp.goto(A + '#/achievements');
  await mp.waitForSelector('.passport-card');
  await noOverflow(mp, 'My Achievements');
  await shot(mp, 'j11-mobile-achievements');
  const anim = await mp.evaluate(() => { const d = document.createElement('div'); d.className = 'celebrate'; document.body.appendChild(d); const a = getComputedStyle(d).animationName; d.remove(); return a; });
  ok(anim === 'none', 'mouvement réduit : pas d\'animation de célébration', anim);
  await mp.goto(ORIGIN + '/studioma/creator/sara-creator');
  await noOverflow(mp, 'passeport public');
  await mp.goto(vurl);
  await noOverflow(mp, 'page de vérification');
  await shot(mp, 'j12-mobile-verify');
  await mctx.close();

  ok(errors.length === 0, 'aucune erreur dans les pages', errors);
  ok(csp.length === 0, 'aucune violation de la Content-Security-Policy', csp);
  await browser.close();
  console.log(failed ? `\n${failed} échec(s)` : '\nTous les tests du parcours du créateur (navigateur) sont passés.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
