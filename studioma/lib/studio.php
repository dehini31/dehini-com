<?php
/**
 * Le Studio : projets, Studios, niveaux, colonnes et cartes.
 *
 *   Projet → Studios → Niveaux → Colonnes → Cartes
 *
 * Une carte garde le même id d'un bout à l'autre de son parcours. Quand elle arrive dans la colonne
 * finale d'un niveau, elle est transmise à la colonne principale du niveau suivant (passage de
 * relais) : c'est la même carte qui change de colonne, avec ses commentaires, ses pièces jointes,
 * son historique et ses responsables — jamais une copie.
 */
declare(strict_types=1);

/** Modèles de Studio. Colonne : [nom, rôle] — rôle : principal | final | done | cancelled | ''. */
const STUDIO_TEMPLATES = [
    'content' => ['label' => 'Content workflow', 'about' => 'Creation → Production → Publication, connected by smart handoffs.', 'levels' => [
        ['Creation', '✨', 'iris', [['💡 Ideas', 'principal'], ['✍️ Script in Progress', ''], ['✅ Script Approved', 'final']]],
        ['Production', '🎬', 'coral', [['✅ Script Approved', 'principal'], ['🎬 Video in Progress', ''], ['🎨 Design in Progress', ''], ['🚀 Ready to Publish', 'final']]],
        ['Publication', '📣', 'green', [['🚀 Ready to Publish', 'principal'], ['📅 Scheduled', ''], ['✔️ Published', 'done'], ['🚫 Cancelled', 'cancelled']]],
    ]],
    'kanban' => ['label' => 'Simple board', 'about' => 'One level: To do, In progress, Review, Done.', 'levels' => [
        ['Workflow', '⚡', 'blue', [['📥 To do', 'principal'], ['🛠️ In progress', ''], ['👀 Review', ''], ['✔️ Done', 'done']]],
    ]],
    'blank' => ['label' => 'Start from scratch', 'about' => 'An empty Studio. Add your own levels and columns.', 'levels' => []],
];

function int_in(array $b, string $k): int
{
    $v = $b[$k] ?? 0;
    return is_numeric($v) ? (int) $v : 0;
}
/** Marque le Studio comme modifié : les navigateurs ouverts dessus se resynchronisent. */
function studio_touch(int $studioId): void
{
    q('UPDATE studios SET rev = rev + 1, updated = ? WHERE id = ?', [time(), $studioId]);
}
/** Historique : $text complète la phrase « <Nom> … » (ex. « moved this card to “Scheduled” »). */
function log_event(int $studioId, int $cardId, int $uid, string $kind, string $text): void
{
    insert('events', ['studio_id' => $studioId, 'card_id' => $cardId, 'uid' => $uid, 'kind' => $kind, 'text' => mb_substr($text, 0, 400), 'created' => time()]);
}
function color_in($c, string $default = 'iris'): string
{
    return is_string($c) && in_array($c, STM_COLORS, true) ? $c : $default;
}

/* ---------------------------------------------------------------- projets */

function project_view(array $p, array $studios = []): array
{
    return [
        'id' => (int) $p['id'], 'name' => (string) $p['name'], 'description' => (string) $p['description'],
        'color' => (string) $p['color'], 'logo' => (string) $p['logo'], 'archived' => (bool) $p['archived'],
        'company_id' => (int) $p['company_id'], 'owner_uid' => (int) $p['owner_uid'],
        'role' => (string) ($p['role'] ?? ''), 'limited' => (bool) ($p['limited'] ?? false),
        'created' => (int) $p['created'], 'studios' => $studios,
    ];
}
/** Résumé de Studios (progression par niveau) : [studio_id => fiche]. */
function studios_summary(array $studios): array
{
    if (!$studios) return [];
    $ids = array_map(function (array $s): int { return (int) $s['id']; }, $studios);
    $in = marks($ids);
    $levels = [];
    foreach (rows("SELECT id, studio_id, name, icon, color FROM levels WHERE studio_id IN ($in) AND archived = 0 ORDER BY pos, id", $ids) as $l) {
        $levels[(int) $l['studio_id']][(int) $l['id']] = ['id' => (int) $l['id'], 'name' => (string) $l['name'], 'icon' => (string) $l['icon'], 'color' => (string) $l['color'], 'n' => 0];
    }
    $tot = [];
    foreach (rows("SELECT c.studio_id, co.level_id, co.kind, COUNT(*) AS n FROM cards c JOIN cols co ON co.id = c.col_id JOIN levels l ON l.id = co.level_id
        WHERE c.studio_id IN ($in) AND c.archived = 0 AND co.archived = 0 AND l.archived = 0 GROUP BY c.studio_id, co.level_id, co.kind", $ids) as $r) {
        $sid = (int) $r['studio_id'];
        $tot[$sid] = $tot[$sid] ?? ['cards' => 0, 'done' => 0, 'cancelled' => 0];
        $tot[$sid]['cards'] += (int) $r['n'];
        if ($r['kind'] === 'done') $tot[$sid]['done'] += (int) $r['n'];
        if ($r['kind'] === 'cancelled') $tot[$sid]['cancelled'] += (int) $r['n'];
        if (isset($levels[$sid][(int) $r['level_id']]) && $r['kind'] === '') $levels[$sid][(int) $r['level_id']]['n'] += (int) $r['n'];
    }
    $out = [];
    foreach ($studios as $s) {
        $sid = (int) $s['id'];
        $out[$sid] = [
            'id' => $sid, 'project_id' => (int) $s['project_id'], 'name' => (string) $s['name'], 'description' => (string) $s['description'],
            'archived' => (bool) $s['archived'], 'updated' => (int) $s['updated'],
            'levels' => array_values($levels[$sid] ?? []),
            'cards' => $tot[$sid]['cards'] ?? 0, 'done' => $tot[$sid]['done'] ?? 0, 'cancelled' => $tot[$sid]['cancelled'] ?? 0,
        ];
    }
    return $out;
}
/** Projets du compte (possédés et rejoints) avec leurs Studios accessibles. */
function my_projects(array $u, bool $withArchived = false): array
{
    $uid = (int) $u['id'];
    $projects = [];
    foreach (rows('SELECT p.* FROM projects p JOIN project_members m ON m.project_id = p.id WHERE m.uid = ?', [$uid]) as $p) $projects[(int) $p['id']] = $p + ['limited' => false];
    foreach (rows("SELECT p.* FROM projects p JOIN company_members cm ON cm.company_id = p.company_id WHERE p.company_id > 0 AND cm.uid = ? AND cm.role IN ('owner', 'admin')", [$uid]) as $p) {
        if (!isset($projects[(int) $p['id']])) $projects[(int) $p['id']] = $p + ['limited' => false];
    }
    $only = [];   // Studios auxquels le compte est invité sans être membre du projet
    foreach (rows('SELECT s.id AS sid, p.* FROM studio_members m JOIN studios s ON s.id = m.studio_id JOIN projects p ON p.id = s.project_id WHERE m.uid = ?', [$uid]) as $r) {
        $pid = (int) $r['id'];
        if (isset($projects[$pid]) && !$projects[$pid]['limited']) continue;
        $only[$pid][] = (int) $r['sid'];
        unset($r['sid']);
        $projects[$pid] = $r + ['limited' => true];
    }
    if (!$projects) return [];
    $studios = [];
    foreach (rows('SELECT * FROM studios WHERE project_id IN (' . marks(array_keys($projects)) . ') ORDER BY archived, created', array_keys($projects)) as $s) {
        $pid = (int) $s['project_id'];
        if ($projects[$pid]['limited'] && !in_array((int) $s['id'], $only[$pid] ?? [], true)) continue;
        if ((int) $s['archived'] && !$withArchived) continue;
        $studios[] = $s;
    }
    $sum = studios_summary($studios);
    $out = [];
    foreach ($projects as $pid => $p) {
        if ((int) $p['archived'] && !$withArchived) continue;
        $p['role'] = $p['limited'] ? '' : project_role($uid, $p);
        $list = [];
        foreach ($studios as $s) {
            if ((int) $s['project_id'] === $pid) $list[] = $sum[(int) $s['id']];
        }
        $out[] = project_view($p, $list);
    }
    usort($out, function (array $a, array $b): int { return [$a['archived'], -$a['created']] <=> [$b['archived'], -$b['created']]; });
    return $out;
}

function api_workspace(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $projects = my_projects($u, !empty($b['archived']));
    $companies = company_cards(array_map(function (array $p): int { return $p['company_id']; }, $projects));
    foreach ($projects as &$p) $p['company'] = $companies[$p['company_id']] ?? null;
    unset($p);
    return ['projects' => $projects, 'invites' => my_invites($u), 'tasks' => my_cards($uid, 12, true), 'templates' => templates_view()];
}
function templates_view(): array
{
    $out = [];
    foreach (STUDIO_TEMPLATES as $k => $t) {
        $out[] = ['key' => $k, 'label' => $t['label'], 'about' => $t['about'], 'levels' => array_map(function (array $l): array {
            return ['name' => $l[0], 'icon' => $l[1], 'color' => $l[2], 'cols' => array_column($l[3], 0)];
        }, $t['levels'])];
    }
    return $out;
}

function api_project_save(array $b, array $u): array
{
    $name = line_clean($b['name'] ?? '', 80);
    if ($name === '') fail('Give your project a name.');
    $data = ['name' => $name, 'description' => str_clean($b['description'] ?? '', 1000), 'color' => color_in($b['color'] ?? ''), 'updated' => time()];
    if (array_key_exists('logo', $b)) $data['logo'] = own_image($b['logo'], (int) $u['id']);
    $id = int_in($b, 'id');
    if ($id) {
        load_project($id, $u, 'manage');
        $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($data)));
        q("UPDATE projects SET $sets WHERE id = ?", array_merge(array_values($data), [$id]));
        return ['id' => $id];
    }
    $cid = int_in($b, 'company_id');
    if ($cid && !company_admin((int) $u['id'], $cid)) forbidden('Only admins of this company can create its projects.');
    return tx(function () use ($data, $cid, $u, $b): array {
        $id = insert('projects', $data + ['owner_uid' => $u['id'], 'company_id' => $cid, 'created' => time()]);
        insert('project_members', ['project_id' => $id, 'uid' => $u['id'], 'role' => 'owner', 'created' => time()]);
        $out = ['id' => $id];
        // Création en un geste : le premier Studio est créé avec le projet.
        $tpl = (string) ($b['template'] ?? '');
        if (isset(STUDIO_TEMPLATES[$tpl])) $out['studio_id'] = studio_create($id, line_clean($b['studio_name'] ?? '', 80) ?: 'Content Studio', $tpl, (int) $u['id']);
        return $out;
    });
}
function api_project_get(array $b, array $u): array
{
    $p = load_project(int_in($b, 'id'), $u);
    $uid = (int) $u['id'];
    $studios = rows('SELECT * FROM studios WHERE project_id = ? ORDER BY archived, created', [$p['id']]);
    if ($p['limited']) {
        $mine = array_column(rows('SELECT studio_id FROM studio_members WHERE uid = ?', [$uid]), 'studio_id');
        $studios = array_values(array_filter($studios, function (array $s) use ($mine): bool { return in_array($s['id'], $mine); }));
    }
    $view = project_view($p, array_values(studios_summary($studios)));
    $view['company'] = company_cards([(int) $p['company_id']])[(int) $p['company_id']] ?? null;
    $out = ['project' => $view];
    if (!$p['limited']) {
        $members = [];
        $cards = user_cards(array_column(rows('SELECT uid FROM project_members WHERE project_id = ?', [$p['id']]), 'uid'));
        foreach (rows('SELECT uid, role FROM project_members WHERE project_id = ?', [$p['id']]) as $m) {
            if (isset($cards[(int) $m['uid']])) $members[] = $cards[(int) $m['uid']] + ['role' => (string) $m['role'], 'via' => 'project'];
        }
        usort($members, function (array $a, array $b): int { return [-role_rank($a['role']), $a['name']] <=> [-role_rank($b['role']), $b['name']]; });
        $out['members'] = $members;
        if (can($p['role'], 'manage')) $out['invites'] = invites_view((int) $p['id'], 0);
    }
    return $out;
}
function api_project_archive(array $b, array $u): array
{
    $p = load_project(int_in($b, 'id'), $u, 'manage');
    q('UPDATE projects SET archived = ?, updated = ? WHERE id = ?', [empty($b['archived']) ? 0 : 1, time(), $p['id']]);
    return [];
}
function api_project_delete(array $b, array $u): array
{
    $p = load_project(int_in($b, 'id'), $u, 'own');
    if (line_clean($b['confirm'] ?? '', 80) !== $p['name']) fail('Type the project name to confirm the deletion.');
    tx(function () use ($p): void {
        foreach (rows('SELECT id FROM studios WHERE project_id = ?', [$p['id']]) as $s) studio_destroy((int) $s['id']);
        q('DELETE FROM projects WHERE id = ?', [$p['id']]);
    });
    return [];
}

/* ---------------------------------------------------------------- studios */

function studio_create(int $projectId, string $name, string $tpl, int $uid): int
{
    $now = time();
    $sid = insert('studios', ['project_id' => $projectId, 'name' => $name, 'created_by' => $uid, 'created' => $now, 'updated' => $now]);
    foreach (STUDIO_TEMPLATES[$tpl]['levels'] ?? [] as $i => $l) {
        $lid = insert('levels', ['studio_id' => $sid, 'name' => $l[0], 'icon' => $l[1], 'color' => $l[2], 'pos' => $i]);
        foreach ($l[3] as $j => $c) {
            $cid = insert('cols', ['studio_id' => $sid, 'level_id' => $lid, 'name' => $c[0], 'pos' => $j,
                'principal' => $c[1] === 'principal' ? 1 : 0, 'kind' => in_array($c[1], ['done', 'cancelled'], true) ? $c[1] : '']);
            if ($c[1] === 'final') q('UPDATE levels SET handoff_col = ? WHERE id = ?', [$cid, $lid]);
        }
    }
    log_event($sid, 0, $uid, 'studio', 'created this Studio');
    return $sid;
}
/** Supprime un Studio et tout son contenu (fichiers compris). */
function studio_destroy(int $sid): void
{
    foreach (rows('SELECT * FROM files WHERE studio_id = ?', [$sid]) as $f) file_delete($f);
    q('DELETE FROM cards WHERE studio_id = ?', [$sid]);
    q('DELETE FROM studios WHERE id = ?', [$sid]);
}
function api_studio_create(array $b, array $u): array
{
    $p = load_project(int_in($b, 'project_id'), $u, 'manage');
    $name = line_clean($b['name'] ?? '', 80);
    if ($name === '') fail('Give your Studio a name.');
    $tpl = (string) ($b['template'] ?? 'content');
    if (!isset(STUDIO_TEMPLATES[$tpl])) $tpl = 'content';
    $sid = tx(function () use ($p, $name, $tpl, $u, $b): int {
        $sid = studio_create((int) $p['id'], $name, $tpl, (int) $u['id']);
        q('UPDATE studios SET description = ? WHERE id = ?', [str_clean($b['description'] ?? '', 600), $sid]);
        return $sid;
    });
    return ['id' => $sid];
}
function api_studio_save(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'id'), $u, 'manage');
    $name = line_clean($b['name'] ?? $s['name'], 80);
    if ($name === '') fail('Give your Studio a name.');
    q('UPDATE studios SET name = ?, description = ? WHERE id = ?', [$name, str_clean($b['description'] ?? $s['description'], 600), $s['id']]);
    if ($name !== $s['name']) log_event((int) $s['id'], 0, (int) $u['id'], 'studio', 'renamed the Studio to “' . $name . '”');
    studio_touch((int) $s['id']);
    return [];
}
function api_studio_archive(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'id'), $u, 'manage');
    $on = empty($b['archived']) ? 0 : 1;
    q('UPDATE studios SET archived = ? WHERE id = ?', [$on, $s['id']]);
    log_event((int) $s['id'], 0, (int) $u['id'], 'studio', $on ? 'archived the Studio' : 'restored the Studio');
    studio_touch((int) $s['id']);
    return [];
}
function api_studio_delete(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'id'), $u, 'manage');
    if (!can(project_role((int) $u['id'], $s['project']), 'manage')) forbidden('Only project managers can delete a Studio.');
    if (line_clean($b['confirm'] ?? '', 80) !== $s['name']) fail('Type the Studio name to confirm the deletion.');
    tx(function () use ($s): void { studio_destroy((int) $s['id']); });
    return [];
}

/* --------------------------------------------------------------- tableau */

function level_view(array $l): array
{
    return [
        'id' => (int) $l['id'], 'name' => (string) $l['name'], 'icon' => (string) $l['icon'], 'color' => (string) $l['color'],
        'pos' => (int) $l['pos'], 'handoff' => (string) $l['handoff'], 'handoff_col' => (int) $l['handoff_col'],
        'handoff_level' => (int) $l['handoff_level'], 'archived' => (bool) $l['archived'],
    ];
}
function col_view(array $c): array
{
    return [
        'id' => (int) $c['id'], 'level_id' => (int) $c['level_id'], 'name' => (string) $c['name'], 'pos' => (int) $c['pos'],
        'principal' => (bool) $c['principal'], 'kind' => (string) $c['kind'], 'passed' => (int) $c['passed'], 'archived' => (bool) $c['archived'],
    ];
}
/** Niveaux actifs d'un Studio, dans l'ordre. */
function studio_levels(int $sid): array
{
    return rows('SELECT * FROM levels WHERE studio_id = ? AND archived = 0 ORDER BY pos, id', [$sid]);
}
/** Niveau qui reçoit les cartes d'un niveau : celui choisi, sinon le suivant ; null s'il n'y en a pas. */
function level_target(array $level): ?array
{
    $levels = studio_levels((int) $level['studio_id']);
    if ((int) $level['handoff_level'] > 0) {
        foreach ($levels as $l) {
            if ((int) $l['id'] === (int) $level['handoff_level'] && (int) $l['id'] !== (int) $level['id']) return $l;
        }
    }
    foreach ($levels as $i => $l) {
        if ((int) $l['id'] === (int) $level['id']) return $levels[$i + 1] ?? null;
    }
    return null;
}
/** Colonne principale d'un niveau (à défaut, sa première colonne). */
function level_principal(int $levelId): ?array
{
    return row('SELECT * FROM cols WHERE level_id = ? AND archived = 0 ORDER BY principal DESC, pos, id LIMIT 1', [$levelId]);
}

const CARD_COUNTS = "(SELECT COUNT(*) FROM card_comments x WHERE x.card_id = c.id) AS n_comments,
    (SELECT COUNT(*) FROM card_files x WHERE x.card_id = c.id) AS n_files,
    (SELECT COUNT(*) FROM card_checks x WHERE x.card_id = c.id) AS n_checks,
    (SELECT COUNT(*) FROM card_checks x WHERE x.card_id = c.id AND x.done = 1) AS n_done,
    (SELECT f.token FROM card_files x JOIN files f ON f.id = x.file_id WHERE x.card_id = c.id AND f.kind = 'image' ORDER BY x.id LIMIT 1) AS cover";

/** Carte telle qu'affichée sur le tableau. $members : [card_id => [uid…]]. */
function card_lite(array $c, array $members = []): array
{
    return [
        'id' => (int) $c['id'], 'studio_id' => (int) $c['studio_id'], 'col_id' => (int) $c['col_id'], 'pos' => (int) $c['pos'],
        'title' => (string) $c['title'], 'assignee' => (int) $c['assignee'], 'members' => $members[(int) $c['id']] ?? [],
        'due' => (string) $c['due'], 'priority' => (int) $c['priority'], 'tags' => jarr($c['tags']), 'platforms' => jarr($c['platforms']),
        'ctype' => (string) $c['ctype'], 'publish_at' => (string) $c['publish_at'], 'publish_url' => (string) $c['publish_url'],
        'approval' => (string) $c['approval'], 'archived' => (bool) $c['archived'], 'updated' => (int) $c['updated'],
        'done_at' => (int) $c['done_at'], 'created_by' => (int) $c['created_by'],
        'n_comments' => (int) ($c['n_comments'] ?? 0), 'n_files' => (int) ($c['n_files'] ?? 0),
        'n_checks' => (int) ($c['n_checks'] ?? 0), 'n_done' => (int) ($c['n_done'] ?? 0), 'cover' => (string) ($c['cover'] ?? ''),
    ];
}
function card_members_map(array $cardIds): array
{
    if (!$cardIds) return [];
    $out = [];
    foreach (rows('SELECT card_id, uid FROM card_members WHERE card_id IN (' . marks($cardIds) . ')', $cardIds) as $m) $out[(int) $m['card_id']][] = (int) $m['uid'];
    return $out;
}

function board_data(array $s, array $u): array
{
    $sid = (int) $s['id'];
    $levels = array_map('level_view', studio_levels($sid));
    $cols = array_map('col_view', rows('SELECT co.* FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.studio_id = ? AND co.archived = 0 AND l.archived = 0 ORDER BY co.pos, co.id', [$sid]));
    $cards = rows('SELECT c.*, ' . CARD_COUNTS . ' FROM cards c JOIN cols co ON co.id = c.col_id JOIN levels l ON l.id = co.level_id
        WHERE c.studio_id = ? AND c.archived = 0 AND co.archived = 0 AND l.archived = 0 ORDER BY c.pos, c.id', [$sid]);
    $members = card_members_map(array_column($cards, 'id'));
    $p = $s['project'];
    $role = (string) $s['role'];
    return [
        'studio' => [
            'id' => $sid, 'name' => (string) $s['name'], 'description' => (string) $s['description'], 'rev' => (int) $s['rev'],
            'archived' => (bool) $s['archived'], 'role' => $role,
            'can' => ['comment' => can($role, 'comment'), 'approve' => can($role, 'approve'), 'work' => can($role, 'work'),
                'edit' => can($role, 'edit'), 'manage' => can($role, 'manage'), 'own' => can($role, 'own')],
            'project' => ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'color' => (string) $p['color'], 'logo' => (string) $p['logo']],
        ],
        'levels' => $levels, 'cols' => $cols,
        'cards' => array_map(function (array $c) use ($members): array { return card_lite($c, $members); }, $cards),
        'members' => studio_people_view($s, $p),
    ];
}
function api_board(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u);
    // Synchronisation : si le navigateur a déjà cette version, on ne renvoie que les pastilles du menu.
    $counts = me_counts((int) $u['id']);
    if (isset($b['rev']) && (int) $b['rev'] === (int) $s['rev']) return ['rev' => (int) $s['rev'], 'same' => true, 'counts' => $counts];
    return ['rev' => (int) $s['rev'], 'counts' => $counts] + board_data($s, $u);
}

/* ---------------------------------------------------------------- niveaux */

function load_level(int $id, array $u): array
{
    $l = row('SELECT * FROM levels WHERE id = ?', [$id]);
    if (!$l) not_found('This level');
    $l['studio'] = load_studio((int) $l['studio_id'], $u, 'manage');
    return $l;
}
/** Renumérote une liste (niveaux d'un Studio, colonnes d'un niveau) en plaçant $id à l'index voulu. */
function reorder(string $table, string $scopeCol, int $scopeId, int $id, int $index): void
{
    if (!in_array($table . '.' . $scopeCol, ['levels.studio_id', 'cols.level_id'], true)) throw new InvalidArgumentException('table');
    $ids = array_map('intval', array_column(rows("SELECT id FROM $table WHERE $scopeCol = ? AND archived = 0 AND id <> ? ORDER BY pos, id", [$scopeId, $id]), 'id'));
    array_splice($ids, max(0, min($index, count($ids))), 0, [$id]);
    foreach ($ids as $i => $x) q("UPDATE $table SET pos = ? WHERE id = ?", [$i, $x]);
}
function api_level_save(array $b, array $u): array
{
    $id = int_in($b, 'id');
    $name = line_clean($b['name'] ?? '', 60);
    if ($id) {
        $l = load_level($id, $u);
        $sid = (int) $l['studio_id'];
        $set = [];
        if (isset($b['name'])) { if ($name === '') fail('Give this level a name.'); $set['name'] = $name; }
        if (isset($b['icon'])) $set['icon'] = mb_substr(line_clean($b['icon'], 8), 0, 4);
        if (isset($b['color'])) $set['color'] = color_in($b['color']);
        if (isset($b['handoff'])) $set['handoff'] = in_array($b['handoff'], ['auto', 'manual', 'off'], true) ? $b['handoff'] : 'auto';
        if (isset($b['handoff_col'])) {
            $cid = int_in($b, 'handoff_col');
            if ($cid && !val('SELECT 1 FROM cols WHERE id = ? AND level_id = ? AND archived = 0', [$cid, $id])) fail('Choose a column of this level.');
            $set['handoff_col'] = $cid;
        }
        if (isset($b['handoff_level'])) {
            $lid = int_in($b, 'handoff_level');
            if ($lid && ($lid === $id || !val('SELECT 1 FROM levels WHERE id = ? AND studio_id = ? AND archived = 0', [$lid, $sid]))) fail('Choose another level of this Studio.');
            $set['handoff_level'] = $lid;
        }
        if ($set) {
            $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set)));
            q("UPDATE levels SET $sets WHERE id = ?", array_merge(array_values($set), [$id]));
            if (isset($set['name']) && $set['name'] !== $l['name']) log_event($sid, 0, (int) $u['id'], 'level', 'renamed the level “' . $l['name'] . '” to “' . $set['name'] . '”');
            if (isset($set['handoff']) && $set['handoff'] !== $l['handoff']) log_event($sid, 0, (int) $u['id'], 'level', 'set the handoff of “' . $l['name'] . '” to ' . $set['handoff']);
        }
        studio_touch($sid);
        return ['id' => $id];
    }
    $s = load_studio(int_in($b, 'studio_id'), $u, 'manage');
    if ($name === '') fail('Give this level a name.');
    $id = tx(function () use ($s, $name, $b, $u): int {
        $pos = (int) val('SELECT COALESCE(MAX(pos), -1) + 1 FROM levels WHERE studio_id = ?', [$s['id']]);
        $n = (int) val('SELECT COUNT(*) FROM levels WHERE studio_id = ?', [$s['id']]);
        $id = insert('levels', ['studio_id' => $s['id'], 'name' => $name, 'icon' => mb_substr(line_clean($b['icon'] ?? '', 8), 0, 4),
            'color' => color_in($b['color'] ?? '', STM_COLORS[$n % count(STM_COLORS)]), 'pos' => $pos]);
        // Un niveau naît avec sa colonne principale : on peut y créer une carte tout de suite.
        insert('cols', ['studio_id' => $s['id'], 'level_id' => $id, 'name' => 'New column', 'pos' => 0, 'principal' => 1]);
        log_event((int) $s['id'], 0, (int) $u['id'], 'level', 'added the level “' . $name . '”');
        studio_touch((int) $s['id']);
        return $id;
    });
    return ['id' => $id];
}
function api_level_move(array $b, array $u): array
{
    $l = load_level(int_in($b, 'id'), $u);
    tx(function () use ($l, $b): void {
        reorder('levels', 'studio_id', (int) $l['studio_id'], (int) $l['id'], int_in($b, 'index'));
        studio_touch((int) $l['studio_id']);
    });
    return [];
}
function api_level_duplicate(array $b, array $u): array
{
    $l = load_level(int_in($b, 'id'), $u);
    $id = tx(function () use ($l, $u): int {
        $id = insert('levels', ['studio_id' => $l['studio_id'], 'name' => mb_substr($l['name'] . ' (copy)', 0, 60), 'icon' => $l['icon'], 'color' => $l['color'],
            'pos' => 0, 'handoff' => $l['handoff'], 'handoff_level' => $l['handoff_level']]);
        foreach (rows('SELECT * FROM cols WHERE level_id = ? AND archived = 0 ORDER BY pos, id', [$l['id']]) as $c) {
            $cid = insert('cols', ['studio_id' => $l['studio_id'], 'level_id' => $id, 'name' => $c['name'], 'pos' => $c['pos'], 'principal' => $c['principal'], 'kind' => $c['kind']]);
            if ((int) $c['id'] === (int) $l['handoff_col']) q('UPDATE levels SET handoff_col = ? WHERE id = ?', [$cid, $id]);
        }
        reorder('levels', 'studio_id', (int) $l['studio_id'], $id, (int) val('SELECT COUNT(*) FROM levels WHERE studio_id = ? AND archived = 0 AND pos <= ? AND id <> ?', [$l['studio_id'], $l['pos'], $id]));
        log_event((int) $l['studio_id'], 0, (int) $u['id'], 'level', 'duplicated the level “' . $l['name'] . '”');
        studio_touch((int) $l['studio_id']);
        return $id;
    });
    return ['id' => $id];
}
function api_level_archive(array $b, array $u): array
{
    $l = load_level(int_in($b, 'id'), $u);
    $on = empty($b['archived']) ? 0 : 1;
    q('UPDATE levels SET archived = ? WHERE id = ?', [$on, $l['id']]);
    if (!$on) q('UPDATE levels SET pos = (SELECT COALESCE(MAX(pos), -1) + 1 FROM levels WHERE studio_id = ? AND archived = 0 AND id <> ?) WHERE id = ?', [$l['studio_id'], $l['id'], $l['id']]);
    log_event((int) $l['studio_id'], 0, (int) $u['id'], 'level', ($on ? 'archived' : 'restored') . ' the level “' . $l['name'] . '”');
    studio_touch((int) $l['studio_id']);
    return [];
}
/** Déplace toutes les cartes d'une ou plusieurs colonnes à la fin d'une autre (suppression d'une colonne ou d'un niveau). */
function cards_relocate(array $fromCols, array $to): void
{
    $pos = (int) val('SELECT COALESCE(MAX(pos), -1) + 1 FROM cards WHERE col_id = ?', [$to['id']]);
    foreach (rows('SELECT id FROM cards WHERE col_id IN (' . marks($fromCols) . ') ORDER BY pos, id', $fromCols) as $c) {
        q('UPDATE cards SET col_id = ?, pos = ?, done_at = ? WHERE id = ?', [$to['id'], $pos++, $to['kind'] === 'done' ? time() : 0, $c['id']]);
    }
}
function api_level_delete(array $b, array $u): array
{
    $l = load_level(int_in($b, 'id'), $u);
    $sid = (int) $l['studio_id'];
    $cols = array_map('intval', array_column(rows('SELECT id FROM cols WHERE level_id = ?', [$l['id']]), 'id'));
    $n = $cols ? (int) val('SELECT COUNT(*) FROM cards WHERE col_id IN (' . marks($cols) . ')', $cols) : 0;
    $to = null;
    if ($n) {
        $to = row('SELECT * FROM cols WHERE id = ? AND studio_id = ? AND level_id <> ?', [int_in($b, 'move_to'), $sid, $l['id']]);
        if (!$to) out(['error' => 'not_empty', 'message' => 'This level still contains ' . $n . ' card' . ($n > 1 ? 's' : '') . '. Choose where to move them, or archive the level instead.', 'cards' => $n], 409);
    }
    tx(function () use ($l, $sid, $cols, $to, $u): void {
        if ($to) cards_relocate($cols, $to);
        q('DELETE FROM levels WHERE id = ?', [$l['id']]);
        q('UPDATE levels SET handoff_level = 0 WHERE studio_id = ? AND handoff_level = ?', [$sid, $l['id']]);
        log_event($sid, 0, (int) $u['id'], 'level', 'deleted the level “' . $l['name'] . '”');
        studio_touch($sid);
    });
    return [];
}

/* --------------------------------------------------------------- colonnes */

function load_col(int $id, array $u, string $cap = 'manage'): array
{
    $c = row('SELECT * FROM cols WHERE id = ?', [$id]);
    if (!$c) not_found('This column');
    $c['studio'] = load_studio((int) $c['studio_id'], $u, $cap);
    return $c;
}
/** Un niveau garde toujours une colonne principale tant qu'il a des colonnes. */
function level_fix_principal(int $levelId): void
{
    if (val('SELECT 1 FROM cols WHERE level_id = ? AND archived = 0 AND principal = 1', [$levelId])) return;
    $first = val('SELECT id FROM cols WHERE level_id = ? AND archived = 0 ORDER BY pos, id LIMIT 1', [$levelId]);
    if ($first) q('UPDATE cols SET principal = 1 WHERE id = ?', [$first]);
}
function api_column_save(array $b, array $u): array
{
    $id = int_in($b, 'id');
    $name = line_clean($b['name'] ?? '', 60);
    if ($id) {
        $c = load_col($id, $u);
        $set = [];
        if (isset($b['name'])) { if ($name === '') fail('Give this column a name.'); $set['name'] = $name; }
        if (isset($b['kind'])) $set['kind'] = in_array($b['kind'], ['done', 'cancelled'], true) ? $b['kind'] : '';
        if ($set) {
            $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set)));
            q("UPDATE cols SET $sets WHERE id = ?", array_merge(array_values($set), [$id]));
            if (isset($set['kind']) && $set['kind'] !== $c['kind']) q('UPDATE cards SET done_at = ? WHERE col_id = ?', [$set['kind'] === 'done' ? time() : 0, $id]);
            if (isset($set['name']) && $set['name'] !== $c['name']) log_event((int) $c['studio_id'], 0, (int) $u['id'], 'column', 'renamed the column “' . $c['name'] . '” to “' . $set['name'] . '”');
        }
        studio_touch((int) $c['studio_id']);
        return ['id' => $id];
    }
    $l = load_level(int_in($b, 'level_id'), $u);
    if ($name === '') fail('Give this column a name.');
    $id = tx(function () use ($l, $name, $u): int {
        $pos = (int) val('SELECT COALESCE(MAX(pos), -1) + 1 FROM cols WHERE level_id = ?', [$l['id']]);
        $id = insert('cols', ['studio_id' => $l['studio_id'], 'level_id' => $l['id'], 'name' => $name, 'pos' => $pos]);
        level_fix_principal((int) $l['id']);
        log_event((int) $l['studio_id'], 0, (int) $u['id'], 'column', 'added the column “' . $name . '” to ' . $l['name']);
        studio_touch((int) $l['studio_id']);
        return $id;
    });
    return ['id' => $id];
}
/** Réordonne une colonne dans son niveau, ou la déplace (avec ses cartes) vers un autre niveau. */
function api_column_move(array $b, array $u): array
{
    $c = load_col(int_in($b, 'id'), $u);
    $sid = (int) $c['studio_id'];
    $to = int_in($b, 'level_id') ?: (int) $c['level_id'];
    if (!val('SELECT 1 FROM levels WHERE id = ? AND studio_id = ? AND archived = 0', [$to, $sid])) fail('Choose a level of this Studio.');
    tx(function () use ($c, $to, $sid, $b, $u): void {
        $from = (int) $c['level_id'];
        if ($to !== $from) {
            q('UPDATE cols SET level_id = ?, principal = 0 WHERE id = ?', [$to, $c['id']]);
            q('UPDATE levels SET handoff_col = 0 WHERE id = ? AND handoff_col = ?', [$from, $c['id']]);
            $names = [];
            foreach ([$from, $to] as $lid) $names[] = (string) val('SELECT name FROM levels WHERE id = ?', [$lid]);
            log_event($sid, 0, (int) $u['id'], 'column', 'moved the column “' . $c['name'] . '” from ' . $names[0] . ' to ' . $names[1]);
        }
        reorder('cols', 'level_id', $to, (int) $c['id'], int_in($b, 'index'));
        level_fix_principal($from);
        level_fix_principal($to);
        studio_touch($sid);
    });
    return [];
}
function api_column_principal(array $b, array $u): array
{
    $c = load_col(int_in($b, 'id'), $u);
    tx(function () use ($c, $u): void {
        q('UPDATE cols SET principal = 0 WHERE level_id = ?', [$c['level_id']]);
        q('UPDATE cols SET principal = 1 WHERE id = ?', [$c['id']]);
        log_event((int) $c['studio_id'], 0, (int) $u['id'], 'column', 'made “' . $c['name'] . '” the principal column of its level');
        studio_touch((int) $c['studio_id']);
    });
    return [];
}
/** Désigne (ou retire) la colonne finale d'un niveau : celle d'où les cartes passent au niveau suivant. */
function api_column_final(array $b, array $u): array
{
    $c = load_col(int_in($b, 'id'), $u);
    $on = !empty($b['on']);
    q('UPDATE levels SET handoff_col = ? WHERE id = ?', [$on ? $c['id'] : 0, $c['level_id']]);
    if (!$on) q('UPDATE levels SET handoff_col = 0 WHERE id = ? AND handoff_col = ?', [$c['level_id'], $c['id']]);
    log_event((int) $c['studio_id'], 0, (int) $u['id'], 'column', $on ? 'made “' . $c['name'] . '” the final column of its level' : 'removed the handoff from “' . $c['name'] . '”');
    studio_touch((int) $c['studio_id']);
    return [];
}
function api_column_duplicate(array $b, array $u): array
{
    $c = load_col(int_in($b, 'id'), $u);
    $id = tx(function () use ($c, $u): int {
        $id = insert('cols', ['studio_id' => $c['studio_id'], 'level_id' => $c['level_id'], 'name' => mb_substr($c['name'] . ' (copy)', 0, 60), 'pos' => 0, 'kind' => $c['kind']]);
        reorder('cols', 'level_id', (int) $c['level_id'], $id, (int) val('SELECT COUNT(*) FROM cols WHERE level_id = ? AND archived = 0 AND pos <= ? AND id <> ?', [$c['level_id'], $c['pos'], $id]));
        log_event((int) $c['studio_id'], 0, (int) $u['id'], 'column', 'duplicated the column “' . $c['name'] . '”');
        studio_touch((int) $c['studio_id']);
        return $id;
    });
    return ['id' => $id];
}
function api_column_archive(array $b, array $u): array
{
    $c = load_col(int_in($b, 'id'), $u);
    $on = empty($b['archived']) ? 0 : 1;
    tx(function () use ($c, $on, $u): void {
        q('UPDATE cols SET archived = ?, principal = CASE WHEN ? = 1 THEN 0 ELSE principal END WHERE id = ?', [$on, $on, $c['id']]);
        if ($on) q('UPDATE levels SET handoff_col = 0 WHERE id = ? AND handoff_col = ?', [$c['level_id'], $c['id']]);
        else q('UPDATE cols SET pos = (SELECT COALESCE(MAX(pos), -1) + 1 FROM cols WHERE level_id = ? AND archived = 0 AND id <> ?) WHERE id = ?', [$c['level_id'], $c['id'], $c['id']]);
        level_fix_principal((int) $c['level_id']);
        log_event((int) $c['studio_id'], 0, (int) $u['id'], 'column', ($on ? 'archived' : 'restored') . ' the column “' . $c['name'] . '”');
        studio_touch((int) $c['studio_id']);
    });
    return [];
}
function api_column_delete(array $b, array $u): array
{
    $c = load_col(int_in($b, 'id'), $u);
    $sid = (int) $c['studio_id'];
    $n = (int) val('SELECT COUNT(*) FROM cards WHERE col_id = ?', [$c['id']]);
    $to = null;
    if ($n) {
        $to = row('SELECT * FROM cols WHERE id = ? AND studio_id = ? AND id <> ?', [int_in($b, 'move_to'), $sid, $c['id']]);
        if (!$to) out(['error' => 'not_empty', 'message' => 'This column still contains ' . $n . ' card' . ($n > 1 ? 's' : '') . '. Choose where to move them, or archive the column instead.', 'cards' => $n], 409);
    }
    tx(function () use ($c, $sid, $to, $u): void {
        if ($to) cards_relocate([(int) $c['id']], $to);
        q('DELETE FROM cols WHERE id = ?', [$c['id']]);
        q('UPDATE levels SET handoff_col = 0 WHERE id = ? AND handoff_col = ?', [$c['level_id'], $c['id']]);
        level_fix_principal((int) $c['level_id']);
        log_event($sid, 0, (int) $u['id'], 'column', 'deleted the column “' . $c['name'] . '”');
        studio_touch($sid);
    });
    return [];
}

/* ----------------------------------------------------------------- cartes */

/** Place la carte dans une colonne, à l'index voulu, et renumérote la colonne. */
function card_place(int $cardId, array $col, int $index): void
{
    $ids = array_map('intval', array_column(rows('SELECT id FROM cards WHERE col_id = ? AND archived = 0 AND id <> ? ORDER BY pos, id', [$col['id'], $cardId]), 'id'));
    array_splice($ids, max(0, min($index, count($ids))), 0, [$cardId]);
    $was = (int) val('SELECT done_at FROM cards WHERE id = ?', [$cardId]);
    q('UPDATE cards SET col_id = ?, updated = ?, done_at = ? WHERE id = ?', [$col['id'], time(), $col['kind'] === 'done' ? ($was ?: time()) : 0, $cardId]);
    foreach ($ids as $i => $id) q('UPDATE cards SET pos = ? WHERE id = ?', [$i, $id]);
}
/** Personnes à prévenir pour une carte (responsable, collaborateurs, créateur), sauf $except. */
function card_watchers(array $card, int $except = 0): array
{
    $ids = array_map('intval', array_column(rows('SELECT uid FROM card_members WHERE card_id = ?', [$card['id']]), 'uid'));
    $ids[] = (int) $card['assignee'];
    $ids[] = (int) $card['created_by'];
    return array_values(array_diff(array_unique(array_filter($ids)), [$except]));
}
function card_link(array $card): string
{
    return 'studio/' . (int) $card['studio_id'] . '/card/' . (int) $card['id'];
}
/**
 * Passage de relais : la carte quitte la colonne finale de son niveau pour la colonne principale
 * du niveau suivant. Retourne ['level' => nom, 'col' => nom] ou null s'il n'y a pas de destination.
 */
function card_handoff(array $card, array $level, int $uid): ?array
{
    $target = level_target($level);
    $col = $target ? level_principal((int) $target['id']) : null;
    if (!$col) return null;
    $from = (string) val('SELECT name FROM cols WHERE id = ?', [$card['col_id']]);
    q('UPDATE cols SET passed = passed + 1 WHERE id = ?', [$card['col_id']]);
    card_place((int) $card['id'], $col, 0);
    // La validation concernait l'étape précédente : la carte repart sans demande en cours.
    q("UPDATE card_approvals SET status = 'cancelled', decided = ? WHERE card_id = ? AND status = 'pending'", [time(), $card['id']]);
    q("UPDATE cards SET approval = '' WHERE id = ?", [$card['id']]);
    log_event((int) $card['studio_id'], (int) $card['id'], $uid, 'handoff', 'handed this card off from ' . $level['name'] . ' › ' . $from . ' to ' . $target['name'] . ' › ' . $col['name']);
    foreach (card_watchers($card, $uid) as $w) notify($w, $uid, 'handoff', 'handed “' . $card['title'] . '” off to ' . $target['name'], card_link($card));
    return ['level' => (string) $target['name'], 'level_id' => (int) $target['id'], 'col' => (string) $col['name'], 'col_id' => (int) $col['id']];
}
/**
 * Déplace une carte vers une colonne ; applique le passage de relais automatique si la colonne
 * est la colonne finale de son niveau. À appeler dans une transaction.
 */
function card_move(array $card, array $col, int $index, int $uid, bool $handoff = true): ?array
{
    $changed = (int) $card['col_id'] !== (int) $col['id'];
    card_place((int) $card['id'], $col, $index);
    $level = row('SELECT * FROM levels WHERE id = ?', [$col['level_id']]);
    if ($changed) {
        $old = row('SELECT co.name, co.level_id, l.name AS level FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.id = ?', [$card['col_id']]);
        $crossed = $old && (int) $old['level_id'] !== (int) $col['level_id'];
        log_event((int) $card['studio_id'], (int) $card['id'], $uid, 'move', 'moved this card from ' . ($crossed ? $old['level'] . ' › ' : '') . '“' . ($old['name'] ?? '?') . '” to ' . ($crossed ? $level['name'] . ' › ' : '') . '“' . $col['name'] . '”');
        if ($crossed) {
            q("UPDATE card_approvals SET status = 'cancelled', decided = ? WHERE card_id = ? AND status = 'pending'", [time(), $card['id']]);
            q("UPDATE cards SET approval = '' WHERE id = ?", [$card['id']]);
        }
    }
    $card['col_id'] = (int) $col['id'];
    if ($handoff && $changed && $level && $level['handoff'] === 'auto' && (int) $level['handoff_col'] === (int) $col['id']) {
        return card_handoff($card, $level, $uid);
    }
    return null;
}
/** Colonne active d'un Studio (ni archivée, ni dans un niveau archivé), ou échec. */
function col_in_studio(int $colId, int $studioId): array
{
    $col = row('SELECT co.* FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.id = ? AND co.studio_id = ? AND co.archived = 0 AND l.archived = 0', [$colId, $studioId]);
    if (!$col) fail('This column no longer exists. Refresh the board.', 409, 'stale');
    return $col;
}

function api_card_create(array $b, array $u): array
{
    $col = load_col(int_in($b, 'col_id'), $u, 'work');
    $col = col_in_studio((int) $col['id'], (int) $col['studio_id']) + ['studio' => $col['studio']];
    $title = line_clean($b['title'] ?? '', 200);
    if ($title === '') fail('Give this card a title.');
    $uid = (int) $u['id'];
    $id = tx(function () use ($col, $title, $uid, $b): int {
        $now = time();
        $id = insert('cards', ['studio_id' => $col['studio_id'], 'col_id' => $col['id'], 'pos' => 0, 'title' => $title, 'created_by' => $uid, 'created' => $now, 'updated' => $now]);
        card_place($id, $col, empty($b['top']) ? PHP_INT_MAX : 0);
        // Une personne qui ne modifie que ses propres cartes devient responsable de celle qu'elle crée.
        if (!can($col['studio']['role'], 'edit')) q('UPDATE cards SET assignee = ? WHERE id = ?', [$uid, $id]);
        log_event((int) $col['studio_id'], $id, $uid, 'create', 'created this card in “' . $col['name'] . '”');
        studio_touch((int) $col['studio_id']);
        return $id;
    });
    $c = row('SELECT c.*, ' . CARD_COUNTS . ' FROM cards c WHERE c.id = ?', [$id]);
    return ['card' => card_lite($c)];
}
function api_card_move(array $b, array $u): array
{
    $card = load_card(int_in($b, 'id'), $u, 'cardedit');
    $col = col_in_studio(int_in($b, 'col_id'), (int) $card['studio_id']);
    $res = tx(function () use ($card, $col, $b, $u): ?array {
        $r = card_move($card, $col, int_in($b, 'index'), (int) $u['id']);
        studio_touch((int) $card['studio_id']);
        return $r;
    });
    return ['handoff' => $res];
}
/** Envoi manuel au niveau suivant, depuis la colonne finale d'un niveau. */
function api_card_handoff(array $b, array $u): array
{
    $card = load_card(int_in($b, 'id'), $u, 'cardedit');
    $level = row('SELECT l.* FROM levels l JOIN cols co ON co.level_id = l.id WHERE co.id = ?', [$card['col_id']]);
    if (!$level || $level['handoff'] === 'off' || (int) $level['handoff_col'] !== (int) $card['col_id']) fail('This card is not in the final column of its level.');
    $res = tx(function () use ($card, $level, $u): ?array {
        $r = card_handoff($card, $level, (int) $u['id']);
        studio_touch((int) $card['studio_id']);
        return $r;
    });
    if (!$res) fail('There is no next level to hand this card off to.');
    return ['handoff' => $res];
}

const METRIC_KEYS = ['views', 'reach', 'likes', 'comments', 'shares', 'saves', 'clicks'];

function api_card_save(array $b, array $u): array
{
    $card = load_card(int_in($b, 'id'), $u, 'cardedit');
    $s = $card['studio'];
    $sid = (int) $s['id'];
    $uid = (int) $u['id'];
    $cid = (int) $card['id'];
    $set = [];
    $log = [];
    $people = null;
    $member = function (int $who) use (&$people, $s): bool {
        if ($people === null) $people = studio_people($s, $s['project']);
        return isset($people[$who]);
    };
    if (isset($b['title'])) {
        $t = line_clean($b['title'], 200);
        if ($t === '') fail('Give this card a title.');
        if ($t !== $card['title']) { $set['title'] = $t; $log[] = ['edit', 'renamed this card to “' . $t . '”']; }
    }
    if (isset($b['body'])) {
        $body = rich_clean($b['body']);
        if ($body !== $card['body']) { $set['body'] = $body; $log[] = ['edit', 'updated the description']; }
    }
    if (isset($b['assignee'])) {
        $a = int_in($b, 'assignee');
        if ($a && !$member($a)) fail('This person is not a member of the Studio.');
        if ($a !== (int) $card['assignee']) {
            // Seuls ceux qui gèrent toutes les cartes peuvent en changer le responsable.
            if (!can($s['role'], 'edit')) forbidden('Only creators and managers can reassign a card.');
            $set['assignee'] = $a;
            $name = $a ? (string) val('SELECT name FROM users WHERE id = ?', [$a]) : '';
            $log[] = ['assign', $a ? 'assigned this card to ' . $name : 'removed the assignee'];
            if ($a) notify($a, $uid, 'assigned', 'assigned “' . ($set['title'] ?? $card['title']) . '” to you', card_link($card), true);
        }
    }
    if (isset($b['due'])) {
        $d = date_clean($b['due']);
        if ($d !== $card['due']) { $set['due'] = $d; $log[] = ['due', $d ? 'set the due date to ' . $d : 'removed the due date']; }
    }
    if (isset($b['priority'])) {
        $p = max(0, min(4, int_in($b, 'priority')));
        if ($p !== (int) $card['priority']) { $set['priority'] = $p; $log[] = ['edit', 'set the priority to ' . STM_PRIORITIES[$p]]; }
    }
    if (isset($b['tags'])) { $v = jenc(list_clean($b['tags'], 12, 30)); if ($v !== $card['tags']) { $set['tags'] = $v; $log[] = ['edit', 'updated the tags']; } }
    if (isset($b['platforms'])) { $v = jenc(keys_clean($b['platforms'], STM_PLATFORMS)); if ($v !== $card['platforms']) { $set['platforms'] = $v; $log[] = ['edit', 'updated the platforms']; } }
    if (isset($b['ctype'])) {
        $v = isset(STM_CONTENT_TYPES[$b['ctype']]) ? (string) $b['ctype'] : '';
        if ($v !== $card['ctype']) { $set['ctype'] = $v; $log[] = ['edit', $v ? 'set the content type to ' . STM_CONTENT_TYPES[$v] : 'removed the content type']; }
    }
    if (isset($b['publish_at'])) {
        $v = datetime_clean($b['publish_at']);
        if ($v !== $card['publish_at']) { $set['publish_at'] = $v; $log[] = ['due', $v ? 'set the publication date to ' . str_replace('T', ' at ', $v) : 'removed the publication date']; }
    }
    if (isset($b['publish_url'])) {
        $v = url_clean($b['publish_url'], 500);
        if ($b['publish_url'] !== '' && $v === '') fail('Enter a valid link (https://…).');
        if ($v !== $card['publish_url']) { $set['publish_url'] = $v; $log[] = ['publish', $v ? 'added the publication link' : 'removed the publication link']; }
    }
    if (isset($b['metrics']) && is_array($b['metrics'])) {
        $m = [];
        foreach (METRIC_KEYS as $k) {
            if (isset($b['metrics'][$k]) && is_numeric($b['metrics'][$k]) && (int) $b['metrics'][$k] > 0) $m[$k] = min(2000000000, (int) $b['metrics'][$k]);
        }
        $v = jenc((object) $m);
        if ($v !== $card['metrics'] && !($m === [] && jarr($card['metrics']) === [])) { $set['metrics'] = $v; $log[] = ['edit', 'updated the performance metrics']; }
    }
    tx(function () use ($b, $card, $cid, $sid, $uid, $set, $log, $member, $s): void {
        if (isset($b['members']) && is_array($b['members'])) {
            $new = array_values(array_unique(array_filter(array_map('intval', $b['members']), $member)));
            $old = array_map('intval', array_column(rows('SELECT uid FROM card_members WHERE card_id = ?', [$cid]), 'uid'));
            if (array_diff($new, $old) || array_diff($old, $new)) {
                if (!can($s['role'], 'edit')) forbidden('Only creators and managers can change the collaborators of a card.');
                q('DELETE FROM card_members WHERE card_id = ?', [$cid]);
                foreach ($new as $m) q('INSERT OR IGNORE INTO card_members (card_id, uid) VALUES (?, ?)', [$cid, $m]);
                foreach (array_diff($new, $old) as $m) notify($m, $uid, 'assigned', 'added you as a collaborator on “' . $card['title'] . '”', card_link($card), true);
                log_event($sid, $cid, $uid, 'assign', 'updated the collaborators');
            }
        }
        if ($set) {
            $set['updated'] = time();
            $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set)));
            q("UPDATE cards SET $sets WHERE id = ?", array_merge(array_values($set), [$cid]));
            foreach ($log as $l) log_event($sid, $cid, $uid, $l[0], $l[1]);
        }
        studio_touch($sid);
    });
    return ['card' => card_full($cid, $u, $s)];
}
function api_card_get(array $b, array $u): array
{
    $card = load_card(int_in($b, 'id'), $u);
    return ['card' => card_full((int) $card['id'], $u, $card['studio'])];
}
function api_card_duplicate(array $b, array $u): array
{
    $card = load_card(int_in($b, 'id'), $u, 'cardedit');
    $col = col_in_studio((int) $card['col_id'], (int) $card['studio_id']);
    $uid = (int) $u['id'];
    $id = tx(function () use ($card, $col, $uid): int {
        $now = time();
        $id = insert('cards', ['studio_id' => $card['studio_id'], 'col_id' => $col['id'], 'pos' => 0, 'title' => mb_substr($card['title'] . ' (copy)', 0, 200),
            'body' => $card['body'], 'assignee' => $card['assignee'], 'due' => $card['due'], 'priority' => $card['priority'], 'tags' => $card['tags'],
            'platforms' => $card['platforms'], 'ctype' => $card['ctype'], 'created_by' => $uid, 'created' => $now, 'updated' => $now]);
        foreach (rows('SELECT text, pos FROM card_checks WHERE card_id = ? ORDER BY pos, id', [$card['id']]) as $ck) insert('card_checks', ['card_id' => $id, 'text' => $ck['text'], 'pos' => $ck['pos']]);
        foreach (rows('SELECT uid FROM card_members WHERE card_id = ?', [$card['id']]) as $m) q('INSERT OR IGNORE INTO card_members (card_id, uid) VALUES (?, ?)', [$id, $m['uid']]);
        card_place($id, $col, (int) val('SELECT COUNT(*) FROM cards WHERE col_id = ? AND archived = 0 AND pos <= ? AND id <> ?', [$col['id'], $card['pos'], $id]));
        log_event((int) $card['studio_id'], $id, $uid, 'create', 'created this card as a copy of “' . $card['title'] . '”');
        studio_touch((int) $card['studio_id']);
        return $id;
    });
    return ['id' => $id];
}
function card_archive(array $card, bool $on, int $uid): void
{
    q('UPDATE cards SET archived = ?, updated = ? WHERE id = ?', [$on ? 1 : 0, time(), $card['id']]);
    if (!$on) {
        // Restauration : dans sa colonne si elle existe encore, sinon dans la première colonne du Studio.
        $col = row('SELECT co.* FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.id = ? AND co.archived = 0 AND l.archived = 0', [$card['col_id']])
            ?? row('SELECT co.* FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.studio_id = ? AND co.archived = 0 AND l.archived = 0 ORDER BY l.pos, co.principal DESC, co.pos LIMIT 1', [$card['studio_id']]);
        if (!$col) fail('Add a column to this Studio before restoring a card.');
        card_place((int) $card['id'], $col, PHP_INT_MAX);
    }
    log_event((int) $card['studio_id'], (int) $card['id'], $uid, 'archive', $on ? 'archived this card' : 'restored this card');
}
function card_destroy(array $card): void
{
    foreach (rows('SELECT f.* FROM card_files cf JOIN files f ON f.id = cf.file_id WHERE cf.card_id = ?', [$card['id']]) as $f) file_delete($f);
    q('DELETE FROM events WHERE card_id = ?', [$card['id']]);
    q('DELETE FROM cards WHERE id = ?', [$card['id']]);
}
function api_card_archive(array $b, array $u): array
{
    $card = load_card(int_in($b, 'id'), $u, 'cardedit');
    tx(function () use ($card, $b, $u): void {
        card_archive($card, !empty($b['archived']), (int) $u['id']);
        studio_touch((int) $card['studio_id']);
    });
    return [];
}
/** Suppression définitive : réservée aux managers, et au créateur de la carte s'il gère les cartes. */
function card_can_delete(array $card, string $role, int $uid): bool
{
    return can($role, 'manage') || (can($role, 'edit') && (int) $card['created_by'] === $uid);
}
function api_card_delete(array $b, array $u): array
{
    $card = load_card(int_in($b, 'id'), $u);
    if (!card_can_delete($card, $card['studio']['role'], (int) $u['id'])) forbidden('Only managers can delete a card for good. You can archive it instead.');
    tx(function () use ($card, $u): void {
        card_destroy($card);
        log_event((int) $card['studio_id'], 0, (int) $u['id'], 'archive', 'deleted the card “' . $card['title'] . '”');
        studio_touch((int) $card['studio_id']);
    });
    return [];
}
/** Action sur plusieurs cartes : move | archive | delete | assign | priority. */
function api_cards_bulk(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u, 'edit');
    $sid = (int) $s['id'];
    $uid = (int) $u['id'];
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($b['ids'] ?? null) ? $b['ids'] : [])))), 0, 200);
    if (!$ids) fail('Select at least one card.');
    $cards = rows('SELECT * FROM cards WHERE studio_id = ? AND id IN (' . marks($ids) . ') ORDER BY pos, id', array_merge([$sid], $ids));
    $op = (string) ($b['op'] ?? '');
    $handed = 0;
    tx(function () use ($op, $cards, $b, $s, $sid, $uid, &$handed): void {
        switch ($op) {
            case 'move':
                $col = col_in_studio(int_in($b, 'col_id'), $sid);
                foreach ($cards as $c) {
                    if (card_move($c, $col, PHP_INT_MAX, $uid)) $handed++;
                }
                break;
            case 'archive':
                foreach ($cards as $c) card_archive($c, true, $uid);
                break;
            case 'delete':
                foreach ($cards as $c) {
                    if (!card_can_delete($c, $s['role'], $uid)) forbidden('Only managers can delete cards for good. You can archive them instead.');
                    card_destroy($c);
                }
                log_event($sid, 0, $uid, 'archive', 'deleted ' . count($cards) . ' card' . (count($cards) > 1 ? 's' : ''));
                break;
            case 'assign':
                $a = int_in($b, 'assignee');
                if ($a && !isset(studio_people($s, $s['project'])[$a])) fail('This person is not a member of the Studio.');
                $name = $a ? (string) val('SELECT name FROM users WHERE id = ?', [$a]) : '';
                foreach ($cards as $c) {
                    if ((int) $c['assignee'] === $a) continue;
                    q('UPDATE cards SET assignee = ?, updated = ? WHERE id = ?', [$a, time(), $c['id']]);
                    log_event($sid, (int) $c['id'], $uid, 'assign', $a ? 'assigned this card to ' . $name : 'removed the assignee');
                }
                if ($a && $cards) notify($a, $uid, 'assigned', 'assigned ' . count($cards) . ' card' . (count($cards) > 1 ? 's' : '') . ' to you in ' . $s['name'], 'studio/' . $sid, true);
                break;
            case 'priority':
                $p = max(0, min(4, int_in($b, 'priority')));
                foreach ($cards as $c) q('UPDATE cards SET priority = ?, updated = ? WHERE id = ?', [$p, time(), $c['id']]);
                break;
            default:
                fail('Unknown action.');
        }
        studio_touch($sid);
    });
    return ['count' => count($cards), 'handed' => $handed];
}

/** Éléments archivés d'un Studio (cartes, colonnes, niveaux), pour les restaurer ou les supprimer. */
function api_studio_archived(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u);
    $sid = (int) $s['id'];
    $cards = rows('SELECT c.*, co.name AS col_name FROM cards c LEFT JOIN cols co ON co.id = c.col_id WHERE c.studio_id = ? AND c.archived = 1 ORDER BY c.updated DESC LIMIT 300', [$sid]);
    return [
        'cards' => array_map(function (array $c): array { return card_lite($c) + ['col_name' => (string) ($c['col_name'] ?? '')]; }, $cards),
        'cols' => array_map(function (array $c): array {
            return col_view($c) + ['level_name' => (string) $c['level_name'], 'cards' => (int) $c['n']];
        }, rows('SELECT co.*, l.name AS level_name, (SELECT COUNT(*) FROM cards x WHERE x.col_id = co.id) AS n FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.studio_id = ? AND co.archived = 1 AND l.archived = 0', [$sid])),
        'levels' => array_map(function (array $l): array {
            return level_view($l) + ['cards' => (int) $l['n']];
        }, rows('SELECT l.*, (SELECT COUNT(*) FROM cards x JOIN cols co ON co.id = x.col_id WHERE co.level_id = l.id) AS n FROM levels l WHERE l.studio_id = ? AND l.archived = 1', [$sid])),
    ];
}

/** Historique du Studio (structure, membres, cartes), du plus récent au plus ancien. */
function api_studio_activity(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u);
    $before = int_in($b, 'before') ?: PHP_INT_MAX;
    $evs = rows('SELECT e.*, c.title AS card_title FROM events e LEFT JOIN cards c ON c.id = e.card_id WHERE e.studio_id = ? AND e.id < ? ORDER BY e.id DESC LIMIT 50', [$s['id'], $before]);
    $users = user_cards(array_column($evs, 'uid'));
    return ['events' => array_map(function (array $e) use ($users): array {
        return ['id' => (int) $e['id'], 'card_id' => (int) $e['card_id'], 'card_title' => (string) ($e['card_title'] ?? ''), 'kind' => (string) $e['kind'],
            'text' => (string) $e['text'], 'created' => (int) $e['created'], 'user' => $users[(int) $e['uid']] ?? null];
    }, $evs)];
}

/**
 * Cartes confiées au compte (responsable ou collaborateur) dans tous ses Studios.
 * $open : seulement celles qui ne sont ni terminées ni annulées.
 */
function my_cards(int $uid, int $limit = 500, bool $open = false): array
{
    $studios = my_studio_ids($uid);
    if (!$studios) return [];
    $cards = rows('SELECT c.*, ' . CARD_COUNTS . ', co.name AS col_name, co.kind AS col_kind, l.name AS level_name, l.color AS level_color, l.icon AS level_icon,
            s.name AS studio_name, p.id AS project_id, p.name AS project_name, p.color AS project_color
        FROM cards c JOIN cols co ON co.id = c.col_id JOIN levels l ON l.id = co.level_id JOIN studios s ON s.id = c.studio_id JOIN projects p ON p.id = s.project_id
        WHERE c.studio_id IN (' . marks($studios) . ') AND c.archived = 0 AND co.archived = 0 AND l.archived = 0
            AND (c.assignee = ? OR EXISTS (SELECT 1 FROM card_members m WHERE m.card_id = c.id AND m.uid = ?))' . ($open ? " AND co.kind = ''" : '') . "
        ORDER BY CASE WHEN c.due = '' THEN 1 ELSE 0 END, c.due, c.priority DESC, c.updated DESC LIMIT " . (int) $limit, array_merge($studios, [$uid, $uid]));
    $members = card_members_map(array_column($cards, 'id'));
    return array_map(function (array $c) use ($members): array {
        return card_lite($c, $members) + [
            'col_name' => (string) $c['col_name'], 'col_kind' => (string) $c['col_kind'], 'level_name' => (string) $c['level_name'],
            'level_color' => (string) $c['level_color'], 'level_icon' => (string) $c['level_icon'], 'studio_name' => (string) $c['studio_name'],
            'project_id' => (int) $c['project_id'], 'project_name' => (string) $c['project_name'], 'project_color' => (string) $c['project_color'],
        ];
    }, $cards);
}
function api_mywork(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $cards = my_cards($uid);
    // Validations qui attendent la décision du compte.
    $approvals = rows("SELECT a.id, a.card_id, a.note, a.created, a.by_uid, c.title, c.studio_id, s.name AS studio_name FROM card_approvals a
        JOIN cards c ON c.id = a.card_id JOIN studios s ON s.id = c.studio_id WHERE a.approver_uid = ? AND a.status = 'pending' AND c.archived = 0 ORDER BY a.id DESC", [$uid]);
    $mine = my_studio_ids($uid);
    $approvals = array_values(array_filter($approvals, function (array $a) use ($mine): bool { return in_array((int) $a['studio_id'], $mine, true); }));
    $users = user_cards(array_merge(array_column($approvals, 'by_uid'), array_column($cards, 'assignee')));
    return [
        'cards' => $cards,
        'approvals' => array_map(function (array $a) use ($users): array {
            return ['id' => (int) $a['id'], 'card_id' => (int) $a['card_id'], 'title' => (string) $a['title'], 'studio_id' => (int) $a['studio_id'],
                'studio_name' => (string) $a['studio_name'], 'note' => (string) $a['note'], 'created' => (int) $a['created'], 'by' => $users[(int) $a['by_uid']] ?? null];
        }, $approvals),
        'users' => array_values($users),
    ];
}

/**
 * Tableau de bord d'un Studio : avancement, rythme de livraison, respect des échéances, répartition.
 * Réservé aux membres ; la charge par personne n'est visible que des managers.
 */
function api_studio_stats(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u);
    $sid = (int) $s['id'];
    $cards = rows('SELECT c.*, co.kind AS col_kind, co.level_id FROM cards c JOIN cols co ON co.id = c.col_id JOIN levels l ON l.id = co.level_id
        WHERE c.studio_id = ? AND c.archived = 0 AND co.archived = 0 AND l.archived = 0', [$sid]);
    $today = date('Y-m-d');
    $tot = ['cards' => count($cards), 'open' => 0, 'done' => 0, 'cancelled' => 0, 'overdue' => 0, 'ontime' => 0, 'late' => 0, 'pending' => 0];
    $weeks = [];
    for ($i = 7; $i >= 0; $i--) $weeks[date('o-\WW', strtotime("-$i week"))] = ['week' => date('M j', strtotime('monday this week', strtotime("-$i week"))), 'done' => 0, 'created' => 0];
    $platforms = [];
    $types = [];
    $load = [];
    $cycle = [];
    foreach ($cards as $c) {
        $kind = (string) $c['col_kind'];
        if ($kind === 'done') {
            $tot['done']++;
            $wk = date('o-\WW', (int) $c['done_at']);
            if (isset($weeks[$wk])) $weeks[$wk]['done']++;
            if ((int) $c['done_at'] > 0) $cycle[] = ((int) $c['done_at'] - (int) $c['created']) / 86400;
            if ($c['due'] !== '') $tot[date('Y-m-d', (int) $c['done_at']) <= $c['due'] ? 'ontime' : 'late']++;
            foreach (jarr($c['platforms']) as $p) $platforms[$p] = ($platforms[$p] ?? 0) + 1;
            if ($c['ctype'] !== '') $types[$c['ctype']] = ($types[$c['ctype']] ?? 0) + 1;
        } elseif ($kind === 'cancelled') {
            $tot['cancelled']++;
        } else {
            $tot['open']++;
            if ($c['due'] !== '' && $c['due'] < $today) $tot['overdue']++;
            if ($c['approval'] === 'pending') $tot['pending']++;
            $a = (int) $c['assignee'];
            $load[$a] = $load[$a] ?? ['open' => 0, 'overdue' => 0];
            $load[$a]['open']++;
            if ($c['due'] !== '' && $c['due'] < $today) $load[$a]['overdue']++;
        }
        $wk = date('o-\WW', (int) $c['created']);
        if (isset($weeks[$wk])) $weeks[$wk]['created']++;
    }
    $levels = [];
    foreach (studio_levels($sid) as $l) {
        $n = 0;
        foreach ($cards as $c) {
            if ((int) $c['level_id'] === (int) $l['id'] && $c['col_kind'] === '') $n++;
        }
        $levels[] = ['name' => (string) $l['name'], 'icon' => (string) $l['icon'], 'color' => (string) $l['color'], 'n' => $n];
    }
    sort($cycle);
    arsort($platforms);
    arsort($types);
    $out = [
        'totals' => $tot, 'levels' => $levels, 'weeks' => array_values($weeks),
        'cycle_days' => $cycle ? round($cycle[(int) floor(count($cycle) / 2)], 1) : null,
        'platforms' => array_map(function (string $k, int $n): array { return ['key' => $k, 'label' => STM_PLATFORMS[$k] ?? $k, 'n' => $n]; }, array_keys($platforms), $platforms),
        'types' => array_map(function (string $k, int $n): array { return ['key' => $k, 'label' => STM_CONTENT_TYPES[$k] ?? $k, 'n' => $n]; }, array_keys($types), $types),
    ];
    if (can($s['role'], 'manage')) {
        $users = user_cards(array_keys($load));
        $out['load'] = [];
        foreach ($load as $uid => $l) $out['load'][] = ['user' => $users[$uid] ?? null] + $l;
        usort($out['load'], function (array $a, array $b): int { return $b['open'] <=> $a['open']; });
    }
    return $out;
}
