<?php
/**
 * Modèle de configuration du défi Impact — NE PAS remplir ce fichier ni le committer.
 *
 * Sur le serveur, copie-le en impact/config.php (exclu de Git, bloqué par .htaccess)
 * et renseigne au minimum le compte email d'envoi. Tout ce qui n'est pas défini
 * garde sa valeur par défaut (voir lib/bootstrap.php).
 */

// Adresse publique de la page (sert aux liens des emails envoyés par le cron).
define('IMPACT_SITE_URL', 'https://dehini.com/impact/');

// Compte email créé dans cPanel → Comptes de messagerie (ex. impact@dehini.com).
define('IMPACT_MAIL_FROM', 'impact@dehini.com');
define('IMPACT_SMTP_HOST', 'mail.dehini.com');   // vide = fonction mail() de PHP
define('IMPACT_SMTP_PORT', 465);
define('IMPACT_SMTP_SECURE', 'ssl');            // ssl (465) | tls (587) | none
define('IMPACT_SMTP_USER', 'impact@dehini.com');
define('IMPACT_SMTP_PASS', 'mot-de-passe-du-compte-email');

// Modération des récits : 'auto' (publiés tout de suite, les liens/numéros
// passent en attente) ou 'manual' (tout récit est validé dans moderation.html).
define('IMPACT_MODERATION', 'auto');
// Reçoit un email quand un récit attend une validation (vide = aucun).
define('IMPACT_NOTIFY_EMAIL', 'ton-adresse@example.com');

// Rythme des emails.
define('IMPACT_DAILY_HOUR', 9);     // la question du jour part à partir de 9 h (heure d'Alger)
define('IMPACT_DIGEST_DAYS', 7);    // au plus un email « impacts positifs » par semaine
define('IMPACT_MAIL_BATCH', 80);    // emails max par passage du cron
