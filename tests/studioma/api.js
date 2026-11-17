/*
 * Tests de l'API de Studioma (sans navigateur), sur le faux hébergement de run.sh :
 *   inscription → projet et Studio (modèle par défaut) → niveaux, colonnes, cartes → passage de
 *   relais (la carte garde son identité) → invitations et rôles (droits vérifiés côté serveur) →
 *   commentaires, mentions, pièces jointes privées, validations → archivage et suppression sûrs →
 *   réseau (publications, relations), portfolio et autorisations, entreprises, opportunités,
 *   annuaire des talents, messages.
 *   node tests/studioma/api.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra) : '')); } };
const step = (t) => console.log('\n' + t);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);

/** Un « navigateur » : garde son cookie de session et son jeton CSRF. */
class Client {
  constructor(name) { this.name = name; this.cookie = ''; this.csrf = ''; }
  async call(action, body, params, raw) {
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const init = { headers: {} };
    if (this.cookie) init.headers.Cookie = this.cookie;
    if (body !== undefined) {
      init.method = 'POST';
      init.headers['X-Requested-With'] = 'studioma';
      if (this.csrf) init.headers['X-CSRF'] = this.csrf;
      if (body instanceof FormData) init.body = body;
      else { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body || {}); }
    }
    const res = await fetch(url, init);
    const set = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    set.forEach((c) => { if (c.startsWith('stm_sid=')) this.cookie = c.split(';')[0]; });
    if (raw) return res;
    let data;
    try { data = await res.json(); } catch (e) { data = { error: 'not-json' }; }
    if (typeof data.status === 'string') data.state = data.status;   // « status » du corps de la réponse
    data.status = res.status;
    if (data.csrf) this.csrf = data.csrf;
    return data;
  }
  get(action, params) { return this.call(action, undefined, params); }
  post(action, body) { return this.call(action, body || {}); }
  async signup(name, email, extra) { return this.post('signup', Object.assign({ name, email, password: 'a-solid-password', accept: true }, extra || {})); }
  async upload(kind, file, type, extra) {
    const fd = new FormData();
    fd.append('kind', kind);
    Object.entries(extra || {}).forEach(([k, v]) => fd.append(k, String(v)));
    fd.append('file', new Blob([fs.readFileSync(file)], { type }), path.basename(file));
    return this.call('upload', fd);
  }
}

(async () => {
  const amel = new Client('amel');     // community manager, propriétaire du projet
  const karim = new Client('karim');   // monteur vidéo invité
  const lina = new Client('lina');     // cliente
  const sami = new Client('sami');     // étranger au projet
  const anon = new Client('anon');

  /* ------------------------------------------------------------ comptes */
  step('Comptes');
  let r = await anon.post('signup', { name: 'A', email: 'x', password: 'short' });
  ok(r.status === 400, 'inscription invalide refusée');
  r = await amel.signup('Amel Haddad', 'amel@test.example');
  ok(r.ok && r.user.handle === 'amel-haddad' && r.csrf, 'inscription (compte individuel)', r);
  r = await anon.signup('Amel Bis', 'amel@test.example');
  ok(r.error === 'exists', 'email déjà utilisé refusé');
  await karim.signup('Karim Benali', 'karim@test.example');
  await sami.signup('Sami Intrus', 'sami@test.example');
  r = await lina.signup('Lina Mansouri', 'lina@test.example', { kind: 'company', company: 'Dar Lina' });
  ok(r.ok && r.companies.length === 1 && r.companies[0].role === 'owner', 'inscription d\'une entreprise : page créée', r.companies);
  ok(mails().some((m) => m.to === 'amel@test.example' && /Welcome/.test(m.subject)), 'email de bienvenue');
  ok(mails().some((m) => m.to === 'amel@test.example' && /Welcome/.test(m.subject) && /\/confirm\/[a-f0-9]{48}/.test(m.text)), 'email de bienvenue : lien de confirmation de l\'adresse');
  r = await anon.get('workspace');
  ok(r.status === 401, 'espace privé refusé sans connexion');
  const noCsrf = new Client('nocsrf'); noCsrf.cookie = amel.cookie;
  r = await noCsrf.post('project_save', { name: 'Sans jeton' });
  ok(r.status === 401, 'écriture refusée sans jeton CSRF');
  r = await fetch(API + '?action=project_save', { method: 'POST', headers: { Cookie: amel.cookie, 'Content-Type': 'application/json' }, body: '{}' });
  ok(r.status === 403, 'écriture refusée sans en-tête X-Requested-With');
  const again = new Client('amel2');
  r = await again.post('login', { email: 'amel@test.example', password: 'wrong-password' });
  ok(r.status === 401, 'mauvais mot de passe refusé');
  r = await again.post('login', { email: 'amel@test.example', password: 'a-solid-password' });
  ok(r.ok && r.user.name === 'Amel Haddad', 'connexion');

  /* ------------------------------------------------- projet, studio, modèle */
  step('Projet, Studio et modèle par défaut');
  r = await amel.post('project_save', { name: 'Vélora', description: 'Marque de vélos', color: 'coral', template: 'content', studio_name: 'Social 2026' });
  const projectId = r.id; const studioId = r.studio_id;
  ok(projectId > 0 && studioId > 0, 'projet et premier Studio créés en un geste', r);
  let board = await amel.get('board', { studio: studioId });
  const L = board.levels; const colsOf = (lv) => board.cols.filter((c) => c.level_id === lv.id);
  ok(L.length === 3 && L.map((l) => l.name).join() === 'Creation,Production,Publication', '3 niveaux : Creation, Production, Publication');
  ok(colsOf(L[0]).map((c) => c.name).join('|') === '💡 Ideas|✍️ Script in Progress|✅ Script Approved', 'colonnes du niveau Creation');
  ok(colsOf(L[1]).length === 4 && colsOf(L[2]).length === 4, 'colonnes de Production et Publication');
  ok(colsOf(L[0])[0].principal && colsOf(L[1])[0].principal && colsOf(L[2])[0].principal, 'une colonne principale par niveau');
  ok(L[0].handoff === 'auto' && L[0].handoff_col === colsOf(L[0])[2].id && L[1].handoff_col === colsOf(L[1])[3].id && L[2].handoff_col === 0, 'colonnes finales et relais automatique configurés');
  ok(colsOf(L[2]).find((c) => c.name.includes('Published')).kind === 'done', 'colonne « Published » marquée terminée');
  const [ideas, scripting, scriptOk] = colsOf(L[0]);
  const [prodIn, video, design, ready] = colsOf(L[1]);
  const [pubIn, scheduled, published] = colsOf(L[2]);

  /* ---------------------------------------------------------------- cartes */
  step('Cartes et passage de relais');
  r = await amel.post('card_create', { col_id: ideas.id, title: 'Reel : 5 erreurs de débutant' });
  const cardId = r.card.id;
  ok(cardId > 0 && r.card.col_id === ideas.id, 'carte créée d\'un geste dans Ideas');
  await amel.post('card_create', { col_id: ideas.id, title: 'Carrousel entretien' });
  const c3 = (await amel.post('card_create', { col_id: ideas.id, title: 'Story coulisses', top: true })).card.id;
  board = await amel.get('board', { studio: studioId });
  ok(board.cards.filter((c) => c.col_id === ideas.id).sort((a, b) => a.pos - b.pos)[0].id === c3, 'carte créée en haut de la colonne');
  r = await amel.post('card_save', { id: cardId, body: '<h2>Script</h2><p onclick="x()">Accroche <b>forte</b> <script>alert(1)</script><a href="javascript:alert(1)">lien</a> <a href="https://exemple.com">ok</a></p><img src=x onerror=alert(1)>',
    due: '2026-12-20', priority: 3, tags: ['reel', 'débutants'], platforms: ['instagram', 'tiktok', 'bogus'], ctype: 'reel', publish_at: '2026-12-22T18:30' });
  ok(r.ok && !/<script|alert|onclick|onerror|javascript:|<img/i.test(r.card.body) && /<h2>Script<\/h2>/.test(r.card.body) && /href="https:\/\/exemple\.com"/.test(r.card.body), 'texte mis en forme nettoyé (balises et liens dangereux retirés)', r.card && r.card.body);
  ok(r.card.platforms.join() === 'instagram,tiktok' && r.card.priority === 3 && r.card.due === '2026-12-20' && r.card.ctype === 'reel', 'champs de la carte enregistrés');
  await amel.post('check_save', { card_id: cardId, text: 'Écrire l\'accroche' });
  const ck = (await amel.post('check_save', { card_id: cardId, text: 'Relire' })).id;
  await amel.post('check_save', { id: ck, done: true });
  await amel.post('comment_add', { card_id: cardId, body: 'Première version prête.' });
  const png = path.join(DIR, 'photo.png');
  r = await amel.upload('card', png, 'image/png', { card_id: cardId });
  ok(r.ok && r.file.kind === 'image' && r.file.token.length === 32, 'pièce jointe (image) envoyée', r);
  const fileToken = r.file.token;
  r = await amel.upload('card', path.join(DIR, 'faux.jpg'), 'image/jpeg', { card_id: cardId });
  ok(r.status === 400, 'faux fichier image refusé (contenu vérifié)');
  r = await amel.upload('card', path.join(DIR, 'notes.txt'), 'text/plain', { card_id: cardId });
  ok(r.ok && r.file.kind === 'doc', 'pièce jointe (document) envoyée');

  r = await amel.post('card_move', { id: cardId, col_id: scripting.id, index: 0 });
  ok(r.ok && r.handoff === null, 'déplacement dans le niveau : pas de relais');
  r = await amel.post('card_move', { id: cardId, col_id: scriptOk.id, index: 0 });
  ok(r.ok && r.handoff && r.handoff.level === 'Production' && r.handoff.col_id === prodIn.id, 'colonne finale → relais automatique vers Production', r);
  let full = (await amel.get('card_get', { id: cardId })).card;
  ok(full.id === cardId && full.col_id === prodIn.id, 'même carte (même id) dans la colonne principale de Production');
  ok(full.comments.length === 1 && full.files.length === 2 && full.checks.length === 2 && full.checks[1].done && full.tags.length === 2 && full.due === '2026-12-20', 'commentaires, pièces jointes, check-list et métadonnées conservés');
  ok(full.events.some((e) => e.kind === 'handoff') && full.events.some((e) => e.kind === 'create') && full.events.length >= 5, 'historique complet conservé (création, déplacements, relais)');
  board = await amel.get('board', { studio: studioId });
  ok(board.cards.filter((c) => c.title.startsWith('Reel')).length === 1, 'aucune copie créée par le relais');
  ok(board.cols.find((c) => c.id === scriptOk.id).passed === 1, 'la colonne finale compte les cartes transmises');
  ok(board.cards.find((c) => c.id === cardId).cover === fileToken && board.cards.find((c) => c.id === cardId).n_done === 1, 'aperçu de la carte : image de couverture, check-list');

  // Relais manuel puis désactivé.
  await amel.post('level_save', { id: L[1].id, handoff: 'manual' });
  r = await amel.post('card_move', { id: cardId, col_id: ready.id, index: 0 });
  ok(r.ok && r.handoff === null, 'relais manuel : la carte attend dans la colonne finale');
  full = (await amel.get('card_get', { id: cardId })).card;
  ok(full.can.handoff === true && full.level.target === 'Publication', 'envoi manuel proposé');
  r = await amel.post('card_handoff', { id: cardId });
  ok(r.ok && r.handoff.col_id === pubIn.id, 'envoi manuel vers Publication');
  await amel.post('level_save', { id: L[1].id, handoff: 'off' });
  const c2 = (await amel.post('card_create', { col_id: video.id, title: 'Vidéo test' })).card.id;
  r = await amel.post('card_move', { id: c2, col_id: ready.id, index: 0 });
  ok(r.handoff === null && (await amel.get('card_get', { id: c2 })).card.can.handoff === false, 'relais désactivé : rien ne se passe');
  await amel.post('level_save', { id: L[1].id, handoff: 'auto' });
  r = await amel.post('card_move', { id: cardId, col_id: published.id, index: 0 });
  full = (await amel.get('card_get', { id: cardId })).card;
  ok(full.col.kind === 'done' && full.done_at > 0, 'carte publiée : marquée terminée');
  r = await amel.post('card_save', { id: cardId, publish_url: 'javascript:alert(1)' });
  ok(r.status === 400, 'lien de publication invalide refusé');
  r = await amel.post('card_save', { id: cardId, publish_url: 'https://instagram.com/p/abc', metrics: { views: 12000, likes: 840, bogus: 5 } });
  ok(r.ok && r.card.publish_url === 'https://instagram.com/p/abc' && r.card.metrics.views === 12000 && r.card.metrics.bogus === undefined, 'lien de publication et indicateurs enregistrés');

  /* ------------------------------------------------- niveaux et colonnes */
  step('Niveaux et colonnes personnalisables');
  r = await amel.post('level_save', { studio_id: studioId, name: 'Analyse', icon: '📊', color: 'teal' });
  const lv4 = r.id;
  board = await amel.get('board', { studio: studioId });
  ok(board.levels.length === 4 && board.cols.filter((c) => c.level_id === lv4).length === 1 && board.cols.find((c) => c.level_id === lv4).principal, 'niveau créé avec sa colonne principale');
  await amel.post('level_save', { id: lv4, name: 'Analyse & bilan' });
  await amel.post('level_move', { id: lv4, index: 0 });
  board = await amel.get('board', { studio: studioId });
  ok(board.levels[0].id === lv4 && board.levels[0].name === 'Analyse & bilan', 'niveau renommé et réordonné');
  await amel.post('level_move', { id: lv4, index: 3 });
  r = await amel.post('level_duplicate', { id: L[0].id });
  board = await amel.get('board', { studio: studioId });
  const dup = board.levels.find((l) => l.id === r.id);
  ok(dup && dup.name === 'Creation (copy)' && board.levels[1].id === dup.id && board.cols.filter((c) => c.level_id === dup.id).length === 3 && dup.handoff_col > 0, 'niveau dupliqué (colonnes et colonne finale comprises), placé juste après');
  await amel.post('level_archive', { id: dup.id, archived: true });
  board = await amel.get('board', { studio: studioId });
  ok(!board.levels.find((l) => l.id === dup.id), 'niveau archivé retiré du tableau');
  r = await amel.get('studio_archived', { studio: studioId });
  ok(r.levels.length === 1 && r.levels[0].id === dup.id, 'niveau archivé retrouvé dans les archives');
  r = await amel.post('level_delete', { id: dup.id });
  ok(r.ok, 'niveau vide supprimé');

  const colX = (await amel.post('column_save', { level_id: L[1].id, name: '🎙️ Voix off' })).id;
  await amel.post('column_move', { id: colX, level_id: L[1].id, index: 1 });
  board = await amel.get('board', { studio: studioId });
  ok(board.cols.filter((c) => c.level_id === L[1].id).sort((a, b) => a.pos - b.pos)[1].id === colX, 'colonne créée et réordonnée');
  await amel.post('column_save', { id: colX, name: '🎙️ Voice-over' });
  await amel.post('column_principal', { id: colX });
  board = await amel.get('board', { studio: studioId });
  ok(board.cols.filter((c) => c.level_id === L[1].id && c.principal).length === 1 && board.cols.find((c) => c.id === colX).principal, 'colonne principale changée (une seule par niveau)');
  await amel.post('column_principal', { id: prodIn.id });
  const cX = (await amel.post('card_create', { col_id: colX, title: 'Voix à enregistrer' })).card.id;
  r = await amel.post('column_delete', { id: colX });
  ok(r.status === 409 && r.error === 'not_empty' && r.cards === 1, 'suppression d\'une colonne non vide refusée (où déplacer les cartes ?)');
  await amel.post('column_move', { id: colX, level_id: lv4, index: 1 });
  board = await amel.get('board', { studio: studioId });
  ok(board.cols.find((c) => c.id === colX).level_id === lv4 && board.cards.find((c) => c.id === cX).col_id === colX, 'colonne déplacée vers un autre niveau avec ses cartes');
  r = await amel.post('column_delete', { id: colX, move_to: video.id });
  board = await amel.get('board', { studio: studioId });
  ok(r.ok && board.cards.find((c) => c.id === cX).col_id === video.id, 'colonne supprimée, cartes déplacées');
  const colD = (await amel.post('column_duplicate', { id: video.id })).id;
  await amel.post('column_archive', { id: colD, archived: true });
  ok((await amel.get('studio_archived', { studio: studioId })).cols.some((c) => c.id === colD), 'colonne dupliquée puis archivée');
  r = await amel.post('level_delete', { id: lv4 });
  ok(r.ok, 'niveau supprimé');

  /* ------------------------------------------------ invitations et rôles */
  step('Invitations, rôles et droits');
  r = await sami.get('board', { studio: studioId });
  ok(r.status === 404, 'Studio invisible pour un compte non invité');
  r = await sami.get('card_get', { id: cardId });
  ok(r.status === 404, 'carte invisible pour un compte non invité');
  r = await sami.call('file', undefined, { t: fileToken }, true);
  ok(r.status === 404, 'pièce jointe invisible pour un compte non invité');
  r = await anon.call('file', undefined, { t: fileToken }, true);
  ok(r.status === 404, 'pièce jointe invisible sans connexion');
  r = await amel.call('file', undefined, { t: fileToken, s: 1 }, true);
  ok(r.status === 200 && /image\/webp/.test(r.headers.get('content-type')), 'pièce jointe servie à un membre (ré-encodée en WebP)');
  r = await sami.post('card_move', { id: cardId, col_id: ideas.id, index: 0 });
  ok(r.status === 404, 'déplacement refusé pour un compte non invité');

  // Adresse jamais confirmée (inscription par mot de passe) : quelqu'un a pu créer ce compte avec
  // l'adresse d'une autre personne. Une invitation envoyée à cette adresse ne lui est pas rattachée et
  // reste invisible pour lui ; seul le lien de l'email d'invitation permet de l'ouvrir.
  const confirmLink = (to) => { const m = mails().reverse().find((x) => x.to === to && /Confirm your email/.test(x.subject)); return m && (m.text.match(/\/confirm\/([a-f0-9]{48})/) || [])[1]; };
  r = await karim.get('me');
  ok(r.user.email_ok === false, 'compte créé avec un mot de passe : adresse non confirmée');
  r = await amel.post('invite_create', { studio_id: studioId, email: 'karim@test.example', role: 'collaborator', message: 'Bienvenue !' });
  ok(r.ok && r.invite.kind === 'person' && r.invite.user === null, 'invitation par email créée — adresse non confirmée : rattachée à aucun compte', r.invite);
  const tokenE = r.invite.token;
  ok(mails().some((m) => m.to === 'karim@test.example' && m.text.includes('join/' + tokenE)), 'email d\'invitation avec le lien');
  ok((await karim.get('me')).counts.invites === 0, 'adresse non confirmée : invitation non comptée');
  ok((await karim.get('workspace')).invites.length === 0, '… ni listée (son lien n\'est pas donné)');
  ok(!(await karim.get('notifications')).notifications.some((n) => n.link.includes(tokenE)), '… ni notifiée');
  ok((await karim.get('invite_info', { token: tokenE })).mine === false, 'page de l\'invitation : pas adressée à ce compte (pas de « Decline »)');
  await karim.post('invite_decline', { token: tokenE });
  ok((await anon.get('invite_info', { token: tokenE })).state === 'pending', 'refus sans adresse confirmée : sans effet');
  // Confirmation par le lien reçu, ouvert depuis le compte connecté.
  r = await karim.post('email_confirm_send', {});
  ok(r.ok && r.email === 'karim@test.example', 'lien de confirmation envoyé à l\'adresse du compte');
  const ctoken = confirmLink('karim@test.example');
  ok(!!ctoken, 'email de confirmation avec son lien');
  ok((await anon.post('email_confirm', { token: ctoken })).status === 401, 'confirmation : il faut être connecté');
  r = await sami.post('email_confirm', { token: ctoken });
  ok(r.status === 410 && (await sami.get('me')).user.email_ok === false, 'confirmation : lien refusé depuis un autre compte');
  r = await karim.post('email_confirm', { token: ctoken });
  ok(r.ok && r.user.email_ok === true && r.counts.invites === 1, 'adresse confirmée : l\'invitation apparaît', r.counts);
  r = await karim.get('workspace');
  ok(r.invites.length === 1 && r.invites[0].token === tokenE, '… dans l\'espace de travail, avec son lien');
  ok((await karim.post('email_confirm', { token: ctoken })).already === true, 'lien de confirmation rouvert : sans effet');
  ok((await karim.post('email_confirm_send', {})).status === 400, 'adresse déjà confirmée : pas de nouveau lien');
  ok((await karim.get('invite_info', { token: tokenE })).mine === true, 'page de l\'invitation : adressée à ce compte');
  r = await karim.post('invite_decline', { token: tokenE });
  ok(r.ok && (await anon.get('invite_info', { token: tokenE })).state === 'declined' && (await karim.get('me')).counts.invites === 0, 'adresse confirmée : invitation refusée');

  // Adresse confirmée : l'invitation est rattachée au compte (nominative).
  r = await amel.post('invite_create', { studio_id: studioId, email: 'karim@test.example', role: 'collaborator', message: 'Bienvenue !' });
  ok(r.ok && r.invite.kind === 'person' && r.invite.user && r.invite.user.handle === 'karim-benali', 'adresse confirmée : invitation par email rattachée au compte', r.invite);
  const tokenK = r.invite.token;
  ok(mails().some((m) => m.to === 'karim@test.example' && m.text.includes('join/' + tokenK)), 'email d\'invitation avec le lien');
  ok((await karim.get('notifications')).notifications.some((n) => n.link === 'join/' + tokenK), 'invitation notifiée dans l\'application');
  r = await karim.get('me');
  ok(r.counts.invites === 1, 'invitation visible dans le compte invité');
  r = await anon.get('invite_info', { token: tokenK });
  ok(r.ok && r.valid && r.studio.name === 'Social 2026' && r.role === 'collaborator', 'aperçu de l\'invitation (sans compte)');
  r = await sami.post('invite_accept', { token: tokenK });
  ok(r.status === 403, 'invitation nominative refusée à un autre compte');
  r = await karim.post('invite_accept', { token: tokenK });
  ok(r.ok && r.studio_id === studioId, 'invitation acceptée');
  r = await karim.post('invite_accept', { token: tokenK });
  ok(r.status === 410, 'invitation déjà utilisée refusée');
  r = await karim.get('workspace');
  ok(r.projects.length === 1 && r.projects[0].limited && r.projects[0].studios.length === 1, 'membre d\'un seul Studio : projet visible, limité à ce Studio');

  // Collaborateur : ses cartes seulement.
  r = await karim.post('card_move', { id: c2, col_id: design.id, index: 0 });
  ok(r.status === 403, 'collaborateur : ne déplace pas une carte qui ne lui est pas confiée');
  r = await karim.post('level_save', { studio_id: studioId, name: 'Pirate' });
  ok(r.status === 403, 'collaborateur : ne modifie pas la structure');
  r = await karim.post('invite_create', { studio_id: studioId, role: 'viewer' });
  ok(r.status === 403, 'collaborateur : n\'invite pas');
  const karimId = (await karim.get('me')).user.id;
  r = await amel.post('card_save', { id: c2, assignee: karimId });
  ok(r.ok && r.card.assignee === karimId, 'carte confiée au collaborateur');
  ok((await karim.get('notifications')).notifications.some((n) => n.kind === 'assigned'), 'notification d\'attribution');
  ok(mails().some((m) => m.to === 'karim@test.example' && /assigned/.test(m.subject)), 'email d\'attribution');
  r = await karim.post('card_move', { id: c2, col_id: design.id, index: 0 });
  ok(r.ok, 'collaborateur : déplace la carte qui lui est confiée');
  r = await karim.post('card_save', { id: c2, assignee: 0 });
  ok(r.status === 403, 'collaborateur : ne change pas le responsable');
  r = await karim.post('card_create', { col_id: ideas.id, title: 'Idée de Karim' });
  ok(r.ok && r.card.assignee === karimId, 'collaborateur : crée une carte (il en devient responsable)');
  r = await karim.post('comment_add', { card_id: cardId, body: 'Bravo @amel-haddad, top !' });
  ok(r.ok, 'collaborateur : commente partout');
  ok((await amel.get('notifications')).notifications.some((n) => n.kind === 'mention'), 'mention notifiée');
  r = await karim.get('mywork');
  ok(r.cards.length === 2 && r.cards.every((c) => c.studio_name === 'Social 2026'), 'Mon travail : les cartes confiées, tous Studios confondus');

  // Lien d'invitation réutilisable, rôle cliente.
  r = await amel.post('invite_create', { project_id: projectId, role: 'client' });
  ok(r.ok && r.invite.kind === 'link' && /#\/join\//.test(r.invite.url), 'lien d\'invitation créé');
  const tokenL = r.invite.token;
  r = await lina.post('invite_accept', { token: tokenL });
  ok(r.ok && r.project_id === projectId && r.studio_id === 0, 'lien d\'invitation accepté (membre du projet)');
  const linaId = (await lina.get('me')).user.id;
  r = await lina.post('card_create', { col_id: ideas.id, title: 'Pirate' });
  ok(r.status === 403, 'cliente : ne crée pas de carte');
  r = await lina.post('comment_add', { card_id: c2, body: 'Hâte de voir.' });
  ok(r.ok, 'cliente : commente');
  r = await amel.post('invite_create', { project_id: projectId, role: 'owner' });
  ok(r.ok, 'propriétaire : peut inviter un propriétaire');
  await amel.post('invite_revoke', { id: r.invite.id });
  ok((await sami.post('invite_accept', { token: r.invite.token })).status === 410, 'invitation révoquée inutilisable');
  r = await amel.get('members', { studio_id: studioId });
  ok(r.members.length === 3 && r.members.find((m) => m.id === karimId).via === 'studio' && r.members.find((m) => m.id === linaId).role === 'client', 'liste des membres et de leurs rôles');
  r = await amel.post('member_role', { studio_id: studioId, uid: karimId, role: 'creator' });
  ok(r.ok && (await karim.post('card_move', { id: cX, col_id: design.id, index: 0 })).ok, 'rôle changé : le créateur déplace toutes les cartes');
  r = await karim.post('member_role', { studio_id: studioId, uid: karimId, role: 'manager' });
  ok(r.status === 403, 'un membre ne s\'élève pas lui-même');
  r = await amel.post('member_remove', { project_id: projectId, uid: (await amel.get('me')).user.id });
  ok(r.status === 400, 'le dernier propriétaire ne peut pas partir');

  // Invitation adressée à un compte (Sami), dont l'adresse passe ensuite à un autre compte (Karim) sans
  // y être confirmée : même avec son lien, cet autre compte ne peut pas l'accepter ; confirmée, si.
  const samiId = (await sami.get('me')).user.id;
  r = await amel.post('invite_create', { studio_id: studioId, uid: samiId, role: 'viewer' });
  const tokenS = r.invite.token;
  ok(r.ok && r.invite.user.id === samiId && r.invite.email === 'sami@test.example', 'invitation adressée à un compte (et à son adresse)');
  await sami.post('account_save', { email: 'sami.new@test.example', current: 'a-solid-password' });
  r = await karim.post('account_save', { email: 'sami@test.example', current: 'a-solid-password' });
  ok(r.ok && r.user.email === 'sami@test.example' && r.user.email_ok === false && r.counts.invites === 0, 'nouvelle adresse : non confirmée, l\'invitation qui lui est envoyée n\'apparaît pas');
  ok((await karim.post('invite_accept', { token: tokenS })).status === 403, 'adresse non confirmée : invitation refusée, même avec son lien');
  r = await karim.post('email_confirm', { token: confirmLink('sami@test.example') });
  ok(r.ok && r.user.email_ok === true && r.counts.invites === 1, 'lien de confirmation envoyé à la nouvelle adresse : confirmée, l\'invitation apparaît');
  r = await karim.post('invite_accept', { token: tokenS });
  ok(r.ok && r.studio_id === studioId, 'adresse confirmée : invitation acceptée');
  r = await karim.post('account_save', { email: 'karim@test.example', current: 'a-solid-password' });
  ok(r.ok && r.user.email_ok === false, 'retour à l\'ancienne adresse : à confirmer de nouveau');

  /* ------------------------------------------------------------ validations */
  step('Validations');
  r = await karim.post('approval_request', { card_id: c2, approver: karimId });
  ok(r.status === 400, 'on ne valide pas son propre travail');
  r = await karim.post('approval_request', { card_id: c2, approver: linaId, note: 'Montage terminé.' });
  ok(r.ok, 'validation demandée à la cliente');
  ok((await lina.get('mywork')).approvals.length === 1, 'la demande attend la cliente');
  ok(mails().some((m) => m.to === 'lina@test.example' && /approve/.test(m.subject)), 'email de demande de validation');
  full = (await lina.get('card_get', { id: c2 })).card;
  ok(full.approval === 'pending' && full.can.decide && !full.can.edit, 'cliente : peut décider, pas modifier');
  r = await karim.post('approval_decide', { id: full.approvals[0].id, decision: 'approved' });
  ok(r.status === 403, 'seule la personne sollicitée décide');
  r = await lina.post('approval_decide', { id: full.approvals[0].id, decision: 'changes' });
  ok(r.status === 400, 'demande de modification : explication obligatoire');
  r = await lina.post('approval_decide', { id: full.approvals[0].id, decision: 'changes', reply: 'Raccourcir l\'intro.' });
  ok(r.ok && (await karim.get('card_get', { id: c2 })).card.approval === 'changes', 'modifications demandées');
  await karim.post('approval_request', { card_id: c2, approver: linaId });
  full = (await lina.get('card_get', { id: c2 })).card;
  r = await lina.post('approval_decide', { id: full.approvals[0].id, decision: 'approved' });
  ok(r.ok && r.moved === '🚀 Ready to Publish' && r.handoff && r.handoff.level === 'Publication', 'validée : la carte avance dans la colonne finale et passe au niveau suivant', r);
  full = (await karim.get('card_get', { id: c2 })).card;
  ok(full.col_id === pubIn.id && full.approvals.length === 2 && full.approvals[0].status === 'approved', 'carte dans Publication, validations conservées dans la carte');

  /* -------------------------------------------- archivage, suppression */
  step('Archivage et suppression');
  r = await amel.post('card_duplicate', { id: c3 });
  const c3b = r.id;
  ok(c3b > 0 && (await amel.get('card_get', { id: c3b })).card.title === 'Story coulisses (copy)', 'carte dupliquée');
  await amel.post('card_archive', { id: c3b, archived: true });
  board = await amel.get('board', { studio: studioId });
  ok(!board.cards.find((c) => c.id === c3b) && (await amel.get('studio_archived', { studio: studioId })).cards.some((c) => c.id === c3b), 'carte archivée : hors du tableau, dans les archives');
  await amel.post('card_archive', { id: c3b, archived: false });
  ok((await amel.get('board', { studio: studioId })).cards.some((c) => c.id === c3b), 'carte restaurée');
  r = await karim.post('card_delete', { id: c3b });
  ok(r.status === 403, 'suppression définitive réservée');
  r = await amel.post('cards_bulk', { studio: studioId, ids: [c3, c3b], op: 'move', col_id: scripting.id });
  ok(r.ok && r.count === 2, 'déplacement de plusieurs cartes');
  r = await amel.post('cards_bulk', { studio: studioId, ids: [c3, c3b], op: 'assign', assignee: karimId });
  r = await amel.post('cards_bulk', { studio: studioId, ids: [c3b], op: 'delete' });
  board = await amel.get('board', { studio: studioId });
  ok(r.ok && !board.cards.find((c) => c.id === c3b) && board.cards.find((c) => c.id === c3).assignee === karimId, 'attribution et suppression groupées');
  r = await amel.get('board', { studio: studioId, rev: board.rev });
  ok(r.same === true, 'synchronisation : rien à renvoyer si le tableau n\'a pas changé');
  await karim.post('card_save', { id: c3, priority: 4 });
  r = await amel.get('board', { studio: studioId, rev: board.rev });
  ok(!r.same && r.rev > board.rev && r.cards.find((c) => c.id === c3).priority === 4, 'synchronisation : la modification d\'un coéquipier arrive');
  r = await amel.get('studio_activity', { studio: studioId });
  ok(r.events.length >= 20 && r.events.some((e) => e.kind === 'member'), 'historique du Studio');
  r = await amel.get('studio_stats', { studio: studioId });
  ok(r.totals.done === 1 && r.totals.cards >= 5 && r.levels.length === 3 && Array.isArray(r.load), 'tableau de bord du Studio');
  ok((await karim.get('studio_stats', { studio: studioId })).load === undefined, 'charge par personne réservée aux managers');

  /* ---------------------------------------------------------------- réseau */
  step('Profil, réseau et fil');
  r = await amel.upload('avatar', png, 'image/png');
  const avatar = r.file.token;
  r = await amel.post('profile_save', { name: 'Amel Haddad', handle: 'amel', title: 'Community manager freelance', bio: 'Contenus pour marques sportives.', location: 'Alger',
    skills: ['Community management', 'Scriptwriting'], platforms: ['instagram', 'tiktok'], industries: ['Sports & fitness'], languages: ['Arabic', 'French'], experience: 5,
    availability: 'open', avatar, website: 'amel.example', links: { instagram: 'https://instagram.com/amel', bad: 'javascript:1' }, pro: true });
  ok(r.ok && r.handle === 'amel', 'profil enregistré', r);
  r = await anon.get('profile_get', { handle: 'amel' });
  ok(r.ok && r.profile.title === 'Community manager freelance' && r.profile.avatar === avatar && r.profile.website === 'https://amel.example' && r.profile.links.instagram && !r.profile.links.bad && r.profile.email === undefined, 'profil public lisible sans compte (email non exposé)');
  r = await anon.call('file', undefined, { t: avatar }, true);
  ok(r.status === 200, 'photo de profil publique');
  r = await karim.post('profile_save', { name: 'Karim Benali', handle: 'amel' });
  ok(r.status === 400, 'identifiant déjà pris refusé');
  r = await amel.upload('post', png, 'image/png');
  r = await amel.post('post_create', { body: 'Nouveau reel en ligne ! #velo', media: [r.file.token, fileToken] });
  const postId = r.post.id;
  ok(r.ok && r.post.media.length === 1, 'publication créée (une pièce jointe de Studio ne peut pas y être glissée)');
  await karim.post('follow', { type: 'user', id: r.post.author.id, on: true });
  r = await karim.get('feed', { tab: 'home' });
  ok(r.posts.length === 1 && r.posts[0].id === postId, 'fil : publications des comptes suivis');
  r = await karim.post('post_like', { id: postId, on: true });
  ok(r.likes === 1, 'j\'aime');
  await karim.post('post_comment', { id: postId, body: 'Très réussi.' });
  await karim.post('post_save', { id: postId, on: true });
  r = await karim.post('post_create', { body: 'À voir', share_id: postId });
  ok(r.ok && r.post.shared && r.post.shared.id === postId, 'partage d\'une publication');
  r = await karim.get('feed', { tab: 'saved' });
  ok(r.posts.length === 1 && r.posts[0].liked && r.posts[0].comments === 1, 'publications enregistrées');
  r = await sami.post('post_delete', { id: postId });
  ok(r.status === 403, 'on ne supprime pas la publication d\'un autre');
  await karim.post('connect', { uid: (await amel.get('me')).user.id });
  r = await amel.get('network');
  ok(r.requests.length === 1 && r.followers.length === 1, 'demande de mise en relation reçue');
  await amel.post('connect', { uid: karimId });
  r = await karim.get('network');
  ok(r.connections.length === 1 && r.connections[0].handle === 'amel', 'mise en relation acceptée');
  r = await anon.get('search', { q: 'community' });
  ok(r.people.length === 1 && r.people[0].handle === 'amel', 'recherche de personnes');

  /* ------------------------------------------------------------- portfolio */
  step('Portfolio, autorisations et preuves de travail');
  r = await amel.post('portfolio_save', { title: 'Lancement boutique', brand: 'Kivo', role: 'Stratégie et contenus', objectives: 'Faire connaître la marque', results: '+12 % d\'abonnés',
    metrics: [{ label: 'Reach', value: '1.2M' }], links: ['https://instagram.com/p/xyz', 'javascript:alert(1)'], platforms: ['instagram'], publish: true });
  const pf1 = r.id;
  ok(r.ok && r.state === 'published', 'étude de cas publiée (déclarée par l\'auteur)');
  r = await anon.get('profile_get', { handle: 'amel' });
  ok(r.portfolio.length === 1 && r.portfolio[0].links.length === 1 && !r.portfolio[0].verified && r.proof.self === 1 && r.proof.studio === 0, 'profil : étude de cas « déclarée », preuves distinguées');
  // Étude de cas tirée du Studio : le projet a une cliente → son accord est exigé.
  r = await karim.post('portfolio_from_studio', { studio: studioId, card_ids: [c2] });
  ok(r.status === 400, 'seules les cartes publiées deviennent une étude de cas');
  r = await sami.post('portfolio_from_studio', { studio: studioId, card_ids: [cardId] });
  ok(r.status === 404, 'un compte étranger ne peut rien tirer du Studio');
  r = await amel.post('portfolio_from_studio', { studio: studioId, card_ids: [cardId] });
  const pf2 = r.id;
  ok(r.ok && r.need_client === true && r.need_owner === false, 'brouillon créé depuis le Studio (accord de la cliente exigé)', r);
  r = await anon.get('profile_get', { handle: 'amel' });
  ok(r.portfolio.length === 1, 'brouillon invisible sur le profil public');
  r = await amel.get('profile_get', { handle: 'amel' });
  const draft = r.portfolio.find((p) => p.id === pf2);
  ok(draft && draft.status === 'draft' && draft.verified && draft.pieces === 1 && draft.media.length === 1 && draft.metrics.some((m) => m.label === 'Views'), 'brouillon : lien, image et indicateurs repris de la carte');
  r = await anon.call('file', undefined, { t: draft.media[0].token }, true);
  ok(r.status === 404, 'image du brouillon privée');
  r = await amel.post('portfolio_save', { id: pf2, title: 'Vélora — reels débutants', brand: 'Vélora', results: '12 000 vues', metrics: draft.metrics, media: draft.media.map((m) => m.token), links: draft.links, platforms: draft.platforms, publish: true });
  ok(r.ok && r.state === 'pending', 'publication en attente de l\'accord de la cliente');
  ok((await anon.get('profile_get', { handle: 'amel' })).portfolio.length === 1, 'toujours invisible tant que la cliente n\'a pas autorisé');
  r = await lina.get('requests');
  ok(r.authorizations.length === 1 && r.authorizations[0].as === 'client', 'la cliente voit la demande d\'autorisation');
  r = await karim.post('portfolio_authorize', { id: pf2, approve: true });
  ok(r.status === 404, 'un simple membre ne peut pas autoriser');
  r = await lina.post('portfolio_authorize', { id: pf2, approve: true });
  ok(r.ok && r.state === 'published', 'autorisée par la cliente : publiée');
  r = await anon.get('profile_get', { handle: 'amel' });
  const pub = r.portfolio.find((p) => p.id === pf2);
  ok(pub && pub.verified && pub.confirmed_by && pub.confirmed_by.handle === 'lina-mansouri' && r.proof.studio === 1 && r.proof.client === 1 && r.proof.pieces === 1, 'profil : étude de cas vérifiée par le Studio et confirmée par la cliente');
  ok((await anon.call('file', undefined, { t: pub.media[0].token }, true)).status === 200, 'image de l\'étude de cas devenue publique');
  await amel.post('portfolio_confirm_ask', { id: pf1, handle: 'lina-mansouri' });
  r = await lina.get('requests');
  ok(r.confirmations.length === 1, 'demande de confirmation des résultats reçue');
  await lina.post('portfolio_confirm', { id: pf1, confirm: true });
  ok((await anon.get('profile_get', { handle: 'amel' })).proof.client === 2, 'résultats confirmés par la cliente');
  r = await lina.post('recommend', { uid: (await amel.get('me')).user.id, relation: 'client', body: 'Amel a transformé notre présence en ligne en trois mois.' });
  ok(r.ok && (await anon.get('profile_get', { handle: 'amel' })).recommendations.length === 0, 'recommandation en attente : invisible');
  r = await amel.get('profile_get', { handle: 'amel' });
  ok(r.recommendations.length === 1 && r.recommendations[0].together === true, 'recommandation reçue (« ont travaillé ensemble » vérifié)');
  await amel.post('recommend_set', { id: r.recommendations[0].id, status: 'visible' });
  r = await anon.get('profile_get', { handle: 'amel' });
  ok(r.recommendations.length === 1 && r.proof.client_recommendations === 1 && r.proof.activity === undefined, 'recommandation affichée ; activité des Studios non exposée par défaut');
  await amel.post('account_save', { settings: { show_activity: true } });
  r = await anon.get('profile_get', { handle: 'amel' });
  ok(r.proof.activity && r.proof.activity.projects === 1 && JSON.stringify(r).indexOf('Social 2026') === -1, 'activité affichée sur choix, sans nom de projet ni de Studio');

  /* ----------------------------------------- entreprises et opportunités */
  step('Entreprises, opportunités, talents, messages');
  r = await lina.get('me');
  const company = r.companies[0];
  r = await lina.post('company_save', { id: company.id, name: 'Dar Lina', tagline: 'Maison & déco', industry: 'E-commerce & retail', location: 'Oran', size: '11-50', website: 'darlina.example' });
  ok(r.ok && r.slug === 'dar-lina', 'page entreprise enregistrée');
  r = await sami.post('company_save', { id: company.id, name: 'Pirate' });
  ok(r.status === 403, 'page entreprise : réservée à ses administrateurs');
  r = await amel.post('opp_save', { company_id: company.id, title: 'x', body: 'y' });
  ok(r.status === 403, 'opportunité : réservée aux entreprises');
  r = await lina.post('opp_save', { company_id: company.id, title: 'Community manager Instagram', body: 'Nous cherchons une personne pour animer notre compte Instagram au quotidien.',
    skills: ['Community management', 'Copywriting'], mode: 'remote', contract: 'freelance', apply_kind: 'inapp' });
  const oppId = r.id;
  ok(r.ok && oppId > 0, 'opportunité publiée');
  r = await anon.get('opps', { q: 'instagram', mode: 'remote' });
  ok(r.opps.length === 1 && r.opps[0].company.name === 'Dar Lina', 'opportunités : recherche et filtres');
  r = await amel.post('opp_apply', { id: oppId, message: 'Bonjour, cinq ans d\'expérience en community management.' });
  ok(r.ok && (await amel.post('opp_apply', { id: oppId, message: 'Encore moi, pour être sûre de candidater.' })).status === 400, 'candidature envoyée (une seule fois)');
  r = await lina.get('opp_get', { id: oppId });
  ok(r.applications.length === 1 && r.applications[0].user.handle === 'amel', 'l\'entreprise voit les candidatures');
  ok((await amel.get('opp_get', { id: oppId })).applications === undefined, 'les candidatures restent privées');
  await lina.post('opp_application', { id: r.applications[0].id, status: 'shortlisted' });
  ok((await amel.get('notifications')).notifications.some((n) => n.kind === 'opp'), 'candidate prévenue de la présélection');
  r = await lina.get('talent', { skill: 'Scriptwriting', platform: 'instagram', language: 'French', portfolio: 1 });
  ok(r.people.length === 1 && r.people[0].handle === 'amel' && r.people[0].proof.studio === 1 && r.people[0].cases === 2, 'annuaire des talents : filtres et preuves de travail');
  ok((await lina.get('talent', { skill: 'Podcasting' })).people.length === 0, 'annuaire : filtre sans résultat');
  await lina.post('talent_save', { uid: r.people[0].id, on: true });
  ok((await lina.get('talent', { saved: 1 })).people.length === 1, 'professionnel enregistré');
  r = await lina.post('invite_create', { project_id: projectId, uid: karimId, role: 'viewer' });
  ok(r.status === 403, 'une cliente n\'invite pas dans le projet d\'un autre');
  r = await lina.post('project_save', { name: 'Dar Lina — social', company_id: company.id, template: 'kanban' });
  r = await lina.post('invite_create', { project_id: r.id, uid: (await amel.get('me')).user.id, role: 'creator', message: 'Travaillons ensemble !' });
  ok(r.ok && (await amel.get('workspace')).invites.length === 1, 'invitation à collaborer envoyée à une professionnelle');
  r = await lina.post('message_send', { uid: (await amel.get('me')).user.id, body: 'Bonjour Amel !' });
  ok(r.ok && (await amel.get('me')).counts.messages === 1, 'message privé reçu');
  r = await amel.get('thread', { with: 'lina-mansouri' });
  ok(r.messages.length === 1 && !r.messages[0].mine && (await amel.get('me')).counts.messages === 0, 'conversation lue');
  r = await sami.get('thread', { with: 'lina-mansouri' });
  ok(r.messages.length === 0, 'conversation invisible pour un tiers');

  /* ------------------------------------------------ mot de passe, départ */
  /* ------------------------------------------------- connexion avec Google */
  step('Continue with Google');
  const G = require('./google.js');
  const google = G.setup(DIR);
  const gtok = google.token;
  r = await anon.get('me', { ref: 1 });
  ok(r.ref.google_client_id === G.CLIENT_ID, 'ID client Google transmis à l\'application');
  const bad = async (cred, label) => { const c = new Client('g-bad'); const x = await c.post('google', { credential: cred }); ok(x.status === 401 && x.error === 'google_bad' && !c.cookie, label); };
  await bad('not.a.token', 'jeton illisible refusé');
  await bad(gtok({ sub: 'g-1', email: 'nora@test.example' }, { key: google.otherKey() }), 'signature falsifiée refusée');
  await bad(gtok({ sub: 'g-1', email: 'nora@test.example', aud: 'other.apps.googleusercontent.com' }), 'jeton destiné à une autre application refusé');
  await bad(gtok({ sub: 'g-1', email: 'nora@test.example', exp: Math.floor(Date.now() / 1000) - 3600 }), 'jeton expiré refusé');
  await bad(gtok({ sub: 'g-1', email: 'nora@test.example', iss: 'https://evil.example' }), 'jeton d\'un autre émetteur refusé');
  await bad(gtok({ sub: 'g-1', email: 'nora@test.example', email_verified: false }), 'adresse non confirmée par Google refusée');

  const nora = new Client('nora');
  r = await nora.post('google', { credential: gtok({ sub: 'g-nora', email: 'Nora@Test.example', name: 'Nora Belkacem' }) });
  ok(r.ok && r.created && r.user.name === 'Nora Belkacem' && r.user.email === 'nora@test.example' && r.user.google && !r.user.has_password && r.csrf, 'nouvelle identité Google : compte créé, connecté, sans mot de passe', r.user);
  const noraId = r.user.id;
  ok((await nora.get('workspace')).ok, 'session ouverte par Google utilisable');
  ok(mails().some((m) => m.to === 'nora@test.example' && /Welcome/.test(m.subject)), 'email de bienvenue (inscription avec Google)');
  const nora2 = new Client('nora2');
  r = await nora2.post('google', { credential: gtok({ sub: 'g-nora', email: 'nora@test.example' }) });
  ok(r.ok && !r.created && r.user.id === noraId, 'même identité Google : même compte, pas de doublon');
  r = await anon.post('login', { email: 'nora@test.example', password: 'whatever-password' });
  ok(r.status === 401 && r.error === 'use_google', 'connexion par mot de passe : invite à utiliser Google');
  r = await nora.post('google_unlink', {});
  ok(r.status === 400, 'Google ne peut pas être retiré tant qu\'il n\'y a pas de mot de passe');
  r = await nora.post('account_save', { email: 'nora2@test.example' });
  ok(r.status === 400, 'changement d\'adresse : mot de passe à choisir d\'abord');
  r = await nora.post('password', { password: 'nora-solid-password' });
  ok(r.ok, 'mot de passe choisi sans « ancien » mot de passe');
  r = await new Client('nora3').post('login', { email: 'nora@test.example', password: 'nora-solid-password' });
  ok(r.ok && r.user.google && r.user.has_password, 'connexion par mot de passe désormais possible aussi');
  r = await nora.post('password', { password: 'another-solid-password' });
  ok(r.status === 400, 'changer ensuite le mot de passe demande l\'ancien');
  r = await nora.post('google_unlink', {});
  ok(r.ok && !r.user.google, 'Google retiré du compte');
  r = await new Client('nora4').post('google', { credential: gtok({ sub: 'g-nora', email: 'nora@test.example' }) });
  ok(r.ok && !r.created && r.user.id === noraId && !r.password_reset && r.user.has_password, 'Google à nouveau : relié par l\'adresse (confirmée), mot de passe gardé');

  // Compte créé avec l'adresse de quelqu'un d'autre, avant que cette personne n'arrive.
  const squatter = new Client('squatter');
  r = await squatter.signup('Victim Name', 'victim@test.example');
  ok(r.ok, 'compte créé par un tiers avec l\'adresse d\'une autre personne (adresse non confirmée)');
  const victim = new Client('victim');
  r = await victim.post('google', { credential: gtok({ sub: 'g-victim', email: 'victim@test.example', name: 'Real Victim' }) });
  ok(r.ok && !r.created && r.password_reset && !r.user.has_password, 'Google prouve l\'adresse : compte relié, mot de passe posé par le tiers retiré');
  ok((await squatter.get('workspace')).status === 401, 'la session du tiers est fermée');
  r = await new Client('squatter2').post('login', { email: 'victim@test.example', password: 'a-solid-password' });
  ok(r.status === 401, 'le tiers ne peut plus se connecter avec son mot de passe');
  r = await new Client('other').post('google', { credential: gtok({ sub: 'g-impostor', email: 'victim@test.example' }) });
  ok(r.status === 409 && r.error === 'google_other', 'une autre identité Google avec la même adresse est refusée');

  // Relier Google depuis les réglages d'un compte créé par email.
  const pia = new Client('pia');
  await pia.signup('Pia Ferhat', 'pia@test.example');
  r = await pia.post('google_link', { credential: gtok({ sub: 'g-pia', email: 'pia@test.example' }) });
  ok(r.ok && r.user.google && r.user.has_password, 'Google relié depuis les réglages');
  r = await nora.post('google_link', { credential: gtok({ sub: 'g-pia', email: 'pia@test.example' }) });
  ok(r.status === 409, 'une identité Google déjà reliée à un autre compte est refusée');
  r = await anon.post('google_link', { credential: gtok({ sub: 'g-x', email: 'x@test.example' }) });
  ok(r.status === 401, 'relier Google exige d\'être connecté');
  ok((await pia.get('me')).user.email_ok === true, 'Google relié avec la même adresse : adresse confirmée');
  // Changement d'adresse : la nouvelle n'est confirmée ni par un lien envoyé à l'ancienne, ni par Google.
  await anon.post('forgot', { email: 'pia@test.example' });
  const pmail = mails().reverse().find((m) => m.to === 'pia@test.example' && /Reset/.test(m.subject));
  const ptoken = pmail && (pmail.text.match(/reset\/([a-f0-9]{48})/) || [])[1];
  r = await pia.post('account_save', { email: 'pia.other@test.example', current: 'a-solid-password' });
  ok(r.ok && r.user.email === 'pia.other@test.example' && r.user.email_ok === false, 'nouvelle adresse : non confirmée');
  ok(mails().some((m) => m.to === 'pia.other@test.example' && /Confirm your email/.test(m.subject)), 'lien de confirmation envoyé à la nouvelle adresse');
  r = await new Client('pia-reset').post('reset', { token: ptoken, password: 'pia-new-password' });
  ok(!!ptoken && r.status === 410, 'lien de mot de passe oublié envoyé à l\'ancienne adresse : annulé');
  r = await new Client('pia2').post('google', { credential: gtok({ sub: 'g-pia', email: 'pia@test.example' }) });
  ok(r.ok && r.user.email === 'pia.other@test.example' && r.user.email_ok === false, 'connexion avec Google : une adresse qui n\'est pas celle de Google reste non confirmée');

  // Inscription d'une entreprise avec Google.
  const co = new Client('co');
  r = await co.post('google', { credential: gtok({ sub: 'g-co', email: 'hello@brand.example', name: 'Brand Owner' }), kind: 'company' });
  ok(r.status === 400 && !co.cookie, 'entreprise avec Google : nom de l\'entreprise exigé');
  r = await co.post('google', { credential: gtok({ sub: 'g-co', email: 'hello@brand.example', name: 'Brand Owner' }), kind: 'company', company: 'Brand Co' });
  ok(r.ok && r.created && r.user.kind === 'company' && r.companies.length === 1 && r.companies[0].name === 'Brand Co', 'entreprise créée avec Google, avec sa page');
  r = await co.post('account_delete', { confirm: 'wrong@test.example' });
  ok(r.status === 400, 'suppression d\'un compte sans mot de passe : adresse à retaper');
  r = await co.post('account_delete', { confirm: 'hello@brand.example' });
  ok(r.ok && (await co.get('workspace')).status === 401, 'compte sans mot de passe supprimé');

  step('Mot de passe oublié, suppression');
  await anon.post('forgot', { email: 'karim@test.example' });
  const link = mails().reverse().find((m) => m.to === 'karim@test.example' && /Reset/.test(m.subject));
  const rtoken = link && (link.text.match(/reset\/([a-f0-9]{48})/) || [])[1];
  const k2 = new Client('karim2');
  r = await k2.post('reset', { token: rtoken, password: 'another-solid-password' });
  ok(r.ok && r.user.handle === 'karim-benali', 'mot de passe réinitialisé par le lien reçu');
  ok((await karim.get('workspace')).status === 401, 'anciennes sessions fermées');
  r = await amel.post('project_delete', { id: projectId, confirm: 'mauvais nom' });
  ok(r.status === 400, 'suppression du projet : nom à confirmer');
  r = await k2.post('project_delete', { id: projectId, confirm: 'Vélora' });
  ok(r.status === 404 || r.status === 403, 'suppression du projet réservée à son propriétaire');
  r = await amel.post('project_delete', { id: projectId, confirm: 'Vélora' });
  ok(r.ok && (await amel.get('board', { studio: studioId })).status === 404, 'projet supprimé avec ses Studios');
  ok((await amel.call('file', undefined, { t: fileToken }, true)).status === 404, 'pièces jointes supprimées avec le projet');
  for (const f of ['lib/core.php', 'config.php', '.user.ini', 'lib/']) {
    r = await fetch(ORIGIN + '/studioma/' + f);
    ok(r.status === 403, 'fichier interne interdit : ' + f);
  }

  console.log('\n' + (failed ? failed + ' échec(s)' : 'Tout est bon.'));
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
