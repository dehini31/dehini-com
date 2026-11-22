/*
 * Studioma — parcours du créateur : « My Achievements » (#/achievements), détails d'une réalisation, visibilité et
 * passeport (#/achievements/settings), partage (lien de vérification, réseaux, image PNG avec QR code), célébrations,
 * « Your Next Milestone » (accueil), projet terminé. Tout est calculé par le serveur (lib/journey.php) ; les pages
 * publiques de vérification et de passeport sont rendues par p.php.
 */
(function () {
  'use strict';
  const { html, icon, fmt } = SM;
  const pad2 = (n) => String(n).padStart(2, '0');
  const VER = { system: 'jv-system', client: 'jv-client', evidence: 'jv-evidence', self: 'jv-self' };
  const VIS = { private: 'Private', link: 'Anyone with the link', passport: 'Public on my Creator Passport' };
  const levelName = (l) => `Level ${pad2(l.n)} — ${l.name}`;
  const pct = (have, need) => Math.max(0, Math.min(100, Math.floor((have / Math.max(1, need)) * 100)));

  /* ------------------------------------------------------------ célébrations */
  // Le serveur signale « _journey.celebrate » dans ses réponses (assets/core.js) : une carte discrète s'affiche dans un
  // coin, sans bloquer le travail, une seule fois, sans son ; elle se ferme seule ou d'un clic.
  let showing = false;
  SM.onJourney = (j) => { if (j && j.celebrate > 0 && SM.me && !SM.guest) celebrate(); };
  const celebrate = SM.debounce(async () => {
    if (showing) return;
    let r;
    try { r = await SM.get('journey_celebrations'); } catch (e) { return; }
    if (!r.items.length) return;
    showing = true;
    SM.post('journey_seen').catch(() => {});
    const one = r.items.length === 1 ? r.items[0] : null;
    const next = r.next && r.next[0];
    const box = document.createElement('div');
    box.className = 'celebrate';
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    box.innerHTML = String(html`<span class="celebrate-badge" aria-hidden="true">${icon(one ? one.icon : 'trophy')}</span>
      <div class="celebrate-body">
        <p class="celebrate-kicker">${one ? (one.kind === 'level' ? 'New level' : 'Achievement unlocked') : r.items.length + ' new milestones'}</p>
        <b>${one ? one.title : 'Your Creator Journey moved forward'}</b>
        ${one ? html`<p>${one.text}</p>` : html`<ul>${r.items.slice(0, 4).map((i) => html`<li>${icon(i.icon)}${i.title}</li>`)}</ul>`}
        ${one && one.xp ? html`<span class="chip chip-ok">+${fmt.num(one.xp)} XP</span>` : ''}
        ${next ? html`<p class="muted small">${icon('target')}Next: ${next.title}</p>` : ''}
        <div class="row"><a class="btn btn-primary btn-sm" href="#/achievements${one && one.kind === 'achievement' ? '/' + one.id : ''}" data-act="celebrate-view">View</a>
          <button class="btn btn-ghost btn-sm" data-act="celebrate-close">Dismiss</button></div>
      </div>
      <button class="btn-icon celebrate-x" data-act="celebrate-close" aria-label="Dismiss">${icon('x')}</button>`);
    document.body.appendChild(box);
    let t = setTimeout(close, 15000);
    box.addEventListener('mouseenter', () => clearTimeout(t));
    box.addEventListener('focusin', () => clearTimeout(t));
    box.addEventListener('mouseleave', () => { clearTimeout(t); t = setTimeout(close, 6000); });
    function close() { clearTimeout(t); box.classList.add('is-out'); setTimeout(() => { box.remove(); showing = false; }, 250); }
    box.close = close;
  }, 500);
  SM.actions['celebrate-close'] = (el) => { const b = el.closest('.celebrate'); if (b && b.close) b.close(); };
  SM.actions['celebrate-view'] = (el) => { SM.actions['celebrate-close'](el); SM.go(el.getAttribute('href').slice(1)); };

  /* -------------------------------------------------------- jalon suivant */
  /** « Your Next Milestone » : un seul pas concret, tiré de l'activité réelle (accueil). */
  const milestoneView = (n, level) => html`<section class="panel pad milestone">
      <header class="row between"><h2 class="h3">${icon('target')}Your Next Milestone</h2>${level ? html`<a class="small" href="#/achievements">${levelName(level)}</a>` : ''}</header>
      <b class="milestone-title">${n.title}</b><p class="muted small">${n.desc}</p>
      <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="${n.need}" aria-valuenow="${n.have}" aria-label="${n.title}"><i style="width:${n.percent ? n.have : pct(n.have, n.need)}%"></i></div>
      <div class="row between small"><span class="muted">${n.percent ? n.have + '%' : fmt.num(n.have) + ' / ' + fmt.num(n.need)}</span>${n.reward ? html`<span class="chip chip-soft">+${fmt.num(n.reward)} XP</span>` : ''}</div>
      <a class="btn btn-soft btn-sm btn-block" href="${n.href}">${n.cta}</a>
    </section>`;
  SM.milestoneWidget = async (els) => {
    let r;
    try { r = await SM.get('journey_next'); } catch (e) { return; }
    const n = r.next[0];
    els.filter(Boolean).forEach((el) => SM.set(el, n ? milestoneView(n, r.level) : ''));
  };

  /* ------------------------------------------------------------ éléments */
  const vchip = (a) => html`<span class="vchip ${a.status === 'revoked' ? 'jv-revoked' : a.status === 'pending' ? 'jv-pending' : VER[a.verification] || ''}">${a.status === 'verified' ? icon('check-circle') : a.status === 'revoked' ? icon('alert') : a.status === 'pending' ? icon('clock') : ''}${a.label}</span>`;
  const badge = (a, cls) => html`<span class="ach-badge ${VER[a.verification] || ''} ${a.status !== 'verified' && a.status !== 'self' ? 'is-dim' : ''} ${cls || ''}" aria-hidden="true">${icon(a.icon)}</span>`;
  const progressBar = (p, label) => html`<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${p}" aria-label="${label}"><i style="width:${p}%"></i></div>`;
  const achCard = (a) => html`<article class="ach-card ach-${a.status}">
      ${badge(a)}
      <div class="ach-main"><h3>${a.title}</h3><p class="muted small clamp-2">${a.desc}</p>
        <div class="ach-meta">${vchip(a)}${a.xp ? html`<span class="muted small">+${fmt.num(a.xp)} XP</span>` : ''}${a.earned ? html`<span class="muted small">${fmt.date(a.earned)}</span>` : ''}</div>
        ${a.progress ? html`<div class="ach-progress">${progressBar(a.progress.percent, a.title)}<span class="muted small">${a.progress.percent}%</span></div>` : ''}
        ${a.pending_note ? html`<p class="muted small">${icon('clock')}${a.pending_note}</p>` : ''}
        ${a.status === 'revoked' ? html`<p class="small ach-revoked">${icon('alert')}Revoked by Studioma${a.revoked_reason ? ': ' + a.revoked_reason : ''}</p>` : ''}
      </div>
      <footer class="row"><a class="btn btn-ghost btn-sm" href="#/achievements/${a.id ? a.id : 'a/' + a.aid}">View Details</a>
        ${a.id && a.shareable ? html`<button class="btn btn-soft btn-sm" data-act="ach-share" data-id="${a.id}">${icon('share')}Share</button>` : ''}</footer>
    </article>`;
  const TABS = [['verified', 'Verified'], ['progress', 'In Progress'], ['pending', 'Pending Verification'], ['self', 'Self reported']];

  /* -------------------------------------------------- tableau de bord */
  let J = null;   // dernières données du tableau de bord (partages)
  SM.route(/^\/achievements$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page">${SM.loading(4)}</div>`);
    const d = await SM.get('journey');
    if (!ctx.alive()) return;
    J = d;
    const tab = SM.query().get('tab') || 'verified';
    const l = d.level;
    const nx = l.next;
    const by = (t) => d.achievements.filter((a) => a.tab === t || (t === 'verified' && a.status === 'revoked'));
    const tabs = TABS.filter(([k]) => k !== 'self' || by('self').length);
    const shown = by(tabs.some(([k]) => k === tab) ? tab : 'verified');
    SM.set(ctx.view, html`<div class="page journey">
      <header class="page-head"><div><p class="eyebrow-s">${icon('trophy')}My Achievements</p><h1>Your Creator Journey</h1>
        <p class="muted">Your Work. Your Reputation. Every meaningful action builds your professional identity.</p></div>
        <div class="row row-wrap"><a class="btn btn-ghost" href="#/achievements/settings">${icon('eye')}Visibility & Passport</a>
          <button class="btn btn-primary" data-act="passport-share">${icon('share')}Share Passport</button></div></header>
      <section class="panel passport-card">
        <div class="passport-id">${SM.avatar(d.profile, 'av-l')}<div><p class="eyebrow-s">Creator Passport</p><h2>${d.profile.name}</h2><p class="muted">${d.profile.title}</p></div></div>
        <div class="passport-level"><span class="level-pill">${icon('trophy')}${levelName(l)}</span>
          ${nx ? html`<div class="xp-line"><b>${fmt.num(l.xp)} / ${fmt.num(nx.xp_target)} XP</b><span class="muted">Progress to ${nx.name}: ${nx.percent}%</span></div>
            ${progressBar(nx.xp_percent, 'XP toward ' + nx.name)}` : html`<div class="xp-line"><b>${fmt.num(l.xp)} XP</b><span class="muted">Highest Studioma level reached</span></div>`}</div>
        <div class="passport-stats">${[['Total XP', l.xp], ['Achievements', d.stats.achievements], ['Completed projects', d.stats.projects], ['Verified', d.stats.verified], ['Client-approved', d.stats.client]]
          .map(([k, v]) => html`<div class="stat-mini"><b>${fmt.num(v)}</b><small>${k}</small></div>`)}</div>
        <p class="muted small passport-summary">${d.summary}</p>
      </section>
      <div class="with-rail">
        <div class="stack-l">
          ${d.next.length ? milestoneView(d.next[0], null) : ''}
          <section class="panel pad stack">
            <header class="row between row-wrap"><h2 class="section-title">${icon('award')}Achievement Collection</h2>
              <button class="btn btn-ghost btn-sm" data-act="self-new">${icon('plus')}Add a self-reported achievement</button></header>
            <div class="tabs" role="tablist">${tabs.map(([k, label]) => html`<a class="tab${(tabs.some(([t]) => t === tab) ? tab : 'verified') === k ? ' is-active' : ''}" role="tab" href="#/achievements?tab=${k}">${label} <span class="count">${by(k).length}</span></a>`)}</div>
            ${shown.length ? html`<div class="ach-grid">${shown.map(achCard)}</div>`
              : SM.empty('trophy', tab === 'pending' ? 'Nothing awaiting verification' : tab === 'verified' ? 'No verified achievement yet' : 'Nothing here', tab === 'verified' ? 'Complete real work in your Studios: your first achievements are close.' : 'Achievements appear here as soon as they apply to you.')}
          </section>
          <section class="panel pad stack" id="timeline"><header class="row between row-wrap"><h2 class="section-title">${icon('history')}Career Progress</h2>
            <div class="seg-inline" role="group" aria-label="Filter the timeline">${[['all', 'All Activity'], ['achievements', 'Achievements'], ['projects', 'Projects'], ['collaboration', 'Collaboration'], ['clients', 'Client Recognition']]
              .map(([k, label], i) => html`<label><input type="radio" name="tl" value="${k}" data-change="timeline" ${i === 0 ? 'checked' : ''}><span>${label}</span></label>`)}</div></header>
            <div id="tl-list">${SM.loading(2)}</div></section>
        </div>
        <aside class="rail">
          <section class="panel pad stack"><h3>${icon('layers')}Creator Levels</h3>
            <ol class="levels">${d.levels.map((x) => html`<li class="${x.reached ? 'is-reached' : ''} ${x.n === l.n ? 'is-current' : ''}"><span class="lv-n">${pad2(x.n)}</span><span><b>${x.name}</b><small class="muted">${x.about}</small></span></li>`)}</ol>
            ${nx ? html`<div class="stack-s"><b class="small">To reach ${levelName(nx)}</b>
              <ul class="criteria">${nx.criteria.map((c) => html`<li class="${c.ok ? 'is-ok' : ''}">${icon(c.ok ? 'check-circle' : 'target')}<span>${c.label}<small class="muted">${fmt.num(c.have)} / ${fmt.num(c.need)}</small></span></li>`)}</ul></div>` : ''}
            <p class="muted small">Levels are Studioma's internal indicators of professional development — not externally accredited qualifications.</p>
          </section>
          <section class="panel pad stack"><h3>${icon('zap')}How you earn XP</h3>
            <ul class="xp-rules">${d.events.map((e) => html`<li><span>${e.label}</span><b>+${e.xp}</b></li>`)}</ul>
            <p class="muted small">Only meaningful work counts, once per item, with daily limits on repeated actions. Empty cards earn nothing. XP and achievements stay yours whatever your plan.</p>
          </section>
        </aside>
      </div>
    </div>`);
    loadTimeline('all');
  });
  async function loadTimeline(filter) {
    const box = SM.$('#tl-list');
    if (!box) return;
    const r = await SM.get('journey_timeline', { filter });
    SM.set(box, r.items.length ? html`<ol class="timeline">${r.items.map((i) => html`<li><span class="tl-ico">${icon(i.icon)}</span><span class="grow">${i.text}</span><time class="muted small">${fmt.date(i.at)}</time></li>`)}</ol>`
      : html`<p class="muted small">Nothing recorded in this category yet.</p>`);
  }
  SM.changes.timeline = (el) => loadTimeline(el.value);

  /* -------------------------------------------------------------- détails */
  SM.route(/^\/achievements\/(\d+|a\/[a-z_]+)$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow">${SM.loading(3)}</div>`);
    const isDef = m[1].startsWith('a/');
    const r = await SM.get('journey_achievement', isDef ? { aid: m[1].slice(2) } : { id: m[1] });
    if (!ctx.alive()) return;
    const a = r.achievement;
    const state = a.status === 'verified' ? ['ok', 'Verified', a.label] : a.status === 'self' ? ['self', 'Self reported', 'Not independently verified by Studioma']
      : a.status === 'revoked' ? ['bad', 'Revoked', a.revoked_reason ? 'Reason: ' + a.revoked_reason : 'No longer valid'] : a.status === 'pending' ? ['warn', 'Pending verification', a.pending_note || 'Waiting for confirmation']
        : ['soft', 'In progress', (a.progress ? a.progress.percent : 0) + '% done'];
    SM.set(ctx.view, html`<div class="page page-narrow ach-detail">
      <a class="back" href="#/achievements">${icon('arrow-left')}My Achievements</a>
      <section class="panel pad stack">
        <div class="ach-hero">${badge(a, 'ach-badge-l')}<div><p class="eyebrow-s">${a.category}</p><h1>${a.title}</h1><p class="muted">${a.status === 'self' && a.body ? a.body : a.desc}</p></div></div>
        <div class="status-box status-${state[0]}"><b>${state[1]}</b><span>${state[2]}</span></div>
        <ul class="pub-facts">
          <li>${icon('shield')}<span><small>Verification</small><b>${a.status === 'self' ? 'Self Reported — not independently verified' : a.label}</b></span></li>
          ${a.earned ? html`<li>${icon('calendar')}<span><small>${a.status === 'self' ? 'Date' : 'Date earned'}</small><b>${fmt.date(a.earned)}</b></span></li>` : ''}
          ${a.xp ? html`<li>${icon('zap')}<span><small>${a.earned ? 'XP awarded' : 'Reward'}</small><b>+${fmt.num(a.xp)} XP</b></span></li>` : ''}
          ${a.id ? html`<li>${icon('flag')}<span><small>Achievement ID</small><b class="mono">${a.public_id}</b></span></li>` : ''}
          ${a.id && a.status !== 'revoked' ? html`<li>${icon('eye')}<span><small>Visibility</small><b>${VIS[a.visibility]}</b></span></li>` : ''}
        </ul>
        ${a.progress ? html`<div class="stack-s"><b class="small">Progress</b>${a.progress.parts.map((p) => html`<div class="part"><span class="grow small">${p.label}</span><span class="muted small">${p.have} / ${p.need}</span></div>${progressBar(pct(p.have, p.need), p.label)}`)}</div>` : ''}
        <h2 class="section-title">${icon('check-circle')}Evidence</h2>
        <p class="muted small">${a.evidence_label}</p>
        ${a.evidence.length ? html`<ul class="evidence">${a.evidence.map((e) => html`<li>${icon('file')}<span class="grow"><b>${e.label}</b><small class="muted">${fmt.date(e.at)}${e.xp != null ? ' · +' + e.xp + ' XP' : ''}</small>
            ${e.project ? html`<small>${e.project}${e.studio ? ' › ' + e.studio : ''}${e.card ? ' › ' + e.card : ''}</small>` : ''}
            ${e.client ? html`<small>${e.client}</small>` : ''}${e.note ? html`<small class="muted">${e.note}</small>` : ''}</span>
            ${e.href ? html`<a class="btn btn-ghost btn-sm" href="${e.href}">Open</a>` : ''}</li>`)}</ul>`
          : a.status === 'self' && a.link ? html`<p><a href="${a.link}" target="_blank" rel="noopener nofollow">${a.link}</a></p>` : html`<p class="muted small">No evidence recorded yet.</p>`}
        <h2 class="section-title">${icon('info')}What this proves</h2>
        <p>${a.proves}</p><p class="muted">${a.not}</p>
        <p class="muted small">Project, Studio, card and client details are shown to you only while you can access them. Public pages never show them.</p>
        <footer class="row row-wrap">
          ${a.id && a.shareable ? html`<button class="btn btn-primary" data-act="ach-share" data-id="${a.id}">${icon('share')}Share Achievement</button>` : ''}
          ${a.status === 'self' ? html`<button class="btn btn-ghost" data-act="self-edit" data-id="${a.id}">${icon('edit')}Edit</button><button class="btn btn-ghost" data-act="self-delete" data-id="${a.id}">${icon('trash')}Delete</button>` : ''}
          ${a.url ? html`<a class="btn btn-ghost" href="${a.url}" target="_blank" rel="noopener">${icon('external')}Open the public page</a>` : ''}
        </footer>
      </section>
    </div>`);
    SM.achDetail = a;
  });

  /* ------------------------------------------------- réglages et visibilité */
  SM.route(/^\/achievements\/settings$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow">${SM.loading(3)}</div>`);
    const d = await SM.get('journey_settings');
    if (!ctx.alive()) return;
    const p = d.passport;
    SM.set(ctx.view, html`<div class="page page-narrow">
      <a class="back" href="#/achievements">${icon('arrow-left')}My Achievements</a>
      <header class="page-head"><div><h1>Visibility & Passport</h1><p class="muted">Everything is private until you choose otherwise. Public pages never show your projects, clients, files or email.</p></div></header>
      <section class="panel pad"><form class="stack" data-form="passport-save">
        <h2 class="section-title">${icon('globe')}Creator Passport</h2>
        <label class="switch-row"><input type="checkbox" name="enabled" ${p.enabled ? 'checked' : ''}><span><b>Public Creator Passport</b><small class="muted">${p.url}</small></span></label>
        <label class="field"><span>Professional headline</span><input class="input" name="headline" maxlength="120" value="${p.headline}" placeholder="${SM.me.title || 'Creative Professional'}"></label>
        <fieldset class="stack-s"><legend class="small muted">Show on my passport</legend>
          ${[['show_level', 'My Studioma level'], ['show_stats', 'My numbers (verified achievements, completed projects…) and a factual summary'], ['show_work', 'Selected published portfolio work'], ['show_journey', 'My Creator Journey progress and public timeline']]
            .map(([k, label]) => html`<label class="check"><input type="checkbox" name="${k}" ${p[k] ? 'checked' : ''}><span>${label}</span></label>`)}</fieldset>
        <p class="muted small">Only achievements set to “Public on my Creator Passport” appear on it.${p.views ? ' Passport views: ' + fmt.num(p.views) + '.' : ''}</p>
        <div class="row row-wrap"><button class="btn btn-primary">Save</button>${p.enabled ? html`<a class="btn btn-ghost" href="${p.url}" target="_blank" rel="noopener">${icon('external')}View my passport</a>` : ''}</div>
      </form></section>
      <section class="panel pad stack"><h2 class="section-title">${icon('eye')}Achievement visibility</h2>
        ${d.achievements.length ? html`<div class="list">${d.achievements.map((a) => html`<div class="list-row">${badge(a, 'ach-badge-s')}<span class="grow"><b>${a.title}</b><small class="muted">${a.label}</small></span>
            ${a.status === 'revoked' ? html`<span class="chip">Revoked</span>` : html`<select class="input input-s" data-change="ach-vis" data-id="${a.id}" aria-label="Visibility of ${a.title}">${Object.entries(VIS).map(([k, label]) => html`<option value="${k}" ${a.visibility === k ? 'selected' : ''}>${label}</option>`)}</select>`}</div>`)}</div>`
          : html`<p class="muted small">Your earned achievements will appear here.</p>`}
      </section>
      ${d.given.length ? html`<section class="panel pad stack"><h2 class="section-title">${icon('shield')}Approvals you gave as a client</h2>
        <p class="muted small">Creators can show that a client approved their work. Your name appears on their public pages only if you allow it; otherwise it reads “a verified client”.</p>
        <div class="list">${d.given.map((g) => html`<div class="list-row">${SM.avatar(g.creator, 'av-m')}<span class="grow"><b>${g.creator ? g.creator.name : 'A creator'}</b><small class="muted">${g.achievement} · ${fmt.date(g.at)}</small></span>
          <label class="check"><input type="checkbox" data-change="consent" data-id="${g.id}" ${g.named ? 'checked' : ''}><span>Show my name</span></label></div>`)}</div></section>` : ''}
    </div>`);
  });
  SM.forms['passport-save'] = async (form, data) => {
    const r = await SM.post('journey_passport_save', data);
    SM.toast(r.passport.enabled ? 'Your Creator Passport is public' : 'Your Creator Passport is private');
    SM.navigate();
  };
  SM.changes['ach-vis'] = async (el) => {
    const r = await SM.try(SM.post('journey_visibility', { id: +el.dataset.id, visibility: el.value }));
    if (r) SM.toast(el.value === 'private' ? 'Now private' : el.value === 'link' ? 'Anyone with the link can verify it' : 'Shown on your Creator Passport');
  };
  SM.changes.consent = async (el) => {
    if (await SM.try(SM.post('journey_consent', { id: +el.dataset.id, named: el.checked }))) SM.toast(el.checked ? 'Your name may appear on this achievement' : 'Your name is hidden');
  };

  /* -------------------------------------------- réalisations déclarées */
  const selfDialog = (a) => SM.modal(html`${SM.modalHead(a ? 'Edit self-reported achievement' : 'Add a self-reported achievement', 'Shown as “Self Reported — not independently verified”. It earns no XP.')}
    <form class="modal-body stack" data-form="self-save">${a ? html`<input type="hidden" name="id" value="${a.id}">` : ''}
      <label class="field"><span>Title</span><input class="input" name="title" maxlength="100" required value="${a ? a.title : ''}" placeholder="Speaker at a marketing meetup"></label>
      <label class="field"><span>Description</span><textarea class="input" name="body" rows="3" maxlength="1000">${a ? a.body || '' : ''}</textarea></label>
      <div class="row row-wrap"><label class="field grow"><span>Date</span><input class="input" type="date" name="date" value="${a && a.earned ? new Date(a.earned * 1000).toISOString().slice(0, 10) : ''}"></label>
        <label class="field grow"><span>Supporting link (optional)</span><input class="input" type="url" name="url" value="${a ? a.link || '' : ''}" placeholder="https://"></label></div>
      <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">Save</button></footer>
    </form>`);
  SM.actions['self-new'] = () => selfDialog(null);
  SM.actions['self-edit'] = () => selfDialog(SM.achDetail);
  SM.forms['self-save'] = async (form, data) => {
    const r = await SM.post('journey_self_save', data);
    SM.closeModals();
    SM.toast('Saved — shown as self-reported');
    SM.go('/achievements/' + r.achievement.id);
  };
  SM.actions['self-delete'] = async (el) => {
    if (!await SM.confirm({ title: 'Delete this self-reported achievement?', ok: 'Delete', danger: true })) return;
    if (await SM.try(SM.post('journey_self_delete', { id: +el.dataset.id }))) { SM.toast('Deleted'); SM.go('/achievements'); }
  };

  /* --------------------------------------------------------------- partage */
  const enc = encodeURIComponent;
  const socials = (url, text, subject) => [
    ['LinkedIn', 'briefcase', `https://www.linkedin.com/sharing/share-offsite/?url=${enc(url)}`],
    ['Facebook', 'share', `https://www.facebook.com/sharer/sharer.php?u=${enc(url)}`],
    ['WhatsApp', 'message', `https://wa.me/?text=${enc(text + ' ' + url)}`],
    ['Email', 'mail', `mailto:?subject=${enc(subject)}&body=${enc(text + '\n\n' + url)}`],
  ];
  const shareButtons = (url, text, subject, id) => html`<div class="row row-wrap">
      <button class="btn btn-soft btn-sm" data-act="native-share" data-url="${url}" data-text="${text}" data-id="${id || ''}">${icon('share')}Share…</button>
      ${socials(url, text, subject).map(([label, ic, href]) => html`<a class="btn btn-ghost btn-sm" href="${href}" target="_blank" rel="noopener noreferrer" data-track="${id || ''}" data-share="${label.toLowerCase()}">${icon(ic)}${label}</a>`)}</div>`;
  SM.shareButtons = shareButtons;   // opportunités (assets/publish.js)
  SM.actions['native-share'] = async (el) => {
    track(el.dataset.id);
    if (navigator.share) { try { await navigator.share({ title: 'Studioma', text: el.dataset.text, url: el.dataset.url }); } catch (e) { /* annulé */ } return; }
    SM.copy(el.dataset.text + ' ' + el.dataset.url);
  };
  // Les liens de partage s'ouvrent normalement (le réseau demande confirmation) ; on compte seulement le geste.
  document.addEventListener('click', (e) => { const a = e.target.closest('a[data-track]'); if (a) track(a.dataset.track); });
  const track = (id) => { if (+id) SM.post('journey_shared', { id: +id }).catch(() => {}); };
  SM.actions['copy-link'] = (el) => { track(el.dataset.id); SM.copy(el.dataset.text); };

  const FORMATS = { landscape: [1200, 630, 'Landscape · 1200 × 630 (LinkedIn, Facebook)'], square: [1080, 1080, 'Square · 1080 × 1080 (Instagram post)'], story: [1080, 1920, 'Story · 1080 × 1920'] };
  async function achievementOf(id) {
    const r = await SM.get('journey_achievement', { id });
    return r.achievement;
  }
  SM.actions['ach-share'] = async (el) => {
    const a = await SM.try(achievementOf(+el.dataset.id));
    if (a) shareDialog(a);
  };
  function shareDialog(a) {
    const body = () => {
      const text = a.status === 'self' ? `I added “${a.title}” to my Studioma Creator Passport (self-reported).` : `I earned “${a.title}” on Studioma — ${a.label}. Verify it here:`;
      return html`${SM.modalHead('Share Achievement', a.title)}
      <div class="modal-body stack share-ach">
        <fieldset class="stack-s"><legend class="small"><b>Who can see the verification page</b></legend>
          ${Object.entries(VIS).map(([k, label]) => html`<label class="check"><input type="radio" name="vis" value="${k}" data-change="share-vis" ${a.visibility === k ? 'checked' : ''}><span>${label}${k === 'private' ? html` <small class="muted">— only you</small>` : ''}</span></label>`)}</fieldset>
        ${a.url ? html`<div class="ref-link"><input class="input" value="${a.url}" readonly aria-label="Verification link"><button class="btn btn-primary" data-act="copy-link" data-text="${a.url}" data-id="${a.id}">${icon('copy')}Copy Link</button></div>
          ${shareButtons(a.url, text, a.title + ' — Studioma achievement', a.id)}
          <p class="muted small">${icon('shield')}The link shows the current verification status. It never opens your Studios, cards or client files.</p>`
          : html`<p class="notice">${icon('lock')}<span>This achievement is private. Choose “Anyone with the link” to get a verification link you can share.</span></p>`}
        <h3 class="section-title">${icon('image')}Share as image</h3>
        <div class="row row-wrap"><label class="field grow"><span>Format</span><select class="input" data-change="png-format" id="png-format">${Object.entries(FORMATS).map(([k, f]) => html`<option value="${k}">${f[2]}</option>`)}</select></label>
          <label class="check"><input type="checkbox" id="png-qr" data-change="png-format" ${a.url ? 'checked' : 'disabled'}><span>QR code to the verification page</span></label></div>
        <div class="png-preview"><canvas id="png-canvas" aria-label="Preview of the achievement image"></canvas></div>
        <div class="row"><button class="btn btn-primary" data-act="png-download">${icon('download')}Download PNG</button></div>
      </div>`;
    };
    const m = SM.modal(body(), { size: 'l' });
    SM.shareAch = { a, m, render: async () => { m.el.innerHTML = String(body()); await preview(); } };
    preview();
  }
  SM.changes['share-vis'] = async (el) => {
    const s = SM.shareAch;
    const r = await SM.try(SM.post('journey_visibility', { id: s.a.id, visibility: el.value }));
    if (!r) return;
    s.a = Object.assign(s.a, { visibility: r.achievement.visibility, url: r.achievement.url });
    s.render();
  };
  SM.changes['png-format'] = () => preview();
  async function preview() {
    const s = SM.shareAch;
    const c = SM.$('#png-canvas');
    if (!s || !c) return;
    const fmtKey = (SM.$('#png-format') || {}).value || 'landscape';
    const qr = !!((SM.$('#png-qr') || {}).checked && s.a.url);
    await drawCard(c, s.a, J ? J.profile : SM.me, fmtKey, qr);
  }
  SM.actions['png-download'] = async () => {
    const s = SM.shareAch;
    const c = SM.$('#png-canvas');
    if (!s || !c) return;
    await preview();
    c.toBlob((blob) => {
      if (!blob) { SM.toast('The image could not be created', 'error'); return; }
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = 'studioma-' + s.a.title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '-' + ((SM.$('#png-format') || {}).value || 'landscape') + '.png';
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 2000);
      track(s.a.id);
    }, 'image/png');
  };

  /* ------------------------------------------------------- image PNG (canvas) */
  /** Dessine une icône (tracés de assets/icons.js : path, circle, rect) en blanc. */
  function drawIcon(ctx, name, x, y, size, color) {
    const src = (window.SM_ICONS || {})[name] || (window.SM_ICONS || {}).star;
    const doc = new DOMParser().parseFromString('<svg xmlns="http://www.w3.org/2000/svg">' + src + '</svg>', 'image/svg+xml');
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(size / 24, size / 24);
    ctx.strokeStyle = color || '#fff';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    Array.from(doc.documentElement.children).forEach((n) => {
      const g = (k) => parseFloat(n.getAttribute(k) || '0');
      if (n.tagName === 'path') ctx.stroke(new Path2D(n.getAttribute('d')));
      else if (n.tagName === 'circle') { ctx.beginPath(); ctx.arc(g('cx'), g('cy'), g('r'), 0, Math.PI * 2); ctx.stroke(); }
      else if (n.tagName === 'rect') { ctx.beginPath(); roundRect(ctx, g('x'), g('y'), g('width'), g('height'), g('rx')); ctx.stroke(); }
    });
    ctx.restore();
  }
  function roundRect(ctx, x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function wrap(ctx, text, maxW, maxLines) {
    const words = String(text).split(/\s+/);
    const lines = [];
    let cur = '';
    words.forEach((w) => { const t = cur ? cur + ' ' + w : w; if (ctx.measureText(t).width <= maxW || !cur) cur = t; else { lines.push(cur); cur = w; } });
    if (cur) lines.push(cur);
    if (lines.length > maxLines) { lines.length = maxLines; lines[maxLines - 1] = lines[maxLines - 1].replace(/\s*\S*$/, '') + '…'; }
    return lines;
  }
  const FONT = '"Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif';
  const COLORS = { system: ['#7c6cff', '#5b4bff'], client: ['#2fc6c9', '#1a8f9e'], evidence: ['#ff7a59', '#e0466d'], self: ['#4a4566', '#2d2850'] };
  async function drawCard(canvas, a, who, format, withQr) {
    try { await Promise.all([document.fonts.load('800 60px "Plus Jakarta Sans"'), document.fonts.load('600 30px "Plus Jakarta Sans"')]); } catch (e) { /* police système */ }
    const [W, H] = FORMATS[format] || FORMATS.landscape;
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');
    const u = Math.min(W, H) / 100;   // unité relative
    // Fond sombre, lueurs violette et corail.
    const bg = ctx.createLinearGradient(0, 0, W, H);
    bg.addColorStop(0, '#0f0d1f'); bg.addColorStop(1, '#1f1b38');
    ctx.fillStyle = bg; ctx.fillRect(0, 0, W, H);
    const glow = (x, y, r, c) => { const g = ctx.createRadialGradient(x, y, 0, x, y, r); g.addColorStop(0, c); g.addColorStop(1, 'rgba(0,0,0,0)'); ctx.fillStyle = g; ctx.fillRect(0, 0, W, H); };
    glow(W * 0.85, H * 0.1, Math.max(W, H) * 0.6, 'rgba(124,108,255,0.35)');
    glow(W * 0.05, H * 0.95, Math.max(W, H) * 0.5, 'rgba(255,122,89,0.18)');
    const m = 7 * u;
    // Logo Studioma.
    const ls = 8 * u;
    ctx.fillStyle = '#5b4bff'; ctx.beginPath(); roundRect(ctx, m, m, ls, ls, ls * 0.28); ctx.fill();
    const bar = (bx, bh, c, o) => { ctx.globalAlpha = o; ctx.fillStyle = c; ctx.beginPath(); roundRect(ctx, m + bx * ls / 32, m + 8 * ls / 32, 5 * ls / 32, bh * ls / 32, 2.5 * ls / 32); ctx.fill(); ctx.globalAlpha = 1; };
    bar(7, 16, '#fff', 1); bar(13.5, 11, '#fff', 0.75); bar(20, 7, '#ff7a59', 1);
    ctx.fillStyle = '#f3f1ff'; ctx.font = `800 ${5 * u}px ${FONT}`; ctx.textBaseline = 'middle';
    ctx.fillText('Studioma', m + ls + 2.5 * u, m + ls / 2);
    // Badge.
    const tall = H > W * 1.2;
    const bs = (tall ? 26 : 22) * u;
    const bx = m;
    const by = tall ? H * 0.2 : m + ls + 9 * u;
    const [c1, c2] = a.status === 'self' ? COLORS.self : COLORS[a.verification] || COLORS.system;
    const bgB = ctx.createLinearGradient(bx, by, bx + bs, by + bs);
    bgB.addColorStop(0, c1); bgB.addColorStop(1, c2);
    ctx.shadowColor = c1; ctx.shadowBlur = 6 * u;
    ctx.fillStyle = bgB; ctx.beginPath(); roundRect(ctx, bx, by, bs, bs, bs * 0.28); ctx.fill();
    ctx.shadowBlur = 0;
    drawIcon(ctx, a.icon, bx + bs * 0.22, by + bs * 0.22, bs * 0.56, '#fff');
    // Textes.
    const tx = tall ? m : bx + bs + 6 * u;
    let ty = tall ? by + bs + 9 * u : by + 2 * u;
    const textW = W - tx - m - (withQr && !tall ? 30 * u : 0);
    ctx.textBaseline = 'top';
    ctx.fillStyle = '#9d97c0';
    ctx.font = `700 ${2.8 * u}px ${FONT}`;
    ctx.fillText(a.status === 'self' ? 'SELF-REPORTED ACHIEVEMENT' : 'VERIFIED ACHIEVEMENT', tx, ty);
    ty += 5 * u;
    ctx.fillStyle = '#ffffff';
    ctx.font = `800 ${(tall ? 9 : 7.5) * u}px ${FONT}`;
    wrap(ctx, a.title, textW, 2).forEach((line) => { ctx.fillText(line, tx, ty); ty += (tall ? 10.5 : 8.6) * u; });
    ty += 1.5 * u;
    ctx.fillStyle = '#c9c4e6';
    ctx.font = `600 ${3.6 * u}px ${FONT}`;
    ctx.fillText('Awarded to ' + ((who && who.name) || ''), tx, ty);
    ty += 7 * u;
    // Pastille de vérification (libellé du serveur : jamais « Verified » pour une déclaration).
    const label = a.status === 'self' ? 'Self Reported — not independently verified' : a.label;
    ctx.font = `700 ${3 * u}px ${FONT}`;
    const pw = ctx.measureText(label).width + 6 * u;
    ctx.fillStyle = a.status === 'self' ? 'rgba(245,184,74,0.16)' : 'rgba(54,211,154,0.16)';
    ctx.beginPath(); roundRect(ctx, tx, ty, pw, 6 * u, 3 * u); ctx.fill();
    ctx.fillStyle = a.status === 'self' ? '#f5b84a' : '#36d39a';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, tx + 3 * u, ty + 3 * u);
    ty += 9.5 * u;
    ctx.textBaseline = 'top';
    ctx.fillStyle = '#9d97c0';
    ctx.font = `600 ${3 * u}px ${FONT}`;
    ctx.fillText((a.status === 'self' ? 'Date: ' : 'Earned on ') + fmt.date(a.earned) + (a.xp && a.status !== 'self' ? '  ·  +' + fmt.num(a.xp) + ' XP' : ''), tx, ty);
    // QR code vers la page de vérification.
    if (withQr && a.url && window.StmQr) {
      const q = window.StmQr(a.url);
      if (q) {
        const qs = (tall ? 34 : 24) * u;
        const qx = W - m - qs;
        const qy = tall ? H - m - qs - 12 * u : H - m - qs - 4 * u;
        ctx.fillStyle = '#ffffff';
        ctx.beginPath(); roundRect(ctx, qx - 1.6 * u, qy - 1.6 * u, qs + 3.2 * u, qs + 3.2 * u, 2 * u); ctx.fill();
        const cell = qs / q.size;
        ctx.fillStyle = '#0f0d1f';
        for (let y = 0; y < q.size; y++) for (let x = 0; x < q.size; x++) if (q.dark(x, y)) ctx.fillRect(qx + x * cell, qy + y * cell, Math.ceil(cell), Math.ceil(cell));
        ctx.fillStyle = '#c9c4e6'; ctx.font = `600 ${2.4 * u}px ${FONT}`; ctx.textAlign = 'center';
        ctx.fillText('Scan to verify', qx + qs / 2, qy + qs + 3 * u);
        ctx.textAlign = 'left';
      }
    }
    // Pied de page.
    ctx.fillStyle = '#9d97c0';
    ctx.font = `600 ${2.6 * u}px ${FONT}`;
    ctx.textBaseline = 'bottom';
    ctx.fillText('Your Work. Your Reputation.', m, H - m + 2 * u);
    if (a.url) { ctx.font = `500 ${2.2 * u}px ${FONT}`; ctx.fillText(a.url.replace(/^https?:\/\//, ''), m, H - m - 2.5 * u); }
  }
  SM.drawAchievementCard = drawCard;   // tests

  /* --------------------------------------------------------- passeport */
  SM.actions['passport-share'] = async () => {
    const d = J || await SM.get('journey');
    const p = d.passport;
    const text = `My Studioma Creator Passport: verified achievements and professional progress.`;
    SM.modal(html`${SM.modalHead('Share your Creator Passport', p.url)}
      <div class="modal-body stack">
        ${p.enabled ? html`<div class="ref-link"><input class="input" value="${p.url}" readonly aria-label="Creator Passport link"><button class="btn btn-primary" data-act="copy-link" data-text="${p.url}">${icon('copy')}Copy Profile Link</button></div>
          ${shareButtons(p.url, text, 'My Studioma Creator Passport', 0)}
          <p class="muted small">Only what you chose in Visibility & Passport is shown. Nothing is published without your action.</p>`
          : html`<p class="notice">${icon('lock')}<span>Your Creator Passport is private. Turn it on in Visibility & Passport to get a public link.</span></p>
            <button class="btn btn-primary" data-act="goto-settings">${icon('settings')}Open Visibility & Passport</button>`}
      </div>`);
  };

  SM.actions['goto-settings'] = () => { SM.closeModals(); SM.go('/achievements/settings'); };

  /* ------------------------------------------- pages publiques dans l'application */
  // /studioma/verify/<id> et /studioma/creator/<identifiant> sont des pages du serveur (p.php) : on y renvoie.
  SM.route(/^\/verify\/([a-f0-9]{16})$/, (m) => { location.href = SM.base + 'verify/' + m[1]; }, { public: true });
  SM.route(/^\/creator\/([a-z0-9-]{1,40})$/, (m) => { location.href = SM.base + 'creator/' + m[1]; }, { public: true });

  /* -------------------------------------------------------- projet terminé */
  SM.projectCompleteDialog = (p) => {
    const c = p.completion;
    if (!c) return;
    if (c.completed_at) {
      SM.confirm({ title: 'Reopen this project?', text: 'It will no longer show as completed. XP already earned by contributors stays: completing it again later gives nothing twice.', ok: 'Reopen project' })
        .then(async (ok) => { if (ok && await SM.try(SM.post('project_complete', { id: p.id, completed: false }))) { SM.toast('Project reopened'); SM.navigate(); } });
      return;
    }
    SM.modal(html`${SM.modalHead('Mark the project as completed', 'Studioma checks the project before recording it.')}
      <div class="modal-body stack">
        <ul class="criteria">${c.criteria.map((k) => html`<li class="${k.ok ? 'is-ok' : ''}">${icon(k.ok ? 'check-circle' : 'alert')}<span>${k.label}${k.key === 'cards' ? html`<small class="muted">${k.have} / ${k.need}</small>` : k.key === 'open' && !k.ok ? html`<small class="muted">${fmt.plural(k.have, 'open card')}</small>` : ''}</span></li>`)}</ul>
        <p class="muted small">Every contributor who completed eligible cards in this project earns the “Complete a project” XP once, and the Project Finisher achievement on their first completed project.</p>
        <footer class="modal-foot"><button class="btn btn-ghost" data-act="modal-close">Cancel</button>
          <button class="btn btn-primary" data-act="project-complete" data-id="${p.id}" ${c.eligible ? '' : 'disabled'}>${icon('flag')}Mark as completed</button></footer>
      </div>`);
  };
  SM.actions['project-complete'] = async (el) => {
    SM.busy(el, true);
    const r = await SM.try(SM.post('project_complete', { id: +el.dataset.id, completed: true }));
    SM.busy(el, false);
    if (!r) return;
    SM.closeModals();
    SM.toast('Project completed — well done!');
    SM.navigate();
  };
})();
