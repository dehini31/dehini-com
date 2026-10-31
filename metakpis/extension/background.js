// Stats Meta KPIs · service worker
// The only part of the extension that talks to the server (dehini.com/metakpis/api.php).
// The account token stays in chrome.storage and never reaches the Ads Manager page.
'use strict';

const SITE_DEFAULT = 'https://dehini.com/metakpis/';
const SITE_OK = /^https:\/\/(www\.)?dehini\.com\/metakpis\/$|^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/(.+\/)?metakpis\/$/;
const ACTIONS = new Set(['whoami', 'scan', 'export', 'source', 'create', 'proposal_status', 'accounts', 'ext_logout']);
const FROM_PAGES = /^https:\/\/(adsmanager|business)\.facebook\.com\//;

async function site() {
  const { smk_site } = await chrome.storage.local.get('smk_site');
  return typeof smk_site === 'string' && SITE_OK.test(smk_site) ? smk_site : SITE_DEFAULT;
}

async function call(action, payload) {
  const { smk_token } = await chrome.storage.local.get('smk_token');
  if (!smk_token) return { error: 'auth' };
  let res;
  try {
    res = await fetch((await site()) + 'api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'omit',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'metakpis',
        'Authorization': 'Bearer ' + smk_token,
        'X-SMK-Token': smk_token, // for hosts that strip the Authorization header
        'X-SMK-Version': chrome.runtime.getManifest().version,
      },
      body: JSON.stringify(payload || {}),
    });
  } catch (e) {
    return { error: 'network' };
  }
  let data;
  try { data = await res.json(); } catch (e) { data = { error: 'generic', status: res.status }; }
  if (!data || typeof data !== 'object') data = { error: 'generic' };
  // Token revoked from the profile, or account deleted: forget it, the panel shows the sign-in screen.
  if (res.status === 401 && data.error === 'auth') await chrome.storage.local.remove(['smk_token', 'smk_user']);
  if (data.ok && action === 'whoami' && data.user) {
    await chrome.storage.local.set({ smk_user: { name: data.user.name, email: data.user.email } });
  }
  return data;
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (sender.id !== chrome.runtime.id || !msg || msg.type !== 'smk-api' || !ACTIONS.has(msg.action)) return false;
  // Content scripts may only call from Ads Manager; the popup (no tab) may call too.
  if (sender.tab && !FROM_PAGES.test(sender.url || '')) return false;
  call(msg.action, msg.payload).then(sendResponse, () => sendResponse({ error: 'generic' }));
  return true;
});
