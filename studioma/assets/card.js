/*
 * Studioma — le panneau d'une carte : titre, étape, responsables, dates, plateformes, script mis
 * en forme, check-list, pièces jointes, validation, indicateurs, commentaires (avec mentions) et
 * historique. Chaque champ s'enregistre dès qu'il change.
 */
(function () {
  'use strict';
  const { html, raw, icon, fmt } = SM;
  const B = SM.board;
  const PRIO = ['No priority', 'Low', 'Medium', 'High', 'Urgent'];
  const P = SM.cardPanel = { id: 0, card: null, modal: null, tab: 'comments', uploads: [] };

  const typing = () => { const a = document.activeElement; return !!(P.modal && a && P.modal.el.contains(a) && (a.matches('input:not([type=checkbox]):not([type=file]), textarea, [contenteditable="true"]'))); };
  const part = (id, content) => { const el = P.modal && SM.$('#' + id, P.modal.el); if (el) SM.set(el, content); };

  /** Lien d'un email ou du menu de la carte : ?cardshare=1[&req&do] ouvre son partage, ?publish=1 « Share & publish ». */
  const fromQuery = () => {
    const q = SM.query();
    if (SM.guest || !P.card || (!q.get('cardshare') && !q.get('publish'))) return;
    history.replaceState(null, '', '#' + B.cardPath(P.id));
    if (q.get('cardshare') && P.card.can.share) SM.cardShareDialog(B.id, P.id, { req: +q.get('req') || 0, do: q.get('do') || '' });
    else if (q.get('publish') && SM.sharePublish) SM.sharePublish(P.card);
  };
  P.open = async (id) => {
    if (P.id === id && P.modal) { fromQuery(); return; }
    P.close(true);
    P.id = id;
    P.card = null;
    P.tab = 'comments';
    const m = P.modal = SM.modal(html`<div class="cp"><div class="cp-loading">${SM.loading(4)}</div></div>`, { side: true, cls: 'drawer-card', label: 'Card', onClose: () => { if (P.modal === m) { P.modal = null; P.id = 0; SM.go(B.home()); } } });
    try {
      const r = await SM.get('card_get', { id });
      if (P.modal !== m) return;
      P.card = r.card;
      draw();
      fromQuery();
      // Dépôt de fichiers n'importe où sur le panneau.
      m.el.addEventListener('dragover', (e) => { if (P.card.can.upload && e.dataTransfer && Array.from(e.dataTransfer.types).includes('Files')) { e.preventDefault(); m.el.classList.add('is-drop'); } });
      m.el.addEventListener('dragleave', (e) => { if (!m.el.contains(e.relatedTarget)) m.el.classList.remove('is-drop'); });
      m.el.addEventListener('drop', (e) => { m.el.classList.remove('is-drop'); if (P.card.can.upload && e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); upload(Array.from(e.dataTransfer.files)); } });
    } catch (e) {
      if (P.modal !== m) return;
      SM.toast(e.message, 'error');
      m.close();
    }
  };
  P.close = (silent) => {
    const m = P.modal;
    P.modal = null;
    P.id = 0;
    if (m) m.close(true);
    if (!silent && B.id) SM.go(B.home());
  };
  /** Après une synchronisation du tableau : la carte a-t-elle changé (ou disparu) ? */
  P.sync = async (team) => {
    if (!P.card) return;
    const lite = B.card(P.id);
    if (!lite) { SM.toast('This card was archived or deleted.'); P.close(); return; }
    if (team && !typing()) { drawProps(); drawApproval(); }
    if (lite.updated === P.card.updated && lite.col_id === P.card.col_id && lite.n_comments === P.card.comments.length && lite.n_files === P.card.files.length && lite.n_checks === P.card.checks.length && lite.n_done === P.card.checks.filter((k) => k.done).length && lite.approval === P.card.approval) return;
    if (typing()) return;   // on ne redessine pas sous les doigts : la prochaine synchronisation s'en chargera
    await refresh();
  };
  P.refresh = () => refresh();
  async function refresh() {
    const id = P.id;
    try {
      const r = await SM.get('card_get', { id });
      if (P.id !== id || !P.modal) return;
      P.card = r.card;
      if (typing()) { drawFeed(); return; }
      draw();
    } catch (e) { /* la carte a disparu : la synchronisation du tableau fermera le panneau */ }
  }
  /** Enregistre un ou plusieurs champs. $redraw : sections à redessiner ensuite. */
  async function save(fields, redraw) {
    const id = P.id;
    setSaving(true);
    try {
      const r = await SM.post('card_save', Object.assign({ id }, fields));
      if (P.id !== id) return;
      P.card = r.card;
      (redraw || []).forEach((fn) => fn());
      drawFeed();
      setSaving(false);
      B.reload();
    } catch (e) {
      setSaving(false);
      SM.toast(e.message, 'error');
      if (P.id === id) refresh();
    }
  }
  const setSaving = (on) => { const el = P.modal && SM.$('.cp-saving', P.modal.el); if (el) { el.textContent = on ? 'Saving…' : 'Saved'; el.classList.toggle('is-on', true); if (!on) setTimeout(() => el.classList.remove('is-on'), 1500); } };

  /* ---------------------------------------------------------------- dessin */
  function draw() {
    const c = P.card;
    if (!P.modal) return;
    const scroll = SM.$('.cp-scroll', P.modal.el);
    const top = scroll ? scroll.scrollTop : 0;
    SM.set(P.modal.el, html`<div class="cp">
      <header class="cp-head" id="cp-head" role="none"></header>
      <div class="cp-scroll">
        <div class="cp-main">
          ${c.can.edit ? html`<textarea class="cp-title" rows="1" maxlength="200" data-change="card-title" data-input="autogrow" aria-label="Card title">${c.title}</textarea>` : html`<h1 class="cp-title">${c.title}</h1>`}
          <section class="cp-props" id="cp-props"></section>
          <section class="cp-pubs" id="cp-pubs"></section>
          <section class="cp-sec"><h3>${icon('edit')}Description & script<span class="cp-saving" aria-live="polite"></span></h3>
            ${c.can.edit ? SM.editor(c.body, { placeholder: 'Write the brief, the script, the caption, the notes…', name: 'card-body', label: 'Description & script' }) : (c.body ? html`<div class="rich">${SM.rich(c.body)}</div>` : html`<p class="muted">No description yet.</p>`)}</section>
          <section class="cp-sec" id="cp-checks"></section>
          <section class="cp-sec" id="cp-files"></section>
          <section class="cp-sec" id="cp-approval"></section>
          <section class="cp-sec" id="cp-metrics"></section>
          <section class="cp-sec" id="cp-feed"></section>
        </div>
      </div>
      <div class="cp-drop">${icon('upload')}Drop files to attach them</div>
    </div>`);
    drawHead(); drawProps(); drawPubs(); drawChecks(); drawFiles(); drawApproval(); drawMetrics(); drawFeed();
    SM.$('.cp-scroll', P.modal.el).scrollTop = top;
    const t = SM.$('textarea.cp-title', P.modal.el);
    if (t) grow(t);
  }
  const grow = (t) => { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; };
  SM.inputs.autogrow = grow;

  function drawHead() {
    const c = P.card;
    if (P.modal && P.modal.el) P.modal.el.setAttribute('aria-label', 'Card: ' + c.title);   // nom de la fenêtre
    part('cp-head', html`
      <button class="cp-status c-${c.level ? c.level.color : 'slate'}" ${c.can.edit ? raw('data-act="cp-move" title="Move this card"') : 'disabled'}>
        <span>${c.level ? c.level.icon + ' ' + c.level.name : ''}</span>${icon('chevron-right')}<b>${c.col ? c.col.name : ''}</b>${c.can.edit ? icon('chevron-down') : ''}</button>
      ${c.can.handoff ? html`<button class="btn btn-primary btn-sm" data-act="cp-handoff">Send to ${c.level.target}${icon('arrow-right')}</button>` : ''}
      ${SM.approvalBadge(c.approval)}
      <span class="grow"></span>
      ${!SM.guest && (c.can.share || c.can.publish) ? html`<button class="btn btn-soft btn-sm cp-sharepub" data-act="cp-sharepub" aria-haspopup="menu" aria-label="Share & publish">${icon('share')}<span>Share & publish</span></button>` : ''}
      <button class="btn-icon" data-act="cp-menu" aria-label="Card options">${icon('more')}</button>
      <button class="btn-icon" data-act="modal-close" aria-label="Close">${icon('x')}</button>`);
  }
  /** Indicateurs de « Share & publish » : réalisation publiée, opportunité ouverte ou fermée, partage de la carte. */
  function drawPubs() {
    const c = P.card;
    if (SM.guest || !c.pubs) { part('cp-pubs', ''); return; }
    const sh = c.shared || { links: 0, guests: 0, requests: 0 };
    const items = c.pubs.map((p) => (p.kind === 'achievement'
      ? html`<a class="pub-pill pub-ach${p.status === 'published' ? '' : ' is-closed'}" href="#/pub/${p.id}">${icon('award')}<span>${p.status === 'published' ? 'Published as Achievement' : 'Achievement unpublished'}</span></a>`
      : html`<a class="pub-pill pub-opp${p.status === 'open' ? '' : ' is-closed'}" href="#/pub/${p.id}">${icon('briefcase')}<span>${p.status === 'open' ? 'Open Opportunity' : 'Closed Opportunity'}</span>${p.proposals ? html`<b>${fmt.plural(p.proposals, 'proposal')}${p.new ? ` · ${p.new} new` : ''}</b>` : ''}</a>`));
    if (sh.links || sh.guests) {
      items.push(html`<button class="pub-pill pub-shared" data-act="cp-share" ${c.can.share ? '' : 'disabled'}>${icon('link')}<span>Shared with guests</span><b>${fmt.plural(sh.links, 'link')}${sh.guests ? ' · ' + fmt.plural(sh.guests, 'guest') : ''}${sh.requests ? ` · ${sh.requests} request${sh.requests > 1 ? 's' : ''}` : ''}</b></button>`);
    }
    part('cp-pubs', items.length ? html`<div class="pub-pills">${items}</div>` : '');
  }
  P.drawPubs = () => drawPubs();
  function drawProps() {
    const c = P.card;
    const e = c.can.edit;
    const dis = e ? '' : 'disabled';
    const assignee = c.assignee ? B.member(c.assignee) : null;
    const members = c.members.map((id) => B.member(id)).filter(Boolean);
    const due = fmt.due(c.due);
    // Un tag en cours de saisie survit au redessin (un autre champ vient d'être enregistré).
    const typed = P.modal && SM.$('#cp-props .chips-in', P.modal.el);
    const typedValue = typed ? typed.value : '';
    const typedFocus = !!typed && document.activeElement === typed;
    part('cp-props', html`
      <div class="prop"><span class="prop-k">${icon('user')}Assignee</span>
        <button class="prop-v prop-btn" ${c.can.reassign ? raw('data-act="cp-assignee"') : 'disabled'}>${assignee ? html`${SM.avatar(assignee, 'av-s')}<span>${assignee.name}</span>` : html`<span class="muted">Nobody yet</span>`}</button></div>
      <div class="prop"><span class="prop-k">${icon('users')}Collaborators</span>
        <button class="prop-v prop-btn" ${c.can.reassign ? raw('data-act="cp-members"') : 'disabled'}>${members.length ? SM.avatars(members, 6) : html`<span class="muted">${c.can.reassign ? 'Add people' : 'None'}</span>`}</button></div>
      <div class="prop"><span class="prop-k">${icon('calendar')}Due date</span>
        <span class="prop-v"><input class="input input-sm${c.due && c.col && c.col.kind === '' ? ' due-' + due : ''}" type="date" value="${c.due}" data-change="card-field" data-field="due" aria-label="Due date" ${dis}></span></div>
      <div class="prop"><span class="prop-k">${icon('flag')}Priority</span>
        <button class="prop-v prop-btn" ${e ? raw('data-act="cp-priority"') : 'disabled'}><span class="prio prio-${c.priority}">${icon('flag')}</span><span class="${c.priority ? '' : 'muted'}">${PRIO[c.priority]}</span></button></div>
      <div class="prop"><span class="prop-k">${icon('globe')}Platforms</span>
        <button class="prop-v prop-btn prop-wrap" ${e ? raw('data-act="cp-platforms"') : 'disabled'}>${c.platforms.length ? c.platforms.map((p) => html`<span class="chip chip-pf pf-${p}">${SM.ref.platforms[p]}</span>`) : html`<span class="muted">${e ? 'Choose platforms' : 'None'}</span>`}</button></div>
      <div class="prop"><span class="prop-k">${icon('layers')}Content type</span>
        <span class="prop-v"><select class="input input-sm" data-change="card-field" data-field="ctype" aria-label="Content type" ${dis}><option value="">—</option>${Object.entries(SM.ref.content_types).map(([k, l]) => html`<option value="${k}" ${c.ctype === k ? 'selected' : ''}>${l}</option>`)}</select></span></div>
      <div class="prop"><span class="prop-k">${icon('send')}Publication</span>
        <span class="prop-v"><input class="input input-sm" type="datetime-local" value="${c.publish_at.length === 10 ? c.publish_at + 'T09:00' : c.publish_at}" data-change="card-field" data-field="publish_at" aria-label="Publication date and time" ${dis}></span></div>
      <div class="prop"><span class="prop-k">${icon('link')}Published link</span>
        <span class="prop-v row"><input class="input input-sm grow" type="url" inputmode="url" placeholder="https://…" value="${c.publish_url}" data-change="card-field" data-field="publish_url" aria-label="Link to the published content" ${dis}>
          ${c.publish_url ? html`<a class="btn-icon" href="${c.publish_url}" target="_blank" rel="noopener noreferrer" title="Open the published content" aria-label="Open the published content">${icon('external')}</a>` : ''}</span></div>
      <div class="prop prop-wide"><span class="prop-k">${icon('tag')}Tags</span>
        <span class="prop-v">${e ? SM.chips.render('tags', c.tags, { change: 'card-tags', max: 12, len: 30, placeholder: 'Add a tag…' }) : (c.tags.length ? c.tags.map((t) => html`<span class="tag">#${t}</span>`) : html`<span class="muted">None</span>`)}</span></div>
      <p class="cp-by muted small">Created by ${c.creator ? c.creator.name : 'a former member'} · ${fmt.date(c.created)}</p>`);
    const again = (typedValue || typedFocus) && SM.$('#cp-props .chips-in', P.modal.el);
    if (again) { again.value = typedValue; if (typedFocus) again.focus(); }
  }
  function drawChecks() {
    const c = P.card;
    const done = c.checks.filter((k) => k.done).length;
    if (!c.checks.length && !c.can.edit) { part('cp-checks', ''); return; }
    part('cp-checks', html`<h3>${icon('checklist')}Checklist${c.checks.length ? html`<span class="count">${done}/${c.checks.length}</span>` : ''}</h3>
      ${c.checks.length ? html`<div class="progress"><i style="width:${Math.round((done / c.checks.length) * 100)}%"></i></div>` : ''}
      <ul class="checks">${c.checks.map((k) => html`<li class="${k.done ? 'is-done' : ''}"><label><input type="checkbox" data-change="check-toggle" data-id="${k.id}" ${k.done ? 'checked' : ''} ${c.can.edit ? '' : 'disabled'}><span>${k.text}</span></label>
        ${c.can.edit ? html`<button class="btn-icon btn-xs" data-act="check-delete" data-id="${k.id}" aria-label="Remove “${k.text}”">${icon('x')}</button>` : ''}</li>`)}</ul>
      ${c.can.edit ? html`<form class="check-add" data-form="check-add">${icon('plus')}<input class="input input-sm" name="text" maxlength="300" placeholder="Add a step…" aria-label="New checklist item" autocomplete="off"></form>` : ''}`);
  }
  function drawFiles() {
    const c = P.card;
    if (!c.files.length && !c.can.upload) { part('cp-files', ''); return; }
    const media = c.files.filter((f) => f.kind !== 'doc');
    const docs = c.files.filter((f) => f.kind === 'doc');
    const del = (f) => (c.can.upload && f.mine ? html`<button class="btn-icon btn-xs file-x" data-act="file-delete" data-id="${f.id}" data-name="${f.name}" aria-label="Remove ${f.name}">${icon('x')}</button>` : '');
    part('cp-files', html`<h3>${icon('paperclip')}Attachments${c.files.length ? html`<span class="count">${c.files.length}</span>` : ''}
        ${c.can.upload ? html`<label class="btn btn-soft btn-sm cp-h-btn">${icon('upload')}Add files<input type="file" multiple hidden data-change="card-upload"></label>` : ''}</h3>
      ${media.length ? html`<div class="files">${media.map((f) => html`<figure class="file-thumb">
          ${f.kind === 'image' ? html`<button class="file-img" data-act="lightbox" data-token="${f.token}" data-name="${f.name}"><img src="${SM.fileUrl(f.token, true)}" alt="${f.name}" loading="lazy"></button>`
            : html`<video src="${SM.fileUrl(f.token)}" controls preload="metadata"></video>`}
          <figcaption><span title="${f.name}">${f.name}</span><a class="btn-icon btn-xs" href="${SM.fileUrl(f.token, false, true)}" title="Download" aria-label="Download ${f.name}">${icon('download')}</a>${del(f)}</figcaption></figure>`)}</div>` : ''}
      ${docs.length ? html`<div class="list">${docs.map((f) => html`<div class="list-row"><span class="list-ico">${icon('file')}</span><span class="grow"><b>${f.name}</b><small class="muted">${fmt.size(f.size)}${f.user ? ' · ' + f.user.name : ''} · ${fmt.ago(f.created)}</small></span>
          <a class="btn btn-soft btn-sm" href="${SM.fileUrl(f.token, false, true)}">${icon('download')}Download</a>${del(f)}</div>`)}</div>` : ''}
      ${P.uploads.length ? html`<div class="uploads">${P.uploads.map((u) => html`<div class="upload"><span>${u.name}</span><div class="progress"><i style="width:${Math.round(u.p * 100)}%"></i></div></div>`)}</div>` : ''}
      ${!c.files.length && !P.uploads.length ? html`<p class="muted small">Images, videos and documents — visible to the members of this Studio only. Drop files anywhere on this panel.</p>` : ''}`);
  }
  function drawApproval() {
    const c = P.card;
    const last = c.approvals.find((a) => a.status !== 'cancelled');
    const me = SM.me ? SM.me.id : -1;
    const approvers = B.data.members.filter((u) => ['owner', 'manager', 'client'].includes(u.role) && u.id !== me)
      .map((u) => ({ id: u.id, label: `${u.name} — ${SM.ref.roles[u.role]}` }))
      .concat((B.data.guests || []).filter((g) => !g.card || g.card === P.id).map((g) => ({ id: g.id, label: `${g.name} — Guest (${g.role === 'editor' ? 'Editor' : 'Commenter'})` })));
    const moveHint = c.level && c.level.final && c.level.handoff !== 'off' && c.col && c.level.final.id !== c.col.id ? html`<p class="muted small">Once approved, this card moves to <b>${c.level.final.name}</b>${c.level.handoff === 'auto' && c.level.target ? html` and is handed off to <b>${c.level.target}</b>` : ''}.</p>` : '';
    let body = '';
    if (c.approval === 'pending' && last) {
      body = html`<div class="appr-box appr-pending">${SM.avatar(last.approver, 'av-m')}<div class="grow"><b>Waiting for ${last.approver ? last.approver.name : 'the approver'}</b><p class="muted small">Requested by ${last.by ? last.by.name : ''} ${fmt.ago(last.created)}</p>${last.note ? html`<p class="appr-note">“${last.note}”</p>` : ''}${c.can.decide ? moveHint : ''}</div></div>
        ${c.can.decide ? html`<form class="stack" data-form="approval-decide" data-id="${last.id}"><textarea class="input" name="reply" rows="2" maxlength="1000" placeholder="Feedback (required to request changes)" aria-label="Feedback"></textarea>
          <p class="form-error" data-error hidden></p>
          <div class="row end"><button class="btn btn-soft" name="decision" value="changes" data-decision="changes">${icon('alert')}Request changes</button><button class="btn btn-primary" name="decision" value="approved" data-decision="approved">${icon('check')}Approve</button></div></form>`
          : c.can.request ? html`<div class="row end"><button class="btn btn-ghost btn-sm" data-act="approval-cancel" data-id="${last.id}">Cancel request</button></div>` : ''}`;
    } else {
      const status = last && c.approval === last.status ? (last.status === 'approved'
        ? html`<div class="appr-box appr-approved">${icon('check-circle')}<div><b>Approved by ${last.approver ? last.approver.name : ''}</b><p class="muted small">${fmt.ago(last.decided)}${last.reply ? ' — “' + last.reply + '”' : ''}</p></div></div>`
        : html`<div class="appr-box appr-changes">${icon('alert')}<div><b>${last.approver ? last.approver.name : ''} requested changes</b><p class="appr-note">“${last.reply}”</p><p class="muted small">${fmt.ago(last.decided)}</p></div></div>`) : '';
      const form = c.can.request ? (approvers.length ? html`<form class="appr-form" data-form="approval-request">
          <label class="field"><span>Approver</span><select class="input input-sm" name="approver">${approvers.map((u) => html`<option value="${u.id}">${u.label}</option>`)}</select></label>
          <label class="field grow"><span>Note <em>optional</em></span><input class="input input-sm" name="note" maxlength="1000" placeholder="What should they look at?"></label>
          <button class="btn btn-soft">${icon('shield')}${last && c.approval === 'changes' ? 'Ask again' : 'Request approval'}</button>
          <p class="form-error" data-error hidden></p></form>${moveHint}`
        : html`<p class="muted small">Invite a client, a manager or another owner to this Studio — or share it with a client as a guest — to request their approval.</p>`) : '';
      body = html`${status}${form}`;
      if (!status && !form) { part('cp-approval', ''); return; }
    }
    part('cp-approval', html`<h3>${icon('shield')}Approval</h3>${body}`);
  }
  function drawMetrics() {
    const c = P.card;
    const has = Object.keys(c.metrics).length > 0;
    const published = (c.col && c.col.kind === 'done') || c.publish_url;
    if (!has && !(published && c.can.edit)) { part('cp-metrics', ''); return; }
    part('cp-metrics', html`<details ${has ? 'open' : ''}><summary><h3>${icon('chart')}Performance <em>optional</em></h3></summary>
      <p class="muted small">Self-reported results, private to this Studio. They can feed a case study if you decide to publish one.</p>
      <div class="metrics-in">${SM.ref.metrics.map((k) => html`<label class="field"><span>${k[0].toUpperCase() + k.slice(1)}</span><input class="input input-sm" type="number" min="0" inputmode="numeric" value="${c.metrics[k] || ''}" data-change="card-metric" data-k="${k}" ${c.can.edit ? '' : 'disabled'}></label>`)}</div></details>`);
  }
  function drawFeed() {
    const c = P.card;
    if (!P.modal) return;
    const keep = SM.$('#cp-comment', P.modal.el);
    const draft = keep ? keep.value : '';
    const focused = keep && document.activeElement === keep;
    part('cp-feed', html`<div class="tabs tabs-sm"><button class="tab${P.tab === 'comments' ? ' is-active' : ''}" data-act="cp-tab" data-tab="comments">${icon('comment')}Comments <span class="count">${c.comments.length}</span></button>
        <button class="tab${P.tab === 'history' ? ' is-active' : ''}" data-act="cp-tab" data-tab="history">${icon('history')}History</button></div>
      ${P.tab === 'comments' ? html`
        ${c.comments.length ? html`<ul class="comments">${c.comments.map((m) => html`<li class="comment">${SM.avatar(m.user, 'av-m')}<div class="comment-body"><header><b>${m.user ? m.user.name : 'Former member'}</b>${m.user && m.user.guest ? html`<span class="chip chip-guest">Guest</span>` : ''}<small class="muted">${fmt.ago(m.created)}</small>
          ${m.mine ? html`<button class="btn-icon btn-xs" data-act="comment-delete" data-id="${m.id}" aria-label="Delete comment">${icon('trash')}</button>` : ''}</header><p>${SM.linkify(m.body)}</p></div></li>`)}</ul>` : html`<p class="muted small">No comment yet.${c.can.comment ? ' Start the conversation — type @ to mention a teammate.' : ''}</p>`}
        ${c.can.comment ? html`<form class="comment-form" data-form="comment-add">${SM.avatar(SM.me || { name: (B.data.studio.guest || {}).email || 'Guest' }, 'av-m')}<div class="grow"><textarea class="input" id="cp-comment" name="body" rows="2" maxlength="4000" placeholder="Write a comment… @ to mention" data-input="mention" aria-label="Comment"></textarea>
          <div class="row end"><span class="muted small grow">Ctrl + Enter to send</span><button class="btn btn-primary btn-sm">${icon('send')}Comment</button></div></div></form>` : ''}`
        : html`<ul class="events">${c.events.map((e) => SM.eventRow(e, false))}</ul>`}`);
    const now = SM.$('#cp-comment', P.modal.el);
    if (now && draft) { now.value = draft; if (focused) now.focus(); }
  }

  /* ---------------------------------------------------------------- actions */
  SM.actions['cp-tab'] = (el) => { P.tab = el.dataset.tab; drawFeed(); };
  SM.actions['cp-menu'] = (el) => B.cardMenu(el, P.id, { align: 'right' });
  SM.actions['cp-sharepub'] = (el) => SM.sharePublishMenu(el, P.card);
  SM.actions['cp-share'] = () => SM.cardShareDialog(B.id, P.id);
  SM.actions['cp-move'] = (el) => B.pickColumn(el, P.card.col_id, async (col) => {
    const r = await B.run('card_move', { id: P.id, col_id: col.id, index: 9999 });
    if (r && r.handoff) SM.toast(`Handed off to ${r.handoff.level} › ${r.handoff.col}`, 'handoff');
    refresh();
  });
  SM.actions['cp-handoff'] = async () => {
    const r = await B.run('card_handoff', { id: P.id });
    if (r) SM.toast(`Handed off to ${r.handoff.level} › ${r.handoff.col}`, 'handoff');
    refresh();
  };
  SM.changes['card-title'] = (el) => { const v = el.value.trim(); if (!v) { el.value = P.card.title; grow(el); return; } if (v !== P.card.title) save({ title: v }); };
  document.addEventListener('keydown', (e) => {
    if (e.target.classList && e.target.classList.contains('cp-title') && e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
    if (e.target.id === 'cp-comment' && e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); e.target.form.requestSubmit(); }
  });
  SM.changes['card-field'] = (el) => { if (P.card && el.value !== P.card[el.dataset.field]) save({ [el.dataset.field]: el.value }, el.dataset.field === 'publish_url' ? [drawProps, drawMetrics] : el.dataset.field === 'due' ? [drawProps] : []); };
  SM.changes['card-tags'] = (el) => save({ tags: SM.chips.value(el) });
  SM.changes['card-metric'] = () => {
    const m = {};
    SM.$$('[data-change="card-metric"]', P.modal.el).forEach((i) => { if (+i.value > 0) m[i.dataset.k] = Math.round(+i.value); });
    save({ metrics: m });
  };
  // Le script s'enregistre quand on quitte l'éditeur.
  document.addEventListener('focusout', (e) => {
    const ed = e.target.closest && e.target.closest('[data-rich="card-body"]');
    if (!ed || !P.card) return;
    const v = SM.editorValue(ed);
    if (v !== P.card.body) save({ body: v });
  });
  SM.actions['cp-assignee'] = (el) => B.pickMember(el, { current: P.card.assignee, none: 'Nobody' }, (uid) => save({ assignee: uid }, [drawProps]));
  SM.actions['cp-members'] = (el) => {
    const set = new Set(P.card.members);
    SM.menu(el, B.data.members.map((u) => ({ label: u.name, hint: SM.ref.roles[u.role], html: SM.avatar(u, 'av-s'), checked: set.has(u.id), act: (it) => { if (it.checked) set.add(u.id); else set.delete(u.id); save({ members: Array.from(set) }, [drawProps]); } })), { keep: true, search: B.data.members.length > 6, width: 260 });
  };
  SM.actions['cp-priority'] = (el) => B.pickPriority(el, P.card.priority, (p) => save({ priority: p }, [drawProps]));
  SM.actions['cp-platforms'] = (el) => {
    const set = new Set(P.card.platforms);
    SM.menu(el, Object.entries(SM.ref.platforms).map(([k, l]) => ({ label: l, checked: set.has(k), act: (it) => { if (it.checked) set.add(k); else set.delete(k); save({ platforms: Array.from(set) }, [drawProps]); } })), { keep: true, width: 220 });
  };

  /** Petite écriture liée à la carte, puis rechargement de la carte et du tableau. */
  const act = async (action, body) => { const r = await SM.try(SM.post(action, body)); if (r) { await refresh(); B.reload(); } return r; };
  SM.forms['check-add'] = async (form, data) => {
    if (!data.text.trim()) return;
    await SM.post('check_save', { card_id: P.id, text: data.text });
    const r = await SM.get('card_get', { id: P.id });
    P.card = r.card;
    drawChecks(); drawFeed();
    const input = SM.$('.check-add input', P.modal.el);
    if (input) input.focus();
    B.reload();
  };
  SM.changes['check-toggle'] = async (el) => {
    const k = P.card.checks.find((x) => x.id === +el.dataset.id);
    if (k) k.done = el.checked;
    drawChecks();
    if (!await SM.try(SM.post('check_save', { id: +el.dataset.id, done: el.checked }))) refresh(); else B.reload();
  };
  SM.actions['check-delete'] = async (el) => { P.card.checks = P.card.checks.filter((x) => x.id !== +el.dataset.id); drawChecks(); if (!await SM.try(SM.post('check_delete', { id: +el.dataset.id }))) refresh(); else B.reload(); };

  async function upload(files) {
    const id = P.id;
    for (const file of files.slice(0, 10)) {
      const u = { name: file.name, p: 0 };
      P.uploads.push(u);
      drawFiles();
      try {
        await SM.upload('card', file, { card_id: id }, (p) => { u.p = p; if (P.id === id) drawFiles(); });
      } catch (e) { SM.toast(e.message, 'error'); }
      P.uploads = P.uploads.filter((x) => x !== u);
    }
    if (P.id === id) { await refresh(); drawFiles(); }
    B.reload();
  }
  SM.changes['card-upload'] = (el) => { const files = Array.from(el.files); el.value = ''; if (files.length) upload(files); };
  SM.actions['file-delete'] = async (el) => {
    if (!await SM.confirm({ title: 'Remove this attachment?', text: `“${el.dataset.name}” will be deleted for everyone.`, ok: 'Remove', danger: true })) return;
    act('attach_delete', { id: +el.dataset.id });
  };
  SM.actions.lightbox = (el) => SM.modal(html`<figure class="lightbox"><img src="${SM.fileUrl(el.dataset.token)}" alt="${el.dataset.name}"><figcaption><span>${el.dataset.name}</span><a class="btn btn-soft btn-sm" href="${SM.fileUrl(el.dataset.token, false, true)}">${icon('download')}Download</a><button class="btn-icon" data-act="modal-close" aria-label="Close">${icon('x')}</button></figcaption></figure>`, { size: 'xl', cls: 'modal-bare' });

  SM.forms['approval-request'] = async (form, data) => {
    await SM.post('approval_request', { card_id: P.id, approver: /^g\d+$/.test(data.approver) ? data.approver : +data.approver, note: data.note });
    SM.toast('Approval requested');
    await refresh();
    B.reload();
  };
  SM.forms['approval-decide'] = async (form, data, e) => {
    const decision = (e.submitter && e.submitter.dataset.decision) || 'approved';
    const r = await SM.post('approval_decide', { id: +form.dataset.id, decision, reply: data.reply });
    if (r.handoff) SM.toast(`Approved — handed off to ${r.handoff.level} › ${r.handoff.col}`, 'handoff');
    else SM.toast(decision === 'approved' ? (r.moved ? `Approved — moved to ${r.moved}` : 'Approved') : 'Changes requested');
    await refresh();
    B.reload();
  };
  SM.actions['approval-cancel'] = (el) => act('approval_cancel', { id: +el.dataset.id });

  SM.forms['comment-add'] = async (form, data) => {
    if (!data.body.trim()) { form.body.focus(); return; }
    await SM.post('comment_add', { card_id: P.id, body: data.body });
    form.body.value = '';
    await refresh();
    drawFeed();
    B.reload();
  };
  SM.actions['comment-delete'] = async (el) => {
    if (!await SM.confirm({ title: 'Delete this comment?', ok: 'Delete', danger: true })) return;
    act('comment_delete', { id: +el.dataset.id });
  };
  /** Saisie de « @ » dans un commentaire : propose les membres du Studio. */
  SM.inputs.mention = (el) => {
    grow(el);
    const before = el.value.slice(0, el.selectionStart);
    const m = before.match(/(^|\s)@([a-z0-9._-]*)$/i);
    if (!m || !B.data) { SM.closeMenus(); return; }
    const q = m[2].toLowerCase();
    const list = B.data.members.filter((u) => (!SM.me || u.id !== SM.me.id) && (u.handle.includes(q) || u.name.toLowerCase().includes(q))).slice(0, 6);
    if (!list.length) { SM.closeMenus(); return; }
    SM.closeMenus();
    SM.menu(el, list.map((u) => ({ label: u.name, hint: '@' + u.handle, html: SM.avatar(u, 'av-s'), act: () => {
      const start = before.length - m[2].length;
      el.value = el.value.slice(0, start) + u.handle + ' ' + el.value.slice(el.selectionStart);
      el.focus();
      el.selectionStart = el.selectionEnd = start + u.handle.length + 1;
    } })), { width: 250 });
  };
})();
