/*
 * Studioma — formules et abonnements : page des prix (#/pricing, publique), Réglages › Plan & billing
 * (#/settings/billing), invitation à changer de formule quand une limite est atteinte (erreur « plan_limit »).
 * Les limites sont appliquées par le serveur (lib/billing.php) ; le paiement passe par Paddle (page studioma/pay/).
 */
(function () {
  'use strict';
  const { html, icon, fmt } = SM;
  const money = (cents) => '$' + (cents % 100 ? (cents / 100).toFixed(2) : String(cents / 100));
  const STATE = { free: ['Free forever', 'chip-soft'], active: ['Active', 'chip-ok'], trialing: ['Trial', 'chip-soft'], past_due: ['Payment failed', 'chip-bad'] };

  /* ---------------------------------------------- invitation à changer de formule */
  /** Fenêtre affichée quand le serveur refuse une action à cause de la formule (core.js › SM.api). */
  SM.onPlanLimit = (d) => {
    const up = d.upgrade;
    const label = up === 'solo' ? (d.feature === 'approvals' || d.feature === 'private_links' || d.feature === 'guest_collab' ? 'Unlock with Solo' : 'Upgrade to Solo') : up === 'team' ? 'Upgrade to Team' : '';
    const m = SM.modal(html`<div class="plan-gate">
        <span class="plan-gate-ico">${icon(up === 'team' ? 'users' : 'sparkles')}</span>
        <h2>${up ? 'Upgrade to keep going' : 'Plan limit reached'}</h2>
        <p>${d.message}</p>
        <div class="plan-gate-actions">
          ${up ? html`<a class="btn btn-primary btn-block" href="#/pricing?plan=${up}" data-act="plan-gate-go">${icon('zap')}${label}</a>` : ''}
          <a class="btn btn-ghost btn-block" href="#/settings/billing" data-act="plan-gate-go">See my plan and usage</a>
          <button class="btn btn-ghost btn-block" data-act="modal-close">Not now</button>
        </div>
      </div>`, { size: 's', cls: 'plan-gate-modal' });
    return m;
  };
  SM.actions['plan-gate-go'] = (el) => { SM.closeModals(); SM.go(el.getAttribute('href').slice(1)); };
  /** Bloc à la place d'un écran réservé à une formule (Insights, par exemple). */
  SM.planGate = (d) => html`<div class="empty plan-empty"><span class="empty-ico">${icon('sparkles')}</span><h3>${d.upgrade === 'team' ? 'Available on the Team plan' : 'Available on Solo and Team'}</h3>
    <p>${d.message}</p>${d.upgrade ? html`<a class="btn btn-primary" href="#/pricing?plan=${d.upgrade}">${icon('zap')}${d.upgrade === 'team' ? 'Upgrade to Team' : 'Upgrade to Solo'}</a>` : ''}</div>`;

  /* ------------------------------------------------------------ page des prix */
  const unlimited = (v, unit) => (v == null ? 'Unlimited' + (unit ? ' ' + unit : '') : fmt.num(v) + (unit ? ' ' + unit : ''));
  const yes = (on) => (on ? html`<span class="cmp-yes">${icon('check')}<span class="sr">Included</span></span>` : html`<span class="cmp-no">—<span class="sr">Not included</span></span>`);
  const COMPARE = [
    ['Projects', (p) => fmt.num(p.limits.projects)],
    ['Studios (in total)', (p) => fmt.num(p.limits.studios)],
    ['New cards per month', (p) => unlimited(p.limits.cards_month)],
    ['Registered members (including you)', (p) => fmt.num(p.limits.members)],
    ['Board, calendar, levels and automatic handoffs', () => yes(true)],
    ['Public guest links (view-only)', (p) => (p.limits.share_links == null ? 'Unlimited' : 'Up to ' + p.limits.share_links)],
    ['Private guest links, guests verified by email', (p) => yes(p.features.private_links)],
    ['Guests who comment, approve or edit; edit requests by email', (p) => yes(p.features.guest_collab)],
    ['Client approvals and approval notifications', (p) => yes(p.features.approvals)],
    ['Team invitations, roles and assignments', (p) => yes(p.features.team)],
    ['Studio performance insights', (p) => yes(p.features.insights)],
  ];
  /** Réponse « essai gratuit » : l'offre de lancement quand elle est ouverte (lib/promo.php), sinon la formule Free. */
  const trialAnswer = (on) => (on
    ? `Yes. Every account gets ${SM.ref.promo_trial_days || 30} days of full access to all features (the Team plan and its limits) — no credit card, no payment, no automatic billing. When it ends you continue on Free: nothing is deleted and nothing is charged. Invite 10 friends who verify their accounts and you unlock a full year.`
    : 'The Free plan is free forever, without a card. Start there and upgrade when you need more clients or a team.');
  const FAQ = [
    ['Can I change plans later?', 'Yes. Upgrades apply right away and Paddle charges only the prorated difference. Moving to a lower plan or back to Free never deletes anything.'],
    ['What happens to my work if I downgrade?', 'Everything stays: projects, Studios, cards, history. If you are above the new plan\'s limits, you can still open everything, but you can\'t create more until you archive or delete some, or upgrade again. Extra team members and guests keep read-only access.'],
    ['Do my clients and guests need to pay?', 'No. Guests open your Studio with a share link, without an account, and never count as members.'],
    ['What counts as a card on the Free plan?', 'Each new card created in your projects during the calendar month (by you, a guest or an AI assistant). The allowance renews on the 1st. Existing cards are never deleted. Paid plans have unlimited cards, with a fair-use limit of 5,000 new cards per month.'],
    ['How does payment work?', 'Payments are handled by Paddle, our reseller and merchant of record. Your card details go to Paddle, never to Studioma. Taxes may apply depending on your country; Paddle shows the final amount before you pay and emails your invoices.'],
    ['Can I cancel at any time?', 'Yes, from Settings › Plan & billing. You keep your paid plan until the end of the period you paid for, then your account moves to Free.'],
    ['Can I get a refund?', (d) => `Yes. If Studioma is not right for you, ask within ${d.refund_days || 14} days of the first payment of your subscription and we refund it in full (once per account). Renewals are not refunded, but you can cancel at any time before the next one. Details are in the Refund Policy.`],
    ['Is there a free trial?', ''],
  ];
  SM.route(/^\/pricing$/, async (m, ctx) => {
    const q = SM.query();
    let interval = q.get('interval') === 'year' ? 'year' : 'month';
    const d = await SM.get('billing_plans');
    if (!ctx.alive()) return;
    const me = SM.me ? await SM.get('billing').catch(() => null) : null;
    if (!ctx.alive()) return;
    const current = me ? me.plan : null;
    const paid = me && me.subscription && me.subscription.provider === 'paddle' ? me.subscription : null;
    const promoSub = me && me.subscription && me.subscription.provider === 'promo' ? me.subscription : null;
    const focus = q.get('plan');
    // Offre de lancement : 30 jours d'accès complet, sans carte (la même pour Solo et Team), et parrainage.
    const promoOn = !!d.promo;
    const promo = SM.me ? SM.promo : null;
    /** Bouton d'une carte payante pendant l'offre : jamais de paiement, un seul essai par compte. */
    const promoCta = (p) => {
      const tag = html`<p class="plan-nocard">${icon('check')}No credit card required</p>`;
      if (!SM.me) return html`<a class="btn ${p.key === 'solo' ? 'btn-primary' : 'btn-soft'} btn-block" href="#/signup">Get 30 Days Free</a>${tag}`;
      if (promo && (promo.reward.state === 'active' || promo.reward.state === 'scheduled')) return html`<button class="btn btn-block" disabled>${icon('award')}Free year unlocked</button>${tag}`;
      if (promo && promo.trial.state === 'active') return html`<button class="btn btn-block" disabled>${icon('check')}Free trial active — ${fmt.plural(promo.trial.days_left, 'day')} left</button>${tag}`;
      if (promo && promo.claimable) return html`<button class="btn ${p.key === 'solo' ? 'btn-primary' : 'btn-soft'} btn-block" data-act="promo-claim">Get 30 Days Free</button>${tag}`;
      if (promo && promo.other_team) return html`<button class="btn btn-block" disabled>${icon('check')}You already have full access</button>`;
      return html`<button class="btn btn-block" disabled>Free trial already used</button><a class="plan-alt" href="#/refer">${icon('award')}Invite 10 friends for a free year</a>`;
    };
    const draw = () => {
      const card = (p) => {
        const isCurrent = current === p.key && (!paid || paid.interval === interval || p.key === 'free');
        const price = p.price[interval];
        let cta;
        if (p.key === 'free') {
          cta = !SM.me ? html`<a class="btn btn-block" href="#/signup">Get Started Free</a>`
            : current === 'free' ? html`<button class="btn btn-block" disabled>${icon('check')}Current plan</button>`
              : html`<a class="btn btn-ghost btn-block" href="#/settings/billing">Downgrade to Free</a>`;
        } else if (!d.checkout && promoOn) {
          cta = promoCta(p);   // même essai Team pour Solo et Team, jamais de paiement
        } else if (isCurrent) {
          cta = html`<button class="btn btn-block" disabled>${icon('check')}Current plan</button>`;
        } else if (!d.checkout) {
          cta = html`<button class="btn ${p.key === 'solo' ? 'btn-primary' : ''} btn-block" disabled>Coming soon</button>`;
        } else {
          const verb = !current || current === 'free' ? 'Choose ' + p.name : (STM_RANK[p.key] > STM_RANK[current] ? 'Upgrade to ' + p.name : current === p.key ? 'Switch to ' + (interval === 'year' ? 'yearly' : 'monthly') : 'Switch to ' + p.name);
          cta = html`<button class="btn ${p.key === 'solo' ? 'btn-primary' : 'btn-soft'} btn-block" data-act="plan-choose" data-plan="${p.key}" data-interval="${interval}">${verb}</button>`;
        }
        return html`<article class="panel plan-card ${p.key === 'solo' ? 'is-popular' : ''} ${focus === p.key ? 'is-focus' : ''}">
          ${p.key === 'solo' ? html`<span class="plan-badge">Most Popular</span>` : ''}
          <header><h2>${p.name}</h2>${current === p.key ? html`<span class="chip chip-ok">${promoSub ? (promoSub.status === 'trialing' ? 'Free trial' : 'Free year') : 'Your plan'}</span>` : ''}</header>
          <p class="muted">${p.tagline}</p>
          <p class="plan-price"><b>${money(price)}</b><span>/${interval}</span></p>
          <p class="plan-sub">${p.key === 'free' ? 'Free forever — no card needed' : interval === 'year' ? `${money(Math.round(price / 12))}/month billed yearly · 2 months free` : `or ${money(p.price.year)}/year — 2 months free`}</p>
          ${cta}
          <ul class="plan-list">${p.bullets.map((x) => html`<li>${icon('check')}<span>${x}</span></li>`)}</ul>
        </article>`;
      };
      const plans = d.plans;
      SM.set(ctx.view, html`<div class="page pricing">
        <header class="pricing-head">
          <h1>Simple pricing for every stage of your growth</h1>
          <p>Start free. Manage more clients. Collaborate better.</p>
          <div class="seg seg-inline" role="radiogroup" aria-label="Billing period">
            <label><input type="radio" name="interval" value="month" data-change="plan-interval" ${interval === 'month' ? 'checked' : ''}><span>Monthly</span></label>
            <label><input type="radio" name="interval" value="year" data-change="plan-interval" ${interval === 'year' ? 'checked' : ''}><span>Yearly <em class="save">2 months free</em></span></label>
          </div>
        </header>
        ${promoOn ? html`<div class="promo-hero pricing-note">${icon('sparkles')}<div><b>Special Launch Offer: Enjoy 30 days of full access to all Studioma features — completely free. No credit card required.</b>
            ${!d.checkout ? html`<span>Paid subscriptions aren't available yet: the Solo and Team prices below are planned prices. The offer is a free trial of every feature, not a subscription — nothing is ever charged, and you return to Free when it ends without losing anything.</span>` : ''}</div></div>`
          : !d.checkout ? html`<p class="notice pricing-note">${icon('info')}<span>Online payment for Solo and Team opens soon. Start free today — everything you create stays when you upgrade.</span></p>` : ''}
        ${me && me.over && me.over.length ? html`<p class="notice notice-warn pricing-note">${icon('alert')}<span>Your account is above the ${me.plan_name} plan limits (${me.over.map((o) => `${o.label} ${o.used}/${o.max}`).join(', ')}). Nothing was deleted — <a href="#/settings/billing">see details</a>.</span></p>` : ''}
        <div class="plan-grid">${plans.map(card)}</div>
        ${promoOn ? html`<section class="panel pad promo-invite">${icon('award')}<div class="grow"><h2>Invite 10 Friends. Get 1 Year Free.</h2>
            <p>Share Studioma with your friends. When 10 new users register and verify their accounts through your referral link, unlock 12 months of full access.</p></div>
            <a class="btn btn-primary" href="${SM.me ? '#/refer' : '#/signup'}" data-act="promo-invite">${icon('users')}Invite & Earn</a></section>` : ''}
        <section class="panel compare-wrap"><h2 class="pad">Compare plans</h2>
          <div class="table-scroll"><table class="compare">
            <thead><tr><th><span class="sr-only">Feature</span></th>${plans.map((p) => html`<th>${p.name}<small>${money(p.price[interval])}/${interval}</small></th>`)}</tr></thead>
            <tbody>${COMPARE.map(([label, fn]) => html`<tr><th scope="row">${label}</th>${plans.map((p) => html`<td>${fn(p)}</td>`)}</tr>`)}</tbody>
          </table></div></section>
        <section class="faq"><h2>Frequently asked questions</h2>${FAQ.map(([qq, a]) => html`<details class="panel"><summary>${qq}</summary><p>${typeof a === 'function' ? a(d) : (a || trialAnswer(promoOn))}</p></details>`)}
          <p class="legal-links muted"><a href="${SM.base}terms/">Terms of Service</a> · <a href="${SM.base}privacy/">Privacy Policy</a> · <a href="${SM.base}refunds/">Refund Policy</a></p></section>
      </div>`);
      const f = focus && SM.$('.plan-card.is-focus');
      if (f && f.scrollIntoView) f.scrollIntoView({ block: 'center' });
    };
    SM.changes['plan-interval'] = (el) => { interval = el.value; draw(); };
    draw();
  }, { public: true });
  SM.actions['promo-invite'] = (el) => { if (!SM.me) sessionStorage.setItem('stm.next', '/refer'); SM.go(SM.me ? '/refer' : '/signup'); };
  const STM_RANK = { free: 0, solo: 1, team: 2 };

  /** Choisir une formule : paiement Paddle (nouvel abonnement) ou changement de l'abonnement en cours. */
  SM.actions['plan-choose'] = async (el) => {
    const plan = el.dataset.plan;
    const interval = el.dataset.interval;
    if (!SM.me) { sessionStorage.setItem('stm.next', '/pricing?plan=' + plan + '&interval=' + interval); SM.go('/signup'); return; }
    const me = await SM.try(SM.get('billing'));
    if (!me) return;
    const sub = me.subscription && me.subscription.provider === 'paddle' ? me.subscription : null;
    const name = plan === 'solo' ? 'Solo' : 'Team';
    if (sub) {
      const up = STM_RANK[plan] > STM_RANK[sub.plan] || (plan === sub.plan && interval === 'year');
      const ok = await SM.confirm({
        title: (up ? 'Upgrade to ' : 'Switch to ') + name + (interval === 'year' ? ' (yearly)' : ' (monthly)') + '?',
        text: up ? 'The change applies right away. Paddle charges only the prorated difference for the rest of your current period.'
          : 'The change applies right away; Paddle credits the difference on your next invoice. Nothing is deleted — if you are above the new limits, you can still open everything.',
        ok: up ? 'Upgrade now' : 'Switch plan',
      });
      if (!ok) return;
      SM.busy(el, true);
      const r = await SM.try(SM.post('billing_change', { plan, interval }));
      SM.busy(el, false);
      if (r) { SM.toast('Your plan is now ' + r.plan_name); SM.go('/settings/billing'); }
      return;
    }
    SM.busy(el, true);
    const r = await SM.try(SM.post('billing_checkout', { plan, interval }));
    SM.busy(el, false);
    if (r && r.checkout_url) location.href = r.checkout_url;   // page de paiement Paddle (studioma/pay/)
  };

  /* -------------------------------------------- Réglages › Plan & billing */
  SM.route(/^\/settings\/billing$/, async (m, ctx) => {
    let d = await SM.get('billing');
    if (!ctx.alive()) return;
    const afterCheckout = SM.query().get('checkout') === 'done';
    const draw = () => {
      const s = d.subscription;
      const paddle = s && s.provider === 'paddle';
      const promoSub = s && s.provider === 'promo';
      const [stateLabel, stateCls] = promoSub ? [s.status === 'trialing' ? 'Free trial' : 'Free year (referrals)', 'chip-ok'] : s && s.provider === 'manual' ? ['Complimentary', 'chip-soft'] : s && s.cancel_at ? ['Ends ' + fmt.date(s.cancel_at), 'chip-warn'] : (STATE[d.state] || [d.state, '']);
      const L = d.limits;
      const U = d.usage;
      const bar = (label, used, max, hint) => {
        const pct = max == null ? 0 : Math.min(100, Math.round((used / Math.max(1, max)) * 100));
        return html`<div class="usage-row ${max != null && used > max ? 'is-over' : ''}">
          <div class="usage-top"><b>${label}</b><span>${fmt.num(used)}${max == null ? html` <small>· unlimited</small>` : html` / ${fmt.num(max)}`}</span></div>
          ${max != null ? html`<span class="progress"><i style="width:${pct}%"></i></span>` : ''}${hint ? html`<small class="muted">${hint}</small>` : ''}</div>`;
      };
      SM.set(ctx.view, html`<div class="page page-narrow">
        <header class="page-head"><div><a class="back small" href="#/settings">${icon('arrow-left')}Settings</a><h1>Plan & billing</h1></div>
          <a class="btn btn-soft" href="#/pricing">${icon('sparkles')}<span>See all plans</span></a></header>
        ${afterCheckout && d.plan === 'free' ? html`<p class="notice" id="checkout-wait">${SM.spinner()}<span>Payment received by Paddle. Your plan is activated as soon as Paddle confirms it — usually within a few seconds.</span></p>` : ''}
        ${d.state === 'past_due' ? html`<p class="notice notice-warn">${icon('alert')}<span><b>Your last payment failed.</b> Update your payment method before ${fmt.date(d.grace_until)} to keep the ${d.plan_name} plan. Nothing will be deleted.</span></p>` : ''}
        ${d.over.length ? html`<div class="notice notice-warn">${icon('alert')}<div><b>Your account is above the ${d.plan_name} plan limits.</b> Nothing was deleted and everything stays readable. To create more, archive or delete some items, or upgrade.
          <ul class="over-list">${d.over.map((o) => html`<li>${o.label}: ${o.used} of ${o.max}${o.key === 'members' ? ' — members beyond the plan have read-only access' : ''}</li>`)}</ul></div></div>` : ''}
        <section class="panel pad current-plan">
          <div class="row between"><div><small class="muted">Current plan</small><h2 class="plan-name">${d.plan_name}</h2></div><span class="chip ${stateCls}">${stateLabel}</span></div>
          ${paddle ? html`<p>${money(s.amount)} per ${s.interval === 'year' ? 'year' : 'month'} · billed ${s.interval === 'year' ? 'yearly' : 'monthly'}${s.cancel_at ? html` · <b>ends on ${fmt.date(s.cancel_at)}</b>, then Free` : s.period_end ? ` · renews on ${fmt.date(s.period_end)}` : ''}</p>` : ''}
          ${s && s.provider === 'manual' ? html`<p>Offered by Studioma${s.period_end ? ' until ' + fmt.date(s.period_end) : ''}.</p>` : ''}
          ${promoSub ? html`<p>Launch offer: full access until <b>${fmt.date(s.period_end)}</b> — no credit card, no payment, no automatic billing. Then your account returns to Free; nothing is deleted. <a href="#/refer">Invite friends to earn a free year</a>.</p>` : ''}
          ${d.plan === 'free' ? html`<p class="muted">Free forever. Upgrade when you need more projects, client approvals or a team.</p>` : ''}
          <div class="row row-wrap">
            ${d.plan !== 'team' ? html`<a class="btn btn-primary" href="#/pricing?plan=${d.plan === 'free' ? 'solo' : 'team'}">${icon('zap')}${d.plan === 'free' ? 'Upgrade' : 'Upgrade to Team'}</a>` : ''}
            ${paddle && d.checkout ? html`<button class="btn" data-act="plan-choose" data-plan="${s.plan}" data-interval="${s.interval === 'year' ? 'month' : 'year'}">Switch to ${s.interval === 'year' ? 'monthly' : 'yearly'} billing</button>` : ''}
            ${paddle && d.plan === 'team' && d.checkout ? html`<button class="btn" data-act="plan-choose" data-plan="solo" data-interval="${s.interval}">Downgrade to Solo</button>` : ''}
            ${paddle && d.checkout ? html`<button class="btn" data-act="billing-portal">${icon('external')}Manage billing</button>` : ''}
            ${paddle && d.checkout && !s.cancel_at ? html`<button class="btn is-danger" data-act="billing-cancel">Cancel subscription</button>` : ''}
            ${paddle && d.checkout && s.cancel_at ? html`<button class="btn btn-soft" data-act="billing-resume">Keep my subscription</button>` : ''}
          </div>
          ${!d.checkout ? html`<p class="muted small">Online payment opens soon. Your plan and limits are already shown below.</p>` : ''}
        </section>
        <section class="panel pad"><h2>${icon('chart')} Usage</h2>
          ${bar('Projects', U.projects, L.projects, 'Archived projects don\'t count.')}
          ${bar('Studios', U.studios, L.studios)}
          ${bar('Team members', U.members, L.members, U.pending_invites ? fmt.plural(U.pending_invites, 'pending invitation') + ' also reserve a place.' : 'Including you. Guests on share links never count.')}
          ${bar('Cards created this month', U.cards_month, L.cards_month, L.cards_month == null ? 'Fair use: up to ' + fmt.num(d.fair_use_cards) + ' new cards per month.' : 'Renews on ' + fmt.date(d.renews_cards) + '. Existing cards are never deleted.')}
          ${bar('Active guest links', U.share_links, L.share_links)}
        </section>
        ${d.transactions.length ? html`<section class="panel pad"><h2>${icon('history')} Payments</h2>${d.transactions.map((t) => html`<div class="list-row"><span class="grow"><b>${money(t.amount)} ${t.currency}</b><small class="muted">${fmt.date(t.billed_at)}</small></span><span class="chip ${t.status === 'payment_failed' ? 'chip-bad' : 'chip-ok'}">${t.status === 'payment_failed' ? 'Failed' : 'Paid'}</span></div>`)}
          <p class="muted small">Invoices are emailed by Paddle and available in Manage billing.</p></section>` : ''}
      </div>`);
    };
    draw();
    if (afterCheckout && d.plan === 'free') {
      // La formule ne change qu'à la confirmation de Paddle (webhook) : on attend, sans jamais l'activer ici.
      let n = 0;
      const t = setInterval(async () => {
        if (!ctx.alive() || ++n > 30) { clearInterval(t); const w = SM.$('#checkout-wait'); if (w && ctx.alive()) SM.set(w, html`${icon('info')}<span>Paddle hasn't confirmed the payment yet. Refresh this page in a minute; if your plan doesn't change, contact us.</span>`); return; }
        const r = await SM.get('billing').catch(() => null);
        if (r && r.plan !== 'free' && ctx.alive()) { clearInterval(t); d = r; SM.toast('Welcome to ' + r.plan_name + '!'); draw(); }
      }, 3000);
      SM.onLeave(() => clearInterval(t));
    }
  });
  SM.actions['billing-cancel'] = async (el) => {
    const d = await SM.try(SM.get('billing'));
    if (!d || !d.subscription) return;
    const end = d.subscription.period_end ? fmt.date(d.subscription.period_end) : 'the end of your billing period';
    if (!(await SM.confirm({ title: 'Cancel your subscription?', text: `You keep ${d.plan_name} until ${end}, then your account moves to Free. Nothing is deleted.`, ok: 'Cancel subscription', cancel: 'Keep it', danger: true }))) return;
    SM.busy(el, true);
    const r = await SM.try(SM.post('billing_cancel'));
    SM.busy(el, false);
    if (r) { SM.toast('Your subscription ends on ' + end); SM.navigate(); }
  };
  SM.actions['billing-resume'] = async (el) => {
    SM.busy(el, true);
    const r = await SM.try(SM.post('billing_resume'));
    SM.busy(el, false);
    if (r) { SM.toast('Your subscription continues'); SM.navigate(); }
  };
  SM.actions['billing-portal'] = async (el) => {
    SM.busy(el, true);
    const r = await SM.try(SM.post('billing_portal'));
    SM.busy(el, false);
    if (r && r.url) window.open(r.url, '_blank', 'noopener');
  };
})();
