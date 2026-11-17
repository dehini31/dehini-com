# Meta app migration (ads analyse → chatgptapp)

How the marketing dashboard (`https://dehini.com/marketing`) moves from the current Meta app
(« ads analyse ») to the new one (« chatgptapp », App ID `3025910297765050`) without losing data, and what
has to be configured at Meta. The real-account test plan is in
[`meta-real-test-checklist.md`](meta-real-test-checklist.md).

Nothing changes in production until someone clicks « Basculer vers la nouvelle app » in
Connexions › Nouvelle app Meta, and « Revenir à l'ancienne app » undoes it until « Terminer la migration ».

Sources used (read 15 Nov 2026): Facebook Login for Business, System Users (install apps and generate
tokens), Conversations API, Messenger Platform overview, Instagram Platform webhooks (setup and fields),
Webhooks for Pages, Graph API `/{page-id}/subscribed_apps` and `/{app-id}/subscriptions` references, Lead Ads
retrieval, Page Insights, `debug_token`, Graph API error handling and rate limiting, Instagram Messaging
get started — all on developers.facebook.com.

---

## 1. Exact permissions

Only what the implemented features call. The dashboard is read-only: it never sends a message, publishes,
or creates/edits an ad. The same list is shown in the dashboard (card « Autorisations exactes »), stored in
`MK_META_PERMS` (`marketing/lib/meta.php`), and checked by the pre-switch tests.

### Page, comments, messages, Instagram, real time (required)

| Permission | Used for | Graph API calls | Why Meta requires it |
|---|---|---|---|
| `pages_show_list` | List the Pages to select YINVESTI | `GET /me/accounts` · `POST /{page}/subscribed_apps` | Needed by `subscribed_apps` (with `pages_manage_metadata`) |
| `business_management` | — (dependency) | — | Messenger Platform: “a dependency for pages_messaging, pages_show_list, and instagram_manage_messages” |
| `pages_read_engagement` | Page posts, reactions, comment counts | `GET /{page}`, `/{page}/published_posts`, `/{post}/comments` | Also required by Page Insights, the Conversations API and Instagram |
| `pages_read_user_content` | Facebook comments **with the author's name** | `GET /{post}/comments` (`from`) | User-generated content on the Page |
| `read_insights` | Page statistics (reach, views, follows) | `GET /{page}/insights` | Page Insights requirement (with `pages_read_engagement`) |
| `pages_manage_metadata` | Subscribe the Page to webhooks; read conversations | `POST/GET/DELETE /{page}/subscribed_apps` · `GET /{page}/conversations` | Conversations API (Messenger and Instagram) and `subscribed_apps` |
| `pages_messaging` | Read Messenger conversations; `messages` webhook | `GET /{page}/conversations?platform=messenger` | Conversations API + `messages` Page webhook field |
| `instagram_basic` | @yinvesti_ profile, media, stories, followers | `GET /{ig}`, `/{ig}/media`, `/{ig}/stories` | Base permission of every Instagram feature |
| `instagram_manage_comments` | Instagram comments (with username); `comments` webhook | `GET /{media}/comments` | Instagram `comments` webhook field |
| `instagram_manage_insights` | Instagram account and media statistics | `GET /{ig}/insights`, `/{media}/insights` | Instagram insights |
| `instagram_manage_messages` | Read Instagram Direct; `messages` webhook | `GET /{page}/conversations?platform=instagram` | Conversations API, Instagram messaging |

### Meta Ads (required because the current app reads an ad account)

| Permission | Used for | Graph API calls |
|---|---|---|
| `ads_read` | Ad accounts, campaigns, ad sets, results | `GET /me/adaccounts`, `/act_{id}/campaigns`, `/act_{id}/adsets`, `/act_{id}/insights` |

### Lead Ads (required when leads are already imported)

| Permission | Used for | Graph API calls | Why |
|---|---|---|---|
| `leads_retrieval` | Read Lead Ads prospects | `GET /{form}/leads` | “The leads_retrieval permission is required to read leads.” |
| `pages_manage_ads` | List the Page's lead forms | `GET /{page}/leadgen_forms` | Must be submitted together with `leads_retrieval` |
| `ads_management` | — (read only) | `GET /{form}/leads` with `ad_id`, `campaign_id` | Listed by Meta's *Retrieving Leads* guide for lead data and ad-level fields. The dashboard never writes to ads. |

Not requested anymore: nothing else. `email`, `public_profile`, publishing and ad-writing features are not used.

The token used for leads must belong to someone “who can advertise on the ad account and on the Page”
(Retrieving Leads), and Messenger reading needs a Page task that can manage messages.

### App Review texts (English, copy into « How will your app use this permission? »)

The same texts, with a copy button, are in the dashboard (card « Publication de l'app chez Meta »).

| Permission | Text |
|---|---|
| pages_show_list | Lists the Facebook Pages the business owner manages so they can pick the YINVESTI Page whose data is shown in their private marketing dashboard (dehini.com/marketing). Also required by Meta to subscribe the Page to webhooks. |
| business_management | Required by Meta as a dependency of pages_messaging, pages_show_list and instagram_manage_messages when access is granted through Facebook Login for Business. The app only reads the YINVESTI business assets selected during login. |
| pages_read_engagement | Reads the YINVESTI Page's posts, reactions and comment counts to display engagement statistics in the business's internal marketing dashboard. Also required for Page insights, conversations and the linked Instagram account. |
| pages_read_user_content | Reads comments left on the YINVESTI Page's posts, including the author's name, so the marketing team can see questions from prospective investors and answer them on Facebook. |
| read_insights | Reads Page insights (reach, views, follows) to build daily, weekly and monthly marketing reports for the YINVESTI team. |
| pages_manage_metadata | Subscribes the YINVESTI Page to webhooks so new messages and comments appear in the dashboard in real time; required by Meta to read the Page's conversations. Nothing is changed on the Page. |
| pages_messaging | Reads Messenger conversations of the YINVESTI Page and receives the messages webhook (read only: the app never sends messages) so the team sees who is waiting for an answer. |
| instagram_basic | Reads the YINVESTI Instagram professional account profile, media and stories to display posts and their engagement in the dashboard. |
| instagram_manage_comments | Reads comments (with the commenter's username) on the YINVESTI Instagram account's posts and receives the comments webhook (read only) so the team can follow and answer questions on Instagram. |
| instagram_manage_insights | Reads Instagram account and media insights (reach, views, interactions) for the marketing reports. |
| instagram_manage_messages | Reads Instagram Direct conversations of the YINVESTI account and receives the messages webhook (read only: no message is sent) to list unanswered messages and create leads. |
| ads_read | Reads the business's own ad accounts, campaigns, ad sets and their results (spend, impressions, clicks, leads) to compare campaigns in the marketing dashboard. |
| leads_retrieval | Retrieves leads submitted through the YINVESTI Page's Lead Ads forms into the business's private lead list so sales can call them back. |
| pages_manage_ads | Lists the YINVESTI Page's Lead Ads forms; Meta requires it together with leads_retrieval to read leads. No ad or form is created or changed. |
| ads_management | Listed by Meta's Lead Ads retrieval guide as required to read leads with their ad and campaign (ad_id, campaign_id). The app only reads: it never creates, edits or pauses ads. |

---

## 2. Facebook Login for Business configuration and the system-user token

### What to create (new app › Facebook Login for Business › Configurations)

- **Access token**: « Jeton d'accès d'utilisateur système » (business integration system user token), no expiration.
- **Permissions**: the 15 above (copy the list from the dashboard card).
- **Assets**: the YINVESTI Page, the @yinvesti_ Instagram account, the ad account(s). A system-user token
  “can only access the assets that were designated by your business client when they completed the
  Facebook Login for Business flow” — anything not ticked is invisible to the dashboard.
- Copy the configuration ID into `MK_META_NEXT_CONFIG_ID`.

### Does it support every feature? What was verified

| Check | Result | Source |
|---|---|---|
| All 15 permissions available to system-user tokens | ✓ — each is marked supported in the FLfB permissions table and in the system users “Supported scopes” list | FLfB; System users › Install apps and generate tokens |
| App type | Must be a Business app (or have a use case that supports FLfB) | FLfB |
| Ownership | The app must be associated with a business portfolio you fully control | FLfB |
| Login surface | System-user login is web only — `https://dehini.com/marketing/meta.php` is a web redirect ✓ | FLfB |
| Instagram conversations | “Your app must be owned by a verified business” | Conversations API |
| Real people (not app roles) | Advanced Access + Business Verification + Live mode | Messenger overview, Instagram webhooks |

**Open point to confirm on the real account**: the FLfB comparison table ends the system-user
column with the note “Tech Providers only.” It is not clear whether this limits the token type itself.
If the configuration screen does not offer the system-user token, or the login fails with it, use a
configuration with « Jeton d'accès utilisateur » instead: it works with the same 15 permissions, but expires
(about 60 days, data access 90 days). The dashboard shows both dates and warns 14 days before.

The pre-switch test reports what Meta actually issued (`debug_token`): validity, `expires_at`,
`data_access_expires_at`, granted scopes, and `granular_scopes` target IDs. That last point catches a token
whose permissions do not cover the YINVESTI Page, @yinvesti_ or the ad account.

### “Never expires” is not “never invalid”

Meta: “Facebook will not notify you that an access token has become invalid” and non-expiring tokens “are
still subject to invalidation for other reasons. This also applies to access tokens for System Users.” A
system-user token is invalidated when the business removes the app (Business Settings › Integrations ›
Connected apps), and can be invalidated for security reasons.

What the dashboard does (`mk_meta_token_check`, `mk_meta_err_info`):

| Situation | Detection | Behaviour |
|---|---|---|
| Token revoked / app removed / expired / password changed / checkpoint | `debug_token` `is_valid=false`, or error 190 / 102 with subcode 458, 459, 460, 463, 464, 467 | Clear reason shown, syncs paused (no data deleted), banner on Overview and Connexions, **one email** to report recipients (reminder after 3 days) |
| Account lost its role on the Page | error 190 subcode 492 | Same, with « redonnez-lui l'accès » |
| Page token refused but account token valid | Page probe fails with 190 | New Page token fetched automatically (`/me/accounts`), noted in the health box |
| Permission removed | error 10 / 200–299, or a scope missing compared with the first check | Warning listing the withdrawn permission(s) |
| Rate limited | codes 4, 17, 32, 341, 613, 80000–80014 | Sync stops, automatic syncs wait 15 minutes |
| Expiring token (user-token config) | `expires_at` / `data_access_expires_at` within 14 days | Warning |

Checks run at most every 6 hours (every 30 minutes while refused), before each sync, and on demand
(« Vérifier maintenant »). Re-connecting clears the incident.

---

## 3. Webhooks — what is configured, and how it is verified

Callback URL `https://dehini.com/marketing/webhook.php`; verify token = `MK_META_WEBHOOK_VERIFY_TOKEN`
from `config.php`. Every POST must carry a valid `X-Hub-Signature-256` (HMAC-SHA256 with an app secret),
otherwise it is rejected with 403.

| Level | What | How | Verified by reading back |
|---|---|---|---|
| App · object `page` | fields `messages`, `message_echoes`, `feed` | Automatic: `POST /{app-id}/subscriptions` (app token) with `callback_url`, `verify_token`, `fields`. Meta calls the URL with the verify token before accepting. | `GET /{app-id}/subscriptions`: active, same callback URL, all fields |
| App · object `instagram` | fields `comments`, `messages` | **Manual, once**: Meta's API reference says “Webhooks for Instagram is not supported. Instagram webhooks must be configured using the App Dashboard.” The dashboard tries the API anyway and, if refused, shows it as a manual step. | `GET /{app-id}/subscriptions` (shows the `instagram` object once set in the App Dashboard) |
| Page | app installed on the YINVESTI Page with `messages`, `message_echoes`, `feed` | Automatic: `POST /{page-id}/subscribed_apps` (Page token + `appsecret_proof`) | `GET /{page-id}/subscribed_apps`: the app is listed with the 3 fields |
| Instagram account (Page-linked) | — | Delivered through the Page installation (“Send a POST request to the linked Page's /subscribed_apps edge”) | Real message / comment test |
| Instagram account settings | « Autoriser l'accès aux messages » | Manual, in the Instagram app: Paramètres › Messages et réponses aux stories › Contrôle des messages › Outils connectés | Real message test |

Where to see it: Connexions › Nouvelle app Meta, step 4 (« Activer le temps réel en parallèle »), and
Connexions › Temps réel after the switch. Each step shows « Vérifié chez Meta : … » and the count of
webhooks received **per app**.

Delivery limits from Meta: in Development mode only people with a role on the app trigger webhooks;
Instagram comment notifications need Advanced Access and a Live app; private Instagram accounts get no comment
notifications. The 2-minute quick check and the hourly sync keep importing in the meantime.

### Migration sequence

1. **Before the switch** (« Activer le temps réel en parallèle »): the new app gets its app-level `page`
   subscription and the Page is subscribed to **both** apps. Each event arrives twice and is stored once
   (same message / comment ID). The per-app counter proves the new app delivers.
2. **Switch**: the old app is removed from the Page (`DELETE /{page-id}/subscribed_apps` with its own
   `appsecret_proof`), the new one is (re)subscribed and everything is read back.
3. **Trial period**: signatures of the old app (and its Instagram app) are still accepted — an Instagram
   Login subscription of the old app may keep sending — without duplicates.
4. **Rollback**: the new app is removed from the Page, the old one re-subscribed; the new app's connection
   stays ready for another attempt.
5. **« Terminer la migration »**: old signatures are refused from then on; remove the old app at Meta.

---

## 4. The existing Instagram connection

Today Instagram may be connected with « Se connecter avec Instagram » (Instagram API with Instagram Login:
`graph.instagram.com`, a separate Instagram app ID/secret, a 60-day token refreshed at each sync).
After the switch Instagram is read **through the YINVESTI Page** with the new app (Instagram API with
Facebook Login: `graph.facebook.com`, the Page token, no separate Instagram token). @yinvesti_ must be linked
to the Page (Meta Business Suite › Paramètres › Comptes Instagram).

Meta's documentation does not say whether media, comment, conversation and person IDs are identical in the
two APIs, so the migration does not assume it:

| Data | Key | Safeguard |
|---|---|---|
| Posts / media, comments | Meta object ID | Pre-switch test compares the IDs read by the new app with those stored; **blocks the switch** if none match where data exists |
| Daily Instagram stats | account ID | If the Page-linked account has another ID for the same username, rows are renumbered at the switch (never overwriting) and back on rollback |
| Conversations | conversation ID, then person | Matched by person ID, or for Instagram by username; one conversation per person, it takes the new ID |
| Messages | message ID | A message with the same text, direction and time in the same conversation is not copied again |
| Old Instagram Login token | — | Kept for rollback; its webhooks accepted until « Terminer la migration », then dropped |
| Manual data (status, assignee, targets, funnels) | — | Never written by sync (only Meta-owned fields are updated) |

After « Terminer la migration », remove the old app from Instagram (Paramètres › Apps et sites web) and from
the business (Paramètres de l'entreprise › Intégrations › Apps connectées).

---

## 5. Server configuration (`~/public_html/marketing/config.php`, never in Git)

```php
define('MK_META_NEXT_APP_ID', '3025910297765050');
define('MK_META_NEXT_APP_SECRET', '… entered on the server only …');
define('MK_META_NEXT_CONFIG_ID', '… Facebook Login for Business configuration ID …');
define('MK_META_WEBHOOK_VERIFY_TOKEN', '… random text, 20+ characters, same as in the App Dashboard …');
```

The secret is never sent to the browser or logged; Meta tokens are stored encrypted (AES-256-GCM, key
outside the public folder).

New app settings (developers.facebook.com):

| Where | Value |
|---|---|
| Settings › Basic | App domain `dehini.com`; Privacy Policy URL `https://dehini.com/marketing/confidentialite.html`; Data deletion instructions URL `https://dehini.com/marketing/suppression-donnees.html`; icon; category |
| Facebook Login for Business › Settings | Valid OAuth redirect URI `https://dehini.com/marketing/meta.php` |
| Facebook Login for Business › Configurations | Section 2 |
| Webhooks › Instagram (or Instagram use case › Configure webhooks) | Callback URL and verify token above; subscribe `comments` and `messages` |
| Use cases | Messenger, Instagram API (Facebook Login), Pages, Marketing API / Lead Ads |

---

## 6. App Review, Business Verification, Live mode

1. Business Verification (Business Settings › Security Center) — required for Advanced Access and for
   Instagram conversations.
2. App Review › Permissions and features › « Request advanced access » for each permission in section 1,
   with the texts above and a screencast.
3. When approved: switch the app to **Live**, then Connexions › Temps réel › « Réactiver ».

Screencast script (one video can cover everything):

1. Open `https://dehini.com/marketing` and sign in.
2. Connexions › « Se connecter avec la nouvelle app »: the Meta dialog, the assets and permissions granted.
3. Leads & Contacts › Messages (pages_messaging, instagram_manage_messages, pages_manage_metadata): open a
   conversation, « Créer le prospect ».
4. Leads & Contacts › Commentaires (pages_read_user_content, instagram_manage_comments): a comment and its author.
5. Social Media and Communication (pages_read_engagement, read_insights, instagram_basic, instagram_manage_insights).
6. Ads Manager (ads_read) and Leads (leads_retrieval, pages_manage_ads, ads_management).
7. Say on screen that the app is read-only: it sends no message, publishes nothing, changes no ad.
