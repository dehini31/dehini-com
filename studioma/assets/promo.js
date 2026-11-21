/*
 * Studioma — offre de lancement : bannière de l'essai / de l'année offerte (accueil, projets), page « Refer & Earn »
 * (#/refer : lien personnel, partage, statistiques, progression, historique), réclamation de l'essai, et lien de
 * parrainage ouvert directement dans l'application (?ref=…). Aucune carte, aucun paiement : les règles sont
 * appliquées par le serveur (lib/promo.php).
 */
(function () {
  'use strict';
  const { html, icon, fmt } = SM;
  const day = (ts) => (ts ? new Date(ts * 1000).toLocaleDateString('en', { day: 'numeric', month: 'long', year: 'numeric' }) : '');
  const left = (n) => (n > 60 ? `${Math.round(n / 30)} months` : fmt.plural(n, 'day'));

  /* ------------------------------------------- lien de parrainage dans l'application */
  // …/app/?ref=xxxx ou …/app/#/signup?ref=xxxx : le clic est compté une fois par visite (le serveur pose l'attribution).
  (function captureRef() {
    const hashQ = new URLSearchParams((location.hash.split('?')[1] || ''));
    const code = new URLSearchParams(location.search).get('ref') || hashQ.get('ref');
    if (!code || !/^[a-z2-9]{8}$/.test(code)) return;
    try { if (sessionStorage.getItem('stm.ref') === code) return; sessionStorage.setItem('stm.ref', code); } catch (e) { /* navigation privée */ }
    fetch(SM.base + 'api.php?action=ref_click', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma' }, body: JSON.stringify({ code }) }).catch(() => {});
  })();

  /* ------------------------------------------------------------- bannière */
  /** Bannière de l'offre selon l'état du compte (SM.promo, reçu avec « me »). L'année offerte l'emporte sur l'essai. */
  SM.promoBanner = () => {
    const p = SM.promo;
    if (!p) return '';
    const r = p.reward;
    const t = p.trial;
    if (r.state === 'active') {
      return html`<section class="promo-banner is-reward" aria-label="Your free year">${icon('award')}
        <div class="grow"><b>1 Year Pro Access Unlocked</b><span>From ${day(r.starts)} to ${day(r.ends)} · ${left(r.days_left)} left · no card, no payment.</span></div>
        <a class="btn btn-soft btn-sm" href="#/refer">View my referrals</a></section>`;
    }
    if (t.state === 'active') {
      const soon = t.days_left <= 7;
      return html`<section class="promo-banner${soon ? ' is-soon' : ''}" aria-label="Your free trial">${icon(soon ? 'clock' : 'sparkles')}
        <div class="grow">${soon && r.state !== 'scheduled' ? html`<b>Your free trial ends soon. Invite ${p.goal} friends and unlock 1 full year of Studioma.</b>`
          : html`<b>Your ${p.trial_days}-Day Full Access Trial Is Active</b>`}
          <span>${r.state === 'scheduled' ? html`Your free year starts on <b>${day(r.starts)}</b>, right after your trial. ` : 'Enjoy all Studioma features. No credit card required. '}
          <b class="promo-days">${fmt.plural(t.days_left, 'day')} left</b> · ends on ${day(t.ends)}.</span></div>
        ${r.state === 'scheduled' ? html`<a class="btn btn-soft btn-sm" href="#/refer">View my referrals</a>`
          : html`<a class="btn btn-primary btn-sm" href="#/refer">${soon ? 'View My Referrals' : `Invite ${p.goal} Friends & Get 1 Year Free`}</a>`}</section>`;
    }
    if (p.claimable) {
      return html`<section class="promo-banner" aria-label="Launch offer">${icon('sparkles')}
        <div class="grow"><b>Special launch offer: ${p.trial_days} days of full access, free</b><span>Every Studioma feature. No credit card, no automatic billing.</span></div>
        <button class="btn btn-primary btn-sm" data-act="promo-claim">Start my free ${p.trial_days} days</button></section>`;
    }
    if (t.state === 'ended' && r.state === 'none' && p.enabled) {
      return html`<section class="promo-banner is-ended" aria-label="Your free trial ended">${icon('zap')}
        <div class="grow"><b>Your ${p.trial_days}-day full-access trial has ended.</b><span>You can continue with the Free plan and still earn a full year by inviting ${p.goal} friends.</span></div>
        <a class="btn btn-primary btn-sm" href="#/refer">Invite Friends</a></section>`;
    }
    return '';
  };
  /** Réclamer l'essai (compte existant) : le serveur l'accorde une fois, jamais par-dessus un accès Team. */
  SM.actions['promo-claim'] = async (el) => {
    if (!SM.me) { sessionStorage.setItem('stm.next', '/refer'); SM.go('/signup'); return; }
    SM.busy(el, true);
    const r = await SM.try(SM.post('promo_claim'));
    SM.busy(el, false);
    if (!r) return;
    SM.promo = r;
    SM.toast(`Your ${r.trial_days}-day full-access trial is active — enjoy every feature`);
    SM.navigate();
  };

  /* ------------------------------------------------------ Refer & Earn */
  const MESSAGES = (v, goal) => (v >= goal ? 'Congratulations! You\'ve unlocked 1 year of full Studioma access.'
    : v === goal - 1 ? 'Just one more verified friend to unlock your reward!'
      : v >= 5 ? 'You\'re getting closer to your free year!'
        : v >= 1 ? 'Great start! Keep sharing Studioma with your friends.'
          : 'Your journey starts here! Share your link and invite your first friend.');
  const STATUS_CLS = { verified: 'chip-ok', pending: 'chip-soft', review: 'chip-warn', rejected: '' };
  SM.route(/^\/refer$/, async (m, ctx) => {
    SM.set(ctx.view, html`<div class="page page-narrow">${SM.loading(4)}</div>`);
    const d = await SM.get('promo');
    if (!ctx.alive()) return;
    SM.promo = d;
    const v = d.stats.verified;
    const pct = Math.round((d.progress / d.goal) * 100);
    const enc = encodeURIComponent;
    const shares = [
      ['WhatsApp', 'message', `https://wa.me/?text=${enc(d.share_text)}`],
      ['Facebook', 'share', `https://www.facebook.com/sharer/sharer.php?u=${enc(d.url)}`],
      ['LinkedIn', 'briefcase', `https://www.linkedin.com/sharing/share-offsite/?url=${enc(d.url)}`],
      ['Email', 'mail', `mailto:?subject=${enc('Try Studioma free for ' + d.trial_days + ' days')}&body=${enc(d.share_text)}`],
    ];
    const r = d.reward;
    const unlocked = r.state === 'active' || r.state === 'scheduled' || r.state === 'ended';
    SM.set(ctx.view, html`<div class="page page-narrow refer">
      <header class="page-head"><div><p class="eyebrow-s">${icon('award')}Refer & Earn</p><h1>Invite ${d.goal} Friends. Unlock 1 Year Free.</h1>
        <p class="muted">Share your unique referral link. When ${d.goal} friends create and verify their Studioma accounts, you'll earn ${d.reward_months} months of full access.</p></div></header>
      ${unlocked ? html`<section class="panel pad congrats" aria-live="polite">
          <p class="congrats-emoji" aria-hidden="true">🎉</p><h2>Congratulations!</h2>
          <p><b>You've invited ${d.goal} friends to Studioma!</b><br>You've unlocked 1 FULL YEAR of Studioma Pro — completely free.<br>No credit card. No payment. Just more creative possibilities.</p>
          <ul class="pub-facts"><li>${icon('calendar')}<span><small>Activation date</small><b>${day(r.starts)}</b></span></li>
            <li>${icon('clock')}<span><small>Expiration date</small><b>${day(r.ends)}</b></span></li>
            <li>${icon('check-circle')}<span><small>Access status</small><b>${r.state === 'active' ? 'Active — ' + left(r.days_left) + ' left' : r.state === 'scheduled' ? 'Starts after your trial' : 'Ended'}</b></span></li></ul>
          <a class="btn btn-primary" href="#/projects">${icon('sparkles')}Explore Your Pro Features</a></section>` : ''}
      ${!d.enabled && !unlocked ? html`<p class="notice">${icon('info')}<span>The launch offer has ended. Your link still works for invitations, but new referrals no longer unlock a free year.</span></p>` : ''}
      <section class="panel pad stack">
        <h2 class="section-title">${icon('link')}Your referral link</h2>
        <div class="ref-link"><input class="input" value="${d.url}" readonly aria-label="Your referral link" id="ref-url">
          <button class="btn btn-primary" data-act="copy" data-text="${d.url}">${icon('copy')}Copy Link</button></div>
        <div class="row row-wrap ref-share">
          <button class="btn btn-soft btn-sm" data-act="ref-native">${icon('share')}Share Link</button>
          ${shares.map(([label, ic, href]) => html`<a class="btn btn-ghost btn-sm" href="${href}" target="_blank" rel="noopener noreferrer" data-share="${label.toLowerCase()}">${icon(ic)}${label}</a>`)}
        </div>
        <details class="ref-msg"><summary>${icon('edit')}Ready-to-share message</summary><p class="pre">${d.share_text}</p>
          <button class="btn btn-ghost btn-sm" data-act="copy" data-text="${d.share_text}">${icon('copy')}Copy message</button></details>
        <p class="muted small">${icon('shield')}Your link holds a random code — never your name or email. Nothing is ever sent on your behalf: you choose who to invite.</p>
      </section>
      <section class="ref-stats">
        ${[['Link Clicks', d.stats.clicks, 'Times your link was opened'], ['Unique Visitors', d.stats.visitors, 'Different browsers that opened it'], ['Registrations', d.stats.registrations, 'Accounts created through your link'], ['Verified Referrals', d.stats.verified, 'Verified accounts that count']]
          .map(([label, value, hint]) => html`<div class="panel stat"><small>${label}</small><b>${fmt.num(value)}</b><span class="muted small">${hint}</span></div>`)}
      </section>
      <section class="panel pad stack">
        <h2 class="section-title">${icon('target')}Your Referral Progress</h2>
        <div class="ref-progress"><b>${d.progress} / ${d.goal}</b> Verified Friends<span class="grow"></span><span class="muted">Progress: ${pct}%</span></div>
        <div class="progress progress-l" role="progressbar" aria-valuemin="0" aria-valuemax="${d.goal}" aria-valuenow="${d.progress}" aria-label="Verified friends"><i style="width:${pct}%"></i></div>
        <p class="ref-msg-line">${MESSAGES(v, d.goal)}${v < d.goal ? html` <span class="muted">Only ${d.goal - v} more verified friend${d.goal - v > 1 ? 's' : ''} to unlock your free year!</span>` : ''}</p>
        <p class="muted small">A friend counts once they create a new account through your link and confirm their email address. Your own accounts, existing members and suspicious sign-ups don't count.</p>
      </section>
      <section class="panel pad stack"><h2 class="section-title">${icon('history')}Referral history <span class="count">${d.history.length}</span></h2>
        ${d.history.length ? html`<div class="list">${d.history.map((h) => html`<div class="list-row"><span class="list-ico">${icon(h.counts ? 'check-circle' : 'user')}</span>
            <span class="grow"><b>Friend #${h.n}</b><small class="muted">Registered ${day(h.registered_at)}${h.verified_at ? ' · verified ' + day(h.verified_at) : ''}</small></span>
            <span class="chip ${STATUS_CLS[h.status] || ''}">${h.label}</span></div>`)}</div>`
          : SM.empty('users', 'No referral yet', 'Friends who sign up through your link appear here — without their name or email.')}
      </section>
      ${SM.promoBanner()}
    </div>`);
  });
  /** Partage natif (téléphone) ; sinon, copie du message. */
  SM.actions['ref-native'] = async () => {
    const d = SM.promo;
    if (navigator.share) {
      try { await navigator.share({ title: 'Studioma', text: d.share_text.replace(d.url, '').trim(), url: d.url }); } catch (e) { /* partage annulé */ }
      return;
    }
    SM.copy(d.share_text);
  };
})();
