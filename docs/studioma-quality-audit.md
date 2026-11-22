# Studioma — quality, mobile, UX, SEO and performance audit (2026-11-22)

Audit of `main` @ `9da027c` (Creator Journey merged) on a fake hosting seeded with realistic data (a community
manager, a content Studio, cards, one published achievement, one published opportunity, an open Creator Passport).
Tools: Playwright / Chromium (only browser installed here — **WebKit / Safari not tested**), axe-core 4 (WCAG 2.0–2.2
A/AA + best practices), Lighthouse 12 (mobile, simulated slow 4G) on the local hosting and, read-only, on
`https://dehini.com` (Meta pixel requests blocked so no analytics event was sent). Scripts are kept out of the
repository; the checks that matter are now in `tests/studioma/quality-e2e.js`.

Mobile sweep: 23 signed-in routes and 7 public pages × 8 viewports (320×640, 360×740, 375×667, 390×844, 414×896,
768×1024, 1024×768, 844×390 landscape) = 240 page loads, measuring horizontal overflow, elements outside the screen,
touch targets under 24 × 24 px and console errors.

## Issue inventory

| # | Problem | Route / component | Severity | Root cause | Fix | Verification |
|---|---|---|---|---|---|---|
| O1 | **Published opportunities never appear in the Home feed** | `#/` (For you), `#/discover`, `feed` API | Critical | Only achievements get a feed post (`pubs.post_id`); `feed_pubs()` keeps `status = 'published'` only, which an opportunity (`open`) never has; « For you » only shows the network's posts | One feed post per live publication (achievement published, opportunity open), created on publish / reopen, filtered out when closed, hidden or deleted, reused when reopened; « For you » also shows every open opportunity; backfill for existing ones (DB v13) | `journey`-style API tests + browser test: publish → Home → details → edit → close → reopen → delete |
| O2 | Opportunities have no public, crawlable page or shareable public link | `#/pub/<id>` (JavaScript, under `/studioma/app/`, disallowed in robots.txt) | High | Public pages are rendered by JavaScript | Server-rendered `/studioma/opportunities/` and `/studioma/opportunity/<id>-<slug>` (Open Graph, canonical, noindex when closed, 404 when gone); « Copy public link » and share buttons in the app | HTML tests (status, metadata, no private data) |
| M1 | « Create » button squashed to 20 × 38 px in the mobile top bar | every signed-in page < 760 px | High | Flex item shrinks; its label is hidden on mobile so the button has no accessible name | Fixed 40 × 40 icon button with `aria-label` | Sweep + axe `button-name` |
| M2 | Logged-out header overflows (« Join Studioma » cut off) | `#/login`, `#/signup`, `#/pricing`, `#/community` at 320 px | High | Fixed-size buttons in one row | Compact header under 360 px | Sweep (no overflow at 320) |
| M3 | Settings overflow by 31 px | `#/settings` at 320 px | Medium | Google « Continue with Google » iframe rendered at 320 px wide inside a narrower card | Button width follows its container | Sweep |
| M4 | Plan comparison table overflows | `#/pricing` at 320 px | Medium | Table wider than the screen, no scroll container | Horizontal scroll inside its own box | Sweep |
| M5 | Long passport URL overflows | `#/achievements/settings` at 320 px | Low | Unbreakable URL | `overflow-wrap: anywhere` | Sweep |
| M6 | Manager actions shown above the opportunity on phones | `#/pub/<id>` | Medium | Rail rendered before the main column when it collapses | Main content first on small screens | Screenshot |
| M7 | Small touch targets: column names (16 px high), « back » links, card selection box | board, detail pages | Low | Inline text used as a control | Minimum 32 px target height | Sweep (counts) |
| A1 | Text and button contrast below 4.5:1 (muted grey on beige, dark-mode white on light purple, green « ok » text, avatar initials, dimmed levels) | most pages | Serious | Design tokens slightly too light; `opacity` used on text | Darker light-theme `--muted`, `--ok`; dark-theme buttons on the strong purple; darker avatar palette; no opacity on text | axe `color-contrast` |
| A2 | Icon-only buttons without a name (like, comment, create) | feed, top bar | Critical | Label hidden on mobile, icon only | `aria-label` | axe `button-name` |
| A3 | Card panel dialog and its editor have no accessible name | `#/studio/…/card/…` | Serious | Missing `aria-label` | Card title as dialog name, « Description & script » for the editor | axe |
| A4 | Missing `h1` (Home, board), empty table header (pricing), heading order (publication page), duplicate banner / unnamed navigation landmarks | several | Moderate | Markup | Proper headings / labels, visually hidden where needed | axe |
| A5 | Links distinguished by colour only | sign-up, settings | Serious | No underline | Underline | axe `link-in-text-block` |
| P1 | App first paint 3.2 s (production, mobile lab) | `/studioma/app/` | High | 17 parser-blocking scripts at the end of `<body>` delay the first render; Google Fonts CSS is render-blocking (third party) | `defer` on every script (order kept); self-hosted variable font (latin, latin-ext) with preload | Lighthouse before / after |
| P2 | Landing LCP 2.7 s (production, mobile lab), LCP element = hero `h1` | `/studioma/` | Medium | Render-blocking Google Fonts CSS (~0.85 s) and parser-blocking scripts; ~0.9 s time to first byte | Same as P1 (`defer`, self-hosted font) | Lighthouse |
| S1 | No sitemap for Studioma, not referenced in `robots.txt` | `/studioma/` | High | — | Dynamic `/studioma/sitemap.xml` (landing, pricing, open opportunities, open passports, passport-visible achievements) referenced in `/robots.txt` | XML test |
| S2 | Pricing not crawlable (JavaScript page under disallowed `/studioma/app/`) | `#/pricing` | Medium | Single-page app | Server-rendered `/studioma/pricing/` from the same plan table (`STM_PLANS`) | HTML test |
| S3 | Landing: no social image, no Twitter card, no structured data, generic title, `h2` before the `h1` | `/studioma/` | Medium | — | Title / description focused on content planning and client collaboration, `og:image`, Organization + WebSite + SoftwareApplication JSON-LD (no invented ratings), `h1` first in the document | HTML / JSON-LD tests |
| S4 | `http://` and `www.` variants answer 200 (duplicates) | whole domain | Medium | No HTTPS / host redirect at the server | Canonical tags already present; redirect to be switched on in cPanel (owner) — not done in code to avoid redirect loops behind the host's proxy | Manual |

Not reproduced / already fine: board usable on phones (horizontal scroll per Studio, column picker in the card panel as
the alternative to drag-and-drop), modals scroll, safe areas on the bottom tab bar, Creator Passport and verification
pages (Lighthouse 100 / 100 / 100 / 100 locally), production already compressed with Brotli over HTTP/2 and HTTP/3.

Language: English only; no hreflang needed. Opportunities are requests for professionals, proposals, collaboration or
help — not job vacancies — so no `JobPosting` structured data (Google requires real job postings).

## Results after the fixes (same tools, same seeded data)

| Check | Before | After |
|---|---|---|
| Mobile sweep: page loads with horizontal overflow or elements off screen (240 loads) | 7 (320 px: logged-out header ×4, settings, pricing, passport settings) | **0** |
| Touch targets under 24 × 24 px (sum over the 240 loads) | 711 | 370 (remaining: checkboxes inside clickable labels, inline text links — allowed by WCAG 2.5.8) |
| axe-core violations (15 pages × desktop and mobile) | 10 rules: contrast, button names, dialog name, editor name, landmarks, missing `h1`, heading order, empty table header, link-in-text | **0** |
| Publish dialog on a phone | top ~1 000 px unreachable (could not choose the opportunity type) | scrollable from its top (tested) |
| Published opportunity in Home « For you » / Discover | never | always while open (tested: publish, edit, close, reopen, moderation, delete) |

Lighthouse, mobile preset (simulated slow 4G), **local test server** (PHP built-in server: HTTP/1.1, no compression —
absolute values are pessimistic; compare before / after only):

| Page | Before (perf / a11y / SEO, FCP, LCP, TBT) | After |
|---|---|---|
| `/studioma/` landing | 97 / 95 / 100, FCP 1.6 s, LCP 1.6 s, TBT 190 ms | **99 / 100 / 100**, FCP 1.1 s, LCP 1.5 s, TBT 110 ms |
| `/studioma/app/#/login` (app shell) | 66 / 96 / 60*, FCP 4.6 s, LCP 4.7 s | 77 / 100 / 60*, FCP **1.6 s**, LCP 5.3 s (still bound to the JavaScript: see below) |
| `/studioma/creator/…`, `/studioma/verify/…` | 100 / 100 / 100 | 100 / 100 / 100 |
| `/studioma/opportunities/`, `/studioma/opportunity/…`, `/studioma/pricing/` (new) | — | 100 / 100 / 100 / 100 (best practices 100) |

\* The app is intentionally `noindex` (SEO audit « is-crawlable »). Production (read-only measurement on 2026-11-22,
before deployment): landing 92 perf, LCP 2.7 s; app login 83 perf, LCP 3.6 s. **After-deployment production figures
are not measured yet** (nothing was deployed); re-run Lighthouse / PageSpeed Insights once the branch is live, and
watch the Core Web Vitals report in Search Console (real-user data needs traffic).

## Still open (not fixed here)

- **Safari / WebKit and Firefox not tested** — only Chromium is installed in this environment.
- **App shell LCP** stays high in the lab: every screen is drawn by JavaScript after 16 scripts load. Real fixes need
  bundling or server-rendered entry screens, i.e. a build step — out of scope for this pass.
- `http://` and `www.` variants: redirect in cPanel (owner).
- Search Console verification and sitemap submission: owner (not done, no access).
- Drag-and-drop on touch screens is not implemented; the card panel's column menu (« Move ») is the mobile path.
