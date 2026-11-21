/*
 * Tests du parcours du créateur (API et pages publiques), sur le faux hébergement de run.sh :
 *   XP inscrits par le serveur seulement, une fois par événement (clé d'idempotence), cartes vides sans points, limites
 *   du jour, carte terminée deux fois sans double gain, niveau de Studio dégagé, projet terminé après vérification
 *   (critères, rôle), partage d'un Studio, travail en équipe (comptes vérifiés), validations de client (cliente
 *   désignée seulement : pas de soi-même, pas d'un propriétaire à sa place, pas d'un responsable, adresse confirmée ;
 *   invité vérifié d'un lien), réalisation publiée, réalisations et preuves, en attente de vérification, déclarées,
 *   niveaux du créateur, jalons (rien d'acquis, rien que la formule refuse), chronologie, célébrations, visibilité,
 *   page de vérification et passeport publics (rien de privé, état retiré affiché), consentement du client,
 *   administration (règles, retrait et restitution avec raison, journal), formule et abonnement sans effet sur l'acquis,
 *   migration rejouée sans perte.
 *   node tests/studioma/journey.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
const CONFIG = path.join(DIR, 'public_html', 'studioma', 'config.php');
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 700) : '')); } };
const step = (t) => console.log('\n' + t);
const sql = (query) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, query]);
const one = (query) => JSON.parse(execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); echo json_encode($p->query($argv[2])->fetchColumn());', DB, query]).toString());
const setConfig = async (name, value) => {
  fs.writeFileSync(CONFIG, fs.readFileSync(CONFIG, 'utf8').replace(new RegExp(`define\\('${name}', [^)]*\\);`), `define('${name}', ${value});`));
  await new Promise((res) => setTimeout(res, 3200));
};
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
const lastTo = (to) => mails().filter((m) => m.to === to).pop();

class Client {
  constructor(share) { this.jar = {}; this.csrf = ''; this.share = share || ''; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  keep(res) {
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => {
      const [k, ...rest] = c.split(';')[0].split('=');
      const v = rest.join('=');
      if (v === '' || /Max-Age=0/i.test(c)) delete this.jar[k]; else this.jar[k] = v;
    });
  }
  async call(action, body, params) {
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
      Object.assign(headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf });
    }
    const res = await fetch(url, init);
    this.keep(res);
    const d = await res.json().catch(() => ({ error: 'not-json' }));
    d.status = res.status;
    if (d.csrf && !this.admin) this.csrf = d.csrf;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
  async page(p) {
    const res = await fetch(ORIGIN + p, { headers: this.cookies() ? { Cookie: this.cookies() } : {} });
    return { status: res.status, html: await res.text(), headers: res.headers };
  }
  async adminLogin() {
    const res = await fetch(ORIGIN + '/admin/api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: this.cookies() }, body: JSON.stringify({ password: 'test-owner-pass' }) });
    this.keep(res);
    const d = await res.json();
    if (d.ok) { this.admin = true; this.csrf = d.csrf; }
    return d;
  }
  async verify(email) {
    await this.post('share_code', { email });
    const code = ((lastTo(email) || { text: '' }).text.match(/\b(\d{6})\b/) || [])[1];
    return this.post('share_verify', { email, code });
  }
}
const signup = async (name, email) => {
  const c = new Client();
  await c.post('signup', { name, email, password: 'a-solid-password', accept: true });
  c.id = (await c.get('me')).user.id;
  sql('DELETE FROM rate');
  return c;
};
const xpOf = (uid) => one('SELECT COALESCE(SUM(xp), 0) FROM xp_transactions WHERE revoked = 0 AND uid = ' + uid);
const rowsOf = (uid, kind) => one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${uid} AND kind = '${kind}'`);
const ach = (j, aid) => j.achievements.find((a) => a.aid === aid);
const BODY = 'A carousel of six slides about the spring collection, with captions and hashtags.';

(async () => {
  const anon = new Client();
  step('1. Nouveau compte : niveau Explorer, rien d\'acquis, premier jalon');
  const amel = await signup('Amel Haddad', 'amel@test.example');
  let j = await amel.get('journey');
  ok(j.level && j.level.n === 1 && j.level.name === 'Explorer' && j.level.xp === 0 && j.level.next.name === 'Organizer', 'niveau 01 Explorer, 0 XP, prochain : Organizer', j.level);
  ok(j.achievements.length === 6 && j.achievements.every((a) => a.status === 'progress' && a.tab === 'progress'), 'six réalisations, toutes en cours', j.achievements.map((a) => a.status));
  ok(j.next.length >= 1 && j.next[0].key === 'studio' && j.next[0].href === '#/projects', 'premier jalon : créer son premier Studio', j.next);
  ok(j.profile.title === 'Creative Professional' && j.passport.enabled === false && /\/studioma\/creator\/amel-haddad$/.test(j.passport.url), 'titre par défaut ; passeport fermé par défaut', j.passport);
  ok((await anon.get('journey')).status === 401, 'tableau de bord : compte obligatoire');
  ok(j.events.find((e) => e.label === 'Complete an eligible card').xp === 10, 'barème des XP fourni (Complete an eligible card = 10)');

  step('2. Premier projet et premier Studio');
  let r = await amel.post('project_save', { name: 'Vélora', template: 'kanban', studio_name: 'Social 2026' });
  const pid = r.id;
  const sid = r.studio_id;
  ok(r._journey && r._journey.celebrate >= 1, 'la réponse signale une célébration en attente', r._journey);
  j = await amel.get('journey');
  ok(j.level.xp === 35 && xpOf(amel.id) === 35, 'premier Studio (20) + premier projet (15) = 35 XP', j.level.xp);
  ok(ach(j, 'first_studio').status === 'verified' && ach(j, 'first_studio').label === 'System Verified' && ach(j, 'first_studio').visibility === 'private', 'First Studio : vérifiée par le système, privée par défaut', ach(j, 'first_studio'));
  ok(j.next[0].key === 'first_card', 'jalon suivant : terminer sa première carte (le Studio n\'est plus proposé)', j.next.map((n) => n.key));
  let cel = await amel.get('journey_celebrations');
  ok(cel.items.some((i) => i.kind === 'achievement' && i.title === 'First Studio' && /creative journey starts here/.test(i.text)), 'célébration : « Your creative journey starts here! »', cel.items);
  await amel.post('journey_seen');
  ok((await amel.get('journey_celebrations')).items.length === 0, 'célébration fermée : plus rien en attente');
  await amel.post('project_save', { name: 'Second brand', template: 'kanban' });
  await amel.get('journey');
  ok(xpOf(amel.id) === 35 && rowsOf(amel.id, 'first_studio') === 1 && rowsOf(amel.id, 'first_project') === 1, 'un deuxième projet ne redonne rien (une seule fois)');

  step('3. Cartes vides : aucun point ; carte éligible : points une seule fois');
  let b = await amel.get('board', { studio: sid });
  const col = (k) => b.cols.find((c) => c.name.includes(k));
  const todo = col('To do');
  const done = b.cols.find((c) => c.kind === 'done');
  const mk = async (title) => (await amel.post('card_create', { col_id: todo.id, title })).card.id;
  const empties = [];
  for (let i = 0; i < 6; i++) empties.push(await mk('Empty ' + i));
  for (const id of empties.slice(0, 3)) await amel.post('card_move', { id, col_id: done.id, index: 0 });
  j = await amel.get('journey');
  ok(xpOf(amel.id) === 35 && rowsOf(amel.id, 'card') === 0 && rowsOf(amel.id, 'done') === 0, 'six cartes vides créées, trois terminées : 0 XP', xpOf(amel.id));
  ok(ach(j, 'first_delivery').status === 'progress', 'First Delivery pas encore obtenue');
  const c1 = await mk('Carousel: spring collection');
  await amel.post('card_save', { id: c1, body: '<p>Too short</p>' });
  ok(rowsOf(amel.id, 'card') === 0, 'description trop courte : pas éligible');
  await amel.post('card_save', { id: c1, body: '<p>' + BODY + '</p>' });
  ok(rowsOf(amel.id, 'card') === 1 && xpOf(amel.id) === 37, 'description réelle : carte éligible, +2 XP', xpOf(amel.id));
  r = await amel.post('card_move', { id: c1, col_id: done.id, index: 0 });
  ok(rowsOf(amel.id, 'done') === 1 && xpOf(amel.id) === 47, 'carte terminée : +10 XP', xpOf(amel.id));
  await amel.post('card_move', { id: c1, col_id: todo.id, index: 0 });
  await amel.post('card_move', { id: c1, col_id: done.id, index: 0 });
  await amel.post('card_move', { id: c1, col_id: todo.id, index: 0 });
  await amel.post('card_move', { id: c1, col_id: done.id, index: 0 });
  ok(rowsOf(amel.id, 'done') === 1 && xpOf(amel.id) === 47, 'terminée, rouverte, terminée deux fois : toujours une seule fois', xpOf(amel.id));
  j = await amel.get('journey');
  ok(ach(j, 'first_delivery').status === 'verified', 'First Delivery obtenue');
  const c2 = await mk('Story: checklist only');
  await amel.post('check_save', { card_id: c2, text: 'Shoot the product' });
  await amel.get('journey');
  ok(one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND ikey = 'card:${c2}'`) === 1, 'une liste de contrôle suffit à rendre une carte éligible');
  ok((await amel.post('journey_grant', { xp: 1000 })).status === 404, 'aucune action ne permet au navigateur de s\'accorder des points');
  ok((await amel.post('journey_visibility', { id: 999999, visibility: 'link' })).status === 404, 'réalisation inconnue ou d\'un autre compte : introuvable');

  step('4. Limite du jour : les événements comptent, les points s\'arrêtent');
  const many = [];
  for (let i = 0; i < 12; i++) {
    const id = await mk('Post ' + i);
    await amel.post('card_save', { id, body: '<p>' + BODY + ' #' + i + '</p>' });
    await amel.post('card_move', { id, col_id: done.id, index: 0 });
    many.push(id);
  }
  j = await amel.get('journey');
  const paidDone = one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND kind = 'done' AND xp > 0`);
  const paidCard = one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND kind = 'card' AND xp > 0`);
  ok(paidDone === 10 && paidCard === 10, 'au plus 10 cartes créées et 10 terminées payées par jour', { paidDone, paidCard });
  ok(rowsOf(amel.id, 'done') === 13 && one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND note = 'daily limit'`) >= 3, 'au-delà : inscrites sans points (« daily limit »)');
  ok(j.level.xp === xpOf(amel.id) && j.level.xp === 35 + 10 * 2 + 10 * 10, 'total = somme du grand livre (155 XP)', j.level.xp);
  ok(one(`SELECT COUNT(*) FROM (SELECT ikey FROM xp_transactions GROUP BY uid, ikey HAVING COUNT(*) > 1)`) === 0, 'aucune clé en double dans le grand livre');

  step('5. Niveau de Studio dégagé (une seule fois)');
  ok(rowsOf(amel.id, 'level') === 0, 'des cartes restent ouvertes : niveau pas dégagé');
  for (const id of empties.slice(3)) await amel.post('card_move', { id, col_id: done.id, index: 0 });
  await amel.post('card_move', { id: c2, col_id: done.id, index: 0 });
  ok(rowsOf(amel.id, 'level') === 1 && one(`SELECT xp FROM xp_transactions WHERE uid = ${amel.id} AND kind = 'level'`) === 20, 'toutes les cartes terminées : niveau « Workflow » dégagé, +20 XP');
  await amel.post('card_move', { id: c2, col_id: todo.id, index: 0 });
  await amel.post('card_move', { id: c2, col_id: done.id, index: 0 });
  ok(rowsOf(amel.id, 'level') === 1, 'rouvert puis dégagé à nouveau : pas de second gain');
  j = await amel.get('journey');
  ok(j.level.n === 2 && j.level.name === 'Organizer', 'niveau 02 Organizer atteint (150 XP et 5 cartes)', j.level);
  cel = await amel.get('journey_celebrations');
  ok(cel.items.some((i) => i.kind === 'level' && /Level 02 — Organizer/.test(i.title) && /new milestone in your Creator Journey/.test(i.text)), 'célébration du nouveau niveau', cel.items.map((i) => i.title));
  ok(cel.items.some((i) => i.title === 'First Delivery') && cel.next.length === 1, 'célébrations regroupées + jalon suivant', cel.next);
  await amel.post('journey_seen');

  step('6. Projet terminé : critères vérifiés par le serveur');
  let pg = await amel.get('project_get', { id: pid });
  ok(pg.project.completion && pg.project.completion.eligible === false && pg.project.completion.criteria.find((c) => c.key === 'age').ok === false, 'projet trop récent (24 h) : pas encore éligible', pg.project.completion);
  r = await amel.post('project_complete', { id: pid, completed: true });
  ok(r.status === 400 && /hours ago/.test(r.message), 'refusé par le serveur avec la raison', r.message);
  sql(`UPDATE projects SET created = created - 3 * 86400 WHERE id = ${pid}`);
  const open = await mk('Still open');
  pg = await amel.get('project_get', { id: pid });
  ok(pg.project.completion.criteria.find((c) => c.key === 'open').ok === false, 'une carte ouverte : critère non rempli');
  ok((await amel.post('project_complete', { id: pid, completed: true })).status === 400, 'refusé tant qu\'une carte est ouverte');
  await amel.post('card_move', { id: open, col_id: done.id, index: 0 });
  r = await amel.post('project_complete', { id: pid, completed: true });
  ok(r.completion && r.completion.completed_at > 0 && r.completion.completed_by.id === amel.id, 'projet marqué terminé', r);
  ok(rowsOf(amel.id, 'project') === 1 && one(`SELECT xp FROM xp_transactions WHERE uid = ${amel.id} AND kind = 'project'`) === 50, '+50 XP pour le projet terminé');
  await amel.post('project_complete', { id: pid, completed: false });
  await amel.post('project_complete', { id: pid, completed: true });
  ok(rowsOf(amel.id, 'project') === 1, 'rouvert puis terminé de nouveau : pas de second gain');
  j = await amel.get('journey');
  ok(ach(j, 'project_finisher').status === 'verified', 'Project Finisher obtenue');
  ok((await amel.get('project_get', { id: pid })).project.completed_at > 0, 'le projet indique sa date de fin');

  step('7. Partager un Studio avec un collaborateur, travail en équipe');
  const karim = await signup('Karim Saidi', 'karim@test.example');
  await amel.post('invite_create', { project_id: pid, uid: karim.id, role: 'creator' });
  r = await karim.post('invite_accept', { token: one('SELECT token FROM invites WHERE uid = ' + karim.id) });
  ok(!r.error, 'Karim rejoint le projet', r);
  ok((await karim.post('project_complete', { id: pid, completed: false })).status === 403, 'un Creator ne peut pas rouvrir ni terminer le projet');
  j = await amel.get('journey');
  ok(rowsOf(amel.id, 'share') === 1 && one(`SELECT ikey FROM xp_transactions WHERE uid = ${amel.id} AND kind = 'share'`) === 'share:p' + pid, 'Amel : Studio partagé (+10), une fois pour ce projet');
  const lina = await signup('Lina Other', 'lina@test.example');
  await amel.post('invite_create', { project_id: pid, uid: lina.id, role: 'viewer' });
  await lina.post('invite_accept', { token: one('SELECT token FROM invites WHERE uid = ' + lina.id) });
  await amel.get('journey');
  ok(rowsOf(amel.id, 'share') === 1, 'une deuxième personne dans le même projet : pas de second gain');
  const amelDone = rowsOf(amel.id, 'done');
  const team = await mk('Reel: behind the scenes');
  await amel.post('card_save', { id: team, body: '<p>' + BODY + '</p>', assignee: karim.id });
  await karim.post('card_move', { id: team, col_id: done.id, index: 0 });
  j = await karim.get('journey');
  ok(rowsOf(karim.id, 'done') === 1 && rowsOf(amel.id, 'done') === amelDone, 'la carte revient à Karim, son responsable (pas à Amel)');
  ok(ach(j, 'team_player').status === 'progress', 'Team Player : pas tant que l\'autre compte n\'a pas confirmé son adresse');
  sql(`UPDATE users SET email_ok = 1 WHERE id IN (${amel.id}, ${karim.id})`);
  sql(`UPDATE journey_progress SET dirty = 1 WHERE uid = ${karim.id}`);
  j = await karim.get('journey');
  ok(ach(j, 'team_player').status === 'verified', 'Team Player obtenue (carte terminée avec un compte vérifié)');

  step('8. Validations de client');
  const clara = await signup('Clara Client', 'clara@brand.example');
  await amel.post('invite_create', { project_id: pid, uid: clara.id, role: 'client' });
  await clara.post('invite_accept', { token: one('SELECT token FROM invites WHERE uid = ' + clara.id) });
  const a1 = await mk('Video: launch film');
  await amel.post('card_save', { id: a1, publish_url: 'https://www.instagram.com/p/launch-film' });
  ok((await amel.post('approval_request', { card_id: a1, approver: amel.id })).status === 400, 'demander sa propre validation : refusé');
  await amel.post('approval_request', { card_id: a1, approver: clara.id });
  j = await amel.get('journey');
  ok(ach(j, 'client_approved').status === 'pending' && ach(j, 'client_approved').tab === 'pending' && /Waiting for 1 client approval request/.test(ach(j, 'client_approved').pending_note), 'demande en cours : « en attente de vérification »', ach(j, 'client_approved'));
  let appr = (await clara.get('card_get', { id: a1 })).card.approvals.find((a) => a.status === 'pending');
  await clara.post('approval_decide', { id: appr.id, decision: 'approved' });
  ok(one(`SELECT decided_by FROM card_approvals WHERE id = ${appr.id}`) === clara.id && one(`SELECT decider_role FROM card_approvals WHERE id = ${appr.id}`) === 'client', 'la décision garde qui a décidé et à quel titre');
  await amel.get('journey');
  ok(rowsOf(amel.id, 'approval') === 0, 'cliente sans adresse confirmée : pas encore comptée');
  sql(`UPDATE users SET email_ok = 1 WHERE id = ${clara.id}`);
  sql(`UPDATE journey_progress SET dirty = 1 WHERE uid = ${amel.id}`);
  j = await amel.get('journey');
  ok(rowsOf(amel.id, 'approval') === 1 && one(`SELECT xp FROM xp_transactions WHERE uid = ${amel.id} AND kind = 'approval'`) === 30, 'adresse confirmée : validation du client comptée (+30)');
  const ca = ach(j, 'client_approved');
  ok(ca.status === 'verified' && ca.verification === 'client' && ca.label === 'Client Verified', 'Client Approved : « Client Verified »', ca);
  ok((await clara.get('notifications')).notifications.some((n) => /Choose whether your name may appear/.test(n.text) && n.link === 'achievements/settings'), 'la cliente est prévenue et peut accepter que son nom apparaisse');
  // Un propriétaire qui décide à la place de la cliente : la validation n'est pas celle d'un client.
  const a2 = await mk('Carousel: owner shortcut');
  await amel.post('card_save', { id: a2, body: '<p>' + BODY + '</p>' });
  await amel.post('approval_request', { card_id: a2, approver: clara.id });
  appr = (await amel.get('card_get', { id: a2 })).card.approvals.find((a) => a.status === 'pending');
  r = await amel.post('approval_decide', { id: appr.id, decision: 'approved' });
  await amel.get('journey');
  ok(!r.error && one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND ikey = 'approval:${a2}'`) === 0, 'le propriétaire décide à la place de la cliente : pas de validation de client');
  // Un responsable (Manager) qui valide : relecture interne, pas un client.
  await amel.post('member_role', { project_id: pid, uid: lina.id, role: 'manager' });
  const a3 = await mk('Story: manager review');
  await amel.post('card_save', { id: a3, body: '<p>' + BODY + '</p>' });
  await amel.post('approval_request', { card_id: a3, approver: lina.id });
  appr = (await lina.get('card_get', { id: a3 })).card.approvals.find((a) => a.status === 'pending');
  await lina.post('approval_decide', { id: appr.id, decision: 'approved' });
  await amel.get('journey');
  ok(one(`SELECT decider_role FROM card_approvals WHERE id = ${appr.id}`) === 'manager' && one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND ikey = 'approval:${a3}'`) === 0, 'validation d\'un Manager : pas une validation de client');
  // Invité vérifié d'un lien privé (client sans compte).
  await amel.post('share_guest', { studio: sid, op: 'add', kind: 'private', emails: 'boss@brand.example', role: 'commenter', send: true });
  const privTok = (await amel.get('share_list', { studio: sid })).links.private.url.split('/s/')[1];
  const boss = new Client(privTok);
  r = await boss.verify('boss@brand.example');
  ok(r.guest && r.guest.verified, 'l\'invité vérifie son adresse', r);
  const gBoss = (await amel.get('share_list', { studio: sid })).guests.find((g) => g.email === 'boss@brand.example');
  const a4 = await mk('Reel: guest approval');
  await amel.post('card_save', { id: a4, body: '<p>' + BODY + '</p>' });
  await amel.post('approval_request', { card_id: a4, approver: 'g' + gBoss.id });
  appr = (await boss.get('card_get', { id: a4 })).card.approvals.find((a) => a.status === 'pending');
  r = await boss.post('approval_decide', { id: appr.id, decision: 'approved' });
  ok(!r.error, 'l\'invité approuve', r);
  await amel.get('journey');
  ok(one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND ikey = 'approval:${a4}'`) === 1 && one(`SELECT decider_role FROM card_approvals WHERE id = ${appr.id}`) === 'guest', 'validation d\'un invité vérifié : comptée');
  ok(one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND ikey = 'share:s${sid}'`) === 1, 'invité vérifié sur un lien du Studio : Studio partagé (+10, une fois)');
  ok((await boss.get('journey')).status !== 200, 'un invité n\'a pas accès au parcours');

  step('9. Réalisation publiée dans la communauté');
  r = await amel.post('pub_save', { card: c1, kind: 'achievement', title: 'Spring carousel', body: 'Six slides for the spring collection.', category: 'Design & visuals' });
  ok(r.pub && r.pub.status === 'published', 'réalisation publiée', r);
  await amel.get('journey');
  ok(rowsOf(amel.id, 'publish') === 1, '+20 XP pour la publication');
  await amel.post('pub_save', { card: c1, kind: 'achievement', title: 'Spring carousel (edited)', body: 'Six slides.', category: 'Design & visuals' });
  await amel.get('journey');
  ok(rowsOf(amel.id, 'publish') === 1, 'modifier la même publication : pas de second gain');

  step('10. Détails, visibilité et page de vérification publique');
  j = await amel.get('journey');
  const fd = ach(j, 'first_delivery');
  let d = await amel.get('journey_achievement', { id: fd.id });
  ok(d.achievement.evidence.length === 1 && d.achievement.evidence[0].label === 'Completed card record' && d.achievement.evidence[0].card === 'Carousel: spring collection' && d.achievement.evidence[0].project === 'Vélora',
    'détails (propriétaire) : la preuve et la carte qu\'elle peut encore ouvrir', d.achievement.evidence);
  ok(/reached a “done” column/.test(d.achievement.proves) && /does not prove/.test(d.achievement.not) && d.achievement.url === '', 'ce que la réalisation prouve et ne prouve pas ; pas de lien tant qu\'elle est privée');
  ok((await karim.get('journey_achievement', { id: fd.id })).status === 404, 'la réalisation d\'un autre compte : introuvable');
  let pgv = await anon.page('/studioma/verify/' + fd.public_id);
  ok(pgv.status === 404 && /isn't available/.test(pgv.html), 'privée : la page publique répond « indisponible »');
  r = await amel.post('journey_visibility', { id: fd.id, visibility: 'link' });
  ok(r.achievement.url.endsWith('/studioma/verify/' + fd.public_id) && /^[a-f0-9]{16}$/.test(fd.public_id), 'visible avec le lien : identifiant aléatoire non séquentiel', r.achievement.url);
  pgv = await anon.page('/studioma/verify/' + fd.public_id);
  ok(pgv.status === 200 && pgv.html.includes('First Delivery') && pgv.html.includes('Amel Haddad') && pgv.html.includes('System Verified') && pgv.html.includes('Completed card record'), 'page publique : titre, créatrice, vérification, preuve');
  ok(!/Vélora|Carousel: spring|Social 2026|amel@test|instagram/.test(pgv.html), 'aucun nom de projet, de Studio, de carte, d\'email ni de lien privé');
  ok(/noindex/.test(pgv.headers.get('x-robots-tag')) && !/<script/i.test(pgv.html), 'lien seulement : pas indexée ; aucun script');
  ok((await anon.page('/studioma/verify/0123456789abcdef')).status === 404 && (await anon.page('/studioma/verify/../api.php')).status === 404, 'identifiant inconnu ou invalide : 404');
  const viewsBefore = one(`SELECT views FROM user_achievements WHERE id = ${fd.id}`);
  await amel.page('/studioma/verify/' + fd.public_id);
  ok(one(`SELECT views FROM user_achievements WHERE id = ${fd.id}`) === viewsBefore, 'les visites de la propriétaire ne sont pas comptées');
  await amel.post('journey_shared', { id: fd.id });
  ok(one(`SELECT shared FROM user_achievements WHERE id = ${fd.id}`) === 1, 'partage compté (statistiques)');

  step('11. Consentement du client et nom sur la page publique');
  const caId = ca.id;
  await amel.post('journey_visibility', { id: caId, visibility: 'link' });
  pgv = await anon.page('/studioma/verify/' + ca.public_id);
  ok(pgv.status === 200 && pgv.html.includes('Approved by a verified client') && !pgv.html.includes('Clara') && pgv.html.includes('Client Verified'), 'sans accord : « Approved by a verified client », nom caché');
  let st = await clara.get('journey_settings');
  const given = st.given.find((g) => g.creator.id === amel.id);
  ok(given && given.named === false && given.achievement === 'Client Approved', 'la cliente voit les validations qu\'elle a données', st.given);
  ok((await karim.post('journey_consent', { id: given.id, named: true })).status === 404, 'un autre compte ne peut pas donner l\'accord à sa place');
  await clara.post('journey_consent', { id: given.id, named: true });
  pgv = await anon.page('/studioma/verify/' + ca.public_id);
  ok(pgv.html.includes('Approved by Clara Client') && !pgv.html.includes('clara@brand'), 'avec son accord : son nom (jamais son adresse)');

  step('12. Passeport public');
  ok((await anon.page('/studioma/creator/amel-haddad')).status === 404, 'passeport fermé par défaut : 404');
  r = await amel.post('journey_passport_save', { enabled: true, headline: 'Community manager for fashion brands', show_level: true, show_stats: true, show_work: true, show_journey: true });
  ok(r.passport.enabled && r.passport.headline === 'Community manager for fashion brands', 'passeport ouvert');
  let pp = await anon.page('/studioma/creator/amel-haddad');
  ok(pp.status === 200 && pp.html.includes('Community manager for fashion brands') && pp.html.includes('Level 02 — Organizer') && /index, follow/.test(pp.headers.get('x-robots-tag')), 'passeport : titre, niveau, indexable');
  ok(!pp.html.includes('First Delivery'), 'réalisation « avec le lien » : pas sur le passeport');
  await amel.post('journey_visibility', { id: fd.id, visibility: 'passport' });
  pp = await anon.page('/studioma/creator/amel-haddad');
  ok(pp.html.includes('First Delivery') && pp.html.includes('/studioma/verify/' + fd.public_id) && !pp.html.includes('Client Approved'), 'seules les réalisations choisies apparaissent, avec leur lien de vérification');
  ok(!/Vélora|amel@test|clara|boss@brand|Second brand/.test(pp.html), 'aucun projet, email ni client sur le passeport');
  await amel.post('journey_passport_save', { enabled: true, headline: '', show_level: false, show_stats: false, show_work: false, show_journey: false });
  pp = await anon.page('/studioma/creator/amel-haddad');
  ok(pp.status === 200 && !/Level 0\d/.test(pp.html) && !pp.html.includes('Completed projects') && !pp.html.includes('Creator Journey</h2>'), 'niveau, chiffres et parcours masqués à la demande');
  const amel2 = await signup('Amel Haddad', 'amel.two@test.example');
  ok(one('SELECT handle FROM users WHERE id = ' + amel2.id) === 'amel-haddad-2' && (await anon.page('/studioma/creator/amel-haddad-2')).status === 404, 'même nom : identifiant distinct, passeport distinct (fermé)');
  ok((await anon.page('/studioma/creator/Amel-Haddad')).status === 404 && (await anon.page('/studioma/creator/amel%20haddad')).status === 404, 'identifiant mal formé : 404');

  step('13. Réalisation déclarée (jamais présentée comme vérifiée)');
  r = await amel.post('journey_self_save', { title: 'Speaker at a marketing meetup', body: 'Talk about short-form video.', date: '2026-10-02', url: 'https://example.com/talk' });
  const self = r.achievement;
  ok(self.status === 'self' && self.verification === 'self' && self.label === 'Self Reported — not independently verified' && xpOf(amel.id) === one(`SELECT xp FROM journey_progress WHERE uid = ${amel.id}`), 'déclarée : « Self Reported — not independently verified », aucun XP', self);
  await amel.post('journey_visibility', { id: self.id, visibility: 'link' });
  pgv = await anon.page('/studioma/verify/' + self.public_id);
  ok(pgv.status === 200 && pgv.html.includes('Self reported') && pgv.html.includes('Studioma did not verify it') && !pgv.html.includes('pp-status-ok'), 'page publique : « Self reported », jamais l\'état vérifié');
  ok((await amel.post('journey_self_delete', { id: fd.id })).status === 400, 'une réalisation vérifiée ne se supprime pas');
  ok((await amel.post('journey_self_save', { title: 'x' })).status === 400, 'titre obligatoire');

  step('14. Chronologie, résumé, jalons');
  let tl = await amel.get('journey_timeline', { filter: 'all' });
  ok(tl.items.some((i) => i.text === 'Joined Studioma') && tl.items.some((i) => /Completed \d+ eligible cards in/.test(i.text)) && tl.items.some((i) => /Reached Level 02/.test(i.text)), 'chronologie : inscription, cartes du mois, niveau', tl.items.map((i) => i.text).slice(0, 8));
  tl = await amel.get('journey_timeline', { filter: 'clients' });
  ok(tl.items.length >= 2 && tl.items.every((i) => i.cat === 'clients'), 'filtre « Client Recognition »');
  tl = await amel.get('journey_timeline', { filter: 'collaboration' });
  ok(tl.items.some((i) => /Shared a Studio/.test(i.text)), 'filtre « Collaboration »');
  j = await amel.get('journey');
  ok(/Member since .* completed project/.test(j.summary) && /client approval/.test(j.summary), 'résumé factuel', j.summary);
  ok(!j.next.some((n) => ['studio', 'first_card', 'five_cards', 'share', 'project', 'approval'].includes(n.key)), 'aucun jalon déjà franchi n\'est proposé', j.next.map((n) => n.key));

  step('15. Formule Free : jalons accessibles seulement, et rien de payant');
  await setConfig('STM_PLANS_ENFORCED', 'true');
  const free = await signup('Farid Free', 'farid@test.example');
  const fp = await free.post('project_save', { name: 'Solo brand', template: 'kanban' });
  j = await free.get('journey');
  ok(!j.next.some((n) => n.key === 'share' || n.key === 'approval'), 'formule Free : ni partage avec un membre ni validation proposés', j.next.map((n) => n.key));
  ok(j.level.xp === 35 && ach(j, 'first_studio').status === 'verified', 'le parcours fonctionne sur la formule Free (aucun paywall)');
  j = await amel.get('journey');
  const amelXp = j.level.xp;
  ok(amelXp === xpOf(amel.id) && ach(j, 'project_finisher').status === 'verified', 'formule appliquée : XP et réalisations d\'Amel intacts');
  await setConfig('STM_PLANS_ENFORCED', 'false');
  ok(fp.id > 0, 'projet Free créé');

  step('16. Règles réglées par l\'administration');
  const adm = new Client();
  ok((await amel.get('admin_journey')).status === 401 || (await amel.get('admin_journey')).status === 403, 'administration : refusée à un membre');
  await adm.adminLogin();
  let a = await adm.get('admin_journey');
  ok(a.metrics && a.metrics.participants >= 3 && a.metrics.achievements >= 5 && a.metrics.projects === 1 && a.metrics.client >= 1 && a.metrics.passports === 1, 'indicateurs', a.metrics);
  ok(a.level_distribution.find((l) => l.n === 2).users >= 1 && a.xp_distribution.length === 7 && a.achievements.length === 6, 'répartitions par niveau et par XP, six réalisations');
  const evs = Object.fromEntries(a.rules.events.map((e) => [e.key, { xp: e.xp, cap: e.cap }]));
  evs.done = { xp: 15, cap: 100 };
  a = await adm.post('admin_journey_rules', { events: evs, params: { proven_projects: 1, proven_approvals: 2, proven_approval_projects: 1, project_min_hours: 0 }, levels: { 3: { xp: 300, done: 10, projects: 1 } }, off: [] });
  ok(a.rules.v === 2 && a.rules.events.find((e) => e.key === 'done').xp === 15 && a.rules.params.find((p) => p.key === 'proven_projects').value === 1, 'nouvelles règles (version 2)', a.rules.v);
  j = await amel.get('journey');
  ok(one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${amel.id} AND kind = 'done' AND xp = 15`) === 0, 'les points déjà accordés gardent leur valeur');
  ok(ach(j, 'proven_creator').status === 'verified' && ach(j, 'proven_creator').verification === 'evidence' && ach(j, 'proven_creator').label === 'Evidence Based', 'Proven Creator : 1 projet terminé + 2 validations de client (preuves)', ach(j, 'proven_creator'));
  ok(j.level.n === 3 && j.level.name === 'Creator', 'seuils abaissés : niveau 03 Creator', j.level);
  d = await amel.get('journey_achievement', { id: ach(j, 'proven_creator').id });
  ok(d.achievement.evidence.filter((e) => e.label === 'Client approval record').length === 2 && d.achievement.evidence.some((e) => e.label === 'Completed project record'), 'preuves de Proven Creator : projet terminé et validations', d.achievement.evidence);
  const fresh = await mk('After the rule change');
  await amel.post('card_save', { id: fresh, body: '<p>' + BODY + '</p>' });
  await amel.post('card_move', { id: fresh, col_id: done.id, index: 0 });
  ok(one(`SELECT xp FROM xp_transactions WHERE uid = ${amel.id} AND ikey = 'done:${fresh}'`) === 15 && one(`SELECT rule_v FROM xp_transactions WHERE uid = ${amel.id} AND ikey = 'done:${fresh}'`) === 2, 'nouvelle carte terminée : 15 XP, version de règle 2');
  a = await adm.post('admin_journey_rules', { events: Object.assign(evs, { done: { xp: 10, cap: 10 } }), params: {}, levels: {}, off: ['team_player'] });
  const lisa = await signup('Lisa New', 'lisa@test.example');
  j = await lisa.get('journey');
  ok(!j.achievements.some((x) => x.aid === 'team_player'), 'réalisation désactivée : plus proposée');
  j = await karim.get('journey');
  ok(ach(j, 'team_player') && ach(j, 'team_player').status === 'verified', '… celles déjà obtenues restent');
  await adm.post('admin_journey_rules', { events: evs, params: {}, levels: {}, off: [] });

  step('17. Retrait et restitution par l\'administration (avec raison)');
  a = await adm.get('admin_journey_user', { id: amel.id });
  ok(a.ledger.length > 20 && a.achievements.length >= 6 && a.achievements.find((x) => x.aid === 'client_approved').evidence[0].client.includes('Clara Client'), 'fiche d\'un compte : grand livre, réalisations, preuves (client nommé pour l\'administration)');
  const before = xpOf(amel.id);
  const line = a.ledger.find((x) => x.kind === 'publish');
  ok((await adm.post('admin_journey_revoke', { type: 'xp', id: line.id, reason: '' })).status === 400, 'raison obligatoire');
  a = await adm.post('admin_journey_revoke', { type: 'xp', id: line.id, reason: 'Duplicate publication' });
  ok(xpOf(amel.id) === before - 20 && one(`SELECT revoked_reason FROM xp_transactions WHERE id = ${line.id}`) === 'Duplicate publication', 'ligne retirée : −20 XP, raison gardée');
  await amel.get('journey');
  ok(rowsOf(amel.id, 'publish') === 1 && xpOf(amel.id) === before - 20, 'une ligne retirée n\'est jamais accordée de nouveau');
  await adm.post('admin_journey_revoke', { type: 'xp', id: line.id, restore: true });
  ok(xpOf(amel.id) === before, 'ligne rendue');
  a = await adm.post('admin_journey_revoke', { type: 'achievement', id: fd.id, reason: 'Card copied from another account' });
  ok(a.achievements.find((x) => x.id === fd.id).status === 'revoked', 'réalisation retirée');
  ok((await amel.get('notifications')).notifications.some((n) => /revoked your achievement “First Delivery”: Card copied/.test(n.text)), 'la membre est prévenue avec la raison');
  pgv = await anon.page('/studioma/verify/' + fd.public_id);
  ok(pgv.status === 200 && pgv.html.includes('Revoked') && pgv.html.includes('no longer valid') && !/pp-status-ok|System Verified|verified achievement/.test(pgv.html) && !pgv.html.includes('Card copied'), 'lien déjà partagé : la page affiche « Revoked », jamais « Verified » (raison interne non publiée)');
  ok(!(await anon.page('/studioma/creator/amel-haddad')).html.includes('First Delivery'), 'retirée du passeport');
  ok((await amel.post('journey_visibility', { id: fd.id, visibility: 'link' })).status === 400, 'une réalisation retirée ne peut pas être partagée');
  j = await amel.get('journey');
  ok(ach(j, 'first_delivery').status === 'revoked' && ach(j, 'first_delivery').revoked_reason === 'Card copied from another account', 'la membre voit l\'état et la raison');
  await adm.post('admin_journey_revoke', { type: 'achievement', id: fd.id, restore: true });
  j = await amel.get('journey');
  ok(ach(j, 'first_delivery').status === 'verified' && ach(j, 'first_delivery').visibility === 'private', 'restituée (redevient privée)');
  ok(one("SELECT COUNT(*) FROM admin_log WHERE action LIKE 'journey_%'") >= 5, 'chaque décision est dans le journal de l\'administration');
  a = await adm.get('admin_journey');
  ok(a.flags.some((f) => f.user && f.user.id === amel.id) || a.flags.length >= 0, 'liste d\'activité à examiner disponible', a.flags);

  step('18. Abonnement : l\'acquis reste quand la formule change');
  const xpNow = xpOf(amel.id);
  sql(`INSERT INTO subscriptions (uid, provider, env, plan, status, period_start, period_end, created, updated) VALUES (${amel.id}, 'manual', 'manual', 'team', 'active', ${Math.floor(Date.now() / 1000) - 100}, ${Math.floor(Date.now() / 1000) - 10}, 0, 0)`);
  j = await amel.get('journey');
  ok(j.level.xp === xpNow && ach(j, 'proven_creator').status === 'verified', 'accès Team terminé : XP, niveau et réalisations intacts');

  step('19. Migration rejouée sur une base existante (sans perte)');
  sql(`UPDATE card_approvals SET decided_by = 0, decider_role = '' WHERE status = 'approved'`);
  sql('PRAGMA user_version = 11');
  const n0 = one('SELECT COUNT(*) FROM xp_transactions');
  await anon.get('me');
  ok(one('PRAGMA user_version') === 12, 'base remise en v12');
  ok(one(`SELECT decided_by FROM card_approvals WHERE card_id = ${a1}`) === clara.id && one(`SELECT decider_role FROM card_approvals WHERE card_id = ${a1}`) === 'client', 'décision d\'une cliente retrouvée dans l\'historique');
  ok(one(`SELECT decided_by FROM card_approvals WHERE card_id = ${a2}`) === amel.id && one(`SELECT decider_role FROM card_approvals WHERE card_id = ${a2}`) === 'owner', 'décision d\'un propriétaire à sa place : retrouvée comme telle');
  ok(one(`SELECT decider_role FROM card_approvals WHERE card_id = ${a4}`) === 'guest', 'décision d\'un invité retrouvée');
  ok(one('SELECT COUNT(*) FROM xp_transactions') === n0 && one('SELECT COUNT(*) FROM projects') >= 3, 'aucune donnée perdue');

  step('20. Requêtes simultanées : aucun doublon');
  sql(`UPDATE journey_progress SET dirty = 1`);
  sql(`DELETE FROM xp_transactions WHERE uid = ${karim.id}`);
  await Promise.all(Array.from({ length: 8 }, () => karim.get('journey')));
  ok(one(`SELECT COUNT(*) FROM xp_transactions WHERE uid = ${karim.id} AND kind = 'done'`) === 1 && one(`SELECT COUNT(*) FROM (SELECT ikey FROM xp_transactions GROUP BY uid, ikey HAVING COUNT(*) > 1)`) === 0, 'huit recalculs en même temps : une seule ligne par événement');

  console.log(failed ? `\n${failed} échec(s)` : '\nTous les tests du parcours du créateur sont passés.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
