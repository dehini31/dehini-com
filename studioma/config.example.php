<?php
/**
 * Modèle de configuration de Studioma — facultatif, NE PAS committer config.php.
 *
 * Tout fonctionne sans ce fichier. Il ne sert que pour des cas particuliers : copie-le en
 * studioma/config.php (exclu de Git, bloqué par .htaccess) et décommente ce dont tu as besoin.
 */

// Adresse publique de la plateforme (sert aux liens des emails et des invitations).
// define('STM_SITE_URL', 'https://dehini.com/studioma/');

// Dossier des données : la base et les fichiers envoyés (par défaut ~/studioma-data, hors public_html).
// define('STM_DATA_DIR', '/home/dehini91/studioma-data');

// Taille maximale d'une vidéo ou d'une pièce jointe, en Mo (à accorder avec studioma/.user.ini).
// define('STM_MAX_UPLOAD_MB', 64);

// Durée de validité d'une invitation, en jours.
// define('STM_INVITE_DAYS', 14);

// « Continue with Google » : par défaut, l'ID client du défi Impact est repris (même origine
// https://dehini.com). Pour un ID client propre à Studioma :
// define('STM_GOOGLE_CLIENT_ID', '1234567890-abc.apps.googleusercontent.com');
// Pour ne pas reprendre celui du défi Impact (et masquer le bouton si aucun ID n'est défini ici) :
// define('STM_GOOGLE_FROM_IMPACT', false);

// Emails : par défaut, le compte SMTP du défi Impact (impact/config.php) est réutilisé.
// Meta Pixel + API Conversions (lib/meta.php). L'ensemble de données est déjà réglé dans le code. Le jeton
// peut aussi être mis dans le fichier meta-token.txt du dossier des données (conseillé : hors du site).
// define('STM_META_PIXEL_ID', '1718566156353795');
// define('STM_META_TOKEN', 'EAAG…');
// define('STM_META_TEST_CODE', 'TEST12345');   // onglet « Événements de test », à retirer ensuite

// define('STM_SMTP_HOST', 'mail.dehini.com');
// define('STM_SMTP_PORT', 465);
// define('STM_SMTP_SECURE', 'ssl');
// define('STM_SMTP_USER', 'studioma@dehini.com');
// define('STM_SMTP_PASS', 'mot-de-passe-du-compte-email');
// define('STM_MAIL_FROM', 'studioma@dehini.com');
// Campagnes de l'administration : au plus tant d'emails par heure (tous emails confondus). Rester sous la
// limite d'envoi de l'hébergeur.
// define('STM_MAIL_PER_HOUR', 200);

// Formules et abonnements (lib/billing.php, lib/paddle.php ; guide complet : docs/billing.md).
// Limites des formules Free / Solo / Team appliquées par le serveur (true par défaut). false = tout permis
// (interrupteur de secours ; les compteurs continuent d'être tenus).
// define('STM_PLANS_ENFORCED', true);
// Offre de lancement (lib/promo.php) : 30 jours d'accès complet à l'inscription et 1 an offert pour 10 amis vérifiés,
// sans carte ni paiement (true par défaut). false = plus de nouvel essai ni de récompense ; les accès déjà accordés
// continuent. Règles anti-abus du parrainage (0 = coupée) : comptes du même parrain depuis la même adresse IP en
// 7 jours (3), parrainages vérifiés en une heure (10) — au-delà, « à examiner » dans l'administration.
// define('STM_LAUNCH_PROMO', true);
// define('STM_REFERRAL_SAME_IP_MAX', 3);
// define('STM_REFERRAL_BURST_MAX', 10);
// Paiement en ligne avec Paddle Billing : reste fermé tant que STM_BILLING_ENABLED n'est pas true ET que tous les
// réglages ci-dessous sont remplis. Commencer en « sandbox » ; « production » seulement après l'approbation du
// compte Paddle et un paiement de test réussi. Ces valeurs sont secrètes (sauf le jeton client) : jamais dans Git.
// define('STM_BILLING_ENABLED', true);
// define('STM_PADDLE_ENV', 'sandbox');                         // sandbox | production
// define('STM_PADDLE_API_KEY', 'pdl_sdbx_apikey_…');           // Paddle › Developer tools › Authentication (secret)
// define('STM_PADDLE_CLIENT_TOKEN', 'test_…');                 // jeton client (public, pour Paddle.js)
// define('STM_PADDLE_WEBHOOK_SECRET', 'pdl_ntfset_…');         // secret de la destination de notifications (webhook)
// define('STM_PADDLE_PRICE_SOLO_MONTHLY', 'pri_…');            // 12 $ / mois
// define('STM_PADDLE_PRICE_SOLO_YEARLY', 'pri_…');             // 120 $ / an
// define('STM_PADDLE_PRICE_TEAM_MONTHLY', 'pri_…');            // 29 $ / mois
// define('STM_PADDLE_PRICE_TEAM_YEARLY', 'pri_…');             // 290 $ / an
// Pages légales publiques (/studioma/terms/, /privacy/, /refunds/, voir p.php). Valeurs par défaut dans lib/core.php,
// choisies par le propriétaire ; le nom doit correspondre au compte Paddle. Changer STM_LEGAL_UPDATED à chaque
// modification du texte des pages.
// define('STM_LEGAL_NAME', 'Mohammed Dehini');
// define('STM_LEGAL_EMAIL', 'contact@dehini.com');
// define('STM_LEGAL_COUNTRY', 'Algeria');
// define('STM_LEGAL_UPDATED', '2026-11-23');
// define('STM_MIN_AGE', 16);
// define('STM_REFUND_DAYS', 14);
