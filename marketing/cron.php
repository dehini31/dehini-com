<?php
/**
 * Rapports automatiques du Marketing Dashboard — à lancer par une tâche cron, une fois par
 * jour (le matin, par exemple à 7 h 05) :
 *
 *   /usr/local/bin/php ~/public_html/marketing/cron.php > /dev/null 2>&1
 *
 * À chaque passage :
 *   - rapport quotidien de la veille ;
 *   - le lundi : rapport hebdomadaire de la semaine écoulée (lundi → dimanche) ;
 *   - le 1er du mois : rapport mensuel du mois écoulé.
 * Chaque rapport est archivé (onglet Overview → Archives) et envoyé aux destinataires
 * réglés dans le tableau de bord (Réglages), si l'envoi de ce type de rapport est activé.
 * Un rapport déjà envoyé par le cron n'est pas renvoyé si la tâche repasse le même jour.
 *
 * Options :
 *   --dry-run               affiche ce qui partirait, sans rien envoyer ni archiver
 *   --date=AAAA-MM-JJ       fait comme si on était ce jour-là (rattrapage)
 *   --force=day|week|month  produit ce rapport même si ce n'est pas le jour prévu
 *   --test=adresse@x.com    envoie les trois rapports (veille, semaine, mois écoulés) à cette adresse
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/report.php';

function say(string $s): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $s . "\n";
}

$opts = getopt('', ['dry-run', 'date:', 'force:', 'test:']);
$dry = isset($opts['dry-run']);
$today = isset($opts['date']) && mk_valid_date((string) $opts['date']) ? (string) $opts['date'] : mk_today();
$force = (array) ($opts['force'] ?? []);
$S = mk_settings();

// Les trois rapports possibles aujourd'hui : [type, date dans la période, activé ?, prévu aujourd'hui ?]
$d = new DateTimeImmutable($today);
$plan = [
    ['day', mk_add_days($today, -1), $S['send_daily'], true],
    ['week', mk_add_days($today, -7), $S['send_weekly'], $d->format('N') === '1'],
    ['month', mk_add_days($today, -1), $S['send_monthly'], $d->format('j') === '1'],
];

if (isset($opts['test'])) {
    $to = strtolower((string) $opts['test']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { say('Adresse de test invalide.'); exit(1); }
    foreach ($plan as [$type, $date]) {
        $r = mk_report($type, $date);
        [$subject, $html, $text] = mk_report_email($r, mk_site_url());
        if ($dry) { say("(dry-run) → $to : $subject"); continue; }
        try {
            mk_send_mail($to, '[Test] ' . $subject, $html, $text);
            say("✓ $to : $subject");
        } catch (Throwable $e) {
            say("✗ $to : " . $e->getMessage());
        }
    }
    exit(0);
}

$errors = 0;
foreach ($plan as [$type, $date, $enabled, $due]) {
    if (!$due && !in_array($type, $force, true)) continue;
    $r = mk_report($type, $date);
    $p = $r['period'];
    $already = !in_array($type, $force, true) && qv("SELECT 1 FROM reports WHERE type = ? AND date_from = ? AND date_to = ? AND origin = 'cron'", [$type, $p['from'], $p['to']]);
    if ($already) {
        say("Rapport {$r['type_label']} « {$p['label']} » déjà produit : ignoré.");
        continue;
    }
    $to = $enabled ? $S['recipients'] : [];
    if ($dry) {
        say("(dry-run) Rapport {$r['type_label']} « {$p['label']} » → " . ($to ? implode(', ', $to) : 'archivé seulement (aucun destinataire ou envoi désactivé)'));
        foreach ($r['insights'] as $i) say("    [{$i['level']}] {$i['text']}");
        continue;
    }
    if (!$to) {
        mk_archive_report($r, 'cron');
        say("Rapport {$r['type_label']} « {$p['label']} » archivé (" . ($enabled ? 'aucun destinataire dans les Réglages' : 'envoi désactivé') . ').');
        continue;
    }
    $res = mk_send_report($r, 'cron', $to);
    foreach ($res['sent'] as $m) say("✓ $m : {$res['subject']}");
    foreach ($res['errors'] as $e) { say("✗ $e"); $errors++; }
}
exit($errors ? 1 : 0);
