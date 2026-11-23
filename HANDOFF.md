# HANDOFF — read first, update last

Shared memory between **Claude Code** and **OpenAI Codex** (protocol: `AGENTS.md` § 6). Keep the « Current
state » block short and true; add a log entry at the top of « Log » after every meaningful task. Never write
secrets here. Mark anything you could not verify as *(unverified)*.

## Current state — 2026-11-23

| | |
|---|---|
| Last agent | Claude Code (cloud session) |
| Branch | **`claude/trusting-keller-rynk1j`** (tracks `origin/claude/trusting-keller-rynk1j`), based on `origin/main` `666e21c` (PR #85, legal pages). No pull request yet for this work (open one only when the owner asks) |
| Base code | `main` @ `666e21c` = Merge PR #85 (legal pages; DB v13). **`main` = production** — no deployment cron: the owner copies `main` by hand. Live check 2026-11-23 11:00 UTC: live `passport.css` = PR #84 version → PR #85 not copied yet *(unverified since)* |
| Checkpoint | the commit that last changed this file: `git log -1 --format='%H %s' -- HANDOFF.md` |
| Uncommitted work at checkpoint | none |
| In progress | **Studioma: PayPal replaces Paddle** (owner's request 2026-11-23). Implemented, tested against a fake PayPal, committed and pushed on `claude/trusting-keller-rynk1j`; **not merged, not deployed, not tested against real PayPal** |
| Last task changed application code? | **yes** — Studioma billing (DB schema 13 → 14: two columns added) |
| Codex access | **not verified** — Codex has not been run on this repository from this session |

**Start here (Codex or Claude)**

1. `git fetch origin`, then `git switch claude/trusting-keller-rynk1j` (or `git switch -c codex/<topic> origin/claude/trusting-keller-rynk1j`).
   Production is `origin/main` @ `666e21c`.
2. Read `docs/billing.md` (whole file: PayPal architecture, lifecycle, owner setup, sandbox test, limitations) and
   decision S41; S40 for the legal pages; S36–S39 for the quality pass.
3. Checks: `AGENTS.md` § 5 (lint, then `tests/studioma/run.sh`, ~25 min, 16 suites + `billing-config.php`; a long run
   may need to be split — each suite can run on its own fresh hosting).

**Done on this branch, not in `main`** (PayPal):
- Owner's choices (asked 2026-11-23): **no tax added** (prices final; Studioma is now the seller, tax obligations are
  the owner's) and **upgrade right away** (new price from the next renewal; PayPal cannot prorate). Downgrades keep the
  paid plan until the period ends (`subscriptions.prev_plan` / `prev_until`, DB v14).
- `studioma/lib/paypal.php` (new): OAuth token (memory only), `billing_checkout` (server-created subscription,
  `custom_id` = `stm:<uid>`, `pending` row, PayPal approval link), `billing_confirm` (re-reads at PayPal after the
  return), `billing_change` (« revise », approval link or direct), `billing_cancel` (final at PayPal, plan kept until
  paid-through date, no second subscription meanwhile), sync and status mapping, payments (`PAYMENT.SALE.*`),
  webhook handling (verified by PayPal's `verify-webhook-signature`, raw body forwarded, subscription always re-read,
  idempotent, 503 when PayPal is unreachable), `admin_paypal_setup` (creates product + 4 plans, returns config lines).
- `studioma/paypal-webhook.php` (new); removed `lib/paddle.php`, `paddle-webhook.php`, `tests/studioma/fake-paddle.php`.
- `lib/billing.php`: PayPal settings (`STM_PAYPAL_ENV` sandbox|live, `_CLIENT_ID`, `_SECRET`, `_WEBHOOK_ID`,
  `_PLAN_*`), config check, canceled-but-paid and `prev_plan` handling in `account_plan()`, `manage_url`;
  `billing_config` route removed. `lib/admin.php`: metrics for PayPal (pending excluded, canceled-in-period counted as
  subscriber, not MRR), `can_setup_plans`, `webhook_url`. `api.php`: routes; **account deletion refused while a
  PayPal subscription is active**. `lib/core.php`: migration v14.
- UI: `assets/billing.js` (pricing FAQ and texts, plan choice → PayPal, « Plan & billing » confirmation after return,
  Manage in PayPal, cancel, payments with refunded/failed states), `assets/pay.js` + `pay/index.html` + `pay/.htaccess`
  (return page, strict CSP, no third-party script), `assets/admin.js` + `admin.css` (PayPal wording, « Create the
  PayPal plans », webhook address).
- Legal pages (`p.php`): Terms / Privacy / Refunds now say paid through PayPal, Studioma (Mohammed Dehini) is the
  seller, no tax added, plan-change rules, PayPal as independent controller, no PayPal script.
- Tests: `tests/studioma/fake-paypal.php` (new), `billing.js` rewritten (141 checks), `billing-config.php` (13),
  `legal.js`, `promo.js`, `promo-e2e.js`, `quality.js`, `admin.js` (schema 14), `setup.sh`, `router.php`, `run.sh`.
- Docs: `docs/billing.md` (rewritten), README « Studioma › 8 », tests README, DECISIONS S41, ROADMAP,
  PROJECT_CONTEXT, AGENTS.md map, `.env.example`, `config.example.php`.

**Unfinished / waiting (owner)**

- **PayPal dashboard done by the owner (2026-11-23, reported, *unverified* by the agent)**: the secrets pasted in a
  chat are **disabled** (sandbox and live apps: « Secret key 1 » disabled, « Secret key 2 » active — the new secrets
  were never shared). Webhooks to `https://dehini.com/studioma/paypal-webhook.php` with the 11 events of
  `docs/billing.md` § 5: **sandbox** app « Default Application », webhook ID `7D705505XL3910920`; **live** webhook ID
  `74L10208VA199193E` (IDs are not secrets). Sandbox buyer: the « Personal » sandbox account.
- **PR #86 (PayPal) merged by the owner (`main` @ `ed3341d`) and deployed** — verified from outside 2026-11-23:
  `paypal-webhook.php` answers 405 to GET (exists), `paddle-webhook.php` 404 (removed), `lib/paypal.php` 403.
- **Incident 2026-11-23**: the server's `~/public_html/studioma/config.php` was created with the 4 PayPal lines but
  **without `<?php`** (`php -l` does not catch that), so every Studioma response started with the file's text,
  exposing the sandbox secret « Secret key 2 » publicly and breaking JSON replies; the settings were not applied.
  Fixed the same day (`<?php` added; a duplicate `<?php` briefly caused HTTP 500, then removed): **verified** the
  live API answers 200 with JSON, no leak, `env` sandbox. The agent deleted its local copy of the leaked reply.
  Then the owner generated a new sandbox secret; a manual edit broke the file's syntax (HTTP 500 for a few minutes);
  the file was rewritten with a one-line `printf` (secret typed with `read -rs`, never shown) — **verified** 200 JSON,
  no leak. Lesson: `php -l` does not catch a missing `<?php`; never keep a copy of `config.php` under `public_html`.
- **Real PayPal sandbox, 2026-11-23/24 (owner's screenshots + live API check)** — working: OAuth with the new secret,
  « Create the PayPal plans » (4 plans), `STM_BILLING_ENABLED` (live API `checkout: true`, `env: sandbox`),
  subscription creation, redirect to PayPal's approval page (« Studioma », 12.00 USD), cancel return to pricing,
  webhooks delivered and **verified by PayPal** (`BILLING.SUBSCRIPTION.CREATED` ×4 « processed »). **Not yet seen**:
  an approved payment (`ACTIVATED`, `PAYMENT.SALE.COMPLETED`). The first approval failed on PayPal's page (« couldn't
  set up your subscription using the card you selected ») with the default sandbox buyer; the sandbox **seller is
  country DZ** — possible cause *(unverified)*: PayPal limits for Algerian sellers. Next test: a US sandbox buyer
  with balance; if it fails the same way, test with a US sandbox seller + app, and ask PayPal about the live account.
- Open owner questions: buyers without a PayPal account (guest card option depends on PayPal and the seller's
  country; « PayPal account optional » setting; else a second provider), and the trial display fix (a member who pays
  during the 30-day trial does not see the PayPal subscription in « Plan & billing » until the trial ends — offered,
  not done).
- Confirm the live PayPal account can **receive** payments (Algeria: *unverified*); tax obligations as seller.
- Setup and sandbox test: `docs/billing.md` § 5 and § 8.1 (config, webhook + its ID, « Create the PayPal plans »,
  `STM_BILLING_ENABLED`, buy Solo with a sandbox personal account, upgrade, downgrade, cancel, refund).
- Review, ask for the pull request, merge, deploy (`cp -rf studioma ~/public_html/`). Also still to deploy: PR #85.
- Carried over: cookie consent for the Meta pixel; lawyer review of the legal pages; Search Console; Safari / Firefox.

**Next recommended action**

1. **Owner**: rotate the PayPal secrets, then follow `docs/billing.md` § 5 → § 8.1 in **sandbox**; ask for the PR.
2. **Agent, after the owner's sandbox test**: fix whatever real PayPal does differently from the fake (see
   `docs/billing.md` § 7 « not tested against real PayPal yet »).

**Blockers**: real PayPal behaviour unverified (no PayPal access from the development environment).

**Validation at this checkpoint** (2026-11-23, branch `claude/trusting-keller-rynk1j`): all 16 Studioma suites +
config check, each on a fresh fake hosting (same `setup.sh` as `run.sh`), **0 failures** — api 239, share 144,
meta 73, mcp 155, admin 116, **billing 141** (fake PayPal, browser round trip through PayPal and `studioma/pay/`),
promo 76, promo-e2e 44, journey 135, journey-e2e 57, publish 130, publish-e2e 37, quality 53, quality-e2e 36,
legal 77, e2e 202, billing-config 13. (api, billing, admin and promo were re-run after the last code change;
the others ran before the account-deletion guard, which only adds a refusal for accounts with a PayPal
subscription.) `php -l` 121 PHP files, `node --check` 36 JS files: 0 errors.

## Log (newest first)

### 2026-11-23 — Claude Code — Studioma: PayPal replaces Paddle
- Owner: « change Paddle to PayPal payment » (developer.paypal.com/checkout) and pasted sandbox and live API
  credentials in the chat — **not used, not stored**; owner told to rotate them.
- Owner's choices (AskUserQuestion): no tax added; upgrade applies right away. PayPal docs read: revise = new price
  from the next billing cycle, no proration; webhook verification API; event names.
- Server-created subscriptions with redirect approval (no PayPal JS, strict CSP on the return page); plan only from
  PayPal's API answer or a PayPal-verified webhook; DB v14 for downgrades; account deletion blocked while subscribed
  (gap that also existed with Paddle). Paddle code removed (never live). Legal pages updated (Studioma is the seller).
- Tests: see « Validation ». Committed and pushed; not merged; real PayPal untested.


### 2026-11-23 — Claude Code — Studioma: Terms of Service, Privacy Policy, Refund Policy
- Owner's request: « add Terms of service, Privacy policy, Refund policy ». Choices asked and answered: legal name
  Mohammed Dehini, contact@dehini.com, Algeria, 14-day money-back guarantee, minimum age 16.
- Server-rendered pages in `p.php` (no database needed), linked from every public page, the landing, the app's
  sign-up and terms gate, the pricing FAQ and the checkout page; sitemap; overridable constants. Texts derived from
  what the code actually does (cookies, processors, retention). Decision S40.
- Found and fixed while testing: the legal article (a grid item) widened the page at 320 px (`min-width: 0`); the
  refund policy did not name the governing law.
- Tests: see « Validation ». Committed and pushed; merged by the owner as PR #85 (`666e21c`); not deployed at
  2026-11-23 11:00 UTC (live `passport.css` = PR #84 version).


### 2026-11-22 — Claude Code — Studioma: quality, mobile, opportunities on Home, SEO and performance
- Owner's brief: audit then fix mobile usability, make published opportunities appear in the public Home feed, improve
  UX/UI consistency and accessibility, technical SEO, performance; measure before / after; no rebuild, no payment
  change, no deployment.
- Audit (`docs/studioma-quality-audit.md`): 240-page mobile sweep (8 viewports), axe-core on 30 page scans,
  Lighthouse locally and read-only on production (Meta pixel blocked). Only Chromium available (WebKit not tested).
- Root causes: opportunities had no feed post and `feed_pubs()` ignored them; tall dialogs on phones were bottom-aligned
  in a scroll container so their top was unreachable (publish form); a flex-shrunk Create button; grid tab bar and
  fixed-width buttons overflowing at 320 px; an absolutely positioned element escaping the pricing table's scroll box;
  light design tokens under 4.5:1; render-blocking Google Fonts and parser-blocking scripts.
- Decisions S36–S39. Production already serves Brotli over HTTP/2 and HTTP/3 (local « no compression » warning is a
  test-server artifact).
- Tests: see « Validation ». Committed and pushed; not merged.


### 2026-11-21 — Claude Code — Studioma: Creator Journey & verified achievements (DB v12)
- Owner's brief: My Achievements, server-side XP engine with anti-abuse, six achievements, six Creator levels,
  verification categories, details page, public verification links, PNG sharing, Creator Passport, celebrations,
  next milestone, client reputation, timeline, admin, no paywall, no payment change, tests, no deployment.
- Audit: completion = cards in a `done` column (`cards.done_at`); approvals in `card_approvals` but an owner could
  decide for the approver and the decider was not stored (fixed: `decided_by`, `decider_role`, backfilled from card
  history); projects had no completion state (added, server-checked); invitations and verified guests give real
  collaboration evidence; no gamification existed.
- Done: see « Current state ». Decisions S31 (ledger, idempotency, revocation), S32 (eligible work, caps, credit),
  S33 (client approvals), S34 (project completion, levels), S35 (privacy of public pages).
- Fixed on the way: a SQL comparison with a parameter bound as text (`length(body) >= ?`) was always false
  (now `CAST(? AS INTEGER)`); a mobile overflow of the timeline filters.
- QR encoder compared module by module with Python `qrcode` (7 texts × 8 masks, v1–10, UTF-8): identical.
- Tests: see « Validation ». Committed and pushed; not merged.


### 2026-11-21 — Claude Code — Studioma: launch offer (30 days free, refer 10 friends for 1 year, no card)
- Owner's brief: 30 days of full Team access without any card, referral program (unique link, clicks, unique
  visitors, verified referrals, 10 → 12 months free once), pricing page CTAs, dashboard banners, Refer & Earn,
  landing sections, admin analytics, security, no payment ever; reuse the existing entitlement system.
- Audit: `account_plan()` already centralised plans (paid + admin-offered); five places mark an email verified;
  public POSTs need no CSRF (landing can record clicks); signup rate limit is per IP.
- Done: see « Current state ». Decisions S28 (offer = `promo` subscription rows, never Paddle), S29 (one trial and
  one reward per account, unique constraint, reward after the trial), S30 (referral counting rules, abuse review, no
  IP in clicks, no fingerprinting).
- Fixed on the way: admin « users who reached 10 » counted 0 (threshold bound as text in a `HAVING`); logged-out
  header overflow on phones (from PR #80).
- Tests: see « Validation ». Committed and pushed; not merged.


### 2026-11-21 — Claude Code — Studioma: Share & publish (card sharing, Community, proposals)
- Owner's brief: share individual cards with guests (public, email-restricted, edit requests, revoke, expiry),
  publish unfinished cards as opportunities (proposals with approach, price, timeline, portfolio) and completed
  cards as achievements (likes, comments, sharing, profile), Community area, links to the source card, security,
  notifications, tests; reuse existing systems; dedicated feature branch.
- Audit: Studio sharing (links, verified guests, requests, emails), company opportunities (`opps`, tied to a
  company by FK), posts with likes/comments, portfolio case studies, notifications, file copies already existed —
  all reused. Columns of kind `done` / `cancelled` give the card stage.
- Branch `claude/card-share-publish` from `origin/main` `f5ff9f3` (PR #79 had been merged by the owner; local
  `claude/trusting-keller-rynk1j` fast-forwarded to it).
- Done: see « Current state ». Decisions S25 (card sharing reuses Studio sharing, separate scopes), S26 (only chosen
  copies are published; one per kind per card; `edit` to publish, author or Studio manager to manage), S27 (opportunity
  lifecycle; acceptance grants nothing; company jobs listed beside card opportunities).
- Fixed on the way: avatar initials in post bylines were top-aligned (`.post-by > .avatar`); guest board thumbnail
  hidden when its image isn't a shared attachment.
- Tests: see « Validation at this checkpoint ». Committed and pushed; not merged.


### 2026-11-21 — Claude Code — handoff checkpoint for Codex
- Branch `claude/trusting-keller-rynk1j`, base `550f1a6` (= `origin` branch; 4 commits above `origin/main`
  `7b703d3`); open PR #79 from this branch (not merged). Working tree was clean.
- Verified: `CLAUDE.md` imports `@AGENTS.md` (unchanged); `AGENTS.md` tells both agents to read `HANDOFF.md`,
  inspect Git and update the handoff after each task. Added step 1.5 (fetch, start from the branch named here,
  beware a stale local `main`, check open PRs). Corrected the deployment facts without removing the rule: the
  5-minute cron is documented but was absent from the server crontab on 2026-11-20 (`AGENTS.md` § 3,
  `docs/PROJECT_CONTEXT.md`).
- `.gitignore`: also ignores SSH keys (`id_rsa*`, `id_ed25519*`, `*.ppk`) and `credentials.json`; no tracked file
  became ignored. Secret scan of tracked files: only fake test fixtures (e.g. `tests/swanbida/run.sh` dummy token).
- HANDOFF « Current state » rewritten: start steps, done-on-branch list, unfinished work, owner choices pending.
- Tests: see « Validation at this checkpoint » (Studioma 1,036 + 15, SWANBIDA 119 passed; Maillage 180/181, known).
- No application code changed. Codex access not verified.

### 2026-11-21 — Claude Code — Studioma: Paddle sandbox → live migration preparation
- Owner's brief: verify sandbox first, audit live read-only, prepare the live setup without publishing or taking a
  real payment; never delete/recreate/rotate live entities; never expose credentials.
- MCP: no `paddle-sandbox` / `paddle-live` servers in the repo or session. The owner connected the claude.ai **Paddle**
  connector: it is the **live** account (`environment: production`) with **read-only** scopes. No sandbox connector.
- Sandbox: **not passed** — the billing code was never deployed and no sandbox credentials exist anywhere; the only
  evidence is the fake-Paddle suite. No live entity created.
- Live audit (read-only, nothing changed): no products, prices, discounts, notification destinations, API keys,
  client tokens, checkout domains; default payment link not set; website `https://dehini.com/studioma/`; tax mode
  `location`; balance currency USD; card, PayPal, Apple Pay, Bancontact enabled (Google Pay off); statement
  descriptor `STUDIOMA`; legal name and email filled, display name empty; onboarding verification **in progress**
  (account setup step; automated review pending) — not started or changed by the agent.
- Code: `billing_config_check()` / `billing_config_problems()` (`studioma/lib/billing.php`) keep payment closed on a
  sandbox/live mix-up and list problems by name in admin › Billing (`lib/admin.php`, `assets/admin.js`, `.notice-list`
  in `app.css`); `.env.example` (names + placeholders only); `docs/billing.md` § 8 (guard, staging sandbox test, live
  audit, ID mapping, go-live checklist); DECISIONS S24; ROADMAP.
- Tests: new `tests/studioma/billing-config.php` (15 checks, run first by `run.sh`); `billing.js` +1 check (no config
  problem with the test settings). `tests/studioma/run.sh`: **1,036 checks, 7 suites + 15 unit checks — all passed**.
- Gaps found for Paddle's website review: no Terms of Service / Privacy Policy / Refund Policy pages, no contact
  address on the Studioma landing page or pricing page.

### 2026-11-21 — Claude Code — Studioma: SaaS plans and Paddle billing
- Owner's brief: complete subscription system with Free / Solo ($12 / $120) / Team ($29 / $290), server-side limits,
  Paddle Billing, pricing page, Plan & billing settings, upgrade/downgrade, admin analytics, tests.
- Audit: no billing existed; PHP 8 + SQLite, no package manager (no Paddle SDK — REST v1 via curl); ownership =
  `projects.owner_uid`; roles in `lib/access.php`. Branch fast-forwarded to `main` 7b703d3 first.
- Checkpoints: `a267cfc` backend (schema v9, `lib/plans.php`, `lib/billing.php`, `lib/paddle.php`,
  `paddle-webhook.php`, hooks in access/studio/cards/collab/share/admin, MCP), `4c9af1e` UI (`assets/billing.js`,
  `pay/`, admin Billing), then docs (`docs/billing.md`, README « Studioma › 8 », DECISIONS S20–S23, ROADMAP,
  PROJECT_CONTEXT, `config.example.php`, tests README).
- Tests: new `tests/studioma/billing.js` (106 checks, fake Paddle `tests/studioma/fake-paddle.php` routed by
  `router.php`, setup with `BILLING=1`); other suites run with `STM_PLANS_ENFORCED` false. Two e2e waits fixed
  (redraw race; the new « Plan & billing » link shares the `.ai-link` class).
- Committed and pushed on this branch; not merged.

### 2026-11-21 — Claude Code — Studioma admin: Studios and real-time visitors
- Owner: « as admin i need to see studios created … and numbers of visiters in realtime and who is active ».
- Admin **Studios** page (`#/studios`, `api_admin_studios`, `api_admin_studio`): name, project, owner, cards,
  team, sharing, dates, search/filter/sort; record with team and roles, levels, counts. Card contents never shown
  (decision S19 supersedes S14 for Studio names).
- **Visitors**: DB **v8** `visitors`, `visit_days`; `ping` route (public POST) called by `app.js` (each screen +
  every 60 s while visible) and `landing.js`; anonymous visitor = daily salted hash of IP + UA (no cookie); only
  the screen type stored. Overview: tile *Visitors now*, panel *Who's here now* (person or « Visitor », screen,
  device, since), per-screen chips, chart *Visitors (unique per day)*.
- Fixed: hovering directly on a chart bar showed no value (`pointer-events: none` on bars).
- Tests: full `tests/studioma/run.sh` **929 checks — passed** (admin: pings, bot ignored, unknown screen,
  dedup, Studios list/search/filter/record without card titles; e2e: tile, panel, Studios page and record).
- Committed and pushed on this branch; not merged.

### 2026-11-21 — Claude Code — Studioma: terms of use after Google sign-in (replaces the locked button)
- Owner: the locked Google button was not practical; wanted the terms under « Create my account » and, for Google,
  a pop-up after getting into the account.
- Removed the checkbox, the lock and its styles. Sign up: sentence « By creating your account, you agree… » under
  the button (hidden `accept=1`, still required by `api_signup`). Google: non-dismissible « One last step » pop-up
  (`SM.termsGate`, new `locked` option of `SM.modal` in `core.js`), « I agree and continue » or « Not now — sign
  out »; opened on boot, after sign-in, and on any `terms` API error.
- Server: DB **v7** `users.terms_at` (existing non-Google accounts backfilled as accepted); `terms_accept` route;
  every user POST refused with `terms` (403) until accepted, except `terms_accept`, `logout`, `account_delete`;
  `me_view` exposes `terms`; email sign-up sets it; Google account taken back from a squatter resets it. Admin
  record shows « Terms accepted ». Decision S18.
- Tests: full `tests/studioma/run.sh` **914 checks, 6 suites — passed** (new API checks: refused before
  acceptance, accept, email sign-up accepted, squatter reset, company with Google; e2e: sentence under the
  button, pop-up after Google sign-in, Escape / outside click do not close it, server refusal, accept, sign out,
  pop-up again at next sign-in). Screenshots light/dark, desktop/mobile.
- Committed and pushed on this branch; not merged.

### 2026-11-21 — Claude Code — Studioma sign-up: Google button locked until the terms are accepted
- Owner's report: on Sign up, choosing a Google account first, then « Please accept the terms » appeared (and
  Google had already notified the account). The Google button is in Google's iframe, so the click cannot be
  checked before Google opens.
- `studioma/assets/app.js` (`signupLock`, `signupNudge`, `signup-gate`): on Sign up the Google button is covered by
  a transparent gate, greyed and `inert` until the terms box is ticked (and the company name entered for a
  company); a click on it shakes and highlights what is missing, focuses it and shows a message; the email form
  does the same instead of the old error at the bottom. `app.css`: gate, lock hint, shake and highlight (reduced
  motion respected by the existing global rule). Login page unchanged.
- Tests: `e2e.js` 199 checks — passed (new: locked button, nudge of company name then terms, focus, no Google
  request before acceptance, unlock). Screenshots light and dark. README « Studioma › 3 » updated.
- Committed and pushed on this branch; not merged.

### 2026-11-21 — Claude Code — Studioma admin: compact Live activity
- Owner's feedback on the live admin: « Live activity take all the screen » (30 rows, many identical « added a
  card to a Studio »).
- `studioma/assets/admin.js`: consecutive repeated actions of one person are grouped (« added 12 cards to
  Studios »; cards, messages, applications, Studios, projects, guests), 25 lines at most, redrawn only when it
  changes; layout: Online now + Emails stacked beside Live activity, charts, then Platform totals full width.
  `admin.css`: feed of fixed height (440 px) with its own scroll, online list capped. `lib/admin.php`: the feed
  sends 80 raw events so grouping still fills the list.
- Tests: `tests/studioma/admin.js` 103 checks and `e2e.js` 195 checks — passed; screenshots desktop and mobile
  (no horizontal scroll). Committed and pushed on this branch; not merged.

### 2026-11-20 — Claude Code — no deployment cron on the server
- Owner's terminal output: server clone `~/dehini-com` was on `claude/trusting-keller-rynk1j` at `1a498c7`, clean;
  `git pull` fast-forwarded it to `fed2b58`. `crontab -l` lists **no `dehini-com` deployment line** (only other
  sites' jobs). Live site had PR #69 images and PR #72 files, so earlier deployments were copied by hand.
- `cron.php` "Could not open input file": the file had not been copied to `public_html` yet (expected).
- Gave the owner the commands to return the clone to `main`, copy `studioma/` + `admin/index.html`, and add the
  deployment and Studioma cron jobs (optional, owner's decision).

### 2026-11-20 — Claude Code — admin merged but not deployed
- Owner merged PR #74 (`5a0b124`) at 23:15 UTC and ran the `cron.php` command by hand (output hidden by
  `>/dev/null`; the file was not on the server yet). Live check at 23:18–23:25 UTC: `/studioma/admin/`,
  `assets/admin.js`, `unsubscribe.php` → 404, `assets/app.js` → 200: the deployment cron did not copy `main`.
- This branch fast-forwarded to `origin/main`. No code change.

### 2026-11-20 — Claude Code — Studioma: platform administration
- Branch `claude/trusting-keller-rynk1j`, base `main` @ `1218970`; committed and pushed (commit after `56d6fc9`).
- Owner's request: « adminstration for studioma … reeltime stats and gestion accounts and anythink helped me and
  also the part of emailing ».
- Done: `studioma/admin/` (+ `assets/admin.{js,css}`) — Live overview (online now, DAU/WAU/MAU, today's activity,
  6 charts over 30 days, live feed, totals, email health; polls every 10 s), Accounts (search, filters, sort, CSV
  export, record drawer: usage counts, active days, emails received, admin history; actions: email the person,
  reset link, confirmation link / mark confirmed, sign out everywhere, disconnect AI, news opt-out, suspend /
  lift, delete), Moderation (posts, comments, opportunities, companies, case studies), Emails (campaigns with live
  preview, audiences, test send, batched sending with pause/resume/cancel/duplicate; email log; sending settings
  and test; cron status), System (versions, DB, storage, integrations, admin log).
- Backend: `studioma/lib/admin.php` (routes with access `admin` in `ROUTES`, `require_admin()` = `/admin`
  session + CSRF); DB **v6** (`users.suspended`/`suspend_note`, `activity_days`, `mail_log`, `campaigns`,
  `mail_queue`, `admin_log`); suspension enforced in `current_user()`, `start_session()`, `api_login`,
  `api_forgot`, `api_reset`, `bearer_user()` (MCP), hidden in profile/search/suggestions/talent/feed;
  `mail_send_logged()` logs every email; `send_mail()` accepts extra headers; `account_destroy()` extracted from
  `api_account_delete` (also fixes: a solo-owned company was deleted before a later check could refuse);
  `user_seen()` (last_seen every minute + activity_days); `studioma/unsubscribe.php`; `studioma/cron.php`; setting
  `email_news` (+ toggle in Settings); link from `/admin`.
- Files: `studioma/{api.php,cron.php,unsubscribe.php,config.example.php}`, `studioma/lib/{admin,core,auth,oauth,social,talent}.php`,
  `studioma/admin/index.html`, `studioma/assets/{admin.js,admin.css,app.js}`, `admin/index.html`,
  `tests/studioma/{admin.js,e2e.js,run.sh,setup.sh,README.md}`, `README.md` (Studioma § 7 Administration, sections
  renumbered 8–10), `AGENTS.md`, `docs/{DECISIONS,ROADMAP,PROJECT_CONTEXT}.md`.
- Decisions: S13–S17 in `docs/DECISIONS.md`.
- Tests: see « Validation at this checkpoint » above. UI checked by screenshots (desktop 1360 px, mobile 390 px,
  no horizontal scroll).
- Uncommitted / unpushed: nothing.
- Exact next step: owner opens/merges the PR, then the post-merge actions in « Next recommended action ».

### 2026-11-20 — Claude Code — server clone must stay on `main`
- The owner switched the server's deployment clone (`~/dehini-com` on `oort-shared`) to
  `claude/trusting-keller-rynk1j` and tried to start `codex` there (not installed). Live site unaffected: that
  branch had the same code as `main` after PR #73. Asked the owner to `git checkout main` there.
- Added the rule « never run agents or switch branches in the server clone » to `AGENTS.md` § 3,
  `docs/PROJECT_CONTEXT.md`, `docs/DECISIONS.md` (R10) and `docs/ROADMAP.md` (owner action).
- PR #73 (shared agent setup) was merged by the owner: `main` @ `1218970`. This branch was fast-forwarded to it.
- Docs only; no tests needed (no code changed).

### 2026-11-20 — Claude Code — shared Claude Code / Codex setup (this checkpoint)
- Added `AGENTS.md` (canonical shared instructions), `CLAUDE.md` (imports `@AGENTS.md`), `HANDOFF.md`,
  `docs/PROJECT_CONTEXT.md`, `docs/DECISIONS.md`, `docs/ROADMAP.md`, a read-only Claude Code SessionStart hook
  (`.claude/settings.json`, `.claude/hooks/session-start.sh`), and protective `.gitignore` patterns (env files,
  keys, tokens, local databases, dependencies, temp files). No tracked file became ignored.
- Fast-forwarded this branch to `origin/main` (`6aa8755`) first; nothing was lost (the branch had no commit
  outside `main`).
- No application code, UI, schema or API changed.
- Tests: Studioma 788 checks (5 suites), SWANBIDA 119, Maillage 181 — all passed; `php -l` (108 files) and
  `node --check` (47 files) clean. Hook validated: exits 0 in ~20 ms, changes nothing, survives a bad path.

### 2026-11-20 — Claude Code — Studioma: Meta Pixel + Conversions API (`1a498c7`, PR #72, live)
- `studioma/lib/meta.php`, `studioma/assets/meta.js`, hooks in `api.php`, `lib/auth.php` (`_meta` in responses),
  `lib/core.php` (config), CSP in `studioma/.htaccess`, tests `tests/studioma/meta.js` + e2e checks.
- Dataset `1718566156353795`; token read from `~/studioma-data/meta-token.txt` (not in Git). Event list and
  privacy rules: README « Studioma › 6 », `docs/DECISIONS.md` S10–S12.

### 2026-11-20 — Claude Code — Studioma: guest sharing + secure links (`7427e19`, `241ccdf`, PR #71, live)
- Guests without account: `studioma/lib/share.php`, `GUEST_ROUTES` in `studioma/api.php`, hooks in `access.php`,
  `studio.php`, `cards.php`, `files.php`; UI `assets/guest.js`, Share dialog in `assets/studio.js`; DB schema v5.
- Clean links `https://dehini.com/studioma/s/<key>` via `studioma/s.php`; tests `tests/studioma/share.js`.

### 2026-11-20 — other session — « Projets » showcase of the platforms (`3f8e9f5`, PR #69, live)
- `index.html` (`SHOWCASE`), `img/projets/*.webp`, `tools/vitrine/captures.js`.

### 2026-11-17 — Claude Code — Studioma MCP server, 44 tools (`f9e8ef1`, PR #68, live)

## Template for the next entry

```
### YYYY-MM-DD — <Claude Code | Codex> — <task>
- Branch / base commit / checkpoint commit (or "uncommitted"):
- Done:
- Files changed:
- Decisions (also add to docs/DECISIONS.md):
- Tests run and results:
- Uncommitted / unpushed:
- Blockers / open questions:
- Exact next step:
```
