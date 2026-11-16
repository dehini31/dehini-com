/*
 * Studioma — page d'accueil : une personne déjà connectée voit « Open my workspace » à la place
 * des boutons d'inscription. Sans JavaScript, la page reste entièrement utilisable.
 */
(function () {
  'use strict';
  fetch('api.php?action=me', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
    if (!d || !d.user) return;
    document.querySelectorAll('[data-auth]').forEach(function (a) {
      if (a.dataset.auth === 'signup') { a.textContent = 'Open my workspace'; a.href = 'app/#/'; } else a.hidden = true;
    });
  }).catch(function () { /* hors ligne : la page reste telle quelle */ });
})();
