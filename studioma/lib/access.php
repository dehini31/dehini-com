<?php
/**
 * Rôles et droits d'accès aux projets, Studios et cartes. Toutes les vérifications se font ici,
 * côté serveur : l'interface ne fait que masquer ce que le rôle n'autorise pas.
 *
 *   owner         tout, y compris supprimer le projet et nommer d'autres propriétaires
 *   manager       structure (niveaux, colonnes), membres et invitations, toutes les cartes
 *   creator       crée, modifie, déplace et archive toutes les cartes
 *   collaborator  modifie et déplace les cartes qui lui sont confiées, commente partout
 *   client        consulte, commente, valide ou demande des modifications
 *   viewer        consulte seulement
 */
declare(strict_types=1);

const ROLES = ['viewer' => 1, 'client' => 2, 'collaborator' => 3, 'creator' => 4, 'manager' => 5, 'owner' => 6];
const ROLE_LABELS = [
    'owner' => 'Owner', 'manager' => 'Manager', 'creator' => 'Creator', 'collaborator' => 'Collaborator',
    'client' => 'Client / Approver', 'viewer' => 'Viewer',
];

function role_rank(string $role): int
{
    return ROLES[$role] ?? 0;
}
function role_max(string $a, string $b): string
{
    return role_rank($a) >= role_rank($b) ? $a : $b;
}
/** Le rôle autorise-t-il cette capacité ? view | comment | approve | work | edit | manage | own */
function can(string $role, string $cap): bool
{
    $r = role_rank($role);
    switch ($cap) {
        case 'view':    return $r >= 1;
        case 'comment': return $r >= 2;
        case 'approve': return in_array($role, ['client', 'manager', 'owner'], true);
        case 'work':    return $r >= 3;
        case 'edit':    return $r >= 4;
        case 'manage':  return $r >= 5;
        case 'own':     return $r >= 6;
    }
    return false;
}

/** Rôle sur tout le projet ('' si le compte n'en est pas membre). */
function project_role(int $uid, array $project): string
{
    $role = (string) (val('SELECT role FROM project_members WHERE project_id = ? AND uid = ?', [$project['id'], $uid]) ?? '');
    // Les administrateurs d'une entreprise gèrent tous ses projets.
    if ((int) $project['company_id'] > 0 && company_admin($uid, (int) $project['company_id'])) $role = role_max($role, 'manager');
    return $role;
}
/** Rôle dans un Studio : le plus élevé entre celui du projet et celui du Studio. */
function studio_role(int $uid, array $studio, array $project): string
{
    $own = (string) (val('SELECT role FROM studio_members WHERE studio_id = ? AND uid = ?', [$studio['id'], $uid]) ?? '');
    return role_max(project_role($uid, $project), $own);
}

/** Projet + rôle du compte (clé « role »). Introuvable si le compte n'y a aucun accès. */
function load_project(int $id, array $u, string $cap = 'view'): array
{
    if (!empty($u['guest'])) not_found('This project');   // un invité ne voit que le Studio de son lien
    $p = row('SELECT * FROM projects WHERE id = ?', [$id]);
    if (!$p) not_found('This project');
    $p['role'] = project_role((int) $u['id'], $p);
    // Un membre d'un seul Studio voit le projet (son nom, ses Studios accessibles), sans rôle sur l'ensemble.
    $p['limited'] = $p['role'] === '';
    if ($p['limited']) {
        $n = (int) val('SELECT COUNT(*) FROM studio_members m JOIN studios s ON s.id = m.studio_id WHERE s.project_id = ? AND m.uid = ?', [$id, $u['id']]);
        if (!$n) not_found('This project');
        if ($cap !== 'view') forbidden();
    } elseif (!can($p['role'], $cap)) {
        forbidden();
    }
    return $p;
}
/** Studio + son projet (« project ») + rôle du compte (« role »). */
function load_studio(int $id, array $u, string $cap = 'view'): array
{
    if (!empty($u['guest'])) return guest_load_studio($id, $u, $cap);   // invité d'un lien : lib/share.php
    $s = row('SELECT * FROM studios WHERE id = ?', [$id]);
    if (!$s) not_found('This Studio');
    $p = row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]);
    $s['role'] = $p ? studio_role((int) $u['id'], $s, $p) : '';
    if ($s['role'] === '') not_found('This Studio');
    if (!can($s['role'], $cap)) forbidden();
    $s['project'] = $p;
    return $s;
}
/** Le compte est-il responsable de la carte ou l'un de ses collaborateurs ? */
function card_involves(array $card, int $uid): bool
{
    if ($uid <= 0) return false;   // invité (id 0) : jamais responsable d'une carte
    if ((int) $card['assignee'] === $uid || (int) $card['created_by'] === $uid) return true;
    return (bool) val('SELECT 1 FROM card_members WHERE card_id = ? AND uid = ?', [$card['id'], $uid]);
}
/**
 * Carte + son Studio (« studio »). $cap : view | comment | cardedit (modifier la carte : creator et
 * plus, ou collaborator sur ses propres cartes) | edit | manage.
 */
function load_card(int $id, array $u, string $cap = 'view'): array
{
    if (!empty($u['guest'])) return guest_load_card($id, $u, $cap);
    $c = row('SELECT * FROM cards WHERE id = ?', [$id]);
    if (!$c) not_found('This card');
    $s = load_studio((int) $c['studio_id'], $u, 'view');
    if ($cap === 'cardedit') {
        if (!can($s['role'], 'edit') && !(can($s['role'], 'work') && card_involves($c, (int) $u['id']))) {
            forbidden('You can only edit cards assigned to you.');
        }
    } elseif (!can($s['role'], $cap)) {
        forbidden();
    }
    $c['studio'] = $s;
    return $c;
}

/**
 * Personnes ayant accès à un Studio : [uid => ['role' => …, 'via' => project | studio | company]].
 * Sert aux listes de membres, aux responsables possibles d'une carte et aux mentions.
 */
function studio_people(array $studio, array $project): array
{
    $out = [];
    $add = function (int $uid, string $role, string $via) use (&$out): void {
        if (!isset($out[$uid]) || role_rank($role) > role_rank($out[$uid]['role'])) $out[$uid] = ['role' => $role, 'via' => $via];
    };
    foreach (rows('SELECT uid, role FROM project_members WHERE project_id = ?', [$project['id']]) as $m) $add((int) $m['uid'], (string) $m['role'], 'project');
    foreach (rows('SELECT uid, role FROM studio_members WHERE studio_id = ?', [$studio['id']]) as $m) $add((int) $m['uid'], (string) $m['role'], 'studio');
    if ((int) $project['company_id'] > 0) {
        foreach (rows("SELECT uid FROM company_members WHERE company_id = ? AND role IN ('owner', 'admin')", [$project['company_id']]) as $m) $add((int) $m['uid'], 'manager', 'company');
    }
    return $out;
}
/** Liste des membres d'un Studio, prête pour l'interface. */
function studio_people_view(array $studio, array $project): array
{
    $people = studio_people($studio, $project);
    $cards = user_cards(array_keys($people));
    $out = [];
    foreach ($people as $uid => $m) {
        if (!isset($cards[$uid])) continue;
        $out[] = $cards[$uid] + ['role' => $m['role'], 'via' => $m['via']];
    }
    usort($out, function (array $a, array $b): int {
        return [-role_rank($a['role']), $a['name']] <=> [-role_rank($b['role']), $b['name']];
    });
    return $out;
}
/** Ids des Studios accessibles au compte (tous projets confondus), archivés exclus. */
function my_studio_ids(int $uid): array
{
    $ids = [];
    foreach (rows('SELECT s.id FROM studios s JOIN projects p ON p.id = s.project_id JOIN project_members m ON m.project_id = p.id
        WHERE m.uid = ? AND s.archived = 0 AND p.archived = 0', [$uid]) as $r) $ids[(int) $r['id']] = true;
    foreach (rows('SELECT s.id FROM studios s JOIN projects p ON p.id = s.project_id JOIN studio_members m ON m.studio_id = s.id
        WHERE m.uid = ? AND s.archived = 0 AND p.archived = 0', [$uid]) as $r) $ids[(int) $r['id']] = true;
    foreach (rows("SELECT s.id FROM studios s JOIN projects p ON p.id = s.project_id JOIN company_members cm ON cm.company_id = p.company_id
        WHERE cm.uid = ? AND cm.role IN ('owner', 'admin') AND p.company_id > 0 AND s.archived = 0 AND p.archived = 0", [$uid]) as $r) $ids[(int) $r['id']] = true;
    return array_keys($ids);
}
/** Deux comptes partagent-ils (ou ont-ils partagé) un projet ? */
function worked_together(int $a, int $b): bool
{
    $pa = array_column(rows('SELECT project_id FROM project_members WHERE uid = ?
        UNION SELECT s.project_id FROM studio_members m JOIN studios s ON s.id = m.studio_id WHERE m.uid = ?', [$a, $a]), 'project_id');
    if (!$pa) return false;
    return (bool) val('SELECT 1 FROM project_members WHERE uid = ? AND project_id IN (' . marks($pa) . ')
        UNION SELECT 1 FROM studio_members m JOIN studios s ON s.id = m.studio_id WHERE m.uid = ? AND s.project_id IN (' . marks($pa) . ') LIMIT 1',
        array_merge([$b], $pa, [$b], $pa));
}
