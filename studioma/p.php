<?php
/**
 * Pages publiques de Studioma rendues par le serveur (moteurs de recherche, aperçus des liens, lecture sans JavaScript) :
 *   https://dehini.com/studioma/verify/<identifiant>  — vérification d'une réalisation (réécrit en p.php?verify=…)
 *   https://dehini.com/studioma/creator/<identifiant> — passeport public du créateur (réécrit en p.php?creator=…)
 *   https://dehini.com/studioma/opportunities/        — opportunités ouvertes publiées par les membres (p.php?opps=1)
 *   https://dehini.com/studioma/opportunity/<n°>-<titre> — une opportunité (p.php?opp=…)
 *   https://dehini.com/studioma/pricing/               — formules et prix, tirés de lib/plans.php (p.php?pricing=1)
 *   https://dehini.com/studioma/terms/, /privacy/, /refunds/ — conditions, confidentialité, remboursements (p.php?legal=…)
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
        . '<p><a href="' . e(site_url()) . 'terms/">Terms of Service</a> · <a href="' . e(site_url()) . 'privacy/">Privacy Policy</a> · <a href="' . e(site_url()) . 'refunds/">Refund Policy</a> · ' . e((string) STM_LEGAL_EMAIL) . '</p>'
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
        . '<p class="pp-muted pp-small">Prices in US dollars. See every detail and compare plans <a href="' . e(site_url()) . 'app/#/pricing">in the app</a>. Paid plans: ' . (int) STM_REFUND_DAYS . '-day money-back guarantee — see the <a href="' . e(site_url()) . 'refunds/">Refund Policy</a> and the <a href="' . e(site_url()) . 'terms/">Terms of Service</a>.</p></section>';
    p_page('Pricing — Free, Solo and Team plans | Studioma', 'Studioma pricing: a free plan, Solo for freelancers managing several clients and Team for agencies — content planning, client approvals and collaboration.' . ($promo ? ' 30 days free, no credit card required.' : ''),
        site_url() . 'pricing/', $body, true, 200, ['type' => 'website', 'journey' => false,
            'jsonld' => [p_crumbs([['Studioma', site_url()], ['Pricing', site_url() . 'pricing/']])]]);
}

/* ------------------------------------------------------------ plan du site */

/** Plan du site : pages publiques indexables seulement (jamais l'application, un Studio, un lien de partage). */
function p_sitemap(): void
{
    $base = site_url();
    $legal = (int) strtotime((string) STM_LEGAL_UPDATED);
    $urls = [[$base, (int) filemtime(__DIR__ . '/index.html')], [$base . 'pricing/', (int) filemtime(__DIR__ . '/lib/plans.php')],
        [$base . 'terms/', $legal], [$base . 'privacy/', $legal], [$base . 'refunds/', $legal]];
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

/* ------------------------------------------------------------ pages légales */

/**
 * Conditions d'utilisation, politique de confidentialité, politique de remboursement. Les faits décrits (cookies, durées,
 * données envoyées à Meta, Google, Paddle) sont ceux du code : à mettre à jour avec lui (README « Studioma › 13 »).
 * Exploitant, contact, pays, âge minimum et garantie : réglages STM_LEGAL_* (lib/core.php), choisis par le propriétaire.
 */
function p_legal_vars(): array
{
    return [
        'name' => (string) STM_LEGAL_NAME, 'email' => (string) STM_LEGAL_EMAIL, 'country' => (string) STM_LEGAL_COUNTRY,
        'updated' => p_day((string) STM_LEGAL_UPDATED), 'age' => (int) STM_MIN_AGE, 'days' => (int) STM_REFUND_DAYS, 'site' => site_url(),
    ];
}
/** Remplace {name}, {email}… par les valeurs (échappées) ; le texte lui-même est écrit ici, jamais saisi par un membre. */
function p_legal_fill(string $html, array $v): string
{
    $mail = '<a href="mailto:' . e($v['email']) . '">' . e($v['email']) . '</a>';
    return strtr($html, ['{name}' => e($v['name']), '{email}' => $mail, '{country}' => e($v['country']), '{age}' => (string) $v['age'],
        '{days}' => (string) $v['days'], '{site}' => e($v['site']), '{terms}' => e($v['site'] . 'terms/'), '{privacy}' => e($v['site'] . 'privacy/'),
        '{refunds}' => e($v['site'] . 'refunds/'), '{pricing}' => e($v['site'] . 'pricing/')]);
}
/** Gabarit d'une page légale : titre, date, sommaire, sections [id, titre, html]. */
function p_legal_page(string $slug, string $title, string $desc, string $intro, array $sections): void
{
    $v = p_legal_vars();
    $toc = '';
    $body = '';
    foreach ($sections as $i => [$id, $h, $html]) {
        $h = str_replace('{days}', (string) $v['days'], $h);
        $toc .= '<li><a href="#' . $id . '">' . e($h) . '</a></li>';
        $body .= '<section id="' . $id . '"><h2>' . ($i + 1) . '. ' . e($h) . '</h2>' . p_legal_fill($html, $v) . '</section>';
    }
    $nav = '';
    foreach ([['terms', 'Terms of Service'], ['privacy', 'Privacy Policy'], ['refunds', 'Refund Policy']] as [$s, $t]) {
        $nav .= $s === $slug ? '<span aria-current="page">' . $t . '</span>' : '<a href="' . e(site_url() . $s . '/') . '">' . $t . '</a>';
    }
    $html = '<article class="pp-card pp-legal">'
        . '<p class="pp-kicker">Studioma — legal</p><h1>' . e($title) . '</h1>'
        . '<p class="pp-muted">Last updated: ' . e($v['updated']) . ' · Operated by ' . e($v['name']) . ' · Contact: ' . p_legal_fill('{email}', $v) . '</p>'
        . '<nav class="pp-legal-nav" aria-label="Legal documents">' . $nav . '</nav>'
        . '<div class="pp-lead">' . p_legal_fill($intro, $v) . '</div>'
        . '<nav class="pp-toc" aria-label="Contents"><h2>Contents</h2><ol>' . $toc . '</ol></nav>'
        . $body . '</article>';
    p_page($title . ' | Studioma', $desc, site_url() . $slug . '/', $html, true, 200, ['type' => 'website', 'journey' => false,
        'jsonld' => [p_crumbs([['Studioma', site_url()], [$title, site_url() . $slug . '/']])]]);
}

function p_terms(): void
{
    p_legal_page('terms', 'Terms of Service', 'The terms that apply when you use Studioma: accounts, content, collaboration, community, plans, payments through Paddle and responsibilities.',
        '<p>These Terms of Service (the “Terms”) govern your use of Studioma, available at <a href="{site}">{site}</a> (the “Service”). Please read them together with our <a href="{privacy}">Privacy Policy</a> and our <a href="{refunds}">Refund Policy</a>.</p>', [
        ['who', 'Who we are', '<p>Studioma is operated by {name}, established in {country} (“we”, “us”). You can reach us at {email}.</p>'],
        ['accept', 'Accepting these Terms', '<p>By creating an account, or by using the Service as a guest of a share link, you agree to these Terms. If you use Studioma on behalf of a company or another organization, you confirm that you are allowed to accept these Terms for it, and “you” also means that organization.</p>'],
        ['eligibility', 'Who can use Studioma', '<p>You must be at least {age} years old to create an account. To buy a paid plan you must be legally able to enter into a contract in your country, or have the permission of a parent or legal guardian.</p>'],
        ['account', 'Your account', '<p>Give accurate information, keep your password secret and tell us at {email} if you think someone else is using your account. An account belongs to one person; you are responsible for what happens under it. You can sign in with an email and password or with Google; when you use Google, Google’s own terms also apply to that sign-in.</p>'],
        ['service', 'The Service', '<p>Studioma lets you plan and produce content in private projects and Studios (levels, columns, cards, comments, files, approvals), invite members with roles, share a Studio or a single card with guests through links, keep a professional profile and portfolio, publish finished work as achievements and unfinished work as opportunities, receive and send proposals, follow a Creator Journey (experience points, levels and achievements) and connect AI assistants through Studioma’s MCP server.</p><p>We improve the Service continuously and may add, change or remove features. We aim to keep it available and secure but do not guarantee that it will be uninterrupted or error-free. We will give reasonable notice before removing a paid feature you rely on.</p>'],
        ['content', 'Your content', '<p>You keep all rights to the content you put on Studioma (texts, scripts, files, comments, profile, posts, publications). You give us a worldwide, non-exclusive, royalty-free licence to host, store, copy, display and transmit that content only as needed to run the Service for you and for the people you share it with, including showing publicly what you choose to make public. The licence ends when the content is deleted, except for copies that others have legitimately received (for example a comment in someone else’s project) and backups kept for a limited time.</p><p>You are responsible for your content and must have the rights and permissions it needs — in particular the permission of your clients before you publish work done for them. Private projects and Studios stay private: nothing in them becomes public unless you, or someone with the right role, publishes it.</p>'],
        ['workspace', 'Projects, roles and guests', '<p>The owner of a project decides who can access it and with which role (Owner, Manager, Creator, Collaborator, Client / Approver, Viewer). Share links give guests without an account access to one Studio or one card, within the rights chosen by the person who shared it; the owner is responsible for the links and invitations they create and can revoke them at any time. An approval recorded in Studioma records the decision of the person who made it; it is not a guarantee of quality given by Studioma.</p>'],
        ['community', 'Community, opportunities and proposals', '<p>Opportunities and proposals put members in contact. Any agreement, price, payment or deliverable is a matter between the members concerned: Studioma is not a party to it, does not employ anyone, does not hold or transfer money between members and does not guarantee the identity, quality or payment of anyone. Prices shown in opportunities and proposals are indicative. Be careful and use your own judgement.</p>'],
        ['journey', 'Creator Journey', '<p>Experience points, levels and achievements are internal indicators of activity recorded in Studioma. They are not certifications or accredited qualifications. Self-reported achievements are shown as such. We may correct or revoke points or achievements obtained in breach of these Terms (for example through fake accounts, fake approvals or automated activity); the public verification page of an achievement always shows its current status.</p>'],
        ['use', 'Acceptable use', '<p>Use Studioma respectfully and lawfully. In particular, do not:</p><ul><li>publish illegal, hateful, harassing, sexually explicit or violent content, or content that infringes someone else’s rights (copyright, trademarks, privacy, image);</li><li>share confidential client material without permission;</li><li>send spam, unsolicited advertising or misleading opportunities;</li><li>create fake accounts or use several accounts to obtain referral rewards, trials, approvals or achievements;</li><li>impersonate a person, brand or company;</li><li>try to access data or features you are not allowed to, test or bypass security or plan limits, or disrupt the Service;</li><li>scrape or copy the Service or its content by automated means, except through features meant for it (such as the MCP server, within your own account);</li><li>upload malware or harmful code.</li></ul><p>We may remove content, limit features or suspend accounts that break these rules (see “Suspension and termination”).</p>'],
        ['plans', 'Plans, trial and referral offer', '<p>Studioma has a free plan and paid plans (Solo and Team) described on the <a href="{pricing}">pricing page</a>, with their limits. Paid plans are subscriptions sold by our reseller <b>Paddle</b> (Paddle.com Market Limited and its affiliates), which acts as the merchant of record: Paddle processes the payment, charges applicable taxes, issues invoices and its own buyer terms apply to the purchase. Your card details go to Paddle, never to Studioma. Prices are in US dollars unless shown otherwise; taxes may be added depending on your country.</p><p>Subscriptions renew automatically at the end of each monthly or yearly period until you cancel. You can cancel at any time in Settings › Plan & billing: your plan stays active until the end of the period already paid, then your account moves to the free plan. Moving to a lower plan never deletes your content; above a plan’s limits you can still open everything but cannot create more until you are within the limits again.</p><p>Launch offer: while it runs, new accounts get a free trial of every feature and members who invite verified friends can unlock a free period, as described in the app. These offers require no payment and no card, never turn into a paid subscription by themselves, are granted once per account, have no cash value and may end at any time for new participants. Access already granted continues until its end date. Rewards obtained through fake or duplicate accounts can be withdrawn.</p>'],
        ['refunds', 'Refunds', '<p>Refunds are described in our <a href="{refunds}">Refund Policy</a> (including a {days}-day money-back guarantee on the first payment of a subscription).</p>'],
        ['termination', 'Suspension and termination', '<p>You can stop using Studioma and delete your account at any time in Settings (if you are the only owner of a project shared with others, transfer or delete it first). We may suspend or close an account, or remove content, if it breaks these Terms or the law, puts other users or the Service at risk, or if we are required to by law. Where reasonable we will tell you why and give you a chance to respond. If we close a paid account without a breach on your part, we will refund the unused part of the current period.</p>'],
        ['third', 'Third-party services', '<p>Some features use services run by others: Google (sign-in), Paddle (payments), Meta (measurement of visits and sign-ups) and the AI assistants you choose to connect. Their own terms and privacy policies apply to what they do. We are not responsible for services we do not control.</p>'],
        ['liability', 'Disclaimers and liability', '<p>The Service is provided “as is”. To the extent permitted by law, we disclaim implied warranties (for example of fitness for a particular purpose) and we are not liable for indirect or consequential losses, loss of profits, loss of opportunities or loss of data caused by events outside our reasonable control. Keep your own copies of important files. To the extent permitted by law, our total liability for any claim relating to the Service is limited to the amounts you paid for it in the 12 months before the claim, or 100 US dollars if you paid nothing.</p><p>Nothing in these Terms excludes or limits liability that cannot be excluded or limited by law, nor the rights you have as a consumer under the mandatory law of the country where you live.</p>'],
        ['changes', 'Changes to these Terms', '<p>We may update these Terms, for example when features or the law change. The date at the top shows the latest version. For important changes we will inform members by email or in the app before they apply. If you do not agree with the new Terms, you can stop using Studioma and delete your account; continuing to use it after the changes apply means you accept them.</p>'],
        ['law', 'Governing law and disputes', '<p>These Terms are governed by the laws of {country}. Before going to court, please contact us at {email}: most issues can be solved quickly. Any dispute that cannot be solved amicably falls under the jurisdiction of the competent courts of {country}, without depriving consumers of the protection of the mandatory rules and courts of the country where they live.</p>'],
        ['contact', 'Contact', '<p>Questions about these Terms: {email}.</p>'],
    ]);
}

function p_privacy(): void
{
    p_legal_page('privacy', 'Privacy Policy', 'How Studioma collects, uses, shares and protects personal data, which cookies it uses, how long data is kept and how to exercise your rights.',
        '<p>This policy explains what personal data Studioma collects, why, who receives it and what choices you have. It applies to the Studioma website, app and public pages at <a href="{site}">{site}</a>.</p>', [
        ['controller', 'Who is responsible', '<p>The data controller is {name}, established in {country}, who operates Studioma. Contact for any privacy question or request: {email}.</p>'],
        ['collect', 'What we collect', '<ul>'
            . '<li><b>Account data</b> — your name, email address, password (stored only as a secure hash), account type, whether your email is confirmed and, if you sign in with Google, your Google account identifier.</li>'
            . '<li><b>Profile and public content</b> — what you add to your profile (title, bio, skills, location, photo, cover, links, portfolio), your posts, comments, likes, recommendations, and the achievements and opportunities you publish.</li>'
            . '<li><b>Workspace content</b> — projects, Studios, cards, scripts, comments, files, approvals, invitations and the history of changes, visible only to the people who have access to them.</li>'
            . '<li><b>Messages and proposals</b> — private messages and the proposals you send or receive.</li>'
            . '<li><b>Guests of share links</b> — the email address a guest verifies with a one-time code, and what the guest does in the shared Studio or card.</li>'
            . '<li><b>Plan and payment data</b> — your plan, its status and period, and identifiers and amounts received from Paddle. We never receive or store card numbers.</li>'
            . '<li><b>Referral data</b> — your referral code, clicks on your link counted with a random browser identifier, the accounts created through it and, at sign-up, a salted hash of the IP address used only to detect abuse.</li>'
            . '<li><b>Creator Journey</b> — experience points, achievements, levels and your Creator Passport settings, computed from your activity.</li>'
            . '<li><b>Technical and usage data</b> — a session cookie, visit statistics (signed-in members by account, other visitors with an identifier that changes every day and is derived from a salted hash; no IP address is stored for statistics), salted hashes of IP addresses for rate limiting, the list of emails sent to you (recipient, subject, date) and security logs. Our hosting provider keeps standard server logs (including IP addresses) for a limited time.</li>'
            . '<li><b>AI assistants</b> — when you connect an assistant, the name of the application and its access tokens (stored only as hashes).</li>'
            . '</ul>'],
        ['use', 'Why we use it (purposes and legal bases)', '<ul>'
            . '<li>To provide the Service you asked for: your account, workspaces, sharing, community, messages, Creator Journey and AI connections — <i>performance of our contract with you</i>.</li>'
            . '<li>To keep Studioma safe: preventing spam, fraud, abuse of offers and attacks, enforcing our Terms — <i>our legitimate interests</i> and <i>legal obligations</i>.</li>'
            . '<li>To manage plans and payments with Paddle and keep accounting records — <i>contract</i> and <i>legal obligations</i>.</li>'
            . '<li>To send service emails (confirmation, invitations, notifications you can adjust in Settings) — <i>contract</i>; and occasional news about Studioma, which you can stop at any time with the link in each email or in Settings — <i>legitimate interests or consent where the law requires it</i>.</li>'
            . '<li>To measure visits and sign-ups and the performance of our advertising with Meta (see below) — <i>our legitimate interests or your consent where the law requires it</i>.</li>'
            . '<li>To improve Studioma using aggregated statistics — <i>our legitimate interests</i>.</li>'
            . '</ul><p>We do not sell your personal data and we do not use your private workspace content for advertising.</p>'],
        ['public', 'What is public', '<p>Your profile, posts, published achievements and opportunities, portfolio case studies you publish and, if you turn them on, your Creator Passport and the achievements you choose to show can be seen by anyone, including people without an account, and can be indexed by search engines. Private projects, Studios, cards, files and messages are never public unless you or someone with the right role publishes something from them. Achievements are private by default; a client’s name is shown on a public achievement only if that client agrees.</p>'],
        ['share', 'Who receives your data', '<ul>'
            . '<li><b>Other people you work with</b> — members and guests of your projects, Studios and cards see what their role allows; people you message see your messages.</li>'
            . '<li><b>Our hosting provider</b> — stores the database and files on its servers on our behalf.</li>'
            . '<li><b>Email delivery</b> — emails are sent through our mail server.</li>'
            . '<li><b>Paddle</b> — our reseller and merchant of record for paid plans. Paddle collects the payment and billing details it needs and acts as an independent controller for them (see Paddle’s privacy notice). We share with Paddle your account email and identifiers needed to link the subscription to your account.</li>'
            . '<li><b>Google</b> — only if you use “Continue with Google”: Google confirms your identity and email to us; the sign-in button is loaded from Google on the sign-in pages.</li>'
            . '<li><b>Meta Platforms</b> — the Meta Pixel and Conversions API measure page views and key actions (such as sign-ups). For signed-in members we send your email address and account identifier only as SHA-256 hashes; for everyone we send the IP address, browser information and Meta cookies (_fbp, _fbc). We never send the content of your projects, cards, messages, searches or share links.</li>'
            . '<li><b>AI assistants you connect</b> — they access the data you authorize through Studioma’s MCP server, under their own terms.</li>'
            . '<li><b>Authorities</b> — when the law requires it, or to protect the rights and safety of users and of Studioma.</li>'
            . '</ul>'],
        ['cookies', 'Cookies and similar technologies', '<p>Studioma uses its own cookies for essential functions and a few measurement cookies:</p>'
            . '<div class="pp-table-wrap" role="region" aria-label="Cookies used by Studioma" tabindex="0"><table class="pp-table"><thead><tr><th>Name</th><th>Purpose</th><th>Duration</th></tr></thead><tbody>'
            . '<tr><td><code>stm_sid</code></td><td>Keeps you signed in (essential)</td><td>30 days, renewed while you use Studioma</td></tr>'
            . '<tr><td><code>stm_g…</code></td><td>Keeps a verified guest signed in to one share link (essential)</td><td>Until the guest session expires or the link is revoked</td></tr>'
            . '<tr><td><code>stm_vid</code></td><td>Random browser identifier to count unique visitors of referral links</td><td>1 year</td></tr>'
            . '<tr><td><code>stm_ref</code></td><td>Remembers which referral link brought you (signed)</td><td>30 days</td></tr>'
            . '<tr><td><code>_fbp</code>, <code>_fbc</code></td><td>Meta measurement and advertising</td><td>Up to 90 days</td></tr>'
            . '</tbody></table></div>'
            . '<p>Google sets its own cookies when you use “Continue with Google”, and Paddle when you open the payment page. The app also stores small preferences in your browser (such as the theme). You can delete or block cookies in your browser settings; blocking essential cookies prevents signing in. You can limit Meta’s use of your data in your browser (tracking protection, extensions) and in your Meta ad settings.</p>'],
        ['transfers', 'International transfers', '<p>Your data is stored by our hosting provider. Meta, Google and Paddle may process data in other countries, including the United States, the European Union and the United Kingdom, under their own safeguards and terms. When data leaves your country, we rely on the protections these providers offer.</p>'],
        ['retention', 'How long we keep data', '<ul>'
            . '<li>Account, profile and content: while your account exists. When you delete your account, your account, profile, private projects you alone belong to, posts, messages and files are deleted; contributions you made in other people’s projects (such as cards or comments) can remain there without your name.</li>'
            . '<li>Sessions: up to 30 days. List of emails sent: 180 days. Administration audit log: about 400 days.</li>'
            . '<li>Referral records and Creator Journey records: while the accounts concerned exist.</li>'
            . '<li>Payment records: as long as accounting and tax rules require (Paddle keeps its own records as merchant of record).</li>'
            . '<li>Backups, if any, are kept for a limited time and then overwritten.</li>'
            . '</ul>'],
        ['rights', 'Your rights', '<p>You can access, correct, download (in a common format, on request) or delete your personal data, object to some uses, and withdraw any consent you gave. Many things can be done directly: edit your profile, change your email settings, turn your Creator Passport off, make achievements private, unpublish publications, or delete your account in Settings. For anything else, write to {email} from the address of your account; we answer within one month. You can also complain to your data protection authority — in {country}, the national authority for the protection of personal data (ANPDP); in the European Union, the authority of your country.</p>'],
        ['children', 'Children', '<p>Studioma is not intended for anyone under {age}. If you think a younger person has created an account, write to {email} and we will delete it.</p>'],
        ['security', 'Security', '<p>Passwords are stored as hashes, connections use HTTPS, access tokens and share links are stored as hashes, private files are kept outside the public website and served only to people with access, and permissions are checked on the server for every request. No system is perfectly secure: tell us at {email} if you find a vulnerability.</p>'],
        ['changes', 'Changes to this policy', '<p>We will update this policy when our practices change; the date at the top shows the latest version. We will inform members of important changes by email or in the app.</p>'],
        ['contact', 'Contact', '<p>{name} — {email}.</p>'],
    ]);
}

function p_refunds(): void
{
    p_legal_page('refunds', 'Refund Policy', 'Studioma refund policy: a ' . (int) STM_REFUND_DAYS . '-day money-back guarantee on the first payment of a Solo or Team subscription, cancellation at any time, payments handled by Paddle.',
        '<p>This policy applies to Studioma’s paid plans (Solo and Team). It is part of our <a href="{terms}">Terms of Service</a>.</p>', [
        ['free', 'Free plan and free offers', '<p>The free plan, the free trial of the launch offer and referral rewards cost nothing and require no card: there is nothing to refund, and they never turn into a paid subscription by themselves.</p>'],
        ['paddle', 'Who handles payments', '<p>Paid subscriptions are sold by our reseller <b>Paddle</b>, the merchant of record: Paddle charges your payment method, issues the invoice and processes refunds. Refunds go back to the payment method used for the purchase.</p>'],
        ['guarantee', '{days}-day money-back guarantee', '<p>If Studioma is not right for you, you can ask for a <b>full refund of the first payment of a subscription</b> (monthly or yearly) within <b>{days} days</b> of that payment. No justification is needed. The guarantee applies once per account.</p>'],
        ['renewals', 'Renewals and cancellation', '<p>Subscriptions renew automatically. You can cancel at any time in Settings › Plan & billing; your plan stays active until the end of the period already paid and is not renewed. Renewal payments are not refunded, except where the law requires it or in the cases below — so please cancel before the renewal date if you no longer need the plan.</p>'],
        ['changes', 'Upgrades and downgrades', '<p>When you change plan during a period, Paddle calculates the prorated amount automatically (you pay only the difference when upgrading).</p>'],
        ['errors', 'Billing errors', '<p>Duplicate charges, charges after a cancellation that was correctly made, and other billing errors are always refunded in full, whenever you report them.</p>'],
        ['how', 'How to ask for a refund', '<p>Write to {email} from the email address of your Studioma account, with the date of the payment (or the invoice number from your Paddle receipt). You can also contact Paddle directly through the link in your receipt email. We aim to confirm the refund within 5 business days; the money then usually reaches your account within 5 to 10 business days, depending on your bank.</p>'],
        ['after', 'What happens after a refund', '<p>The refunded subscription ends and your account moves to the free plan. Nothing is deleted: above the free plan’s limits, you can still open everything but cannot create more until you are within the limits or choose a plan again.</p>'],
        ['law', 'Your legal rights', '<p>This policy is part of our <a href="{terms}">Terms of Service</a> and is governed by the laws of {country}. It does not limit the rights you have under the consumer law of your country (for example a legal right of withdrawal). Where the law gives you more, the law applies.</p>'],
        ['contact', 'Contact', '<p>{name} — {email}.</p>'],
    ]);
}

try {
    // Pages légales : texte fixe, sans base de données (elles restent lisibles même si SQLite est indisponible).
    $legal = (string) ($_GET['legal'] ?? '');
    if ($legal === 'terms') p_terms();
    if ($legal === 'privacy') p_privacy();
    if ($legal === 'refunds') p_refunds();
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
