<?php
/**
 * Administration de Studioma (dehini.com/studioma/admin/) : statistiques en direct, comptes (recherche,
 * fiche, suspension, suppression, export), modération des contenus publics, emails (journal, test,
 * campagnes envoyées par petits lots), état du système et journal des actions de l'administrateur.
 *
 * Accès : la session de l'administration du site (/admin, cookie « dehini_admin », 12 h), comme Maillage
 * et le Marketing Dashboard. Toute écriture exige l'en-tête X-CSRF de cette session. Aucun compte Studioma
 * n'a de droit d'administration.
 *
 * Confidentialité : l'administration voit les comptes et les contenus publics (publications, offres,
 * entreprises, études de cas publiées). Du contenu privé des Studios et des messages, elle ne voit que des
 * nombres, jamais un titre de carte, un nom de projet ou le texte d'un message.
 */
declare(strict_types=1);

const ADMIN_PAGE = 50;
const ONLINE_WINDOW = 300;   // « en ligne » : actif dans les 5 dernières minutes

/**
 * Publics des campagnes : clé => [libellé, condition SQL sur users]. Toujours en plus : compte non suspendu
 * et réglage « news » non désactivé (filtré en PHP, le réglage est en JSON).
 */
const ADMIN_AUDIENCES = [
    'all'         => ['Everyone', '1 = 1'],
    'confirmed'   => ['Confirmed email addresses', 'email_ok = 1'],
    'unconfirmed' => ['Unconfirmed email addresses', 'email_ok = 0'],
    'individuals' => ['Individual accounts (professionals)', "kind = 'individual'"],
    'companies'   => ['Company accounts', "kind = 'company'"],
    'active30'    => ['Active in the last 30 days', 'last_seen >= :t30'],
    'inactive30'  => ['Inactive for 30 days or more', 'last_seen < :t30'],
    'new7'        => ['Joined in the last 7 days', 'created >= :t7'],
    'no_project'  => ['Never joined or created a project', 'NOT EXISTS (SELECT 1 FROM project_members m WHERE m.uid = users.id)'],
];

/* --------------------------------------------------------------- accès */

/** Session de l'administration du site (/admin) obligatoire ; pour une écriture, son jeton CSRF. */
function require_admin(): array
{
    global $isPost;
    $sid = (string) ($_COOKIE['dehini_admin'] ?? '');
    if (!preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid)) fail('Sign in to the administration first.', 401, 'auth');
    session_name('dehini_admin');
    @session_start();
    $ok = !empty($_SESSION['admin']) && !empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) < 12 * 3600;
    $csrf = (string) ($_SESSION['csrf'] ?? '');
    session_write_close();
    if (!$ok) fail('Your administration session has expired. Sign in again.', 401, 'auth');
    if ($isPost && ($csrf === '' || !hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF'] ?? '')))) fail('Your administration session has expired. Reload the page.', 401, 'auth');
    $GLOBALS['stm_admin'] = true;
    return ['id' => 0, 'admin' => true];
}
function admin_log(string $action, string $target = '', string $text = ''): void
{
    insert('admin_log', ['created' => time(), 'action' => $action, 'target' => $target, 'text' => mb_substr($text, 0, 500)]);
    if (random_int(1, 100) === 1) q('DELETE FROM admin_log WHERE created < ?', [time() - 400 * 86400]);
}
function day_start(int $daysAgo = 0): int
{
    return (int) strtotime('today') - $daysAgo * 86400;
}

/* ------------------------------------------------- statistiques en direct */

/** Nombre de lignes par jour sur $days jours (colonne de date en secondes). */
function daily_counts(string $table, string $col, int $days, string $where = '1 = 1', array $args = []): array
{
    if (!in_array($table, ['users', 'cards', 'posts', 'msgs', 'projects', 'card_comments', 'opp_apps', 'mail_log'], true) || !preg_match('/^[a-z_]+$/', $col)) throw new InvalidArgumentException('table');
    $from = day_start($days - 1);
    $out = array_fill(0, $days, 0);
    foreach (rows("SELECT $col AS t FROM $table WHERE $col >= ? AND $where", array_merge([$from], $args)) as $r) {
        $i = (int) floor(((int) strtotime(date('Y-m-d', (int) $r['t'])) - $from) / 86400);
        if ($i >= 0 && $i < $days) $out[$i]++;
    }
    return $out;
}
/** Comptes actifs par jour (table activity_days). */
function daily_active(int $days): array
{
    $from = date('Y-m-d', day_start($days - 1));
    $map = [];
    foreach (rows('SELECT day, COUNT(*) AS n FROM activity_days WHERE day >= ? GROUP BY day', [$from]) as $r) $map[$r['day']] = (int) $r['n'];
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[] = $map[date('Y-m-d', day_start($i))] ?? 0;
    return $out;
}
/** Visiteurs uniques par jour (table visit_days : comptes et visiteurs anonymes). */
function daily_visitors(int $days): array
{
    $from = date('Y-m-d', day_start($days - 1));
    $map = [];
    foreach (rows('SELECT day, COUNT(*) AS n FROM visit_days WHERE day >= ? GROUP BY day', [$from]) as $r) $map[$r['day']] = (int) $r['n'];
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[] = $map[date('Y-m-d', day_start($i))] ?? 0;
    return $out;
}
/** Qui est sur Studioma en ce moment (ping des 5 dernières minutes) : comptes et visiteurs anonymes, page, appareil. */
function admin_visitors(): array
{
    $since = time() - ONLINE_WINDOW;
    $list = rows('SELECT * FROM visitors WHERE last_seen >= ? ORDER BY uid > 0 DESC, last_seen DESC LIMIT 60', [$since]);
    $users = user_cards(array_column($list, 'uid'));
    return [
        'now' => (int) val('SELECT COUNT(*) FROM visitors WHERE last_seen >= ?', [$since]),
        'members' => (int) val('SELECT COUNT(*) FROM visitors WHERE last_seen >= ? AND uid > 0', [$since]),
        'today' => (int) val('SELECT COUNT(*) FROM visit_days WHERE day = ?', [date('Y-m-d')]),
        'week' => (int) val('SELECT COUNT(DISTINCT vid) FROM visit_days WHERE day >= ?', [date('Y-m-d', day_start(6))]),
        'pages' => array_map(function (array $r): array { return ['page' => (string) $r['page'], 'n' => (int) $r['n']]; },
            rows('SELECT page, COUNT(*) AS n FROM visitors WHERE last_seen >= ? GROUP BY page ORDER BY n DESC', [$since])),
        'list' => array_map(function (array $v) use ($users): array {
            return ['user' => (int) $v['uid'] ? ($users[(int) $v['uid']] ?? null) : null, 'member' => (int) $v['uid'] > 0, 'page' => (string) $v['page'],
                'device' => (string) $v['device'], 'since' => (int) $v['first_seen'], 'last' => (int) $v['last_seen'], 'hits' => (int) $v['hits']];
        }, $list),
    ];
}
function admin_user_row(array $t): array
{
    return [
        'id' => (int) $t['id'], 'name' => (string) $t['name'], 'handle' => (string) $t['handle'], 'avatar' => (string) $t['avatar'],
        'email' => (string) $t['email'], 'kind' => (string) $t['kind'], 'title' => (string) $t['title'],
        'created' => (int) $t['created'], 'last_seen' => (int) $t['last_seen'], 'email_ok' => (int) $t['email_ok'] === 1,
        'google' => (string) ($t['google_sub'] ?? '') !== '', 'has_password' => (string) $t['pass'] !== '',
        'suspended' => (int) $t['suspended'], 'online' => (int) $t['last_seen'] >= time() - ONLINE_WINDOW,
        'terms_at' => (int) ($t['terms_at'] ?? 0),
    ];
}
/**
 * Fil d'activité de toute la plateforme. Contenus publics : un extrait ; contenus privés (Studios, messages) :
 * seulement la nature de l'action.
 */
function admin_feed(int $limit = 30): array
{
    $ev = [];
    $add = function (array $list, string $kind, callable $text) use (&$ev): void {
        foreach ($list as $r) $ev[] = ['t' => (int) $r['t'], 'kind' => $kind, 'uid' => (int) ($r['uid'] ?? 0), 'text' => $text($r), 'ref' => (int) ($r['id'] ?? 0)];
    };
    $add(rows('SELECT id, id AS uid, created AS t, kind FROM users ORDER BY id DESC LIMIT ?', [$limit]), 'signup',
        function (array $r): string { return 'joined Studioma' . ($r['kind'] === 'company' ? ' as a company' : ''); });
    $add(rows('SELECT id, uid, created AS t, body FROM posts ORDER BY id DESC LIMIT ?', [$limit]), 'post',
        function (array $r): string { $b = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $r['body']) ?? ''), 0, 90); return 'published a post' . ($b !== '' ? ': “' . $b . '”' : ''); });
    $add(rows('SELECT id, owner_uid AS uid, created AS t FROM projects ORDER BY id DESC LIMIT ?', [$limit]), 'project',
        function (): string { return 'created a project'; });
    $add(rows('SELECT id, created_by AS uid, created AS t FROM studios ORDER BY id DESC LIMIT ?', [$limit]), 'studio',
        function (): string { return 'created a Studio'; });
    $add(rows('SELECT id, created_by AS uid, created AS t FROM cards WHERE created_by > 0 ORDER BY id DESC LIMIT ?', [$limit]), 'card',
        function (): string { return 'added a card to a Studio'; });
    $add(rows('SELECT id, uid, created AS t FROM msgs ORDER BY id DESC LIMIT ?', [$limit]), 'message',
        function (): string { return 'sent a private message'; });
    $add(rows('SELECT o.id, o.uid, o.created AS t, o.title, c.name FROM opps o LEFT JOIN companies c ON c.id = o.company_id ORDER BY o.id DESC LIMIT ?', [$limit]), 'opp',
        function (array $r): string { return 'published the opportunity “' . $r['title'] . '”' . ($r['name'] ? ' for ' . $r['name'] : ''); });
    $add(rows('SELECT id, uid, created AS t FROM opp_apps ORDER BY id DESC LIMIT ?', [$limit]), 'apply',
        function (): string { return 'applied to an opportunity'; });
    $add(rows("SELECT id, uid, created AS t, title FROM portfolio WHERE status = 'published' ORDER BY id DESC LIMIT ?", [$limit]), 'case',
        function (array $r): string { return 'published the case study “' . $r['title'] . '”'; });
    $add(rows('SELECT id, created AS t FROM share_guests WHERE verified > 0 ORDER BY id DESC LIMIT ?', [$limit]), 'guest',
        function (): string { return 'A guest joined a shared Studio'; });
    usort($ev, function (array $a, array $b): int { return $b['t'] <=> $a['t']; });
    $ev = array_slice($ev, 0, $limit);
    $users = user_cards(array_column($ev, 'uid'));
    foreach ($ev as &$e) $e['user'] = $users[$e['uid']] ?? null;
    return $ev;
}
/** GET admin_live : tout le tableau de bord en direct (interrogé toutes les 10 secondes). */
function api_admin_live(array $b, array $a): array
{
    $now = time();
    $today = date('Y-m-d');
    $t0 = day_start();
    $online = rows('SELECT * FROM users WHERE last_seen >= ? AND suspended = 0 ORDER BY last_seen DESC LIMIT 24', [$now - ONLINE_WINDOW]);
    $count = function (string $sql, array $args = []): int { return (int) val($sql, $args); };
    $days = 30;
    $files = row('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes FROM files');
    // Campagne en cours : un lot part après la réponse (la page d'administration interroge toutes les 10 s).
    if (campaign_waiting()) campaign_pump_later(10);
    return [
        'now' => $now,
        'visitors' => admin_visitors(),
        'online' => [
            'users' => $count('SELECT COUNT(*) FROM users WHERE last_seen >= ? AND suspended = 0', [$now - ONLINE_WINDOW]),
            'list' => array_map('admin_user_row', $online),
            'guests' => $count('SELECT COUNT(*) FROM share_guests WHERE last_seen >= ?', [$now - ONLINE_WINDOW]),
            'ai' => $count('SELECT COUNT(DISTINCT uid) FROM ai_grants WHERE used >= ?', [$now - 900]),
        ],
        'today' => [
            'signups' => $count('SELECT COUNT(*) FROM users WHERE created >= ?', [$t0]),
            'active' => $count('SELECT COUNT(*) FROM activity_days WHERE day = ?', [$today]),
            'cards' => $count('SELECT COUNT(*) FROM cards WHERE created >= ?', [$t0]),
            'comments' => $count('SELECT COUNT(*) FROM card_comments WHERE created >= ?', [$t0]),
            'posts' => $count('SELECT COUNT(*) FROM posts WHERE created >= ?', [$t0]),
            'messages' => $count('SELECT COUNT(*) FROM msgs WHERE created >= ?', [$t0]),
            'emails' => $count("SELECT COUNT(*) FROM mail_log WHERE created >= ? AND status = 'sent'", [$t0]),
            'email_failed' => $count("SELECT COUNT(*) FROM mail_log WHERE created >= ? AND status = 'failed'", [$t0]),
        ],
        'active' => [
            'day' => $count('SELECT COUNT(*) FROM activity_days WHERE day = ?', [$today]),
            'week' => $count('SELECT COUNT(DISTINCT uid) FROM activity_days WHERE day >= ?', [date('Y-m-d', day_start(6))]),
            'month' => $count('SELECT COUNT(DISTINCT uid) FROM activity_days WHERE day >= ?', [date('Y-m-d', day_start(29))]),
        ],
        'totals' => [
            'users' => $count('SELECT COUNT(*) FROM users'),
            'new7' => $count('SELECT COUNT(*) FROM users WHERE created >= ?', [day_start(6)]),
            'new30' => $count('SELECT COUNT(*) FROM users WHERE created >= ?', [day_start(29)]),
            'confirmed' => $count('SELECT COUNT(*) FROM users WHERE email_ok = 1'),
            'google' => $count("SELECT COUNT(*) FROM users WHERE google_sub IS NOT NULL AND google_sub <> ''"),
            'company_accounts' => $count("SELECT COUNT(*) FROM users WHERE kind = 'company'"),
            'suspended' => $count('SELECT COUNT(*) FROM users WHERE suspended > 0'),
            'companies' => $count('SELECT COUNT(*) FROM companies'),
            'projects' => $count('SELECT COUNT(*) FROM projects WHERE archived = 0'),
            'studios' => $count('SELECT COUNT(*) FROM studios WHERE archived = 0'),
            'cards' => $count('SELECT COUNT(*) FROM cards WHERE archived = 0'),
            'delivered' => $count('SELECT COUNT(*) FROM cards WHERE done_at > 0'),
            'posts' => $count('SELECT COUNT(*) FROM posts'),
            'opps' => $count("SELECT COUNT(*) FROM opps WHERE status = 'open'"),
            'applications' => $count('SELECT COUNT(*) FROM opp_apps'),
            'cases' => $count("SELECT COUNT(*) FROM portfolio WHERE status = 'published'"),
            'messages' => $count('SELECT COUNT(*) FROM msgs'),
            'files' => (int) $files['n'], 'bytes' => (int) $files['bytes'],
            'shares' => $count('SELECT COUNT(*) FROM shares WHERE active = 1'),
            'guests' => $count("SELECT COUNT(*) FROM share_guests WHERE status = 'active'"),
            'ai' => $count('SELECT COUNT(*) FROM ai_grants'),
        ],
        'series' => [
            'from' => date('Y-m-d', day_start($days - 1)),
            'visitors' => daily_visitors($days),
            'signups' => daily_counts('users', 'created', $days),
            'active' => daily_active($days),
            'cards' => daily_counts('cards', 'created', $days),
            'posts' => daily_counts('posts', 'created', $days),
            'messages' => daily_counts('msgs', 'created', $days),
            'emails' => daily_counts('mail_log', 'created', $days, "status = 'sent'"),
        ],
        'feed' => admin_feed(80),   // l'interface regroupe les actions répétées et en montre 25
        'mail' => mail_status_light(),
    ];
}
/** État court des emails (barre du tableau de bord). */
function mail_status_light(): array
{
    $last = jarr(meta_get('mail_last_error') ?? '');
    return [
        'transport' => mail_transport(),
        'hour' => (int) val('SELECT COUNT(*) FROM mail_log WHERE created >= ?', [time() - 3600]),
        'per_hour' => (int) STM_MAIL_PER_HOUR,
        'pending' => (int) val("SELECT COUNT(*) FROM mail_queue q JOIN campaigns c ON c.id = q.campaign_id WHERE q.status IN ('pending', 'sending') AND c.status = 'sending'"),
        'failed7' => (int) val("SELECT COUNT(*) FROM mail_log WHERE status = 'failed' AND created >= ?", [day_start(6)]),
        'last_error' => $last ? ['at' => (int) ($last['at'] ?? 0), 'error' => (string) ($last['error'] ?? '')] : null,
    ];
}
/** Moyen d'envoi des emails : log (tests) | smtp | mail (fonction mail() de PHP). */
function mail_transport(): string
{
    return STM_MAIL_LOG !== '' ? 'log' : (STM_SMTP_HOST !== '' ? 'smtp' : 'mail');
}

/* ---------------------------------------------------------------- comptes */

const ADMIN_USER_FILTERS = ['all', 'online', 'new', 'active', 'inactive', 'unconfirmed', 'google', 'company', 'individual', 'suspended'];

function admin_user_where(string $filter): array
{
    $now = time();
    switch ($filter) {
        case 'online': return ['last_seen >= ? AND suspended = 0', [$now - ONLINE_WINDOW]];
        case 'new': return ['created >= ?', [day_start(6)]];
        case 'active': return ['last_seen >= ?', [day_start(29)]];
        case 'inactive': return ['last_seen < ?', [day_start(29)]];
        case 'unconfirmed': return ['email_ok = 0', []];
        case 'google': return ["google_sub IS NOT NULL AND google_sub <> ''", []];
        case 'company': return ["kind = 'company'", []];
        case 'individual': return ["kind = 'individual'", []];
        case 'suspended': return ['suspended > 0', []];
        default: return ['1 = 1', []];
    }
}
/** GET admin_users : q (nom, email, identifiant), filter, sort (new | seen | name), page. */
function api_admin_users(array $b, array $a): array
{
    $filter = in_array($b['filter'] ?? '', ADMIN_USER_FILTERS, true) ? (string) $b['filter'] : 'all';
    [$where, $args] = admin_user_where($filter);
    $term = line_clean($b['q'] ?? '', 80);
    if ($term !== '') {
        $where .= " AND (search LIKE ? ESCAPE '\\' OR email LIKE ? ESCAPE '\\')";
        $args[] = like($term);
        $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($term)) . '%';
    }
    $order = ['seen' => 'last_seen DESC', 'name' => 'name COLLATE NOCASE', 'old' => 'id'][$b['sort'] ?? ''] ?? 'id DESC';
    $page = max(0, int_in($b, 'page'));
    $total = (int) val("SELECT COUNT(*) FROM users WHERE $where", $args);
    $list = rows("SELECT u.*, (SELECT COUNT(*) FROM project_members m WHERE m.uid = u.id) AS n_projects FROM users u WHERE $where ORDER BY $order LIMIT " . ADMIN_PAGE . ' OFFSET ' . ($page * ADMIN_PAGE), $args);
    $counts = [];
    foreach (ADMIN_USER_FILTERS as $f) {
        [$w, $wa] = admin_user_where($f);
        $counts[$f] = (int) val("SELECT COUNT(*) FROM users WHERE $w", $wa);
    }
    return [
        'users' => array_map(function (array $t): array {
            return admin_user_row($t) + ['projects' => (int) $t['n_projects'], 'news' => user_settings($t)['email_news'] !== false];
        }, $list),
        'total' => $total, 'page' => $page, 'per_page' => ADMIN_PAGE, 'counts' => $counts,
    ];
}
function admin_target(array $b): array
{
    $t = user_by_id(int_in($b, 'id'));
    if (!$t) fail('This account no longer exists.', 404, 'notfound');
    return $t;
}
/** GET admin_user : la fiche complète d'un compte (sans contenu privé : des nombres seulement). */
function api_admin_user(array $b, array $a): array
{
    $t = admin_target($b);
    $uid = (int) $t['id'];
    $count = function (string $sql, array $args = []): int { return (int) val($sql, $args); };
    $files = row('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes FROM files WHERE uid = ?', [$uid]);
    $days = array_column(rows('SELECT day FROM activity_days WHERE uid = ? AND day >= ? ORDER BY day', [$uid, date('Y-m-d', day_start(29))]), 'day');
    $s = user_settings($t);
    return [
        'user' => admin_user_row($t) + [
            'bio' => (string) $t['bio'], 'location' => (string) $t['location'], 'pro' => (bool) $t['pro'], 'suspend_note' => (string) $t['suspend_note'],
            'settings' => ['email_notifs' => $s['email_notifs'] !== false, 'email_news' => $s['email_news'] !== false],
        ],
        'stats' => [
            'projects_owned' => $count("SELECT COUNT(*) FROM project_members WHERE uid = ? AND role = 'owner'", [$uid]),
            'projects' => $count('SELECT COUNT(*) FROM project_members WHERE uid = ?', [$uid]),
            'studios' => $count('SELECT COUNT(*) FROM studio_members WHERE uid = ?', [$uid]),
            'cards' => $count('SELECT COUNT(*) FROM cards WHERE created_by = ?', [$uid]),
            'comments' => $count('SELECT COUNT(*) FROM card_comments WHERE uid = ?', [$uid]),
            'posts' => $count('SELECT COUNT(*) FROM posts WHERE uid = ?', [$uid]),
            'messages' => $count('SELECT COUNT(*) FROM msgs WHERE uid = ?', [$uid]),
            'cases' => $count("SELECT COUNT(*) FROM portfolio WHERE uid = ? AND status = 'published'", [$uid]),
            'followers' => $count("SELECT COUNT(*) FROM follows WHERE ttype = 'user' AND tid = ?", [$uid]),
            'connections' => $count("SELECT COUNT(*) FROM connections WHERE (a = ? OR b = ?) AND status = 'accepted'", [$uid, $uid]),
            'files' => (int) $files['n'], 'bytes' => (int) $files['bytes'],
            'sessions' => $count('SELECT COUNT(*) FROM sessions WHERE uid = ? AND exp > ?', [$uid, time()]),
            'active_days' => count($days),
        ],
        'days' => $days,
        'companies' => array_map(function (array $c): array {
            return company_card($c) + ['role' => (string) $c['m_role']];
        }, rows('SELECT c.*, m.role AS m_role FROM company_members m JOIN companies c ON c.id = m.company_id WHERE m.uid = ? ORDER BY c.name', [$uid])),
        'ai' => array_map(function (array $g): array {
            return ['id' => (int) $g['id'], 'name' => (string) $g['name'], 'personal' => (string) $g['client_id'] === '', 'created' => (int) $g['created'], 'used' => (int) $g['used']];
        }, rows('SELECT * FROM ai_grants WHERE uid = ? ORDER BY id DESC', [$uid])),
        'emails' => rows('SELECT created, subject, kind, status, error FROM mail_log WHERE email = ? ORDER BY id DESC LIMIT 25', [(string) $t['email']]),
        'billing' => billing_summary($uid),
        'log' => rows('SELECT created, action, text FROM admin_log WHERE target = ? ORDER BY id DESC LIMIT 25', ['user:' . $uid]),
    ];
}
/**
 * POST admin_user_action {id, op} : confirm_email | send_reset | send_confirm | signout | revoke_ai | suspend {note}
 * | unsuspend | news {on} | email {subject, message} | delete {confirm = adresse email du compte}.
 */
function api_admin_user_action(array $b, array $a): array
{
    $t = admin_target($b);
    $uid = (int) $t['id'];
    $email = (string) $t['email'];
    $target = 'user:' . $uid;
    $op = (string) ($b['op'] ?? '');
    switch ($op) {
        case 'confirm_email':
            q('UPDATE users SET email_ok = 1, chash = NULL, cexp = 0 WHERE id = ?', [$uid]);
            admin_log('confirm_email', $target, $email);
            break;
        case 'send_reset':
            $raw = token(24);
            q('UPDATE users SET rhash = ?, rexp = ? WHERE id = ?', [hash('sha256', $raw), time() + 3600, $uid]);
            $GLOBALS['stm_mail_kind'] = 'admin';
            mail_later($email, 'Reset your ' . STM_NAME . ' password', 'Reset your password', [
                'The ' . STM_NAME . ' team sent you this link to choose a new password. It is valid for one hour.',
                'If you didn\'t ask for it, ignore this email: your password stays the same.',
            ], 'Choose a new password', app_url('reset/' . $raw));
            admin_log('send_reset', $target, $email);
            break;
        case 'send_confirm':
            if ((int) $t['email_ok']) fail('This email address is already confirmed.');
            $GLOBALS['stm_mail_kind'] = 'admin';
            email_confirm_mail($email, email_confirm_link($uid));
            admin_log('send_confirm', $target, $email);
            break;
        case 'signout':
            $n = q('DELETE FROM sessions WHERE uid = ?', [$uid])->rowCount();
            admin_log('signout', $target, $n . ' session(s)');
            break;
        case 'revoke_ai':
            $n = q('DELETE FROM ai_grants WHERE uid = ?', [$uid])->rowCount();
            admin_log('revoke_ai', $target, $n . ' connection(s)');
            break;
        case 'suspend':
            $note = line_clean($b['note'] ?? '', 300);
            // Sessions fermées ; les accès des assistants IA sont refusés tant que le compte reste suspendu.
            tx(function () use ($uid, $note): void {
                q('UPDATE users SET suspended = ?, suspend_note = ? WHERE id = ?', [time(), $note, $uid]);
                q('DELETE FROM sessions WHERE uid = ?', [$uid]);
            });
            admin_log('suspend', $target, $email . ($note !== '' ? ' — ' . $note : ''));
            break;
        case 'unsuspend':
            q("UPDATE users SET suspended = 0, suspend_note = '' WHERE id = ?", [$uid]);
            admin_log('unsuspend', $target, $email);
            break;
        case 'news':
            $s = user_settings($t);
            $s['email_news'] = !empty($b['on']);
            q('UPDATE users SET settings = ? WHERE id = ?', [jenc($s), $uid]);
            admin_log('news', $target, $s['email_news'] ? 'subscribed' : 'unsubscribed');
            break;
        case 'email':
            $subject = line_clean($b['subject'] ?? '', 150);
            $message = str_clean($b['message'] ?? '', 5000);
            if ($subject === '' || $message === '') fail('Write a subject and a message.');
            [$html, $text] = mail_layout($subject, admin_paragraphs($message, (string) $t['name']));
            $error = mail_send_logged($email, $subject, $html, $text, 'admin');
            admin_log('email', $target, $subject);
            if ($error !== '') fail('The email could not be sent: ' . $error, 502, 'mail');
            break;
        case 'plan':
            // Formule offerte par l'administration (sans paiement, jamais comptée dans le chiffre d'affaires).
            $plan = (string) ($b['plan'] ?? '');
            if (!isset(STM_PLANS[$plan])) fail('Choose Free, Solo or Team.');
            $until = date_clean((string) ($b['until'] ?? ''));
            if (($b['until'] ?? '') !== '' && $until === '') fail('Enter the end date as YYYY-MM-DD.');
            tx(function () use ($uid, $plan, $until): void {
                q("UPDATE subscriptions SET status = 'canceled', canceled_at = ?, updated = ? WHERE uid = ? AND provider = 'manual' AND status = 'active'", [time(), time(), $uid]);
                if ($plan !== 'free') {
                    insert('subscriptions', ['uid' => $uid, 'provider' => 'manual', 'env' => 'manual', 'plan' => $plan, 'status' => 'active', 'period_start' => time(),
                        'period_end' => $until !== '' ? (int) strtotime($until . ' 23:59:59') : 0, 'note' => 'Granted by the administration', 'created' => time(), 'updated' => time()]);
                }
            });
            billing_cache_clear();
            admin_log('plan', $target, $email . ' — ' . ($plan === 'free' ? 'complimentary plan removed' : 'complimentary ' . STM_PLANS[$plan]['name'] . ($until !== '' ? ' until ' . $until : '')));
            break;
        case 'delete':
            if (strtolower(trim(is_string($b['confirm'] ?? null) ? $b['confirm'] : '')) !== strtolower($email)) fail('Type the account\'s email address to confirm.');
            account_destroy($uid, false);
            admin_log('delete', $target, $email . ' (' . $t['name'] . ')');
            return ['deleted' => true];
        default:
            fail('Unknown action.');
    }
    return api_admin_user(['id' => $uid], $a);
}
/** GET admin_users_csv : export CSV des comptes (téléchargé par le navigateur). */
function api_admin_users_csv(array $b, array $a): array
{
    admin_log('export', 'users', 'CSV export of accounts');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="studioma-accounts-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel reconnaît l'UTF-8
    $cell = function ($v): string {
        $v = (string) $v;
        // Une cellule qui commence par = + - @ serait lue comme une formule par un tableur.
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    };
    fputcsv($out, ['id', 'name', 'email', 'handle', 'kind', 'created', 'last_seen', 'email_confirmed', 'google', 'suspended', 'news_emails', 'projects'], ',', '"', '');
    $stmt = q('SELECT u.*, (SELECT COUNT(*) FROM project_members m WHERE m.uid = u.id) AS n_projects FROM users u ORDER BY u.id');
    while ($t = $stmt->fetch()) {
        fputcsv($out, array_map($cell, [
            $t['id'], $t['name'], $t['email'], $t['handle'], $t['kind'], date('Y-m-d H:i', (int) $t['created']),
            (int) $t['last_seen'] ? date('Y-m-d H:i', (int) $t['last_seen']) : '', (int) $t['email_ok'] ? 'yes' : 'no',
            (string) $t['google_sub'] !== '' ? 'yes' : 'no', (int) $t['suspended'] ? 'yes' : 'no',
            user_settings($t)['email_news'] !== false ? 'yes' : 'no', $t['n_projects'],
        ]), ',', '"', '');
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------ modération */

const ADMIN_CONTENT = ['posts', 'comments', 'opps', 'companies', 'cases'];

/** GET admin_content : type (posts | comments | opps | companies | cases), q, page — contenus publics seulement. */
function api_admin_content(array $b, array $a): array
{
    $type = in_array($b['type'] ?? '', ADMIN_CONTENT, true) ? (string) $b['type'] : 'posts';
    $term = line_clean($b['q'] ?? '', 80);
    $page = max(0, int_in($b, 'page'));
    $lim = ' LIMIT ' . (ADMIN_PAGE + 1) . ' OFFSET ' . ($page * ADMIN_PAGE);
    $likeRaw = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    switch ($type) {
        case 'posts':
            $list = rows('SELECT p.*, (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS likes, (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comments
                FROM posts p' . ($term !== '' ? " WHERE p.body LIKE ? ESCAPE '\\'" : '') . ' ORDER BY p.id DESC' . $lim, $term !== '' ? [$likeRaw] : []);
            $items = array_map(function (array $p): array {
                return ['id' => (int) $p['id'], 'uid' => (int) $p['uid'], 'text' => (string) $p['body'], 'media' => count(jarr($p['media'])),
                    'created' => (int) $p['created'], 'likes' => (int) $p['likes'], 'comments' => (int) $p['comments'], 'link' => 'post/' . $p['id']];
            }, $list);
            break;
        case 'comments':
            $list = rows('SELECT * FROM post_comments' . ($term !== '' ? " WHERE body LIKE ? ESCAPE '\\'" : '') . ' ORDER BY id DESC' . $lim, $term !== '' ? [$likeRaw] : []);
            $items = array_map(function (array $c): array {
                return ['id' => (int) $c['id'], 'uid' => (int) $c['uid'], 'text' => (string) $c['body'], 'created' => (int) $c['created'], 'link' => 'post/' . $c['post_id']];
            }, $list);
            break;
        case 'opps':
            $list = rows('SELECT o.*, c.name AS company, (SELECT COUNT(*) FROM opp_apps x WHERE x.opp_id = o.id) AS apps FROM opps o LEFT JOIN companies c ON c.id = o.company_id'
                . ($term !== '' ? " WHERE o.search LIKE ? ESCAPE '\\'" : '') . ' ORDER BY o.id DESC' . $lim, $term !== '' ? [like($term)] : []);
            $items = array_map(function (array $o): array {
                return ['id' => (int) $o['id'], 'uid' => (int) $o['uid'], 'title' => (string) $o['title'], 'text' => rich_text((string) $o['body'], 200),
                    'company' => (string) $o['company'], 'status' => (string) $o['status'], 'apps' => (int) $o['apps'], 'created' => (int) $o['created'], 'link' => 'opportunity/' . $o['id']];
            }, $list);
            break;
        case 'companies':
            $list = rows('SELECT c.*, (SELECT COUNT(*) FROM company_members m WHERE m.company_id = c.id) AS members, (SELECT COUNT(*) FROM opps o WHERE o.company_id = c.id) AS opps,
                (SELECT m.uid FROM company_members m WHERE m.company_id = c.id AND m.role = \'owner\' ORDER BY m.created LIMIT 1) AS owner
                FROM companies c' . ($term !== '' ? " WHERE c.search LIKE ? ESCAPE '\\'" : '') . ' ORDER BY c.id DESC' . $lim, $term !== '' ? [like($term)] : []);
            $items = array_map(function (array $c): array {
                return ['id' => (int) $c['id'], 'uid' => (int) $c['owner'], 'title' => (string) $c['name'], 'text' => (string) $c['tagline'], 'logo' => (string) $c['logo'],
                    'members' => (int) $c['members'], 'opps' => (int) $c['opps'], 'created' => (int) $c['created'], 'link' => 'c/' . $c['slug']];
            }, $list);
            break;
        default:
            $list = rows("SELECT * FROM portfolio WHERE status = 'published'" . ($term !== '' ? " AND (title LIKE ? ESCAPE '\\' OR brand LIKE ? ESCAPE '\\')" : '') . ' ORDER BY id DESC' . $lim, $term !== '' ? [$likeRaw, $likeRaw] : []);
            $items = array_map(function (array $p): array {
                return ['id' => (int) $p['id'], 'uid' => (int) $p['uid'], 'title' => (string) $p['title'], 'text' => (string) $p['brand'], 'created' => (int) $p['created'],
                    'source' => (string) $p['source']];
            }, $list);
    }
    $more = count($items) > ADMIN_PAGE;
    $items = array_slice($items, 0, ADMIN_PAGE);
    $users = user_cards(array_column($items, 'uid'));
    foreach ($items as &$i) $i['user'] = $users[$i['uid']] ?? null;
    return ['type' => $type, 'items' => $items, 'more' => $more, 'page' => $page];
}
/** POST admin_content_action {type, id, op} : delete (posts, comments, opps, companies) | close (opps) | unpublish (cases). */
function api_admin_content_action(array $b, array $a): array
{
    $type = (string) ($b['type'] ?? '');
    $op = (string) ($b['op'] ?? '');
    $id = int_in($b, 'id');
    $done = false;
    if ($type === 'posts' && $op === 'delete' && ($p = row('SELECT * FROM posts WHERE id = ?', [$id]))) {
        q('DELETE FROM posts WHERE id = ?', [$id]);
        files_release(jarr($p['media']));
        admin_log('delete_post', 'user:' . $p['uid'], mb_substr((string) $p['body'], 0, 120));
        $done = true;
    } elseif ($type === 'comments' && $op === 'delete' && ($c = row('SELECT * FROM post_comments WHERE id = ?', [$id]))) {
        q('DELETE FROM post_comments WHERE id = ?', [$id]);
        admin_log('delete_comment', 'user:' . $c['uid'], mb_substr((string) $c['body'], 0, 120));
        $done = true;
    } elseif ($type === 'opps' && in_array($op, ['delete', 'close'], true) && ($o = row('SELECT * FROM opps WHERE id = ?', [$id]))) {
        if ($op === 'close') q("UPDATE opps SET status = 'closed', updated = ? WHERE id = ?", [time(), $id]);
        else q('DELETE FROM opps WHERE id = ?', [$id]);
        admin_log($op . '_opp', 'user:' . $o['uid'], (string) $o['title']);
        $done = true;
    } elseif ($type === 'companies' && $op === 'delete' && ($c = row('SELECT * FROM companies WHERE id = ?', [$id]))) {
        q('DELETE FROM companies WHERE id = ?', [$id]);
        foreach (['logo', 'cover'] as $k) if ((string) $c[$k] !== '') files_release([(string) $c[$k]]);
        admin_log('delete_company', 'company:' . $id, (string) $c['name']);
        $done = true;
    } elseif ($type === 'cases' && $op === 'unpublish' && ($p = row('SELECT * FROM portfolio WHERE id = ?', [$id]))) {
        q("UPDATE portfolio SET status = 'draft' WHERE id = ?", [$id]);
        admin_log('unpublish_case', 'user:' . $p['uid'], (string) $p['title']);
        $done = true;
    }
    if (!$done) fail('This item no longer exists or this action isn\'t possible.', 404, 'notfound');
    return [];
}

/* ------------------------------------------------------------------ emails */

/** Paragraphes d'un message saisi (lignes vides = nouveau paragraphe) ; {name} devient le prénom. */
function admin_paragraphs(string $message, string $name): array
{
    $first = trim((string) strtok(trim($name) . ' ', ' '));
    $message = str_replace('{name}', $first !== '' ? $first : 'there', $message);
    return array_values(array_filter(array_map('trim', preg_split("/\n\s*\n/", $message) ?: []), 'strlen'));
}
/** Adresse de désinscription signée (sans compte connecté) d'un compte. */
function unsubscribe_url(int $uid): string
{
    return site_url() . 'unsubscribe.php?u=' . $uid . '&t=' . unsubscribe_sig($uid);
}
function unsubscribe_sig(int $uid): string
{
    return substr(hash_hmac('sha256', 'unsubscribe|' . $uid, install_salt()), 0, 32);
}
/** Email d'une campagne pour un compte : [objet, html, texte, en-têtes]. */
function campaign_render(array $c, array $t): array
{
    $first = trim((string) strtok(trim((string) $t['name']) . ' ', ' '));
    $subject = str_replace('{name}', $first !== '' ? $first : 'there', (string) $c['subject']);
    $title = str_replace('{name}', $first !== '' ? $first : 'there', (string) ($c['title'] !== '' ? $c['title'] : $c['subject']));
    [$html, $text] = mail_layout($title, admin_paragraphs((string) $c['body'], (string) $t['name']), (string) $c['btn_label'], (string) $c['btn_url']);
    $unsub = (int) $t['id'] > 0 ? unsubscribe_url((int) $t['id']) : site_url() . 'unsubscribe.php';
    $note = 'You receive this email because you have a ' . STM_NAME . ' account.';
    $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $foot = '<p style="font-size:12px;line-height:1.5;color:#777483;margin:28px 0 0;border-top:1px solid #e6e4dd;padding-top:12px">' . $e($note)
        . ' <a href="' . $e($unsub) . '" style="color:#777483">Unsubscribe from news</a></p>';
    $pos = strrpos($html, '</div>');
    $html = substr($html, 0, (int) $pos) . $foot . substr($html, (int) $pos);
    $text .= "\n--\n" . $note . "\nUnsubscribe: " . $unsub . "\n";
    return [$subject, $html, $text, ['List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click']];
}
/** Destinataires d'un public : comptes non suspendus qui n'ont pas désactivé les emails « news ». */
function audience_recipients(string $key): array
{
    if (!isset(ADMIN_AUDIENCES[$key])) fail('Choose who receives this email.');
    $sql = ADMIN_AUDIENCES[$key][1];
    $args = [];
    foreach ([':t30' => day_start(29), ':t7' => day_start(6)] as $k => $v) {
        if (strpos($sql, $k) !== false) { $sql = str_replace($k, '?', $sql); $args[] = $v; }
    }
    $out = [];
    foreach (rows("SELECT id, email, name, settings FROM users WHERE suspended = 0 AND ($sql) ORDER BY id", $args) as $t) {
        if (user_settings($t)['email_news'] === false || !valid_email((string) $t['email'])) continue;
        $out[] = ['id' => (int) $t['id'], 'email' => (string) $t['email'], 'name' => (string) $t['name']];
    }
    return $out;
}
function campaign_view(array $c): array
{
    $pending = (int) val("SELECT COUNT(*) FROM mail_queue WHERE campaign_id = ? AND status IN ('pending', 'sending')", [$c['id']]);
    return [
        'id' => (int) $c['id'], 'subject' => (string) $c['subject'], 'title' => (string) $c['title'], 'body' => (string) $c['body'],
        'btn_label' => (string) $c['btn_label'], 'btn_url' => (string) $c['btn_url'], 'audience' => (string) $c['audience'],
        'audience_label' => ADMIN_AUDIENCES[$c['audience']][0] ?? $c['audience'], 'status' => (string) $c['status'],
        'total' => (int) $c['total'], 'sent' => (int) $c['sent'], 'failed' => (int) $c['failed'], 'skipped' => (int) $c['skipped'], 'pending' => $pending,
        'created' => (int) $c['created'], 'started' => (int) $c['started'], 'finished' => (int) $c['finished'],
    ];
}
function campaign_fields(array $b): array
{
    $subject = line_clean($b['subject'] ?? '', 150);
    if (mb_strlen($subject) < 3) fail('Write a subject.');
    $body = str_clean($b['body'] ?? '', 10000);
    if (mb_strlen($body) < 10) fail('Write the message.');
    $btnLabel = line_clean($b['btn_label'] ?? '', 40);
    $btnUrl = url_clean($b['btn_url'] ?? '', 500);
    if ($btnLabel !== '' && $btnUrl === '') fail('Add the link of the button (https://…), or remove its label.');
    if ($btnUrl !== '' && $btnLabel === '') $btnLabel = 'Open ' . STM_NAME;
    $audience = (string) ($b['audience'] ?? 'all');
    if (!isset(ADMIN_AUDIENCES[$audience])) fail('Choose who receives this email.');
    return ['subject' => $subject, 'title' => line_clean($b['title'] ?? '', 150), 'body' => $body, 'btn_label' => $btnLabel, 'btn_url' => $btnUrl, 'audience' => $audience];
}
function campaign_get(array $b): array
{
    $c = row('SELECT * FROM campaigns WHERE id = ?', [int_in($b, 'id')]);
    if (!$c) fail('This campaign no longer exists.', 404, 'notfound');
    return $c;
}
/** GET admin_mail : réglages d'envoi, compteurs, campagnes, publics (avec leur nombre de destinataires). */
function api_admin_mail(array $b, array $a): array
{
    $from = mail_from();
    // La configuration du défi Impact n'est chargée que si studioma/config.php ne règle pas son propre SMTP (lib/core.php).
    $source = STM_SMTP_HOST === '' ? 'none' : (defined('IMPACT_SMTP_HOST') ? 'impact' : 'studioma');
    $user = (string) STM_SMTP_USER;
    $masked = $user === '' ? '' : (strpos($user, '@') !== false ? substr($user, 0, 2) . '…' . strstr($user, '@') : substr($user, 0, 2) . '…');
    $audiences = [];
    foreach (ADMIN_AUDIENCES as $k => $v) $audiences[] = ['key' => $k, 'label' => $v[0], 'count' => count(audience_recipients($k))];
    return [
        'config' => [
            'transport' => mail_transport(), 'source' => $source, 'from' => $from, 'from_name' => STM_MAIL_FROM_NAME,
            'host' => STM_SMTP_HOST, 'port' => (int) STM_SMTP_PORT, 'secure' => (string) STM_SMTP_SECURE, 'user' => $masked, 'per_hour' => (int) STM_MAIL_PER_HOUR,
            'finish' => function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'),
            'cron_last' => (int) (meta_get('cron_last') ?? 0),
        ],
        'stats' => mail_status_light() + [
            'today' => (int) val("SELECT COUNT(*) FROM mail_log WHERE status = 'sent' AND created >= ?", [day_start()]),
            'week' => (int) val("SELECT COUNT(*) FROM mail_log WHERE status = 'sent' AND created >= ?", [day_start(6)]),
            'opted_out' => count(array_filter(rows('SELECT settings FROM users'), function (array $t): bool { return (jarr($t['settings'])['email_news'] ?? true) === false; })),
        ],
        'campaigns' => array_map('campaign_view', rows('SELECT * FROM campaigns ORDER BY id DESC LIMIT 50')),
        'audiences' => $audiences,
    ];
}
/** GET admin_mail_log : q (adresse ou objet), status (sent | failed), kind, page. */
function api_admin_mail_log(array $b, array $a): array
{
    $where = ['1 = 1'];
    $args = [];
    $term = line_clean($b['q'] ?? '', 120);
    if ($term !== '') { $where[] = "(email LIKE ? ESCAPE '\\' OR subject LIKE ? ESCAPE '\\')"; $l = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%'; $args[] = $l; $args[] = $l; }
    if (in_array($b['status'] ?? '', ['sent', 'failed'], true)) { $where[] = 'status = ?'; $args[] = $b['status']; }
    if (in_array($b['kind'] ?? '', ['system', 'campaign', 'admin', 'test'], true)) { $where[] = 'kind = ?'; $args[] = $b['kind']; }
    $page = max(0, int_in($b, 'page'));
    $list = rows('SELECT * FROM mail_log WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . (ADMIN_PAGE + 1) . ' OFFSET ' . ($page * ADMIN_PAGE), $args);
    return ['items' => array_map(function (array $m): array {
        return ['id' => (int) $m['id'], 'created' => (int) $m['created'], 'email' => (string) $m['email'], 'subject' => (string) $m['subject'],
            'kind' => (string) $m['kind'], 'campaign_id' => (int) $m['campaign_id'], 'status' => (string) $m['status'], 'error' => (string) $m['error']];
    }, array_slice($list, 0, ADMIN_PAGE)), 'more' => count($list) > ADMIN_PAGE, 'page' => $page];
}
/** POST admin_mail_test {to} : envoie tout de suite un email d'essai et dit si l'envoi a réussi. */
function api_admin_mail_test(array $b, array $a): array
{
    rate('admin_test', 20, 3600, 'admin');
    $to = strtolower(line_clean($b['to'] ?? '', 190));
    if (!valid_email($to)) fail('Enter a valid email address.');
    [$html, $text] = mail_layout('Test email', ['This test email was sent from the ' . STM_NAME . ' administration on ' . date('j M Y, H:i') . '.', 'If you can read it, sending works.']);
    $error = mail_send_logged($to, STM_NAME . ' — test email', $html, $text, 'test');
    admin_log('mail_test', 'mail', $to . ($error !== '' ? ' — failed' : ''));
    return ['sent' => $error === '', 'error' => $error, 'transport' => mail_transport()];
}
/** POST admin_campaign_preview {champs, audience} : aperçu HTML (prénom d'exemple) et nombre de destinataires. */
function api_admin_campaign_preview(array $b, array $a): array
{
    $c = campaign_fields($b);
    [$subject, $html] = campaign_render($c, ['id' => 0, 'name' => 'Amina Example']);
    return ['subject' => $subject, 'html' => $html, 'count' => count(audience_recipients($c['audience']))];
}
/** POST admin_campaign_save {id?, champs} : enregistre un brouillon. */
function api_admin_campaign_save(array $b, array $a): array
{
    $f = campaign_fields($b);
    if (int_in($b, 'id')) {
        $c = campaign_get($b);
        if ($c['status'] !== 'draft') fail('This campaign was already sent: duplicate it to send a new version.');
        q('UPDATE campaigns SET subject = ?, title = ?, body = ?, btn_label = ?, btn_url = ?, audience = ? WHERE id = ?',
            [$f['subject'], $f['title'], $f['body'], $f['btn_label'], $f['btn_url'], $f['audience'], $c['id']]);
        $id = (int) $c['id'];
    } else {
        $id = insert('campaigns', $f + ['status' => 'draft', 'created' => time()]);
        admin_log('campaign_create', 'campaign:' . $id, $f['subject']);
    }
    return ['campaign' => campaign_view(row('SELECT * FROM campaigns WHERE id = ?', [$id]))];
}
/** POST admin_campaign_test {id, to} : envoie un exemplaire d'essai de la campagne. */
function api_admin_campaign_test(array $b, array $a): array
{
    rate('admin_test', 20, 3600, 'admin');
    $c = campaign_get($b);
    $to = strtolower(line_clean($b['to'] ?? '', 190));
    if (!valid_email($to)) fail('Enter a valid email address.');
    $t = row('SELECT id, name FROM users WHERE email = ?', [$to]) ?? ['id' => 0, 'name' => 'Amina Example'];
    [$subject, $html, $text, $headers] = campaign_render($c, $t);
    $error = mail_send_logged($to, '[Test] ' . $subject, $html, $text, 'test', (int) $c['id'], $headers);
    return ['sent' => $error === '', 'error' => $error];
}
/**
 * POST admin_campaign_send {id} : fige la liste des destinataires et met la campagne en envoi. Les emails
 * partent par petits lots (campaign_pump) sans dépasser STM_MAIL_PER_HOUR.
 */
function api_admin_campaign_send(array $b, array $a): array
{
    $c = campaign_get($b);
    if ($c['status'] !== 'draft') fail('This campaign was already sent.');
    $list = audience_recipients((string) $c['audience']);
    if (!$list) fail('Nobody would receive this email: the audience is empty (or everyone in it unsubscribed).');
    tx(function () use ($c, $list): void {
        $now = time();
        foreach ($list as $r) q('INSERT OR IGNORE INTO mail_queue (campaign_id, uid, email, updated) VALUES (?, ?, ?, ?)', [$c['id'], $r['id'], $r['email'], $now]);
        q("UPDATE campaigns SET status = 'sending', total = ?, started = ? WHERE id = ?", [count($list), $now, $c['id']]);
    });
    admin_log('campaign_send', 'campaign:' . $c['id'], $c['subject'] . ' — ' . count($list) . ' recipients');
    campaign_pump_later(10);
    return ['campaign' => campaign_view(row('SELECT * FROM campaigns WHERE id = ?', [$c['id']]))];
}
/** POST admin_campaign_action {id, op} : pause | resume | cancel | delete (brouillon) | duplicate. */
function api_admin_campaign_action(array $b, array $a): array
{
    $c = campaign_get($b);
    $id = (int) $c['id'];
    $op = (string) ($b['op'] ?? '');
    if ($op === 'pause' && $c['status'] === 'sending') {
        q("UPDATE campaigns SET status = 'paused' WHERE id = ?", [$id]);
    } elseif ($op === 'resume' && $c['status'] === 'paused') {
        q("UPDATE campaigns SET status = 'sending' WHERE id = ?", [$id]);
        campaign_pump_later(10);
    } elseif ($op === 'cancel' && in_array($c['status'], ['sending', 'paused'], true)) {
        tx(function () use ($id): void {
            q("UPDATE mail_queue SET status = 'skipped', error = 'Cancelled', updated = ? WHERE campaign_id = ? AND status = 'pending'", [time(), $id]);
            q("UPDATE campaigns SET status = 'cancelled', finished = ? WHERE id = ?", [time(), $id]);
            campaign_counts($id);
        });
    } elseif ($op === 'delete' && in_array($c['status'], ['draft', 'cancelled', 'sent'], true)) {
        q('DELETE FROM campaigns WHERE id = ?', [$id]);
        admin_log('campaign_delete', 'campaign:' . $id, (string) $c['subject']);
        return ['deleted' => true];
    } elseif ($op === 'duplicate') {
        $new = insert('campaigns', ['subject' => $c['subject'], 'title' => $c['title'], 'body' => $c['body'], 'btn_label' => $c['btn_label'],
            'btn_url' => $c['btn_url'], 'audience' => $c['audience'], 'status' => 'draft', 'created' => time()]);
        return ['campaign' => campaign_view(row('SELECT * FROM campaigns WHERE id = ?', [$new]))];
    } else {
        fail('This action isn\'t possible for this campaign now.');
    }
    admin_log('campaign_' . $op, 'campaign:' . $id, (string) $c['subject']);
    return ['campaign' => campaign_view(row('SELECT * FROM campaigns WHERE id = ?', [$id]))];
}
/** GET admin_campaign {id} : la campagne et ses derniers échecs. */
function api_admin_campaign(array $b, array $a): array
{
    $c = campaign_get($b);
    return ['campaign' => campaign_view($c), 'failures' => rows("SELECT email, error, updated FROM mail_queue WHERE campaign_id = ? AND status = 'failed' ORDER BY id DESC LIMIT 50", [$c['id']])];
}
/** POST admin_pump : envoie tout de suite un lot de la file (la page d'administration l'appelle pendant un envoi). */
function api_admin_pump(array $b, array $a): array
{
    $sent = campaign_pump(12, 8.0);
    return ['sent' => $sent, 'mail' => mail_status_light(),
        'campaigns' => array_map('campaign_view', rows("SELECT * FROM campaigns WHERE status IN ('sending', 'paused') OR finished >= ? ORDER BY id DESC LIMIT 10", [time() - 120]))];
}
function campaign_counts(int $id): void
{
    q("UPDATE campaigns SET sent = (SELECT COUNT(*) FROM mail_queue WHERE campaign_id = ? AND status = 'sent'),
        failed = (SELECT COUNT(*) FROM mail_queue WHERE campaign_id = ? AND status = 'failed'),
        skipped = (SELECT COUNT(*) FROM mail_queue WHERE campaign_id = ? AND status = 'skipped') WHERE id = ?", [$id, $id, $id, $id]);
}
/**
 * Envoie au plus $max emails de campagne en attente, dans un budget de $budget secondes et sans dépasser
 * STM_MAIL_PER_HOUR (tous emails confondus). Retourne le nombre d'emails traités.
 */
function campaign_pump(int $max, float $budget): int
{
    $start = microtime(true);
    $now = time();
    // Lignes prises par une requête qui s'est arrêtée en route : marquées en échec plutôt que renvoyées
    // (l'email est peut-être parti).
    q("UPDATE mail_queue SET status = 'failed', error = 'Interrupted', updated = ? WHERE status = 'sending' AND updated < ?", [$now, $now - 900]);
    $room = (int) STM_MAIL_PER_HOUR - (int) val('SELECT COUNT(*) FROM mail_log WHERE created >= ?', [$now - 3600]);
    $n = min($max, $room);
    if ($n <= 0) return 0;
    $claim = token(8);
    q("UPDATE mail_queue SET status = 'sending', claim = ?, updated = ? WHERE id IN (SELECT q.id FROM mail_queue q JOIN campaigns c ON c.id = q.campaign_id
        WHERE q.status = 'pending' AND c.status = 'sending' ORDER BY q.id LIMIT " . (int) $n . ')', [$claim, $now]);
    $list = rows("SELECT * FROM mail_queue WHERE claim = ? AND status = 'sending' ORDER BY id", [$claim]);
    $campaigns = [];
    $done = 0;
    foreach ($list as $r) {
        $cid = (int) $r['campaign_id'];
        if (microtime(true) - $start > $budget) {
            q("UPDATE mail_queue SET status = 'pending', claim = '' WHERE id = ?", [$r['id']]);
            continue;
        }
        $campaigns[$cid] = $campaigns[$cid] ?? row('SELECT * FROM campaigns WHERE id = ?', [$cid]);
        $c = $campaigns[$cid];
        $t = user_by_id((int) $r['uid']);
        // Entre-temps : compte supprimé ou suspendu, désinscription, ou campagne arrêtée.
        if (!$c || $c['status'] !== 'sending' || !$t || (int) $t['suspended'] > 0 || user_settings($t)['email_news'] === false) {
            q("UPDATE mail_queue SET status = 'skipped', error = ?, updated = ? WHERE id = ?", [!$t ? 'Account deleted' : 'Unsubscribed or suspended', time(), $r['id']]);
            continue;
        }
        [$subject, $html, $text, $headers] = campaign_render($c, $t);
        $error = mail_send_logged((string) $t['email'], $subject, $html, $text, 'campaign', $cid, $headers);
        q('UPDATE mail_queue SET status = ?, error = ?, email = ?, updated = ? WHERE id = ?', [$error === '' ? 'sent' : 'failed', $error, $t['email'], time(), $r['id']]);
        $done++;
    }
    foreach (array_keys($campaigns) as $cid) {
        campaign_counts($cid);
        if (!val("SELECT 1 FROM mail_queue WHERE campaign_id = ? AND status IN ('pending', 'sending') LIMIT 1", [$cid])) {
            q("UPDATE campaigns SET status = 'sent', finished = ? WHERE id = ? AND status = 'sending'", [time(), $cid]);
        }
    }
    return $done;
}
/**
 * Lot d'envoi après la réponse, si le serveur sait terminer la réponse avant la fin du script (PHP-FPM ou
 * LiteSpeed) ; sinon rien (la page d'administration et la tâche cron s'en chargent).
 */
function campaign_pump_later(int $max): void
{
    static $done = false;
    if ($done || !(function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) return;
    $done = true;
    register_shutdown_function(function () use ($max): void {
        if (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { campaign_pump($max, 15.0); } catch (Throwable $e) { error_log('studioma campaign: ' . $e->getMessage()); }
    });
}
/** Emails de campagne en attente ? (requête légère, faite à chaque appel de l'API). */
function campaign_waiting(): bool
{
    return (bool) val("SELECT 1 FROM mail_queue WHERE status = 'pending' LIMIT 1");
}

/* ---------------------------------------------------------------- Studios */

/**
 * GET admin_studios : q (Studio, projet, propriétaire), filter (active | archived | all), sort (new | activity |
 * cards), page. Noms, propriétaire, équipe et nombres — jamais le contenu des cartes.
 */
function api_admin_studios(array $b, array $a): array
{
    $filter = in_array($b['filter'] ?? '', ['active', 'archived', 'all'], true) ? (string) $b['filter'] : 'active';
    $where = $filter === 'active' ? ['s.archived = 0 AND p.archived = 0'] : ($filter === 'archived' ? ['(s.archived = 1 OR p.archived = 1)'] : ['1 = 1']);
    $args = [];
    $term = line_clean($b['q'] ?? '', 80);
    if ($term !== '') {
        $l = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
        $where[] = "(s.name LIKE ? ESCAPE '\\' OR p.name LIKE ? ESCAPE '\\' OR u.name LIKE ? ESCAPE '\\' OR u.email LIKE ? ESCAPE '\\')";
        array_push($args, $l, $l, $l, $l);
    }
    $order = ['activity' => 's.updated DESC', 'cards' => 'n_cards DESC, s.id DESC'][$b['sort'] ?? ''] ?? 's.id DESC';
    $page = max(0, int_in($b, 'page'));
    $list = rows("SELECT s.*, p.name AS project, p.owner_uid, p.archived AS p_archived, p.company_id,
            (SELECT COUNT(*) FROM cards c WHERE c.studio_id = s.id AND c.archived = 0) AS n_cards,
            (SELECT COUNT(*) FROM cards c WHERE c.studio_id = s.id AND c.done_at > 0) AS n_done,
            (SELECT COUNT(*) FROM project_members m WHERE m.project_id = s.project_id) + (SELECT COUNT(*) FROM studio_members m WHERE m.studio_id = s.id) AS n_members,
            (SELECT COUNT(*) FROM shares x WHERE x.studio_id = s.id AND x.active = 1) AS n_shares,
            (SELECT COUNT(*) FROM share_guests g WHERE g.studio_id = s.id AND g.status = 'active') AS n_guests
        FROM studios s JOIN projects p ON p.id = s.project_id LEFT JOIN users u ON u.id = p.owner_uid
        WHERE " . implode(' AND ', $where) . " ORDER BY $order LIMIT " . (ADMIN_PAGE + 1) . ' OFFSET ' . ($page * ADMIN_PAGE), $args);
    $more = count($list) > ADMIN_PAGE;
    $list = array_slice($list, 0, ADMIN_PAGE);
    $users = user_cards(array_merge(array_column($list, 'owner_uid'), array_column($list, 'created_by')));
    return [
        'studios' => array_map(function (array $s) use ($users): array {
            return ['id' => (int) $s['id'], 'name' => (string) $s['name'], 'project' => (string) $s['project'], 'company' => (int) $s['company_id'] > 0,
                'owner' => $users[(int) $s['owner_uid']] ?? null, 'creator' => $users[(int) $s['created_by']] ?? null,
                'archived' => (int) $s['archived'] || (int) $s['p_archived'], 'created' => (int) $s['created'], 'updated' => (int) $s['updated'],
                'cards' => (int) $s['n_cards'], 'done' => (int) $s['n_done'], 'members' => (int) $s['n_members'], 'shares' => (int) $s['n_shares'], 'guests' => (int) $s['n_guests']];
        }, $list),
        'more' => $more, 'page' => $page,
        'counts' => [
            'active' => (int) val('SELECT COUNT(*) FROM studios s JOIN projects p ON p.id = s.project_id WHERE s.archived = 0 AND p.archived = 0'),
            'archived' => (int) val('SELECT COUNT(*) FROM studios s JOIN projects p ON p.id = s.project_id WHERE s.archived = 1 OR p.archived = 1'),
            'all' => (int) val('SELECT COUNT(*) FROM studios'),
        ],
    ];
}
/** GET admin_studio {id} : équipe et rôles, niveaux avec leur nombre de cartes, liens de partage, activité (nombres). */
function api_admin_studio(array $b, array $a): array
{
    $s = row('SELECT s.*, p.name AS project, p.owner_uid, p.archived AS p_archived FROM studios s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [int_in($b, 'id')]);
    if (!$s) fail('This Studio no longer exists.', 404, 'notfound');
    $sid = (int) $s['id'];
    $members = [];
    foreach (rows('SELECT uid, role, created FROM project_members WHERE project_id = ?', [$s['project_id']]) as $m) $members[(int) $m['uid']] = ['role' => (string) $m['role'], 'scope' => 'project', 'since' => (int) $m['created']];
    foreach (rows('SELECT uid, role, created FROM studio_members WHERE studio_id = ?', [$sid]) as $m) {
        if (!isset($members[(int) $m['uid']])) $members[(int) $m['uid']] = ['role' => (string) $m['role'], 'scope' => 'studio', 'since' => (int) $m['created']];
    }
    $users = user_cards(array_merge(array_keys($members), [(int) $s['owner_uid'], (int) $s['created_by']]));
    $count = function (string $sql, array $args): int { return (int) val($sql, $args); };
    return [
        'studio' => ['id' => $sid, 'name' => (string) $s['name'], 'project' => (string) $s['project'], 'archived' => (int) $s['archived'] || (int) $s['p_archived'],
            'created' => (int) $s['created'], 'updated' => (int) $s['updated'], 'owner' => $users[(int) $s['owner_uid']] ?? null, 'creator' => $users[(int) $s['created_by']] ?? null],
        'members' => array_values(array_map(function (int $uid, array $m) use ($users): array { return $m + ['user' => $users[$uid] ?? null]; }, array_keys($members), $members)),
        'levels' => array_map(function (array $l): array { return ['name' => (string) $l['name'], 'icon' => (string) $l['icon'], 'cards' => (int) $l['n'], 'archived' => (bool) $l['archived']]; },
            rows('SELECT l.name, l.icon, l.archived, (SELECT COUNT(*) FROM cards c JOIN cols k ON k.id = c.col_id WHERE k.level_id = l.id AND c.archived = 0) AS n FROM levels l WHERE l.studio_id = ? ORDER BY l.pos', [$sid])),
        'stats' => [
            'cards' => $count('SELECT COUNT(*) FROM cards WHERE studio_id = ? AND archived = 0', [$sid]),
            'done' => $count('SELECT COUNT(*) FROM cards WHERE studio_id = ? AND done_at > 0', [$sid]),
            'archived' => $count('SELECT COUNT(*) FROM cards WHERE studio_id = ? AND archived = 1', [$sid]),
            'week' => $count('SELECT COUNT(*) FROM cards WHERE studio_id = ? AND created >= ?', [$sid, day_start(6)]),
            'comments' => $count('SELECT COUNT(*) FROM card_comments c JOIN cards k ON k.id = c.card_id WHERE k.studio_id = ?', [$sid]),
            'files' => $count('SELECT COUNT(*) FROM files WHERE studio_id = ?', [$sid]),
            'events7' => $count('SELECT COUNT(*) FROM events WHERE studio_id = ? AND created >= ?', [$sid, day_start(6)]),
        ],
        'shares' => array_map(function (array $x): array { return ['kind' => (string) $x['kind'], 'active' => (bool) $x['active'], 'guests' => (int) $x['n']]; },
            rows("SELECT x.kind, x.active, (SELECT COUNT(*) FROM share_guests g WHERE g.share_id = x.id AND g.status = 'active') AS n FROM shares x WHERE x.studio_id = ?", [$sid])),
    ];
}

/* ------------------------------------------------------------ abonnements */

/**
 * GET admin_billing {env} : indicateurs des abonnements, calculés sur les seuls abonnements Paddle vérifiés de
 * l'environnement choisi (production par défaut ; sandbox = tests, jamais mêlés). Un compte compte une fois (son
 * meilleur abonnement en cours) ; MRR = prix mensuel, ou annuel / 12. Les formules offertes sont à part.
 */
function api_admin_billing(array $b, array $a): array
{
    $env = ($b['env'] ?? '') === 'sandbox' ? 'sandbox' : 'production';
    $now = time();
    $grace = $now - STM_PAST_DUE_GRACE_DAYS * 86400;
    $live = rows("SELECT * FROM subscriptions WHERE provider = 'paddle' AND env = ? AND (status IN ('trialing', 'active') OR (status = 'past_due' AND past_due_since > ?)) ORDER BY id", [$env, $grace]);
    $best = [];
    foreach ($live as $s) {
        $u = (int) $s['uid'];
        if (!isset($best[$u]) || plan_def($s['plan'])['rank'] > plan_def($best[$u]['plan'])['rank']) $best[$u] = $s;
    }
    $mrr = [];
    $dist = ['solo' => ['month' => 0, 'year' => 0], 'team' => ['month' => 0, 'year' => 0]];
    $trialing = 0;
    foreach ($best as $s) {
        if (isset($dist[$s['plan']][$s['interval']])) $dist[$s['plan']][$s['interval']]++;
        if ($s['status'] === 'trialing') { $trialing++; continue; }   // pas encore payé
        $cur = $s['currency'] !== '' ? $s['currency'] : 'USD';
        $mrr[$cur] = ($mrr[$cur] ?? 0) + ($s['interval'] === 'year' ? (int) round((int) $s['amount'] / 12) : (int) $s['amount']);
    }
    $users = (int) val('SELECT COUNT(*) FROM users');
    $comp = (int) val("SELECT COUNT(DISTINCT uid) FROM subscriptions WHERE provider = 'manual' AND status = 'active' AND (period_end = 0 OR period_end > ?)", [$now]);
    $everPaid = (int) val("SELECT COUNT(DISTINCT uid) FROM subscriptions WHERE provider = 'paddle' AND env = ?", [$env]);
    $firsts = rows("SELECT uid, MIN(created) AS first FROM subscriptions WHERE provider = 'paddle' AND env = ? GROUP BY uid", [$env]);
    $conv30 = count(array_filter($firsts, function (array $r) use ($now): bool { return (int) $r['first'] >= $now - 30 * 86400; }));
    $list = rows("SELECT s.*, u.name, u.email, u.handle, u.avatar FROM subscriptions s LEFT JOIN users u ON u.id = s.uid WHERE s.env = ? OR s.provider = 'manual' ORDER BY s.id DESC LIMIT 60", [$env]);
    return [
        'env' => $env, 'configured_env' => billing_env(), 'available' => billing_available(), 'missing' => billing_missing(), 'problems' => billing_config_problems(), 'enforced' => billing_enforced(),
        'metrics' => [
            'users' => $users, 'paid' => count($best), 'free' => max(0, $users - count($best) - $comp), 'complimentary' => $comp, 'trialing' => $trialing,
            'solo' => $dist['solo']['month'] + $dist['solo']['year'], 'team' => $dist['team']['month'] + $dist['team']['year'], 'distribution' => $dist,
            'mrr' => $mrr, 'arr' => array_map(function (int $v): int { return $v * 12; }, $mrr),
            'past_due' => count(array_filter($best, function (array $s): bool { return $s['status'] === 'past_due'; })),
            'scheduled_cancel' => count(array_filter($best, function (array $s): bool { return (int) $s['cancel_at'] > 0; })),
            'canceled_total' => (int) val("SELECT COUNT(*) FROM subscriptions WHERE provider = 'paddle' AND env = ? AND status = 'canceled'", [$env]),
            'canceled_30' => (int) val("SELECT COUNT(*) FROM subscriptions WHERE provider = 'paddle' AND env = ? AND status = 'canceled' AND updated >= ?", [$env, $now - 30 * 86400]),
            'converted' => $everPaid, 'converted_30' => $conv30, 'conversion_rate' => $users ? round($everPaid / $users * 100, 1) : 0,
            'revenue_30' => (int) val("SELECT COALESCE(SUM(amount), 0) FROM billing_transactions WHERE env = ? AND status IN ('completed', 'paid') AND billed_at >= ?", [$env, $now - 30 * 86400]),
        ],
        'subscriptions' => array_map(function (array $s): array {
            return subscription_view($s) + ['user' => $s['name'] !== null ? ['id' => (int) $s['uid'], 'name' => (string) $s['name'], 'email' => (string) $s['email'], 'handle' => (string) $s['handle'], 'avatar' => (string) $s['avatar']] : null,
                'provider_sub_id' => (string) $s['provider_sub_id'], 'created' => (int) $s['created']];
        }, $list),
        'events' => rows('SELECT event_id, type, status, error, uid, received FROM billing_events ORDER BY id DESC LIMIT 40'),
    ];
}

/* ---------------------------------------------------------------- système */

/** GET admin_system : versions, base, stockage, intégrations, tâche cron, journal de l'administration. */
function api_admin_system(array $b, array $a): array
{
    $dbFile = STM_DATA_DIR . '/studioma.sqlite';
    $dbSize = 0;
    foreach (['', '-wal', '-shm'] as $sfx) if (is_file($dbFile . $sfx)) $dbSize += (int) filesize($dbFile . $sfx);
    $page = max(0, int_in($b, 'page'));
    $log = rows('SELECT * FROM admin_log ORDER BY id DESC LIMIT ' . (ADMIN_PAGE + 1) . ' OFFSET ' . ($page * ADMIN_PAGE));
    return [
        'system' => [
            'php' => PHP_VERSION, 'sqlite' => (string) db()->query('SELECT sqlite_version()')->fetchColumn(), 'schema' => (int) db()->query('PRAGMA user_version')->fetchColumn(),
            'db_bytes' => $dbSize, 'files_bytes' => (int) val('SELECT COALESCE(SUM(size), 0) FROM files'),
            'disk_free' => (int) (@disk_free_space(STM_DATA_DIR) ?: 0), 'upload_mb' => upload_limit_mb(), 'timezone' => STM_TIMEZONE,
            'site' => site_url(), 'time' => date('Y-m-d H:i'),
        ],
        'integrations' => [
            'google' => google_client_id() !== '',
            'meta_pixel' => meta_pixel_id(), 'meta_token' => meta_token() !== '',
            // Textes « date — détail » écrits par lib/meta.php.
            'meta_capi_ok' => (string) (meta_get('meta_capi_ok') ?? ''), 'meta_capi_error' => (string) (meta_get('meta_capi_error') ?? ''),
            'mail' => mail_transport(), 'mcp_grants' => (int) val('SELECT COUNT(*) FROM ai_grants'),
            'cron_last' => (int) (meta_get('cron_last') ?? 0),
        ],
        'log' => array_map(function (array $l): array {
            return ['id' => (int) $l['id'], 'created' => (int) $l['created'], 'action' => (string) $l['action'], 'target' => (string) $l['target'], 'text' => (string) $l['text']];
        }, array_slice($log, 0, ADMIN_PAGE)),
        'more' => count($log) > ADMIN_PAGE, 'page' => $page,
    ];
}
