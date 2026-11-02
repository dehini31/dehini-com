<?php
/**
 * Modèle de configuration de SWANBIDA — facultatif, NE PAS committer config.php.
 *
 * Presque tout se règle dans l'administration (https://dehini.com/swanbida/admin/). Ce fichier
 * sert seulement pour des cas particuliers : copie-le en swanbida/config.php sur le serveur
 * (exclu de Git, bloqué par .htaccess) et décommente ce dont tu as besoin.
 */

// Adresse publique (sert aux liens des emails et de Telegram).
// define('SWB_SITE_URL', 'https://dehini.com/swanbida/');

// Dossier des données (base SQLite, clé de chiffrement, sauvegardes) — hors public_html.
// define('SWB_DATA_DIR', '/home/dehini91/swanbida-data');

// Mot de passe propriétaire propre à SWANBIDA (sinon : celui de /admin, dans admin/config.php).
// Générer le hash : php -r 'echo password_hash("ton-mot-de-passe", PASSWORD_DEFAULT), "\n";'
// define('SWB_OWNER_PASSWORD_HASH', '$2y$10$...');

// Meta : pixel et jeton Conversions API (sinon : admin → Meta & suivi). Le jeton ne doit jamais aller sur GitHub.
// define('SWB_META_PIXEL_ID', '123456789012345');
// define('SWB_META_CAPI_TOKEN', 'EAAB...');

// Emails : par défaut, le compte SMTP du défi Impact (impact/config.php) est réutilisé.
// define('SWB_SMTP_HOST', 'mail.dehini.com');
// define('SWB_SMTP_PORT', 465);
// define('SWB_SMTP_SECURE', 'ssl');
// define('SWB_SMTP_USER', 'swanbida@dehini.com');
// define('SWB_SMTP_PASS', 'mot-de-passe-du-compte-email');
// define('SWB_MAIL_FROM', 'swanbida@dehini.com');

// Migration vers un autre hébergement : redirige tout ce déploiement (avec les paramètres
// d'URL) vers le même chemin sur le nouveau domaine. À activer une fois le nouveau site
// vérifié en ligne ; retirer cette ligne ramène l'ancien site tel quel.
// define('SWB_REDIRECT_TO', 'https://swanbida.com');
