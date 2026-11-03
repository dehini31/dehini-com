<?php
/**
 * Compteurs d'affichage du défi Impact.
 *
 * - Pages vues du site (défi et pages publiques), par jour et par page, et visiteurs différents.
 * - Pour chaque page pro : affichages (son nom ou son logo vu à l'écran, à côté d'une question,
 *   sur une expérience ou dans l'annuaire), visites de sa page, clics sur ses contacts, et
 *   personnes différentes touchées. C'est ce que le pro suit dans son espace, et ce que
 *   l'admin montre pour vendre une mise en avant.
 *
 * Les événements arrivent par lots depuis le navigateur (api.php?action=track) : les robots ne
 * sont pas comptés, une même personne compte au plus PRO_DAILY_CAP événements par jour pour un
 * même pro, et le propriétaire d'une page ne compte pas ses propres visites.
 */
declare(strict_types=1);

/** Pages du site suivies : celles du défi (#…) et les pages publiques rendues par page.php. */
const VIEW_NAMES = [
    'home' => 'Accueil', 'defi' => 'Défi', 'resultat' => 'Résultat', 'monde' => 'Impact mondial', 'messages' => 'Messages',
    'soutenir' => 'Soutenir', 'pro' => 'Espace pro', 'page_q' => 'Pages des questions', 'page_hub' => 'Toutes les questions',
    'page_pros' => 'Annuaire des pros', 'page_pro' => 'Pages pro',
];
/** Événements d'une page pro : affichage, visite de sa page, clics sur site web / WhatsApp / appel / email. */
const PRO_EVENTS = ['imp', 'view', 'web', 'wa', 'call', 'mail'];
const PRO_CLICKS = ['web', 'wa', 'call', 'mail'];
const PRO_DAILY_CAP = 20;
const VIEWS_KEEP_DAYS = 95;

/** Robots, aperçus de liens et outils automatiques : rien n'est compté. */
function is_bot(string $ua): bool
{
    return $ua === '' || preg_match('~bot|crawl|spider|slurp|facebookexternalhit|preview|headless|lighthouse|pagespeed|python|curl|wget|httpclient|java/|go-http|okhttp|scrapy~i', $ua) === 1;
}

/** Jour local (heure d'Alger) : « 2026-11-03 ». */
function stat_day(int $offset = 0): string
{
    return date('Y-m-d', strtotime(($offset >= 0 ? '+' : '') . $offset . ' days'));
}

/** Ajoute $n au compteur identifié par $key (sans « UPSERT », pour les vieux SQLite). */
function stat_bump(string $table, array $key, int $n = 1): void
{
    $cols = array_keys($key);
    $pdo = db();
    $pdo->prepare("INSERT OR IGNORE INTO $table (" . implode(', ', $cols) . ', n) VALUES (' . str_repeat('?, ', count($cols)) . '0)')
        ->execute(array_values($key));
    $pdo->prepare("UPDATE $table SET n = n + ? WHERE " . implode(' AND ', array_map(function ($c) { return "$c = ?"; }, $cols)))
        ->execute(array_merge([$n], array_values($key)));
}

/**
 * Enregistre un lot d'événements envoyés par le navigateur :
 *   ["pv", "home"]            une page vue
 *   ["imp", proId, qid]       un affichage du pro (qid : la question à côté de laquelle il est vu, 0 sinon)
 *   ["view", proId]           une visite de sa page
 *   ["web"|"wa"|"call"|"mail", proId]   un clic sur un contact
 */
function track_events(string $pid, int $uid, array $events): int
{
    $pdo = db();
    $day = stat_day();
    $pros = pros_active();
    $own = 0;
    if ($uid) foreach ($pros as $p) if ((int) $p['uid'] === $uid) $own = (int) $p['id'];
    $seen = $pdo->prepare('SELECT n FROM pro_seen WHERE pro_id = ? AND day = ? AND pid = ?');
    $done = 0;
    $visit = false;
    $pdo->beginTransaction();
    foreach (array_slice($events, 0, 80) as $e) {
        if (!is_array($e) || !isset($e[0]) || !is_string($e[0])) continue;
        $k = $e[0];
        if ($k === 'pv') {
            $v = $e[1] ?? '';
            if (!is_string($v) || !isset(VIEW_NAMES[$v])) continue;
            stat_bump('views_daily', ['day' => $day, 'view' => $v]);
            $visit = true;
            $done++;
            continue;
        }
        if (!in_array($k, PRO_EVENTS, true)) continue;
        $id = (int) ($e[1] ?? 0);
        if (!isset($pros[$id]) || $id === $own) continue; // pro masqué ou inconnu, ou ses propres visites
        $pdo->prepare('INSERT OR IGNORE INTO pro_seen (pro_id, day, pid, n) VALUES (?, ?, ?, 0)')->execute([$id, $day, $pid]);
        $seen->execute([$id, $day, $pid]);
        if ((int) $seen->fetchColumn() >= PRO_DAILY_CAP) continue; // une même personne ne gonfle pas les chiffres
        $pdo->prepare('UPDATE pro_seen SET n = n + 1 WHERE pro_id = ? AND day = ? AND pid = ?')->execute([$id, $day, $pid]);
        $qid = $k === 'imp' ? max(0, min(100000, (int) ($e[2] ?? 0))) : 0;
        stat_bump('pro_stats', ['pro_id' => $id, 'day' => $day, 'kind' => $k, 'qid' => $qid]);
        $done++;
    }
    if ($visit) $pdo->prepare('INSERT OR IGNORE INTO visitors (day, pid) VALUES (?, ?)')->execute([$day, $pid]);
    $pdo->commit();
    if (random_int(1, 200) === 1) {
        $old = stat_day(-VIEWS_KEEP_DAYS);
        $pdo->prepare('DELETE FROM pro_seen WHERE day < ?')->execute([$old]);
        $pdo->prepare('DELETE FROM visitors WHERE day < ?')->execute([$old]);
    }
    return $done;
}

/** Les $days derniers jours, du plus ancien à aujourd'hui. */
function stat_days(int $days): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[] = stat_day(-$i);
    return $out;
}

/**
 * Statistiques d'une page pro sur 7, 30 ou 90 jours : courbes par jour, totaux, comparaison avec la
 * période précédente, personnes touchées et questions qui la montrent le plus.
 */
function pro_stats_view(int $proId, int $days, string $lang): array
{
    $days = in_array($days, [7, 30, 90], true) ? $days : 30;
    $labels = stat_days($days);
    $from = $labels[0];
    $prevFrom = stat_day(-(2 * $days - 1));
    $pdo = db();
    $series = ['imp' => array_fill_keys($labels, 0), 'view' => array_fill_keys($labels, 0), 'clicks' => array_fill_keys($labels, 0)];
    $tot = array_fill_keys(PRO_EVENTS, 0);
    $prev = array_fill_keys(PRO_EVENTS, 0);
    $s = $pdo->prepare('SELECT day, kind, SUM(n) AS n FROM pro_stats WHERE pro_id = ? AND day >= ? GROUP BY day, kind');
    $s->execute([$proId, $prevFrom]);
    foreach ($s as $r) {
        $k = (string) $r['kind'];
        if (!isset($tot[$k])) continue;
        $n = (int) $r['n'];
        if ($r['day'] < $from) { $prev[$k] += $n; continue; }
        $tot[$k] += $n;
        $col = in_array($k, PRO_CLICKS, true) ? 'clicks' : $k;
        if (isset($series[$col][$r['day']])) $series[$col][$r['day']] += $n;
    }
    $clicks = array_sum(array_intersect_key($tot, array_flip(PRO_CLICKS)));
    $prevClicks = array_sum(array_intersect_key($prev, array_flip(PRO_CLICKS)));
    $s = $pdo->prepare('SELECT COUNT(DISTINCT pid) FROM pro_seen WHERE pro_id = ? AND day >= ?');
    $s->execute([$proId, $from]);
    $reach = (int) $s->fetchColumn();
    $s = $pdo->prepare("SELECT qid, SUM(n) AS n FROM pro_stats WHERE pro_id = ? AND kind = 'imp' AND qid > 0 AND day >= ? GROUP BY qid ORDER BY n DESC LIMIT 8");
    $s->execute([$proId, $from]);
    $qs = questions();
    $top = [];
    foreach ($s as $r) if (isset($qs[(int) $r['qid']])) $top[] = [(int) $r['qid'], (int) $r['n'], qtext($qs[(int) $r['qid']], $lang)];
    $s = $pdo->prepare("SELECT COALESCE(SUM(likes), 0) FROM stories WHERE pro_id = ? AND status = 'published'");
    $s->execute([$proId]);
    return [
        'days' => $days, 'labels' => $labels,
        'series' => array_map('array_values', $series),
        'totals' => $tot + ['clicks' => $clicks, 'reach' => $reach, 'likes' => (int) $s->fetchColumn()],
        'prev' => ['imp' => $prev['imp'], 'view' => $prev['view'], 'clicks' => $prevClicks],
        'top' => $top,
    ];
}

/** Totaux des 30 derniers jours pour une liste de pros (onglet « Pros » de l'admin). */
function pros_stats_30(): array
{
    $from = stat_day(-29);
    $out = [];
    $s = db()->prepare('SELECT pro_id, kind, SUM(n) AS n FROM pro_stats WHERE day >= ? GROUP BY pro_id, kind');
    $s->execute([$from]);
    foreach ($s as $r) {
        $id = (int) $r['pro_id'];
        $out[$id] = $out[$id] ?? ['imp' => 0, 'view' => 0, 'clicks' => 0, 'reach' => 0];
        $k = in_array($r['kind'], PRO_CLICKS, true) ? 'clicks' : (string) $r['kind'];
        if (isset($out[$id][$k])) $out[$id][$k] += (int) $r['n'];
    }
    $s = db()->prepare('SELECT pro_id, COUNT(DISTINCT pid) AS n FROM pro_seen WHERE day >= ? GROUP BY pro_id');
    $s->execute([$from]);
    foreach ($s as $r) {
        $id = (int) $r['pro_id'];
        $out[$id] = $out[$id] ?? ['imp' => 0, 'view' => 0, 'clicks' => 0, 'reach' => 0];
        $out[$id]['reach'] = (int) $r['n'];
    }
    return $out;
}

/** Pages vues et visiteurs du site (onglet « Statistiques » de l'admin). */
function site_views_view(): array
{
    $pdo = db();
    $sum = function (string $from) use ($pdo): int {
        $s = $pdo->prepare('SELECT COALESCE(SUM(n), 0) FROM views_daily WHERE day >= ?');
        $s->execute([$from]);
        return (int) $s->fetchColumn();
    };
    $uniq = function (string $from) use ($pdo): int {
        $s = $pdo->prepare('SELECT COUNT(DISTINCT pid) FROM visitors WHERE day >= ?');
        $s->execute([$from]);
        return (int) $s->fetchColumn();
    };
    $days = stat_days(14);
    $pv = array_fill_keys($days, 0);
    $uv = array_fill_keys($days, 0);
    $s = $pdo->prepare('SELECT day, SUM(n) AS n FROM views_daily WHERE day >= ? GROUP BY day');
    $s->execute([$days[0]]);
    foreach ($s as $r) if (isset($pv[$r['day']])) $pv[$r['day']] = (int) $r['n'];
    $s = $pdo->prepare('SELECT day, COUNT(*) AS n FROM visitors WHERE day >= ? GROUP BY day');
    $s->execute([$days[0]]);
    foreach ($s as $r) if (isset($uv[$r['day']])) $uv[$r['day']] = (int) $r['n'];
    $from30 = stat_day(-29);
    $byView = [];
    $s = $pdo->prepare('SELECT view, SUM(n) AS n FROM views_daily WHERE day >= ? GROUP BY view ORDER BY n DESC');
    $s->execute([$from30]);
    foreach ($s as $r) $byView[] = [(string) $r['view'], VIEW_NAMES[$r['view']] ?? (string) $r['view'], (int) $r['n']];
    $pros = pros_active();
    $top = [];
    foreach (pros_stats_30() as $id => $x) if (isset($pros[$id])) $top[] = ['id' => $id, 'name' => (string) $pros[$id]['name'], 'slug' => (string) $pros[$id]['slug']] + $x;
    usort($top, function ($a, $b) { return $b['imp'] <=> $a['imp'] ?: $b['view'] <=> $a['view']; });
    return [
        'pv' => ['today' => $sum(stat_day()), 'week' => $sum(stat_day(-6)), 'month' => $sum($from30)],
        'uv' => ['today' => $uniq(stat_day()), 'week' => $uniq(stat_day(-6)), 'month' => $uniq($from30)],
        'trend' => ['days' => $days, 'pv' => array_values($pv), 'uv' => array_values($uv)],
        'by_view' => $byView,
        'top_pros' => array_slice($top, 0, 8),
    ];
}
