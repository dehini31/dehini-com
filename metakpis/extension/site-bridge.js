// Stats Meta KPIs · runs only on dehini.com/metakpis/.
// Lets the signed-in profile page hand this browser's extension its account token
// ("Connect the extension"), and tells the page whether the extension is installed and connected.
(() => {
  'use strict';
  const V = chrome.runtime.getManifest().version;
  const SITE = location.origin + location.pathname.replace(/[^/]*$/, '');
  document.documentElement.setAttribute('data-smk-ext', V);
  const post = m => window.postMessage(Object.assign({ smk: 'ext', version: V }, m), location.origin);
  async function status(type) {
    const s = await chrome.storage.local.get(['smk_token', 'smk_user']);
    post({ type, connected: !!s.smk_token, email: s.smk_user ? s.smk_user.email || '' : '' });
  }
  window.addEventListener('message', async e => {
    if (e.source !== window || e.origin !== location.origin || !e.data || e.data.smk !== 'page') return;
    const d = e.data;
    try {
      if (d.type === 'status') return status('status');
      if (d.type === 'connect' && typeof d.token === 'string' && /^[a-f0-9]{64}$/.test(d.token)) {
        const u = d.user && typeof d.user === 'object' ? d.user : {};
        const user = { name: String(u.name || '').slice(0, 80), email: String(u.email || '').slice(0, 190) };
        await chrome.storage.local.set({ smk_token: d.token, smk_user: user, smk_site: SITE });
        return post({ type: 'connected', email: user.email });
      }
      if (d.type === 'disconnect') {
        await chrome.storage.local.remove(['smk_token', 'smk_user']);
        return post({ type: 'disconnected' });
      }
    } catch (err) { /* extension reloaded: the page will ask again after a reload */ }
  });
  status('ready');
})();
