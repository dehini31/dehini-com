# AGENTS.md — shared instructions for every coding agent

Canonical instructions for **OpenAI Codex and Claude Code** (Claude reads this file through `@AGENTS.md` in
`CLAUDE.md`). Both agents work on the same repository and take over from each other. Keep this file short and
true; put details in the files it points to.

## 1. Before you start any task

1. Read `HANDOFF.md` (current state, last agent, unfinished work, exact next step).
2. Run `git status --short --branch` and `git log --oneline -10`; look at the diff of anything uncommitted
   (`git diff`, `git diff --cached`). **Uncommitted changes belong to the user or to the other agent: never
   discard, reset, stash-drop or overwrite them.**
3. Read the README section of the project you will touch (see the map below), and `docs/DECISIONS.md` before
   changing behaviour, security or data handling.
4. If `HANDOFF.md` says another agent is mid-task on the same files, do not edit them: ask the user, or work on
   another branch / worktree.
5. Run `git fetch origin` and start from the branch and commit named in `HANDOFF.md` « Current state » (a local
   `main` may be stale: compare with `origin/main`). Check open pull requests for that branch before pushing to it.

## 2. What this repository is

Personal site of Mohammed Dehini (`https://dehini.com`) plus several independent web platforms, all hosted on one
cPanel account and developed here. **No build step, no package manager**: plain PHP 8 + SQLite on the server,
vanilla HTML/CSS/JS in the browser. Full context: `docs/PROJECT_CONTEXT.md`. Human documentation (French):
`README.md`, one section per project.

| Path | What | README section |
|---|---|---|
| `index.html`, `data.json`, `admin/`, `uploads/`, `img/` | personal site + its back-office | top, « Utiliser l'admin », « Vitrine… » |
| `elbahiferti/` | static showcase page (fr/ar) | top |
| `impact/` | Défi Impact (ar/fr/en): questions, accounts, stories, payments, emails | « Défi Impact » |
| `metakpis/` | Stats Meta KPIs site + Chrome extension (`metakpis/extension/`) | « Stats Meta KPIs » |
| `swanbida/` | e-shop, cash on delivery, Meta Pixel + Conversions API | « SWANBIDA » |
| `marketing/` | private marketing dashboard (Meta Graph API, webhooks) | « Marketing Dashboard », `docs/meta-*.md` |
| `maillage/` | points-of-sale / distribution platform, map, in-browser image search | « Maillage », `tools/vision/` |
| `studioma/` | social network + collaborative studio for community managers (English UI), MCP server, guest sharing, Meta Pixel/CAPI, platform admin (`studioma/admin/`), plans & Paddle billing (`docs/billing.md`) | « Studioma » |
| `.well-known/` | OAuth discovery rewrites for Studioma's MCP server | « Studioma › 4 » |
| `tests/<project>/` | fake-hosting test suites (Studioma, SWANBIDA, Maillage) | `tests/*/README.md` |
| `tools/` | one-off scripts (data import, vision model build, showcase screenshots) | each tool's header |

## 3. Production and deployment — read this

- **`main` is production.** A cron job on the server runs `git pull` on `main` every 5 minutes and copies the code
  into `~/public_html/` (README « Installation › 4 »). *Verified 2026-11-20: the server's crontab had no such job, so
  the owner currently copies `main` to the live site by hand — see `HANDOFF.md`.* Either way, **treat merging or
  pushing to `main` as deploying to the live site.** Never push to `main`; never merge a pull request. The owner
  merges.
- Work on a feature branch (`claude/<topic>` or `codex/<topic>`), push it, and open a pull request **only when the
  user asks**. Never force-push, never rewrite published history, never `git reset --hard`, `git clean -fd`,
  `git checkout -- <file>` on someone else's changes, or delete branches you did not create.
- **Never run an agent, edit files or switch branches in the server's clone `~/dehini-com`** (cPanel account
  `dehini91`, host `oort-shared`). The cron pulls whatever branch is checked out there and copies its working
  tree into `public_html`: another branch or an uncommitted edit would go live, and merges to `main` would stop
  deploying. That clone must stay on `main`, clean. Agents run on a developer computer, in Codex cloud
  (chatgpt.com/codex) or in a Claude Code session — never on the hosting server.
- Live data never comes from Git: each platform's SQLite database and uploaded files live **outside
  `public_html`** in `~/<project>-data/` on the server, and `data.json` on the server is edited through `/admin`.
  The repository's `data.json` only seeds a new install.
- **Secrets are never committed**: every `<project>/config.php` and `admin/config.php` is git-ignored (only
  `config.example.php` is tracked); tokens such as Studioma's Meta Conversions API token live in
  `~/studioma-data/meta-token.txt` on the server. Never paste tokens, passwords or personal data into code,
  commits, docs, tests or `HANDOFF.md`.

## 4. Architecture and code conventions

- Each platform is self-contained: `<project>/api.php` (JSON API, routing table), `<project>/lib/*.php`
  (logic), static HTML + `assets/*.js` (UI), `.htaccess` (rewrites, security headers, CSP).
- PHP: `declare(strict_types=1);`, PDO + SQLite through the helpers already in that project's `lib/` (every
  project has `db()`; Studioma adds `q`, `row`, `rows`, `val`, `insert`, `tx`), prepared statements only. Schema
  changes are **additive migrations** guarded by `PRAGMA user_version` (`if ($v < N) { … }`), idempotent, and
  must keep existing data: production databases upgrade themselves on the next request.
- **Permissions are enforced on the server**, never only in the UI (e.g. `studioma/lib/access.php`,
  `studioma/lib/share.php`). The UI only hides what the role cannot do.
- Browser code: vanilla JS, no framework, no CDN for app code. Where a project sets a Content-Security-Policy
  (Studioma and Maillage in `.htaccess`, SWANBIDA in `index.php`), **no inline scripts**; a new external origin
  goes into that CSP **and** into the matching `tests/<project>/router.php`. Escape all user content (Studioma:
  the `html` tagged template in `assets/core.js`).
- Language: code comments and `README.md` are in **French**; follow the language already used in a file. UI
  language is per project (Studioma: English; Impact: ar/fr/en; SWANBIDA: fr/ar).
- Keep changes focused. Do not reformat, rename or restructure working code you are not asked to change. Update
  the project's README section when behaviour, setup or deployment steps change.
- Commit messages: `<Project>: <what changed>` (e.g. `Studioma: share a Studio with guests…`), body explaining
  why. Never put API keys, tokens or model identifiers in commits.

## 5. Commands (no install needed beyond PHP 8.x CLI with pdo_sqlite, gd, curl, mbstring, intl, zip, openssl; Node 20+; Playwright with Chromium)

```bash
# Syntax checks (fast, safe)
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors'
for f in studioma/assets/*.js; do node --check "$f"; done

# Full suites: each builds a throw-away fake cPanel hosting in the given folder (never touches production)
tests/studioma/run.sh /tmp/stm-test      # API, sharing, Meta, MCP/OAuth, admin, billing, browser e2e (~7 min, port 8097)
tests/swanbida/run.sh /tmp/swb-test      # ports 8090-8091
tests/maillage/run.sh /tmp/mlg-test      # port 8096
```

Emails and Meta events are written to files in the test folder (`mail.jsonl`, `meta.jsonl`), never sent. If
Playwright lives in a global `node_modules`, export `NODE_PATH` to it. Impact, Stats Meta KPIs, Marketing and the
personal site have no automated suite: lint them and test by hand as the README describes.

## 6. Handoff protocol (both agents)

- **During work**: one agent per working tree. If both must work at the same time, use separate branches
  (`claude/…`, `codex/…`) or `git worktree add ../dehini-com-<agent> <branch>`; never edit files the other agent
  has uncommitted changes in.
- **After every meaningful completed task, and before you stop or run out of budget**, update `HANDOFF.md`:
  date, agent, branch, base commit, what was done, files changed, decisions, tests run and results, what is
  uncommitted, blockers, and the **exact next step**. Add durable decisions to `docs/DECISIONS.md` and status
  changes to `docs/ROADMAP.md`.
- Commit checkpoints on your branch with a clear message (`chore: handoff checkpoint …` for handoff-only
  commits). State in `HANDOFF.md` whether work is committed, pushed, merged, or only in the working tree.
- Never claim a test, push, merge or deployment succeeded unless you verified it; mark guesses as
  **unverified**.
- If you find work you do not understand (unexpected diff, unknown branch), record it in `HANDOFF.md` and ask
  the user instead of reverting it.

## 7. Where things are

- `HANDOFF.md` — latest state and next step (read first, update last).
- `docs/PROJECT_CONTEXT.md` — purpose, architecture, stack, integrations, data, environment.
- `docs/DECISIONS.md` — decisions and constraints with their reasons.
- `docs/ROADMAP.md` — verified done / in progress / pending / blocked.
- `docs/meta-app-review.md`, `docs/meta-real-test-checklist.md` — Marketing dashboard Meta app migration.
- `CLAUDE.md` — Claude Code specifics (imports this file). `.claude/` — Claude Code hook (session context).
