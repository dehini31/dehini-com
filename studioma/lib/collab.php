<?php
/**
 * Travail d'équipe : invitations (par email, à un compte ou par lien), membres et rôles d'un
 * projet ou d'un Studio, notifications.
 */
declare(strict_types=1);

function invite_url(string $token): string
{
    return app_url('join/' . $token);
}
function invite_view(array $i, array $users): array
{
    return [
        'id' => (int) $i['id'], 'token' => (string) $i['token'], 'url' => invite_url((string) $i['token']),
        'project_id' => (int) $i['project_id'], 'studio_id' => (int) $i['studio_id'], 'studio_name' => (string) ($i['studio_name'] ?? ''),
        'email' => (string) $i['email'], 'user' => $users[(int) $i['uid']] ?? null, 'role' => (string) $i['role'],
        'kind' => $i['email'] === '' && !(int) $i['uid'] ? 'link' : 'person', 'uses' => (int) $i['uses'],
        'by' => $users[(int) $i['by_uid']] ?? null, 'created' => (int) $i['created'], 'expires' => (int) $i['expires'],
    ];
}
/** Invitations en attente d'un projet ($studioId = 0 : toutes) ou d'un seul Studio. */
function invites_view(int $projectId, int $studioId): array
{
    $list = rows("SELECT i.*, s.name AS studio_name FROM invites i LEFT JOIN studios s ON s.id = i.studio_id
        WHERE i.project_id = ? AND i.status = 'pending' AND i.expires > ?" . ($studioId ? ' AND i.studio_id = ?' : '') . ' ORDER BY i.id DESC',
        $studioId ? [$projectId, time(), $studioId] : [$projectId, time()]);
    $users = user_cards(array_merge(array_column($list, 'uid'), array_column($list, 'by_uid')));
    return array_map(function (array $i) use ($users): array { return invite_view($i, $users); }, $list);
}
/**
 * Adresse par laquelle un compte retrouve les invitations envoyées par email : seulement si elle est
 * confirmée (par Google ou par un lien reçu à cette adresse), '' sinon. Les adresses ne sont pas
 * vérifiées à l'inscription : sans cela, quelqu'un pourrait créer un compte avec l'adresse d'une autre
 * personne et voir, puis accepter, les invitations qui lui sont envoyées. Le lien de l'email
 * d'invitation, lui, suffit toujours : le recevoir prouve l'accès à la boîte.
 */
function invite_email(array $u): string
{
    return (int) ($u['email_ok'] ?? 0) === 1 ? (string) $u['email'] : '';
}
/** L'invitation est-elle adressée à ce compte : à son id, ou à son adresse confirmée ? */
function invite_for(array $i, array $u): bool
{
    if ((int) $i['uid'] > 0 && (int) $i['uid'] === (int) $u['id']) return true;
    $email = invite_email($u);
    return $email !== '' && (string) $i['email'] === $email;
}
/** Invitations adressées au compte (à son id ou à son adresse confirmée), encore valables. */
function my_invites(array $u): array
{
    $list = rows("SELECT i.*, s.name AS studio_name, p.name AS project_name, p.color AS project_color FROM invites i JOIN projects p ON p.id = i.project_id
        LEFT JOIN studios s ON s.id = i.studio_id WHERE i.status = 'pending' AND i.expires > ? AND (i.uid = ? OR (i.email <> '' AND i.email = ?)) ORDER BY i.id DESC",
        [time(), $u['id'], invite_email($u)]);
    $users = user_cards(array_column($list, 'by_uid'));
    return array_map(function (array $i) use ($users): array {
        return ['token' => (string) $i['token'], 'project_name' => (string) $i['project_name'], 'project_color' => (string) $i['project_color'],
            'studio_name' => (string) ($i['studio_name'] ?? ''), 'role' => (string) $i['role'], 'message' => (string) $i['message'],
            'by' => $users[(int) $i['by_uid']] ?? null, 'created' => (int) $i['created']];
    }, $list);
}

/** Portée d'une invitation ou d'une gestion de membres : [projet, studio | null, rôle du compte]. */
function scope_load(array $b, array $u): array
{
    $sid = int_in($b, 'studio_id');
    if ($sid) {
        $s = load_studio($sid, $u, 'manage');
        return [$s['project'], $s, (string) $s['role']];
    }
    $p = load_project(int_in($b, 'project_id'), $u, 'manage');
    return [$p, null, (string) $p['role']];
}
function role_in($role, string $mine): string
{
    $role = is_string($role) ? $role : '';
    if (!isset(ROLES[$role])) fail('Choose a role.');
    if (role_rank($role) > role_rank($mine)) forbidden('You can\'t grant a role higher than your own.');
    return $role;
}

function api_invite_create(array $b, array $u): array
{
    [$p, $s, $mine] = scope_load($b, $u);
    $uid = (int) $u['id'];
    rate('invite', 60, 3600, 'u' . $uid);
    $role = role_in($b['role'] ?? '', $mine);
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    $target = int_in($b, 'uid');
    if ($email !== '') {
        if (!valid_email($email)) fail('Enter a valid email address.');
        // Rattachée au compte de cette adresse seulement si elle est confirmée : sinon ce compte a pu être
        // créé par quelqu'un d'autre. L'invitation reste alors adressée à l'email (son lien suffit) et
        // apparaît au compte une fois l'adresse confirmée.
        $target = (int) (val('SELECT id FROM users WHERE email = ? AND email_ok = 1', [$email]) ?? 0);
    } elseif ($target) {
        $t = user_by_id($target);
        if (!$t) fail('This person no longer exists.');
        $email = (string) $t['email'];
    }
    if ($target) {
        if ($target === $uid) fail('You are already a member.');
        $people = $s ? studio_people($s, $p) : [];
        $current = $s ? ($people[$target]['role'] ?? '') : project_role($target, $p);
        if ($current !== '' && role_rank($current) >= role_rank($role)) fail('This person is already a member with this role or a higher one.');
    }
    $where = $s ? $s['name'] . ' (' . $p['name'] . ')' : $p['name'];
    $message = str_clean($b['message'] ?? '', 600);
    $id = tx(function () use ($p, $s, $email, $target, $role, $uid, $message): int {
        // Une invitation réserve une place de membre dans la formule du compte propriétaire (lib/billing.php).
        $account = project_account($p);
        if (!$target || !in_array($target, account_seats($account), true)) {
            if ($email !== '') q("UPDATE invites SET status = 'revoked' WHERE project_id = ? AND studio_id = ? AND email = ? AND status = 'pending'", [$p['id'], $s ? $s['id'] : 0, $email]);
            need_seat($account, $uid, true);
        }
        // Une nouvelle invitation à la même personne remplace la précédente.
        if ($email !== '') q("UPDATE invites SET status = 'revoked' WHERE project_id = ? AND studio_id = ? AND email = ? AND status = 'pending'", [$p['id'], $s ? $s['id'] : 0, $email]);
        return insert('invites', ['token' => token(16), 'project_id' => $p['id'], 'studio_id' => $s ? $s['id'] : 0, 'email' => $email, 'uid' => $target,
            'role' => $role, 'by_uid' => $uid, 'message' => $message, 'created' => time(), 'expires' => time() + STM_INVITE_DAYS * 86400]);
    });
    $inv = row('SELECT i.*, s.name AS studio_name FROM invites i LEFT JOIN studios s ON s.id = i.studio_id WHERE i.id = ?', [$id]);
    if ($email !== '') {
        $lines = [$u['name'] . ' invites you to join ' . $where . ' on ' . STM_NAME . ' as ' . ROLE_LABELS[$role] . '.'];
        if ($message !== '') $lines[] = '“' . $message . '”';
        $lines[] = 'This invitation is valid for ' . STM_INVITE_DAYS . ' days.';
        mail_later($email, $u['name'] . ' invited you to ' . $where, 'You\'re invited to collaborate', $lines, 'Accept the invitation', invite_url((string) $inv['token']));
        if ($target) notify($target, $uid, 'invite', 'invited you to join ' . $where . ' as ' . ROLE_LABELS[$role], 'join/' . $inv['token']);
    }
    return ['invite' => invite_view($inv, user_cards([$target, $uid]))];
}
function api_invite_revoke(array $b, array $u): array
{
    $i = row('SELECT * FROM invites WHERE id = ?', [int_in($b, 'id')]);
    if (!$i) return [];
    scope_load(['studio_id' => (int) $i['studio_id'], 'project_id' => (int) $i['project_id']], $u);
    q("UPDATE invites SET status = 'revoked' WHERE id = ?", [$i['id']]);
    return [];
}
function invite_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    return row('SELECT * FROM invites WHERE token = ?', [$token]);
}
/** Ce que l'invitation propose (avant de l'accepter) — accessible sans compte. */
function api_invite_info(array $b, ?array $u): array
{
    rate('invite_info', 60, 600);
    $i = invite_by_token((string) ($b['token'] ?? ''));
    if (!$i) fail('This invitation link is not valid.', 404, 'notfound');
    $p = row('SELECT * FROM projects WHERE id = ?', [$i['project_id']]);
    $s = (int) $i['studio_id'] ? row('SELECT * FROM studios WHERE id = ?', [$i['studio_id']]) : null;
    $valid = $i['status'] === 'pending' && (int) $i['expires'] > time() && $p && (!(int) $i['studio_id'] || $s);
    $member = false;
    if ($u && $p) {
        $cur = $s ? studio_role((int) $u['id'], $s, $p) : project_role((int) $u['id'], $p);
        $member = $cur !== '' && role_rank($cur) >= role_rank((string) $i['role']);
    }
    return [
        'valid' => (bool) $valid, 'status' => $valid ? 'pending' : ($i['status'] === 'pending' ? 'expired' : (string) $i['status']),
        'project' => $p ? ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'color' => (string) $p['color'], 'logo' => (string) $p['logo']] : null,
        'studio' => $s ? ['id' => (int) $s['id'], 'name' => (string) $s['name']] : null,
        'role' => (string) $i['role'], 'role_label' => ROLE_LABELS[$i['role']] ?? '', 'message' => (string) $i['message'],
        'by' => user_cards([(int) $i['by_uid']])[(int) $i['by_uid']] ?? null, 'email' => (string) $i['email'], 'member' => $member,
        // Adressée au compte connecté (il peut alors aussi la refuser).
        'mine' => $u ? invite_for($i, $u) : false,
    ];
}
function api_invite_accept(array $b, array $u): array
{
    $i = invite_by_token((string) ($b['token'] ?? ''));
    if (!$i || $i['status'] !== 'pending' || (int) $i['expires'] <= time()) fail('This invitation is no longer valid. Ask for a new one.', 410, 'gone');
    $uid = (int) $u['id'];
    // Une invitation adressée à un compte précis ne peut être acceptée que par lui (ou par le compte dont
    // l'adresse, confirmée, est celle de l'invitation). Envoyée à un email sans compte : son lien suffit.
    if ((int) $i['uid'] && !invite_for($i, $u)) forbidden('This invitation was sent to someone else.');
    $p = row('SELECT * FROM projects WHERE id = ?', [$i['project_id']]);
    $s = (int) $i['studio_id'] ? row('SELECT * FROM studios WHERE id = ?', [$i['studio_id']]) : null;
    if (!$p || ((int) $i['studio_id'] && !$s)) fail('This project no longer exists.', 410, 'gone');
    $role = (string) $i['role'];
    tx(function () use ($i, $p, $s, $uid, $role, $u): void {
        // Rejoindre occupe une place dans la formule du compte propriétaire (sauf pour quelqu'un qui en a déjà une).
        $account = project_account($p);
        if (billing_enforced() && !in_array($uid, account_seats($account), true)) {
            if (!plan_has($account, 'team')) plan_fail('This workspace\'s plan doesn\'t include team members. Ask the person who invited you to upgrade to the Team plan.', '', 'team');
            $max = account_entitlements($account)['limits']['members'];
            if ($max !== null && count(account_seats($account)) >= $max) plan_fail('This team is full on its current plan (' . $max . ' members). Ask the person who invited you to make room or change plan.', '', 'members');
        }
        if ($s) {
            $cur = (string) (val('SELECT role FROM studio_members WHERE studio_id = ? AND uid = ?', [$s['id'], $uid]) ?? '');
            q('INSERT OR REPLACE INTO studio_members (studio_id, uid, role, created) VALUES (?, ?, ?, ?)', [$s['id'], $uid, role_max($cur, $role), time()]);
            log_event((int) $s['id'], 0, $uid, 'member', 'joined the Studio as ' . ROLE_LABELS[$role]);
            studio_touch((int) $s['id']);
        } else {
            $cur = (string) (val('SELECT role FROM project_members WHERE project_id = ? AND uid = ?', [$p['id'], $uid]) ?? '');
            q('INSERT OR REPLACE INTO project_members (project_id, uid, role, created) VALUES (?, ?, ?, ?)', [$p['id'], $uid, role_max($cur, $role), time()]);
            foreach (rows('SELECT id FROM studios WHERE project_id = ?', [$p['id']]) as $st) {
                log_event((int) $st['id'], 0, $uid, 'member', 'joined the project as ' . ROLE_LABELS[$role]);
                studio_touch((int) $st['id']);
            }
        }
        if ($i['email'] === '' && !(int) $i['uid']) q('UPDATE invites SET uses = uses + 1 WHERE id = ?', [$i['id']]);
        else q("UPDATE invites SET status = 'accepted', uid = ?, uses = 1 WHERE id = ?", [$uid, $i['id']]);
        billing_cache_clear();
    });
    notify((int) $i['by_uid'], $uid, 'invite', 'joined ' . ($s ? $s['name'] : $p['name']) . ' as ' . ROLE_LABELS[$role], $s ? 'studio/' . $s['id'] : 'project/' . $p['id']);
    return ['project_id' => (int) $p['id'], 'studio_id' => $s ? (int) $s['id'] : 0];
}
function api_invite_decline(array $b, array $u): array
{
    $i = invite_by_token((string) ($b['token'] ?? ''));
    if (!$i || $i['status'] !== 'pending') return [];
    if (!invite_for($i, $u)) return [];
    q("UPDATE invites SET status = 'declined' WHERE id = ?", [$i['id']]);
    notify((int) $i['by_uid'], (int) $u['id'], 'invite', 'declined your invitation', '');
    return [];
}

/* ---------------------------------------------------------------- membres */

/** Membres et invitations d'un Studio (fenêtre « Partager »). */
function api_members(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio_id'), $u);
    $out = ['members' => studio_people_view($s, $s['project']), 'roles' => ROLE_LABELS, 'role' => (string) $s['role'],
        'project_role' => project_role((int) $u['id'], $s['project'])];
    if (can($s['role'], 'manage')) $out['invites'] = invites_view((int) $s['project_id'], (int) $s['id']);
    return $out;
}
/** Nombre de propriétaires d'un projet (il en faut toujours un). */
function owners_count(int $projectId): int
{
    return (int) val("SELECT COUNT(*) FROM project_members WHERE project_id = ? AND role = 'owner'", [$projectId]);
}
function api_member_role(array $b, array $u): array
{
    [$p, $s, $mine] = scope_load($b, $u);
    $target = int_in($b, 'uid');
    $role = role_in($b['role'] ?? '', $mine);
    $table = $s ? 'studio_members' : 'project_members';
    $key = $s ? 'studio_id' : 'project_id';
    $sid = $s ? (int) $s['id'] : (int) $p['id'];
    $cur = (string) (val("SELECT role FROM $table WHERE $key = ? AND uid = ?", [$sid, $target]) ?? '');
    if ($cur === '') fail($s ? 'This person has access through the project: change their role from the project page.' : 'This person is not a member of the project.');
    if (role_rank($cur) > role_rank($mine)) forbidden('You can\'t change the role of someone with a higher role than yours.');
    if (!$s && $cur === 'owner' && $role !== 'owner' && owners_count((int) $p['id']) <= 1) fail('A project needs at least one owner. Name another owner first.');
    q("UPDATE $table SET role = ? WHERE $key = ? AND uid = ?", [$role, $sid, $target]);
    foreach ($s ? [$s] : rows('SELECT id FROM studios WHERE project_id = ?', [$p['id']]) as $st) studio_touch((int) $st['id']);
    if ($target !== (int) $u['id']) notify($target, (int) $u['id'], 'invite', 'changed your role in ' . ($s ? $s['name'] : $p['name']) . ' to ' . ROLE_LABELS[$role], $s ? 'studio/' . $s['id'] : 'project/' . $p['id']);
    return [];
}
/** Retire un membre — ou quitte soi-même le projet ou le Studio. */
function api_member_remove(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $target = int_in($b, 'uid') ?: $uid;
    $sidIn = int_in($b, 'studio_id');
    if ($target === $uid) {
        // Chacun peut partir, quel que soit son rôle.
        if ($sidIn) { $s = load_studio($sidIn, $u); $p = $s['project']; }
        else { $p = load_project(int_in($b, 'project_id'), $u); $s = null; }
        $mine = 'owner';
    } else {
        [$p, $s, $mine] = scope_load($b, $u);
    }
    $table = $s ? 'studio_members' : 'project_members';
    $key = $s ? 'studio_id' : 'project_id';
    $sid = $s ? (int) $s['id'] : (int) $p['id'];
    $cur = (string) (val("SELECT role FROM $table WHERE $key = ? AND uid = ?", [$sid, $target]) ?? '');
    if ($cur === '') fail($s ? 'This person has access through the project: remove them from the project page.' : 'This person is not a member of the project.');
    if (role_rank($cur) > role_rank($mine)) forbidden('You can\'t remove someone with a higher role than yours.');
    if (!$s && $cur === 'owner' && owners_count((int) $p['id']) <= 1) fail('A project needs at least one owner. Name another owner first.');
    tx(function () use ($table, $key, $sid, $target, $s, $p, $uid): void {
        q("DELETE FROM $table WHERE $key = ? AND uid = ?", [$sid, $target]);
        // Les cartes des Studios que la personne ne voit plus n'ont plus de responsable.
        $name = (string) val('SELECT name FROM users WHERE id = ?', [$target]);
        foreach ($s ? [$s] : rows('SELECT * FROM studios WHERE project_id = ?', [$p['id']]) as $st) {
            if (studio_role($target, $st, $p) === '') {
                q('UPDATE cards SET assignee = 0 WHERE studio_id = ? AND assignee = ?', [$st['id'], $target]);
                q('DELETE FROM card_members WHERE uid = ? AND card_id IN (SELECT id FROM cards WHERE studio_id = ?)', [$target, $st['id']]);
                q("UPDATE card_approvals SET status = 'cancelled', decided = ? WHERE approver_uid = ? AND status = 'pending' AND card_id IN (SELECT id FROM cards WHERE studio_id = ?)", [time(), $target, $st['id']]);
            }
            log_event((int) $st['id'], 0, $uid, 'member', $target === $uid ? 'left' : 'removed ' . $name);
            studio_touch((int) $st['id']);
        }
    });
    return [];
}

/* ---------------------------------------------------------- notifications */

function api_notifications(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $before = int_in($b, 'before') ?: PHP_INT_MAX;
    $list = rows('SELECT * FROM notifications WHERE uid = ? AND id < ? ORDER BY id DESC LIMIT 40', [$uid, $before]);
    $users = user_cards(array_column($list, 'actor'));
    return ['notifications' => array_map(function (array $n) use ($users): array {
        return ['id' => (int) $n['id'], 'kind' => (string) $n['kind'], 'text' => (string) $n['text'], 'link' => (string) $n['link'],
            'seen' => (bool) $n['seen'], 'created' => (int) $n['created'], 'actor' => $users[(int) $n['actor']] ?? null, 'guest' => (string) ($n['guest'] ?? '')];
    }, $list), 'counts' => me_counts($uid)];
}
function api_notifs_read(array $b, array $u): array
{
    $id = int_in($b, 'id');
    if ($id) q('UPDATE notifications SET seen = 1 WHERE id = ? AND uid = ?', [$id, $u['id']]);
    else q('UPDATE notifications SET seen = 1 WHERE uid = ? AND seen = 0', [$u['id']]);
    return ['counts' => me_counts((int) $u['id'])];
}
function api_counts(array $b, array $u): array
{
    return ['counts' => me_counts((int) $u['id'])];
}
