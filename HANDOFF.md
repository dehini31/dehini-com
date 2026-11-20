# HANDOFF — read first, update last

Shared memory between **Claude Code** and **OpenAI Codex** (protocol: `AGENTS.md` § 6). Keep the « Current
state » block short and true; add a log entry at the top of « Log » after every meaningful task. Never write
secrets here. Mark anything you could not verify as *(unverified)*.

## Current state — 2026-11-20

| | |
|---|---|
| Last agent | Claude Code (cloud session) |
| Branch | `claude/trusting-keller-rynk1j` (tracks `origin/claude/trusting-keller-rynk1j`) |
| Base code | `main` @ `1218970` (Merge PR #73: shared agent setup). **`main` = production** (server cron, every 5 min) |
| Checkpoint | the commit that last changed this file: `git log -1 --format='%H %s' -- HANDOFF.md` |
| Uncommitted work at checkpoint | none |
| In progress | nothing — no open pull request, no half-done code |
| Last task changed application code? | **no** (documentation only) |

**Next recommended action**

1. **Owner, urgent**: put the server clone back on `main` — `cd ~/dehini-com && git checkout main`, then
   `git status --short --branch` must show `## main...origin/main` *(unverified whether done)*. Never run Codex
   or Claude on the server (`AGENTS.md` § 3); use Codex cloud (chatgpt.com/codex) or a personal computer.
2. Owner: the other actions in `docs/ROADMAP.md` › « Pending — owner actions » (Meta token on the server and its
   regeneration, cPanel « Force HTTPS Redirect », cookie-consent decision).
3. Agent: no coding task is pending. Ask the owner which item to take from `docs/ROADMAP.md`. Ready candidates:
   (a) review branch `claude/funny-fermi-v7vhfr` (1 commit, clearer Impact error when Brevo blocks the server IP,
   not in `main`) and propose it as a PR; (b) a Studioma gap from the roadmap.

**Blockers**: none for development. Unverified: whether the Studioma Conversions API token is installed on the
server (only the browser pixel works without it).

**Validation at this checkpoint** (2026-11-20, on the base code `6aa8755`, same application code as `1218970`):
`tests/studioma/run.sh` 788 checks, `tests/swanbida/run.sh` 119, `tests/maillage/run.sh` 181 — all passed, 0
failures; `php -l` on 108 PHP files and `node --check` on 47 JS files: 0 errors.

## Log (newest first)

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
