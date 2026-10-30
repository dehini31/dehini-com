<?php
/**
 * Emails de rappel du défi Impact — à lancer par une tâche cron, toutes les heures :
 *
 *   php ~/public_html/impact/cron.php
 *
 * À chaque passage (au plus IMPACT_MAIL_BATCH emails) :
 *   1. Question du jour — à partir de IMPACT_DAILY_HOUR, une fois par jour et par
 *      compte confirmé qui l'a demandée : une question sans réponse, avec trois
 *      boutons pour répondre en un clic. Quand tout est répondu, un rappel tous
 *      les 3 jours pour raconter un impact positif pas encore écrit.
 *   2. Impacts positifs — au plus tous les IMPACT_DIGEST_DAYS jours : jusqu'à 3
 *      récits d'autres participants sur les questions où le compte a répondu
 *      « impact négatif » ou « aucun impact » (jamais deux fois le même récit).
 *
 * Options :
 *   --dry-run               affiche ce qui partirait, sans rien envoyer ni modifier
 *   --test=adresse@x.com    envoie un exemple de chaque email à cette adresse
 *   --lang=ar|fr|en         langue des exemples de --test (fr par défaut)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php';

$opts = getopt('', ['dry-run', 'test:', 'lang:']);
$dry = isset($opts['dry-run']);
$pdo = db();
$qs = questions();
$total = count($qs);
$budget = (int) IMPACT_MAIL_BATCH;
$now = time();
$today = date('Y-m-d');

function say(string $s): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $s . "\n";
}

/** Choix stable dans la journée (ne change pas si le cron repasse). */
function pick(array $list, string $seed)
{
    sort($list);
    return $list[abs(crc32($seed)) % count($list)];
}

function deliver(array $u, array $mail, string $unsubType, bool $dry): bool
{
    [$subject, $html, $text] = $mail;
    if ($dry) {
        say("  (dry-run) → {$u['email']} : $subject");
        return true;
    }
    try {
        send_mail($u['email'], $subject, $html, $text, unsub_url($u, $unsubType));
        say("  ✓ {$u['email']} : $subject");
        return true;
    } catch (Throwable $e) {
        say("  ✗ {$u['email']} : " . $e->getMessage());
        return false;
    }
}

function digest_stories(PDO $pdo, array $u): array
{
    $s = $pdo->prepare("SELECT s.* FROM stories s
        JOIN answers a ON a.qid = s.qid AND a.pid = ? AND a.v <= 0
        WHERE s.status = 'published' AND (s.uid IS NULL OR s.uid != ?)
          AND s.id NOT IN (SELECT sid FROM digest_seen WHERE uid = ?)
        ORDER BY (s.lang = ?) DESC, s.likes DESC, s.created DESC LIMIT 3");
    $s->execute(['u' . $u['id'], $u['id'], $u['id'], $u['lang']]);
    return $s->fetchAll();
}

/* ------------------------------------------------------------ --test */

if (isset($opts['test'])) {
    $to = (string) $opts['test'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        say('Adresse invalide.');
        exit(1);
    }
    $lang = impact_lang($opts['lang'] ?? 'fr');
    $u = ['id' => 0, 'email' => $to, 'name' => 'Test', 'lang' => $lang, 'utoken' => str_repeat('0', 48), 'vtoken' => str_repeat('0', 48)];
    say("Envoi des exemples à $to (" . (IMPACT_SMTP_HOST !== '' ? 'SMTP ' . IMPACT_SMTP_HOST : 'mail()') . ", expéditeur " . mail_from() . ')');
    $q = $qs[pick(array_keys($qs), $today)];
    deliver($u, compose_daily($u, $q, 37, $total), 'daily', $dry);
    deliver($u, compose_story_prompt($u, $q), 'daily', $dry);
    $stories = $pdo->query("SELECT * FROM stories WHERE status = 'published' ORDER BY created DESC LIMIT 3")->fetchAll();
    if (!$stories) {
        $stories = [['qid' => $q['id'], 'author' => '', 'lang' => $lang,
            'exp' => '(exemple) Aucun récit publié pour l\'instant : voici à quoi ressemblera un récit partagé par un participant.',
            'imp' => '(exemple) Et ici, ce que cette expérience lui a apporté de positif.']];
    }
    deliver($u, compose_digest($u, $stories), 'digest', $dry);
    deliver($u, compose_welcome($u), 'all', $dry);
    deliver($u, compose_reset($u, str_repeat('0', 48)), 'all', $dry);
    $sample = ['fr' => '(exemple) Votre récit m\'a beaucoup touché, merci de l\'avoir partagé.',
        'ar' => '(مثال) أثّرت فيّ قصتك كثيراً، شكراً لأنك شاركتها.', 'en' => '(example) Your story really moved me, thank you for sharing it.'][$lang];
    deliver($u, compose_message($u, 'Nadia', qtext($q, $lang), $sample, 0, true), 'msg', $dry);
    deliver($u, compose_thanks(['lang' => $lang, 'name' => 'Test', 'amount' => 1000]), 'all', $dry);
    Smtp::close();
    exit(0);
}

/* --------------------------------------------------- 1. question du jour */

$sent = 0;
if ((int) date('G') >= (int) IMPACT_DAILY_HOUR) {
    $s = $pdo->prepare("SELECT * FROM users WHERE verified = 1 AND opt_daily = 1 AND (last_daily IS NULL OR last_daily != ?) ORDER BY id LIMIT ?");
    $s->execute([$today, $budget]);
    $users = $s->fetchAll();
    say('Question du jour : ' . count($users) . ' compte(s) à traiter');
    $mark = $pdo->prepare('UPDATE users SET last_daily = ? WHERE id = ?');

    foreach ($users as $u) {
        $u['lang'] = impact_lang($u['lang']);
        $a = $pdo->prepare('SELECT qid, v FROM answers WHERE pid = ?');
        $a->execute(['u' . $u['id']]);
        $answers = [];
        foreach ($a as $r) $answers[(int) $r['qid']] = (int) $r['v'];
        $open = array_values(array_diff(array_keys($qs), array_keys($answers)));
        $answered = count(array_intersect_key($answers, $qs));
        $mail = null;

        if ($open) {
            $mail = compose_daily($u, $qs[pick($open, $u['id'] . $today)], $answered, $total);
        } elseif ((int) date('z') % 3 === 0) {
            $w = $pdo->prepare('SELECT qid FROM stories WHERE uid = ?');
            $w->execute([$u['id']]);
            $written = array_map('intval', $w->fetchAll(PDO::FETCH_COLUMN));
            $cands = array_values(array_diff(array_keys(array_filter($answers, function ($v) { return $v === 1; })), $written));
            $cands = array_values(array_intersect($cands, array_keys($qs)));
            if ($cands) $mail = compose_story_prompt($u, $qs[pick($cands, $u['id'] . $today)]);
        }

        if ($mail === null) {
            if (!$dry) $mark->execute([$today, $u['id']]);
            continue;
        }
        if (deliver($u, $mail, 'daily', $dry) && !$dry) $mark->execute([$today, $u['id']]);
        $sent++;
    }
}

/* ------------------------------------------- 2. impacts positifs des autres */

$left = $budget - $sent;
if ($left > 0) {
    $s = $pdo->prepare('SELECT * FROM users WHERE verified = 1 AND opt_digest = 1 AND last_digest <= ? AND digest_check <= ? ORDER BY last_digest, id LIMIT ?');
    $s->execute([$now - (int) IMPACT_DIGEST_DAYS * 86400, $now - 86400, $left * 3]);
    $users = $s->fetchAll();
    say('Impacts positifs : ' . count($users) . ' compte(s) à vérifier');
    $checked = $pdo->prepare('UPDATE users SET digest_check = ? WHERE id = ?');
    $done = $pdo->prepare('UPDATE users SET last_digest = ?, digest_check = ? WHERE id = ?');
    $seen = $pdo->prepare('INSERT OR IGNORE INTO digest_seen (uid, sid) VALUES (?, ?)');

    foreach ($users as $u) {
        if ($left <= 0) break;
        $u['lang'] = impact_lang($u['lang']);
        $stories = digest_stories($pdo, $u);
        if (!$stories) {
            if (!$dry) $checked->execute([$now, $u['id']]);
            continue;
        }
        $left--;
        if (!deliver($u, compose_digest($u, $stories), 'digest', $dry) || $dry) continue;
        $done->execute([$now, $now, $u['id']]);
        foreach ($stories as $st) $seen->execute([$u['id'], $st['id']]);
    }
}

Smtp::close();
say('Terminé.');
