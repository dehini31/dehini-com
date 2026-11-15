<?php
/**
 * Modèle de configuration du Marketing Dashboard — facultatif, NE PAS committer config.php.
 *
 * Tout se règle normalement dans https://dehini.com/marketing/ → Réglages (destinataires des
 * rapports, commerciaux, devise). Ce fichier ne sert que pour des cas particuliers : copie-le en
 * marketing/config.php (exclu de Git, bloqué par .htaccess) et décommente ce dont tu as besoin.
 */

// Adresse publique du tableau de bord (sert au lien des emails de rapport).
// define('MK_SITE_URL', 'https://dehini.com/marketing/');

// Emplacement de la base (par défaut ~/marketing-data/marketing.sqlite, hors public_html).
// define('MK_DB_PATH', '/home/dehini91/marketing-data/marketing.sqlite');

// Emails : par défaut, le compte SMTP du défi Impact (impact/config.php) est réutilisé.
// define('MK_SMTP_HOST', 'mail.dehini.com');
// define('MK_SMTP_PORT', 465);
// define('MK_SMTP_SECURE', 'ssl');
// define('MK_SMTP_USER', 'marketing@dehini.com');
// define('MK_SMTP_PASS', 'mot-de-passe-du-compte-email');
// define('MK_MAIL_FROM', 'marketing@dehini.com');

// Connexion Meta (Facebook, Instagram, Meta Ads) : l'app Meta se règle normalement dans le tableau de
// bord (Connexions), ou est reprise d'ecom.dehini.com (~/ecom/.env : META_APP_ID / META_APP_SECRET).
// define('MK_META_APP_ID', '1234567890123456');
// define('MK_META_APP_SECRET', '0123456789abcdef0123456789abcdef');
//
// Nouvelle app Meta à préparer et tester avant de basculer (Connexions › Nouvelle app Meta). Le tableau de
// bord continue avec l'app actuelle jusqu'au clic sur « Basculer » ; la clé secrète reste dans ce fichier.
// define('MK_META_NEXT_APP_ID', '3025910297765050');
// define('MK_META_NEXT_APP_SECRET', 'clé secrète de 32 caractères (Paramètres › Général)');
// define('MK_META_NEXT_CONFIG_ID', 'ID de configuration Facebook Login for Business (facultatif)');
//
// Jeton de vérification des webhooks : le même que dans l'app Meta › Webhooks (texte libre, 20 caractères ou plus).
// define('MK_META_WEBHOOK_VERIFY_TOKEN', 'un-texte-secret-au-choix');
// define('MK_META_GRAPH_VERSION', 'v24.0');
