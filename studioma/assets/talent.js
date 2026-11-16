/*
 * Studioma — entreprises (pages, équipe), opportunités et candidatures, annuaire des talents,
 * invitations à collaborer et messagerie privée.
 */
(function () {
  'use strict';
  const { html, raw, icon, fmt } = SM;

  /* ------------------------------------------------------------ entreprises */
  SM.companyCard = (c) => html`<a class="panel person company" href="#/c/${c.slug}"><span class="person-main">${SM.logoBox(c, 'av-l')}<b>${c.name}</b><span class="muted small clamp-2">${c.tagline || c.industry || 'Company'}</span>${c.location ? html`<span class="muted small">${icon('pin')}${c.location}</span>` : ''}</span></a>`;
  let co = null;
  SM.route(/^\/c\/([a-z0-9][a-z0-9-]{1,40})$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page">${SM.loading(3)}</div>`);
    const r = co = await SM.get('company_get', { slug: m[1] });
    if (!ctx.alive()) return;
    const c = r.company;
    const admin = r.rel.admin;
    SM.set(ctx.view, html`<div class="page profile">
      <header class="panel profile-head">
        <div class="cover"${c.cover ? raw(` style="background-image:url('${SM.fileUrl(c.cover)}')"`) : ''}></div>
        <div class="profile-id">${SM.logoBox(c, 'av-xl')}
          <div class="grow"><h1>${c.name}</h1><p class="profile-title">${c.tagline || (admin ? 'Add a tagline' : '')}</p>
            <p class="muted small profile-meta">${c.industry ? html`<span>${icon('briefcase')}${c.industry}</span>` : ''}${c.location ? html`<span>${icon('pin')}${c.location}</span>` : ''}${c.size ? html`<span>${icon('users')}${c.size} people</span>` : ''}<span><b>${c.followers}</b> followers</span></p></div>
          <div class="profile-actions">${admin ? html`<button class="btn btn-soft" data-act="company-edit">${icon('edit')}Edit page</button><button class="btn btn-primary" data-act="opp-new" data-company="${c.id}">${icon('plus')}Post an opportunity</button>` : SM.followBtn('company', c.id, r.rel.following)}
            ${c.website ? html`<a class="btn btn-soft" href="${c.website}" target="_blank" rel="noopener noreferrer">${icon('globe')}Website</a>` : ''}</div></div>
      </header>
      <div class="with-rail">
        <div class="stack-l">
          ${c.about || admin ? html`<section class="panel pad"><h2>${icon('building')}About</h2>${c.about ? html`<p class="pre">${SM.linkify(c.about)}</p>` : html`<p class="muted">Present your company to professionals. <a href="#" data-act="company-edit">Write a description</a></p>`}</section>` : ''}
          <section><h2 class="section-title">${icon('briefcase')}Opportunities <span class="count">${r.opps.length}</span></h2>
            ${r.opps.length ? html`<div class="grid grid-2">${r.opps.map((o) => SM.oppCard(o))}</div>` : html`<p class="muted">${admin ? 'Publish your first opportunity to find the right community manager.' : 'No open opportunity right now.'}</p>`}</section>
          <section><h2 class="section-title">${icon('edit')}Posts</h2><div id="feed" class="feed">${SM.loading(1)}</div></section>
        </div>
        <aside class="rail"><section class="panel pad"><header class="row between"><h3>${icon('users')}Team <span class="count">${r.members.length}</span></h3>${admin ? html`<a class="btn btn-soft btn-sm" href="#/team">Manage</a>` : ''}</header>
          <div class="members">${r.members.map((u) => html`<a class="member" href="#/u/${u.handle}">${SM.avatar(u, 'av-m')}<span class="grow"><b>${u.name}</b><small class="muted">${u.member_title || u.title || '@' + u.handle}</small></span>${u.role !== 'member' ? html`<span class="role role-${u.role === 'owner' ? 'owner' : 'manager'}">${u.role === 'owner' ? 'Owner' : 'Admin'}</span>` : ''}</a>`)}</div></section></aside>
      </div></div>`);
    SM.feed(SM.$('#feed'), { tab: 'company', id: c.id }, ctx, html`<p class="muted">${admin ? 'Share news from your company: use Create › Post and choose to post as the company.' : 'No post yet.'}</p>`);
  }, { public: true });
  let cdraft = null;
  SM.companyDialog = (c) => {
    SM.imgDraft = cdraft = { logo: c ? c.logo : '', cover: c ? c.cover : '' };
    SM.modal(html`${SM.modalHead(c ? 'Edit company page' : 'Create a company page', c ? '' : 'For a brand, an agency or an organization. You become its owner.')}
      <form class="modal-body stack" data-form="company-save">
        ${c ? html`<input type="hidden" name="id" value="${c.id}">` : ''}
        <div class="row">${SM.imgField('logo', 'Logo', cdraft.logo, 'img-square')}${SM.imgField('cover', 'Cover image', cdraft.cover, 'img-wide')}</div>
        <label class="field"><span>Company name</span><input class="input" name="name" maxlength="80" required value="${c ? c.name : ''}" autofocus></label>
        <label class="field"><span>Tagline</span><input class="input" name="tagline" maxlength="140" value="${c ? c.tagline : ''}" placeholder="What you do, in one sentence"></label>
        <div class="field-row"><label class="field"><span>Industry</span><select class="input" name="industry"><option value="">Choose…</option>${SM.ref.industries.map((i) => html`<option ${c && c.industry === i ? 'selected' : ''}>${i}</option>`)}</select></label>
          <label class="field"><span>Size</span><select class="input" name="size"><option value="">—</option>${SM.ref.sizes.map((s) => html`<option value="${s}" ${c && c.size === s ? 'selected' : ''}>${s} people</option>`)}</select></label></div>
        <div class="field-row"><label class="field"><span>Location</span><input class="input" name="location" maxlength="80" value="${c ? c.location : ''}" placeholder="City, country"></label>
          <label class="field"><span>Website</span><input class="input" name="website" maxlength="300" value="${c ? c.website : ''}" placeholder="https://"></label></div>
        <label class="field"><span>About</span><textarea class="input" name="about" rows="4" maxlength="3000">${c ? c.about : ''}</textarea></label>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">${c ? 'Save' : 'Create page'}</button></footer>
      </form>`, { size: 'l' });
  };
  SM.actions['company-new'] = () => SM.companyDialog();
  SM.actions['company-edit'] = () => SM.companyDialog(co.company);
  SM.forms['company-save'] = async (form, data) => {
    const r = await SM.post('company_save', Object.assign(data, { id: +data.id || 0, logo: cdraft.logo, cover: cdraft.cover }));
    SM.setMe(await SM.get('me'));
    SM.closeModals();
    SM.shell();
    SM.toast('Company page saved');
    SM.go('/c/' + r.slug);
  };

  /* ----------------------------------------------------------------- équipe */
  SM.route(/^\/team$/, async (m, ctx) => {
    const mine = SM.adminCompanies();
    if (!mine.length) {
      SM.set(ctx.view, html`<div class="page">${SM.empty('building', 'Create your company page', 'Manage your team, publish opportunities and discover talent as a brand or an agency.', html`<button class="btn btn-primary" data-act="company-new">${icon('plus')}Create a company page</button>`)}</div>`);
      return;
    }
    const slug = SM.query().get('c') || mine[0].slug;
    SM.set(ctx.view, html`<div class="page page-narrow">${SM.loading(3)}</div>`);
    const r = co = await SM.get('company_get', { slug });
    if (!ctx.alive()) return;
    const c = r.company;
    const owner = r.rel.role === 'owner';
    SM.set(ctx.view, html`<div class="page page-narrow">
      <header class="page-head"><div class="row">${SM.logoBox(c, 'av-l')}<div><h1>Team</h1><p class="muted">${mine.length > 1 ? html`<select class="input input-sm" data-change="team-company" aria-label="Company">${mine.map((x) => html`<option value="${x.slug}" ${x.slug === slug ? 'selected' : ''}>${x.name}</option>`)}</select>` : c.name}</p></div></div>
        <a class="btn btn-soft" href="#/c/${c.slug}">${icon('external')}View page</a></header>
      <section class="panel pad"><h2>${icon('user-plus')}Add a teammate</h2>
        <p class="muted small">They need a Studioma account. Admins can edit the page, post as the company, publish opportunities and manage all company projects.</p>
        <form class="invite-form" data-form="team-add">
          <label class="field grow"><span>Email or username</span><input class="input" name="who" required maxlength="190" placeholder="name@example.com or @username" autocomplete="off"></label>
          <label class="field"><span>Job title <em>optional</em></span><input class="input" name="title" maxlength="80" placeholder="e.g. Social media lead"></label>
          <label class="field"><span>Access</span><select class="input" name="role"><option value="member">Member</option><option value="admin">Admin</option></select></label>
          <button class="btn btn-primary">Add</button><p class="form-error" data-error hidden></p></form></section>
      <section class="panel pad"><h2>${icon('users')}Members <span class="count">${r.members.length}</span></h2>
        <div class="list">${r.members.map((u) => html`<div class="list-row">${SM.avatar(u, 'av-m')}<a class="grow" href="#/u/${u.handle}"><b>${u.name}${u.id === SM.me.id ? ' (you)' : ''}</b><small class="muted">${u.member_title || u.title || '@' + u.handle}</small></a>
          ${u.role === 'owner' && !owner ? html`<span class="role role-owner">Owner</span>` : html`<select class="input input-sm" data-change="team-role" data-uid="${u.id}" data-title="${u.member_title}" aria-label="Access of ${u.name}">${[['owner', 'Owner'], ['admin', 'Admin'], ['member', 'Member']].filter(([k]) => k !== 'owner' || owner).map(([k, l]) => html`<option value="${k}" ${u.role === k ? 'selected' : ''}>${l}</option>`)}</select>
            <button class="btn-icon" data-act="team-remove" data-uid="${u.id}" data-name="${u.name}" aria-label="Remove ${u.name}">${icon('x')}</button>`}</div>`)}</div></section>
    </div>`);
  });
  SM.changes['team-company'] = (el) => SM.go('/team?c=' + el.value);
  SM.forms['team-add'] = async (form, data) => { await SM.post('company_member', Object.assign({ company_id: co.company.id, op: 'add' }, data)); SM.toast('Teammate added'); SM.navigate(); };
  SM.changes['team-role'] = async (el) => { if (await SM.try(SM.post('company_member', { company_id: co.company.id, op: 'role', uid: +el.dataset.uid, role: el.value, title: el.dataset.title }))) SM.toast('Access updated'); SM.navigate(); };
  SM.actions['team-remove'] = async (el) => {
    if (!await SM.confirm({ title: `Remove ${el.dataset.name} from the team?`, ok: 'Remove', danger: true })) return;
    if (await SM.try(SM.post('company_member', { company_id: co.company.id, op: 'remove', uid: +el.dataset.uid }))) { SM.setMe(await SM.get('me')); SM.shell(); SM.navigate(); }
  };

  /* ------------------------------------------------------------ opportunités */
  const oppMeta = (o) => html`<span class="chip">${icon(o.mode === 'remote' ? 'globe' : 'pin')}${SM.ref.modes[o.mode]}${o.location ? ' · ' + o.location : ''}</span><span class="chip">${SM.ref.contracts[o.contract]}</span>`;
  SM.oppCard = (o) => html`<a class="panel opp${o.status === 'closed' ? ' is-closed' : ''}" href="#/opportunity/${o.id}">
      <header class="row">${SM.logoBox(o.company, 'av-m')}<div class="grow"><h3>${o.title}</h3><small class="muted">${o.company ? o.company.name : ''} · ${fmt.ago(o.created)}</small></div>
        ${o.status === 'closed' ? html`<span class="chip">Closed</span>` : o.applied ? html`<span class="chip chip-ok">${icon('check')}Applied</span>` : ''}</header>
      <p class="muted small clamp-2">${o.body}</p>
      <footer class="chips-list">${oppMeta(o)}${o.skills.slice(0, 3).map((s) => html`<span class="chip chip-soft">${s}</span>`)}${o.applications != null ? html`<span class="chip chip-ok">${fmt.plural(o.applications, 'application')}</span>` : ''}</footer>
    </a>`;
  const OF = { q: '', mode: '', contract: '', scope: '' };
  SM.route(/^\/opportunities$/, async (m, ctx) => {
    const admin = SM.me && SM.adminCompanies().length;
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Opportunities</h1><p class="muted">Positions and projects published by companies looking for community managers and creators.</p></div>
        ${admin ? html`<button class="btn btn-primary" data-act="opp-new">${icon('plus')}Post an opportunity</button>` : ''}</header>
      <div class="filters"><label class="search-in grow">${icon('search')}<input class="input" placeholder="Title, skill, company…" value="${OF.q}" data-input="opp-q" aria-label="Search opportunities"></label>
        <select class="input" data-change="opp-filter" data-name="mode" aria-label="Work mode"><option value="">Remote or on-site</option>${Object.entries(SM.ref.modes).map(([k, l]) => html`<option value="${k}" ${OF.mode === k ? 'selected' : ''}>${l}</option>`)}</select>
        <select class="input" data-change="opp-filter" data-name="contract" aria-label="Contract type"><option value="">Any contract</option>${Object.entries(SM.ref.contracts).map(([k, l]) => html`<option value="${k}" ${OF.contract === k ? 'selected' : ''}>${l}</option>`)}</select>
        ${SM.me ? html`<select class="input" data-change="opp-filter" data-name="scope" aria-label="Show"><option value="">All open opportunities</option><option value="applied" ${OF.scope === 'applied' ? 'selected' : ''}>My applications</option>${admin ? html`<option value="mine" ${OF.scope === 'mine' ? 'selected' : ''}>Published by my company</option>` : ''}</select>` : ''}</div>
      <div id="opps">${SM.loading(3)}</div></div>`);
    const load = async () => {
      const r = await SM.get('opps', { q: OF.q, mode: OF.mode, contract: OF.contract, mine: OF.scope === 'mine', applied: OF.scope === 'applied' });
      if (!ctx.alive()) return;
      SM.set(SM.$('#opps'), r.opps.length ? html`<div class="grid grid-2">${r.opps.map((o) => SM.oppCard(o))}</div>`
        : SM.empty('briefcase', OF.q || OF.mode || OF.contract || OF.scope ? 'No opportunity matches' : 'No open opportunity yet', OF.scope === 'applied' ? 'Opportunities you apply to are listed here with their status.' : admin ? 'Publish the first one: describe the mission, the skills you need and how to apply.' : 'Companies publish their positions and projects here. Complete your profile to be found in the meantime.', admin ? html`<button class="btn btn-primary" data-act="opp-new">${icon('plus')}Post an opportunity</button>` : '')}`);
    };
    SM.inputs['opp-q'] = SM.debounce((el) => { OF.q = el.value.trim(); load(); }, 250);
    SM.changes['opp-filter'] = (el) => { OF[el.dataset.name] = el.value; load(); };
    load();
  }, { public: true });

  let opp = null;
  SM.route(/^\/opportunity\/(\d+)$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow">${SM.loading(3)}</div>`);
    const r = opp = await SM.get('opp_get', { id: m[1] });
    if (!ctx.alive()) return;
    const o = r.opp;
    const applyBox = o.admin ? '' : o.status === 'closed' ? html`<div class="notice">${icon('lock')}<span>This opportunity is closed.</span></div>`
      : o.applied ? html`<div class="notice notice-ok">${icon('check')}<span>You applied. Status: <b>${SM.ref.app_statuses[o.applied]}</b>.</span></div>`
      : o.apply_kind === 'url' ? html`<a class="btn btn-primary" href="${o.apply_to}" target="_blank" rel="noopener noreferrer">${icon('external')}Apply on the company website</a>`
      : o.apply_kind === 'email' ? html`<a class="btn btn-primary" href="mailto:${o.apply_to}?subject=${encodeURIComponent(o.title)}">${icon('mail')}Apply by email</a>`
      : SM.me ? html`<form class="stack" data-form="opp-apply"><label class="field"><span>Introduce yourself</span><textarea class="input" name="message" rows="4" maxlength="2000" required placeholder="Why this mission? What relevant work have you done? Your profile and portfolio are shared with the company."></textarea></label>
          <p class="form-error" data-error hidden></p><div><button class="btn btn-primary">${icon('send')}Send my application</button></div></form>`
      : html`<a class="btn btn-primary" href="#/signup" data-act="login-first">Join Studioma to apply</a>`;
    SM.set(ctx.view, html`<div class="page page-narrow">
      <a class="back" href="#/opportunities">${icon('arrow-left')}Opportunities</a>
      <article class="panel pad opp-full">
        <header class="row"><a href="#/c/${o.company ? o.company.slug : ''}">${SM.logoBox(o.company, 'av-l')}</a><div class="grow"><h1>${o.title}</h1><p class="muted"><a href="#/c/${o.company ? o.company.slug : ''}">${o.company ? o.company.name : ''}</a> · published ${fmt.ago(o.created)}</p></div>
          ${o.admin ? html`<button class="btn btn-soft" data-act="opp-edit">${icon('edit')}Edit</button>` : ''}</header>
        <div class="chips-list">${oppMeta(o)}${o.status === 'closed' ? html`<span class="chip chip-warn">Closed</span>` : ''}</div>
        <p class="pre">${SM.linkify(o.body)}</p>
        ${o.skills.length ? html`<div><h3>Skills required</h3><div class="chips-list">${o.skills.map((s) => html`<span class="chip">${s}</span>`)}</div></div>` : ''}
        ${applyBox ? html`<hr>${applyBox}` : ''}
      </article>
      ${r.applications ? html`<section><h2 class="section-title">${icon('inbox')}Applications <span class="count">${r.applications.length}</span></h2>
        ${r.applications.length ? html`<div class="stack">${r.applications.map((a) => html`<article class="panel pad app">
          <header class="row"><a class="row grow" href="#/u/${a.user ? a.user.handle : ''}">${SM.avatar(a.user, 'av-m')}<span><b>${a.user ? a.user.name : 'Former member'}</b><small class="muted">${a.user ? a.user.title || '@' + a.user.handle : ''} · ${fmt.ago(a.created)}</small></span></a>
            <select class="input input-sm" data-change="app-status" data-id="${a.id}" aria-label="Application status">${Object.entries(SM.ref.app_statuses).map(([k, l]) => html`<option value="${k}" ${a.status === k ? 'selected' : ''}>${l}</option>`)}</select></header>
          <p class="pre">${a.message}</p>
          ${a.user ? html`<footer class="row">${a.user.skills.slice(0, 4).map((s) => html`<span class="chip">${s}</span>`)}<span class="grow"></span><a class="btn btn-soft btn-sm" href="#/u/${a.user.handle}">View profile</a><a class="btn btn-soft btn-sm" href="#/messages/${a.user.handle}">${icon('message')}Message</a></footer>` : ''}</article>`)}</div>`
          : html`<p class="muted">No application yet. Share the link of this page to spread the word.</p>`}</section>` : ''}
    </div>`);
  }, { public: true });
  SM.forms['opp-apply'] = async (form, data) => { await SM.post('opp_apply', { id: opp.opp.id, message: data.message }); SM.toast('Application sent'); SM.navigate(); };
  SM.changes['app-status'] = async (el) => { if (await SM.try(SM.post('opp_application', { id: +el.dataset.id, status: el.value }))) SM.toast('Status updated — the applicant is notified'); };
  SM.oppDialog = (o, companyId) => {
    const companies = SM.adminCompanies();
    if (!companies.length) { SM.companyDialog(); return; }
    SM.modal(html`${SM.modalHead(o ? 'Edit opportunity' : 'Post an opportunity')}
      <form class="modal-body stack" data-form="opp-save">
        ${o ? html`<input type="hidden" name="id" value="${o.id}">` : companies.length > 1 ? html`<label class="field"><span>Company</span><select class="input" name="company_id">${companies.map((c) => html`<option value="${c.id}" ${c.id === companyId ? 'selected' : ''}>${c.name}</option>`)}</select></label>` : html`<input type="hidden" name="company_id" value="${companies[0].id}">`}
        <label class="field"><span>Position or project title</span><input class="input" name="title" maxlength="120" required value="${o ? o.title : ''}" placeholder="e.g. Community manager for our Instagram and TikTok" autofocus></label>
        <label class="field"><span>Description</span><textarea class="input" name="body" rows="6" maxlength="6000" required placeholder="The mission, the brand, what success looks like, the budget or salary range…">${o ? o.body : ''}</textarea></label>
        <div class="field"><span>Skills required</span>${SM.chips.render('skills', o ? o.skills : [], { suggest: SM.ref.skills, max: 12, placeholder: 'Add a skill and press Enter' })}</div>
        <div class="field-row"><label class="field"><span>Work mode</span><select class="input" name="mode">${Object.entries(SM.ref.modes).map(([k, l]) => html`<option value="${k}" ${o && o.mode === k ? 'selected' : ''}>${l}</option>`)}</select></label>
          <label class="field"><span>Location</span><input class="input" name="location" maxlength="80" value="${o ? o.location : ''}" placeholder="City, country"></label>
          <label class="field"><span>Contract type</span><select class="input" name="contract">${Object.entries(SM.ref.contracts).map(([k, l]) => html`<option value="${k}" ${o && o.contract === k ? 'selected' : ''}>${l}</option>`)}</select></label></div>
        <div class="field-row"><label class="field"><span>How to apply</span><select class="input" name="apply_kind" data-change="apply-kind"><option value="inapp" ${o && o.apply_kind === 'inapp' ? 'selected' : ''}>On Studioma (recommended)</option><option value="url" ${o && o.apply_kind === 'url' ? 'selected' : ''}>On an external page</option><option value="email" ${o && o.apply_kind === 'email' ? 'selected' : ''}>By email</option></select></label>
          <label class="field" data-apply-to ${o && o.apply_kind !== 'inapp' ? '' : 'hidden'}><span>Link or email</span><input class="input" name="apply_to" maxlength="400" value="${o ? o.apply_to : ''}"></label>
          ${o ? html`<label class="field"><span>Status</span><select class="input" name="status"><option value="open">Open</option><option value="closed" ${o.status === 'closed' ? 'selected' : ''}>Closed</option></select></label>` : ''}</div>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot">${o ? html`<button type="button" class="btn btn-ghost is-danger" data-act="opp-delete" data-id="${o.id}">Delete</button><span class="grow"></span>` : ''}<button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">${o ? 'Save' : 'Publish'}</button></footer>
      </form>`, { size: 'l' });
  };
  SM.changes['apply-kind'] = (el) => { SM.$('[data-apply-to]', el.form).hidden = el.value === 'inapp'; };
  SM.actions['opp-new'] = (el) => SM.oppDialog(null, +el.dataset.company || 0);
  SM.actions['opp-edit'] = () => SM.oppDialog(opp.opp);
  SM.forms['opp-save'] = async (form, data) => {
    const r = await SM.post('opp_save', Object.assign(data, { id: +data.id || 0, company_id: +data.company_id || 0 }));
    SM.closeModals();
    SM.toast(data.id ? 'Opportunity saved' : 'Opportunity published');
    SM.go('/opportunity/' + r.id);
  };
  SM.actions['opp-delete'] = async (el) => {
    if (!await SM.confirm({ title: 'Delete this opportunity?', text: 'Its applications are deleted too. Close it instead to keep them.', ok: 'Delete', danger: true })) return;
    if (await SM.try(SM.post('opp_delete', { id: +el.dataset.id }))) { SM.closeModals(); SM.toast('Opportunity deleted'); SM.go('/opportunities'); }
  };

  /* -------------------------------------------------------------- talents */
  const TF = { q: '', skill: '', platform: '', industry: '', language: '', location: '', availability: '', experience: '', portfolio: false, saved: false };
  SM.route(/^\/talent$/, async (m, ctx) => {
    const opt = (list, value) => list.map((x) => html`<option ${x === value ? 'selected' : ''}>${x}</option>`);
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Talent</h1><p class="muted">Find community managers and creators by what they can do and what they have delivered. Results follow your filters — there is no global ranking.</p></div></header>
      <div class="talent-layout">
        <aside class="panel pad talent-filters" id="talent-filters">
          <label class="search-in">${icon('search')}<input class="input" placeholder="Name, title, keyword…" value="${TF.q}" data-input="talent-q" data-name="q" aria-label="Search talent"></label>
          <label class="field"><span>Skill</span><select class="input" data-change="talent-filter" data-name="skill"><option value="">Any skill</option>${opt(SM.ref.skills, TF.skill)}</select></label>
          <label class="field"><span>Platform</span><select class="input" data-change="talent-filter" data-name="platform"><option value="">Any platform</option>${Object.entries(SM.ref.platforms).map(([k, l]) => html`<option value="${k}" ${TF.platform === k ? 'selected' : ''}>${l}</option>`)}</select></label>
          <label class="field"><span>Industry</span><select class="input" data-change="talent-filter" data-name="industry"><option value="">Any industry</option>${opt(SM.ref.industries, TF.industry)}</select></label>
          <label class="field"><span>Language</span><select class="input" data-change="talent-filter" data-name="language"><option value="">Any language</option>${opt(SM.ref.languages, TF.language)}</select></label>
          <label class="field"><span>Location</span><input class="input" placeholder="City or country" value="${TF.location}" data-input="talent-q" data-name="location"></label>
          <label class="field"><span>Experience</span><select class="input" data-change="talent-filter" data-name="experience"><option value="">Any</option>${[[1, '1+ year'], [3, '3+ years'], [5, '5+ years'], [8, '8+ years']].map(([v, l]) => html`<option value="${v}" ${+TF.experience === v ? 'selected' : ''}>${l}</option>`)}</select></label>
          <label class="field"><span>Availability</span><select class="input" data-change="talent-filter" data-name="availability"><option value="">Any</option>${Object.entries(SM.ref.availability).map(([k, l]) => html`<option value="${k}" ${TF.availability === k ? 'selected' : ''}>${l}</option>`)}</select></label>
          <label class="check"><input type="checkbox" data-change="talent-filter" data-name="portfolio" ${TF.portfolio ? 'checked' : ''}><span>With a published portfolio</span></label>
          <label class="check"><input type="checkbox" data-change="talent-filter" data-name="saved" ${TF.saved ? 'checked' : ''}><span>Saved professionals only</span></label>
          <button class="btn btn-ghost btn-sm" data-act="talent-reset">Reset filters</button>
        </aside>
        <div id="talent">${SM.loading(4)}</div>
      </div></div>`);
    let page = 0;
    let people = [];
    const card = (u) => html`<article class="panel tcard">
        <a class="row" href="#/u/${u.handle}">${SM.avatar(u, 'av-l')}<div class="grow"><h3>${u.name}</h3><p class="muted small clamp-2">${u.title || '@' + u.handle}</p></div></a>
        <p class="muted small tcard-meta">${u.location ? html`<span>${icon('pin')}${u.location}</span>` : ''}${u.experience ? html`<span>${icon('clock')}${fmt.plural(u.experience, 'year')}</span>` : ''}${u.languages.length ? html`<span>${icon('globe')}${u.languages.slice(0, 3).join(', ')}</span>` : ''}</p>
        ${SM.availChip(u.availability)}
        ${u.skills.length ? html`<div class="chips-list">${u.skills.slice(0, 4).map((s) => html`<span class="chip">${s}</span>`)}${u.skills.length > 4 ? html`<span class="chip">+${u.skills.length - 4}</span>` : ''}</div>` : ''}
        <div class="tcard-proof" title="Proof of work">${u.proof.studio ? html`<span class="vbadge v-studio">${icon('badge')}${u.proof.studio} Studio-verified</span>` : ''}${u.proof.client ? html`<span class="vbadge v-client">${icon('shield')}${u.proof.client} client-confirmed</span>` : ''}${u.proof.self ? html`<span class="vbadge v-self">${icon('user')}${u.proof.self} self-reported</span>` : ''}${u.proof.recommendations ? html`<span class="vbadge v-self">${icon('quote')}${fmt.plural(u.proof.recommendations, 'recommendation')}</span>` : ''}${!u.cases && !u.proof.recommendations ? html`<span class="muted small">No published work yet</span>` : ''}</div>
        <footer class="row"><button class="btn-icon${u.saved ? ' is-on' : ''}" data-act="talent-save" data-id="${u.id}" aria-pressed="${u.saved}" title="${u.saved ? 'Saved' : 'Save this professional'}" aria-label="Save ${u.name}">${icon('bookmark')}</button><span class="grow"></span>
          <a class="btn btn-soft btn-sm" href="#/messages/${u.handle}">${icon('message')}Contact</a><button class="btn btn-primary btn-sm" data-act="talent-invite" data-id="${u.id}">${icon('user-plus')}Invite</button></footer>
      </article>`;
    const load = async (more) => {
      page = more ? page + 1 : 0;
      const r = await SM.get('talent', Object.assign({}, TF, { page }));
      if (!ctx.alive()) return;
      people = more ? people.concat(r.people) : r.people;
      SM.set(SM.$('#talent'), people.length ? html`<p class="muted small">${fmt.plural(people.length, 'professional')}${r.more ? '+' : ''} · most relevant to your filters and most recently active first</p><div class="grid grid-3">${people.map(card)}</div>${r.more ? html`<button class="btn btn-soft btn-block" data-act="talent-more">Show more</button>` : ''}`
        : SM.empty('search', 'No professional matches these filters', 'Remove a filter or try a broader skill.', html`<button class="btn btn-soft" data-act="talent-reset">Reset filters</button>`));
    };
    SM.inputs['talent-q'] = SM.debounce((el) => { TF[el.dataset.name] = el.value.trim(); load(); }, 250);
    SM.changes['talent-filter'] = (el) => { TF[el.dataset.name] = el.type === 'checkbox' ? el.checked : el.value; load(); };
    SM.actions['talent-more'] = () => load(true);
    SM.actions['talent-reset'] = () => { Object.assign(TF, { q: '', skill: '', platform: '', industry: '', language: '', location: '', availability: '', experience: '', portfolio: false, saved: false }); SM.navigate(); };
    SM.actions['talent-save'] = async (el) => { const on = !el.classList.contains('is-on'); if (await SM.try(SM.post('talent_save', { uid: +el.dataset.id, on }))) { el.classList.toggle('is-on', on); SM.toast(on ? 'Saved to your talent list' : 'Removed from your list'); } };
    SM.actions['talent-invite'] = (el) => SM.collabDialog(people.find((u) => u.id === +el.dataset.id));
    load();
  });

  /** Invite une personne à rejoindre l'un de ses projets ou Studios (invitation à collaborer). */
  SM.collabDialog = async (person) => {
    const w = await SM.try(SM.workspace(true));
    if (!w) return;
    const projects = w.projects.filter((p) => !p.archived && (p.role === 'owner' || p.role === 'manager'));
    if (!projects.length) { SM.toast('Create a project first, then invite people to it.'); SM.projectDialog(); return; }
    SM.modal(html`${SM.modalHead('Invite ' + person.name + ' to collaborate', 'They receive the invitation in Studioma and by email, and choose to accept or not.')}
      <form class="modal-body stack" data-form="collab-invite" data-uid="${person.id}">
        <label class="field"><span>Project or Studio</span><select class="input" name="scope">${projects.map((p) => html`<optgroup label="${p.name}"><option value="p${p.id}">Whole project — ${p.name}</option>${p.studios.map((s) => html`<option value="s${s.id}">Only the Studio “${s.name}”</option>`)}</optgroup>`)}</select></label>
        <label class="field"><span>Role</span><select class="input" name="role">${['manager', 'creator', 'collaborator', 'client', 'viewer'].map((k) => html`<option value="${k}" ${k === 'creator' ? 'selected' : ''}>${SM.ref.roles[k]}</option>`)}</select></label>
        <label class="field"><span>Message <em>optional</em></span><textarea class="input" name="message" rows="3" maxlength="600" placeholder="What is the project about? Why them?"></textarea></label>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">${icon('send')}Send invitation</button></footer>
      </form>`);
  };
  SM.forms['collab-invite'] = async (form, data) => {
    const id = +data.scope.slice(1);
    await SM.post('invite_create', Object.assign({ uid: +form.dataset.uid, role: data.role, message: data.message }, data.scope[0] === 's' ? { studio_id: id } : { project_id: id }));
    SM.closeModals();
    SM.toast('Invitation sent');
  };

  /* --------------------------------------------------------------- messages */
  SM.route(/^\/messages(?:\/([a-z0-9][a-z0-9._-]{1,30}))?$/i, async (m, ctx) => {
    const handle = m[1] ? m[1].toLowerCase() : '';
    SM.set(ctx.view, html`<div class="page chat${handle ? ' has-thread' : ''}"><aside class="panel chat-list" id="chat-list">${SM.loading(3)}</aside><section class="panel chat-thread" id="chat-thread"></section></div>`);
    const drawList = async () => {
      const r = await SM.get('threads');
      if (!ctx.alive()) return;
      SM.set(SM.$('#chat-list'), html`<header><h2>Messages</h2></header>${r.threads.length ? r.threads.map((t) => html`<a class="chat-row${t.user && t.user.handle === handle ? ' is-active' : ''}${t.unread ? ' is-new' : ''}" href="#/messages/${t.user ? t.user.handle : ''}">${SM.avatar(t.user, 'av-m')}<span class="grow"><b>${t.user ? t.user.name : 'Former member'}</b><small class="muted clamp-1">${t.last ? (t.last.mine ? 'You: ' : '') + t.last.body : ''}</small></span><small class="muted">${fmt.ago(t.last_at)}</small></a>`)
        : html`<p class="muted small pad">No conversation yet. Open a profile and tap “Message”.</p>`}`);
      SM.get('counts').then((c) => SM.refreshCounts(c.counts)).catch(() => {});
    };
    if (!handle) { SM.set(SM.$('#chat-thread'), SM.empty('message', 'Your messages', 'Talk with clients, teammates and companies. Choose a conversation, or start one from a profile.')); await drawList(); return; }
    let last = 0;
    let other = null;
    const bubble = (x) => html`<div class="bubble${x.mine ? ' is-mine' : ''}"><p>${SM.linkify(x.body)}</p><small>${fmt.time(x.created)}</small></div>`;
    const pull = async (first) => {
      const r = await SM.get('thread', { with: handle, after: last });
      if (!ctx.alive()) return;
      if (first) {
        other = r.user;
        SM.set(SM.$('#chat-thread'), html`<header><a class="btn-icon chat-back" href="#/messages" aria-label="Back">${icon('arrow-left')}</a><a class="row grow" href="#/u/${other.handle}">${SM.avatar(other, 'av-m')}<span><b>${other.name}</b><small class="muted">${other.title || '@' + other.handle}</small></span></a></header>
          <div class="chat-msgs" id="chat-msgs">${r.messages.length ? '' : html`<p class="muted small chat-empty">Say hello to ${other.name.split(' ')[0]}.</p>`}</div>
          <form class="chat-form" data-form="chat-send"><textarea class="input" name="body" rows="1" maxlength="4000" placeholder="Write a message…" data-input="autogrow" data-key="chat" aria-label="Message" autofocus></textarea><button class="btn btn-primary btn-icon-l" aria-label="Send">${icon('send')}</button></form>`);
      }
      if (!r.messages.length) return;
      const box = SM.$('#chat-msgs');
      const empty = SM.$('.chat-empty', box);
      if (empty) empty.remove();
      box.insertAdjacentHTML('beforeend', r.messages.filter((x) => x.id > last).map((x) => String(bubble(x))).join(''));
      last = r.messages[r.messages.length - 1].id;
      box.scrollTop = box.scrollHeight;
    };
    SM.forms['chat-send'] = async (form, data) => {
      if (!data.body.trim() || !other) return;
      form.body.value = '';
      form.body.style.height = 'auto';
      await SM.post('message_send', { uid: other.id, body: data.body });
      await pull(false);
      drawList();
      form.body.focus();
    };
    await pull(true);
    drawList();
    const timer = setInterval(() => { if (!document.hidden) pull(false).catch(() => {}); }, 5000);
    SM.onLeave(() => clearInterval(timer));
  });
  document.addEventListener('keydown', (e) => {
    if (e.target.dataset && e.target.dataset.key === 'chat' && e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.target.form.requestSubmit(); }
  });
})();
