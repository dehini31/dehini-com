# Decisions

Technical and product decisions that every agent must respect. Each entry says **where it is visible** (code,
README, history) so it can be checked. Add new decisions at the end of the relevant section, with the date; do
not rewrite old ones — mark them *superseded* and link the replacement.

## Whole repository

| # | Decision | Why | Where |
|---|---|---|---|
| R1 | No build step, no package manager: PHP 8 + SQLite on the server, vanilla HTML/CSS/JS | Runs as-is on a shared cPanel account; nothing to compile or install in production | README top, every project |
| R2 | **`main` is production**: a server cron pulls `main` every 5 minutes and copies the code into `public_html`. Agents work on branches; only the owner merges | Simple, auditable deployment without CI | README « Installation › 4 » |
| R3 | Live data stays on the server, outside `public_html` (`~/<project>-data/`), never in Git; `data.json` in Git only seeds a new install | Deployments must never overwrite user data | README « Comment ça se met à jour », `.gitignore` |
| R4 | Secrets only in git-ignored `config.php` files (or files in the data folders); `config.example.php` documents the keys | The repository can be read by tools and people without exposing credentials | `.gitignore`, `*/config.example.php` |
| R5 | Databases migrate themselves, additively and idempotently, guarded by `PRAGMA user_version` | Deploys happen by `git pull`; no manual migration step exists | each `lib/` (`migrate()`) |
| R6 | Permissions enforced server-side; the UI only hides what a role cannot do | Security does not depend on the browser | `studioma/lib/access.php`, `studioma/lib/share.php`, each `api.php` |
| R7 | Strict Content-Security-Policy where set (Studioma, Maillage, SWANBIDA): no inline scripts, explicit external origins; test routers mirror the same headers | User-generated content can never run as code; CSP regressions are caught by tests | `studioma/.htaccess`, `maillage/.htaccess`, `swanbida/index.php`, `tests/*/router.php` |
| R8 | Tests run on a throw-away fake cPanel hosting (PHP built-in server + Playwright); emails and Meta events go to files, never to real services | Reproducible, safe, no production access | `tests/*/README.md` |
| R9 | Code comments and README in French; UI language per project | Owner's working language; product audiences differ | README, code |

## Studioma

| # | Decision | Why | Where |
|---|---|---|---|
| S1 | Hierarchy Account → Projects → Studios → Levels → Columns → Cards; a handoff **moves** the same card to the next level's principal column (never a copy) | Card identity, comments, files and history stay intact | `studioma/lib/studio.php` (`card_move`, `card_handoff`) |
| S2 | Roles owner / manager / creator / collaborator / client (approver) / viewer, checked by `can()` | Product spec; backend enforcement | `studioma/lib/access.php` |
| S3 | Studio content private by default; nothing reaches a public profile without explicit publication; a case study from a Studio needs the project owner's (and client's, if any) authorization; proof of work distinguishes studio-verified / client-confirmed / self-reported; no global ranking | Trust between community managers and their clients | `studioma/lib/social.php`, README « Studioma › Confidentialité » |
| S4 | Email invitations attach to an account only if its email is confirmed | Prevents someone registering another person's address to read their invitations | `studioma/lib/collab.php` (PR #66) |
| S5 | MCP server: stateless Streamable HTTP, OAuth 2.1 (DCR, PKCE S256, refresh rotation) or personal tokens; tools act as the user with exactly the user's rights; outward actions flagged `openWorldHint`; out-of-list values refused | Works on shared hosting; AI assistants never get more rights than the person | `studioma/mcp.php`, `studioma/lib/mcp.php`, `studioma/lib/oauth.php` |
| S6 | Guest sharing without account: at most one public and one private link per Studio; read-only by default; email ownership proven by 6-digit code or magic link (15 min, single use, 5 tries); guests per link with role viewer / commenter / editor (mapped to viewer / client / creator) and optional editing scope (levels, columns, cards); only the actions in `GUEST_ROUTES` are reachable; guests cannot upload files, manage the Studio, archive or delete | "Anyone can collaborate without an account, but nobody modifies anything without verified identity and explicit authorization" (owner's spec, 2026-11-20) | `studioma/lib/share.php`, `studioma/api.php` |
| S7 | Guest changes are emailed to the link's creator (or project owners): first immediately, then grouped at most every 10 minutes, sent when a request arrives after the window | Avoids email floods without a cron job | `share_digests()` in `studioma/lib/share.php` |
| S8 | Share links look like `https://dehini.com/studioma/s/<32-char base64url key>` (192 bits); `studioma/s.php` redirects with `Referrer-Policy: no-referrer`, `no-store`, `noindex`; links are always built with https on a real domain | Short, clean, visibly secure links that never leak their key | `studioma/s.php`, `share_url()`, `site_url()` |
| S9 | No site-wide http→https redirect in `.htaccess`: the owner should enable cPanel « Force HTTPS Redirect » instead | The host's HTTPS variables could not be tested; a wrong rule could loop the whole site | README « Studioma › 5 » (2026-11-20) |
| S10 | Meta tracking: the server decides every event (`meta_after()`), sends it by Conversions API and returns it to the browser so the pixel sends the same `event_id` (deduplication); PageView from the pixel | Accurate counts even when the pixel is blocked | `studioma/lib/meta.php`, `studioma/assets/meta.js` |
| S11 | Meta privacy: only categories and public ids; signed-in users' email / id / names SHA-256 hashed; never content, project/Studio names, messages, typed search text, share or invite keys, or guests' emails; pixel silent on routes with secret keys; automatic events off; token outside the repo | Privacy of clients' work and of third parties | same files; README « Studioma › 6 » |
| S12 | No cookie-consent banner (same choice as SWANBIDA) | Owner's existing practice — **open question** if EU visitors are targeted | README « Studioma › 6 » |

## SWANBIDA

| # | Decision | Where |
|---|---|---|
| W1 | Cash-on-delivery tracking: browser + server events share `event_id`; `Lead` once at order creation; `Purchase` server-side only when the team confirms the order (or at delivery if chosen), once per order | `swanbida/lib/meta.php` (header comment) |
| W2 | Notifications by email only (Telegram removed, 2026-11-06) | Git history |

## Repository history

- Around 2026-11-06 a branch was "resynced after rebuild from main" (`claude/nifty-newton-3sgph0`); several
  older `claude/*` branches show commits that are not in today's `main`. Their content may have been re-applied
  under other commits *(unverified)*: never merge or delete them without the owner (see `docs/ROADMAP.md`).
