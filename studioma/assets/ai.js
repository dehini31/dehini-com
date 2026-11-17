/*
 * Studioma — assistants IA : l'écran d'accord OAuth (#/connect/<id>), où la personne autorise une
 * application comme Claude ou ChatGPT, et la page Settings › AI assistants (adresse du serveur MCP,
 * guides de connexion, applications connectées, jetons personnels).
 */
(function () {
  'use strict';
  const { html, icon, fmt } = SM;

  /* ------------------------------------------------------- écran d'accord */
  SM.route(/^\/connect\/([a-f0-9]{32})$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="auth">${SM.loading(1)}</div>`);
    let r;
    try { r = await SM.get('oauth_request', { id: m[1] }); } catch (e) {
      if (!ctx.alive()) return;
      SM.set(ctx.view, html`<div class="auth"><div class="auth-card panel">${SM.logo('logo-l')}<h1>Request expired</h1>
        <p class="muted">${e.message}</p><a class="btn btn-soft btn-block" href="#/settings/ai">AI assistants settings</a></div></div>`);
      return;
    }
    if (!ctx.alive()) return;
    const q = r.request;
    SM.set(ctx.view, html`<div class="auth"><div class="auth-card panel consent">
      <div class="consent-logos">${SM.logo('logo-l')}<span class="consent-link">${icon('link')}</span><span class="consent-app">${icon('sparkles')}</span></div>
      <h1>Connect ${q.client_name} to your Studioma account?</h1>
      <p class="muted"><b>${q.client_name}</b> will act as <b>${SM.me.name}</b>, with your role in each project, Studio and company. Afterwards you will be sent back to <b>${q.redirect_host}</b>.</p>
      <ul class="consent-list">
        <li>${icon('eye')}<span>See your projects, Studios, cards, comments and deadlines</span></li>
        <li>${icon('board')}<span>Create and update projects, Studios and cards, move cards through your workflow, comment and request approvals</span></li>
        <li>${icon('layers')}<span>Add levels and columns, and invite people — only where your role allows it</span></li>
        <li>${icon('award')}<span>Write your case studies and publish them, publish posts and comments in your name or your company's</span></li>
        <li>${icon('message')}<span>Read your private messages and send messages in your name</span></li>
        <li>${icon('building')}<span>Create and manage your company pages: team, opportunities and applications</span></li>
        <li>${icon('shield')}<span>It never sees your password, and it can't delete anything for good</span></li>
      </ul>
      ${q.already ? html`<p class="notice">${icon('info')}<span>This app is already connected. Allowing again keeps a single connection.</span></p>` : ''}
      <p class="muted small">Only allow apps you trust and started yourself. You can disconnect it at any time in Settings › AI assistants.</p>
      <div class="row end"><button class="btn btn-ghost" data-act="oauth-decide" data-id="${q.id}" data-allow="0">Deny</button>
        <button class="btn btn-primary" data-act="oauth-decide" data-id="${q.id}" data-allow="1">${icon('check')}Allow access</button></div>
    </div></div>`);
  });
  SM.actions['oauth-decide'] = async (el) => {
    SM.$$('[data-act="oauth-decide"]').forEach((b) => SM.busy(b, true));
    const r = await SM.try(SM.post('oauth_decide', { id: el.dataset.id, allow: el.dataset.allow === '1' }));
    if (!r) { SM.$$('[data-act="oauth-decide"]').forEach((b) => SM.busy(b, false)); return; }
    SM.set(SM.$('.consent'), html`${SM.logo('logo-l')}<h1>${el.dataset.allow === '1' ? 'Connected' : 'Access denied'}</h1><p class="muted">Returning to your assistant…</p>`);
    location.assign(r.redirect);
  };

  /* ----------------------------------------------- Settings › AI assistants */
  const code = (text) => html`<div class="code"><pre>${text}</pre><button class="btn-icon btn-xs" data-act="copy" data-text="${text}" title="Copy" aria-label="Copy">${icon('copy')}</button></div>`;
  let fresh = null;   // jeton personnel qui vient d'être créé : montré une seule fois
  SM.route(/^\/settings\/ai$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow"><header class="page-head"><h1>AI assistants</h1></header>${SM.loading(3)}</div>`);
    const r = await SM.get('ai_access');
    if (!ctx.alive()) return;
    const url = r.mcp_url;
    const apps = r.grants.filter((g) => g.kind === 'app');
    const tokens = r.grants.filter((g) => g.kind === 'token');
    const guide = (id, title, body, open) => html`<details class="guide" ${open ? 'open' : ''}><summary><b>${title}</b>${icon('chevron-down')}</summary><div class="guide-body">${body}</div></details>`;
    SM.set(ctx.view, html`<div class="page page-narrow ai-page">
      <a class="back" href="#/settings">${icon('arrow-left')}Settings</a>
      <header class="page-head"><div><h1>AI assistants</h1>
        <p class="muted">Let Claude, ChatGPT, Gemini and other AI assistants work with you on Studioma: plan content, create and move cards, write scripts into cards, follow deadlines and approvals, write case studies, publish posts, answer your messages and manage your company page. They act as you, with your role in each Studio and company, and can't delete anything for good.</p></div></header>
      <section class="panel pad"><h2>${icon('link')}Studioma MCP server</h2>
        <p class="muted small">Give this address to your assistant. It will ask you to sign in to Studioma and allow access.</p>
        ${code(url)}
      </section>
      <section class="panel pad"><h2>${icon('sparkles')}How to connect</h2>
        ${guide('claude', 'Claude (web, desktop and mobile apps)', html`<ol><li>In Claude, open <b>Settings › Connectors</b> and choose <b>Add custom connector</b>.</li><li>Name it <b>Studioma</b> and paste the server address above.</li><li>Click <b>Connect</b>: Studioma opens, sign in and click <b>Allow access</b>.</li></ol><p class="muted small">Then ask Claude things like “Show my overdue cards in Studioma” or “Add 5 reel ideas about spring cycling to my Social 2026 Studio”.</p>`, true)}
        ${guide('chatgpt', 'ChatGPT', html`<ol><li>In ChatGPT, open <b>Settings › Apps &amp; Connectors</b> and turn on <b>Developer mode</b> (under Advanced settings).</li><li>Create a connector named <b>Studioma</b> with the server address above, authentication <b>OAuth</b>.</li><li>Studioma opens: sign in and click <b>Allow access</b>. Enable the connector in a conversation to use it.</li></ol>`)}
        ${guide('gemini', 'Gemini CLI', html`<p>Add Studioma to <code>~/.gemini/settings.json</code>:</p>${code('{\n  "mcpServers": {\n    "studioma": { "httpUrl": "' + url + '" }\n  }\n}')}<p>Then run <code>/mcp auth studioma</code> in Gemini CLI to sign in and allow access.</p>`)}
        ${guide('code', 'Claude Code', html`${code('claude mcp add --transport http studioma ' + url)}<p>Then type <code>/mcp</code> in Claude Code, choose <b>studioma</b> and authenticate.</p>`)}
        ${guide('other', 'Other tools (Cursor, VS Code, Windsurf, scripts…)', html`<p>Use the server address above with a <b>personal token</b> (below) sent as an HTTP header:</p>${code('Authorization: Bearer <your personal token>')}`)}
      </section>
      <section class="panel pad"><h2>${icon('users')}Connected apps <span class="count">${apps.length}</span></h2>
        ${apps.length ? html`<div class="list">${apps.map((g) => html`<div class="list-row"><span class="list-ico">${icon('sparkles')}</span><span class="grow"><b>${g.name}</b>
            <small class="muted">Connected ${fmt.date(g.created)}${g.used ? ' · last used ' + fmt.ago(g.used) : ''}${g.client_uri ? ' · ' + g.client_uri.replace(/^https?:\/\//, '') : ''}</small></span>
            <button class="btn btn-ghost btn-sm" data-act="ai-revoke" data-id="${g.id}" data-name="${g.name}">Disconnect</button></div>`)}</div>`
          : html`<p class="muted small">No app connected yet. Follow one of the guides above.</p>`}
      </section>
      <section class="panel pad"><h2>${icon('lock')}Personal tokens</h2>
        <p class="muted small">For tools that ask for a token instead of signing in. A token gives the same access as your account: keep it secret, and delete it when you no longer use it.</p>
        ${fresh ? html`<div class="notice notice-warn token-once">${icon('alert')}<div class="grow"><b>Copy your token now — it won't be shown again.</b>${code(fresh)}</div></div>` : ''}
        <form class="invite-form" data-form="ai-token"><label class="field grow"><span>Name</span><input class="input" name="name" maxlength="60" required placeholder="e.g. Claude Code on my laptop" autocomplete="off"></label>
          <button class="btn btn-soft">${icon('plus')}Create token</button><p class="form-error" data-error hidden></p></form>
        ${tokens.length ? html`<div class="list">${tokens.map((g) => html`<div class="list-row"><span class="list-ico">${icon('lock')}</span><span class="grow"><b>${g.name}</b>
            <small class="muted">…${g.hint} · created ${fmt.date(g.created)} · ${g.used ? 'last used ' + fmt.ago(g.used) : 'never used'}</small></span>
            <button class="btn btn-ghost btn-sm" data-act="ai-revoke" data-id="${g.id}" data-name="${g.name}">Delete</button></div>`)}</div>` : ''}
      </section>
    </div>`);
    fresh = null;
  });
  SM.forms['ai-token'] = async (form, data) => {
    const r = await SM.post('ai_token_create', data);
    fresh = r.token;
    SM.navigate();
  };
  SM.actions['ai-revoke'] = async (el) => {
    if (!await SM.confirm({ title: `Remove “${el.dataset.name}”?`, text: 'It stops working right away. You can connect it again later.', ok: 'Remove', danger: true })) return;
    if (await SM.try(SM.post('ai_revoke', { id: +el.dataset.id }))) { SM.toast('Access removed'); SM.navigate(); }
  };
})();
