/*
 * Studioma — retour de PayPal (studioma/pay/?paypal=return|cancel). PayPal renvoie ici après l'accord (ou
 * l'abandon) du paiement ; la page renvoie aussitôt vers l'application, où Réglages › Plan & billing demande au
 * serveur de relire l'abonnement chez PayPal (billing_confirm). Rien n'est activé depuis cette page.
 */
(function () {
  'use strict';
  var box = document.getElementById('pay');
  var say = function (title, text) {
    box.querySelector('[data-title]').textContent = title;
    box.querySelector('[data-text]').textContent = text;
  };
  var params = new URLSearchParams(location.search);
  var back = params.get('paypal');
  if (back === 'return') {
    var sub = params.get('subscription_id') || '';
    say('Payment approved', 'Taking you back to Studioma to confirm your plan with PayPal…');
    location.replace('../app/#/settings/billing?checkout=return' + (/^I-[A-Z0-9]{6,40}$/.test(sub) ? '&sub=' + sub : ''));
  } else if (back === 'cancel') {
    say('Checkout canceled', 'Nothing was charged. Taking you back to the plans…');
    location.replace('../app/#/pricing?checkout=canceled');
  } else {
    say('Nothing to pay here', 'Choose a plan on the pricing page: payment happens securely on PayPal.');
  }
})();
