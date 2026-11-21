/*
 * Tests de bout en bout de Studioma (Playwright / Chromium), sur le faux hébergement de run.sh :
 *   inscription → premiers pas → projet et Studio → cartes créées d'un geste → glisser-déposer
 *   (persistant) → passage de relais entre niveaux → panneau d'une carte (champs, script,
 *   check-list, pièce jointe, commentaire) → niveaux et colonnes (renommer, ajouter, réordonner,
 *   relais manuel) → sélection multiple → calendrier, Mon travail, tableau de bord → invitation
 *   par lien, second compte, synchronisation entre deux navigateurs, droits du collaborateur →
 *   validation par une cliente → profil, publication, réseau, étude de cas → entreprise,
 *   opportunité, candidature, annuaire des talents, messages → mobile, thème sombre →
 *   connexion avec Google (faux Google signé par le test) → administration (vue en direct, comptes,
 *   campagne) → contenu saisi non exécuté, aucune erreur JavaScript, aucune violation de la CSP.
 *   node tests/studioma/e2e.js <adresse> <dossier>
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const [ORIGIN, DIR] = process.argv.slice(2);
const A = ORIGIN + '/studioma/app/';
const SHOTS = path.join(DIR, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra) : '')); } };
const step = (t) => console.log('\n' + t);
const errors = [];
const csp = [];
const watch = (page, name) => {
  page.on('pageerror', (e) => errors.push(name + ' : ' + e.message));
  // Les refus de l'API (400, 403, 404…) sont provoqués et vérifiés un par un ; ici on guette les
  // erreurs de script et les fichiers de la plateforme qui ne se chargent pas.
  page.on('console', (m) => {
    if (/Content Security Policy/i.test(m.text())) csp.push(name + ' : ' + m.text());
    else if (m.type() === 'error' && !/Failed to load resource|fonts\.g|net::ERR_/.test(m.text() + ' ' + (m.location().url || ''))) errors.push(name + ' : ' + m.text());
  });
  page.on('response', (r) => { const u = r.url(); if (r.status() >= 400 && u.startsWith(ORIGIN) && !/api\.php|\/(lib|config)/.test(u)) errors.push(name + ' : ' + r.status() + ' ' + u); });
  page.on('dialog', (d) => { errors.push(name + ' : boîte de dialogue « ' + d.message() + ' »'); d.dismiss(); });
};
/** Appel direct à l'API dans le contexte d'une page (mêmes cookies, même jeton). */
const call = (page, action, body, params) => page.evaluate(async ({ action, body, params }) => {
  try { return await SM.api(action, { body, params }); } catch (e) { return { error: e.code, message: e.message, status: e.status }; }
}, { action, body, params });
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name + '.png') });
const noOverflow = async (page, label) => ok(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'pas de défilement horizontal de la page : ' + label);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
const toast = (page, re) => page.waitForFunction((src) => Array.from(document.querySelectorAll('.toast')).some((t) => new RegExp(src, 'i').test(t.textContent)), re.source, { timeout: 8000 }).then(() => true, () => false);
/** Glisse un élément vers un autre à la souris, par petits pas (comme une vraie main). */
async function drag(page, from, to, opts) {
  // Le tableau se redessine après chaque enregistrement : on attend qu'il soit au repos.
  await page.waitForFunction(() => !window.SM || !SM.board || !SM.board.writing);
  await page.locator(from).first().scrollIntoViewIfNeeded().catch(() => {});
  let a = null; let b = null;
  for (let i = 0; i < 20 && !(a && b); i++) {
    a = await page.locator(from).first().boundingBox();
    b = await page.locator(to).first().boundingBox();
    if (!(a && b)) await page.waitForTimeout(100);
  }
  const o = Object.assign({ fx: 0.5, fy: 0.5, tx: 0.5, ty: 0.5 }, opts || {});
  const sx = a.x + a.width * o.fx; const sy = a.y + Math.min(a.height * o.fy, a.height - 4);
  const ex = b.x + b.width * o.tx; const ey = b.y + b.height * o.ty;
  await page.mouse.move(sx, sy);
  await page.mouse.down();
  await page.mouse.move(sx + 8, sy + 8, { steps: 3 });
  await page.mouse.move(ex, ey, { steps: 14 });
  await page.waitForTimeout(120);
  await page.mouse.move(ex, ey + 1);
  await page.waitForTimeout(80);
  await page.mouse.up();
}
const signup = async (page, name, email, company) => {
  await page.goto(A + '#/signup', { waitUntil: 'load' });
  await page.waitForSelector('form[data-form="signup"]');
  if (company) { await page.click('.seg label:has-text("Company")'); await page.fill('input[name=company]', company); }
  await page.fill('input[name=name]', name);
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'a-solid-password');
  await page.click('form[data-form="signup"] button.btn-primary');
};
/** Événements envoyés à l'API Conversions de Meta (écrits dans meta.jsonl pendant les tests). */
const metaEvents = () => (fs.existsSync(path.join(DIR, 'meta.jsonl')) ? fs.readFileSync(path.join(DIR, 'meta.jsonl'), 'utf8').trim().split('\n').filter(Boolean).flatMap((l) => JSON.parse(l).data) : []);
/** Faux script du pixel Meta : il note les appels à fbq (window.__fbqCalls) au lieu de les envoyer. */
const FAKE_FBQ = 'window.__fbqCalls=window.__fbqCalls||[];(function(){var f=window.fbq;var rec=function(){window.__fbqCalls.push(JSON.parse(JSON.stringify(Array.prototype.slice.call(arguments))));};f.callMethod=rec;(f.queue||[]).forEach(function(a){rec.apply(null,a);});f.queue=[];})();';
const colSel = (name) => `.bcol:has(.bcol-name:text-is("${name}"))`;
const cardIn = (page, col, title) => page.locator(`${colSel(col)} .kcard:has(h3:text-is("${title}"))`);

(async () => {
  const browser = await chromium.launch();
  const desk = { viewport: { width: 1440, height: 900 } };
  // Le vrai script de Google n'est jamais chargé pendant les tests : chaque navigateur le voit
  // échouer (le bouton doit alors disparaître), sauf celui qui reçoit le faux Google (google.js).
  const G = require('./google.js');
  const google = G.setup(DIR);
  const newContext = browser.newContext.bind(browser);
  browser.newContext = async (opts) => {
    const ctx = await newContext(opts);
    await ctx.route('https://accounts.google.com/**', (route) => route.abort());
    await ctx.route('https://connect.facebook.net/**', (route) => route.fulfill({ status: 200, contentType: 'application/javascript', body: FAKE_FBQ }));
    return ctx;
  };

  /* ------------------------------------------------- inscription, premiers pas */
  step('Page d\'accueil, inscription et premiers pas');
  const amelCtx = await browser.newContext(desk);
  const ap = await amelCtx.newPage();
  watch(ap, 'amel');
  await ap.goto(ORIGIN + '/studioma/', { waitUntil: 'load' });
  ok(/Create together/.test(await ap.locator('h1').innerText()) && await ap.locator('.mock-level').count() === 2, 'page d\'accueil : titre et aperçu du tableau');
  ok(await ap.locator('a[data-auth="signup"]').first().getAttribute('href') === 'app/#/signup', 'page d\'accueil : bouton d\'inscription');
  await shot(ap, '00-landing');
  await noOverflow(ap, 'page d\'accueil');
  ok(await ap.waitForFunction(() => (window.__fbqCalls || []).some((c) => c[0] === 'track' && c[1] === 'PageView'), null, { timeout: 5000 }).then(() => true, () => false), 'pixel Meta : PageView sur la page d\'accueil (sans script en ligne, CSP respectée)');
  await ap.goto(A, { waitUntil: 'load' });
  await ap.waitForSelector('form[data-form="login"]');
  ok(/#\/login$/.test(ap.url()), 'sans compte : redirigé vers la connexion');
  await shot(ap, '01-login');
  await ap.goto(A + '#/signup');
  await ap.waitForSelector('form[data-form="signup"]');
  await ap.fill('input[name=name]', 'Amel Haddad');
  await ap.fill('input[name=email]', 'amel@test.example');
  await ap.fill('input[name=password]', 'court');
  await ap.click('form[data-form="signup"] button.btn-primary');
  ok(await ap.evaluate(() => !document.querySelector('form[data-form="signup"]').checkValidity()), 'mot de passe trop court refusé par le formulaire');
  await ap.fill('input[name=password]', 'a-solid-password');
  ok(await ap.locator('input[name=accept][type=checkbox]').count() === 0 && /By creating your account, you agree/.test(await ap.locator('form[data-form="signup"] .terms-note').innerText()),
    'conditions sous « Create my account » (pas de case à cocher)');
  await ap.click('form[data-form="signup"] button.btn-primary');
  await ap.waitForSelector('.welcome-card');
  ok(/#\/welcome/.test(ap.url()), 'compte créé : premiers pas');
  const fbqCalls = await ap.evaluate(() => window.__fbqCalls || []);
  const regPixel = fbqCalls.find((c) => c[0] === 'track' && c[1] === 'CompleteRegistration');
  const regCapi = metaEvents().find((e) => e.event_name === 'CompleteRegistration');
  ok(fbqCalls.some((c) => c[0] === 'set' && c[1] === 'autoConfig' && c[2] === false) && fbqCalls.some((c) => c[0] === 'init' && c[1] === '1234567890123')
    && fbqCalls.filter((c) => c[1] === 'PageView').length >= 2, 'pixel : événements automatiques coupés, PageView à chaque écran', fbqCalls.slice(0, 6));
  ok(regPixel && regCapi && regPixel[3].eventID === regCapi.event_id && regCapi.user_data.em[0].length === 64, 'inscription : CompleteRegistration par le pixel et par l\'API Conversions, même event_id (déduplication), email haché', [regPixel, regCapi && regCapi.event_id]);
  await ap.fill('input[name=title]', 'Freelance community manager');
  await ap.fill('.chips-in', 'Scriptwriting');
  await ap.keyboard.press('Enter');
  await ap.fill('.chips-in', 'Community management');
  await ap.keyboard.press('Enter');
  ok(await ap.locator('.welcome-card .chip-x').count() === 2 && /#\/welcome$/.test(ap.url()), 'compétences ajoutées avec Entrée (sans envoyer le formulaire)');
  await ap.click('.toggle:has-text("Instagram")');
  await ap.click('.toggle:has-text("TikTok")');
  await ap.click('.welcome-card button.btn-primary');
  await ap.waitForSelector('form[data-form="welcome-studio"]');
  ok(await ap.locator('.tpl').count() === 3 && await ap.locator('.tpl input:checked').getAttribute('value') === 'content', 'modèles de Studio proposés, « Content workflow » par défaut');
  await ap.fill('input[name=name]', 'Vélora');
  await ap.fill('input[name=studio_name]', 'Social 2026');
  await shot(ap, '02-first-studio');
  await ap.click('.welcome-card button.btn-primary');
  await ap.waitForSelector('.level');
  const studioId = +ap.url().match(/studio\/(\d+)/)[1];
  ok(studioId > 0 && await ap.locator('.level').count() === 3, 'projet et Studio créés : 3 niveaux');
  ok((await ap.locator('.level-name').allInnerTexts()).join() === 'Creation,Production,Publication', 'niveaux Creation, Production, Publication');
  ok((await ap.locator('.level').nth(0).locator('.bcol-name').allInnerTexts()).join('|') === '💡 Ideas|✍️ Script in Progress|✅ Script Approved', 'colonnes du niveau Creation');
  ok(await ap.locator('.level').nth(1).locator('.bcol').count() === 4 && await ap.locator('.level').nth(2).locator('.bcol').count() === 4, 'colonnes de Production et de Publication');
  ok(/Auto handoff/.test(await ap.locator('.level').nth(0).locator('.handoff').innerText()) && await ap.locator('.level').nth(2).locator('.handoff').count() === 0, 'relais automatique affiché entre les niveaux');

  /* ------------------------------------------------------------------ cartes */
  step('Cartes : création rapide, glisser-déposer, passage de relais');
  await ap.click(`${colSel('💡 Ideas')} .bcol-add`);
  for (const t of ['Reel: 5 beginner mistakes', 'Carousel: bike maintenance', 'Story: behind the scenes', '<img src=x onerror=alert(1)> script test']) {
    const n = await ap.locator('.kcard').count();
    await ap.fill('.card-new textarea', t);
    await ap.keyboard.press('Enter');
    await ap.waitForFunction((n) => document.querySelectorAll('.kcard').length === n + 1, n);
  }
  await ap.keyboard.press('Escape');
  ok(await ap.locator(`${colSel('💡 Ideas')} .kcard`).count() === 4, '4 cartes créées à la suite (Entrée, le formulaire reste ouvert)');
  ok(await ap.locator('.kcard h3').last().innerText() === '<img src=x onerror=alert(1)> script test', 'titre saisi affiché tel quel (non interprété)');
  const firstId = await cardIn(ap, '💡 Ideas', 'Reel: 5 beginner mistakes').getAttribute('data-card');

  // Réordonner dans la colonne : la 3e carte passe en tête.
  await drag(ap, `${colSel('💡 Ideas')} .kcard:has(h3:text-is("Story: behind the scenes"))`, `${colSel('💡 Ideas')} .kcard >> nth=0`, { ty: 0.2 });
  await ap.waitForFunction(() => document.querySelector('.bcol .kcard h3').textContent === 'Story: behind the scenes');
  await ap.waitForTimeout(400);
  await ap.reload({ waitUntil: 'load' });
  await ap.waitForSelector('.kcard');
  ok(await ap.locator(`${colSel('💡 Ideas')} .kcard h3`).first().innerText() === 'Story: behind the scenes', 'ordre des cartes conservé après rechargement');

  // Vers une autre colonne.
  await drag(ap, `.kcard[data-card="${firstId}"]`, `${colSel('✍️ Script in Progress')} .bcol-cards`);
  await cardIn(ap, '✍️ Script in Progress', 'Reel: 5 beginner mistakes').waitFor();
  ok(true, 'carte glissée vers une autre colonne');
  // Vers la colonne finale : relais automatique vers Production.
  await drag(ap, `.kcard[data-card="${firstId}"]`, `${colSel('✅ Script Approved')} .bcol-cards >> nth=0`);
  ok(await toast(ap, /Handed off to Production/), 'colonne finale : message « Handed off to Production »');
  await ap.waitForFunction((id) => { const c = document.querySelector(`.kcard[data-card="${id}"]`); return c && c.closest('.level').querySelector('.level-name').textContent === 'Production'; }, firstId);
  ok(await ap.locator(`.level >> nth=1 >> .bcol >> nth=0 >> .kcard[data-card="${firstId}"]`).count() === 1, 'la même carte (même id) est dans la colonne principale de Production');
  ok(await ap.locator('.kcard:has(h3:text-is("Reel: 5 beginner mistakes"))').count() === 1, 'aucune copie de la carte');
  ok(/1 card handed off to Production/.test(await ap.locator(`.level >> nth=0 >> ${colSel('✅ Script Approved')} .bcol-passed`).innerText()), 'la colonne finale indique les cartes transmises');
  await shot(ap, '03-board');

  /* ------------------------------------------------------- panneau d'une carte */
  step('Panneau d\'une carte');
  await ap.click(`.kcard[data-card="${firstId}"]`);
  await ap.waitForSelector('.cp-title');
  ok(new RegExp('/studio/' + studioId + '/card/' + firstId + '$').test(ap.url()), 'la carte a sa propre adresse');
  ok(/Production/.test(await ap.locator('.cp-status').innerText()) && /Script Approved/.test(await ap.locator('.cp-status').innerText()), 'étape affichée : Production › Script Approved');
  await ap.fill('.cp-props input[data-field="due"]', '2026-12-20');
  await ap.locator('.cp-props input[data-field="due"]').blur();
  await ap.click('[data-act="cp-priority"]');
  await ap.click('.menu-item:has-text("High")');
  await ap.click('[data-act="cp-platforms"]');
  await ap.click('.menu-item:has-text("Instagram")');
  await ap.click('.menu-item:has-text("TikTok")');
  await ap.keyboard.press('Escape');
  await ap.selectOption('.cp-props select[data-field="ctype"]', 'reel');
  await ap.fill('.cp-props input[data-field="publish_at"]', '2026-12-22T18:30');
  await ap.locator('.cp-props input[data-field="publish_at"]').blur();
  await ap.click('[data-act="cp-assignee"]');
  await ap.click('.menu-item:has-text("Amel Haddad")');
  await ap.fill('.cp-props .chips-in', 'beginners');
  await ap.keyboard.press('Enter');
  await ap.click('.ed-body');
  await ap.keyboard.type('Hook: stop doing this on your first ride.');
  await ap.keyboard.press('Control+a');
  await ap.click('.ed-btn[data-cmd="bold"]');
  await ap.fill('.check-add input', 'Write the hook');
  await ap.keyboard.press('Enter');
  await ap.waitForSelector('.checks li');
  await ap.fill('.check-add input', 'Record the voice-over');
  await ap.keyboard.press('Enter');
  await ap.waitForFunction(() => document.querySelectorAll('.checks li').length === 2);
  await ap.check('.checks li:first-child input');
  await ap.setInputFiles('input[data-change="card-upload"]', path.join(DIR, 'photo.png'));
  await ap.waitForSelector('.file-thumb img');
  await ap.fill('#cp-comment', 'First draft ready <b>bold</b>');
  await ap.click('form[data-form="comment-add"] button.btn-primary');
  await ap.waitForSelector('.comments .comment');
  ok(await ap.locator('.comments .comment p').innerText() === 'First draft ready <b>bold</b>', 'commentaire affiché tel quel (non interprété)');
  await ap.waitForTimeout(500);
  await shot(ap, '04-card');
  await ap.reload({ waitUntil: 'load' });
  await ap.waitForSelector('.cp-title');
  const card = await call(ap, 'card_get', undefined, { id: firstId });
  ok(card.card.due === '2026-12-20' && card.card.priority === 3 && card.card.platforms.join() === 'instagram,tiktok' && card.card.ctype === 'reel' && card.card.publish_at === '2026-12-22T18:30' && card.card.tags.join() === 'beginners', 'champs enregistrés (échéance, priorité, plateformes, type, publication, tags)', card.card);
  ok(/<b>Hook: stop doing this on your first ride\.<\/b>/.test(card.card.body), 'script mis en forme enregistré', card.card.body);
  ok(card.card.checks.length === 2 && card.card.checks[0].done && card.card.files.length === 1 && card.card.comments.length === 1 && card.card.assignee > 0, 'check-list, pièce jointe, commentaire et responsable enregistrés');
  await ap.click('[data-act="cp-tab"][data-tab="history"]');
  ok(await ap.locator('.cp .events .event').count() >= 8 && /handed this card off from Creation/.test(await ap.locator('.cp .events').innerText()), 'historique de la carte (dont le passage de relais)');
  await ap.keyboard.press('Escape');
  await ap.waitForFunction(() => !document.querySelector('.cp'));
  ok(new RegExp('/studio/' + studioId + '$').test(ap.url()), 'fermer le panneau revient au tableau');
  const k = ap.locator(`.kcard[data-card="${firstId}"]`);
  ok(await k.locator('.kcard-cover').count() === 1 && /1\/2/.test(await k.innerText()) && await k.locator('.due').count() === 2 && await k.locator('.avatar').count() === 1, 'aperçu de la carte : image, check-list, dates, responsable');

  /* --------------------------------------------------- niveaux et colonnes */
  step('Niveaux et colonnes');
  await ap.click(`${colSel('✍️ Script in Progress')} .bcol-name`);
  await ap.fill('.inline-edit', '✍️ Writing');
  await ap.keyboard.press('Enter');
  await ap.waitForSelector(colSel('✍️ Writing'));
  ok(true, 'colonne renommée en cliquant sur son nom');
  await ap.locator('.level').nth(0).locator('[data-act="col-add"]').first().click();
  await ap.fill('.modal input[name=value]', '🔍 Review');
  await ap.click('.modal button[type=submit]');
  await ap.waitForSelector(colSel('🔍 Review'));
  ok(await ap.locator('.level').nth(0).locator('.bcol').count() === 4, 'colonne ajoutée');
  // La nouvelle colonne (4e) est glissée en 2e position.
  await drag(ap, `${colSel('🔍 Review')} .bcol-head`, `${colSel('✍️ Writing')} .bcol-head`, { fx: 0.3, tx: 0.2 });
  await ap.waitForFunction(() => Array.from(document.querySelectorAll('.level')[0].querySelectorAll('.bcol-name')).map((n) => n.textContent)[1] === '🔍 Review');
  await ap.waitForTimeout(300);
  let board = await call(ap, 'board', undefined, { studio: studioId });
  const lv = board.levels;
  ok(board.cols.filter((c) => c.level_id === lv[0].id).sort((a, b) => a.pos - b.pos).map((c) => c.name).join('|') === '💡 Ideas|🔍 Review|✍️ Writing|✅ Script Approved', 'colonne réordonnée par glisser-déposer (enregistré)');
  await ap.click(`${colSel('🔍 Review')} [data-act="col-menu"]`);
  await ap.click('.menu-item:has-text("Delete")');
  await ap.waitForFunction(() => !Array.from(document.querySelectorAll('.bcol-name')).some((n) => n.textContent === '🔍 Review'));
  ok(true, 'colonne vide supprimée');
  // Relais manuel sur Production.
  await ap.locator('.level').nth(1).locator('.handoff').click();
  await ap.click('.modal .seg label:has-text("Manual")');
  await ap.click('.modal button.btn-primary');
  await ap.waitForFunction(() => /Manual handoff/.test(document.querySelectorAll('.level')[1].querySelector('.handoff').textContent));
  await drag(ap, `.kcard[data-card="${firstId}"]`, `${colSel('🚀 Ready to Publish')} .bcol-cards >> nth=0`);
  await ap.waitForSelector('.kcard-send');
  ok(await ap.locator(`.level >> nth=1 >> .kcard[data-card="${firstId}"]`).count() === 1, 'relais manuel : la carte attend dans la colonne finale');
  await ap.click('.kcard-send');
  ok(await toast(ap, /Handed off to Publication/), 'bouton « Send to Publication » : carte transmise');
  await ap.waitForFunction((id) => { const c = document.querySelector(`.kcard[data-card="${id}"]`); return c && c.closest('.level').querySelector('.level-name').textContent === 'Publication'; }, firstId);
  // Nouveau niveau.
  await ap.click('.pipe-add');
  await ap.fill('.modal input[name=name]', 'Analysis');
  await ap.click('.modal [data-icon="📊"]');
  await ap.click('.modal button.btn-primary');
  await ap.waitForFunction(() => document.querySelectorAll('.level').length === 4);
  ok(await ap.locator('.level').nth(3).locator('.level-name').innerText() === 'Analysis' && await ap.locator('.level').nth(3).locator('.bcol').count() === 1, 'niveau ajouté avec sa première colonne');
  // Niveaux repliés (ils tiennent tous à l'écran), puis le 4e est glissé en tête.
  for (let i = 0; i < 4; i++) await ap.locator('.level').nth(i).locator('.level-toggle').click();
  ok(await ap.locator('.level.is-collapsed').count() === 4 && await ap.locator('.bcol').count() === 0, 'niveaux repliés');
  await drag(ap, '.level >> nth=3 >> .level-grip', '.level >> nth=0 >> .level-head', { ty: 0.1 });
  await ap.waitForFunction(() => document.querySelector('.level .level-name').textContent === 'Analysis');
  ok(true, 'niveau réordonné par glisser-déposer');
  for (let i = 0; i < 4; i++) await ap.locator('.level').nth(i).locator('.level-toggle').click();
  await ap.locator('.level').nth(0).locator('[data-act="level-menu"]').click();
  await ap.click('.menu-item:has-text("Archive")');
  await ap.waitForFunction(() => document.querySelectorAll('.level').length === 3);
  await ap.click('[data-act="studio-menu"]');
  await ap.click('.menu-item:has-text("Archive")');
  await ap.waitForSelector('.modal [data-act="unarchive"]');
  ok(/Analysis/.test(await ap.locator('.modal').innerText()), 'niveau archivé retrouvé dans les archives du Studio');
  await ap.keyboard.press('Escape');
  // Mode « un seul niveau ».
  await ap.click('.pipe-step:has-text("Creation")');
  ok(await ap.locator('.level').count() === 1, 'vue centrée sur un niveau');
  await ap.click('.pipe-all');
  ok(await ap.locator('.level').count() === 3, 'retour à tous les niveaux');

  /* ------------------------------------------------- sélection, filtres */
  step('Sélection multiple et filtres');
  const ideas = ap.locator(`${colSel('💡 Ideas')} .kcard`);
  await ideas.nth(0).click({ modifiers: ['Control'] });
  await ideas.nth(1).click({ modifiers: ['Control'] });
  ok(/2 cards selected/.test(await ap.locator('.bulk').innerText()), 'deux cartes sélectionnées (Ctrl + clic)');
  await ap.click('[data-act="bulk-priority"]');
  await ap.click('.menu-item:has-text("Urgent")');
  await ap.waitForFunction(() => document.querySelectorAll('.kcard.kprio-4').length === 2);
  ok(true, 'priorité appliquée aux cartes sélectionnées');
  await ap.fill('.board-tools input', 'carousel');
  await ap.waitForFunction(() => document.querySelectorAll('.kcard').length === 1);
  ok(await ap.locator('.kcard h3').innerText() === 'Carousel: bike maintenance', 'filtre par texte');
  await ap.fill('.board-tools input', '');
  await ap.click('[data-act="filter-mine"]');
  await ap.waitForFunction(() => document.querySelectorAll('.kcard').length === 1);
  ok(await ap.locator('.kcard').getAttribute('data-card') === firstId, 'filtre « Mine » : mes cartes seulement');
  await ap.click('[data-act="filter-mine"]');

  /* -------------------------------------- calendrier, mon travail, tableau de bord */
  step('Calendrier, Mon travail, tableau de bord');
  await ap.click('.tab:has-text("Calendar")');
  await ap.waitForSelector('.cal-grid');
  // Le calendrier s'ouvre sur le mois en cours : on va jusqu'à décembre 2026.
  for (let i = 0; i < 24 && !/December 2026/.test(await ap.locator('.cal-head h2').innerText()); i++) await ap.click('[data-act="cal-nav"][data-d="1"]');
  ok(await ap.locator('.cal-items[data-date="2026-12-20"] .cal-item.k-due').count() === 1 && await ap.locator('.cal-items[data-date="2026-12-22"] .cal-item.k-publish').count() === 1, 'calendrier : échéance le 20, publication le 22');
  ok(/18:30/.test(await ap.locator('.cal-items[data-date="2026-12-22"] .cal-item').innerText()), 'heure de publication affichée');
  ok(await ap.locator('.cal-tray .cal-item').count() === 3, 'cartes sans date listées à part');
  await drag(ap, '.cal-items[data-date="2026-12-20"] .cal-item', '.cal-items[data-date="2026-12-24"]');
  await ap.waitForSelector('.cal-items[data-date="2026-12-24"] .cal-item.k-due');
  await ap.waitForTimeout(400);
  ok((await call(ap, 'card_get', undefined, { id: firstId })).card.due === '2026-12-24', 'échéance déplacée en glissant la carte sur un autre jour');
  await shot(ap, '05-calendar');
  await ap.click('.tab:has-text("My work")');
  await ap.waitForSelector('.wrow');
  ok(await ap.locator('.wrow').count() === 1 && /Vélora · Social 2026/.test(await ap.locator('.wrow').innerText()) && /Publication › /.test(await ap.locator('.wrow').innerText()), 'Mon travail : la carte confiée, avec son projet et son étape');
  await ap.selectOption('select[data-name="status"]', 'done');
  ok(await ap.locator('.wrow').count() === 0 && /No card matches/.test(await ap.locator('.work').innerText()), 'Mon travail : filtre par statut');
  await ap.selectOption('select[data-name="status"]', 'open');
  await ap.click('.tab:has-text("Insights")');
  await ap.waitForSelector('.stats .stat');
  ok(await ap.locator('.stats .stat').count() === 6 && await ap.locator('.bars .bar-row').count() >= 3, 'tableau de bord du Studio');
  await shot(ap, '06-insights');
  await ap.goto(A + '#/projects');
  await ap.waitForSelector('.pcard');
  ok(await ap.locator('.pcard').count() === 1 && await ap.locator('.task').count() === 1, 'espace de travail : projet, Studio et tâches confiées');
  await shot(ap, '07-workspace');
  await noOverflow(ap, 'espace de travail');

  /* ------------------------------------------ invitation, second compte */
  step('Invitation par lien, synchronisation, droits');
  await ap.goto(A + '#/studio/' + studioId);
  await ap.waitForSelector('.level');
  await ap.click('.studio-head [data-act="share"].btn');
  await ap.waitForSelector('form[data-form="invite-email"]');
  await ap.fill('.modal input[name=email]', 'lina@test.example');
  await ap.selectOption('.modal select[name=role]', 'client');
  await ap.click('form[data-form="invite-email"] button.btn-primary');
  ok(await toast(ap, /Invitation sent to lina/), 'invitation par email envoyée');
  const linaLink = (mails().reverse().find((m) => m.to === 'lina@test.example').text.match(/https?:\/\/\S+join\/[a-f0-9]{32}/) || [])[0];
  ok(!!linaLink, 'email d\'invitation avec son lien');
  await ap.selectOption('#link-role', 'collaborator');
  await ap.click('[data-act="invite-link"]');
  await ap.waitForSelector('.modal .list-row:has-text("Link · Collaborator")');
  const link = await ap.locator('.modal [data-act="copy"]').first().getAttribute('data-text');
  ok(/#\/join\/[a-f0-9]{32}$/.test(link), 'lien d\'invitation créé');
  await shot(ap, '08-share');
  await ap.keyboard.press('Escape');

  const karimCtx = await browser.newContext(desk);
  const kp = await karimCtx.newPage();
  watch(kp, 'karim');
  await kp.goto(link.replace(/^https?:\/\/[^/]+/, ORIGIN), { waitUntil: 'load' });
  await kp.waitForSelector('.auth-card');
  ok(/invites you to join the Studio Social 2026 in Vélora as Collaborator/.test((await kp.locator('.auth-card').innerText()).replace(/\s+/g, ' ')), 'page de l\'invitation lisible sans compte');
  await kp.click('a:has-text("Create my account to join")');
  await kp.waitForSelector('form[data-form="signup"]');
  await kp.fill('input[name=name]', 'Karim Benali');
  await kp.fill('input[name=email]', 'karim@test.example');
  await kp.fill('input[name=password]', 'a-solid-password');
  await kp.click('form[data-form="signup"] button.btn-primary');
  await kp.waitForSelector('[data-act="invite-accept"]');
  ok(true, 'après l\'inscription : retour à l\'invitation');
  await kp.click('[data-act="invite-accept"]');
  await kp.waitForSelector('.level');
  ok(new RegExp('/studio/' + studioId + '$').test(kp.url()) && /Collaborator/.test(await kp.locator('.studio-head .role').innerText()), 'invitation acceptée : le Studio s\'ouvre, rôle Collaborator');
  ok(await kp.locator('[data-act="col-menu"]').count() === 0 && await kp.locator('.level-grip').count() === 0 && await kp.locator('.pipe-add').count() === 0, 'collaborateur : pas d\'outils de structure');
  // Synchronisation : Amel crée une carte, Karim la voit arriver sans recharger.
  await call(ap, 'card_create', { col_id: board.cols.find((c) => c.name === '💡 Ideas').id, title: 'Live: Q&A with the mechanic' });
  await kp.waitForSelector('.kcard:has(h3:text-is("Live: Q&A with the mechanic"))', { timeout: 12000 });
  ok(true, 'synchronisation : la carte créée par Amel apparaît chez Karim');
  // Karim ne déplace pas une carte qui ne lui est pas confiée.
  const story = await cardIn(kp, '💡 Ideas', 'Story: behind the scenes').getAttribute('data-card');
  await drag(kp, `.kcard[data-card="${story}"]`, `${colSel('✍️ Writing')} .bcol-cards`);
  await kp.waitForTimeout(500);
  ok(await cardIn(kp, '💡 Ideas', 'Story: behind the scenes').count() === 1, 'collaborateur : une carte qui ne lui est pas confiée ne bouge pas');
  ok((await call(kp, 'card_move', { id: +story, col_id: board.cols.find((c) => c.name === '✅ Script Approved').id, index: 0 })).status === 403, '… et le serveur refuse aussi');
  // Amel lui confie la carte : il peut la déplacer.
  const karimId = (await call(kp, 'me')).user.id;
  await call(ap, 'card_save', { id: +story, assignee: karimId });
  await kp.waitForSelector(`.kcard[data-card="${story}"] .avatar`, { timeout: 12000 });
  await drag(kp, `.kcard[data-card="${story}"]`, `${colSel('✍️ Writing')} .bcol-cards`);
  await cardIn(kp, '✍️ Writing', 'Story: behind the scenes').waitFor();
  ok(true, 'collaborateur : déplace la carte qui lui est confiée');
  await ap.waitForSelector(`${colSel('✍️ Writing')} .kcard[data-card="${story}"]`, { timeout: 12000 });
  ok(true, 'synchronisation : le déplacement de Karim apparaît chez Amel');
  await kp.click(`.kcard[data-card="${story}"]`);
  await kp.waitForSelector('#cp-comment');
  await kp.fill('#cp-comment', 'Script ready @amel');
  await kp.waitForSelector('.menu .menu-item:has-text("Amel Haddad")');
  await kp.click('.menu .menu-item:has-text("Amel Haddad")');
  ok(/@amel-haddad /.test(await kp.locator('#cp-comment').inputValue()), 'mention : choix d\'un membre en tapant @');
  await kp.click('form[data-form="comment-add"] button.btn-primary');
  await kp.waitForSelector('.comments .mention');
  await kp.keyboard.press('Escape');
  ok(await kp.locator('.nav-link[data-nav="notifications"] .nav-badge').count() === 1, 'pastille de notification (carte confiée)');
  await ap.goto(A + '#/notifications');
  await ap.waitForSelector('.notif');
  ok(/Karim Benali mentioned you/.test((await ap.locator('.notif').first().innerText()).replace(/\s+/g, ' ')), 'notification de mention reçue');
  await shot(ap, '09-notifications');

  /* ------------------------------------------------------------ validation */
  step('Validation par la cliente');
  const linaCtx = await browser.newContext(desk);
  const lp = await linaCtx.newPage();
  watch(lp, 'lina');
  await lp.goto(linaLink.replace(/^https?:\/\/[^/]+/, ORIGIN), { waitUntil: 'load' });
  await lp.waitForSelector('.auth-card');
  await lp.click('a:has-text("Create my account to join")');
  await lp.waitForSelector('form[data-form="signup"]');
  await lp.click('.seg label:has-text("Company")');
  await lp.fill('input[name=company]', 'Dar Lina');
  await lp.fill('input[name=name]', 'Lina Mansouri');
  await lp.fill('input[name=email]', 'lina@test.example');
  await lp.fill('input[name=password]', 'a-solid-password');
  await lp.click('form[data-form="signup"] button.btn-primary');
  await lp.waitForSelector('[data-act="invite-accept"]');
  await lp.click('[data-act="invite-accept"]');
  await lp.waitForSelector('.level');
  ok(/Client/.test(await lp.locator('.studio-head .role').innerText()) && await lp.locator('.bcol-add').count() === 0, 'cliente : accès au Studio, sans création de carte');
  // Karim demande la validation de sa carte à la cliente.
  await kp.click(`.kcard[data-card="${story}"]`);
  await kp.waitForSelector('form[data-form="approval-request"]');
  await kp.selectOption('form[data-form="approval-request"] select', { label: 'Lina Mansouri — Client / Approver' });
  await kp.fill('form[data-form="approval-request"] input[name=note]', 'Ready for your review');
  await kp.click('form[data-form="approval-request"] button');
  await kp.waitForSelector('.appr-pending');
  ok(true, 'validation demandée depuis la carte');
  await kp.keyboard.press('Escape');
  await lp.goto(A + '#/work');
  await lp.waitForSelector('.appr-box .list-row');
  ok(/Story: behind the scenes/.test(await lp.locator('.appr-box').first().innerText()), 'la demande attend la cliente dans « My work »');
  await lp.click('.appr-box .list-row');
  await lp.waitForSelector('form[data-form="approval-decide"]');
  ok(await lp.locator('textarea.cp-title').count() === 0 && await lp.locator('.ed-body').count() === 0, 'cliente : carte en lecture seule');
  await shot(lp, '10-approval');
  await lp.click('button[data-decision="approved"]');
  ok(await toast(lp, /Approved — handed off to Production/), 'validée : la carte avance et passe au niveau suivant');
  await kp.waitForSelector(`.level >> nth=1 >> .kcard[data-card="${story}"]`, { timeout: 12000 });
  ok(true, 'la carte validée arrive dans Production chez Karim');

  /* ---------------------------------------------------- profil et réseau */
  step('Profil, publications, réseau, étude de cas');
  await ap.goto(A + '#/profile');
  await ap.waitForSelector('.profile-head');
  ok(/Freelance community manager/.test(await ap.locator('.profile-title').innerText()) && await ap.locator('.rail .chip:has-text("Scriptwriting")').count() === 1, 'profil : titre et compétences des premiers pas');
  await ap.click('[data-act="profile-edit"]');
  await ap.waitForSelector('form[data-form="profile-save"]');
  await ap.fill('.modal input[name=handle]', 'amel');
  await ap.fill('.modal input[name=location]', 'Algiers');
  await ap.fill('.modal textarea[name=bio]', 'I help sports brands grow on short video. <script>alert(1)</script>');
  await ap.setInputFiles('.modal input[data-key="avatar"]', path.join(DIR, 'photo.png'));
  await ap.waitForSelector('#img-avatar img');
  await ap.fill('.modal input[name=experience]', '5');
  await ap.click('.modal button.btn-primary');
  await ap.waitForFunction(() => /\/u\/amel$/.test(location.hash));
  await ap.waitForSelector('.profile-head .av-xl img');
  ok(/<script>alert\(1\)<\/script>/.test(await ap.locator('.profile .pre').first().innerText()), 'profil enregistré ; bio affichée telle quelle (non interprétée)');
  await ap.goto(A + '#/');
  await ap.waitForSelector('.composer');
  await ap.click('.composer');
  await ap.fill('.modal textarea[name=body]', 'Our new reel is live! #cycling https://example.com/reel');
  await ap.setInputFiles('.modal input[data-change="compose-upload"]', path.join(DIR, 'photo.png'));
  await ap.waitForSelector('#compose-media img');
  await ap.click('.modal button.btn-primary');
  await ap.waitForSelector('.post');
  ok(await ap.locator('.post .media img').count() === 1 && await ap.locator('.post .hashtag').count() === 1 && await ap.locator('.post-body a[href="https://example.com/reel"]').count() === 1, 'publication avec image, mot-clé et lien');
  await shot(ap, '11-feed');
  // Karim découvre, suit, aime, commente, se met en relation.
  await kp.goto(A + '#/discover');
  await kp.waitForSelector('.post');
  await kp.click('.post [data-act="post-like"]');
  await kp.waitForFunction(() => document.querySelector('.post [data-act="post-like"] span').textContent === '1');
  await kp.click('.post [data-act="post-comments"]');
  await kp.fill('.post-comments input', 'Great work!');
  await kp.keyboard.press('Enter');
  await kp.waitForSelector('.post-comments .comment');
  ok(true, 'j\'aime et commentaire sur une publication');
  await kp.goto(A + '#/u/amel');
  await kp.waitForSelector('.profile-head');
  await kp.click('.profile-actions [data-act="follow"]');
  await kp.waitForSelector('.profile-actions [data-act="follow"][data-on="1"]');
  await kp.click('.profile-actions [data-act="connect"]');
  await kp.waitForSelector('.profile-actions [data-act="connect"]:has-text("Request sent")');
  ok(true, 'suivre et demander une mise en relation');
  await ap.goto(A + '#/network');
  await ap.waitForSelector('.person');
  ok(/Requests/.test(await ap.locator('.section-title').first().innerText()), 'demande de mise en relation reçue');
  await ap.click('.person [data-act="connect"]:has-text("Accept")');
  await ap.waitForFunction(() => /Connections/.test(document.querySelector('.section-title').textContent));
  ok(true, 'mise en relation acceptée');
  // Étude de cas déclarée.
  await ap.goto(A + '#/profile');
  await ap.waitForSelector('[data-act="case-new"]');
  await ap.locator('[data-act="case-new"]').first().click();
  await ap.fill('.modal input[name=title]', 'Launching Kivo on TikTok');
  await ap.fill('.modal input[name=brand]', 'Kivo');
  await ap.fill('.modal input[name=role]', 'Strategy and scripts');
  await ap.fill('.modal textarea[name=results]', '+38% engagement in three months');
  await ap.fill('.modal input[name="m_value[]"]', '+38%');
  await ap.fill('.modal input[name="m_label[]"]', 'Engagement');
  await ap.click('.modal button[data-publish="1"]');
  await ap.waitForSelector('.case');
  ok(/Self-reported/.test(await ap.locator('.case').innerText()) && /\+38%/.test(await ap.locator('.case').innerText()), 'étude de cas publiée, marquée « Self-reported »');
  ok(/1\s*Self-reported/.test((await ap.locator('.proof-self').innerText()).replace(/\n/g, ' ')), 'preuves de travail : origines distinguées');
  await shot(ap, '12-profile');
  await noOverflow(ap, 'profil');
  // Profil public sans compte.
  const anonCtx = await browser.newContext(desk);
  const np = await anonCtx.newPage();
  watch(np, 'visiteur');
  await np.goto(A + '#/u/amel', { waitUntil: 'load' });
  await np.waitForSelector('.profile-head');
  ok(await np.locator('.case').count() === 1 && await np.locator('.public-top').count() === 1 && await np.locator('.post').count() === 1, 'profil public lisible sans compte (portfolio, publications)');
  ok((await np.evaluate(async () => (await fetch('../api.php?action=board&studio=1')).status)) === 401, 'sans compte : le Studio reste fermé');

  /* ------------------------- entreprise, opportunité, talents, messages */
  step('Entreprise, opportunité, talents, messages');
  await lp.goto(A + '#/talent');
  await lp.waitForSelector('.tcard');
  ok(await lp.locator('.nav-link[data-nav="talent"]').count() === 1 && await lp.locator('.nav-link[data-nav="team"]').count() === 1, 'compte entreprise : rubriques Talent et Team');
  ok(await ap.locator('.nav-link[data-nav="talent"]').count() === 0, 'compte individuel : pas de rubrique Talent');
  await lp.selectOption('select[data-name="skill"]', 'Scriptwriting');
  await lp.waitForFunction(() => document.querySelectorAll('.tcard').length === 1);
  ok(/Amel Haddad/.test(await lp.locator('.tcard').innerText()) && /1 self-reported/.test(await lp.locator('.tcard-proof').innerText()), 'annuaire des talents : filtre par compétence, preuves de travail');
  await lp.selectOption('select[data-name="skill"]', 'Podcasting');
  await lp.waitForSelector('#talent .empty');
  ok(true, 'annuaire : aucun résultat, état vide');
  await lp.click('#talent [data-act="talent-reset"]');
  await lp.waitForSelector('.tcard');
  await shot(lp, '13-talent');
  await lp.locator('.tcard:has-text("Amel Haddad") [data-act="talent-save"]').click();
  ok(await toast(lp, /Saved to your talent list/), 'professionnelle enregistrée');
  await lp.click('.top-create');
  await lp.click('.menu-item:has-text("Opportunity")');
  await lp.fill('.modal input[name=title]', 'Community manager for Instagram');
  await lp.fill('.modal textarea[name=body]', 'We are looking for someone to run our Instagram account every day.');
  await lp.fill('.modal .chips-in', 'Community management');
  await lp.keyboard.press('Enter');
  await lp.click('.modal button.btn-primary');
  await lp.waitForSelector('.opp-full');
  ok(/Community manager for Instagram/.test(await lp.locator('.opp-full h1').innerText()), 'opportunité publiée');
  const oppUrl = lp.url();
  await ap.goto(A + '#/opportunities');
  await ap.waitForSelector('.opp');
  await ap.click('.opp');
  await ap.waitForSelector('form[data-form="opp-apply"]');
  await ap.fill('form[data-form="opp-apply"] textarea', 'Hello, five years of experience running sports brand accounts.');
  await ap.click('form[data-form="opp-apply"] button');
  await ap.waitForSelector('.notice-ok');
  ok(/You applied/.test(await ap.locator('.notice-ok').innerText()), 'candidature envoyée');
  await lp.goto(oppUrl);
  await lp.reload({ waitUntil: 'load' });
  await lp.waitForSelector('.applic');
  ok(/Amel Haddad/.test(await lp.locator('.applic').innerText()), 'l\'entreprise voit la candidature');
  await lp.click('.applic a:has-text("Message")');
  await lp.waitForSelector('.chat-form textarea');
  await lp.fill('.chat-form textarea', 'Hello Amel, can we talk this week?');
  await lp.keyboard.press('Enter');
  await lp.waitForSelector('.bubble.is-mine');
  await ap.goto(A + '#/messages');
  await ap.waitForSelector('.chat-row');
  await ap.click('.chat-row');
  await ap.waitForSelector('.bubble');
  ok(/can we talk this week/.test(await ap.locator('.bubble').innerText()), 'message privé reçu');
  await ap.fill('.chat-form textarea', 'With pleasure!');
  await ap.keyboard.press('Enter');
  await lp.waitForSelector('.bubble:not(.is-mine)', { timeout: 12000 });
  ok(true, 'réponse reçue sans recharger');
  await lp.goto(A + '#/c/dar-lina');
  await lp.waitForSelector('.profile-head');
  ok(await lp.locator('.opp').count() === 1, 'page de l\'entreprise : ses opportunités');
  await shot(lp, '14-company');

  /* ---------------------------- étude de cas vérifiée, historique, réglages */
  step('Étude de cas tirée du Studio, historique, équipe, réglages');
  board = await call(ap, 'board', undefined, { studio: studioId });
  const publishedCol = board.cols.find((c) => c.name === '✔️ Published');
  await call(ap, 'card_move', { id: +firstId, col_id: publishedCol.id, index: 0 });
  await call(ap, 'card_save', { id: +firstId, publish_url: 'https://instagram.com/p/velora-reel', metrics: { views: 12000, likes: 840 } });
  await ap.goto(A + '#/studio/' + studioId);
  await ap.waitForSelector('.bcol.is-done .kcard');
  await ap.click('[data-act="studio-menu"]');
  await ap.click('.menu-item:has-text("Activity")');
  await ap.waitForSelector('.drawer .event');
  ok(await ap.locator('.drawer .event').count() >= 20 && /joined the Studio as Collaborator/.test(await ap.locator('.drawer').innerText()), 'historique du Studio (cartes, structure, membres)');
  await ap.keyboard.press('Escape');
  await ap.click('[data-act="studio-menu"]');
  await ap.click('.menu-item:has-text("Create a case study")');
  await ap.waitForSelector('form[data-form="case-create"]');
  ok(await ap.locator('.modal .list-check input:checked').count() === 1 && /Verifiable/.test(await ap.locator('.modal .list-check').innerText()), 'étude de cas : la carte publiée (avec son lien) est proposée');
  await ap.click('.modal button.btn-primary');
  await ap.waitForSelector('form[data-form="case-save"]');
  ok(/authorization of the client/.test(await ap.locator('.modal .notice').innerText()), 'brouillon ouvert : l\'accord de la cliente est annoncé');
  ok(await ap.locator('#case-media .thumb img').count() === 1 && await ap.locator('.modal input[name="m_label[]"]').first().inputValue() === 'Views', 'brouillon prérempli : image et indicateurs de la carte');
  await ap.fill('.modal input[name=title]', 'Vélora — beginner reels');
  await ap.click('.modal button[data-publish="1"]');
  ok(await toast(ap, /waiting for the required authorization/), 'publication en attente de l\'autorisation');
  await ap.waitForSelector('.case.is-draft');
  ok(/Awaiting authorization/.test(await ap.locator('.case.is-draft').innerText()), 'profil : étude de cas « Awaiting authorization »');
  await np.reload({ waitUntil: 'load' });
  await np.waitForSelector('.profile-head');
  ok(await np.locator('.case').count() === 1, 'visiteur : l\'étude de cas en attente reste invisible');
  await lp.goto(A + '#/requests');
  await lp.waitForSelector('.req');
  ok(/Vélora — beginner reels/.test(await lp.locator('.req').innerText()) && /as the client/.test(await lp.locator('.req').innerText()), 'la cliente reçoit la demande d\'autorisation');
  await shot(lp, '14b-request');
  await lp.click('.req [data-yes="1"]');
  ok(await toast(lp, /now public/), 'autorisation donnée');
  await np.reload({ waitUntil: 'load' });
  await np.waitForSelector('.case');
  ok(await np.locator('.case').count() === 2 && await np.locator('.case .v-studio').count() === 1 && /Client-confirmed by Lina Mansouri/.test(await np.locator('.cases').innerText()), 'visiteur : étude de cas vérifiée par le Studio et confirmée par la cliente');
  ok(/1\s*Studio-verified/.test((await np.locator('.proof-studio').innerText()).replace(/\n/g, ' ')) && /1 published piece on record/.test(await np.locator('.proof-studio').innerText()), 'preuves de travail mises à jour');
  await np.click('.case:has(.v-studio)');
  await np.waitForSelector('.case-view');
  ok(await np.locator('.case-view a[href="https://instagram.com/p/velora-reel"]').count() === 1 && await np.locator('.case-view .media img').count() === 1, 'étude de cas publique : lien de publication et image');
  await shot(np, '14c-case');
  await np.keyboard.press('Escape');
  await lp.goto(A + '#/team');
  await lp.waitForSelector('form[data-form="team-add"]');
  await lp.fill('form[data-form="team-add"] input[name=who]', 'karim@test.example');
  await lp.selectOption('form[data-form="team-add"] select', 'admin');
  await lp.click('form[data-form="team-add"] button');
  await lp.waitForFunction(() => document.querySelectorAll('.page .list .list-row').length === 2);
  ok(true, 'équipe de l\'entreprise : coéquipier ajouté');
  await kp.goto(A + '#/');
  await kp.reload({ waitUntil: 'load' });
  await kp.waitForSelector('.nav');
  ok(await kp.locator('.nav-link[data-nav="talent"]').count() === 1, 'le nouvel administrateur voit la rubrique Talent');
  await ap.goto(A + '#/settings');
  await ap.waitForSelector('.switch');
  await ap.click('.switch[data-name="show_activity"]');
  ok(await toast(ap, /Saved/) && (await call(ap, 'me')).user.settings.show_activity === true, 'réglage enregistré (activité des Studios sur le profil)');
  // Adresse non confirmée : Settings › Email l'explique et envoie le lien de confirmation.
  ok(/Invitations to projects and Studios sent to amel@test\.example will appear in your workspace once you confirm it/.test((await ap.locator('[data-email-unconfirmed]').innerText()).replace(/\s+/g, ' ')), 'réglages : adresse non confirmée, invitations par email expliquées');
  await ap.locator('#account-email').scrollIntoViewIfNeeded();
  await shot(ap, '14b-settings-email');
  await ap.click('[data-act="email-confirm-send"]');
  await ap.waitForSelector('[data-confirm-sent]');
  const confirmMail = mails().reverse().find((m) => m.to === 'amel@test.example' && /Confirm your email/.test(m.subject));
  const confirmUrl = confirmMail && (confirmMail.text.match(/https?:\/\/\S+confirm\/[a-f0-9]{48}/) || [])[0];
  ok(!!confirmUrl, 'lien de confirmation envoyé');
  await ap.goto(confirmUrl.replace(/^https?:\/\/[^/]+/, ORIGIN));
  await ap.waitForSelector('[data-email-confirmed]');
  ok(await ap.evaluate(() => SM.me.email_ok) === true, 'lien ouvert depuis le compte connecté : adresse confirmée');
  await ap.goto(A + '#/settings');
  await ap.waitForSelector('#account-email .email-state');
  ok(await ap.locator('[data-email-unconfirmed]').count() === 0, 'réglages : adresse confirmée, plus d\'avertissement');
  await ap.goto(A + '#/discover?q=scriptwriting');
  await ap.waitForSelector('.person');
  ok(/Amel Haddad/.test(await ap.locator('.person').first().innerText()), 'recherche : personnes par compétence');
  await ap.goto(A + '#/project/' + board.studio.project.id);
  await ap.waitForSelector('.scard');
  ok(await ap.locator('.member').count() === 1 && await ap.locator('.scard').count() === 1, 'page du projet : Studios et membres du projet');
  await shot(ap, '14d-project');

  /* --------------------------------------------- continuer avec Google */
  step('Continue with Google');
  await np.goto(A + '#/login', { waitUntil: 'load' });
  await np.waitForSelector('form[data-form="login"]');
  await np.waitForFunction(() => document.querySelector('[data-google]').hidden, null, { timeout: 8000 });
  ok(true, 'Google injoignable : le bouton disparaît, le formulaire reste utilisable');
  const gCtx = await browser.newContext(desk);
  await gCtx.addInitScript(G.STUB);
  const gp = await gCtx.newPage();
  watch(gp, 'google');
  await gp.goto(A + '#/login', { waitUntil: 'load' });
  await gp.waitForSelector('.fake-google');
  ok(await gp.evaluate(() => window.__gcfg.client_id) === G.CLIENT_ID && await gp.locator('.fake-google').getAttribute('data-text') === 'continue_with', 'page de connexion : bouton Google avec l\'ID client');
  await shot(gp, '18-google-login');
  await gp.evaluate((t) => { window.__gcred = t; }, google.token({ sub: 'g-sofia', email: 'sofia@test.example', name: 'Sofia Rahmani' }, {}));
  await gp.click('.fake-google');
  await gp.waitForSelector('.welcome-card');
  ok(/Welcome, Sofia/.test(await gp.locator('.welcome-card h1').innerText()), 'nouvelle identité Google : compte créé, premiers pas');
  await gp.waitForSelector('.terms-modal');
  ok(/One last step, Sofia/.test(await gp.locator('.terms-modal').innerText()) && /respectfully/.test(await gp.locator('.terms-modal .terms-box').innerText()), 'compte créé avec Google : fenêtre des conditions dans le compte');
  await shot(gp, '20-google-terms');
  await gp.keyboard.press('Escape');
  await gp.mouse.click(10, 10);
  ok(await gp.locator('.terms-modal').isVisible(), 'la fenêtre ne se ferme ni par Échap ni par un clic à côté');
  ok((await call(gp, 'project_save', { name: 'Trop tôt' })).error === 'terms' && await gp.locator('.terms-modal').count() === 1, 'écriture refusée par le serveur avant d\'accepter');
  await gp.click('[data-act="terms-accept"]');
  await gp.waitForSelector('.terms-modal', { state: 'detached' });
  ok(await toast(gp, /Welcome to Studioma/) && await gp.evaluate(() => SM.me.terms === true), 'conditions acceptées : la fenêtre se ferme');
  await gp.goto(A + '#/settings');
  await gp.waitForSelector('#signin-methods');
  ok(/Connected/.test(await gp.locator('#signin-methods .method').innerText()) && await gp.locator('[data-act="google-unlink"]').isDisabled(), 'réglages : Google connecté, retrait impossible sans mot de passe');
  ok(/Choose a password/.test(await gp.locator('form[data-form="account-password"]').innerText()) && await gp.locator('form[data-form="account-password"] input[name=current]').count() === 0, 'réglages : choisir un mot de passe, sans « ancien »');
  await gp.fill('form[data-form="account-password"] input[name=password]', 'sofia-solid-password');
  await gp.click('form[data-form="account-password"] button');
  ok(await toast(gp, /Password saved/), 'mot de passe choisi');
  await gp.waitForSelector('[data-act="google-unlink"]:not([disabled])');
  ok(await gp.locator('form[data-form="account-password"] input[name=current]').count() === 1, 'le formulaire devient « changer le mot de passe »');
  await shot(gp, '19-google-settings');
  // Déconnexion, puis retour avec Google : même compte.
  await gp.click('.top-avatar');
  await gp.click('.menu-item:has-text("Sign out")');
  await gp.waitForSelector('.fake-google');
  await gp.click('.fake-google');
  await gp.waitForSelector('.composer');
  ok(await gp.evaluate(() => SM.me.name) === 'Sofia Rahmani' && await toast(gp, /Signed in with Google/), 'reconnexion avec Google : même compte');
  // Inscription d'une entreprise avec Google : nom exigé avant Google, conditions demandées ensuite dans le compte.
  await gp.click('.top-avatar');
  await gp.click('.menu-item:has-text("Sign out")');
  await gp.waitForSelector('.auth-card:has(form[data-form="login"]) .fake-google');
  await gp.goto(A + '#/signup?as=company');
  await gp.waitForSelector('form[data-form="signup"] .fake-google');
  ok(await gp.locator('.fake-google').getAttribute('data-text') === 'signup_with', 'page d\'inscription : bouton « Sign up with Google »');
  await gp.evaluate((t) => { window.__gcred = t; }, google.token({ sub: 'g-brand', email: 'team@brand.example', name: 'Brand Team' }, {}));
  await gp.click('.fake-google');
  await gp.waitForSelector('form[data-form="signup"] [data-error]:not([hidden])');
  ok(/name of your company/.test(await gp.locator('form[data-form="signup"] [data-error]').innerText()) && await gp.evaluate(() => document.activeElement.name) === 'company', 'entreprise : nom demandé avant Google');
  await gp.fill('input[name=company]', 'Brand Co');
  await gp.click('.fake-google');
  await gp.waitForSelector('.welcome-card');
  ok(await gp.evaluate(() => SM.me.kind === 'company' && SM.companies.length === 1 && SM.companies[0].name === 'Brand Co'), 'entreprise créée avec Google, avec sa page');
  await gp.waitForSelector('.terms-modal');
  await gp.click('[data-act="terms-signout"]');
  await gp.waitForSelector('form[data-form="login"]');
  ok(await gp.locator('.terms-modal').count() === 0 && await gp.evaluate(() => !SM.me), '« Not now — sign out » : déconnecté');
  await gp.click('.fake-google');
  await gp.waitForSelector('.terms-modal');
  ok(true, 'reconnexion : la fenêtre des conditions revient tant qu\'elles ne sont pas acceptées');
  await gp.click('[data-act="terms-accept"]');
  await gp.waitForSelector('.terms-modal', { state: 'detached' });
  await gCtx.close();

  /* --------------------------------------------------- assistants IA (MCP) */
  step('AI assistants : serveur MCP, jeton personnel, écran d\'accord');
  const MCP = ORIGIN + '/studioma/mcp';
  const rpc = (tok, method, params) => fetch(MCP, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + tok }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params: params || {} }) })
    .then(async (x) => ({ status: x.status, data: x.status === 200 ? await x.json() : null }));
  await ap.goto(A + '#/settings');
  await ap.click('a.ai-link');
  await ap.waitForSelector('.ai-page');
  ok(/\/studioma\/mcp$/.test(await ap.locator('.ai-page .code pre').first().innerText()) && await ap.locator('.guide[open]').count() === 1 && await ap.locator('.guide').count() === 5, 'page AI assistants : adresse du serveur et guides de connexion');
  await ap.fill('form[data-form="ai-token"] input[name=name]', 'Script on my laptop');
  await ap.click('form[data-form="ai-token"] button');
  await ap.waitForSelector('.token-once');
  const pat = (await ap.locator('.token-once pre').innerText()).trim();
  ok(/^stm_pat_[a-f0-9]{48}$/.test(pat), 'jeton personnel montré une fois');
  await shot(ap, '20-ai-settings');
  let mres = await rpc(pat, 'tools/call', { name: 'list_projects', arguments: {} });
  ok(mres.status === 200 && mres.data.result.structuredContent.projects.some((p) => p.name === 'Vélora'), 'le jeton ouvre le serveur MCP au nom d\'Amel');
  await ap.reload({ waitUntil: 'load' });
  await ap.waitForSelector('.ai-page');
  ok(await ap.locator('.token-once').count() === 0 && /Script on my laptop/.test(await ap.locator('.ai-page').innerText()), 'après rechargement : jeton listé, jamais réaffiché');
  // Une application s'enregistre et demande l'accès (comme claude.ai).
  const regRes = await fetch(ORIGIN + '/studioma/oauth/register', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ client_name: 'Claude (test)', redirect_uris: ['https://claude.ai/api/mcp/auth_callback'], token_endpoint_auth_method: 'none' }) }).then((x) => x.json());
  const verifier = crypto.randomBytes(32).toString('base64url');
  const challenge = crypto.createHash('sha256').update(verifier).digest('base64url');
  const authUrl = ORIGIN + '/studioma/oauth/authorize?' + new URLSearchParams({ response_type: 'code', client_id: regRes.client_id, redirect_uri: 'https://claude.ai/api/mcp/auth_callback', code_challenge: challenge, code_challenge_method: 'S256', state: 's1', resource: MCP });
  const oCtx = await browser.newContext(desk);
  let landed = '';
  await oCtx.route('https://claude.ai/**', (route) => { landed = route.request().url(); route.fulfill({ status: 200, contentType: 'text/plain', body: 'Back in Claude' }); });
  const op = await oCtx.newPage();
  watch(op, 'oauth');
  await op.goto(authUrl, { waitUntil: 'load' });
  await op.waitForSelector('form[data-form="login"]');
  ok(true, 'écran d\'accord sans session : connexion demandée d\'abord');
  await op.fill('input[name=email]', 'amel@test.example');
  await op.fill('input[name=password]', 'a-solid-password');
  await op.click('form[data-form="login"] button.btn-primary');
  await op.waitForSelector('.consent');
  const ctext = (await op.locator('.consent').innerText()).replace(/\s+/g, ' ');
  ok(/Connect Claude \(test\) to your Studioma account/.test(ctext) && /act as Amel Haddad/.test(ctext) && /sent back to claude\.ai/.test(ctext), 'écran d\'accord : application, compte et site de retour');
  await shot(op, '21-ai-consent');
  await op.click('[data-act="oauth-decide"][data-allow="1"]');
  await op.waitForFunction(() => /Back in Claude/.test(document.body.innerText), null, { timeout: 8000 });
  const backUrl = new URL(landed);
  ok(backUrl.searchParams.get('code') && backUrl.searchParams.get('state') === 's1', 'Allow : retour vers l\'application avec le code');
  const tok = await fetch(ORIGIN + '/studioma/oauth/token', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ grant_type: 'authorization_code', code: backUrl.searchParams.get('code'), redirect_uri: 'https://claude.ai/api/mcp/auth_callback', client_id: regRes.client_id, code_verifier: verifier }) }).then((x) => x.json());
  mres = await rpc(tok.access_token, 'tools/list');
  ok(mres.status === 200 && mres.data.result.tools.length >= 20, 'jeton de l\'application : outils accessibles');
  await oCtx.close();
  await ap.reload({ waitUntil: 'load' });
  await ap.waitForSelector('.ai-page');
  ok(/Claude \(test\)/.test(await ap.locator('.ai-page').innerText()), 'application connectée listée');
  await ap.click('.list-row:has-text("Claude (test)") [data-act="ai-revoke"]');
  await ap.click('.modal button.btn-danger');
  ok(await toast(ap, /Access removed/), 'application déconnectée');
  ok((await rpc(tok.access_token, 'tools/list')).status === 401, '… et ses jetons refusés aussitôt');
  await ap.click('.list-row:has-text("Script on my laptop") [data-act="ai-revoke"]');
  await ap.click('.modal button.btn-danger');
  await toast(ap, /Access removed/);
  ok((await rpc(pat, 'tools/list')).status === 401, 'jeton personnel supprimé : refusé');

  /* ------------------------------------------- partage avec des invités sans compte */
  step('Partage sans compte : lien public, demande de droits, lien privé');
  await ap.goto(A + '#/studio/' + studioId, { waitUntil: 'load' });
  await ap.waitForSelector('.kcard');
  await ap.click('.studio-head .btn[data-act="share"]');
  await ap.click('[data-act="share-tab"][data-tab="guests"]');
  await ap.click('[data-act="glink-create"][data-kind="public"]');
  await ap.waitForSelector('.link-card[data-link="public"] [data-act="copy"]');
  const shPub = await ap.getAttribute('.link-card[data-link="public"] [data-act="copy"]', 'data-text');
  ok(/\/studioma\/s\/[A-Za-z0-9_-]{32}$/.test(shPub), 'lien public créé depuis Partager › Guests without an account : /studioma/s/<clé>');
  const masked = await ap.locator('.link-card[data-link="public"] .sl-url').innerText();
  ok(masked.includes('••••') && !masked.includes(shPub.split('/s/')[1]), 'la clé est masquée à l\'écran');
  await ap.click('.link-card[data-link="public"] [data-act="glink-reveal"]');
  ok((await ap.locator('.link-card[data-link="public"] .sl-url').innerText()).includes(shPub.split('/s/')[1]), '« Show » affiche le lien entier');
  await shot(ap, '22-share-guests');
  await ap.keyboard.press('Escape');
  const shCtx = await browser.newContext(desk);
  const shG = await shCtx.newPage();
  watch(shG, 'invité');
  await shG.goto(shPub, { waitUntil: 'load' });
  await shG.waitForSelector('.guest-head');
  await shG.waitForSelector('.kcard');
  ok(await shG.evaluate(() => !window.fbq), 'lien de partage : le pixel Meta ne se charge pas (la clé du lien ne part jamais chez Meta)');
  ok(/View only/.test(await shG.locator('.chip-guest-role').innerText()) && await shG.locator('.bcol-add, [data-act="col-add"], [data-act="studio-menu"], .side').count() === 0, 'visiteur sans compte : tableau en lecture seule, ni ajout ni réglages');
  await shG.locator('.kcard').first().click();
  await shG.waitForSelector('.cp h1.cp-title');
  ok(await shG.locator('textarea.cp-title, form[data-form="comment-add"], input[type=file]').count() === 0, 'carte : rien de modifiable, pas de commentaire');
  await shG.keyboard.press('Escape');
  await shG.click('[data-act="guest-request"]');
  await shG.fill('form[data-form="guest-email"] textarea[name=note]', 'I want to fix the captions');
  await shG.fill('form[data-form="guest-email"] input[name=email]', 'client@brand.example');
  await shG.click('form[data-form="guest-email"] button');
  await shG.waitForSelector('form[data-form="guest-code"]');
  const shCode = (mails().reverse().find((m) => m.to === 'client@brand.example').text.match(/\b(\d{6})\b/) || [])[1];
  await shG.fill('input[name=code]', '000000' === shCode ? '111111' : '000000');
  await shG.click('form[data-form="guest-code"] button.btn-primary');
  await shG.waitForSelector('form[data-form="guest-code"] [data-error]:not([hidden])');
  ok(/not correct/.test(await shG.locator('form[data-form="guest-code"] [data-error]').innerText()), 'code faux : message clair');
  await shG.fill('input[name=code]', shCode);
  await shG.click('form[data-form="guest-code"] button.btn-primary');
  await shG.waitForSelector('.chip-pending');
  ok(/client@brand\.example/.test(await shG.locator('.guest-me').innerText()) && /sent to Amel Haddad/.test(await shG.locator('.guest-note').innerText()), 'adresse vérifiée par code ; demande envoyée, en attente');
  await shot(shG, '23-guest-pending');
  const shAsk = mails().reverse().find((m) => m.to === 'amel@test.example' && /asks to edit/.test(m.subject));
  await ap.goto((shAsk.text.match(/(http\S+do=approve)/) || [])[1], { waitUntil: 'load' });
  await ap.waitForSelector('form[data-form="confirm-ok"]');
  ok(/Give client@brand\.example editor access/.test(await ap.locator('.modal').last().innerText()), 'lien « Approve » de l\'email : confirmation dans Studioma');
  await ap.click('form[data-form="confirm-ok"] button[type=submit]');
  ok(await toast(ap, /is now Editor/), 'demande approuvée');
  await shG.waitForSelector('.chip-guest-role:has-text("Editor")', { timeout: 12000 });
  ok(await shG.locator('.bcol-add').count() > 0, 'l\'invité devient Editor sans recharger la page');
  await shG.locator('.bcol-add').first().click();
  await shG.fill('.card-new textarea', 'Idea from the client');
  await shG.press('.card-new textarea', 'Enter');
  await shG.waitForSelector('.kcard:has(h3:text-is("Idea from the client"))');
  await shG.keyboard.press('Escape');
  await shG.locator('.kcard:has(h3:text-is("Idea from the client"))').click();
  await shG.waitForSelector('textarea.cp-title');
  await shG.fill('#cp-comment', 'Here is my idea');
  await shG.click('form[data-form="comment-add"] button');
  await shG.waitForSelector('.comment:has-text("Here is my idea") .chip-guest');
  ok(true, 'l\'invité crée une carte et commente (signalé « Guest »)');
  await shot(shG, '24-guest-editor');
  await shG.keyboard.press('Escape');
  // Le responsable limite ce que l'invité peut modifier.
  await ap.goto(A + '#/studio/' + studioId + '?guests=1', { waitUntil: 'load' });
  await ap.waitForSelector('[data-act="guest-scope"]');
  await ap.click('[data-act="guest-scope"]');
  await ap.click('input[name=all][value="0"]');
  await ap.locator('.scope-cols input').first().check();
  await ap.click('form[data-form="guest-scope"] button.btn-primary');
  ok(await toast(ap, /Editing access saved/), 'périmètre d\'édition : une seule colonne');
  await shG.waitForFunction(() => document.querySelectorAll('.bcol-add').length === 1, null, { timeout: 12000 });
  ok(true, 'côté invité : une seule colonne reste modifiable');
  // Lien privé : la personne ajoutée vérifie son adresse, puis commente.
  await ap.goto(A + '#/studio/' + studioId + '?guests=1', { waitUntil: 'load' });
  await ap.click('[data-act="glink-create"][data-kind="private"]');
  await ap.waitForSelector('.guest-add');
  await ap.fill('.guest-add input[name=emails]', 'partner@brand.example');
  await ap.selectOption('.guest-add select[name=role]', 'commenter');
  await ap.click('.guest-add button');
  ok(await toast(ap, /received the link by email/), 'personne ajoutée au lien privé, lien envoyé par email');
  const shInv = mails().reverse().find((m) => m.to === 'partner@brand.example');
  const shPriv = (shInv.text.match(/https?:\/\/\S+\/studioma\/s\/[A-Za-z0-9_-]{32}/) || [])[0];
  const shPCtx = await browser.newContext(desk);
  const shP = await shPCtx.newPage();
  watch(shP, 'invité privé');
  await shP.goto(shPriv, { waitUntil: 'load' });
  await shP.waitForSelector('.guest-gate form');
  ok(await shP.locator('.kcard').count() === 0, 'lien privé : rien n\'est visible avant la vérification');
  await shot(shP, '25-private-gate');
  await shP.fill('.guest-gate input[name=email]', 'partner@brand.example');
  await shP.click('.guest-gate button.btn-primary');
  await shP.waitForSelector('input[name=code]');
  const shMagic = (mails().reverse().find((m) => m.to === 'partner@brand.example' && /code/.test(m.subject)).text.match(/https?:\/\/\S+\?v=[a-f0-9]{48}/) || [])[0];
  await shP.goto(shMagic, { waitUntil: 'load' });
  await shP.waitForSelector('.kcard');
  ok(/Commenter/.test(await shP.locator('.chip-guest-role').innerText()) && !/\?v=/.test(shP.url()), 'lien magique : adresse vérifiée, rôle Commenter, lien retiré de l\'adresse');
  // Accès retiré par le responsable : l'invité le voit aussitôt.
  await ap.click('.guest-row:has-text("partner@brand.example") [data-act="guest-more"]');
  await ap.click('.menu button:has-text("Revoke access")');
  await ap.click('form[data-form="confirm-ok"] button[type=submit]');
  ok(await toast(ap, /Access revoked/), 'accès retiré');
  await shP.waitForSelector('.guest-gate', { timeout: 12000 });
  ok(true, 'l\'invité retiré revient à la porte du lien privé');
  await ap.keyboard.press('Escape');
  await ap.waitForFunction(() => !document.querySelector('.overlay'));
  await ap.goto(A + '#/notifications', { waitUntil: 'load' });
  await ap.waitForSelector('.notif-text');
  ok(/client@brand\.example \(guest\)/.test(await ap.locator('#view').innerText()), 'notifications : au nom de l\'invité');
  const shM = await browser.newContext(devices['iPhone 13']);
  const shMp = await shM.newPage();
  watch(shMp, 'invité mobile');
  await shMp.goto(shPub, { waitUntil: 'load' });
  await shMp.waitForSelector('.kcard');
  await noOverflow(shMp, 'invité sur mobile');
  await shot(shMp, '26-guest-mobile');
  await Promise.all([shCtx.close(), shPCtx.close(), shM.close()]);
  const metaRaw = fs.existsSync(path.join(DIR, 'meta.jsonl')) ? fs.readFileSync(path.join(DIR, 'meta.jsonl'), 'utf8') : '';
  ok(metaRaw.length > 0 && !metaRaw.includes(shPub.split('/s/')[1]) && !metaRaw.includes(shPriv.split('/s/')[1]) && !/Vélora|Social 2026|Idea from the client|client@brand|partner@brand/.test(metaRaw),
    'API Conversions : ni clé de lien, ni nom de projet ou de Studio, ni titre de carte, ni email d\'invité');
  // La carte de l'invité est archivée : les étapes suivantes retrouvent le tableau tel qu'il était.
  const shCard = (await call(ap, 'board', undefined, { studio: studioId })).cards.find((c) => c.title === 'Idea from the client');
  await call(ap, 'card_archive', { id: shCard.id, archived: true });

  /* ------------------------------------------------- mobile, thème sombre */
  step('Mobile et thème sombre');
  const mobCtx = await browser.newContext(Object.assign({}, devices['iPhone 13'], { storageState: await amelCtx.storageState() }));
  const mp = await mobCtx.newPage();
  watch(mp, 'mobile');
  await mp.goto(A + '#/studio/' + studioId, { waitUntil: 'load' });
  await mp.waitForSelector('.kcard');
  ok(await mp.evaluate(() => (window.__fbqCalls || []).some((c) => c[0] === 'init' && c[2] && c[2].em === 'amel@test.example')), 'pixel : correspondance avancée (email) pour une personne connectée');
  ok(await mp.locator('.tabbar').isVisible() && !(await mp.locator('.side').isVisible().catch(() => false) && await mp.evaluate(() => document.querySelector('.side').getBoundingClientRect().right > 0)), 'mobile : barre d\'onglets en bas, menu replié');
  await noOverflow(mp, 'tableau sur mobile');
  await shot(mp, '15-mobile-board');
  // Glisser-déposer au doigt : appui long sur la dernière carte d'Ideas, puis glissement en tête de colonne.
  const cdp = await mobCtx.newCDPSession(mp);
  const touch = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y, id: 1 }] });
  const ideasCards = mp.locator(`${colSel('💡 Ideas')} .kcard`);
  const lastTitle = await ideasCards.last().locator('h3').innerText();
  const from = await ideasCards.last().boundingBox();
  const to = await ideasCards.first().boundingBox();
  await touch('touchStart', from.x + 60, from.y + 18);
  await mp.waitForTimeout(400);
  ok(await mp.evaluate(() => document.body.classList.contains('is-dragging')), 'mobile : un appui long saisit la carte');
  for (let i = 1; i <= 10; i++) { await touch('touchMove', from.x + 60, from.y + 18 + ((to.y + 5) - (from.y + 18)) * (i / 10)); await mp.waitForTimeout(30); }
  await mp.waitForTimeout(150);
  await touch('touchEnd');
  await mp.waitForFunction((t) => document.querySelector('.bcol .kcard h3').textContent === t, lastTitle);
  await mp.waitForFunction(() => !SM.board.writing);
  const afterTouch = await call(mp, 'board', undefined, { studio: studioId });
  const ideasCol = afterTouch.cols.find((c) => c.name === '💡 Ideas');
  ok(afterTouch.cards.filter((c) => c.col_id === ideasCol.id).sort((a, b) => a.pos - b.pos)[0].title === lastTitle, 'mobile : carte déplacée au doigt (enregistré)');
  const quick = await ideasCards.first().boundingBox();
  await touch('touchStart', quick.x + 60, quick.y + 18);
  for (let i = 1; i <= 5; i++) { await touch('touchMove', quick.x + 60, quick.y + 18 - i * 22); await mp.waitForTimeout(16); }
  ok(!(await mp.evaluate(() => document.body.classList.contains('is-dragging'))), 'mobile : un balayage rapide fait défiler, sans saisir la carte');
  await touch('touchEnd');
  await mp.evaluate(() => window.scrollTo(0, 0));
  await mp.locator(`.kcard[data-card="${firstId}"]`).scrollIntoViewIfNeeded();
  await mp.locator(`.kcard[data-card="${firstId}"] h3`).click();
  await mp.waitForSelector('.cp-title');
  ok(await mp.evaluate(() => document.querySelector('.drawer').getBoundingClientRect().width === window.innerWidth), 'mobile : la carte s\'ouvre en plein écran');
  await shot(mp, '16-mobile-card');
  await mp.click('.cp-head [data-act="modal-close"]');
  await mp.click('.top-menu');
  ok(await mp.waitForFunction(() => document.querySelector('.side').getBoundingClientRect().left === 0, null, { timeout: 3000 }).then(() => true, () => false), 'mobile : menu ouvert par le bouton');
  await mp.click('.nav-link[data-nav="projects"]');
  await mp.waitForSelector('.pcard');
  await noOverflow(mp, 'projets sur mobile');
  await mp.goto(A + '#/u/amel');
  await mp.waitForSelector('.profile-head');
  await noOverflow(mp, 'profil sur mobile');
  await ap.goto(A + '#/studio/' + studioId);
  await ap.waitForSelector('.kcard');
  await ap.click('.top-avatar');
  await ap.click('.menu-item:has-text("Dark")');
  ok(await ap.evaluate(() => document.documentElement.dataset.theme) === 'dark', 'thème sombre activé');
  await ap.waitForTimeout(300);
  await shot(ap, '17-dark-board');
  await ap.reload({ waitUntil: 'load' });
  await ap.waitForSelector('.kcard');
  ok(await ap.evaluate(() => document.documentElement.dataset.theme) === 'dark', 'thème sombre conservé');

  /* ------------------------------------------------------- administration */
  step('Administration de la plateforme (studioma/admin/)');
  const admCtx = await browser.newContext(desk);
  const adp = await admCtx.newPage();
  watch(adp, 'admin');
  let admPixel = 0;
  await admCtx.route('https://connect.facebook.net/**', (route) => { admPixel++; route.abort(); });
  await adp.goto(ORIGIN + '/studioma/admin/');
  await adp.waitForSelector('form[data-form="adm-login"]');
  await adp.fill('input[name=password]', 'not-the-password');
  await adp.click('form[data-form="adm-login"] button[type=submit]');
  ok(await adp.waitForSelector('[data-error]:not([hidden])', { timeout: 5000 }).then((e) => e.textContent()).then((t) => /Wrong password/.test(t), () => false), 'mauvais mot de passe : message clair');
  await adp.fill('input[name=password]', 'test-owner-pass');
  await adp.click('form[data-form="adm-login"] button[type=submit]');
  await adp.waitForSelector('#ov-charts .chart');
  ok(await adp.locator('#ov-tiles .tile').count() === 6 && await adp.locator('#ov-charts .chart').count() === 6, 'vue d\'ensemble : 6 chiffres et 6 graphiques sur 30 jours');
  ok(/Live · /.test(await adp.locator('#adm-live').textContent()), 'indicateur « en direct »');
  ok(await adp.locator('#ov-feed .feed-row').count() > 3 && await adp.locator('#ov-online .who-btn').count() >= 1, 'fil d\'activité et comptes en ligne');
  const bar = adp.locator('#ov-charts .chart').first().locator('.hit').last();
  await bar.hover();
  ok(await adp.waitForSelector('.chart-tip:not([hidden])', { timeout: 3000 }).then((t) => t.textContent()).then((t) => /today/.test(t), () => false), 'valeur d\'un jour au survol d\'une colonne');
  await shot(adp, '30-admin-overview');
  await adp.click('.nav-link[data-nav="#/accounts"]');
  await adp.waitForSelector('table.table tbody tr');
  await adp.fill('input[name=q]', 'amel');
  await adp.press('input[name=q]', 'Enter');
  await adp.waitForFunction(() => document.querySelectorAll('table.table tbody tr').length === 1);
  await adp.click('table.table tbody tr button.who');
  await adp.waitForSelector('.ud-head');
  ok(/amel/i.test(await adp.locator('.ud-head').textContent()) && await adp.locator('[data-act="adm-uop"][data-op="suspend"]').count() === 1, 'fiche d\'un compte avec ses actions');
  await adp.waitForTimeout(300);
  await shot(adp, '31-admin-account');
  await adp.keyboard.press('Escape');
  await adp.goto(ORIGIN + '/studioma/admin/#/emails/new');
  await adp.waitForSelector('#camp-form');
  await adp.fill('input[name=subject]', 'Hello {name}');
  await adp.fill('textarea[name=body]', 'Hi {name},\n\n<img src=x onerror=alert(1)> New features.');
  ok(await adp.waitForFunction(() => /Hi Amina/.test((document.querySelector('#camp-preview') || {}).textContent || ''), null, { timeout: 5000 }).then(() => true, () => false), 'aperçu de la campagne en direct');
  ok(await adp.locator('#camp-preview img').count() === 0, 'texte saisi affiché comme texte dans l\'aperçu');
  ok(/Send to \d+ (person|people)/.test(await adp.locator('#camp-send').textContent()), 'nombre de destinataires sur le bouton d\'envoi');
  await shot(adp, '32-admin-campaign');
  await adp.setViewportSize({ width: 390, height: 844 });
  await adp.goto(ORIGIN + '/studioma/admin/#/');
  await adp.waitForSelector('#ov-charts .chart');
  await noOverflow(adp, 'administration sur mobile');
  ok(admPixel === 0, 'aucun pixel Meta dans l\'administration');
  await admCtx.close();

  /* -------------------------------------------------------------- sécurité */
  step('Sécurité');
  ok(csp.length === 0, 'aucune violation de la politique de sécurité (CSP)', csp.slice(0, 3));
  ok(errors.length === 0, 'aucune erreur JavaScript ni fichier manquant', errors.slice(0, 6));
  await browser.close();
  console.log('\n' + (failed ? failed + ' échec(s)' : 'Tout est bon.') + '  Captures : ' + SHOTS);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
