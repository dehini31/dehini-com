/*
 * Studioma — page de paiement (studioma/pay/?_ptxn=txn_…) : ouvre le paiement hébergé de Paddle pour la
 * transaction créée par le serveur (api.php › billing_checkout). Paiement terminé : retour aux réglages, où la
 * formule s'affiche dès que Paddle la confirme au serveur (webhook). Rien n'est activé depuis cette page.
 */
(function () {
  'use strict';
  var box = document.getElementById('pay');
  var say = function (title, text) {
    box.querySelector('[data-title]').textContent = title;
    box.querySelector('[data-text]').textContent = text;
  };
  var params = new URLSearchParams(location.search);
  var txn = params.get('_ptxn') || '';
  if (!/^txn_[A-Za-z0-9]+$/.test(txn)) { say('Nothing to pay here', 'Choose a plan on the pricing page to start a secure checkout.'); return; }
  // L'identifiant de transaction est retiré de l'adresse : Paddle.js ne doit pas ouvrir le paiement une seconde fois.
  history.replaceState(null, '', location.pathname);
  var completed = false;
  fetch('../api.php?action=billing_config', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (cfg) {
    if (!cfg || !cfg.available || !cfg.client_token) { say('Online payment isn\'t available yet', 'Paid plans open soon. Your Free plan keeps working.'); return; }
    var sc = document.createElement('script');
    sc.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
    sc.onerror = function () { say('The secure checkout could not load', 'Check your connection or turn off content blockers for this page, then reload it.'); };
    sc.onload = function () {
      if (cfg.env === 'sandbox') window.Paddle.Environment.set('sandbox');
      window.Paddle.Initialize({
        token: cfg.client_token,
        eventCallback: function (ev) {
          if (!ev || !ev.name) return;
          if (ev.name === 'checkout.completed') {
            completed = true;
            say('Payment received', 'We\'re activating your plan as soon as Paddle confirms it…');
            setTimeout(function () { location.href = '../app/#/settings/billing?checkout=done'; }, 1500);
          } else if (ev.name === 'checkout.closed' && !completed) {
            location.href = '../app/#/pricing';
          }
        },
      });
      var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
      window.Paddle.Checkout.open({ transactionId: txn, settings: { displayMode: 'overlay', theme: dark ? 'dark' : 'light', locale: 'en', allowLogout: false } });
      say('Secure checkout', 'Complete your payment in the Paddle window.');
    };
    document.head.appendChild(sc);
  }).catch(function () { say('Connection lost', 'Reload this page to try again.'); });
})();
