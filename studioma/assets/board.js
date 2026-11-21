/*
 * Studioma — un Studio : le tableau (niveaux, colonnes, cartes), le glisser-déposer, le passage
 * de relais entre niveaux, la sélection multiple, les filtres, le calendrier, « Mon travail »,
 * le tableau de bord, l'historique et les archives. Le panneau d'une carte est dans card.js.
 *
 * Le tableau se resynchronise tout seul : toutes les 4 secondes, il demande au serveur si le
 * Studio a changé (numéro de version « rev ») et se redessine si un coéquipier a modifié quelque chose.
 */
(function () {
  'use strict';
  const { html, raw, icon, fmt } = SM;
  const B = SM.board = { id: 0, data: null, view: 'board', focus: 0, filter: { q: '', mine: false, assignee: 0, platform: '' }, selected: new Set(), adding: null, editing: null, collapsed: {}, writing: 0, cal: null, undrag: [] };
  const PRIO = ['', 'Low', 'Medium', 'High', 'Urgent'];
  const HANDOFF = { auto: ['zap', 'Auto'], manual: ['hand', 'Manual'], off: ['x', 'Off'] };

  /* ------------------------------------------------------------- données */
  const can = (cap) => !!(B.data && B.data.studio.can[cap]);
  const colsOf = (levelId) => B.data.cols.filter((c) => c.level_id === levelId).sort((a, b) => a.pos - b.pos || a.id - b.id);
  const cardsOf = (colId) => B.data.cards.filter((c) => c.col_id === colId).sort((a, b) => a.pos - b.pos || a.id - b.id);
  B.level = (id) => B.data.levels.find((l) => l.id === id);
  B.col = (id) => B.data.cols.find((c) => c.id === id);
  B.card = (id) => B.data.cards.find((c) => c.id === id);
  B.member = (id) => B.data.members.find((u) => u.id === id) || null;
  // Invité d'un lien de partage : le serveur dit, carte par carte et colonne par colonne, ce qu'il peut modifier.
  B.canEditCard = (c) => (SM.guest ? !!(c && c.editable) : can('edit') || (can('work') && (c.assignee === SM.me.id || c.created_by === SM.me.id || c.members.includes(SM.me.id))));
  const canAdd = (col) => (SM.guest ? !!col.writable : can('work'));
  /** Niveau qui reçoit les cartes d'un niveau (celui choisi, sinon le suivant). */
  const targetOf = (level) => {
    const chosen = level.handoff_level && level.handoff_level !== level.id ? B.level(level.handoff_level) : null;
    return chosen || B.data.levels[B.data.levels.indexOf(level) + 1] || null;
  };
  const visible = (c) => {
    const f = B.filter;
    if (f.mine && SM.me && c.assignee !== SM.me.id && !c.members.includes(SM.me.id)) return false;
    if (f.assignee && c.assignee !== f.assignee) return false;
    if (f.platform && !c.platforms.includes(f.platform)) return false;
    if (f.q) { const q = f.q.toLowerCase(); if (!c.title.toLowerCase().includes(q) && !c.tags.some((t) => t.toLowerCase().includes(q))) return false; }
    return true;
  };
  const filtering = () => B.filter.q || B.filter.mine || B.filter.assignee || B.filter.platform;
  const base = () => (SM.guest ? '/s/' + SM.guest : '/studio/' + B.id + (B.view === 'board' ? '' : '/' + B.view));
  B.home = base;
  B.cardPath = (id) => base() + '/card/' + id;

  /* ------------------------------------------------------- synchronisation */
  let alive = () => false;
  function apply(r) {
    const head = B.data ? JSON.stringify([B.data.studio, B.data.members]) : '';
    B.data = r;
    B.selected.forEach((id) => { if (!B.card(id)) B.selected.delete(id); });
    const changed = JSON.stringify([r.studio, r.members]) !== head;
    if (changed) drawHead();
    drawBody();
    if (r.counts) SM.refreshCounts(r.counts);
    // Le panneau d'une carte suit : la carte a changé, ou l'équipe (responsables et validateurs possibles).
    if (SM.cardPanel.id) SM.cardPanel.sync(changed);
  }
  B.reload = async () => {
    try { const r = await SM.get('board', { studio: B.id }); if (alive()) apply(r); } catch (e) { if (alive()) gone(e); }
  };
  /** Exécute une écriture puis recharge le tableau. Retourne la réponse, ou null en cas d'échec ($silent : l'erreur est relancée). */
  B.run = async (action, body, silent) => {
    B.writing++;
    let r = null;
    let err = null;
    try { r = await SM.post(action, body); } catch (e) { err = e; }
    try { await B.reload(); } finally { B.writing--; }
    if (!err) return r;
    if (silent) throw err;
    SM.toast(err.message, 'error');
    return null;
  };
  function gone(e) {
    if (SM.guest) { if ([401, 404, 410].includes(e.status)) SM.guestGone(e); return; }
    if (e.status === 404) { SM.toast('This Studio is no longer available.', 'error'); SM.dropWorkspace(); SM.go('/projects'); }
  }
  async function poll() {
    if (document.hidden || B.writing || B.adding || B.editing || document.body.classList.contains('is-dragging')) return;
    try {
      const r = await SM.get('board', { studio: B.id, rev: B.data.rev });
      if (!alive()) return;
      if (r.same) { SM.refreshCounts(r.counts); return; }
      if (B.writing || B.adding || B.editing || document.body.classList.contains('is-dragging')) return;
      apply(r);
    } catch (e) { if (alive()) gone(e); }
  }

  /* ---------------------------------------------------------------- écran */
  SM.route(/^\/studio\/(\d+)(?:\/(calendar|work|insights))?(?:\/card\/(\d+))?$/, async (m, ctx) => {
    await B.open(+m[1], m[2] || 'board', +m[3] || 0, ctx);
    if (ctx.alive()) guestsFromQuery();
  }, { wide: true, update: (m, prev) => {
    if (m[1] !== prev[1] || (m[2] || '') !== (prev[2] || '') || !B.data) return false;
    if (m[3]) SM.cardPanel.open(+m[3]); else SM.cardPanel.close(true);
    guestsFromQuery();
    return true;
  } });
  /** Lien reçu par email (« Gérer les invités », demande d'un invité) : la fenêtre de partage s'ouvre sur les invités. */
  function guestsFromQuery() {
    const q = SM.query();
    if (!q.get('guests') || !B.data || !can('manage')) return;
    history.replaceState(null, '', '#' + base());
    SM.closeModals();
    SM.shareDialog({ studio_id: B.id }, { tab: 'guests', req: +q.get('req') || 0, do: q.get('do') || '' });
  }
  /** Ouvre le tableau d'un Studio (membre, ou invité quand SM.guest est défini). */
  B.open = async (id, view, cardId, ctx) => {
    if (B.id !== id) Object.assign(B, { focus: SM.ls.get('focus.' + id, 0), filter: { q: '', mine: false, assignee: 0, platform: '' }, collapsed: SM.ls.get('collapsed.' + id, {}), cal: null });
    Object.assign(B, { id, view, data: null, adding: null, editing: null, writing: 0 });
    B.selected.clear();
    alive = ctx.alive;
    SM.set(ctx.view, html`<div class="studio"><div class="studio-loading">${SM.loading(3)}</div></div>`);
    const r = await SM.get('board', { studio: id });
    if (!ctx.alive()) return;
    B.data = r;
    SM.set(ctx.view, html`<div class="studio view-${B.view}"><div id="studio-head"></div><div id="studio-body"></div></div>`);
    drawHead();
    drawBody();
    setupDrag();
    const timer = setInterval(poll, 4000);
    const onVisible = () => { if (!document.hidden) poll(); };
    document.addEventListener('visibilitychange', onVisible);
    SM.onLeave(() => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
      B.undrag.forEach((fn) => fn());
      B.undrag = [];
      SM.cardPanel.close(true);
      alive = () => false;
    });
    if (cardId) SM.cardPanel.open(cardId);
  };

  function drawHead() {
    const s = B.data.studio;
    if (SM.guest) { SM.set(SM.$('#studio-head'), SM.guestHead(B.data)); return; }
    const tab = (view, ic, label) => html`<a class="tab${B.view === view ? ' is-active' : ''}" href="#/studio/${B.id}${view === 'board' ? '' : '/' + view}">${icon(ic)}${label}</a>`;
    SM.set(SM.$('#studio-head'), html`
      <header class="studio-head">
        <div class="crumbs">
          <button class="crumb" data-act="switch-project">${SM.projectMark(s.project, 'pmark-s')}<span>${s.project.name}</span>${icon('chevron-down')}</button>
          <span class="crumb-sep">/</span>
          <button class="crumb crumb-strong" data-act="switch-studio"><span>${s.name}</span>${icon('chevron-down')}</button>
          ${SM.roleBadge(s.role)}${s.archived ? html`<span class="chip">Archived</span>` : ''}
        </div>
        <span class="grow"></span>
        <button class="avatars-btn" data-act="share" title="Members" aria-label="Members">${SM.avatars(B.data.members, 5)}</button>
        <button class="btn btn-soft" data-act="share">${icon('user-plus')}<span>Share</span></button>
        <button class="btn-icon" data-act="studio-menu" aria-label="Studio options">${icon('more')}</button>
      </header>
      <div class="studio-bar">
        <div class="tabs">${tab('board', 'board', 'Board')}${tab('calendar', 'calendar', 'Calendar')}${tab('work', 'checklist', 'My work')}${tab('insights', 'chart', 'Insights')}</div>
        <span class="grow"></span>
        ${B.view === 'board' ? html`<div class="board-tools">
          <label class="search-in">${icon('search')}<input class="input input-sm" placeholder="Filter cards…" value="${B.filter.q}" data-input="board-q" aria-label="Filter cards"></label>
          <button class="btn btn-soft btn-sm${B.filter.mine ? ' is-on' : ''}" data-act="filter-mine" aria-pressed="${B.filter.mine}">${icon('user')}<span>Mine</span></button>
          <button class="btn btn-soft btn-sm${B.filter.assignee || B.filter.platform ? ' is-on' : ''}" data-act="filter-menu">${icon('filter')}<span>Filter</span></button>
        </div>` : ''}
      </div>`);
  }
  function drawBody() {
    const el = SM.$('#studio-body');
    if (!el) return;
    if (B.view === 'calendar') drawCalendar(el);
    else if (B.view === 'work') { if (!el.dataset.work) { el.dataset.work = '1'; SM.set(el, html`<div class="page">${SM.loading(3)}</div>`); SM.renderMyWork(el, { alive }, B.id); } }
    else if (B.view === 'insights') { if (!el.dataset.stats) { el.dataset.stats = '1'; drawInsights(el); } }
    else drawBoard(el);
  }

  /* -------------------------------------------------------------- tableau */
  const dueChip = (c, done) => {
    if (!c.due) return '';
    return html`<span class="due due-${done ? 'done' : fmt.due(c.due)}" title="Due date">${icon('calendar')}${fmt.day(c.due)}</span>`;
  };
  const APPROVAL = { pending: ['clock', 'Awaiting approval'], approved: ['check-circle', 'Approved'], changes: ['alert', 'Changes requested'] };
  SM.approvalBadge = (state) => (APPROVAL[state] ? html`<span class="appr appr-${state}" title="${APPROVAL[state][1]}">${icon(APPROVAL[state][0])}<span>${APPROVAL[state][1]}</span></span>` : '');
  /** Pastilles de « Share & publish » : réalisation publiée, opportunité ouverte ou fermée, carte partagée seule. */
  const pubBadges = (c) => (c.pub && (c.pub.a || c.pub.o || c.pub.s) ? html`<div class="kcard-pubs">
      ${c.pub.a ? html`<span class="pub-badge pub-ach" title="Published as an achievement in the Community">${icon('award')}Achievement</span>` : ''}
      ${c.pub.o ? html`<span class="pub-badge pub-opp${c.pub.o === 'closed' ? ' is-closed' : ''}" title="${c.pub.o === 'closed' ? 'Closed opportunity' : 'Open opportunity in the Community'}">${icon('briefcase')}${c.pub.o === 'closed' ? 'Closed' : 'Open'} opportunity</span>` : ''}
      ${c.pub.s ? html`<span class="pub-badge pub-shared" title="Shared on its own with guests">${icon('link')}Shared</span>` : ''}
    </div>` : '');
  function cardView(c, col, level) {
    const done = col.kind !== '';
    const assignee = c.assignee ? B.member(c.assignee) : null;
    const manual = !SM.guest && level.handoff === 'manual' && level.handoff_col === col.id && targetOf(level) && B.canEditCard(c);
    return html`<article class="kcard${c.priority ? ' kprio-' + c.priority : ''}${B.selected.has(c.id) ? ' is-selected' : ''}${col.kind === 'cancelled' ? ' is-cancelled' : ''}${SM.guest && c.editable ? ' is-editable' : ''}" data-card="${c.id}" data-act="card-open" data-id="${c.id}" tabindex="0" aria-label="${c.title}">
      ${can('edit') ? html`<button class="kcard-check" data-act="card-select" data-id="${c.id}" aria-label="Select card" aria-pressed="${B.selected.has(c.id)}">${icon('check')}</button>` : ''}
      ${c.cover ? html`<img class="kcard-cover" src="${SM.fileUrl(c.cover, true)}" alt="" loading="lazy" draggable="false">` : ''}
      ${c.ctype || c.platforms.length ? html`<div class="kcard-top">${c.ctype ? html`<span class="chip chip-type">${SM.ref.content_types[c.ctype]}</span>` : ''}${c.platforms.slice(0, 3).map((p) => html`<span class="chip chip-pf pf-${p}">${SM.ref.platforms[p]}</span>`)}${c.platforms.length > 3 ? html`<span class="chip">+${c.platforms.length - 3}</span>` : ''}</div>` : ''}
      <h3>${c.title}</h3>
      ${pubBadges(c)}
      ${c.tags.length ? html`<div class="kcard-tags">${c.tags.slice(0, 4).map((t) => html`<span class="tag">#${t}</span>`)}</div>` : ''}
      ${manual ? html`<button class="btn btn-soft btn-sm kcard-send" data-act="card-handoff" data-id="${c.id}">Send to ${targetOf(level).name}${icon('arrow-right')}</button>` : ''}
      ${c.priority || c.due || c.publish_at || c.approval || c.n_checks || c.n_comments || c.n_files || assignee ? html`<footer class="kcard-meta">
        ${c.priority ? html`<span class="prio prio-${c.priority}" title="${PRIO[c.priority]} priority">${icon('flag')}</span>` : ''}
        ${dueChip(c, done)}
        ${c.publish_at ? html`<span class="due due-pub" title="Publication date">${icon('send')}${fmt.day(c.publish_at)}</span>` : ''}
        ${SM.approvalBadge(c.approval)}
        ${c.n_checks ? html`<span class="meta${c.n_done === c.n_checks ? ' is-ok' : ''}" title="Checklist">${icon('checklist')}${c.n_done}/${c.n_checks}</span>` : ''}
        ${c.n_comments ? html`<span class="meta" title="Comments">${icon('comment')}${c.n_comments}</span>` : ''}
        ${c.n_files ? html`<span class="meta" title="Attachments">${icon('paperclip')}${c.n_files}</span>` : ''}
        <span class="grow"></span>${assignee ? SM.avatar(assignee, 'av-s') : ''}
      </footer>` : ''}
    </article>`;
  }
  function colView(col, level) {
    const all = cardsOf(col.id);
    const cards = all.filter(visible);
    const target = targetOf(level);
    const isFinal = level.handoff_col === col.id && level.handoff !== 'off' && target;
    const editing = B.editing && B.editing.type === 'col' && B.editing.id === col.id;
    const adding = B.adding && B.adding.col === col.id;
    const form = (top) => html`<form class="card-new" data-form="card-new" data-col="${col.id}" data-top="${top ? 1 : 0}">
        <textarea class="input" name="title" rows="2" maxlength="200" placeholder="What needs to be created?" aria-label="Card title" data-key="card-new"></textarea>
        <div class="row"><button class="btn btn-primary btn-sm">Add card</button><button type="button" class="btn-icon" data-act="card-new-cancel" aria-label="Cancel">${icon('x')}</button><span class="muted small grow end">Enter to add</span></div></form>`;
    return html`<div class="bcol${col.kind ? ' is-' + col.kind : ''}${col.principal ? ' is-principal' : ''}" data-col="${col.id}">
      <header class="bcol-head">
        ${editing ? html`<input class="input input-sm inline-edit" value="${col.name}" maxlength="60" data-edit="col" data-id="${col.id}" aria-label="Column name">`
          : html`<span class="bcol-name" ${can('manage') ? raw('data-act="col-rename" title="Click to rename"') : ''} data-id="${col.id}">${col.name}</span>`}
        <span class="count">${filtering() ? cards.length + '/' + all.length : all.length}</span>
        ${col.principal ? html`<span class="cbadge" title="Principal column: new cards and cards handed off to this level arrive here">${icon('inbox')}</span>` : ''}
        ${isFinal ? html`<span class="cbadge cbadge-final" title="Final column: cards here are handed off to ${target.name}${level.handoff === 'auto' ? ' automatically' : ' when you send them'}">${icon('zap')}</span>` : ''}
        ${col.kind === 'done' ? html`<span class="cbadge cbadge-done" title="Cards here are done">${icon('check-circle')}</span>` : ''}
        <span class="grow"></span>
        ${canAdd(col) ? html`<button class="btn-icon btn-xs" data-act="card-new" data-col="${col.id}" data-top="1" title="Add a card on top" aria-label="Add a card to ${col.name}">${icon('plus')}</button>` : ''}
        ${can('manage') ? html`<button class="btn-icon btn-xs" data-act="col-menu" data-id="${col.id}" aria-label="Column options">${icon('more')}</button>` : ''}
      </header>
      ${adding && B.adding.top ? form(true) : ''}
      <div class="bcol-cards" data-cards="${col.id}">${cards.map((c) => cardView(c, col, level))}${!cards.length && !adding ? html`<div class="bcol-empty">${filtering() && all.length ? 'No card matches the filter' : canAdd(col) ? 'Drop a card here' : 'No card'}</div>` : ''}</div>
      ${isFinal && col.passed ? html`<div class="bcol-passed" title="Cards that went through this column">${icon('zap')}${fmt.plural(col.passed, 'card')} handed off to ${target.name}</div>` : ''}
      ${adding && !B.adding.top ? form(false) : canAdd(col) ? html`<button class="bcol-add" data-act="card-new" data-col="${col.id}">${icon('plus')}Add card</button>` : ''}
    </div>`;
  }
  function levelView(level) {
    const cols = colsOf(level.id);
    const n = cols.reduce((sum, c) => sum + cardsOf(c.id).length, 0);
    const target = targetOf(level);
    const final = level.handoff_col ? B.col(level.handoff_col) : null;
    const collapsed = !!B.collapsed[level.id] && !B.focus;
    const editing = B.editing && B.editing.type === 'level' && B.editing.id === level.id;
    let pill = '';
    // Un invité ne voit qu'une partie des niveaux : la flèche de relais pourrait désigner un niveau caché.
    if (target && !SM.guest) {
      const [ic, label] = HANDOFF[final ? level.handoff : 'off'];
      const text = !final ? 'No final column' : level.handoff === 'off' ? 'Handoff off' : `${label} handoff`;
      pill = html`<button class="handoff h-${final ? level.handoff : 'off'}" ${can('manage') ? raw('data-act="level-settings"') : 'disabled'} data-id="${level.id}" title="${final && level.handoff !== 'off' ? `Cards reaching “${final.name}” are handed off to ${target.name}` : 'Cards stay in this level'}">${icon(ic)}<span>${text}</span>${icon('arrow-right')}<b>${target.name}</b></button>`;
    }
    return html`<section class="level c-${level.color}${collapsed ? ' is-collapsed' : ''}" data-level="${level.id}">
      <header class="level-head">
        ${can('manage') && !B.focus ? html`<span class="level-grip" title="Drag to reorder levels">${icon('grip')}</span>` : ''}
        <button class="btn-icon btn-xs level-toggle" data-act="level-collapse" data-id="${level.id}" aria-label="${collapsed ? 'Expand' : 'Collapse'} ${level.name}" aria-expanded="${!collapsed}">${icon(collapsed ? 'chevron-right' : 'chevron-down')}</button>
        <span class="level-icon">${level.icon || '•'}</span>
        ${editing ? html`<input class="input input-sm inline-edit" value="${level.name}" maxlength="60" data-edit="level" data-id="${level.id}" aria-label="Level name">`
          : html`<h2 class="level-name" ${can('manage') ? raw('data-act="level-rename" title="Click to rename"') : ''} data-id="${level.id}">${level.name}</h2>`}
        <span class="count">${n}</span>
        ${pill}
        <span class="grow"></span>
        ${can('manage') ? html`<button class="btn btn-ghost btn-sm" data-act="col-add" data-id="${level.id}">${icon('plus')}<span>Column</span></button>
          <button class="btn-icon btn-xs" data-act="level-menu" data-id="${level.id}" aria-label="Level options">${icon('more')}</button>` : ''}
      </header>
      ${collapsed ? '' : html`<div class="level-cols" data-level-cols="${level.id}">
        ${cols.map((c) => colView(c, level))}
        ${can('manage') ? html`<button class="bcol-new" data-act="col-add" data-id="${level.id}">${icon('plus')}Add column</button>` : ''}
        ${!cols.length && !can('manage') ? html`<p class="muted">No column in this level yet.</p>` : ''}
      </div>`}
    </section>`;
  }
  function drawBoard(el) {
    const scroll = {};
    SM.$$('.level-cols, .bcol-cards', el).forEach((n) => { scroll[n.dataset.levelCols ? 'l' + n.dataset.levelCols : 'c' + n.dataset.cards] = [n.scrollLeft, n.scrollTop]; });
    const levels = B.data.levels;
    if (B.focus && !B.level(B.focus)) B.focus = 0;
    const shown = B.focus ? levels.filter((l) => l.id === B.focus) : levels;
    const count = (l) => colsOf(l.id).reduce((s, c) => s + cardsOf(c.id).length, 0);
    const pipeline = levels.length ? html`<nav class="pipeline" aria-label="Workflow">
        <button class="pipe-all${B.focus ? '' : ' is-active'}" data-act="focus-level" data-id="0">${icon('layers')}All levels</button>
        ${levels.map((l, i) => html`${i ? html`<span class="pipe-link h-${l && levels[i - 1].handoff_col ? levels[i - 1].handoff : 'off'}">${icon('chevron-right')}</span>` : ''}
          <button class="pipe-step c-${l.color}${B.focus === l.id ? ' is-active' : ''}" data-act="focus-level" data-id="${l.id}" title="Focus on ${l.name}"><em>${l.icon}</em><span>${l.name}</span><b>${count(l)}</b></button>`)}
        ${can('manage') ? html`<button class="pipe-add" data-act="level-add">${icon('plus')}Level</button>` : ''}
      </nav>` : '';
    const bulk = B.selected.size ? html`<div class="bulk" role="toolbar" aria-label="Selected cards">
        <b>${fmt.plural(B.selected.size, 'card')} selected</b>
        <button class="btn btn-sm" data-act="bulk-move">${icon('arrow-right')}Move</button>
        <button class="btn btn-sm" data-act="bulk-assign">${icon('user')}Assign</button>
        <button class="btn btn-sm" data-act="bulk-priority">${icon('flag')}Priority</button>
        <button class="btn btn-sm" data-act="bulk-archive">${icon('archive')}Archive</button>
        ${can('manage') ? html`<button class="btn btn-sm" data-act="bulk-delete">${icon('trash')}Delete</button>` : ''}
        <button class="btn-icon" data-act="bulk-clear" aria-label="Clear selection">${icon('x')}</button>
      </div>` : '';
    SM.set(el, html`${pipeline}
      <div class="levels${B.focus ? ' is-focus' : ''}${B.selected.size ? ' has-selection' : ''}${can('manage') ? ' can-manage' : ''}">${shown.map(levelView)}</div>
      ${!levels.length ? SM.empty('layers', 'Design your workflow', can('manage') ? 'Levels are the big stages of your work — for example Creation, Production and Publication. Each level holds its own columns.' : 'This Studio has no level yet.', can('manage') ? html`<button class="btn btn-primary" data-act="level-add">${icon('plus')}Add the first level</button>` : '') : ''}
      ${bulk}`);
    SM.$$('.level-cols, .bcol-cards', el).forEach((n) => { const s = scroll[n.dataset.levelCols ? 'l' + n.dataset.levelCols : 'c' + n.dataset.cards]; if (s) { n.scrollLeft = s[0]; n.scrollTop = s[1]; } });
    const focus = SM.$('.card-new textarea, .inline-edit', el);
    if (focus) { focus.focus(); if (focus.select && focus.classList.contains('inline-edit')) focus.select(); }
  }

  /* -------------------------------------------------------- glisser-déposer */
  function setupDrag() {
    const root = SM.$('#studio-body');
    B.undrag.push(SM.drag({ root, item: '.kcard', container: '.bcol-cards', axis: 'y', phClass: 'ph-card',
      enabled: (el) => B.view === 'board' && B.canEditCard(B.card(+el.dataset.card)),
      over: (el) => {
        const col = el.closest('.bcol');
        if (!col || (SM.guest && !(B.col(+col.dataset.col) || {}).writable)) return null;   // invité : seulement ses colonnes
        return col.querySelector('.bcol-cards');
      },
      onDrop: (item, cont, index) => {
        const id = +item.dataset.card;
        const colId = +cont.dataset.cards;
        if (B.selected.size > 1 && B.selected.has(id) && can('edit')) { bulk('move', { col_id: colId }); return; }
        // Avec un filtre actif, la position visible est ramenée à la position réelle dans la colonne.
        const full = cardsOf(colId).filter((c) => c.id !== id);
        const shown = full.filter(visible);
        const real = index < shown.length ? full.indexOf(shown[index]) : (shown.length ? full.indexOf(shown[shown.length - 1]) + 1 : full.length);
        moveCard(id, colId, real);
      } }));
    B.undrag.push(SM.drag({ root, item: '.bcol', handle: '.bcol-head', container: '.level-cols', axis: 'x', phClass: 'ph-col',
      enabled: () => B.view === 'board' && can('manage') && !B.editing,
      onDrop: (item, cont, index) => B.run('column_move', { id: +item.dataset.col, level_id: +cont.dataset.levelCols, index }) }));
    B.undrag.push(SM.drag({ root, item: '.level', handle: '.level-grip', container: '.levels', axis: 'y', phClass: 'ph-level',
      enabled: () => B.view === 'board' && can('manage') && !B.focus,
      onDrop: (item, cont, index) => B.run('level_move', { id: +item.dataset.level, index }) }));
    B.undrag.push(SM.drag({ root, item: '.cal-item', container: '.cal-items', axis: 'y', phClass: 'ph-cal',
      enabled: (el) => B.view === 'calendar' && B.canEditCard(B.card(+el.dataset.card)),
      over: (el) => { const d = el.closest('.cal-day, .cal-tray'); return d ? d.querySelector('.cal-items') : null; },
      onDrop: (item, cont) => {
        const c = B.card(+item.dataset.card);
        const date = cont.dataset.date;
        const field = item.dataset.kind === 'publish' || (item.dataset.kind === 'none' && B.cal.mode === 'publish') ? 'publish_at' : 'due';
        const value = date && field === 'publish_at' && c.publish_at.length > 10 ? date + c.publish_at.slice(10) : date;
        if (c[field] === value) { drawBody(); return; }
        c[field] = value;
        drawBody();
        B.run('card_save', { id: c.id, [field]: value });
      } }));
  }
  /** Déplace une carte : affichage immédiat, puis enregistrement ; le serveur applique le relais. */
  async function moveCard(id, colId, index) {
    const c = B.card(id);
    const list = cardsOf(colId).filter((x) => x.id !== id);
    list.splice(index, 0, c);
    c.col_id = colId;
    list.forEach((x, i) => { x.pos = i; });
    drawBody();
    const r = await B.run('card_move', { id, col_id: colId, index });
    if (r && r.handoff) SM.toast(`Handed off to ${r.handoff.level} › ${r.handoff.col}`, 'handoff');
  }
  async function bulk(op, extra) {
    const ids = Array.from(B.selected);
    const r = await B.run('cards_bulk', Object.assign({ studio: B.id, ids, op }, extra || {}));
    if (!r) return;
    B.selected.clear();
    drawBody();
    SM.toast({ move: `${fmt.plural(r.count, 'card')} moved${r.handed ? ` · ${r.handed} handed off` : ''}`, archive: `${fmt.plural(r.count, 'card')} archived`, delete: `${fmt.plural(r.count, 'card')} deleted`, assign: 'Assignee updated', priority: 'Priority updated' }[op], r.handed ? 'handoff' : '');
  }

  /* ------------------------------------------------------ sélecteurs, menus */
  /** Menu de choix d'une colonne, groupé par niveau. */
  B.pickColumn = (anchor, current, onPick) => {
    const items = [];
    B.data.levels.forEach((l) => {
      const cols = colsOf(l.id).filter((c) => !SM.guest || c.writable || c.id === current);
      if (!cols.length) return;
      items.push({ head: `${l.icon} ${l.name}` });
      cols.forEach((c) => items.push({ label: c.name, checked: c.id === current, act: () => { if (c.id !== current) onPick(c); } }));
    });
    SM.menu(anchor, items, { search: B.data.cols.length > 8, width: 250 });
  };
  /** Menu de choix d'un membre. opts : current, none (libellé de « personne »), filter. */
  B.pickMember = (anchor, opts, onPick) => {
    const members = B.data.members.filter(opts.filter || (() => true));
    const items = members.map((u) => ({ label: u.name, hint: SM.ref.roles[u.role], html: SM.avatar(u, 'av-s'), checked: u.id === opts.current, act: () => onPick(u.id) }));
    if (opts.none) items.unshift({ label: opts.none, icon: 'x', act: () => onPick(0) }, { sep: true });
    SM.menu(anchor, items, { search: members.length > 6, width: 260, align: opts.align });
  };
  const pickPriority = (anchor, current, onPick) => SM.menu(anchor, [0, 4, 3, 2, 1].map((p) => ({ label: p ? PRIO[p] : 'No priority', html: html`<span class="prio prio-${p}">${icon('flag')}</span>`, checked: p === current, act: () => onPick(p) })), { width: 190 });
  B.pickPriority = pickPriority;

  SM.inputs['board-q'] = SM.debounce((el) => { B.filter.q = el.value.trim(); drawBody(); }, 150);
  SM.actions['filter-mine'] = () => { B.filter.mine = !B.filter.mine; drawHead(); drawBody(); };
  SM.actions['filter-menu'] = (el) => {
    const f = B.filter;
    const set = (k, v) => () => { f[k] = f[k] === v ? (k === 'assignee' ? 0 : '') : v; drawHead(); drawBody(); };
    const used = Array.from(new Set(B.data.cards.flatMap((c) => c.platforms)));
    SM.menu(el, [
      { head: 'Assignee' }, ...B.data.members.map((u) => ({ label: u.name, html: SM.avatar(u, 'av-s'), checked: f.assignee === u.id, act: set('assignee', u.id) })),
      ...(used.length ? [{ head: 'Platform' }, ...used.map((p) => ({ label: SM.ref.platforms[p], checked: f.platform === p, act: set('platform', p) }))] : []),
      { sep: true }, { label: 'Clear filters', icon: 'x', act: () => { B.filter = { q: '', mine: false, assignee: 0, platform: '' }; drawHead(); drawBody(); } },
    ], { align: 'right', width: 240 });
  };
  SM.actions['focus-level'] = (el) => { B.focus = +el.dataset.id === B.focus ? 0 : +el.dataset.id; SM.ls.set('focus.' + B.id, B.focus); drawBody(); };
  SM.actions['level-collapse'] = (el) => { const id = +el.dataset.id; if (B.focus) { B.focus = 0; SM.ls.set('focus.' + B.id, 0); } else { B.collapsed[id] = !B.collapsed[id]; SM.ls.set('collapsed.' + B.id, B.collapsed); } drawBody(); };

  /* ----------------------------------------------------------------- cartes */
  SM.actions['card-open'] = (el, e) => {
    const id = +el.dataset.id;
    if ((e.ctrlKey || e.metaKey || e.shiftKey) && can('edit') && B.view === 'board') { toggleSelect(id); return; }
    SM.go(B.id ? B.cardPath(id) : `/studio/${el.dataset.studio}/card/${id}`);
  };
  const toggleSelect = (id) => { if (B.selected.has(id)) B.selected.delete(id); else B.selected.add(id); drawBody(); };
  SM.actions['card-select'] = (el, e) => { e.stopPropagation(); toggleSelect(+el.dataset.id); };
  SM.actions['card-new'] = (el) => { B.adding = { col: +el.dataset.col, top: el.dataset.top === '1' }; B.editing = null; drawBody(); };
  SM.actions['card-new-cancel'] = () => { B.adding = null; drawBody(); };
  SM.forms['card-new'] = async (form, data) => {
    const title = data.title.trim();
    if (!title) { form.title.focus(); return; }
    const r = await SM.post('card_create', { col_id: +form.dataset.col, title, top: form.dataset.top === '1' });
    B.data.cards.forEach((c) => { if (c.col_id === r.card.col_id && form.dataset.top === '1') c.pos++; });
    B.data.cards.push(r.card);
    B.data.rev = -1;   // la prochaine synchronisation récupère la version à jour
    drawBody();        // le formulaire reste ouvert : on enchaîne les idées
  };
  document.addEventListener('keydown', (e) => {
    const t = e.target;
    if (t.dataset && t.dataset.key === 'card-new') {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); t.form.requestSubmit(); }
      if (e.key === 'Escape') { e.stopPropagation(); B.adding = null; drawBody(); }
    }
    if (t.dataset && t.dataset.edit) {
      if (e.key === 'Enter') { e.preventDefault(); t.blur(); }
      if (e.key === 'Escape') { e.stopPropagation(); B.editing = null; drawBody(); }
    }
    if (e.key === 'Enter' && t.classList && t.classList.contains('kcard')) t.click();
  }, true);
  // Sortie d'un champ de renommage : le nouveau nom est enregistré.
  document.addEventListener('focusout', (e) => {
    const t = e.target;
    if (!t.dataset || !t.dataset.edit || !B.editing) return;
    const { type, id } = B.editing;
    const name = t.value.trim();
    const cur = type === 'col' ? B.col(id) : B.level(id);
    B.editing = null;
    if (!cur || !name || name === cur.name) { drawBody(); return; }
    cur.name = name;
    drawBody();
    B.run(type === 'col' ? 'column_save' : 'level_save', { id, name });
  });
  // Clic hors du formulaire d'ajout resté vide : il se referme.
  document.addEventListener('mousedown', (e) => {
    if (!B.adding || !B.data || e.target.closest('.card-new, [data-act="card-new"]')) return;
    const ta = SM.$('.card-new textarea');
    if (ta && !ta.value.trim()) { B.adding = null; setTimeout(drawBody, 0); }
  });
  SM.actions['card-handoff'] = async (el, e) => {
    e.stopPropagation();
    const r = await B.run('card_handoff', { id: +el.dataset.id });
    if (r) SM.toast(`Handed off to ${r.handoff.level} › ${r.handoff.col}`, 'handoff');
  };
  /** Menu d'une carte (clic droit, ou depuis son panneau). */
  B.cardMenu = (anchor, id, opts) => {
    const c = B.card(id);
    if (!c) return;
    const editable = B.canEditCard(c);
    const items = [{ label: 'Open', icon: 'external', act: () => SM.go(B.cardPath(id)) }];
    if (SM.guest) {
      // Invité : déplacer (vers ses colonnes) et la priorité, rien d'autre.
      if (editable && B.data.cols.some((x) => x.writable && x.id !== c.col_id)) items.push({ label: 'Move to…', icon: 'arrow-right', act: () => B.pickColumn(anchor, c.col_id, (col) => moveCard(id, col.id, cardsOf(col.id).length)) });
      if (editable) items.push({ label: 'Priority…', icon: 'flag', act: () => pickPriority(anchor, c.priority, (p) => B.run('card_save', { id, priority: p })) });
      items.push({ label: 'Copy link', icon: 'link', act: () => SM.copy(location.origin + location.pathname + '#' + B.cardPath(id)) });
      SM.menu(anchor, items, { width: 210, align: opts && opts.align });
      return;
    }
    if (editable) {
      items.push({ label: 'Move to…', icon: 'arrow-right', act: () => B.pickColumn(anchor, c.col_id, (col) => moveCard(id, col.id, cardsOf(col.id).length)) });
      if (can('edit')) items.push({ label: 'Assign to…', icon: 'user', act: () => B.pickMember(anchor, { current: c.assignee, none: 'Nobody' }, (uid) => B.run('card_save', { id, assignee: uid })) });
      items.push({ label: 'Priority…', icon: 'flag', act: () => pickPriority(anchor, c.priority, (p) => B.run('card_save', { id, priority: p })) });
      items.push({ label: 'Duplicate', icon: 'copy', act: async () => { if (await B.run('card_duplicate', { id })) SM.toast('Card duplicated'); } });
    }
    if (can('edit') && B.view === 'board') items.push({ label: B.selected.has(id) ? 'Deselect' : 'Select', hint: 'Then act on several cards at once', icon: 'checklist', act: () => toggleSelect(id) });
    items.push({ label: 'Copy link', icon: 'link', act: () => SM.copy(location.origin + location.pathname + '#/studio/' + B.id + '/card/' + id) });
    // Share & publish (assets/publish.js) : partager la carte seule, la publier dans la communauté.
    if (can('manage') || can('edit')) items.push({ label: 'Share & publish…', icon: 'share', act: () => SM.go(B.cardPath(id) + '?publish=1') });
    if (editable) {
      items.push({ sep: true }, { label: 'Archive', icon: 'archive', act: async () => { if (await B.run('card_archive', { id, archived: true })) { SM.toast('Card archived. Restore it from the Studio menu › Archive.'); if (SM.cardPanel.id === id) SM.go(base()); } } });
    }
    if (can('manage') || (can('edit') && c.created_by === SM.me.id)) {
      items.push({ label: 'Delete…', icon: 'trash', danger: true, act: async () => {
        if (!await SM.confirm({ title: 'Delete this card for good?', text: `“${c.title}”, its comments, attachments and history will be deleted. Archive it instead if you may need it again.`, ok: 'Delete card', danger: true })) return;
        if (await B.run('card_delete', { id })) { SM.toast('Card deleted'); if (SM.cardPanel.id === id) SM.go(base()); }
      } });
    }
    SM.menu(anchor, items, { width: 210, align: opts && opts.align });
  };
  document.addEventListener('contextmenu', (e) => {
    const el = e.target.closest('.kcard');
    // Pas de menu pendant un déplacement (au doigt, l'appui long déclenche aussi cet événement).
    if (!el || !B.data || B.view !== 'board' || e.defaultPrevented || document.body.classList.contains('is-dragging') || e.target.closest('a, input, textarea')) return;
    e.preventDefault();
    B.cardMenu(el, +el.dataset.card);
  });
  SM.actions['bulk-clear'] = () => { B.selected.clear(); drawBody(); };
  SM.actions['bulk-move'] = (el) => B.pickColumn(el, 0, (col) => bulk('move', { col_id: col.id }));
  SM.actions['bulk-assign'] = (el) => B.pickMember(el, { none: 'Nobody' }, (uid) => bulk('assign', { assignee: uid }));
  SM.actions['bulk-priority'] = (el) => pickPriority(el, -1, (p) => bulk('priority', { priority: p }));
  SM.actions['bulk-archive'] = () => bulk('archive');
  SM.actions['bulk-delete'] = async () => {
    if (await SM.confirm({ title: `Delete ${fmt.plural(B.selected.size, 'card')} for good?`, text: 'Their comments, attachments and history will be deleted. Archive them instead if you may need them again.', ok: 'Delete', danger: true })) bulk('delete');
  };

  /* --------------------------------------------------------------- colonnes */
  SM.actions['col-rename'] = (el) => { B.editing = { type: 'col', id: +el.dataset.id }; B.adding = null; drawBody(); };
  SM.actions['col-add'] = async (el) => {
    const name = await SM.prompt({ title: 'New column', label: 'Column name', placeholder: 'e.g. ✍️ In review', ok: 'Add column', max: 60 });
    if (name) B.run('column_save', { level_id: +el.dataset.id, name });
  };
  /** Suppression sûre : s'il reste des cartes, on choisit où les déplacer (ou on archive). */
  async function removeWithCards(kind, item) {
    const action = kind === 'level' ? 'level_delete' : 'column_delete';
    try { await B.run(action, { id: item.id }, true); SM.toast(`${kind === 'level' ? 'Level' : 'Column'} deleted`); return; } catch (e) {
      if (e.code !== 'not_empty') { SM.toast(e.message, 'error'); return; }
      const options = B.data.cols.filter((c) => (kind === 'level' ? c.level_id !== item.id : c.id !== item.id));
      const data = await SM.confirm({ title: `Delete “${item.name}”?`, text: e.message, ok: 'Move cards and delete', danger: true, values: true,
        body: options.length ? html`<label class="field"><span>Move its ${fmt.plural(e.data.cards, 'card')} to</span><select class="input" name="move_to">${options.map((c) => html`<option value="${c.id}">${B.level(c.level_id).name} › ${c.name}</option>`)}</select></label>
          <p class="muted small">Prefer to keep everything as it is? Close this window and use <b>Archive</b> instead: it can be restored at any time.</p>` : html`<p class="muted">There is no other column to receive the cards. Archive it instead.</p>` });
      if (data && data.move_to && await B.run(action, { id: item.id, move_to: +data.move_to })) SM.toast('Deleted — the cards were moved');
    }
  }
  SM.actions['col-menu'] = (el) => {
    const col = B.col(+el.dataset.id);
    const level = B.level(col.level_id);
    const cols = colsOf(level.id);
    const i = cols.indexOf(col);
    const isFinal = level.handoff_col === col.id;
    const kind = (k, label, ic) => ({ label, icon: ic, checked: col.kind === k, act: () => B.run('column_save', { id: col.id, kind: k }) });
    SM.menu(el, [
      { label: 'Rename', icon: 'edit', act: () => SM.actions['col-rename'](el) },
      { label: 'Add a card', icon: 'plus', act: () => SM.actions['card-new']({ dataset: { col: col.id, top: '1' } }) },
      { sep: true }, { head: 'Workflow' },
      { label: 'Principal column', hint: 'Cards arrive here', icon: 'inbox', checked: col.principal, disabled: col.principal, act: () => B.run('column_principal', { id: col.id }) },
      { label: 'Final column', hint: 'Hands cards off to the next level', icon: 'zap', checked: isFinal, act: () => B.run('column_final', { id: col.id, on: !isFinal }) },
      { head: 'Cards in this column are' }, kind('', 'In progress', 'clock'), kind('done', 'Done', 'check-circle'), kind('cancelled', 'Cancelled', 'x'),
      { sep: true },
      i > 0 ? { label: 'Move left', icon: 'chevron-left', act: () => B.run('column_move', { id: col.id, level_id: level.id, index: i - 1 }) } : null,
      i < cols.length - 1 ? { label: 'Move right', icon: 'chevron-right', act: () => B.run('column_move', { id: col.id, level_id: level.id, index: i + 1 }) } : null,
      B.data.levels.length > 1 ? { label: 'Move to another level…', icon: 'layers', act: () => SM.menu(el, B.data.levels.filter((l) => l.id !== level.id).map((l) => ({ label: `${l.icon} ${l.name}`, act: () => B.run('column_move', { id: col.id, level_id: l.id, index: 999 }) })), { width: 220 }) } : null,
      { label: 'Duplicate', icon: 'copy', act: () => B.run('column_duplicate', { id: col.id }) },
      can('edit') && cardsOf(col.id).length ? { label: 'Select all cards', icon: 'checklist', act: () => { cardsOf(col.id).filter(visible).forEach((c) => B.selected.add(c.id)); drawBody(); } } : null,
      { sep: true },
      { label: 'Archive', icon: 'archive', act: async () => { if (await B.run('column_archive', { id: col.id, archived: true })) SM.toast('Column archived with its cards. Restore it from the Studio menu › Archive.'); } },
      { label: 'Delete…', icon: 'trash', danger: true, act: () => removeWithCards('column', col) },
    ].filter(Boolean), { width: 260 });
  };

  /* ---------------------------------------------------------------- niveaux */
  SM.actions['level-rename'] = (el) => { B.editing = { type: 'level', id: +el.dataset.id }; B.adding = null; drawBody(); };
  SM.actions['level-add'] = () => levelDialog(null);
  SM.actions['level-settings'] = (el) => levelDialog(B.level(+el.dataset.id));
  const ICONS = ['✨', '💡', '✍️', '🎬', '🎨', '📣', '🚀', '📅', '📊', '🎯', '🔍', '🤝', '📦', '⚡', '🧪', '✅'];
  function levelDialog(level) {
    const cols = level ? colsOf(level.id) : [];
    const others = level ? B.data.levels.filter((l) => l.id !== level.id) : [];
    const next = level ? B.data.levels[B.data.levels.indexOf(level) + 1] : null;
    SM.modal(html`${SM.modalHead(level ? 'Level settings' : 'New level', level ? '' : 'A level is a major stage of your workflow. It starts with one column; add more afterwards.')}
      <form class="modal-body stack" data-form="level-save">
        ${level ? html`<input type="hidden" name="id" value="${level.id}">` : ''}
        <div class="field-row"><label class="field field-icon"><span>Icon</span><input class="input" name="icon" maxlength="4" value="${level ? level.icon : '✨'}" aria-label="Icon (emoji)"></label>
          <label class="field grow"><span>Name</span><input class="input" name="name" maxlength="60" required value="${level ? level.name : ''}" placeholder="e.g. Production" autofocus></label></div>
        <div class="icon-picks">${ICONS.map((i) => html`<button type="button" data-act="icon-pick" data-icon="${i}">${i}</button>`)}</div>
        <div class="field"><span>Color</span>${SM.swatches('color', level ? level.color : SM.ref.colors[B.data.levels.length % SM.ref.colors.length])}</div>
        ${level ? html`<fieldset class="fieldset"><legend>${icon('zap')}Handoff to the next level</legend>
          <p class="muted small">When a card reaches the final column, it moves to the principal column of the destination level. It stays the same card: comments, attachments, history and assignees are kept.</p>
          <div class="seg seg-3">${[['auto', 'zap', 'Automatic', 'As soon as it arrives'], ['manual', 'hand', 'Manual', 'With a “Send” button'], ['off', 'x', 'Off', 'Cards stay here']].map(([k, ic, l, hint]) => html`
            <label><input type="radio" name="handoff" value="${k}" ${level.handoff === k ? 'checked' : ''}><span>${icon(ic)}<b>${l}</b><small>${hint}</small></span></label>`)}</div>
          <div class="field-row"><label class="field"><span>Final column</span><select class="input" name="handoff_col"><option value="0">None</option>${cols.map((c) => html`<option value="${c.id}" ${c.id === level.handoff_col ? 'selected' : ''}>${c.name}</option>`)}</select></label>
            <label class="field"><span>Destination level</span><select class="input" name="handoff_level"><option value="0">${next ? `Next level (${next.name})` : 'Next level (none yet)'}</option>${others.map((l) => html`<option value="${l.id}" ${l.id === level.handoff_level ? 'selected' : ''}>${l.icon} ${l.name}</option>`)}</select></label></div>
        </fieldset>` : ''}
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">${level ? 'Save' : 'Add level'}</button></footer>
      </form>`);
  }
  SM.actions['icon-pick'] = (el) => { el.closest('form').icon.value = el.dataset.icon; };
  SM.forms['level-save'] = async (form, data) => {
    const body = Object.assign({}, data, data.id ? { id: +data.id, handoff_col: +data.handoff_col, handoff_level: +data.handoff_level } : { studio_id: B.id });
    await B.run('level_save', body, true);
    SM.closeModals();
    SM.toast(data.id ? 'Level saved' : 'Level added');
  };
  SM.actions['level-menu'] = (el) => {
    const level = B.level(+el.dataset.id);
    const i = B.data.levels.indexOf(level);
    SM.menu(el, [
      { label: 'Rename', icon: 'edit', act: () => SM.actions['level-rename'](el) },
      { label: 'Icon, color and handoff…', icon: 'settings', act: () => levelDialog(level) },
      { label: 'Add column', icon: 'plus', act: () => SM.actions['col-add'](el) },
      { sep: true },
      i > 0 ? { label: 'Move up', icon: 'chevron-left', act: () => B.run('level_move', { id: level.id, index: i - 1 }) } : null,
      i < B.data.levels.length - 1 ? { label: 'Move down', icon: 'chevron-right', act: () => B.run('level_move', { id: level.id, index: i + 1 }) } : null,
      { label: 'Duplicate', hint: 'Columns and settings, without the cards', icon: 'copy', act: () => B.run('level_duplicate', { id: level.id }) },
      { sep: true },
      { label: 'Archive', icon: 'archive', act: async () => { if (await B.run('level_archive', { id: level.id, archived: true })) SM.toast('Level archived with its cards. Restore it from the Studio menu › Archive.'); } },
      { label: 'Delete…', icon: 'trash', danger: true, act: () => removeWithCards('level', level) },
    ].filter(Boolean), { align: 'right', width: 270 });
  };

  /* --------------------------------------------------------- menus du Studio */
  SM.actions['switch-project'] = async (el) => {
    const w = await SM.try(SM.workspace());
    if (!w) return;
    SM.menu(el, [{ head: 'Projects' }, ...w.projects.filter((p) => !p.archived).map((p) => ({ label: p.name, hint: fmt.plural(p.studios.length, 'Studio'), html: SM.projectMark(p, 'pmark-s'), checked: p.id === B.data.studio.project.id, href: p.studios.length === 1 ? '#/studio/' + p.studios[0].id : '#/project/' + p.id })),
      { sep: true }, { label: 'All projects', icon: 'folder', href: '#/projects' }, { label: 'New project', icon: 'plus', act: () => SM.projectDialog() }], { width: 280, search: w.projects.length > 7 });
  };
  SM.actions['switch-studio'] = async (el) => {
    const w = await SM.try(SM.workspace());
    if (!w) return;
    const p = w.projects.find((x) => x.id === B.data.studio.project.id);
    SM.menu(el, [{ head: 'Studios of ' + B.data.studio.project.name }, ...(p ? p.studios : []).map((s) => ({ label: s.name, icon: 'board', checked: s.id === B.id, href: '#/studio/' + s.id })),
      { sep: true }, { label: 'Project overview', icon: 'folder', href: '#/project/' + B.data.studio.project.id },
      p && (p.role === 'owner' || p.role === 'manager') ? { label: 'New Studio', icon: 'plus', act: () => SM.studioDialog(p.id) } : null].filter(Boolean), { width: 260 });
  };
  SM.actions['studio-menu'] = (el) => {
    const s = B.data.studio;
    SM.menu(el, [
      can('manage') ? { label: 'Add a level', icon: 'layers', act: () => levelDialog(null) } : null,
      { label: 'Activity', icon: 'history', act: () => activityPanel() },
      { label: 'Archive', hint: 'Archived cards, columns and levels', icon: 'archive', act: () => archiveDialog() },
      can('work') ? { label: 'Create a case study', hint: 'Showcase published work on your profile', icon: 'award', act: () => caseDialog() } : null,
      can('manage') ? { sep: true } : null,
      can('manage') ? { label: 'Studio settings', icon: 'settings', act: () => settingsDialog() } : null,
      can('manage') ? { label: s.archived ? 'Restore Studio' : 'Archive Studio', icon: s.archived ? 'restore' : 'archive', act: async () => { if (await B.run('studio_archive', { id: B.id, archived: !s.archived })) { SM.dropWorkspace(); SM.toast(s.archived ? 'Studio restored' : 'Studio archived'); } } } : null,
      can('manage') ? { label: 'Delete Studio…', icon: 'trash', danger: true, act: async () => {
        if (!await SM.confirm({ title: 'Delete this Studio?', text: `“${s.name}”, its levels, cards, comments and attachments will be deleted for everyone. This cannot be undone — archive it instead if you may need it again.`, type: s.name, ok: 'Delete Studio', danger: true })) return;
        if (await SM.try(SM.post('studio_delete', { id: B.id, confirm: s.name }))) { SM.dropWorkspace(); SM.toast('Studio deleted'); SM.go('/project/' + s.project.id); }
      } } : null,
      { sep: true }, { label: 'Leave', icon: 'logout', act: () => SM.actions.leave({ dataset: (B.data.members.find((u) => u.id === SM.me.id) || {}).via === 'studio' ? { studio: B.id } : { project: s.project.id } }) },
    ].filter(Boolean), { align: 'right', width: 280 });
  };
  function settingsDialog() {
    const s = B.data.studio;
    SM.modal(html`${SM.modalHead('Studio settings')}
      <form class="modal-body stack" data-form="studio-save">
        <label class="field"><span>Name</span><input class="input" name="name" maxlength="80" required value="${s.name}" autofocus></label>
        <label class="field"><span>Description <em>optional</em></span><textarea class="input" name="description" rows="3" maxlength="600">${s.description}</textarea></label>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">Save</button></footer>
      </form>`);
  }
  SM.forms['studio-save'] = async (form, data) => { await B.run('studio_save', Object.assign({ id: B.id }, data), true); SM.dropWorkspace(); SM.closeModals(); SM.toast('Studio saved'); };

  const eventRow = (e, withCard) => html`<li class="event">${SM.avatar(e.user, 'av-s')}<div><p><b>${e.user ? e.user.name : 'Someone'}</b> ${e.text}${withCard && e.card_id && e.card_title ? html` — <a href="#${B.cardPath(e.card_id)}" data-act="modal-close-go">${e.card_title}</a>` : ''}</p><small class="muted">${fmt.ago(e.created)}</small></div></li>`;
  SM.eventRow = eventRow;
  SM.actions['modal-close-go'] = (el) => { const href = el.getAttribute('href'); SM.closeModals(); location.hash = href; };
  async function activityPanel() {
    const m = SM.modal(html`${SM.modalHead('Activity', 'Everything that happened in this Studio.')}<div class="modal-body">${SM.loading(4)}</div>`, { side: true });
    let events = [];
    const load = async (before) => {
      const r = await SM.try(SM.get('studio_activity', { studio: B.id, before }));
      if (!r || !document.contains(m.el)) return;
      events = events.concat(r.events);
      SM.set(SM.$('.modal-body', m.el), html`<ul class="events">${events.map((e) => eventRow(e, true))}</ul>${r.events.length === 50 ? html`<button class="btn btn-soft btn-block" data-act="activity-more">Show older activity</button>` : ''}`);
    };
    SM.actions['activity-more'] = () => load(events[events.length - 1].id);
    load(0);
  }
  async function archiveDialog() {
    const m = SM.modal(html`${SM.modalHead('Archive', 'Archived items are hidden from the board, never lost. Restore them at any time.')}<div class="modal-body">${SM.loading(3)}</div>`, { size: 'l' });
    const draw = async () => {
      const r = await SM.try(SM.get('studio_archived', { studio: B.id }));
      if (!r || !document.contains(m.el)) return;
      const row = (kind, id, title, sub) => html`<div class="list-row"><span class="list-ico">${icon(kind === 'card' ? 'file' : kind === 'col' ? 'columns' : 'layers')}</span><span class="grow"><b>${title}</b><small class="muted">${sub}</small></span>
        ${can(kind === 'card' ? 'work' : 'manage') ? html`<button class="btn btn-soft btn-sm" data-act="unarchive" data-kind="${kind}" data-id="${id}">${icon('restore')}Restore</button>` : ''}
        ${can('manage') ? html`<button class="btn-icon" data-act="archive-delete" data-kind="${kind}" data-id="${id}" data-name="${title}" title="Delete for good" aria-label="Delete ${title} for good">${icon('trash')}</button>` : ''}</div>`;
      const empty = !r.cards.length && !r.cols.length && !r.levels.length;
      SM.set(SM.$('.modal-body', m.el), empty ? SM.empty('archive', 'The archive is empty', 'Cards, columns and levels you archive will be kept here.') : html`<div class="stack-l">
        ${r.levels.length ? html`<div><h3 class="section-title">Levels</h3><div class="list">${r.levels.map((l) => row('level', l.id, `${l.icon} ${l.name}`, fmt.plural(l.cards, 'card')))}</div></div>` : ''}
        ${r.cols.length ? html`<div><h3 class="section-title">Columns</h3><div class="list">${r.cols.map((c) => row('col', c.id, c.name, `${c.level_name} · ${fmt.plural(c.cards, 'card')}`))}</div></div>` : ''}
        ${r.cards.length ? html`<div><h3 class="section-title">Cards</h3><div class="list">${r.cards.map((c) => row('card', c.id, c.title, `was in “${c.col_name}” · archived ${fmt.ago(c.updated)}`))}</div></div>` : ''}</div>`);
    };
    SM.actions.unarchive = async (el) => {
      const action = { card: 'card_archive', col: 'column_archive', level: 'level_archive' }[el.dataset.kind];
      if (await B.run(action, { id: +el.dataset.id, archived: false })) { SM.toast('Restored'); draw(); }
    };
    SM.actions['archive-delete'] = async (el) => {
      const kind = el.dataset.kind;
      if (kind !== 'card') { SM.closeModals(); await B.run(kind === 'col' ? 'column_archive' : 'level_archive', { id: +el.dataset.id, archived: false }); removeWithCards(kind === 'col' ? 'column' : 'level', { id: +el.dataset.id, name: el.dataset.name }); return; }
      if (!await SM.confirm({ title: 'Delete this card for good?', text: `“${el.dataset.name}” and everything attached to it will be deleted. This cannot be undone.`, ok: 'Delete', danger: true })) return;
      if (await B.run('card_delete', { id: +el.dataset.id })) draw();
    };
    draw();
  }
  /** Travail publié → brouillon d'étude de cas (publiée seulement avec les accords requis). */
  function caseDialog() {
    const done = B.data.cards.filter((c) => (B.col(c.col_id) || {}).kind === 'done' && (can('edit') || B.canEditCard(c)));
    SM.modal(html`${SM.modalHead('Create a case study', 'Turn published work into a portfolio piece on your public profile.')}
      <form class="modal-body stack" data-form="case-create">
        <div class="notice">${icon('lock')}<span>Studio work is private. Your case study stays a draft until the project owner authorizes it — and the client too, when the project has one. Only what you choose to show becomes public.</span></div>
        ${done.length ? html`<div class="field"><span>Published cards to include</span><div class="list list-check">${done.map((c) => html`<label class="list-row"><input type="checkbox" name="card_ids[]" value="${c.id}" ${c.publish_url ? 'checked' : ''}><span class="grow"><b>${c.title}</b><small class="muted">${c.publish_url ? c.publish_url : 'No publication link yet'}</small></span>${c.publish_url ? html`<span class="vbadge v-studio">${icon('badge')}Verifiable</span>` : ''}</label>`)}</div></div>`
          : SM.empty('award', 'No published work yet', 'Cards that reach a “Done” column (for example ✔️ Published) can become a case study. Add their publication link to make the work verifiable.')}
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button>${done.length ? html`<button class="btn btn-primary">Create draft</button>` : ''}</footer>
      </form>`, { size: 'l' });
  }
  SM.forms['case-create'] = async (form, data) => {
    const r = await SM.post('portfolio_from_studio', { studio: B.id, card_ids: (data.card_ids || []).map(Number) });
    SM.closeModals();
    SM.toast('Draft created — complete it, then publish');
    SM.go('/profile?case=' + r.id);
  };

  /* ------------------------------------------------------------- calendrier */
  function drawCalendar(el) {
    const now = new Date();
    const cal = B.cal || (B.cal = { y: now.getFullYear(), m: now.getMonth(), mode: 'all' });
    const first = new Date(cal.y, cal.m, 1);
    const start = new Date(cal.y, cal.m, 1 - ((first.getDay() + 6) % 7));
    const entries = {};
    const add = (date, c, kind) => { (entries[date] = entries[date] || []).push({ c, kind }); };
    const loose = [];
    B.data.cards.forEach((c) => {
      const col = B.col(c.col_id);
      if (cal.mode !== 'publish' && c.due) add(c.due, c, 'due');
      if (cal.mode !== 'due' && c.publish_at) add(c.publish_at.slice(0, 10), c, 'publish');
      if (!c.due && !c.publish_at && col && col.kind === '') loose.push(c);
    });
    const item = (c, kind) => {
      const col = B.col(c.col_id) || {};
      const level = B.level(col.level_id) || {};
      return html`<div class="cal-item k-${kind} c-${level.color}${col.kind === 'done' ? ' is-done' : ''}${kind === 'due' && col.kind === '' && fmt.due(c.due) === 'overdue' ? ' is-late' : ''}" data-card="${c.id}" data-kind="${kind}" data-act="card-open" data-id="${c.id}" title="${kind === 'publish' ? 'Publication' : kind === 'due' ? 'Deadline' : 'Unscheduled'} · ${level.name || ''} › ${col.name || ''}">
        ${icon(kind === 'publish' ? 'send' : kind === 'due' ? 'flag' : 'grip')}${kind === 'publish' && c.publish_at.length > 10 ? html`<time>${c.publish_at.slice(11, 16)}</time>` : ''}<span>${c.title}</span></div>`;
    };
    const days = [];
    for (let i = 0; i < 42; i++) {
      const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
      if (i >= 35 && d.getMonth() !== cal.m && d.getDate() <= 7) break;
      days.push(d);
    }
    const today = fmt.today();
    const title = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric' }).format(first);
    SM.set(el, html`<div class="cal">
      <header class="cal-head">
        <div class="row"><button class="btn-icon" data-act="cal-nav" data-d="-1" aria-label="Previous month">${icon('chevron-left')}</button><h2>${title}</h2><button class="btn-icon" data-act="cal-nav" data-d="1" aria-label="Next month">${icon('chevron-right')}</button>
          <button class="btn btn-soft btn-sm" data-act="cal-nav" data-d="0">Today</button></div>
        <span class="grow"></span>
        <div class="seg seg-inline" role="radiogroup" aria-label="Dates shown">${[['all', 'All dates'], ['due', 'Deadlines'], ['publish', 'Publications']].map(([k, l]) => html`<label><input type="radio" name="calmode" value="${k}" data-change="cal-mode" ${cal.mode === k ? 'checked' : ''}><span>${l}</span></label>`)}</div>
      </header>
      <div class="cal-legend"><span>${icon('flag')}Production deadline</span><span>${icon('send')}Publication date</span><span class="muted">Drag a card to another day to reschedule it.</span></div>
      ${loose.length ? html`<div class="cal-tray"><b>Unscheduled <span class="count">${loose.length}</span></b><div class="cal-items" data-date="">${loose.slice(0, 40).map((c) => item(c, 'none'))}</div></div>` : ''}
      <div class="cal-grid"><div class="cal-week">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => html`<span>${d}</span>`)}</div>
        ${days.map((d) => {
          const key = fmt.ymd(d);
          const list = (entries[key] || []).sort((a, b) => (a.kind === 'publish' ? a.c.publish_at : '').localeCompare(b.kind === 'publish' ? b.c.publish_at : ''));
          return html`<div class="cal-day${d.getMonth() !== cal.m ? ' is-out' : ''}${key === today ? ' is-today' : ''}${list.length ? '' : ' is-empty'}">
            <header><span class="cal-num">${d.getDate()}</span><span class="cal-dow">${new Intl.DateTimeFormat('en-GB', { weekday: 'short', month: 'short' }).format(d)}</span></header>
            <div class="cal-items" data-date="${key}">${list.map((e) => item(e.c, e.kind))}</div></div>`;
        })}
      </div>
      ${!B.data.cards.some((c) => c.due || c.publish_at) ? html`<p class="muted cal-hint">No card has a date yet. Open a card to set its due date or its publication date${loose.length ? ', or drag an unscheduled card onto a day' : ''}.</p>` : ''}
    </div>`);
  }
  SM.actions['cal-nav'] = (el) => {
    const d = +el.dataset.d;
    const now = new Date();
    const next = d ? new Date(B.cal.y, B.cal.m + d, 1) : now;
    B.cal.y = next.getFullYear(); B.cal.m = next.getMonth();
    drawBody();
  };
  SM.changes['cal-mode'] = (el) => { B.cal.mode = el.value; drawBody(); };

  /* ------------------------------------------------------------ mon travail */
  const W = { project: 0, priority: -1, status: 'open', deadline: '' };
  /** Cartes confiées au compte, tous Studios confondus ($studioId : mise en avant d'un Studio). */
  SM.renderMyWork = async (el, ctx, studioId) => {
    const r = await SM.get('mywork');
    if (!ctx.alive()) return;
    const draw = () => {
      const projects = Array.from(new Map(r.cards.map((c) => [c.project_id, c.project_name])).entries());
      const today = fmt.today();
      const week = fmt.ymd(new Date(Date.now() + 7 * 86400000));
      const list = r.cards.filter((c) => {
        if (W.project && c.project_id !== W.project) return false;
        if (W.priority >= 0 && c.priority !== W.priority) return false;
        if (W.status === 'open' && c.col_kind !== '') return false;
        if (W.status === 'done' && c.col_kind !== 'done') return false;
        if (W.status === 'pending' && c.approval !== 'pending') return false;
        if (W.deadline === 'overdue' && !(c.due && c.due < today && c.col_kind === '')) return false;
        if (W.deadline === 'week' && !(c.due && c.due >= today && c.due <= week)) return false;
        if (W.deadline === 'none' && c.due) return false;
        return true;
      });
      const buckets = [['Overdue', 'alert', []], ['Today', 'zap', []], ['Next 7 days', 'calendar', []], ['Later', 'clock', []], ['No due date', 'inbox', []], ['Done', 'check-circle', []]];
      list.forEach((c) => {
        const i = c.col_kind !== '' ? 5 : !c.due ? 4 : c.due < today ? 0 : c.due === today ? 1 : c.due <= week ? 2 : 3;
        buckets[i][2].push(c);
      });
      const row = (c) => html`<a class="wrow${c.studio_id === studioId ? ' is-here' : ''}" href="#/studio/${c.studio_id}/card/${c.id}">
        <span class="prio prio-${c.priority}" title="${PRIO[c.priority] || 'No'} priority">${icon('flag')}</span>
        <span class="grow"><b>${c.title}</b><small class="muted">${c.project_name} · ${c.studio_name}</small></span>
        ${SM.approvalBadge(c.approval)}
        <span class="chip c-${c.level_color} wrow-where">${c.level_icon} ${c.level_name} › ${c.col_name}</span>
        ${c.due ? html`<span class="due due-${c.col_kind !== '' ? 'done' : fmt.due(c.due)}">${icon('calendar')}${fmt.day(c.due)}</span>` : html`<span class="due due-none">No date</span>`}
      </a>`;
      const sel = (name, options, value) => html`<select class="input input-sm" data-change="work-filter" data-name="${name}" aria-label="${name}">${options.map(([v, l]) => html`<option value="${v}" ${String(v) === String(value) ? 'selected' : ''}>${l}</option>`)}</select>`;
      SM.set(el, html`<div class="${studioId ? 'page' : ''} work">
        ${r.approvals.length ? html`<section class="panel pad appr-box"><h3>${icon('shield')}Waiting for your approval</h3><div class="list">${r.approvals.map((a) => html`<a class="list-row" href="#/studio/${a.studio_id}/card/${a.card_id}">${SM.avatar(a.by, 'av-m')}<span class="grow"><b>${a.title}</b><small class="muted">${a.by ? a.by.name : ''} asked ${fmt.ago(a.created)} · ${a.studio_name}${a.note ? ' — “' + a.note + '”' : ''}</small></span><span class="btn btn-primary btn-sm">Review</span></a>`)}</div></section>` : ''}
        <div class="filters">${sel('project', [[0, 'All projects'], ...projects], W.project)}${sel('deadline', [['', 'Any deadline'], ['overdue', 'Overdue'], ['week', 'Due within 7 days'], ['none', 'No due date']], W.deadline)}
          ${sel('priority', [[-1, 'Any priority'], [4, 'Urgent'], [3, 'High'], [2, 'Medium'], [1, 'Low'], [0, 'No priority']], W.priority)}${sel('status', [['open', 'In progress'], ['pending', 'Awaiting approval'], ['done', 'Done'], ['all', 'All statuses']], W.status)}
          <span class="muted small">${fmt.plural(list.length, 'card')}</span></div>
        ${list.length ? buckets.filter((b) => b[2].length).map(([label, ic, cards]) => html`<section class="wgroup"><h3 class="section-title wg-${label.split(' ')[0].toLowerCase()}">${icon(ic)}${label} <span class="count">${cards.length}</span></h3><div class="panel list">${cards.map(row)}</div></section>`)
          : SM.empty('checklist', r.cards.length ? 'No card matches these filters' : 'Nothing assigned to you yet', r.cards.length ? 'Try another combination of filters.' : 'When a card is assigned to you in any Studio, it appears here with its deadline, its project and its status.')}
      </div>`);
    };
    SM.changes['work-filter'] = (sel) => { const n = sel.dataset.name; W[n] = n === 'project' || n === 'priority' ? +sel.value : sel.value; draw(); };
    draw();
  };

  /* --------------------------------------------------------- tableau de bord */
  async function drawInsights(el) {
    SM.set(el, html`<div class="page">${SM.loading(3)}</div>`);
    let r = null;
    try { r = await SM.get('studio_stats', { studio: B.id }); } catch (e) {
      // Insights : formule Team (lib/billing.php) ; autre erreur : message habituel.
      if (alive()) SM.set(el, e.code === 'plan_limit' && SM.planGate ? html`<div class="page">${SM.planGate(e.data)}</div>` : html`<div class="page">${SM.empty('alert', 'Something went wrong', e.message)}</div>`);
      return;
    }
    if (!r || !alive()) return;
    const t = r.totals;
    const tile = (label, value, ic, cls, hint) => html`<div class="panel stat ${cls || ''}"><span class="stat-ico">${icon(ic)}</span><b>${value}</b><span>${label}</span>${hint ? html`<small class="muted">${hint}</small>` : ''}</div>`;
    const rate = t.ontime + t.late ? Math.round((t.ontime / (t.ontime + t.late)) * 100) + '%' : '—';
    const bars = (rows, cls) => { const max = Math.max(1, ...rows.map((x) => x.n)); return html`<div class="bars">${rows.map((x) => html`<div class="bar-row"><span class="bar-label">${x.icon ? x.icon + ' ' : ''}${x.label || x.name}</span><span class="bar-track"><i class="${cls || ''} ${x.color ? 'c-' + x.color : ''}" style="width:${Math.round((x.n / max) * 100)}%"></i></span><b>${x.n}</b></div>`)}</div>`; };
    const wmax = Math.max(1, ...r.weeks.map((w) => Math.max(w.done, w.created)));
    SM.set(el, html`<div class="page insights">
      <div class="notice">${icon('lock')}<span>These figures are private to the members of this Studio. They never appear on a public profile.</span></div>
      <div class="stats">${tile('In progress', t.open, 'clock')}${tile('Done', t.done, 'check-circle', 'stat-ok')}${tile('Overdue', t.overdue, 'alert', t.overdue ? 'stat-bad' : '')}${tile('Awaiting approval', t.pending, 'shield')}
        ${tile('Delivered on time', rate, 'target', '', t.ontime + t.late ? `${t.ontime} of ${t.ontime + t.late} cards with a due date` : 'No finished card with a due date yet')}${tile('Typical time to done', r.cycle_days == null ? '—' : r.cycle_days + ' d', 'history', '', 'Median, from creation to done')}</div>
      <div class="grid grid-2">
        <section class="panel pad"><h3>Where the work is</h3><p class="muted small">Cards in progress, by level.</p>${r.levels.length ? bars(r.levels) : html`<p class="muted">No level yet.</p>`}</section>
        <section class="panel pad"><h3>Rhythm — last 8 weeks</h3><p class="muted small"><span class="dot dot-a"></span>Created <span class="dot dot-b"></span>Done</p>
          <div class="weeks">${r.weeks.map((w) => html`<div class="week" title="Week of ${w.week}: ${w.created} created, ${w.done} done"><span class="week-bars"><i class="wa" style="height:${Math.round((w.created / wmax) * 100)}%"></i><i class="wb" style="height:${Math.round((w.done / wmax) * 100)}%"></i></span><small>${w.week}</small></div>`)}</div></section>
        <section class="panel pad"><h3>Published by platform</h3>${r.platforms.length ? bars(r.platforms, 'c-iris') : html`<p class="muted small">Set the platforms of your cards to see where your content goes.</p>`}</section>
        <section class="panel pad"><h3>Published by content type</h3>${r.types.length ? bars(r.types, 'c-coral') : html`<p class="muted small">Set the content type of your cards (Reel, Carousel, Story…) to see your mix.</p>`}</section>
        ${r.load ? html`<section class="panel pad span-2"><h3>Workload</h3><p class="muted small">Cards in progress per person. Visible to managers only.</p>
          ${r.load.length ? html`<div class="list">${r.load.map((l) => html`<div class="list-row">${SM.avatar(l.user, 'av-m')}<span class="grow"><b>${l.user ? l.user.name : 'Unassigned'}</b></span><span class="chip">${fmt.plural(l.open, 'card')}</span>${l.overdue ? html`<span class="chip chip-bad">${l.overdue} overdue</span>` : ''}</div>`)}</div>` : html`<p class="muted">Nothing in progress.</p>`}</section>` : ''}
      </div></div>`);
  }
})();
