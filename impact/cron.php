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
 *   3. Nouvelles expériences positives — dès qu'un récit est publié (par une personne
 *      ou une page pro) sur une question, ceux qui y ont répondu « impact négatif » le
 *      reçoivent : comptes confirmés (emails « impacts positifs ») et abonnés sans compte
 *      confirmés. Au plus un email toutes les IMPACT_ALERT_HOURS heures par personne,
 *      jusqu'à 3 récits, jamais deux fois le même.
 *   4. Emails automatiques (lib/automations.php) — premiers pas, rappel de confirmation,
 *      paliers, relance des absents, récit apprécié, et pour les pros : bienvenue, page
 *      incomplète, rapport mensuel. Chacun s'active ou se coupe dans l'admin (onglet Emails).
 *   5. Invitations (lib/campaigns.php) — la suite des campagnes « en cours », au plus le
 *      nombre réglé par heure dans l'admin.
 *
 * Options :
 *   --dry-run               affiche ce qui partirait, sans rien envoyer ni modifier
 *   --test=adresse@x.com    envoie un exemple de chaque email à cette adresse
 *   --lang=ar|fr|en         langue des exemples de --test (fr par défaut)
 *   --only=invite,onboard   seulement ces exemples (liste affichée si un nom est inconnu) ;
 *                           ex. pour noter un email sur mail-tester.com : --test=<adresse donnée> --only=invite
 *   --domain=finance        domaine de l'exemple « invite_pro » (psy par défaut)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php';
require __DIR__ . '/lib/seo.php';
require __DIR__ . '/lib/pros.php';
require __DIR__ . '/lib/views.php';
require __DIR__ . '/lib/automations.php';
require __DIR__ . '/lib/campaigns.php';

$opts = getopt('', ['dry-run', 'test:', 'lang:', 'only:', 'domain:']);
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
    $only = isset($opts['only']) ? array_filter(array_map('trim', explode(',', (string) $opts['only']))) : [];
    $domain = in_array($opts['domain'] ?? '', CAMPAIGN_DOMAINS, true) ? (string) $opts['domain'] : 'psy';
    $u = ['id' => 0, 'email' => $to, 'name' => 'Test', 'lang' => $lang, 'utoken' => str_repeat('0', 48), 'vtoken' => str_repeat('0', 48)];
    say("Envoi des exemples à $to (" . (IMPACT_SMTP_HOST !== '' ? 'SMTP ' . IMPACT_SMTP_HOST : 'mail()') . ", expéditeur " . IMPACT_MAIL_FROM_NAME . ' <' . mail_from() . '>)');
    $q = $qs[pick(array_keys($qs), $today)];
    $stories = $pdo->query("SELECT * FROM stories WHERE status = 'published' ORDER BY created DESC LIMIT 3")->fetchAll();
    if (!$stories) {
        $stories = [['qid' => $q['id'], 'author' => '', 'lang' => $lang,
            'exp' => '(exemple) Aucun récit publié pour l\'instant : voici à quoi ressemblera un récit partagé par un participant.',
            'imp' => '(exemple) Et ici, ce que cette expérience lui a apporté de positif.']];
    }
    $sample = ['fr' => '(exemple) Votre récit m\'a beaucoup touché, merci de l\'avoir partagé.',
        'ar' => '(مثال) أثّرت فيّ قصتك كثيراً، شكراً لأنك شاركتها.', 'en' => '(example) Your story really moved me, thank you for sharing it.'][$lang];
    $inv = ['lang' => $lang, 'subject' => '', 'note' => '', 'sign' => ''];
    // Chaque exemple n'est composé que s'il est demandé (--only=invite,onboard…).
    $samples = [
        'daily' => function () use ($u, $q, $total) { return compose_daily($u, $q, 37, $total); },
        'story' => function () use ($u, $q) { return compose_story_prompt($u, $q); },
        'digest' => function () use ($u, $stories) { return compose_digest($u, $stories); },
        'welcome' => function () use ($u) { return compose_welcome($u); },
        'reset' => function () use ($u) { return compose_reset($u, str_repeat('0', 48)); },
        'message' => function () use ($u, $q, $lang, $sample) { return compose_message($u, 'Nadia', qtext($q, $lang), $sample, 0, true); },
        'thanks' => function () use ($lang) { return compose_thanks(['lang' => $lang, 'name' => 'Test', 'amount' => 1000]); },
        'sub_confirm' => function () use ($lang) { return compose_sub_confirm(['lang' => $lang, 'token' => str_repeat('0', 48)]); },
        'alert' => function () use ($to, $lang, $stories) { return compose_alert(['email' => $to, 'lang' => $lang, 'name' => ''], $stories, site_url() . 'api.php?action=sub_unsub&token=' . str_repeat('0', 48)); },
    ];
    foreach (array_keys(AUTOMATIONS) as $k) $samples[$k] = function () use ($k, $lang, $to) { return auto_sample($k, $lang, $to); };
    $samples['invite'] = function () use ($inv) { return array_slice(camp_compose($inv + ['audience' => 'particulier', 'domain' => ''], ['name' => 'Test', 'token' => '']), 0, 3); };
    $samples['invite_pro'] = function () use ($inv, $domain) { return array_slice(camp_compose($inv + ['audience' => 'pro', 'domain' => $domain], ['name' => 'Test', 'token' => '']), 0, 3); };
    if ($only && array_diff($only, array_keys($samples))) {
        say('Exemples possibles : ' . implode(', ', array_keys($samples)));
        exit(1);
    }
    $first = true;
    foreach ($samples as $k => $make) {
        if ($only && !in_array($k, $only, true)) continue;
        // Une petite pause entre deux envois : une rafale d'emails vers une même boîte ressemble à du spam.
        if (!$first && !$dry) sleep(2);
        $first = false;
        deliver($u, $make(), 'all', $dry);
    }
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
$digestSent = 0;
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
        $digestSent++;
        if (!deliver($u, compose_digest($u, $stories), 'digest', $dry) || $dry) continue;
        $done->execute([$now, $now, $u['id']]);
        foreach ($stories as $st) $seen->execute([$u['id'], $st['id']]);
    }
}

/* ---------------------------- 3. nouvelles expériences positives (alertes) */

$left = max(0, $budget - $sent - $digestSent);
if ($left > 0) {
    $since = $now - (int) IMPACT_ALERT_DAYS * 86400;
    $gap = $now - (int) IMPACT_ALERT_HOURS * 3600;
    $byQ = [];
    foreach ($pdo->query("SELECT * FROM stories WHERE status = 'published' AND published >= $since ORDER BY published DESC LIMIT 500") as $st) {
        $byQ[(int) $st['qid']][] = $st;
    }
    // Pour chaque personne qui a répondu « négatif » : les récits récents sur ces questions.
    $recipients = [];
    $neg = $pdo->prepare('SELECT pid FROM answers WHERE qid = ? AND v = -1');
    foreach ($byQ as $qid => $list) {
        $neg->execute([$qid]);
        foreach ($neg->fetchAll(PDO::FETCH_COLUMN) as $pid) foreach ($list as $st) $recipients[$pid][(int) $st['id']] = $st;
    }
    say('Alertes : ' . count($byQ) . ' question(s) avec des récits récents, ' . count($recipients) . ' personne(s) concernée(s)');
    $mailed = [];
    $pick = function (array $stories, int $uid, array $seen, string $lang): array {
        $list = array_values(array_filter($stories, function ($st) use ($uid, $seen) {
            return (!$uid || (int) $st['uid'] !== $uid) && !isset($seen[(int) $st['id']]);
        }));
        usort($list, function ($a, $b) use ($lang) {
            return [$b['lang'] === $lang, (int) $b['likes'], (int) $b['published']] <=> [$a['lang'] === $lang, (int) $a['likes'], (int) $a['published']];
        });
        return array_slice($list, 0, 3);
    };
    $userQ = $pdo->prepare('SELECT * FROM users WHERE id = ? AND verified = 1 AND opt_digest = 1');
    $userSeen = $pdo->prepare('SELECT sid FROM digest_seen WHERE uid = ?');
    $markUser = $pdo->prepare('INSERT OR IGNORE INTO digest_seen (uid, sid) VALUES (?, ?)');
    $userDone = $pdo->prepare('UPDATE users SET last_alert = ? WHERE id = ?');
    $subQ = $pdo->prepare('SELECT * FROM subscribers WHERE pid = ? AND confirmed = 1');
    $subSeen = $pdo->prepare('SELECT sid FROM sub_seen WHERE sub = ?');
    $markSub = $pdo->prepare('INSERT OR IGNORE INTO sub_seen (sub, sid) VALUES (?, ?)');
    $subDone = $pdo->prepare('UPDATE subscribers SET last_alert = ? WHERE id = ?');

    foreach ($recipients as $pid => $stories) {
        if ($left <= 0) break;
        $uid = strncmp((string) $pid, 'u', 1) === 0 ? (int) substr((string) $pid, 1) : 0;
        // Compte confirmé qui accepte les emails « impacts positifs ».
        if ($uid) {
            $userQ->execute([$uid]);
            $u = $userQ->fetch();
            if ($u && (int) $u['last_alert'] <= $gap && !isset($mailed[$u['email']])) {
                $userSeen->execute([$uid]);
                $list = $pick($stories, $uid, array_flip(array_map('intval', $userSeen->fetchAll(PDO::FETCH_COLUMN))), impact_lang($u['lang']));
                if ($list) {
                    $u['lang'] = impact_lang($u['lang']);
                    $left--;
                    $mailed[$u['email']] = 1;
                    if (deliver($u, compose_alert(['email' => $u['email'], 'lang' => $u['lang'], 'name' => $u['name']], $list, unsub_url($u, 'digest')), 'digest', $dry) && !$dry) {
                        $userDone->execute([$now, $uid]);
                        foreach ($list as $st) $markUser->execute([$uid, $st['id']]);
                    }
                }
            }
        }
        // Abonnés sans compte (ou devenus un compte depuis) liés à ces réponses.
        $subQ->execute([$pid]);
        foreach ($subQ->fetchAll() as $sub) {
            if ($left <= 0 || (int) $sub['last_alert'] > $gap || isset($mailed[$sub['email']])) continue;
            $subSeen->execute([$sub['id']]);
            $list = $pick($stories, $uid, array_flip(array_map('intval', $subSeen->fetchAll(PDO::FETCH_COLUMN))), impact_lang($sub['lang']));
            if (!$list) continue;
            $left--;
            $mailed[$sub['email']] = 1;
            $unsub = site_url() . 'api.php?action=sub_unsub&token=' . rawurlencode((string) $sub['token']);
            [$subject, $html, $text] = compose_alert(['email' => $sub['email'], 'lang' => $sub['lang'], 'name' => ''], $list, $unsub);
            if ($dry) {
                say("  (dry-run) → {$sub['email']} : $subject");
                continue;
            }
            try {
                send_mail((string) $sub['email'], $subject, $html, $text, $unsub);
                say("  ✓ {$sub['email']} : $subject");
                $subDone->execute([$now, $sub['id']]);
                foreach ($list as $st) $markSub->execute([$sub['id'], $st['id']]);
            } catch (Throwable $e) {
                say("  ✗ {$sub['email']} : " . $e->getMessage());
            }
        }
    }
}

/* ------------------------------------------------ 4. emails automatiques */

// Ce qui reste du lot après les alertes (elles ont décompté leurs envois de $left).
$left = max(0, $left);
$autoSent = run_automations($left, $dry, function (array $u, array $mail, string $type) use ($dry): bool {
    return deliver($u, $mail, $type, $dry);
}, 'say');

/* ------------------------------------------------------- 5. invitations */

camp_send(camp_per_hour(), $dry, 'say');

Smtp::close();
say('Terminé.');
