<?php
/**
 * Rapports du Marketing Dashboard : calculés à la volée à partir des données saisies dans
 * toutes les sections (rien n'est saisi deux fois). Un rapport couvre un jour, une semaine
 * (lundi → dimanche), un mois ou une période libre, et se compare à la période précédente
 * de même durée. Il contient aussi une liste de constats (« Analyse & décisions »).
 */
declare(strict_types=1);

const MK_MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MK_MONTHS_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const MK_DAYS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const MK_TYPES = ['day' => 'quotidien', 'week' => 'hebdomadaire', 'month' => 'mensuel', 'custom' => 'de période'];

/** « 7 novembre 2026 » (ou sans l'année). */
function mk_fdate(string $d, bool $year = true): string
{
    $t = new DateTimeImmutable($d);
    return $t->format('j') . ($t->format('j') === '1' ? 'er' : '') . ' ' . MK_MONTHS[(int) $t->format('n') - 1] . ($year ? ' ' . $t->format('Y') : '');
}

function mk_frange(string $from, string $to): string
{
    if ($from === $to) return mk_fdate($from);
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    if ($a->format('Y-m') === $b->format('Y-m')) {
        return 'du ' . $a->format('j') . ($a->format('j') === '1' ? 'er' : '') . ' au ' . mk_fdate($to);
    }
    return 'du ' . mk_fdate($from, $a->format('Y') !== $b->format('Y')) . ' au ' . mk_fdate($to);
}

/**
 * Bornes d'une période et de la période précédente de même durée.
 * day : le jour ; week : du lundi au dimanche ; month : le mois civil ; custom : $date → $to.
 */
function mk_period(string $type, string $date, string $to = ''): array
{
    if (!mk_valid_date($date)) $date = mk_today();
    $d = new DateTimeImmutable($date);
    switch ($type) {
        case 'week':
            $from = $d->modify('-' . ((int) $d->format('N') - 1) . ' day');
            $end = $from->modify('+6 day');
            $pf = $from->modify('-7 day');
            $pt = $from->modify('-1 day');
            $label = 'Semaine ' . mk_frange($from->format('Y-m-d'), $end->format('Y-m-d'));
            break;
        case 'month':
            $from = $d->modify('first day of this month');
            $end = $d->modify('last day of this month');
            $pf = $from->modify('first day of previous month');
            $pt = $from->modify('-1 day');
            $label = ucfirst(MK_MONTHS[(int) $from->format('n') - 1]) . ' ' . $from->format('Y');
            break;
        case 'custom':
            if (!mk_valid_date($to)) $to = $date;
            if ($to < $date) [$date, $to] = [$to, $date];
            $from = new DateTimeImmutable($date);
            $end = new DateTimeImmutable($to);
            if ((int) $from->diff($end)->days > 1100) $from = $end->modify('-1100 day'); // garde-fou : ~3 ans
            $len = (int) $from->diff($end)->days + 1;
            $pt = $from->modify('-1 day');
            $pf = $pt->modify('-' . ($len - 1) . ' day');
            $label = ucfirst(mk_frange($from->format('Y-m-d'), $end->format('Y-m-d')));
            break;
        default:
            $type = 'day';
            $from = $end = $d;
            $pf = $pt = $d->modify('-1 day');
            $label = ucfirst(MK_DAYS[(int) $d->format('N')]) . ' ' . mk_fdate($d->format('Y-m-d'));
    }
    // Semaine ou mois en cours : on compare au même nombre de jours de la période précédente
    // (du 1er au 7 novembre contre du 1er au 7 octobre), pas à la période précédente entière.
    $today = new DateTimeImmutable(mk_today());
    $partial = $type !== 'day' && $from <= $today && $today < $end;
    if ($partial) {
        $cut = $pf->modify('+' . (int) $from->diff($today)->days . ' day');
        if ($cut < $pt) $pt = $cut;
    }
    return [
        'type' => $type, 'from' => $from->format('Y-m-d'), 'to' => $end->format('Y-m-d'),
        'prev_from' => $pf->format('Y-m-d'), 'prev_to' => $pt->format('Y-m-d'),
        'days' => (int) $from->diff($end)->days + 1, 'label' => $label,
        'partial' => $partial, 'cut' => $partial ? $today->format('Y-m-d') : null,
    ];
}

function mk_ratio($a, $b): ?float
{
    return (float) $b > 0 ? (float) $a / (float) $b : null;
}

function mk_inc(array &$map, string $key, $n = 1): void
{
    $map[$key] = ($map[$key] ?? 0) + $n;
}

/** Points du graphique d'évolution : un par jour, ou un par mois au-delà de 62 jours. */
function mk_buckets(string $from, string $to): array
{
    $out = [];
    $a = new DateTimeImmutable($from);
    $b = new DateTimeImmutable($to);
    $monthly = (int) $a->diff($b)->days + 1 > 62;
    for ($d = $a; $d <= $b; $d = $d->modify('+1 day')) {
        $key = $monthly ? $d->format('Y-m') : $d->format('Y-m-d');
        if (!isset($out[$key])) {
            $short = MK_MONTHS_SHORT[(int) $d->format('n') - 1];
            $out[$key] = ['key' => $key, 'label' => $monthly ? $short . ' ' . $d->format('y') : $d->format('j') . ' ' . $short];
        }
    }
    return [$out, $monthly];
}

/** Tous les chiffres agrégés d'une période. */
function mk_collect(string $from, string $to): array
{
    $E = mk_enums();
    $types = array_keys($E['contact_types']);

    /* ---- Leads & contacts */
    [$buckets, $monthly] = mk_buckets($from, $to);
    foreach ($buckets as $k => $b) foreach ($types as $t) $buckets[$k][$t] = 0;
    $L = ['total' => 0, 'qualified' => 0, 'converted' => 0, 'non_qualified' => 0, 'untreated' => 0, 'unassigned' => 0,
        'by_type' => [], 'by_source' => [], 'by_status' => [], 'by_commercial' => [], 'reasons' => [], 'source_quality' => [], 'type_quality' => []];
    foreach (q('SELECT date, type, source, status, assigned_to, reason FROM contacts WHERE date BETWEEN ? AND ?', [$from, $to]) as $r) {
        $qual = in_array($r['status'], ['qualifie', 'converti'], true);
        $conv = $r['status'] === 'converti';
        $L['total']++;
        if ($qual) $L['qualified']++;
        if ($conv) $L['converted']++;
        if ($r['status'] === 'non_qualifie') { $L['non_qualified']++; mk_inc($L['reasons'], $r['reason'] ?: 'autre'); }
        if ($r['status'] === 'nouveau') $L['untreated']++;
        if ($r['assigned_to'] === '') $L['unassigned']++;
        mk_inc($L['by_type'], $r['type']);
        mk_inc($L['by_source'], $r['source']);
        mk_inc($L['by_status'], $r['status']);
        foreach (['source_quality' => $r['source'], 'type_quality' => $r['type']] as $m => $key) {
            $L[$m][$key] = $L[$m][$key] ?? ['total' => 0, 'qualified' => 0, 'converted' => 0];
            $L[$m][$key]['total']++;
            if ($qual) $L[$m][$key]['qualified']++;
            if ($conv) $L[$m][$key]['converted']++;
        }
        $c = $r['assigned_to'];
        $L['by_commercial'][$c] = $L['by_commercial'][$c] ?? ['total' => 0, 'qualified' => 0, 'converted' => 0, 'untreated' => 0];
        $L['by_commercial'][$c]['total']++;
        if ($qual) $L['by_commercial'][$c]['qualified']++;
        if ($conv) $L['by_commercial'][$c]['converted']++;
        if ($r['status'] === 'nouveau') $L['by_commercial'][$c]['untreated']++;
        $bk = $monthly ? substr($r['date'], 0, 7) : $r['date'];
        if (isset($buckets[$bk][$r['type']])) $buckets[$bk][$r['type']]++;
    }
    foreach (['by_type', 'by_source', 'by_status', 'reasons'] as $m) arsort($L[$m]);
    uasort($L['source_quality'], fn ($a, $b) => $b['total'] <=> $a['total']);
    uasort($L['by_commercial'], fn ($a, $b) => $b['total'] <=> $a['total']);
    $L['qual_rate'] = mk_ratio($L['qualified'], $L['total']);
    $L['series'] = array_values($buckets);
    $L['series_monthly'] = $monthly;

    /* ---- Questions & inquiries */
    $I = ['total' => 0, 'answered' => 0, 'by_source' => [], 'by_category' => [], 'by_status' => []];
    foreach (q('SELECT source, category, status FROM inquiries WHERE date BETWEEN ? AND ?', [$from, $to]) as $r) {
        $I['total']++;
        if (in_array($r['status'], ['repondu', 'clos'], true)) $I['answered']++;
        mk_inc($I['by_source'], $r['source']);
        mk_inc($I['by_category'], $r['category']);
        mk_inc($I['by_status'], $r['status']);
    }
    foreach (['by_source', 'by_category', 'by_status'] as $m) arsort($I[$m]);
    $I['answer_rate'] = mk_ratio($I['answered'], $I['total']);

    /* ---- Projets : nouvelles demandes et montants levés pendant la période */
    $P = ['requests' => 0, 'by_status' => [], 'requests_list' => [], 'raised' => 0.0, 'investors' => 0, 'funded' => []];
    foreach (q('SELECT id, date, name, owner, sector, source, amount_target, status FROM projects WHERE date BETWEEN ? AND ? ORDER BY date DESC, id DESC', [$from, $to]) as $r) {
        $P['requests']++;
        mk_inc($P['by_status'], $r['status']);
        if (count($P['requests_list']) < 50) $P['requests_list'][] = $r;
    }
    $hist = q('SELECT h.project_id, h.date, h.raised, h.investors, h.status, p.name FROM project_history h JOIN projects p ON p.id = h.project_id WHERE h.date <= ? ORDER BY h.project_id, h.date, h.id', [$to]);
    $byP = [];
    foreach ($hist as $h) $byP[$h['project_id']][] = $h;
    foreach ($byP as $rows) {
        $before = ['raised' => 0.0, 'investors' => 0, 'status' => ''];
        $last = null;
        foreach ($rows as $h) {
            if ($h['date'] < $from) { $before = $h; continue; }
            if ($h['status'] === 'finance' && ($last['status'] ?? $before['status']) !== 'finance') $P['funded'][] = $h['name'];
            $last = $h;
        }
        if ($last) {
            $P['raised'] += max(0, (float) $last['raised'] - (float) $before['raised']);
            $P['investors'] += max(0, (int) $last['investors'] - (int) $before['investors']);
        }
    }
    $P['funded'] = array_values(array_unique($P['funded']));

    /* ---- Social media : activité (publications) et performances par réseau */
    $SOC = ['posts' => 0, 'posts_by_platform' => [], 'posts_by_format' => [], 'platforms' => [], 'totals' => [], 'entries' => 0];
    foreach (q('SELECT platform, format FROM posts WHERE date BETWEEN ? AND ?', [$from, $to]) as $r) {
        $SOC['posts']++;
        mk_inc($SOC['posts_by_platform'], $r['platform']);
        mk_inc($SOC['posts_by_format'], $r['format']);
    }
    $metrics = ['reach', 'impressions', 'engagement', 'clicks', 'new_followers'];
    $SOC['totals'] = array_fill_keys($metrics, 0);
    foreach (array_keys($E['platforms']) as $pf) {
        $SOC['platforms'][$pf] = array_fill_keys($metrics, 0) + ['posts' => (int) ($SOC['posts_by_platform'][$pf] ?? 0), 'followers' => null];
    }
    foreach (q('SELECT platform, SUM(reach) reach, SUM(impressions) impressions, SUM(engagement) engagement, SUM(clicks) clicks, SUM(new_followers) new_followers, COUNT(*) n FROM social_stats WHERE date BETWEEN ? AND ? GROUP BY platform', [$from, $to]) as $r) {
        if (!isset($SOC['platforms'][$r['platform']])) continue;
        foreach ($metrics as $m) {
            $SOC['platforms'][$r['platform']][$m] = (int) $r[$m];
            $SOC['totals'][$m] += (int) $r[$m];
        }
        $SOC['entries'] += (int) $r['n'];
    }
    foreach (q("SELECT s.platform, s.followers FROM social_stats s WHERE s.followers > 0 AND s.date <= ? AND s.id = (SELECT s2.id FROM social_stats s2 WHERE s2.platform = s.platform AND s2.followers > 0 AND s2.date <= ? ORDER BY s2.date DESC, s2.id DESC LIMIT 1)", [$to, $to]) as $r) {
        if (isset($SOC['platforms'][$r['platform']])) $SOC['platforms'][$r['platform']]['followers'] = (int) $r['followers'];
    }
    foreach ($SOC['platforms'] as $pf => $v) $SOC['platforms'][$pf]['eng_rate'] = mk_ratio($v['engagement'], $v['reach']);
    $SOC['totals']['eng_rate'] = mk_ratio($SOC['totals']['engagement'], $SOC['totals']['reach']);
    $SOC['totals']['followers'] = array_sum(array_map(fn ($v) => (int) $v['followers'], $SOC['platforms']));

    /* ---- Website & landing pages */
    $wm = ['visits', 'visitors', 'reg_investors', 'reg_owners', 'forms'];
    $W = ['totals' => array_fill_keys($wm, 0), 'site' => array_fill_keys($wm, 0), 'landing' => array_fill_keys($wm, 0), 'pages' => [], 'entries' => 0];
    foreach (q('SELECT p.id, p.name, p.type, p.url, p.funnel_id, COALESCE(SUM(w.visits),0) visits, COALESCE(SUM(w.visitors),0) visitors, COALESCE(SUM(w.reg_investors),0) reg_investors, COALESCE(SUM(w.reg_owners),0) reg_owners, COALESCE(SUM(w.forms),0) forms, COUNT(w.id) n FROM pages p LEFT JOIN web_stats w ON w.page_id = p.id AND w.date BETWEEN ? AND ? GROUP BY p.id ORDER BY p.type DESC, visits DESC, p.name', [$from, $to]) as $r) {
        $row = ['id' => (int) $r['id'], 'name' => $r['name'], 'type' => $r['type'], 'url' => $r['url'], 'funnel_id' => $r['funnel_id'] === null ? null : (int) $r['funnel_id'], 'entries' => (int) $r['n']];
        foreach ($wm as $m) {
            $row[$m] = (int) $r[$m];
            $W['totals'][$m] += (int) $r[$m];
            $W[$r['type'] === 'site' ? 'site' : 'landing'][$m] += (int) $r[$m];
        }
        $row['registrations'] = $row['reg_investors'] + $row['reg_owners'];
        $row['conv'] = mk_ratio($row['registrations'] + $row['forms'], $row['visits']);
        $W['entries'] += (int) $r['n'];
        $W['pages'][] = $row;
    }
    foreach (['totals', 'site', 'landing'] as $g) {
        $W[$g]['registrations'] = $W[$g]['reg_investors'] + $W[$g]['reg_owners'];
        $W[$g]['conv'] = mk_ratio($W[$g]['registrations'] + $W[$g]['forms'], $W[$g]['visits']);
    }

    /* ---- Ads Manager : résultats de la période, par campagne */
    $am = ['spend', 'impressions', 'clicks', 'leads', 'conversions'];
    $A = ['totals' => array_fill_keys($am, 0), 'campaigns' => [], 'entries' => 0];
    foreach (q('SELECT c.id, c.name, c.platform, c.status, SUM(r.spend) spend, SUM(r.impressions) impressions, SUM(r.clicks) clicks, SUM(r.leads) leads, SUM(r.conversions) conversions, COUNT(*) n FROM ad_results r JOIN campaigns c ON c.id = r.campaign_id WHERE r.date BETWEEN ? AND ? GROUP BY c.id ORDER BY spend DESC', [$from, $to]) as $r) {
        $row = ['id' => (int) $r['id'], 'name' => $r['name'], 'platform' => $r['platform'], 'status' => $r['status']];
        foreach ($am as $m) {
            $row[$m] = $m === 'spend' ? round((float) $r[$m], 2) : (int) $r[$m];
            $A['totals'][$m] += $row[$m];
        }
        $A['entries'] += (int) $r['n'];
        $A['campaigns'][] = mk_ad_rates($row);
    }
    $A['totals'] = mk_ad_rates($A['totals']);

    /* ---- Email marketing : campagnes envoyées pendant la période */
    $em = ['sent', 'opens', 'clicks', 'conversions', 'unsubscribes'];
    $M = ['count' => 0, 'totals' => array_fill_keys($em, 0), 'list' => []];
    foreach (q("SELECT id, name, date, subject, segment, objective, target, sent, opens, clicks, conversions, unsubscribes, funnel_id FROM emails WHERE status = 'envoyee' AND date BETWEEN ? AND ? ORDER BY date DESC, id DESC", [$from, $to]) as $r) {
        $M['count']++;
        foreach ($em as $m) $M['totals'][$m] += (int) $r[$m];
        $M['list'][] = mk_email_rates($r);
    }
    $M['totals'] = mk_email_rates($M['totals']);

    return ['leads' => $L, 'inquiries' => $I, 'projects' => $P, 'social' => $SOC, 'web' => $W, 'ads' => $A, 'email' => $M];
}

function mk_ad_rates(array $r): array
{
    $r['ctr'] = mk_ratio($r['clicks'], $r['impressions']);
    $r['cpc'] = mk_ratio($r['spend'], $r['clicks']);
    $r['cpl'] = mk_ratio($r['spend'], $r['leads']);
    $r['cpa'] = mk_ratio($r['spend'], $r['conversions']);
    $r['cpm'] = $r['impressions'] > 0 ? $r['spend'] / $r['impressions'] * 1000 : null;
    return $r;
}

function mk_email_rates(array $r): array
{
    foreach (['sent', 'opens', 'clicks', 'conversions', 'unsubscribes', 'target'] as $m) {
        if (array_key_exists($m, $r)) $r[$m] = (int) $r[$m];
    }
    $r['open_rate'] = mk_ratio($r['opens'], $r['sent']);
    $r['click_rate'] = mk_ratio($r['clicks'], $r['sent']);
    $r['ctor'] = mk_ratio($r['clicks'], $r['opens']);
    $r['conv_rate'] = mk_ratio($r['conversions'], $r['sent']);
    if (isset($r['target'])) $r['target_pct'] = mk_ratio($r['conversions'], $r['target']);
    return $r;
}

/**
 * Fiche budget / objectif de chaque campagne concernée par la période : cumul depuis le
 * début de la campagne jusqu'à la fin de la période, budget restant, rythme de dépense.
 */
function mk_campaign_snapshot(string $from, string $to, ?int $only = null): array
{
    $out = [];
    $sql = "SELECT * FROM campaigns WHERE status != 'brouillon' AND start <= ? AND (end = '' OR end >= ?)" . ($only ? ' AND id = ?' : '') . ' ORDER BY start DESC, id DESC';
    $params = [$to, $from];
    if ($only) {
        $sql = 'SELECT * FROM campaigns WHERE id = ?';
        $params = [$only];
    }
    foreach (q($sql, $params) as $c) {
        $t = q1('SELECT COALESCE(SUM(spend),0) spend, COALESCE(SUM(impressions),0) impressions, COALESCE(SUM(clicks),0) clicks, COALESCE(SUM(leads),0) leads, COALESCE(SUM(conversions),0) conversions FROM ad_results WHERE campaign_id = ? AND date <= ?', [(int) $c['id'], $to]);
        $tot = mk_ad_rates(['spend' => round((float) $t['spend'], 2), 'impressions' => (int) $t['impressions'], 'clicks' => (int) $t['clicks'], 'leads' => (int) $t['leads'], 'conversions' => (int) $t['conversions']]);
        $budget = (float) $c['budget'];
        $elapsed = null;
        $daysLeft = null;
        if ($c['end'] !== '') {
            $len = (int) (new DateTimeImmutable($c['start']))->diff(new DateTimeImmutable($c['end']))->days + 1;
            $ref = min($to, mk_today()) < $c['start'] ? $c['start'] : min($to, mk_today());
            $done = $ref > $c['end'] ? $len : (int) (new DateTimeImmutable($c['start']))->diff(new DateTimeImmutable($ref))->days + 1;
            $elapsed = $len > 0 ? min(1, $done / $len) : null;
            $daysLeft = max(0, (int) (new DateTimeImmutable(mk_today()))->diff(new DateTimeImmutable($c['end']))->format('%r%a'));
        }
        $metric = $c['target_metric'] ?: 'leads';
        $achieved = (int) ($tot[$metric] ?? 0);
        $out[] = [
            'id' => (int) $c['id'], 'name' => $c['name'], 'platform' => $c['platform'], 'objective' => $c['objective'],
            'status' => $c['status'], 'start' => $c['start'], 'end' => $c['end'], 'funnel_id' => $c['funnel_id'] === null ? null : (int) $c['funnel_id'],
            'budget' => $budget, 'spent' => $tot['spend'], 'remaining' => $budget > 0 ? $budget - $tot['spend'] : null,
            'budget_pct' => mk_ratio($tot['spend'], $budget), 'elapsed' => $elapsed, 'days_left' => $daysLeft,
            'target_metric' => $metric, 'target_value' => (int) $c['target_value'], 'achieved' => $achieved,
            'target_pct' => mk_ratio($achieved, (int) $c['target_value']), 'totals' => $tot,
        ];
    }
    return $out;
}

/** Projets en cours de financement, à la date du jour. */
function mk_funding_snapshot(): array
{
    $out = [];
    foreach (q("SELECT * FROM projects WHERE status = 'en_financement' ORDER BY CASE WHEN funding_end = '' THEN 1 ELSE 0 END, funding_end, name") as $p) {
        $out[] = [
            'id' => (int) $p['id'], 'name' => $p['name'], 'owner' => $p['owner'], 'sector' => $p['sector'],
            'target' => (float) $p['amount_target'], 'raised' => (float) $p['raised'], 'investors' => (int) $p['investors'],
            'pct' => mk_ratio($p['raised'], $p['amount_target']), 'start' => $p['funding_start'], 'end' => $p['funding_end'],
            'days_left' => $p['funding_end'] !== '' ? (int) (new DateTimeImmutable(mk_today()))->diff(new DateTimeImmutable($p['funding_end']))->format('%r%a') : null,
        ];
    }
    return $out;
}

/** Ce qui reste à traiter aujourd'hui (indépendant de la période affichée). */
function mk_backlog(bool $lists = false): array
{
    $today = mk_today();
    $b = [
        'untreated' => (int) qv("SELECT COUNT(*) FROM contacts WHERE status = 'nouveau'"),
        'stale' => (int) qv("SELECT COUNT(*) FROM contacts WHERE status = 'nouveau' AND date <= ?", [mk_add_days($today, -2)]),
        'unassigned' => (int) qv("SELECT COUNT(*) FROM contacts WHERE status IN ('nouveau','contacte') AND assigned_to = ''"),
        'open_inquiries' => (int) qv("SELECT COUNT(*) FROM inquiries WHERE status IN ('nouveau','en_cours')"),
        'stale_inquiries' => (int) qv("SELECT COUNT(*) FROM inquiries WHERE status IN ('nouveau','en_cours') AND date <= ?", [mk_add_days($today, -1)]),
        'pending_projects' => (int) qv("SELECT COUNT(*) FROM projects WHERE status IN ('demande','etude')"),
    ];
    if ($lists) {
        $b['leads_list'] = q("SELECT * FROM contacts WHERE status = 'nouveau' OR (status = 'contacte' AND assigned_to = '') ORDER BY date, id LIMIT 60");
        $b['inquiries_list'] = q("SELECT * FROM inquiries WHERE status IN ('nouveau','en_cours') ORDER BY date, id LIMIT 60");
    }
    return $b;
}

/** Rapport complet d'une période. */
function mk_report(string $type, string $date, string $to = ''): array
{
    $p = mk_period($type, $date, $to);
    $S = mk_settings();
    $cur = mk_collect($p['from'], $p['to']);
    $prv = mk_collect($p['prev_from'], $p['prev_to']);

    $funnels = [];
    foreach (q("SELECT * FROM funnels WHERE status = 'actif' ORDER BY name") as $f) {
        $funnels[] = mk_funnel_compute($f, $p['from'], $p['to']);
    }
    $r = [
        'type' => $p['type'], 'type_label' => MK_TYPES[$p['type']], 'period' => $p,
        'org' => $S['org'], 'currency' => $S['currency'], 'generated' => time(),
        'kpis' => mk_kpis($cur, $prv),
        'leads' => $cur['leads'], 'inquiries' => $cur['inquiries'],
        'projects' => $cur['projects'] + ['funding' => mk_funding_snapshot()],
        'social' => $cur['social'], 'web' => $cur['web'],
        'ads' => $cur['ads'] + ['budgets' => mk_campaign_snapshot($p['from'], $p['to'])],
        'email' => $cur['email'],
        'funnels' => $funnels,
        'backlog' => mk_backlog($p['type'] === 'day'),
        'prev' => [
            'leads' => $prv['leads'], 'inquiries' => ['total' => $prv['inquiries']['total']],
            'social' => ['posts' => $prv['social']['posts'], 'totals' => $prv['social']['totals']],
            'web' => ['totals' => $prv['web']['totals']], 'ads' => ['totals' => $prv['ads']['totals']],
            'email' => ['totals' => $prv['email']['totals'], 'count' => $prv['email']['count']],
        ],
    ];
    if ($p['type'] === 'day') {
        $d = $p['from'];
        $r['day'] = [
            'contacts' => q('SELECT * FROM contacts WHERE date = ? ORDER BY id DESC', [$d]),
            'inquiries' => q('SELECT * FROM inquiries WHERE date = ? ORDER BY id DESC', [$d]),
            'posts' => q('SELECT * FROM posts WHERE date = ? ORDER BY id DESC', [$d]),
            'projects' => q('SELECT * FROM projects WHERE date = ? ORDER BY id DESC', [$d]),
        ];
    }
    $r['insights'] = mk_insights($r, $cur, $prv);
    return $r;
}

/** Indicateurs principaux avec la valeur de la période précédente. */
function mk_kpis(array $c, array $p): array
{
    $k = fn (string $key, string $label, $v, $pv, string $fmt = 'int', string $better = 'up') => compact('key', 'label', 'fmt', 'better') + ['value' => $v, 'prev' => $pv];
    $reg = fn ($x) => $x['web']['totals']['registrations'];
    return [
        $k('leads', 'Nouveaux leads & contacts', $c['leads']['total'], $p['leads']['total']),
        $k('qualified', 'Leads qualifiés', $c['leads']['qualified'], $p['leads']['qualified']),
        $k('qual_rate', 'Taux de qualification', $c['leads']['qual_rate'], $p['leads']['qual_rate'], 'pct'),
        $k('inquiries', 'Questions & inquiries', $c['inquiries']['total'], $p['inquiries']['total'], 'int', 'neutral'),
        $k('project_requests', 'Demandes de projets', $c['projects']['requests'], $p['projects']['requests']),
        $k('raised', 'Montant levé', $c['projects']['raised'], $p['projects']['raised'], 'money'),
        $k('visits', 'Visites site & landing pages', $c['web']['totals']['visits'], $p['web']['totals']['visits']),
        $k('registrations', 'Inscriptions', $reg($c), $reg($p)),
        $k('web_conv', 'Taux de conversion web', $c['web']['totals']['conv'], $p['web']['totals']['conv'], 'pct'),
        $k('ads_spend', 'Dépenses publicitaires', $c['ads']['totals']['spend'], $p['ads']['totals']['spend'], 'money', 'neutral'),
        $k('ads_leads', 'Leads publicitaires', $c['ads']['totals']['leads'], $p['ads']['totals']['leads']),
        $k('cpl', 'Coût par lead', $c['ads']['totals']['cpl'], $p['ads']['totals']['cpl'], 'money', 'down'),
        $k('posts', 'Publications social media', $c['social']['posts'], $p['social']['posts']),
        $k('social_reach', 'Portée social media', $c['social']['totals']['reach'], $p['social']['totals']['reach']),
        $k('emails_sent', 'Emails envoyés', $c['email']['totals']['sent'], $p['email']['totals']['sent'], 'int', 'neutral'),
        $k('email_conversions', 'Objectifs email atteints', $c['email']['totals']['conversions'], $p['email']['totals']['conversions']),
    ];
}

function mk_pct($x, int $dec = 0): string
{
    return $x === null ? '—' : number_format((float) $x * 100, $dec, ',', ' ') . ' %';
}

function mk_num($x, int $dec = 0): string
{
    return $x === null ? '—' : number_format((float) $x, $dec, ',', ' ');
}

function mk_money($x): string
{
    return $x === null ? '—' : mk_num($x) . ' ' . mk_settings()['currency'];
}

/**
 * « Analyse & décisions » : constats calculés à partir des chiffres de la période, classés
 * du plus urgent au plus informatif. Niveaux : critical, warning, good, info.
 */
function mk_insights(array $r, array $cur, array $prv): array
{
    $out = [];
    $add = function (string $level, string $text, string $section = '') use (&$out) { $out[] = compact('level', 'text', 'section'); };
    $L = $cur['leads'];
    $pl = $prv['leads']['total'];
    $periodWord = ['day' => 'la veille', 'week' => 'la semaine précédente', 'month' => 'le mois précédent', 'custom' => 'la période précédente'][$r['type']];
    if ($r['period']['partial']) $periodWord = mk_frange($r['period']['prev_from'], $r['period']['prev_to']);
    $long = $r['type'] !== 'day';

    // Leads : tendance
    if ($L['total'] >= 5 || $pl >= 5) {
        $ch = mk_ratio($L['total'] - $pl, $pl);
        if ($pl > 0 && $ch !== null && $ch >= 0.2) $add('good', 'Leads en hausse de ' . mk_pct($ch) . ' (' . mk_num($L['total']) . ' contre ' . mk_num($pl) . " $periodWord).", 'leads');
        elseif ($pl > 0 && $ch !== null && $ch <= -0.2) $add('warning', 'Leads en baisse de ' . mk_pct(-$ch) . ' (' . mk_num($L['total']) . ' contre ' . mk_num($pl) . " $periodWord) : vérifier les campagnes et les publications.", 'leads');
    }
    // À traiter
    $b = $r['backlog'];
    if ($b['stale'] > 0) $add('critical', "{$b['stale']} lead(s) attendent un premier contact depuis plus de 48 h : à traiter en priorité.", 'leads');
    if ($b['unassigned'] > 0) $add('warning', "{$b['unassigned']} lead(s) ne sont assignés à aucun commercial.", 'leads');
    if ($b['stale_inquiries'] > 0) $add('warning', "{$b['stale_inquiries']} question(s) attendent une réponse depuis plus de 24 h.", 'inquiries');

    // Sources : volume et qualité
    if ($L['total'] >= 3 && $L['by_source']) {
        $top = array_key_first($L['by_source']);
        $add('info', 'Première source de leads : ' . mk_label('sources', $top) . ' (' . mk_pct($L['by_source'][$top] / $L['total']) . ' des leads).', 'leads');
    }
    $quals = array_filter($L['source_quality'], fn ($q) => $q['total'] >= 3);
    if (count($quals) >= 2 && $L['qualified'] > 0) {
        uasort($quals, fn ($a, $b) => ($b['qualified'] / $b['total']) <=> ($a['qualified'] / $a['total']));
        $best = array_key_first($quals);
        $worst = array_key_last($quals);
        $add('good', mk_label('sources', $best) . ' apporte les leads les mieux qualifiés (' . mk_pct($quals[$best]['qualified'] / $quals[$best]['total']) . ' qualifiés).', 'leads');
        $wr = $quals[$worst]['qualified'] / $quals[$worst]['total'];
        if ($worst !== $best && $quals[$worst]['total'] >= 5 && $wr < 0.2) {
            $add('warning', mk_label('sources', $worst) . ' : seulement ' . mk_pct($wr) . ' de leads qualifiés. Revoir le ciblage ou le message.', 'leads');
        }
    }
    if ($L['non_qualified'] >= 3 && $L['reasons']) {
        $rk = array_key_first($L['reasons']);
        $add('info', 'Principale raison de non-qualification : ' . mk_label('reasons', $rk) . " ({$L['reasons'][$rk]} sur {$L['non_qualified']}).", 'leads');
    }

    // Publicité : budgets, rythme, objectifs, coût par lead
    foreach ($r['ads']['budgets'] as $c) {
        if ($c['status'] !== 'active') continue;
        if ($c['budget'] > 0 && $c['spent'] > $c['budget']) {
            $add('critical', "Budget dépassé : « {$c['name']} » (" . mk_money($c['spent']) . ' dépensés pour ' . mk_money($c['budget']) . ' prévus).', 'ads');
        } elseif ($c['budget_pct'] !== null && $c['budget_pct'] >= 0.9) {
            $add('warning', "Budget presque épuisé : « {$c['name']} » (" . mk_pct($c['budget_pct']) . ' consommé).', 'ads');
        } elseif ($c['budget_pct'] !== null && $c['elapsed'] !== null && $c['elapsed'] >= 0.3 && $c['elapsed'] < 1 && $c['budget_pct'] < $c['elapsed'] * 0.5) {
            $add('info', "« {$c['name']} » dépense lentement : " . mk_pct($c['budget_pct']) . ' du budget pour ' . mk_pct($c['elapsed']) . ' de la durée.', 'ads');
        }
        if ($c['target_pct'] !== null) {
            if ($c['target_pct'] >= 1) {
                $add('good', "Objectif atteint pour « {$c['name']} » : " . mk_num($c['achieved']) . ' ' . mb_strtolower(mk_label('target_metrics', $c['target_metric'])) . ' sur ' . mk_num($c['target_value']) . ' (' . mk_pct($c['target_pct']) . ').', 'ads');
            } elseif ($c['elapsed'] !== null && $c['elapsed'] >= 0.5 && $c['target_pct'] < $c['elapsed'] * 0.7) {
                $add('warning', "« {$c['name']} » est en retard sur son objectif : " . mk_pct($c['target_pct']) . ' atteint pour ' . mk_pct($c['elapsed']) . ' de la durée écoulée.', 'ads');
            }
        }
    }
    $withLeads = [];
    foreach ($cur['ads']['campaigns'] as $c) {
        if ($c['spend'] > 0 && $c['leads'] === 0 && $c['conversions'] === 0 && $long) {
            $add('warning', "« {$c['name']} » a dépensé " . mk_money($c['spend']) . ' sans lead ni conversion sur la période.', 'ads');
        }
        if ($c['leads'] > 0 && $c['cpl'] !== null) $withLeads[] = $c;
    }
    if (count($withLeads) >= 2) {
        usort($withLeads, fn ($a, $b) => $a['cpl'] <=> $b['cpl']);
        $best = $withLeads[0];
        $worst = $withLeads[count($withLeads) - 1];
        if ($worst['cpl'] > 1.5 * $best['cpl']) {
            $add('info', "Meilleur coût par lead : « {$best['name']} » (" . mk_money($best['cpl']) . "). Le plus cher : « {$worst['name']} » (" . mk_money($worst['cpl']) . ') : envisager de déplacer du budget.', 'ads');
        }
    }

    // Funnels : étape où l'on perd le plus
    foreach ($r['funnels'] as $f) {
        if ($f['worst'] === null || $f['worst'] < 1) continue;
        $a = $f['stages'][$f['worst'] - 1];
        $s = $f['stages'][$f['worst']];
        if ($a['value'] <= 0) continue;
        $add($s['drop'] >= 0.8 ? 'warning' : 'info', "Funnel « {$f['name']} » : la plus forte perte est entre « {$a['name']} » et « {$s['name']} » (" . mk_pct($s['drop']) . ' d\'abandon, ' . mk_num($s['lost']) . ' prospects perdus).', 'funnels');
    }

    // Website : tendance et pages
    $wv = $cur['web']['totals']['visits'];
    $pv = $prv['web']['totals']['visits'];
    if ($long && $pv >= 50) {
        $ch = mk_ratio($wv - $pv, $pv);
        if ($ch !== null && $ch >= 0.2) $add('good', 'Visites du site en hausse de ' . mk_pct($ch) . ' (' . mk_num($wv) . ' contre ' . mk_num($pv) . ').', 'web');
        elseif ($ch !== null && $ch <= -0.2) $add('warning', 'Visites du site en baisse de ' . mk_pct(-$ch) . ' (' . mk_num($wv) . ' contre ' . mk_num($pv) . ').', 'web');
    }
    $pages = array_values(array_filter($cur['web']['pages'], fn ($p) => $p['visits'] >= 50 && $p['conv'] !== null));
    if (count($pages) >= 2) {
        usort($pages, fn ($a, $b) => $b['conv'] <=> $a['conv']);
        $best = $pages[0];
        $worst = $pages[count($pages) - 1];
        $add('good', "Page la plus efficace : « {$best['name']} » (" . mk_pct($best['conv'], 1) . ' de conversion).', 'web');
        if ($worst['conv'] < $best['conv'] / 2) $add('warning', "Page à optimiser : « {$worst['name']} » (" . mk_pct($worst['conv'], 1) . ' de conversion pour ' . mk_num($worst['visits']) . ' visites).', 'web');
    }

    // Email
    foreach ($cur['email']['list'] as $e) {
        if ($e['sent'] < 50) continue;
        if ($e['open_rate'] !== null && $e['open_rate'] < 0.15) $add('warning', "Email « {$e['name']} » : taux d'ouverture faible (" . mk_pct($e['open_rate']) . ') : tester un autre objet ou un autre horaire.', 'email');
        if ($e['target'] > 0 && $e['target_pct'] !== null && $e['target_pct'] >= 1) $add('good', "Email « {$e['name']} » : objectif atteint (" . mk_num($e['conversions']) . ' sur ' . mk_num($e['target']) . ').', 'email');
    }

    // Social media
    $plats = array_filter($cur['social']['platforms'], fn ($p) => $p['reach'] >= 100 && $p['eng_rate'] !== null);
    if (count($plats) >= 2) {
        uasort($plats, fn ($a, $b) => $b['eng_rate'] <=> $a['eng_rate']);
        $bp = array_key_first($plats);
        $add('good', mk_label('platforms', $bp) . ' génère le meilleur engagement (' . mk_pct($plats[$bp]['eng_rate'], 1) . ' de la portée).', 'social');
    }
    if ($long && $cur['social']['posts'] === 0) $add('warning', 'Aucune publication social media enregistrée sur la période.', 'social');

    // Projets en financement
    foreach ($r['projects']['funding'] as $p) {
        if ($p['days_left'] !== null && $p['days_left'] >= 0 && $p['days_left'] <= 7 && $p['pct'] !== null && $p['pct'] < 0.8) {
            $add('warning', "Le projet « {$p['name']} » se termine dans {$p['days_left']} jour(s) à " . mk_pct($p['pct']) . ' de son objectif : relancer les investisseurs.', 'projects');
        }
    }
    foreach ($cur['projects']['funded'] as $name) $add('good', "Projet financé : « $name ».", 'projects');

    // Données manquantes (rapports de la semaine et du mois)
    if ($long) {
        if (q1('SELECT 1 FROM pages LIMIT 1') && $cur['web']['entries'] === 0) $add('info', "Aucune statistique de site saisie pour cette période (Website Analytics).", 'web');
        if ($cur['social']['entries'] === 0) $add('info', 'Aucune performance social media saisie pour cette période (Social Media → Performances).', 'social');
        if ($cur['ads']['entries'] === 0 && array_filter($r['ads']['budgets'], fn ($c) => $c['status'] === 'active')) $add('info', 'Des campagnes sont actives mais aucun résultat publicitaire n\'est saisi pour cette période.', 'ads');
    }

    $rank = ['critical' => 0, 'warning' => 1, 'good' => 2, 'info' => 3];
    usort($out, fn ($a, $b) => $rank[$a['level']] <=> $rank[$b['level']]);
    return $out;
}

/* ============================================================ email du rapport */

function mk_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function mk_kpi_value(array $k, $v): string
{
    if ($v === null) return '—';
    return $k['fmt'] === 'pct' ? mk_pct($v, 1) : ($k['fmt'] === 'money' ? mk_money($v) : mk_num($v));
}

/** Variation affichée à côté d'un indicateur : « ▲ 12 % » (vert si c'est une bonne nouvelle). */
function mk_delta(array $k): array
{
    $v = $k['value'];
    $p = $k['prev'];
    if ($v === null || $p === null) return ['', '#667085'];
    if ($k['fmt'] === 'pct') {
        $d = ((float) $v - (float) $p) * 100;
        if (abs($d) < 0.05) return ['=', '#667085'];
        $txt = ($d > 0 ? '▲ ' : '▼ ') . number_format(abs($d), 1, ',', ' ') . ' pt';
    } else {
        if ((float) $p == 0.0) return [(float) $v > 0 ? 'nouveau' : '', '#667085'];
        $d = ((float) $v - (float) $p) / (float) $p;
        if (abs($d) < 0.005) return ['=', '#667085'];
        $txt = ($d > 0 ? '▲ ' : '▼ ') . mk_pct(abs($d));
    }
    $up = ($k['fmt'] === 'pct' ? (float) $v - (float) $p : $d) > 0;
    $color = $k['better'] === 'neutral' ? '#667085' : (($up xor $k['better'] === 'down') ? '#067647' : '#B42318');
    return [$txt, $color];
}

/** Sujet, HTML et texte de l'email d'un rapport. */
function mk_report_email(array $r, string $link = ''): array
{
    $cur = $r['currency'];
    $title = 'Rapport ' . $r['type_label'] . ' marketing — ' . $r['period']['label'];
    $subject = '[' . $r['org'] . '] ' . $title;
    $th = 'style="text-align:left;padding:6px 8px;background:#F2F4F7;font-size:12px;color:#344054;border-bottom:1px solid #E4E7EC"';
    $thn = 'style="text-align:right;padding:6px 8px;background:#F2F4F7;font-size:12px;color:#344054;border-bottom:1px solid #E4E7EC"';
    $td = 'style="padding:6px 8px;border-bottom:1px solid #F2F4F7;font-size:13px"';
    $tdn = 'style="padding:6px 8px;border-bottom:1px solid #F2F4F7;font-size:13px;text-align:right;white-space:nowrap"';
    $h2 = fn ($t) => '<h2 style="font-size:16px;margin:26px 0 8px;color:#0B1B33">' . mk_h($t) . '</h2>';
    $table = function (array $head, array $rows, array $num = []) use ($th, $thn, $td, $tdn): string {
        if (!$rows) return '<p style="color:#667085;font-size:13px;margin:4px 0">Aucune donnée sur la période.</p>';
        $h = '<table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%"><tr>';
        foreach ($head as $i => $c) $h .= '<th ' . (in_array($i, $num, true) ? $thn : $th) . '>' . mk_h($c) . '</th>';
        $h .= '</tr>';
        foreach ($rows as $row) {
            $h .= '<tr>';
            foreach (array_values($row) as $i => $c) $h .= '<td ' . (in_array($i, $num, true) ? $tdn : $td) . '>' . mk_h($c) . '</td>';
            $h .= '</tr>';
        }
        return $h . '</table>';
    };

    // Indicateurs : deux par ligne
    $cells = [];
    $text = [$title, str_repeat('=', 40), ''];
    foreach ($r['kpis'] as $k) {
        [$dTxt, $dCol] = mk_delta($k);
        $cells[] = '<td style="width:50%;padding:10px 12px;border:1px solid #E4E7EC;border-radius:8px;vertical-align:top"><div style="font-size:11px;color:#667085;text-transform:uppercase;letter-spacing:.04em;font-weight:bold">'
            . mk_h($k['label']) . '</div><div style="font-size:20px;font-weight:bold;color:#101828;margin-top:2px">' . mk_h(mk_kpi_value($k, $k['value']))
            . '</div><div style="font-size:12px;color:' . $dCol . '">' . mk_h($dTxt) . ($dTxt !== '' && $dTxt !== 'nouveau' ? ' <span style="color:#98A2B3">vs ' . mk_h(mk_kpi_value($k, $k['prev'])) . '</span>' : '') . '</div></td>';
        $text[] = '- ' . $k['label'] . ' : ' . mk_kpi_value($k, $k['value']) . ($dTxt !== '' ? " ($dTxt)" : '');
    }
    $grid = '<table role="presentation" cellspacing="6" cellpadding="0" style="width:100%;border-collapse:separate">';
    foreach (array_chunk($cells, 2) as $pair) $grid .= '<tr>' . implode('', $pair) . (count($pair) < 2 ? '<td></td>' : '') . '</tr>';
    $grid .= '</table>';

    $icons = ['critical' => ['⛔', '#B42318'], 'warning' => ['⚠️', '#B54708'], 'good' => ['✅', '#067647'], 'info' => ['ℹ️', '#344054']];
    $ins = '';
    $text[] = '';
    $text[] = 'ANALYSE & DÉCISIONS';
    foreach ($r['insights'] as $i) {
        [$ic, $col] = $icons[$i['level']];
        $ins .= '<tr><td style="padding:5px 8px 5px 0;vertical-align:top;font-size:14px">' . $ic . '</td><td style="padding:5px 0;font-size:14px;color:' . $col . '">' . mk_h($i['text']) . '</td></tr>';
        $text[] = '- ' . $i['text'];
    }
    if (!$r['insights']) {
        $ins = '<tr><td style="font-size:14px;color:#667085">Rien de particulier à signaler sur la période.</td></tr>';
        $text[] = '- Rien de particulier à signaler.';
    }

    $L = $r['leads'];
    $srcRows = [];
    foreach ($L['source_quality'] as $s => $v) $srcRows[] = [mk_label('sources', $s), mk_num($v['total']), mk_num($v['qualified']), mk_pct(mk_ratio($v['qualified'], $v['total']))];
    $comRows = [];
    foreach ($L['by_commercial'] as $c => $v) $comRows[] = [$c === '' ? 'Non assigné' : $c, mk_num($v['total']), mk_num($v['untreated']), mk_num($v['qualified']), mk_num($v['converted'])];
    $typeTxt = [];
    foreach ($L['by_type'] as $t => $n) $typeTxt[] = mk_label('contact_types', $t) . ' : ' . $n;

    $adRows = [];
    foreach ($r['ads']['campaigns'] as $c) $adRows[] = [$c['name'], mk_money($c['spend']), mk_num($c['clicks']), mk_num($c['leads']), mk_num($c['conversions']), mk_money($c['cpl'])];
    $budRows = [];
    foreach ($r['ads']['budgets'] as $c) $budRows[] = [$c['name'], mk_label('campaign_status', $c['status']), mk_money($c['budget']), mk_money($c['spent']), mk_money($c['remaining']), $c['target_value'] > 0 ? mk_num($c['achieved']) . ' / ' . mk_num($c['target_value']) . ' ' . mb_strtolower(mk_label('target_metrics', $c['target_metric'])) : '—'];
    $webRows = [];
    foreach ($r['web']['pages'] as $p) if ($p['visits'] || $p['registrations'] || $p['forms']) $webRows[] = [$p['name'], mk_num($p['visits']), mk_num($p['reg_investors']), mk_num($p['reg_owners']), mk_num($p['forms']), mk_pct($p['conv'], 1)];
    $socRows = [];
    foreach ($r['social']['platforms'] as $pf => $v) if ($v['posts'] || $v['reach'] || $v['followers']) $socRows[] = [mk_label('platforms', $pf), mk_num($v['posts']), mk_num($v['reach']), mk_num($v['engagement']), mk_pct($v['eng_rate'], 1), mk_num($v['new_followers']), $v['followers'] === null ? '—' : mk_num($v['followers'])];
    $emRows = [];
    foreach ($r['email']['list'] as $e) $emRows[] = [$e['name'], mk_num($e['sent']), mk_pct($e['open_rate']), mk_pct($e['click_rate'], 1), mk_num($e['conversions']) . ($e['target'] > 0 ? ' / ' . mk_num($e['target']) : '')];
    $fundRows = [];
    foreach ($r['projects']['funding'] as $p) $fundRows[] = [$p['name'], mk_money($p['raised']), mk_money($p['target']), mk_pct($p['pct']), mk_num($p['investors']), $p['end'] !== '' ? mk_fdate($p['end']) : '—'];
    $reqRows = [];
    foreach ($r['projects']['requests_list'] as $p) $reqRows[] = [mk_fdate($p['date'], false), $p['name'], $p['sector'], mk_money((float) $p['amount_target']), mk_label('project_status', $p['status'])];

    $funnelHtml = '';
    foreach ($r['funnels'] as $f) {
        $rows = [];
        foreach ($f['stages'] as $s) $rows[] = [$s['name'] . ($s['channels'] ? ' — ' . implode(', ', array_map(fn ($c) => mk_label('sources', $c), $s['channels'])) : ''), mk_num($s['value']), mk_pct($s['conv_prev']), mk_pct($s['drop']), $s['target'] ? mk_pct($s['target_pct']) . ' de ' . mk_num($s['target']) : '—'];
        $funnelHtml .= '<p style="font-weight:bold;margin:12px 0 4px;font-size:14px">' . mk_h($f['name']) . ($f['overall'] !== null ? ' — conversion globale ' . mk_h(mk_pct($f['overall'], 1)) : '') . '</p>'
            . $table(['Étape', 'Prospects', 'Conversion', 'Abandon', 'Objectif'], $rows, [1, 2, 3]);
    }

    $b = $r['backlog'];
    $todo = "{$b['untreated']} lead(s) à traiter (dont {$b['stale']} depuis plus de 48 h), {$b['unassigned']} non assigné(s), {$b['open_inquiries']} question(s) en attente, {$b['pending_projects']} demande(s) de projet à étudier.";

    $body = '<p style="margin:0 0 6px;font-size:13px;color:#667085">' . mk_h($r['org']) . ' · Marketing Dashboard</p>'
        . '<h1 style="font-size:22px;margin:0 0 4px;color:#0B1B33">Rapport ' . mk_h($r['type_label']) . '</h1>'
        . '<p style="margin:0 0 14px;font-size:15px;color:#344054">' . mk_h($r['period']['label']) . ' · comparé à ' . mk_h(mk_frange($r['period']['prev_from'], $r['period']['prev_to'])) . '</p>'
        . $grid
        . $h2('Analyse & décisions') . '<table role="presentation" cellspacing="0" cellpadding="0">' . $ins . '</table>'
        . $h2('À traiter maintenant') . '<p style="font-size:14px;margin:0">' . mk_h($todo) . '</p>'
        . $h2('Leads & contacts') . '<p style="font-size:14px;margin:0 0 8px">' . mk_h($L['total'] . ' nouveau(x) — ' . ($typeTxt ? implode(' · ', $typeTxt) : 'aucun') . ' · ' . $L['qualified'] . ' qualifié(s), ' . $L['non_qualified'] . ' non qualifié(s), ' . $L['converted'] . ' converti(s)') . '</p>'
        . $table(['Source', 'Leads', 'Qualifiés', 'Taux'], $srcRows, [1, 2, 3])
        . ($comRows ? '<p style="font-weight:bold;margin:12px 0 4px;font-size:14px">Par commercial</p>' . $table(['Commercial', 'Leads', 'À traiter', 'Qualifiés', 'Convertis'], $comRows, [1, 2, 3, 4]) : '')
        . $h2('Questions & inquiries') . '<p style="font-size:14px;margin:0">' . mk_h($r['inquiries']['total'] . ' reçue(s), ' . $r['inquiries']['answered'] . ' répondue(s) (' . mk_pct($r['inquiries']['answer_rate']) . ').') . '</p>'
        . $h2('Projets') . '<p style="font-size:14px;margin:0 0 8px">' . mk_h($r['projects']['requests'] . ' nouvelle(s) demande(s) · ' . mk_money($r['projects']['raised']) . ' levés sur la période · ' . $r['projects']['investors'] . ' nouvel(le)s participation(s)') . '</p>'
        . ($reqRows ? $table(['Date', 'Projet', 'Secteur', 'Montant', 'Statut'], $reqRows, [3]) : '')
        . '<p style="font-weight:bold;margin:12px 0 4px;font-size:14px">En cours de financement</p>' . $table(['Projet', 'Levé', 'Objectif', 'Avancement', 'Investisseurs', 'Fin'], $fundRows, [1, 2, 3, 4])
        . $h2('Website Analytics') . $table(['Page', 'Visites', 'Inscr. investisseurs', 'Inscr. porteurs', 'Formulaires', 'Conversion'], $webRows, [1, 2, 3, 4, 5])
        . $h2('Social Media') . $table(['Canal', 'Publications', 'Portée', 'Engagement', 'Taux', 'Nouveaux abonnés', 'Abonnés'], $socRows, [1, 2, 3, 4, 5, 6])
        . $h2('Ads Manager') . $table(['Campagne', 'Dépense', 'Clics', 'Leads', 'Conversions', 'Coût / lead'], $adRows, [1, 2, 3, 4, 5])
        . ($budRows ? '<p style="font-weight:bold;margin:12px 0 4px;font-size:14px">Budgets et objectifs (cumul)</p>' . $table(['Campagne', 'Statut', 'Budget', 'Dépensé', 'Restant', 'Objectif'], $budRows, [2, 3, 4]) : '')
        . $h2('Email Marketing') . $table(['Campagne', 'Envoyés', 'Ouverture*', 'Clics', 'Objectifs'], $emRows, [1, 2, 3, 4])
        . ($emRows ? '<p style="font-size:11px;color:#98A2B3;margin:4px 0 0">* Ouvertures estimées : certaines messageries bloquent leur mesure.</p>' : '')
        . ($funnelHtml !== '' ? $h2('Funnel Analytics') . $funnelHtml : '')
        . ($link !== '' ? '<p style="margin:26px 0 0"><a href="' . mk_h($link) . '" style="display:inline-block;background:#0B1B33;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:bold;font-size:14px">Ouvrir le tableau de bord</a></p>' : '');

    $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"><title>' . mk_h($subject) . '</title></head>'
        . '<body style="margin:0;background:#F5F6F8;font-family:Arial,Helvetica,sans-serif;color:#101828">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F5F6F8"><tr><td align="center" style="padding:20px 10px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#ffffff;border:1px solid #E4E7EC;border-radius:14px"><tr><td style="padding:24px">'
        . $body . '</td></tr></table>'
        . '<p style="font-size:11px;color:#98A2B3;margin:12px 0 0">Rapport généré automatiquement le ' . mk_h(mk_fdate(date('Y-m-d', (int) $r['generated']))) . ' à ' . date('H:i', (int) $r['generated']) . '. Destinataires et fréquence : Réglages du tableau de bord.</p>'
        . '</td></tr></table></body></html>';

    $text[] = '';
    $text[] = 'À TRAITER : ' . $todo;
    $text[] = '';
    $text[] = 'Leads : ' . $L['total'] . ' (' . ($typeTxt ? implode(', ', $typeTxt) : 'aucun') . ')';
    $text[] = 'Projets : ' . $r['projects']['requests'] . ' demande(s), ' . mk_money($r['projects']['raised']) . ' levés';
    if ($link !== '') { $text[] = ''; $text[] = 'Tableau de bord : ' . $link; }
    return [$subject, $html, implode("\n", $text)];
}

/** Envoie un rapport aux destinataires des réglages et le range dans les archives. */
function mk_send_report(array $r, string $origin, array $to = [], bool $dry = false): array
{
    $to = $to ?: mk_settings()['recipients'];
    $sent = [];
    $errors = [];
    $link = mk_site_url() . '#overview/' . ['day' => 'daily', 'week' => 'weekly', 'month' => 'monthly', 'custom' => 'weekly'][$r['type']] . '/' . $r['period']['from'];
    [$subject, $html, $text] = mk_report_email($r, $link);
    foreach ($to as $email) {
        if ($dry) { $sent[] = $email; continue; }
        try {
            mk_send_mail($email, $subject, $html, $text);
            $sent[] = $email;
        } catch (Throwable $e) {
            $errors[] = $email . ' : ' . $e->getMessage();
        }
    }
    $id = 0;
    // Aucun envoi réussi : archivé comme « échec », pour que le cron le retente à son prochain passage.
    if (!$dry) $id = mk_archive_report($r, $to && !$sent ? $origin . '-echec' : $origin, $sent);
    return ['id' => $id, 'sent' => $sent, 'errors' => $errors, 'subject' => $subject];
}

/** Range une photo figée du rapport (remplace celle de la même période). */
function mk_archive_report(array $r, string $origin, array $sent = []): int
{
    $p = $r['period'];
    unset($r['day'], $r['backlog']['leads_list'], $r['backlog']['inquiries_list']);
    db()->prepare('DELETE FROM reports WHERE type = ? AND date_from = ? AND date_to = ?')->execute([$r['type'], $p['from'], $p['to']]);
    db()->prepare('INSERT INTO reports (type, date_from, date_to, label, payload, sent_to, origin, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$r['type'], $p['from'], $p['to'], $p['label'], json_encode($r, JSON_UNESCAPED_UNICODE), implode(', ', $sent), $origin, time()]);
    return (int) db()->lastInsertId();
}
