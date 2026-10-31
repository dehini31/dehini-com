// Stats Meta KPIs · bridge between the Ads Manager page (MAIN world dashboard) and the extension.
// Runs in the isolated world: relays a short list of API actions to background.js and tells the
// dashboard when the account connection changes. The account token is never sent to the page.
(() => {
  'use strict';
  const ORIGIN = location.origin;
  const ACTIONS = new Set(['whoami', 'scan', 'export', 'source', 'create', 'proposal_status', 'accounts']);
  const post = m => window.postMessage(m, ORIGIN);

  window.addEventListener('message', async e => {
    if (e.source !== window || !e.data || e.data.__smk !== 'req' || typeof e.data.id !== 'string') return;
    const { id, kind } = e.data;
    let res;
    try {
      if (kind === 'auth') {
        const s = await chrome.storage.local.get(['smk_token', 'smk_user', 'smk_site']);
        res = { connected: !!s.smk_token, user: s.smk_user || null, site: s.smk_site || null };
      } else if (kind === 'api' && ACTIONS.has(e.data.action)) {
        res = await chrome.runtime.sendMessage({ type: 'smk-api', action: e.data.action, payload: e.data.payload || {} });
      } else res = { error: 'generic' };
    } catch (err) {
      res = { error: 'bridge' }; // extension reloaded or updated: the page must be reloaded
    }
    post({ __smk: 'res', id, res: res || { error: 'generic' } });
  });

  chrome.storage.onChanged.addListener((ch, area) => {
    if (area === 'local' && (ch.smk_token || ch.smk_user)) post({ __smk: 'auth-changed' });
  });
})();
