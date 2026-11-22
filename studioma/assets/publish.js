/*
 * Studioma — « Share & publish » d'une carte et la Communauté.
 *
 *   - le menu Share & publish d'une carte : la partager seule (fenêtre de partage, studio.js), la publier comme
 *     opportunité (carte en cours) ou comme réalisation (carte terminée) ;
 *   - le formulaire de publication : ce qui sera public est saisi ou coché ici (rien n'est copié de la carte sans
 *     le choisir), aperçu, puis confirmation ;
 *   - la Communauté (#/community) : réalisations, opportunités (et offres des entreprises), mes publications, mes
 *     propositions ; la page d'une publication (#/pub/<id>) : J'aime et commentaires d'une réalisation, propositions
 *     d'une opportunité (envoi, suivi, réponses, invitation sur la carte).
 * Les règles (droits, ce qui est public, cycle de vie) sont appliquées par le serveur : lib/pubs.php.
 */
(function () {
  'use strict';
  const { html, raw, icon, fmt } = SM;
  const KIND_ICON = { pro: 'user', proposals: 'inbox', collab: 'users', help: 'hand' };
  const STATUS = { new: 'New', shortlisted: 'Shortlisted', accepted: 'Accepted', declined: 'Not selected', withdrawn: 'Withdrawn' };
  const needLogin = () => { if (SM.me) return false; sessionStorage.setItem('stm.next', SM.path()); SM.go('/signup'); return true; };
  const money = (n, cur) => (n ? new Intl.NumberFormat('en', { style: 'currency', currency: cur || 'USD', maximumFractionDigits: 0 }).format(n) : '');
  const budget = (p) => (p.budget_min || p.budget_max ? (p.budget_min && p.budget_max ? `${money(p.budget_min, p.currency)} – ${money(p.budget_max, p.currency)}` : p.budget_max ? `Up to ${money(p.budget_max, p.currency)}` : `From ${money(p.budget_min, p.currency)}`) : '');
  SM.pubBudget = budget;   // fil d'actualité (assets/social.js)
  const day = (d) => (d ? new Date(d + 'T12:00:00').toLocaleDateString('en', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
  const fileIcon = (f) => icon(f.kind === 'image' ? 'image' : f.kind === 'video' ? 'video' : 'file');

  /* ------------------------------------------------- menu Share & publish */
  /** Ce qu'on peut faire de cette carte, selon son étape et ses droits. */
  function choices(card) {
    const pub = (kind) => card.pubs.find((p) => p.kind === kind);
    const out = [];
    if (card.can.share) out.push({ key: 'share', icon: 'link', label: 'Share card', hint: 'A link to this card only — never the rest of the Studio' });
    if (card.can.publish && card.stage === 'open') {
      const o = pub('opportunity');
      out.push(o ? { key: 'opp-open', icon: 'briefcase', label: o.status === 'open' ? 'Open opportunity' : 'Closed opportunity', hint: o.proposals ? fmt.plural(o.proposals, 'proposal') : 'View, edit or close it', pub: o }
        : { key: 'opp-new', icon: 'briefcase', label: 'Publish opportunity', hint: 'Find a professional, proposals, collaboration or help' });
    }
    if (card.can.publish && card.stage === 'done') {
      const a = pub('achievement');
      out.push(a ? { key: 'ach-open', icon: 'award', label: a.status === 'published' ? 'Achievement' : 'Achievement (unpublished)', hint: 'View, edit or unpublish it', pub: a }
        : { key: 'ach-new', icon: 'award', label: 'Publish achievement', hint: 'Showcase the finished work in the Community' });
    }
    // Une opportunité restée sur une carte terminée (fermée d'elle-même) reste accessible.
    card.pubs.forEach((p) => { if (!out.some((c) => c.pub && c.pub.id === p.id)) out.push({ key: 'pub-open', icon: p.kind === 'achievement' ? 'award' : 'briefcase', label: p.kind === 'achievement' ? 'Achievement' : 'Opportunity (closed)', hint: 'View it', pub: p }); });
    return out;
  }
  function run(card, c) {
    if (c.key === 'share') SM.cardShareDialog(SM.board.id, card.id);
    else if (c.key === 'opp-new') publishDialog(card, 'opportunity', null);
    else if (c.key === 'ach-new') publishDialog(card, 'achievement', null);
    else if (c.pub) { SM.closeModals(); SM.go('/pub/' + c.pub.id); }
  }
  SM.sharePublishMenu = (anchor, card) => {
    const list = choices(card);
    const note = card.stage === 'cancelled' ? 'Cancelled cards can be shared, not published.' : !card.can.publish ? 'Publishing needs the Creator role or higher.' : '';
    SM.menu(anchor, [{ head: 'Share & publish' }, ...list.map((c) => ({ label: c.label, hint: c.hint, icon: c.icon, act: () => run(card, c) })),
      ...(note ? [{ sep: true }, { label: note, icon: 'info', disabled: true }] : [])], { align: 'right', width: 300 });
  };
  /** Fenêtre de choix (lien « Share & publish… » du menu de la carte sur le tableau). */
  SM.sharePublish = (card) => {
    const list = choices(card);
    const m = SM.modal(html`${SM.modalHead('Share & publish', `“${card.title}”`)}
      <div class="modal-body stack">
        ${list.length ? html`<div class="sp-choices">${list.map((c, i) => html`<button class="sp-choice" data-act="sp-choice" data-i="${i}">${icon(c.icon)}<span><b>${c.label}</b><small>${c.hint}</small></span>${icon('chevron-right')}</button>`)}</div>`
          : html`<p class="muted">Your role in this Studio doesn't allow sharing or publishing this card.</p>`}
        ${card.stage === 'cancelled' ? html`<p class="muted small">${icon('info')}Cancelled cards can be shared, not published.</p>` : ''}
        <p class="muted small privacy-note">${icon('lock')}Publishing never moves or copies the card: only what you choose in the next step becomes public.</p>
      </div>`, { size: 's' });
    SM.actions['sp-choice'] = (el) => { m.close(); run(card, list[+el.dataset.i]); };
  };

  /* --------------------------------------------------- publier une carte */
  let pubDraft = null;   // { card, kind, pub, values, modal }
  const val = (k, d) => (pubDraft.values[k] !== undefined ? pubDraft.values[k] : d);
  const opt = (list, cur, labels) => list.map((k) => html`<option value="${k}" ${k === cur ? 'selected' : ''}>${labels ? labels[k] : k}</option>`);
  function publishDialog(card, kind, pub) {
    const p = pub || {};
    // Réalisation déjà publiée : sa première image tient lieu de couverture.
    const src = (p.media_src || []).slice();
    const first = card && src.length ? (card.files || []).find((f) => f.id === src[0]) : null;
    const hasCover = kind === 'achievement' && !!(first && first.kind === 'image');
    pubDraft = {
      card, kind, pub,
      values: pub ? {
        title: p.title, body: p.body, category: p.category, skills: p.skills, outcome: p.outcome || '', show_team: !!(p.team && p.team.length),
        opp_kind: p.opp_kind || 'pro', deliverables: p.deliverables || '', budget_min: p.budget_min || '', budget_max: p.budget_max || '', currency: p.currency || 'USD',
        deadline: p.deadline || '', apply_until: p.apply_until || '', mode: p.mode || 'remote', location: p.location || '',
        files: src.slice(hasCover ? 1 : 0), cover: hasCover ? src[0] : 0,
      } : { title: card ? card.title : '', body: '', skills: [], opp_kind: 'pro', currency: 'USD', mode: 'remote', files: [], cover: 0, show_team: true },
    };
    pubDraft.modal = SM.modal(html`<div id="pub-dialog"></div>`, { size: 'l' });
    drawForm();
  }
  SM.publishDialog = publishDialog;
  function filesField() {
    const card = pubDraft.card;
    if (!card) return html`<p class="muted small">${icon('info')}The card is no longer available: the published files can't be changed.</p>`;
    const files = card.files || [];
    const chosen = new Set((val('files', []) || []).map(Number));
    const images = files.filter((f) => f.kind === 'image');
    const ach = pubDraft.kind === 'achievement';
    if (!files.length) return html`<p class="muted small">${icon('paperclip')}No attachment on this card. Attach files to the card to publish them.</p>`;
    return html`${ach ? html`<div class="field"><span>Cover image</span>${images.length ? html`<div class="pick-grid">
        <label class="pick"><input type="radio" name="cover" value="0" ${!+val('cover', 0) ? 'checked' : ''}><span class="pick-none">${icon('image')}No cover</span></label>
        ${images.map((f) => html`<label class="pick"><input type="radio" name="cover" value="${f.id}" ${+val('cover', 0) === f.id ? 'checked' : ''}><img src="${SM.fileUrl(f.token, true)}" alt="${f.name}"></label>`)}</div>`
        : html`<p class="muted small">Attach an image to the card to use it as cover.</p>`}</div>` : ''}
      <fieldset class="fieldset"><legend>${ach ? 'Completed assets to show' : 'Attachments to publish'} <em>optional</em></legend>
        <div class="stack-s">${files.map((f) => html`<label class="check">${raw(`<input type="checkbox" name="files[]" value="${f.id}" ${chosen.has(f.id) ? 'checked' : ''}>`)}${fileIcon(f)}<span>${f.name}</span></label>`)}</div>
        <p class="muted small">${icon('lock')}Only ticked files are copied to the public page. The others, and the card itself, stay private.</p></fieldset>`;
  }
  function drawForm() {
    const d = pubDraft;
    const ach = d.kind === 'achievement';
    const cats = SM.ref.pub_categories || [];
    SM.set(SM.$('#pub-dialog'), html`${SM.modalHead(d.pub ? (ach ? 'Edit achievement' : 'Edit opportunity') : (ach ? 'Publish as achievement' : 'Publish as opportunity'),
      ach ? 'Showcase this finished work in the Community and on your profile.' : 'Find professionals, proposals, collaboration or help for this card.')}
      <form class="modal-body stack" data-form="pub-preview">
        <p class="notice">${icon('shield')}<span>Everything below will be <b>public</b>${ach ? '' : ', visible even without an account'}. Nothing is copied from the card unless you write or tick it here — its comments, history, team and other files stay private.</span></p>
        ${ach ? '' : html`<div class="field"><span>What are you looking for?</span><div class="choice choice-grid" role="radiogroup">
          ${Object.entries(SM.ref.opp_kinds || {}).map(([k, l]) => html`<label class="choice-opt"><input type="radio" name="opp_kind" value="${k}" ${val('opp_kind', 'pro') === k ? 'checked' : ''}><span><b>${icon(KIND_ICON[k] || 'briefcase')}${l}</b></span></label>`)}</div></div>`}
        <label class="field"><span>${ach ? 'Achievement title' : 'Opportunity title'}</span><input class="input" name="title" maxlength="120" required value="${val('title', '')}" placeholder="${ach ? 'e.g. Launch film for a cosmetics brand' : 'e.g. Video editor for a 30-second teaser'}"></label>
        <label class="field"><span>Description</span><textarea class="input" name="body" rows="5" maxlength="4000" required placeholder="${ach ? 'What was made, for whom, how.' : 'The context, what you need, what success looks like.'}">${val('body', '')}</textarea></label>
        <div class="field-row"><label class="field"><span>${ach ? 'Project category' : 'Category'}</span><select class="input" name="category"><option value="">Choose…</option>${opt(cats, val('category', ''))}</select></label></div>
        <div class="field"><span>${ach ? 'Tools and skills used' : 'Required skills'}</span>${SM.chips.render('skills', val('skills', []), { suggest: SM.ref.skills, max: 12, placeholder: 'Add a skill and press Enter' })}</div>
        ${ach ? html`<label class="field"><span>Outcome <em>optional</em></span><textarea class="input" name="outcome" rows="2" maxlength="1500" placeholder="Results, reach, what the client said…">${val('outcome', '')}</textarea></label>
            <label class="check">${raw(`<input type="checkbox" name="show_team" ${val('show_team', true) ? 'checked' : ''}>`)}Show the people who worked on this card (assignee and collaborators with an account)</label>`
          : html`<label class="field"><span>Expected deliverables <em>optional</em></span><textarea class="input" name="deliverables" rows="2" maxlength="2000" placeholder="e.g. 1 vertical video (30 s), subtitles, 2 revisions">${val('deliverables', '')}</textarea></label>
            <div class="field-row"><label class="field"><span>Budget from <em>optional</em></span><input class="input" type="number" min="0" max="10000000" step="1" name="budget_min" value="${val('budget_min', '')}"></label>
              <label class="field"><span>to</span><input class="input" type="number" min="0" max="10000000" step="1" name="budget_max" value="${val('budget_max', '')}"></label>
              <label class="field"><span>Currency</span><select class="input" name="currency">${opt(SM.ref.currencies || ['USD'], val('currency', 'USD'))}</select></label></div>
            <div class="field-row"><label class="field"><span>Work deadline <em>optional</em></span><input class="input" type="date" name="deadline" value="${val('deadline', '')}"></label>
              <label class="field"><span>Proposals until <em>optional</em></span><input class="input" type="date" name="apply_until" value="${val('apply_until', '')}"></label></div>
            <div class="field-row"><label class="field"><span>Collaboration</span><select class="input" name="mode">${opt(Object.keys(SM.ref.modes), val('mode', 'remote'), SM.ref.modes)}</select></label>
              <label class="field"><span>Location <em>if on-site</em></span><input class="input" name="location" maxlength="80" value="${val('location', '')}" placeholder="City, country"></label></div>`}
        ${filesField()}
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><span class="grow"></span><button class="btn btn-primary">${icon('eye')}Preview</button></footer>
      </form>`);
    const t = SM.$('#pub-dialog input[name=title]');
    if (t && !d.pub) t.focus();
  }
  /** Valeurs du formulaire pour l'API (nombres, listes). */
  function collect(data) {
    const v = Object.assign({}, data);
    v.files = (data.files || []).map(Number);
    v.cover = +data.cover || 0;
    if (pubDraft.kind === 'achievement') v.files = v.files.filter((id) => id !== v.cover);
    ['budget_min', 'budget_max'].forEach((k) => { if (k in v) v[k] = v[k] === '' ? 0 : +v[k]; });
    return v;
  }
  SM.forms['pub-preview'] = (form, data) => {
    const v = collect(data);
    if (!v.title.trim()) throw new SM.UserError('Give it a title.');
    if (pubDraft.kind === 'opportunity' && v.body.trim().length < 30) throw new SM.UserError('Describe what you need in a few sentences (30 characters at least).');
    if (pubDraft.kind === 'achievement' && v.body.trim().length < 10) throw new SM.UserError('Describe the work in a sentence or two.');
    if (v.budget_max && v.budget_min > v.budget_max) throw new SM.UserError('The maximum budget must be higher than the minimum.');
    pubDraft.values = v;
    drawPreview();
  };
  function drawPreview() {
    const d = pubDraft;
    const v = d.values;
    const ach = d.kind === 'achievement';
    const files = (d.card ? d.card.files : []);
    const byId = (id) => files.find((f) => f.id === id);
    const cover = v.cover ? byId(v.cover) : null;
    const shown = v.files.map(byId).filter(Boolean);
    const p = Object.assign({}, v, { author: SM.me, created: Date.now() / 1000, opp_label: (SM.ref.opp_kinds || {})[v.opp_kind], status: ach ? 'published' : 'open' });
    SM.set(SM.$('#pub-dialog'), html`${SM.modalHead('Preview', 'This is exactly what people will see. Check that nothing private is in it.')}
      <div class="modal-body stack">
        <div class="pub-preview">${ach ? achHead(p, cover) : oppHead(p)}
          ${v.body ? html`<p class="pre">${v.body}</p>` : ''}
          ${!ach && v.deliverables ? html`<div><h3 class="h-s">Deliverables</h3><p class="pre">${v.deliverables}</p></div>` : ''}
          ${ach && v.outcome ? html`<div><h3 class="h-s">Outcome</h3><p class="pre">${v.outcome}</p></div>` : ''}
          ${shown.length ? html`<div><h3 class="h-s">${ach ? 'Assets' : 'Attachments'}</h3><div class="pub-files">${shown.map((f) => html`<span class="chip">${fileIcon(f)}${f.name}</span>`)}</div></div>` : ''}
          ${ach && v.show_team ? html`<p class="muted small">${icon('users')}The people who worked on the card are shown.</p>` : ''}
        </div>
        <p class="notice notice-warn">${icon('globe')}<span>Publishing makes this page public${ach ? ' in the Community and on your profile' : ' in the Community'}. You can ${ach ? 'unpublish' : 'close'} it at any time; the card is never moved or changed.</span></p>
        <p class="form-error" data-error hidden id="pub-error"></p>
        <footer class="modal-foot"><button class="btn btn-ghost" data-act="pub-back">${icon('arrow-left')}Back to edit</button><span class="grow"></span>
          <button class="btn btn-primary" data-act="pub-confirm">${icon('send')}${d.pub ? 'Save changes' : 'Publish'}</button></footer>
      </div>`);
  }
  SM.actions['pub-back'] = () => drawForm();
  SM.actions['pub-confirm'] = async (el) => {
    const d = pubDraft;
    const ach = d.kind === 'achievement';
    if (!d.pub && !(await SM.confirm({ title: ach ? 'Publish this achievement?' : 'Publish this opportunity?', text: `It becomes visible to ${ach ? 'the Studioma community and on your profile' : 'everyone, even without an account'}. Only the content of the preview is published.`, ok: 'Publish' }))) return;
    const body = Object.assign({}, d.values, d.pub ? { id: d.pub.id } : { card: d.card.id, kind: d.kind });
    if (d.pub && !d.card) { delete body.files; delete body.cover; }
    SM.busy(el, true);
    try {
      const r = await SM.post('pub_save', body);
      d.modal.close();
      SM.toast(d.pub ? 'Changes saved' : ach ? 'Published as an achievement' : 'Opportunity published');
      if (SM.cardPanel.id && SM.cardPanel.refresh) SM.cardPanel.refresh();
      if (SM.board.data && SM.board.reload) SM.board.reload();
      if (d.pub) SM.navigate(); else SM.go('/pub/' + r.pub.id);
    } catch (e) {
      SM.busy(el, false);
      const box = SM.$('#pub-error');
      if (box) { box.hidden = false; box.textContent = e.message; } else SM.toast(e.message, 'error');
    }
  };

  /* ---------------------------------------------------- vues partagées */
  const byline = (p) => (p.author ? html`<a class="post-by" href="#/u/${p.author.handle}">${SM.avatar(p.author, 'av-m')}<span><b>${p.author.name}</b><small class="muted">${p.author.title || '@' + p.author.handle} · ${fmt.ago(p.created)}</small></span></a>` : '');
  function achHead(p, coverFile) {
    const cov = coverFile ? coverFile.token : p.cover && p.cover.kind === 'image' ? p.cover.token : '';
    return html`${cov ? html`<img class="pub-hero" src="${SM.fileUrl(cov)}" alt="">` : ''}
      <div class="pub-kicker">${icon('award')}Achievement${p.category ? html` · <span>${p.category}</span>` : ''}</div>
      <h1 class="pub-title">${p.title}</h1>${byline(p)}
      ${(p.skills || []).length ? html`<div class="chips-list">${p.skills.map((s) => html`<span class="chip chip-soft">${s}</span>`)}</div>` : ''}`;
  }
  function oppFacts(p) {
    const b = budget(p);
    return html`<ul class="pub-facts">
      ${b ? html`<li>${icon('target')}<span><small>Budget</small><b>${b}</b></span></li>` : html`<li>${icon('target')}<span><small>Budget</small><b>To discuss</b></span></li>`}
      ${p.deadline ? html`<li>${icon('calendar')}<span><small>Work deadline</small><b>${day(p.deadline)}</b></span></li>` : ''}
      ${p.apply_until ? html`<li>${icon('clock')}<span><small>Proposals until</small><b>${day(p.apply_until)}</b></span></li>` : ''}
      <li>${icon(p.mode === 'remote' ? 'globe' : 'pin')}<span><small>Collaboration</small><b>${SM.ref.modes[p.mode] || 'Remote'}${p.location ? ' · ' + p.location : ''}</b></span></li>
      ${p.category ? html`<li>${icon('tag')}<span><small>Category</small><b>${p.category}</b></span></li>` : ''}
    </ul>`;
  }
  function oppHead(p) {
    return html`<div class="pub-kicker">${icon(KIND_ICON[p.opp_kind] || 'briefcase')}${p.opp_label || 'Opportunity'}
        <span class="chip ${p.status === 'open' ? 'chip-on' : ''}">${p.status === 'open' ? 'Open' : 'Closed'}</span></div>
      <h1 class="pub-title">${p.title}</h1>${byline(p)}${oppFacts(p)}
      ${(p.skills || []).length ? html`<div><h2 class="h-s">Required skills</h2><div class="chips-list">${p.skills.map((s) => html`<span class="chip">${s}</span>`)}</div></div>` : ''}`;
  }
  /** Carte d'une réalisation dans une grille. */
  SM.achCard = (p) => html`<a class="panel ach-card" href="#/pub/${p.id}">
      <div class="ach-cover">${p.cover && p.cover.kind === 'image' ? html`<img src="${SM.fileUrl(p.cover.token, true)}" alt="" loading="lazy">` : icon('award')}</div>
      <div class="ach-body"><b class="clamp-2">${p.title}</b>
        <small class="muted">${p.author ? p.author.name : ''}${p.category ? ' · ' + p.category : ''}</small>
        <footer class="row muted small">${p.status !== 'published' ? html`<span class="chip">Unpublished</span>` : ''}<span>${icon('heart')}${p.likes || 0}</span><span>${icon('comment')}${p.comments || 0}</span></footer></div></a>`;
  /** Ligne d'une opportunité (carte publiée). */
  SM.pubOppCard = (p) => html`<a class="panel opp pub-opp-card${p.status === 'open' ? '' : ' is-closed'}" href="#/pub/${p.id}">
      <header class="row">${SM.avatar(p.author, 'av-m')}<div class="grow"><h3>${p.title}</h2><small class="muted">${p.author ? p.author.name : ''} · ${fmt.ago(p.created)}</small></div>
        ${p.status === 'open' ? (p.my_proposal ? html`<span class="chip chip-ok">${icon('check')}${STATUS[p.my_proposal]}</span>` : '') : html`<span class="chip">Closed</span>`}</header>
      <p class="muted small clamp-2">${p.body}</p>
      <footer class="chips-list"><span class="chip chip-type">${icon(KIND_ICON[p.opp_kind] || 'briefcase')}${p.opp_label}</span>${budget(p) ? html`<span class="chip">${budget(p)}</span>` : ''}
        <span class="chip">${icon(p.mode === 'remote' ? 'globe' : 'pin')}${SM.ref.modes[p.mode]}</span>${(p.skills || []).slice(0, 3).map((s) => html`<span class="chip chip-soft">${s}</span>`)}
        ${p.manage ? html`<span class="chip chip-ok">${fmt.plural(p.proposals_count, 'proposal')}</span>` : ''}</footer></a>`;

  /* ---------------------------------------------------------- communauté */
  const CF = { tab: 'achievements', q: '', category: '', kind: '', mode: '', budget: false, status: 'open' };
  const TABS = [['achievements', 'Achievements', 'award'], ['opportunities', 'Opportunities', 'briefcase'], ['mine', 'My publications', 'sparkles'], ['proposals', 'My proposals', 'send']];
  SM.route(/^\/community(?:\/(achievements|opportunities|mine|proposals))?$/, async (m, ctx) => {
    CF.tab = m[1] || 'achievements';
    if ((CF.tab === 'mine' || CF.tab === 'proposals') && needLogin()) return;
    const tabs = TABS.filter(([k]) => SM.me || (k !== 'mine' && k !== 'proposals'));
    const catOpts = html`<option value="">All categories</option>${(SM.ref.pub_categories || []).map((c) => html`<option ${CF.category === c ? 'selected' : ''}>${c}</option>`)}`;
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Community</h1><p class="muted">Finished work from Studioma members, and projects looking for professionals, proposals or help. Everything here is published from a Studio card.</p></div></header>
      <nav class="tabs" role="tablist">${tabs.map(([k, l, ic]) => html`<a class="tab${CF.tab === k ? ' is-active' : ''}" href="#/community/${k}" role="tab" aria-selected="${CF.tab === k}">${icon(ic)}${l}</a>`)}</nav>
      ${CF.tab === 'achievements' || CF.tab === 'opportunities' ? html`<div class="filters">
        <label class="search-in grow">${icon('search')}<input class="input" placeholder="${CF.tab === 'achievements' ? 'Title, skill, member…' : 'Title, skill, deliverable…'}" value="${CF.q}" data-input="cm-q" aria-label="Search"></label>
        <select class="input" data-change="cm-filter" data-name="category" aria-label="Category">${catOpts}</select>
        ${CF.tab === 'opportunities' ? html`<select class="input" data-change="cm-filter" data-name="kind" aria-label="Looking for"><option value="">Any request</option>${Object.entries(SM.ref.opp_kinds || {}).map(([k, l]) => html`<option value="${k}" ${CF.kind === k ? 'selected' : ''}>${l}</option>`)}</select>
          <select class="input" data-change="cm-filter" data-name="mode" aria-label="Collaboration"><option value="">Remote or on-site</option>${Object.entries(SM.ref.modes).map(([k, l]) => html`<option value="${k}" ${CF.mode === k ? 'selected' : ''}>${l}</option>`)}</select>
          <select class="input" data-change="cm-filter" data-name="status" aria-label="Status"><option value="open" ${CF.status === 'open' ? 'selected' : ''}>Open</option><option value="closed" ${CF.status === 'closed' ? 'selected' : ''}>Closed</option><option value="all" ${CF.status === 'all' ? 'selected' : ''}>Open and closed</option></select>
          <label class="check">${raw(`<input type="checkbox" data-change="cm-budget" ${CF.budget ? 'checked' : ''}>`)}With a budget</label>` : ''}</div>` : ''}
      <div id="cm-list">${SM.loading(4)}</div></div>`);
    let next = 0;
    const load = async (more) => {
      const r = await SM.get('community', { tab: CF.tab, q: CF.q, category: CF.category, kind: CF.kind, mode: CF.mode, budget: CF.budget ? 1 : '', status: CF.status, before: more ? next : '' });
      if (!ctx.alive()) return;
      next = r.next || 0;
      const box = SM.$('#cm-list');
      const moreBtn = r.more ? html`<div class="row center cm-more-row"><button class="btn btn-soft" data-act="cm-more">Load more</button></div>` : '';
      let body;
      if (CF.tab === 'proposals') {
        body = r.items.length ? html`<div class="stack">${r.items.map((x) => html`<a class="panel pad list-row" href="#/pub/${x.pub.id}">${icon('send', 'list-ico')}<span class="grow"><b>${x.pub.title}</b>
            <small class="muted">${x.pub.author ? x.pub.author.name : ''} · sent ${fmt.ago(x.proposal.created)}${x.proposal.price ? ' · ' + money(x.proposal.price, x.proposal.currency) : ''}${x.pub.status !== 'open' ? ' · opportunity closed' : ''}</small></span>
            <span class="chip prop-${x.proposal.status}">${STATUS[x.proposal.status]}</span></a>`)}</div>`
          : SM.empty('send', 'No proposal yet', 'Proposals you send to opportunities are listed here with their status.', html`<a class="btn btn-primary" href="#/community/opportunities">Browse opportunities</a>`);
      } else if (CF.tab === 'mine') {
        body = r.items.length ? html`<div class="grid grid-2">${r.items.map((x) => (x.pub.kind === 'achievement' ? SM.achCard(x.pub) : SM.pubOppCard(x.pub)))}</div>`
          : SM.empty('sparkles', 'Nothing published yet', 'Open a card in one of your Studios and use “Share & publish”: finished cards become achievements, cards in progress become opportunities.', html`<a class="btn btn-primary" href="#/studios">Open my Studios</a>`);
      } else if (CF.tab === 'achievements') {
        body = r.items.length ? html`<div class="ach-grid">${r.items.map((x) => SM.achCard(x.pub))}</div>`
          : SM.empty('award', CF.q || CF.category ? 'No achievement matches' : 'No achievement yet', 'Members publish their finished cards here: films, campaigns, designs, launches.');
      } else {
        const cards = r.items.map((x) => SM.pubOppCard(x.pub));
        body = html`${cards.length ? html`<div class="grid grid-2">${cards}</div>` : SM.empty('briefcase', 'No opportunity matches', 'Members publish their cards in progress here when they need a professional, proposals, collaboration or help.')}
          ${r.jobs && r.jobs.length ? html`<section class="stack"><h2 class="section-title">${icon('building')}Jobs from companies</h2><div class="grid grid-2">${r.jobs.map((o) => SM.oppCard(o))}</div>
            <p class="muted small"><a href="#/opportunities">See all company opportunities</a></p></section>` : ''}`;
      }
      if (more) {
        // Page suivante : ses éléments rejoignent la grille déjà affichée.
        const tmp = document.createElement('div');
        SM.set(tmp, body);
        const grid = SM.$('.ach-grid, .grid', box);
        const add = SM.$('.ach-grid, .grid', tmp);
        if (grid && add) grid.append(...add.children);
        SM.$$('.cm-more-row', box).forEach((x) => x.remove());
        if (r.more) { const b = document.createElement('div'); SM.set(b, moreBtn); box.append(...b.children); }
        return;
      }
      SM.set(box, html`${body}${moreBtn}`);
    };
    SM.inputs['cm-q'] = SM.debounce((el) => { CF.q = el.value.trim(); load(); }, 250);
    SM.changes['cm-filter'] = (el) => { CF[el.dataset.name] = el.value; load(); };
    SM.changes['cm-budget'] = (el) => { CF.budget = el.checked; load(); };
    SM.actions['cm-more'] = () => load(true);
    await load();
  }, { public: true });

  /* ------------------------------------------------ page d'une publication */
  let cur = null;   // dernière réponse de pub_get
  SM.route(/^\/pub\/(\d+)$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow">${SM.loading(4)}</div>`);
    const r = await SM.get('pub_get', { id: m[1] });
    if (!ctx.alive()) return;
    cur = r.pub;
    draw(ctx.view);
  }, { public: true });
  function manageBar(p) {
    if (!p.manage) return '';
    const ach = p.kind === 'achievement';
    return html`<div class="pub-manage panel pad">
      <span class="muted small grow">${icon('shield')}You manage this publication${p.source.card_id ? '' : p.source.available ? '' : ' — its card is no longer available'}.</span>
      ${p.source.card_id ? html`<a class="btn btn-ghost btn-sm" href="#/studio/${p.source.studio_id}/card/${p.source.card_id}">${icon('board')}Open the card</a>` : ''}
      <button class="btn btn-soft btn-sm" data-act="pub-edit">${icon('edit')}Edit</button>
      ${ach ? html`<button class="btn btn-soft btn-sm" data-act="pub-toggle" data-status="${p.status === 'published' ? 'hidden' : 'published'}">${icon(p.status === 'published' ? 'eye' : 'globe')}${p.status === 'published' ? 'Unpublish' : 'Publish again'}</button>`
        : html`<button class="btn btn-soft btn-sm" data-act="pub-toggle" data-status="${p.status === 'open' ? 'closed' : 'open'}">${icon(p.status === 'open' ? 'lock' : 'globe')}${p.status === 'open' ? 'Close' : 'Reopen'}</button>`}
      <button class="btn btn-ghost btn-sm is-danger" data-act="pub-delete">${icon('trash')}Delete</button></div>`;
  }
  const mediaList = (media) => (media.length ? html`<div class="pub-media">${media.map((f) => (f.kind === 'image'
    ? html`<button class="media-img" data-act="lightbox" data-token="${f.token}" data-name="${f.name}"><img src="${SM.fileUrl(f.token, true)}" alt="${f.name}" loading="lazy"></button>`
    : f.kind === 'video' ? html`<video src="${SM.fileUrl(f.token)}" controls preload="metadata"></video>`
      : html`<a class="chip" href="${SM.fileUrl(f.token, false, true)}">${icon('download')}${f.name}</a>`))}</div>` : '');
  function draw(view) {
    const p = cur;
    const ach = p.kind === 'achievement';
    document.title = p.title + ' · Studioma';
    if (ach) {
      SM.set(view, html`<div class="page page-narrow">
        <a class="back" href="#/community/achievements">${icon('arrow-left')}Achievements</a>
        ${manageBar(p)}
        <article class="panel post pub-full" data-post="${p.post_id}">
          ${p.status !== 'published' ? html`<p class="notice notice-warn">${icon('eye')}<span>Unpublished: only you and the Studio managers can see this page.</span></p>` : ''}
          ${achHead(p)}
          ${p.body ? html`<p class="pre">${SM.linkify(p.body)}</p>` : ''}
          ${p.outcome ? html`<div><h2 class="h-s">Outcome</h2><p class="pre">${SM.linkify(p.outcome)}</p></div>` : ''}
          ${mediaList(p.media.slice(p.cover && p.cover.kind === 'image' ? 1 : 0))}
          ${p.team.length ? html`<div><h2 class="h-s">Made by</h2><div class="row row-wrap">${p.team.map((t) => html`<a class="row" href="#/u/${t.handle}">${SM.avatar(t, 'av-s')}<span>${t.name}</span></a>`)}</div></div>` : ''}
          ${p.post_id ? html`<footer class="post-actions">
            <button class="post-act${p.liked ? ' is-on' : ''}" data-act="post-like" data-id="${p.post_id}" aria-pressed="${p.liked}">${icon('heart')}<span>${p.likes || ''}</span><em>Like</em></button>
            <button class="post-act" data-act="post-comments" data-id="${p.post_id}">${icon('comment')}<span>${p.comments || ''}</span><em>Comment</em></button>
            <button class="post-act" data-act="post-share" data-id="${p.post_id}">${icon('share')}<em>Share</em></button>
            <button class="post-act" data-act="copy" data-text="${location.origin + location.pathname}#/pub/${p.id}">${icon('link')}<em>Copy link</em></button>
            <span class="grow"></span>
            <button class="post-act${p.saved ? ' is-on' : ''}" data-act="post-save" data-id="${p.post_id}" aria-pressed="${p.saved}" title="Save">${icon('bookmark')}</button></footer>
          <div class="post-comments" hidden></div>` : ''}
        </article></div>`);
      return;
    }
    const closed = p.status !== 'open';
    SM.set(view, html`<div class="page page-narrow">
      <a class="back" href="#/community/opportunities">${icon('arrow-left')}Opportunities</a>
      ${manageBar(p)}
      <article class="panel pad pub-full stack">
        ${oppHead(p)}
        ${closed ? html`<p class="notice">${icon('lock')}<span>This opportunity is closed. ${p.closed_reason}</span></p>` : ''}
        ${p.body ? html`<div><h2 class="h-s">Description</h2><p class="pre">${SM.linkify(p.body)}</p></div>` : ''}
        ${p.deliverables ? html`<div><h2 class="h-s">Expected deliverables</h2><p class="pre">${SM.linkify(p.deliverables)}</p></div>` : ''}
        ${p.media.length ? html`<div><h2 class="h-s">Attachments</h2>${mediaList(p.media)}</div>` : ''}
        <footer class="row row-wrap muted small"><span>${icon('send')}${fmt.plural(p.proposals_count, 'proposal')}</span><span class="grow"></span>
          ${p.url ? html`<button class="btn btn-ghost btn-sm" data-act="copy" data-text="${p.url}">${icon('link')}Copy public link</button>
            <button class="btn btn-soft btn-sm" data-act="pub-share">${icon('share')}Share</button>` : ''}</footer>
      </article>
      ${p.manage ? proposalsSection(p) : applyBox(p)}
    </div>`);
    // Formulaire de proposition affiché : les travaux du profil qu'on peut y joindre.
    if (SM.$('#prop-work')) loadWork(p.proposal ? p.proposal.work : []);
  }
  /** Partager une opportunité : sa page publique (rendue par le serveur, aperçu des liens). Rien n'est publié automatiquement. */
  SM.actions['pub-share'] = () => {
    const p = cur;
    const text = `Opportunity on Studioma: ${p.title}`;
    SM.modal(html`${SM.modalHead('Share this opportunity', p.title)}
      <div class="modal-body stack">
        <div class="ref-link"><input class="input" value="${p.url}" readonly aria-label="Public link of the opportunity"><button class="btn btn-primary" data-act="copy" data-text="${p.url}">${icon('copy')}Copy link</button></div>
        ${SM.shareButtons ? SM.shareButtons(p.url, text, p.title + ' — Studioma', 0) : ''}
        <p class="muted small">${icon('globe')}Anyone with the link can read the opportunity. Only signed-in professionals can send a proposal. Its Studio and card stay private.</p>
      </div>`);
  };
  SM.actions['pub-edit'] = async () => {
    const p = cur;
    let card = null;
    if (p.source.card_id) {
      const r = await SM.try(SM.get('card_get', { id: p.source.card_id }));
      if (r) card = r.card;
    }
    publishDialog(card, p.kind, p);
  };
  SM.actions['pub-toggle'] = async (el) => {
    const status = el.dataset.status;
    const t = { hidden: ['Unpublish this achievement?', 'It disappears from the Community, the feed and your profile. Likes and comments are kept if you publish it again.', 'Unpublish'],
      closed: ['Close this opportunity?', 'It stops taking proposals. The proposals you received are kept.', 'Close'] }[status];
    if (t && !(await SM.confirm({ title: t[0], text: t[1], ok: t[2] }))) return;
    const r = await SM.try(SM.post('pub_status', { id: cur.id, status }));
    if (r) { SM.toast({ hidden: 'Unpublished', published: 'Published again', closed: 'Opportunity closed', open: 'Opportunity reopened' }[status]); cur = r.pub; draw(SM.$('#view')); }
  };
  SM.actions['pub-delete'] = async () => {
    if (!(await SM.confirm({ title: 'Delete this publication?', text: cur.kind === 'achievement' ? 'Its likes, comments and public file copies are deleted. The card is not affected.' : 'The proposals it received are deleted too. Close it instead to keep them. The card is not affected.', ok: 'Delete', danger: true }))) return;
    if (await SM.try(SM.post('pub_delete', { id: cur.id }))) { SM.toast('Publication deleted'); SM.go('/community/mine'); }
  };

  /* ---------------------------------------------------------- propositions */
  const workList = (work) => (work.length ? html`<div class="row row-wrap">${work.map((w) => html`<a class="chip" href="${w.t === 'case' ? '#/u/' + w.handle : '#/pub/' + w.id}">${icon(w.t === 'case' ? 'award' : 'sparkles')}${w.title}</a>`)}</div>` : '');
  function applyBox(p) {
    if (!SM.me) return html`<section class="panel pad stack"><h2 class="section-title">${icon('send')}Send a proposal</h2>${p.status === 'open' ? html`<p class="muted">Professionals with a Studioma account send their approach, price and timeline.</p><div><a class="btn btn-primary" href="#/signup" data-act="login-first">Join Studioma to send a proposal</a></div>` : html`<p class="muted">This opportunity no longer takes proposals.</p>`}</section>`;
    const x = p.proposal;
    if (x && x.status !== 'withdrawn') {
      const editable = p.status === 'open' && (x.status === 'new' || x.status === 'shortlisted');
      return html`<section class="panel pad stack"><h2 class="section-title">${icon('send')}Your proposal <span class="chip prop-${x.status}">${STATUS[x.status]}</span></h2>
        <p class="muted small">${x.price ? money(x.price, x.currency) : 'Price to discuss'}${x.timeline ? ' · ' + x.timeline : ''} · sent ${fmt.ago(x.created)}</p>
        <p class="pre">${x.message}</p>${workList(x.work)}
        ${x.status === 'accepted' ? html`<p class="notice notice-ok">${icon('check')}<span>Accepted. ${p.author ? p.author.name : 'The author'} will contact you — you can also <a href="#/messages/${p.author ? p.author.handle : ''}">send a message</a>.</span></p>` : ''}
        <div class="row">${editable ? html`<button class="btn btn-soft btn-sm" data-act="prop-edit">${icon('edit')}Edit</button>` : ''}<button class="btn btn-ghost btn-sm" data-act="prop-withdraw" data-id="${x.id}">Withdraw</button></div>
        <div id="prop-form"></div></section>`;
    }
    if (p.status !== 'open') return '';
    return html`<section class="panel pad stack"><h2 class="section-title">${icon('send')}Send a proposal</h2><div id="prop-form">${proposalForm(p, x)}</div></section>`;
  }
  function proposalForm(p, x) {
    return html`<form class="stack" data-form="prop-save">
      <label class="field"><span>Your approach</span><textarea class="input" name="message" rows="5" maxlength="3000" required placeholder="How you would do it, relevant experience, questions you have…">${x ? x.message : ''}</textarea></label>
      <div class="field-row"><label class="field"><span>Your price (${p.currency}) <em>0 = to discuss</em></span><input class="input" type="number" min="0" max="10000000" name="price" value="${x && x.price ? x.price : ''}"></label>
        <label class="field"><span>Delivery timeline</span><input class="input" name="timeline" maxlength="80" value="${x ? x.timeline : ''}" placeholder="e.g. 5 days after the brief"></label></div>
      <div class="field"><span>Relevant work from your portfolio <em>optional</em></span><div id="prop-work" class="stack-s">${SM.spinner()}</div></div>
      <p class="muted small">${icon('shield')}Only ${p.author ? p.author.name : 'the author'} and the Studio managers see your proposal. Your profile is linked.</p>
      <p class="form-error" data-error hidden></p>
      <div><button class="btn btn-primary">${icon('send')}${x ? 'Update my proposal' : 'Send my proposal'}</button></div></form>`;
  }
  /** Travaux du profil à joindre : études de cas publiées et réalisations. */
  async function loadWork(selected) {
    const box = SM.$('#prop-work');
    if (!box || !SM.me) return;
    const r = await SM.try(SM.get('profile_get', { handle: SM.me.handle }));
    if (!r || !document.contains(box)) return;
    const sel = new Set((selected || []).map((w) => w.t + ':' + w.id));
    const items = r.portfolio.filter((c) => c.status === 'published').map((c) => ({ t: 'case', id: c.id, title: c.title }))
      .concat((r.achievements || []).map((a) => ({ t: 'achievement', id: a.id, title: a.title })));
    SM.set(box, items.length ? html`${items.map((w) => html`<label class="check">${raw(`<input type="checkbox" name="work[]" value="${w.t}:${w.id}" ${sel.has(w.t + ':' + w.id) ? 'checked' : ''}>`)}${icon(w.t === 'case' ? 'award' : 'sparkles')}${w.title}</label>`)}`
      : html`<p class="muted small">No published case study or achievement yet. <a href="#/profile">Add one to your profile</a>.</p>`);
  }
  SM.actions['prop-edit'] = () => { SM.set(SM.$('#prop-form'), proposalForm(cur, cur.proposal)); loadWork(cur.proposal.work); };
  SM.forms['prop-save'] = async (form, data) => {
    const work = (data.work || []).map((v) => { const [t, id] = v.split(':'); return { t, id: +id }; });
    await SM.post('proposal_save', { pub: cur.id, message: data.message, price: data.price === '' ? 0 : +data.price, timeline: data.timeline, work });
    SM.toast('Proposal sent — you\'ll be notified when it\'s answered');
    SM.navigate();
  };
  SM.actions['prop-withdraw'] = async (el) => {
    if (!(await SM.confirm({ title: 'Withdraw your proposal?', text: 'The author is told. You can send a new one while the opportunity is open.', ok: 'Withdraw', danger: true }))) return;
    if (await SM.try(SM.post('proposal_withdraw', { id: +el.dataset.id }))) { SM.toast('Proposal withdrawn'); SM.navigate(); }
  };
  function proposalsSection(p) {
    const list = p.proposals || [];
    return html`<section class="stack"><h2 class="section-title">${icon('inbox')}Proposals <span class="count">${list.length}</span></h2>
      ${list.length ? list.map((x) => html`<article class="panel pad applic prop-row${x.status === 'withdrawn' ? ' is-off' : ''}">
          <header class="row row-wrap"><a class="row grow" href="#/u/${x.user ? x.user.handle : ''}">${SM.avatar(x.user, 'av-m')}<span><b>${x.user ? x.user.name : 'Former member'}</b><small class="muted">${x.user ? x.user.title || '@' + x.user.handle : ''} · ${fmt.ago(x.created)}</small></span></a>
            ${x.status === 'withdrawn' ? html`<span class="chip">Withdrawn</span>` : html`<select class="input input-sm" data-change="prop-status" data-id="${x.id}" aria-label="Status of the proposal of ${x.user ? x.user.name : ''}">${['new', 'shortlisted', 'accepted', 'declined'].map((k) => html`<option value="${k}" ${x.status === k ? 'selected' : ''}>${STATUS[k]}</option>`)}</select>`}</header>
          <p class="muted small">${x.price ? money(x.price, x.currency) : 'Price to discuss'}${x.timeline ? ' · ' + x.timeline : ''}</p>
          <p class="pre">${x.message}</p>${workList(x.work)}
          ${x.user ? html`<footer class="row row-wrap"><a class="btn btn-soft btn-sm" href="#/u/${x.user.handle}">View profile</a><a class="btn btn-soft btn-sm" href="#/messages/${x.user.handle}">${icon('message')}Message</a>
            ${x.status === 'accepted' && p.source.available ? html`<button class="btn btn-primary btn-sm" data-act="prop-invite" data-id="${x.id}" data-name="${x.user.name}">${icon('user-plus')}Invite to collaborate</button>` : ''}</footer>` : ''}
        </article>`) : html`<p class="muted">No proposal yet. Share the link of this page to reach more professionals.</p>`}
      <p class="muted small">${icon('shield')}Accepting a proposal never gives access to your card. Invite the person explicitly when you're ready.</p></section>`;
  }
  SM.changes['prop-status'] = async (el) => {
    const r = await SM.try(SM.post('proposal_decide', { id: +el.dataset.id, status: el.value }));
    if (r) { SM.toast(el.value === 'new' ? 'Status updated' : 'Status updated — the professional is notified'); cur = r.pub; draw(SM.$('#view')); }
  };
  SM.actions['prop-invite'] = (el) => {
    const id = +el.dataset.id;
    const name = el.dataset.name;
    const go = async (how, role, what) => {
      if (!(await SM.confirm({ title: `Invite ${name}?`, text: what, ok: 'Invite' }))) return;
      const r = await SM.try(SM.post('pub_invite', { proposal: id, how, role }));
      if (r) SM.toast(how === 'card' ? `${name} received a private link to this card` : `${name} was invited to the Studio`);
    };
    SM.menu(el, [{ head: 'Give access' },
      { label: 'This card only — Editor', hint: 'Private link; they verify their email', icon: 'edit', act: () => go('card', 'editor', `${name} gets a private link to this card only, as Editor. They never see the rest of the Studio. You can revoke it at any time from the card's sharing.`) },
      { label: 'This card only — Commenter', hint: 'Comment and approve, no edits', icon: 'comment', act: () => go('card', 'commenter', `${name} gets a private link to this card only, as Commenter.`) },
      { label: 'Invite to the Studio team', hint: 'Member invitation (Collaborator)', icon: 'users', act: () => go('studio', 'collaborator', `${name} receives an invitation to join the Studio as Collaborator, with access to its cards. It uses a seat of your plan.`) },
    ], { width: 300 });
  };
})();
