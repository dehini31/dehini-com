<?php
/**
 * Pages publiques de Studioma rendues par le serveur (moteurs de recherche, aperçus des liens, lecture sans JavaScript) :
 *   https://dehini.com/studioma/verify/<identifiant>  — vérification d'une réalisation (réécrit en p.php?verify=…)
 *   https://dehini.com/studioma/creator/<identifiant> — passeport public du créateur (réécrit en p.php?creator=…)
 *   https://dehini.com/studioma/opportunities/        — opportunités ouvertes publiées par les membres (p.php?opps=1)
 *   https://dehini.com/studioma/opportunity/<n°>-<titre> — une opportunité (p.php?opp=…)
 *   https://dehini.com/studioma/pricing/               — formules et prix, tirés de lib/plans.php (p.php?pricing=1)
 *   https://dehini.com/studioma/sitemap.xml            — plan du site : seulement les pages publiques indexables
 *
 * Seul ce que la personne a rendu visible est montré : une réalisation « privée » ou un passeport fermé répondent comme
 * s'ils n'existaient pas. Jamais de nom de projet, de Studio, de carte, de fichier, d'email ni d'invité ; le nom d'un
 * client seulement s'il l'a accepté. Une réalisation retirée affiche son nouvel état, jamais « vérifiée ».
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/plans.php';
require __DIR__ . '/lib/billing.php';
require __DIR__ . '/lib/access.php';
require __DIR__ . '/lib/files.php';
require __DIR__ . '/lib/pubs.php';
require __DIR__ . '/lib/promo.php';
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
    'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
    'target' => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
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
/** Image des aperçus de liens (1200 × 630) quand la page n'en a pas une à elle. */
function p_og_image(): string
{
    return site_url() . 'assets/og-studioma.jpg';
}
/**
 * Gabarit commun. $index : la page peut être indexée par les moteurs de recherche. $o : type (Open Graph), image,
 * jsonld (données structurées), journey (pied de page du parcours du créateur).
 */
function p_page(string $title, string $desc, string $url, string $body, bool $index, int $status = 200, array $o = []): void
{
    http_response_code($status);
    $ld = '';
    foreach ($o['jsonld'] ?? [] as $data) $ld .= '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
    header('X-Robots-Tag: ' . ($index ? 'index, follow' : 'noindex, nofollow'));
    $logo = '<svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="9" fill="#5b4bff"/><rect x="7" y="8" width="5" height="16" rx="2.5" fill="#fff"/><rect x="13.5" y="8" width="5" height="11" rx="2.5" fill="#fff" opacity=".75"/><rect x="20" y="8" width="5" height="7" rx="2.5" fill="#ff7a59"/></svg>';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title><meta name="description" content="' . e($desc) . '">'
        . '<meta name="robots" content="' . ($index ? 'index, follow' : 'noindex, nofollow') . '">'
        . ($url !== '' ? '<link rel="canonical" href="' . e($url) . '"><meta property="og:url" content="' . e($url) . '">' : '')
        . '<meta property="og:type" content="' . e($o['type'] ?? 'profile') . '"><meta property="og:site_name" content="Studioma"><meta property="og:title" content="' . e($title) . '">'
        . '<meta property="og:description" content="' . e($desc) . '"><meta property="og:image" content="' . e($o['image'] ?? p_og_image()) . '">'
        . '<meta name="twitter:card" content="summary_large_image">' . $ld
        . '<meta name="theme-color" content="#0f0d1f"><link rel="icon" href="' . e(site_url()) . 'assets/favicon.svg" type="image/svg+xml">'
        . '<link rel="preload" href="' . e(site_url()) . 'assets/fonts/plus-jakarta-sans-latin.woff2" as="font" type="font/woff2" crossorigin>'
        . '<link rel="stylesheet" href="' . e(site_url()) . 'assets/fonts.css">'
        . '<link rel="stylesheet" href="' . e(site_url()) . 'assets/passport.css"></head><body>'
        . '<a class="pp-skip" href="#main">Skip to content</a>'
        . '<header class="pp-top"><a class="pp-logo" href="' . e(site_url()) . '">' . $logo . '<b>Studioma</b></a>'
        . '<nav class="pp-nav" aria-label="Studioma"><a href="' . e(site_url()) . 'opportunities/">Opportunities</a><a href="' . e(site_url()) . 'pricing/">Pricing</a></nav><span class="pp-grow"></span>'
        . '<a class="pp-btn pp-btn-soft" href="' . e(site_url()) . 'app/#/signup">Join Studioma</a></header>'
        . '<main class="pp-main" id="main">' . $body . '</main>'
        . '<footer class="pp-foot"><p>Studioma — Your Work. Your Reputation. · <a href="' . e(site_url()) . '">About Studioma</a> · <a href="' . e(site_url()) . 'opportunities/">Opportunities</a> · <a href="' . e(site_url()) . 'pricing/">Pricing</a></p>'
        . (($o['journey'] ?? true) ? '<p>Levels and achievements are Studioma\'s internal indicators of professional development, based on activity recorded in Studioma. They are not externally accredited qualifications.</p>' : '') . '</footer>'
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

/* ------------------------------------------------------------- opportunités */

/** Texte saisi, échappé, avec ses retours à la ligne. */
function p_text(string $s): string
{
    return nl2br(e($s), false);
}
function p_money(int $n, string $cur): string
{
    if ($n <= 0) return '';
    if (class_exists('NumberFormatter')) {
        $f = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $f->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $out = $f->formatCurrency($n, $cur);
        if ($out !== false) return $out;
    }
    return $cur . ' ' . number_format($n);
}
function p_budget(array $p): string
{
    $a = p_money((int) $p['budget_min'], (string) $p['currency']);
    $b = p_money((int) $p['budget_max'], (string) $p['currency']);
    return $a && $b ? $a . ' – ' . $b : ($b ? 'Up to ' . $b : ($a ? 'From ' . $a : ''));
}
function p_day(string $ymd): string
{
    $t = strtotime($ymd . ' 12:00');
    return $t ? date('j F Y', $t) : $ymd;
}
/** Opportunité publique : ouverte ou fermée, auteur non suspendu (sinon null). Une opportunité périmée se ferme ici. */
function p_opp_row(int $id): ?array
{
    $p = row("SELECT * FROM pubs WHERE id = ? AND kind = 'opportunity' AND status IN ('open', 'closed')", [$id]);
    if (!$p || (int) val('SELECT suspended FROM users WHERE id = ?', [$p['uid']])) return null;
    return pub_sync($p);
}
/** Faits courts d'une opportunité (liste et page). */
function p_opp_facts(array $p): string
{
    $f = [];
    if ($b = p_budget($p)) $f[] = '<span>' . p_icon('target') . e($b) . '</span>';
    if ($p['apply_until'] !== '') $f[] = '<span>' . p_icon('calendar') . 'Apply by ' . e(p_day((string) $p['apply_until'])) . '</span>';
    if ($p['mode'] !== '') $f[] = '<span>' . p_icon('globe') . e((STM_WORK_MODES[$p['mode']] ?? $p['mode']) . ($p['location'] !== '' ? ' · ' . $p['location'] : '')) . '</span>';
    return $f ? '<p class="pp-facts-line">' . implode('', $f) . '</p>' : '';
}
function p_crumbs(array $items): array
{
    $list = [];
    foreach (array_values($items) as $i => [$name, $url]) $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $url];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}
const P_OPPS_PER_PAGE = 20;
function p_opps(int $page): void
{
    $page = max(1, $page);
    foreach (rows("SELECT * FROM pubs WHERE kind = 'opportunity' AND status = 'open'") as $o) pub_sync($o);   // périmées : fermées
    $where = "p.kind = 'opportunity' AND p.status = 'open' AND p.uid NOT IN (SELECT id FROM users WHERE suspended > 0)";
    $total = (int) val("SELECT COUNT(*) FROM pubs p WHERE $where");
    $pages = max(1, (int) ceil($total / P_OPPS_PER_PAGE));
    if ($page > $pages) p_missing('This page');
    $list = rows("SELECT p.* FROM pubs p WHERE $where ORDER BY p.id DESC LIMIT " . P_OPPS_PER_PAGE . ' OFFSET ' . (($page - 1) * P_OPPS_PER_PAGE));
    $authors = user_cards(array_map('intval', array_column($list, 'uid')));
    $url = site_url() . 'opportunities/' . ($page > 1 ? '?page=' . $page : '');
    $body = '<section class="pp-card"><p class="pp-kicker">Studioma Community</p><h1>Open opportunities for community managers and content creators</h1>'
        . '<p class="pp-lead">Members of Studioma publish work they need help with — social media content, video, design, copywriting, strategy. Send a proposal with your approach, price and timeline.</p>'
        . '<p class="pp-muted">' . e(number_format($total)) . ' open opportunit' . ($total === 1 ? 'y' : 'ies') . '. <a href="' . e(site_url()) . 'app/#/signup">Join Studioma</a> to send a proposal or publish your own.</p></section>';
    if (!$list) {
        $body .= '<section class="pp-card pp-center"><h2>No open opportunity right now</h2><p class="pp-muted">New opportunities appear here as soon as members publish them.</p></section>';
    } else {
        $body .= '<ul class="pp-opps">';
        foreach ($list as $p) {
            $a = $authors[(int) $p['uid']] ?? null;
            $body .= '<li class="pp-card pp-opp"><p class="pp-kicker">' . p_icon('briefcase') . e(OPP_KINDS[$p['opp_kind']] ?? 'Opportunity') . ($p['category'] !== '' ? ' · ' . e($p['category']) : '') . '</p>'
                . '<h2><a href="' . e(pub_public_url($p)) . '">' . e($p['title']) . '</a></h2>'
                . ($p['body'] !== '' ? '<p class="pp-excerpt">' . e(mb_strimwidth((string) $p['body'], 0, 220, '…')) . '</p>' : '')
                . p_opp_facts($p)
                . '<p class="pp-muted pp-small">' . ($a ? 'Published by ' . e($a['name']) . ' · ' : '') . e(p_date((int) $p['created'])) . '</p></li>';
        }
        $body .= '</ul>';
    }
    if ($pages > 1) {
        $body .= '<nav class="pp-pager" aria-label="Pages">'
            . ($page > 1 ? '<a class="pp-btn pp-btn-soft" rel="prev" href="' . e(site_url() . 'opportunities/' . ($page > 2 ? '?page=' . ($page - 1) : '')) . '">Newer</a>' : '')
            . '<span class="pp-muted">Page ' . $page . ' of ' . $pages . '</span>'
            . ($page < $pages ? '<a class="pp-btn pp-btn-soft" rel="next" href="' . e(site_url() . 'opportunities/?page=' . ($page + 1)) . '">Older</a>' : '') . '</nav>';
    }
    p_page('Open opportunities for community managers and creators' . ($page > 1 ? ' — page ' . $page : '') . ' | Studioma',
        'Browse open opportunities published by Studioma members: social media content, video, design, copywriting and strategy work. Send a proposal with your approach, price and timeline.',
        $url, $body, $total > 0, 200, ['type' => 'website', 'journey' => false,
            'jsonld' => [p_crumbs([['Studioma', site_url()], ['Opportunities', site_url() . 'opportunities/']])]]);
}
function p_opp(int $id, string $slug): void
{
    $p = p_opp_row($id);
    if (!$p) p_missing('This opportunity');
    $url = pub_public_url($p);
    // Une seule adresse par opportunité : un titre modifié ou mal recopié renvoie vers l'adresse actuelle.
    $expected = substr($url, strlen(site_url() . 'opportunity/' . $id));
    if ($slug !== $expected) {
        header('Location: ' . $url, true, 301);
        exit;
    }
    $a = user_cards([(int) $p['uid']])[(int) $p['uid']] ?? null;
    $open = $p['status'] === 'open';
    $media = files_view(pub_tokens($p));
    $cover = null;
    foreach ($media as $m) if (($m['kind'] ?? '') === 'image') { $cover = $m; break; }
    $img = $cover ? site_url() . 'api.php?action=file&t=' . rawurlencode((string) $cover['token']) : p_og_image();
    $skills = jarr($p['skills']);
    $enc = 'rawurlencode';
    $share = [['LinkedIn', 'https://www.linkedin.com/sharing/share-offsite/?url=' . $enc($url)], ['Facebook', 'https://www.facebook.com/sharer/sharer.php?u=' . $enc($url)],
        ['WhatsApp', 'https://wa.me/?text=' . $enc($p['title'] . ' ' . $url)], ['Email', 'mailto:?subject=' . $enc($p['title'] . ' — Studioma') . '&body=' . $enc($url)]];
    $body = '<nav class="pp-crumbs" aria-label="Breadcrumb"><a href="' . e(site_url()) . '">Studioma</a> › <a href="' . e(site_url()) . 'opportunities/">Opportunities</a></nav>'
        . '<article class="pp-card pp-opp-full">'
        . '<p class="pp-kicker">' . p_icon('briefcase') . e(OPP_KINDS[$p['opp_kind']] ?? 'Opportunity') . ($p['category'] !== '' ? ' · ' . e($p['category']) : '') . '</p>'
        . '<h1>' . e($p['title']) . '</h1>'
        . '<div class="pp-status pp-status-' . ($open ? 'ok' : 'self') . '"><b>' . ($open ? 'Open' : 'Closed') . '</b><span>' . ($open ? 'Taking proposals' . ($p['apply_until'] !== '' ? ' until ' . e(p_day((string) $p['apply_until'])) : '') : e(PUB_CLOSED[$p['closed_reason']] ?? PUB_CLOSED['manual'])) . '</span></div>'
        . ($a ? '<p class="pp-author">' . p_avatar($a) . '<span><b>' . e($a['name']) . '</b>' . ($a['title'] !== '' ? '<small class="pp-muted">' . e($a['title']) . '</small>' : '') . '</span></p>' : '')
        . '<dl class="pp-facts">'
        . (($b = p_budget($p)) ? '<div><dt>Budget</dt><dd>' . e($b) . '</dd></div>' : '')
        . ($p['deadline'] !== '' ? '<div><dt>Delivery</dt><dd>' . e(p_day((string) $p['deadline'])) . '</dd></div>' : '')
        . ($p['apply_until'] !== '' ? '<div><dt>Apply by</dt><dd>' . e(p_day((string) $p['apply_until'])) . '</dd></div>' : '')
        . '<div><dt>Work mode</dt><dd>' . e((STM_WORK_MODES[$p['mode']] ?? 'Remote') . ($p['location'] !== '' ? ' · ' . $p['location'] : '')) . '</dd></div>'
        . '<div><dt>Published</dt><dd><time datetime="' . e(date('c', (int) $p['created'])) . '">' . e(p_date((int) $p['created'])) . '</time></dd></div>'
        . '</dl>'
        . ($cover ? '<img class="pp-cover" src="' . e($img) . '" alt="' . e($p['title']) . '" loading="lazy">' : '')
        . ($p['body'] !== '' ? '<h2>Description</h2><p>' . p_text((string) $p['body']) . '</p>' : '')
        . ($p['deliverables'] !== '' ? '<h2>Expected deliverables</h2><p>' . p_text((string) $p['deliverables']) . '</p>' : '')
        . ($skills ? '<h2>Skills</h2><p class="pp-tags">' . implode('', array_map(function ($k): string { return '<span>' . e($k) . '</span>'; }, $skills)) . '</p>' : '')
        . '<div class="pp-actions">' . ($open ? '<a class="pp-btn" href="' . e(site_url() . 'app/#/pub/' . $id) . '">Send a proposal</a>' : '<a class="pp-btn" href="' . e(site_url()) . 'opportunities/">See open opportunities</a>')
        . '<span class="pp-share">Share: ' . implode(' · ', array_map(function (array $s): string { return '<a href="' . e($s[1]) . '" target="_blank" rel="noopener noreferrer">' . e($s[0]) . '</a>'; }, $share)) . '</span></div>'
        . '<p class="pp-muted pp-small">Proposals are sent from a Studioma account. The Studio and the card behind this opportunity stay private.</p>'
        . '</article>';
    $desc = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', (string) $p['body']) ?? '') ?: (OPP_KINDS[$p['opp_kind']] ?? 'Opportunity') . ' on Studioma', 0, 158, '…');
    p_page($p['title'] . ' — ' . ($open ? 'open opportunity' : 'closed opportunity') . ' | Studioma', $desc, $url, $body, $open, 200, [
        'type' => 'article', 'image' => $img, 'journey' => false,
        'jsonld' => [p_crumbs([['Studioma', site_url()], ['Opportunities', site_url() . 'opportunities/'], [(string) $p['title'], $url]])],
    ]);
}

/* -------------------------------------------------------------------- prix */

function p_pricing(): void
{
    $promo = function_exists('promo_enabled') && promo_enabled();
    $checkout = function_exists('billing_available') && billing_available();
    $money = function (int $cents): string { return '$' . rtrim(rtrim(number_format($cents / 100, 2, '.', ','), '0'), '.'); };
    $cards = '';
    foreach (STM_PLANS as $key => $pl) {
        $m = (int) $pl['price']['month'];
        $y = (int) $pl['price']['year'];
        $cta = $key === 'free' ? ['Get Started Free', 'app/#/signup']
            : ($promo ? ['Get 30 Days Free', 'app/#/signup'] : ($checkout ? ['Choose ' . $pl['name'], 'app/#/pricing'] : ['Start with Free', 'app/#/signup']));
        $cards .= '<article class="pp-card pp-plan' . ($key === 'solo' ? ' is-popular' : '') . '">'
            . ($key === 'solo' ? '<p class="pp-badge-txt">Most Popular</p>' : '')
            . '<h2>' . e($pl['name']) . '</h2><p class="pp-muted">' . e($pl['tagline']) . '</p>'
            . '<p class="pp-price"><b>' . e($money($m)) . '</b><span>/month</span></p>'
            . '<p class="pp-muted pp-small">' . ($key === 'free' ? 'Free forever — no card needed' : 'or ' . e($money($y)) . '/year — 2 months free') . '</p>'
            . '<a class="pp-btn' . ($key === 'solo' ? '' : ' pp-btn-soft') . '" href="' . e(site_url() . $cta[1]) . '">' . e($cta[0]) . '</a>'
            . ($key !== 'free' && $promo ? '<p class="pp-muted pp-small">' . p_icon('check') . 'No credit card required</p>' : '')
            . '<ul class="pp-checks">' . implode('', array_map(function (string $b): string { return '<li>' . p_icon('check') . e($b) . '</li>'; }, STM_PLAN_BULLETS[$key] ?? [])) . '</ul></article>';
    }
    $note = $promo ? '<p class="pp-note"><b>Special launch offer:</b> 30 days of full access to every Studioma feature — completely free, no credit card required.'
            . ($checkout ? '' : ' Paid subscriptions aren\'t available yet: the Solo and Team prices are planned prices. Nothing is ever charged, and you return to Free when the offer ends without losing anything.') . '</p>'
        : ($checkout ? '' : '<p class="pp-note">Online payment for Solo and Team opens soon. Start free today — everything you create stays when you upgrade.</p>');
    $faq = [
        ['What happens to my work if I change plans?', 'Everything stays: projects, Studios, cards and history. Above a plan\'s limits you can still open everything; you just can\'t create more until you archive or delete some, or upgrade.'],
        ['Do my clients and guests need to pay?', 'No. Guests open your Studio with a share link, without an account, and never count as members.'],
        ['What counts as a card on the Free plan?', 'Each new card created in your projects during the calendar month. The allowance renews on the 1st and existing cards are never deleted. Paid plans have unlimited cards.'],
    ];
    $body = '<section class="pp-card pp-center"><p class="pp-kicker">Studioma pricing</p><h1>Simple pricing for community managers, freelancers and agencies</h1>'
        . '<p class="pp-lead">Plan content, collaborate with clients and get approvals in one place. Start free, then grow with Solo or Team.</p>' . $note . '</section>'
        . '<div class="pp-plans">' . $cards . '</div>'
        . '<section class="pp-card"><h2>Frequently asked questions</h2>' . implode('', array_map(function (array $q): string { return '<h3>' . e($q[0]) . '</h3><p>' . e($q[1]) . '</p>'; }, $faq))
        . '<p class="pp-muted pp-small">Prices in US dollars. See every detail and compare plans <a href="' . e(site_url()) . 'app/#/pricing">in the app</a>.</p></section>';
    p_page('Pricing — Free, Solo and Team plans | Studioma', 'Studioma pricing: a free plan, Solo for freelancers managing several clients and Team for agencies — content planning, client approvals and collaboration.' . ($promo ? ' 30 days free, no credit card required.' : ''),
        site_url() . 'pricing/', $body, true, 200, ['type' => 'website', 'journey' => false,
            'jsonld' => [p_crumbs([['Studioma', site_url()], ['Pricing', site_url() . 'pricing/']])]]);
}

/* ------------------------------------------------------------ plan du site */

/** Plan du site : pages publiques indexables seulement (jamais l'application, un Studio, un lien de partage). */
function p_sitemap(): void
{
    $base = site_url();
    $urls = [[$base, (int) filemtime(__DIR__ . '/index.html')], [$base . 'pricing/', (int) filemtime(__DIR__ . '/lib/plans.php')]];
    $open = rows("SELECT p.* FROM pubs p WHERE p.kind = 'opportunity' AND p.status = 'open' AND p.uid NOT IN (SELECT id FROM users WHERE suspended > 0) ORDER BY p.id DESC LIMIT 5000");
    if ($open) $urls[] = [$base . 'opportunities/', max(array_map('intval', array_column($open, 'updated')))];
    foreach ($open as $p) $urls[] = [pub_public_url($p), (int) $p['updated']];
    foreach (rows('SELECT u.handle, s.updated FROM passport_settings s JOIN users u ON u.id = s.uid WHERE s.enabled = 1 AND u.suspended = 0 ORDER BY s.uid LIMIT 5000') as $r) {
        $urls[] = [passport_url((string) $r['handle']), (int) $r['updated']];
    }
    foreach (rows("SELECT a.public_id, a.updated FROM user_achievements a JOIN users u ON u.id = a.uid JOIN passport_settings s ON s.uid = a.uid
        WHERE a.status = 'verified' AND a.visibility = 'passport' AND s.enabled = 1 AND u.suspended = 0 ORDER BY a.id LIMIT 5000") as $r) {
        $urls[] = [journey_url((string) $r['public_id']), (int) $r['updated']];
    }
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($urls as [$loc, $t]) echo '  <url><loc>' . e($loc) . '</loc>' . ($t > 0 ? '<lastmod>' . date('Y-m-d', $t) . '</lastmod>' : '') . "</url>\n";
    echo "</urlset>\n";
    exit;
}

try {
    db();
    $verify = (string) ($_GET['verify'] ?? '');
    $creator = (string) ($_GET['creator'] ?? '');
    if ($verify !== '' && preg_match('/^[a-f0-9]{16}$/', $verify)) p_verify($verify);
    if ($creator !== '' && preg_match('/^[a-z0-9-]{1,40}$/', $creator)) p_passport($creator);
    if (!empty($_GET['opps'])) p_opps(isset($_GET['page']) && ctype_digit((string) $_GET['page']) ? (int) $_GET['page'] : 1);
    $opp = (string) ($_GET['opp'] ?? '');
    // Partie lisible de l'adresse (« -titre-de-l-opportunite ») : mal formée, elle est remplacée par la bonne (301).
    $slug = (string) ($_GET['slug'] ?? '');
    if ($opp !== '' && ctype_digit($opp)) p_opp((int) $opp, preg_match('/^(-[a-z0-9-]{0,80})?$/', $slug) ? $slug : '!');
    if (!empty($_GET['pricing'])) p_pricing();
    if (!empty($_GET['sitemap'])) p_sitemap();
    p_missing('This page');
} catch (Throwable $ex) {
    error_log('studioma p.php: ' . $ex->getMessage());
    http_response_code(500);
    echo 'The service is temporarily unavailable.';
}
