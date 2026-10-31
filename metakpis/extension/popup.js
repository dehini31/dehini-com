'use strict';
const FR = /^fr/i.test(chrome.i18n.getUILanguage() || '');
const T = FR ? {
  on: 'Connectée à votre compte', off: 'Non connectée', hint: 'Connectez-vous sur dehini.com pour utiliser l\'extension dans Ads Manager.',
  am: 'Ouvrir Meta Ads Manager', profile: 'Mon profil', login: 'Se connecter / créer un compte', logout: 'Déconnecter cette extension', key: 'Raccourci dans Ads Manager : Alt + K',
} : {
  on: 'Connected to your account', off: 'Not connected', hint: 'Sign in on dehini.com to use the extension in Ads Manager.',
  am: 'Open Meta Ads Manager', profile: 'My profile', login: 'Sign in / create an account', logout: 'Disconnect this extension', key: 'Shortcut in Ads Manager: Alt + K',
};
const LANG = FR ? 'fr' : 'en';
const SITE_OK = /^https:\/\/(www\.)?dehini\.com\/metakpis\/$|^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?\/(.+\/)?metakpis\/$/;
const $ = s => document.querySelector(s);
const open = url => { chrome.tabs.create({ url }); window.close(); };
const btn = (cls, txt, fn) => { const b = document.createElement('button'); b.className = 'b ' + cls; b.textContent = txt; b.onclick = fn; return b; };

(async () => {
  const s = await chrome.storage.local.get(['smk_token', 'smk_user', 'smk_site']);
  const site = typeof s.smk_site === 'string' && SITE_OK.test(s.smk_site) ? s.smk_site : 'https://dehini.com/metakpis/';
  const on = !!s.smk_token;
  $('#st').className = 'st ' + (on ? 'on' : 'off');
  $('#stTxt').textContent = on ? T.on : T.off;
  $('#who').textContent = on ? [s.smk_user && s.smk_user.name, s.smk_user && s.smk_user.email].filter(Boolean).join(' · ') : T.hint;
  const acts = $('#acts');
  if (on) {
    acts.append(btn('pri', T.am, () => open('https://adsmanager.facebook.com/adsmanager/manage/campaigns')));
    acts.append(btn('', T.profile, () => open(site + '?lang=' + LANG + '#profile')));
    acts.append(btn('lnk', T.logout, async () => {
      try { await chrome.runtime.sendMessage({ type: 'smk-api', action: 'ext_logout', payload: {} }); } catch (e) { }
      await chrome.storage.local.remove(['smk_token', 'smk_user']);
      location.reload();
    }));
  } else {
    acts.append(btn('pri', T.login, () => open(site + '?connect=extension&lang=' + LANG + '#login')));
  }
  $('#ft').textContent = T.key + ' · v' + chrome.runtime.getManifest().version;
})();
