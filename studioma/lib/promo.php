<?php
/**
 * Offre de lancement de Studioma — sans carte, sans paiement, sans Paddle.
 *
 *   essai       30 jours d'accès complet (formule Team et ses limites) : accordé à l'inscription, ou réclamé une fois
 *               par un compte existant qui ne l'a jamais eu. Pas de renouvellement : à la fin, la formule Free
 *               s'applique (rien n'est supprimé), sauf autre accès en cours.
 *   parrainage  chaque compte a un lien personnel (…/studioma/?ref=<code>). Un compte créé par ce lien dans les 30
 *               jours, puis vérifié (adresse confirmée), compte pour son parrain ; à 10, le parrain reçoit 12 mois
 *               civils d'accès complet, une seule fois, à la suite de son essai s'il est encore en cours.
 *
 * Les accès sont des lignes de subscriptions (provider « promo », env « promo ») : account_plan() (lib/billing.php)
 * les lit comme les autres, donc toutes les limites et fonctionnalités s'appliquent au même endroit, côté serveur.
 * promo_grants garantit par une contrainte d'unicité un seul essai et une seule année par compte.
 * Règles et raisons : docs/DECISIONS.md S28–S30.
 */
declare(strict_types=1);

const REF_CODE_CHARS = 'abcdefghjkmnpqrstuvwxyz23456789';
const REF_COOKIE = 'stm_ref';
const VISITOR_COOKIE = 'stm_vid';
const REF_STATUSES = ['pending' => 'Email not verified yet', 'verified' => 'Verified — counts', 'review' => 'Under review', 'rejected' => 'Not eligible'];

function promo_enabled(): bool
{
    return billing_flag('LAUNCH_PROMO', true);
}

/* ---------------------------------------------------------------- accès */

/** Avantage de l'offre accordé à un compte (trial | referral), ou null. */
function promo_grant(int $uid, string $kind): ?array
{
    return row('SELECT * FROM promo_grants WHERE uid = ? AND promo = ? AND kind = ?', [$uid, STM_PROMO_ID, $kind]);
}
/** Le compte a-t-il en ce moment un accès Team qui ne vient pas de l'offre (payé ou offert par l'administration) ? */
function promo_has_other_team(int $uid): bool
{
    $p = account_plan($uid);
    return $p['plan'] === STM_PROMO_PLAN && $p['sub'] && $p['sub']['provider'] !== 'promo';
}
/** Crée l'accès (ligne de subscriptions) et l'avantage ; null si le compte l'a déjà eu (contrainte d'unicité). */
function promo_insert(int $uid, string $kind, string $source, int $starts, int $ends, string $note): ?array
{
    return tx(function () use ($uid, $kind, $source, $starts, $ends, $note): ?array {
        if (!q('INSERT OR IGNORE INTO promo_grants (uid, promo, kind, source, starts, ends, created) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$uid, STM_PROMO_ID, $kind, $source, $starts, $ends, time()])->rowCount()) return null;
        $gid = (int) db()->lastInsertId();
        $sid = insert('subscriptions', ['uid' => $uid, 'provider' => 'promo', 'env' => 'promo', 'plan' => STM_PROMO_PLAN, 'interval' => '',
            'status' => $kind === 'trial' ? 'trialing' : 'active', 'period_start' => $starts, 'period_end' => $ends, 'note' => $note, 'created' => time(), 'updated' => time()]);
        q('UPDATE promo_grants SET sub_id = ? WHERE id = ?', [$sid, $gid]);
        billing_cache_clear();
        return row('SELECT * FROM promo_grants WHERE id = ?', [$gid]);
    });
}
/**
 * Accorde l'essai de 30 jours, une fois par compte. Refusé (sans le consommer) si le compte a déjà un accès Team
 * payé ou offert : l'essai ne remplacerait rien. Retourne [granted, reason].
 */
function promo_grant_trial(int $uid, string $source): array
{
    if (!promo_enabled()) return [false, 'closed'];
    if (promo_grant($uid, 'trial')) return [false, 'used'];
    if (promo_has_other_team($uid)) return [false, 'has_team'];
    $now = time();
    $g = promo_insert($uid, 'trial', $source, $now, $now + STM_PROMO_TRIAL_DAYS * 86400, 'Launch offer: ' . STM_PROMO_TRIAL_DAYS . ' days of full access, no card');
    return $g ? [true, ''] : [false, 'used'];
}
/** Où en est l'offre pour ce compte (bannières, page Refer & Earn, page des prix). */
function promo_state(int $uid): array
{
    $now = time();
    $t = promo_grant($uid, 'trial');
    $r = promo_grant($uid, 'referral');
    $phase = function (?array $g) use ($now): string { return !$g ? 'none' : ((int) $g['starts'] > $now ? 'scheduled' : ((int) $g['ends'] > $now ? 'active' : 'ended')); };
    $tp = $phase($t);
    return [
        'enabled' => promo_enabled(), 'goal' => STM_REFERRAL_GOAL, 'trial_days' => STM_PROMO_TRIAL_DAYS, 'reward_months' => STM_REFERRAL_REWARD_MONTHS,
        'trial' => ['state' => $tp, 'starts' => $t ? (int) $t['starts'] : 0, 'ends' => $t ? (int) $t['ends'] : 0,
            'days_left' => $tp === 'active' ? (int) ceil(((int) $t['ends'] - $now) / 86400) : 0],
        'reward' => ['state' => $phase($r), 'starts' => $r ? (int) $r['starts'] : 0, 'ends' => $r ? (int) $r['ends'] : 0,
            'days_left' => $r && (int) $r['ends'] > $now ? (int) ceil(((int) $r['ends'] - max($now, (int) $r['starts'])) / 86400) : 0],
        // Peut encore réclamer l'essai : offre ouverte, jamais eu, pas d'accès Team payé ou offert en cours.
        'claimable' => promo_enabled() && !$t && !promo_has_other_team($uid),
        'other_team' => promo_has_other_team($uid),
    ];
}

/* ---------------------------------------------------------- parrainage */

/** Code de parrainage du compte, créé au premier besoin (aléatoire, sans lien avec l'id ni l'email). */
function ref_code(int $uid): string
{
    $c = val('SELECT code FROM ref_codes WHERE uid = ?', [$uid]);
    if ($c) return (string) $c;
    for ($i = 0; $i < 8; $i++) {
        $code = '';
        for ($k = 0; $k < 8; $k++) $code .= REF_CODE_CHARS[random_int(0, strlen(REF_CODE_CHARS) - 1)];
        if (q('INSERT OR IGNORE INTO ref_codes (uid, code, created) VALUES (?, ?, ?)', [$uid, $code, time()])->rowCount()) return $code;
        $c = val('SELECT code FROM ref_codes WHERE uid = ?', [$uid]);   // créé entre-temps par une autre requête
        if ($c) return (string) $c;
    }
    throw new RuntimeException('Referral code generation failed');
}
function ref_url(string $code): string
{
    return site_url() . '?ref=' . $code;
}
function ref_owner(string $code): ?array
{
    if (!preg_match('/^[' . REF_CODE_CHARS . ']{8}$/', $code)) return null;
    return row('SELECT u.* FROM ref_codes r JOIN users u ON u.id = r.uid WHERE r.code = ? AND u.suspended = 0', [$code]);
}
/** Cookie d'attribution signé : code.horodatage.signature (impossible à fabriquer ou à rajeunir côté navigateur). */
function ref_cookie_value(string $code, int $ts): string
{
    return $code . '.' . $ts . '.' . substr(hash_hmac('sha256', $code . '|' . $ts, install_salt() . '|ref'), 0, 20);
}
/** Attribution en cours dans ce navigateur : [code, clicked_at] si le cookie est intact et a moins de 30 jours. */
function ref_cookie(): ?array
{
    $raw = (string) ($_COOKIE[REF_COOKIE] ?? '');
    if (!preg_match('/^([a-z2-9]{8})\.(\d{9,11})\.([a-f0-9]{20})$/', $raw, $m)) return null;
    if (!hash_equals(ref_cookie_value($m[1], (int) $m[2]), $raw)) return null;
    if ((int) $m[2] < time() - STM_REFERRAL_ATTRIBUTION_DAYS * 86400) return null;
    return [$m[1], (int) $m[2]];
}
/**
 * POST ref_click {code} (public) : un visiteur a ouvert un lien de parrainage (le JavaScript de la page l'envoie :
 * les robots d'aperçu de liens ne comptent pas). Compte le clic, le visiteur unique (cookie aléatoire du navigateur)
 * et pose l'attribution pour 30 jours — la première attribution valable n'est jamais remplacée par une autre.
 */
function api_ref_click(array $b, ?array $u): array
{
    $code = strtolower(line_clean($b['code'] ?? '', 20));
    $owner = promo_enabled() ? ref_owner($code) : null;
    if (!$owner) return ['valid' => false];
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python/i', $ua)) return ['valid' => true];
    if ($u) return ['valid' => true, 'member' => true];   // déjà membre (ou le parrain lui-même) : ni clic, ni attribution
    rate('ref_click', 60, 3600);
    $vid = (string) ($_COOKIE[VISITOR_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $vid)) {
        $vid = token(16);
        setcookie(VISITOR_COOKIE, $vid, cookie_opts(time() + 365 * 86400));
    }
    insert('ref_clicks', ['uid' => (int) $owner['id'], 'visitor' => substr(hash('sha256', install_salt() . '|vid|' . $vid), 0, 32), 'created' => time()]);
    if (!ref_cookie()) setcookie(REF_COOKIE, ref_cookie_value($code, time()), cookie_opts(time() + STM_REFERRAL_ATTRIBUTION_DAYS * 86400));
    return ['valid' => true];
}
/** Adresse « normalisée » pour repérer un parrainage de soi-même (alias +xxx, points des adresses Gmail). */
function email_root(string $email): string
{
    [$local, $domain] = array_pad(explode('@', strtolower(trim($email)), 2), 2, '');
    $local = (string) preg_replace('/\+.*$/', '', $local);
    if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) { $local = str_replace('.', '', $local); $domain = 'gmail.com'; }
    return $local . '@' . $domain;
}
/**
 * Nouveau compte : essai de 30 jours, et parrainage si ce navigateur a ouvert un lien valable dans les 30 derniers
 * jours. Appelé par l'inscription par email et par la création d'un compte avec Google ($verified : adresse déjà
 * vérifiée). Ne fait jamais échouer l'inscription. Retourne [trial accordé ?].
 */
function promo_on_signup(int $uid, bool $verified, ?array $before): bool
{
    try {
        [$granted] = promo_grant_trial($uid, 'signup');
        $att = promo_enabled() ? ref_cookie() : null;
        if ($att) {
            setcookie(REF_COOKIE, '', cookie_opts(time() - 3600));
            $owner = ref_owner($att[0]);
            $me = user_by_id($uid);
            if ($owner && $me && (int) $owner['id'] !== $uid) {
                $status = 'pending';
                $reason = '';
                // Parrainage de soi-même : même adresse (aux alias près), ou inscription depuis la session du parrain.
                if (email_root((string) $owner['email']) === email_root((string) $me['email']) || ($before && (int) $before['id'] === (int) $owner['id'])) {
                    $status = 'rejected';
                    $reason = 'self';
                }
                insert('referrals', ['referrer_uid' => (int) $owner['id'], 'referred_uid' => $uid, 'code' => $att[0], 'clicked_at' => $att[1],
                    'registered_at' => time(), 'status' => $status, 'reason' => $reason, 'ip_hash' => ip_hash()]);
                if ($verified && $status === 'pending') promo_on_verified($uid);
            }
        }
        return $granted;
    } catch (Throwable $e) {
        error_log('studioma promo signup: ' . $e->getMessage());
        return false;
    }
}
/**
 * L'adresse d'un compte vient d'être vérifiée (lien de confirmation, Google, mot de passe oublié, administration) : son
 * parrainage en attente devient vérifié — ou « à examiner » si l'activité est suspecte. Idempotent : seule une
 * ligne « pending » change, une nouvelle vérification ne compte jamais deux fois.
 */
function promo_on_verified(int $uid): void
{
    try {
        $r = row("SELECT * FROM referrals WHERE referred_uid = ? AND status = 'pending'", [$uid]);
        if (!$r) return;
        $ref = (int) $r['referrer_uid'];
        $now = time();
        $reason = '';
        // Même réseau : STM_REFERRAL_SAME_IP_MAX comptes ou plus (3 par défaut) du même parrain inscrits depuis la même
        // adresse IP en 7 jours. Rafale : STM_REFERRAL_BURST_MAX parrainages vérifiés ou plus (10) en une heure. 0 = règle coupée.
        $sameIp = (int) billing_cfg('REFERRAL_SAME_IP_MAX', 3);
        $burst = (int) billing_cfg('REFERRAL_BURST_MAX', 10);
        if ($sameIp > 0 && $r['ip_hash'] !== '' && (int) val("SELECT COUNT(*) FROM referrals WHERE referrer_uid = ? AND ip_hash = ? AND registered_at >= ? AND status <> 'rejected'", [$ref, $r['ip_hash'], $now - 7 * 86400]) >= $sameIp) $reason = 'same_network';
        if ($reason === '' && $burst > 0 && (int) val("SELECT COUNT(*) FROM referrals WHERE referrer_uid = ? AND verified_at >= ? AND status IN ('verified', 'review')", [$ref, $now - 3600]) >= $burst) $reason = 'burst';
        $status = $reason === '' ? 'verified' : 'review';
        if (!q("UPDATE referrals SET status = ?, reason = ?, verified_at = ? WHERE id = ? AND status = 'pending'", [$status, $reason, $now, $r['id']])->rowCount()) return;
        if ($status === 'verified') {
            $n = promo_verified_count($ref);
            if ($n < STM_REFERRAL_GOAL) notify($ref, 0, 'promo', 'A friend you invited verified their account — ' . $n . ' of ' . STM_REFERRAL_GOAL . ' toward your free year', 'refer');
            promo_reward_check($ref);
        }
    } catch (Throwable $e) {
        error_log('studioma promo verified: ' . $e->getMessage());
    }
}
/** Parrainages qui comptent : vérifiés, compte toujours actif (non suspendu). */
function promo_verified_count(int $uid): int
{
    return (int) val("SELECT COUNT(*) FROM referrals r JOIN users u ON u.id = r.referred_uid WHERE r.referrer_uid = ? AND r.status = 'verified' AND u.suspended = 0", [$uid]);
}
/**
 * 10 parrainages vérifiés : 12 mois civils d'accès complet, une seule fois (contrainte d'unicité, transaction). Ils
 * commencent à la fin de l'essai s'il est encore en cours, sinon tout de suite ; un accès payé ou offert en cours
 * n'est ni réduit ni remplacé (le meilleur accès l'emporte toujours).
 */
function promo_reward_check(int $uid): ?array
{
    if (!promo_enabled() || promo_verified_count($uid) < STM_REFERRAL_GOAL || promo_grant($uid, 'referral')) return null;
    $now = time();
    $t = promo_grant($uid, 'trial');
    $start = $t && (int) $t['starts'] <= $now && (int) $t['ends'] > $now ? (int) $t['ends'] : $now;
    $end = (int) strtotime('+' . STM_REFERRAL_REWARD_MONTHS . ' months', $start);
    $g = promo_insert($uid, 'referral', 'referrals', $start, $end, 'Referral reward: ' . STM_REFERRAL_REWARD_MONTHS . ' months of full access, no card');
    if ($g) {
        notify($uid, 0, 'promo', '🎉 You invited ' . STM_REFERRAL_GOAL . ' friends: 1 full year of Studioma Pro is unlocked — from ' . date('j M Y', $start) . ' to ' . date('j M Y', $end) . '. No card, no payment.', 'refer', true);
    }
    return $g;
}

/* ----------------------------------------------------------------- API */

/** Message prêt à partager (le lien personnel y est inséré). */
function promo_share_text(string $url): string
{
    return "🚀 I've been using Studioma to organize creative projects, content, and collaboration.\n"
        . 'You can try all its features free for ' . STM_PROMO_TRIAL_DAYS . " days — no credit card required!\nJoin through my invitation:\n" . $url;
}
/** GET promo : l'offre pour le compte connecté — essai, année offerte, lien, statistiques, progression, historique. */
function api_promo(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $code = ref_code($uid);
    $url = ref_url($code);
    $verified = promo_verified_count($uid);
    $list = rows('SELECT r.status, r.registered_at, r.verified_at, r.reason FROM referrals r WHERE r.referrer_uid = ? ORDER BY r.id DESC LIMIT 200', [$uid]);
    $n = count($list);
    return promo_state($uid) + [
        'code' => $code, 'url' => $url, 'share_text' => promo_share_text($url),
        'stats' => [
            'clicks' => (int) val('SELECT COUNT(*) FROM ref_clicks WHERE uid = ?', [$uid]),
            'visitors' => (int) val('SELECT COUNT(DISTINCT visitor) FROM ref_clicks WHERE uid = ?', [$uid]),
            'registrations' => (int) val('SELECT COUNT(*) FROM referrals WHERE referrer_uid = ?', [$uid]),
            'verified' => $verified,
        ],
        'progress' => min($verified, STM_REFERRAL_GOAL),
        // Historique sans donnée personnelle : ni nom, ni adresse des amis invités.
        'history' => array_map(function (array $r, int $i) use ($n): array {
            return ['n' => $n - $i, 'registered_at' => (int) $r['registered_at'], 'verified_at' => (int) $r['verified_at'], 'status' => (string) $r['status'],
                'label' => REF_STATUSES[$r['status']] ?? $r['status'], 'counts' => $r['status'] === 'verified'];
        }, $list, array_keys($list)),
    ];
}
/** POST promo_claim : un compte existant réclame son essai de 30 jours (une fois ; jamais de paiement). */
function api_promo_claim(array $b, array $u): array
{
    $uid = (int) $u['id'];
    rate('promo_claim', 10, 3600, 'u' . $uid);
    [$ok, $why] = promo_grant_trial($uid, 'claim');
    if (!$ok) {
        $msg = ['closed' => 'The launch offer is not available right now.', 'used' => 'You already used your 30-day free trial. Invite 10 friends to unlock a free year.',
            'has_team' => 'You already have full access with your current plan.'][$why] ?? 'The trial could not be started.';
        fail($msg, 409, 'promo_' . $why);
    }
    return promo_state($uid) + ['granted' => true];
}

/* -------------------------------------------------------- administration */

/**
 * GET admin_promo {days?} : indicateurs de l'offre de lancement (données réelles ; days = 7 | 30 | 90 | 0 pour tout,
 * appliqué aux clics, inscriptions, essais et récompenses) et parrainages à examiner.
 */
function api_admin_promo(array $b, array $a): array
{
    $days = in_array((int) ($b['days'] ?? 0), [7, 30, 90], true) ? (int) $b['days'] : 0;
    $since = $days ? time() - $days * 86400 : 0;
    $now = time();
    $P = STM_PROMO_ID;
    $c = function (string $sql, array $args = []): int { return (int) val($sql, $args); };
    $visitors = $c('SELECT COUNT(DISTINCT visitor) FROM ref_clicks WHERE created >= ?', [$since]);
    $regs = $c('SELECT COUNT(*) FROM referrals WHERE registered_at >= ?', [$since]);
    $verified = $c("SELECT COUNT(*) FROM referrals WHERE status = 'verified' AND verified_at >= ?", [$since]);
    $review = rows("SELECT r.*, a.name AS referrer_name, a.email AS referrer_email, b.name AS referred_name, b.email AS referred_email,
        (SELECT COUNT(*) FROM referrals x WHERE x.referrer_uid = r.referrer_uid AND x.status = 'verified') AS referrer_verified
        FROM referrals r JOIN users a ON a.id = r.referrer_uid JOIN users b ON b.id = r.referred_uid WHERE r.status = 'review' ORDER BY r.id DESC LIMIT 100");
    $top = rows("SELECT r.referrer_uid AS uid, u.name, u.handle, COUNT(*) AS n FROM referrals r JOIN users u ON u.id = r.referrer_uid WHERE r.status = 'verified' GROUP BY r.referrer_uid ORDER BY n DESC LIMIT 10");
    return [
        'enabled' => promo_enabled(), 'days' => $days, 'goal' => STM_REFERRAL_GOAL,
        'metrics' => [
            'users' => $c('SELECT COUNT(*) FROM users WHERE suspended = 0'),
            // Peuvent encore recevoir l'essai : jamais eu (un accès Team payé ou offert n'est pas compté ici).
            'eligible' => $c('SELECT COUNT(*) FROM users u WHERE u.suspended = 0 AND NOT EXISTS (SELECT 1 FROM promo_grants g WHERE g.uid = u.id AND g.promo = ? AND g.kind = \'trial\')', [$P]),
            'trials' => $c("SELECT COUNT(*) FROM promo_grants WHERE promo = ? AND kind = 'trial' AND created >= ?", [$P, $since]),
            'trials_active' => $c("SELECT COUNT(*) FROM promo_grants WHERE promo = ? AND kind = 'trial' AND starts <= ? AND ends > ?", [$P, $now, $now]),
            'trials_ended' => $c("SELECT COUNT(*) FROM promo_grants WHERE promo = ? AND kind = 'trial' AND ends <= ?", [$P, $now]),
            'clicks' => $c('SELECT COUNT(*) FROM ref_clicks WHERE created >= ?', [$since]),
            'visitors' => $visitors,
            'registrations' => $regs,
            'verified' => $verified,
            'conversion' => $visitors ? round($regs * 100 / $visitors, 1) : 0.0,
            'verify_rate' => $regs ? round($verified * 100 / $regs, 1) : 0.0,
            'referrers_1' => $c("SELECT COUNT(DISTINCT referrer_uid) FROM referrals WHERE status = 'verified'"),
            // Seuil écrit en entier dans la requête : lié comme paramètre, il serait comparé comme du texte par SQLite.
            'referrers_goal' => $c("SELECT COUNT(*) FROM (SELECT referrer_uid FROM referrals WHERE status = 'verified' GROUP BY referrer_uid HAVING COUNT(*) >= " . (int) STM_REFERRAL_GOAL . ')'),
            'rewards' => $c("SELECT COUNT(*) FROM promo_grants WHERE promo = ? AND kind = 'referral' AND created >= ?", [$P, $since]),
            'rewards_active' => $c("SELECT COUNT(*) FROM promo_grants WHERE promo = ? AND kind = 'referral' AND starts <= ? AND ends > ?", [$P, $now, $now]),
            'rewards_scheduled' => $c("SELECT COUNT(*) FROM promo_grants WHERE promo = ? AND kind = 'referral' AND starts > ?", [$P, $now]),
            'rewards_ended' => $c("SELECT COUNT(*) FROM promo_grants WHERE promo = ? AND kind = 'referral' AND ends <= ?", [$P, $now]),
            'review' => count($review),
            'rejected' => $c("SELECT COUNT(*) FROM referrals WHERE status = 'rejected' AND registered_at >= ?", [$since]),
        ],
        'review' => array_map(function (array $r): array {
            return ['id' => (int) $r['id'], 'reason' => (string) $r['reason'], 'registered_at' => (int) $r['registered_at'], 'verified_at' => (int) $r['verified_at'],
                'referrer' => ['id' => (int) $r['referrer_uid'], 'name' => (string) $r['referrer_name'], 'email' => (string) $r['referrer_email'], 'verified' => (int) $r['referrer_verified']],
                'referred' => ['id' => (int) $r['referred_uid'], 'name' => (string) $r['referred_name'], 'email' => (string) $r['referred_email']]];
        }, $review),
        'top' => array_map(function (array $t): array { return ['uid' => (int) $t['uid'], 'name' => (string) $t['name'], 'handle' => (string) $t['handle'], 'verified' => (int) $t['n']]; }, $top),
    ];
}
/** POST admin_promo_review {id, decision: approve | reject} : décision sur un parrainage suspect. */
function api_admin_promo_review(array $b, array $a): array
{
    $r = row("SELECT * FROM referrals WHERE id = ? AND status = 'review'", [int_in($b, 'id')]);
    if (!$r) fail('This referral was already decided or no longer exists.', 404, 'notfound');
    $ok = ($b['decision'] ?? '') === 'approve';
    q("UPDATE referrals SET status = ?, decided_at = ? WHERE id = ? AND status = 'review'", [$ok ? 'verified' : 'rejected', time(), $r['id']]);
    admin_log($ok ? 'referral_approve' : 'referral_reject', 'user:' . $r['referrer_uid'], 'referral #' . $r['id'] . ' (' . $r['reason'] . ')');
    if ($ok) promo_reward_check((int) $r['referrer_uid']);
    return api_admin_promo([], $a);
}
