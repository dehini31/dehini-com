/*
 * Offre de lancement dans un navigateur (Playwright / Chromium), faux hébergement en mode PROMO=1 :
 *   page d'accueil (offre, confiance, lien ?ref= compté) → page des prix (plus de « Coming soon », « Get 30 Days
 *   Free » et « No credit card required » sur Solo et Team, mensuel / annuel, Most Popular, aucun formulaire de
 *   carte) → inscription sans paiement → bannière de l'essai (jours restants, date) → Refer & Earn (lien, copie,
 *   partages, statistiques, progression, messages, historique) → 10 amis vérifiés : félicitations et dates →
 *   essai terminé → compte existant qui réclame l'essai → Plan & billing → administration → mobile.
 *   node tests/studioma/promo-e2e.js <adresse> <dossier>
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
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 400) : '')); } };
const step = (t) => console.log('\n' + t);
const sql = (q) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, q]);
const one = (q) => JSON.parse(execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); echo json_encode($p->query($argv[2])->fetchColumn());', DB, q]).toString());
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
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
const shot = (page, name) => page.screenshot({ path: path.join(SHOTS, name + '.png') });
const noOverflow = async (page, label) => ok(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'pas de défilement horizontal : ' + label);
const toast = (page, re) => page.waitForFunction((src) => Array.from(document.querySelectorAll('.toast')).some((t) => new RegExp(src, 'i').test(t.textContent)), re.source, { timeout: 8000 }).then(() => true, () => false);
const noCard = (page) => page.evaluate(() => !document.querySelector('input[autocomplete^="cc-"], input[name*="card" i], iframe[src*="paypal"], iframe[src*="checkout"]'));

/** Client d'API (amis invités) : mêmes en-têtes qu'un navigateur. */
class Client {
  constructor() { this.jar = {}; this.csrf = ''; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  async post(action, body) {
    const res = await fetch(API + '?action=' + action, { method: 'POST', body: JSON.stringify(body || {}), headers: { 'User-Agent': 'Mozilla/5.0 Chrome/130', 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf, Cookie: this.cookies() } });
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => { const [k, ...v] = c.split(';')[0].split('='); if (v.join('=')) this.jar[k] = v.join('='); });
    const d = await res.json();
    if (d.csrf) this.csrf = d.csrf;
    return d;
  }
}
let n = 0;
const friend = async (code) => {
  const c = new Client();
  await c.post('ref_click', { code });
  sql('DELETE FROM rate');
  const mail = `pal${++n}@friends.example`;
  await c.post('signup', { name: 'Pal ' + n, email: mail, password: 'a-solid-password', accept: true });
  const m = mails().filter((x) => x.to === mail && /confirm\/[a-f0-9]{48}/.test(x.text)).pop();
  await c.post('email_confirm', { token: m.text.match(/confirm\/([a-f0-9]{48})/)[1] });
};

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: ORIGIN });
  const page = await ctx.newPage();
  watch(page, 'visiteur');
  let paid = 0;
  page.on('request', (r) => { if (/\/pay\/|paypal|paddle|checkout/i.test(r.url())) paid++; });

  step('Page des prix (visiteur)');
  await page.goto(A + '#/pricing');
  await page.waitForSelector('.plan-card');
  const text = await page.locator('.pricing').innerText();
  ok(!/Coming soon/i.test(text), 'plus aucun bouton « Coming soon »');
  ok(await page.locator('.plan-card a:has-text("Get 30 Days Free")').count() === 2 && await page.locator('.plan-card .plan-nocard').count() === 2, 'Solo et Team : « Get 30 Days Free » et « No credit card required »');
  ok(await page.locator('.plan-card a:has-text("Get Started Free")').count() === 1, 'Free : « Get Started Free » conservé');
  ok(/Special Launch Offer: Enjoy 30 days of full access to all Studioma features — completely free\. No credit card required\./.test(text) && !/Online payment for Solo and Team opens soon/.test(text), 'nouvelle annonce de lancement (l\'ancienne a disparu)');
  ok(/Paid subscriptions aren't available yet/.test(text) && /not a subscription/.test(text), 'il est dit clairement que les abonnements payants ne sont pas ouverts');
  ok(/Invite 10 Friends\. Get 1 Year Free\./.test(text) && await page.locator('[data-act="promo-invite"]:has-text("Invite & Earn")').count() === 1, 'section « Invite 10 Friends. Get 1 Year Free. » et « Invite & Earn »');
  ok(await page.locator('.plan-card.is-popular .plan-badge:has-text("Most Popular")').count() === 1 && /\$12/.test(await page.locator('.plan-card.is-popular .plan-price').innerText()), 'Solo : Most Popular, 12 $ / mois');
  await page.click('label:has-text("Yearly")');
  ok(/\$120/.test(await page.locator('.plan-card.is-popular .plan-price').innerText()) && /\$290/.test(await page.locator('.plan-card').nth(2).locator('.plan-price').innerText()), 'mensuel / annuel : 120 $ et 290 $');
  ok(await noCard(page), 'aucun champ de carte ni module de paiement');
  await shot(page, 'r01-pricing');

  step('Page d\'accueil et lien de parrainage');
  const amelApi = new Client();
  sql('DELETE FROM rate');
  await amelApi.post('signup', { name: 'Amel Ref', email: 'amel.ref@test.example', password: 'a-solid-password', accept: true });
  const code = (await (await fetch(API + '?action=promo', { headers: { Cookie: amelApi.cookies() } })).json()).code;
  await page.goto(ORIGIN + '/studioma/?ref=' + code);
  await page.waitForSelector('.launch h2');
  ok(/30 Days Free\. All Features Unlocked\./.test(await page.locator('.launch').first().innerText()) && await page.locator('.launch a:has-text("Start My Free 30 Days")').count() === 1, 'accueil : « 30 Days Free. All Features Unlocked. » et « Start My Free 30 Days »');
  const trust = await page.locator('.trust').innerText();
  ok(['No credit card required', 'No payment required', 'No automatic billing', 'No hidden charges', 'remain saved'].every((x) => trust.includes(x)), 'messages de confiance');
  ok(await page.locator('.launch-ref a:has-text("Start Inviting")').count() === 1, 'section « Invite 10 Friends. Get 1 Year Free. » et « Start Inviting »');
  await page.waitForFunction(() => !location.search.includes('ref='));
  ok(one(`SELECT COUNT(*) FROM ref_clicks WHERE uid = (SELECT uid FROM ref_codes WHERE code = '${code}')`) === 1, 'le clic sur le lien est compté (et le code retiré de l\'adresse)');
  await shot(page, 'r02-landing');

  step('Inscription depuis la page des prix : sans paiement, essai actif');
  await page.goto(A + '#/pricing');
  await page.locator('.plan-card').nth(2).locator('a:has-text("Get 30 Days Free")').click();
  await page.waitForSelector('form[data-form="signup"]');
  ok(/30 days of full access included/.test(await page.locator('.promo-note').innerText()), 'inscription : « 30 days of full access included — No credit card required »');
  await page.fill('form[data-form="signup"] input[name=name]', 'Nadia Trial');
  await page.fill('form[data-form="signup"] input[name=email]', 'nadia.trial@test.example');
  await page.fill('form[data-form="signup"] input[name=password]', 'a-solid-password');
  await page.click('form[data-form="signup"] button.btn-primary');
  await page.waitForFunction(() => window.SM && SM.me);
  await page.goto(A + '#/');
  await page.waitForSelector('.promo-banner');
  const banner = await page.locator('.promo-banner').innerText();
  ok(/Your 30-Day Full Access Trial Is Active/.test(banner) && /30 days left/.test(banner) && /ends on/.test(banner) && /No credit card required/.test(banner), 'bannière : essai actif, jours restants, date de fin', banner);
  ok(await page.locator('.promo-banner a:has-text("Invite 10 Friends & Get 1 Year Free")').count() === 1, 'bannière : « Invite 10 Friends & Get 1 Year Free »');
  ok(paid === 0 && await noCard(page), 'aucune page ni requête de paiement pendant tout le parcours');
  ok(one("SELECT COUNT(*) FROM referrals r JOIN users u ON u.id = r.referred_uid WHERE u.email = 'nadia.trial@test.example'") === 1, 'inscription attribuée au lien ouvert sur l\'accueil');
  await shot(page, 'r03-home-trial');
  await page.goto(A + '#/pricing');
  await page.waitForSelector('.plan-card');
  ok(await page.locator('.plan-card button:has-text("Free trial active — 30 days left")').count() === 2, 'prix : « Free trial active — 30 days left » sur Solo et Team (pas de nouvel essai)');
  await page.goto(A + '#/settings/billing');
  await page.waitForSelector('.current-plan');
  const plan = await page.locator('.current-plan').innerText();
  ok(/Team/.test(plan) && /Free trial/.test(plan) && /no credit card, no payment, no automatic billing/.test(plan), 'Plan & billing : Team, essai gratuit, sans carte ni facturation automatique');

  step('Refer & Earn');
  await page.goto(A + '#/refer');
  await page.waitForSelector('#ref-url');
  const url = await page.inputValue('#ref-url');
  ok(/\/studioma\/\?ref=[a-z2-9]{8}$/.test(url), 'lien personnel affiché', url);
  ok(/Invite 10 Friends\. Unlock 1 Year Free\./.test(await page.locator('h1').innerText()), 'titre « Invite 10 Friends. Unlock 1 Year Free. »');
  await page.click('.ref-link [data-act="copy"]');
  ok(await page.evaluate(() => navigator.clipboard.readText()) === url, 'copier le lien : dans le presse-papiers');
  const hrefs = await page.$$eval('.ref-share a', (as) => as.map((a) => a.href));
  ok(hrefs.length === 4 && hrefs.every((h) => h.includes(encodeURIComponent(url)) || h.includes(url)) && /^https:\/\/wa\.me\//.test(hrefs[0]) && /facebook\.com\/sharer/.test(hrefs[1]) && /linkedin\.com\/sharing/.test(hrefs[2]) && /^mailto:/.test(hrefs[3]),
    'partages WhatsApp, Facebook, LinkedIn, email avec le vrai lien', hrefs);
  ok(await page.locator('[data-act="ref-native"]').count() === 1 && /no credit card required/.test(await page.locator('.ref-msg').textContent()), 'partage natif et message prêt à partager');
  const stats = await page.locator('.ref-stats').innerText();
  ok(/Link Clicks/.test(stats) && /Unique Visitors/.test(stats) && /Registrations/.test(stats) && /Verified Referrals/.test(stats), 'quatre statistiques');
  ok(/0 \/ 10/.test(await page.locator('.ref-progress').innerText()) && /Your journey starts here!/.test(await page.locator('.ref-msg-line').innerText()), '0 / 10 et message de départ');
  await shot(page, 'r04-refer-empty');
  const myCode = url.split('ref=')[1];
  for (let i = 0; i < 7; i++) await friend(myCode);
  await page.reload();
  await page.waitForSelector('.ref-progress');
  ok(/7 \/ 10/.test(await page.locator('.ref-progress').innerText()) && /Progress: 70%/.test(await page.locator('.ref-progress').innerText()) && /Only 3 more verified friends/.test(await page.locator('.ref-msg-line').innerText()), '7 / 10, 70 %, « Only 3 more verified friends »');
  ok(/You're getting closer/.test(await page.locator('.ref-msg-line').innerText()) && await page.locator('.list-row:has-text("Friend #7")').count() === 1 && !(await page.locator('#view').innerText()).includes('@friends.example'), 'message, historique sans adresse');
  for (let i = 0; i < 2; i++) await friend(myCode);
  await page.reload();
  await page.waitForSelector('.ref-msg-line');
  ok(/Just one more verified friend/.test(await page.locator('.ref-msg-line').innerText()), '9 / 10 : « Just one more verified friend »');
  await friend(myCode);
  await page.reload();
  await page.waitForSelector('.congrats');
  const congrats = await page.locator('.congrats').innerText();
  ok(/Congratulations!/.test(congrats) && /You've invited 10 friends to Studioma!/.test(congrats) && /1 FULL YEAR of Studioma Pro/.test(congrats) && /Activation date/.test(congrats) && /Expiration date/.test(congrats) && /Starts after your trial/.test(congrats),
    'félicitations : message, dates, statut (après l\'essai)', congrats);
  ok(await page.locator('.congrats a:has-text("Explore Your Pro Features")').count() === 1 && /10 \/ 10/.test(await page.locator('.ref-progress').innerText()), '« Explore Your Pro Features », 10 / 10');
  await shot(page, 'r05-refer-unlocked');
  await page.goto(A + '#/');
  await page.waitForSelector('.promo-banner');
  ok(/Your free year starts on/.test(await page.locator('.promo-banner').innerText()), 'bannière : l\'essai, puis l\'année offerte annoncée');
  const uid = one("SELECT id FROM users WHERE email = 'nadia.trial@test.example'");
  sql(`UPDATE subscriptions SET period_end = strftime('%s','now') - 60 WHERE uid = ${uid} AND status = 'trialing'; UPDATE promo_grants SET ends = strftime('%s','now') - 60 WHERE uid = ${uid} AND kind = 'trial';
    UPDATE subscriptions SET period_start = strftime('%s','now') - 30 WHERE uid = ${uid} AND provider = 'promo' AND status = 'active'; UPDATE promo_grants SET starts = strftime('%s','now') - 30 WHERE uid = ${uid} AND kind = 'referral';`);
  await page.reload();
  await page.waitForSelector('.promo-banner.is-reward');
  const rb = await page.locator('.promo-banner').innerText();
  ok(/1 Year Pro Access Unlocked/.test(rb) && /left/.test(rb) && !/trial/i.test(rb), 'année offerte active : sa bannière seule, sans compte à rebours de l\'essai', rb);

  step('Essai terminé, compte existant qui réclame l\'essai');
  const ctx2 = await browser.newContext({ viewport: { width: 1280, height: 860 } });
  const p2 = await ctx2.newPage();
  watch(p2, 'compte existant');
  sql('DELETE FROM rate');
  const old = new Client();
  await old.post('signup', { name: 'Old Member', email: 'old.member@test.example', password: 'a-solid-password', accept: true });
  const oldId = one("SELECT id FROM users WHERE email = 'old.member@test.example'");
  sql(`UPDATE subscriptions SET period_end = strftime('%s','now') - 60 WHERE uid = ${oldId}; UPDATE promo_grants SET ends = strftime('%s','now') - 60 WHERE uid = ${oldId};`);
  await p2.goto(A + '#/login');
  await p2.fill('input[name=email]', 'old.member@test.example');
  await p2.fill('input[name=password]', 'a-solid-password');
  await p2.click('form[data-form="login"] button.btn-primary');
  await p2.waitForSelector('.promo-banner.is-ended');
  ok(/Your 30-day full-access trial has ended/.test(await p2.locator('.promo-banner').innerText()) && await p2.locator('.promo-banner a:has-text("Invite Friends")').count() === 1, 'essai terminé : « continue with the Free plan », « Invite Friends »');
  sql(`DELETE FROM subscriptions WHERE uid = ${oldId}; DELETE FROM promo_grants WHERE uid = ${oldId};`);   // compte d'avant l'offre
  await p2.reload();
  await p2.waitForSelector('.promo-banner [data-act="promo-claim"]');
  await p2.click('.promo-banner [data-act="promo-claim"]');
  ok(await toast(p2, /full-access trial is active/), 'compte existant : essai réclamé en un clic');
  await p2.waitForSelector('.promo-banner:has-text("Trial Is Active")');
  await p2.goto(A + '#/pricing');
  await p2.waitForSelector('.plan-card');
  ok(await p2.locator('.plan-card [data-act="promo-claim"]').count() === 0, 'la page des prix ne propose plus de nouvel essai');
  await ctx2.close();

  step('Administration');
  const actx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  const ap = await actx.newPage();
  watch(ap, 'admin');
  await ap.goto(ORIGIN + '/admin/');
  await ap.fill('input[type=password]', 'test-owner-pass');
  await ap.press('input[type=password]', 'Enter');
  await ap.waitForTimeout(800);
  await ap.goto(ORIGIN + '/studioma/admin/#/promo');
  await ap.waitForSelector('h1:has-text("Launch Promotion Analytics")');
  const at = await ap.locator('#view').innerText();
  ok(/Trial activations/.test(at) && /Unique visitors/.test(at) && /Verified eligible referrals/.test(at) && /Rewards granted/.test(at) && /Suspicious activity/.test(at), 'tableau « Launch Promotion Analytics »');
  await shot(ap, 'r06-admin-promo');
  await actx.close();

  step('Mobile');
  const mctx = await browser.newContext(Object.assign({}, devices['iPhone 13']));
  const mp = await mctx.newPage();
  watch(mp, 'mobile');
  await mp.goto(A + '#/pricing');
  await mp.waitForSelector('.plan-card');
  await noOverflow(mp, 'prix sur mobile');
  await mp.goto(ORIGIN + '/studioma/');
  await mp.waitForSelector('.launch');
  await noOverflow(mp, 'accueil sur mobile');
  await shot(mp, 'r07-mobile-landing');
  await mp.goto(A + '#/login');
  await mp.fill('input[name=email]', 'nadia.trial@test.example');
  await mp.fill('input[name=password]', 'a-solid-password');
  await mp.click('form[data-form="login"] button.btn-primary');
  await mp.waitForFunction(() => window.SM && SM.me);
  await mp.goto(A + '#/refer');
  await mp.waitForSelector('.ref-stats');
  await noOverflow(mp, 'Refer & Earn sur mobile');
  await shot(mp, 'r08-mobile-refer');
  await mp.goto(A + '#/');
  await mp.waitForSelector('.promo-banner');
  await noOverflow(mp, 'bannière sur mobile');
  await mctx.close();

  step('Sécurité');
  ok(csp.length === 0, 'aucune violation de la politique de sécurité (CSP)', csp.slice(0, 3));
  ok(errors.length === 0, 'aucune erreur JavaScript ni fichier manquant', errors.slice(0, 6));
  await browser.close();
  console.log('\n' + (failed ? failed + ' échec(s)' : 'Tout est bon.') + '  Captures : ' + SHOTS);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
