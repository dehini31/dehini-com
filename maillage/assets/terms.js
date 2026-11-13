/* Maillage — conditions d'utilisation (fenêtre partagée par les deux landing pages). */
(function () {
  'use strict';
  const { html } = ML;
  ML.actions.terms = () => ML.modal({
    title: ML.t('Conditions d\'utilisation'),
    wide: true,
    body: html`
      <div class="terms">
        <h3>${ML.t('Votre espace')}</h3>
        <p>${ML.t("Vous créez un compte pour une entreprise que vous représentez, avec des informations exactes. Vous êtes responsable de ce que vous publiez : points de vente, produits, prix et disponibilités.")}</p>
        <h3>${ML.t('Publication')}</h3>
        <p>${ML.t("Une inscription peut être vérifiée avant que le réseau n'apparaisse sur la carte. Un contenu trompeur, illicite ou sans rapport avec la vente de produits peut être retiré, et l'espace suspendu.")}</p>
        <h3>${ML.t('Commandes et réservations')}</h3>
        <p>${ML.t("La plateforme transmet la demande du client au point de vente qu'il a choisi ou, s'il commande sans en choisir, aux points de vente les plus proches de chez lui qui ont l'article : le premier qui la prend la traite. La vente se conclut entre le client et ce point de vente, qui confirme, prépare, encaisse (au retrait ou à la livraison) et assure le service après-vente.")}</p>
        <h3>${ML.t('Données')}</h3>
        <p>${ML.t("Les informations du compte (nom, email, téléphone) servent à gérer votre espace. Pour une commande, le nom, le téléphone et, s'ils sont fournis, l'email et l'adresse du client sont transmis au point de vente concerné, uniquement pour la traiter. Les recherches des visiteurs sont comptées sans leur identité, avec une position arrondie à la ville, pour produire les statistiques. Aucune donnée n'est revendue.")}</p>
        <h3>${ML.t('Suppression')}</h3>
        <p>${ML.t("Vous pouvez demander à tout moment la suppression de votre espace et de ses données.")}</p>
      </div>`,
    foot: html`<button class="btn btn-dark" type="button" data-close>${ML.t("J'ai compris")}</button>`,
  });
})();
