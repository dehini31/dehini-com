/*
 * Tests de qualité (API et pages rendues par le serveur), sur le faux hébergement de run.sh :
 *   opportunités publiées → fil d'accueil (« For you ») et Discover pour tout le monde, page de détails, modification,
 *   fermeture (retirée du fil), réouverture (même publication, pas de doublon), suppression, modération (masquée,
 *   ne revient pas), droits (non-membre, Viewer), rien de privé dans le fil, l'API ou la page publique ;
 *   pages publiques : liste et détail des opportunités (adresse canonique, 301, noindex une fois fermée, 404 supprimée),
 *   prix (tirés de lib/plans.php), plan du site (pages indexables seulement), robots.txt, métadonnées et données
 *   structurées de l'accueil, aucun script en ligne ; migration v13 (opportunités déjà ouvertes rattachées au fil).
 *   node tests/studioma/quality.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
const REPO = path.resolve(__dirname, '..', '..');
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : '')); } };
const step = (t) => console.log('\n' + t);
const sql = (q) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, q]);
const one = (q) => JSON.parse(execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); echo json_encode($p->query($argv[2])->fetchColumn());', DB, q]).toString());

class Client {
  constructor() { this.jar = {}; this.csrf = ''; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  async call(action, body, params) {
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const init = { headers: this.cookies() ? { Cookie: this.cookies() } : {} };
    if (body !== undefined) Object.assign(init, { method: 'POST', body: JSON.stringify(body) }), Object.assign(init.headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf });
    const res = await fetch(url, init);
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => { const [k, ...v] = c.split(';')[0].split('='); if (v.join('=')) this.jar[k] = v.join('='); else delete this.jar[k]; });
    const d = await res.json().catch(() => ({ error: 'not-json' }));
    d.status = res.status;
    if (d.csrf) this.csrf = d.csrf;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
}
const page = async (p, opts) => {
  const res = await fetch(ORIGIN + p, Object.assign({ redirect: 'manual' }, opts || {}));
  return { status: res.status, html: await res.text(), headers: res.headers };
};
const signup = async (name, email) => {
  const c = new Client();
  await c.post('signup', { name, email, password: 'a-solid-password', accept: true });
  c.id = (await c.get('me')).user.id;
  sql('DELETE FROM rate');
  return c;
};
const OPP = { kind: 'opportunity', title: 'Looking for a motion designer for a skincare carousel', body: 'We need five animated slides explaining skincare myths, delivered as MP4 and stills.',
  category: 'Design & visuals', opp_kind: 'pro', skills: ['Motion design', 'After Effects'], deliverables: 'Five animated slides and stills', budget_min: 200, budget_max: 400, currency: 'USD',
  deadline: '2099-12-20', apply_until: '2099-12-10', mode: 'remote' };
const inFeed = (r, pubId) => (r.posts || []).some((p) => p.pub && p.pub.id === pubId);

(async () => {
  step('Préparation : une créatrice, son Studio privé, des cartes ; deux autres comptes sans lien avec elle');
  const amel = await signup('Amel Haddad', 'amel@test.example');
  let r = await amel.post('project_save', { name: 'Vélora Secret Client', template: 'kanban', studio_name: 'Private Studio 2026' });
  const sid = r.studio_id;
  const b = await amel.get('board', { studio: sid });
  const todo = b.cols.find((c) => /To do/.test(c.name));
  const done = b.cols.find((c) => c.kind === 'done');
  const card = (await amel.post('card_create', { col_id: todo.id, title: 'INTERNAL card title' })).card.id;
  await amel.post('card_save', { id: card, body: '<p>CONFIDENTIAL brief: budget of the client and internal notes.</p>' });
  const karim = await signup('Karim Saidi', 'karim@test.example');
  const nadia = await signup('Nadia Viewer', 'nadia@test.example');
  await amel.post('invite_create', { project_id: r.id, uid: nadia.id, role: 'viewer' });
  await nadia.post('invite_accept', { token: one('SELECT token FROM invites WHERE uid = ' + nadia.id) });
  const anon = new Client();

  step('1. Droits : seul un membre qui peut modifier publie la carte');
  ok((await karim.post('pub_save', Object.assign({ card }, OPP))).status === 404, 'un non-membre : introuvable');
  ok((await nadia.post('pub_save', Object.assign({ card }, OPP))).status === 403, 'un Viewer du Studio : refusé');
  ok((await anon.post('pub_save', Object.assign({ card }, OPP))).status === 401, 'sans compte : refusé');
  ok((await karim.get('feed', { tab: 'home' })).posts.length === 0, 'rien n\'est publié tant que l\'auteure ne le décide pas');

  step('2. Publier une opportunité : elle apparaît dans le fil d\'accueil de tout le monde');
  r = await amel.post('pub_save', Object.assign({ card }, OPP));
  const opp = r.pub;
  ok(opp && opp.status === 'open' && /\/studioma\/opportunity\/\d+-looking-for-a-motion-designer-for-a-skincare-carousel$/.test(opp.url), 'opportunité ouverte, adresse publique lisible', opp && opp.url);
  const postId = one(`SELECT post_id FROM pubs WHERE id = ${opp.id}`);
  ok(postId > 0 && one(`SELECT pub_id FROM posts WHERE id = ${postId}`) === opp.id, 'une publication du fil la porte');
  let feed = await karim.get('feed', { tab: 'home' });
  ok(inFeed(feed, opp.id), 'accueil « For you » d\'un compte qui ne suit pas l\'auteure : présente', feed.posts.map((p) => p.id));
  const fp = feed.posts.find((p) => p.pub && p.pub.id === opp.id);
  ok(fp.pub.kind === 'opportunity' && fp.pub.title === OPP.title && fp.pub.budget_min === 200 && fp.pub.opp_label === 'Find a professional' && fp.pub.apply_until === '2099-12-10' && fp.author.name === 'Amel Haddad', 'titre, type, budget, date limite et auteure dans le fil', fp.pub);
  ok(inFeed(await amel.get('feed', { tab: 'home' }), opp.id) && inFeed(await anon.get('feed', { tab: 'discover' }), opp.id), 'aussi dans l\'accueil de l\'auteure et dans Discover (public)');
  const leak = JSON.stringify(feed) + JSON.stringify(await anon.get('feed', { tab: 'discover' })) + JSON.stringify(await anon.get('pub_get', { id: opp.id }));
  ok(!/INTERNAL card title|CONFIDENTIAL|Secret Client|Private Studio/.test(leak), 'rien de la carte, du Studio ni du projet privés dans le fil ou l\'API publique');
  ok((await anon.get('pub_get', { id: opp.id })).pub.source.card_id === undefined, 'l\'adresse de la carte d\'origine n\'est donnée qu\'aux membres du Studio');
  ok((await amel.post('pub_save', Object.assign({ card }, OPP))).pub.id === opp.id && one(`SELECT COUNT(*) FROM pubs WHERE card_id = ${card} AND kind = 'opportunity'`) === 1
    && one(`SELECT COUNT(*) FROM posts WHERE pub_id = ${opp.id}`) === 1, 'republier la même carte : même opportunité, une seule publication du fil');

  step('3. Page publique de l\'opportunité (rendue par le serveur)');
  const pathOf = (u) => u.replace(/^https?:\/\/[^/]+/, '');
  let pg = await page(pathOf(opp.url));
  ok(pg.status === 200 && pg.html.includes('<h1>Looking for a motion designer for a skincare carousel</h1>') && pg.html.includes('Amel Haddad') && /\$200/.test(pg.html) && pg.html.includes('Open'), 'titre, auteure, budget, état');
  ok(pg.html.includes(`<link rel="canonical" href="${opp.url}">`) && pg.html.includes('og:title') && pg.html.includes('og:image') && pg.html.includes('summary_large_image') && /index, follow/.test(pg.headers.get('x-robots-tag')), 'canonique, Open Graph, indexable tant qu\'elle est ouverte');
  ok(pg.html.includes('"@type":"BreadcrumbList"') && !pg.html.includes('JobPosting'), 'fil d\'Ariane structuré ; pas de « JobPosting » (ce n\'est pas une offre d\'emploi)');
  ok(!/INTERNAL card title|CONFIDENTIAL|Secret Client|Private Studio|amel@test/.test(pg.html), 'aucune donnée privée (carte, Studio, projet, email)');
  ok(!/<script(?![^>]*application\/ld\+json)/i.test(pg.html), 'aucun script exécutable (seulement des données structurées)');
  ok(pg.html.includes('app/#/pub/' + opp.id) && pg.html.includes('linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(opp.url)), 'bouton « Send a proposal » et partages encodés');
  const bare = await page('/studioma/opportunity/' + opp.id);
  const wrong = await page('/studioma/opportunity/' + opp.id + '-Old-Title');
  ok(bare.status === 301 && wrong.status === 301 && bare.headers.get('location') === opp.url && wrong.headers.get('location') === opp.url, 'adresse sans titre ou ancienne : 301 vers l\'adresse canonique');
  pg = await page('/studioma/opportunities/');
  ok(pg.status === 200 && pg.html.includes(opp.url) && /1 open opportunity/.test(pg.html) && /index, follow/.test(pg.headers.get('x-robots-tag')), 'liste publique des opportunités ouvertes');

  step('4. Modifier : le fil et la page montrent la nouvelle version');
  r = await amel.post('pub_save', { id: opp.id, title: 'Motion designer wanted for a skincare carousel', body: OPP.body, category: OPP.category, opp_kind: 'pro', skills: OPP.skills, budget_min: 300, budget_max: 500, currency: 'USD', apply_until: '2099-12-10', mode: 'remote' });
  ok(r.pub && r.pub.title === 'Motion designer wanted for a skincare carousel', 'modifiée', r);
  feed = await karim.get('feed', { tab: 'home' });
  ok(feed.posts.find((p) => p.pub && p.pub.id === opp.id).pub.title === 'Motion designer wanted for a skincare carousel' && one(`SELECT COUNT(*) FROM posts WHERE pub_id = ${opp.id}`) === 1, 'fil : nouveau titre, toujours une seule publication');
  const newUrl = (await amel.get('pub_get', { id: opp.id })).pub.url;
  ok(newUrl !== opp.url && (await page(pathOf(opp.url))).headers.get('location') === newUrl, 'nouvelle adresse ; l\'ancienne y renvoie (301)');
  ok((await karim.post('pub_save', { id: opp.id, title: 'Hijack' })).status === 403 && (await nadia.post('pub_status', { id: opp.id, status: 'closed' })).status === 403, 'personne d\'autre ne la modifie ni ne la ferme');

  step('5. Fermer (dépublier) : retirée du fil ; rouvrir : de retour, sans doublon');
  await amel.post('pub_status', { id: opp.id, status: 'closed' });
  ok(!inFeed(await karim.get('feed', { tab: 'home' }), opp.id) && !inFeed(await anon.get('feed', { tab: 'discover' }), opp.id), 'fermée : plus dans l\'accueil ni dans Discover');
  pg = await page(pathOf(newUrl));
  ok(pg.status === 200 && pg.html.includes('Closed') && /noindex/.test(pg.headers.get('x-robots-tag')), 'page publique : « Closed », non indexée');
  ok(!(await page('/studioma/sitemap.xml')).html.includes('/opportunity/'), 'retirée du plan du site');
  await amel.post('pub_status', { id: opp.id, status: 'open' });
  ok(inFeed(await karim.get('feed', { tab: 'home' }), opp.id) && one(`SELECT COUNT(*) FROM posts WHERE pub_id = ${opp.id}`) === 1, 'rouverte : de retour dans le fil, même publication');
  await amel.post('card_move', { id: card, col_id: done.id, index: 0 });
  ok(!inFeed(await karim.get('feed', { tab: 'home' }), opp.id) && one(`SELECT status FROM pubs WHERE id = ${opp.id}`) === 'closed', 'carte terminée : l\'opportunité se ferme et quitte le fil');
  await amel.post('card_move', { id: card, col_id: todo.id, index: 0 });
  await amel.post('pub_status', { id: opp.id, status: 'open' });

  step('6. Modération : une opportunité masquée ne revient pas en ligne');
  sql(`UPDATE pubs SET status = 'hidden', post_id = 0 WHERE id = ${opp.id}`);
  ok(!inFeed(await karim.get('feed', { tab: 'discover' }), opp.id) && (await anon.get('pub_get', { id: opp.id })).status === 404 && (await page(pathOf(newUrl))).status === 404, 'masquée : ni fil, ni API publique, ni page publique');
  ok((await amel.post('pub_status', { id: opp.id, status: 'open' })).status === 403 && (await amel.post('pub_save', Object.assign({ card }, OPP))).status === 403, 'l\'auteure ne peut ni la rouvrir ni la republier');
  sql(`UPDATE pubs SET status = 'open' WHERE id = ${opp.id}`);
  await amel.post('pub_status', { id: opp.id, status: 'open' });
  ok(inFeed(await karim.get('feed', { tab: 'home' }), opp.id), '(rétablie pour la suite)');

  step('7. Supprimer : disparaît partout');
  await amel.post('pub_delete', { id: opp.id });
  ok(!inFeed(await karim.get('feed', { tab: 'home' }), opp.id) && one(`SELECT COUNT(*) FROM posts WHERE pub_id = ${opp.id}`) === 0, 'supprimée : retirée du fil (publication du fil supprimée)');
  ok((await page(pathOf(newUrl))).status === 404 && (await anon.get('pub_get', { id: opp.id })).status === 404, 'page publique et API : 404');
  ok(one(`SELECT COUNT(*) FROM cards WHERE id = ${card}`) === 1, 'la carte privée, elle, est intacte');

  step('8. Réalisations : toujours dans le fil, jamais sur une adresse d\'opportunité');
  const dcard = (await amel.post('card_create', { col_id: done.id, title: 'Finished reel' })).card.id;
  r = await amel.post('pub_save', { card: dcard, kind: 'achievement', title: 'Spring reel for Vélora', body: 'A 30-second reel.', category: 'Video & editing' });
  ok(inFeed(await karim.get('feed', { tab: 'discover' }), r.pub.id) && r.pub.url === '' && (await page('/studioma/opportunity/' + r.pub.id)).status === 404, 'réalisation publiée : dans Discover, pas de page d\'opportunité');

  step('9. Migration v13 : une opportunité ouverte sans publication du fil est rattachée');
  const opp2 = (await amel.post('pub_save', Object.assign({ card }, OPP))).pub.id;
  sql(`DELETE FROM posts WHERE pub_id = ${opp2}; UPDATE pubs SET post_id = 0 WHERE id = ${opp2}; PRAGMA user_version = 12;`);
  ok(one(`SELECT COUNT(*) FROM posts WHERE pub_id = ${opp2}`) === 0 && one('PRAGMA user_version') === 12, 'état d\'avant (base v12) : sans publication du fil');
  await anon.get('me');   // première requête : la base se migre
  ok(one('PRAGMA user_version') >= 13 && one(`SELECT COUNT(*) FROM posts WHERE pub_id = ${opp2}`) === 1 && inFeed(await karim.get('feed', { tab: 'home' }), opp2), 'base migrée : présente dans le fil, une seule fois');
  sql('PRAGMA user_version = 12');
  await anon.get('me');
  ok(one(`SELECT COUNT(*) FROM posts WHERE pub_id = ${opp2}`) === 1, 'migration rejouée : pas de doublon');

  step('10. Prix, plan du site, robots.txt');
  pg = await page('/studioma/pricing/');
  const plans = execFileSync('php', ['-r', 'require $argv[1]; echo json_encode(STM_PLANS);', path.join(REPO, 'studioma/lib/plans.php')]).toString();
  const P = JSON.parse(plans);
  ok(pg.status === 200 && pg.html.includes('<h1>') && ['free', 'solo', 'team'].every((k) => pg.html.includes(P[k].name) && pg.html.includes(P[k].tagline)), 'page des prix : les trois formules de lib/plans.php');
  ok(pg.html.includes('$' + P.solo.price.month / 100 + '<') && pg.html.includes('$' + P.team.price.month / 100 + '<') && pg.html.includes('<link rel="canonical" href="' + ORIGIN + '/studioma/pricing/">'), 'prix exacts, adresse canonique');
  ok(!/Checkout|card number/i.test(pg.html) && !pg.html.includes('paddle'), 'aucun paiement sur la page');
  pg = await page('/studioma/sitemap.xml');
  ok(pg.status === 200 && /xml/.test(pg.headers.get('content-type')) && pg.html.startsWith('<?xml') && pg.html.includes('<loc>' + ORIGIN + '/studioma/</loc>') && pg.html.includes('/studioma/pricing/') && pg.html.includes('/opportunity/' + opp2 + '-'), 'plan du site : accueil, prix, opportunités ouvertes');
  ok(!/\/app\/|api\.php|\/s\/|\/verify\/|\/creator\//.test(pg.html), 'jamais l\'application, l\'API, un lien de partage, ni un passeport ou une vérification non publics');
  await amel.post('journey_passport_save', { enabled: true });
  ok((await page('/studioma/sitemap.xml')).html.includes('/studioma/creator/amel-haddad'), 'un passeport ouvert y entre');
  const robots = fs.readFileSync(path.join(REPO, 'robots.txt'), 'utf8');
  ok(/Disallow: \/studioma\/app\//.test(robots) && /Disallow: \/studioma\/api\.php/.test(robots) && /Allow: \/studioma\/api\.php\?action=file/.test(robots) && /Sitemap: https:\/\/dehini\.com\/studioma\/sitemap\.xml/.test(robots), 'robots.txt : application et API exclues, fichiers publics permis, plan du site déclaré');

  step('11. Accueil : métadonnées et données structurées');
  pg = await page('/studioma/');
  const json = [...pg.html.matchAll(/<script type="application\/ld\+json">(.*?)<\/script>/g)].map((m) => JSON.parse(m[1]));
  const types = json.map((j) => j['@type']);
  ok(/<title>Studioma — Content Planning &amp; Collaboration for Community Managers<\/title>/.test(pg.html) && /<meta name="description" content="Plan content/.test(pg.html), 'titre et description');
  ok(['Organization', 'WebSite', 'SoftwareApplication'].every((t) => types.includes(t)) && !JSON.stringify(json).includes('aggregateRating') && !JSON.stringify(json).includes('review'), 'Organization, WebSite, SoftwareApplication — sans note ni avis inventés', types);
  ok(pg.html.includes('og:image" content="https://dehini.com/studioma/assets/og-studioma.jpg') && fs.existsSync(path.join(REPO, 'studioma/assets/og-studioma.jpg')), 'image des aperçus de liens');
  ok(pg.html.indexOf('<h1') < pg.html.indexOf('<h2') && (pg.html.match(/<h1/g) || []).length === 1, 'un seul h1, avant les h2');
  ok(pg.html.includes('href="pricing/"') && pg.html.includes('href="opportunities/"'), 'liens internes vers les prix et les opportunités');
  ok(!pg.html.includes('fonts.googleapis.com') && pg.html.includes('assets/fonts.css') && / defer><\/script>/.test(pg.html), 'police hébergée sur le site, scripts différés');
  const app = fs.readFileSync(path.join(REPO, 'studioma/app/index.html'), 'utf8');
  ok(/<meta name="robots" content="noindex">/.test(app) && !app.includes('fonts.googleapis.com') && (app.match(/<script src=/g) || []).length === (app.match(/ defer><\/script>/g) || []).length, 'application : non indexée, police locale, tous les scripts différés');

  console.log(failed ? `\n${failed} échec(s)` : '\nTous les tests de qualité sont passés.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
