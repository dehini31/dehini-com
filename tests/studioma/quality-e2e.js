/*
 * Qualité dans un navigateur, sur téléphone (Playwright / Chromium, iPhone 13 émulé et 320 px) :
 *   connexion → nouveau projet (fenêtre défilante, boutons visibles) → Studio → cartes créées → carte ouverte et modifiée
 *   → changement de colonne sans glisser-déposer → « Publish opportunity » → page de l'opportunité (contenu avant les
 *   actions du gestionnaire) → accueil : l'opportunité dans le fil → lien public copié, partage → page publique →
 *   menu latéral (ouverture, fermeture) → balayage de 14 écrans à 320 px et 390 px : pas de défilement horizontal, un seul
 *   h1, tous les boutons nommés, barre du haut utilisable ; aucune erreur JavaScript ni violation de la CSP.
 *   node tests/studioma/quality-e2e.js <adresse> <dossier>
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
const errors = [];
const csp = [];
const watch = (page, name) => {
  page.on('pageerror', (e) => errors.push(name + ' : ' + e.message));
  page.on('console', (m) => {
    if (/Content Security Policy/i.test(m.text())) csp.push(name + ' : ' + m.text());
    else if (m.type() === 'error' && !/Failed to load resource|GSI_LOGGER|net::ERR_/.test(m.text())) errors.push(name + ' : ' + m.text());
  });
  page.on('dialog', (d) => { errors.push(name + ' : boîte de dialogue « ' + d.message() + ' »'); d.dismiss(); });
};
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name + '.png') });
const toast = (page, re) => page.waitForFunction((src) => Array.from(document.querySelectorAll('.toast')).some((t) => new RegExp(src, 'i').test(t.textContent)), re.source, { timeout: 8000 }).then(() => true, () => false);
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
/** Contrôles d'un écran : défilement horizontal, nombre de h1, boutons visibles sans nom accessible. */
const audit = (page) => page.evaluate(() => {
  const visible = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !e.closest('[hidden]'); };
  const named = (e) => (e.getAttribute('aria-label') || e.getAttribute('title') || e.textContent || '').trim() !== '' || !!e.querySelector('img[alt]:not([alt=""])');
  const unnamed = Array.from(document.querySelectorAll('button, [role=button], a[href]')).filter((e) => visible(e) && !e.closest('.side') && !named(e)).map((e) => e.outerHTML.slice(0, 90));
  return { ovf: document.documentElement.scrollWidth - document.documentElement.clientWidth, h1: document.querySelectorAll('h1').length, unnamed };
});

(async () => {
  step('Préparation : un compte (API)');
  const res = await fetch(API + '?action=signup', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma' }, body: JSON.stringify({ name: 'Sara Mobile', email: 'sara@test.example', password: 'a-solid-password', accept: true }) });
  ok((await res.json()).user, 'compte créé');
  sql('DELETE FROM rate');

  const browser = await chromium.launch();
  const ctx = await browser.newContext(Object.assign({}, devices['iPhone 13']));
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
  const page = await ctx.newPage();
  watch(page, 'téléphone');

  step('1. Connexion sur téléphone');
  await page.goto(A + '#/login');
  await page.waitForSelector('.public-top a:has-text("Join Studioma")');
  ok(await overflow(page) <= 1 && await page.locator('.public-top a:has-text("Join Studioma")').isVisible(), 'en-tête des visiteurs entier (rien hors de l\'écran)');
  await page.fill('input[name=email]', 'sara@test.example');
  await page.fill('input[name=password]', 'a-solid-password');
  await page.click('form[data-form="login"] button.btn-primary');
  await page.waitForFunction(() => window.SM && SM.me);
  const create = await page.locator('.top-create').boundingBox();
  ok(create && create.width >= 36 && create.height >= 36 && await page.locator('.top-create').getAttribute('aria-label') === 'Create', 'bouton « Create » : cible tactile ≥ 36 px, nommé', create);

  step('2. Nouveau projet dans une fenêtre (téléphone)');
  await page.goto(A + '#/projects');
  await page.waitForSelector('[data-act="project-new"]');
  await page.locator('[data-act="project-new"]').first().click();
  await page.waitForSelector('form[data-form="project-save"]');
  await page.fill('form[data-form="project-save"] input[name=name]', 'Maison Lune');
  await page.fill('form[data-form="project-save"] input[name=studio_name]', 'Instagram');
  const dlg = await page.locator('.modal').boundingBox();
  const vp = page.viewportSize();
  ok(dlg && dlg.x >= 0 && dlg.x + dlg.width <= vp.width + 1, 'fenêtre dans la largeur de l\'écran', dlg);
  ok(await page.locator('.modal').getAttribute('aria-labelledby') && await page.locator('.modal h2').innerText() === 'New project', 'fenêtre nommée par son titre (lecteurs d\'écran)');
  const submit = page.locator('form[data-form="project-save"] button.btn-primary');
  await submit.scrollIntoViewIfNeeded();
  ok(await submit.isVisible() && await page.locator('form[data-form="project-save"] [data-act="modal-close"]').isVisible(), '« Create project » et « Cancel » atteignables (la fenêtre défile)');
  await shot(page, 'q01-new-project-mobile');
  await submit.click();
  ok(await toast(page, /Project created/), 'projet créé');
  await page.waitForSelector('.level');
  ok(/#\/studio\/\d+$/.test(page.url()), 'le Studio s\'ouvre');
  ok(await overflow(page) <= 1, 'tableau : pas de défilement de toute la page (chaque Studio défile dans son cadre)');

  step('3. Cartes : création, ouverture, modification, changement de colonne sans glisser');
  await page.locator('.bcol .bcol-add').first().click();
  for (const t of ['Reel: spring teaser', 'Carousel: skincare myths']) {
    const n = await page.locator('.kcard').count();
    await page.fill('.card-new textarea', t);
    await page.keyboard.press('Enter');
    await page.waitForFunction((n) => document.querySelectorAll('.kcard').length === n + 1, n);
  }
  await page.keyboard.press('Escape');
  ok(await page.locator('.kcard').count() === 2, 'deux cartes créées au clavier du téléphone');
  await page.locator('.kcard:has-text("Carousel: skincare myths")').click();
  await page.waitForSelector('.drawer .cp-head .cp-status');
  await page.waitForTimeout(450);   // fin de l'animation d'entrée du panneau
  const drawer = await page.locator('.drawer').boundingBox();
  ok(drawer && drawer.x >= -1 && drawer.x + drawer.width <= vp.width + 1, 'panneau de la carte dans l\'écran', drawer);
  ok(/^Card: Carousel/.test(await page.locator('.drawer').getAttribute('aria-label')), 'panneau nommé d\'après la carte');
  ok(await page.locator('.drawer [data-act="modal-close"], .drawer [aria-label="Close"]').first().isVisible(), 'bouton de fermeture visible');
  await page.locator('.drawer .ed-body').click();
  await page.keyboard.type('Five slides about skincare myths, with captions and sources.');
  ok(await page.locator('.drawer .ed-body').getAttribute('aria-label') === 'Description & script', 'éditeur nommé');
  await page.locator('.drawer .cp-status').click();
  await page.click('.menu .menu-item:has-text("Script in Progress")');
  await page.waitForFunction(() => /Script in Progress/.test(document.querySelector('.drawer .cp-status').textContent));
  ok(true, 'carte déplacée avec « Move » (colonne choisie dans un menu, sans glisser-déposer)');
  await shot(page, 'q02-card-mobile');

  step('4. Publier l\'opportunité depuis le téléphone');
  await page.click('.drawer .cp-sharepub');
  await page.click('.menu .menu-item:has-text("Publish opportunity")');
  await page.waitForSelector('form[data-form="pub-preview"]');
  // Fenêtre plus haute que l'écran : son haut doit rester atteignable en faisant défiler (bogue corrigé le 2026-11-22).
  const top = await page.evaluate(() => { const o = Array.from(document.querySelectorAll('.overlay')).pop(); o.scrollTop = 0; const m = o.querySelector('.modal'); return { top: Math.round(m.getBoundingClientRect().top), h: Math.round(m.getBoundingClientRect().height), vh: innerHeight }; });
  ok(top.h > top.vh && top.top >= 0, 'longue fenêtre de publication : son titre est atteignable (défilement depuis le haut)', top);
  await page.fill('form[data-form="pub-preview"] input[name=title]', 'Motion designer for a skincare carousel');
  await page.fill('form[data-form="pub-preview"] textarea[name=body]', 'Five animated slides explaining skincare myths, delivered as MP4 and stills.');
  await page.click('form[data-form="pub-preview"] label.choice-opt:has-text("Find a professional")');
  await page.fill('form[data-form="pub-preview"] input[name=budget_min]', '200');
  await page.fill('form[data-form="pub-preview"] input[name=budget_max]', '400');
  const next = page.locator('form[data-form="pub-preview"] button.btn-primary');
  await next.scrollIntoViewIfNeeded();
  ok(await next.isVisible(), 'bouton de la fenêtre de publication atteignable');
  await next.click();
  await page.waitForSelector('.pub-preview');
  await page.click('[data-act="pub-confirm"]');
  await page.waitForSelector('form[data-form="confirm-ok"]');
  await page.click('form[data-form="confirm-ok"] button[type=submit]');
  await page.waitForSelector('.pub-full .pub-title');
  ok(/Motion designer for a skincare carousel/.test(await page.locator('.pub-full .pub-title').innerText()), 'opportunité publiée, sa page s\'ouvre');
  const order = await page.evaluate(() => { const a = document.querySelector('.pub-full').getBoundingClientRect().top; const m = document.querySelector('.pub-manage'); return m ? m.getBoundingClientRect().top - a : 0; });
  ok(order > 0, 'téléphone : le contenu passe avant les actions du gestionnaire', order);
  await shot(page, 'q03-opportunity-mobile');

  step('5. L\'opportunité apparaît dans le fil d\'accueil');
  await page.goto(A + '#/');
  await page.waitForSelector('.post-pub.is-opp');
  const block = await page.locator('.post-pub.is-opp').first().innerText();
  ok(/Opportunity · Find a professional/i.test(block) && /Motion designer for a skincare carousel/.test(block) && /\$200/.test(block) && /View and send a proposal/.test(block), 'fil : type, titre, budget, appel à l\'action', block);
  await shot(page, 'q04-home-opportunity');
  await page.locator('.post-pub.is-opp').first().click();
  await page.waitForSelector('.pub-full .pub-title');
  ok(/#\/pub\/\d+$/.test(page.url()), 'touché depuis le fil : ouvre ses détails');

  step('6. Lien public et partage');
  await page.click('.pub-full [data-act="copy"]');
  const url = await page.evaluate(() => navigator.clipboard.readText());
  ok(/\/studioma\/opportunity\/\d+-motion-designer-for-a-skincare-carousel$/.test(url), 'lien public copié', url);
  await page.click('.pub-full [data-act="pub-share"]');
  await page.waitForSelector('.modal a[data-share=linkedin]');
  ok((await page.locator('.modal a[data-share=linkedin]').getAttribute('href')).endsWith(encodeURIComponent(url)) && await page.locator('.modal a[data-share=whatsapp]').count() === 1, 'partage : LinkedIn, WhatsApp… (rien n\'est publié tout seul)');
  await page.keyboard.press('Escape');
  const pub = await ctx.newPage();
  watch(pub, 'page publique');
  const r = await pub.goto(url);
  ok(r.status() === 200 && /Motion designer for a skincare carousel/.test(await pub.locator('h1').innerText()) && await overflow(pub) <= 1, 'page publique sur téléphone, sans débordement');
  await shot(pub, 'q05-public-opportunity');
  await pub.close();

  step('7. Menu latéral sur téléphone');
  await page.click('.top-menu');
  await page.waitForFunction(() => document.body.classList.contains('side-open'));
  ok(await page.locator('.side a.nav-link[href="#/achievements"]').isVisible(), 'menu ouvert : toutes les rubriques');
  await page.click('.side a.nav-link[href="#/achievements"]');
  await page.waitForSelector('.passport-card');
  ok(!(await page.evaluate(() => document.body.classList.contains('side-open'))), 'le menu se ferme après le choix');

  step('8. Balayage des écrans à 320 px et 390 px');
  const routes = ['#/', '#/discover', '#/community', '#/community?tab=opportunities', '#/projects', '#/studios', '#/work', '#/opportunities', '#/achievements', '#/achievements/settings', '#/refer', '#/notifications', '#/settings', '#/pricing'];
  for (const w of [320, 390]) {
    const c2 = await browser.newContext({ viewport: { width: w, height: 700 }, isMobile: true, hasTouch: true });
    await c2.addCookies((await ctx.cookies()).filter((c) => c.name.startsWith('stm')));
    const p2 = await c2.newPage();
    watch(p2, w + ' px');
    const bad = [];
    for (const rt of routes) {
      await p2.goto(A + rt);
      await p2.waitForTimeout(900);
      const a = await audit(p2);
      if (a.ovf > 1 || a.h1 !== 1 || a.unnamed.length) bad.push({ rt, ...a });
    }
    ok(bad.length === 0, `${w} px : 14 écrans sans défilement horizontal, un h1 chacun, aucun bouton sans nom`, bad);
    await c2.close();
  }
  const c3 = await browser.newContext({ viewport: { width: 320, height: 640 }, isMobile: true });
  const p3 = await c3.newPage();
  for (const u of [ORIGIN + '/studioma/', ORIGIN + '/studioma/pricing/', ORIGIN + '/studioma/opportunities/', A + '#/login', A + '#/signup', A + '#/pricing']) {
    await p3.goto(u);
    await p3.waitForTimeout(u.includes('/app/') ? 1000 : 400);
    ok(await overflow(p3) <= 1, '320 px, visiteur : ' + u.replace(ORIGIN, ''));
  }
  await c3.close();

  ok(errors.length === 0, 'aucune erreur JavaScript', errors);
  ok(csp.length === 0, 'aucune violation de la Content-Security-Policy', csp);
  await browser.close();
  console.log(failed ? `\n${failed} échec(s)` : '\nTous les tests de qualité (navigateur) sont passés.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
