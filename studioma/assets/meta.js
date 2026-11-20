/*
 * Studioma — pixel Meta (même ensemble de données que l'API Conversions, voir lib/meta.php).
 *
 * Le script de Meta est chargé sans code en ligne (la politique de sécurité l'interdit). Les événements
 * des actions arrivent dans les réponses de l'API (« _meta ») avec leur event_id : le serveur envoie les
 * mêmes, Meta n'en garde qu'un. PageView part à chaque écran. Les événements automatiques de Meta sont
 * coupés, et le pixel se tait sur les écrans dont l'adresse contient une clé secrète (lien de partage,
 * invitation, mot de passe oublié, confirmation d'email, accord d'un assistant IA).
 *
 * Page d'accueil : <script src="assets/meta.js" data-auto> (l'identifiant vient de l'API).
 */
(function () {
  'use strict';
  const SENSITIVE = /^#\/(s|join|reset|confirm|connect)\//;
  const M = window.StmMeta = { id: '', user: null, on: false };
  M.safe = () => !SENSITIVE.test(location.hash);
  /** Prépare le pixel ; il ne démarre qu'au premier écran sans clé secrète. */
  M.setup = (pixelId, user) => {
    if (!/^\d{5,20}$/.test(String(pixelId || ''))) return;
    M.id = String(pixelId);
    M.user = user || null;
  };
  function start() {
    if (M.on || !M.id || !M.safe()) return M.on;
    if (!window.fbq) {
      // Équivalent du code de Meta, sans script en ligne.
      const n = window.fbq = function () { if (n.callMethod) n.callMethod.apply(n, arguments); else n.queue.push(arguments); };
      if (!window._fbq) window._fbq = n;
      n.push = n; n.loaded = true; n.version = '2.0'; n.queue = [];
      const t = document.createElement('script');
      t.async = true;
      t.src = 'https://connect.facebook.net/en_US/fbevents.js';
      document.head.appendChild(t);
    }
    window.fbq('set', 'autoConfig', false, M.id);   // pas d'événements automatiques (clics, microdonnées)
    window.fbq('init', M.id, M.user && M.user.email ? { em: String(M.user.email).toLowerCase() } : {});
    M.on = true;
    return true;
  }
  M.page = () => { if (start()) window.fbq('track', 'PageView'); };
  /** Événements renvoyés par l'API : même nom, mêmes données, même event_id que côté serveur. */
  M.fire = (list) => {
    if (!Array.isArray(list) || !start()) return;
    list.forEach((e) => { try { window.fbq(e.std ? 'track' : 'trackCustom', e.name, e.data || {}, { eventID: e.id }); } catch (x) { /* pixel bloqué */ } });
  };
  M.custom = (name, data) => { if (start()) window.fbq('trackCustom', name, data || {}); };

  // Page d'accueil : identifiant demandé à l'API, PageView, et départ vers l'inscription.
  const me = document.currentScript;
  if (me && me.hasAttribute('data-auto')) {
    fetch(new URL('api.php?action=pixel', me.src.replace(/assets\/meta\.js.*$/, '')), { credentials: 'same-origin' })
      .then((r) => r.json()).then((d) => { M.setup(d.pixel); M.page(); }).catch(() => {});
    document.addEventListener('click', (e) => {
      const a = e.target.closest && e.target.closest('a[href*="#/signup"]');
      if (a) M.custom('StartSignup', { content_name: /company/.test(a.getAttribute('href') + a.textContent) ? 'company' : 'individual' });
    });
  }
})();
