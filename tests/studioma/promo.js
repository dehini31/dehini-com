/*
 * Tests de l'offre de lancement (API seule), sur le faux hébergement de run.sh en mode PROMO=1 (limites appliquées,
 * paiement fermé comme en production) :
 *   essai de 30 jours à l'inscription (formule Team et ses limites), une seule fois, réclamable par un compte
 *   existant qui ne l'a jamais eu, jamais par-dessus un accès Team payé ou offert ; fin de l'essai : formule Free,
 *   rien de supprimé, limites appliquées même par l'API ;
 *   lien de parrainage (code aléatoire, permanent), clics et visiteurs uniques, robots ignorés, attribution de 30
 *   jours signée (la première l'emporte, cookie falsifié ou trop vieux refusé), vérification de l'adresse (lien de
 *   confirmation, mot de passe oublié), parrainage de soi-même refusé, activité suspecte à examiner ;
 *   10 parrainages vérifiés : 12 mois civils, une seule fois (même avec des vérifications simultanées), à la suite
 *   de l'essai s'il est en cours, sans réduire un accès payé ou offert ; fin de l'année offerte ;
 *   indicateurs de l'administration ; aucun paiement, aucune carte, aucun appel à PayPal ; offre coupée.
 *   node tests/studioma/promo.js <adresse> <dossier>
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const [ORIGIN, DIR] = process.argv.slice(2);
const API = ORIGIN + '/studioma/api.php';
const DB = path.join(DIR, 'studioma-data', 'studioma.sqlite');
const CONFIG = path.join(DIR, 'public_html', 'studioma', 'config.php');
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 500) : '')); } };
const step = (t) => console.log('\n' + t);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
const lastTo = (to) => mails().filter((m) => m.to === to).pop();
const sql = (query) => execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); $p->exec($argv[2]);', DB, query]);
const one = (query) => JSON.parse(execFileSync('php', ['-r', '$p = new PDO("sqlite:" . $argv[1]); echo json_encode($p->query($argv[2])->fetchColumn());', DB, query]).toString());
const php = (code) => execFileSync('php', ['-r', code]).toString();
/** Réglage de config.php changé en cours de test (chaque requête relit le fichier). */
// OPcache du serveur de test ne relit un fichier qu'au plus toutes les 2 secondes : on attend un peu après l'écriture.
const setConfig = async (name, value) => {
  fs.writeFileSync(CONFIG, fs.readFileSync(CONFIG, 'utf8').replace(new RegExp(`define\\('${name}', [^)]*\\);`), `define('${name}', ${value});`));
  await new Promise((res) => setTimeout(res, 3200));
};
const BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

class Client {
  constructor() { this.jar = {}; this.csrf = ''; this.ua = BROWSER_UA; }
  cookies() { return Object.entries(this.jar).map(([k, v]) => k + '=' + v).join('; '); }
  keep(res) {
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => {
      const [k, ...rest] = c.split(';')[0].split('=');
      const v = rest.join('=');
      if (v === '' || /Max-Age=0/i.test(c)) delete this.jar[k]; else this.jar[k] = v;
    });
  }
  async call(action, body, params, base) {
    const url = new URL(base || API);
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const headers = { 'User-Agent': this.ua };
    if (this.cookies()) headers.Cookie = this.cookies();
    const init = { headers };
    if (body !== undefined) {
      init.method = 'POST';
      init.body = JSON.stringify(body);
      Object.assign(headers, { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf });
    }
    const res = await fetch(url, init);
    this.keep(res);
    const d = await res.json().catch(() => ({ error: 'not-json' }));
    if (typeof d.status === 'string') d.state = d.status;
    d.status = res.status;
    if (d.csrf && !this.admin) this.csrf = d.csrf;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
  async adminLogin() {
    const res = await fetch(ORIGIN + '/admin/api.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: this.cookies() }, body: JSON.stringify({ password: 'test-owner-pass' }) });
    this.keep(res);
    const d = await res.json();
    if (d.ok) { this.admin = true; this.csrf = d.csrf; }
    return d;
  }
}
let n = 0;
const email = (name) => `${name}${++n}@friends.example`;
/** Inscription par email depuis ce navigateur (ses cookies d'attribution éventuels sont envoyés). */
const signup = async (c, name, mail) => {
  sql('DELETE FROM rate');   // toutes les requêtes du test viennent de 127.0.0.1 : on simule des visiteurs distincts
  const r = await c.post('signup', { name, email: mail, password: 'a-solid-password', accept: true });
  c.id = r.user && r.user.id;
  c.email = mail;
  return r;
};
/** Confirme l'adresse avec le lien du courriel de bienvenue (ou du dernier envoyé). */
const confirmEmail = async (c) => {
  const m = mails().filter((x) => x.to === c.email && /confirm\/[a-f0-9]{48}/.test(x.text)).pop();
  const token = m && m.text.match(/confirm\/([a-f0-9]{48})/)[1];
  return c.post('email_confirm', { token });
};
/** Un ami : ouvre le lien de parrainage, s'inscrit ; vérifie son adresse si $verify. */
const friend = async (code, verify) => {
  const c = new Client();
  await c.post('ref_click', { code });
  await signup(c, 'Friend ' + (n + 1), email('friend'));
  if (verify) await confirmEmail(c);
  return c;
};
const noPayment = () => {
  const fp = path.join(DIR, 'fake-paypal.json');
  const calls = fs.existsSync(fp) ? (JSON.parse(fs.readFileSync(fp, 'utf8')).calls || []) : [];
  return calls.length === 0 && one("SELECT COUNT(*) FROM subscriptions WHERE provider IN ('paypal', 'paddle')") === 0
    && one('SELECT COUNT(*) FROM billing_customers') === 0 && one('SELECT COUNT(*) FROM billing_transactions') === 0;
};
const DAY = 86400;

(async () => {
  step('1. Page des prix et paiement : fermé, sans carte');
  const anon = new Client();
  let r = await anon.get('billing_plans');
  ok(r.checkout === false && r.plans.length === 3, 'paiement en ligne fermé, trois formules');
  ok((await anon.get('me')).promo === undefined, 'visiteur : aucune donnée d\'offre');

  step('2. Essai de 30 jours à l\'inscription');
  const amel = new Client();
  r = await signup(amel, 'Amel Haddad', 'amel@test.example');
  const now = Math.floor(Date.now() / 1000);
  ok(r.promo && r.promo.trial.state === 'active' && r.promo.trial.days_left === 30 && Math.abs(r.promo.trial.ends - (now + 30 * DAY)) < 120 && r.promo.claimable === false,
    'nouveau compte : essai actif, 30 jours, date de fin calculée par le serveur', r.promo);
  r = await amel.get('billing');
  ok(r.plan === 'team' && r.state === 'trialing' && r.subscription.provider === 'promo' && r.subscription.amount === 0 && r.limits.projects === 10 && r.limits.members === 5 && r.limits.studios === 20,
    'formule Team (limites Team : 10 projets, 20 Studios, 5 membres), sans montant', { plan: r.plan, sub: r.subscription, limits: r.limits });
  ok(/full-access trial is active/.test((lastTo('amel@test.example') || {}).text || ''), 'l\'email de bienvenue annonce l\'essai');
  const projects = [];
  for (let i = 0; i < 10; i++) {
    r = await amel.post('project_save', { name: 'Project ' + (i + 1), template: 'kanban', studio_name: 'Studio ' + (i + 1) });
    if (r.id || r.project_id) projects.push(r.id || r.project_id);
  }
  ok(projects.length === 10, 'Team pendant l\'essai : 10 projets créés (Free : 1)', projects.length);
  r = await amel.post('project_save', { name: 'Project 11', template: 'kanban', studio_name: 'S' });
  ok(r.status === 402 && r.error === 'plan_limit', 'limite Team toujours appliquée : 11e projet refusé', r);
  const sid = (await amel.get('board', { studio: one('SELECT id FROM studios ORDER BY id LIMIT 1') })).studio.id;
  r = await amel.post('share_save', { studio: sid, kind: 'private' });
  ok(r.links && r.links.private, 'fonctionnalité payante (lien privé) ouverte pendant l\'essai');
  r = await amel.post('promo_claim');
  ok(r.status === 409 && r.error === 'promo_used', 'réclamer encore (autre carte de prix) : refusé, l\'essai ne recommence pas', r);
  ok(one("SELECT COUNT(*) FROM promo_grants WHERE kind = 'trial'") === 1, 'un seul essai en base');

  step('3. Compte existant : réclamer l\'essai une fois ; jamais par-dessus un accès Team');
  const old = new Client();
  await signup(old, 'Old Member', 'old@test.example');
  sql(`DELETE FROM subscriptions WHERE uid = ${old.id}; DELETE FROM promo_grants WHERE uid = ${old.id};`);   // compte d'avant l'offre
  r = await old.get('me');
  ok(r.promo.claimable === true && r.promo.trial.state === 'none', 'compte existant sans essai : il peut le réclamer');
  r = await old.post('promo_claim');
  ok(r.granted && r.trial.state === 'active' && (await old.get('billing')).plan === 'team', 'essai accordé sur demande');
  ok((await old.post('promo_claim')).error === 'promo_used', 'une seule fois');
  const vip = new Client();
  await signup(vip, 'Vip Member', 'vip@test.example');
  sql(`DELETE FROM subscriptions WHERE uid = ${vip.id}; DELETE FROM promo_grants WHERE uid = ${vip.id};
    INSERT INTO subscriptions (uid, provider, env, plan, status, period_start, period_end, note, created, updated) VALUES (${vip.id}, 'manual', 'manual', 'team', 'active', ${now}, 0, 'test', ${now}, ${now});`);
  r = await vip.get('me');
  ok(r.promo.claimable === false && r.promo.other_team === true, 'accès Team offert en cours : rien à réclamer');
  r = await vip.post('promo_claim');
  ok(r.error === 'promo_has_team' && one(`SELECT COUNT(*) FROM promo_grants WHERE uid = ${vip.id}`) === 0, 'réclamation refusée sans consommer l\'essai', r);

  step('4. Fin de l\'essai : Free, rien de supprimé, limites appliquées');
  const before = one(`SELECT COUNT(*) FROM projects WHERE owner_uid = ${amel.id}`);
  sql(`UPDATE subscriptions SET period_end = ${now - 60} WHERE uid = ${amel.id} AND provider = 'promo'; UPDATE promo_grants SET ends = ${now - 60} WHERE uid = ${amel.id};`);
  r = await amel.get('billing');
  ok(r.plan === 'free' && r.over.some((o) => o.key === 'projects'), 'essai terminé : formule Free, dépassement signalé (rien supprimé)', { plan: r.plan, over: r.over });
  ok(one(`SELECT COUNT(*) FROM projects WHERE owner_uid = ${amel.id}`) === before && (await amel.get('board', { studio: sid })).studio, 'projets, Studios et cartes conservés et lisibles');
  r = await amel.post('studio_create', { project_id: projects[0], name: 'One more', template: 'kanban' });
  ok(r.status === 402 && r.error === 'plan_limit', 'appel direct à l\'API : création refusée selon Free', r);
  r = await amel.get('me');
  ok(r.promo.trial.state === 'ended' && r.promo.claimable === false, 'essai terminé, pas de nouvel essai');
  ok((await amel.post('promo_claim')).error === 'promo_used', 'impossible de recommencer l\'essai');

  step('5. Lien de parrainage');
  r = await amel.get('promo');
  const code = r.code;
  ok(/^[abcdefghjkmnpqrstuvwxyz23456789]{8}$/.test(code) && r.url === ORIGIN + '/studioma/?ref=' + code && !/amel|haddad|test\.example/i.test(r.url),
    'code aléatoire de 8 caractères, lien …/studioma/?ref=<code>, sans id ni email', r.url);
  ok((await amel.get('promo')).code === code, 'le code ne change pas');
  ok((await old.get('promo')).code !== code, 'un code différent par compte');
  ok(r.share_text.includes(r.url) && /free for 30 days — no credit card required/.test(r.share_text), 'message de partage avec le lien personnel');
  ok(r.stats.clicks === 0 && r.progress === 0 && r.history.length === 0, 'aucune activité au départ');
  ok((await anon.get('promo')).status === 401, 'tableau de parrainage : compte obligatoire');

  step('6. Clics et visiteurs uniques');
  const v1 = new Client();
  ok((await v1.post('ref_click', { code })).valid === true, 'lien valable');
  await v1.post('ref_click', { code });
  await v1.post('ref_click', { code });
  const v2 = new Client();
  await v2.post('ref_click', { code });
  const bot = new Client();
  bot.ua = 'facebookexternalhit/1.1';
  await bot.post('ref_click', { code });
  ok((await new Client().post('ref_click', { code: 'zzzzzzzz' })).valid === false, 'code inconnu : refusé');
  ok((await amel.post('ref_click', { code })).member === true, 'membre connecté (dont le parrain) : ni clic ni attribution');
  r = await amel.get('promo');
  ok(r.stats.clicks === 4 && r.stats.visitors === 2, '4 clics, 2 visiteurs uniques (robot ignoré)', r.stats);
  ok(v1.jar.stm_ref && !amel.jar.stm_ref, 'attribution posée chez le visiteur seulement');

  step('7. Inscription attribuée, puis vérification de l\'adresse');
  await signup(v1, 'Nadia Friend', email('nadia'));
  ok(!v1.jar.stm_ref, 'attribution consommée à l\'inscription');
  r = await amel.get('promo');
  ok(r.stats.registrations === 1 && r.stats.verified === 0 && r.history[0].status === 'pending' && !JSON.stringify(r.history).includes('Nadia') && !JSON.stringify(r.history).includes('@'),
    'inscription comptée, en attente de vérification ; historique sans nom ni adresse', r.history);
  ok((await v1.get('me')).promo.trial.state === 'active', 'l\'ami invité a aussi son essai de 30 jours');
  r = await confirmEmail(v1);
  ok(r.user && r.user.email_ok, 'l\'ami confirme son adresse');
  r = await amel.get('promo');
  ok(r.stats.verified === 1 && r.progress === 1 && r.history[0].status === 'verified', 'parrainage vérifié : 1 / 10');
  ok(one(`SELECT COUNT(*) FROM notifications WHERE uid = ${amel.id} AND kind = 'promo'`) === 1, 'le parrain est prévenu');
  await confirmEmail(v1);
  ok((await amel.get('promo')).stats.verified === 1, 'une seconde confirmation ne compte pas deux fois');
  ok(one(`SELECT COUNT(*) FROM referrals WHERE referred_uid = ${v1.id}`) === 1, 'un seul parrainage par compte (contrainte d\'unicité)');

  step('8. Attribution : première valable, signée, 30 jours');
  const karim = new Client();
  await signup(karim, 'Karim Ref', 'karim@test.example');
  const code2 = (await karim.get('promo')).code;
  const v3 = new Client();
  await v3.post('ref_click', { code });
  await v3.post('ref_click', { code: code2 });
  await signup(v3, 'Two Links', email('two'));
  ok(one(`SELECT referrer_uid FROM referrals WHERE referred_uid = ${v3.id}`) === amel.id, 'deux liens ouverts : le premier parrain l\'emporte');
  const salt = one("SELECT v FROM meta WHERE k = 'salt'");
  const sign = (c, ts) => `${c}.${ts}.` + crypto.createHmac('sha256', salt + '|ref').update(`${c}|${ts}`).digest('hex').slice(0, 20);
  const v4 = new Client();
  v4.jar.stm_ref = sign(code2, now - 31 * DAY);
  await signup(v4, 'Too Late', email('late'));
  ok(one(`SELECT COUNT(*) FROM referrals WHERE referred_uid = ${v4.id}`) === 0, 'attribution de plus de 30 jours : ignorée');
  const v5 = new Client();
  v5.jar.stm_ref = sign(code2, now - 29 * DAY).replace(/.$/, (x) => (x === 'a' ? 'b' : 'a'));
  await signup(v5, 'Forged', email('forged'));
  ok(one(`SELECT COUNT(*) FROM referrals WHERE referred_uid = ${v5.id}`) === 0, 'cookie d\'attribution falsifié : ignoré');
  const v6 = new Client();
  v6.jar.stm_ref = sign(code2, now - 29 * DAY);
  await signup(v6, 'Still Valid', email('valid'));
  ok(one(`SELECT referrer_uid FROM referrals WHERE referred_uid = ${v6.id}`) === karim.id, 'attribution de 29 jours : retenue');

  step('9. Parrainage de soi-même et vérification par mot de passe oublié');
  const self = new Client();
  await self.post('ref_click', { code });
  await signup(self, 'Amel Again', 'amel+second@test.example');
  ok(one(`SELECT status || ':' || reason FROM referrals WHERE referred_uid = ${self.id}`) === 'rejected:self', 'même adresse (alias +…) : refusé, ne compte pas');
  await confirmEmail(self);
  ok(one(`SELECT status FROM referrals WHERE referred_uid = ${self.id}`) === 'rejected', 'confirmer l\'adresse ne le rend pas valable');
  const v7 = new Client();
  await v7.post('ref_click', { code: code2 });
  await signup(v7, 'Reset Friend', email('reset'));
  sql('DELETE FROM rate');
  await new Client().post('forgot', { email: v7.email });
  const resetToken = (lastTo(v7.email).text.match(/reset\/([a-f0-9]{48})/) || [])[1];
  r = await new Client().post('reset', { token: resetToken, password: 'another-solid-password' });
  ok(r.user && one(`SELECT status FROM referrals WHERE referred_uid = ${v7.id}`) === 'verified', 'adresse vérifiée par « mot de passe oublié » : parrainage vérifié');

  step('10. Dix amis vérifiés : un an offert, à la suite de l\'essai');
  const lina = new Client();
  await signup(lina, 'Lina Influencer', 'lina@test.example');
  const codeL = (await lina.get('promo')).code;
  const trialEnd = (await lina.get('me')).promo.trial.ends;
  for (let i = 0; i < 9; i++) await friend(codeL, true);
  r = await lina.get('promo');
  ok(r.stats.verified === 9 && r.reward.state === 'none' && one(`SELECT COUNT(*) FROM promo_grants WHERE uid = ${lina.id} AND kind = 'referral'`) === 0, '9 amis vérifiés : pas encore de récompense', r.reward);
  const pend = await friend(codeL, false);
  ok((await lina.get('promo')).reward.state === 'none', '10e inscrit mais pas vérifié : toujours pas');
  await confirmEmail(pend);
  r = await lina.get('promo');
  const expectEnd = +php(`date_default_timezone_set('Africa/Algiers'); echo strtotime('+12 months', ${trialEnd});`);
  ok(r.stats.verified === 10 && r.progress === 10 && r.reward.state === 'scheduled' && r.reward.starts === trialEnd && r.reward.ends === expectEnd,
    '10 amis vérifiés : 12 mois civils, qui commencent à la fin de l\'essai en cours', { reward: r.reward, trialEnd, expectEnd });
  ok((await lina.get('billing')).subscription.provider === 'promo' && (await lina.get('billing')).plan === 'team', 'accès Team continu (essai, puis année offerte)');
  const congrats = lastTo('lina@test.example');
  ok(congrats && /1 full year of Studioma Pro is unlocked/.test(congrats.text) && one(`SELECT COUNT(*) FROM notifications WHERE uid = ${lina.id} AND text LIKE '%full year%'`) === 1, 'félicitations : notification et email');
  await friend(codeL, true);
  r = await lina.get('promo');
  ok(r.stats.verified === 11 && r.progress === 10 && one(`SELECT COUNT(*) FROM promo_grants WHERE uid = ${lina.id} AND kind = 'referral'`) === 1, '11e ami : statistiques exactes, progression plafonnée, une seule récompense');
  sql(`UPDATE subscriptions SET period_end = ${now - 60} WHERE uid = ${lina.id} AND provider = 'promo' AND status = 'trialing'; UPDATE promo_grants SET ends = ${now - 60} WHERE uid = ${lina.id} AND kind = 'trial';
    UPDATE subscriptions SET period_start = ${now - 30} WHERE uid = ${lina.id} AND provider = 'promo' AND status = 'active'; UPDATE promo_grants SET starts = ${now - 30} WHERE uid = ${lina.id} AND kind = 'referral';`);
  r = await lina.get('billing');
  ok(r.plan === 'team' && r.subscription.status === 'active' && (await lina.get('me')).promo.reward.state === 'active', 'essai fini : l\'année offerte prend le relais');
  sql(`UPDATE subscriptions SET period_end = ${now - 10} WHERE uid = ${lina.id} AND provider = 'promo'; UPDATE promo_grants SET ends = ${now - 10} WHERE uid = ${lina.id};`);
  r = await lina.get('billing');
  ok(r.plan === 'free' && (await lina.get('me')).promo.reward.state === 'ended', 'année offerte terminée : Free, sans paiement');

  step('11. Récompense immédiate (essai fini), accès offert préservé, vérifications simultanées');
  const omar = new Client();
  await signup(omar, 'Omar Expired', 'omar@test.example');
  sql(`UPDATE subscriptions SET period_end = ${now - 60} WHERE uid = ${omar.id} AND provider = 'promo'; UPDATE promo_grants SET ends = ${now - 60} WHERE uid = ${omar.id};`);
  const codeO = (await omar.get('promo')).code;
  for (let i = 0; i < 10; i++) await friend(codeO, true);
  r = await omar.get('promo');
  ok(r.reward.state === 'active' && Math.abs(r.reward.starts - Math.floor(Date.now() / 1000)) < 120, 'essai déjà fini : l\'année commence tout de suite', r.reward);
  const codeV = (await vip.get('promo')).code;
  for (let i = 0; i < 10; i++) await friend(codeV, true);
  ok(one(`SELECT COUNT(*) FROM subscriptions WHERE uid = ${vip.id} AND provider = 'manual' AND status = 'active'`) === 1 && (await vip.get('me')).promo.reward.state === 'active' && (await vip.get('billing')).plan === 'team',
    'accès Team offert : conservé, l\'année offerte s\'ajoute sans rien réduire');
  const sara = new Client();
  await signup(sara, 'Sara Race', 'sara@test.example');
  const codeS = (await sara.get('promo')).code;
  const pending = [];
  for (let i = 0; i < 12; i++) pending.push(await friend(codeS, false));
  await Promise.all(pending.map((c) => confirmEmail(c)));
  ok(one(`SELECT COUNT(*) FROM promo_grants WHERE uid = ${sara.id} AND kind = 'referral'`) === 1 && one(`SELECT COUNT(*) FROM subscriptions WHERE uid = ${sara.id} AND provider = 'promo' AND status = 'active'`) === 1,
    '12 vérifications simultanées : exactement une récompense');

  step('12. Activité suspecte : à examiner par l\'administration');
  await setConfig('STM_REFERRAL_SAME_IP_MAX', 3);
  const yan = new Client();
  await signup(yan, 'Yan Suspicious', 'yan@test.example');
  const codeY = (await yan.get('promo')).code;
  for (let i = 0; i < 3; i++) await friend(codeY, true);
  r = await yan.get('promo');
  ok(r.stats.verified === 2 && r.history.some((h) => h.status === 'review' && h.label === 'Under review' && !h.counts), '3e compte depuis la même adresse IP : « à examiner », ne compte pas', r.history);
  await setConfig('STM_REFERRAL_SAME_IP_MAX', 0);
  ok((await yan.get('admin_promo')).status === 401, 'analyses de l\'offre : réservées à l\'administration');
  const adm = new Client();
  ok((await adm.adminLogin()).ok, 'connexion à l\'administration');
  r = await adm.get('admin_promo');
  const item = r.review && r.review.find((x) => x.referrer.id === yan.id);
  ok(item && item.reason === 'same_network' && item.referred.email, 'l\'administration voit le parrainage suspect (parrain, compte, motif)', r.review);
  r = await adm.post('admin_promo_review', { id: item.id, decision: 'approve' });
  ok(!r.error && (await yan.get('promo')).stats.verified === 3, 'approuvé : il compte');
  ok((await adm.post('admin_promo_review', { id: item.id, decision: 'reject' })).status === 404, 'décision déjà prise : refusée');

  step('13. Indicateurs de l\'administration (données réelles)');
  r = await adm.get('admin_promo');
  const M = r.metrics;
  ok(M.trials === one("SELECT COUNT(*) FROM promo_grants WHERE kind = 'trial'") && M.trials_active + M.trials_ended === M.trials, 'essais : accordés, actifs, terminés', M);
  ok(M.clicks === one('SELECT COUNT(*) FROM ref_clicks') && M.visitors === one('SELECT COUNT(DISTINCT visitor) FROM ref_clicks') && M.registrations === one('SELECT COUNT(*) FROM referrals'), 'clics, visiteurs uniques, inscriptions');
  ok(M.verified === one("SELECT COUNT(*) FROM referrals WHERE status = 'verified'") && M.referrers_goal === 4 && M.rewards === 4 && M.rewards_active + M.rewards_scheduled + M.rewards_ended === 4 && M.rewards_ended === 1,
    'parrainages vérifiés, parrains à 10, récompenses (actives, à venir, terminées)', M);
  ok(M.rejected >= 1 && M.review === 0 && typeof M.conversion === 'number' && r.top.length > 0, 'refusés, suspects, conversion, meilleurs parrains');
  ok((await adm.get('admin_promo', { days: 7 })).days === 7, 'filtre de période');
  r = await adm.get('admin_billing');
  ok(r.metrics.promo >= 1 && Object.keys(r.metrics.mrr).length === 0, 'abonnements : l\'offre à part, jamais de chiffre d\'affaires', r.metrics);

  step('14. Aucun paiement, aucune carte, aucun PayPal');
  ok(noPayment(), 'aucun appel à PayPal, aucun client ni paiement, aucun abonnement payant');
  r = await amel.post('billing_checkout', { plan: 'team', interval: 'month' });
  ok(r.status === 503 && r.error === 'billing_unavailable', 'le paiement reste fermé (rien n\'est ouvert par l\'offre)', r);
  ok(one("SELECT COUNT(*) FROM subscriptions WHERE provider = 'promo' AND amount <> 0") === 0, 'aucun montant sur les accès de l\'offre');

  step('15. Offre coupée');
  await setConfig('STM_LAUNCH_PROMO', 'false');
  const late = new Client();
  r = await signup(late, 'After Launch', 'after@test.example');
  ok(r.promo && r.promo.trial.state === 'none' && r.promo.enabled === false && (await late.get('billing')).plan === 'free', 'offre coupée : plus d\'essai pour les nouveaux comptes', { promo: r.promo, err: r.error, cfg: fs.readFileSync(CONFIG, 'utf8').match(/STM_LAUNCH_PROMO[^;]*/)[0] });
  ok((await late.post('promo_claim')).error === 'promo_closed', 'plus de réclamation');
  ok((await new Client().post('ref_click', { code: codeY })).valid === false, 'plus d\'attribution');
  ok((await omar.get('billing')).plan === 'team', 'les accès déjà accordés continuent');
  await setConfig('STM_LAUNCH_PROMO', 'true');

  console.log('\n' + (failed ? failed + ' échec(s).' : 'Tout est bon.'));
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
