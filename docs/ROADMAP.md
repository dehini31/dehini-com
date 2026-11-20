# Roadmap

Status verified on **2026-11-20** from the code, `README.md`, Git history, GitHub (no open pull request) and
read-only checks of the live site. *(unverified)* marks what could not be checked. Update this file when a
status changes; keep the history of finished items short.

## Done (in `main`, therefore deployed by the cron)

- **Personal site**: data-driven page, `/admin` back-office (texts, images, PDFs, links), SEO (robots, sitemap),
  « Projets » showcase of the platforms with live preview (PR #69, 2026-11-20).
- **ELBAHI FERTI**: static showcase page fr/ar.
- **Défi Impact**: 1000 questions (editable), accounts + Google, stories with likes and private conversations,
  moderation, support payments page, reminder emails (Brevo key editable in admin, 2026-11-07).
- **Stats Meta KPIs**: site, accounts, Chrome extension 2.1.0 (KPI colours, Excel export, « Que faire ? »).
- **SWANBIDA**: catalogue, cash-on-delivery orders, admin, stock, delivery, statistics, Meta Pixel + CAPI, SEO;
  e2e tests.
- **Marketing Dashboard**: manual tracking, automatic daily/weekly/monthly reports, Meta permissions, verified
  webhooks, token health checks, tooling for the migration to the new Meta app (2026-11-15).
- **Maillage**: landing, map of points of sale, brands and catalogue, availability, orders/reservations, company
  space, analytics, in-browser photo search, automatic Algeria database import (2026-11-14); e2e tests.
- **Studioma** (live checks on 2026-11-20): accounts (email + Google), workspace (projects, Studios, levels,
  columns, cards, handoffs, calendar, My work, insights), invitations and roles, network (profiles, feed,
  portfolio, recommendations), companies, opportunities, talent directory, messages; MCP server with 44 tools
  (PR #68); **guest sharing without account** and **secure https share links** (PR #71); **Meta Pixel +
  Conversions API** (PR #72). Test suites: API, sharing, Meta, MCP/OAuth, browser e2e.

## In progress

- Nothing verified as in progress: no open pull request, working tree clean, `claude/trusting-keller-rynk1j` has no
  commit beyond `main` except the shared-agent setup (see `HANDOFF.md`).

## Pending — owner actions (outside the code)

- [ ] **Studioma Meta token on the server**: put the Conversions API token in `~/studioma-data/meta-token.txt`
  (`chmod 600`) — *(unverified whether done; without it only the browser pixel sends events)*.
- [ ] **Regenerate that token** (Events Manager › Settings › Conversions API): it was pasted in a chat on
  2026-11-20. Then rewrite the file.
- [ ] **cPanel › Domains › Force HTTPS Redirect** for dehini.com (the site still answers on plain http).
- [ ] **Cookie consent** for Meta tracking if EU visitors are targeted (Studioma and SWANBIDA have no banner).
- [ ] **Marketing Dashboard**: switch to the new Meta app « chatgptapp » following
  `docs/meta-real-test-checklist.md` — *(status unverified)*.
- [ ] Terms of use: Maillage shows a starting text to review and adapt; Studioma only has the sign-up checkbox
  (no terms page yet).

## Pending — product gaps documented in the README (« Ce qui n'est pas fait »)

- **Studioma**: no connection to social networks (manual publication dates, links and metrics); no built-in AI
  (only external assistants through MCP); MCP has no server-sent events and no read-only token; guests cannot
  upload files, see the calendar or dashboards, or request approvals; sync by polling every 4 s (last write
  wins); email not verified at sign-up; no moderation / central admin / blocking; English only; public pages
  not server-rendered for SEO (only the landing is).
- **Maillage**: no online payment or delivery fees; one product per order (no cart); one account per company
  (no multi-user team yet); radar matches stores by commune then wilaya (no road distance).
- Impact, Stats Meta KPIs, SWANBIDA, Marketing, personal site: no gap list in the README *(open items unknown —
  ask the owner)*.

## Blocked / needs an owner decision

- **Unmerged branches** (commits not in `main`; do not merge or delete without the owner):
  - `claude/funny-fermi-v7vhfr` — 1 commit (2026-11-08): clearer Impact error when Brevo blocks the server IP
    (`impact/api.php`, `impact/lib/mailer.php`). **Confirmed not in `main`.** Small; needs review and a PR.
  - `claude/youthful-hamilton-s8qrer` — 15 commits (2026-10-28/29): a `Yinvesti/` ASC 2026 site (registration
    deadline 11 November 2026, now past). Not in `main`; probably obsolete *(unverified)*.
  - `claude/elegant-dirac-4x2mlb` (11), `claude/epic-ramanujan-fcn007` (7), `claude/nifty-bohr-lojw3d` (14) —
    October work (admin, PDF viewer, ELBAHI FERTI) that predates the 2026-11-06 history rebuild; their features
    exist in `main`, so they are probably superseded *(unverified)*.
- Studioma guest sharing and Meta events in production: functionally verified only by tests and by the routes
  answering live; no real-user validation recorded yet *(unverified)*.
