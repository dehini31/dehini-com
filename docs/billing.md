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
   payments. They usually require on `dehini.com`: a pricing page (`/studioma/app/#/pricing` exists), **Terms of
   Service, Privacy Policy and Refund Policy pages** (do not exist yet — to write), and contact details. Approval
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
   price ids. The admin › Billing page lists any missing setting by name.
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
- The Paddle API calls could not be tested against real Paddle from the development environment (no credentials
  and no network access to Paddle): they follow Paddle Billing API v1 and are tested against a fake API with the
  same shapes *(real-Paddle behaviour unverified until the sandbox test in step 7)*.
