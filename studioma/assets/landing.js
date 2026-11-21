/*
 * Studioma — page d'accueil : une personne déjà connectée voit « Open my workspace » à la place
 * des boutons d'inscription. Lien de parrainage (?ref=…) : le clic est envoyé au serveur, qui pose
 * l'attribution (30 jours) ; l'offre de lancement est masquée si elle est fermée. Sans JavaScript, la
 * page reste entièrement utilisable.
 */
(function () {
  'use strict';
  var post = function (action, body) {
    return fetch('api.php?action=' + action, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma' }, body: JSON.stringify(body) });
  };
  var params = new URLSearchParams(location.search);
  var code = params.get('ref');
  if (code && /^[a-z2-9]{8}$/.test(code)) {
    post('ref_click', { code: code }).catch(function () {});
    // L'adresse reste propre (le code n'a plus d'utilité dans la barre d'adresse une fois envoyé).
    if (history.replaceState) history.replaceState(null, '', location.pathname + location.hash);
  }
  fetch('api.php?action=billing_plans', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
    if (d && d.promo === false) document.querySelectorAll('[data-promo]').forEach(function (s) { s.hidden = true; });
  }).catch(function () {});
  // « Start Inviting » sans compte : après l'inscription, la page de parrainage.
  document.querySelectorAll('[data-auth="refer"]').forEach(function (a) {
    a.addEventListener('click', function () { try { sessionStorage.setItem('stm.next', '/refer'); } catch (e) { /* navigation privée */ } });
  });
  fetch('api.php?action=me', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d || !d.user) return;
    document.querySelectorAll('[data-auth]').forEach(function (a) {
      if (a.dataset.auth === 'signup') { a.textContent = 'Open my workspace'; a.href = 'app/#/'; }
      else if (a.dataset.auth === 'refer') { a.textContent = 'Open my referral link'; a.href = 'app/#/refer'; }
      else a.hidden = true;
    });
  }).catch(function () { /* hors ligne : la page reste telle quelle */ });
  // Présence pour l'administration (visiteurs en ce moment) : « landing », au chargement puis chaque minute.
  var ping = function () {
    if (document.hidden) return;
    fetch('api.php?action=ping', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'studioma' }, body: '{"p":"landing"}' }).catch(function () {});
  };
  ping();
  setInterval(ping, 60000);
})();
