<?php
/**
 * Tâches planifiées de SWANBIDA — à lancer toutes les 10 minutes par le cron de cPanel :
 *   /usr/local/bin/php ~/public_html/swanbida/cron.php > /dev/null 2>&1
 *
 *  - renvoie à Meta les événements serveur en attente (Lead, Purchase, livraison) ;
 *  - sauvegarde la base une fois par jour (30 jours gardés dans ~/swanbida-data/backups) ;
 *  - efface les données techniques des commandes (IP, navigateur) au bout de 45 jours ;
 *  - nettoie les compteurs d'essais expirés.
 * Options : --backup (sauvegarde immédiate), --verbose.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/i18n.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/markdown.php';
require __DIR__ . '/lib/media.php';
require __DIR__ . '/lib/orders.php';
require __DIR__ . '/lib/meta.php';
require __DIR__ . '/lib/notify.php';
require __DIR__ . '/lib/view.php';
require __DIR__ . '/lib/backup.php';

$verbose = in_array('--verbose', $argv, true);
$say = function (string $m) use ($verbose) { if ($verbose) echo $m, "\n"; };

$lock = fopen(SWB_DATA_DIR . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $say('Déjà en cours.'); exit(0); }

$n = capi_process(100);
$say("Meta : $n événement(s) envoyé(s).");

if (in_array('--backup', $argv, true) || (int) date('G') >= 2) {
    $f = backup_daily(in_array('--backup', $argv, true));
    $say($f ? 'Sauvegarde : ' . $f : 'Sauvegarde du jour déjà faite.');
}

$limit = time() - 45 * 86400;
$u = db()->prepare("UPDATE orders SET ip = '', ua = '' WHERE created < ? AND (ip <> '' OR ua <> '')");
$u->execute([$limit]);
$say('Données techniques effacées : ' . $u->rowCount() . ' commande(s).');
db()->prepare('DELETE FROM rate WHERE reset < ?')->execute([time()]);

foreach (db()->query('SELECT * FROM products WHERE stock IS NOT NULL AND stock <= stock_alert') as $r) {
    notify_low_stock(product_from_row($r));
}
meta_set('cron_last', (string) time());
$say('Terminé.');
