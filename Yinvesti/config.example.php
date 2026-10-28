<?php
// Copier en config.php (sur le serveur seulement, jamais dans Git) puis adapter.

// Adresse qui reçoit chaque nouvelle candidature (laisser commenté pour ne rien recevoir).
define('YINV_NOTIF_EMAIL', 'projets@yinvesti.dz');

// Accusé de réception envoyé au porteur de projet (true / false).
define('YINV_ACCUSE', true);

// Dossier de stockage des candidatures (par défaut : ~/yinvesti-candidatures, hors du web).
// define('YINV_DATA_DIR', '/home/dehini91/yinvesti-candidatures');
