# Real Meta test checklist (new app « chatgptapp »)

Validation with the real YINVESTI Page, @yinvesti_ and ad account, **before** switching production, then
right after the switch. Background and Meta references: [`meta-app-review.md`](meta-app-review.md).

How to use: tick each line, write the date and anything unexpected in « Notes ». When a line fails, click
« Copier le rapport » in Connexions › Nouvelle app Meta (step 4) and keep it with the notes.

**What deploying this branch changes before any switch** (the current app keeps running):

- the current connection is checked at Meta every 6 hours (box under the account in Connexions);
- « Activer le temps réel » now reads every subscription back at Meta, and shows the Instagram webhook object as
  a manual step if it is not set in the App Dashboard;
- conversations are matched by person (one per person per Page / Instagram account), and a message already
  stored under another ID is not copied again.

Nothing is sent to the new app's Page subscription until « Activer le temps réel en parallèle » (phase C), and
nothing switches until « Basculer » (phase E).

---

## A. Meta and server setup (no impact on production)

| # | Step | Expected | ✓ | Notes |
|---|---|---|---|---|
| A1 | New app › Settings › Basic: app domain `dehini.com`, Privacy Policy URL `https://dehini.com/marketing/confidentialite.html`, Data deletion instructions URL `https://dehini.com/marketing/suppression-donnees.html` | Saved without error; both pages open publicly | ☐ | |
| A2 | Facebook Login for Business › Settings › Valid OAuth redirect URI `https://dehini.com/marketing/meta.php` | Saved | ☐ | |
| A3 | Facebook Login for Business › Configurations › create: system-user access token, no expiration, the 15 permissions (list in [meta-app-review.md §1](meta-app-review.md#1-exact-permissions)), assets = YINVESTI Page + @yinvesti_ + ad account | Configuration ID shown. **If the system-user token type is not offered**, note it and use a user access token configuration (see §2 of the guide) | ☐ | Config ID: |
| A4 | App Dashboard › Webhooks › Instagram (or Instagram use case › Configure webhooks): callback `https://dehini.com/marketing/webhook.php`, verify token = the value you will put in config.php, subscribe `comments` and `messages` | « Verify and save » succeeds (needs A6 deployed first: Meta calls the URL with the token) | ☐ | |
| A5 | Instagram app on @yinvesti_: Paramètres › Messages et réponses aux stories › Contrôle des messages › Outils connectés › « Autoriser l'accès aux messages » ON | On | ☐ | |
| A6 | Server `~/public_html/marketing/config.php`: `MK_META_NEXT_APP_ID`, `MK_META_NEXT_APP_SECRET` (entered on the server only), `MK_META_NEXT_CONFIG_ID`, `MK_META_WEBHOOK_VERIFY_TOKEN` — then deploy the branch | `https://dehini.com/marketing/webhook.php?hub.mode=subscribe&hub.verify_token=<token>&hub.challenge=42` returns `42`; a wrong token returns 403 | ☐ | |
| A7 | App Dashboard › App roles: the people who will send test messages/comments have a role (admin, developer or tester) — needed while the app is in Development mode | Roles accepted | ☐ | Testers: |
| A8 | Connexions › Facebook · Instagram · Ads: « Vérifier maintenant » under the account (current app) | « acceptée »; no warning, or only warnings you understand | ☐ | |

## B. Connect the new app and run the tests (no impact on production)

| # | Step | Expected | ✓ | Notes |
|---|---|---|---|---|
| B1 | Connexions › Nouvelle app Meta, step 1 | « Réglée dans marketing/config.php : ID 3025910297765050 · clé secrète présente » and the configuration ID | ☐ | |
| B2 | « Se connecter avec la nouvelle app » | Meta dialog shows chatgptapp and the assets of the configuration; after accepting: « Compte Facebook connecté à la nouvelle app » | ☐ | |
| B3 | Step 3 | Page YINVESTI « même Page que l'app actuelle », Instagram @yinvesti_ | ☐ | |
| B4 | « Lancer les tests » | Green banner « Tous les tests essentiels passent » | ☐ | |
| B5 | In the test list: « Compte Facebook connecté à la nouvelle app » | « connexion sans date d'expiration » (system-user token). A date means a user token: renewal every ~60 days | ☐ | Expiry: |
| B6 | « Autorisations : … » (3 groups) | Each group « N autorisation(s) accordée(s) » — no « manquantes », no « pas pour la ressource choisie » | ☐ | |
| B7 | « Mêmes numéros que les données enregistrées » | « Pas de doublon à la bascule ». Instagram people may be « reconnues … par nom d'utilisateur » (normal when Instagram was connected with Instagram Login) | ☐ | |
| B8 | « Compte Instagram » | « relié à la Page », possibly « même compte … avec un autre numéro : ses relevés seront renumérotés » | ☐ | |
| B9 | « Formulaires Lead Ads », « Compte publicitaire » | Forms and leads readable; campaigns readable | ☐ | |
| B10 | Drill — revoked token (optional, safe before the switch): Business Settings › Integrations › Connected apps › remove chatgptapp, then « Relancer les tests » | « L'app a été retirée … reconnectez-vous » on the connection line; production unaffected. Then B2 again and B4 green | ☐ | |

## C. Real-time with real messages, before the switch

Click « Activer le temps réel en parallèle » (step 4). The Page is then subscribed to both apps: every event
arrives twice and is stored once. Reload the page to refresh the counter « Envois reçus de la nouvelle app ».

| # | Step | Expected | ✓ | Notes |
|---|---|---|---|---|
| C1 | Read the « Vérifié chez Meta » lines | Green: objet Page de l'app · objet Instagram de l'app (after A4) · Page abonnée à l'app | ☐ | |
| C2 | From a tester's Facebook account, send a Messenger message to YINVESTI | Appears in Leads & Contacts › Messages within seconds, **once**; new-app counter +1 | ☐ | |
| C3 | Reply from Meta Business Suite | Reply shown in the thread; conversation counted as answered | ☐ | |
| C4 | From a tester, comment on a YINVESTI post | Appears in Leads & Contacts › Commentaires within seconds, once; author name shown or « Nom masqué par Meta » (normal before Advanced Access) | ☐ | |
| C5 | Delete that comment | Removed from the dashboard | ☐ | |
| C6 | From a tester's Instagram account, send a DM to @yinvesti_ | Appears in Messages (Instagram). If the counter does not move, it should still appear within 2 minutes (quick check) — note which | ☐ | webhook / quick check |
| C7 | Comment on an @yinvesti_ post | Appears in Commentaires. Meta only sends Instagram comment webhooks to Live apps with Advanced Access: before that, expect the quick check (≤ 2 min) | ☐ | webhook / quick check |
| C8 | Connexions › Temps réel | « Envois reçus par app » lists both apps | ☐ | |

## D. Insights, ads and Lead Ads with real data

| # | Step | Expected | ✓ | Notes |
|---|---|---|---|---|
| D1 | Social Media › Relevés, Facebook, yesterday vs Meta Business Suite › Insights (same day) | Same reach / views / new followers (Meta revises the last 2–3 days; compare an older day if needed) | ☐ | |
| D2 | Same for Instagram | Same reach / views / followers | ☐ | |
| D3 | Ads Manager (dashboard) vs Meta Ads Manager, yesterday, one campaign | Same impressions, clicks, results; spend × conversion rate set in Connexions | ☐ | |
| D4 | Lead Ads Testing Tool (developers.facebook.com/tools/lead-ads-testing): create a test lead on a YINVESTI form, then « Synchroniser maintenant » | The lead appears in Leads & Contacts with the form's answers; delete the test lead afterwards (dashboard and testing tool) | ☐ | |

## E. Switch (production)

| # | Step | Expected | ✓ | Notes |
|---|---|---|---|---|
| E1 | Re-run the tests (B4) less than an hour before; note the totals on Overview (messages, comments, leads) | Green | ☐ | Totals: |
| E2 | « Basculer vers la nouvelle app » | « Nouvelle app active · temps réel activé »; notes about the old app being unsubscribed (and Instagram stats renumbered, if any) | ☐ | |
| E3 | Connexions › Temps réel | All « Vérifié chez Meta » lines green; the Page is subscribed to the new app | ☐ | |
| E4 | « Synchroniser maintenant » | No error; totals unchanged except genuinely new items; no duplicate conversations, comments, posts, leads, daily stats | ☐ | |
| E5 | Repeat C2, C4, C6, C7 | Same results, counted for « app active » | ☐ | |
| E6 | Open a lead you had qualified/assigned and a campaign with a target | Status, assignee, target unchanged | ☐ | |
| E7 | Connexions › Facebook · Instagram · Ads › « Vérifier maintenant » | « acceptée · sans date d'expiration » | ☐ | |

## F. Rollback drill (recommended once, at a quiet time)

| # | Step | Expected | ✓ | Notes |
|---|---|---|---|---|
| F1 | Connexions › Nouvelle app Meta › « Revenir à l'ancienne app » | « Ancienne app rétablie » + « Nouvelle app désabonnée des webhooks de la Page » (+ Instagram stats renumbered back, if any) | ☐ | |
| F2 | Send a Messenger message | Arrives (old app) | ☐ | |
| F3 | Social Media › Relevés, Instagram | One row per day, no gaps, no doubles | ☐ | |
| F4 | Step 2 still « Connecté »; re-run tests; « Basculer » again | Back on the new app | ☐ | |

## G. After App Review and Live mode

| # | Step | Expected | ✓ | Notes |
|---|---|---|---|---|
| G1 | Advanced Access granted for the 15 permissions; Business Verification done; app switched to Live | — | ☐ | Date: |
| G2 | Connexions › Temps réel › « Réactiver » | All green | ☐ | |
| G3 | From an account **without** a role on the app: Messenger message, Facebook comment, Instagram DM, Instagram comment | All four arrive within seconds via webhook (counter moves); Facebook comment authors' names visible | ☐ | |
| G4 | After a few days without problems: « Terminer la migration » | Old app signatures refused from now on | ☐ | |
| G5 | Remove the old app: Business Settings › Integrations › Connected apps; Facebook › Settings › Business integrations; Instagram › Settings › Apps and websites | Done | ☐ | |

## Optional: alert drill on the live connection (disruptive, needs a reconnect)

Only if you want to see the alert e-mail: remove chatgptapp from Business Settings › Integrations › Connected
apps, then Connexions › « Vérifier maintenant ». Expected: red banner with the reason, syncs paused, one e-mail
to the report recipients. Fix: « Reconnecter » (B2) → « Vérifier maintenant » green, syncs resume.
