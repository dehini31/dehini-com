<?php
/**
 * Tâche planifiée de Studioma (facultative mais conseillée) : envoie les emails de campagne en attente (sans
 * dépasser STM_MAIL_PER_HOUR), les emails groupés des modifications d'invités, et fait le ménage (sessions et
 * limites expirées). Sans elle, les campagnes partent quand même, plus lentement : pendant que la page
 * d'administration est ouverte, ou après les requêtes des membres si le serveur le permet.
 *
 * En ligne de commande uniquement — cPanel › Cron Jobs, toutes les 5 minutes :
 *   /usr/local/bin/php ~/public_html/studioma/cron.php >/dev/null 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$isPost = false;
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/access.php';
require __DIR__ . '/lib/files.php';
require __DIR__ . '/lib/studio.php';
require __DIR__ . '/lib/cards.php';
require __DIR__ . '/lib/collab.php';
require __DIR__ . '/lib/social.php';
require __DIR__ . '/lib/talent.php';
require __DIR__ . '/lib/share.php';
require __DIR__ . '/lib/meta.php';
require __DIR__ . '/lib/admin.php';

// Une seule exécution à la fois (une tâche lente ne se superpose pas à la suivante).
$lock = @fopen(STM_DATA_DIR . '/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Already running\n";
    exit(0);
}
try {
    db();
    $sent = campaign_pump((int) STM_MAIL_PER_HOUR, 240.0);
    share_digests();
    q('DELETE FROM sessions WHERE exp < ?', [time()]);
    q('DELETE FROM rate WHERE reset < ?', [time()]);
    meta_set('cron_last', (string) time());
    echo 'OK — ' . $sent . " campaign email(s) sent\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'studioma cron: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
