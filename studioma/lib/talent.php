<?php
/**
 * Entreprises (pages, équipes), opportunités et candidatures, annuaire des talents,
 * professionnels enregistrés et messagerie privée.
 */
declare(strict_types=1);

const COMPANY_SIZES = ['1', '2-10', '11-50', '51-200', '201-1000', '1000+'];
const APP_STATUSES = ['new' => 'New', 'shortlisted' => 'Shortlisted', 'declined' => 'Not selected', 'hired' => 'Hired'];

/* ------------------------------------------------------------ entreprises */

function company_reindex(int $id): void
{
    $c = row('SELECT * FROM companies WHERE id = ?', [$id]);
    if ($c) q('UPDATE companies SET search = ? WHERE id = ?', [norm(implode(' ', [$c['name'], $c['tagline'], $c['industry'], $c['location'], $c['about']])), $id]);
}
function company_fields(array $b, int $uid, ?array $cur): array
{
    $name = line_clean($b['name'] ?? '', 80);
    if (mb_strlen($name) < 2) fail('Enter the name of the company.');
    $set = [
        'name' => $name, 'tagline' => line_clean($b['tagline'] ?? '', 140), 'about' => str_clean($b['about'] ?? '', 3000),
        'industry' => line_clean($b['industry'] ?? '', 60), 'size' => in_array($b['size'] ?? '', COMPANY_SIZES, true) ? $b['size'] : '',
        'location' => line_clean($b['location'] ?? '', 80), 'website' => url_clean($b['website'] ?? ''),
    ];
    foreach (['logo', 'cover'] as $k) {
        if (array_key_exists($k, $b)) $set[$k] = $cur && $b[$k] === $cur[$k] ? $cur[$k] : own_image($b[$k], $uid);
    }
    return $set;
}
/** Crée la page d'une entreprise ; son créateur en devient le propriétaire. */
function company_create(array $set, int $uid): int
{
    $id = insert('companies', $set + ['slug' => unique_slug('companies', 'slug', $set['name']), 'created' => time()]);
    insert('company_members', ['company_id' => $id, 'uid' => $uid, 'role' => 'owner', 'created' => time()]);
    company_reindex($id);
    return $id;
}
function api_company_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $id = int_in($b, 'id');
    if ($id) {
        if (!company_admin($uid, $id)) forbidden('Only admins can edit this company page.');
        $cur = row('SELECT * FROM companies WHERE id = ?', [$id]);
        $set = company_fields($b, $uid, $cur);
        $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set)));
        q("UPDATE companies SET $sets WHERE id = ?", array_merge(array_values($set), [$id]));
        company_reindex($id);
    } else {
        if ((int) val("SELECT COUNT(*) FROM company_members WHERE uid = ? AND role = 'owner'", [$uid]) >= 10) fail('You already manage 10 companies.');
        $id = tx(function () use ($b, $uid): int { return company_create(company_fields($b, $uid, null), $uid); });
    }
    return ['id' => $id, 'slug' => (string) val('SELECT slug FROM companies WHERE id = ?', [$id])];
}
function api_company_get(array $b, ?array $u): array
{
    $c = row('SELECT * FROM companies WHERE slug = ?', [strtolower(line_clean($b['slug'] ?? '', 40))]);
    if (!$c) not_found('This company');
    $cid = (int) $c['id'];
    $me = $u ? (int) $u['id'] : 0;
    $role = $me ? company_role($me, $cid) : '';
    $admin = in_array($role, ['owner', 'admin'], true);
    $members = rows('SELECT m.role AS m_role, m.title AS m_title, u.id, u.name, u.handle, u.title, u.avatar FROM company_members m JOIN users u ON u.id = m.uid WHERE m.company_id = ? ORDER BY m.created', [$cid]);
    $opps = rows('SELECT * FROM opps WHERE company_id = ?' . ($admin ? '' : " AND status = 'open'") . ' ORDER BY status DESC, id DESC', [$cid]);
    return [
        'company' => company_card($c) + [
            'about' => (string) $c['about'], 'size' => (string) $c['size'], 'website' => (string) $c['website'], 'cover' => (string) $c['cover'], 'created' => (int) $c['created'],
            'followers' => (int) val("SELECT COUNT(*) FROM follows WHERE ttype = 'company' AND tid = ?", [$cid]),
        ],
        'rel' => ['role' => $role, 'admin' => $admin, 'following' => $me && follows($me, 'company', $cid)],
        'members' => array_map(function (array $m): array {
            return user_card($m) + ['role' => (string) $m['m_role'], 'member_title' => (string) $m['m_title']];
        }, $members),
        'opps' => opp_views($opps, $u),
    ];
}
/** Équipe d'une entreprise : ajoute un compte (par email ou identifiant), change son rôle ou le retire. */
function api_company_member(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $cid = int_in($b, 'company_id');
    $mine = company_role($uid, $cid);
    if (!in_array($mine, ['owner', 'admin'], true)) forbidden('Only admins can manage the team.');
    $op = (string) ($b['op'] ?? '');
    $c = row('SELECT * FROM companies WHERE id = ?', [$cid]);
    if ($op === 'add') {
        $who = strtolower(ltrim(line_clean($b['who'] ?? '', 190), '@'));
        $t = row('SELECT * FROM users WHERE email = ? OR handle = ?', [$who, $who]);
        if (!$t) fail('No account matches this email or username. Ask this person to create their account first.');
        $role = in_array($b['role'] ?? '', ['admin', 'member'], true) ? $b['role'] : 'member';
        q('INSERT OR IGNORE INTO company_members (company_id, uid, role, title, created) VALUES (?, ?, ?, ?, ?)', [$cid, $t['id'], $role, line_clean($b['title'] ?? '', 80), time()]);
        notify((int) $t['id'], $uid, 'network', 'added you to the team of ' . $c['name'], 'c/' . $c['slug'], true);
        return [];
    }
    $target = int_in($b, 'uid');
    $cur = company_role($target, $cid);
    if ($cur === '') fail('This person is not in the team.');
    if ($cur === 'owner' && ($mine !== 'owner' || (int) val("SELECT COUNT(*) FROM company_members WHERE company_id = ? AND role = 'owner'", [$cid]) <= 1)) {
        fail('A company needs an owner. Name another owner first.');
    }
    if ($op === 'remove') {
        q('DELETE FROM company_members WHERE company_id = ? AND uid = ?', [$cid, $target]);
    } elseif ($op === 'role') {
        $role = in_array($b['role'] ?? '', ['owner', 'admin', 'member'], true) ? $b['role'] : 'member';
        if ($role === 'owner' && $mine !== 'owner') forbidden('Only an owner can name another owner.');
        q('UPDATE company_members SET role = ?, title = ? WHERE company_id = ? AND uid = ?', [$role, line_clean($b['title'] ?? '', 80), $cid, $target]);
    } else {
        fail('Unknown action.');
    }
    return [];
}

/* ----------------------------------------------------------- opportunités */

function opp_views(array $opps, ?array $u): array
{
    if (!$opps) return [];
    $me = $u ? (int) $u['id'] : 0;
    $companies = company_cards(array_column($opps, 'company_id'));
    $ids = array_map('intval', array_column($opps, 'id'));
    $applied = [];
    if ($me) {
        foreach (rows('SELECT opp_id, status FROM opp_apps WHERE uid = ? AND opp_id IN (' . marks($ids) . ')', array_merge([$me], $ids)) as $a) $applied[(int) $a['opp_id']] = (string) $a['status'];
    }
    $out = [];
    foreach ($opps as $o) {
        $id = (int) $o['id'];
        $admin = $me && company_admin($me, (int) $o['company_id']);
        $out[] = [
            'id' => $id, 'title' => (string) $o['title'], 'body' => (string) $o['body'], 'skills' => jarr($o['skills']), 'mode' => (string) $o['mode'],
            'location' => (string) $o['location'], 'contract' => (string) $o['contract'], 'apply_kind' => (string) $o['apply_kind'],
            'apply_to' => (string) $o['apply_to'], 'status' => (string) $o['status'], 'created' => (int) $o['created'],
            'company' => $companies[(int) $o['company_id']] ?? null, 'applied' => $applied[$id] ?? '', 'admin' => $admin,
            'applications' => $admin ? (int) val('SELECT COUNT(*) FROM opp_apps WHERE opp_id = ?', [$id]) : null,
        ];
    }
    return $out;
}
/** GET opps : q, mode, contract, skill ; mine=1 : celles de mes entreprises ; applied=1 : mes candidatures. */
function api_opps(array $b, ?array $u): array
{
    $me = $u ? (int) $u['id'] : 0;
    $where = [];
    $args = [];
    if (!empty($b['mine']) && $me) {
        $where[] = "o.company_id IN (SELECT company_id FROM company_members WHERE uid = ? AND role IN ('owner', 'admin'))";
        $args[] = $me;
    } elseif (!empty($b['applied']) && $me) {
        $where[] = 'o.id IN (SELECT opp_id FROM opp_apps WHERE uid = ?)';
        $args[] = $me;
    } else {
        $where[] = "o.status = 'open'";
    }
    $term = line_clean($b['q'] ?? '', 60);
    if ($term !== '') { $where[] = "o.search LIKE ? ESCAPE '\\'"; $args[] = like($term); }
    if (isset(STM_WORK_MODES[$b['mode'] ?? ''])) { $where[] = 'o.mode = ?'; $args[] = $b['mode']; }
    if (isset(STM_CONTRACTS[$b['contract'] ?? ''])) { $where[] = 'o.contract = ?'; $args[] = $b['contract']; }
    $skill = line_clean($b['skill'] ?? '', 40);
    if ($skill !== '') { $where[] = "o.search LIKE ? ESCAPE '\\'"; $args[] = like($skill); }
    $opps = rows('SELECT o.* FROM opps o WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT 100', $args);
    return ['opps' => opp_views($opps, $u)];
}
function api_opp_get(array $b, ?array $u): array
{
    $o = row('SELECT * FROM opps WHERE id = ?', [int_in($b, 'id')]);
    if (!$o) not_found('This opportunity');
    $view = opp_views([$o], $u)[0];
    $out = ['opp' => $view];
    if ($view['admin']) {
        $apps = rows('SELECT * FROM opp_apps WHERE opp_id = ? ORDER BY id DESC', [$o['id']]);
        $people = [];
        foreach (people_view(array_column($apps, 'uid')) as $p) $people[$p['id']] = $p;
        $out['applications'] = array_map(function (array $a) use ($people): array {
            return ['id' => (int) $a['id'], 'message' => (string) $a['message'], 'status' => (string) $a['status'], 'created' => (int) $a['created'], 'user' => $people[(int) $a['uid']] ?? null];
        }, $apps);
    }
    return $out;
}
function api_opp_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $id = int_in($b, 'id');
    $cur = $id ? row('SELECT * FROM opps WHERE id = ?', [$id]) : null;
    if ($id && !$cur) not_found('This opportunity');
    $cid = $cur ? (int) $cur['company_id'] : int_in($b, 'company_id');
    if (!company_admin($uid, $cid)) forbidden('Opportunities are published by a company. Create your company page first.');
    $title = line_clean($b['title'] ?? '', 120);
    if ($title === '') fail('Give this opportunity a title.');
    $body = str_clean($b['body'] ?? '', 6000);
    if (mb_strlen($body) < 30) fail('Describe the opportunity in a few sentences.');
    $kind = in_array($b['apply_kind'] ?? '', ['inapp', 'url', 'email'], true) ? $b['apply_kind'] : 'inapp';
    $to = '';
    if ($kind === 'url') { $to = url_clean($b['apply_to'] ?? '', 400); if ($to === '') fail('Enter the link where people can apply.'); }
    if ($kind === 'email') { $to = strtolower(line_clean($b['apply_to'] ?? '', 190)); if (!valid_email($to)) fail('Enter the email address where people can apply.'); }
    $skills = list_clean($b['skills'] ?? [], 12);
    $set = [
        'title' => $title, 'body' => $body, 'skills' => jenc($skills), 'mode' => isset(STM_WORK_MODES[$b['mode'] ?? '']) ? $b['mode'] : 'remote',
        'location' => line_clean($b['location'] ?? '', 80), 'contract' => isset(STM_CONTRACTS[$b['contract'] ?? '']) ? $b['contract'] : 'freelance',
        'apply_kind' => $kind, 'apply_to' => $to, 'status' => ($b['status'] ?? 'open') === 'closed' ? 'closed' : 'open', 'updated' => time(),
    ];
    $set['search'] = norm(implode(' ', [$title, $body, implode(' ', $skills), $set['location'], (string) val('SELECT name FROM companies WHERE id = ?', [$cid])]));
    if ($id) {
        $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set)));
        q("UPDATE opps SET $sets WHERE id = ?", array_merge(array_values($set), [$id]));
    } else {
        rate('opp', 20, 3600, 'u' . $uid);
        $id = insert('opps', $set + ['company_id' => $cid, 'uid' => $uid, 'created' => time()]);
    }
    return ['id' => $id];
}
function api_opp_delete(array $b, array $u): array
{
    $o = row('SELECT * FROM opps WHERE id = ?', [int_in($b, 'id')]);
    if (!$o) return [];
    if (!company_admin((int) $u['id'], (int) $o['company_id'])) forbidden();
    q('DELETE FROM opps WHERE id = ?', [$o['id']]);
    return [];
}
function api_opp_apply(array $b, array $u): array
{
    $o = row("SELECT * FROM opps WHERE id = ? AND status = 'open'", [int_in($b, 'id')]);
    if (!$o) fail('This opportunity is closed.', 410, 'gone');
    $uid = (int) $u['id'];
    if ($o['apply_kind'] !== 'inapp') fail('Apply with the link or the email of this opportunity.');
    if (val('SELECT 1 FROM opp_apps WHERE opp_id = ? AND uid = ?', [$o['id'], $uid])) fail('You already applied to this opportunity.');
    rate('apply', 30, 3600, 'u' . $uid);
    $msg = str_clean($b['message'] ?? '', 2000);
    if (mb_strlen($msg) < 20) fail('Introduce yourself in a few sentences.');
    insert('opp_apps', ['opp_id' => $o['id'], 'uid' => $uid, 'message' => $msg, 'created' => time()]);
    foreach (rows("SELECT uid FROM company_members WHERE company_id = ? AND role IN ('owner', 'admin')", [$o['company_id']]) as $m) {
        notify((int) $m['uid'], $uid, 'opp', 'applied to “' . $o['title'] . '”', 'opportunity/' . $o['id'], true);
    }
    return [];
}
function api_opp_application(array $b, array $u): array
{
    $a = row('SELECT a.*, o.company_id, o.title FROM opp_apps a JOIN opps o ON o.id = a.opp_id WHERE a.id = ?', [int_in($b, 'id')]);
    if (!$a) not_found('This application');
    if (!company_admin((int) $u['id'], (int) $a['company_id'])) forbidden();
    $status = isset(APP_STATUSES[$b['status'] ?? '']) ? (string) $b['status'] : 'new';
    q('UPDATE opp_apps SET status = ? WHERE id = ?', [$status, $a['id']]);
    if ($status !== 'new' && $status !== $a['status']) {
        $text = ['shortlisted' => 'shortlisted your application to', 'declined' => 'did not select your application to', 'hired' => 'selected you for'][$status];
        notify((int) $a['uid'], (int) $u['id'], 'opp', $text . ' “' . $a['title'] . '”', 'opportunity/' . $a['opp_id'], true);
    }
    return [];
}

/* ---------------------------------------------------- annuaire des talents */

/**
 * GET talent : q, skill, platform, industry, location, language, availability, experience (années
 * minimum), portfolio=1, saved=1. Classement par pertinence aux filtres puis par activité récente :
 * pas de classement général qui comparerait des profils sans rapport entre eux.
 */
function api_talent(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $where = ['u.pro = 1', 'u.suspended = 0'];
    $args = [];
    foreach (['q' => 60, 'skill' => 40, 'industry' => 40, 'location' => 60, 'language' => 30] as $k => $max) {
        $v = line_clean($b[$k] ?? '', $max);
        if ($v === '') continue;
        foreach ($k === 'q' ? array_slice(preg_split('/\s+/', $v) ?: [], 0, 5) : [$v] as $word) {
            $where[] = "u.search LIKE ? ESCAPE '\\'";
            $args[] = like($word);
        }
    }
    if (isset(STM_PLATFORMS[$b['platform'] ?? ''])) { $where[] = 'u.platforms LIKE ?'; $args[] = '%"' . $b['platform'] . '"%'; }
    if (isset(STM_AVAILABILITY[$b['availability'] ?? ''])) { $where[] = 'u.availability = ?'; $args[] = $b['availability']; }
    $exp = int_in($b, 'experience');
    if ($exp > 0) { $where[] = 'u.experience >= ?'; $args[] = $exp; }
    if (!empty($b['portfolio'])) $where[] = "EXISTS (SELECT 1 FROM portfolio p WHERE p.uid = u.id AND p.status = 'published')";
    if (!empty($b['saved'])) { $where[] = 'EXISTS (SELECT 1 FROM saved_pros s WHERE s.uid = ? AND s.pro_uid = u.id)'; $args[] = $uid; }
    $page = max(0, int_in($b, 'page'));
    $list = rows("SELECT u.*, (SELECT COUNT(*) FROM portfolio p WHERE p.uid = u.id AND p.status = 'published') AS n_cases
        FROM users u WHERE " . implode(' AND ', $where) . "
        ORDER BY CASE WHEN u.title <> '' THEN 0 ELSE 1 END, CASE u.availability WHEN 'open' THEN 0 WHEN 'limited' THEN 1 ELSE 2 END, u.last_seen DESC
        LIMIT 25 OFFSET " . ($page * 24), $args);
    $more = count($list) > 24;
    $list = array_slice($list, 0, 24);
    $saved = array_map('intval', array_column(rows('SELECT pro_uid FROM saved_pros WHERE uid = ?', [$uid]), 'pro_uid'));
    return ['more' => $more, 'people' => array_map(function (array $t) use ($saved): array {
        return person_view($t) + ['cases' => (int) $t['n_cases'], 'proof' => proof_of_work($t), 'saved' => in_array((int) $t['id'], $saved, true)];
    }, $list)];
}
function api_talent_save(array $b, array $u): array
{
    $pro = int_in($b, 'uid');
    if (!user_by_id($pro) || $pro === (int) $u['id']) fail('This person no longer exists.');
    if (!empty($b['on'])) q('INSERT OR REPLACE INTO saved_pros (uid, company_id, pro_uid, note, created) VALUES (?, 0, ?, ?, ?)', [$u['id'], $pro, line_clean($b['note'] ?? '', 200), time()]);
    else q('DELETE FROM saved_pros WHERE uid = ? AND pro_uid = ?', [$u['id'], $pro]);
    return [];
}

/* ------------------------------------------------------------- messagerie */

function thread_view(array $t, int $me, array $users, array $last): array
{
    $other = (int) $t['a'] === $me ? (int) $t['b'] : (int) $t['a'];
    $read = (int) ((int) $t['a'] === $me ? $t['a_read'] : $t['b_read']);
    $l = $last[(int) $t['last_id']] ?? null;
    return [
        'id' => (int) $t['id'], 'user' => $users[$other] ?? null, 'last_at' => (int) $t['last_at'], 'unread' => (int) $t['last_id'] > $read,
        'last' => $l ? ['body' => mb_substr((string) $l['body'], 0, 120), 'mine' => (int) $l['uid'] === $me] : null,
    ];
}
function api_threads(array $b, array $u): array
{
    $me = (int) $u['id'];
    $list = rows('SELECT * FROM threads WHERE (a = ? OR b = ?) AND last_id > 0 ORDER BY last_at DESC LIMIT 100', [$me, $me]);
    $users = user_cards(array_merge(array_column($list, 'a'), array_column($list, 'b')));
    $last = [];
    $lids = array_map('intval', array_column($list, 'last_id'));
    if ($lids) {
        foreach (rows('SELECT id, uid, body FROM msgs WHERE id IN (' . marks($lids) . ')', $lids) as $m) $last[(int) $m['id']] = $m;
    }
    return ['threads' => array_map(function (array $t) use ($me, $users, $last): array { return thread_view($t, $me, $users, $last); }, $list)];
}
/** GET thread : with = identifiant de l'autre compte ; after = id du dernier message déjà affiché. */
function api_thread(array $b, array $u): array
{
    $me = (int) $u['id'];
    $other = row('SELECT * FROM users WHERE handle = ?', [strtolower(line_clean($b['with'] ?? '', 40))]);
    if (!$other || (int) $other['id'] === $me) not_found('This conversation');
    [$a, $c] = pair($me, (int) $other['id']);
    $t = row('SELECT * FROM threads WHERE a = ? AND b = ?', [$a, $c]);
    $msgs = [];
    if ($t) {
        $msgs = rows('SELECT * FROM msgs WHERE thread_id = ? AND id > ? ORDER BY id DESC LIMIT 200', [$t['id'], int_in($b, 'after')]);
        if ((int) $t['last_id'] > (int) ($a === $me ? $t['a_read'] : $t['b_read'])) {
            q('UPDATE threads SET ' . ($a === $me ? 'a_read' : 'b_read') . ' = ? WHERE id = ?', [$t['last_id'], $t['id']]);
        }
    }
    return ['user' => user_card($other), 'messages' => array_map(function (array $m) use ($me): array {
        return ['id' => (int) $m['id'], 'body' => (string) $m['body'], 'created' => (int) $m['created'], 'mine' => (int) $m['uid'] === $me];
    }, array_reverse($msgs))];
}
function api_message_send(array $b, array $u): array
{
    $me = (int) $u['id'];
    $other = int_in($b, 'uid');
    if ($other === $me || !user_by_id($other)) fail('This person no longer exists.');
    rate('message', 80, 600, 'u' . $me);
    $body = str_clean($b['body'] ?? '', 4000);
    if ($body === '') fail('Write a message first.');
    [$a, $c] = pair($me, $other);
    $id = tx(function () use ($a, $c, $me, $body): int {
        $tid = (int) (val('SELECT id FROM threads WHERE a = ? AND b = ?', [$a, $c]) ?? 0);
        if (!$tid) $tid = insert('threads', ['a' => $a, 'b' => $c]);
        $id = insert('msgs', ['thread_id' => $tid, 'uid' => $me, 'body' => $body, 'created' => time()]);
        q('UPDATE threads SET last_id = ?, last_at = ?, ' . ($a === $me ? 'a_read' : 'b_read') . ' = ? WHERE id = ?', [$id, time(), $id, $tid]);
        return $id;
    });
    return ['message' => ['id' => $id, 'body' => $body, 'created' => time(), 'mine' => true]];
}
