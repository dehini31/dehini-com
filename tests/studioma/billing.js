/*
 * Tests des formules et des abonnements (Free, Solo, Team) sur le faux hébergement de run.sh, limites appliquées
 * (setup.sh avec BILLING=1) et faux Paddle (tests/studioma/fake-paddle.php) :
 * migration des comptes existants, limites Free / Solo / Team côté serveur (API et serveur MCP), compteur
 * mensuel de cartes, invités en lecture seule sur Free, paiement (transaction liée au compte), webhooks signés
 * (signature, rejeu, désordre, compte inconnu), montée et descente de formule (prorata Paddle), résiliation,
 * reprise, paiement en échec et délai de grâce, places de membres (invités non comptés), créations simultanées,
 * isolement entre comptes, indicateurs de l'administration (MRR, ARR), formule offerte.
 *   node tests/studioma/billing.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
const SECRET = 'test-webhook-secret';
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : '')); } };
const step = (t) => console.log('\n' + t);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
/** Requête SQL directe sur la base de test (simuler le temps qui passe). */
const sql = (query) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $s = $p->query($argv[2]); echo json_encode($s ? $s->fetchAll(PDO::FETCH_ASSOC) : []);', DB, query], { encoding: 'utf8' });

class Client {
  constructor(opts) { this.jar = {}; this.csrf = ''; this.share = (opts && opts.share) || ''; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  keep(res) {
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => {
      const [k, v] = c.split(';')[0].split('=');
      if (v === '' || /expires=Thu, 01 Jan 1970|Max-Age=0/i.test(c)) delete this.jar[k]; else this.jar[k] = v;
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
    if (body !== undefined) Object.assign(init, { method: 'POST', body: JSON.stringify(body) }), Object.assign(headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf });
    const res = await fetch(url, init);
    this.keep(res);
    const d = await res.json().catch(() => ({}));
    if (d.csrf && !this.admin) this.csrf = d.csrf;
    d.status = res.status;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
  async adminLogin() {
    const res = await fetch(ORIGIN + '/admin/api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ password: 'test-owner-pass' }) });
    this.keep(res);
    const d = await res.json();
    this.admin = true;
    this.csrf = d.csrf;
    return d;
  }
}
const signup = async (name, email) => { const c = new Client(); const r = await c.post('signup', { name, email, password: 'a-solid-password', accept: true }); c.id = r.user.id; c.email = email; return c; };
const fake = async (p, body) => (await fetch(ORIGIN + '/fake-paddle' + p, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {})).json();
let evn = 0;
/** Notification Paddle signée (ou mal signée) envoyée au webhook de Studioma. */
async function webhook(type, data, opts) {
  opts = opts || {};
  const raw = JSON.stringify({ event_id: opts.id || 'evt_' + (++evn).toString().padStart(26, '0'), event_type: type, occurred_at: new Date().toISOString(), notification_id: 'ntf_' + evn, data });
  const ts = opts.ts || Math.floor(Date.now() / 1000);
  const h1 = crypto.createHmac('sha256', opts.secret || SECRET).update(ts + ':' + raw).digest('hex');
  const headers = { 'Content-Type': 'application/json' };
  if (!opts.noSig) headers['Paddle-Signature'] = 'ts=' + ts + ';h1=' + h1;
  const res = await fetch(ORIGIN + '/studioma/paddle-webhook.php', { method: 'POST', headers, body: opts.tamper ? raw.replace('active', 'actives') : raw });
  return { status: res.status, body: await res.json().catch(() => ({})), raw };
}
/** Abonnement payé de bout en bout : transaction créée par Studioma, paiement simulé par le faux Paddle, webhooks. */
async function subscribe(c, plan, interval) {
  const r = await c.post('billing_checkout', { plan, interval });
  const done = await fake('/__complete', { transaction_id: r.transaction_id });
  await webhook('transaction.completed', done.transaction);
  await webhook('subscription.created', done.subscription);
  return { checkout: r, sub: done.subscription, txn: done.transaction };
}
const isLimit = (r, feature, upgrade) => r.status === 402 && r.error === 'plan_limit' && (!feature || r.feature === feature) && (upgrade === undefined || r.upgrade === upgrade);

(async () => {
  step('Formules et configuration');
  const anon = new Client();
  let r = await anon.get('billing_plans');
  const P = Object.fromEntries((r.plans || []).map((p) => [p.key, p]));
  ok(r.plans.length === 3 && P.free.price.month === 0 && P.solo.price.month === 1200 && P.solo.price.year === 12000 && P.team.price.month === 2900 && P.team.price.year === 29000, 'trois formules : Free 0 $, Solo 12 $ / 120 $, Team 29 $ / 290 $');
  ok(P.free.limits.projects === 1 && P.free.limits.studios === 1 && P.free.limits.cards_month === 30 && P.free.limits.members === 1 && P.free.limits.share_links === 2
    && P.solo.limits.projects === 3 && P.solo.limits.studios === 5 && P.solo.limits.cards_month === null && P.solo.limits.members === 1
    && P.team.limits.projects === 10 && P.team.limits.studios === 20 && P.team.limits.members === 5, 'limites de chaque formule');
  ok(r.checkout === true && r.env === 'sandbox' && r.current === null, 'paiement ouvert (sandbox configuré), pas de formule sans compte');
  r = await anon.get('billing_config');
  ok(r.available && r.client_token === 'test_client_token_0123' && !JSON.stringify(r).includes('test-paddle-api-key') && !JSON.stringify(r).includes(SECRET), 'page de paiement : jeton client seulement (jamais la clé d\'API ni le secret)');
  ok((await anon.get('billing')).status === 401 && (await anon.post('billing_checkout', { plan: 'solo', interval: 'month' })).status === 401, 'abonnement : compte connecté obligatoire');

  step('Migration des comptes existants');
  const old = await signup('Old Member', 'old@test.example');
  r = await old.post('project_save', { name: 'Legacy', template: 'content', studio_name: 'Legacy Studio' });
  const legacyStudio = r.studio_id;
  const before = JSON.parse(sql("SELECT (SELECT COUNT(*) FROM projects) p, (SELECT COUNT(*) FROM studios) s, (SELECT COUNT(*) FROM cards) c, (SELECT COUNT(*) FROM users) u"))[0];
  // Base remise dans l'état d'avant les abonnements (schéma 8) : la migration doit la remettre à niveau sans rien perdre.
  execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); foreach (["DROP TABLE subscriptions","DROP TABLE billing_customers","DROP TABLE billing_events","DROP TABLE billing_transactions","DROP TABLE usage_counters","PRAGMA user_version = 8"] as $q) { try { $p->exec($q); } catch (Throwable $e) {} }', DB]);
  ok(JSON.parse(sql('PRAGMA user_version'))[0].user_version === 8, 'base ramenée au schéma 8 (avant les abonnements)');
  r = await old.get('billing');
  const after = JSON.parse(sql("SELECT (SELECT COUNT(*) FROM projects) p, (SELECT COUNT(*) FROM studios) s, (SELECT COUNT(*) FROM cards) c, (SELECT COUNT(*) FROM users) u"))[0];
  ok(JSON.parse(sql('PRAGMA user_version'))[0].user_version === 9 && JSON.stringify(before) === JSON.stringify(after), 'migration additive : schéma 9, projets, Studios, cartes et comptes intacts', { before, after });
  ok(r.plan === 'free' && r.state === 'free' && JSON.parse(sql('SELECT COUNT(*) n FROM subscriptions'))[0].n === 0, 'compte existant sans abonnement : formule Free (aucune ligne créée, pas de doublon possible)', { plan: r.plan, state: r.state });
  ok((await old.get('board', { studio: legacyStudio })).ok, 'données existantes lisibles');

  step('Formule Free : limites appliquées par le serveur');
  const amel = await signup('Amel Haddad', 'amel@test.example');
  r = await amel.get('billing');
  ok(r.plan === 'free' && r.usage.projects === 0 && r.limits.cards_month === 30 && r.enforced, 'nouveau compte : Free, usage à zéro');
  r = await amel.post('project_save', { name: 'Velora', template: 'content', studio_name: 'Social' });
  const pid = r.id;
  const sid = r.studio_id;
  ok(r.ok && sid, 'premier projet et son Studio');
  r = await amel.post('project_save', { name: 'Second' });
  ok(isLimit(r, 'projects', 'solo') && r.message === 'You\'ve reached your Free plan limit. Upgrade to Solo to manage more projects.', 'deuxième projet refusé : message et formule conseillée', r);
  r = await amel.post('studio_create', { project_id: pid, name: 'Second Studio' });
  ok(isLimit(r, 'studios', 'solo'), 'deuxième Studio refusé');
  await amel.post('project_archive', { id: pid, archived: true });
  r = await amel.post('project_save', { name: 'Temp', template: 'kanban', studio_name: 'Temp' });
  ok(r.ok, 'un projet archivé ne compte plus : nouveau projet possible');
  const tempId = r.id;
  r = await amel.post('project_archive', { id: pid, archived: false });
  ok(isLimit(r, 'projects'), 'restaurer le projet archivé : refusé tant que la limite est atteinte');
  await amel.post('project_delete', { id: tempId, confirm: 'Temp' });
  ok((await amel.post('project_archive', { id: pid, archived: false })).ok, 'après suppression volontaire : restauration possible');
  let b = await amel.get('board', { studio: sid });
  const col = b.cols.find((c) => c.name.includes('Ideas'));
  for (let i = 0; i < 30; i++) await amel.post('card_create', { col_id: col.id, title: 'Card ' + i });
  r = await amel.post('card_create', { col_id: col.id, title: 'Card 31' });
  ok(isLimit(r, 'cards', 'solo') && /30 new cards/.test(r.message), '31e carte du mois refusée', r);
  b = await amel.get('board', { studio: sid });
  const card0 = b.cards[0].id;
  ok(isLimit(await amel.post('card_duplicate', { id: card0 }), 'cards'), 'dupliquer une carte compte aussi');
  await amel.post('card_delete', { id: card0 });
  ok(isLimit(await amel.post('card_create', { col_id: col.id, title: 'After delete' })), 'supprimer une carte ne rend pas d\'allocation (cartes créées, pas stockées)');
  ok((await amel.get('billing')).usage.cards_month === 30, 'compteur du mois : 30');
  // Nouveau mois : le compteur du mois passé reste, l'allocation repart à zéro ; aucune carte n'est supprimée.
  const prev = new Date(); prev.setDate(1); prev.setMonth(prev.getMonth() - 1);
  const prevKey = prev.getFullYear() + '-' + String(prev.getMonth() + 1).padStart(2, '0');
  sql(`UPDATE usage_counters SET period = '${prevKey}' WHERE uid = ${amel.id}`);
  const cardsBefore = JSON.parse(sql(`SELECT COUNT(*) n FROM cards WHERE studio_id = ${sid}`))[0].n;
  r = await amel.post('card_create', { col_id: col.id, title: 'New month' });
  ok(r.ok && (await amel.get('billing')).usage.cards_month === 1 && JSON.parse(sql(`SELECT COUNT(*) n FROM cards WHERE studio_id = ${sid}`))[0].n === cardsBefore + 1, 'nouveau mois : allocation renouvelée, cartes précédentes conservées');
  r = await amel.post('invite_create', { project_id: pid, email: 'karim@test.example', role: 'creator' });
  ok(isLimit(r, 'team', 'team') && r.message === 'Team collaboration is available on the Team plan.', 'inviter un membre : réservé à Team');
  ok(isLimit(await amel.post('invite_create', { studio_id: sid, role: 'viewer' }), 'team'), 'lien d\'invitation : réservé à Team aussi');
  r = await amel.post('share_save', { studio: sid, kind: 'public' });
  ok(r.ok && r.links.public.active, 'lien public (lecture seule) : autorisé');
  const T = r.links.public.url.split('/s/')[1];
  ok(isLimit(await amel.post('share_save', { studio: sid, kind: 'private' }), 'private_links', 'solo'), 'lien privé : Solo et Team');
  ok(isLimit(await amel.post('share_guest', { studio: sid, op: 'add', kind: 'public', emails: 'client@brand.example', role: 'editor' }), 'guest_collab'), 'ajouter un invité avec des droits : Solo et Team');
  r = await amel.post('approval_request', { card_id: b.cards[1].id, approver: 1 });
  ok(isLimit(r, 'approvals', 'solo') && r.message === 'Client approval workflows are available on Solo and Team.', 'demande de validation : Solo et Team');
  r = await amel.get('studio_stats', { studio: sid });
  ok(isLimit(r, 'insights', 'team'), 'Insights : réservé à Team');
  // Invité d'un lien sur Free : vérifié par code, mais en lecture seule et sans demande possible.
  const guest = new Client({ share: T });
  await guest.post('share_code', { email: 'client@brand.example' });
  const code = (mails().reverse().find((m) => m.to === 'client@brand.example').subject.match(/\b\d{6}\b/) || [])[0];
  r = await guest.post('share_verify', { email: 'client@brand.example', code });
  ok(r.ok && r.guest.role === 'viewer' && r.guest.requests === false, 'invité vérifié sur Free : lecture seule, pas de demande de droits', r.guest);
  r = await guest.post('share_request', { role: 'editor', note: 'please' });
  ok(r.status === 403 && /view-only/.test(r.message), 'demande de droits d\'un invité : refusée sur Free');
  ok((await guest.post('comment_add', { card_id: b.cards[1].id, body: 'hi' })).status === 403, 'invité sur Free : aucun commentaire');

  step('Serveur MCP : mêmes limites');
  r = await amel.post('ai_token_create', { name: 'Tests' });
  const mcp = async (name, args) => (await (await fetch(ORIGIN + '/studioma/mcp', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-06-18', Authorization: 'Bearer ' + r.token }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name, arguments: args } }) })).json()).result;
  const m = await mcp('create_project', { name: 'Via AI' });
  ok(m && m.isError && /Free plan limit/.test(m.content[0].text), 'assistant IA : projet refusé avec le même message', m);

  step('Paiement : transaction liée au compte, formule activée par Paddle seulement');
  r = await amel.post('billing_checkout', { plan: 'gold', interval: 'month' });
  ok(r.status === 400, 'formule inconnue refusée');
  r = await amel.post('billing_checkout', { plan: 'solo', interval: 'month' });
  ok(r.ok && /^txn_/.test(r.transaction_id) && r.checkout_url === ORIGIN + '/studioma/pay/?_ptxn=' + r.transaction_id, 'transaction Paddle créée par le serveur, page de paiement Studioma', r);
  const st = await fake('/__state');
  const txn = st.transactions[r.transaction_id];
  ok(txn.custom_data.stm_uid === String(amel.id) && st.customers[txn.customer_id].email === 'amel@test.example', 'transaction liée au compte : client Paddle de son adresse, custom_data');
  ok((await amel.get('billing')).plan === 'free', 'avant la confirmation de Paddle : toujours Free (l\'adresse de retour n\'active rien)');
  const done = await fake('/__complete', { transaction_id: r.transaction_id });
  let w = await webhook('subscription.created', done.subscription, { secret: 'wrong-secret' });
  ok(w.status === 401, 'webhook mal signé : refusé');
  ok((await webhook('subscription.created', done.subscription, { noSig: true })).status === 401, 'webhook sans signature : refusé');
  ok((await webhook('subscription.created', done.subscription, { ts: Math.floor(Date.now() / 1000) - 3600 })).status === 401, 'webhook trop ancien (rejeu) : refusé');
  ok((await webhook('subscription.created', done.subscription, { tamper: true })).status === 401, 'corps modifié après signature : refusé');
  ok((await amel.get('billing')).plan === 'free', 'aucune de ces notifications n\'a changé la formule');
  w = await webhook('transaction.completed', done.transaction);
  ok(w.status === 200 && w.body.status === 'processed', 'paiement reçu (transaction.completed)');
  w = await webhook('subscription.created', done.subscription, { id: 'evt_sub_created_amel' });
  ok(w.status === 200 && w.body.status === 'processed', 'abonnement reçu (subscription.created)');
  r = await amel.get('billing');
  ok(r.plan === 'solo' && r.state === 'active' && r.subscription.interval === 'month' && r.subscription.amount === 1200 && r.subscription.period_end > Date.now() / 1000 + 20 * 86400, 'formule Solo active, mensuelle, 12 $, prochaine échéance', r.subscription);
  ok(r.transactions.length === 1 && r.transactions[0].amount === 1200 && r.transactions[0].status === 'completed', 'historique des paiements');
  w = await webhook('subscription.created', done.subscription, { id: 'evt_sub_created_amel' });
  ok(w.status === 200 && w.body.duplicate && JSON.parse(sql(`SELECT COUNT(*) n FROM subscriptions WHERE uid = ${amel.id}`))[0].n === 1, 'même notification reçue deux fois : traitée une seule fois');
  const older = Object.assign({}, done.subscription, { status: 'past_due', updated_at: '2001-01-01T00:00:00.000000Z' });
  w = await webhook('subscription.updated', older);
  const afterStale = await amel.get('billing');
  ok(w.body.status === 'stale' && afterStale.state === 'active', 'notification plus ancienne arrivée en retard : ignorée', w.body);
  ok((await amel.post('billing_checkout', { plan: 'team', interval: 'month' })).status === 409, 'pas de second abonnement : changer de formule à la place');

  step('Formule Solo');
  ok((await amel.post('project_save', { name: 'P2' })).ok && (await amel.post('project_save', { name: 'P3' })).ok, '3 projets');
  r = await amel.post('project_save', { name: 'P4' });
  ok(isLimit(r, 'projects', 'team') && /limit of 3 projects. Upgrade to Team to manage up to 10/.test(r.message), '4e projet : refusé, Team conseillé', r.message);
  ok((await amel.post('card_create', { col_id: col.id, title: 'Unlimited' })).ok, 'cartes illimitées (au-delà de 30 dans le mois)');
  ok((await amel.post('share_save', { studio: sid, kind: 'private' })).ok, 'lien privé : autorisé');
  ok(isLimit(await amel.post('invite_create', { project_id: pid, email: 'karim@test.example', role: 'creator' }), 'team', 'team'), 'membres : toujours réservé à Team');
  r = await amel.post('approval_request', { card_id: b.cards[1].id, approver: 999 });
  ok(r.status === 400 && r.error !== 'plan_limit', 'demandes de validation : autorisées (validateur à choisir)');
  ok(isLimit(await amel.get('studio_stats', { studio: sid }), 'insights', 'team'), 'Insights : toujours Team');
  r = await guest.get('share_open');
  ok(r.guest && r.guest.requests === true, 'invités : demandes de droits de nouveau possibles');
  ok((await amel.post('share_guest', { studio: sid, op: 'add', kind: 'private', emails: 'reviewer@brand.example', role: 'commenter' })).ok, 'invités ajoutés par email avec des droits');

  step('Montée Solo → Team (prorata Paddle)');
  r = await amel.post('billing_change', { plan: 'team', interval: 'month' });
  ok(r.ok && r.plan === 'team' && (await fake('/__state')).last_proration === 'prorated_immediately', 'Team aussitôt (réponse vérifiée de Paddle), prorata immédiat');
  ok((await amel.get('studio_stats', { studio: sid })).ok, 'Insights disponibles');
  r = await amel.post('invite_create', { project_id: pid, email: 'karim@test.example', role: 'creator' });
  ok(r.ok, 'invitation d\'un membre');
  const karim = await signup('Karim Benali', 'karim@test.example');
  r = await karim.post('invite_accept', { token: r.invite.token });
  ok(r.ok, 'le membre rejoint l\'équipe');
  for (const e of ['a@x.example', 'b@x.example', 'c@x.example']) await amel.post('invite_create', { project_id: pid, email: e, role: 'viewer' });
  r = await amel.post('invite_create', { project_id: pid, email: 'd@x.example', role: 'viewer' });
  ok(isLimit(r, 'members') && /5 members/.test(r.message), '5 places (propriétaire, 1 membre, 3 invitations en attente) : 6e refusée', r.message);
  r = await amel.get('billing');
  ok(r.usage.members === 2 && r.usage.pending_invites === 3, 'usage : 2 membres, 3 invitations en attente');
  ok(JSON.parse(sql(`SELECT COUNT(*) n FROM share_guests WHERE studio_id = ${sid}`))[0].n >= 2 && r.usage.members === 2, 'les invités des liens ne prennent pas de place');

  step('Créations simultanées (un compte Free)');
  const lina = await signup('Lina Free', 'lina@test.example');
  const many = await Promise.all(Array.from({ length: 8 }, (_, i) => lina.post('project_save', { name: 'Race ' + i })));
  ok(many.filter((x) => x.ok).length === 1 && many.filter((x) => isLimit(x, 'projects')).length === 7, '8 projets demandés en même temps : 1 créé, 7 refusés');
  const lp = many.find((x) => x.ok).id;
  const ls = (await lina.post('studio_create', { project_id: lp, name: 'S' })).id;
  const lcol = (await lina.get('board', { studio: ls })).cols[0].id;
  for (let i = 0; i < 25; i++) await lina.post('card_create', { col_id: lcol, title: 'c' + i });
  const burst = await Promise.all(Array.from({ length: 10 }, (_, i) => lina.post('card_create', { col_id: lcol, title: 'burst ' + i })));
  ok(burst.filter((x) => x.ok).length === 5 && (await lina.get('billing')).usage.cards_month === 30, '10 cartes en même temps à 25/30 : exactement 5 créées');

  step('Isolement entre comptes');
  r = await lina.post('billing_change', { plan: 'team', interval: 'month', subscription: done.subscription.id });
  ok(r.status === 409 && (await amel.get('billing')).plan === 'team', 'un compte ne peut pas agir sur l\'abonnement d\'un autre');
  r = await lina.post('billing_cancel', {});
  ok(r.status === 409, 'résilier sans abonnement : refusé');
  ok((await lina.post('project_save', { name: 'x', plan: 'team' })).status === 402 && (await lina.post('account_save', { plan: 'team', settings: { plan: 'team' } })).ok && (await lina.get('billing')).plan === 'free', 'impossible de se donner une formule par une requête');

  step('Descente Team → Solo');
  r = await amel.post('billing_change', { plan: 'solo', interval: 'month' });
  ok(r.ok && r.plan === 'solo' && (await fake('/__state')).last_proration === 'prorated_next_billing_period', 'Solo, prorata sur la prochaine facture');
  ok(r.over.some((o) => o.key === 'members' && o.used === 2 && o.max === 1), 'dépassement signalé : 2 membres pour 1 place');
  r = await karim.get('board', { studio: sid });
  ok(r.ok && r.studio.role === 'viewer', 'le membre en trop garde la lecture seule (rien n\'est supprimé)', r.studio && r.studio.role);
  ok((await karim.post('card_create', { col_id: col.id, title: 'blocked' })).status === 403, 'mais ne modifie plus');
  ok((await amel.get('board', { studio: sid })).cards.length > 30, 'toutes les cartes conservées');

  step('Résiliation et reprise');
  r = await amel.post('billing_cancel', {});
  ok(r.ok && r.plan === 'solo' && r.subscription.cancel_at > Date.now() / 1000, 'résiliation programmée : Solo jusqu\'à la fin de la période payée');
  r = await amel.post('billing_resume', {});
  ok(r.ok && r.subscription.cancel_at === 0, 'reprise : la résiliation est annulée');
  await amel.post('billing_cancel', {});
  const subNow = (await fake('/__state')).subscriptions[done.subscription.id];
  w = await webhook('subscription.canceled', Object.assign({}, subNow, { status: 'canceled', canceled_at: new Date().toISOString(), updated_at: new Date(Date.now() + 1000).toISOString().replace('Z', '999Z') }));
  r = await amel.get('billing');
  ok(w.body.status === 'processed' && r.plan === 'free', 'fin de l\'abonnement confirmée par Paddle : Free');
  ok(r.usage.projects === 3 && r.over.some((o) => o.key === 'projects'), 'les 3 projets restent, dépassement affiché');
  ok((await amel.get('project_get', { id: pid })).ok && isLimit(await amel.post('project_save', { name: 'P5' }), 'projects'), 'lecture possible, nouvelles créations refusées');
  ok(isLimit(await amel.post('share_save', { studio: sid, kind: 'private', active: false }).then(() => amel.post('share_save', { studio: sid, kind: 'private', active: true })), 'private_links'), 'lien privé désactivé : ne peut plus être rallumé sur Free');

  step('Paiement en échec : délai de grâce');
  const zoe = await signup('Zoe Team', 'zoe@test.example');
  const z = await subscribe(zoe, 'team', 'year');
  r = await zoe.get('billing');
  ok(r.plan === 'team' && r.subscription.interval === 'year' && r.subscription.amount === 29000, 'Team annuel (290 $)');
  const pd = Object.assign({}, z.sub, { status: 'past_due', updated_at: new Date(Date.now() + 5000).toISOString() });
  await webhook('subscription.past_due', pd);
  await webhook('transaction.payment_failed', Object.assign({}, z.txn, { id: 'txn_failed000000000000000000001', status: 'past_due' }));
  r = await zoe.get('billing');
  ok(r.plan === 'team' && r.state === 'past_due' && r.grace_until > Date.now() / 1000 + 13 * 86400, 'paiement en échec : Team gardé pendant le délai de grâce (14 jours)', { plan: r.plan, state: r.state });
  ok(r.transactions.some((t) => t.status === 'payment_failed'), 'échec de paiement enregistré');
  sql(`UPDATE subscriptions SET past_due_since = ${Math.floor(Date.now() / 1000) - 15 * 86400} WHERE uid = ${zoe.id}`);
  ok((await zoe.get('billing')).plan === 'free', 'délai de grâce dépassé : Free (les données restent)');
  await webhook('subscription.updated', Object.assign({}, z.sub, { status: 'active', updated_at: new Date(Date.now() + 9000).toISOString() }));
  ok((await zoe.get('billing')).plan === 'team', 'paiement régularisé : Team de nouveau');

  step('Notifications inexploitables');
  w = await webhook('subscription.created', Object.assign({}, z.sub, { id: 'sub_unknown0000000000000000001', customer_id: 'ctm_unknown', custom_data: {} }));
  ok(w.status === 200 && w.body.status === 'unmatched' && JSON.parse(sql("SELECT status FROM billing_events ORDER BY id DESC LIMIT 1"))[0].status === 'unmatched', 'abonnement d\'un client inconnu : journalisé, aucun accès donné');
  w = await webhook('customer.updated', { id: 'ctm_x' });
  ok(w.status === 200 && w.body.status === 'ignored', 'autres événements : ignorés');
  r = await zoe.post('billing_portal', {});
  ok(r.ok && /customer-portal/.test(r.url), 'espace client Paddle (moyen de paiement, factures)');

  step('Administration : indicateurs et formule offerte');
  const adm = new Client();
  await adm.adminLogin();
  ok((await amel.get('admin_billing')).status === 401, 'indicateurs réservés à l\'administration');
  r = await adm.get('admin_billing', { env: 'sandbox' });
  ok(r.metrics.team === 1 && r.metrics.solo === 0 && r.metrics.paid === 1 && r.metrics.mrr.USD === Math.round(29000 / 12) && r.metrics.arr.USD === Math.round(29000 / 12) * 12, 'sandbox : 1 abonné Team annuel, MRR = 290 / 12', r.metrics);
  ok(r.metrics.canceled_total === 1 && r.metrics.converted === 2, 'résiliations et conversions');
  r = await adm.get('admin_billing');
  ok(r.env === 'production' && r.metrics.paid === 0 && Object.keys(r.metrics.mrr).length === 0, 'production : les paiements de test ne comptent pas');
  r = await adm.post('admin_user_action', { id: lina.id, op: 'plan', plan: 'team', until: '' });
  ok(r.ok && r.billing.plan === 'team' && (await lina.post('project_save', { name: 'Comp' })).ok, 'formule offerte par l\'administration : appliquée');
  ok((await adm.get('admin_billing', { env: 'sandbox' })).metrics.mrr.USD === Math.round(29000 / 12), 'formule offerte : hors MRR');
  await adm.post('admin_user_action', { id: lina.id, op: 'plan', plan: 'solo', until: '2001-01-01' });
  ok((await lina.get('billing')).plan === 'free', 'formule offerte jusqu\'à une date passée : sans effet');

  step('Navigateur : page des prix, invitation à changer de formule, paiement, réglages');
  const { chromium } = require('playwright');
  const browser = await chromium.launch();
  const errors = [];
  // Faux Paddle.js : enregistre l'ouverture du paiement et annonce un paiement terminé (rien n'est activé par là).
  const FAKE_PADDLE = 'window.Paddle = { Environment: { set: function (e) { window.__penv = e; } }, Initialize: function (o) { window.__pinit = o; },'
    + ' Checkout: { open: function (o) { window.__popen = o; setTimeout(function () { window.__pinit.eventCallback({ name: "checkout.completed", data: {} }); }, 300); } } };';
  const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
  await ctx.route('https://cdn.paddle.com/**', (rt) => rt.fulfill({ status: 200, contentType: 'application/javascript', body: FAKE_PADDLE }));
  await ctx.route('https://accounts.google.com/**', (rt) => rt.abort());
  await ctx.route('https://connect.facebook.net/**', (rt) => rt.abort());
  const pg = await ctx.newPage();
  pg.on('pageerror', (e) => errors.push(e.message));
  pg.on('console', (msg) => { if (/Content Security Policy/.test(msg.text())) errors.push('CSP: ' + msg.text()); });
  await pg.goto(ORIGIN + '/studioma/app/#/pricing');
  await pg.waitForSelector('.plan-card');
  ok(/Simple pricing for every stage of your growth/.test(await pg.locator('h1').innerText()) && /Start free\. Manage more clients\. Collaborate better\./.test(await pg.locator('.pricing-head p').innerText()), 'titre et sous-titre');
  const cards = await pg.locator('.plan-card').allInnerTexts();
  ok(cards.length === 3 && /\$0/.test(cards[0]) && /Get Started Free/.test(cards[0]) && /\$12/.test(cards[1]) && /Most Popular/.test(cards[1]) && /Choose Solo/.test(cards[1]) && /\$29/.test(cards[2]) && /Choose Team/.test(cards[2]), 'trois formules, prix, boutons, « Most Popular »');
  await pg.click('.seg-inline label:has-text("Yearly")');
  const yearly = await pg.locator('.plan-card').allInnerTexts();
  ok(/\$120/.test(yearly[1]) && /\$290/.test(yearly[2]) && /2 months free/.test(yearly[1]), 'facturation annuelle : 120 $ et 290 $, deux mois offerts');
  ok(await pg.locator('table.compare tbody tr').count() >= 10 && await pg.locator('.faq details').count() >= 6, 'comparatif et questions fréquentes');
  await pg.click('.plan-card:nth-child(2) [data-act="plan-choose"]');
  await pg.waitForSelector('form[data-form="signup"]');
  await pg.fill('input[name=name]', 'Nadia Browser'); await pg.fill('input[name=email]', 'nadia@test.example'); await pg.fill('input[name=password]', 'a-solid-password');
  await pg.click('form[data-form="signup"] button.btn-primary');
  await pg.waitForSelector('.plan-card.is-focus');
  ok(/#\/pricing\?plan=solo&interval=year/.test(pg.url()) && /Your plan/.test(await pg.locator('.plan-card:nth-child(1)').innerText()), 'après l\'inscription : retour aux prix, formule Free indiquée');
  await pg.evaluate(() => SM.post('project_save', { name: 'One', template: 'content', studio_name: 'Studio' }));
  await pg.evaluate(() => SM.try(SM.post('project_save', { name: 'Two' })));
  await pg.waitForSelector('.plan-gate-modal');
  ok(/You've reached your Free plan limit\. Upgrade to Solo to manage more projects\./.test(await pg.locator('.plan-gate-modal').innerText()) && await pg.locator('.plan-gate-modal a:has-text("Upgrade to Solo")').count() === 1, 'limite atteinte : message et bouton « Upgrade to Solo »');
  await pg.click('.plan-gate-modal a:has-text("Upgrade to Solo")');
  await pg.waitForSelector('.plan-card.is-focus');
  await pg.click('.plan-card.is-focus [data-act="plan-choose"]');
  await pg.waitForURL(/\/studioma\/pay\//);
  await pg.waitForURL(/settings\/billing\?checkout=done/, { timeout: 10000 });
  ok(/Payment received by Paddle/.test(await pg.locator('#checkout-wait').innerText()) && /Free/.test(await pg.locator('.current-plan .plan-name').innerText()), 'retour du paiement : on attend la confirmation de Paddle (toujours Free)');
  const nst = await fake('/__state');
  const ntx = Object.values(nst.transactions).find((t) => t.custom_data && t.custom_data.stm_plan === 'solo' && t.status === 'ready');
  const nd = await fake('/__complete', { transaction_id: ntx.id });
  await webhook('subscription.created', nd.subscription);
  await pg.waitForSelector('.current-plan .plan-name:has-text("Solo")', { timeout: 10000 });
  ok(/\$12 per month/.test(await pg.locator('.current-plan').innerText()) && await pg.locator('.usage-row').count() === 5, 'confirmation de Paddle : Solo affiché, usage réel');
  await pg.goto(ORIGIN + '/studioma/app/#/studio/' + (await pg.evaluate(() => SM.get('workspace').then((w) => w.projects[0].studios[0].id))) + '/insights');
  await pg.waitForSelector('.plan-empty');
  ok(/Available on the Team plan/.test(await pg.locator('.plan-empty').innerText()), 'Insights : invitation à passer à Team');
  await pg.setViewportSize({ width: 390, height: 844 });
  await pg.goto(ORIGIN + '/studioma/app/#/pricing');
  await pg.waitForSelector('.plan-card');
  ok(await pg.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'page des prix sur mobile : pas de défilement horizontal');
  ok(errors.length === 0, 'aucune erreur JavaScript ni violation de la CSP (page de paiement comprise)', errors);
  await browser.close();

  console.log(failed ? `\n${failed} échec(s)` : '\nTout est bon.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
