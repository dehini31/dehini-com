<?php
/**
 * Pages publiques du parcours du créateur, rendues par le serveur (aperçus des liens, lecture sans JavaScript) :
 *   https://dehini.com/studioma/verify/<identifiant>  — vérification d'une réalisation (réécrit en p.php?verify=…)
 *   https://dehini.com/studioma/creator/<identifiant> — passeport public du créateur (réécrit en p.php?creator=…)
 *
 * Seul ce que la personne a rendu visible est montré : une réalisation « privée » ou un passeport fermé répondent comme
 * s'ils n'existaient pas. Jamais de nom de projet, de Studio, de carte, de fichier, d'email ni d'invité ; le nom d'un
 * client seulement s'il l'a accepté. Une réalisation retirée affiche son nouvel état, jamais « vérifiée ».
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/journey.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

/** Tracés des icônes utilisées (les mêmes que assets/icons.js, licence ISC). */
const P_ICONS = [
    'board' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 7v7"/><path d="M12 7v4"/><path d="M16 7v9"/>',
    'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/>',
    'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
    'star' => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/>',
    'trophy' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
];
function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function p_icon(string $name): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . (P_ICONS[$name] ?? P_ICONS['star']) . '</svg>';
}
function p_date(int $t): string
{
    return date('j F Y', $t);
}
function p_avatar(array $u, string $cls = ''): string
{
    if ((string) $u['avatar'] !== '') return '<span class="pp-avatar ' . $cls . '"><img src="' . e(site_url() . 'api.php?action=file&t=' . rawurlencode((string) $u['avatar']) . '&s=1') . '" alt=""></span>';
    $ini = implode('', array_map(function (string $w): string { return mb_strtoupper(mb_substr($w, 0, 1)); }, array_slice(preg_split('/\s+/u', trim((string) $u['name'])) ?: [], 0, 2)));
    return '<span class="pp-avatar pp-ini ' . $cls . '">' . e($ini) . '</span>';
}
/** Gabarit commun. $index : la page peut être indexée par les moteurs de recherche (passeport ouvert). */
function p_page(string $title, string $desc, string $url, string $body, bool $index, int $status = 200): void
{
    http_response_code($status);
    header('X-Robots-Tag: ' . ($index ? 'index, follow' : 'noindex, nofollow'));
    $logo = '<svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#5b4bff"/><rect x="7" y="8" width="5" height="16" rx="2.5" fill="#fff"/><rect x="13.5" y="8" width="5" height="11" rx="2.5" fill="#fff" opacity=".75"/><rect x="20" y="8" width="5" height="7" rx="2.5" fill="#ff7a59"/></svg>';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title><meta name="description" content="' . e($desc) . '">'
        . '<meta name="robots" content="' . ($index ? 'index, follow' : 'noindex, nofollow') . '">'
        . ($url !== '' ? '<link rel="canonical" href="' . e($url) . '"><meta property="og:url" content="' . e($url) . '">' : '')
        . '<meta property="og:type" content="profile"><meta property="og:site_name" content="Studioma"><meta property="og:title" content="' . e($title) . '">'
        . '<meta property="og:description" content="' . e($desc) . '"><meta name="twitter:card" content="summary">'
        . '<meta name="theme-color" content="#0f0d1f"><link rel="icon" href="' . e(site_url()) . 'assets/favicon.svg" type="image/svg+xml">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="' . e(site_url()) . 'assets/passport.css"></head><body>'
        . '<header class="pp-top"><a class="pp-logo" href="' . e(site_url()) . '">' . $logo . '<b>Studioma</b></a><span class="pp-grow"></span>'
        . '<a class="pp-btn pp-btn-soft" href="' . e(site_url()) . 'app/#/signup">Join Studioma</a></header>'
        . '<main class="pp-main">' . $body . '</main>'
        . '<footer class="pp-foot"><p>Studioma — Your Work. Your Reputation.</p><p>Levels and achievements are Studioma\'s internal indicators of professional development, based on activity recorded in Studioma. They are not externally accredited qualifications.</p></footer>'
        . '</body></html>';
    exit;
}
function p_missing(string $what): void
{
    p_page('Not available — Studioma', 'This page is not available.', '', '<section class="pp-card pp-center"><h1>' . e($what) . ' isn\'t available</h1><p class="pp-muted">It may be private, or the link may be wrong. Ask its owner for an up-to-date link.</p><a class="pp-btn" href="' . e(site_url()) . '">Discover Studioma</a></section>', false, 404);
}
/** Compte qui regarde sa propre page : ses visites ne sont pas comptées. */
function p_viewer(): int
{
    try {
        $u = current_user();
        return $u ? (int) $u['id'] : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/* ------------------------------------------------------- page de vérification */

function p_verify(string $id): void
{
    $ua = row('SELECT * FROM user_achievements WHERE public_id = ?', [$id]);
    $owner = $ua ? row('SELECT * FROM users WHERE id = ? AND suspended = 0', [$ua['uid']]) : null;
    // Privée : comme si elle n'existait pas. Retirée : visible seulement si elle avait été partagée avant (lien encore en
    // circulation) — elle affiche alors son état, jamais « vérifiée ».
    if (!$ua || !$owner || ($ua['visibility'] === 'private' && $ua['status'] !== 'revoked') || ($ua['status'] === 'revoked' && (int) $ua['shared'] === 0 && (int) $ua['views'] === 0)) p_missing('This achievement');
    if (p_viewer() !== (int) $owner['id']) q('UPDATE user_achievements SET views = views + 1 WHERE id = ?', [$ua['id']]);
    $v = journey_ua_view($ua);
    $def = JOURNEY_ACHIEVEMENTS[$ua['aid']] ?? null;
    $pp = passport_settings((int) $owner['id']);
    $url = journey_url((string) $ua['public_id']);
    $revoked = $ua['status'] === 'revoked';
    $self = $ua['status'] === 'self';
    $state = $revoked ? ['revoked', 'Revoked', 'Studioma revoked this achievement on ' . p_date((int) $ua['revoked_at']) . '. It is no longer valid.']
        : ($self ? ['self', 'Self reported', 'Added by its owner. Studioma did not verify it.'] : ['ok', 'Verified', $v['label'] . ' · checked live by Studioma']);
    $name = $pp['enabled'] ? '<a href="' . e(passport_url((string) $owner['handle'])) . '">' . e($owner['name']) . '</a>' : e($owner['name']);
    $ev = '';
    if (!$revoked) {
        foreach (journey_evidence_view($ua, $owner, 'public') as $x) {
            $ev .= '<li>' . p_icon('check-circle') . '<span><b>' . e($x['label']) . '</b> · ' . e(p_date($x['at'])) . (isset($x['client']) ? '<br><span class="pp-muted">' . e($x['client']) . '</span>' : '') . '</span></li>';
        }
        if ($self && $ua['url'] !== '') $ev .= '<li>' . p_icon('star') . '<span><b>Link provided by the creator</b><br><a href="' . e($ua['url']) . '" rel="nofollow ugc noopener" target="_blank">' . e($ua['url']) . '</a></span></li>';
    }
    $body = '<section class="pp-card pp-verify pp-state-' . $state[0] . '">'
        . '<div class="pp-badge pp-badge-' . e($ua['verification']) . '">' . p_icon($v['icon']) . '</div>'
        . '<p class="pp-kicker">Studioma achievement verification</p>'
        . '<h1>' . e($v['title']) . '</h1>'
        . '<p class="pp-lead">' . e($self ? $ua['body'] : $v['desc']) . '</p>'
        . '<div class="pp-status pp-status-' . $state[0] . '"><b>' . e($state[1]) . '</b><span>' . e($state[2]) . '</span></div>'
        . '<dl class="pp-facts">'
        . '<div><dt>Creator</dt><dd>' . $name . '</dd></div>'
        . '<div><dt>' . ($self ? 'Date' : 'Earned on') . '</dt><dd>' . e(p_date((int) $ua['earned'])) . '</dd></div>'
        . '<div><dt>Verification</dt><dd>' . e($revoked ? 'Revoked — no longer valid' : ($self ? 'Self Reported — not independently verified' : (JOURNEY_VERIFICATIONS[$ua['verification']] ?? ''))) . '</dd></div>'
        . '<div><dt>Achievement ID</dt><dd><code>' . e($ua['public_id']) . '</code></dd></div>'
        . '</dl>'
        . ($ev !== '' ? '<h2>Evidence</h2><ul class="pp-evidence">' . $ev . '</ul>' : '')
        . ($def && !$revoked ? '<h2>What this proves</h2><p>' . e($def['proves']) . '</p><p class="pp-muted">' . e($def['not']) . '</p>' : '')
        . '<p class="pp-muted pp-small">This page shows the current status of the achievement each time it is opened. It does not give access to any private project, Studio, card or file.</p>'
        . '</section>';
    $who = $owner['name'];
    $title = $v['title'] . ' — ' . ($revoked ? 'revoked achievement' : ($self ? 'self-reported achievement' : 'verified achievement')) . ' of ' . $who . ' | Studioma';
    $desc = $revoked ? 'This Studioma achievement was revoked and is no longer valid.' : ($self ? 'Self-reported achievement (not independently verified) of ' . $who . ' on Studioma.' : $v['label'] . ': ' . $v['desc']);
    p_page($title, $desc, $url, $body, !$revoked && $ua['visibility'] === 'passport' && $pp['enabled']);
}

/* ------------------------------------------------------------------- passeport */

function p_passport(string $handle): void
{
    $u = row('SELECT * FROM users WHERE handle = ? AND suspended = 0', [$handle]);
    $pp = $u ? passport_settings((int) $u['id']) : null;
    if (!$u || !$pp['enabled']) p_missing('This Creator Passport');
    $uid = (int) $u['id'];
    if (p_viewer() !== $uid) q('UPDATE passport_settings SET views = views + 1 WHERE uid = ?', [$uid]);
    $st = json_decode((string) (val('SELECT stats FROM journey_progress WHERE uid = ?', [$uid]) ?? ''), true);
    if (!is_array($st) || !isset($st['level'])) $st = journey_eval($uid);
    $levels = journey_rules()['levels'];
    $lv = journey_level_view($st);
    $headline = $pp['headline'] !== '' ? $pp['headline'] : ((string) $u['title'] !== '' ? (string) $u['title'] : 'Creative Professional');
    $counts = journey_stats_view($uid, $st);
    $shown = rows("SELECT * FROM user_achievements WHERE uid = ? AND visibility = 'passport' AND status IN ('verified', 'self') ORDER BY status = 'self', earned DESC", [$uid]);
    $body = '<section class="pp-card pp-hero">' . p_avatar($u, 'pp-avatar-l')
        . '<div><p class="pp-kicker">Studioma Creator Passport</p><h1>' . e($u['name']) . '</h1><p class="pp-lead">' . e($headline) . '</p>'
        . ($pp['show_level'] ? '<p class="pp-level">' . p_icon('trophy') . '<b>Level ' . sprintf('%02d', $lv['n']) . ' — ' . e($lv['name']) . '</b></p>' : '')
        . '</div></section>';
    if ($pp['show_stats']) {
        $tiles = [['Verified achievements', $counts['verified']], ['Client-approved achievements', $counts['client']], ['Completed projects', $counts['projects']], ['Achievements shown', count($shown)]];
        $body .= '<section class="pp-tiles">' . implode('', array_map(function (array $t): string { return '<div class="pp-tile"><b>' . e(number_format((int) $t[1])) . '</b><span>' . e($t[0]) . '</span></div>'; }, $tiles)) . '</section>'
            . '<p class="pp-summary">' . e(journey_summary($u, $st, (bool) $pp['show_level'])) . '</p>';
    }
    $body .= '<section class="pp-card"><h2>Achievements</h2>';
    if (!$shown) $body .= '<p class="pp-muted">' . e($u['name']) . ' hasn\'t made any achievement public yet.</p>';
    else {
        $body .= '<div class="pp-achievements">';
        foreach ($shown as $ua) {
            $v = journey_ua_view($ua);
            $body .= '<a class="pp-ach" href="' . e(journey_url((string) $ua['public_id'])) . '"><span class="pp-badge pp-badge-sm pp-badge-' . e($ua['verification']) . '">' . p_icon($v['icon']) . '</span>'
                . '<span><b>' . e($v['title']) . '</b><small class="pp-label pp-label-' . e($ua['verification']) . '">' . e($v['label']) . '</small><small class="pp-muted">' . e(p_date((int) $ua['earned'])) . '</small></span></a>';
        }
        $body .= '</div>';
    }
    $body .= '</section>';
    if ($pp['show_work']) {
        $work = rows("SELECT title, brand, role, period FROM portfolio WHERE uid = ? AND status = 'published' ORDER BY id DESC LIMIT 6", [$uid]);
        if ($work) {
            $body .= '<section class="pp-card"><h2>Selected work</h2><ul class="pp-work">';
            foreach ($work as $w) $body .= '<li><b>' . e($w['title']) . '</b><span class="pp-muted">' . e(implode(' · ', array_filter([(string) $w['brand'], (string) $w['role'], (string) $w['period']]))) . '</span></li>';
            $body .= '</ul><a class="pp-link" href="' . e(app_url('u/' . $u['handle'])) . '">See the full profile on Studioma</a></section>';
        }
    }
    if ($pp['show_journey']) {
        $body .= '<section class="pp-card"><h2>Creator Journey</h2>';
        if ($lv['next']) {
            $body .= '<p>' . ($pp['show_level'] ? 'Progress to Level ' . sprintf('%02d', $lv['next']['n']) . ' — ' . e($lv['next']['name']) . ': ' : 'Progress to the next level: ') . '<b>' . (int) $lv['next']['percent'] . '%</b></p>'
                . '<div class="pp-bar"><span style="width:' . (int) $lv['next']['percent'] . '%"></span></div>';
        } else {
            $body .= '<p>Highest Studioma level reached.</p>';
        }
        $body .= '<ol class="pp-timeline">';
        foreach (array_slice(journey_timeline($uid, 'all', true), 0, 12) as $t) {
            if (!$pp['show_level'] && strpos($t['text'], 'Reached Level') === 0) continue;
            $body .= '<li><time>' . e(date('M Y', $t['at'])) . '</time><span>' . e($t['text']) . '</span></li>';
        }
        $body .= '</ol></section>';
    }
    $title = $u['name'] . ' — Creator Passport | Studioma';
    $desc = $headline . '. ' . ($pp['show_level'] ? 'Level ' . sprintf('%02d', $lv['n']) . ' — ' . $levels[$lv['n']]['name'] . ' on Studioma. ' : '') . ($pp['show_stats'] ? $counts['verified'] . ' verified achievements.' : '');
    p_page($title, trim($desc), passport_url((string) $u['handle']), $body, true);
}

try {
    db();
    $verify = (string) ($_GET['verify'] ?? '');
    $creator = (string) ($_GET['creator'] ?? '');
    if ($verify !== '' && preg_match('/^[a-f0-9]{16}$/', $verify)) p_verify($verify);
    if ($creator !== '' && preg_match('/^[a-z0-9-]{1,40}$/', $creator)) p_passport($creator);
    p_missing('This page');
} catch (Throwable $ex) {
    error_log('studioma p.php: ' . $ex->getMessage());
    http_response_code(500);
    echo 'The service is temporarily unavailable.';
}
