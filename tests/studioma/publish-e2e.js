/*
 * « Share & publish » dans un navigateur (Playwright / Chromium), sur le faux hébergement de run.sh :
 *   bouton et menu d'une carte selon son étape → partager la carte seule (fenêtre de partage, ce que voient les
 *   invités) → la carte ouverte par un invité sans compte (rien d'autre du Studio) → publier une opportunité
 *   (formulaire, aperçu, confirmation) → pastilles sur la carte et le tableau → proposition d'un professionnel →
 *   réponse de l'auteur → publier une réalisation (couverture choisie) → Communauté, profil, fil → mobile →
 *   aucune erreur JavaScript, aucune violation de la CSP.
 *   node tests/studioma/publish-e2e.js <adresse> <dossier>
 */
const { chromium, devices } = require('playwright');
const fs = require('fs');
const path = require('path');

const [ORIGIN, DIR] = process.argv.slice(2);
const A = ORIGIN + '/studioma/app/';
const SHOTS = path.join(DIR, 'shots');
fs.mkdirSync(SHOTS, { recursive: true });
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 400) : '')); } };
const step = (t) => console.log('\n' + t);
const errors = [];
const csp = [];
const watch = (page, name) => {
  page.on('pageerror', (e) => errors.push(name + ' : ' + e.message));
  page.on('console', (m) => {
    if (/Content Security Policy/i.test(m.text())) csp.push(name + ' : ' + m.text());
    else if (m.type() === 'error' && !/Failed to load resource|fonts\.g|net::ERR_/.test(m.text() + ' ' + (m.location().url || ''))) errors.push(name + ' : ' + m.text());
  });
  page.on('response', (r) => { const u = r.url(); if (r.status() >= 400 && u.startsWith(ORIGIN) && !/api\.php|\/(lib|config)/.test(u)) errors.push(name + ' : ' + r.status() + ' ' + u); });
  page.on('dialog', (d) => { errors.push(name + ' : boîte de dialogue « ' + d.message() + ' »'); d.dismiss(); });
};
const call = (page, action, body, params) => page.evaluate(async ({ action, body, params }) => {
  try { return await SM.api(action, { body, params }); } catch (e) { return { error: e.code, message: e.message, status: e.status }; }
}, { action, body, params });
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name + '.png') });
const noOverflow = async (page, label) => ok(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'pas de défilement horizontal : ' + label);
const toast = (page, re) => page.waitForFunction((src) => Array.from(document.querySelectorAll('.toast')).some((t) => new RegExp(src, 'i').test(t.textContent)), re.source, { timeout: 8000 }).then(() => true, () => false);
const confirmOk = async (page) => { await page.waitForSelector('form[data-form="confirm-ok"]'); await page.click('form[data-form="confirm-ok"] button[type=submit]'); };
const signup = async (page, name, email) => {
  await page.goto(A + '#/signup', { waitUntil: 'load' });
  await page.waitForSelector('form[data-form="signup"]');
  await page.fill('input[name=name]', name);
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'a-solid-password');
  await page.click('form[data-form="signup"] button.btn-primary');
  await page.waitForFunction(() => window.SM && SM.me);
};
/** Image PNG envoyée en pièce jointe d'une carte (dessinée dans la page). */
const attachImage = (page, cardId, name, color) => page.evaluate(async ({ cardId, name, color }) => {
  const c = document.createElement('canvas');
  c.width = 800; c.height = 500;
  const g = c.getContext('2d');
  g.fillStyle = color; g.fillRect(0, 0, 800, 500);
  g.fillStyle = '#fff'; g.font = 'bold 64px sans-serif'; g.fillText(name, 60, 270);
  const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
  return SM.upload('card', new File([blob], name + '.png', { type: 'image/png' }), { card_id: cardId });
}, { cardId, name, color });

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const page = await ctx.newPage();
  watch(page, 'amel');

  step('Préparation : compte, Studio, une carte en cours et une carte terminée avec des images');
  await signup(page, 'Amel Haddad', 'amel@test.example');
  let r = await call(page, 'project_save', { name: 'Vélora', template: 'content', studio_name: 'Social 2026' });
  const sid = r.studio_id;
  const b = await call(page, 'board', undefined, { studio: sid });
  const ideas = b.cols.find((c) => c.name.includes('Ideas'));
  const doneCol = b.cols.find((c) => c.kind === 'done');
  const cOpen = (await call(page, 'card_create', { col_id: ideas.id, title: 'Reel: spring teaser' })).card.id;
  await call(page, 'card_create', { col_id: ideas.id, title: 'Carousel: care guide' });
  const cDone = (await call(page, 'card_create', { col_id: doneCol.id, title: 'Launch film' })).card.id;
  await call(page, 'card_save', { id: cOpen, body: 'PRIVATE-BRIEF do not show' });
  await attachImage(page, cOpen, 'Moodboard', '#5b4bff');
  await attachImage(page, cDone, 'Still', '#ff7a59');
  await attachImage(page, cDone, 'Poster', '#2bb673');
  ok(true, 'données prêtes');

  step('Bouton « Share & publish » d\'une carte en cours');
  await page.goto(A + `#/studio/${sid}/card/${cOpen}`);
  await page.waitForSelector('.cp-sharepub');
  await page.click('.cp-sharepub');
  await page.waitForSelector('.menu');
  const menuText = await page.locator('.menu').innerText();
  ok(/Share card/.test(menuText) && /Publish opportunity/.test(menuText) && !/Publish achievement/.test(menuText), 'carte en cours : partager, publier une opportunité (pas de réalisation)', menuText);
  await page.click('.menu .menu-item:has-text("Share card")');
  await page.waitForSelector('.modal h2:has-text("Share this card")');
  ok(await page.locator('.share-tabs').count() === 0, 'fenêtre de partage de la carte : sans onglet « Team »');
  await page.click('[data-act="glink-create"][data-kind="public"]');
  await page.waitForSelector('.link-card[data-link="public"]');
  await page.waitForSelector('.card-sees');
  ok(await page.locator('[data-act="glink-levels"]').count() === 0, 'pas de choix de niveaux pour une carte');
  await page.locator('.card-sees input[data-change="gsee-file"]').first().check();
  ok(await toast(page, /Guests can open this attachment/), 'pièce jointe montrée aux invités');
  const link = await page.locator('.link-card[data-link="public"] [data-act="copy"]').getAttribute('data-text');
  ok(/\/studioma\/s\/[A-Za-z0-9_-]{32}$/.test(link), 'lien de la carte', link);
  await shot(page, 'p01-card-share');
  await page.keyboard.press('Escape');
  await page.waitForSelector('.modal h2:has-text("Share this card")', { state: 'detached' });
  await page.waitForSelector('.pub-pill.pub-shared');
  ok(/1 link/.test(await page.locator('.pub-pill.pub-shared').innerText()), 'la carte indique son partage');

  step('Un invité sans compte ouvre le lien de la carte');
  const gctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
  const gp = await gctx.newPage();
  watch(gp, 'invité');
  await gp.goto(link);
  await gp.waitForSelector('.drawer .cp-title');
  ok(/Reel: spring teaser/.test(await gp.locator('.drawer .cp-title').innerText()), 'la carte s\'ouvre directement');
  ok(/Shared card/.test(await gp.locator('.guest-head').innerText()) && /Only this card is shared with you/.test(await gp.locator('.guest-note').innerText()), 'en-tête : carte partagée, rien d\'autre');
  ok(await gp.locator('.drawer .file-img').count() === 1 && await gp.locator('.drawer .cp-sharepub').count() === 0, 'la pièce jointe choisie, sans bouton de publication');
  await gp.keyboard.press('Escape');
  await gp.waitForSelector('.kcard');
  ok(await gp.locator('.kcard').count() === 1 && !(await gp.locator('#view').innerText()).includes('Carousel'), 'le tableau de l\'invité ne montre que cette carte');
  await shot(gp, 'p02-guest-card');
  await gctx.close();

  step('Publier la carte comme opportunité : formulaire, aperçu, confirmation');
  await page.click('.cp-sharepub');
  await page.click('.menu .menu-item:has-text("Publish opportunity")');
  await page.waitForSelector('form[data-form="pub-preview"]');
  ok(await page.locator('form[data-form="pub-preview"] textarea[name=body]').inputValue() === '', 'la description de la carte n\'est pas reprise d\'office');
  await page.fill('form[data-form="pub-preview"] input[name=title]', 'Video editor for a spring teaser');
  await page.fill('form[data-form="pub-preview"] textarea[name=body]', 'We need a vertical teaser edited from our rushes, with captions and licensed music.');
  await page.click('form[data-form="pub-preview"] label.choice-opt:has-text("Request proposals")');
  await page.fill('form[data-form="pub-preview"] input[name=budget_min]', '300');
  await page.fill('form[data-form="pub-preview"] input[name=budget_max]', '600');
  await page.selectOption('form[data-form="pub-preview"] select[name=currency]', 'EUR');
  await page.locator('fieldset input[name="files[]"]').first().check();
  await shot(page, 'p03-publish-form');
  await page.click('form[data-form="pub-preview"] button.btn-primary');
  await page.waitForSelector('.pub-preview');
  const prev = await page.locator('.pub-preview').innerText();
  ok(/Video editor for a spring teaser/.test(prev) && /€300\s*–\s*€600/.test(prev) && /Request proposals/i.test(prev) && !/PRIVATE-BRIEF/.test(prev), 'aperçu : titre, budget, type, rien de privé', prev.slice(0, 200));
  await shot(page, 'p04-publish-preview');
  await page.click('[data-act="pub-confirm"]');
  await confirmOk(page);
  await page.waitForSelector('.pub-full .pub-title');
  ok(/#\/pub\/\d+/.test(page.url()) && /Video editor/.test(await page.locator('.pub-title').innerText()), 'publiée : page de l\'opportunité');
  const oppId = +page.url().split('/pub/')[1];
  ok(await page.locator('.pub-manage').count() === 1 && await page.locator('.pub-media img').count() === 1, 'barre de gestion et pièce jointe publiée');
  await shot(page, 'p05-opportunity');
  await page.goto(A + `#/studio/${sid}/card/${cOpen}`);
  await page.waitForSelector('.pub-pill.pub-opp');
  ok(/Open Opportunity/.test(await page.locator('.pub-pill.pub-opp').innerText()), 'la carte affiche « Open Opportunity »');
  await page.keyboard.press('Escape');
  await page.waitForSelector('.kcard .pub-badge.pub-opp');
  ok(await page.locator(`.kcard[data-card="${cOpen}"] .pub-badge.pub-shared`).count() === 1, 'pastilles sur le tableau (opportunité, partagée)');

  step('Un professionnel envoie une proposition');
  const kctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
  const kp = await kctx.newPage();
  watch(kp, 'karim');
  await signup(kp, 'Karim Saidi', 'karim@test.example');
  await kp.goto(A + '#/community/opportunities');
  await kp.waitForSelector(`a[href="#/pub/${oppId}"]`);
  await kp.click(`a[href="#/pub/${oppId}"]`);
  await kp.waitForSelector('form[data-form="prop-save"]');
  await kp.fill('textarea[name=message]', 'I edit vertical teasers every week: rhythm, captions and licensed music.');
  await kp.fill('input[name=price]', '450');
  await kp.fill('input[name=timeline]', '5 days');
  await kp.click('form[data-form="prop-save"] button.btn-primary');
  ok(await toast(kp, /Proposal sent/), 'proposition envoyée');
  await kp.waitForSelector('.chip.prop-new');
  ok(/€450/.test(await kp.locator('#view').innerText()), 'le professionnel suit sa proposition (statut, prix)');
  await shot(kp, 'p06-proposal');

  step('L\'auteur répond');
  await page.goto(A + `#/pub/${oppId}`);
  await page.waitForSelector('.prop-row');
  ok(/Karim Saidi/.test(await page.locator('.prop-row').innerText()), 'la proposition apparaît chez l\'auteur');
  await page.selectOption('.prop-row select[data-change="prop-status"]', 'accepted');
  ok(await toast(page, /professional is notified/), 'proposition acceptée');
  await page.waitForSelector('[data-act="prop-invite"]');
  ok(/never gives access/.test(await page.locator('#view').innerText()), 'rappel : accepter ne donne pas accès à la carte');
  await shot(page, 'p07-proposals');
  await kp.goto(A + '#/community/proposals');
  await kp.waitForSelector('.chip.prop-accepted');
  ok(true, 'le professionnel voit « Accepted » dans ses propositions');

  step('Publier une carte terminée comme réalisation');
  await page.goto(A + `#/studio/${sid}/card/${cDone}?publish=1`);
  await page.waitForSelector('.sp-choice');
  ok(/Publish achievement/.test(await page.locator('.sp-choices').innerText()) && !/opportunity/i.test(await page.locator('.sp-choices').innerText()), 'carte terminée : réalisation (pas d\'opportunité)');
  await page.click('.sp-choice:has-text("Publish achievement")');
  await page.waitForSelector('form[data-form="pub-preview"]');
  await page.fill('form[data-form="pub-preview"] textarea[name=body]', 'A 45-second launch film, shot in two days.');
  await page.locator('.pick input[type=radio]').nth(1).check();
  await page.locator('fieldset input[name="files[]"]').nth(1).check();
  await page.click('form[data-form="pub-preview"] button.btn-primary');
  await page.waitForSelector('.pub-preview .pub-hero');
  ok(true, 'aperçu avec la couverture choisie');
  await page.click('[data-act="pub-confirm"]');
  await confirmOk(page);
  await page.waitForSelector('.pub-full .pub-hero');
  const achId = +page.url().split('/pub/')[1];
  ok(await page.locator('.pub-full [data-act="post-like"]').count() === 1 && await page.locator('.pub-media img').count() === 1, 'réalisation publiée : couverture, autre visuel, J\'aime');
  await shot(page, 'p08-achievement');
  await kp.goto(A + `#/pub/${achId}`);
  await kp.waitForSelector('.pub-full [data-act="post-like"]');
  await kp.click('.pub-full [data-act="post-like"]');
  await kp.waitForFunction(() => document.querySelector('.pub-full [data-act="post-like"] span').textContent === '1');
  ok(true, 'un membre aime la réalisation');
  await kp.goto(A + '#/community/achievements');
  await kp.waitForSelector(`.ach-card[href="#/pub/${achId}"]`);
  ok(true, 'Communauté : la réalisation est listée');
  await kp.goto(A + '#/discover');
  await kp.waitForSelector(`.post-pub[href="#/pub/${achId}"]`);
  ok(true, 'le fil montre la réalisation');
  await page.goto(A + '#/profile');
  await page.waitForSelector(`.ach-card[href="#/pub/${achId}"]`);
  ok(true, 'la réalisation apparaît sur le profil');
  ok(!(await kp.locator('#view').innerText()).includes('PRIVATE-BRIEF'), 'rien de privé côté communauté');

  step('Mobile');
  const mctx = await browser.newContext(Object.assign({}, devices['iPhone 13']));
  const mp = await mctx.newPage();
  watch(mp, 'mobile');
  await mp.goto(A + '#/login');
  await mp.waitForSelector('form[data-form="login"]');
  await mp.fill('input[name=email]', 'amel@test.example');
  await mp.fill('input[name=password]', 'a-solid-password');
  await mp.click('form[data-form="login"] button.btn-primary');
  await mp.waitForFunction(() => window.SM && SM.me);
  await mp.goto(A + '#/community/opportunities');
  await mp.waitForSelector(`a[href="#/pub/${oppId}"]`);
  await noOverflow(mp, 'Communauté sur mobile');
  await shot(mp, 'p09-mobile-community');
  await mp.goto(A + `#/pub/${oppId}`);
  await mp.waitForSelector('.prop-row');
  await noOverflow(mp, 'page d\'une opportunité sur mobile');
  await mp.goto(A + `#/studio/${sid}/card/${cOpen}?publish=1`);
  await mp.waitForSelector('.sp-choice');
  await noOverflow(mp, 'Share & publish sur mobile');
  await mp.click('.sp-choice:has-text("Share card")');
  await mp.waitForSelector('.card-sees');
  await noOverflow(mp, 'partage de la carte sur mobile');
  await shot(mp, 'p10-mobile-share');
  await mctx.close();
  await kctx.close();

  step('Sécurité');
  ok(csp.length === 0, 'aucune violation de la politique de sécurité (CSP)', csp.slice(0, 3));
  ok(errors.length === 0, 'aucune erreur JavaScript ni fichier manquant', errors.slice(0, 6));
  await browser.close();
  console.log('\n' + (failed ? failed + ' échec(s)' : 'Tout est bon.') + '  Captures : ' + SHOTS);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
