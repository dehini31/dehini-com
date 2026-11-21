/*
 * Tests de « Share & publish » d'une carte (API seule), sur le faux hébergement de run.sh :
 *   - partage d'une seule carte (lien public, lien privé restreint à des adresses, demande et accord de droits,
 *     pièces jointes et commentaires choisis, retrait, lien désactivé ou régénéré, carte archivée ou supprimée) ;
 *   - opportunité publiée depuis une carte en cours, propositions des professionnels, réponses, invitation sur la
 *     carte après acceptation (jamais avant), fermeture et réouverture, fermeture automatique ;
 *   - réalisation publiée depuis une carte terminée (copie choisie, fil, J'aime, commentaires, profil, retrait) ;
 *   - rien de privé ne sort : ni la description de la carte, ni ses commentaires, ni ses autres fichiers, ni le
 *     reste du Studio ; le partage d'un Studio entier fonctionne comme avant.
 *   node tests/studioma/publish.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : '')); } };
const step = (t) => console.log('\n' + t);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
const lastTo = (to) => mails().filter((m) => m.to === to).pop();
const sql = (query) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, query]);
const sqlVal = (query) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); echo json_encode($p->query($argv[2])->fetchColumn());', DB, query]).toString();

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
  file(t) { return fetch(API + '?action=file&t=' + t, { headers: this.cookie ? { Cookie: this.cookie } : {} }).then((r) => r.status); }
}
class Guest {
  constructor(token) { this.token = token; this.jar = {}; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  async call(action, body, params) {
    const url = new URL(API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const headers = { 'X-Share': this.token };
    if (this.cookies()) headers.Cookie = this.cookies();
    const init = { headers };
    if (body !== undefined) Object.assign(init, { method: 'POST', body: JSON.stringify(body) }), Object.assign(headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma' });
    const res = await fetch(url, init);
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => {
      const [k, v] = c.split(';')[0].split('=');
      if (/^stm_g\d+$/.test(k)) { if (v) this.jar[k] = v; else delete this.jar[k]; }
    });
    const d = await res.json().catch(() => ({ error: 'not-json' }));
    d.status = res.status;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
  file(t) { return fetch(API + '?action=file&t=' + t + '&share=' + this.token, { headers: this.cookies() ? { Cookie: this.cookies() } : {} }).then((r) => r.status); }
  async verify(email) {
    await this.post('share_code', { email });
    const code = ((lastTo(email) || { text: '' }).text.match(/\b(\d{6})\b/) || [])[1];
    return this.post('share_verify', { email, code });
  }
}
const anon = new Member();
const tokenOf = (url) => url.split('/s/')[1];

(async () => {
  step('Préparation : un Studio, une carte en cours avec deux pièces jointes, une carte terminée, des comptes');
  const amel = new Member();
  await amel.post('signup', { name: 'Amel Haddad', email: 'amel@test.example', password: 'a-solid-password', accept: true });
  let r = await amel.post('project_save', { name: 'Vélora', template: 'content', studio_name: 'Social 2026' });
  const sid = r.studio_id;
  let b = await amel.get('board', { studio: sid });
  const col = (name) => b.cols.find((c) => c.name.includes(name));
  const ideas = col('Ideas');
  const doneCol = b.cols.find((c) => c.kind === 'done');
  const cancelled = b.cols.find((c) => c.kind === 'cancelled');
  const mk = async (c, title) => (await amel.post('card_create', { col_id: c.id, title })).card.id;
  const cOpen = await mk(ideas, 'Reel: spring launch teaser');
  const cNext = await mk(ideas, 'Carousel: care guide');
  const cDone = await mk(doneCol, 'Video: launch film');
  await amel.post('card_save', { id: cOpen, body: 'SECRET-BRIEF budget of the client' });
  await amel.post('comment_add', { card_id: cOpen, body: 'SECRET-COMMENT internal' });
  await amel.post('comment_add', { card_id: cNext, body: 'Other card note' });
  r = await amel.upload(path.join(DIR, 'photo.png'), 'image/png', { card_id: cOpen });
  const imgOpen = r.file.token;
  r = await amel.upload(path.join(DIR, 'notes.txt'), 'text/plain', { card_id: cOpen });
  const txtOpen = r.file.token;
  r = await amel.upload(path.join(DIR, 'photo.png'), 'image/png', { card_id: cDone });
  const imgDone = r.file.token;
  r = await amel.upload(path.join(DIR, 'notes.txt'), 'text/plain', { card_id: cDone });
  const txtDone = r.file.token;
  let card = (await amel.get('card_get', { id: cOpen })).card;
  const cfImg = card.files.find((f) => f.token === imgOpen).id;
  const cfTxt = card.files.find((f) => f.token === txtOpen).id;
  ok(card.stage === 'open' && card.can.share && card.can.publish && card.pubs.length === 0 && card.shared.links === 0, 'carte en cours : étape, droits de partage et de publication', card.can);
  const doneCard = (await amel.get('card_get', { id: cDone })).card;
  ok(doneCard.stage === 'done', 'carte terminée : étape « done »');
  const cfImgDone = doneCard.files.find((f) => f.token === imgDone).id;
  const karim = new Member();
  await karim.post('signup', { name: 'Karim Saidi', email: 'karim@test.example', password: 'a-solid-password', accept: true });
  const nadia = new Member();
  await nadia.post('signup', { name: 'Nadia Belkacem', email: 'nadia@test.example', password: 'a-solid-password', accept: true });
  const lina = new Member();
  await lina.post('signup', { name: 'Lina Viewer', email: 'lina@test.example', password: 'a-solid-password', accept: true });
  const linaId = (await lina.get('me')).user.id;
  r = await amel.post('invite_create', { studio_id: sid, uid: linaId, role: 'viewer' });
  const inv = sqlVal("SELECT token FROM invites WHERE uid = " + linaId).replace(/"/g, '');
  await lina.post('invite_accept', { token: inv });
  ok((await lina.get('board', { studio: sid })).studio, 'Lina est membre (Viewer) du Studio');

  step('1. Partager une carte avec un invité sans compte (lien public)');
  r = await amel.post('share_save', { studio: sid, card: cOpen, kind: 'public' });
  ok(r.links && r.links.public && r.links.public.url && r.card && r.card.id === cOpen && r.files.length === 2 && r.opts.files.length === 0 && r.opts.comments === false,
    'lien public de la carte créé ; rien de joint ni de commentaires montrés par défaut', r);
  const pubTok = tokenOf(r.links.public.url);
  ok(!(await amel.get('share_list', { studio: sid })).links.public, 'le lien de la carte n\'apparaît pas dans le partage du Studio');
  const visitor = new Guest(pubTok);
  r = await visitor.get('share_open');
  ok(r.share && r.share.card && r.share.card.id === cOpen && r.guest.card === cOpen && r.guest.comments === false, 'ouverture : le lien annonce la carte partagée', r);
  r = await visitor.get('board', { studio: sid });
  ok(r.cards && r.cards.length === 1 && r.cards[0].id === cOpen && r.cols.length === 1 && r.levels.length === 1 && !r.studio.description, 'tableau de l\'invité : cette carte seulement (une colonne, un niveau)', r.cards && r.cards.map((c) => c.id));
  ok(r.cards[0].pub === undefined, 'pas de pastille de publication côté invité');
  r = await visitor.get('card_get', { id: cOpen });
  ok(r.card && r.card.title.startsWith('Reel') && r.card.files.length === 0 && r.card.comments.length === 0 && r.card.events.length === 0 && r.card.pubs.length === 0 && r.card.shared === null,
    'carte vue par l\'invité : sans pièce jointe, commentaire, historique ni publication', r.card && { f: r.card.files.length, c: r.card.comments.length, e: r.card.events.length });
  ok((await visitor.get('card_get', { id: cNext })).status === 404, 'une autre carte du même Studio : introuvable');
  ok((await visitor.get('card_get', { id: cDone })).status === 404, 'la carte terminée du Studio : introuvable');
  ok((await visitor.file(imgOpen)) === 404 && (await visitor.file(txtOpen)) === 404, 'pièces jointes non choisies : introuvables (404)');
  ok((await visitor.get('board', { studio: sid })).cards[0].cover === '', 'vignette du tableau masquée : son image n\'est pas partagée');
  ok((await visitor.post('card_save', { id: cOpen, title: 'Hacked' })).status === 403, 'lecture seule : modification refusée');
  ok((await visitor.post('comment_add', { card_id: cOpen, body: 'hello' })).status === 403, 'commentaires non partagés : commentaire refusé');
  ok((await visitor.get('share_list', { studio: sid, card: cOpen })).status === 403, 'la gestion du partage n\'est pas accessible par le lien');
  r = await amel.post('share_save', { studio: sid, card: cOpen, kind: 'public', files: [cfImg, 999999], comments: true });
  ok(r.opts && r.opts.files.length === 1 && r.opts.files[0] === cfImg && r.opts.comments === true, 'le responsable choisit une pièce jointe et montre les commentaires (id étranger ignoré)', r.opts);
  ok((await visitor.file(imgOpen)) === 200 && (await visitor.file(txtOpen)) === 404, 'seule la pièce jointe choisie est lisible');
  ok((await visitor.get('board', { studio: sid })).cards[0].cover === imgOpen, 'vignette montrée une fois l\'image partagée');
  r = await visitor.get('card_get', { id: cOpen });
  ok(r.card.files.length === 1 && r.card.comments.length === 1 && r.card.comments_shared === true, 'l\'invité voit la pièce jointe choisie et les commentaires', r.card.files.length);
  ok((await anon.get('share_list', { studio: sid, card: cOpen })).status === 401, 'gestion du partage : compte obligatoire');
  ok((await lina.get('share_list', { studio: sid, card: cOpen })).status === 403, 'gestion du partage : un Viewer du Studio ne peut pas');
  ok((await amel.get('share_list', { studio: sid, card: 99999 })).status === 404, 'carte d\'un autre Studio ou inconnue : introuvable');

  step('2. Lien privé de la carte, réservé à une adresse');
  r = await amel.post('share_guest', { studio: sid, card: cOpen, op: 'add', kind: 'private', emails: 'client@brand.example', role: 'viewer', send: true, message: 'Please review' });
  ok(r.links && r.links.private && r.guests.some((g) => g.email === 'client@brand.example' && g.kind === 'private'), 'adresse ajoutée au lien privé de la carte', r.guests);
  let m = lastTo('client@brand.example');
  ok(m && /shared “Reel: spring launch teaser” with you/.test(m.subject) && /Open the card/.test(m.text), 'email d\'invitation : la carte, pas le Studio', m && m.subject);
  const privTok = tokenOf(r.links.private.url);
  const stranger = new Guest(privTok);
  ok((await stranger.get('board', { studio: sid })).status === 401, 'lien privé ouvert sans adresse vérifiée : rien n\'est montré');
  r = await stranger.post('share_code', { email: 'other@brand.example' });
  ok(r.sent && !lastTo('other@brand.example'), 'adresse absente de la liste : aucun code envoyé (réponse identique)');
  const client = new Guest(privTok);
  r = await client.verify('client@brand.example');
  ok(r.guest && r.guest.verified && r.guest.card === cOpen, 'le client vérifie son adresse : accès à la carte', r);
  ok((await client.get('board', { studio: sid })).cards.length === 1, 'le client voit la seule carte');

  step('3. Demande de droits d\'édition et accord');
  r = await client.post('share_request', { role: 'editor', note: 'I want to fix the caption' });
  ok(r.guest && r.guest.pending === 'editor', 'le client demande à modifier', r);
  m = lastTo('amel@test.example');
  ok(m && /asks to edit Reel: spring launch teaser/.test(m.subject) && /cardshare=1/.test(m.text) && /They never see the rest of the Studio/.test(m.text), 'email au responsable : la carte, lien vers sa fenêtre de partage', m && m.subject);
  r = await amel.get('notifications');
  ok(r.notifications && r.notifications.some((n) => /asks for editor access to “Reel/.test(n.text) && /cardshare=1/.test(n.link)), 'notification dans l\'application', r.notifications && r.notifications.slice(0, 2));
  r = await amel.get('share_list', { studio: sid, card: cOpen });
  const cg = r.guests.find((g) => g.email === 'client@brand.example');
  ok(cg && cg.request && cg.request.role === 'editor', 'la demande apparaît dans le partage de la carte');
  ok((await amel.post('share_guest', { studio: sid, op: 'approve', guest: cg.id, role: 'editor' })).status === 404, 'la demande ne se traite pas depuis le partage du Studio (périmètres séparés)');
  r = await amel.post('share_guest', { studio: sid, card: cOpen, op: 'approve', guest: cg.id, role: 'editor' });
  ok(r.guests && r.guests.find((g) => g.id === cg.id).role === 'editor', 'demande acceptée : Editor');
  m = lastTo('client@brand.example');
  ok(m && /editor access/.test(m.subject) && /you can edit it/.test(m.text), 'le client est prévenu par email', m && m.subject);
  r = await client.post('card_save', { id: cOpen, title: 'Reel: spring launch teaser v2' });
  ok(r.card && r.card.title === 'Reel: spring launch teaser v2', 'l\'éditeur invité modifie la carte');
  ok((await client.post('card_save', { id: cNext, title: 'Hacked' })).status === 404, '… mais aucune autre carte');
  ok((await client.post('card_create', { col_id: ideas.id, title: 'New' })).status === 403, '… ni créer de carte');
  ok((await client.post('card_move', { id: cOpen, col_id: doneCol.id, index: 0 })).status === 403, '… ni la déplacer dans une colonne');
  r = await client.post('comment_add', { card_id: cOpen, body: 'Caption fixed' });
  ok(!r.error, 'il commente (commentaires montrés)', r);
  ok((await amel.post('share_guest', { studio: sid, card: cOpen, op: 'scope', guest: cg.id, levels: [] })).status === 400, 'pas de périmètre d\'édition pour un lien de carte');

  step('4. Retirer l\'accès, désactiver, régénérer');
  r = await amel.post('share_guest', { studio: sid, card: cOpen, op: 'revoke', guest: cg.id });
  ok(r.guests.find((g) => g.id === cg.id).status === 'revoked', 'accès du client retiré');
  ok((await client.get('board', { studio: sid })).status === 401, 'le client ne voit plus rien');
  r = await amel.post('share_save', { studio: sid, card: cOpen, kind: 'public', active: false });
  ok((await visitor.get('board', { studio: sid })).status === 410, 'lien public désactivé : 410');
  await amel.post('share_save', { studio: sid, card: cOpen, kind: 'public', active: true });
  r = await amel.post('share_regen', { studio: sid, card: cOpen, kind: 'public' });
  const pubTok2 = tokenOf(r.links.public.url);
  ok(pubTok2 !== pubTok && (await visitor.get('board', { studio: sid })).status === 404 && (await new Guest(pubTok2).get('board', { studio: sid })).cards.length === 1, 'nouveau lien : l\'ancien ne fonctionne plus, le nouveau oui');
  r = await amel.post('share_save', { studio: sid, card: cOpen, kind: 'public', expires: '2099-01-31' });
  ok(r.links.public.expires > 0, 'date d\'expiration réglée');
  sql('UPDATE shares SET expires = 1000 WHERE card_id = ' + cOpen + " AND kind = 'public'");
  ok((await new Guest(pubTok2).get('board', { studio: sid })).status === 410, 'lien expiré : 410');
  sql('UPDATE shares SET expires = 0 WHERE card_id = ' + cOpen + " AND kind = 'public'");
  r = await amel.get('share_list', { studio: sid, card: cOpen });
  ok(r.history.some((h) => /link to this card/.test(h.text)) && r.history.every((h) => h.card_id === cOpen || h.card_id === 0), 'journal du partage de la carte', r.history.slice(0, 3));
  r = await amel.get('card_get', { id: cOpen });
  ok(r.card.shared.links === 2 && r.card.shared.guests === 0, 'résumé du partage dans la carte (2 liens actifs)', r.card.shared);
  b = await amel.get('board', { studio: sid });
  ok(b.cards.find((c) => c.id === cOpen).pub && b.cards.find((c) => c.id === cOpen).pub.s === true, 'pastille « partagée » sur le tableau');

  step('5. Publier une carte en cours comme opportunité');
  const opp = { kind: 'opportunity', title: 'Video editor for a spring teaser', body: 'We need a short vertical teaser edited from our rushes, with captions and music.', opp_kind: 'pro',
    category: 'Video & editing', skills: ['Video editing', 'Captions'], deliverables: '1 vertical video, 30 s', budget_min: 300, budget_max: 600, currency: 'EUR',
    deadline: '2099-06-30', apply_until: '2099-05-31', mode: 'remote', files: [cfImg] };
  ok((await karim.post('pub_save', Object.assign({ card: cOpen }, opp))).status === 404, 'un non-membre ne peut pas publier la carte');
  ok((await lina.post('pub_save', Object.assign({ card: cOpen }, opp))).status === 403, 'un Viewer du Studio non plus');
  ok((await amel.post('pub_save', Object.assign({ card: cOpen }, opp, { kind: 'achievement' }))).status === 409, 'réalisation refusée : la carte n\'est pas terminée');
  ok((await amel.post('pub_save', Object.assign({ card: cDone }, opp))).status === 409, 'opportunité refusée sur une carte terminée');
  ok((await amel.post('pub_save', Object.assign({ card: cOpen }, opp, { body: 'Too short' }))).status === 400, 'description trop courte : refusée');
  ok((await amel.post('pub_save', Object.assign({ card: cOpen }, opp, { budget_min: 900 }))).status === 400, 'budget minimum supérieur au maximum : refusé');
  ok((await amel.post('pub_save', Object.assign({ card: cOpen }, opp, { files: [cfImgDone] }))).status === 400, 'fichier d\'une autre carte : refusé');
  r = await amel.post('pub_save', Object.assign({ card: cOpen }, opp));
  const op = r.pub;
  ok(op && op.status === 'open' && op.kind === 'opportunity' && op.media.length === 1 && op.media[0].token !== imgOpen && op.manage, 'opportunité publiée, avec une copie de l\'image choisie', r);
  ok((await anon.file(op.media[0].token)) === 200 && (await anon.file(imgOpen)) === 404, 'la copie est publique ; le fichier de la carte reste privé');
  r = await amel.post('pub_save', Object.assign({ card: cOpen }, opp, { title: 'Video editor for a spring teaser (EU)' }));
  ok(r.pub.id === op.id && sqlVal("SELECT COUNT(*) FROM pubs WHERE card_id = " + cOpen + " AND kind = 'opportunity'") === '1', 'republier la même carte réutilise la même publication (pas de doublon)');
  r = await anon.get('pub_get', { id: op.id });
  const pubJson = JSON.stringify(r);
  ok(r.pub && r.pub.title.includes('(EU)') && r.pub.source.available === true && r.pub.source.card_id === undefined && r.pub.source.studio_id === undefined, 'vue publique : la carte existe, sans son adresse', r.pub && r.pub.source);
  ok(!/SECRET-BRIEF|SECRET-COMMENT|Carousel: care guide|Social 2026/.test(pubJson), 'rien de privé dans la publication (description, commentaires, autres cartes, Studio)');
  r = await lina.get('pub_get', { id: op.id });
  ok(r.pub.source.card_id === cOpen && r.pub.manage === false, 'un membre du Studio voit le lien vers la carte, sans gérer la publication');
  card = (await amel.get('card_get', { id: cOpen })).card;
  ok(card.pubs.length === 1 && card.pubs[0].status === 'open', 'la carte affiche son opportunité ouverte');
  ok(card.events.some((e) => /published this card as an opportunity/.test(e.text)), 'historique de la carte : publication');
  b = await amel.get('board', { studio: sid });
  ok(b.cards.find((c) => c.id === cOpen).pub.o === 'open', 'pastille « opportunité ouverte » sur le tableau');
  r = await anon.get('community', { tab: 'opportunities' });
  ok(r.items && r.items.some((i) => i.pub.id === op.id), 'communauté (sans compte) : l\'opportunité est listée', r.items && r.items.length);
  r = await anon.get('community', { tab: 'opportunities', q: 'teaser', kind: 'pro', budget: 1, mode: 'remote' });
  ok(r.items.length === 1, 'recherche et filtres (type, budget, mode)');
  r = await anon.get('community', { tab: 'opportunities', q: 'plumber' });
  ok(r.items.length === 0, 'recherche sans résultat');

  step('6. Propositions des professionnels');
  ok((await anon.post('proposal_save', { pub: op.id, message: 'x'.repeat(40) })).status === 401, 'proposer : compte obligatoire');
  ok((await amel.post('proposal_save', { pub: op.id, message: 'I can do it myself, with a long enough message.' })).status === 403, 'l\'auteur ne se propose pas à lui-même');
  ok((await karim.post('proposal_save', { pub: op.id, message: 'Too short' })).status === 400, 'approche trop courte : refusée');
  r = await karim.post('portfolio_save', { title: 'Spring campaign for Atlas', body: 'Edited 12 reels.', publish: true });
  const caseId = r.id;
  ok((await karim.post('proposal_save', { pub: op.id, message: 'I edit vertical teasers every week: rhythm, captions, licensed music.', work: [{ t: 'case', id: 999 }] })).status === 400, 'travail joint qui n\'est pas le sien : refusé');
  r = await karim.post('proposal_save', { pub: op.id, message: 'I edit vertical teasers every week: rhythm, captions, licensed music.', price: 450, timeline: '5 days', work: [{ t: 'case', id: caseId }] });
  ok(r.proposal && r.proposal.status === 'new' && r.proposal.price === 450 && r.proposal.currency === 'EUR' && r.proposal.work.length === 1, 'Karim envoie sa proposition (prix, délai, étude de cas)', r);
  const karimProp = r.proposal.id;
  m = lastTo('amel@test.example');
  ok(m && /sent a proposal for/.test(m.subject), 'l\'auteur est prévenu par email', m && m.subject);
  r = await karim.post('proposal_save', { pub: op.id, message: 'I edit vertical teasers every week: rhythm, captions, licensed music. Updated.', price: 420, timeline: '4 days' });
  ok(r.proposal.id === karimProp && r.proposal.price === 420, 'il modifie sa proposition (une seule par personne)');
  r = await nadia.post('proposal_save', { pub: op.id, message: 'Motion designer, I can add animated titles to the teaser.', price: 0, timeline: '1 week' });
  const nadiaProp = r.proposal.id;
  r = await amel.get('pub_get', { id: op.id });
  ok(r.pub.proposals && r.pub.proposals.length === 2 && r.pub.proposals.some((x) => x.user && x.user.name === 'Karim Saidi' && x.work.length && x.work[0].title === 'Spring campaign for Atlas'), 'l\'auteur voit les propositions et les travaux joints', r.pub.proposals);
  r = await nadia.get('pub_get', { id: op.id });
  ok(!r.pub.proposals && r.pub.proposal && r.pub.proposal.id === nadiaProp && r.pub.my_proposal === 'new', 'Nadia ne voit que sa propre proposition');
  ok(!JSON.stringify(await anon.get('pub_get', { id: op.id })).includes('licensed music'), 'les propositions ne sont jamais publiques');
  ok((await karim.post('proposal_decide', { id: nadiaProp, status: 'declined' })).status === 403, 'un professionnel ne décide pas (pas même de celle d\'un autre)');
  r = await nadia.get('community', { tab: 'proposals' });
  ok(r.items && r.items.length === 1 && r.items[0].proposal.status === 'new' && r.items[0].pub.id === op.id, 'Nadia suit ses propositions');

  step('7. Accepter, refuser — sans accès automatique à la carte');
  r = await amel.post('proposal_decide', { id: nadiaProp, status: 'shortlisted' });
  ok(r.pub.proposals.find((x) => x.id === nadiaProp).status === 'shortlisted', 'Nadia présélectionnée');
  r = await amel.post('proposal_decide', { id: karimProp, status: 'accepted' });
  ok(r.pub.proposals.find((x) => x.id === karimProp).status === 'accepted', 'proposition de Karim acceptée');
  m = lastTo('karim@test.example');
  ok(m && /accepted your proposal/.test(m.subject), 'Karim est prévenu par email', m && m.subject);
  await amel.post('proposal_decide', { id: nadiaProp, status: 'declined' });
  ok((await nadia.get('pub_get', { id: op.id })).pub.my_proposal === 'declined', 'Nadia voit le refus');
  ok((await karim.get('card_get', { id: cOpen })).status === 404 && (await karim.get('board', { studio: sid })).status === 404, 'accepté ne donne aucun accès à la carte ni au Studio');
  ok((await amel.post('pub_invite', { proposal: nadiaProp, how: 'card', role: 'editor' })).status === 409, 'inviter une proposition non acceptée : refusé');
  ok((await lina.post('pub_invite', { proposal: karimProp, how: 'card', role: 'editor' })).status === 403, 'un membre qui ne gère pas la publication ne peut pas inviter');
  r = await amel.post('pub_invite', { proposal: karimProp, how: 'card', role: 'editor' });
  ok(r.invited === 'card', 'l\'auteur invite Karim sur cette seule carte', r);
  m = lastTo('karim@test.example');
  ok(m && /shared “Reel: spring launch teaser v2” with you/.test(m.subject), 'Karim reçoit le lien privé de la carte par email', m && m.subject);
  const karimGuest = new Guest(tokenOf((await amel.get('share_list', { studio: sid, card: cOpen })).links.private.url));
  r = await karimGuest.verify('karim@test.example');
  ok(r.guest && r.guest.role === 'editor', 'Karim vérifie son adresse : Editor de la carte');
  ok((await karimGuest.get('card_get', { id: cNext })).status === 404, '… et rien d\'autre du Studio');
  r = await amel.post('pub_invite', { proposal: karimProp, how: 'studio', role: 'collaborator' });
  ok(r.invited === 'studio' && sqlVal("SELECT COUNT(*) FROM invites WHERE studio_id = " + sid + " AND role = 'collaborator' AND status = 'pending'") === '1', 'ou dans le Studio, par l\'invitation habituelle');

  step('8. Fermer, rouvrir, fermeture automatique');
  r = await amel.post('pub_status', { id: op.id, status: 'closed' });
  ok(r.pub.status === 'closed' && /Closed by its author/.test(r.pub.closed_reason), 'opportunité fermée');
  const other = new Member();
  await other.post('signup', { name: 'Yanis Copy', email: 'yanis@test.example', password: 'a-solid-password', accept: true });
  ok((await other.post('proposal_save', { pub: op.id, message: 'Copywriter here, I would love to help with captions.' })).status === 410, 'fermée : plus aucune proposition');
  ok((await anon.get('community', { tab: 'opportunities' })).items.every((i) => i.pub.id !== op.id), 'fermée : absente des opportunités ouvertes');
  ok((await anon.get('community', { tab: 'opportunities', status: 'closed' })).items.some((i) => i.pub.id === op.id), '… mais listée avec le filtre « fermées »');
  r = await amel.post('pub_status', { id: op.id, status: 'open' });
  ok(r.pub.status === 'open', 'rouverte');
  await amel.post('card_move', { id: cOpen, col_id: doneCol.id, index: 0 });
  r = await anon.get('pub_get', { id: op.id });
  ok(r.pub.status === 'closed' && /work is done/.test(r.pub.closed_reason), 'carte terminée : l\'opportunité se ferme d\'elle-même', r.pub.closed_reason);
  ok((await amel.post('pub_status', { id: op.id, status: 'open' })).status === 409, 'rouvrir est refusé tant que la carte est terminée');
  await amel.post('card_move', { id: cOpen, col_id: ideas.id, index: 0 });
  ok((await amel.post('pub_status', { id: op.id, status: 'open' })).pub.status === 'open', 'carte de nouveau en cours : réouverture possible');
  sql("UPDATE pubs SET apply_until = '2000-01-01' WHERE id = " + op.id);
  ok((await anon.get('pub_get', { id: op.id })).pub.status === 'closed', 'date limite de candidature passée : fermée');
  sql("UPDATE pubs SET apply_until = '2099-05-31', status = 'open', closed_reason = '' WHERE id = " + op.id);

  step('9. Publier une carte terminée comme réalisation');
  const ach = { kind: 'achievement', title: 'Launch film for Vélora', body: 'A 45-second launch film shot in two days.', category: 'Video & editing',
    skills: ['Video editing', 'Color grading'], outcome: '120k views in a week', files: [cfImgDone], cover: cfImgDone, show_team: true };
  ok((await amel.post('pub_save', Object.assign({ card: cDone }, ach, { cover: doneCard.files.find((f) => f.token === txtDone).id }))).status === 400, 'la couverture doit être une image');
  r = await amel.post('pub_save', Object.assign({ card: cDone }, ach));
  const ac = r.pub;
  ok(ac && ac.status === 'published' && ac.post_id > 0 && ac.cover && ac.cover.token !== imgDone && ac.team.length === 0, 'réalisation publiée, couverture copiée, publication du fil créée', r);
  r = await anon.get('community', { tab: 'achievements', category: 'Video & editing' });
  ok(r.items.some((i) => i.pub.id === ac.id), 'communauté : réalisations (filtre catégorie)');
  r = await anon.get('feed', { tab: 'discover' });
  const fp = r.posts.find((p) => p.id === ac.post_id);
  ok(fp && fp.pub && fp.pub.title === 'Launch film for Vélora' && fp.pub.cover, 'le fil montre la réalisation', r.posts.slice(0, 2));
  r = await karim.post('post_like', { id: ac.post_id, on: true });
  ok(r.likes === 1, 'J\'aime');
  r = await karim.post('post_comment', { id: ac.post_id, body: 'Beautiful grading!' });
  ok(r.comments && r.comments.length === 1, 'commentaire');
  r = await karim.post('post_create', { body: 'Look at this', share_id: ac.post_id });
  ok(r.post && r.post.shared && r.post.shared.pub && r.post.shared.pub.id === ac.id, 'partage dans le fil');
  r = await anon.get('pub_get', { id: ac.id });
  ok(r.pub.likes === 1 && r.pub.comments === 1 && r.pub.author.name === 'Amel Haddad', 'compteurs et auteur de la réalisation');
  r = await anon.get('profile_get', { handle: (await amel.get('me')).user.handle });
  ok(r.achievements && r.achievements.some((a) => a.id === ac.id), 'la réalisation apparaît sur le profil');
  m = sqlVal("SELECT COUNT(*) FROM notifications WHERE kind = 'post' AND text LIKE 'liked your post%'");
  ok(m === '1', 'l\'auteur est prévenu du J\'aime');

  step('10. Rien de privé ne sort');
  const all = JSON.stringify([await anon.get('community', { tab: 'achievements' }), await anon.get('community', { tab: 'opportunities' }), await anon.get('pub_get', { id: ac.id }), await anon.get('feed', { tab: 'discover' })]);
  ok(!/SECRET-BRIEF|SECRET-COMMENT|Other card note|Social 2026|notes\.txt/.test(all), 'aucune donnée de l\'espace de travail dans les réponses publiques');
  ok((await anon.file(txtDone)) === 404 && (await karim.file(imgDone)) === 404, 'les fichiers de la carte terminée restent privés');
  ok((await karim.post('pub_status', { id: ac.id, status: 'hidden' })).status === 403, 'un autre compte ne peut pas retirer la réalisation');
  ok((await karim.post('pub_save', { id: op.id, title: 'Hacked', body: 'x'.repeat(40), opp_kind: 'pro' })).status === 403, '… ni modifier l\'opportunité');

  step('11. Cycle de vie : retrait, carte archivée ou supprimée, partage du Studio');
  r = await amel.post('pub_status', { id: ac.id, status: 'hidden' });
  ok(r.pub.status === 'hidden', 'réalisation retirée');
  ok((await anon.get('pub_get', { id: ac.id })).status === 404 && (await anon.file(ac.cover.token)) === 404, 'retirée : introuvable, copie de couverture redevenue privée');
  ok(!(await anon.get('feed', { tab: 'discover' })).posts.some((p) => p.id === ac.post_id), 'retirée : absente du fil');
  r = await amel.post('pub_status', { id: ac.id, status: 'published' });
  ok(r.pub.status === 'published' && (await anon.get('pub_get', { id: ac.id })).pub.likes === 1, 'republiée : J\'aime conservés');
  r = await amel.post('post_delete', { id: ac.post_id });
  ok((await anon.get('pub_get', { id: ac.id })).status === 404, 'supprimer sa publication du fil retire la réalisation');
  await amel.post('pub_status', { id: ac.id, status: 'published' });
  await amel.post('card_archive', { id: cOpen, archived: true });
  ok((await anon.get('pub_get', { id: op.id })).pub.status === 'closed', 'carte archivée : opportunité fermée');
  ok((await new Guest(pubTok2).get('board', { studio: sid })).status === 410, 'carte archivée : son lien ne fonctionne plus');
  await amel.post('card_archive', { id: cOpen, archived: false });
  ok((await new Guest(pubTok2).get('board', { studio: sid })).cards.length === 1, 'carte restaurée : le lien refonctionne');
  await amel.post('card_delete', { id: cDone });
  r = await anon.get('pub_get', { id: ac.id });
  ok(r.pub && r.pub.status === 'published' && r.pub.source.available === false, 'carte supprimée : la réalisation (copie) reste, sans source');
  ok((await amel.post('share_save', { studio: sid, card: cDone, kind: 'public' })).status === 404, 'carte supprimée : plus de partage possible');
  r = await amel.post('share_save', { studio: sid, kind: 'public' });
  const studioTok = tokenOf(r.links.public.url);
  r = await new Guest(studioTok).get('board', { studio: sid });
  ok(r.cards && r.cards.length >= 2 && r.cards.some((c) => c.id === cNext), 'le partage du Studio entier fonctionne comme avant (toutes les cartes)', r.cards && r.cards.length);
  r = await amel.get('share_list', { studio: sid });
  ok(r.links.public && !r.links.private && r.levels && r.cards, 'partage du Studio : ses liens seulement, avec niveaux et cartes');
  r = await amel.post('pub_delete', { id: op.id });
  ok(!r.error && (await anon.get('pub_get', { id: op.id })).status === 404 && sqlVal('SELECT COUNT(*) FROM proposals WHERE pub_id = ' + op.id) === '0', 'opportunité supprimée avec ses propositions');

  console.log('\n' + (failed ? failed + ' échec(s).' : 'Tout est bon.'));
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
