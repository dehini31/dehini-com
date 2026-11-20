/*
 * Tests du partage d'un Studio avec des invités sans compte (API seule), sur le faux hébergement de
 * run.sh : liens public et privé, lecture seule par défaut, vérification de l'adresse (code à usage
 * unique, lien magique), demande de droits et réponse du responsable, droits Viewer / Commenter /
 * Editor et périmètre d'édition, validation demandée à un invité, emails (demande, décision,
 * modifications), retrait d'accès, lien désactivé, expiré ou régénéré, journal. Chaque règle est
 * vérifiée côté serveur, en appelant l'API comme le ferait un navigateur malveillant.
 *   node tests/studioma/share.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 500) : '')); } };
const step = (t) => console.log('\n' + t);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
const mailsTo = (to) => mails().filter((m) => m.to === to);
const lastTo = (to) => mailsTo(to).pop();
/** Requête SQL directe (pour simuler le temps qui passe : expiration, fenêtre des emails). */
const sql = (query) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, query]);

/** Membre connecté (cookie de session + CSRF). */
class Member {
  constructor() { this.cookie = ''; this.csrf = ''; }
  async call(action, body, params) {
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const init = { headers: this.cookie ? { Cookie: this.cookie } : {} };
    if (body !== undefined) Object.assign(init, { method: 'POST', body: JSON.stringify(body) }), Object.assign(init.headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf });
    const res = await fetch(url, init);
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => { if (c.startsWith('stm_sid=')) this.cookie = c.split(';')[0]; });
    const d = await res.json().catch(() => ({ error: 'not-json' }));
    if (typeof d.status === 'string') d.state = d.status;
    d.status = res.status;
    if (d.csrf) this.csrf = d.csrf;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
  async upload(file, type, extra) {
    const fd = new FormData();
    fd.append('kind', 'card');
    Object.entries(extra).forEach(([k, v]) => fd.append(k, String(v)));
    fd.append('file', new Blob([fs.readFileSync(file)], { type }), path.basename(file));
    const res = await fetch(API + '?action=upload', { method: 'POST', body: fd, headers: { Cookie: this.cookie, 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf } });
    return res.json();
  }
}
/** Invité : pas de compte ; le jeton du lien dans l'en-tête X-Share, ses cookies d'invité (stm_g<id>). */
class Guest {
  constructor(token) { this.token = token; this.jar = {}; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  async call(action, body, params, opts) {
    opts = opts || {};
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const headers = {};
    if (!opts.noShare) headers['X-Share'] = opts.token || this.token;
    if (this.cookies()) headers.Cookie = this.cookies();
    const init = { headers };
    if (body !== undefined) {
      init.method = 'POST';
      init.body = JSON.stringify(body);
      headers['Content-Type'] = 'application/json';
      if (!opts.noXrw) headers['X-Requested-With'] = 'studioma';
    }
    const res = await fetch(url, init);
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => {
      const [kv] = c.split(';');
      const [k, v] = kv.split('=');
      if (/^stm_g\d+$/.test(k)) { if (v) this.jar[k] = v; else delete this.jar[k]; }
    });
    if (opts.raw) return res;
    const d = await res.json().catch(() => ({ error: 'not-json' }));
    if (typeof d.status === 'string') d.state = d.status;
    d.status = res.status;
    return d;
  }
  get(a, p, o) { return this.call(a, undefined, p, o); }
  post(a, b, o) { return this.call(a, b || {}, undefined, o); }
  /** Fichier par le paramètre « share » (comme une balise <img>). */
  file(t, token) { return fetch(API + '?action=file&t=' + t + '&share=' + (token || this.token), { headers: this.cookies() ? { Cookie: this.cookies() } : {} }).then((r) => r.status); }
}
const codeIn = (m) => (m && m.text.match(/\b(\d{6})\b/) || [])[1];
const linkIn = (m) => (m && m.text.match(/\/studioma\/s\/[A-Za-z0-9_-]{32}\?v=([a-f0-9]{48})/) || [])[1];

(async () => {
  step('Préparation : un Studio, des cartes dans chaque niveau, une pièce jointe');
  const amel = new Member();
  let r = await amel.post('signup', { name: 'Amel Haddad', email: 'amel@test.example', password: 'a-solid-password', accept: true });
  r = await amel.post('project_save', { name: 'Vélora', template: 'content', studio_name: 'Social 2026' });
  const sid = r.studio_id;
  let b = await amel.get('board', { studio: sid });
  const lv = (name) => b.levels.find((l) => l.name.includes(name));
  const col = (level, name) => b.cols.find((c) => c.level_id === lv(level).id && c.name.includes(name));
  const ideas = col('Creation', 'Ideas');
  const scripting = col('Creation', 'Script in Progress');
  const prodFirst = col('Production', 'Script Approved');
  const pubFirst = col('Publication', 'Ready');
  const mk = async (c, title) => (await amel.post('card_create', { col_id: c.id, title })).card.id;
  const cIdea = await mk(ideas, 'Reel: 5 beginner mistakes');
  const cIdea2 = await mk(ideas, 'Carousel: maintenance');
  const cScript = await mk(scripting, 'Story: behind the scenes');
  const cProd = await mk(prodFirst, 'Video: spring launch');
  const cPub = await mk(pubFirst, 'Post: launch day');
  r = await amel.upload(path.join(DIR, 'notes.txt'), 'text/plain', { card_id: cIdea });
  const fileOk = r.file && r.file.token;
  r = await amel.upload(path.join(DIR, 'notes.txt'), 'text/plain', { card_id: cPub });
  const fileHidden = r.file && r.file.token;
  await amel.post('comment_add', { card_id: cIdea, body: 'Internal note from Amel' });
  r = await amel.post('studio_create', { project_id: (await amel.get('board', { studio: sid })).studio.project.id, name: 'Other Studio', template: 'kanban' });
  const otherSid = r.id;
  const otherCard = (await amel.post('card_create', { col_id: (await amel.get('board', { studio: otherSid })).cols[0].id, title: 'Secret plan' })).card.id;
  const karim = new Member();
  await karim.post('signup', { name: 'Karim Benali', email: 'karim@test.example', password: 'a-solid-password', accept: true });
  ok(sid && cIdea && cPub && fileOk && fileHidden && otherCard, 'Studio, 5 cartes, 2 pièces jointes, un autre Studio');

  step('Le responsable crée les liens (seul un manager le peut)');
  r = await amel.get('share_list', { studio: sid });
  ok(r.status === 200 && r.links.public === null && r.links.private === null && r.guests.length === 0, 'aucun lien au départ');
  r = await karim.get('share_list', { studio: sid });
  ok(r.status === 404, 'un compte sans accès au Studio : introuvable');
  r = await amel.post('share_save', { studio: sid, kind: 'public', levels: [lv('Creation').id, lv('Production').id] });
  const pub = r.links && r.links.public;
  const T = pub && pub.url.split('/s/')[1];
  ok(r.status === 200 && pub && /\/studioma\/s\/[A-Za-z0-9_-]{32}$/.test(pub.url) && !/#/.test(pub.url) && pub.active && pub.levels.length === 2 && r.history.some((h) => /created the public link/.test(h.text)), 'lien public : /studioma/s/<clé de 32 caractères (192 bits)>, niveaux Creation et Production', r);
  r = await amel.post('share_save', { studio: sid, kind: 'private' });
  const priv = r.links.private;
  const P = priv.url.split('/s/')[1];
  ok(priv && P !== T && priv.levels.length === 0, 'lien privé : un autre jeton, tous les niveaux');
  r = await amel.post('share_save', { studio: sid, kind: 'weird' });
  ok(r.status === 400, 'type de lien inconnu : refusé');

  step('Le lien propre (/studioma/s/<clé>) renvoie vers l\'application, sans fuite de la clé');
  const S0 = ORIGIN + '/studioma/s/';
  let res = await fetch(S0 + T, { redirect: 'manual' });
  ok(res.status === 302 && res.headers.get('location') === ORIGIN + '/studioma/app/#/s/' + T && res.headers.get('referrer-policy') === 'no-referrer' && /no-store/.test(res.headers.get('cache-control')) && /noindex/.test(res.headers.get('x-robots-tag') || ''), 'redirection vers l\'application ; no-referrer, jamais en cache ni indexé');
  res = await fetch(S0 + T + '/card/' + cIdea + '?v=' + 'c'.repeat(48), { redirect: 'manual' });
  ok(res.status === 302 && res.headers.get('location').endsWith('#/s/' + T + '/card/' + cIdea + '?v=' + 'c'.repeat(48)), 'lien d\'une carte et lien magique gardés');
  res = await fetch(S0 + T + '?v=<script>', { redirect: 'manual' });
  ok(res.status === 404, 'paramètre inattendu : refusé');
  res = await fetch(S0 + 'short', { redirect: 'manual' });
  ok(res.status === 404, 'clé mal formée : refusée');
  // fetch ne laisse pas choisir l'en-tête Host : on passe par le module http.
  r = await new Promise((resolve, reject) => {
    const u = new URL(API + '?action=share_list&studio=' + sid);
    require('http').get({ host: u.hostname, port: u.port, path: u.pathname + u.search, headers: { Cookie: amel.cookie, Host: 'dehini.com' } }, (x) => {
      let body = '';
      x.on('data', (c) => { body += c; });
      x.on('end', () => resolve(JSON.parse(body)));
    }).on('error', reject);
  });
  ok(r.links && /^https:\/\/dehini\.com\/studioma\/s\/[A-Za-z0-9_-]{32}$/.test(r.links.public.url), 'sur un vrai domaine, le lien est toujours en https', r.links && r.links.public.url);

  step('Lien public : lecture seule, sans compte, limitée au Studio et aux niveaux partagés');
  const anon = new Guest(T);
  r = await anon.get('share_open');
  ok(r.status === 200 && r.share.studio === 'Social 2026' && r.share.kind === 'public' && r.share.by.name === 'Amel Haddad' && r.guest.verified === false && r.guest.role === 'viewer', 'share_open : Studio, auteur du partage, visiteur non vérifié');
  b = await anon.get('board', { studio: sid });
  ok(b.status === 200 && b.levels.length === 2 && b.levels.every((l) => !/Publication/.test(l.name)) && !b.cards.some((c) => c.id === cPub) && b.cards.some((c) => c.id === cProd), 'tableau : le niveau Publication et ses cartes sont absents', b.levels);
  ok(b.studio.can.comment === false && b.studio.can.work === false && b.studio.can.edit === false && b.studio.can.manage === false && b.cards.every((c) => c.editable === false) && b.cols.every((c) => c.writable === false), 'lecture seule par défaut (aucun droit, aucune colonne ni carte modifiable)');
  ok(Array.isArray(b.members) && b.members.length <= 1 && !JSON.stringify(b).includes('@test.example') && !('guests' in b), 'aucune adresse email ; équipe limitée aux personnes visibles sur les cartes');
  r = await anon.get('card_get', { id: cIdea });
  ok(r.status === 200 && r.card.can.edit === false && r.card.can.comment === false && r.card.can.upload === false && r.card.can.request === false && r.card.comments.length === 1, 'carte partagée : lisible, rien de modifiable');
  r = await anon.get('card_get', { id: cPub });
  ok(r.status === 404, 'carte d\'un niveau non partagé : introuvable');
  r = await anon.get('card_get', { id: otherCard });
  ok(r.status === 404, 'carte d\'un autre Studio : introuvable');
  r = await anon.get('board', { studio: otherSid });
  ok(r.status === 404, 'autre Studio avec le même lien : introuvable');
  ok(await anon.file(fileOk) === 200, 'pièce jointe d\'une carte partagée : téléchargeable avec le lien');
  ok(await anon.file(fileHidden) === 404, 'pièce jointe d\'un niveau non partagé : refusée');
  ok(await anon.file(fileOk, 'f'.repeat(32)) === 404, 'pièce jointe avec un faux jeton : refusée');
  r = await fetch(API + '?action=file&t=' + fileOk).then((x) => x.status);
  ok(r === 404, 'pièce jointe sans lien ni compte : refusée');
  for (const [action, body] of [['card_save', { id: cIdea, title: 'Hacked' }], ['comment_add', { card_id: cIdea, body: 'Hi' }], ['card_create', { col_id: ideas.id, title: 'Spam' }], ['card_move', { id: cIdea, col_id: scripting.id, index: 0 }], ['check_save', { card_id: cIdea, text: 'x' }]]) {
    r = await anon.post(action, body);
    ok(r.status === 403 && r.error === 'guest_verify', action + ' sans adresse vérifiée : refusé', r);
  }
  for (const [action, body, params] of [['workspace'], ['members', null, { studio_id: sid }], ['share_list', null, { studio: sid }], ['card_archive', { id: cIdea, archived: true }], ['card_delete', { id: cIdea }], ['studio_save', { id: sid, name: 'X' }], ['invite_create', { studio_id: sid, email: 'x@y.z', role: 'owner' }], ['column_save', { level_id: lv('Creation').id, name: 'X' }], ['approval_request', { card_id: cIdea, approver: 1 }], ['cards_bulk', { studio: sid, ids: [cIdea], op: 'archive' }], ['profile_get', null, { handle: 'amel-haddad' }]]) {
    r = body ? await anon.post(action, body) : await anon.get(action, params);
    ok(r.status === 403 && r.error === 'forbidden', action + ' avec un lien de partage : hors de la liste des actions permises', r);
  }
  r = await anon.get('board', { studio: sid }, { token: 'a'.repeat(32) });
  ok(r.status === 404 && r.error === 'share_gone', 'jeton inventé : lien introuvable');
  r = await anon.get('board', { studio: sid }, { token: T.slice(0, 24) });
  ok(r.status === 404, 'jeton tronqué : refusé');
  r = await anon.post('comment_add', { card_id: cIdea, body: 'x' }, { noXrw: true });
  ok(r.status === 403 && r.error === 'forbidden', 'écriture sans l\'en-tête X-Requested-With : refusée');
  r = await (new Member()).get('card_get', { id: cIdea });
  ok(r.status === 401, 'sans lien ni compte : rien n\'est lisible');

  step('Demande de droits : adresse vérifiée par un code à usage unique');
  const client = new Guest(T);
  r = await client.post('share_code', { email: 'not-an-email' });
  ok(r.status === 400, 'adresse invalide : refusée');
  r = await client.post('share_code', { email: 'Client@Brand.example', request: 'editor', note: 'I want to fix the captions' });
  let m = lastTo('client@brand.example');
  const code1 = codeIn(m);
  ok(r.status === 200 && r.sent && code1 && /code/i.test(m.subject) && linkIn(m) && !/password/i.test(m.text.replace('No account or password is needed', '')), 'code à 6 chiffres et lien magique envoyés (adresse mise en minuscules)', m);
  for (let i = 1; i <= 4; i++) {
    r = await client.post('share_verify', { email: 'client@brand.example', code: String((+code1 + i) % 1000000).padStart(6, '0') });
    ok(r.status === 400 && r.error === 'code', 'code faux n°' + i + ' : refusé');
  }
  r = await client.post('share_verify', { email: 'client@brand.example', code: '000000' === code1 ? '111111' : '000000' });
  ok(r.status === 400 && /Too many/.test(r.message), '5e code faux : le code ne vaut plus');
  r = await client.post('share_verify', { email: 'client@brand.example', code: code1 });
  ok(r.status === 410, '… même le bon code est alors refusé');
  ok(!Object.keys(client.jar).length, 'aucune session d\'invité ouverte');
  r = await client.post('share_code', { email: 'client@brand.example', request: 'editor', note: 'I want to fix the captions' });
  const code2 = codeIn(lastTo('client@brand.example'));
  r = await client.post('share_verify', { email: 'client@brand.example', code: code2 });
  ok(r.status === 200 && r.guest.verified && r.guest.email === 'client@brand.example' && r.guest.role === 'viewer' && r.guest.pending === 'editor' && r.requested === 'pending' && Object.keys(client.jar).length === 1, 'bon code : session d\'invité (cookie), demande d\'édition enregistrée', r);
  r = await client.post('share_verify', { email: 'client@brand.example', code: code2 });
  ok(r.status === 410, 'code déjà utilisé : refusé');
  m = lastTo('amel@test.example');
  ok(m && /client@brand\.example asks to edit Social 2026/.test(m.subject) && /email verified/.test(m.text) && /fix the captions/.test(m.text) && /Approve as Editor/.test(m.text) && /Reject the request/.test(m.text) && /guests=1&req=\d+&do=approve/.test(m.text), 'email au responsable : adresse vérifiée, Studio, message, liens Examiner / Approuver / Refuser', m);
  r = await amel.get('notifications');
  ok(r.notifications.some((n) => n.kind === 'guest' && n.guest === 'client@brand.example' && /asks for editor access/.test(n.text)), 'notification dans l\'application, au nom de l\'invité');
  r = await client.post('card_save', { id: cIdea, title: 'Not yet' });
  ok(r.status === 403, 'demande en attente : toujours en lecture seule');
  r = await client.post('share_request', { role: 'owner' });
  ok(r.status === 400, 'droit demandé inconnu : refusé');
  r = await anon.post('share_request', { role: 'editor' });
  ok(r.status === 401, 'demande sans adresse vérifiée : refusée');

  step('Le responsable accepte, en limitant ce que l\'invité peut modifier');
  let list = await amel.get('share_list', { studio: sid });
  const gClient = list.guests.find((g) => g.email === 'client@brand.example');
  ok(gClient && gClient.status === 'active' && gClient.request && gClient.request.role === 'editor' && gClient.request.note === 'I want to fix the captions', 'demande visible dans la gestion des invités');
  r = await karim.post('share_guest', { studio: sid, op: 'approve', guest: gClient.id });
  ok(r.status === 404, 'un compte sans accès ne peut pas répondre');
  r = await amel.post('share_guest', { studio: sid, op: 'approve', guest: gClient.id });
  ok(r.status === 200 && r.guests.find((g) => g.id === gClient.id).role === 'editor' && !r.guests.find((g) => g.id === gClient.id).request, 'demande approuvée : Editor');
  m = lastTo('client@brand.example');
  ok(m && /gave you editor access/.test(m.subject) && m.text.includes('/studioma/s/' + T), 'l\'invité est prévenu par email, avec le lien');
  r = await amel.post('share_guest', { studio: sid, op: 'approve', guest: gClient.id });
  ok(r.status === 409, 'demande déjà traitée : 409');
  r = await amel.post('share_guest', { studio: sid, op: 'scope', guest: gClient.id, cols: [ideas.id], cards: [cProd], levels: [] });
  ok(r.status === 200 && r.guests.find((g) => g.id === gClient.id).limited && r.history.some((h) => /limited what client@brand.example can edit/.test(h.text)), 'périmètre : la colonne Ideas et une carte de Production');
  b = await client.get('board', { studio: sid });
  const ed = (id) => (b.cards.find((c) => c.id === id) || {}).editable;
  const wr = (id) => (b.cols.find((c) => c.id === id) || {}).writable;
  ok(b.studio.guest.role === 'editor' && b.studio.guest.limited && b.studio.can.comment && b.studio.can.work && !b.studio.can.edit && !b.studio.can.manage, 'tableau de l\'invité : Editor, périmètre limité, aucun droit de gestion');
  ok(ed(cIdea) && ed(cIdea2) && ed(cProd) && !ed(cScript) && wr(ideas.id) && !wr(scripting.id) && !wr(prodFirst.id), 'cartes et colonnes modifiables : exactement le périmètre donné');

  step('Modifications de l\'invité, toujours vérifiées par le serveur');
  r = await client.post('card_save', { id: cIdea, title: 'Reel: 5 beginner mistakes (client edit)', body: '<p>New <b>hook</b><script>alert(1)</script></p>', due: '2026-12-24' });
  ok(r.status === 200 && r.card.title === 'Reel: 5 beginner mistakes (client edit)' && !/script/.test(r.card.body) && r.card.can.edit && !r.card.can.reassign && !r.card.can.delete, 'modification d\'une carte du périmètre (texte nettoyé)');
  ok(r.card.events.some((e) => /renamed this card/.test(e.text) && e.user && e.user.name === 'client@brand.example' && e.user.guest), 'historique : la modification porte l\'adresse de l\'invité');
  r = await client.post('card_save', { id: cScript, title: 'Out of scope' });
  ok(r.status === 403 && /isn't part of what you can edit/.test(r.message), 'carte hors périmètre : refusée');
  r = await client.post('card_save', { id: cIdea, assignee: (await amel.get('me')).user.id });
  ok(r.status === 403, 'changer le responsable d\'une carte : refusé à un invité');
  r = await client.post('card_save', { id: cIdea, members: [1] });
  ok(r.status === 403, 'changer les collaborateurs : refusé');
  r = await client.post('card_create', { col_id: ideas.id, title: 'Idea from the client' });
  const cGuest = r.card && r.card.id;
  ok(r.status === 200 && cGuest && r.card.editable, 'nouvelle carte dans une colonne du périmètre');
  r = await client.post('card_create', { col_id: scripting.id, title: 'Nope' });
  ok(r.status === 403, 'nouvelle carte hors périmètre : refusée');
  r = await client.post('card_create', { col_id: pubFirst.id, title: 'Nope' });
  ok(r.status === 403, 'nouvelle carte dans un niveau non partagé : refusée');
  r = await client.post('card_move', { id: cIdea2, col_id: scripting.id, index: 0 });
  ok(r.status === 403 && /can't add or move cards to this column/.test(r.message), 'déplacement vers une colonne hors périmètre : refusé');
  r = await client.post('card_move', { id: cIdea2, col_id: ideas.id, index: 0 });
  ok(r.status === 200, 'réordonner dans sa colonne : permis');
  r = await client.post('check_save', { card_id: cIdea, text: 'Fix caption typos' });
  const item = r.id;
  r = await client.post('check_save', { id: item, done: true });
  ok(r.status === 200 && (await amel.get('card_get', { id: cIdea })).card.checks.some((k) => k.done && k.text === 'Fix caption typos'), 'check-list : ajout et case cochée');
  r = await client.post('check_save', { card_id: cScript, text: 'x' });
  ok(r.status === 403, 'check-list d\'une carte hors périmètre : refusée');
  r = await client.post('comment_add', { card_id: cIdea, body: 'Updated the hook, @amel-haddad please check' });
  const cmt = r.id;
  let full = (await amel.get('card_get', { id: cIdea })).card;
  ok(full.comments.some((c) => c.id === cmt && c.user.name === 'client@brand.example' && c.user.guest), 'commentaire au nom de l\'invité, vu par l\'équipe');
  const amelComment = full.comments.find((c) => c.user && c.user.name === 'Amel Haddad').id;
  r = await client.post('comment_delete', { id: amelComment });
  ok(r.status === 403, 'supprimer le commentaire d\'un membre : refusé');
  r = await anon.post('comment_delete', { id: cmt });
  ok(r.status === 403 && (await amel.get('card_get', { id: cIdea })).card.comments.some((c) => c.id === cmt), 'un visiteur anonyme ne supprime pas le commentaire d\'un invité');
  r = await client.post('card_save', { id: cPub, title: 'x' });
  ok(r.status === 404, 'carte d\'un niveau non partagé : introuvable, même pour un éditeur');
  r = await client.post('card_save', { id: otherCard, title: 'x' });
  ok(r.status === 404, 'carte d\'un autre Studio : introuvable');

  step('Emails des modifications : tout de suite, puis regroupés');
  const digests = () => mailsTo('amel@test.example').filter((x) => /client@brand\.example made/.test(x.subject));
  let d = digests();
  ok(d.length === 1 && /made 3 changes in Social 2026/.test(d[0].subject) && /verified email/.test(d[0].text) && /updated the description/.test(d[0].text) && /set the due date to 2026-12-24/.test(d[0].text) && /renamed this card to “Reel: 5 beginner mistakes \(client edit\)”/.test(d[0].text) && /#\/studio\/\d+\/card\/\d+/.test(d[0].text) && /\d{1,2} \w{3} \d{4}, \d{2}:\d{2}/.test(d[0].text), 'première modification : email immédiat (invité, quoi, quand, lien vers la carte)', d[0]);
  ok(d.length === 1, 'les suivantes attendent la fin de la fenêtre de 10 minutes (pas d\'avalanche)');
  sql("UPDATE share_guests SET mailed_at = mailed_at - 700 WHERE email = 'client@brand.example'");
  await amel.get('board', { studio: sid });
  d = digests();
  ok(d.length === 2 && /made \d+ changes/.test(d[1].subject) && /added the checklist item “Fix caption typos”/.test(d[1].text) && /commented: “Updated the hook/.test(d[1].text) && /created this card/.test(d[1].text), 'fenêtre passée : un seul email regroupe toutes les modifications suivantes', d[1] && d[1].subject);
  await amel.get('board', { studio: sid });
  ok(digests().length === 2, 'rien n\'est envoyé deux fois');
  r = await amel.get('notifications');
  ok(r.notifications.some((n) => n.guest === 'client@brand.example' && /mentioned you/.test(n.text)), 'mention d\'un membre par un invité : notification au nom de l\'invité');

  step('Lien privé : seules les adresses ajoutées, après vérification');
  const boss = new Guest(P);
  r = await boss.get('share_open');
  ok(r.status === 200 && r.share.kind === 'private' && !r.guest.verified, 'share_open : lien privé, visiteur non vérifié');
  r = await boss.get('board', { studio: sid });
  ok(r.status === 401 && r.error === 'verify', 'tableau : vérification exigée');
  r = await boss.get('card_get', { id: cIdea });
  ok(r.status === 401, 'carte : vérification exigée');
  ok(await boss.file(fileOk) === 404, 'pièce jointe : refusée sans vérification');
  const before = mails().length;
  r = await boss.post('share_code', { email: 'intruder@else.example' });
  ok(r.status === 200 && r.sent && mails().length === before, 'adresse absente de la liste : même réponse, aucun email (la liste ne se devine pas)');
  r = await amel.post('share_guest', { studio: sid, op: 'add', kind: 'private', emails: 'boss@brand.example, Team@Brand.example; bad', role: 'commenter', send: true });
  ok(r.status === 400 && /bad/.test(r.message), 'adresse invalide dans la liste : refusée, rien n\'est ajouté');
  r = await amel.post('share_guest', { studio: sid, op: 'add', kind: 'private', emails: 'boss@brand.example, Team@Brand.example', role: 'commenter', send: true, message: 'Please review the scripts' });
  ok(r.status === 200 && r.guests.filter((g) => g.kind === 'private' && g.status === 'invited' && g.role === 'commenter').length === 2, 'deux adresses ajoutées (Commenter, en attente de vérification)');
  m = lastTo('team@brand.example');
  ok(m && /shared “Social 2026” with you/.test(m.subject) && /Please review the scripts/.test(m.text) && m.text.includes(P), 'invitation envoyée par email, avec le lien privé');
  r = await boss.post('share_code', { email: 'boss@brand.example' });
  m = lastTo('boss@brand.example');
  const magic = linkIn(m);
  ok(magic && m.text.includes(P), 'adresse de la liste : code et lien magique envoyés');
  r = await boss.post('share_verify', { link: 'b'.repeat(48) });
  ok(r.status === 410, 'faux lien magique : refusé');
  r = await (new Guest(T)).post('share_verify', { link: magic });
  ok(r.status === 410, 'lien magique présenté sur un autre lien de partage : refusé');
  r = await boss.post('share_verify', { link: magic });
  ok(r.status === 200 && r.guest.verified && r.guest.role === 'commenter', 'lien magique : adresse vérifiée, session ouverte');
  r = await boss.post('share_verify', { link: magic });
  ok(r.status === 410, 'lien magique : une seule fois');
  b = await boss.get('board', { studio: sid });
  ok(b.status === 200 && b.levels.length === 3 && b.studio.can.comment && !b.studio.can.work && b.cards.every((c) => !c.editable), 'Commenter : tout le Studio (lien privé sans limite de niveaux), rien de modifiable');
  r = await boss.post('comment_add', { card_id: cPub, body: 'Love it' });
  ok(r.status === 200, 'Commenter : commente');
  r = await boss.post('card_save', { id: cPub, title: 'x' });
  ok(r.status === 403, 'Commenter : ne modifie pas');
  const cross = new Guest(P);
  cross.jar['stm_g' + priv.id] = client.jar['stm_g' + pub.id];
  r = await cross.get('board', { studio: sid });
  ok(r.status === 401, 'cookie d\'invité d\'un autre lien recopié : refusé (session liée à son lien)');
  r = await client.get('board', { studio: sid }, { token: P });
  ok(r.status === 401, 'invité du lien public sur le lien privé : vérification exigée');

  step('Validation demandée à un invité');
  const gBoss = (await amel.get('share_list', { studio: sid })).guests.find((g) => g.email === 'boss@brand.example');
  b = await amel.get('board', { studio: sid });
  ok(b.guests.some((g) => g.id === 'g' + gBoss.id && g.role === 'commenter') && b.guests.some((g) => g.id === 'g' + gClient.id), 'les invités Commenter et Editor sont proposés comme validateurs');
  r = await amel.post('approval_request', { card_id: cScript, approver: 'g' + gBoss.id, note: 'Can we go with this script?' });
  ok(r.status === 200, 'validation demandée à l\'invité');
  m = lastTo('boss@brand.example');
  ok(m && /asks you to approve “Story: behind the scenes”/.test(m.subject) && /Can we go with this script/.test(m.text) && m.text.includes('/studioma/s/' + P + '/card/' + cScript), 'email à l\'invité avec le lien direct vers la carte');
  r = await amel.post('approval_request', { card_id: cPub, approver: 'g' + gClient.id });
  ok(r.status === 400 && /doesn't see this card/.test(r.message), 'invité qui ne voit pas la carte (niveau non partagé) : refusé');
  r = await amel.post('approval_request', { card_id: cScript, approver: 'g999999' });
  ok(r.status === 400, 'invité inconnu : refusé');
  full = (await boss.get('card_get', { id: cScript })).card;
  const appr = full.approvals.find((a) => a.status === 'pending');
  ok(full.can.decide && appr.approver.name === 'boss@brand.example', 'l\'invité désigné peut décider');
  r = await client.post('approval_decide', { id: appr.id, decision: 'approved' });
  ok(r.status === 403 || r.status === 404, 'un autre invité ne peut pas décider à sa place');
  r = await (new Guest(P)).post('approval_decide', { id: appr.id, decision: 'approved' });
  ok(r.status === 401, 'visiteur non vérifié : refusé');
  r = await boss.post('approval_decide', { id: appr.id, decision: 'approved' });
  full = (await amel.get('card_get', { id: cScript })).card;
  ok(r.status === 200 && full.approvals[0].status === 'approved' && full.events.some((e) => /approved this card/.test(e.text) && e.user && e.user.name === 'boss@brand.example'), 'approuvée par l\'invité ; l\'historique le montre');
  r = await amel.get('notifications');
  ok(r.notifications.some((n) => n.guest === 'boss@brand.example' && /approved/.test(n.text)), 'le demandeur est prévenu, au nom de l\'invité');
  r = await amel.post('approval_request', { card_id: cIdea, approver: 'g' + (await amel.get('share_list', { studio: sid })).guests.find((g) => g.email === 'team@brand.example').id });
  ok(r.status === 400, 'invité pas encore vérifié : ne peut pas valider');

  step('Retirer, rendre, supprimer un accès');
  r = await amel.post('share_guest', { studio: sid, op: 'revoke', guest: gBoss.id });
  ok(r.status === 200 && r.guests.find((g) => g.id === gBoss.id).status === 'revoked', 'accès retiré');
  r = await boss.get('board', { studio: sid });
  ok(r.status === 401, 'aussitôt : plus rien de visible (session fermée)');
  const n0 = mailsTo('boss@brand.example').length;
  r = await boss.post('share_code', { email: 'boss@brand.example' });
  ok(r.status === 200 && mailsTo('boss@brand.example').length === n0, 'nouveau code : aucun email pour une adresse retirée');
  r = await amel.post('share_guest', { studio: sid, op: 'restore', guest: gBoss.id });
  r = await boss.post('share_code', { email: 'boss@brand.example' });
  r = await boss.post('share_verify', { email: 'boss@brand.example', code: codeIn(lastTo('boss@brand.example')) });
  ok(r.status === 200 && (await boss.get('board', { studio: sid })).status === 200, 'accès rendu : l\'invité revient après vérification');
  r = await amel.post('share_guest', { studio: sid, op: 'role', guest: gBoss.id, role: 'viewer' });
  r = await boss.post('comment_add', { card_id: cIdea, body: 'x' });
  ok(r.status === 403, 'rôle passé à Viewer : commenter est refusé aussitôt');
  r = await amel.post('share_guest', { studio: sid, op: 'role', guest: gBoss.id, role: 'admin' });
  ok(r.status === 400, 'rôle inconnu : refusé');
  r = await amel.post('share_guest', { studio: sid, op: 'remove', guest: gBoss.id });
  ok(r.status === 200 && !r.guests.some((g) => g.id === gBoss.id) && (await boss.get('board', { studio: sid })).status === 401, 'invité supprimé de la liste ; plus d\'accès');
  full = (await amel.get('card_get', { id: cPub })).card;
  ok(full.comments.some((c) => c.user && c.user.name === 'boss@brand.example'), 'ses commentaires gardent son adresse');
  r = await amel.post('share_guest', { studio: sid, op: 'role', guest: gBoss.id, role: 'editor' });
  ok(r.status === 404, 'invité supprimé : introuvable');
  const otherGuest = (await amel.get('share_list', { studio: otherSid })).guests;
  r = await amel.post('share_guest', { studio: otherSid, op: 'revoke', guest: gClient.id });
  ok(r.status === 404 && otherGuest.length === 0, 'invité d\'un autre Studio : introuvable depuis celui-ci');

  step('Lien désactivé, expiré, régénéré ; Studio archivé');
  r = await amel.post('share_save', { studio: sid, kind: 'public', active: false });
  r = await client.get('board', { studio: sid });
  ok(r.status === 410 && r.error === 'share_disabled', 'lien désactivé : plus rien, même pour un invité vérifié');
  r = await anon.get('share_open');
  ok(r.status === 410 && /turned off/.test(r.message), 'message clair pour le visiteur');
  await amel.post('share_save', { studio: sid, kind: 'public', active: true });
  ok((await client.get('board', { studio: sid })).status === 200, 'réactivé : l\'invité retrouve son accès');
  r = await amel.post('share_save', { studio: sid, kind: 'public', expires: '2020-01-01' });
  ok(r.status === 400 && /future/.test(r.message), 'date d\'expiration passée : refusée');
  r = await amel.post('share_save', { studio: sid, kind: 'public', expires: '2099-12-31' });
  ok(r.status === 200 && r.links.public.expires > 0, 'date d\'expiration enregistrée');
  sql("UPDATE shares SET expires = 1000 WHERE kind = 'public'");
  r = await anon.get('board', { studio: sid });
  ok(r.status === 410 && r.error === 'share_expired', 'lien expiré : refusé');
  await amel.post('share_save', { studio: sid, kind: 'public', expires: '' });
  ok((await anon.get('board', { studio: sid })).status === 200, 'expiration retirée : le lien fonctionne');
  r = await amel.post('share_regen', { studio: sid, kind: 'public' });
  const T2 = r.links.public.url.split('/s/')[1];
  ok(T2 && T2 !== T && r.history.some((h) => /regenerated the public link/.test(h.text)), 'lien régénéré : nouveau jeton');
  ok((await anon.get('board', { studio: sid })).status === 404, 'l\'ancien lien ne fonctionne plus');
  r = await client.get('board', { studio: sid }, { token: T2 });
  ok(r.status === 200 && r.studio.guest.role === 'editor', 'un invité vérifié garde ses droits avec le nouveau lien');
  await amel.post('studio_archive', { id: sid, archived: true });
  r = await client.get('board', { studio: sid }, { token: T2 });
  ok(r.status === 410 && r.error === 'share_unavailable', 'Studio archivé : le lien ne montre plus rien');
  await amel.post('studio_archive', { id: sid, archived: false });

  step('Membre connecté, déconnexion d\'invité, journal');
  const amelAsGuest = new Guest(T2);
  r = await fetch(API + '?action=share_open', { headers: { 'X-Share': T2, Cookie: amel.cookie } }).then((x) => x.json());
  ok(r.member === sid, 'un membre connecté qui ouvre le lien est renvoyé vers son Studio');
  r = await amelAsGuest.get('share_open');
  ok(!r.member, '… pas un visiteur');
  r = await client.post('share_leave', {}, { token: T2 });
  const after = await client.post('comment_add', { card_id: cIdea, body: 'x' }, { token: T2 });
  ok(r.status === 200 && after.status === 403 && after.error === 'guest_verify', 'l\'invité se déconnecte : retour à la lecture seule', [r, after]);
  list = await amel.get('share_list', { studio: sid });
  const texts = list.history.map((h) => (h.by ? h.by.name : '') + ' ' + h.text);
  ok(['Amel Haddad created the public link', 'client@brand.example verified their email', 'client@brand.example asked for editor access', 'Amel Haddad approved the request of client@brand.example', 'client@brand.example renamed this card', 'Amel Haddad revoked the access of boss@brand.example', 'Amel Haddad turned off the public link'].every((t) => texts.some((x) => x.includes(t))), 'journal : accès, demandes, décisions et modifications des invités', texts.slice(0, 12));
  r = await amel.get('studio_activity', { studio: sid });
  ok(r.events.some((e) => e.user && e.user.guest && e.user.name === 'client@brand.example'), 'historique du Studio : les actions des invités portent leur adresse');

  console.log('\n' + (failed ? failed + ' échec(s)' : 'Tout est bon.'));
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
