/*
 * Tests du suivi Meta (pixel + API Conversions), API seule, sur le faux hébergement de run.sh. Les
 * événements de l'API Conversions sont écrits dans <dossier>/meta.jsonl (STM_META_LOG) : chaque action
 * doit y produire le bon événement, avec le même event_id que celui renvoyé au navigateur (« _meta »), des
 * identifiants hachés, et jamais de contenu (titres, noms, textes) ni de clé secrète.
 *   node tests/studioma/meta.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const LOG = path.join(DIR, 'meta.jsonl');
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 500) : '')); } };
const step = (t) => console.log('\n' + t);
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');
const UA = 'Mozilla/5.0 (Studioma tests)';
const events = () => (fs.existsSync(LOG) ? fs.readFileSync(LOG, 'utf8').trim().split('\n').filter(Boolean).flatMap((l) => JSON.parse(l).data) : []);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
let seen = 0;
/** Événements apparus depuis le dernier appel. */
const fresh = () => { const all = events(); const out = all.slice(seen); seen = all.length; return out; };

class Client {
  constructor() { this.cookie = '_fbp=fb.1.1700000000000.1234567890'; this.csrf = ''; this.share = ''; }
  async call(action, body, params) {
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const headers = { Cookie: this.cookie, 'User-Agent': UA, Referer: ORIGIN + '/studioma/app/#/secret-route' };
    if (this.share) headers['X-Share'] = this.share;
    const init = { headers };
    if (body !== undefined) Object.assign(init, { method: 'POST', body: JSON.stringify(body) }), Object.assign(headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf });
    const res = await fetch(url, init);
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => {
      const kv = c.split(';')[0];
      const k = kv.split('=')[0];
      this.cookie = this.cookie.split('; ').filter((x) => !x.startsWith(k + '=')).concat(kv).join('; ');
    });
    const d = await res.json();
    if (d.csrf) this.csrf = d.csrf;
    d.http = res.status;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
}
/** L'action a produit exactement ces événements (côté serveur), avec les mêmes event_id renvoyés au navigateur. */
function expect(r, names, label, browser = true) {
  const evs = fresh();
  const got = evs.map((e) => e.event_name);
  const ids = (r._meta || []).map((m) => m.id);
  const same = browser ? evs.every((e) => ids.includes(e.event_id)) && ids.length === evs.length : !r._meta;
  ok(JSON.stringify(got) === JSON.stringify(names) && same, label + ' → ' + (names.join(', ') || 'aucun événement'), { got, browser: r._meta });
  return evs;
}

(async () => {
  step('Réglages');
  const amel = new Client();
  let r = await amel.get('pixel');
  ok(r.pixel === '1234567890123', 'GET pixel : l\'ensemble de données (page d\'accueil)');
  r = await amel.get('me', { ref: 1 });
  ok(r.ref.meta_pixel === '1234567890123', 'référence de l\'application : meta_pixel');
  expect(r, [], 'consulter son compte');

  step('Comptes');
  r = await amel.post('signup', { name: 'Amel Haddad', email: 'Amel@Test.example', password: 'a-solid-password', accept: true });
  let ev = expect(r, ['CompleteRegistration'], 'inscription');
  const e0 = ev[0];
  ok(e0 && e0.action_source === 'website' && /^stm\.completeregistration\.[a-f0-9]{16}$/.test(e0.event_id) && Math.abs(e0.event_time - Date.now() / 1000) < 60, 'event_id unique, action_source website, event_time');
  ok(e0 && e0.user_data.em[0] === sha('amel@test.example') && e0.user_data.external_id[0] === sha('studioma:' + r.user.id) && e0.user_data.fn[0] === sha('amel') && e0.user_data.ln[0] === sha('haddad'), 'email, id, prénom et nom hachés en SHA-256 (minuscules)');
  ok(e0 && e0.user_data.client_user_agent === UA && e0.user_data.client_ip_address && e0.user_data.fbp === 'fb.1.1700000000000.1234567890', 'navigateur, IP et cookie _fbp transmis');
  ok(e0 && e0.event_source_url === ORIGIN + '/studioma/app/' && e0.custom_data.content_name === 'individual' && e0.custom_data.registration_method === 'email', 'adresse de la page sans # ni ?, type de compte, méthode');
  ok(!fs.readFileSync(LOG, 'utf8').includes('amel@test.example') && !/Amel@Test/.test(fs.readFileSync(LOG, 'utf8')), 'aucun email en clair envoyé à Meta');
  const lina = new Client();
  r = await lina.post('signup', { name: 'Lina', email: 'lina@test.example', password: 'a-solid-password', accept: true, kind: 'company', company: 'Lina Brand' });
  ev = expect(r, ['CompleteRegistration'], 'inscription d\'une entreprise');
  ok(ev[0] && ev[0].custom_data.content_name === 'company' && !ev[0].user_data.fn, 'type « company » ; nom d\'un seul mot : ni prénom ni nom envoyés');
  await amel.post('logout');
  fresh();
  r = await amel.post('login', { email: 'amel@test.example', password: 'a-solid-password' });
  ev = expect(r, ['Login'], 'connexion');
  ok(ev[0] && ev[0].user_data.em[0] === sha('amel@test.example'), 'connexion : la personne est reconnue (email haché)');
  r = await amel.post('login', { email: 'amel@test.example', password: 'wrong-password' });
  expect(r, [], 'connexion refusée');

  step('Studios et cartes');
  r = await amel.post('project_save', { name: 'Vélora Secret Launch', template: 'content', studio_name: 'Confidential Studio' });
  expect(r, ['CreateProject', 'CreateStudio'], 'projet avec son premier Studio');
  const sid = r.studio_id;
  const pid = r.id;
  r = await amel.post('studio_create', { project_id: pid, name: 'Second', template: 'kanban' });
  expect(r, ['CreateStudio'], 'nouveau Studio');
  let b = await amel.get('board', { studio: sid });
  expect(b, [], 'tableau (synchronisation toutes les 4 secondes) : aucun événement');
  const col = (re) => b.cols.find((c) => re.test(c.name));
  r = await amel.post('card_create', { col_id: col(/Ideas/).id, title: 'Top secret card title' });
  expect(r, ['CreateCard'], 'carte créée');
  const card = r.card.id;
  r = await amel.post('card_save', { id: card, title: 'Still secret' });
  expect(r, [], 'carte modifiée');
  r = await amel.post('comment_add', { card_id: card, body: 'Private feedback text' });
  expect(r, ['AddComment'], 'commentaire');
  const finalCol = b.cols.find((c) => c.level_id === col(/Ideas/).level_id && /Script Approved/.test(c.name));
  r = await amel.post('card_move', { id: card, col_id: finalCol.id, index: 0 });
  expect(r, ['CardHandoff'], 'carte passée au niveau suivant');
  b = await amel.get('board', { studio: sid });
  const published = b.cols.find((c) => c.kind === 'done');
  r = await amel.post('card_move', { id: card, col_id: published.id, index: 0 });
  expect(r, ['ContentPublished'], 'carte publiée (colonne terminée)');
  r = await amel.post('invite_create', { studio_id: sid, email: 'karim@test.example', role: 'creator' });
  ev = expect(r, ['InviteMember'], 'invitation par email');
  ok(ev[0] && ev[0].custom_data.role === 'creator' && ev[0].custom_data.method === 'email', 'rôle et méthode de l\'invitation');
  const karim = new Client();
  await karim.post('signup', { name: 'Karim Benali', email: 'karim@test.example', password: 'a-solid-password', accept: true });
  fresh();
  const tok = (mails().reverse().find((m) => m.to === 'karim@test.example' && /join\//.test(m.text)).text.match(/join\/([a-f0-9]{32})/) || [])[1];
  r = await karim.post('invite_accept', { token: tok });
  expect(r, ['JoinTeam'], 'invitation acceptée');
  r = await amel.post('approval_request', { card_id: card, approver: 0 });
  expect(r, [], 'demande de validation refusée : aucun événement');

  step('Partage sans compte');
  r = await amel.post('share_save', { studio: sid, kind: 'public' });
  ev = expect(r, ['ShareStudio'], 'lien public créé');
  ok(ev[0] && ev[0].custom_data.link_type === 'public', 'type de lien');
  r = await amel.post('share_save', { studio: sid, kind: 'public', expires: '2099-01-01' });
  expect(r, [], 'lien réglé (déjà créé)');
  const key = r.links.public.url.split('/s/')[1];
  const guest = new Client();
  guest.share = key;
  r = await guest.get('board', { studio: sid });
  expect(r, [], 'visiteur du lien', false);
  r = await guest.post('share_code', { email: 'client@brand.example', request: 'editor' });
  const code = (mails().reverse().find((m) => m.to === 'client@brand.example').text.match(/\b(\d{6})\b/) || [])[1];
  r = await guest.post('share_verify', { email: 'client@brand.example', code });
  ev = expect(r, ['GuestVerified', 'Lead'], 'invité : adresse vérifiée et demande d\'édition (serveur seulement)', false);
  ok(ev.every((e) => !e.user_data.em && !e.user_data.external_id) && ev[1].custom_data.content_category === 'guest_access' && ev[1].custom_data.role === 'editor', 'invité : jamais son email ; Lead « guest_access »');
  const gid = (await amel.get('share_list', { studio: sid })).guests[0].id;
  fresh();
  r = await amel.post('share_guest', { studio: sid, op: 'approve', guest: gid, role: 'editor' });
  expect(r, ['ApproveGuest'], 'demande d\'un invité approuvée');
  r = await guest.post('card_create', { col_id: col(/Ideas/).id, title: 'Guest secret idea' });
  expect(r, ['GuestCollaboration'], 'carte créée par un invité', false);
  r = await amel.post('share_guest', { studio: sid, op: 'add', kind: 'private', emails: 'boss@brand.example', role: 'commenter' });
  expect(r, ['ShareStudio', 'AddGuest'], 'lien privé créé en ajoutant une personne');

  step('Réseau, portfolio, entreprises, messages');
  r = await amel.post('post_create', { body: 'Hello world, private text' });
  ev = expect(r, ['CreatePost'], 'publication');
  ok(ev[0] && ev[0].custom_data.content_category === 'personal' && ev[0].custom_data.media === 'no', 'publication : catégories seulement');
  const post = r.post.id;
  r = await karim.post('post_like', { id: post, on: true });
  expect(r, ['LikePost'], 'j\'aime');
  r = await karim.post('post_like', { id: post, on: false });
  expect(r, [], 'j\'aime retiré');
  r = await karim.post('post_comment', { id: post, body: 'Nice' });
  expect(r, ['AddComment'], 'commentaire d\'une publication');
  r = await karim.get('feed', { tab: 'post', id: post });
  expect(r, ['ViewContent'], 'publication consultée');
  r = await karim.post('follow', { type: 'user', id: (await amel.get('me')).user.id, on: true });
  expect(r, ['Follow'], 'abonnement');
  r = await karim.post('connect', { uid: (await amel.get('me')).user.id });
  expect(r, ['ConnectRequest'], 'demande de mise en relation');
  r = await karim.get('profile_get', { handle: 'amel-haddad' });
  ev = expect(r, ['ViewContent'], 'profil d\'une autre personne consulté');
  ok(ev[0] && ev[0].custom_data.content_type === 'profile' && /^profile-\d+$/.test(ev[0].custom_data.content_ids[0]), 'ViewContent : type et identifiant, sans nom');
  r = await amel.get('profile_get', { handle: 'amel-haddad' });
  expect(r, [], 'son propre profil');
  r = await (new Client()).get('profile_get', { handle: 'amel-haddad' });
  ev = expect(r, ['ViewContent'], 'profil public consulté par un visiteur sans compte');
  ok(ev[0] && !ev[0].user_data.em && ev[0].user_data.client_user_agent, 'visiteur : navigateur et IP seulement');
  r = await amel.post('portfolio_save', { title: 'Case', results: 'x', publish: true });
  expect(r, ['PublishCaseStudy'], 'étude de cas publiée');
  r = await amel.post('portfolio_save', { title: 'Draft case', publish: false });
  expect(r, ['CreateCaseStudy'], 'étude de cas en brouillon');
  r = await lina.post('company_save', { id: (await lina.get('me')).companies[0].id, name: 'Lina Brand', tagline: 'x' });
  expect(r, [], 'page entreprise modifiée');
  r = await amel.post('company_save', { name: 'Amel Agency', industry: 'Tech & SaaS' });
  ev = expect(r, ['CreateCompany'], 'page entreprise créée');
  ok(ev[0] && ev[0].custom_data.industry === 'Tech & SaaS', 'secteur (liste de Studioma)');
  const coSlug = r.slug;
  r = await amel.post('opp_save', { company_id: r.id, title: 'Video editor', body: 'Edit 8 reels a month for our spring campaign.', mode: 'hybrid', contract: 'freelance' });
  ev = expect(r, ['PublishOpportunity'], 'opportunité publiée');
  const opp = r.id;
  r = await karim.get('company_get', { slug: coSlug });
  expect(r, ['ViewContent'], 'page entreprise consultée');
  r = await karim.get('opp_get', { id: opp });
  expect(r, ['ViewContent'], 'opportunité consultée');
  r = await karim.post('opp_apply', { id: opp, message: 'I edit reels for two cycling brands, happy to help.' });
  ev = expect(r, ['SubmitApplication'], 'candidature');
  ok(ev[0] && ev[0].custom_data.content_ids[0] === 'opportunity-' + opp, 'candidature : identifiant de l\'opportunité');
  r = await amel.post('message_send', { uid: (await karim.get('me')).user.id, body: 'Hello Karim, very private message' });
  expect(r, ['Contact'], 'message privé');
  r = await lina.post('talent_save', { uid: (await karim.get('me')).user.id, on: true });
  expect(r, ['AddToWishlist'], 'professionnel enregistré');

  step('Recherches : jamais le texte tapé');
  r = await karim.get('search', { q: 'Amel Haddad secret' });
  ev = expect(r, ['Search'], 'recherche');
  ok(ev[0] && !ev[0].custom_data.search_string && !JSON.stringify(ev[0]).includes('secret'), 'recherche : le texte tapé n\'est pas envoyé');
  r = await lina.get('talent', { skill: 'Video editing', platform: 'tiktok', q: 'john doe' });
  ev = expect(r, ['Search'], 'annuaire des talents filtré');
  ok(ev[0] && ev[0].custom_data.content_category === 'talent' && ev[0].custom_data.search_string === 'Video editing, TikTok' && !JSON.stringify(ev[0]).includes('john'), 'talents : seulement les filtres des listes de Studioma');
  r = await lina.get('talent', {});
  expect(r, [], 'annuaire sans filtre');
  r = await karim.get('opps', { mode: 'remote' });
  ev = expect(r, ['Search'], 'opportunités filtrées');
  ok(ev[0] && ev[0].custom_data.search_string === 'Remote', 'opportunités : filtre de la liste');
  r = await karim.get('opps', {});
  expect(r, [], 'liste des opportunités sans filtre');

  step('Assistants IA');
  r = await amel.post('ai_token_create', { name: 'CLI' });
  expect(r, ['ConnectAI'], 'jeton personnel d\'un assistant IA');

  step('Rien de secret ni de privé envoyé à Meta');
  const raw = fs.readFileSync(LOG, 'utf8');
  ok(!/Vélora|Secret Launch|Confidential|Top secret|Still secret|Private feedback|Guest secret|private text|very private|Amel Agency|Lina Brand/.test(raw), 'aucun nom de projet, Studio, entreprise, carte, commentaire ou message');
  ok(!raw.includes(key) && !raw.includes(tok) && !raw.includes(code) && !/secret-route/.test(raw), 'aucune clé de lien, d\'invitation ou code ; aucune partie d\'adresse après #');
  ok(!/@/.test(raw.replace(/"event_source_url":"[^"]*"/g, '')), 'aucune adresse email (seulement des empreintes)');
  ok(events().every((e) => e.user_data.client_user_agent && e.event_id && e.event_name), 'chaque événement a son navigateur, son event_id et son nom');

  console.log('\n' + (failed ? failed + ' échec(s)' : 'Tout est bon.'));
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
