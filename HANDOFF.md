# HANDOFF — read first, update last

Shared memory between **Claude Code** and **OpenAI Codex** (protocol: `AGENTS.md` § 6). Keep the « Current
state » block short and true; add a log entry at the top of « Log » after every meaningful task. Never write
secrets here. Mark anything you could not verify as *(unverified)*.

## Current state — 2026-11-21

| | |
|---|---|
| Last agent | Claude Code (cloud session) |
| Branch | **`claude/trusting-keller-rynk1j`** (tracks `origin/claude/trusting-keller-rynk1j`), restarted from `origin/main` @ `e8f118b` (its earlier PR #79 was merged). No pull request yet for this work (open one only when the owner asks) |
| Base code | `main` @ `e8f118b` = Merge PR #80 (Share & publish, DB v10) on top of PR #79 (billing v9). **`main` = production** — no deployment cron: the owner copies `main` by hand. Live check 2026-11-21: billing files answer 200 (PR #79 copied), `studioma/assets/publish.js` answers 404 (PR #80 **not** copied yet) |
| Checkpoint | the commit that last changed this file: `git log -1 --format='%H %s' -- HANDOFF.md` |
| Uncommitted work at checkpoint | none |
| In progress | **Studioma launch offer** — 30 days of full (Team) access without a card, « invite 10 friends, get 1 year free », Refer & Earn, pricing / landing / banners, admin analytics. Implemented, tested, committed and pushed on `claude/trusting-keller-rynk1j`; **not merged, not deployed** |
| Last task changed application code? | **yes** — Studioma, DB schema 10 → 11 (additive) |
| Codex access | **not verified** — Codex has not been run on this repository from this session |

**Start here (Codex or Claude)**

1. `git fetch origin`, then `git switch claude/trusting-keller-rynk1j` (or `git switch -c codex/<topic> origin/claude/trusting-keller-rynk1j`).
   Production is `origin/main` @ `e8f118b`.
2. Read README « Studioma › 10 » and decisions S28–S30 before touching the offer; S25–S27 for Share & publish;
   `docs/billing.md` for billing.
3. Checks: `AGENTS.md` § 5 (lint, then `tests/studioma/run.sh`, ~13 min, 11 suites).

**Done on this branch, not in `main`** (commits `04e8561` backend, then the UI/docs commit):
- `studioma/lib/promo.php` (new): trial (signup or one-time claim, never over a paid/offered Team), referral codes,
  clicks + unique visitors (`stm_vid` cookie hash, no IP), signed 30-day first-click attribution (`stm_ref`),
  verification hooks, self-referral and abuse rules (admin review), 12-month reward once (after a running trial),
  `api_promo`, `api_promo_claim`, `api_ref_click`, `api_admin_promo`, `api_admin_promo_review`.
- Entitlements: `account_plan()` reads `subscriptions` rows with provider `promo` (period_start ≤ now < period_end)
  — `studioma/lib/billing.php`; `billing_plans` exposes `promo`; constants in `studioma/lib/plans.php`; DB v11 in
  `studioma/lib/core.php` (`ref_codes`, `ref_clicks`, `referrals`, `promo_grants`).
- Hooks: signup and Google sign-up (`studioma/api.php`, `studioma/lib/google.php`), every place an email becomes
  verified (confirm link, password reset, Google link/sign-in, admin), `me_view()` returns `promo`
  (`studioma/lib/auth.php`); admin billing metrics count the offer apart (`studioma/lib/admin.php`).
- UI: `studioma/assets/promo.js` (banners, Refer & Earn `#/refer`, claim, `?ref=` in the app), pricing and Plan &
  billing (`studioma/assets/billing.js`), signup note and nav (`studioma/assets/app.js`), banners on Home and Projects
  (`social.js`, `studio.js`), landing (`studioma/index.html`, `assets/landing.js`, `assets/landing.css`), admin
  « Launch promotion » (`studioma/assets/admin.js`), `app.css`, `app/index.html`. Also fixed: the logged-out header
  overflowed on phones because of the « Community » link added in PR #80 (now hidden below 520 px).
- Config: `STM_LAUNCH_PROMO` (default true), `STM_REFERRAL_SAME_IP_MAX` (3), `STM_REFERRAL_BURST_MAX` (10) in
  `studioma/config.example.php`.
- Tests: `tests/studioma/promo.js` (76 checks), `tests/studioma/promo-e2e.js` (44 checks), `setup.sh` `PROMO=1`
  (other suites run with the offer off), `run.sh`; `admin.js` / `billing.js` expect schema 11.
- Docs: README « Studioma › 10 » (sections renumbered 11–13), DECISIONS S28–S30, ROADMAP, PROJECT_CONTEXT, AGENTS.md
  map, tests README.

**Unfinished / waiting**

- Owner: copy `main` (PR #80) to the live site if wanted; review this branch, ask the agent for a pull request,
  merge, deploy. The database migrates to v11 by itself; the offer is on by default.
- Note for the owner: billing (PR #79) is live; unless `STM_PLANS_ENFORCED` is false on the server, Free limits apply
  to accounts without the trial *(server config unverified)*. Once this branch is live, new accounts get the trial
  automatically and existing accounts can claim it once.
- Not done: Share & publish / offer through the MCP server; consent banner for the referral cookies; other languages.
- Carried over: Paddle sandbox test and live setup (`docs/billing.md` § 8), owner's Paddle choices **not answered**;
  Terms / Privacy / Refund pages and a public contact address **missing** (the offer makes them more important);
  Maillage test « stock enregistré » fails also on unmodified `main`.

**Next recommended action**

1. **Owner**: review the branch (pricing page, landing, Refer & Earn), then ask for the pull request and merge.
2. **Agent, next task**: Terms of Service / Privacy Policy / Refund Policy pages, now also covering the launch offer,
   referrals and their cookies (ask the owner for the legal name and contact email).

**Blockers**: none for the launch offer. Billing: Paddle approval and credentials (owner).

**Validation at this checkpoint** (2026-11-21, branch `claude/trusting-keller-rynk1j`): `tests/studioma/run.sh` —
11 suites, **0 failures**: api 239, share 144, meta 73, mcp 155, admin 116, billing 107, **promo 76**, **promo-e2e 44**,
publish 130, publish-e2e 37, e2e 202 (+ 15 config unit checks). The full run stopped once in `e2e.js` on a class name
I had changed by mistake in the admin Accounts table; restored, `e2e.js` re-run alone: passed. `php -l` 119 PHP files
and `node --check` 29 JS files: 0 errors. SWANBIDA and Maillage not re-run (no file of theirs changed).

## Log (newest first)

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
