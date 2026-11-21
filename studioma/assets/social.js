/*
 * Studioma — le réseau professionnel : fil d'actualité, publications, profils publics, portfolio
 * (études de cas et preuves de travail), recommandations, relations et découverte.
 */
(function () {
  'use strict';
  const { html, raw, icon, fmt } = SM;

  /* ---------------------------------------------------------- publications */
  const mediaGrid = (media) => (media.length ? html`<div class="media media-${Math.min(media.length, 4)}">${media.slice(0, 4).map((f, i) => (f.kind === 'video'
    ? html`<video src="${SM.fileUrl(f.token)}" controls preload="metadata"></video>`
    : html`<button class="media-img" data-act="lightbox" data-token="${f.token}" data-name="${f.name}"><img src="${SM.fileUrl(f.token, media.length > 1)}" alt="" loading="lazy">${i === 3 && media.length > 4 ? html`<span class="media-more">+${media.length - 4}</span>` : ''}</button>`))}</div>` : '');
  const byline = (p) => {
    const c = p.company;
    const a = p.author || { name: 'Former member', handle: '' };
    return html`<a class="post-by" href="#/${c ? 'c/' + c.slug : 'u/' + a.handle}">${c ? SM.logoBox(c, 'av-m') : SM.avatar(a, 'av-m')}<span><b>${c ? c.name : a.name}</b><small class="muted">${c ? (c.tagline || 'Company') : (a.title || '@' + a.handle)} · ${fmt.ago(p.created)}</small></span></a>`;
  };
  /** Réalisation publiée depuis une carte (lib/pubs.php) : un aperçu qui mène à sa page. */
  const pubBlock = (x) => (x ? html`<a class="post-pub" href="#/pub/${x.id}">${x.cover && x.cover.kind === 'image' ? html`<img src="${SM.fileUrl(x.cover.token)}" alt="" loading="lazy">` : ''}
      <span class="post-pub-body"><span class="pub-kicker">${icon('award')}Achievement${x.category ? ' · ' + x.category : ''}</span><b>${x.title}</b>${x.body ? html`<span class="clamp-2 muted small">${x.body}</span>` : ''}
      ${x.skills && x.skills.length ? html`<span class="chips-list">${x.skills.map((k) => html`<span class="chip chip-soft">${k}</span>`)}</span>` : ''}</span></a>` : '');
  SM.postView = (p) => html`<article class="panel post" data-post="${p.id}">
      <header>${byline(p)}<button class="btn-icon" data-act="post-menu" data-id="${p.id}" data-mine="${p.mine ? 1 : 0}" aria-label="Post options">${icon('more')}</button></header>
      ${p.body ? html`<p class="post-body">${SM.linkify(p.body)}</p>` : ''}
      ${mediaGrid(p.media)}
      ${pubBlock(p.pub)}
      ${p.share_id ? (p.shared ? html`<div class="post-shared">${byline(p.shared)}${p.shared.body ? html`<p class="post-body clamp-4">${SM.linkify(p.shared.body)}</p>` : ''}${mediaGrid(p.shared.media)}${pubBlock(p.shared.pub)}</div>` : html`<div class="post-shared muted">This post is no longer available.</div>`) : ''}
      <footer class="post-actions">
        <button class="post-act${p.liked ? ' is-on' : ''}" data-act="post-like" data-id="${p.id}" aria-pressed="${p.liked}">${icon('heart')}<span>${p.likes || ''}</span><em>Like</em></button>
        <button class="post-act" data-act="post-comments" data-id="${p.id}">${icon('comment')}<span>${p.comments || ''}</span><em>Comment</em></button>
        <button class="post-act" data-act="post-share" data-id="${p.share_id || p.id}">${icon('share')}<em>Share</em></button>
        <span class="grow"></span>
        <button class="post-act${p.saved ? ' is-on' : ''}" data-act="post-save" data-id="${p.id}" aria-pressed="${p.saved}" title="Save">${icon('bookmark')}</button>
      </footer>
      <div class="post-comments" hidden></div>
    </article>`;
  const needLogin = () => { if (SM.me) return false; sessionStorage.setItem('stm.next', SM.path()); SM.go('/signup'); return true; };
  SM.actions['post-like'] = async (el) => {
    if (needLogin()) return;
    const on = !el.classList.contains('is-on');
    el.classList.toggle('is-on', on);
    const r = await SM.try(SM.post('post_like', { id: +el.dataset.id, on }));
    if (r) el.querySelector('span').textContent = r.likes || '';
  };
  SM.actions['post-save'] = async (el) => {
    if (needLogin()) return;
    const on = !el.classList.contains('is-on');
    el.classList.toggle('is-on', on);
    if (await SM.try(SM.post('post_save', { id: +el.dataset.id, on }))) SM.toast(on ? 'Saved to your collection' : 'Removed from saved');
  };
  const drawComments = (box, id, list) => SM.set(box, html`
    ${list.map((c) => html`<div class="comment">${SM.avatar(c.user, 'av-s')}<div class="comment-body"><header><a href="#/u/${c.user ? c.user.handle : ''}"><b>${c.user ? c.user.name : 'Former member'}</b></a><small class="muted">${fmt.ago(c.created)}</small>
      ${c.mine ? html`<button class="btn-icon btn-xs" data-act="pcomment-delete" data-id="${c.id}" data-post="${id}" aria-label="Delete comment">${icon('trash')}</button>` : ''}</header><p>${SM.linkify(c.body)}</p></div></div>`)}
    ${SM.me ? html`<form class="comment-form" data-form="post-comment" data-id="${id}">${SM.avatar(SM.me, 'av-s')}<input class="input input-sm grow" name="body" maxlength="1500" placeholder="Add a comment…" aria-label="Comment" autocomplete="off"><button class="btn-icon" aria-label="Send">${icon('send')}</button></form>`
      : html`<p class="muted small"><a href="#/signup">Join Studioma</a> to comment.</p>`}`);
  SM.actions['post-comments'] = async (el) => {
    const box = el.closest('.post').querySelector('.post-comments');
    if (!box.hidden) { box.hidden = true; return; }
    box.hidden = false;
    SM.set(box, SM.spinner());
    const r = await SM.try(SM.get('post_comments', { id: el.dataset.id }));
    if (r) { drawComments(box, +el.dataset.id, r.comments); const i = box.querySelector('input'); if (i) i.focus(); }
  };
  SM.forms['post-comment'] = async (form, data) => {
    if (!data.body.trim()) return;
    const r = await SM.post('post_comment', { id: +form.dataset.id, body: data.body });
    const post = form.closest('.post');
    post.querySelector('[data-act="post-comments"] span').textContent = r.comments.length;
    drawComments(post.querySelector('.post-comments'), +form.dataset.id, r.comments);
    post.querySelector('.post-comments input').focus();
  };
  SM.actions['pcomment-delete'] = async (el) => {
    const box = el.closest('.post-comments');
    if (!await SM.try(SM.post('post_comment_delete', { id: +el.dataset.id }))) return;
    const r = await SM.try(SM.get('post_comments', { id: el.dataset.post }));
    if (r) drawComments(box, +el.dataset.post, r.comments);
  };
  SM.actions['post-share'] = (el) => { if (!needLogin()) SM.composePost({ share: +el.dataset.id }); };
  SM.actions['post-menu'] = (el) => {
    const id = +el.dataset.id;
    SM.menu(el, [
      { label: 'Copy link', icon: 'link', act: () => SM.copy(location.origin + location.pathname + '#/post/' + id) },
      el.dataset.mine === '1' ? { label: 'Delete post', icon: 'trash', danger: true, act: async () => {
        if (!await SM.confirm({ title: 'Delete this post?', text: 'Its comments and likes are deleted too.', ok: 'Delete', danger: true })) return;
        if (await SM.try(SM.post('post_delete', { id }))) { el.closest('.post').remove(); SM.toast('Post deleted'); }
      } } : null,
    ].filter(Boolean), { align: 'right' });
  };

  /** Fenêtre de rédaction d'une publication. opts.share : id de la publication partagée. */
  let draft = null;
  SM.composePost = (opts) => {
    opts = opts || {};
    draft = { media: [], share: opts.share || 0 };
    const companies = SM.adminCompanies();
    SM.modal(html`${SM.modalHead(opts.share ? 'Share this post' : 'New post')}
      <form class="modal-body stack" data-form="post-create">
        <div class="row">${SM.avatar(SM.me, 'av-m')}${companies.length ? html`<select class="input input-sm" name="company_id" aria-label="Post as"><option value="0">Post as ${SM.me.name}</option>${companies.map((c) => html`<option value="${c.id}">Post as ${c.name}</option>`)}</select>` : html`<b>${SM.me.name}</b>`}</div>
        <textarea class="input compose" name="body" rows="5" maxlength="3000" placeholder="${opts.share ? 'Add your thoughts…' : 'Share a result, a lesson learned, a behind-the-scenes…'}" autofocus></textarea>
        <div class="thumbs" id="compose-media"></div>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot between">${opts.share ? html`<span></span>` : html`<label class="btn btn-soft">${icon('image')}Photo / video<input type="file" accept="image/*,video/mp4,video/webm,video/quicktime" multiple hidden data-change="compose-upload"></label>`}
          <button class="btn btn-primary">${icon('send')}Post</button></footer>
      </form>`);
  };
  const drawDraft = () => SM.set(SM.$('#compose-media'), html`${draft.media.map((f, i) => html`<span class="thumb">${f.uploading ? html`<span class="thumb-wait">${SM.spinner()}</span>` : f.kind === 'video' ? html`<span class="thumb-wait">${icon('video')}</span>` : html`<img src="${SM.fileUrl(f.token, true)}" alt="">`}<button type="button" class="thumb-x" data-act="compose-remove" data-i="${i}" aria-label="Remove">${icon('x')}</button></span>`)}`);
  /** Envoi de médias dans un brouillon (publication, étude de cas). */
  SM.mediaUpload = async (kind, files, list, redraw, max) => {
    for (const file of files) {
      if (list.length >= max) { SM.toast(`You can add up to ${max} files.`, 'error'); break; }
      const slot = { uploading: true, name: file.name };
      list.push(slot);
      redraw();
      try { Object.assign(slot, await SM.upload(kind, file), { uploading: false }); } catch (e) { list.splice(list.indexOf(slot), 1); SM.toast(e.message, 'error'); }
      redraw();
    }
  };
  SM.changes['compose-upload'] = (el) => { const files = Array.from(el.files); el.value = ''; SM.mediaUpload('post', files, draft.media, drawDraft, 6); };
  SM.actions['compose-remove'] = (el) => { draft.media.splice(+el.dataset.i, 1); drawDraft(); };
  SM.forms['post-create'] = async (form, data) => {
    if (draft.media.some((f) => f.uploading)) throw new SM.UserError('Wait for the upload to finish.');
    const r = await SM.post('post_create', { body: data.body, media: draft.media.map((f) => f.token), company_id: +data.company_id || 0, share_id: draft.share });
    SM.closeModals();
    SM.toast('Posted');
    const feed = SM.$('#feed');
    if (feed && SM.$('.empty', feed)) SM.navigate();
    else if (feed) feed.insertAdjacentHTML('afterbegin', String(SM.postView(r.post)));
    else SM.go('/');
  };

  /** Liste de publications avec « Afficher plus ». */
  async function feed(el, params, ctx, emptyState) {
    let last = 0;
    const load = async () => {
      const r = await SM.get('feed', Object.assign({ before: last }, params));
      if (!ctx.alive()) return;
      const more = SM.$('.feed-more', el);
      if (more) more.remove();
      if (!last) SM.set(el, r.posts.length ? '' : emptyState);
      el.insertAdjacentHTML('beforeend', r.posts.map((p) => String(SM.postView(p))).join('') + (r.more ? String(html`<button class="btn btn-soft btn-block feed-more" data-act="feed-more">Show more</button>`) : ''));
      if (r.posts.length) last = r.posts[r.posts.length - 1].id;
    };
    SM.actions['feed-more'] = (btn) => { SM.busy(btn, true); load(); };
    await load();
  }
  SM.feed = feed;

  /* ------------------------------------------------------------- personnes */
  const AVAIL = { open: 'Available for work', limited: 'Limited availability', off: 'Not available' };
  SM.availChip = (a) => html`<span class="avail avail-${a}"><i></i>${AVAIL[a] || ''}</span>`;
  /** Carte d'une personne. actions : contenu du pied de carte. */
  SM.personCard = (u, actions) => html`<article class="panel person">
      <a class="person-main" href="#/u/${u.handle}">${SM.avatar(u, 'av-l')}<b>${u.name}</b><span class="muted small clamp-2">${u.title || '@' + u.handle}</span>
        ${u.location ? html`<span class="muted small">${icon('pin')}${u.location}</span>` : ''}</a>
      ${u.skills && u.skills.length ? html`<div class="person-skills">${u.skills.slice(0, 3).map((s) => html`<span class="chip">${s}</span>`)}</div>` : ''}
      ${actions ? html`<footer>${actions}</footer>` : ''}
    </article>`;
  const followBtn = (type, id, on, sm) => html`<button class="btn ${on ? 'btn-soft' : 'btn-primary'} ${sm ? 'btn-sm' : ''}" data-act="follow" data-type="${type}" data-id="${id}" data-on="${on ? 1 : 0}">${icon(on ? 'check' : 'plus')}${on ? 'Following' : 'Follow'}</button>`;
  SM.followBtn = followBtn;
  SM.actions.follow = async (el) => {
    if (needLogin()) return;
    const on = el.dataset.on !== '1';
    if (!await SM.try(SM.post('follow', { type: el.dataset.type, id: +el.dataset.id, on }))) return;
    el.outerHTML = String(followBtn(el.dataset.type, el.dataset.id, on, el.classList.contains('btn-sm')));
  };
  SM.actions.connect = async (el) => {
    if (needLogin()) return;
    const r = await SM.try(SM.post(el.dataset.off ? 'disconnect' : 'connect', { uid: +el.dataset.id }));
    if (r) { SM.toast({ out: 'Request sent', connected: 'You are now connected', none: 'Done' }[r.connection] || 'Done'); SM.navigate(); }
  };

  /* ------------------------------------------------------------------- fil */
  SM.route(/^\/$/, async (m, ctx) => {
    const tab = SM.query().get('tab') === 'saved' ? 'saved' : 'home';
    SM.set(ctx.view, html`<div class="page with-rail feed-page">
      <div class="feed-col">
        <div class="panel composer" data-act="compose" role="button" tabindex="0">${SM.avatar(SM.me, 'av-m')}<span class="composer-in">Share a result, a lesson learned, a behind-the-scenes…</span><span class="btn btn-soft btn-sm">${icon('image')}Media</span></div>
        <div class="tabs"><a class="tab${tab === 'home' ? ' is-active' : ''}" href="#/">${icon('home')}For you</a><a class="tab${tab === 'saved' ? ' is-active' : ''}" href="#/?tab=saved">${icon('bookmark')}Saved</a></div>
        <div id="feed" class="feed">${SM.loading(3)}</div>
      </div>
      <aside class="rail">
        <section class="panel me-card"><a href="#/profile">${SM.avatar(SM.me, 'av-l')}<b>${SM.me.name}</b><span class="muted small">${SM.me.title || '@' + SM.me.handle}</span></a>
          ${SM.me.onboarded ? '' : html`<a class="btn btn-soft btn-sm btn-block" href="#/profile?edit=1">${icon('sparkles')}Complete your profile</a>`}</section>
        <section class="panel pad" id="rail-people"></section>
        <section class="panel pad" id="rail-opps"></section>
      </aside>
    </div>`);
    const empty = tab === 'saved'
      ? SM.empty('bookmark', 'Nothing saved yet', 'Tap the bookmark on a post to keep it here.')
      : SM.empty('compass', 'Your feed is ready to fill', 'Follow professionals and companies, or share your first update.', html`<div class="row"><a class="btn btn-primary" href="#/discover">${icon('compass')}Discover people</a><button class="btn btn-soft" data-act="compose">Write a post</button></div>`);
    feed(SM.$('#feed'), { tab }, ctx, empty);
    SM.get('network').then((r) => {
      if (!ctx.alive()) return;
      SM.set(SM.$('#rail-people'), html`<h3>${icon('users')}People to discover</h3>${r.suggestions.length ? html`<div class="list">${r.suggestions.slice(0, 5).map((u) => html`<div class="list-row"><a class="row grow" href="#/u/${u.handle}">${SM.avatar(u, 'av-m')}<span class="grow"><b>${u.name}</b><small class="muted clamp-1">${u.title || '@' + u.handle}</small></span></a>${followBtn('user', u.id, false, true)}</div>`)}</div><a class="small" href="#/network">See more</a>` : html`<p class="muted small">Invite your collaborators: they will appear here.</p>`}`);
    }).catch(() => {});
    SM.get('opps').then((r) => {
      if (!ctx.alive()) return;
      SM.set(SM.$('#rail-opps'), html`<h3>${icon('briefcase')}Opportunities</h3>${r.opps.length ? html`<div class="list">${r.opps.slice(0, 4).map((o) => html`<a class="list-row" href="#/opportunity/${o.id}">${SM.logoBox(o.company, 'av-m')}<span class="grow"><b class="clamp-1">${o.title}</b><small class="muted">${o.company ? o.company.name : ''} · ${SM.ref.modes[o.mode]}</small></span></a>`)}</div><a class="small" href="#/opportunities">See all</a>` : html`<p class="muted small">No open opportunity right now.</p>`}`);
    }).catch(() => {});
  });
  SM.actions.compose = () => SM.composePost();
  SM.route(/^\/post\/(\d+)$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow"><div id="feed" class="feed">${SM.loading(1)}</div></div>`);
    const r = await SM.get('feed', { tab: 'post', id: m[1] });
    if (ctx.alive()) SM.set(SM.$('#feed'), html`${r.posts.map(SM.postView)}`);
  }, { public: true });

  /* ----------------------------------------------------------------- profil */
  SM.route(/^\/profile$/, () => SM.go('/u/' + SM.me.handle + (SM.path().includes('?') ? '?' + SM.path().split('?')[1] : ''), true));
  const VERIFY = {
    studio: ['badge', 'Studio-verified', 'Produced and published through a Studio on Studioma: the publication links are on record.'],
    client: ['shield', 'Client-confirmed', 'The client confirmed these results.'],
    self: ['user', 'Self-reported', 'Declared by the professional, not verified.'],
  };
  const vbadge = (k, extra) => html`<span class="vbadge v-${k}" title="${VERIFY[k][2]}">${icon(VERIFY[k][0])}${VERIFY[k][1]}${extra || ''}</span>`;
  SM.caseBadges = (p) => html`${p.verified ? vbadge('studio') : ''}${p.confirmed_by ? vbadge('client', html` by ${p.confirmed_by.name}`) : ''}${!p.verified && !p.confirmed_by ? vbadge('self') : ''}`;
  const STATUS = { draft: 'Draft — only you can see it', pending: 'Awaiting authorization', declined: 'Publication declined' };
  const caseCard = (p) => html`<article class="panel case${p.status !== 'published' ? ' is-draft' : ''}" data-act="case-open" data-id="${p.id}" tabindex="0" role="button">
      ${p.media.length && p.media[0].kind === 'image' ? html`<img class="case-cover" src="${SM.fileUrl(p.media[0].token, true)}" alt="" loading="lazy">` : html`<div class="case-cover case-blank">${icon('award')}</div>`}
      <div class="case-body">${p.status !== 'published' ? html`<span class="chip chip-warn">${STATUS[p.status]}</span>` : ''}
        <h3>${p.title}</h3><p class="muted small">${[p.brand, p.role].filter(Boolean).join(' · ')}</p>
        <div class="vbadges">${SM.caseBadges(p)}</div>
        ${p.metrics.length ? html`<div class="metrics">${p.metrics.slice(0, 3).map((x) => html`<span class="metric"><b>${x.value}</b>${x.label}</span>`)}</div>` : ''}</div>
    </article>`;
  let prof = null;
  SM.route(/^\/u\/([a-z0-9][a-z0-9._-]{1,30})$/i, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page">${SM.loading(4)}</div>`);
    const r = prof = await SM.get('profile_get', { handle: m[1].toLowerCase() });
    if (!ctx.alive()) return;
    const p = r.profile;
    const self = r.rel.self;
    const proof = r.proof;
    const conn = r.rel.connection;
    const links = Object.entries(p.links);
    const actions = self
      ? html`<button class="btn btn-soft" data-act="profile-edit">${icon('edit')}Edit profile</button><button class="btn btn-primary" data-act="case-new">${icon('plus')}Add case study</button>`
      : html`${conn === 'connected' ? html`<button class="btn btn-soft" data-act="connect" data-off="1" data-id="${p.id}" title="Remove connection">${icon('check')}Connected</button>`
          : conn === 'out' ? html`<button class="btn btn-soft" data-act="connect" data-off="1" data-id="${p.id}" title="Cancel request">${icon('clock')}Request sent</button>`
          : html`<button class="btn btn-primary" data-act="connect" data-id="${p.id}">${icon('user-plus')}${conn === 'in' ? 'Accept request' : 'Connect'}</button>`}
        ${followBtn('user', p.id, r.rel.following)}
        <a class="btn btn-soft" href="#/messages/${p.handle}" ${SM.me ? '' : raw('data-act="login-first"')}>${icon('message')}Message</a>
        ${SM.me ? html`<button class="btn-icon" data-act="profile-more" aria-label="More actions">${icon('more')}</button>` : ''}`;
    const section = (title, ic, body, extra) => html`<section class="panel pad"><header class="row between"><h2>${icon(ic)}${title}</h2>${extra || ''}</header>${body}</section>`;
    const proofTile = (k, n, sub) => html`<div class="proof proof-${k}"><span class="proof-ico">${icon(VERIFY[k][0])}</span><b>${n}</b><span>${VERIFY[k][1]}</span><small class="muted">${sub}</small></div>`;
    SM.set(ctx.view, html`<div class="page profile">
      <header class="panel profile-head">
        <div class="cover"${p.cover ? raw(` style="background-image:url('${SM.fileUrl(p.cover)}')"`) : ''}></div>
        <div class="profile-id">${SM.avatar(p, 'av-xl')}
          <div class="grow"><h1>${p.name}</h1><p class="profile-title">${p.title || (self ? 'Add your professional title' : '')}</p>
            <p class="muted small profile-meta">${p.location ? html`<span>${icon('pin')}${p.location}</span>` : ''}<span>@${p.handle}</span><span><b>${p.connections}</b> connections</span><span><b>${p.followers}</b> followers</span>${p.pro ? SM.availChip(p.availability) : ''}</p></div>
          <div class="profile-actions">${actions}</div></div>
      </header>
      <div class="with-rail">
        <div class="stack-l">
          ${p.bio || self ? section('About', 'user', p.bio ? html`<p class="pre">${SM.linkify(p.bio)}</p>` : html`<p class="muted">Tell companies and collaborators who you are and what you do best. <a href="#" data-act="profile-edit">Write your bio</a></p>`) : ''}
          ${section('Proof of work', 'shield', html`
            <p class="muted small">Evidence and where it comes from — no score, no ranking.</p>
            <div class="proofs">${proofTile('studio', proof.studio, proof.pieces ? fmt.plural(proof.pieces, 'published piece') + ' on record' : 'Case studies from a Studio')}${proofTile('client', proof.client, 'Results confirmed by a client')}${proofTile('self', proof.self, 'Declared by ' + p.name.split(' ')[0])}</div>
            <p class="small proof-line">${icon('quote')}<span><b>${proof.recommendations}</b> ${proof.recommendations === 1 ? 'recommendation' : 'recommendations'}${proof.client_recommendations ? html`, <b>${proof.client_recommendations}</b> from clients` : ''}${proof.worked_together ? html` · <b>${proof.worked_together}</b> from people who worked with ${p.name.split(' ')[0]} on Studioma` : ''}</span></p>
            ${proof.activity ? html`<p class="small proof-line">${icon('zap')}<span><b>${proof.activity.delivered}</b> pieces delivered through Studios across <b>${proof.activity.projects}</b> ${proof.activity.projects === 1 ? 'project' : 'projects'}</span></p>` : ''}`)}
          ${section('Portfolio', 'award', r.portfolio.length ? html`<div class="grid grid-2 cases">${r.portfolio.map(caseCard)}</div>`
            : SM.empty('award', self ? 'Showcase your best work' : 'No case study yet', self ? 'Add a case study: the campaign, your role, the objectives and the results. Work done in a Studio can be turned into a verified case study.' : '', self ? html`<button class="btn btn-primary" data-act="case-new">${icon('plus')}Add case study</button>` : ''),
            self && r.portfolio.length ? html`<button class="btn btn-soft btn-sm" data-act="case-new">${icon('plus')}Add</button>` : '')}
          ${(r.achievements || []).length || self ? section('Achievements', 'sparkles', (r.achievements || []).length ? html`<div class="ach-grid ach-grid-s">${r.achievements.map((a) => SM.achCard(a))}</div>`
            : html`<p class="muted small">Finished cards you publish from your Studios (“Share & publish” › Publish achievement) appear here and in the <a href="#/community">Community</a>.</p>`) : ''}
          ${section('Recommendations', 'quote', r.recommendations.length ? html`<div class="recs">${r.recommendations.map((x) => html`<blockquote class="rec${x.status !== 'visible' ? ' is-pending' : ''}">
              <p>${x.body}</p><footer><a class="row" href="#/u/${x.from ? x.from.handle : ''}">${SM.avatar(x.from, 'av-m')}<span><b>${x.from ? x.from.name : 'Former member'}</b><small class="muted">${SM.ref.relations[x.relation]}${x.from && x.from.title ? ' · ' + x.from.title : ''}</small></span></a>
                ${x.together ? html`<span class="vbadge v-studio" title="They shared a project on Studioma">${icon('badge')}Worked together</span>` : ''}<span class="grow"></span>
                ${self ? html`${x.status !== 'visible' ? html`<span class="chip chip-warn">${x.status === 'pending' ? 'New — not shown yet' : 'Hidden'}</span><button class="btn btn-primary btn-sm" data-act="rec-set" data-id="${x.id}" data-status="visible">Show on my profile</button>` : html`<button class="btn btn-ghost btn-sm" data-act="rec-set" data-id="${x.id}" data-status="hidden">Hide</button>`}<button class="btn-icon" data-act="rec-set" data-id="${x.id}" data-status="delete" aria-label="Delete recommendation">${icon('trash')}</button>` : ''}</footer></blockquote>`)}</div>`
            : html`<p class="muted">${self ? 'Recommendations from clients and teammates will appear here once you approve them.' : 'No recommendation yet.'}</p>`,
            !self && SM.me ? html`<button class="btn btn-soft btn-sm" data-act="rec-write">${icon('edit')}Recommend</button>` : '')}
          <section><h2 class="section-title">${icon('edit')}Posts</h2><div id="feed" class="feed">${SM.loading(1)}</div></section>
        </div>
        <aside class="rail">
          ${p.skills.length || self ? html`<section class="panel pad"><h3>Skills</h3>${p.skills.length ? html`<div class="chips-list">${p.skills.map((s) => html`<span class="chip">${s}</span>`)}</div>` : html`<p class="muted small"><a href="#" data-act="profile-edit">Add your skills</a></p>`}</section>` : ''}
          ${p.platforms.length ? html`<section class="panel pad"><h3>Social media expertise</h3><div class="chips-list">${p.platforms.map((k) => html`<span class="chip chip-pf pf-${k}">${SM.ref.platforms[k]}</span>`)}</div></section>` : ''}
          ${p.specialization || p.industries.length || p.languages.length || p.experience ? html`<section class="panel pad facts">
            ${p.specialization ? html`<p><small class="muted">Specialization</small>${p.specialization}</p>` : ''}
            ${p.experience ? html`<p><small class="muted">Experience</small>${fmt.plural(p.experience, 'year')}</p>` : ''}
            ${p.industries.length ? html`<p><small class="muted">Industries</small>${p.industries.join(', ')}</p>` : ''}
            ${p.languages.length ? html`<p><small class="muted">Languages</small>${p.languages.join(', ')}</p>` : ''}</section>` : ''}
          ${p.website || p.contact || links.length ? html`<section class="panel pad"><h3>Contact</h3><div class="links">
            ${p.website ? html`<a href="${p.website}" target="_blank" rel="noopener noreferrer">${icon('globe')}${p.website.replace(/^https?:\/\//, '')}</a>` : ''}
            ${p.contact ? html`<a href="mailto:${p.contact}">${icon('mail')}${p.contact}</a>` : ''}
            ${links.map(([k, v]) => html`<a href="${v}" target="_blank" rel="noopener noreferrer">${icon('external')}${k[0].toUpperCase() + k.slice(1)}</a>`)}</div></section>` : ''}
          ${r.companies.length ? html`<section class="panel pad"><h3>Works with</h3><div class="list">${r.companies.map((c) => html`<a class="list-row" href="#/c/${c.slug}">${SM.logoBox(c, 'av-m')}<span class="grow"><b>${c.name}</b><small class="muted">${c.member_title || c.tagline}</small></span></a>`)}</div></section>` : ''}
        </aside>
      </div>
    </div>`);
    feed(SM.$('#feed'), { tab: 'user', id: p.id }, ctx, html`<p class="muted">${self ? 'You have not posted yet.' : 'No post yet.'}</p>`);
    const q = SM.query();
    if (self && q.get('edit')) SM.profileDialog();
    if (self && q.get('case')) SM.portfolioDialog(r.portfolio.find((x) => x.id === +q.get('case')));
  }, { public: true });
  SM.actions['login-first'] = () => needLogin();
  SM.actions['profile-more'] = (el) => {
    const p = prof.profile;
    SM.menu(el, [
      { label: 'Invite to collaborate', hint: 'Add them to one of your projects', icon: 'user-plus', act: () => SM.collabDialog(p) },
      { label: prof.rel.saved ? 'Remove from saved talent' : 'Save this professional', icon: 'bookmark', act: async () => { if (await SM.try(SM.post('talent_save', { uid: p.id, on: !prof.rel.saved }))) { prof.rel.saved = !prof.rel.saved; SM.toast(prof.rel.saved ? 'Saved to your talent list' : 'Removed'); } } },
      { label: 'Write a recommendation', icon: 'quote', act: () => SM.actions['rec-write']() },
      { label: 'Copy profile link', icon: 'link', act: () => SM.copy(location.href) },
    ], { align: 'right', width: 270, cls: 'menu-rich' });
  };

  /* ------------------------------------------------------ édition du profil */
  let pdraft = null;
  const imgField = (key, label, token, cls) => html`<div class="img-field ${cls}"><span class="img-prev" id="img-${key}">${token ? html`<img src="${SM.fileUrl(token, key !== 'cover')}" alt="">` : icon('image')}</span>
      <label class="btn btn-soft btn-sm">${icon('upload')}${label}<input type="file" accept="image/*" hidden data-change="img-upload" data-key="${key}"></label></div>`;
  SM.imgField = imgField;
  /** Envoi d'une image de profil, de couverture ou de logo dans le brouillon en cours (SM.imgDraft). */
  SM.changes['img-upload'] = async (el) => {
    const file = el.files[0];
    el.value = '';
    if (!file) return;
    const key = el.dataset.key;
    const box = SM.$('#img-' + key);
    SM.set(box, SM.spinner());
    try {
      const f = await SM.upload(key === 'avatar' ? 'avatar' : key, file);
      SM.imgDraft[key] = f.token;
      SM.set(box, html`<img src="${SM.fileUrl(f.token, key !== 'cover')}" alt="">`);
    } catch (e) { SM.set(box, icon('image')); SM.toast(e.message, 'error'); }
  };
  SM.profileDialog = () => {
    const p = prof.profile;
    SM.imgDraft = pdraft = { avatar: p.avatar, cover: p.cover };
    SM.modal(html`${SM.modalHead('Edit profile')}
      <form class="modal-body stack" data-form="profile-save">
        <div class="row">${imgField('avatar', 'Photo', p.avatar, 'img-round')}${imgField('cover', 'Cover image', p.cover, 'img-wide')}</div>
        <div class="field-row"><label class="field"><span>Name</span><input class="input" name="name" maxlength="60" required value="${p.name}"></label>
          <label class="field"><span>Username</span><input class="input" name="handle" maxlength="30" required value="${p.handle}" pattern="[a-zA-Z0-9][a-zA-Z0-9._\\-]{2,29}"></label></div>
        <label class="field"><span>Professional title</span><input class="input" name="title" maxlength="100" value="${p.title}" placeholder="e.g. Freelance community manager"></label>
        <div class="field-row"><label class="field"><span>Specialization</span><input class="input" name="specialization" maxlength="80" value="${p.specialization}" placeholder="e.g. Short video for fashion brands"></label>
          <label class="field"><span>Location</span><input class="input" name="location" maxlength="80" value="${p.location}" placeholder="City, country"></label></div>
        <label class="field"><span>About</span><textarea class="input" name="bio" rows="4" maxlength="1500" placeholder="Who you are, what you do best, how you like to work…">${p.bio}</textarea></label>
        <div class="field"><span>Skills</span>${SM.chips.render('skills', p.skills, { suggest: SM.ref.skills, placeholder: 'Add a skill and press Enter' })}</div>
        <div class="field"><span>Social media expertise</span>${SM.toggles('platforms', SM.ref.platforms, p.platforms)}</div>
        <div class="field-row"><div class="field"><span>Industries</span>${SM.chips.render('industries', p.industries, { suggest: SM.ref.industries, max: 8, placeholder: 'Add an industry' })}</div>
          <div class="field"><span>Languages</span>${SM.chips.render('languages', p.languages, { suggest: SM.ref.languages, max: 8, len: 30, placeholder: 'Add a language' })}</div></div>
        <div class="field-row"><label class="field"><span>Years of experience</span><input class="input" type="number" name="experience" min="0" max="60" value="${p.experience || ''}"></label>
          <label class="field"><span>Availability</span><select class="input" name="availability">${Object.entries(SM.ref.availability).map(([k, l]) => html`<option value="${k}" ${p.availability === k ? 'selected' : ''}>${l}</option>`)}</select></label></div>
        <div class="field-row"><label class="field"><span>Website</span><input class="input" name="website" maxlength="300" value="${p.website}" placeholder="https://"></label>
          <label class="field"><span>Public contact email <em>optional</em></span><input class="input" type="email" name="contact" maxlength="190" value="${p.contact}"></label></div>
        <fieldset class="fieldset"><legend>Your social profiles</legend><div class="grid grid-2">${['instagram', 'tiktok', 'linkedin', 'youtube', 'facebook', 'x', 'behance'].map((k) => html`<label class="field"><span>${k[0].toUpperCase() + k.slice(1)}</span><input class="input input-sm" name="link_${k}" maxlength="200" value="${p.links[k] || ''}" placeholder="https://"></label>`)}</div></fieldset>
        <label class="check"><input type="checkbox" name="pro" ${p.pro ? 'checked' : ''}><span><b>List me in the talent directory</b><br><small class="muted">Companies can find you by skills, industry, language and location.</small></span></label>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">Save profile</button></footer>
      </form>`, { size: 'l' });
  };
  SM.actions['profile-edit'] = () => SM.profileDialog();
  SM.forms['profile-save'] = async (form, data) => {
    const links = {};
    Object.keys(data).filter((k) => k.startsWith('link_')).forEach((k) => { links[k.slice(5)] = data[k]; delete data[k]; });
    const r = await SM.post('profile_save', Object.assign(data, { links, avatar: pdraft.avatar, cover: pdraft.cover, experience: +data.experience || 0 }));
    Object.assign(SM.me, { name: data.name, handle: r.handle, title: data.title, avatar: pdraft.avatar, onboarded: true });
    SM.closeModals();
    SM.shell();
    SM.toast('Profile saved');
    SM.go('/u/' + r.handle, true);
  };

  /* -------------------------------------------------------------- portfolio */
  let cdraft = null;
  const drawCaseMedia = () => SM.set(SM.$('#case-media'), html`${cdraft.media.map((f, i) => html`<span class="thumb">${f.uploading ? html`<span class="thumb-wait">${SM.spinner()}</span>` : f.kind === 'video' ? html`<span class="thumb-wait">${icon('video')}</span>` : html`<img src="${SM.fileUrl(f.token, true)}" alt="">`}<button type="button" class="thumb-x" data-act="case-media-x" data-i="${i}" aria-label="Remove">${icon('x')}</button></span>`)}
    <label class="thumb thumb-add" title="Add images or videos">${icon('plus')}<input type="file" accept="image/*,video/mp4,video/webm,video/quicktime" multiple hidden data-change="case-upload"></label>`);
  const metricRow = (m) => html`<div class="metric-row"><input class="input input-sm" name="m_value[]" maxlength="30" placeholder="e.g. +38%" value="${m ? m.value : ''}" aria-label="Value"><input class="input input-sm grow" name="m_label[]" maxlength="40" placeholder="e.g. Engagement rate" value="${m ? m.label : ''}" aria-label="Metric"></div>`;
  /** Fenêtre d'une étude de cas (nouvelle, ou brouillon / étude existante). */
  SM.portfolioDialog = (p) => {
    cdraft = { id: p ? p.id : 0, media: p ? p.media.slice() : [] };
    const studio = p && p.source === 'studio';
    const need = p ? [p.need_owner && !p.owner_ok ? 'the project owner' : '', p.need_client && !p.client_ok ? 'the client' : ''].filter(Boolean) : [];
    SM.modal(html`${SM.modalHead(p ? 'Edit case study' : 'New case study', 'A campaign, your role, the objectives and what came out of it.')}
      <form class="modal-body stack" data-form="case-save">
        ${studio ? html`<div class="notice">${icon('badge')}<span><b>Studio-verified work.</b> ${need.length ? html`Publishing needs the authorization of ${need.join(' and ')}: they will be notified when you publish, and it goes live once they agree.` : 'All required authorizations are given.'} Share only what the project allows.</span></div>` : ''}
        <label class="field"><span>Title</span><input class="input" name="title" maxlength="120" required value="${p ? p.title : ''}" placeholder="e.g. Launching Vélora on TikTok" autofocus></label>
        <div class="field-row"><label class="field"><span>Brand / client</span><input class="input" name="brand" maxlength="80" value="${p ? p.brand : ''}"></label>
          <label class="field"><span>Your role and responsibilities</span><input class="input" name="role" maxlength="120" value="${p ? p.role : ''}" placeholder="e.g. Strategy, scripts and community"></label>
          <label class="field field-s"><span>Period</span><input class="input" name="period" maxlength="40" value="${p ? p.period : ''}" placeholder="e.g. Spring 2026"></label></div>
        <label class="field"><span>Campaign objectives</span><textarea class="input" name="objectives" rows="2" maxlength="1500">${p ? p.objectives : ''}</textarea></label>
        <label class="field"><span>What you did</span><textarea class="input" name="body" rows="4" maxlength="4000">${p ? p.body : ''}</textarea></label>
        <label class="field"><span>Results and achievements</span><textarea class="input" name="results" rows="2" maxlength="1500">${p ? p.results : ''}</textarea></label>
        <div class="field"><span>Key figures <em>shown as self-reported until a client confirms them</em></span><div id="case-metrics">${(p && p.metrics.length ? p.metrics : [null]).map(metricRow)}</div>
          <div><button type="button" class="btn btn-ghost btn-sm" data-act="metric-add">${icon('plus')}Add a figure</button></div></div>
        <div class="field"><span>Platforms</span>${SM.toggles('platforms', SM.ref.platforms, p ? p.platforms : [])}</div>
        <label class="field"><span>Links to the published content <em>one per line</em></span><textarea class="input" name="links" rows="2" placeholder="https://">${p ? p.links.join('\n') : ''}</textarea></label>
        <div class="field"><span>Images and videos</span><div class="thumbs" id="case-media"></div></div>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot">${p ? html`<button type="button" class="btn btn-ghost is-danger" data-act="case-delete" data-id="${p.id}">Delete</button><span class="grow"></span>` : ''}
          <button class="btn btn-soft" data-publish="0">Save as draft</button><button class="btn btn-primary" data-publish="1">${icon('globe')}Publish on my profile</button></footer>
      </form>`, { size: 'l' });
    drawCaseMedia();
  };
  SM.actions['case-new'] = () => SM.portfolioDialog();
  SM.actions['metric-add'] = () => { if (SM.$$('#case-metrics .metric-row').length < 8) SM.$('#case-metrics').insertAdjacentHTML('beforeend', String(metricRow())); };
  SM.changes['case-upload'] = (el) => { const files = Array.from(el.files); el.value = ''; SM.mediaUpload('portfolio', files, cdraft.media, drawCaseMedia, 8); };
  SM.actions['case-media-x'] = (el) => { cdraft.media.splice(+el.dataset.i, 1); drawCaseMedia(); };
  SM.forms['case-save'] = async (form, data, e) => {
    if (cdraft.media.some((f) => f.uploading)) throw new SM.UserError('Wait for the upload to finish.');
    const publish = !!(e.submitter && e.submitter.dataset.publish === '1');
    const metrics = (data.m_label || []).map((label, i) => ({ label, value: (data.m_value || [])[i] || '' })).filter((x) => x.label.trim() && x.value.trim());
    const r = await SM.post('portfolio_save', { id: cdraft.id, title: data.title, brand: data.brand, role: data.role, period: data.period, objectives: data.objectives, body: data.body, results: data.results,
      metrics, platforms: data.platforms || [], links: data.links.split(/\s+/).filter(Boolean), media: cdraft.media.map((f) => f.token), publish });
    SM.closeModals();
    SM.toast({ published: 'Published on your profile', pending: 'Saved — waiting for the required authorization', draft: 'Draft saved' }[r.status]);
    SM.go('/u/' + SM.me.handle, true);
  };
  SM.actions['case-delete'] = async (el) => {
    if (!await SM.confirm({ title: 'Delete this case study?', ok: 'Delete', danger: true })) return;
    if (await SM.try(SM.post('portfolio_delete', { id: +el.dataset.id }))) { SM.closeModals(); SM.toast('Case study deleted'); SM.navigate(); }
  };
  SM.actions['case-open'] = (el) => {
    const p = prof.portfolio.find((x) => x.id === +el.dataset.id);
    const self = prof.rel.self;
    if (self && p.status !== 'published') { SM.portfolioDialog(p); return; }
    SM.modal(html`${SM.modalHead(p.title, [p.brand, p.role, p.period].filter(Boolean).join(' · '))}
      <div class="modal-body stack case-view">
        <div class="vbadges">${SM.caseBadges(p)}</div>
        <p class="muted small">${p.verified ? VERIFY.studio[2] + ' ' : ''}${p.confirmed_by ? html`Results confirmed by <a href="#/u/${p.confirmed_by.handle}" data-act="modal-close-go">${p.confirmed_by.name}</a>.` : 'The figures below are self-reported.'}</p>
        ${p.media.length ? html`<div class="media media-${Math.min(p.media.length, 4)}">${p.media.slice(0, 4).map((f) => (f.kind === 'video' ? html`<video src="${SM.fileUrl(f.token)}" controls preload="metadata"></video>` : html`<button class="media-img" data-act="lightbox" data-token="${f.token}" data-name="${f.name}"><img src="${SM.fileUrl(f.token)}" alt="" loading="lazy"></button>`))}</div>` : ''}
        ${p.metrics.length ? html`<div class="metrics metrics-l">${p.metrics.map((x) => html`<span class="metric"><b>${x.value}</b>${x.label}</span>`)}</div>` : ''}
        ${p.objectives ? html`<div><h3>Objectives</h3><p class="pre">${p.objectives}</p></div>` : ''}
        ${p.body ? html`<div><h3>The work</h3><p class="pre">${p.body}</p></div>` : ''}
        ${p.results ? html`<div><h3>Results</h3><p class="pre">${p.results}</p></div>` : ''}
        ${p.platforms.length ? html`<div class="chips-list">${p.platforms.map((k) => html`<span class="chip chip-pf pf-${k}">${SM.ref.platforms[k]}</span>`)}</div>` : ''}
        ${p.links.length ? html`<div class="links">${p.links.map((l) => html`<a href="${l}" target="_blank" rel="noopener noreferrer">${icon('external')}${l.replace(/^https?:\/\//, '').slice(0, 60)}</a>`)}</div>` : ''}
        ${self ? html`<footer class="modal-foot">${!p.confirmed_by ? html`<button class="btn btn-soft" data-act="case-confirm-ask" data-id="${p.id}">${icon('shield')}Ask a client to confirm</button>` : ''}<span class="grow"></span><button class="btn btn-primary" data-act="case-edit" data-id="${p.id}">${icon('edit')}Edit</button></footer>` : ''}
      </div>`, { size: 'l' });
  };
  SM.actions['case-edit'] = (el) => { SM.closeModals(); SM.portfolioDialog(prof.portfolio.find((x) => x.id === +el.dataset.id)); };
  SM.actions['case-confirm-ask'] = async (el) => {
    const handle = await SM.prompt({ title: 'Ask a client to confirm', label: 'Username of the client on Studioma', placeholder: '@username', ok: 'Send request', max: 40 });
    if (handle && await SM.try(SM.post('portfolio_confirm_ask', { id: +el.dataset.id, handle }))) SM.toast('Request sent. The badge appears once they confirm.');
  };

  /* -------------------------------------------------------- recommandations */
  SM.actions['rec-write'] = () => {
    const p = prof.profile;
    SM.modal(html`${SM.modalHead('Recommend ' + p.name, 'They choose whether to show it on their profile.')}
      <form class="modal-body stack" data-form="rec-save">
        <label class="field"><span>How do you know ${p.name.split(' ')[0]}?</span><select class="input" name="relation">${Object.entries(SM.ref.relations).map(([k, l]) => html`<option value="${k}">I was their ${l.toLowerCase()}</option>`)}</select></label>
        <label class="field"><span>Your recommendation</span><textarea class="input" name="body" rows="5" maxlength="1500" required placeholder="What did you work on together? What stood out?" autofocus></textarea></label>
        <p class="form-error" data-error hidden></p>
        <footer class="modal-foot"><button type="button" class="btn btn-ghost" data-act="modal-close">Cancel</button><button class="btn btn-primary">Send recommendation</button></footer>
      </form>`);
  };
  SM.forms['rec-save'] = async (form, data) => { await SM.post('recommend', Object.assign({ uid: prof.profile.id }, data)); SM.closeModals(); SM.toast('Recommendation sent'); };
  SM.actions['rec-set'] = async (el) => {
    if (el.dataset.status === 'delete' && !await SM.confirm({ title: 'Delete this recommendation?', ok: 'Delete', danger: true })) return;
    if (await SM.try(SM.post('recommend_set', { id: +el.dataset.id, status: el.dataset.status }))) SM.navigate();
  };

  /* ----------------------------------------------------------------- réseau */
  SM.route(/^\/network$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page"><header class="page-head"><h1>My Network</h1></header>${SM.loading(3)}</div>`);
    const r = await SM.get('network');
    if (!ctx.alive()) return;
    const group = (title, list, actions, empty) => (list.length || empty ? html`<section><h2 class="section-title">${title} <span class="count">${list.length}</span></h2>${list.length ? html`<div class="grid grid-4">${list.map((u) => SM.personCard(u, actions(u)))}</div>` : html`<p class="muted">${empty}</p>`}</section>` : '');
    const msg = (u) => html`<a class="btn btn-soft btn-sm" href="#/messages/${u.handle}">${icon('message')}Message</a>`;
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>My Network</h1><p class="muted">${fmt.plural(r.connections.length, 'connection')} · ${fmt.plural(r.followers.length, 'follower')} · following ${r.following.length + r.companies.length}</p></div><a class="btn btn-primary" href="#/discover">${icon('compass')}Discover people</a></header>
      ${group('Requests', r.requests, (u) => html`<button class="btn btn-ghost btn-sm" data-act="connect" data-off="1" data-id="${u.id}">Ignore</button><button class="btn btn-primary btn-sm" data-act="connect" data-id="${u.id}">Accept</button>`)}
      ${group('Connections', r.connections, msg, 'Connect with the people you work with: clients, teammates, fellow creators.')}
      ${group('Requests you sent', r.sent, (u) => html`<button class="btn btn-ghost btn-sm" data-act="connect" data-off="1" data-id="${u.id}">Cancel request</button>`)}
      ${group('People you may know', r.suggestions, (u) => html`<button class="btn btn-soft btn-sm" data-act="connect" data-id="${u.id}">${icon('user-plus')}Connect</button>`)}
      ${group('Following', r.following, (u) => followBtn('user', u.id, true, true))}
      ${r.companies.length ? html`<section><h2 class="section-title">Companies you follow <span class="count">${r.companies.length}</span></h2><div class="grid grid-4">${r.companies.map((c) => SM.companyCard(c))}</div></section>` : ''}
      ${group('Followers', r.followers, msg)}
    </div>`);
  });

  /* ------------------------------------------------------------- découverte */
  SM.route(/^\/discover$/, async (m, ctx) => {
    const term = (SM.query().get('q') || '').trim();
    SM.set(ctx.view, html`<div class="page">
      <header class="page-head"><div><h1>Discover</h1><p class="muted">Professionals, companies, ideas and opportunities.</p></div></header>
      <form class="search-big" data-form="search" role="search">${icon('search')}<input class="input" name="q" value="${term}" placeholder="Search by name, skill, platform, city…" aria-label="Search" autocomplete="off">${term ? html`<a class="btn-icon" href="#/discover" aria-label="Clear search">${icon('x')}</a>` : ''}</form>
      <div id="discover">${SM.loading(3)}</div></div>`);
    const el = SM.$('#discover');
    if (term) {
      const r = await SM.get('search', { q: term });
      if (!ctx.alive()) return;
      const none = !r.people.length && !r.companies.length && !r.posts.length && !r.opps.length;
      SM.set(el, none ? SM.empty('search', `No result for “${term}”`, 'Try a skill, a platform, a city or a name.') : html`
        ${r.people.length ? html`<section><h2 class="section-title">People <span class="count">${r.people.length}</span></h2><div class="grid grid-4">${r.people.map((u) => SM.personCard(u))}</div></section>` : ''}
        ${r.companies.length ? html`<section><h2 class="section-title">Companies <span class="count">${r.companies.length}</span></h2><div class="grid grid-4">${r.companies.map((c) => SM.companyCard(c))}</div></section>` : ''}
        ${r.opps.length ? html`<section><h2 class="section-title">Opportunities <span class="count">${r.opps.length}</span></h2><div class="grid grid-2">${r.opps.map((o) => SM.oppCard(o))}</div></section>` : ''}
        ${r.posts.length ? html`<section class="page-narrow"><h2 class="section-title">Posts <span class="count">${r.posts.length}</span></h2><div class="feed">${r.posts.map(SM.postView)}</div></section>` : ''}`);
      return;
    }
    SM.set(el, html`<div class="with-rail"><div class="feed-col"><h2 class="section-title">${icon('sparkles')}Latest from the community</h2><div id="feed" class="feed">${SM.loading(2)}</div></div>
      <aside class="rail"><section class="panel pad" id="rail-people"></section><section class="panel pad" id="rail-opps"></section></aside></div>`);
    feed(SM.$('#feed'), { tab: 'discover' }, ctx, SM.empty('edit', 'No post yet', 'Be the first to share something with the community.', SM.me ? html`<button class="btn btn-primary" data-act="compose">Write a post</button>` : ''));
    if (SM.me) SM.get('network').then((r) => { if (ctx.alive()) SM.set(SM.$('#rail-people'), html`<h3>${icon('users')}People to discover</h3>${r.suggestions.length ? html`<div class="list">${r.suggestions.map((u) => html`<div class="list-row"><a class="row grow" href="#/u/${u.handle}">${SM.avatar(u, 'av-m')}<span class="grow"><b>${u.name}</b><small class="muted clamp-1">${u.title || '@' + u.handle}</small></span></a>${followBtn('user', u.id, false, true)}</div>`)}</div>` : html`<p class="muted small">You already follow everyone here.</p>`}`); }).catch(() => {});
    SM.get('opps').then((r) => { if (ctx.alive()) SM.set(SM.$('#rail-opps'), html`<h3>${icon('briefcase')}Open opportunities</h3>${r.opps.length ? html`<div class="list">${r.opps.slice(0, 5).map((o) => html`<a class="list-row" href="#/opportunity/${o.id}">${SM.logoBox(o.company, 'av-m')}<span class="grow"><b class="clamp-1">${o.title}</b><small class="muted">${o.company ? o.company.name : ''}</small></span></a>`)}</div><a class="small" href="#/opportunities">See all</a>` : html`<p class="muted small">No open opportunity right now.</p>`}`); }).catch(() => {});
  }, { public: true });
})();
