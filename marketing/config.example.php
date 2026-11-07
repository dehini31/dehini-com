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
