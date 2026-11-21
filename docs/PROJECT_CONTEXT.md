# Project context

Shared reference for coding agents (Claude Code, OpenAI Codex). Facts below were verified in the code, the
README, Git history and read-only checks of the live site on **2026-11-20**; anything not verified is marked
*(unverified)*. The detailed human documentation, in French, is `README.md` (one section per project).

## Purpose

`dehini.com` is the personal site of Mohammed Dehini and the home of several web platforms he builds and runs,
each published under its own path on the same domain:

| Path | Platform | Audience / language |
|---|---|---|
| `/` (`index.html`, `data.json`, `admin/`) | personal site, editable from `/admin`; « Projets » showcase of the platforms | fr |
| `/elbahiferti/` | static showcase of the ELBAHI FERTI — DIAG PMA laboratory | fr / ar |
| `/impact/` | **Défi Impact**: 1000 questions, accounts (incl. Google), shared stories, private conversations, support payments, reminder emails | ar / fr / en |
| `/metakpis/` | **Stats Meta KPIs**: site + Chrome extension colouring Meta Ads KPIs, Excel export, campaign suggestions | fr / en |
| `/swanbida/` | **SWANBIDA**: bath-linen shop, cash on delivery, admin, Meta Pixel + Conversions API, SEO | fr / ar (RTL) |
| `/marketing/` | **Marketing Dashboard** for YINVESTI (admin only): manual tracking + automatic reports, Meta Graph API | fr |
| `/maillage/` | **Maillage**: points-of-sale and distribution networks, OpenStreetMap map, catalogue, orders/reservations, company space, in-browser photo search | fr / ar |
| `/studioma/` | **Studioma**: social network + private collaborative studio for community managers, MCP server for AI assistants, guest sharing without account, Meta Pixel + CAPI; platform admin at `/studioma/admin/` (owner, `/admin` password) | en |

## Hosting and deployment

- One cPanel account (`dehini91`, provider documented as dzSecurity; the live server answers as `DZ-HTTP`).
- Repository cloned in `~/dehini-com`; a **cron job every 5 minutes** runs `git pull` on `main` and copies the
  code into `~/public_html/` (exact command: README « Installation › 4 »). So **`main` = production**. The cron
  pulls the branch that is checked out in `~/dehini-com`: that clone must stay on `main` with no local edits, and
  no coding agent (Codex, Claude) is ever run on the server.
  *Verified 2026-11-20: `crontab -l` on the server lists no `dehini-com` deployment job; deployments are currently
  copied by hand by the owner (`HANDOFF.md`). `main` stays production either way.*
- Live data is never in Git: SQLite databases and uploads live **outside `public_html`** in `~/impact-data/`,
  `~/metakpis-data/`, `~/swanbida-data/`, `~/marketing-data/`, `~/maillage-data/`, `~/studioma-data/`
  (plus `~/public_html/uploads/`, `swanbida/media/`, `maillage/media/`, `impact/uploads/pros/`). The served
  `data.json` is edited through `/admin`; the repository copy only seeds a new install.
- Configuration and secrets: git-ignored `admin/config.php` and `<project>/config.php` (each project ships a
  `config.example.php` listing the keys). Studioma's Meta Conversions API token is read from
  `~/studioma-data/meta-token.txt` (or `STM_META_TOKEN` in `config.php`). Never copy values into the repository.
- Verified live on 2026-11-20 (read-only requests): the site answers 200; Studioma serves the guest-sharing UI,
  the `/studioma/s/<key>` redirect (302) and its pixel endpoint returns dataset `1718566156353795`. Whether the
  Conversions API token file exists on the server is *(unverified)* — it cannot be seen from outside.

## Stack

- **Server**: PHP 8 (tests run on PHP 8.3; production PHP version *(unverified)*), PDO + SQLite (`pdo_sqlite`),
  extensions used: `gd` (WebP re-encoding), `curl`, `mbstring`, `intl` (optional transliteration), `zip`,
  `openssl`. Apache-compatible `.htaccess` (rewrites, security headers, CSP).
- **Browser**: hand-written HTML/CSS/JavaScript, no framework, **no build step, no package manager**.
  Vendored libraries only where needed (`maillage/vendor/`: Leaflet 1.9.4, Leaflet.markercluster 1.5.3).
- **Tests**: Node (20+) + Playwright/Chromium, PHP built-in server with a `router.php` that mirrors `.htaccess`.
- **Tools**: `tools/vision/` (Python, builds Maillage's in-browser TinyCLIP vocabulary), `tools/import/`
  (Python, Algeria points-of-sale import), `tools/vitrine/captures.js` (Playwright screenshots for the showcase).

## Integrations (external services)

| Service | Used by | Notes |
|---|---|---|
| Google Identity Services ("Continue with Google") | Impact, Stats Meta KPIs, Studioma | ID token verified server-side; Studioma can reuse Impact's client ID |
| Email (SMTP, Brevo API) | Impact (Brevo key set in its admin), Studioma (SMTP, defaults to Impact's settings), Maillage, SWANBIDA | tests write emails to `mail.jsonl` |
| Meta Graph API / Marketing API | Stats Meta KPIs extension, Marketing Dashboard (incl. webhooks), SWANBIDA and Studioma (Pixel + Conversions API, Graph v24.0) | Marketing's Meta app migration: `docs/meta-app-review.md` |
| Payment links (SofizPay CIB/Edahabia, PayPal, RedotPay, CCP) | Impact « Soutenir » page | see README « Défi Impact » |
| Paddle Billing (subscriptions, hosted checkout, webhooks) | Studioma plans Solo / Team | `docs/billing.md`; closed until configured in `studioma/config.php` |
| OpenStreetMap tiles | Maillage map | Leaflet vendored |
| MCP + OAuth 2.1 (Streamable HTTP, DCR, PKCE S256) | Studioma (`studioma/mcp.php`, `studioma/oauth.php`, root `.well-known/`) | Claude, ChatGPT, Gemini CLI connect as the user |

## Data

Each platform creates and migrates its own SQLite database on first request (schema and migrations in its
`lib/`, guarded by `PRAGMA user_version`; never edit production databases by hand).

Studioma (most active project) — `~/studioma-data/studioma.sqlite`, schema **version 8** in `main` (v6 admin, v7
`users.terms_at`, v8 `visitors` + `visit_days`), **version 9** (billing) on branch `claude/trusting-keller-rynk1j` until merged
(`studioma/lib/core.php` › `migrate()`):

- accounts & network: `users`, `sessions`, `companies`, `company_members`, `follows`, `connections`, `posts`,
  `post_likes`, `post_saves`, `post_comments`, `portfolio`, `recommendations`, `opps`, `opp_apps`,
  `saved_pros`, `threads`, `msgs`, `notifications`;
- workspace: `projects`, `project_members`, `studios`, `studio_members`, `levels`, `cols`, `cards`,
  `card_members`, `card_checks`, `card_comments`, `card_files`, `card_approvals`, `events`, `files`, `invites`;
- AI assistants (v4): `oauth_clients`, `oauth_requests`, `ai_grants`, `ai_tokens`;
- guest sharing (v5): `shares`, `share_guests`, `share_codes`, `guest_sessions`, `share_log`
  (+ guest columns on `events`, `card_comments`, `card_approvals`, `cards`, `notifications`);
- billing (v9): `subscriptions`, `billing_customers`, `billing_events` (Paddle webhooks), `billing_transactions`,
  `usage_counters` (new cards per account and month) — see `docs/billing.md`;
- administration (v6): `users.suspended` / `suspend_note`, `activity_days` (active accounts per day),
  `mail_log` (every email sent), `campaigns`, `mail_queue`, `admin_log`;
- technical: `rate` (rate limits), `meta` (key/value, incl. `meta_capi_error`, `mail_last_error`, `cron_last`).

## Code layout of a platform (Studioma as the reference)

- `studioma/api.php` — JSON API: `ROUTES` table (method, account required) and `GUEST_ROUTES` (allow-list for
  share links); every action is `api_<action>($data, $user)` in `lib/`.
- `studioma/lib/` — `core.php` (config, DB, migrations, mail), `auth.php` (accounts, sessions, notifications),
  `access.php` (roles and rights), `studio.php`, `cards.php`, `collab.php`, `social.php`, `talent.php`,
  `files.php`, `google.php`, `oauth.php`, `mcp.php`, `share.php` (guests), `meta.php` (Pixel/CAPI events),
  `admin.php` (platform administration: routes with access `admin` in `ROUTES`).
- `studioma/app/index.html` + `studioma/assets/*.js` — single-page app with hash routes (`#/studio/12`, guest
  links `#/s/<key>`), no inline script; `studioma/index.html` — landing page; `studioma/admin/index.html` +
  `assets/admin.{js,css}` — administration; `studioma/unsubscribe.php` (campaign unsubscribe page),
  `studioma/cron.php` (optional CLI scheduled task).

## Environment for development and tests

Nothing to install in the repository. A machine (or Codex / Claude cloud container) needs:

```bash
php -v                         # PHP 8.x CLI with pdo_sqlite, gd, curl, mbstring, intl, zip, openssl
node -v                        # Node 20+ (tests use fetch and Headers.getSetCookie)
node -e "require('playwright')" # Playwright with Chromium, e.g. npm i -g playwright && npx playwright install chromium
```

Each `tests/<project>/setup.sh` builds a throw-away fake hosting (`public_html/` copy + `<project>-data/`) with
test configuration (fake Google keys, fake Meta dataset, mail and Meta event logs); `run.sh` starts PHP's built-in
server and runs the suites. Manual browsing: `tests/studioma/setup.sh /tmp/stm` then
`php -S 127.0.0.1:8097 -t /tmp/stm/public_html tests/studioma/router.php` and open `http://127.0.0.1:8097/studioma/`.
