<?php
/**
 * Abonnements et droits (« entitlements ») de Studioma : formule d'un compte, usage, contrôles appliqués côté
 * serveur à chaque création (projets, Studios, cartes, membres, liens de partage) et à chaque fonctionnalité
 * payante (liens privés, invités qui collaborent, validations, équipe, Insights). Formules : lib/plans.php ;
 * fournisseur de paiement : lib/paddle.php.
 *
 * Qui paie ? Le compte propriétaire d'un projet (projects.owner_uid). Tout ce qui se passe dans ses projets compte
 * pour sa formule, quel que soit le membre (ou l'invité, ou l'assistant IA) qui agit.
 *
 * Réglages (config.php, ou variables d'environnement du même nom) :
 *   STM_PLANS_ENFORCED      true par défaut : limites appliquées (false = tout permis, compteurs tenus quand même)
 *   STM_BILLING_ENABLED     false par défaut : ouvre le paiement quand Paddle est configuré (voir lib/paddle.php)
 *   STM_PADDLE_ENV          sandbox (défaut) | production
 *   STM_PADDLE_API_KEY, STM_PADDLE_CLIENT_TOKEN, STM_PADDLE_WEBHOOK_SECRET
 *   STM_PADDLE_PRICE_SOLO_MONTHLY, STM_PADDLE_PRICE_SOLO_YEARLY, STM_PADDLE_PRICE_TEAM_MONTHLY, STM_PADDLE_PRICE_TEAM_YEARLY
 *
 * Données : aucune n'est jamais supprimée ni archivée par un changement de formule. Au-delà des limites, la
 * création est refusée, les membres en trop passent en lecture seule (par ordre d'arrivée), les invités en
 * lecture seule, jusqu'à un retour dans les limites ou une formule supérieure.
 */
declare(strict_types=1);

/* --------------------------------------------------------------- réglages */

/** Réglage : constante de config.php, sinon variable d'environnement, sinon $default. */
function billing_cfg(string $key, $default = '')
{
    $name = 'STM_' . $key;
    if (defined($name)) return constant($name);
    $env = getenv($name);
    return $env === false || $env === '' ? $default : $env;
}
function billing_flag(string $key, bool $default): bool
{
    $v = billing_cfg($key, $default ? '1' : '0');
    return is_bool($v) ? $v : in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}
function billing_enforced(): bool
{
    return billing_flag('PLANS_ENFORCED', true);
}
function billing_env(): string
{
    return billing_cfg('PADDLE_ENV', 'sandbox') === 'production' ? 'production' : 'sandbox';
}
/** Identifiants de prix Paddle : [plan][interval] => price id ('' si non réglé). */
function billing_prices(): array
{
    return [
        'solo' => ['month' => (string) billing_cfg('PADDLE_PRICE_SOLO_MONTHLY'), 'year' => (string) billing_cfg('PADDLE_PRICE_SOLO_YEARLY')],
        'team' => ['month' => (string) billing_cfg('PADDLE_PRICE_TEAM_MONTHLY'), 'year' => (string) billing_cfg('PADDLE_PRICE_TEAM_YEARLY')],
    ];
}
/** [plan, interval] d'un identifiant de prix Paddle, ou null s'il n'est pas l'un des nôtres. */
function billing_price_plan(string $priceId): ?array
{
    if ($priceId === '') return null;
    foreach (billing_prices() as $plan => $byInterval) {
        foreach ($byInterval as $interval => $id) if ($id !== '' && hash_equals($id, $priceId)) return [$plan, $interval];
    }
    return null;
}
/** Réglages manquants pour ouvrir le paiement (noms seulement, jamais les valeurs). */
function billing_missing(): array
{
    $missing = [];
    if (!billing_flag('BILLING_ENABLED', false)) $missing[] = 'STM_BILLING_ENABLED';
    foreach (['PADDLE_API_KEY', 'PADDLE_CLIENT_TOKEN', 'PADDLE_WEBHOOK_SECRET'] as $k) if ((string) billing_cfg($k) === '') $missing[] = 'STM_' . $k;
    foreach (billing_prices() as $plan => $byInterval) {
        foreach ($byInterval as $interval => $id) if ($id === '') $missing[] = 'STM_PADDLE_PRICE_' . strtoupper($plan) . '_' . ($interval === 'month' ? 'MONTHLY' : 'YEARLY');
    }
    return $missing;
}
/** Le paiement en ligne est-il ouvert ? (Paddle entièrement configuré et activé par STM_BILLING_ENABLED.) */
function billing_available(): bool
{
    return billing_missing() === [];
}

/* ------------------------------------------------------- formule d'un compte */

function plan_def(string $plan): array
{
    return STM_PLANS[$plan] ?? STM_PLANS['free'];
}
function billing_cache_clear(): void
{
    $GLOBALS['stm_plan_cache'] = [];
    $GLOBALS['stm_seat_cache'] = [];
}
/**
 * Formule en vigueur d'un compte, d'après les seuls abonnements vérifiés (Paddle, par webhook ou réponse de son
 * API ; ou offert par l'administration). ['plan', 'status', 'sub' (ligne ou null), 'grace_until'].
 */
function account_plan(int $uid): array
{
    $cache = &$GLOBALS['stm_plan_cache'];
    if (isset($cache[$uid])) return $cache[$uid];
    $best = ['plan' => 'free', 'status' => 'free', 'sub' => null, 'grace_until' => 0];
    if ($uid <= 0) return $best;
    $now = time();
    foreach (rows("SELECT * FROM subscriptions WHERE uid = ? AND status IN ('trialing', 'active', 'past_due') ORDER BY id DESC", [$uid]) as $s) {
        if ($s['provider'] === 'manual') {
            if ((int) $s['period_end'] && (int) $s['period_end'] <= $now) continue;   // offert jusqu'à une date passée
        } elseif ($s['env'] !== billing_env()) {
            continue;   // un abonnement de test (sandbox) ne vaut rien en production, et inversement
        }
        $grace = 0;
        if ($s['status'] === 'past_due') {
            $grace = (int) $s['past_due_since'] + STM_PAST_DUE_GRACE_DAYS * 86400;
            if ($grace <= $now) continue;   // paiement en échec depuis trop longtemps : formule Free
        }
        if (!isset(STM_PLANS[$s['plan']])) continue;
        if ($best['sub'] === null || plan_def($s['plan'])['rank'] > plan_def($best['plan'])['rank']) {
            $best = ['plan' => (string) $s['plan'], 'status' => (string) $s['status'], 'sub' => $s, 'grace_until' => $grace];
        }
    }
    return $cache[$uid] = $best;
}
/** Le compte qui paie pour un projet : son propriétaire d'origine (repris par un autre propriétaire si son compte est supprimé). */
function project_account($project): int
{
    if (is_array($project)) return (int) $project['owner_uid'];
    return (int) (val('SELECT owner_uid FROM projects WHERE id = ?', [(int) $project]) ?? 0);
}
function studio_account(int $studioId): int
{
    return (int) (val('SELECT p.owner_uid FROM studios s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$studioId]) ?? 0);
}
/** Limites et fonctionnalités de la formule en vigueur. */
function account_entitlements(int $uid): array
{
    $plan = account_plan($uid);
    $def = plan_def($plan['plan']);
    return ['plan' => $plan['plan'], 'limits' => $def['limits'], 'features' => $def['features']];
}
function plan_has(int $account, string $feature): bool
{
    return !billing_enforced() || !empty(account_entitlements($account)['features'][$feature]);
}

/* --------------------------------------------------------------- usage */

function usage_period(?int $ts = null): string
{
    return date('Y-m', $ts ?? time());
}
function usage_get(int $uid, string $metric, ?string $period = null): int
{
    return (int) (val('SELECT n FROM usage_counters WHERE uid = ? AND period = ? AND metric = ?', [$uid, $period ?? usage_period(), $metric]) ?? 0);
}
function usage_add(int $uid, string $metric, int $n = 1): void
{
    if ($uid <= 0 || $n <= 0) return;
    $period = usage_period();
    q('INSERT OR IGNORE INTO usage_counters (uid, period, metric, n) VALUES (?, ?, ?, 0)', [$uid, $period, $metric]);
    q('UPDATE usage_counters SET n = n + ? WHERE uid = ? AND period = ? AND metric = ?', [$n, $uid, $period, $metric]);
}
/**
 * Personnes inscrites qui occupent une place dans les projets d'un compte, dans l'ordre d'arrivée, le compte
 * lui-même en premier. Les invités des liens de partage n'y sont jamais.
 */
function account_seats(int $uid): array
{
    $cache = &$GLOBALS['stm_seat_cache'];
    if (isset($cache[$uid])) return $cache[$uid];
    $list = array_map('intval', array_column(rows("SELECT uid, MIN(created) AS since FROM (
            SELECT m.uid, m.created FROM project_members m JOIN projects p ON p.id = m.project_id WHERE p.owner_uid = ?
            UNION ALL SELECT m.uid, m.created FROM studio_members m JOIN studios s ON s.id = m.studio_id JOIN projects p ON p.id = s.project_id WHERE p.owner_uid = ?
            UNION ALL SELECT cm.uid, cm.created FROM company_members cm JOIN projects p ON p.company_id = cm.company_id
                WHERE p.owner_uid = ? AND p.company_id > 0 AND cm.role IN ('owner', 'admin')
        ) GROUP BY uid ORDER BY since, uid", [$uid, $uid, $uid]), 'uid'));
    $list = array_values(array_filter($list, function (int $x) use ($uid): bool { return $x !== $uid; }));
    array_unshift($list, $uid);
    return $cache[$uid] = $list;
}
/** Invitations en attente (personnelles) dans les projets d'un compte : elles réservent une place. */
function account_pending_invites(int $uid): int
{
    return (int) val("SELECT COUNT(*) FROM invites i JOIN projects p ON p.id = i.project_id
        WHERE p.owner_uid = ? AND i.status = 'pending' AND i.expires > ? AND (i.email <> '' OR i.uid > 0)", [$uid, time()]);
}
/** Ce membre a-t-il sa place dans la formule du compte ? Sinon il garde la lecture seule. */
function seat_allowed(int $account, int $uid): bool
{
    if (!billing_enforced() || $account <= 0 || $uid === $account) return true;
    $max = account_entitlements($account)['limits']['members'];
    if ($max === null) return true;
    return in_array($uid, array_slice(account_seats($account), 0, max(1, (int) $max)), true);
}
/** Rôle d'un membre plafonné à « viewer » s'il n'a pas de place dans la formule du compte propriétaire. */
function seat_cap(string $role, int $uid, array $project): string
{
    if ($role === '' || $role === 'viewer' || $uid <= 0) return $role;
    return seat_allowed(project_account($project), $uid) ? $role : 'viewer';
}
function active_share_links(int $uid, int $exceptShareId = 0): int
{
    return (int) val('SELECT COUNT(*) FROM shares x JOIN studios s ON s.id = x.studio_id JOIN projects p ON p.id = s.project_id
        WHERE p.owner_uid = ? AND x.active = 1 AND (x.expires = 0 OR x.expires > ?) AND x.id <> ?', [$uid, time(), $exceptShareId]);
}
/** Usage réel d'un compte (réglages › Plan & billing, administration). */
function account_usage(int $uid): array
{
    return [
        'projects' => (int) val('SELECT COUNT(*) FROM projects WHERE owner_uid = ? AND archived = 0', [$uid]),
        'studios' => (int) val('SELECT COUNT(*) FROM studios s JOIN projects p ON p.id = s.project_id WHERE p.owner_uid = ? AND s.archived = 0 AND p.archived = 0', [$uid]),
        'members' => count(account_seats($uid)),
        'pending_invites' => account_pending_invites($uid),
        'cards_month' => usage_get($uid, 'cards'),
        'share_links' => active_share_links($uid),
    ];
}

/* ------------------------------------------------------------- contrôles */

/**
 * Refus pour cause de formule : HTTP 402, code « plan_limit », formule conseillée (« upgrade ») et fonctionnalité.
 * L'application affiche alors une invitation à changer de formule (assets/billing.js).
 */
function plan_fail(string $message, string $upgrade = '', string $feature = ''): void
{
    tx_rollback();
    out(['error' => 'plan_limit', 'message' => $message, 'upgrade' => $upgrade, 'feature' => $feature], 402);
}
/** « your » pour le compte propriétaire, sinon « this workspace's » (un membre agit dans le projet d'un autre). */
function plan_whose(int $account, int $actor): string
{
    return $account === $actor ? 'your' : 'this workspace\'s';
}
function next_plan(string $plan): string
{
    return $plan === 'free' ? 'solo' : ($plan === 'solo' ? 'team' : '');
}
function need_project_slot(int $account, int $actor): void
{
    if (!billing_enforced()) return;
    $e = account_entitlements($account);
    $max = $e['limits']['projects'];
    if ($max === null || account_usage($account)['projects'] < $max) return;
    $name = plan_def($e['plan'])['name'];
    $up = next_plan($e['plan']);
    $who = plan_whose($account, $actor);
    if ($e['plan'] === 'free') plan_fail('You\'ve reached ' . ($who === 'your' ? 'your' : 'this workspace\'s') . ' Free plan limit. Upgrade to Solo to manage more projects.', 'solo', 'projects');
    plan_fail('You\'ve reached ' . $who . ' ' . $name . ' plan limit of ' . $max . ' projects. ' . ($up ? 'Upgrade to ' . plan_def($up)['name'] . ' to manage up to ' . plan_def($up)['limits']['projects'] . '.' : 'Archive a project to create a new one.'), $up, 'projects');
}
function need_studio_slot(int $account, int $actor): void
{
    if (!billing_enforced()) return;
    $e = account_entitlements($account);
    $max = $e['limits']['studios'];
    if ($max === null || account_usage($account)['studios'] < $max) return;
    $up = next_plan($e['plan']);
    plan_fail('You\'ve reached ' . plan_whose($account, $actor) . ' ' . plan_def($e['plan'])['name'] . ' plan limit of ' . $max . ' Studio' . ($max > 1 ? 's' : '') . '. '
        . ($up ? 'Upgrade to ' . plan_def($up)['name'] . ' for up to ' . plan_def($up)['limits']['studios'] . ' Studios.' : 'Archive a Studio to create a new one.'), $up, 'studios');
}
/** Compte $n nouvelles cartes pour le mois (toujours) et refuse au-delà de la limite (si les limites s'appliquent). */
function count_cards(int $account, int $actor, int $n = 1): void
{
    if ($account <= 0) return;
    if (billing_enforced()) {
        $e = account_entitlements($account);
        $max = $e['limits']['cards_month'];
        $used = usage_get($account, 'cards');
        if ($max !== null && $used + $n > $max) {
            $renew = date('j M', (int) strtotime('first day of next month'));
            plan_fail('You\'ve used ' . plan_whose($account, $actor) . ' ' . $max . ' new cards for this month on the Free plan. Upgrade to Solo for unlimited cards — or wait until ' . $renew . ', when the allowance renews. Existing cards stay available.', 'solo', 'cards');
        }
        if ($max === null && $used + $n > STM_FAIR_USE_CARDS) {
            plan_fail('This account reached the fair-use limit of ' . number_format(STM_FAIR_USE_CARDS) . ' new cards this month. Contact us if you need more.', '', 'cards');
        }
    }
    usage_add($account, 'cards', $n);
}
/** Une place pour un nouveau membre inscrit ($pending : compter aussi les invitations en attente). */
function need_seat(int $account, int $actor, bool $pending): void
{
    if (!billing_enforced()) return;
    $e = account_entitlements($account);
    if (empty($e['features']['team'])) plan_fail('Team collaboration is available on the Team plan.', 'team', 'team');
    $max = $e['limits']['members'];
    if ($max === null) return;
    $used = count(account_seats($account)) + ($pending ? account_pending_invites($account) : 0);
    if ($used + 1 > $max) {
        plan_fail(($account === $actor ? 'Your' : 'This workspace\'s') . ' ' . plan_def($e['plan'])['name'] . ' plan includes ' . $max . ' members (including the owner)' . ($pending ? ', counting pending invitations' : '')
            . '. Remove a member' . ($pending ? ' or a pending invitation' : '') . ' to add someone else.', '', 'members');
    }
}
const PLAN_FEATURE_MESSAGES = [
    'approvals' => ['Client approval workflows are available on Solo and Team.', 'solo'],
    'private_links' => ['Private guest links are available on Solo and Team.', 'solo'],
    'guest_collab' => ['Guests who comment, approve or edit, added by email, are available on Solo and Team. On Free, guest links are view-only.', 'solo'],
    'insights' => ['Studio performance insights are available on the Team plan.', 'team'],
    'team' => ['Team collaboration is available on the Team plan.', 'team'],
];
function need_feature(int $account, string $feature): void
{
    if (plan_has($account, $feature)) return;
    [$msg, $up] = PLAN_FEATURE_MESSAGES[$feature];
    plan_fail($msg, $up, $feature);
}
/** Un lien de partage de plus peut-il être actif ? ($exceptShareId : le lien qu'on active.) */
function need_share_slot(int $account, int $exceptShareId): void
{
    if (!billing_enforced()) return;
    $e = account_entitlements($account);
    $max = $e['limits']['share_links'];
    if ($max === null || active_share_links($account, $exceptShareId) < $max) return;
    plan_fail('The Free plan includes ' . $max . ' active guest links. Turn one off, or upgrade to Solo for unlimited links.', 'solo', 'share_links');
}
/** Les invités des Studios de ce compte peuvent-ils commenter, valider, modifier ? (Sinon : lecture seule.) */
function guest_collab_allowed(int $account): bool
{
    return plan_has($account, 'guest_collab');
}

/* -------------------------------------------------- vue « Plan & billing » */

/** Ce qui dépasse la formule en vigueur (après un passage à une formule inférieure, par exemple). */
function account_over_limits(int $uid): array
{
    if (!billing_enforced()) return [];
    $e = account_entitlements($uid);
    $use = account_usage($uid);
    $out = [];
    foreach (['projects' => 'projects', 'studios' => 'Studios', 'members' => 'members', 'share_links' => 'active guest links'] as $k => $label) {
        $max = $e['limits'][$k];
        if ($max !== null && $use[$k] > $max) $out[] = ['key' => $k, 'used' => $use[$k], 'max' => $max, 'label' => $label];
    }
    return $out;
}
function subscription_view(?array $s): ?array
{
    if (!$s) return null;
    return [
        'id' => (int) $s['id'], 'provider' => (string) $s['provider'], 'env' => (string) $s['env'], 'plan' => (string) $s['plan'], 'interval' => (string) $s['interval'],
        'status' => (string) $s['status'], 'amount' => (int) $s['amount'], 'currency' => (string) $s['currency'],
        'period_start' => (int) $s['period_start'], 'period_end' => (int) $s['period_end'], 'cancel_at' => (int) $s['cancel_at'],
        'canceled_at' => (int) $s['canceled_at'], 'past_due_since' => (int) $s['past_due_since'], 'note' => (string) $s['note'],
    ];
}
/** Tout ce que l'écran « Plan & billing » montre : formule, état, usage réel, dépassements, paiements. */
function billing_summary(int $uid): array
{
    $p = account_plan($uid);
    $def = plan_def($p['plan']);
    return [
        // state : free | trialing | active | past_due (état de l'abonnement qui donne la formule)
        'plan' => $p['plan'], 'plan_name' => $def['name'], 'state' => $p['status'], 'grace_until' => $p['grace_until'],
        'subscription' => subscription_view($p['sub']), 'limits' => $def['limits'], 'features' => $def['features'],
        'usage' => account_usage($uid), 'over' => account_over_limits($uid), 'fair_use_cards' => STM_FAIR_USE_CARDS,
        'enforced' => billing_enforced(), 'period' => usage_period(), 'renews_cards' => (int) strtotime('first day of next month 00:00'),
    ];
}

/* ------------------------------------------------------------- API (compte) */

/** GET billing_plans : formules, prix, contenu, état du paiement en ligne et formule du compte connecté. */
function api_billing_plans(array $b, ?array $u): array
{
    $plans = [];
    foreach (STM_PLANS as $key => $p) {
        $plans[] = ['key' => $key, 'name' => $p['name'], 'tagline' => $p['tagline'], 'price' => $p['price'], 'limits' => $p['limits'],
            'features' => $p['features'], 'bullets' => STM_PLAN_BULLETS[$key]];
    }
    return [
        'plans' => $plans, 'fair_use_cards' => STM_FAIR_USE_CARDS, 'checkout' => billing_available(), 'env' => billing_env(),
        'current' => $u ? account_plan((int) $u['id'])['plan'] : null,
    ];
}
/** GET billing_config : pour la page de paiement (jeton client Paddle, public par nature ; jamais la clé d'API). */
function api_billing_config(array $b, ?array $u): array
{
    return ['available' => billing_available(), 'env' => billing_env(), 'client_token' => billing_available() ? (string) billing_cfg('PADDLE_CLIENT_TOKEN') : ''];
}
/** GET billing : l'écran « Plan & billing » du compte connecté. */
function api_billing(array $b, array $u): array
{
    $uid = (int) $u['id'];
    return billing_summary($uid) + [
        'checkout' => billing_available(), 'env' => billing_env(),
        'transactions' => array_map(function (array $t): array {
            return ['id' => (string) $t['provider_txn_id'], 'status' => (string) $t['status'], 'amount' => (int) $t['amount'], 'currency' => (string) $t['currency'], 'billed_at' => (int) $t['billed_at']];
        }, rows('SELECT * FROM billing_transactions WHERE uid = ? AND env = ? ORDER BY id DESC LIMIT 24', [$uid, billing_env()])),
    ];
}
