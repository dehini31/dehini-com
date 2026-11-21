/*
 * Tests de l'administration de Studioma (studioma/admin/, lib/admin.php), API seule, sur le faux hébergement
 * de run.sh : accès réservé à la session de /admin (+ CSRF), statistiques en direct sans contenu privé,
 * comptes (recherche, fiche, actions, suspension appliquée partout, suppression), export CSV, modération,
 * journal des emails (codes masqués), campagnes (aperçu, test, file, pause, désinscription, en-têtes),
 * page de désinscription, tâche cron, journal de l'administration.
 *   node tests/studioma/admin.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 500) : '')); } };
const step = (t) => console.log('\n' + t);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);

/** Compte Studioma, invité d'un lien, ou administrateur (cookie de /admin). */
class Client {
  constructor(opts) { this.jar = {}; this.csrf = ''; this.share = (opts && opts.share) || ''; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  keep(res) {
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => {
      const [k, v] = c.split(';')[0].split('=');
      if (v === '' || /expires=Thu, 01 Jan 1970|Max-Age=0/i.test(c)) delete this.jar[k]; else this.jar[k] = v;
    });
  }
  async call(action, body, params, opts) {
    opts = opts || {};
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const headers = {};
    if (this.cookies()) headers.Cookie = this.cookies();
    if (this.share) headers['X-Share'] = this.share;
    const init = { headers };
    if (body !== undefined) {
      init.method = 'POST';
      init.body = JSON.stringify(body);
      headers['Content-Type'] = 'application/json';
      headers['X-Requested-With'] = 'studioma';
      if (!opts.noCsrf) headers['X-CSRF'] = this.csrf;
    }
    const res = await fetch(url, init);
    this.keep(res);
    const text = await res.text();
    let d;
    try { d = JSON.parse(text); } catch (e) { d = { raw: text }; }
    if (d.csrf && !this.admin) this.csrf = d.csrf;
    d.status = res.status;
    d.headers = res.headers;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b, o) { return this.call(a, b || {}, undefined, o); }
  /** Connexion à l'administration du site (/admin) : son cookie et son jeton CSRF. */
  async adminLogin(password) {
    const res = await fetch(ORIGIN + '/admin/api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: this.cookies() }, body: JSON.stringify({ password }) });
    this.keep(res);
    const d = await res.json();
    if (d.ok) { this.admin = true; this.csrf = d.csrf; }
    return d;
  }
}
const signup = async (name, email, extra) => {
  const c = new Client();
  const r = await c.post('signup', Object.assign({ name, email, password: 'a-solid-password', accept: true }, extra || {}));
  c.id = r.user && r.user.id;
  return c;
};

(async () => {
  step('Accès réservé à l\'administration du site');
  const amel = await signup('Amel Haddad', 'amel@test.example');
  const karim = await signup('Karim Benali', 'karim@test.example');
  const lina = await signup('Lina Brand', 'lina@test.example', { kind: 'company', company: 'Lina Studio' });
  const anon = new Client();
  let r = await anon.get('admin_live');
  ok(r.status === 401 && r.error === 'auth', 'sans session /admin : 401');
  r = await amel.get('admin_users');
  ok(r.status === 401, 'un compte Studioma connecté n\'a aucun droit d\'administration');
  const bad = new Client();
  r = await bad.adminLogin('wrong-password');
  ok(!r.ok, 'mauvais mot de passe de /admin refusé');
  r = await bad.get('admin_live');
  ok(r.status === 401, 'toujours rien sans connexion réussie');
  const fake = new Client();
  fake.jar.dehini_admin = 'aaaaaaaaaaaaaaaaaaaaaaaaaa';
  ok((await fake.get('admin_live')).status === 401, 'cookie de session inventé : refusé');
  const adm = new Client();
  r = await adm.adminLogin('test-owner-pass');
  ok(r.ok && /^[a-f0-9]{48}$/.test(r.csrf), 'connexion à /admin (mot de passe de test)');
  r = await adm.get('admin_live');
  ok(r.status === 200 && r.ok, 'la session /admin ouvre l\'administration de Studioma');
  r = await adm.post('admin_user_action', { id: amel.id, op: 'signout' }, { noCsrf: true });
  ok(r.status === 401, 'écriture sans le jeton CSRF de /admin : refusée');
  const xrw = await fetch(API + '?action=admin_user_action', { method: 'POST', headers: { Cookie: adm.cookies(), 'Content-Type': 'application/json', 'X-CSRF': adm.csrf }, body: JSON.stringify({ id: amel.id, op: 'signout' }) });
  ok(xrw.status === 403, 'écriture sans l\'en-tête X-Requested-With : refusée');
  const viaShare = new Client({ share: 'a'.repeat(32) });
  viaShare.jar = Object.assign({}, adm.jar);
  ok((await viaShare.get('admin_live')).status === 403, 'aucune action d\'administration par un lien de partage');
  ok((await adm.post('admin_user_action', { id: amel.id, op: 'nope' })).status === 400, 'action inconnue refusée');

  step('Statistiques en direct, sans contenu privé');
  r = await amel.post('project_save', { name: 'Secret Project Velora', template: 'content', studio_name: 'Hidden Studio Name' });
  const sid = r.studio_id;
  let b = await amel.get('board', { studio: sid });
  const ideas = b.cols.find((c) => c.name.includes('Ideas'));
  await amel.post('card_create', { col_id: ideas.id, title: 'Confidential card title' });
  await amel.post('post_create', { body: 'Public post about reels' });
  await amel.post('message_send', { uid: karim.id, body: 'Private message body' });
  r = await adm.get('admin_live');
  const live = JSON.stringify(r);
  ok(r.totals.users === 3 && r.today.signups === 3 && r.totals.company_accounts === 1 && r.totals.companies === 1 && r.totals.projects === 1 && r.totals.messages === 1, 'comptes : total, inscrits du jour, comptes entreprise', r.totals);
  ok(r.online.users === 3 && r.online.list.length === 3 && r.active.day === 3, 'en ligne maintenant et actifs du jour');
  ok(r.today.cards === 1 && r.today.posts === 1 && r.series.signups.length === 30 && r.series.signups[29] === 3 && r.series.active[29] === 3, 'activité du jour et séries sur 30 jours');
  ok(r.feed.some((e) => e.kind === 'signup') && r.feed.some((e) => e.kind === 'post' && /Public post about reels/.test(e.text)) && r.feed.some((e) => e.kind === 'card' && e.text === 'added a card to a Studio'), 'fil en direct : inscriptions, publications (extrait), cartes (sans titre)');
  ok(!/Secret Project|Hidden Studio|Confidential card|Private message body/.test(live), 'aucun nom de projet, de Studio, titre de carte ni texte de message');
  ok(r.mail.transport === 'log' && r.mail.per_hour === 200, 'état des emails (journal de test, limite horaire)');

  step('Comptes : recherche, filtres, fiche');
  r = await adm.get('admin_users', { q: 'karim' });
  ok(r.total === 1 && r.users[0].email === 'karim@test.example', 'recherche par nom');
  r = await adm.get('admin_users', { q: 'lina@test' });
  ok(r.total === 1 && r.users[0].kind === 'company', 'recherche par adresse email');
  r = await adm.get('admin_users', { filter: 'company' });
  ok(r.total === 1 && r.counts.all === 3 && r.counts.unconfirmed === 3 && r.counts.online === 3 && r.counts.suspended === 0, 'filtres et leurs nombres', r.counts);
  r = await adm.get('admin_user', { id: amel.id });
  ok(r.user.email === 'amel@test.example' && r.stats.projects_owned === 1 && r.stats.cards === 1 && r.stats.posts === 1 && r.stats.messages === 1 && r.stats.sessions === 1, 'fiche : nombres d\'usage', r.stats);
  ok(r.days.length === 1 && r.emails.some((e) => /Welcome/.test(e.subject) && e.kind === 'system' && e.status === 'sent'), 'jours d\'activité et emails reçus (journal)');
  ok(!/Secret Project|Hidden Studio|Confidential card|Private message body/.test(JSON.stringify(r)), 'fiche sans contenu privé');
  ok((await adm.get('admin_user', { id: 9999 })).status === 404, 'compte inconnu : 404');

  step('Actions sur un compte');
  r = await adm.post('admin_user_action', { id: karim.id, op: 'confirm_email' });
  ok(r.ok && r.user.email_ok, 'adresse marquée comme confirmée');
  const before = mails().length;
  r = await adm.post('admin_user_action', { id: karim.id, op: 'send_reset' });
  const reset = mails().slice(before).find((m) => m.to === 'karim@test.example');
  ok(r.ok && reset && /reset\/[a-f0-9]{48}/.test(reset.text), 'lien de nouveau mot de passe envoyé');
  r = await adm.get('admin_mail_log', { kind: 'admin' });
  ok(r.items.some((m) => m.email === 'karim@test.example' && /Reset/.test(m.subject)), 'email envoyé par l\'administration : journalisé (« admin »)');
  ok((await adm.post('admin_user_action', { id: karim.id, op: 'send_confirm' })).status === 400, 'lien de confirmation refusé : adresse déjà confirmée');
  r = await adm.post('admin_user_action', { id: amel.id, op: 'email', subject: 'Hello {name}', message: 'Hi {name},\n\nThanks for testing.' });
  const direct = mails().pop();
  ok(r.ok && direct.to === 'amel@test.example' && direct.subject === 'Hello {name}' && /Hi Amel,/.test(direct.text), 'email direct ({name} → prénom dans le message)');
  ok((await adm.post('admin_user_action', { id: amel.id, op: 'email', subject: '', message: '' })).status === 400, 'email vide refusé');
  r = await adm.post('admin_user_action', { id: karim.id, op: 'signout' });
  ok(r.ok && (await karim.get('me')).user === null, 'déconnecté partout : la session ne vaut plus');
  await karim.post('login', { email: 'karim@test.example', password: 'a-solid-password' });
  r = await karim.post('ai_token_create', { name: 'Karim CLI' });
  const pat = r.token;
  const mcpCall = (token) => fetch(ORIGIN + '/studioma/mcp', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-06-18', Authorization: 'Bearer ' + token }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: {} }) });
  ok((await mcpCall(pat)).status === 200, 'jeton personnel d\'assistant IA valable');

  step('Suspension appliquée partout');
  await karim.post('post_create', { body: 'Karim public post' });
  await karim.post('profile_save', { title: 'Community manager', skills: ['Copywriting'], pro: true });
  r = await adm.post('admin_user_action', { id: karim.id, op: 'suspend', note: 'Spam reports' });
  ok(r.ok && r.user.suspended > 0 && r.user.suspend_note === 'Spam reports' && r.stats.sessions === 0, 'compte suspendu, motif gardé, sessions fermées');
  ok((await karim.get('me')).user === null, 'la session du compte ne vaut plus');
  r = await new Client().post('login', { email: 'karim@test.example', password: 'a-solid-password' });
  ok(r.status === 403 && r.error === 'suspended', 'connexion refusée (message clair)');
  ok((await mcpCall(pat)).status === 401, 'assistants IA refusés');
  const forgotBefore = mails().length;
  await new Client().post('forgot', { email: 'karim@test.example' });
  ok(mails().length === forgotBefore, 'mot de passe oublié : aucun lien envoyé');
  ok((await anon.get('profile_get', { handle: 'karim-benali' })).status === 404, 'profil public introuvable');
  ok(!(await anon.get('search', { q: 'karim' })).people.length, 'absent de la recherche');
  ok(!(await amel.get('talent', {})).people.some((p) => p.handle === 'karim-benali'), 'absent de l\'annuaire des talents');
  ok(!(await anon.get('feed', { tab: 'discover' })).posts.some((p) => /Karim public post/.test(p.body)), 'ses publications sont masquées');
  ok((await adm.get('admin_users', { filter: 'suspended' })).total === 1, 'filtre « suspendus »');
  r = await adm.post('admin_user_action', { id: karim.id, op: 'unsuspend' });
  ok(r.ok && !r.user.suspended, 'suspension levée');
  r = await karim.post('login', { email: 'karim@test.example', password: 'a-solid-password' });
  ok(r.ok && (await anon.get('profile_get', { handle: 'karim-benali' })).ok && (await mcpCall(pat)).status === 200, 'tout revient : connexion, profil, assistant IA');
  r = await adm.post('admin_user_action', { id: karim.id, op: 'revoke_ai' });
  ok(r.ok && !r.ai.length && (await mcpCall(pat)).status === 401, 'assistants IA déconnectés');

  step('Suppression d\'un compte');
  r = await amel.post('invite_create', { project_id: b.studio.project.id, role: 'viewer' });
  await karim.post('invite_accept', { token: r.invite.token });
  r = await adm.post('admin_user_action', { id: amel.id, op: 'delete', confirm: 'amel@test.example' });
  ok(r.status === 400 && /This account is the only owner of “Secret Project Velora”/.test(r.message), 'refusée : seule propriétaire d\'un projet partagé (message pour l\'administration)', r.message);
  const spam = await signup('=HYPERLINK("http://evil.example")', 'spam@test.example');
  await spam.post('project_save', { name: 'Spam project', template: 'kanban', studio_name: 'Spam' });
  ok((await adm.post('admin_user_action', { id: spam.id, op: 'delete', confirm: 'wrong@test.example' })).status === 400, 'adresse de confirmation fausse : refusée');

  step('Export CSV');
  const csvRes = await fetch(API + '?action=admin_users_csv', { headers: { Cookie: adm.cookies() } });
  const csv = await csvRes.text();
  ok(csvRes.status === 200 && /text\/csv/.test(csvRes.headers.get('content-type')) && /attachment; filename="studioma-accounts-/.test(csvRes.headers.get('content-disposition')), 'fichier CSV téléchargé');
  ok(csv.includes('id,name,email,handle,kind') && csv.includes('karim@test.example'), 'colonnes et comptes');
  ok(csv.includes('"\'=HYPERLINK') && !/,"=HYPERLINK/.test(csv), 'une cellule qui commence par « = » ne devient pas une formule');
  ok((await fetch(API + '?action=admin_users_csv')).status === 401, 'export refusé sans session');
  r = await adm.post('admin_user_action', { id: spam.id, op: 'delete', confirm: 'SPAM@test.example' });
  ok(r.ok && r.deleted && (await adm.get('admin_user', { id: spam.id })).status === 404, 'compte supprimé (avec son projet)');

  step('Modération des contenus publics');
  r = await adm.get('admin_content', { type: 'posts' });
  const post = r.items.find((i) => /Public post about reels/.test(i.text));
  ok(post && post.user.id === amel.id && post.link === 'post/' + post.id, 'publications listées avec leur auteur');
  const pc = await karim.post('post_comment', { id: post.id, body: 'Rude comment' });
  r = await adm.get('admin_content', { type: 'comments', q: 'rude' });
  ok(r.items.length === 1, 'commentaires : recherche');
  ok((await adm.post('admin_content_action', { type: 'comments', id: r.items[0].id, op: 'delete' })).ok && !(await anon.get('post_comments', { id: post.id })).comments.length, 'commentaire supprimé');
  ok((await adm.post('admin_content_action', { type: 'posts', id: post.id, op: 'delete' })).ok && (await anon.get('feed', { tab: 'post', id: post.id })).status === 404, 'publication supprimée');
  ok((await adm.post('admin_content_action', { type: 'posts', id: post.id, op: 'delete' })).status === 404, 'déjà supprimée : 404');
  const company = (await lina.get('me')).companies[0];
  r = await lina.post('opp_save', { company_id: company.id, title: 'Community manager wanted', body: 'Animate our Instagram account every day.', skills: ['Copywriting'], mode: 'remote', contract: 'freelance', apply_kind: 'inapp' });
  const oppId = r.id;
  ok((await adm.post('admin_content_action', { type: 'opps', id: oppId, op: 'close' })).ok && (await anon.get('opp_get', { id: oppId })).opp.status === 'closed', 'offre fermée');
  r = await adm.get('admin_content', { type: 'companies' });
  ok(r.items.some((i) => i.title === 'Lina Studio' && i.uid === lina.id && i.link.startsWith('c/')), 'entreprises listées avec leur propriétaire');
  ok((await adm.post('admin_content_action', { type: 'companies', id: company.id, op: 'delete' })).ok && !(await lina.get('me')).companies.length, 'page d\'entreprise supprimée (le compte reste)');
  ok((await adm.post('admin_content_action', { type: 'cases', id: 1, op: 'delete' })).status === 404, 'opération impossible refusée');
  void pc;

  step('Journal des emails');
  r = await amel.post('share_save', { studio: sid, kind: 'public' });
  const guest = new Client({ share: r.links.public.url.split('/s/')[1] });
  await guest.post('share_code', { email: 'client@brand.example' });
  const codeMail = mails().pop();
  const code = (codeMail.subject.match(/\b\d{6}\b/) || [''])[0];
  r = await adm.get('admin_mail_log', { q: 'client@brand.example' });
  ok(code && r.items.length === 1 && /•{6}/.test(r.items[0].subject) && !r.items[0].subject.includes(code), 'code de vérification masqué dans le journal', r.items[0]);
  ok((await adm.get('admin_mail_log', { status: 'failed' })).items.length === 0 && (await adm.get('admin_mail_log', { kind: 'system' })).items.length >= 4, 'filtres par état et par type');
  r = await adm.post('admin_mail_test', { to: 'owner@test.example' });
  ok(r.ok && r.sent && mails().pop().to === 'owner@test.example', 'email d\'essai envoyé tout de suite');
  ok((await adm.post('admin_mail_test', { to: 'nope' })).status === 400, 'adresse d\'essai invalide refusée');

  step('Campagnes');
  await adm.post('admin_user_action', { id: lina.id, op: 'news', on: false });
  ok((await adm.get('admin_user', { id: lina.id })).user.settings.email_news === false, 'news désactivées pour un compte');
  const zoe = await signup('Zoé Martin', 'zoe@test.example');
  await zoe.post('account_save', { settings: { email_news: false } });
  ok((await zoe.get('me')).user.settings.email_news === false, 'réglage « news » dans les réglages du compte');
  r = await adm.get('admin_mail');
  const all = r.audiences.find((a) => a.key === 'all');
  ok(all.count === 2 && r.stats.opted_out === 2, 'public « Everyone » : sans les comptes désinscrits', r.audiences);
  ok((await adm.post('admin_campaign_preview', { subject: 'x', body: 'short', audience: 'all' })).status === 400, 'campagne incomplète refusée');
  ok((await adm.post('admin_campaign_preview', { subject: 'News', body: 'Hello everyone here', audience: 'everyone-else' })).status === 400, 'public inconnu refusé');
  ok((await adm.post('admin_campaign_preview', { subject: 'News', body: 'Hello everyone here', btn_label: 'Go', btn_url: 'javascript:alert(1)', audience: 'all' })).status === 400, 'lien de bouton non https refusé');
  r = await adm.post('admin_campaign_preview', { subject: 'News for {name}', body: 'Hi {name},\n\n<script>alert(1)</script> New features.', btn_label: 'Open', btn_url: 'dehini.com/studioma/app/', audience: 'all' });
  ok(r.ok && r.subject === 'News for Amina' && r.count === 2 && r.html.includes('&lt;script&gt;') && !r.html.includes('<script>') && r.html.includes('https://dehini.com/studioma/app/'), 'aperçu : prénom d\'exemple, texte échappé, lien complété en https');
  r = await adm.post('admin_campaign_save', { subject: 'News for {name}', body: 'Hi {name},\n\nNew features are live.', btn_label: 'Open', btn_url: 'https://dehini.com/studioma/app/', audience: 'all' });
  const cid = r.campaign.id;
  ok(r.ok && r.campaign.status === 'draft', 'brouillon enregistré');
  r = await adm.post('admin_campaign_test', { id: cid, to: 'amel@test.example' });
  const test = mails().pop();
  ok(r.sent && test.subject === '[Test] News for Amel' && test.headers.some((h) => /^List-Unsubscribe: <.*unsubscribe\.php\?u=\d+&t=[a-f0-9]{32}>$/.test(h)), 'exemplaire d\'essai (prénom réel, désinscription)');
  await adm.post('admin_user_action', { id: karim.id, op: 'suspend', note: 'again' });
  const n0 = mails().length;
  r = await adm.post('admin_campaign_send', { id: cid });
  ok(r.ok && r.campaign.status === 'sending' && r.campaign.total === 1, 'envoi lancé : destinataires figés (sans désinscrits ni suspendus)', r.campaign);
  ok((await adm.post('admin_campaign_send', { id: cid })).status === 400, 'une campagne ne part qu\'une fois');
  ok((await adm.post('admin_campaign_save', { id: cid, subject: 'Changed', body: 'Changed text here', audience: 'all' })).status === 400, 'campagne envoyée : plus modifiable');
  r = await adm.post('admin_pump');
  const sentNow = mails().slice(n0);
  ok(r.ok && r.sent === 1 && sentNow.length === 1 && sentNow[0].to === 'amel@test.example' && sentNow[0].subject === 'News for Amel', 'lot envoyé : une personne');
  ok(sentNow[0].headers.includes('List-Unsubscribe-Post: List-Unsubscribe=One-Click') && /Unsubscribe: http/.test(sentNow[0].text), 'désinscription en un clic (en-têtes RFC 8058) et lien dans le texte');
  r = await adm.get('admin_campaign', { id: cid });
  ok(r.campaign.status === 'sent' && r.campaign.sent === 1 && r.campaign.finished > 0, 'campagne terminée, compteurs à jour');
  ok((await adm.get('admin_mail_log', { kind: 'campaign' })).items.length === 1, 'emails de campagne journalisés');
  await adm.post('admin_user_action', { id: karim.id, op: 'unsuspend' });
  r = await adm.post('admin_campaign_action', { id: cid, op: 'duplicate' });
  const c2 = r.campaign.id;
  ok(r.campaign.status === 'draft' && r.campaign.subject === 'News for {name}', 'campagne dupliquée en brouillon');
  r = await adm.post('admin_campaign_send', { id: c2 });
  ok(r.campaign.total === 2, 'nouveau public : le compte réactivé est inclus');
  ok((await adm.post('admin_campaign_action', { id: c2, op: 'pause' })).campaign.status === 'paused', 'pause');
  const n1 = mails().length;
  ok((await adm.post('admin_pump')).sent === 0 && mails().length === n1, 'en pause : rien ne part');
  ok((await adm.post('admin_campaign_action', { id: c2, op: 'resume' })).campaign.status === 'sending', 'reprise');
  await amel.post('account_save', { settings: { email_news: false } });
  r = await adm.post('admin_pump');
  ok(r.sent === 1 && mails().slice(n1).every((m) => m.to !== 'amel@test.example'), 'désinscrite entre-temps : sautée au moment de l\'envoi');
  r = await adm.get('admin_campaign', { id: c2 });
  ok(r.campaign.sent === 1 && r.campaign.skipped === 1 && r.campaign.status === 'sent', 'compteurs : envoyé et sauté');
  r = await adm.post('admin_campaign_save', { subject: 'Third', body: 'Third campaign text', audience: 'confirmed' });
  const c3 = r.campaign.id;
  r = await adm.post('admin_campaign_send', { id: c3 });
  ok(r.campaign.total === 1 && (await adm.post('admin_campaign_action', { id: c3, op: 'cancel' })).campaign.status === 'cancelled', 'annulation');
  r = await adm.get('admin_campaign', { id: c3 });
  ok(r.campaign.skipped === 1 && r.campaign.pending === 0, 'annulée : les emails restants ne partent pas');
  ok((await adm.post('admin_campaign_action', { id: c3, op: 'delete' })).deleted && (await adm.get('admin_campaign', { id: c3 })).status === 404, 'campagne supprimée');

  step('Page de désinscription');
  const link = (sentNow[0].text.match(/Unsubscribe: (\S+)/) || [])[1];
  await amel.post('account_save', { settings: { email_news: true } });
  let page = await fetch(link);
  let body = await page.text();
  ok(page.status === 200 && /Unsubscribe from Studioma news\?/.test(body) && /<form method="post">/.test(body) && (await amel.get('me')).user.settings.email_news === true, 'GET : page de confirmation (rien ne change à l\'ouverture)');
  page = await fetch(link, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'List-Unsubscribe=One-Click' });
  ok(page.status === 200 && /You are unsubscribed/.test(await page.text()) && (await amel.get('me')).user.settings.email_news === false, 'POST en un clic (messagerie) : désinscrite');
  page = await fetch(link, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=subscribe' });
  ok(/subscribed again/.test(await page.text()) && (await amel.get('me')).user.settings.email_news === true, 'réinscription');
  page = await fetch(link.replace(/t=[a-f0-9]{4}/, 't=0000'));
  ok(page.status === 404 && /This link doesn/.test(await page.text()), 'signature fausse : refusée');
  page = await fetch(link.replace(/u=\d+/, 'u=' + karim.id));
  ok(page.status === 404, 'signature d\'un autre compte : refusée');

  step('Tâche cron et système');
  ok((await fetch(ORIGIN + '/studioma/cron.php')).status === 404, 'cron.php introuvable depuis le web');
  const out = execFileSync('php', [path.join(DIR, 'public_html/studioma/cron.php')], { encoding: 'utf8' });
  ok(/^OK — 0 campaign email/.test(out), 'cron en ligne de commande', out);
  r = await adm.get('admin_system');
  ok(r.system.schema === 7 && r.integrations.cron_last > 0 && r.integrations.meta_pixel === '1234567890123' && r.integrations.meta_token === true, 'système : schéma v7, dernière exécution du cron, intégrations', r.integrations);
  const actions = r.log.map((l) => l.action);
  ok(['suspend', 'unsuspend', 'delete', 'export', 'delete_post', 'close_opp', 'delete_company', 'campaign_send', 'mail_test', 'revoke_ai'].every((a) => actions.includes(a)), 'journal de l\'administration : chaque action', actions);
  ok(r.log.some((l) => l.action === 'suspend' && l.target === 'user:' + karim.id && /Spam reports/.test(l.text)), 'journal : cible et motif');

  step('Page d\'administration');
  page = await fetch(ORIGIN + '/studioma/admin/');
  body = await page.text();
  ok(page.status === 200 && /<script src="\.\.\/assets\/admin\.js"><\/script>/.test(body) && !/<script>/.test(body) && !/<script src="[^"]*meta\.js"/.test(body), 'page servie, sans script en ligne ni pixel');
  ok(/script-src 'self'/.test(page.headers.get('content-security-policy') || ''), 'politique de sécurité (CSP) appliquée');

  console.log(failed ? `\n${failed} échec(s)` : '\nTout est bon.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
