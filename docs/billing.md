# Studioma — plans, subscriptions and PayPal billing

Status on 2026-11-23: **PayPal replaces Paddle** (owner's decision, 2026-11-23 — decision S41). Implemented and
tested in the code against a fake PayPal API; **online payment closed** until the owner finishes the PayPal setup
below (§ 5) and the sandbox test (§ 8). Plan limits are enforced as soon as the code is deployed (switch:
`STM_PLANS_ENFORCED`). Paddle never went live (no live product, price or customer), so nothing had to be migrated;
its code was removed (it stays in Git history, up to commit `666e21c`).

## 1. Architecture (what exists)

| Piece | File | Role |
|---|---|---|
| Plan catalogue | `studioma/lib/plans.php` | prices, limits, features and the bullets shown on the pricing page — single source of truth |
| Entitlements | `studioma/lib/billing.php` | plan of an account, usage, server-side checks, upgrade errors (`plan_limit`, HTTP 402), PayPal settings and their consistency check |
| PayPal client + webhooks | `studioma/lib/paypal.php`, `studioma/paypal-webhook.php` | OAuth token, subscription creation, confirmation, plan change (« revise »), cancellation, sync, payments, verified notifications, plan setup for the admin |
| Return page | `studioma/pay/` + `assets/pay.js` | where PayPal sends the payer back; forwards to the app (strict CSP, **no third-party script**) |
| UI | `assets/billing.js` | `#/pricing`, `#/settings/billing` (confirmation after PayPal, cancel, change, « Manage in PayPal »), upgrade prompt, Insights gate |
| Admin | `lib/admin.php` › `api_admin_billing`, `lib/paypal.php` › `api_admin_paypal_setup`, `assets/admin.js` › Billing | metrics, subscriptions, notifications, webhook address, « Create the PayPal plans », complimentary plans |
| Data | `lib/core.php` › `migrate()` | v9: `subscriptions`, `billing_events`, `billing_transactions`, `usage_counters` (`billing_customers` stays, unused by PayPal); **v14**: `subscriptions.prev_plan`, `prev_until` |
| Tests | `tests/studioma/billing.js`, `tests/studioma/fake-paypal.php`, `tests/studioma/billing-config.php` | 141 checks with a fake PayPal API (OAuth, subscriptions, revise, cancel, webhook verification, approval page) + 13 config checks |

**Who pays:** the account that owns a project (`projects.owner_uid`). Everything in its projects — Studios, cards
created by any member, guest or AI assistant, members, share links — counts against that account's plan. If the
owner deletes their account, another owner of the project becomes the billing account. Guests (share links) never
count as members and never pay. No second role system: the existing roles of `lib/access.php` are reused; a plan
only caps them.

**Who sells:** with PayPal, **Studioma (operator: Mohammed Dehini) is the seller**; PayPal only processes the
payment. Paddle was a merchant of record (it sold, collected tax and issued invoices); PayPal is not. Owner's
choices (2026-11-23): **prices are final, no tax added** (any tax obligation of the seller, for example EU VAT on
digital services sold to EU consumers, is the owner's to handle — ask an accountant); **an upgrade applies right
away** (see § 4).

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
  an upgrade prompt.
- **Over the limits** (after a downgrade, a cancellation, or for accounts that existed before plans): nothing is
  deleted or archived; everything stays readable; creating more is refused until the account is back within the
  limits or upgrades. Members beyond the plan's seats keep **read-only** access; guests of a Free account are
  read-only; existing extra links keep working view-only but cannot be turned back on.
- The plan comes **only** from verified data: PayPal's answer to a server-to-server API call (`GET
  /v1/billing/subscriptions/{id}`), a webhook whose signature PayPal itself verified, or a complimentary grant by the
  admin. Nothing the browser sends can change it; the return from PayPal activates nothing by itself.

## 4. Subscription lifecycle (PayPal Subscriptions API v1, server-side, no PayPal script on Studioma)

- **Subscribe** (Free → Solo/Team): `billing_checkout` creates the subscription at PayPal (`POST
  /v1/billing/subscriptions` with the plan `P-…`, `custom_id = stm:<account id>`, the account email, return and
  cancel URLs on `studioma/pay/`, no shipping), stores a **`pending`** row (no access) and sends the browser to
  PayPal's approval link. The payer pays on paypal.com (PayPal account, or card where PayPal offers it). PayPal sends
  them back to `studioma/pay/?paypal=return…`, which forwards to `#/settings/billing?checkout=return`; the app calls
  `billing_confirm`, the server reads the subscription at PayPal and activates the plan when PayPal says `ACTIVE`.
  The `BILLING.SUBSCRIPTION.ACTIVATED` webhook does the same if the payer closes the window. Abandoned checkouts
  stay `pending` (never counted anywhere). A second checkout is refused while a subscription is active, and while a
  canceled one is still paid (no double payment for the same period).
- **Change** (Solo ↔ Team, monthly ↔ yearly): `POST /v1/billing/subscriptions/{id}/revise` with the new plan.
  PayPal usually asks the payer to approve on PayPal (approval link → same return path); a payer with a saved card
  may not need to (then the change is read back at once). **PayPal does not prorate**: the new price is charged from
  the next billing date. Owner's choice: an **upgrade unlocks the bigger plan as soon as PayPal reports the new plan**
  (the rest of the current period is a free upgrade — up to a year on a yearly plan); a **downgrade keeps the plan
  already paid until the end of the period** (`prev_plan`, `prev_until`), then the lower plan applies. All four
  plans must belong to the **same PayPal product** (revise only works within one product) — the admin setup does that.
- **Cancel**: `POST /v1/billing/subscriptions/{id}/cancel`. At PayPal this is final (no more payments, cannot be
  restarted). Studioma keeps the plan until the paid-through date (`cancel_at` = last `next_billing_time`), then
  Free. The member can subscribe again from that date. Members can also cancel in their PayPal account (automatic
  payments): the `BILLING.SUBSCRIPTION.CANCELLED` webhook handles it the same way.
- **Payment failure**: PayPal retries; while `failed_payments_count > 0` the subscription is `past_due` in
  Studioma, which keeps the paid plan for **14 days** (`STM_PAST_DUE_GRACE_DAYS`) and shows a warning. After 3
  failures PayPal suspends the subscription (`payment_failure_threshold` of the plans) → `paused` → Free until
  PayPal reactivates it.
- **Payments**: `PAYMENT.SALE.COMPLETED` (paid), `PAYMENT.SALE.REFUNDED` / `REVERSED` (status updated on the same
  payment; a late « completed » never overwrites a refund), `BILLING.SUBSCRIPTION.PAYMENT.FAILED` (failed line).
- **Webhooks** (`studioma/paypal-webhook.php`): every notification is checked by PayPal
  (`POST /v1/notifications/verify-webhook-signature` with `STM_PAYPAL_WEBHOOK_ID`; the body is forwarded byte for
  byte); a certificate URL outside `paypal.com` is refused; each event `id` is processed once (`billing_events`);
  for subscription events the subscription is **read again at PayPal**, so out-of-order events cannot roll it back;
  unknown subscription, account or plan → logged as `unmatched`, no access given; PayPal unreachable → HTTP 503 and
  processing errors → HTTP 500, so PayPal sends it again.
- **Metrics** (admin › Billing): one subscription per account (its best), MRR = monthly price or yearly price / 12
  (Studioma price of the plan), ARR = MRR × 12; a canceled subscription still in its paid period counts as a
  subscriber but not in the MRR; live and sandbox kept apart; complimentary plans and `pending` rows excluded.
- **Account deletion** is refused while a PayPal subscription is active (member or admin): PayPal would keep
  charging a deleted account. Cancel first (the plan stays until the end of the paid period), then delete.
- **Refunds** (Refund Policy, `/studioma/refunds/`): the owner refunds from the PayPal business account (Activity
  › the payment › Refund) **and cancels the subscription** if the refund ends it (a refund does not cancel a PayPal
  subscription by itself). The webhook marks the payment « refunded ».

## 5. PayPal setup (owner — required before payments)

Secrets go **only** into `studioma/config.php` on the server (git-ignored) — never into Git, chat, email or
screenshots. Names and comments: `studioma/config.example.php`.

1. **Business account able to receive payments.** Check in the live PayPal account that it can **receive** payments
   and withdraw them (PayPal limits some countries to sending only; whether an account registered in Algeria can
   receive is *unverified* — confirm with PayPal before going live).
2. **App credentials** (developer.paypal.com › Apps & Credentials, **Sandbox** tab first): the app's Client ID →
   `STM_PAYPAL_CLIENT_ID`, its Secret → `STM_PAYPAL_SECRET`, `STM_PAYPAL_ENV` = `'sandbox'`. Credentials pasted into a
   chat must be **replaced** (app › Secret key › add a new secret, then disable the old one) before use.
3. **Webhook** (same app › Webhooks › Add webhook): URL `https://dehini.com/studioma/paypal-webhook.php`, events
   *Billing subscription activated, cancelled, created, expired, payment failed, re-activated, suspended, updated*
   and *Payment sale completed, refunded, reversed*. Copy the webhook **ID** into `STM_PAYPAL_WEBHOOK_ID` (an ID,
   not a secret).
4. **Plans**: in Studioma's admin › Billing, click **Create the PayPal plans** (appears once the client ID and secret
   are set and plans are missing). It creates the product « Studioma » and four plans — Solo $12/month and
   $120/year, Team $29/month and $290/year, USD, no tax, renewed until canceled, suspended after 3 failed payments —
   and shows four `define('STM_PAYPAL_PLAN_…', 'P-…');` lines to paste into `config.php`.
5. `define('STM_BILLING_ENABLED', true);` — admin › Billing must then show « Online payment is on (PayPal sandbox) »
   and no « settings to fix ».

## 6. Existing accounts and deployment

- Migration v9 created the billing tables with **no subscription rows**; v14 only adds two columns. Every existing
  account is on Free until it subscribes or the admin offers a plan.
- With `STM_PLANS_ENFORCED` true (default), existing accounts above the Free limits keep everything readable but
  cannot create more. **Before deploying, decide per account**: offer Solo or Team from admin › Accounts › account ›
  Plan to the accounts that should keep collaborating — or set `STM_PLANS_ENFORCED` false until payments open.
- Deploying this code with no PayPal settings is safe: payment stays closed (« Coming soon » / launch offer).

## 7. Known limitations

- **Not tested against real PayPal yet** (no network access to PayPal from the development environment, and the
  pasted credentials were deliberately not used): the code follows PayPal's REST API v1 documentation and is tested
  against a fake API with the same shapes. To confirm in the sandbox (§ 8): `application_context` (marked
  « deprecated » in PayPal's reference but still the documented way to set return URLs for subscriptions), the
  query parameters PayPal adds to the return URL (Studioma does not depend on them), and that `plan_id` changes as
  soon as the payer approves a revision *(unverified)*.
- No proration (PayPal); one currency (USD); no coupons; no tax added; no invoices from Studioma (PayPal sends
  receipts). If invoices or tax handling are needed, a merchant of record (like Paddle) or an invoicing tool is
  required.
- No automatic email from Studioma about billing (PayPal sends receipts and payment-failure emails).
- A canceled subscription cannot be resumed (PayPal rule); the member subscribes again after the paid period.

## 8. Sandbox → live

Rule: **live is configured only after the sandbox test has passed.** Never put live credentials anywhere but
`config.php` on the server.

### 8.1 Sandbox test (owner, on a staging copy or on the live site with `STM_PAYPAL_ENV` = sandbox)

1. Settings of § 5 with the **sandbox** app; admin › Billing shows payment on (sandbox).
2. developer.paypal.com › Testing tools › Sandbox accounts: use the **Personal** sandbox account (buyer) — never a
   real card.
3. Sign in to Studioma with a test account → Pricing → Choose Solo → approve on the PayPal sandbox page → back on
   Studioma: « Plan & billing » shows Solo, « paid with PayPal ». admin › Billing (Sandbox) lists the subscription and
   the notifications `BILLING.SUBSCRIPTION.ACTIVATED` and `PAYMENT.SALE.COMPLETED` as « processed ».
4. Upgrade to Team (approve on PayPal) → Team at once. Downgrade to Solo → Team kept until the renewal date.
5. Cancel → « ends on … ». Refund the sandbox payment from the sandbox business account → payment shows « Refunded ».
6. Abandon a checkout (Cancel on the PayPal page) → back to pricing, « nothing was charged ».

### 8.2 Go-live checklist

1. The live account can receive payments (§ 5.1); the legal name on the pages (`STM_LEGAL_NAME`) matches it.
2. Live app credentials (Live tab), **live** webhook (its own ID), live plans created with « Create the PayPal
   plans » while `STM_PAYPAL_ENV` = `'live'`.
3. `config.php` switched in one edit: `STM_PAYPAL_ENV` `'live'` + live client ID, secret, webhook ID and four plan
   IDs; admin › Billing shows « Online payment is on (PayPal live) » and no « settings to fix ».
4. No real payment is taken to « test » live: the first live payment is a real customer's (or the owner's own,
   refunded from PayPal if wanted — owner's decision).
