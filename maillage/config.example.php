<?php
/**
 * Modèle de configuration de Maillage — facultatif, NE PAS committer config.php.
 *
 * Tout fonctionne sans ce fichier. Il ne sert que pour des cas particuliers : copie-le en
 * maillage/config.php (exclu de Git, bloqué par .htaccess) et décommente ce dont tu as besoin.
 */

// Adresse publique de la plateforme (sert aux liens des emails).
// define('MLG_SITE_URL', 'https://dehini.com/maillage/');

// Emplacement de la base (par défaut ~/maillage-data/maillage.sqlite, hors public_html).
// define('MLG_DB_PATH', '/home/dehini91/maillage-data/maillage.sqlite');

// Ne pas charger le réseau de démonstration à la première visite.
// define('MLG_SEED_DEMO', false);

// Devise affichée, durée d'une réservation (heures), rayon de couverture d'un point de vente (km).
// define('MLG_CURRENCY', 'DA');
// define('MLG_RESERVATION_HOURS', 48);
// define('MLG_COVER_KM', 30);

// Fond de carte. Les serveurs d'OpenStreetMap conviennent à un trafic modéré ; pour un trafic
// important, utiliser un fournisseur de tuiles (voir README.md).
// define('MLG_TILES_URL', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png');
// define('MLG_TILES_ATTR', '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>');
// define('MLG_MAP_CENTER', [34.6, 3.2]);
// define('MLG_MAP_ZOOM', 6);

// Emails : par défaut, le compte SMTP du défi Impact (impact/config.php) est réutilisé.
// define('MLG_SMTP_HOST', 'mail.dehini.com');
// define('MLG_SMTP_PORT', 465);
// define('MLG_SMTP_SECURE', 'ssl');
// define('MLG_SMTP_USER', 'maillage@dehini.com');
// define('MLG_SMTP_PASS', 'mot-de-passe-du-compte-email');
// define('MLG_MAIL_FROM', 'maillage@dehini.com');
