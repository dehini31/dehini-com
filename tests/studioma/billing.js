/*
 * Tests des formules et des abonnements (Free, Solo, Team) sur le faux hébergement de run.sh, limites appliquées
 * (setup.sh avec BILLING=1) et faux PayPal (tests/studioma/fake-paypal.php) :
 * migration des comptes existants (schéma 14), limites Free / Solo / Team côté serveur (API et serveur MCP), compteur
 * mensuel de cartes, invités en lecture seule sur Free, souscription (abonnement PayPal créé par le serveur, lié au
 * compte, activé seulement par l'état lu chez PayPal), webhooks vérifiés par PayPal (signature, rejeu, compte inconnu,
 * PayPal indisponible), montée immédiate (nouveau prix au renouvellement), descente à la fin de la période payée,
 * résiliation (formule gardée jusqu'à la fin de la période), paiement en échec et délai de grâce, suspension,
 * remboursement, places de membres, créations simultanées, isolement entre comptes, indicateurs de l'administration
 * (MRR, ARR), création des formules PayPal depuis l'administration, formule offerte, parcours complet dans un navigateur.
 *   node tests/studioma/billing.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
const CFG = path.join(DIR, 'public_html', 'studioma', 'config.php');
const WEBHOOK_ID = 'WHTEST0123456789';
const PLAN = { solo: { month: 'P-SOLOMONTH0000000000000000', year: 'P-SOLOYEAR00000000000000000' }, team: { month: 'P-TEAMMONTH0000000000000000', year: 'P-TEAMYEAR00000000000000000' } };
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : '')); } };
const step = (t) => console.log('\n' + t);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
/** Requête SQL directe sur la base de test (simuler le temps qui passe). */
const sql = (query) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $s = $p->query($argv[2]); echo json_encode($s ? $s->fetchAll(PDO::FETCH_ASSOC) : []);', DB, query], { encoding: 'utf8' });
const now = () => Math.floor(Date.now() / 1000);
/** OPcache du serveur de test : config.php n'est relu qu'après opcache.revalidate_freq ; on attend que le changement soit visible. */
const until = async (fn, ms = 15000) => { const end = Date.now() + ms; while (Date.now() < end) { if (await fn()) return true; await new Promise((r) => setTimeout(r, 300)); } return false; };

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
const fake = async (p, body) => (await fetch(ORIGIN + '/fake-paypal' + p, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {})).json();
/** Accord du payeur sur la page de PayPal (faux) : renvoie l'adresse de retour vers Studioma. */
const approve = async (url, cancel) => (await fetch(url + (cancel ? '&cancel=1' : ''), { redirect: 'manual' })).headers.get('location') || '';
let evn = 0;
/** Notification PayPal signée (ou mal signée) envoyée au webhook de Studioma. */
async function webhook(type, resource, opts) {
  opts = opts || {};
  const raw = JSON.stringify({ id: opts.id || 'WH-' + String(++evn).padStart(17, '0'), event_version: '1.0', create_time: new Date().toISOString(), resource_type: type.startsWith('PAYMENT') ? 'sale' : 'subscription', event_type: type, summary: type, resource });
  const tid = crypto.randomUUID();
  const time = new Date().toISOString();
  const sig = crypto.createHmac('sha256', 'fake-paypal-signing').update(tid + '|' + time + '|' + (opts.webhookId || WEBHOOK_ID) + '|' + crypto.createHash('sha256').update(raw).digest('hex')).digest('hex');
  const headers = { 'Content-Type': 'application/json' };
  if (!opts.noSig) Object.assign(headers, { 'PAYPAL-AUTH-ALGO': 'SHA256withRSA', 'PAYPAL-CERT-URL': 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-fake', 'PAYPAL-TRANSMISSION-ID': tid, 'PAYPAL-TRANSMISSION-TIME': time, 'PAYPAL-TRANSMISSION-SIG': sig });
  const res = await fetch(ORIGIN + '/studioma/paypal-webhook.php', { method: 'POST', headers, body: opts.tamper ? raw.replace('ACTIVE', 'ACTIVEX') : raw });
  return { status: res.status, body: await res.json().catch(() => ({})), raw };
}
const subOf = async (id) => (await fake('/__state')).subs[id];
/** Abonnement payé de bout en bout : créé par Studioma, accepté sur PayPal, activation et paiement notifiés. */
async function subscribe(c, plan, interval) {
  const r = await c.post('billing_checkout', { plan, interval });
  await approve(r.checkout_url);
  const sub = await subOf(r.subscription_id);
  await webhook('BILLING.SUBSCRIPTION.ACTIVATED', sub);
  const sale = await fake('/__sale', { sub: r.subscription_id });
  await webhook('PAYMENT.SALE.COMPLETED', sale);
  return { checkout: r, sub, sale };
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
  ok(!JSON.stringify(r).includes('test-secret') && !JSON.stringify(r).includes('test-client-id') && !JSON.stringify(r).includes(WEBHOOK_ID), 'rien des identifiants PayPal dans les réponses publiques');
  ok((await anon.get('billing_config')).status === 404 || (await anon.get('billing_config')).error, 'plus de configuration de paiement publique (aucun script PayPal chargé)');
  ok((await anon.get('billing')).status === 401 && (await anon.post('billing_checkout', { plan: 'solo', interval: 'month' })).status === 401 && (await anon.post('billing_confirm', {})).status === 401, 'abonnement : compte connecté obligatoire');

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
  ok(JSON.parse(sql('PRAGMA user_version'))[0].user_version === 14 && JSON.stringify(before) === JSON.stringify(after), 'migration additive : schéma 14 (v9 à v14), projets, Studios, cartes et comptes intacts', { before, after });
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

  step('Souscription : abonnement PayPal créé par le serveur, activé seulement par PayPal');
  r = await amel.post('billing_checkout', { plan: 'gold', interval: 'month' });
  ok(r.status === 400, 'formule inconnue refusée');
  r = await amel.post('billing_checkout', { plan: 'solo', interval: 'month' });
  ok(r.ok && /^I-[A-Z0-9]+$/.test(r.subscription_id) && r.checkout_url === ORIGIN + '/fake-paypal/approve?sub=' + r.subscription_id, 'abonnement PayPal créé par le serveur, lien d\'accord de PayPal', r);
  const amelSub = r.subscription_id;
  let st = await fake('/__state');
  let ps = st.subs[amelSub];
  ok(ps.plan_id === PLAN.solo.month && ps.custom_id === 'stm:' + amel.id && ps.subscriber.email_address === 'amel@test.example' && /^stm-sub-/.test(ps.request_id), 'lié au compte (custom_id), formule Solo mensuelle, adresse pré-remplie, requête idempotente');
  ok(ps.context.return_url === ORIGIN + '/studioma/pay/?paypal=return' && ps.context.cancel_url === ORIGIN + '/studioma/pay/?paypal=cancel' && ps.context.shipping_preference === 'NO_SHIPPING' && ps.context.brand_name === 'Studioma', 'retour vers studioma/pay/, aucune adresse de livraison demandée');
  ok(JSON.parse(sql(`SELECT status FROM subscriptions WHERE provider_sub_id = '${amelSub}'`))[0].status === 'pending' && (await amel.get('billing')).plan === 'free', 'en attente : aucune formule tant que PayPal n\'a pas confirmé');
  r = await amel.post('billing_confirm', {});
  ok(r.ok && r.plan === 'free', 'retour sans accord du payeur : toujours Free (l\'adresse de retour n\'active rien)');
  let w = await webhook('BILLING.SUBSCRIPTION.ACTIVATED', Object.assign({}, ps, { status: 'ACTIVE' }), { webhookId: 'WHOTHER000000000' });
  ok(w.status === 401, 'webhook signé pour un autre webhook : refusé');
  ok((await webhook('BILLING.SUBSCRIPTION.ACTIVATED', ps, { noSig: true })).status === 401, 'webhook sans signature : refusé');
  ok((await webhook('BILLING.SUBSCRIPTION.ACTIVATED', Object.assign({}, ps, { status: 'ACTIVE' }), { tamper: true })).status === 401, 'corps modifié après signature : refusé');
  const back = await approve(ORIGIN + '/fake-paypal/approve?sub=' + amelSub);
  ok(back.startsWith(ORIGIN + '/studioma/pay/?paypal=return&subscription_id=' + amelSub), 'accord sur PayPal : retour vers studioma/pay/', back);
  ok((await amel.get('billing')).plan === 'free', 'accord donné, pas encore relu chez PayPal : toujours Free');
  const lina0 = await signup('Lina Other', 'lina0@test.example');
  ok((await lina0.post('billing_confirm', { subscription_id: amelSub })).status === 404 && (await amel.get('billing')).plan === 'free', 'un autre compte ne peut pas confirmer cet abonnement');
  r = await amel.post('billing_confirm', { subscription_id: amelSub });
  ok(r.ok && r.plan === 'solo' && r.state === 'active' && r.subscription.provider === 'paypal' && r.subscription.interval === 'month' && r.subscription.amount === 1200 && r.subscription.period_end > now() + 20 * 86400, 'relu chez PayPal : Solo actif, mensuel, 12 $, prochaine échéance', r.subscription);
  ps = await subOf(amelSub);
  w = await webhook('BILLING.SUBSCRIPTION.ACTIVATED', ps, { id: 'WH-ACTIVATED-AMEL' });
  ok(w.status === 200 && w.body.status === 'processed', 'notification d\'activation vérifiée par PayPal : traitée');
  w = await webhook('BILLING.SUBSCRIPTION.ACTIVATED', ps, { id: 'WH-ACTIVATED-AMEL' });
  ok(w.status === 200 && w.body.duplicate && JSON.parse(sql(`SELECT COUNT(*) n FROM subscriptions WHERE uid = ${amel.id}`))[0].n === 1, 'même notification reçue deux fois : traitée une seule fois');
  // Notification ancienne (état « en attente ») arrivée après coup : l'abonnement est relu chez PayPal, il reste actif.
  w = await webhook('BILLING.SUBSCRIPTION.CREATED', Object.assign({}, ps, { status: 'APPROVAL_PENDING' }));
  ok(w.body.status === 'processed' && (await amel.get('billing')).state === 'active', 'notification au contenu dépassé : l\'état lu chez PayPal fait foi');
  const amelSale = await fake('/__sale', { sub: amelSub });
  w = await webhook('PAYMENT.SALE.COMPLETED', amelSale);
  r = await amel.get('billing');
  ok(w.body.status === 'processed' && r.transactions.length === 1 && r.transactions[0].amount === 1200 && r.transactions[0].status === 'completed' && r.transactions[0].id === amelSale.id, 'paiement reçu (PAYMENT.SALE.COMPLETED) : historique');
  ok((await webhook('PAYMENT.SALE.COMPLETED', amelSale)).body.status === 'processed' && (await amel.get('billing')).transactions.length === 1, 'même paiement notifié deux fois : une seule ligne');
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

  step('Montée Solo → Team : tout de suite, nouveau prix au renouvellement');
  r = await amel.post('billing_change', { plan: 'team', interval: 'month' });
  ok(r.ok && r.approval === true && r.checkout_url === ORIGIN + '/fake-paypal/approve?sub=' + amelSub && (await fake('/__state')).last_revise === PLAN.team.month, '« revise » chez PayPal vers Team mensuel, accord du payeur demandé');
  ok((await amel.get('billing')).plan === 'solo', 'avant l\'accord : toujours Solo');
  await approve(r.checkout_url);
  r = await amel.post('billing_confirm', {});
  ok(r.ok && r.plan === 'team' && r.subscription.plan === 'team' && r.subscription.amount === 2900 && r.subscription.prev_plan === '', 'accord donné : Team aussitôt (choix du propriétaire), 29 $ au prochain renouvellement');
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
  r = await lina.post('billing_change', { plan: 'team', interval: 'month', subscription: amelSub });
  ok(r.status === 409 && (await amel.get('billing')).plan === 'team', 'un compte ne peut pas agir sur l\'abonnement d\'un autre');
  ok((await lina.post('billing_cancel', {})).status === 409, 'résilier sans abonnement : refusé');
  ok((await lina.post('billing_confirm', { subscription_id: amelSub })).status === 404, 'confirmer l\'abonnement d\'un autre : refusé');
  ok((await lina.post('billing_confirm', { subscription_id: '../x' })).status === 400, 'identifiant d\'abonnement mal formé : refusé');
  ok((await lina.post('project_save', { name: 'x', plan: 'team' })).status === 402 && (await lina.post('account_save', { plan: 'team', settings: { plan: 'team' } })).ok && (await lina.get('billing')).plan === 'free', 'impossible de se donner une formule par une requête');

  step('Descente Team → Solo : à la fin de la période payée');
  await fake('/__set', { flags: { no_consent: true } });   // carte enregistrée chez PayPal : pas d'accord à redemander
  const paidUntil = (await amel.get('billing')).subscription.period_end;
  r = await amel.post('billing_change', { plan: 'solo', interval: 'month' });
  ok(r.ok && r.approval === false && r.plan === 'team' && r.subscription.plan === 'solo' && r.subscription.prev_plan === 'team' && r.subscription.prev_until === paidUntil, 'Solo chez PayPal, Team gardé jusqu\'à la fin de la période payée', r.subscription);
  await fake('/__set', { flags: { no_consent: false } });
  ok((await karim.get('board', { studio: sid })).studio.role !== 'viewer', 'pendant la période payée : le membre garde ses droits');
  sql(`UPDATE subscriptions SET prev_until = ${now() - 60} WHERE uid = ${amel.id} AND provider = 'paypal'`);
  r = await amel.get('billing');
  ok(r.plan === 'solo' && r.over.some((o) => o.key === 'members' && o.used === 2 && o.max === 1), 'période terminée : Solo, dépassement signalé (2 membres pour 1 place)');
  r = await karim.get('board', { studio: sid });
  ok(r.ok && r.studio.role === 'viewer', 'le membre en trop garde la lecture seule (rien n\'est supprimé)', r.studio && r.studio.role);
  ok((await karim.post('card_create', { col_id: col.id, title: 'blocked' })).status === 403, 'mais ne modifie plus');
  ok((await amel.get('board', { studio: sid })).cards.length > 30, 'toutes les cartes conservées');
  r = await amel.post('billing_change', { plan: 'solo', interval: 'year' });
  ok(r.ok && r.approval === true && (await fake('/__state')).last_revise === PLAN.solo.year, 'passer à l\'annuel : accord sur PayPal');
  await approve(r.checkout_url);
  r = await amel.post('billing_confirm', {});
  ok(r.plan === 'solo' && r.subscription.interval === 'year' && r.subscription.amount === 12000, 'Solo annuel (120 $ au prochain renouvellement)');

  step('Résiliation : la formule reste jusqu\'à la fin de la période payée');
  const end = (await amel.get('billing')).subscription.period_end;
  r = await amel.post('billing_cancel', {});
  ok(r.ok && r.plan === 'solo' && r.subscription.status === 'canceled' && r.subscription.cancel_at === end && end > now(), 'résiliée chez PayPal : Solo jusqu\'à la fin de la période payée', r.subscription);
  ok((await subOf(amelSub)).status === 'CANCELLED', 'abonnement PayPal résilié (plus aucun prélèvement)');
  ok((await amel.post('billing_cancel', {})).status === 409, 'résilier deux fois : refusé');
  r = await amel.post('billing_checkout', { plan: 'team', interval: 'month' });
  ok(r.status === 409 && r.error === 'paid_until', 'pas de nouvel abonnement pendant la période déjà payée (pas de double paiement)', r);
  w = await webhook('BILLING.SUBSCRIPTION.CANCELLED', await subOf(amelSub));
  ok(w.body.status === 'processed' && (await amel.get('billing')).plan === 'solo', 'notification de résiliation : rien ne change avant la fin de la période');
  sql(`UPDATE subscriptions SET cancel_at = ${now() - 60} WHERE uid = ${amel.id} AND provider = 'paypal'`);
  r = await amel.get('billing');
  ok(r.plan === 'free', 'fin de la période payée : Free');
  ok(r.usage.projects === 3 && r.over.some((o) => o.key === 'projects'), 'les 3 projets restent, dépassement affiché');
  ok((await amel.get('project_get', { id: pid })).ok && isLimit(await amel.post('project_save', { name: 'P5' }), 'projects'), 'lecture possible, nouvelles créations refusées');
  ok(isLimit(await amel.post('share_save', { studio: sid, kind: 'private', active: false }).then(() => amel.post('share_save', { studio: sid, kind: 'private', active: true })), 'private_links'), 'lien privé désactivé : ne peut plus être rallumé sur Free');

  step('Paiement en échec, suspension, remboursement');
  const zoe = await signup('Zoe Team', 'zoe@test.example');
  const z = await subscribe(zoe, 'team', 'year');
  r = await zoe.get('billing');
  ok(r.plan === 'team' && r.subscription.interval === 'year' && r.subscription.amount === 29000 && r.transactions.length === 1, 'Team annuel (290 $), payé');
  let zs = await fake('/__set', { sub: z.sub.id, patch: { billing_info: { failed_payments_count: 1, last_failed_payment: { amount: { currency_code: 'USD', value: '290.00' }, time: new Date().toISOString(), reason_code: 'PAYMENT_DENIED' } } } });
  await webhook('BILLING.SUBSCRIPTION.PAYMENT.FAILED', zs);
  r = await zoe.get('billing');
  ok(r.plan === 'team' && r.state === 'past_due' && r.grace_until > now() + 13 * 86400, 'paiement en échec : Team gardé pendant le délai de grâce (14 jours)', { plan: r.plan, state: r.state });
  ok(r.transactions.some((t) => t.status === 'payment_failed' && t.amount === 29000), 'échec de paiement enregistré');
  sql(`UPDATE subscriptions SET past_due_since = ${now() - 15 * 86400} WHERE uid = ${zoe.id}`);
  ok((await zoe.get('billing')).plan === 'free', 'délai de grâce dépassé : Free (les données restent)');
  zs = await fake('/__set', { sub: z.sub.id, patch: { billing_info: { failed_payments_count: 0 } } });
  await webhook('BILLING.SUBSCRIPTION.UPDATED', zs);
  ok((await zoe.get('billing')).plan === 'team', 'paiement régularisé : Team de nouveau');
  zs = await fake('/__set', { sub: z.sub.id, patch: { status: 'SUSPENDED' } });
  await webhook('BILLING.SUBSCRIPTION.SUSPENDED', zs);
  ok((await zoe.get('billing')).plan === 'free', 'abonnement suspendu chez PayPal : Free');
  zs = await fake('/__set', { sub: z.sub.id, patch: { status: 'ACTIVE' } });
  await webhook('BILLING.SUBSCRIPTION.ACTIVATED', zs);
  ok((await zoe.get('billing')).plan === 'team', 'réactivé : Team');
  w = await webhook('PAYMENT.SALE.REFUNDED', { id: 'REFUND0000000001', sale_id: z.sale.id, state: 'completed', amount: { total: '290.00', currency: 'USD' }, create_time: new Date().toISOString() });
  r = await zoe.get('billing');
  ok(w.body.status === 'processed' && r.transactions.some((t) => t.id === z.sale.id && t.status === 'refunded'), 'remboursement fait dans PayPal : paiement marqué « remboursé »');
  ok((await webhook('PAYMENT.SALE.COMPLETED', z.sale)).body.status === 'processed' && (await zoe.get('billing')).transactions.find((t) => t.id === z.sale.id).status === 'refunded', 'un « payé » arrivé en retard ne remplace pas le remboursement');
  r = await zoe.post('account_delete', { password: 'a-solid-password' });
  ok(r.status === 409 && r.error === 'has_subscription' && (await zoe.get('billing')).plan === 'team', 'supprimer son compte avec un abonnement en cours : refusé (PayPal continuerait de prélever)');

  step('Notifications inexploitables, PayPal indisponible');
  w = await webhook('BILLING.SUBSCRIPTION.ACTIVATED', { id: 'I-UNKNOWN0000001', status: 'ACTIVE' });
  ok(w.status === 200 && w.body.status === 'unmatched', 'abonnement inconnu de PayPal : journalisé, aucun accès donné');
  const ext = await fake('/__create', { plan_id: PLAN.team.month, custom_id: 'stm:999999' });
  w = await webhook('BILLING.SUBSCRIPTION.ACTIVATED', ext);
  ok(w.status === 200 && w.body.status === 'unmatched' && JSON.parse(sql('SELECT status FROM billing_events ORDER BY id DESC LIMIT 1'))[0].status === 'unmatched', 'abonnement sans compte Studioma : journalisé, aucun accès donné');
  const ext2 = await fake('/__create', { plan_id: 'P-SOMEOTHERPLAN00000000000', custom_id: 'stm:' + lina.id });
  ok((await webhook('BILLING.SUBSCRIPTION.ACTIVATED', ext2)).body.status === 'unmatched' && (await lina.get('billing')).plan === 'free', 'formule PayPal qui n\'est pas l\'une des nôtres : aucun accès');
  w = await webhook('CUSTOMER.DISPUTE.CREATED', { dispute_id: 'PP-D-1' });
  ok(w.status === 200 && w.body.status === 'ignored', 'autres événements : ignorés');
  await fake('/__set', { flags: { down: true } });
  w = await webhook('BILLING.SUBSCRIPTION.UPDATED', zs);
  ok(w.status === 503, 'PayPal ne répond pas à la vérification : 503 (PayPal renverra la notification)');
  ok((await zoe.post('billing_confirm', {})).status === 502, 'retour pendant une panne de PayPal : erreur claire, rien de changé');
  await fake('/__set', { flags: { down: false } });
  ok((await webhook('BILLING.SUBSCRIPTION.UPDATED', zs)).body.status === 'processed' && (await zoe.get('billing')).plan === 'team', 'renvoyée plus tard : traitée');
  r = await zoe.get('billing');
  ok(r.manage_url === 'https://www.sandbox.paypal.com/myaccount/autopay/', 'gestion des paiements automatiques : lien vers le compte PayPal (sandbox)');
  st = await fake('/__state');
  ok(st.verifications.length > 10 && st.verifications.filter((x) => !x).length >= 2, 'chaque notification est vérifiée par PayPal');

  step('Administration : indicateurs, formules PayPal, formule offerte');
  const adm = new Client();
  await adm.adminLogin();
  ok((await amel.get('admin_billing')).status === 401 && (await amel.post('admin_paypal_setup')).status === 401, 'réservé à l\'administration');
  r = await adm.get('admin_billing', { env: 'sandbox' });
  ok(r.metrics.team === 1 && r.metrics.solo === 0 && r.metrics.paid === 1 && r.metrics.mrr.USD === Math.round(29000 / 12) && r.metrics.arr.USD === Math.round(29000 / 12) * 12, 'sandbox : 1 abonné Team annuel, MRR = 290 / 12', r.metrics);
  ok(r.metrics.canceled_total === 1 && r.metrics.converted === 2 && r.metrics.revenue_30 === 1200, 'résiliations, conversions (les paiements commencés sans suite ne comptent pas), encaissé hors remboursement', r.metrics);
  ok(!r.subscriptions.some((s) => s.status === 'pending'), 'abonnements en attente : hors de la liste');
  ok(r.available === true && r.problems.length === 0 && r.missing.length === 0 && r.can_setup_plans === false && r.webhook_url === ORIGIN + '/studioma/paypal-webhook.php', 'réglages PayPal cohérents, adresse du webhook affichée');
  ok((await adm.post('admin_paypal_setup')).status === 409, 'formules déjà réglées : pas de doublon');
  r = await adm.get('admin_billing');
  ok(r.env === 'production' && r.metrics.paid === 0 && Object.keys(r.metrics.mrr).length === 0, 'production : les paiements de test ne comptent pas');
  // Réglages sans les quatre formules : l'administration les crée chez PayPal et donne les lignes à recopier.
  const cfg = fs.readFileSync(CFG, 'utf8');
  fs.writeFileSync(CFG, cfg.split('\n').filter((l) => !l.includes('STM_PAYPAL_PLAN_')).join('\n'));
  await until(async () => (await anon.get('billing_plans')).checkout === false);
  r = await adm.get('admin_billing', { env: 'sandbox' });
  ok(r.available === false && r.can_setup_plans === true && r.missing.includes('STM_PAYPAL_PLAN_SOLO_MONTHLY'), 'formules manquantes : paiement fermé, création proposée');
  ok((await anon.get('billing_plans')).checkout === false, 'page des prix : « Coming soon »');
  r = await adm.post('admin_paypal_setup');
  st = await fake('/__state');
  const made = Object.values(st.plans);
  ok(r.ok && r.plans.length === 4 && /^PROD-/.test(r.product_id) && r.config.split('\n').length === 4 && r.config.includes("define('STM_PAYPAL_PLAN_TEAM_YEARLY', 'P-NEW"), 'produit « Studioma » et quatre formules créés, lignes pour config.php', r);
  const price = (name) => made.find((p) => p.name === name).billing_cycles[0];
  ok(made.length === 4 && price('Studioma Solo — monthly').pricing_scheme.fixed_price.value === '12.00' && price('Studioma Solo — yearly').frequency.interval_unit === 'YEAR' && price('Studioma Team — monthly').pricing_scheme.fixed_price.value === '29.00' && price('Studioma Team — yearly').pricing_scheme.fixed_price.value === '290.00'
    && made.every((p) => !p.taxes && p.billing_cycles[0].pricing_scheme.fixed_price.currency_code === 'USD' && p.billing_cycles[0].total_cycles === 0 && p.payment_preferences.payment_failure_threshold === 3), 'prix de lib/plans.php, en dollars, sans taxe, renouvelés sans fin', made.map((p) => p.name));
  fs.writeFileSync(CFG, cfg);
  await until(async () => (await anon.get('billing_plans')).checkout === true);
  ok((await adm.get('admin_billing', { env: 'sandbox' })).available === true, 'réglages rétablis');
  r = await adm.post('admin_user_action', { id: lina.id, op: 'plan', plan: 'team', until: '' });
  ok(r.ok && r.billing.plan === 'team' && (await lina.post('project_save', { name: 'Comp' })).ok, 'formule offerte par l\'administration : appliquée');
  ok((await adm.get('admin_billing', { env: 'sandbox' })).metrics.mrr.USD === Math.round(29000 / 12), 'formule offerte : hors MRR');
  await adm.post('admin_user_action', { id: lina.id, op: 'plan', plan: 'solo', until: '2001-01-01' });
  ok((await lina.get('billing')).plan === 'free', 'formule offerte jusqu\'à une date passée : sans effet');

  step('Navigateur : page des prix, invitation à changer de formule, paiement, réglages');
  const { chromium } = require('playwright');
  const browser = await chromium.launch();
  const errors = [];
  const ctx = await browser.newContext({ viewport: { width: 1360, height: 900 } });
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
  // Paiement sur PayPal (faux) : accord immédiat, retour par studioma/pay/, puis Réglages › Plan & billing.
  await pg.waitForURL(/settings\/billing\?checkout=return&sub=I-/, { timeout: 15000 });
  await pg.waitForSelector('.current-plan .plan-name:has-text("Solo")', { timeout: 10000 });
  ok(/\$12 per month/.test(await pg.locator('.current-plan').innerText()) && /paid with PayPal/.test(await pg.locator('.current-plan').innerText()) && await pg.locator('.usage-row').count() === 5, 'retour de PayPal : abonnement relu chez PayPal, Solo affiché, usage réel');
  ok(await pg.locator('a:has-text("Manage in PayPal")').count() === 1 && await pg.locator('[data-act="billing-cancel"]').count() === 1, 'gérer chez PayPal, résilier');
  await pg.goto(ORIGIN + '/studioma/app/#/studio/' + (await pg.evaluate(() => SM.get('workspace').then((w) => w.projects[0].studios[0].id))) + '/insights');
  await pg.waitForSelector('.plan-empty');
  ok(/Available on the Team plan/.test(await pg.locator('.plan-empty').innerText()), 'Insights : invitation à passer à Team');
  await pg.goto(ORIGIN + '/studioma/pay/?paypal=cancel&token=EC-1');
  await pg.waitForSelector('.pricing-note:has-text("Checkout canceled")');
  ok(/nothing was charged/.test(await pg.locator('.pricing-note').first().innerText()), 'paiement abandonné sur PayPal : retour aux prix, rien n\'est facturé');
  await pg.goto(ORIGIN + '/studioma/pay/');
  ok(/Nothing to pay here/.test(await pg.locator('h1').innerText()), 'page de retour ouverte seule : rien à faire');
  await pg.setViewportSize({ width: 390, height: 844 });
  await pg.goto(ORIGIN + '/studioma/app/#/pricing');
  await pg.waitForSelector('.plan-card');
  ok(await pg.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth) <= 1, 'page des prix sur mobile : pas de défilement horizontal');
  ok(errors.length === 0, 'aucune erreur JavaScript ni violation de la CSP (page de retour comprise)', errors);
  await browser.close();

  console.log(failed ? `\n${failed} échec(s)` : '\nTout est bon.');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
