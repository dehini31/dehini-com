# Studioma — plans, subscriptions and Paddle billing

Status on 2026-11-21: **implemented and tested in the code; online payment closed** until the owner finishes the
Paddle setup below. Plan limits are enforced as soon as the code is deployed (switch: `STM_PLANS_ENFORCED`).

## 1. Architecture (what exists)

| Piece | File | Role |
|---|---|---|
| Plan catalogue | `studioma/lib/plans.php` | prices, limits, features and the bullets shown on the pricing page — single source of truth |
| Entitlements | `studioma/lib/billing.php` | plan of an account, usage, server-side checks, upgrade errors (`plan_limit`, HTTP 402) |
| Paddle client + webhooks | `studioma/lib/paddle.php`, `studioma/paddle-webhook.php` | checkout transaction, plan change, cancel/resume, customer portal, signed notifications |
| Payment page | `studioma/pay/` + `assets/pay.js` | opens Paddle's hosted checkout (only page that loads Paddle.js, own CSP) |
| UI | `assets/billing.js` | `#/pricing`, `#/settings/billing`, upgrade prompt, Insights gate |
| Admin | `lib/admin.php` › `api_admin_billing`, `assets/admin.js` › Billing | metrics, subscriptions, notifications, complimentary plans |
| Data (schema v9) | `lib/core.php` › `migrate()` | `subscriptions`, `billing_customers`, `billing_events`, `billing_transactions`, `usage_counters` |
| Tests | `tests/studioma/billing.js`, `tests/studioma/fake-paddle.php` | 106 checks with a fake Paddle API and signed webhooks |

**Who pays:** the account that owns a project (`projects.owner_uid`). Everything in its projects — Studios, cards
created by any member, guest or AI assistant, members, share links — counts against that account's plan. If the
owner deletes their account, another owner of the project becomes the billing account. Guests (share links) never
count as members and never pay. No second role system: the existing roles of `lib/access.php` are reused; a plan
only caps them.

## 2. Plans

| | Free | Solo | Team |
|---|---|---|---|
| Price | $0 | $12 / month or $120 / year | $29 / month or $290 / year |
| Projects (active) | 1 | 3 | 10 |
| Studios (active, all projects) | 1 | 5 | 20 |
| New cards per calendar month | 30 | unlimited (fair use 5,000) | unlimited (fair use 5,000) |
| Registered members incl. owner | 1 | 1 | 5 (pending invitations reserve a place) |
| Active guest links | 2, view-only | unlimited (1 public + 1 private per Studio) | same |
| Private links, guests added by email | — | yes | yes |
| Guests comment / approve / edit, edit requests | — (view-only) | yes | yes |
| Approval requests (members and guests) | — | yes | yes |
| Team invitations and roles | — | — | yes |
| Studio Insights | — | — | yes |

Archived projects and Studios do not count; restoring one counts again. Cards: a counter per account and month
(`usage_counters`) incremented when a card is created or duplicated, never decremented — deleting cards does not
give the allowance back, and the counter of a new month starts at zero. No card is ever deleted.

## 3. Enforcement rules (server-side, every entry point: API, MCP server, guest links)

- Creation checks run **inside the same write transaction** (`BEGIN IMMEDIATE`) as the insert, so simultaneous
  requests cannot exceed a limit (tested: 8 parallel projects → 1 created; 10 parallel cards at 25/30 → 5).
- Refusals return `{"error":"plan_limit","message","upgrade":"solo|team","feature"}` with HTTP 402; the app shows
  an upgrade prompt. Messages: see `lib/billing.php` (e.g. « You've reached your Free plan limit. Upgrade to Solo
  to manage more projects. »).
- **Over the limits** (after a downgrade, a cancellation, or for accounts that existed before plans): nothing is
  deleted or archived; everything stays readable; creating more is refused until the account is back within the
  limits or upgrades. Members beyond the plan's seats (by order of arrival, owner first) keep **read-only** access
  (`seat_cap()` in `access.php`); guests of a Free account are read-only; existing extra links keep working
  view-only but cannot be turned back on.
- The plan comes **only** from verified data: a Paddle webhook with a valid signature, or Paddle's answer to a
  server-to-server API call, or a complimentary grant by the admin. Nothing the browser sends can change it; the
  checkout return URL activates nothing.

## 4. Subscription lifecycle

- **Subscribe** (Free → Solo/Team): `billing_checkout` creates (or finds) the Paddle customer of the account's
  email, creates a transaction with `custom_data.stm_uid`, and sends the browser to `studioma/pay/?_ptxn=…`.
  Paddle's `subscription.created` webhook activates the plan. A second checkout is refused while a subscription
  exists (use change instead).
- **Change** (Solo ↔ Team, monthly ↔ yearly): `PATCH /subscriptions/{id}` with Paddle's proration —
  `prorated_immediately` for an upgrade (or monthly → yearly), `prorated_next_billing_period` for a downgrade.
- **Cancel**: `POST /subscriptions/{id}/cancel` with `effective_from: next_billing_period` — the plan stays until the
  paid-through date, then Paddle sends `subscription.canceled` → Free. **Keep my subscription** removes the
  scheduled cancellation.
- **Payment failure**: Paddle sets `past_due` and retries (dunning). Studioma keeps the paid plan for **14 days**
  after the first `past_due` (`STM_PAST_DUE_GRACE_DAYS`), shows a warning, then applies Free until Paddle reports
  the subscription active again. Paused subscriptions give no paid features.
- **Webhooks**: signature `Paddle-Signature: ts=…;h1=…` (HMAC-SHA256 of `ts:body`, 5-minute tolerance); each
  `event_id` processed once (`billing_events`); an event older than the stored `updated_at` is ignored
  (out-of-order); unknown customer or price → logged as `unmatched`, no access given; processing errors → HTTP 500
  so Paddle retries. Duplicate active subscriptions for one account are flagged in the admin.
- **Metrics** (admin › Billing): one subscription per account (its best), MRR = monthly price or yearly price / 12
  from Paddle's verified amounts, ARR = MRR × 12; live and sandbox kept apart; complimentary plans excluded.

## 5. Paddle setup (owner — required before payments)

1. **Paddle account and approval**: sign up at paddle.com; Paddle reviews the business and the website before live
   payments. They usually require on `dehini.com`: a pricing page (`/studioma/pricing/` and `/studioma/app/#/pricing`),
   **Terms of Service, Privacy Policy and Refund Policy pages** (`/studioma/terms/`, `/studioma/privacy/`,
   `/studioma/refunds/` — README « Studioma › 12 », decision S40), and contact details (contact@dehini.com). The
   legal name on the pages (`STM_LEGAL_NAME`) must match the Paddle account. Approval
   and payouts are not guaranteed and are outside this code.
2. **Sandbox first** (sandbox-vendors.paddle.com): create two products — *Studioma Solo*, *Studioma Team* — with
   four recurring prices in USD: $12/month, $120/year, $29/month, $290/year. Copy the four `pri_…` ids.
3. Developer tools › Authentication: create an **API key** (server, secret) and a **client-side token** (public).
4. Checkout › Checkout settings: set the **default payment link** to `https://dehini.com/studioma/pay/` and approve
   the domain `dehini.com` (Paddle asks for it before hosted checkouts work).
5. Developer tools › Notifications: new destination `https://dehini.com/studioma/paddle-webhook.php`, events
   `subscription.created`, `subscription.updated`, `subscription.activated`, `subscription.canceled`,
   `subscription.past_due`, `subscription.paused`, `subscription.resumed`, `subscription.trialing`,
   `transaction.completed`, `transaction.paid`, `transaction.payment_failed`, `transaction.past_due`. Copy its
   **secret key**.
6. On the server, add to `~/public_html/studioma/config.php` (never in Git; template in `config.example.php`):
   `STM_BILLING_ENABLED` true, `STM_PADDLE_ENV` `'sandbox'`, the API key, client token, webhook secret and the four
   price ids (variable names and placeholders: `.env.example`). The admin › Billing page lists any missing setting
   by name, and any inconsistent one (« Paddle settings to fix »), without showing values.
7. Test with a Paddle test card in sandbox: subscribe, upgrade, downgrade, cancel, keep; check admin › Billing
   (Sandbox) and the Paddle notifications log.
8. **Live**: repeat steps 2–5 in the live Paddle dashboard (new ids, new key, new secret), set
   `STM_PADDLE_ENV` `'production'`. Sandbox subscriptions never count in production (and vice versa).

Without step 6, the pricing page shows paid plans as « Coming soon » and every payment endpoint answers
`billing_unavailable` (503); the Free plan works normally.

## 6. Existing accounts and deployment

- Migration v9 is additive: it creates the billing tables and **no subscription rows**. Every existing account is on
  Free until it subscribes or the admin offers a plan. Tested: a schema-8 database with data upgrades to 9 with
  projects, Studios, cards and accounts unchanged.
- With `STM_PLANS_ENFORCED` true (default), existing accounts above the Free limits keep everything readable, but
  cannot create more, members beyond 1 seat become read-only, and guests read-only. **Before deploying, decide per
  account**: offer Solo or Team from admin › Accounts › account › Plan (free, optional end date, not counted as
  revenue) to the accounts that should keep collaborating — or set `STM_PLANS_ENFORCED` false until payments open.

## 7. Known limitations

- No free trial, coupons or per-seat pricing; one currency (USD) as configured in Paddle.
- Paddle Retain, tax/VAT details and invoices are Paddle's (customer portal); Studioma stores only amounts and states.
- No automatic email from Studioma about billing (Paddle sends receipts and dunning emails).
- The Paddle API calls have not been tested against real Paddle yet (no sandbox credentials in the development
  environment, and the code is not deployed): they follow Paddle Billing API v1 and are tested against a fake API
  with the same shapes *(real-Paddle behaviour unverified until the sandbox test of § 8)*.

## 8. Sandbox → live migration

Rule: **live is configured only after the sandbox test (8.2) has passed.** Never delete, archive or recreate a live
product, price, discount or notification destination, never rotate a signing secret, never touch live customers or
subscriptions; keys and secrets only in `config.php` on the server (names: `.env.example`).

### 8.1 Safety guard in the code

`billing_config_check()` (`lib/billing.php`) keeps payment **closed** when the settings mix environments: a
`pdl_sdbx_` API key or `test_` client token with `STM_PADDLE_ENV` `production` (or the reverse), an environment
value other than exactly `sandbox` / `production` (« live » would otherwise be read as sandbox), an API key pasted as
webhook secret or client token, a price id that is not `pri_…` or used twice, `http://` site URL in production.
Admin › Billing lists the problems by setting name, never by value. Unit test: `tests/studioma/billing-config.php`.
Subscriptions are stored with their environment and a sandbox subscription never grants a plan in production.

### 8.2 Sandbox end-to-end test on a staging copy (owner, on the server)

A staging copy with **its own data folder** lets Paddle reach a real webhook without touching production. Do not
check out the branch in `~/dehini-com` (it must stay on `main`, clean):

```bash
git -C ~/dehini-com fetch origin claude/trusting-keller-rynk1j
mkdir -p ~/stm-staging-src && git -C ~/dehini-com archive FETCH_HEAD studioma | tar -x -C ~/stm-staging-src
cp -r ~/stm-staging-src/studioma ~/public_html/studioma-staging && mkdir -p ~/studioma-staging-data
```

Then create `~/public_html/studioma-staging/config.php` (never in Git) with **`STM_DATA_DIR`
`'/home/dehini91/studioma-staging-data'`** (without it the copy would open the production database),
`STM_SITE_URL` `'https://dehini.com/studioma-staging/'`, `STM_META_PIXEL_ID` `''` (no test events in the Meta
dataset), `STM_BILLING_ENABLED` true, `STM_PADDLE_ENV` `'sandbox'` and the sandbox key, client token, webhook secret
and four price ids. In the **sandbox** dashboard: default payment link `https://dehini.com/studioma-staging/pay/`,
notification destination `https://dehini.com/studioma-staging/paddle-webhook.php` (events of § 5 step 5).

Pass criteria (Paddle test card `4242 4242 4242 4242`, any future date, any CVC; check admin › Billing › Sandbox
of the staging copy and Paddle › Notifications log, every delivery answered 200):

| # | Step | Expected |
|---|---|---|
| 1 | Free account → Pricing → Solo monthly → pay | `transaction.completed` + `subscription.created/activated` delivered, signature accepted; plan Solo within seconds |
| 2 | `curl -i -X POST -H 'Paddle-Signature: ts=1;h1=bad' -d '{}' https://dehini.com/studioma-staging/paddle-webhook.php` | 401, nothing changes |
| 3 | Same event replayed from Paddle (Notifications › replay) | 200, applied once (idempotent) |
| 4 | Upgrade Solo → Team | prorated immediately, plan Team, `subscription.updated` |
| 5 | Downgrade Team → Solo | scheduled at next billing period, plan stays Team until then |
| 6 | Cancel, then « Keep my plan » | cancellation scheduled, then removed |
| 7 | Cancel and let it end (or cancel immediately in the sandbox dashboard) | back to Free, data kept read-only above limits |
| 8 | A declined and a 3-D Secure test card (Paddle docs › test card numbers) | no plan without a verified payment; a failed renewal gives `past_due` → 14-day grace |
| 9 | Customer portal link | opens Paddle's portal for that customer |

Clean up afterwards: `rm -rf ~/public_html/studioma-staging ~/stm-staging-src` (keep or delete
`~/studioma-staging-data`).

### 8.3 Live audit (read-only, 2026-11-21, through the Paddle connector — production, read-only scopes)

| Item | Live state | Action |
|---|---|---|
| Products / prices / discounts | none | create after 8.2 passes (same names, amounts, intervals as sandbox) |
| Notification destinations | none | create `https://dehini.com/studioma/paddle-webhook.php` (events of § 5) |
| API keys / client tokens | none | create after 8.2 passes; put in `config.php` only |
| Default payment link / checkout domains | not set / none | `https://dehini.com/studioma/pay/`, domain `dehini.com` approved by Paddle |
| Website on the account | `https://dehini.com/studioma/` | OK |
| Tax mode | `location` (Paddle default) | owner's choice (see below) |
| Balance currency | USD | matches USD prices |
| Payment methods enabled | card, PayPal, Apple Pay, Bancontact | owner's choice (Google Pay available, off) |
| Statement descriptor | `STUDIOMA` | OK |
| Onboarding verification | in progress (account setup step), automated review pending | owner, in the dashboard |

Nothing exists in live to reuse yet: every live entity is to be created once, after the sandbox test.

### 8.4 Sandbox → live ID mapping (fill in; ids are not secret, keys are)

| Entity | Sandbox id | Live id |
|---|---|---|
| Product « Studioma Solo » | | |
| Product « Studioma Team » | | |
| Price Solo monthly ($12) → `STM_PADDLE_PRICE_SOLO_MONTHLY` | | |
| Price Solo yearly ($120) → `STM_PADDLE_PRICE_SOLO_YEARLY` | | |
| Price Team monthly ($29) → `STM_PADDLE_PRICE_TEAM_MONTHLY` | | |
| Price Team yearly ($290) → `STM_PADDLE_PRICE_TEAM_YEARLY` | | |
| Notification destination (`ntfset_…`) | | |
| Client-side token (id only, `ctkn_…`) | | |

### 8.5 Go-live checklist (after 8.2 passes)

1. Legal pages online and linked from the pricing page and footer: Terms of Service, Privacy Policy, Refund Policy
   (Paddle named as merchant of record), contact email — required by Paddle's website review. **Written** (decision
   S40); online once the branch is deployed.
2. Live products and prices created with the same amounts/intervals as sandbox; mapping table filled.
3. Live notification destination, API key, client token; default payment link; `dehini.com` approved.
4. `config.php` on the server switched in one edit: `STM_PADDLE_ENV` `'production'` + live key, token, secret,
   price ids; admin › Billing shows « Online payment is on (Paddle production) » and no « settings to fix ».
5. No real payment is taken to « test » live: the first live payment is a real customer's (or the owner's own,
   refunded from the dashboard if needed — owner's decision).
