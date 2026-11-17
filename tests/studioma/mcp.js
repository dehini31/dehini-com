/*
 * Tests du serveur MCP de Studioma et de son OAuth, comme les ferait un assistant IA (claude.ai,
 * ChatGPT, Gemini CLI…), sur le faux hébergement de run.sh :
 *   découverte (401 + métadonnées) → enregistrement de l'application → autorisation avec PKCE et
 *   écran d'accord → jetons → appels MCP (initialize, tools/list, tools/call) → droits de la personne
 *   respectés → renouvellement, révocation, jeton personnel.
 *   node tests/studioma/mcp.js <adresse> <dossier>
 */
const crypto = require('crypto');

const fs = require('fs');
const path = require('path');

const [ORIGIN, DIR] = process.argv.slice(2);
const mails = () => (fs.existsSync(path.join(DIR, 'mail.jsonl')) ? fs.readFileSync(path.join(DIR, 'mail.jsonl'), 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
const S = ORIGIN + '/studioma/';
const MCP = S + 'mcp';
let failed = 0;
const ok = (cond, label, extra) => { if (cond) console.log('  ✓ ' + label); else { failed++; console.log('  ✗ ' + label + (extra !== undefined ? ' → ' + JSON.stringify(extra).slice(0, 600) : '')); } };
const step = (t) => console.log('\n' + t);
const b64 = (buf) => Buffer.from(buf).toString('base64').replace(/=+$/, '').replace(/\+/g, '-').replace(/\//g, '_');

/** Navigateur connecté à l'application (cookie + jeton CSRF). */
class Session {
  constructor() { this.cookie = ''; this.csrf = ''; }
  async call(action, body, params) {
    const url = new URL(S + 'api.php');
    url.searchParams.set('action', action);
    Object.entries(params || {}).forEach(([k, v]) => url.searchParams.set(k, v));
    const init = { headers: { Cookie: this.cookie } };
    if (body !== undefined) Object.assign(init, { method: 'POST', body: JSON.stringify(body) }, { headers: { Cookie: this.cookie, 'Content-Type': 'application/json', 'X-Requested-With': 'studioma', 'X-CSRF': this.csrf } });
    const res = await fetch(url, init);
    (res.headers.getSetCookie ? res.headers.getSetCookie() : []).forEach((c) => { if (c.startsWith('stm_sid=')) this.cookie = c.split(';')[0]; });
    const d = await res.json();
    if (d.csrf) this.csrf = d.csrf;
    d.http = res.status;
    return d;
  }
  get(a, p) { return this.call(a, undefined, p); }
  post(a, b) { return this.call(a, b || {}); }
}
/** Client MCP : un message JSON-RPC par POST, jeton Bearer. */
let rpcId = 0;
async function mcp(token, method, params, raw) {
  const body = raw !== undefined ? raw : JSON.stringify({ jsonrpc: '2.0', id: ++rpcId, method, params: params || {} });
  const res = await fetch(MCP, { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-06-18' }, token ? { Authorization: 'Bearer ' + token } : {}), body });
  const text = await res.text();
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch (e) { data = { unparsed: text }; }
  return { status: res.status, headers: res.headers, data };
}
/** Appel d'un outil ; renvoie { error, text, data } (data = structuredContent). */
async function tool(token, name, args) {
  const r = await mcp(token, 'tools/call', { name, arguments: args || {} });
  if (!r.data || !r.data.result) return { rpc: r.data && r.data.error, status: r.status };
  const res = r.data.result;
  return { error: res.isError, text: (res.content || []).map((c) => c.text).join('\n'), data: res.structuredContent };
}
const form = (o) => new URLSearchParams(o).toString();
async function token(params, basic) {
  const res = await fetch(S + 'oauth/token', { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/x-www-form-urlencoded' }, basic ? { Authorization: 'Basic ' + Buffer.from(basic).toString('base64') } : {}), body: form(params) });
  const d = await res.json();
  d.http = res.status;
  return d;
}
/** Demande d'autorisation complète (PKCE) jusqu'au code, avec l'accord de la session donnée. */
async function authorize(session, client, redirect, opts) {
  opts = opts || {};
  const verifier = b64(crypto.randomBytes(32));
  const challenge = b64(crypto.createHash('sha256').update(verifier).digest());
  const q = new URLSearchParams({ response_type: 'code', client_id: client, redirect_uri: redirect, code_challenge: challenge, code_challenge_method: 'S256', state: 'st-' + rpcId, resource: MCP, scope: 'studioma' });
  const res = await fetch(S + 'oauth/authorize?' + q, { redirect: 'manual' });
  const loc = res.headers.get('location') || '';
  const id = (loc.match(/#\/connect\/([a-f0-9]{32})$/) || [])[1];
  if (!id) return { error: 'no-request', status: res.status, loc };
  const d = await session.post('oauth_decide', { id, allow: opts.deny ? false : true });
  const back = new URL(d.redirect);
  return { verifier, id, back, code: back.searchParams.get('code'), state: back.searchParams.get('state'), sent: 'st-' + (rpcId) };
}

(async () => {
  step('Préparation');
  const amel = new Session();
  let r = await amel.post('signup', { name: 'Amel Haddad', email: 'amel@test.example', password: 'a-solid-password', accept: true });
  ok(r.ok, 'compte créé');
  r = await amel.post('project_save', { name: 'Vélora', template: 'content', studio_name: 'Social 2026' });
  const studioId = r.studio_id;
  ok(studioId > 0, 'projet et Studio créés (modèle par défaut)');
  const karim = new Session();
  await karim.post('signup', { name: 'Karim Benali', email: 'karim@test.example', password: 'a-solid-password', accept: true });

  /* --------------------------------------------------------- découverte */
  step('Découverte (comme un assistant qui ne connaît que l\'adresse du serveur)');
  let m = await mcp(null, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'test', version: '1' } });
  const www = m.headers.get('www-authenticate') || '';
  ok(m.status === 401 && /^Bearer /.test(www) && /resource_metadata="[^"]+"/.test(www), 'sans jeton : 401 avec WWW-Authenticate et resource_metadata', www);
  ok(m.headers.get('access-control-expose-headers') && /WWW-Authenticate/i.test(m.headers.get('access-control-expose-headers')), 'en-tête WWW-Authenticate lisible depuis un navigateur (CORS)');
  const prmUrl = www.match(/resource_metadata="([^"]+)"/)[1];
  let prm = await (await fetch(prmUrl)).json();
  ok(prm.resource === MCP && prm.authorization_servers[0] === ORIGIN + '/studioma', 'métadonnées de la ressource : serveur MCP et serveur d\'autorisation', prm);
  for (const u of ['/.well-known/oauth-protected-resource/studioma/mcp', '/.well-known/oauth-protected-resource']) {
    const res = await fetch(ORIGIN + u);
    ok(res.status === 200 && (await res.json()).resource === MCP, 'découverte à la racine du domaine : ' + u);
  }
  const asUrls = ['/.well-known/oauth-authorization-server/studioma', '/.well-known/openid-configuration/studioma', '/studioma/.well-known/openid-configuration', '/studioma/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server'];
  let as = null;
  for (const u of asUrls) {
    const res = await fetch(ORIGIN + u);
    const d = res.status === 200 ? await res.json() : {};
    if (!as) as = d;
    ok(d.issuer === ORIGIN + '/studioma' && d.code_challenge_methods_supported.includes('S256') && d.registration_endpoint, 'métadonnées du serveur d\'autorisation : ' + u);
  }
  ok(as.authorization_endpoint === S + 'oauth/authorize' && as.token_endpoint === S + 'oauth/token' && as.grant_types_supported.includes('refresh_token'), 'adresses d\'autorisation et de jetons annoncées');
  const pre = await fetch(MCP, { method: 'OPTIONS', headers: { Origin: 'https://claude.ai', 'Access-Control-Request-Method': 'POST', 'Access-Control-Request-Headers': 'authorization,content-type' } });
  ok(pre.status === 204 && pre.headers.get('access-control-allow-origin') === '*' && /Authorization/i.test(pre.headers.get('access-control-allow-headers')), 'pré-vérification CORS');

  /* ---------------------------------------------------- enregistrement */
  step('Enregistrement de l\'application (RFC 7591)');
  const reg = async (body) => { const res = await fetch(as.registration_endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); const d = await res.json(); d.http = res.status; return d; };
  const CB = 'https://claude.ai/api/mcp/auth_callback';
  r = await reg({ client_name: 'Claude', redirect_uris: [CB], token_endpoint_auth_method: 'none', grant_types: ['authorization_code', 'refresh_token'], response_types: ['code'] });
  ok(r.http === 201 && /^stm_/.test(r.client_id) && !r.client_secret && r.token_endpoint_auth_method === 'none', 'client public enregistré (sans secret)', r);
  const claude = r.client_id;
  r = await reg({ client_name: 'Evil', redirect_uris: ['http://evil.example/cb'] });
  ok(r.http === 400 && r.error === 'invalid_redirect_uri', 'adresse de retour en http (hors machine locale) refusée');
  r = await reg({ client_name: 'Evil', redirect_uris: ['javascript:alert(1)'] });
  ok(r.http === 400, 'adresse de retour javascript: refusée');
  r = await reg({ client_name: 'Desktop app', redirect_uris: ['http://127.0.0.1:33418/callback'] });
  ok(r.http === 201 && r.client_secret && r.token_endpoint_auth_method === 'client_secret_basic', 'client confidentiel : secret remis');
  const desk = r;

  /* ------------------------------------------------------- autorisation */
  step('Autorisation (PKCE, écran d\'accord)');
  const q0 = (o) => S + 'oauth/authorize?' + new URLSearchParams(Object.assign({ response_type: 'code', client_id: claude, redirect_uri: CB, code_challenge: b64(crypto.randomBytes(32)), code_challenge_method: 'S256', state: 'abc' }, o));
  let res = await fetch(q0({ redirect_uri: 'https://evil.example/cb' }), { redirect: 'manual' });
  ok(res.status === 400 && /text\/html/.test(res.headers.get('content-type')), 'adresse de retour non enregistrée : arrêt, sans redirection');
  res = await fetch(q0({ client_id: 'stm_000000000000000000000000' }), { redirect: 'manual' });
  ok(res.status === 400, 'application inconnue : arrêt');
  res = await fetch(q0({ code_challenge_method: 'plain' }), { redirect: 'manual' });
  let loc = new URL(res.headers.get('location'));
  ok(res.status === 302 && loc.origin + loc.pathname === CB && loc.searchParams.get('error') === 'invalid_request' && loc.searchParams.get('state') === 'abc', 'PKCE « plain » refusé (erreur renvoyée à l\'application)');
  res = await fetch(q0({ resource: 'https://other.example/mcp' }), { redirect: 'manual' });
  ok(new URL(res.headers.get('location')).searchParams.get('error') === 'invalid_target', 'ressource étrangère refusée (RFC 8707)');
  res = await fetch(q0({}), { redirect: 'manual' });
  loc = res.headers.get('location') || '';
  ok(res.status === 302 && /\/studioma\/app\/#\/connect\/[a-f0-9]{32}$/.test(loc), 'demande valable : envoi vers l\'écran d\'accord de Studioma', loc);
  const reqId = loc.match(/connect\/([a-f0-9]{32})/)[1];
  r = await new Session().get('oauth_request', { id: reqId });
  ok(r.http === 401, 'écran d\'accord : connexion exigée');
  r = await amel.get('oauth_request', { id: reqId });
  ok(r.ok && r.request.client_name === 'Claude' && r.request.redirect_host === 'claude.ai' && !r.request.already, 'écran d\'accord : application et site de retour affichés', r.request);
  r = await amel.post('oauth_decide', { id: reqId, allow: false });
  let back = new URL(r.redirect);
  ok(back.searchParams.get('error') === 'access_denied' && back.searchParams.get('state') === 'abc', 'refus : l\'application reçoit access_denied');

  let a = await authorize(amel, claude, CB);
  ok(a.code && a.back.searchParams.get('iss') === ORIGIN + '/studioma' && a.state, 'accord : code renvoyé à l\'application (avec iss et state)');
  r = await token({ grant_type: 'authorization_code', code: a.code, redirect_uri: CB, client_id: claude, code_verifier: b64(crypto.randomBytes(32)), resource: MCP });
  ok(r.http === 400 && r.error === 'invalid_grant', 'mauvais code_verifier : refusé');
  r = await token({ grant_type: 'authorization_code', code: a.code, redirect_uri: CB, client_id: claude, code_verifier: a.verifier, resource: MCP });
  ok(r.http === 400 && r.error === 'invalid_grant', 'code déjà présenté : inutilisable ensuite');
  a = await authorize(amel, claude, CB);
  r = await token({ grant_type: 'authorization_code', code: a.code, redirect_uri: CB, client_id: claude, code_verifier: a.verifier, resource: MCP });
  ok(r.http === 200 && /^stm_at_/.test(r.access_token) && /^stm_rt_/.test(r.refresh_token) && r.token_type === 'Bearer' && r.expires_in === 3600, 'jetons délivrés (accès 1 h + renouvellement)', r);
  let AT = r.access_token;
  let RT = r.refresh_token;

  /* ------------------------------------------------------------ MCP */
  step('Protocole MCP');
  m = await mcp(AT, 'initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'claude-ai', version: '1' } });
  ok(m.status === 200 && m.data.result.protocolVersion === '2025-06-18' && m.data.result.serverInfo.name === 'studioma' && m.data.result.capabilities.tools && /Project/.test(m.data.result.instructions), 'initialize : version négociée, outils, instructions');
  m = await mcp(AT, 'initialize', { protocolVersion: '2099-01-01', capabilities: {}, clientInfo: { name: 'future', version: '1' } });
  ok(m.data.result.protocolVersion === '2025-11-25', 'version inconnue : le serveur propose la sienne');
  m = await mcp(AT, null, null, JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }));
  ok(m.status === 202 && m.data === null, 'notification : 202 sans corps');
  m = await mcp(AT, 'tools/list');
  const tools = m.data.result.tools;
  const names = tools.map((t) => t.name);
  ok(['search', 'fetch', 'list_projects', 'get_studio', 'create_card', 'move_card', 'update_card', 'add_comment', 'my_work', 'invite_member', 'set_handoff'].every((n) => names.includes(n)) && tools.every((t) => t.inputSchema && t.inputSchema.type === 'object' && t.description && t.annotations), 'tools/list : ' + tools.length + ' outils décrits (schéma, annotations)');
  ok(tools.find((t) => t.name === 'get_studio').annotations.readOnlyHint === true && tools.find((t) => t.name === 'archive_card').annotations.destructiveHint === true && !names.includes('delete_card'), 'lecture seule et actions destructives signalées ; aucune suppression définitive');
  m = await mcp(AT, 'ping');
  ok(m.data.result && Object.keys(m.data.result).length === 0, 'ping');
  m = await mcp(AT, 'nope/method');
  ok(m.data.error.code === -32601, 'méthode inconnue : -32601');
  m = await mcp(AT, 'tools/call', { name: 'nope', arguments: {} });
  ok(m.data.error.code === -32602, 'outil inconnu : -32602');
  m = await mcp(AT, null, null, '{not json');
  ok(m.status === 400 && m.data.error.code === -32700, 'JSON illisible : -32700');
  m = await mcp(AT, null, null, JSON.stringify([{ jsonrpc: '2.0', id: 'a', method: 'ping' }, { jsonrpc: '2.0', method: 'notifications/initialized' }, { jsonrpc: '2.0', id: 'b', method: 'tools/list' }]));
  ok(Array.isArray(m.data) && m.data.length === 2 && m.data[0].id === 'a' && m.data[1].result.tools, 'lot de messages : une réponse par requête');
  res = await fetch(MCP, { headers: { Authorization: 'Bearer ' + AT, Accept: 'text/event-stream' } });
  ok(res.status === 405, 'GET (flux SSE) : 405, le serveur répond en JSON à chaque POST');

  /* ---------------------------------------------------------- outils */
  step('Outils : gérer un Studio');
  let t = await tool(AT, 'list_projects');
  ok(!t.error && t.data.projects[0].name === 'Vélora' && t.data.projects[0].studios[0].id === studioId && t.data.projects[0].your_role === 'owner', 'list_projects');
  ok(JSON.parse(t.text).projects.length === 1, 'résultat aussi en texte JSON (clients plus anciens)');
  t = await tool(AT, 'get_studio', { studio_id: studioId });
  ok(!t.error && t.data.levels.length === 3 && t.data.levels[0].handoff.mode === 'auto' && t.data.levels[0].handoff.to_level === 'Production' && t.data.levels[0].columns[0].principal, 'get_studio : niveaux, colonnes, relais', t.data && t.data.levels[0]);
  t = await tool(AT, 'create_card', { studio_id: studioId, title: 'Reel: 5 beginner mistakes', description: '## Hook\nStop doing **this** on your first ride.\n\n- Saddle too low\n- No *gloves*\n\nSee [guide](https://example.com/guide)', due: '2026-12-20', priority: 'high', platforms: ['instagram', 'tiktok'], content_type: 'reel', tags: ['beginners'], assignee: '@amel-haddad' });
  const cardId = t.data && t.data.card.id;
  ok(!t.error && cardId && t.data.card.where === 'Creation › 💡 Ideas' && t.data.card.priority === 'high' && t.data.card.due === '2026-12-20' && t.data.card.assignee_id > 0, 'create_card : dans la colonne principale du premier niveau, avec ses champs', t);
  r = await amel.get('card_get', { id: cardId });
  ok(/<h2>Hook<\/h2>/.test(r.card.body) && /<b>this<\/b>/.test(r.card.body) && /<li>No <i>gloves<\/i><\/li>/.test(r.card.body) && /href="https:\/\/example.com\/guide"/.test(r.card.body), 'description Markdown → texte mis en forme de la carte', r.card.body);
  ok(/## Hook/.test(t.data.card.description) && /\*\*this\*\*/.test(t.data.card.description), 'description relue en Markdown');
  ok(r.card.events.some((e) => /created this card/.test(e.text) && e.user && e.user.name === 'Amel Haddad'), 'historique : l\'action est au nom de la personne');
  t = await tool(AT, 'create_card', { studio_id: studioId, title: 'Carousel: maintenance', column: 'script in progress' });
  ok(!t.error && t.data.card.where === 'Creation › ✍️ Script in Progress', 'colonne donnée par son nom (sans emoji ni casse)');
  t = await tool(AT, 'move_card', { card_id: cardId, column: 'Script Approved' });
  ok(t.error && /Several columns match/.test(t.text) && /Creation › ✅ Script Approved/.test(t.text) && /Production › ✅ Script Approved/.test(t.text), 'nom ambigu : l\'assistant reçoit les choix possibles', t.text);
  t = await tool(AT, 'move_card', { card_id: cardId, column: 'Script Approved', level: 'Creation' });
  ok(!t.error && t.data.handed_off === 'Production › ✅ Script Approved' && t.data.card.id === cardId && t.data.card.where === 'Production › ✅ Script Approved', 'move_card vers la colonne finale : relais vers Production (même carte)', t.data);
  t = await tool(AT, 'add_comment', { card_id: cardId, text: 'Script ready for review' });
  ok(!t.error && t.data.comments.length === 1 && t.data.comments[0].by === 'Amel Haddad', 'add_comment');
  t = await tool(AT, 'add_checklist_items', { card_id: cardId, items: ['Write the hook', 'Record the voice-over'] });
  ok(!t.error && t.data.checklist.length === 2, 'add_checklist_items');
  t = await tool(AT, 'check_item', { item_id: t.data.checklist[0].item_id, done: true });
  ok(!t.error && t.data.checklist[0].done === true, 'check_item');
  t = await tool(AT, 'update_card', { card_id: cardId, title: 'Reel: 5 beginner mistakes (v2)', publish_at: '2026-12-22T18:30', due: '' });
  ok(!t.error && t.data.card.title === 'Reel: 5 beginner mistakes (v2)' && t.data.card.publish_at === '2026-12-22T18:30' && t.data.card.due === null, 'update_card : seuls les champs donnés changent');
  t = await tool(AT, 'update_card', { card_id: cardId, due: '20/12/2026' });
  ok(t.error && /YYYY-MM-DD/.test(t.text), 'date mal écrite : erreur claire');
  t = await tool(AT, 'get_card', { card_id: cardId });
  ok(!t.error && t.data.checklist.length === 2 && t.data.history.length >= 5 && t.data.url.includes('/card/' + cardId) && t.data.next_level === 'Publication', 'get_card : tout le contenu, l\'historique et le lien');
  t = await tool(AT, 'search', { query: 'beginner' });
  ok(!t.error && t.data.results.some((x) => x.id === 'card:' + cardId), 'search (format attendu par ChatGPT)');
  t = await tool(AT, 'fetch', { id: 'card:' + cardId });
  ok(!t.error && t.data.id === 'card:' + cardId && JSON.parse(t.data.text).title.startsWith('Reel') && t.data.url, 'fetch (format attendu par ChatGPT)');
  t = await tool(AT, 'my_work', {});
  ok(!t.error && t.data.cards.some((c) => c.id === cardId && c.studio === 'Social 2026'), 'my_work');
  t = await tool(AT, 'studio_activity', { studio_id: studioId, limit: 5 });
  ok(!t.error && t.data.events.length === 5 && t.data.events.every((e) => e.text), 'studio_activity');
  t = await tool(AT, 'studio_insights', { studio_id: studioId });
  ok(!t.error && t.data.totals.cards === 2, 'studio_insights');
  t = await tool(AT, 'create_level', { studio_id: studioId, name: 'Analysis', icon: '📊' });
  ok(!t.error && t.data.studio.levels.length === 4 && t.data.studio.levels[3].name === '📊 Analysis', 'create_level');
  t = await tool(AT, 'create_column', { studio_id: studioId, level: 'analysis', name: 'Reported', kind: 'done' });
  ok(!t.error && t.data.studio.levels[3].columns.some((c) => c.name === 'Reported' && c.kind === 'done'), 'create_column');
  t = await tool(AT, 'set_handoff', { studio_id: studioId, level: 'Production', mode: 'manual' });
  ok(!t.error && t.data.studio.levels[0].handoff.mode === 'manual', 'set_handoff');
  t = await tool(AT, 'create_project', { name: 'Kivo', template: 'kanban' });
  ok(!t.error && t.data.project_id > 0 && t.data.studio.levels.length === 1, 'create_project (avec son premier Studio)');
  const kivoId = t.data.project_id;
  t = await tool(AT, 'create_studio', { project_id: kivoId, name: 'Blank', template: 'blank' });
  ok(!t.error && t.data.studio.levels.length === 0, 'create_studio');
  const blankId = t.data.studio_id;
  t = await tool(AT, 'archive_card', { card_id: cardId });
  ok(!t.error && t.data.archived, 'archive_card');
  t = await tool(AT, 'get_studio', { studio_id: studioId, level: 'Production' });
  ok(!t.error && t.data.levels.length === 1 && t.data.levels[0].columns.every((c) => c.cards.every((k) => k.id !== cardId)), 'carte archivée retirée du tableau ; filtre par niveau');
  await tool(AT, 'archive_card', { card_id: cardId, archived: false });

  /* ------------------------------------------------------------ droits */
  step('Droits : l\'assistant n\'a jamais plus de droits que la personne');
  r = await karim.post('ai_token_create', { name: 'Karim CLI' });
  ok(r.ok && /^stm_pat_/.test(r.token) && r.mcp_url === MCP, 'jeton personnel créé (montré une fois)');
  const KT = r.token;
  t = await tool(KT, 'get_studio', { studio_id: studioId });
  ok(t.error && /doesn't exist or you don't have access/.test(t.text), 'Studio d\'un autre : introuvable');
  t = await tool(KT, 'get_card', { card_id: cardId });
  ok(t.error, 'carte d\'un autre : introuvable');
  t = await tool(KT, 'search', { query: 'beginner' });
  ok(!t.error && t.data.results.length === 0, 'recherche limitée à ses propres Studios');
  t = await tool(AT, 'invite_member', { studio_id: studioId, email: 'karim@test.example', role: 'viewer', message: 'Have a look' });
  ok(!t.error && t.data.invited === 'karim@test.example', 'invite_member (par l\'assistant d\'Amel)');
  // Karim ouvre le lien reçu par email (son adresse n'est pas confirmée : l'invitation n'apparaît pas d'elle-même).
  const mail = mails().reverse().find((x) => x.to === 'karim@test.example' && /join\/[a-f0-9]{32}/.test(x.text));
  const tokenInv = mail && mail.text.match(/join\/([a-f0-9]{32})/)[1];
  r = tokenInv ? await karim.post('invite_accept', { token: tokenInv }) : {};
  ok(r.ok, 'Karim accepte l\'invitation reçue par email (lecteur)', r);
  t = await tool(KT, 'get_studio', { studio_id: studioId });
  ok(!t.error && t.data.studio.your_role === 'viewer', 'lecteur : peut lire le Studio');
  t = await tool(KT, 'create_card', { studio_id: studioId, title: 'Pirate' });
  ok(t.error && /role doesn't allow/.test(t.text), 'lecteur : création refusée par le serveur', t.text);
  t = await tool(KT, 'move_card', { card_id: cardId, column: 'Ideas' });
  ok(t.error, 'lecteur : déplacement refusé');
  t = await tool(KT, 'create_level', { studio_id: studioId, name: 'Pirate' });
  ok(t.error, 'lecteur : structure refusée');

  /* ------------------------------------------------ projets et Studios */
  step('Outils : projets et Studios');
  const veloraId = (await tool(AT, 'list_projects')).data.projects.find((p) => p.name === 'Vélora').id;
  t = await tool(AT, 'get_project', { project_id: veloraId });
  ok(!t.error && t.data.project.your_role === 'owner' && t.data.studios.some((s) => s.id === studioId && s.cards > 0) && t.data.members.some((x) => x.username === 'amel-haddad' && x.role === 'owner'), 'get_project : Studios et membres', t.data);
  t = await tool(AT, 'update_project', { project_id: veloraId, description: 'Spring campaign for city bikes', color: 'green' });
  ok(!t.error && t.data.project.name === 'Vélora' && t.data.project.description === 'Spring campaign for city bikes' && t.data.project.color === 'green', 'update_project : seuls les champs donnés changent');
  t = await tool(AT, 'update_studio', { studio_id: blankId, name: 'Kivo launch' });
  ok(!t.error && t.data.studio.name === 'Kivo launch', 'update_studio : renommé');
  t = await tool(AT, 'archive_studio', { studio_id: blankId });
  ok(!t.error && t.data.archived && (await tool(AT, 'get_project', { project_id: kivoId })).data.studios.find((s) => s.id === blankId).archived === true, 'archive_studio');
  await tool(AT, 'archive_studio', { studio_id: blankId, archived: false });
  t = await tool(AT, 'archive_project', { project_id: kivoId });
  ok(!t.error && !(await tool(AT, 'list_projects')).data.projects.some((p) => p.id === kivoId), 'archive_project : le projet quitte la liste');
  t = await tool(AT, 'archive_project', { project_id: kivoId, archived: false });
  ok(!t.error && (await tool(AT, 'list_projects')).data.projects.some((p) => p.id === kivoId), 'archive_project (archived=false) : restauré');
  t = await tool(KT, 'update_project', { project_id: veloraId, name: 'Pirate' });
  ok(t.error && (await tool(AT, 'get_project', { project_id: veloraId })).data.project.name === 'Vélora', 'lecteur : ne modifie pas le projet');
  t = await tool(KT, 'update_studio', { studio_id: studioId, name: 'Pirate' });
  ok(t.error, 'lecteur : ne renomme pas le Studio');

  /* ---------------------------------------------------- études de cas */
  step('Outils : études de cas');
  t = await tool(AT, 'save_case_study', { brand: 'Nobody' });
  ok(t.error && /title/.test(t.text), 'titre exigé à la création');
  t = await tool(AT, 'save_case_study', { title: 'Vélora spring launch', brand: 'Vélora', role: 'Community manager', objectives: 'Grow the community before spring.', metrics: [{ label: 'Reach', value: '120k' }], links: ['https://example.com/reel'], platforms: ['instagram'], publish: false });
  const caseId = t.data && t.data.case_study.id;
  ok(!t.error && caseId && t.data.case_study.status === 'draft' && t.data.case_study.evidence === 'self-reported' && /draft/.test(t.data.note), 'save_case_study : brouillon privé', t);
  r = await karim.get('profile_get', { handle: 'amel-haddad' });
  ok(!JSON.stringify(r).includes('Vélora spring launch'), 'brouillon invisible des autres');
  t = await tool(AT, 'save_case_study', { case_study_id: caseId, results: 'Community doubled in 6 weeks.', publish: true });
  ok(!t.error && t.data.case_study.status === 'published' && t.data.case_study.title === 'Vélora spring launch' && t.data.case_study.metrics[0].value === '120k' && t.data.case_study.links.length === 1 && t.data.case_study.objectives === 'Grow the community before spring.', 'mise à jour : publiée, le reste conservé', t.data);
  r = await karim.get('profile_get', { handle: 'amel-haddad' });
  ok(JSON.stringify(r).includes('Community doubled'), 'publiée : visible sur le profil public');
  t = await tool(AT, 'save_case_study', { case_study_id: caseId, platforms: ['myspace'] });
  ok(t.error && /platforms must be one of: .*instagram/.test(t.text), 'plateforme inconnue : erreur claire', t.text);
  t = await tool(AT, 'request_results_confirmation', { case_study_id: caseId, client: '@karim-benali' });
  r = await karim.get('requests');
  ok(!t.error && r.confirmations.length === 1 && r.confirmations[0].id === caseId, 'request_results_confirmation : la cliente reçoit la demande');
  t = await tool(KT, 'save_case_study', { case_study_id: caseId, title: 'Pirate' });
  ok(t.error && /doesn't exist|not found|no longer/i.test(t.text), 'l\'étude de cas d\'une autre personne : introuvable', t.text);
  t = await tool(AT, 'create_card', { studio_id: studioId, title: 'Reel: city rides', column: 'Published' });
  const pubCard = t.data.card.id;
  t = await tool(AT, 'case_study_from_studio', { studio_id: studioId, card_ids: [pubCard] });
  ok(t.error && /publication link/.test(t.text), 'carte publiée sans lien : refusée (preuve vérifiable)', t.text);
  await tool(AT, 'update_card', { card_id: pubCard, publish_url: 'https://instagram.com/p/velora1', metrics: { views: 4200, likes: 310 } });
  t = await tool(AT, 'case_study_from_studio', { studio_id: studioId, card_ids: [pubCard] });
  const fromId = t.data && t.data.case_study.id;
  ok(!t.error && t.data.case_study.status === 'draft' && t.data.case_study.evidence === 'studio-verified' && t.data.case_study.links[0] === 'https://instagram.com/p/velora1' && t.data.case_study.metrics.some((x) => x.label === 'Views' && x.value === '4,200'), 'case_study_from_studio : brouillon avec lien et chiffres de la carte', t.data);
  t = await tool(AT, 'save_case_study', { case_study_id: fromId, role: 'Video editor', publish: true });
  ok(!t.error && t.data.case_study.status === 'published' && t.data.case_study.evidence === 'studio-verified', 'publiée (propriétaire du projet, sans cliente : aucune autorisation à attendre)');
  t = await tool(AT, 'list_case_studies');
  ok(!t.error && t.data.case_studies.length === 2 && t.data.case_studies.every((c) => c.url.includes('u/amel-haddad')), 'list_case_studies');

  /* ------------------------------------------------------- publications */
  step('Outils : publications');
  t = await tool(AT, 'create_post', { text: 'Spring is here 🚲 Our new #cycling series starts today with @karim-benali' });
  const postId = t.data && t.data.post.id;
  ok(!t.error && postId && t.data.post.yours && /Amel Haddad/.test(t.data.post.author), 'create_post : publiée au nom de la personne');
  t = await tool(AT, 'create_post', { text: '   ' });
  ok(t.error, 'publication vide : refusée');
  t = await tool(AT, 'list_posts', { scope: 'mine' });
  ok(!t.error && t.data.posts[0].id === postId, 'list_posts mine');
  t = await tool(KT, 'list_posts', { scope: 'discover' });
  ok(!t.error && t.data.posts.some((p) => p.id === postId && !p.yours), 'list_posts discover (autre compte)');
  t = await tool(KT, 'comment_on_post', { post_id: postId, text: 'Looks great!' });
  ok(!t.error && t.data.comments.length === 1 && t.data.comments[0].by === 'Karim Benali', 'comment_on_post');
  t = await tool(KT, 'create_post', { text: 'Worth a look', share_post_id: postId });
  ok(!t.error && t.data.post.shares && t.data.post.shares.id === postId, 'create_post en partageant une publication');
  r = await amel.get('notifications');
  ok(r.notifications.some((n) => /commented on your post/.test(n.text)), 'l\'autrice est prévenue du commentaire');

  /* ---------------------------------------------------------- messages */
  step('Outils : personnes et messages');
  t = await tool(AT, 'search_people', { query: 'Karim' });
  ok(!t.error && t.data.people.some((x) => x.username === 'karim-benali'), 'search_people');
  ok(!JSON.stringify(t.data).includes('karim@test.example'), 'aucune adresse email exposée');
  t = await tool(AT, 'send_message', { to: '@karim-benali', text: 'Hi Karim, can you review the spring reel today?' });
  ok(!t.error && t.data.sent_to.username === 'karim-benali', 'send_message');
  t = await tool(KT, 'list_conversations');
  ok(!t.error && t.data.conversations[0].username === 'amel-haddad' && t.data.conversations[0].unread === true && /spring reel/.test(t.data.conversations[0].last_message), 'list_conversations : non lu');
  t = await tool(KT, 'read_conversation', { with: 'amel-haddad' });
  ok(!t.error && t.data.messages.length === 1 && t.data.messages[0].from === 'Amel Haddad', 'read_conversation');
  ok((await tool(KT, 'list_conversations')).data.conversations[0].unread === false, 'lu après lecture');
  t = await tool(KT, 'send_message', { to: 'amel-haddad', text: 'Sure, sending notes in an hour.' });
  r = await amel.get('thread', { with: 'karim-benali' });
  ok(!t.error && r.messages.length === 2 && r.messages[1].body === 'Sure, sending notes in an hour.', 'réponse reçue dans l\'application');
  t = await tool(AT, 'send_message', { to: '@nobody-here', text: 'Hello' });
  ok(t.error && /search_people/.test(t.text), 'destinataire inconnu : l\'assistant sait comment le trouver');
  t = await tool(AT, 'send_message', { to: '@amel-haddad', text: 'Note to self' });
  ok(t.error, 'pas de message à soi-même');

  /* -------------------------------------------------------- entreprises */
  step('Outils : page entreprise, équipe, opportunités');
  t = await tool(AT, 'get_company');
  ok(!t.error && t.data.your_companies.length === 0, 'get_company sans argument : aucune entreprise');
  t = await tool(AT, 'save_opportunity', { title: 'Video editor', description: 'Edit 8 reels a month for our spring campaign.' });
  ok(t.error && /save_company/.test(t.text), 'opportunité sans entreprise : l\'assistant sait quoi faire');
  t = await tool(AT, 'save_company', { name: 'Vélora Bikes', tagline: 'City bikes for everyone', about: 'We design city bikes.', industry: 'Sports & fitness', size: '11-50', location: 'Lyon, France', website: 'https://velora.example' });
  const coId = t.data && t.data.company.id;
  const coSlug = t.data && t.data.company.page;
  ok(!t.error && coId && t.data.your_role === 'owner' && t.data.company.size === '11-50', 'save_company : page créée, la personne en est propriétaire', t);
  t = await tool(AT, 'save_company', { company_id: coId, tagline: 'Ride the city' });
  ok(!t.error && t.data.company.tagline === 'Ride the city' && t.data.company.about === 'We design city bikes.' && t.data.company.website === 'https://velora.example', 'mise à jour : seuls les champs donnés changent');
  t = await tool(AT, 'save_company', { company_id: coId, size: 'huge' });
  ok(t.error && /size must be one of: "" \(none\), 1, 2-10/.test(t.text) && (await tool(AT, 'get_company', { company: coSlug })).data.company.size === '11-50', 'valeur hors liste : refusée avec les choix possibles, rien ne change', t.text);
  t = await tool(AT, 'manage_company_team', { company_id: coId, action: 'promote', person: '@karim-benali' });
  ok(t.error && /action must be one of: add, set_role, remove/.test(t.text), 'action inconnue : refusée');
  t = await tool(AT, 'update_card', { card_id: cardId, priority: 'critical' });
  ok(t.error && /priority must be one of/.test(t.text), 'la vérification vaut pour tous les outils (priorité d\'une carte)');
  t = await tool(KT, 'save_company', { company_id: coId, name: 'Pirate' });
  ok(t.error && (await tool(AT, 'get_company', { company: coSlug })).data.company.name === 'Vélora Bikes', 'la page d\'une autre entreprise : non modifiable');
  t = await tool(AT, 'create_post', { text: 'We are hiring!', company_id: coId });
  ok(!t.error && t.data.post.author === 'Vélora Bikes (company)', 'create_post au nom de l\'entreprise');
  t = await tool(KT, 'create_post', { text: 'Pirate', company_id: coId });
  ok(t.error && /Only admins/.test(t.text), 'publier au nom d\'une entreprise : admins seulement');
  t = await tool(AT, 'list_posts', { scope: 'company', company_id: coId });
  ok(!t.error && t.data.posts.length === 1, 'list_posts company');
  t = await tool(AT, 'save_opportunity', { title: 'Video editor', description: 'Edit 8 reels a month for our spring campaign.', skills: ['Video editing', 'CapCut'], mode: 'hybrid', contract: 'freelance', location: 'Lyon' });
  const oppId = t.data && t.data.opportunity.id;
  ok(!t.error && oppId && t.data.opportunity.status === 'open' && t.data.opportunity.company === 'Vélora Bikes' && t.data.applications.length === 0, 'save_opportunity : publiée (entreprise déduite)', t);
  r = await karim.post('opp_apply', { id: oppId, message: 'I edit reels for two cycling brands, happy to help.' });
  t = await tool(AT, 'get_opportunity', { opportunity_id: oppId });
  const appId = t.data && t.data.applications[0] && t.data.applications[0].application_id;
  ok(r.ok && !t.error && appId && t.data.applications[0].applicant.username === 'karim-benali' && /cycling brands/.test(t.data.applications[0].message), 'get_opportunity : candidatures pour l\'entreprise');
  t = await tool(KT, 'get_opportunity', { opportunity_id: oppId });
  ok(!t.error && !t.data.applications && t.data.opportunity.you_applied === 'new', 'candidat : voit l\'offre, pas les autres candidatures');
  t = await tool(KT, 'update_application', { application_id: appId, status: 'hired' });
  ok(t.error, 'candidat : ne change pas le statut de sa candidature');
  t = await tool(AT, 'update_application', { application_id: appId, status: 'shortlisted' });
  r = await karim.get('notifications');
  ok(!t.error && t.data.applications[0].status === 'shortlisted' && JSON.stringify(r).includes('shortlisted your application'), 'update_application : candidat prévenu');
  t = await tool(AT, 'save_opportunity', { opportunity_id: oppId, status: 'closed' });
  ok(!t.error && t.data.opportunity.status === 'closed' && t.data.opportunity.title === 'Video editor' && t.data.opportunity.skills.length === 2 && t.data.opportunity.mode === 'hybrid', 'opportunité fermée, le reste conservé');
  t = await tool(AT, 'manage_company_team', { company_id: coId, action: 'add', person: '@karim-benali', role: 'member', job_title: 'Video editor' });
  ok(!t.error && t.data.team.some((x) => x.username === 'karim-benali' && x.access === 'member' && x.job_title === 'Video editor'), 'manage_company_team add');
  t = await tool(AT, 'manage_company_team', { company_id: coId, action: 'set_role', person: 'karim-benali', role: 'admin' });
  ok(!t.error && t.data.team.some((x) => x.username === 'karim-benali' && x.access === 'admin' && x.job_title === 'Video editor'), 'set_role : accès changé, intitulé conservé');
  t = await tool(KT, 'manage_company_team', { company_id: coId, action: 'set_role', person: 'karim-benali', role: 'owner' });
  ok(t.error && /Only an owner/.test(t.text), 'un admin ne se nomme pas propriétaire');
  t = await tool(KT, 'get_company', {});
  ok(!t.error && t.data.your_companies.some((c) => c.id === coId && c.your_role === 'admin'), 'get_company : entreprises de la personne avec son rôle');
  t = await tool(AT, 'manage_company_team', { company_id: coId, action: 'remove', person: '@karim-benali' });
  ok(!t.error && !t.data.team.some((x) => x.username === 'karim-benali'), 'manage_company_team remove');
  t = await tool(KT, 'manage_company_team', { company_id: coId, action: 'add', person: '@karim-benali', role: 'admin' });
  ok(t.error && /Only admins/.test(t.text), 'retiré de l\'équipe : plus aucun droit');
  t = await tool(AT, 'manage_company_team', { company_id: coId, action: 'remove', person: 'amel-haddad' });
  ok(t.error && /needs an owner/.test(t.text), 'la dernière propriétaire ne peut pas partir');
  m = await mcp(AT, 'tools/list');
  const all = m.data.result.tools;
  const annot = (n) => all.find((x) => x.name === n).annotations;
  ok(all.length === 44 && ['create_post', 'send_message', 'save_case_study', 'save_company', 'save_opportunity', 'comment_on_post', 'update_application'].every((n) => annot(n).openWorldHint === true) && annot('list_conversations').readOnlyHint === true, 'tools/list : 44 outils ; actions vues par d\'autres personnes signalées (openWorldHint)');

  /* ------------------------------------------ jetons : vie et révocation */
  step('Renouvellement et révocation');
  r = await token({ grant_type: 'refresh_token', refresh_token: RT, client_id: claude });
  ok(r.http === 200 && r.access_token && r.refresh_token && r.refresh_token !== RT, 'renouvellement : nouveaux jetons');
  const RT2 = r.refresh_token;
  const AT2 = r.access_token;
  r = await token({ grant_type: 'refresh_token', refresh_token: RT, client_id: claude });
  ok(r.http === 400 && r.error === 'invalid_grant', 'ancien jeton de renouvellement : refusé (usage unique)');
  r = await token({ grant_type: 'refresh_token', refresh_token: RT2, client_id: desk.client_id }, desk.client_id + ':' + desk.client_secret);
  ok(r.http === 400 && r.error === 'invalid_grant', 'jeton présenté par une autre application : refusé');
  ok((await tool(AT2, 'list_projects')).data.projects.length === 2, 'nouveau jeton d\'accès utilisable');
  r = await amel.get('ai_access');
  const app = r.grants.find((g) => g.kind === 'app');
  ok(r.mcp_url === MCP && r.grants.length === 1 && app && app.name === 'Claude' && app.active, 'Settings › AI assistants : application connectée listée');
  a = await authorize(amel, claude, CB);
  r = await token({ grant_type: 'authorization_code', code: a.code, redirect_uri: CB, client_id: claude, code_verifier: a.verifier });
  ok(r.http === 200 && (await amel.get('ai_access')).grants.length === 1, 'nouvelle autorisation de la même application : une seule connexion listée');
  r = await amel.post('ai_revoke', { id: app.id });
  m = await mcp(AT2, 'tools/list');
  ok(m.status === 401 && /error="invalid_token"/.test(m.headers.get('www-authenticate') || ''), 'application déconnectée : ses jetons cessent de fonctionner');
  r = await token({ grant_type: 'refresh_token', refresh_token: RT2, client_id: claude });
  ok(r.http === 400, '… y compris le renouvellement');
  r = await karim.post('ai_revoke', { id: (await karim.get('ai_access')).grants[0].id });
  ok((await mcp(KT, 'tools/list')).status === 401, 'jeton personnel supprimé : refusé');
  ok((await mcp('stm_at_' + 'a'.repeat(48), 'tools/list')).status === 401 && (await mcp('n0t-a-token', 'tools/list')).status === 401, 'jeton inventé : refusé');
  r = await karim.post('ai_revoke', { id: app.id });
  ok(r.ok, 'on ne peut pas retirer l\'accès d\'un autre (sans effet)');

  /* ------------------------------------- client confidentiel, machine locale */
  step('Application de bureau (secret, retour sur la machine locale)');
  a = await authorize(amel, desk.client_id, 'http://127.0.0.1:51734/callback');
  ok(!!a.code, 'retour vers 127.0.0.1 sur un autre port accepté (RFC 8252)');
  r = await token({ grant_type: 'authorization_code', code: a.code, redirect_uri: 'http://127.0.0.1:51734/callback', client_id: desk.client_id, code_verifier: a.verifier });
  ok(r.http === 401 && r.error === 'invalid_client', 'client confidentiel sans son secret : refusé');
  a = await authorize(amel, desk.client_id, 'http://127.0.0.1:51734/callback');
  r = await token({ grant_type: 'authorization_code', code: a.code, redirect_uri: 'http://127.0.0.1:51734/callback', code_verifier: a.verifier }, desk.client_id + ':' + desk.client_secret);
  ok(r.http === 200 && r.access_token, 'client confidentiel avec son secret (Basic) : jetons délivrés');
  r = await fetch(S + 'oauth/revoke', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Authorization: 'Basic ' + Buffer.from(desk.client_id + ':' + desk.client_secret).toString('base64') }, body: form({ token: r.access_token }) }).then((x) => x.status);
  ok(r === 200, 'révocation d\'un jeton (RFC 7009)');

  console.log('\n' + (failed ? failed + ' échec(s)' : 'Tout est bon.'));
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
