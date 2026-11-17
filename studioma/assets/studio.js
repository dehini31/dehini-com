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
          <p class="muted">${p.company ? html`<a href="#/c/${p.company.slug}">${p.company.name}</a> · ` : ''}${p.limited ? 'You are invited to part of this project' : html`You are ${SM.ref.roles[p.role]}`}${p.archived ? ' · Archived' : ''}</p></div></div>
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
  SM.shareDialog = async (scope) => {
    share = { scope, modal: SM.modal(html`${SM.modalHead('Share')}<div class="modal-body">${SM.loading(3)}</div>`, { size: 'l' }) };
    await drawShare();
  };
  async function drawShare() {
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
      <div class="modal-body stack-l">
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
