<?php
/**
 * Modèle de configuration de Stats Meta KPIs — facultatif, NE PAS committer config.php.
 *
 * Tout se règle normalement dans https://dehini.com/metakpis/admin.html (Google, app Meta).
 * Ce fichier ne sert que pour des cas particuliers : copie-le en metakpis/config.php
 * (exclu de Git, bloqué par .htaccess) et décommente ce dont tu as besoin.
 */

// Adresse publique (sert aux liens des emails « mot de passe oublié » et au retour de Facebook).
// define('METAKPIS_SITE_URL', 'https://dehini.com/metakpis/');

// Emails : par défaut, le compte SMTP du défi Impact (impact/config.php) est réutilisé.
// define('METAKPIS_SMTP_HOST', 'mail.dehini.com');
// define('METAKPIS_SMTP_PORT', 465);
// define('METAKPIS_SMTP_SECURE', 'ssl');
// define('METAKPIS_SMTP_USER', 'metakpis@dehini.com');
// define('METAKPIS_SMTP_PASS', 'mot-de-passe-du-compte-email');
// define('METAKPIS_MAIL_FROM', 'metakpis@dehini.com');

// App Meta (sinon : admin.html → Réglages). La clé secrète ne doit jamais aller sur GitHub.
// define('METAKPIS_META_APP_ID', '123456789012345');
// define('METAKPIS_META_APP_SECRET', '0123456789abcdef0123456789abcdef');

// Version de l'API Marketing de Meta.
// define('METAKPIS_GRAPH_VERSION', 'v24.0');
