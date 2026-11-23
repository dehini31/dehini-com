/*
 * Pages légales de Studioma (p.php : /studioma/terms/, /privacy/, /refunds/), sur le faux hébergement de run.sh :
 *   réponse 200 indexable, adresse canonique, titre, un seul h1, sommaire dont chaque ancre existe, aucun champ
 *   {…} oublié, exploitant / contact / pays / âge minimum / durée de la garantie tirés de la configuration (et
 *   remplaçables dans config.php), contenu essentiel (Paddle revendeur, renouvellement, résiliation, cookies réels de
 *   l'application, Meta en empreintes SHA-256, droits), liens croisés, pages lisibles sans base de données, aucun
 *   script en ligne exécutable, en-têtes de sécurité ; liens depuis l'accueil, les pages publiques, la page des prix,
 *   l'inscription, la fenêtre des conditions, la FAQ des prix et la page de paiement ; plan du site ; navigateur :
 *   320 px sans défilement horizontal, liens d'inscription et FAQ de remboursement, aucune erreur ni violation de CSP.
 *   node tests/studioma/legal.js <adresse> <dossier>
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const A = ORIGIN + '/studioma/app/';
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
const CFG = path.join(DIR, 'public_html', 'studioma', 'config.php');
const REPO = path.resolve(__dirname, '..', '..');
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : '')); } };
const step = (t) => console.log('\n' + t);
const sql = (q) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, q]);
const page = async (p) => {
  const res = await fetch(ORIGIN + p, { redirect: 'manual' });
  return { status: res.status, html: await res.text(), headers: res.headers };
};
/** Texte visible d'une page (sans balises), pour chercher des phrases. */
const text = (h) => h.replace(/<script[\s\S]*?<\/script>/g, ' ').replace(/<[^>]+>/g, ' ').replace(/&amp;/g, '&').replace(/&#039;|&rsquo;|’/g, "'").replace(/\s+/g, ' ');
const PAGES = [['terms', 'Terms of Service'], ['privacy', 'Privacy Policy'], ['refunds', 'Refund Policy']];

(async () => {
  const D = JSON.parse(execFileSync('php', ['-r', 'define("STM_SITE_URL", "x"); require $argv[1]; echo json_encode([STM_LEGAL_NAME, STM_LEGAL_EMAIL, STM_LEGAL_COUNTRY, STM_LEGAL_UPDATED, STM_MIN_AGE, STM_REFUND_DAYS]);', path.join(REPO, 'studioma/lib/core.php')]).toString());
  const [NAME, EMAIL, COUNTRY, UPDATED, AGE, DAYS] = D;
  ok(NAME === 'Mohammed Dehini' && EMAIL === 'contact@dehini.com' && COUNTRY === 'Algeria' && AGE === 16 && DAYS === 14 && /^\d{4}-\d{2}-\d{2}$/.test(UPDATED), 'valeurs par défaut choisies par le propriétaire (lib/core.php)', D);

  step('1. Les trois pages');
  const H = {};
  for (const [slug, title] of PAGES) {
    const pg = await page('/studioma/' + slug + '/');
    H[slug] = pg.html;
    const t = text(pg.html);
    ok(pg.status === 200 && /text\/html/.test(pg.headers.get('content-type')), slug + ' : 200, HTML');
    ok(pg.html.includes('<title>' + title + ' | Studioma</title>') && (pg.html.match(/<h1[ >]/g) || []).length === 1 && pg.html.includes('<h1>' + title + '</h1>'), slug + ' : titre et un seul h1');
    ok(pg.html.includes('<link rel="canonical" href="' + ORIGIN + '/studioma/' + slug + '/">') && !/noindex/.test(pg.html), slug + ' : adresse canonique, indexable');
    ok(/<meta name="description" content="[^"]{60,}"/.test(pg.html), slug + ' : description');
    ok(t.includes('Operated by ' + NAME) && pg.html.includes('href="mailto:' + EMAIL + '"') && /Last updated: \d{1,2} [A-Z][a-z]+ \d{4}/.test(t), slug + ' : exploitant, contact, date de mise à jour');
    ok(!/\{(name|email|country|age|days|site|terms|privacy|refunds|pricing)\}/.test(pg.html), slug + ' : aucun champ {…} oublié');
    const anchors = [...pg.html.matchAll(/<nav class="pp-toc"[\s\S]*?<\/nav>/g)][0];
    const ids = anchors ? [...anchors[0].matchAll(/href="#([a-z0-9-]+)"/g)].map((m) => m[1]) : [];
    ok(ids.length >= 8 && ids.every((id) => pg.html.includes('<section id="' + id + '">')), slug + ' : sommaire, chaque ancre mène à sa section', ids);
    const others = PAGES.filter(([s]) => s !== slug).map(([s]) => s);
    ok(others.every((s) => pg.html.includes('href="' + ORIGIN + '/studioma/' + s + '/"')) && pg.html.includes('aria-current="page">' + title), slug + ' : liens vers les deux autres pages, page courante signalée');
    const inline = [...pg.html.matchAll(/<script(?![^>]*\bsrc=)([^>]*)>/g)].filter((m) => !/application\/ld\+json/.test(m[1]));
    ok(inline.length === 0 && !/\son[a-z]+="/.test(pg.html), slug + ' : aucun script en ligne exécutable ni gestionnaire on…=');
    ok(/default-src/.test(pg.headers.get('content-security-policy') || '') && pg.headers.get('x-content-type-options') === 'nosniff', slug + ' : en-têtes de sécurité (CSP, nosniff)');
    ok(t.includes(COUNTRY), slug + ' : pays de l\'exploitant');
  }

  step('2. Contenu essentiel');
  const T = text(H.terms), P = text(H.privacy), R = text(H.refunds);
  ok(T.includes('at least ' + AGE + ' years old') && P.includes(AGE + ''), 'âge minimum (' + AGE + ' ans) dans les conditions et la confidentialité');
  ok(/Paddle/.test(T) && /merchant of record/.test(T) && /renew automatically/.test(T) && /Settings › Plan & billing/.test(T), 'conditions : Paddle revendeur, renouvellement automatique, résiliation dans les réglages');
  ok(/laws of Algeria/.test(T) && /courts of Algeria/.test(T) && /consumer/i.test(T), 'conditions : droit algérien, sans retirer les droits des consommateurs');
  ok(/not a party/.test(T) && /not certifications/.test(T), 'conditions : Studioma n\'est pas partie aux accords entre membres ; niveaux ≠ certifications');
  ok(T.includes(DAYS + '-day money-back guarantee') && R.includes(DAYS + '-day') && R.includes('once per account') && /first payment/.test(R), 'garantie de ' + DAYS + ' jours sur le premier paiement, une fois par compte');
  ok(/renewal/i.test(R) && new RegExp(EMAIL.replace('.', '\\.')).test(R) && /Paddle/.test(R), 'remboursements : renouvellements, demande par courriel ou via Paddle');
  ok(['stm_sid', 'stm_vid', 'stm_ref', '_fbp', '_fbc'].every((c) => H.privacy.includes('<code>' + c + '</code>')), 'confidentialité : tableau des cookies réels');
  // Les cookies cités existent bien dans le code (pas de liste inventée).
  const code = ['lib/auth.php', 'lib/promo.php', 'lib/meta.php', 'lib/share.php', 'lib/core.php'].map((f) => { try { return fs.readFileSync(path.join(REPO, 'studioma', f), 'utf8'); } catch (e) { return ''; } }).join('\n')
    + fs.readdirSync(path.join(REPO, 'studioma/lib')).map((f) => fs.readFileSync(path.join(REPO, 'studioma/lib', f), 'utf8')).join('\n');
  ok(['stm_sid', 'stm_vid', 'stm_ref'].every((c) => code.includes(c)), 'ces cookies sont ceux posés par lib/*.php');
  ok(/SHA-256/.test(P) && /Meta/.test(P) && /Google/.test(P) && /Paddle/.test(P) && /do not sell your personal data/.test(P), 'confidentialité : Meta (empreintes SHA-256), Google, Paddle, aucune vente de données');
  ok(/access/i.test(P) && /delet/i.test(P) && /download/i.test(P) && /object/i.test(P), 'confidentialité : droits d\'accès, effacement, copie des données, opposition');
  ok(/never/.test(P) && /Paddle/.test(P), 'confidentialité : les données de carte vont à Paddle');

  step('3. Sans base de données, adresse inconnue');
  const cfg0 = fs.readFileSync(CFG, 'utf8');
  // Dossier des données impossible à créer : toute page qui lit la base échoue, pas les pages légales.
  fs.writeFileSync(CFG, cfg0 + "\ndefine('STM_DATA_DIR', '/dev/null/studioma-nodb');\n");
  const nodb = await page('/studioma/terms/');
  ok(nodb.status === 200 && nodb.html.includes('<h1>Terms of Service</h1>'), 'conditions lisibles même si la base est indisponible', nodb.status);
  ok((await page('/studioma/pricing/')).status === 500, 'à titre de comparaison, une page qui lit la base répond 500');
  fs.writeFileSync(CFG, cfg0);
  ok((await page('/studioma/p.php?legal=cookies')).status === 404, 'type de page inconnu : 404');

  step('4. Réglages remplaçables dans config.php');
  const cfg = fs.readFileSync(CFG, 'utf8');
  fs.writeFileSync(CFG, cfg + "\ndefine('STM_LEGAL_NAME', 'Studioma Test Ltd');\ndefine('STM_LEGAL_EMAIL', 'legal@test.example');\ndefine('STM_REFUND_DAYS', 30);\ndefine('STM_MIN_AGE', 18);\n");
  let pg = await page('/studioma/refunds/');
  ok(text(pg.html).includes('Operated by Studioma Test Ltd') && pg.html.includes('mailto:legal@test.example') && text(pg.html).includes('30-day'), 'nom, contact et durée remplacés');
  ok(text((await page('/studioma/terms/')).html).includes('at least 18 years old'), 'âge minimum remplacé');
  const bp = await (await fetch(API + '?action=billing_plans')).json();
  ok(bp.refund_days === 30, 'billing_plans renvoie la durée de la garantie (FAQ des prix)', bp.refund_days);
  fs.writeFileSync(CFG, cfg);
  ok((await (await fetch(API + '?action=billing_plans')).json()).refund_days === DAYS, 'configuration rétablie');

  step('5. Liens depuis le reste du site');
  for (const p of ['/studioma/pricing/', '/studioma/opportunities/']) {
    pg = await page(p);
    ok(PAGES.every(([s]) => pg.html.includes('href="' + ORIGIN + '/studioma/' + s + '/"')) && pg.html.includes(EMAIL), 'pied de page de ' + p + ' : les trois pages et le contact');
  }
  ok(text((await page('/studioma/pricing/')).html).includes(DAYS + '-day money-back guarantee'), 'page des prix : garantie et lien vers la politique de remboursement');
  const land = (await page('/studioma/')).html;
  ok(['terms/', 'privacy/', 'refunds/'].every((h) => land.includes('href="' + h + '"')), 'accueil : liens dans le pied de page');
  const pay = fs.readFileSync(path.join(REPO, 'studioma/pay/index.html'), 'utf8');
  ok(pay.includes('href="../terms/"') && pay.includes('href="../refunds/"') && pay.includes('href="../privacy/"'), 'page de paiement : conditions, remboursements, confidentialité');
  const appjs = fs.readFileSync(path.join(REPO, 'studioma/assets/app.js'), 'utf8');
  ok((appjs.match(/\$\{SM\.base\}terms\//g) || []).length >= 2 && (appjs.match(/\$\{SM\.base\}privacy\//g) || []).length >= 2, 'inscription et fenêtre « One last step » : liens vers les conditions et la confidentialité');
  pg = await page('/studioma/sitemap.xml');
  ok(PAGES.every(([s]) => new RegExp('<loc>' + ORIGIN.replace(/[.:/]/g, '\\$&') + '/studioma/' + s + '/</loc><lastmod>' + UPDATED + '</lastmod>').test(pg.html)), 'plan du site : les trois pages, datées du jour de mise à jour', pg.html.slice(0, 400));
  const ht = fs.readFileSync(path.join(REPO, 'studioma/.htaccess'), 'utf8');
  ok(ht.includes('RewriteRule ^(terms|privacy|refunds)/?$ p.php?legal=$1 [L,QSA]'), '.htaccess : réécriture des trois adresses');

  step('6. Navigateur (Chromium)');
  const errors = [], csp = [];
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 320, height: 640 }, isMobile: true });
  const b = await ctx.newPage();
  b.on('pageerror', (e) => errors.push(e.message));
  b.on('console', (m) => { if (/Content Security Policy/i.test(m.text())) csp.push(m.text()); else if (m.type() === 'error' && !/Failed to load resource|GSI_LOGGER|net::ERR_/.test(m.text())) errors.push(m.text()); });
  const overflow = () => b.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  for (const [slug] of PAGES) {
    await b.goto(ORIGIN + '/studioma/' + slug + '/');
    ok(await overflow() <= 1, '320 px : ' + slug + ' sans défilement horizontal');
  }
  await b.goto(ORIGIN + '/studioma/privacy/#cookies');
  ok(await b.evaluate(() => { const w = document.querySelector('.pp-table-wrap'); return !!w && w.scrollWidth >= w.clientWidth && document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1; }), 'tableau des cookies : défile dans son cadre, pas toute la page');
  await b.goto(ORIGIN + '/studioma/terms/');
  await b.click('.pp-toc a[href="#refunds"]');
  ok(await b.evaluate(() => location.hash === '#refunds'), 'sommaire : l\'ancre fonctionne');
  await b.click('#refunds a[href$="/refunds/"]');
  await b.waitForURL(/\/refunds\/$/);
  ok(/Refund Policy/.test(await b.locator('h1').innerText()), 'lien vers la politique de remboursement');
  await b.goto(A + '#/signup');
  await b.waitForSelector('.terms-note a');
  const hrefs = await b.$$eval('.terms-note a', (as) => as.map((a) => a.href));
  ok(hrefs.includes(ORIGIN + '/studioma/terms/') && hrefs.includes(ORIGIN + '/studioma/privacy/'), 'inscription : liens vers les conditions et la confidentialité', hrefs);
  ok(await overflow() <= 1, '320 px : inscription sans défilement horizontal');
  await b.goto(A + '#/pricing');
  await b.waitForSelector('.faq summary');
  await b.locator('.faq summary', { hasText: 'Can I get a refund?' }).click();
  ok(new RegExp('within ' + DAYS + ' days').test(await b.locator('.faq details[open] p').innerText()), 'FAQ des prix : garantie de ' + DAYS + ' jours');
  ok((await b.$$eval('.faq .legal-links a', (as) => as.map((a) => a.getAttribute('href')))).length === 3, 'FAQ des prix : liens vers les trois pages');
  // Fenêtre « One last step » : un compte qui n'a pas encore accepté les conditions (connexion Google, par exemple).
  const r = await fetch(API + '?action=signup', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma' }, body: JSON.stringify({ name: 'Lina Legal', email: 'lina@test.example', password: 'a-solid-password', accept: true }) });
  ok((await r.json()).user, 'compte créé');
  sql("UPDATE users SET terms_at = 0 WHERE email = 'lina@test.example'");
  sql('DELETE FROM rate');
  await b.goto(A + '#/login');
  await b.fill('form[data-form=login] input[name=email]', 'lina@test.example');
  await b.fill('form[data-form=login] input[name=password]', 'a-solid-password');
  await b.click('form[data-form=login] button.btn-primary');
  await b.waitForSelector('.terms-gate', { timeout: 8000 });
  const gate = await b.$$eval('.terms-box a', (as) => as.map((a) => [a.href, a.target]));
  ok(gate.some(([h, t]) => h === ORIGIN + '/studioma/terms/' && t === '_blank') && gate.some(([h]) => h === ORIGIN + '/studioma/privacy/'), 'fenêtre « One last step » : conditions et confidentialité dans un nouvel onglet', gate);
  await b.click('[data-act="terms-accept"]');
  await b.waitForSelector('.terms-gate', { state: 'detached', timeout: 8000 });
  ok(true, 'conditions acceptées');
  ok(errors.length === 0, 'aucune erreur JavaScript', errors);
  ok(csp.length === 0, 'aucune violation de la Content-Security-Policy', csp);
  await browser.close();

  console.log(failed ? `\n${failed} échec(s)` : '\nTous les tests des pages légales sont passés.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
