/*
 * Studioma — l'espace de travail privé : projets (possédés et rejoints), Studios, invitations,
 * membres et rôles. Le tableau d'un Studio est dans board.js.
 */
(function () {
  'use strict';
  const { html, raw, icon, fmt } = SM;

  /* ------------------------------------------------------- blocs partagés */
  SM.templatePicker = (selected) => html`<div class="tpls">${SM.ref.templates.map((t) => html`
    <label class="tpl"><input type="radio" name="template" value="${t.key}" ${t.key === selected ? 'checked' : ''}>
      <span class="tpl-box"><b>${t.label}</b><small>${t.about}</small>
        ${t.levels.length ? html`<span class="tpl-levels">${t.levels.map((l) => html`<span class="tpl-level c-${l.color}"><em>${l.icon} ${l.name}</em>${l.cols.map((c) => html`<i>${c}</i>`)}</span>`)}</span>` : html`<span class="tpl-blank">${icon('plus')}Your own levels and columns</span>`}
      </span></label>`)}</div>`;
  SM.swatches = (name, selected) => html`<div class="swatches">${SM.ref.colors.map((c) => html`<label class="swatch c-${c}" title="${c}"><input type="radio" name="${name}" value="${c}" ${c === selected ? 'checked' : ''}><span></span></label>`)}</div>`;
  SM.roleBadge = (role) => (role ? html`<span class="role role-${role}">${SM.ref.roles[role] || role}</span>` : '');
  SM.projectMark = (p, cls) => (p.logo
    ? html`<span class="pmark ${cls || ''}"><img src="${SM.fileUrl(p.logo, true)}" alt=""></span>`
    : html`<span class="pmark c-${p.color} ${cls || ''}">${SM.initials(p.name).slice(0, 2)}</span>`);
  const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);

  /** Carte d'un Studio : son parcours niveau par niveau et sa progression. */
  SM.studioCard = (s, project) => {
    const active = s.cards - s.cancelled;
    return html`<a class="panel scard" href="#/studio/${s.id}">
      <header><span class="scard-ico">${icon('board')}</span><div class="grow"><b>${s.name}</b>${project ? html`<small class="muted">${project.name}</small>` : ''}</div>${s.archived ? html`<span class="chip">Archived</span>` : ''}</header>
      ${s.levels.length ? html`<div class="flow">${s.levels.map((l, i) => html`${i ? html`<span class="flow-arrow">${icon('chevron-right')}</span>` : ''}<span class="flow-step c-${l.color}" title="${l.name}"><em>${l.icon}</em><span>${l.name}</span><b>${l.n}</b></span>`)}</div>` : html`<p class="muted small">No level yet — open the Studio to design its workflow.</p>`}
      <footer><div class="progress"><i style="width:${pct(s.done, active)}%"></i></div><small class="muted">${s.done} of ${active} done</small></footer>
    </a>`;
  };

  /* ----------------------------------------------------- espace de travail */
  let ws = null;
  /** Projets du compte (gardés en mémoire pour les menus de changement rapide). */
  SM.workspace = async (fresh) => {
    if (!ws || fresh) ws = await SM.get('workspace', { archived: SM.ls.get('archived', false) });
    return ws;
  };
  SM.dropWorkspace = () => { ws = null; };
  const inviteCard = (i) => html`<article class="panel invite-card">
      <span class="pmark c-${i.project_color}">${icon('mail')}</span>
      <div class="grow"><b>${i.studio_name || i.project_name}</b>
        <p class="muted small">${i.by ? i.by.name : 'Someone'} invites you ${i.studio_name ? html`to this Studio of <b>${i.project_name}</b>` : 'to this project'} as <b>${SM.ref.roles[i.role]}</b>.</p>
        ${i.message ? html`<p class="small invite-note">“${i.message}”</p>` : ''}</div>
      <div class="row"><button class="btn btn-ghost btn-sm" data-act="invite-decline" data-token="${i.token}">Decline</button><button class="btn btn-primary btn-sm" data-act="invite-accept" data-token="${i.token}">Accept</button></div>
    </article>`;
  const taskRow = (c) => {
    const due = fmt.due(c.due);
    return html`<a class="task" href="#/studio/${c.studio_id}/card/${c.id}">
      <span class="task-dot c-${c.level_color}"></span>
      <span class="grow"><b>${c.title}</b><small class="muted">${c.project_name} · ${c.col_name}</small></span>
      ${c.due ? html`<span class="due due-${due}">${icon('calendar')}${fmt.day(c.due)}</span>` : ''}</a>`;
  };
  const projectCard = (p) => html`<article class="panel pcard${p.archived ? ' is-archived' : ''}">
      <header><a class="row grow" href="#/project/${p.id}">${SM.projectMark(p)}<div class="grow"><h3>${p.name}</h3><small class="muted">${p.company ? p.company.name + ' · ' : ''}${p.limited ? 'Invited to a Studio' : SM.ref.roles[p.role] || ''}${p.archived ? ' · Archived' : ''}</small></div></a>
        ${!p.limited && (p.role === 'owner' || p.role === 'manager') ? html`<button class="btn-icon" data-act="studio-new" data-project="${p.id}" title="New Studio" aria-label="New Studio in ${p.name}">${icon('plus')}</button>` : ''}</header>
      ${p.description ? html`<p class="muted small clamp-2">${p.description}</p>` : ''}
      <div class="pcard-studios">${p.studios.length ? p.studios.map((s) => {
        const active = s.cards - s.cancelled;
        return html`<a class="studio-pill" href="#/studio/${s.id}">${icon('board')}<span class="grow">${s.name}</span><small class="muted">${active ? pct(s.done, active) + '%' : 'New'}</small><span class="mini-progress"><i style="width:${pct(s.done, active)}%"></i></span></a>`;
      }) : html`<p class="muted small">No Studio yet.</p>`}</div>
    </article>`;

  SM.route(/^\/projects$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page"><header class="page-head"><h1>Projects</h1></header>${SM.loading(3)}</div>`);
    const w = await SM.workspace(true);
    if (!ctx.alive()) return;
    const owned = w.projects.filter((p) => p.role === 'owner');
    const joined = w.projects.filter((p) => p.role !== 'owner');
    const showArchived = SM.ls.get('archived', false);
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Projects</h1><p class="muted">Your private workspace. A project is a brand, a client or a campaign; each one holds its Studios.</p></div>
        <button class="btn btn-primary" data-act="project-new">${icon('plus')}New project</button></header>
      ${SM.promoBanner ? SM.promoBanner() : ''}
      ${w.invites.length ? html`<section><h2 class="section-title">${icon('mail')}Team invitations</h2><div class="stack">${w.invites.map(inviteCard)}</div></section>` : ''}
      <div class="with-rail">
        <div class="stack-l">
          ${!w.projects.length ? SM.empty('folder', 'Create your first project', 'Start with a brand, a client or a campaign. Your first Studio comes with a ready-to-use content workflow.', html`<button class="btn btn-primary" data-act="project-new">${icon('plus')}New project</button>`) : ''}
          ${owned.length ? html`<section><h2 class="section-title">Owned by me</h2><div class="grid grid-2">${owned.map(projectCard)}</div></section>` : ''}
          ${joined.length ? html`<section><h2 class="section-title">Joined</h2><div class="grid grid-2">${joined.map(projectCard)}</div></section>` : ''}
          <label class="check muted small"><input type="checkbox" data-change="show-archived" ${showArchived ? 'checked' : ''}><span>Show archived projects and Studios</span></label>
        </div>
        <aside class="rail">
          <section class="panel pad"><header class="row between"><h3>${icon('checklist')}Assigned to me</h3><a class="small" href="#/work">My work</a></header>
            ${w.tasks.length ? html`<div class="tasks">${w.tasks.map(taskRow)}</div>` : html`<p class="muted small">Nothing assigned to you right now. Cards assigned to you in any Studio appear here with their deadline.</p>`}
          </section>
        </aside>
      </div>
    </div>`);
  });
  SM.changes['show-archived'] = (el) => { SM.ls.set('archived', el.checked); SM.navigate(); };
  SM.actions['project-new'] = () => SM.projectDialog();
  SM.actions['studio-new'] = (el) => SM.studioDialog(+el.dataset.project || 0);

  SM.route(/^\/studios$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page"><header class="page-head"><h1>Studios</h1></header>${SM.loading(3)}</div>`);
    const w = await SM.workspace(true);
    if (!ctx.alive()) return;
    const groups = w.projects.filter((p) => p.studios.length);
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Studios</h1><p class="muted">Every workspace you can access, across all your projects.</p></div>
        <button class="btn btn-primary" data-act="studio-new">${icon('plus')}New Studio</button></header>
      <div class="tabs"><a class="tab is-active" href="#/studios">${icon('board')}Studios</a><a class="tab" href="#/work">${icon('checklist')}My work</a></div>
      ${groups.length ? groups.map((p) => html`<section><h2 class="section-title"><a class="row" href="#/project/${p.id}">${SM.projectMark(p, 'pmark-s')}${p.name}</a></h2>
          <div class="grid grid-3">${p.studios.map((s) => SM.studioCard(s))}</div></section>`)
        : SM.empty('board', 'No Studio yet', 'A Studio is a private board where ideas become scripts, scripts become content, and content gets published.', html`<button class="btn btn-primary" data-act="studio-new">${icon('plus')}Create a Studio</button>`)}
    </div>`);
  });
  SM.route(/^\/work$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>My work</h1><p class="muted">Cards assigned to you across every project and Studio.</p></div></header>
      <div class="tabs"><a class="tab" href="#/studios">${icon('board')}Studios</a><a class="tab is-active" href="#/work">${icon('checklist')}My work</a></div>
      <div id="mywork">${SM.loading(4)}</div></div>`);
    await SM.renderMyWork(SM.$('#mywork'), ctx);
  });

  /* ----------------------------------------------------------- un projet */
  SM.route(/^\/project\/(\d+)$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page">${SM.loading(3)}</div>`);
    const r = await SM.get('project_get', { id: m[1] });
    if (!ctx.alive()) return;
    const p = r.project;
    const manage = p.role === 'owner' || p.role === 'manager';
    SM.project = p;
    SM.set(ctx.view, html`<div class="page">
      <a class="back" href="#/projects">${icon('arrow-left')}Projects</a>
      <header class="page-head project-head">
        <div class="row grow">${SM.projectMark(p, 'pmark-l')}<div><h1>${p.name}</h1>
          <p class="muted">${p.company ? html`<a href="#/c/${p.company.slug}">${p.company.name}</a> · ` : ''}${p.limited ? 'You are invited to part of this project' : html`You are ${SM.ref.roles[p.role]}`}${p.archived ? ' · Archived' : ''}</p>
          ${p.completed_at ? html`<span class="chip chip-ok">${icon('flag')}Completed ${fmt.date(p.completed_at)}</span>` : ''}</div></div>
        ${manage ? html`<div class="row"><button class="btn btn-soft" data-act="share" data-project="${p.id}">${icon('user-plus')}Invite</button>
          <button class="btn btn-primary" data-act="studio-new" data-project="${p.id}">${icon('plus')}New Studio</button>
          <button class="btn-icon" data-act="project-menu" aria-label="Project options">${icon('more')}</button></div>`
          : html`<button class="btn btn-ghost" data-act="leave" data-project="${p.limited ? 0 : p.id}" ${p.limited ? 'hidden' : ''}>Leave project</button>`}
      </header>
      ${p.description ? html`<p class="project-about">${p.description}</p>` : ''}
      <div class="with-rail">
        <section><h2 class="section-title">${icon('board')}Studios</h2>
          ${p.studios.length ? html`<div class="grid grid-2">${p.studios.map((s) => SM.studioCard(s))}</div>`
            : SM.empty('board', 'No Studio in this project yet', manage ? 'Create the first one from a template or from scratch.' : 'Studios you are invited to will appear here.', manage ? html`<button class="btn btn-primary" data-act="studio-new" data-project="${p.id}">${icon('plus')}New Studio</button>` : '')}
        </section>
        ${r.members ? html`<aside class="rail"><section class="panel pad">
          <header class="row between"><h3>${icon('users')}Members <span class="count">${r.members.length}</span></h3>${manage ? html`<button class="btn btn-soft btn-sm" data-act="share" data-project="${p.id}">Manage</button>` : ''}</header>
          <div class="members">${r.members.map((u) => html`<a class="member" href="#/u/${u.handle}">${SM.avatar(u, 'av-m')}<span class="grow"><b>${u.name}</b><small class="muted">${u.title || '@' + u.handle}</small></span>${SM.roleBadge(u.role)}</a>`)}</div>
          ${r.invites && r.invites.length ? html`<p class="muted small">${fmt.plural(r.invites.length, 'pending invitation')}</p>` : ''}
        </section></aside>` : ''}
      </div>
    </div>`);
  });
  SM.actions['project-menu'] = (el) => {
    const p = SM.project;
    SM.menu(el, [
      { label: 'Edit project', icon: 'edit', act: () => SM.projectDialog(p) },
      // Projet terminé (parcours du créateur, assets/journey.js) : critères vérifiés par le serveur.
      p.completion && SM.projectCompleteDialog ? { label: p.completed_at ? 'Reopen project' : 'Mark as completed…', icon: 'flag', act: () => SM.projectCompleteDialog(p) } : null,
      { label: p.archived ? 'Restore project' : 'Archive project', icon: p.archived ? 'restore' : 'archive', act: async () => {
        if (await SM.try(SM.post('project_archive', { id: p.id, archived: !p.archived }))) { SM.toast(p.archived ? 'Project restored' : 'Project archived. Find it with “Show archived”.'); SM.dropWorkspace(); p.archived ? SM.navigate() : SM.go('/projects'); }
      } },
      { label: 'Leave project', icon: 'logout', act: () => SM.actions.leave({ dataset: { project: p.id } }) },
      p.role === 'owner' ? { sep: true } : null,
      p.role === 'owner' ? { label: 'Delete project…', icon: 'trash', danger: true, act: async () => {
        const ok = await SM.confirm({ title: 'Delete this project?', text: `“${p.name}”, its Studios, cards, comments and attachments will be deleted for everyone. This cannot be undone — archive it instead if you may need it again.`, type: p.name, ok: 'Delete project', danger: true });
        if (ok && await SM.try(SM.post('project_delete', { id: p.id, confirm: p.name }))) { SM.toast('Project deleted'); SM.dropWorkspace(); SM.go('/projects'); }
      } } : null,
    ].filter(Boolean), { align: 'right' });
  };
  SM.actions.leave = async (el) => {
    const body = +el.dataset.studio ? { studio_id: +el.dataset.studio } : { project_id: +el.dataset.project };
    if (!await SM.confirm({ title: body.studio_id ? 'Leave this Studio?' : 'Leave this project?', text: 'You will lose access until someone invites you again.', ok: 'Leave', danger: true })) return;
    if (await SM.try(SM.post('member_remove', body))) { SM.toast('You left'); SM.dropWorkspace(); SM.go('/projects'); }
  };

  /* ------------------------------------------------------------- fenêtres */
  SM.projectDialog = (p) => {
    const companies = SM.adminCompanies();
    SM.modal(html`${SM.modalHead(p ? 'Edit project' : 'New project', p ? '' : 'A project is a brand, a client or a campaign.')}
      <form class="modal-body stack" data-form="project-save">
        ${p ? html`<input type="hidden" name="id" value="${p.id}">` : ''}
        <label class="field"><span>Name</span><input class="input" name="name" maxlength="80" required value="${p ? p.name : ''}" placeholder="e.g. Vélora — social media" autofocus></label>
        <label class="field"><span>Description <em>optional</em></span><textarea class="input" name="description" rows="2" maxlength="1000" placeholder="What is this project about?">${p ? p.description : ''}</textarea></label>
        <div class="field"><span>Color</span>${SM.swatches('color', p ? p.color : 'iris')}</div>
        ${!p && companies.length ? html`<label class="field"><span>Owner</span><select class="input" name="company_id"><option value="0">Me — personal project</option>${companies.map((c) => html`<option value="${c.id}">${c.name}</option>`)}</select></label>` : ''}
        ${!p ? html`<label class="field"><span>First Studio</span><input class="input" name="studio_name" maxlength="80" value="Content Studio"></label>
          <div class="field"><span>Workflow</span>${SM.templatePicker('content')}</div>` : ''}
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">${p ? 'Save' : 'Create project'}</button></footer>
      </form>`, { size: p ? 'm' : 'l' });
  };
  SM.forms['project-save'] = async (form, data) => {
    const r = await SM.post('project_save', data);
    SM.closeModals();
    SM.dropWorkspace();
    if (data.id) { SM.toast('Project saved'); SM.navigate(); } else { SM.toast('Project created'); SM.go(r.studio_id ? '/studio/' + r.studio_id : '/project/' + r.id); }
  };
  SM.studioDialog = async (projectId) => {
    const w = await SM.try(SM.workspace());
    if (!w) return;
    const projects = w.projects.filter((p) => !p.archived && (p.role === 'owner' || p.role === 'manager'));
    if (!projects.length) { SM.projectDialog(); return; }
    SM.modal(html`${SM.modalHead('New Studio', 'A private workspace inside a project.')}
      <form class="modal-body stack" data-form="studio-create">
        <div class="field-row"><label class="field"><span>Project</span><select class="input" name="project_id">${projects.map((p) => html`<option value="${p.id}" ${p.id === projectId ? 'selected' : ''}>${p.name}</option>`)}</select></label>
        <label class="field"><span>Studio name</span><input class="input" name="name" maxlength="80" required placeholder="e.g. Instagram 2026" autofocus></label></div>
        <div class="field"><span>Start from</span>${SM.templatePicker('content')}</div>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">Create Studio</button></footer>
      </form>`, { size: 'l' });
  };
  SM.forms['studio-create'] = async (form, data) => {
    const r = await SM.post('studio_create', data);
    SM.closeModals();
    SM.dropWorkspace();
    SM.toast('Studio created');
    SM.go('/studio/' + r.id);
  };

  /* --------------------------------------------- partage : membres, invitations */
  let share = null;
  const roleSelect = (name, value, mine, attrs) => html`<select class="input input-sm" name="${name}" ${raw(attrs || '')}>${Object.entries(SM.ref.roles).reverse().map(([k, l]) => (ROLE_RANK[k] <= ROLE_RANK[mine] ? html`<option value="${k}" ${k === value ? 'selected' : ''}>${l}</option>` : ''))}</select>`;
  const ROLE_RANK = { viewer: 1, client: 2, collaborator: 3, creator: 4, manager: 5, owner: 6 };
  const ROLE_HELP = {
    owner: 'Full control, including deleting the project.', manager: 'Manages the workflow, the members and every card.', creator: 'Creates, edits and moves every card.',
    collaborator: 'Works on the cards assigned to them, comments everywhere.', client: 'Views, comments, approves or requests changes.', viewer: 'Read-only access.',
  };
  /** Fenêtre « Partager » d'un Studio ou d'un projet. */
  SM.shareDialog = async (scope, opts) => {
    opts = opts || {};
    share = { scope, tab: scope.studio_id && opts.tab === 'guests' ? 'guests' : 'team', req: opts.req || 0, do: opts.do || '', modal: SM.modal(html`${SM.modalHead('Share')}<div class="modal-body">${SM.loading(3)}</div>`, { size: 'l' }) };
    await drawShare();
  };
  /** Onglets d'un Studio : son équipe (comptes Studioma) et ses invités sans compte (liens de partage). */
  const shareTabs = () => (share.scope.studio_id && !share.scope.card_id ? html`<div class="tabs share-tabs" role="tablist">
      <button class="tab${share.tab === 'team' ? ' is-active' : ''}" data-act="share-tab" data-tab="team" role="tab" aria-selected="${share.tab === 'team'}">${icon('users')}Team</button>
      <button class="tab${share.tab === 'guests' ? ' is-active' : ''}" data-act="share-tab" data-tab="guests" role="tab" aria-selected="${share.tab === 'guests'}">${icon('globe')}Guests without an account${share.pending ? html`<span class="nav-badge">${share.pending}</span>` : ''}</button>
    </div>` : '');
  SM.actions['share-tab'] = (el) => { share.tab = el.dataset.tab; drawShare(); };
  SM.shareState = () => share;
  SM.shareTabsHtml = shareTabs;
  async function drawShare() {
    if (share.tab === 'guests') { await SM.drawGuests(); return; }
    const { scope, modal } = share;
    let data;
    if (scope.studio_id) data = await SM.try(SM.get('members', { studio_id: scope.studio_id }));
    else {
      const r = await SM.try(SM.get('project_get', { id: scope.project_id }));
      data = r && { members: r.members || [], invites: r.invites, role: r.project.role, name: r.project.name };
    }
    if (!data || !document.contains(modal.el)) return;
    const mine = data.role;
    const manage = ROLE_RANK[mine] >= 5;
    share.data = data;
    const links = (data.invites || []).filter((i) => i.kind === 'link');
    const people = (data.invites || []).filter((i) => i.kind === 'person');
    SM.set(modal.el, html`${SM.modalHead(scope.studio_id ? 'Share this Studio' : 'Share this project', scope.studio_id ? 'People invited here only see this Studio. Invite from the project page to give access to all its Studios.' : 'Members of the project access all its Studios.')}
      <div class="modal-body stack-l">${shareTabs()}
        ${manage ? html`<form data-form="invite-email" class="invite-form">
            <label class="field grow"><span>Invite by email</span><input class="input" type="email" name="email" placeholder="name@example.com" required autocomplete="off"></label>
            <label class="field"><span>Role</span>${roleSelect('role', 'creator', mine, 'data-change="role-help"')}</label>
            <button class="btn btn-primary">${icon('send')}Send invite</button>
            <p class="muted small invite-help" data-role-help>${ROLE_HELP.creator}</p>
            <p class="form-error" data-error hidden></p>
          </form>
          <div class="invite-link"><div class="grow"><b>${icon('link')}Invitation link</b><p class="muted small">Anyone with the link can join with the chosen role. Valid 14 days; revoke it at any time.</p></div>
            ${roleSelect('link_role', 'collaborator', mine, 'id="link-role" aria-label="Role for the link"')}<button class="btn btn-soft" data-act="invite-link">Create link</button></div>
          ${links.length ? html`<div class="list">${links.map((i) => html`<div class="list-row"><span class="list-ico">${icon('link')}</span><span class="grow"><b>Link · ${SM.ref.roles[i.role]}</b><small class="muted">${fmt.plural(i.uses, 'person', 'people')} joined · expires ${fmt.date(i.expires)}</small></span>
            <button class="btn btn-soft btn-sm" data-act="copy" data-text="${i.url}">${icon('copy')}Copy</button><button class="btn-icon" data-act="invite-revoke" data-id="${i.id}" title="Revoke" aria-label="Revoke link">${icon('trash')}</button></div>`)}</div>` : ''}
          ${people.length ? html`<div><h3 class="section-title">Pending invitations</h3><div class="list">${people.map((i) => html`<div class="list-row">${i.user ? SM.avatar(i.user, 'av-m') : html`<span class="list-ico">${icon('mail')}</span>`}<span class="grow"><b>${i.user ? i.user.name : i.email}</b><small class="muted">${SM.ref.roles[i.role]}${i.studio_name && !scope.studio_id ? ' · ' + i.studio_name : ''} · sent ${fmt.ago(i.created)}</small></span>
            <button class="btn btn-soft btn-sm" data-act="copy" data-text="${i.url}">${icon('copy')}Copy link</button><button class="btn-icon" data-act="invite-revoke" data-id="${i.id}" title="Cancel invitation" aria-label="Cancel invitation">${icon('x')}</button></div>`)}</div></div>` : ''}` : ''}
        <div><h3 class="section-title">Members <span class="count">${data.members.length}</span></h3>
          <div class="list">${data.members.map((u) => {
            const direct = scope.studio_id ? u.via === 'studio' : u.via === 'project';
            const editable = manage && direct && ROLE_RANK[u.role] <= ROLE_RANK[mine];
            return html`<div class="list-row">${SM.avatar(u, 'av-m')}<span class="grow"><b>${u.name}${u.id === SM.me.id ? ' (you)' : ''}</b><small class="muted">${u.title || '@' + u.handle}${!direct ? ' · via the ' + (u.via === 'company' ? 'company' : 'project') : ''}</small></span>
              ${editable ? html`${roleSelect('role', u.role, mine, `data-change="member-role" data-uid="${u.id}" aria-label="Role of ${SM.esc(u.name)}"`)}
                <button class="btn-icon" data-act="member-remove" data-uid="${u.id}" data-name="${u.name}" title="Remove" aria-label="Remove ${u.name}">${icon('x')}</button>` : SM.roleBadge(u.role)}</div>`;
          })}</div></div>
      </div>`);
  }
  /** Partager une seule carte (Share & publish) : la même fenêtre, limitée aux invités de cette carte. */
  SM.cardShareDialog = (studioId, cardId, opts) => SM.shareDialog({ studio_id: studioId, card_id: cardId }, Object.assign({}, opts || {}, { tab: 'guests' }));
  SM.actions.share = (el) => SM.shareDialog(+el.dataset.project ? { project_id: +el.dataset.project } : { studio_id: +el.dataset.studio || SM.board.id });
  SM.changes['role-help'] = (el) => { SM.set(SM.$('[data-role-help]', el.form), ROLE_HELP[el.value]); };
  SM.forms['invite-email'] = async (form, data) => {
    const r = await SM.post('invite_create', Object.assign({}, share.scope, data));
    SM.toast('Invitation sent to ' + data.email);
    form.reset();
    await drawShare();
    return r;
  };
  SM.actions['invite-link'] = async (el) => {
    SM.busy(el, true);
    const r = await SM.try(SM.post('invite_create', Object.assign({}, share.scope, { role: SM.$('#link-role').value })));
    SM.busy(el, false);
    if (!r) return;
    await SM.copy(r.invite.url, 'Invitation link copied');
    await drawShare();
  };
  SM.actions.copy = (el) => SM.copy(el.dataset.text);
  SM.actions['invite-revoke'] = async (el) => { if (await SM.try(SM.post('invite_revoke', { id: +el.dataset.id }))) { SM.toast('Invitation revoked'); drawShare(); } };
  SM.changes['member-role'] = async (el) => {
    const r = await SM.try(SM.post('member_role', Object.assign({}, share.scope, { uid: +el.dataset.uid, role: el.value })));
    if (r) SM.toast('Role updated');
    drawShare();
  };
  SM.actions['member-remove'] = async (el) => {
    if (!await SM.confirm({ title: `Remove ${el.dataset.name}?`, text: 'They lose access right away. The cards assigned to them become unassigned.', ok: 'Remove', danger: true })) return;
    if (await SM.try(SM.post('member_remove', Object.assign({}, share.scope, { uid: +el.dataset.uid })))) { SM.toast('Member removed'); drawShare(); }
  };
})();

/*
 * Studioma — partage d'un Studio avec des invités sans compte (onglet « Guests without an account » de
 * la fenêtre Partager) : lien public, lien privé, demandes de droits, invités et leurs droits, journal.
 * Les règles sont appliquées par le serveur (lib/share.php) ; cet écran ne fait que les régler.
 */
(function () {
  'use strict';
  const { html, icon, fmt } = SM;
  const GROLES = { viewer: 'Viewer', commenter: 'Commenter', editor: 'Editor' };
  const GHELP0 = { viewer: 'Views what the link shows.', commenter: 'Views, comments, approves the cards you send them.', editor: 'Edits the content you open to them.' };
  const GHELP1 = { viewer: 'Views this card.', commenter: 'Views this card, comments if you show comments, approves it when asked.', editor: 'Edits this card only.' };
  const LINK0 = {
    public: ['globe', 'Public link', 'Anyone with the link can view, read-only, without an account. To comment or edit, they verify their email and ask you.'],
    private: ['lock', 'Private link', 'Only the people you add below can open it, after confirming their email address with a one-time code.'],
  };
  const LINK1 = {
    public: ['globe', 'Public link', 'Anyone with the link can view this card only, read-only, without an account. To comment or edit, they verify their email and ask you.'],
    private: ['lock', 'Private link', 'Only the people you add below can open this card, after confirming their email address with a one-time code.'],
  };
  const isCard = () => !!(share() && share().scope.card_id);
  const GHELP = new Proxy({}, { get: (t, k) => (isCard() ? GHELP1 : GHELP0)[k] });
  const LINK = new Proxy({}, { get: (t, k) => (isCard() ? LINK1 : LINK0)[k] });
  const STATE = new Proxy({}, { get: (t, k) => ({ disabled: 'Off', expired: 'Expired', unavailable: isCard() ? 'Card archived' : 'Studio archived' })[k] });
  let G = null;   // dernière réponse de share_list
  const share = () => SM.shareState();
  const roleSel = (name, value, attrs) => html`<select class="input input-sm" name="${name}" ${SM.raw(attrs || '')}>${Object.entries(GROLES).map(([k, l]) => html`<option value="${k}" ${k === value ? 'selected' : ''}>${l}</option>`)}</select>`;
  const ymd = (ts) => { if (!ts) return ''; const d = new Date(ts * 1000); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
  const levelName = (id) => { const l = G.levels.find((x) => x.id === id); return l ? `${l.icon} ${l.name}` : ''; };

  /**
   * Le lien, présenté comme ce qu'il est : chiffré (https), avec une clé impossible à deviner, masquée à
   * l'écran (« Show » l'affiche ; « Copy link » copie toujours le lien entier).
   */
  function secureLink(kind, url, title) {
    const u = new URL(url);
    const key = u.pathname.split('/').pop();
    const base = u.host + u.pathname.slice(0, -key.length);
    const https = u.protocol === 'https:';
    return html`<div class="secure-link${https ? '' : ' is-plain'}" data-link-field>
        <span class="sl-lock" title="${https ? 'Encrypted connection (HTTPS)' : 'Not encrypted: this is a local test server'}">${icon(https ? 'lock' : 'alert')}</span>
        <span class="sl-url" title="${title}"><span class="sl-base">${https ? '' : 'http://'}${base}</span><span class="sl-key sl-masked">${key.slice(0, 5)}<i>••••••••</i>${key.slice(-4)}</span><span class="sl-key sl-full">${key}</span></span>
        <button type="button" class="btn btn-ghost btn-sm sl-show" data-act="glink-reveal" aria-pressed="false">${icon('eye')}<span>Show</span></button>
        <button class="btn btn-primary btn-sm" data-act="copy" data-text="${url}">${icon('copy')}Copy link</button>
      </div>
      <ul class="sl-facts">
        <li class="${https ? 'is-ok' : 'is-warn'}">${icon(https ? 'lock' : 'alert')}${https ? 'Encrypted (HTTPS)' : 'Not encrypted on this test server'}</li>
        <li class="is-ok">${icon('shield')}Private 192-bit key — impossible to guess</li>
        <li class="is-ok">${icon(kind === 'private' ? 'mail' : 'eye')}${kind === 'private' ? 'Opens only for verified emails on your list' : 'Read-only until you approve'}</li>
      </ul>`;
  }
  SM.actions['glink-reveal'] = (el) => {
    const box = el.closest('[data-link-field]');
    const on = !box.classList.contains('is-revealed');
    box.classList.toggle('is-revealed', on);
    el.setAttribute('aria-pressed', String(on));
    SM.set(SM.$('span', el), on ? 'Hide' : 'Show');
  };
  function linkCard(kind) {
    const l = G.links[kind];
    const [ic, title, help] = LINK[kind];
    if (!l) {
      return html`<div class="link-card is-new"><header>${icon(ic)}<b>${title}</b></header><p class="muted small">${help}</p>
        <button class="btn btn-soft btn-sm" data-act="glink-create" data-kind="${kind}">${icon('link')}Create a ${kind} link</button></div>`;
    }
    const off = l.state !== '';
    return html`<div class="link-card${off ? ' is-off' : ''}" data-link="${kind}">
      <header>${icon(ic)}<b>${title}</b>${off ? html`<span class="chip">${STATE[l.state]}</span>` : html`<span class="chip chip-on">On</span>`}<span class="grow"></span>
        <input type="checkbox" class="switch" title="${l.active ? 'Turn the link off' : 'Turn the link on'}" data-change="glink-active" data-kind="${kind}" ${l.active ? 'checked' : ''} aria-label="${title} on"></header>
      <p class="muted small">${help}</p>
      ${secureLink(kind, l.url, title)}
      <div class="link-opts">
        ${isCard() ? '' : html`<button class="btn btn-ghost btn-sm" data-act="glink-levels" data-kind="${kind}" title="Levels the link shows">${icon('layers')}${l.levels.length ? l.levels.map(levelName).join(', ') : 'All levels'}</button>`}
        <label class="btn btn-ghost btn-sm link-exp" title="The link stops working after this day">${icon('calendar')}<span>${l.expires ? 'Expires ' + fmt.date(l.expires) : 'No expiry'}</span><input type="date" class="input input-xs" value="${ymd(l.expires)}" min="${ymd(Date.now() / 1000 + 86400)}" data-change="glink-expires" data-kind="${kind}" aria-label="Expiry date"></label>
        ${l.expires ? html`<button class="btn btn-ghost btn-sm" data-act="glink-noexp" data-kind="${kind}">Remove expiry</button>` : ''}
        <button class="btn btn-ghost btn-sm" data-act="glink-regen" data-kind="${kind}" title="Create a new link: the current one stops working">${icon('restore')}New link</button>
      </div>
      ${kind === 'private' ? html`<form class="guest-add" data-form="guest-add">
        <label class="field grow"><span>Add people</span><input class="input input-sm" name="emails" placeholder="client@brand.com, boss@brand.com" required autocomplete="off"></label>
        <label class="field"><span>Access</span>${roleSel('role', 'viewer')}</label>
        <label class="check"><input type="checkbox" name="send" checked>Email them the link</label>
        <button class="btn btn-soft btn-sm">${icon('user-plus')}Add</button>
        <p class="form-error" data-error hidden></p></form>` : ''}
    </div>`;
  }
  function requestRow(g) {
    const r = g.request;
    return html`<div class="list-row guest-req${share().req === g.id ? ' is-hl' : ''}" data-guest="${g.id}">${SM.avatar({ name: g.email }, 'av-m')}
      <span class="grow"><b>${g.email}</b><small class="muted">asks for <b>${GROLES[r.role].toLowerCase()}</b> access · ${g.kind} link · ${fmt.ago(r.at)}${r.note ? html` — “${r.note}”` : ''}</small></span>
      <select class="input input-sm" data-req-role="${g.id}" aria-label="Access to give">${['commenter', 'editor'].map((k) => html`<option value="${k}" ${k === r.role ? 'selected' : ''}>${GROLES[k]}</option>`)}</select>
      <button class="btn btn-primary btn-sm" data-act="guest-approve" data-id="${g.id}">${icon('check')}Approve</button>
      <button class="btn btn-ghost btn-sm" data-act="guest-reject" data-id="${g.id}">Reject</button></div>`;
  }
  function guestRow(g) {
    const st = g.status === 'revoked' ? html`<span class="chip chip-off">Access revoked</span>` : g.status === 'invited' ? 'Invited — email not verified yet' : 'Active' + (g.last_seen ? ' · seen ' + fmt.ago(g.last_seen) : '');
    return html`<div class="list-row guest-row${g.status === 'revoked' ? ' is-off' : ''}">${SM.avatar({ name: g.email }, 'av-m')}
      <span class="grow"><b>${g.email}</b><small class="muted">${g.kind === 'public' ? 'Public' : 'Private'} link · ${st}</small></span>
      ${g.status === 'revoked' ? '' : html`${roleSel('role', g.role, `data-change="guest-role" data-id="${g.id}" aria-label="Access of ${SM.esc(g.email)}" title="${SM.esc(GHELP[g.role])}"`)}
        ${g.role === 'editor' && !isCard() ? html`<button class="btn btn-ghost btn-sm" data-act="guest-scope" data-id="${g.id}" title="Choose what they can edit">${icon(g.limited ? 'filter' : 'edit')}${g.limited ? 'Limited editing' : 'Edits everything shared'}</button>` : ''}`}
      <button class="btn-icon" data-act="guest-more" data-id="${g.id}" aria-label="More for ${g.email}">${icon('more')}</button></div>`;
  }
  /** Lien d'une carte : ce que les invités voient en plus de la carte (pièces jointes cochées, commentaires). */
  function cardSees() {
    const l = G.links.public || G.links.private;
    if (!G.card || !l) return '';
    const kind = G.links.public ? 'public' : 'private';
    return html`<section class="panel pad card-sees"><h3 class="section-title">${icon('eye')}What guests see on this card</h3>
      <p class="muted small">The title, description, checklist and stage of the card, always. Never its history, the rest of the Studio or your team list. Choose what else to show:</p>
      <div class="stack-s">
        ${G.files.length ? G.files.map((f) => html`<label class="check"><input type="checkbox" data-change="gsee-file" data-kind="${kind}" value="${f.id}" ${G.opts.files.includes(f.id) ? 'checked' : ''}>${icon(f.kind === 'image' ? 'image' : f.kind === 'video' ? 'video' : 'file')}${f.name}</label>`)
          : html`<p class="muted small">${icon('paperclip')}No attachment on this card.</p>`}
        <label class="check"><input type="checkbox" data-change="gsee-comments" data-kind="${kind}" ${G.opts.comments ? 'checked' : ''}>${icon('comment')}Show the comments (and let commenters and editors reply)</label>
      </div>
      <p class="muted small privacy-note">${icon('lock')}Unticked attachments can't be opened by guests, even with the file address.</p></section>`;
  }
  async function drawGuests() {
    const st = share();
    const r = await SM.try(SM.get('share_list', { studio: st.scope.studio_id, card: st.scope.card_id || 0 }));
    if (!r || !document.contains(st.modal.el)) return;
    G = r;
    const pending = G.guests.filter((g) => g.request && g.status !== 'revoked');
    st.pending = pending.length;
    const guests = G.guests;
    const history = G.history.map((h) => ({ user: h.by, text: h.text, created: h.created, card_id: h.card_id, card_title: h.card_title }));
    SM.set(st.modal.el, html`${G.card ? SM.modalHead('Share this card', `“${G.card.title}” — guests see this card only, never the rest of the Studio. They can only view at first: nothing changes without your approval.`)
      : SM.modalHead('Share this Studio', 'Share a link with clients and partners who don\'t have a Studioma account. They can only view at first: nothing changes without your approval.')}
      <div class="modal-body stack-l">${SM.shareTabsHtml()}
        ${pending.length ? html`<section><h3 class="section-title">${icon('hand')}Requests <span class="count">${pending.length}</span></h3><div class="list">${pending.map(requestRow)}</div></section>` : ''}
        <div class="link-cards">${linkCard('public')}${linkCard('private')}</div>
        ${cardSees()}
        <section><h3 class="section-title">Guests <span class="count">${guests.length}</span></h3>
          ${guests.length ? html`<div class="list">${guests.map(guestRow)}</div>` : html`<p class="muted small">No guest yet. People who open a link and verify their email appear here, with what they can do.</p>`}
          <p class="muted small guest-legend">${icon('shield')}<span>${G.card ? 'Viewer: views the card · Commenter: comments (if shown) and approves · Editor: edits this card only. Guests never see or manage the rest of the Studio.' : 'Viewer: views · Commenter: comments and approves · Editor: edits what you open to them. Guests never manage the Studio, its members or its structure.'}</span></p></section>
        ${history.length ? html`<details class="share-history"><summary>${icon('history')}Guest activity and access history <span class="count">${history.length}</span></summary><ul class="events">${history.map((e) => SM.eventRow(e, true))}</ul></details>` : ''}
      </div>`);
    // Lien d'un email (Approuver / Refuser) : la demande est mise en avant, et la décision confirmée.
    if (st.req) {
      const g = G.guests.find((x) => x.id === st.req);
      const what = st.do;
      st.req = 0;
      st.do = '';
      if (!g || !g.request) { if (what) SM.toast('This request was already answered.'); return; }
      const row = SM.$(`[data-guest="${g.id}"]`, st.modal.el);
      if (row) row.scrollIntoView({ block: 'center' });
      if (what === 'approve') decide(g.id, 'approve', g.request.role);
      if (what === 'reject') decide(g.id, 'reject');
    }
  }
  SM.drawGuests = drawGuests;
  const call = async (action, body) => {
    const sc = share().scope;
    const r = await SM.try(SM.post(action, Object.assign({ studio: sc.studio_id, card: sc.card_id || 0 }, body)));
    if (r) await drawGuests();
    // Le panneau de la carte résume son partage : il suit.
    if (r && sc.card_id && SM.cardPanel.id === sc.card_id && SM.cardPanel.refresh) SM.cardPanel.refresh();
    return r;
  };
  SM.changes['gsee-file'] = async (el) => {
    const ids = SM.$$('[data-change="gsee-file"]', el.closest('.card-sees')).filter((c) => c.checked).map((c) => +c.value);
    if (await call('share_save', { kind: el.dataset.kind, files: ids })) SM.toast(el.checked ? 'Guests can open this attachment' : 'Attachment hidden from guests');
  };
  SM.changes['gsee-comments'] = async (el) => { if (await call('share_save', { kind: el.dataset.kind, comments: el.checked })) SM.toast(el.checked ? 'Comments shown to guests' : 'Comments hidden from guests'); };
  async function decide(id, op, role) {
    const g = G.guests.find((x) => x.id === id);
    if (!g) return;
    if (op === 'approve') {
      if (!await SM.confirm({ title: `Give ${g.email} ${GROLES[role].toLowerCase()} access?`, text: `${GHELP[role]} They'll be told by email. You can change or revoke it at any time.`, ok: 'Approve' })) return;
      if (await call('share_guest', { op: 'approve', guest: id, role })) SM.toast(`${g.email} is now ${GROLES[role]}`);
    } else {
      if (!await SM.confirm({ title: `Reject the request of ${g.email}?`, text: 'They keep their current access and are told by email.', ok: 'Reject', danger: true })) return;
      if (await call('share_guest', { op: 'reject', guest: id })) SM.toast('Request rejected');
    }
  }
  SM.actions['guest-approve'] = (el) => decide(+el.dataset.id, 'approve', SM.$(`[data-req-role="${el.dataset.id}"]`).value);
  SM.actions['guest-reject'] = (el) => decide(+el.dataset.id, 'reject');
  SM.actions['glink-create'] = async (el) => { if (await call('share_save', { kind: el.dataset.kind })) SM.toast(`${LINK[el.dataset.kind][1]} created`); };
  SM.changes['glink-active'] = async (el) => { if (await call('share_save', { kind: el.dataset.kind, active: el.checked })) SM.toast(el.checked ? 'Link on' : 'Link off: nobody can open it'); };
  SM.changes['glink-expires'] = async (el) => { if (el.value && await call('share_save', { kind: el.dataset.kind, expires: el.value })) SM.toast('Expiry date saved'); };
  SM.actions['glink-noexp'] = (el) => call('share_save', { kind: el.dataset.kind, expires: '' });
  SM.actions['glink-regen'] = async (el) => {
    if (!await SM.confirm({ title: 'Create a new link?', text: 'The current link stops working right away. Guests who verified their email keep their access with the new link — send it to them.', ok: 'Create a new link' })) return;
    if (await call('share_regen', { kind: el.dataset.kind })) SM.toast('New link ready — copy it and send it');
  };
  SM.actions['glink-levels'] = (el) => {
    const kind = el.dataset.kind;
    const chosen = new Set(G.links[kind].levels);
    const save = () => call('share_save', { kind, levels: Array.from(chosen) });
    SM.menu(el, [{ head: 'Levels this link shows' },
      { label: 'All levels', icon: 'layers', checked: !chosen.size, act: () => { chosen.clear(); save(); } },
      ...G.levels.map((l) => ({ label: `${l.icon} ${l.name}`, checked: chosen.has(l.id), act: (it) => { if (it.checked) chosen.add(l.id); else chosen.delete(l.id); if (!chosen.size) return; save(); } }))], { keep: true, width: 250 });
  };
  SM.forms['guest-add'] = async (form, data) => {
    const r = await SM.post('share_guest', { studio: share().scope.studio_id, card: share().scope.card_id || 0, op: 'add', kind: 'private', emails: data.emails, role: data.role, send: data.send });
    SM.toast(data.send ? 'Added — they received the link by email' : 'Added — send them the private link');
    await drawGuests();
    return r;
  };
  SM.changes['guest-role'] = async (el) => { if (await call('share_guest', { op: 'role', guest: +el.dataset.id, role: el.value })) SM.toast('Access updated'); };
  SM.actions['guest-more'] = (el) => {
    const g = G.guests.find((x) => x.id === +el.dataset.id);
    SM.menu(el, [
      g.status === 'revoked' ? { label: 'Restore access', icon: 'restore', act: () => call('share_guest', { op: 'restore', guest: g.id }) }
        : { label: 'Revoke access', hint: 'Right away; they can\'t verify again', icon: 'lock', act: async () => { if (await SM.confirm({ title: `Revoke the access of ${g.email}?`, text: 'Their guest session ends right away. To also stop someone from viewing a public link, create a new link.', ok: 'Revoke', danger: true }) && await call('share_guest', { op: 'revoke', guest: g.id })) SM.toast('Access revoked'); } },
      { label: 'Remove from the list', icon: 'trash', danger: true, act: async () => { if (await SM.confirm({ title: `Remove ${g.email}?`, text: 'Their access ends. Their comments and changes keep their email address.', ok: 'Remove', danger: true }) && await call('share_guest', { op: 'remove', guest: g.id })) SM.toast('Guest removed'); } },
    ], { align: 'right', width: 250 });
  };
  /** Ce qu'un invité Editor peut modifier : tout, ou des niveaux, des colonnes, des cartes. */
  SM.actions['guest-scope'] = (el) => {
    const g = G.guests.find((x) => x.id === +el.dataset.id);
    const sc = { levels: new Set(g.scope.levels), cols: new Set(g.scope.cols), cards: new Set(g.scope.cards) };
    const link = G.links[g.kind];
    const levels = G.levels.filter((l) => !link || !link.levels.length || link.levels.includes(l.id));
    let all = !g.limited;
    const m = SM.modal(html`${SM.modalHead(`What can ${g.email} edit?`, 'They still see everything the link shows; they change only what you open to them here.')}<div class="modal-body" id="scope-body"></div>`, { size: 'm' });
    const draw = () => {
      const cards = G.cards.filter((c) => sc.cards.has(c.id));
      SM.set(SM.$('#scope-body', m.el), html`<form class="stack" data-form="guest-scope" data-id="${g.id}">
        <div class="choice">
          <label class="choice-opt"><input type="radio" name="all" value="1" ${all ? 'checked' : ''} data-change="scope-all"><span><b>Everything the link shows</b><small>Every card, in every shared level.</small></span></label>
          <label class="choice-opt"><input type="radio" name="all" value="0" ${all ? '' : 'checked'} data-change="scope-all"><span><b>Only what I choose</b><small>Levels, columns or single cards.</small></span></label>
        </div>
        ${all ? '' : html`<div class="scope-tree">${levels.map((l) => html`<div class="scope-level">
            <label class="check"><input type="checkbox" name="levels[]" value="${l.id}" ${sc.levels.has(l.id) ? 'checked' : ''} data-change="scope-pick" data-k="levels"><b>${l.icon} ${l.name}</b> <span class="muted small">(all its columns)</span></label>
            <div class="scope-cols">${l.cols.map((c) => html`<label class="check"><input type="checkbox" name="cols[]" value="${c.id}" ${sc.cols.has(c.id) || sc.levels.has(l.id) ? 'checked' : ''} ${sc.levels.has(l.id) ? 'disabled' : ''} data-change="scope-pick" data-k="cols">${c.name}</label>`)}</div></div>`)}
          <div class="scope-cards"><b>Single cards</b>${cards.map((c) => html`<span class="chip">${c.title}<button type="button" class="btn-icon btn-xs" data-act="scope-card-x" data-id="${c.id}" aria-label="Remove ${c.title}">${icon('x')}</button></span>`)}
            <button type="button" class="btn btn-ghost btn-sm" data-act="scope-card-add">${icon('plus')}Add a card</button></div></div>`}
        <p class="form-error" data-error hidden></p>
        <div class="row end"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">Save</button></div></form>`);
    };
    SM.changes['scope-all'] = (r) => { all = r.value === '1'; draw(); };
    SM.changes['scope-pick'] = (c) => { const set = sc[c.dataset.k]; if (c.checked) set.add(+c.value); else set.delete(+c.value); if (c.dataset.k === 'levels') draw(); };
    SM.actions['scope-card-x'] = (b) => { sc.cards.delete(+b.dataset.id); draw(); };
    SM.actions['scope-card-add'] = (b) => SM.menu(b, G.cards.filter((c) => !sc.cards.has(c.id)).map((c) => ({ label: c.title, act: () => { sc.cards.add(c.id); draw(); } })), { search: true, width: 300 });
    SM.forms['guest-scope'] = async () => {
      const body = all ? { levels: [], cols: [], cards: [] } : { levels: Array.from(sc.levels), cols: Array.from(sc.cols).filter((id) => !levels.some((l) => sc.levels.has(l.id) && l.cols.some((c) => c.id === id))), cards: Array.from(sc.cards) };
      if (!all && !body.levels.length && !body.cols.length && !body.cards.length) throw new SM.UserError('Choose at least one level, column or card — or “Everything the link shows”.');
      await SM.post('share_guest', Object.assign({ studio: share().scope.studio_id, op: 'scope', guest: g.id }, body));
      m.close();
      SM.toast('Editing access saved');
      await drawGuests();
    };
    draw();
  };
})();
