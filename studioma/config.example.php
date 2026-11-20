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
