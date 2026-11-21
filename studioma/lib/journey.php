<?php
/**
 * Parcours du créateur (« Creator Journey ») : points d'expérience (XP), réalisations vérifiées, niveaux, jalons,
 * chronologie, passeport public et pages de vérification.
 *
 * Principe : rien n'est déclaré par le navigateur. Chaque point vient d'un enregistrement qui existe déjà dans
 * Studioma (Studio créé, carte terminée dans une colonne « done », validation décidée par un client, invitation
 * acceptée, réalisation publiée, projet marqué terminé après vérification) et s'inscrit une seule fois dans le grand
 * livre xp_transactions (clé d'idempotence unique par compte). Les réalisations et les niveaux se déduisent du grand
 * livre ; l'administration peut retirer (et rendre) une ligne ou une réalisation, avec une raison.
 *
 * Les règles (points, limites par jour, seuils des niveaux, critères) sont réunies ici ; l'administration peut changer
 * les valeurs numériques et activer ou désactiver une réalisation (meta « journey_rules »). Rien n'est payant : le
 * parcours ne dépend pas de la formule, et ce qui est acquis reste quand l'abonnement change.
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ règles */

/** Événements qui rapportent des points. cap : nombre d'événements payés par jour (0 = sans limite). */
const JOURNEY_EVENTS = [
    'first_studio' => ['label' => 'Create your first Studio', 'xp' => 20, 'cap' => 0],
    'first_project' => ['label' => 'Create your first project', 'xp' => 15, 'cap' => 0],
    'card' => ['label' => 'Create an eligible content card', 'xp' => 2, 'cap' => 10],
    'done' => ['label' => 'Complete an eligible card', 'xp' => 10, 'cap' => 10],
    'level' => ['label' => 'Complete a workflow level', 'xp' => 20, 'cap' => 3],
    'project' => ['label' => 'Complete a project', 'xp' => 50, 'cap' => 2],
    'share' => ['label' => 'Share a Studio with a collaborator', 'xp' => 10, 'cap' => 3],
    'publish' => ['label' => 'Publish a completed achievement', 'xp' => 20, 'cap' => 2],
    'approval' => ['label' => 'Receive an authorized client approval', 'xp' => 30, 'cap' => 5],
];
/** Critères réglables : [libellé, valeur, minimum, maximum]. */
const JOURNEY_PARAMS = [
    'card_min_text' => ['Eligible card: minimum description length (characters) when it has no attachment, checklist or published link', 20, 0, 2000],
    'level_min_cards' => ['Workflow level: eligible cards that went through it before it is cleared', 3, 1, 100],
    'project_min_cards' => ['Project completion: minimum completed eligible cards', 3, 1, 500],
    'project_min_hours' => ['Project completion: minimum project age (hours)', 24, 0, 720],
    'proven_projects' => ['Proven Creator: completed projects', 3, 1, 50],
    'proven_approvals' => ['Proven Creator: client approvals', 3, 1, 100],
    'proven_approval_projects' => ['Proven Creator: different projects with a client approval', 2, 1, 50],
];
/** Niveaux du créateur : indicateurs internes de progression (jamais une qualification reconnue à l'extérieur). */
const JOURNEY_LEVELS = [
    1 => ['name' => 'Explorer', 'about' => 'Discovers Studioma and sets up a first workspace.', 'req' => []],
    2 => ['name' => 'Organizer', 'about' => 'Organizes real content and completes tasks.', 'req' => ['xp' => 150, 'done' => 5]],
    3 => ['name' => 'Creator', 'about' => 'Completes and documents creative projects.', 'req' => ['xp' => 500, 'done' => 15, 'projects' => 1]],
    4 => ['name' => 'Collaborator', 'about' => 'Shows real collaboration with other people.', 'req' => ['xp' => 1500, 'projects' => 2, 'team' => 1, 'shares' => 1]],
    5 => ['name' => 'Professional', 'about' => 'Completes several projects with recorded client approvals.', 'req' => ['xp' => 3000, 'projects' => 3, 'approvals' => 3]],
    6 => ['name' => 'Mentor', 'about' => 'Sustained contributions and real help on other people\'s projects.', 'req' => ['xp' => 6000, 'projects' => 6, 'proven' => 1, 'helped' => 25, 'months' => 6]],
];
const JOURNEY_CRITERIA = [
    'xp' => '%s XP', 'done' => 'Complete %s eligible cards', 'projects' => 'Complete %s projects', 'team' => 'Earn the Team Player achievement',
    'shares' => 'Share a Studio with a collaborator', 'approvals' => 'Receive %s client approvals', 'proven' => 'Earn the Proven Creator achievement',
    'helped' => 'Complete %s cards in projects owned by someone else', 'months' => 'Be active in %s different months',
];
const JOURNEY_VERIFICATIONS = [
    'system' => 'System Verified', 'client' => 'Client Verified', 'evidence' => 'Evidence Based', 'self' => 'Self Reported',
];
/** Réalisations. xp : points de l'action qui la débloque (affichés ; jamais comptés deux fois). */
const JOURNEY_ACHIEVEMENTS = [
    'first_studio' => [
        'title' => 'First Studio', 'icon' => 'board', 'category' => 'Workspace', 'verification' => 'system', 'xp' => 20,
        'desc' => 'Created a first Studio workspace in Studioma.', 'evidence' => 'Studio creation record',
        'proves' => 'Studioma recorded that this person created a Studio workspace.',
        'not' => 'It does not say anything about the content or the quality of the work in it.',
        'celebrate' => 'Your creative journey starts here! You\'ve built your first Studioma workspace.',
    ],
    'first_delivery' => [
        'title' => 'First Delivery', 'icon' => 'check-circle', 'category' => 'Delivery', 'verification' => 'system', 'xp' => 10,
        'desc' => 'Completed a first eligible content card (with a real description, attachment, checklist or published link).', 'evidence' => 'Completed card record',
        'proves' => 'A card this person worked on reached a “done” column of its workflow.',
        'not' => 'It does not prove that a client reviewed or approved the content.',
        'celebrate' => 'First delivery done! Your first eligible card is complete.',
    ],
    'project_finisher' => [
        'title' => 'Project Finisher', 'icon' => 'flag', 'category' => 'Projects', 'verification' => 'system', 'xp' => 50,
        'desc' => 'Contributed to a project that was completed in Studioma.', 'evidence' => 'Completed project record',
        'proves' => 'A project this person delivered cards in was marked as completed, after Studioma checked that its cards were done.',
        'not' => 'It does not prove a client\'s satisfaction, nor the commercial results of the project.',
        'celebrate' => 'You did it! Your first project is complete. That\'s real progress.',
    ],
    'client_approved' => [
        'title' => 'Client Approved', 'icon' => 'shield', 'category' => 'Client recognition', 'verification' => 'client', 'xp' => 30,
        'desc' => 'Received a first approval from an authorized client on delivered work.', 'evidence' => 'Client approval record',
        'proves' => 'A person invited as client (or a verified guest of a share link) approved this work themselves in Studioma.',
        'not' => 'An approval verifies the client\'s recorded decision, not the overall professional quality of the creator.',
        'celebrate' => 'Your work has received its first recorded client approval. Keep building your reputation.',
    ],
    'team_player' => [
        'title' => 'Team Player', 'icon' => 'users', 'category' => 'Collaboration', 'verification' => 'system', 'xp' => 10,
        'desc' => 'Completed an eligible card together with another member who has a verified account.', 'evidence' => 'Shared card completion record',
        'proves' => 'At least two verified accounts worked on the same completed card.',
        'not' => 'It does not measure how much each person contributed.',
        'celebrate' => 'Great teamwork! You completed work together with another member.',
    ],
    'proven_creator' => [
        'title' => 'Proven Creator', 'icon' => 'award', 'category' => 'Reputation', 'verification' => 'evidence', 'xp' => 0,
        'desc' => 'Completed several projects, with client approvals recorded on more than one of them.', 'evidence' => 'Completed projects and client approval records',
        'proves' => 'Studioma holds several completed project records and several client approval records for this person.',
        'not' => 'It is a Studioma indicator, not an externally accredited professional qualification. XP alone never earns it.',
        'celebrate' => 'Proven Creator! Completed projects, backed by recorded client approvals.',
    ],
];
const JOURNEY_SELF_MAX = 20;

/** Règles en vigueur : valeurs par défaut + réglages de l'administration (meta « journey_rules »). */
function journey_rules(bool $reload = false): array
{
    static $rules = null;
    if ($rules !== null && !$reload) return $rules;
    $o = json_decode((string) (meta_get('journey_rules') ?? ''), true);
    $o = is_array($o) ? $o : [];
    $events = JOURNEY_EVENTS;
    foreach ($events as $k => $e) {
        foreach (['xp', 'cap'] as $f) if (isset($o['events'][$k][$f])) $events[$k][$f] = max(0, min(1000, (int) $o['events'][$k][$f]));
    }
    $params = [];
    foreach (JOURNEY_PARAMS as $k => $p) $params[$k] = isset($o['params'][$k]) ? max($p[2], min($p[3], (int) $o['params'][$k])) : $p[1];
    $levels = JOURNEY_LEVELS;
    foreach ($levels as $n => $l) {
        foreach ($l['req'] as $k => $v) if (isset($o['levels'][$n][$k])) $levels[$n]['req'][$k] = max(1, min(1000000, (int) $o['levels'][$n][$k]));
    }
    $active = [];
    foreach (JOURNEY_ACHIEVEMENTS as $k => $a) $active[$k] = !isset($o['off'][$k]);
    return $rules = ['v' => max(1, (int) ($o['v'] ?? 1)), 'events' => $events, 'params' => $params, 'levels' => $levels, 'active' => $active];
}
function journey_param(string $k): int
{
    return (int) journey_rules()['params'][$k];
}

/* ------------------------------------------------------- grand livre des XP */

/** Compte à recalculer à sa prochaine requête (son tableau de bord, ses pastilles…). */
function journey_dirty(array $uids): void
{
    foreach (array_unique(array_filter(array_map('intval', $uids))) as $uid) {
        q('INSERT OR IGNORE INTO journey_progress (uid, dirty) VALUES (?, 1)', [$uid]);
        q('UPDATE journey_progress SET dirty = 1 WHERE uid = ?', [$uid]);
    }
}
function journey_has(int $uid, string $ikey): bool
{
    return (bool) val('SELECT 1 FROM xp_transactions WHERE uid = ? AND ikey = ?', [$uid, $ikey]);
}
/**
 * Inscrit un événement qualifiant, une seule fois par clé (même avec des requêtes simultanées : contrainte d'unicité).
 * Au-delà de la limite du jour, l'événement est inscrit sans points. Retourne l'id de la ligne, ou 0 si elle existait.
 */
function journey_grant(int $uid, string $kind, string $ikey, int $at, array $ctx = []): int
{
    $rules = journey_rules();
    if ($uid <= 0 || !isset($rules['events'][$kind])) return 0;
    $ev = $rules['events'][$kind];
    $at = $at > 0 ? min($at, time()) : time();
    $day = date('Y-m-d', $at);
    $xp = (int) $ev['xp'];
    $note = '';
    if ((int) $ev['cap'] > 0 && $xp > 0) {
        $n = (int) val('SELECT COUNT(*) FROM xp_transactions WHERE uid = ? AND kind = ? AND day = ? AND xp > 0', [$uid, $kind, $day]);
        if ($n >= (int) $ev['cap']) { $xp = 0; $note = 'daily limit'; }
    }
    $st = q('INSERT OR IGNORE INTO xp_transactions (uid, kind, ikey, xp, rule_v, project_id, studio_id, card_id, ref, peer, note, at, day, created)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$uid, $kind, $ikey, $xp, $rules['v'], (int) ($ctx['project_id'] ?? 0), (int) ($ctx['studio_id'] ?? 0),
        (int) ($ctx['card_id'] ?? 0), (int) ($ctx['ref'] ?? 0), (int) ($ctx['peer'] ?? 0), $note, $at, $day, time()]);
    if ($st->rowCount() === 0) return 0;
    $id = (int) db()->lastInsertId();
    journey_dirty([$uid]);
    return $id;
}

/* ------------------------------------------------------ cartes et niveaux */

const JOURNEY_CARD_COLS = 'c.id, c.studio_id, c.col_id, c.title, c.body, c.publish_url, c.created, c.created_by, c.assignee, c.done_at, c.archived, s.project_id, p.owner_uid,
    (SELECT COUNT(*) FROM card_files f WHERE f.card_id = c.id) AS files, (SELECT COUNT(*) FROM card_checks k WHERE k.card_id = c.id) AS checks';
const JOURNEY_CARD_FROM = ' FROM cards c JOIN studios s ON s.id = c.studio_id JOIN projects p ON p.id = s.project_id';
/**
 * Pré-filtre SQL d'une carte qui peut être « éligible » (confirmé en PHP par journey_card_eligible). Paramètre : longueur,
 * convertie en nombre (PDO lie les valeurs en texte, et SQLite trouve tout nombre plus petit qu'un texte).
 */
const JOURNEY_CARD_PRE = "(length(c.body) >= CAST(? AS INTEGER) OR c.publish_url <> '' OR EXISTS (SELECT 1 FROM card_files f2 WHERE f2.card_id = c.id) OR EXISTS (SELECT 1 FROM card_checks k2 WHERE k2.card_id = c.id))";
/** Le compte (3 fois le même paramètre) a contribué à la carte : responsable, collaborateur, ou créateur d'une carte sans responsable. */
const JOURNEY_CONTRIB = '(c.assignee = ? OR EXISTS (SELECT 1 FROM card_members m WHERE m.card_id = c.id AND m.uid = ?) OR (c.assignee = 0 AND c.created_by = ? AND NOT EXISTS (SELECT 1 FROM card_members m2 WHERE m2.card_id = c.id)))';

/** Carte « éligible » : un vrai contenu (description assez longue, pièce jointe, liste de contrôle ou lien publié). */
function journey_card_eligible(array $c): bool
{
    if ((int) ($c['files'] ?? 0) > 0 || (int) ($c['checks'] ?? 0) > 0 || trim((string) ($c['publish_url'] ?? '')) !== '') return true;
    $min = journey_param('card_min_text');
    return $min === 0 || mb_strlen(rich_text((string) ($c['body'] ?? ''), $min + 10)) >= $min;
}
/** Personnes à qui revient le travail d'une carte : responsable et collaborateurs, sinon son créateur. */
function journey_contributors(array $c): array
{
    $ids = array_map('intval', array_column(rows('SELECT uid FROM card_members WHERE card_id = ?', [$c['id']]), 'uid'));
    if ((int) $c['assignee'] > 0) $ids[] = (int) $c['assignee'];
    if (!$ids && (int) $c['created_by'] > 0) $ids[] = (int) $c['created_by'];
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];
    return array_map('intval', array_column(rows('SELECT id FROM users WHERE id IN (' . marks($ids) . ')', $ids), 'id'));
}
function journey_ctx(array $c, int $uid, int $ref = 0): array
{
    return ['project_id' => (int) $c['project_id'], 'studio_id' => (int) $c['studio_id'], 'card_id' => (int) $c['id'], 'ref' => $ref, 'peer' => (int) $c['owner_uid'] !== $uid ? 1 : 0];
}
function journey_card(int $cardId): ?array
{
    return row('SELECT ' . JOURNEY_CARD_COLS . JOURNEY_CARD_FROM . ' WHERE c.id = ?', [$cardId]);
}
/** Carte arrivée dans une colonne « done » : chaque contributeur reçoit l'événement (une fois par carte). */
function journey_card_done(int $cardId): void
{
    $c = journey_card($cardId);
    if (!$c || (int) $c['done_at'] === 0 || !journey_card_eligible($c)) return;
    foreach (journey_contributors($c) as $uid) journey_grant($uid, 'done', 'done:' . $c['id'], (int) $c['done_at'], journey_ctx($c, $uid));
}
/**
 * Appelée par card_place() (lib/studio.php) après chaque déplacement de carte, dans la transaction : passage d'un
 * niveau (vers un niveau plus loin du parcours, ou terminée dans le niveau), carte terminée, niveau peut-être dégagé.
 */
function journey_card_placed(int $cardId, int $oldCol, array $col): void
{
    $new = (int) $col['level_id'];
    $sid = (int) $col['studio_id'];
    $check = [];
    if ($oldCol > 0 && $oldCol !== (int) $col['id']) {
        $old = row('SELECT co.level_id, l.pos FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.id = ?', [$oldCol]);
        if ($old && (int) $old['level_id'] !== $new) {
            if ((int) val('SELECT pos FROM levels WHERE id = ?', [$new]) > (int) $old['pos']) journey_level_pass((int) $old['level_id'], $cardId, $sid);
            $check[] = (int) $old['level_id'];
        }
    }
    $kind = (string) ($col['kind'] ?? '');
    if ($kind === 'done') {
        journey_level_pass($new, $cardId, $sid);
        journey_card_done($cardId);
    }
    if ($kind !== '') $check[] = $new;
    foreach (array_unique($check) as $lid) journey_level_check($lid);
    $c = row('SELECT id, created_by, assignee FROM cards WHERE id = ?', [$cardId]);
    if ($c) journey_dirty(array_merge([(int) $c['created_by'], (int) $c['assignee']], array_column(rows('SELECT uid FROM card_members WHERE card_id = ?', [$cardId]), 'uid')));
}
function journey_level_pass(int $levelId, int $cardId, int $studioId): void
{
    q('INSERT OR IGNORE INTO level_passes (level_id, card_id, studio_id, created) VALUES (?, ?, ?, ?)', [$levelId, $cardId, $studioId, time()]);
}
/**
 * Niveau d'un Studio « mené à bien » : au moins N cartes éligibles y sont passées (terminées ou envoyées plus loin) et
 * aucune carte ouverte n'y reste. Chaque contributeur de ces cartes reçoit l'événement, une seule fois par niveau.
 */
function journey_level_check(int $levelId): void
{
    $l = row('SELECT l.id, l.studio_id, s.project_id, p.owner_uid FROM levels l JOIN studios s ON s.id = l.studio_id JOIN projects p ON p.id = s.project_id WHERE l.id = ? AND l.archived = 0', [$levelId]);
    if (!$l) return;
    $open = (int) val("SELECT COUNT(*) FROM cards c JOIN cols co ON co.id = c.col_id WHERE co.level_id = ? AND c.archived = 0 AND co.archived = 0 AND co.kind NOT IN ('done', 'cancelled')", [$levelId]);
    if ($open > 0) return;
    $passed = array_values(array_filter(rows('SELECT ' . JOURNEY_CARD_COLS . JOURNEY_CARD_FROM . ' JOIN level_passes lp ON lp.card_id = c.id WHERE lp.level_id = ? LIMIT 500', [$levelId]), 'journey_card_eligible'));
    if (count($passed) < journey_param('level_min_cards')) return;
    $who = [];
    foreach ($passed as $c) foreach (journey_contributors($c) as $uid) $who[$uid] = true;
    foreach (array_keys($who) as $uid) {
        journey_grant($uid, 'level', 'level:' . $levelId, time(), ['project_id' => (int) $l['project_id'], 'studio_id' => (int) $l['studio_id'], 'ref' => $levelId, 'peer' => (int) $l['owner_uid'] !== $uid ? 1 : 0]);
    }
}

/* -------------------------------------------------------- validations client */

/** Validation décidée par la personne désignée elle-même, cliente du Studio (adresse confirmée) ou invitée vérifiée. */
function journey_client_ok(array $a): bool
{
    if ($a['status'] !== 'approved') return false;
    if ((int) $a['approver_guest'] > 0) return $a['decider_role'] === 'guest';
    return (int) $a['approver_uid'] > 0 && (int) $a['decided_by'] === (int) $a['approver_uid'] && $a['decider_role'] === 'client'
        && (int) val('SELECT email_ok FROM users WHERE id = ?', [$a['approver_uid']]) === 1;
}
/** Une validation de client accordée à un contributeur de la carte (jamais à la personne qui a validé). */
function journey_approval_for(array $a, array $c, int $uid): void
{
    if (!journey_client_ok($a) || !journey_card_eligible($c)) return;
    if ((int) $a['approver_uid'] === $uid || (int) $a['decided_by'] === $uid) return;
    if ((int) $a['approver_guest'] > 0) {
        $ge = strtolower((string) val('SELECT email FROM share_guests WHERE id = ?', [$a['approver_guest']]));
        if ($ge === '' || $ge === strtolower((string) val('SELECT email FROM users WHERE id = ?', [$uid]))) return;
    }
    journey_grant($uid, 'approval', 'approval:' . $c['id'], (int) $a['decided'], journey_ctx($c, $uid, (int) $a['id']));
}
/** Appelée après la décision d'une validation (lib/cards.php). */
function journey_approval(int $approvalId): void
{
    $a = row('SELECT * FROM card_approvals WHERE id = ?', [$approvalId]);
    if (!$a || $a['status'] !== 'approved') return;
    $c = journey_card((int) $a['card_id']);
    if (!$c) return;
    foreach (journey_contributors($c) as $uid) journey_approval_for($a, $c, $uid);
}

/* --------------------------------------------------------- projets terminés */

/** Ce que vérifie Studioma avant de marquer un projet comme terminé. */
function journey_project_completion(array $p): array
{
    $min = journey_param('project_min_cards');
    $hours = journey_param('project_min_hours');
    $done = array_values(array_filter(rows('SELECT ' . JOURNEY_CARD_COLS . JOURNEY_CARD_FROM . ' WHERE s.project_id = ? AND c.done_at > 0 LIMIT 2000', [$p['id']]), 'journey_card_eligible'));
    $open = (int) val("SELECT COUNT(*) FROM cards c JOIN cols co ON co.id = c.col_id JOIN levels l ON l.id = co.level_id JOIN studios s ON s.id = c.studio_id
        WHERE s.project_id = ? AND s.archived = 0 AND c.archived = 0 AND co.archived = 0 AND l.archived = 0 AND co.kind NOT IN ('done', 'cancelled')", [$p['id']]);
    $age = (time() - (int) $p['created']) / 3600;
    $crit = [
        ['key' => 'cards', 'label' => 'At least ' . $min . ' completed eligible cards', 'have' => count($done), 'need' => $min, 'ok' => count($done) >= $min],
        ['key' => 'open', 'label' => 'No open card left: every active card is done or cancelled', 'have' => $open, 'need' => 0, 'ok' => $open === 0],
        ['key' => 'age', 'label' => 'Project started at least ' . $hours . ' hours ago', 'have' => (int) floor($age), 'need' => $hours, 'ok' => $age >= $hours],
    ];
    return [
        'completed_at' => (int) ($p['completed_at'] ?? 0), 'eligible' => !in_array(false, array_column($crit, 'ok'), true), 'criteria' => $crit,
        'done_ids' => array_map('intval', array_column($done, 'id')),
    ];
}
function journey_completion_view(array $p): array
{
    $c = journey_project_completion($p);
    unset($c['done_ids']);
    $c['completed_by'] = (int) ($p['completed_by'] ?? 0) ? user_cards([(int) $p['completed_by']])[(int) $p['completed_by']] ?? null : null;
    return $c;
}
/** POST project_complete {id, completed} : un propriétaire ou un responsable marque le projet terminé (ou le rouvre). */
function api_project_complete(array $b, array $u): array
{
    $p = load_project(int_in($b, 'id'), $u, 'manage');
    $uid = (int) $u['id'];
    if (!empty($b['completed'])) {
        if ((int) $p['completed_at'] === 0) {
            $c = journey_project_completion($p);
            foreach ($c['criteria'] as $k) if (!$k['ok']) fail('This project can\'t be marked as completed yet. ' . $k['label'] . '.', 400, 'not_eligible');
            tx(function () use ($p, $uid, $c): void {
                q('UPDATE projects SET completed_at = ?, completed_by = ?, updated = ? WHERE id = ? AND completed_at = 0', [time(), $uid, time(), $p['id']]);
                foreach ($c['done_ids'] as $cid) journey_card_done($cid);   // cartes terminées avant le parcours
                foreach (rows('SELECT id FROM studios WHERE project_id = ?', [$p['id']]) as $s) log_event((int) $s['id'], 0, $uid, 'project', 'marked the project as completed');
                $people = array_column(rows("SELECT DISTINCT uid FROM xp_transactions WHERE kind = 'done' AND project_id = ? AND revoked = 0", [$p['id']]), 'uid');
                foreach ($people as $who) {
                    journey_grant((int) $who, 'project', 'project:' . $p['id'], time(), ['project_id' => (int) $p['id'], 'peer' => (int) $p['owner_uid'] !== (int) $who ? 1 : 0]);
                    notify((int) $who, $uid, 'project', 'marked “' . $p['name'] . '” as completed', 'project/' . $p['id']);
                }
            });
        }
    } else {
        q('UPDATE projects SET completed_at = 0, completed_by = 0, updated = ? WHERE id = ?', [time(), $p['id']]);
    }
    $p = row('SELECT * FROM projects WHERE id = ?', [$p['id']]);
    return ['completion' => journey_completion_view($p)];
}

/* ------------------------------------------------------- calcul du parcours */

/**
 * Recalcule le parcours d'un compte : rattrape les événements qualifiants pas encore inscrits (données antérieures au
 * parcours, contributions des autres), puis compteurs, réalisations et niveau. Idempotent.
 */
function journey_eval(int $uid): array
{
    $u = row('SELECT id, email, created FROM users WHERE id = ?', [$uid]);
    if (!$u) return [];
    tx(function () use ($uid, $u): void {
        $min = journey_param('card_min_text');
        if (!journey_has($uid, 'first_studio') && ($s = row('SELECT id, project_id, created FROM studios WHERE created_by = ? ORDER BY created, id LIMIT 1', [$uid]))) {
            journey_grant($uid, 'first_studio', 'first_studio', (int) $s['created'], ['project_id' => (int) $s['project_id'], 'studio_id' => (int) $s['id']]);
        }
        if (!journey_has($uid, 'first_project') && ($p = row('SELECT id, created FROM projects WHERE owner_uid = ? ORDER BY created, id LIMIT 1', [$uid]))) {
            journey_grant($uid, 'first_project', 'first_project', (int) $p['created'], ['project_id' => (int) $p['id']]);
        }
        foreach (rows('SELECT ' . JOURNEY_CARD_COLS . JOURNEY_CARD_FROM . ' WHERE c.created_by = ? AND ' . JOURNEY_CARD_PRE . "
            AND NOT EXISTS (SELECT 1 FROM xp_transactions x WHERE x.uid = ? AND x.ikey = 'card:' || c.id) ORDER BY c.created LIMIT 2000", [$uid, $min, $uid]) as $c) {
            if (journey_card_eligible($c)) journey_grant($uid, 'card', 'card:' . $c['id'], (int) $c['created'], journey_ctx($c, $uid));
        }
        foreach (rows('SELECT ' . JOURNEY_CARD_COLS . JOURNEY_CARD_FROM . ' WHERE c.done_at > 0 AND ' . JOURNEY_CONTRIB . ' AND ' . JOURNEY_CARD_PRE . "
            AND NOT EXISTS (SELECT 1 FROM xp_transactions x WHERE x.uid = ? AND x.ikey = 'done:' || c.id) ORDER BY c.done_at LIMIT 2000", [$uid, $uid, $uid, $min, $uid]) as $c) {
            if (journey_card_eligible($c)) journey_grant($uid, 'done', 'done:' . $c['id'], (int) $c['done_at'], journey_ctx($c, $uid));
        }
        // Niveaux de Studio dégagés avant le parcours (cartes déjà terminées) : vérifiés une fois.
        foreach (rows("SELECT DISTINCT lp.level_id FROM xp_transactions x JOIN level_passes lp ON lp.card_id = x.card_id WHERE x.uid = ? AND x.kind = 'done'
            AND NOT EXISTS (SELECT 1 FROM xp_transactions y WHERE y.uid = ? AND y.ikey = 'level:' || lp.level_id) LIMIT 50", [$uid, $uid]) as $l) {
            journey_level_check((int) $l['level_id']);
        }
        foreach (rows("SELECT p.id, p.completed_at, p.owner_uid FROM projects p WHERE p.completed_at > 0
            AND EXISTS (SELECT 1 FROM xp_transactions x WHERE x.uid = ? AND x.kind = 'done' AND x.revoked = 0 AND x.project_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM xp_transactions y WHERE y.uid = ? AND y.ikey = 'project:' || p.id)", [$uid, $uid]) as $p) {
            journey_grant($uid, 'project', 'project:' . $p['id'], (int) $p['completed_at'], ['project_id' => (int) $p['id'], 'peer' => (int) $p['owner_uid'] !== $uid ? 1 : 0]);
        }
        // Partage d'un Studio (ou d'un projet) : un autre compte l'a rejoint par une invitation de la personne, ou un invité
        // a vérifié son adresse sur un de ses liens. Une fois par Studio ou par projet.
        foreach (rows("SELECT i.id, i.project_id, i.studio_id, i.uid AS joined, i.created FROM invites i WHERE i.by_uid = ?
            AND ((i.status = 'accepted' AND i.uid > 0 AND i.uid <> ?) OR (i.email = '' AND i.uid = 0 AND i.uses > 0))", [$uid, $uid]) as $i) {
            $key = (int) $i['studio_id'] ? 'share:s' . $i['studio_id'] : 'share:p' . $i['project_id'];
            if (journey_has($uid, $key)) continue;
            $at = (int) $i['joined'] ? (int) (val((int) $i['studio_id'] ? 'SELECT created FROM studio_members WHERE studio_id = ? AND uid = ?' : 'SELECT created FROM project_members WHERE project_id = ? AND uid = ?', [(int) $i['studio_id'] ?: (int) $i['project_id'], $i['joined']]) ?? 0) : 0;
            journey_grant($uid, 'share', $key, $at ?: (int) $i['created'], ['project_id' => (int) $i['project_id'], 'studio_id' => (int) $i['studio_id'], 'ref' => (int) $i['id']]);
        }
        foreach (rows('SELECT g.id, g.studio_id, g.verified, s.project_id FROM share_guests g JOIN shares sh ON sh.id = g.share_id JOIN studios s ON s.id = g.studio_id
            WHERE g.verified > 0 AND (sh.created_by = ? OR g.added_by = ?) AND lower(g.email) <> ?', [$uid, $uid, strtolower((string) $u['email'])]) as $g) {
            if (!journey_has($uid, 'share:s' . $g['studio_id'])) journey_grant($uid, 'share', 'share:s' . $g['studio_id'], (int) $g['verified'], ['project_id' => (int) $g['project_id'], 'studio_id' => (int) $g['studio_id'], 'ref' => (int) $g['id']]);
        }
        foreach (rows("SELECT id, card_id, studio_id, project_id, created FROM pubs WHERE uid = ? AND kind = 'achievement' AND status = 'published'", [$uid]) as $pb) {
            $key = 'publish:' . ((int) $pb['card_id'] > 0 ? 'c' . $pb['card_id'] : 'p' . $pb['id']);
            if (!journey_has($uid, $key)) journey_grant($uid, 'publish', $key, (int) $pb['created'], ['project_id' => (int) $pb['project_id'], 'studio_id' => (int) $pb['studio_id'], 'card_id' => (int) $pb['card_id'], 'ref' => (int) $pb['id']]);
        }
        foreach (rows("SELECT a.* FROM card_approvals a JOIN cards c ON c.id = a.card_id WHERE a.status = 'approved' AND a.decider_role IN ('client', 'guest') AND " . JOURNEY_CONTRIB . "
            AND NOT EXISTS (SELECT 1 FROM xp_transactions x WHERE x.uid = ? AND x.ikey = 'approval:' || a.card_id) ORDER BY a.decided LIMIT 500", [$uid, $uid, $uid, $uid]) as $a) {
            $c = journey_card((int) $a['card_id']);
            if ($c) journey_approval_for($a, $c, $uid);
        }
    });
    return tx(function () use ($uid, $u): array {
        $st = journey_stats($uid);
        journey_achievements_sync($uid, $st);
        $st['proven'] = (int) (bool) val("SELECT 1 FROM user_achievements WHERE uid = ? AND aid = 'proven_creator' AND status = 'verified'", [$uid]);
        $st['team'] = (int) (bool) val("SELECT 1 FROM user_achievements WHERE uid = ? AND aid = 'team_player' AND status = 'verified'", [$uid]);
        $level = journey_level_num($st);
        $prev = row('SELECT level, updated FROM journey_progress WHERE uid = ?', [$uid]);
        $was = $prev ? (int) $prev['level'] : 1;
        if ($level > $was) {
            $names = journey_rules()['levels'];
            for ($n = $was + 1; $n <= $level; $n++) {
                insert('journey_log', ['uid' => $uid, 'kind' => 'level', 'ref' => (string) $n, 'text' => 'Reached Level ' . sprintf('%02d', $n) . ' — ' . $names[$n]['name'], 'seen' => $n === $level ? 0 : 1, 'created' => time()]);
            }
        }
        $st['level'] = $level;
        q('INSERT OR IGNORE INTO journey_progress (uid) VALUES (?)', [$uid]);
        q('UPDATE journey_progress SET xp = ?, level = ?, stats = ?, dirty = 0, updated = ? WHERE uid = ?', [$st['xp'], $level, jenc($st), time(), $uid]);
        return $st;
    });
}
/** Compteurs tirés du grand livre (lignes non retirées). */
function journey_stats(int $uid): array
{
    $st = ['xp' => 0, 'card' => 0, 'done' => 0, 'level' => 0, 'projects' => 0, 'shares' => 0, 'publish' => 0, 'approvals' => 0, 'first_studio' => 0, 'first_project' => 0];
    $map = ['project' => 'projects', 'share' => 'shares', 'approval' => 'approvals'];
    foreach (rows('SELECT kind, COUNT(*) AS n, SUM(xp) AS xp FROM xp_transactions WHERE uid = ? AND revoked = 0 GROUP BY kind', [$uid]) as $r) {
        $st[$map[$r['kind']] ?? $r['kind']] = (int) $r['n'];
        $st['xp'] += (int) $r['xp'];
    }
    $st['workflow_levels'] = $st['level'];
    unset($st['level']);
    $st['approval_projects'] = (int) val("SELECT COUNT(DISTINCT project_id) FROM xp_transactions WHERE uid = ? AND kind = 'approval' AND revoked = 0", [$uid]);
    $st['helped'] = (int) val("SELECT COUNT(*) FROM xp_transactions WHERE uid = ? AND kind = 'done' AND peer = 1 AND revoked = 0", [$uid]);
    $st['months'] = (int) val("SELECT COUNT(DISTINCT substr(day, 1, 7)) FROM xp_transactions WHERE uid = ? AND revoked = 0 AND kind NOT IN ('first_studio', 'first_project')", [$uid]);
    $st['pending_approvals'] = (int) val("SELECT COUNT(*) FROM card_approvals a JOIN cards c ON c.id = a.card_id WHERE a.status = 'pending' AND (a.approver_guest > 0
        OR EXISTS (SELECT 1 FROM studio_members sm WHERE sm.studio_id = c.studio_id AND sm.uid = a.approver_uid AND sm.role = 'client')
        OR EXISTS (SELECT 1 FROM project_members pm JOIN studios s ON s.project_id = pm.project_id WHERE s.id = c.studio_id AND pm.uid = a.approver_uid AND pm.role = 'client'))
        AND " . JOURNEY_CONTRIB, [$uid, $uid, $uid]);
    $st['team'] = 0;
    $st['proven'] = 0;
    return $st;
}
/** Première ligne non retirée d'un type (preuve d'une réalisation). */
function journey_first(int $uid, string $kind): ?array
{
    return row('SELECT * FROM xp_transactions WHERE uid = ? AND kind = ? AND revoked = 0 ORDER BY at, id LIMIT 1', [$uid, $kind]);
}
/** Carte terminée avec un autre compte vérifié (responsable, collaborateur ou créateur de la carte). */
function journey_team_entry(int $uid): ?array
{
    return row("SELECT x.* FROM xp_transactions x JOIN cards c ON c.id = x.card_id WHERE x.uid = ? AND x.kind = 'done' AND x.revoked = 0
        AND EXISTS (SELECT 1 FROM users o WHERE o.id <> ? AND o.email_ok = 1 AND o.suspended = 0
            AND (o.id = c.created_by OR o.id = c.assignee OR o.id IN (SELECT m.uid FROM card_members m WHERE m.card_id = c.id)))
        ORDER BY x.at, x.id LIMIT 1", [$uid, $uid]);
}
/** Preuve d'une ligne du grand livre, prête à enregistrer. */
function journey_evidence_of(array $x, string $kind): array
{
    $e = ['kind' => $kind, 'xp_id' => (int) $x['id'], 'project_id' => (int) $x['project_id'], 'studio_id' => (int) $x['studio_id'], 'card_id' => (int) $x['card_id'], 'at' => (int) $x['at']];
    if ($x['kind'] === 'approval' && ($a = row('SELECT approver_uid, approver_guest FROM card_approvals WHERE id = ?', [$x['ref']]))) {
        $e += ['approval_id' => (int) $x['ref'], 'client_uid' => (int) $a['approver_uid'], 'client_guest' => (int) $a['approver_guest']];
    }
    return $e;
}
/** Ce qui débloque chaque réalisation : null, ou [moment, preuves]. */
function journey_unlock(string $aid, int $uid, array $st): ?array
{
    $p = journey_rules()['params'];
    switch ($aid) {
        case 'first_studio':
            $x = journey_first($uid, 'first_studio');
            return $x ? [(int) $x['at'], [journey_evidence_of($x, 'studio')]] : null;
        case 'first_delivery':
            $x = journey_first($uid, 'done');
            return $x ? [(int) $x['at'], [journey_evidence_of($x, 'card')]] : null;
        case 'project_finisher':
            $x = journey_first($uid, 'project');
            return $x ? [(int) $x['at'], [journey_evidence_of($x, 'project')]] : null;
        case 'client_approved':
            $x = journey_first($uid, 'approval');
            return $x ? [(int) $x['at'], [journey_evidence_of($x, 'approval')]] : null;
        case 'team_player':
            $x = journey_team_entry($uid);
            return $x ? [(int) $x['at'], [journey_evidence_of($x, 'collab')]] : null;
        case 'proven_creator':
            if ($st['projects'] < $p['proven_projects'] || $st['approvals'] < $p['proven_approvals'] || $st['approval_projects'] < $p['proven_approval_projects']) return null;
            $ev = [];
            foreach (rows("SELECT * FROM xp_transactions WHERE uid = ? AND kind = 'project' AND revoked = 0 ORDER BY at LIMIT " . (int) $p['proven_projects'], [$uid]) as $x) $ev[] = journey_evidence_of($x, 'project');
            foreach (rows("SELECT * FROM xp_transactions WHERE uid = ? AND kind = 'approval' AND revoked = 0 ORDER BY at LIMIT " . (int) max($p['proven_approvals'], $p['proven_approval_projects']), [$uid]) as $x) $ev[] = journey_evidence_of($x, 'approval');
            return [time(), $ev];
    }
    return null;
}
/** Enregistre les réalisations débloquées (une fois ; une réalisation retirée ne revient jamais d'elle-même). */
function journey_achievements_sync(int $uid, array $st): void
{
    $rules = journey_rules();
    $have = array_column(rows("SELECT aid FROM user_achievements WHERE uid = ? AND aid <> 'self'", [$uid]), 'aid');
    foreach (JOURNEY_ACHIEVEMENTS as $aid => $def) {
        if (in_array($aid, $have, true) || !$rules['active'][$aid]) continue;
        $got = journey_unlock($aid, $uid, $st);
        if (!$got) continue;
        $st0 = q('INSERT OR IGNORE INTO user_achievements (uid, aid, status, verification, public_id, earned, xp, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$uid, $aid, 'verified', $def['verification'], bin2hex(random_bytes(8)), $got[0], $def['xp'], time(), time()]);
        if ($st0->rowCount() === 0) continue;
        $ua = (int) db()->lastInsertId();
        foreach ($got[1] as $e) {
            insert('achievement_evidence', $e + ['ua_id' => $ua]);
            if (!empty($e['client_uid'])) {
                notify((int) $e['client_uid'], $uid, 'journey', 'can show your approval on their verified achievement “' . $def['title'] . '”. Choose whether your name may appear.', 'achievements/settings');
            }
        }
    }
}
function journey_req_value(string $k, array $st): int
{
    return (int) ($st[$k] ?? 0);
}
function journey_level_num(array $st): int
{
    $level = 1;
    foreach (journey_rules()['levels'] as $n => $l) {
        if ($n === 1) continue;
        foreach ($l['req'] as $k => $need) if (journey_req_value($k, $st) < $need) return $level;
        $level = $n;
    }
    return $level;
}
function journey_criteria(array $req, array $st): array
{
    $out = [];
    foreach ($req as $k => $need) {
        $have = journey_req_value($k, $st);
        $out[] = ['key' => $k, 'label' => sprintf(JOURNEY_CRITERIA[$k], number_format($need)), 'have' => min($have, $need), 'need' => $need, 'ok' => $have >= $need];
    }
    return $out;
}
function journey_level_view(array $st): array
{
    $levels = journey_rules()['levels'];
    $n = (int) $st['level'];
    $out = ['n' => $n, 'name' => $levels[$n]['name'], 'about' => $levels[$n]['about'], 'xp' => (int) $st['xp'], 'next' => null];
    if (isset($levels[$n + 1])) {
        $next = $levels[$n + 1];
        $crit = journey_criteria($next['req'], $st);
        $pct = $crit ? array_sum(array_map(function (array $c): float { return $c['need'] > 0 ? min(1, $c['have'] / $c['need']) : 1; }, $crit)) / count($crit) : 1;
        $floor = (int) ($levels[$n]['req']['xp'] ?? 0);
        $target = (int) ($next['req']['xp'] ?? $floor);
        $out['next'] = ['n' => $n + 1, 'name' => $next['name'], 'about' => $next['about'], 'criteria' => $crit, 'percent' => (int) floor($pct * 100),
            'xp_target' => $target, 'xp_percent' => $target > 0 ? (int) min(100, floor($st['xp'] * 100 / $target)) : 100];
    }
    return $out;
}
function journey_levels_view(array $st): array
{
    $out = [];
    foreach (journey_rules()['levels'] as $n => $l) $out[] = ['n' => $n, 'name' => $l['name'], 'about' => $l['about'], 'criteria' => journey_criteria($l['req'], $st), 'reached' => $n <= (int) $st['level']];
    return $out;
}

/* ------------------------------------------------------------- réalisations */

function journey_url(string $publicId): string
{
    return site_url() . 'verify/' . $publicId;
}
function passport_url(string $handle): string
{
    return site_url() . 'creator/' . rawurlencode($handle);
}
/** Libellé de vérification affiché : jamais « vérifié » pour une déclaration ou une réalisation retirée. */
function journey_status_label(array $ua): string
{
    if ($ua['status'] === 'revoked') return 'Revoked';
    if ($ua['status'] === 'self') return 'Self Reported — not independently verified';
    return JOURNEY_VERIFICATIONS[$ua['verification']] ?? 'Verified';
}
/** Progression d'une réalisation pas encore obtenue. */
function journey_ach_progress(string $aid, array $st): array
{
    $p = journey_rules()['params'];
    $parts = [];
    switch ($aid) {
        case 'first_studio': $parts = [['label' => 'Create a Studio', 'have' => min(1, $st['first_studio']), 'need' => 1]]; break;
        case 'first_delivery': $parts = [['label' => 'Complete an eligible card', 'have' => min(1, $st['done']), 'need' => 1]]; break;
        case 'project_finisher': $parts = [['label' => 'Complete a project', 'have' => min(1, $st['projects']), 'need' => 1]]; break;
        case 'client_approved': $parts = [['label' => 'Receive a client approval', 'have' => min(1, $st['approvals']), 'need' => 1]]; break;
        case 'team_player': $parts = [['label' => 'Complete a card with another verified member', 'have' => $st['team'] ? 1 : 0, 'need' => 1]]; break;
        case 'proven_creator':
            $parts = [
                ['label' => 'Completed projects', 'have' => min($st['projects'], $p['proven_projects']), 'need' => $p['proven_projects']],
                ['label' => 'Client approvals', 'have' => min($st['approvals'], $p['proven_approvals']), 'need' => $p['proven_approvals']],
                ['label' => 'Projects with a client approval', 'have' => min($st['approval_projects'], $p['proven_approval_projects']), 'need' => $p['proven_approval_projects']],
            ];
            break;
    }
    $pct = $parts ? array_sum(array_map(function (array $x): float { return min(1, $x['have'] / max(1, $x['need'])); }, $parts)) / count($parts) : 0;
    return ['parts' => $parts, 'percent' => (int) floor($pct * 100)];
}
function journey_ua_view(array $ua): array
{
    $def = JOURNEY_ACHIEVEMENTS[$ua['aid']] ?? null;
    $public = $ua['visibility'] !== 'private' && $ua['status'] !== 'revoked';
    return [
        'id' => (int) $ua['id'], 'aid' => (string) $ua['aid'],
        'title' => $def ? $def['title'] : (string) $ua['title'], 'desc' => $def ? $def['desc'] : (string) $ua['body'],
        'icon' => $def ? $def['icon'] : 'star', 'category' => $def ? $def['category'] : 'Self reported',
        'verification' => (string) $ua['verification'], 'label' => journey_status_label($ua),
        'status' => (string) $ua['status'], 'earned' => (int) $ua['earned'], 'xp' => (int) $ua['xp'],
        'visibility' => (string) $ua['visibility'], 'url' => $public ? journey_url((string) $ua['public_id']) : '',
        'public_id' => (string) $ua['public_id'], 'link' => (string) $ua['url'],
        'revoked_reason' => $ua['status'] === 'revoked' ? (string) $ua['revoked_reason'] : '',
        'shareable' => $ua['status'] !== 'revoked',
    ];
}
/** Toutes les réalisations d'un compte : obtenues, en attente de vérification, en cours, déclarées. */
function journey_achievements_view(int $uid, array $st): array
{
    $rules = journey_rules();
    $rows = rows('SELECT * FROM user_achievements WHERE uid = ? ORDER BY earned DESC, id DESC', [$uid]);
    $by = [];
    $out = [];
    foreach ($rows as $ua) {
        if ($ua['aid'] !== 'self') $by[$ua['aid']] = true;
        $out[] = journey_ua_view($ua) + ['tab' => $ua['status'] === 'self' ? 'self' : 'verified'];
    }
    foreach (JOURNEY_ACHIEVEMENTS as $aid => $def) {
        if (isset($by[$aid]) || !$rules['active'][$aid]) continue;
        $pending = ($aid === 'client_approved' && $st['pending_approvals'] > 0)
            || ($aid === 'proven_creator' && $st['projects'] >= $rules['params']['proven_projects'] && $st['pending_approvals'] > 0);
        $out[] = ['id' => 0, 'aid' => $aid, 'title' => $def['title'], 'desc' => $def['desc'], 'icon' => $def['icon'], 'category' => $def['category'],
            'verification' => $def['verification'], 'label' => $pending ? 'Pending verification' : JOURNEY_VERIFICATIONS[$def['verification']],
            'status' => $pending ? 'pending' : 'progress', 'earned' => 0, 'xp' => $def['xp'], 'visibility' => 'private', 'url' => '', 'shareable' => false,
            'progress' => journey_ach_progress($aid, $st), 'tab' => $pending ? 'pending' : 'progress',
            'pending_note' => $pending ? 'Waiting for ' . $st['pending_approvals'] . ' client approval request' . ($st['pending_approvals'] > 1 ? 's' : '') . ' to be answered.' : ''];
    }
    return $out;
}

/* ---------------------------------------------------------- jalons suivants */

/** Peut-on créer un projet, partager, demander une validation ? (pour ne jamais proposer ce que la formule refuse) */
function journey_access(int $uid): array
{
    $managed = rows("SELECT p.* FROM projects p JOIN project_members m ON m.project_id = p.id WHERE m.uid = ? AND m.role IN ('owner', 'manager') AND p.archived = 0", [$uid]);
    $all = rows('SELECT DISTINCT p.* FROM projects p LEFT JOIN project_members m ON m.project_id = p.id AND m.uid = ? LEFT JOIN studios s ON s.project_id = p.id
        LEFT JOIN studio_members sm ON sm.studio_id = s.id AND sm.uid = ? WHERE p.archived = 0 AND (m.uid IS NOT NULL OR sm.uid IS NOT NULL)', [$uid, $uid]);
    $feature = function (array $ps, string $f): bool {
        foreach ($ps as $p) if (plan_has(project_account($p), $f)) return true;
        return false;
    };
    return [
        'studios' => (bool) $all, 'manage' => (bool) $managed,
        'share' => $feature($managed, 'team') || $feature($managed, 'private_links'),
        'approvals' => $feature($all, 'approvals'),
        'others' => (bool) val('SELECT 1 FROM project_members a JOIN project_members b ON b.project_id = a.project_id AND b.uid <> a.uid WHERE a.uid = ? LIMIT 1', [$uid])
            || (bool) val('SELECT 1 FROM studio_members a JOIN studio_members b ON b.studio_id = a.studio_id AND b.uid <> a.uid WHERE a.uid = ? LIMIT 1', [$uid]),
    ];
}
/** Prochains jalons concrets, dans l'ordre du parcours, sans ceux déjà franchis ni ceux que la formule ne permet pas. */
function journey_next(int $uid, array $st, int $n = 3): array
{
    $ev = journey_rules()['events'];
    $acc = journey_access($uid);
    $c = [];
    if (!$st['first_studio']) {
        $c[] = ['key' => 'studio', 'title' => 'Create your first Studio', 'desc' => 'Set up a workspace for a brand, a client or a campaign: it comes with a ready-to-use content workflow.',
            'have' => 0, 'need' => 1, 'reward' => $ev['first_studio']['xp'] + ($st['first_project'] ? 0 : $ev['first_project']['xp']), 'cta' => 'Create a project', 'href' => '#/projects'];
    }
    if ($acc['studios'] && $st['done'] < 1) {
        $c[] = ['key' => 'first_card', 'title' => 'Complete your first card', 'desc' => 'Give a card a real description, an attachment or a checklist, then move it to a “done” column.',
            'have' => 0, 'need' => 1, 'reward' => $ev['done']['xp'], 'cta' => 'Open my work', 'href' => '#/work'];
    } elseif ($acc['studios'] && $st['done'] < 5) {
        $c[] = ['key' => 'five_cards', 'title' => 'Complete your first five cards', 'desc' => 'Real deliveries build your record: each completed eligible card counts.',
            'have' => $st['done'], 'need' => 5, 'reward' => $ev['done']['xp'] * (5 - $st['done']), 'cta' => 'Open my work', 'href' => '#/work'];
    }
    if ($acc['share'] && $st['shares'] < 1) {
        $c[] = ['key' => 'share', 'title' => 'Share a Studio with a collaborator', 'desc' => 'Invite a teammate or a client to a Studio. It counts when they join.',
            'have' => 0, 'need' => 1, 'reward' => $ev['share']['xp'], 'cta' => 'Open my projects', 'href' => '#/projects'];
    }
    if (($acc['manage'] || $st['done'] > 0) && $st['projects'] < 1) {
        $c[] = ['key' => 'project', 'title' => 'Finish your first project', 'desc' => 'When every card of a project is done or cancelled, its owner can mark it as completed.',
            'have' => 0, 'need' => 1, 'reward' => $ev['project']['xp'], 'cta' => 'Open my projects', 'href' => '#/projects'];
    }
    if ($acc['approvals'] && $st['approvals'] < 1) {
        $c[] = ['key' => 'approval', 'title' => 'Receive your first client approval', 'desc' => 'Ask a client of your Studio to approve a finished card from the card\'s Approval section.',
            'have' => 0, 'need' => 1, 'reward' => $ev['approval']['xp'], 'cta' => 'Open my work', 'href' => '#/work'];
    }
    if ($acc['others'] && !$st['team'] && $st['done'] > 0) {
        $c[] = ['key' => 'team', 'title' => 'Complete a card together with a teammate', 'desc' => 'Add a collaborator to a card (or assign it to them) and finish it together.',
            'have' => 0, 'need' => 1, 'reward' => $ev['done']['xp'], 'cta' => 'Open my work', 'href' => '#/work'];
    }
    $lv = journey_level_view($st);
    if ($lv['next']) {
        $c[] = ['key' => 'level', 'title' => 'Reach Level ' . sprintf('%02d', $lv['next']['n']) . ' — ' . $lv['next']['name'], 'desc' => $lv['next']['about'],
            'have' => $lv['next']['percent'], 'need' => 100, 'reward' => 0, 'cta' => 'See the criteria', 'href' => '#/achievements', 'percent' => true];
    }
    return array_slice($c, 0, $n);
}

/* ------------------------------------------------------------- chronologie */

const JOURNEY_TIMELINE_FILTERS = ['all', 'achievements', 'projects', 'collaboration', 'clients'];
function journey_timeline(int $uid, string $filter = 'all', bool $public = false): array
{
    $items = [];
    $add = function (string $cat, int $at, string $icon, string $text) use (&$items): void { $items[] = ['cat' => $cat, 'at' => $at, 'icon' => $icon, 'text' => $text]; };
    $u = row('SELECT created FROM users WHERE id = ?', [$uid]);
    if ($u) $add('all', (int) $u['created'], 'sparkles', 'Joined Studioma');
    $achSql = $public ? "SELECT * FROM user_achievements WHERE uid = ? AND status = 'verified' AND visibility = 'passport'" : "SELECT * FROM user_achievements WHERE uid = ? AND status <> 'revoked'";
    foreach (rows($achSql, [$uid]) as $ua) {
        $v = journey_ua_view($ua);
        $add('achievements', (int) $ua['earned'], $v['icon'], ($ua['status'] === 'self' ? 'Added a self-reported achievement: ' : 'Earned “') . $v['title'] . ($ua['status'] === 'self' ? '' : '” · ' . $v['label']));
    }
    foreach (rows("SELECT * FROM journey_log WHERE uid = ? AND kind = 'level'", [$uid]) as $l) $add('achievements', (int) $l['created'], 'trophy', (string) $l['text']);
    if (!$public) {
        $labels = [
            'first_studio' => ['projects', 'board', 'Created a first Studio'], 'first_project' => ['projects', 'folder', 'Created a first project'],
            'project' => ['projects', 'flag', 'Completed a project'], 'level' => ['projects', 'layers', 'Cleared a workflow level'],
            'share' => ['collaboration', 'user-plus', 'Shared a Studio with a collaborator'], 'approval' => ['clients', 'shield', 'Received a client approval'],
            'publish' => ['achievements', 'globe', 'Published a completed achievement in the Community'],
        ];
        foreach (rows("SELECT kind, at FROM xp_transactions WHERE uid = ? AND revoked = 0 AND kind IN ('" . implode("', '", array_keys($labels)) . "') ORDER BY at DESC LIMIT 300", [$uid]) as $x) {
            [$cat, $icon, $text] = $labels[$x['kind']];
            $add($cat, (int) $x['at'], $icon, $text);
        }
        foreach (rows("SELECT substr(day, 1, 7) AS m, COUNT(*) AS n, MAX(at) AS at FROM xp_transactions WHERE uid = ? AND kind = 'done' AND revoked = 0 GROUP BY m ORDER BY m DESC LIMIT 24", [$uid]) as $m) {
            $add('projects', (int) $m['at'], 'check-circle', 'Completed ' . $m['n'] . ' eligible card' . ((int) $m['n'] > 1 ? 's' : '') . ' in ' . date('F Y', strtotime($m['m'] . '-01')));
        }
    }
    if ($filter !== 'all') $items = array_values(array_filter($items, function (array $i) use ($filter): bool { return $i['cat'] === $filter; }));
    usort($items, function (array $a, array $b): int { return $b['at'] <=> $a['at']; });
    return array_slice($items, 0, 200);
}
/** Résumé factuel tiré des seuls enregistrements (jamais embelli). */
function journey_summary(array $u, array $st, bool $withLevel = true): string
{
    $parts = ['Member since ' . date('F Y', (int) $u['created'])];
    if ($withLevel) $parts[] = 'Level ' . sprintf('%02d', (int) $st['level']) . ' — ' . journey_rules()['levels'][(int) $st['level']]['name'];
    if ($st['projects']) $parts[] = $st['projects'] . ' completed project' . ($st['projects'] > 1 ? 's' : '');
    if ($st['done']) $parts[] = $st['done'] . ' completed card' . ($st['done'] > 1 ? 's' : '');
    if ($st['approvals']) $parts[] = $st['approvals'] . ' client approval' . ($st['approvals'] > 1 ? 's' : '');
    if ($st['shares']) $parts[] = $st['shares'] . ' shared Studio' . ($st['shares'] > 1 ? 's' : '');
    return implode(' · ', $parts) . '.';
}

/* -------------------------------------------------------------- passeport */

function passport_settings(int $uid): array
{
    $p = row('SELECT * FROM passport_settings WHERE uid = ?', [$uid]);
    return [
        'enabled' => $p ? (bool) $p['enabled'] : false, 'headline' => $p ? (string) $p['headline'] : '',
        'show_level' => $p ? (bool) $p['show_level'] : true, 'show_stats' => $p ? (bool) $p['show_stats'] : true,
        'show_work' => $p ? (bool) $p['show_work'] : true, 'show_journey' => $p ? (bool) $p['show_journey'] : true,
        'views' => $p ? (int) $p['views'] : 0,
    ];
}

/* -------------------------------------------------------------------- API */

function journey_profile(array $u): array
{
    return array_merge(user_card($u), ['title' => (string) $u['title'] !== '' ? (string) $u['title'] : 'Creative Professional', 'joined' => (int) $u['created']]);
}
function journey_stats_view(int $uid, array $st): array
{
    $n = rows("SELECT verification, status, COUNT(*) AS n FROM user_achievements WHERE uid = ? GROUP BY verification, status", [$uid]);
    $sum = function (callable $f) use ($n): int { return array_sum(array_map(function (array $r) use ($f): int { return $f($r) ? (int) $r['n'] : 0; }, $n)); };
    return [
        'achievements' => $sum(function (array $r): bool { return $r['status'] !== 'revoked'; }),
        'verified' => $sum(function (array $r): bool { return $r['status'] === 'verified'; }),
        'client' => $sum(function (array $r): bool { return $r['status'] === 'verified' && $r['verification'] === 'client'; }),
        'projects' => (int) $st['projects'], 'done' => (int) $st['done'], 'approvals' => (int) $st['approvals'], 'shares' => (int) $st['shares'],
    ];
}
/** GET journey : le tableau de bord « My Achievements ». */
function api_journey(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $st = journey_eval($uid);
    $pp = passport_settings($uid);
    return [
        'profile' => journey_profile($u), 'level' => journey_level_view($st), 'levels' => journey_levels_view($st),
        'stats' => journey_stats_view($uid, $st), 'summary' => journey_summary($u, $st),
        'achievements' => journey_achievements_view($uid, $st), 'next' => journey_next($uid, $st, 3),
        'events' => array_map(function (array $e): array { return ['label' => $e['label'], 'xp' => (int) $e['xp'], 'cap' => (int) $e['cap']]; }, array_values(journey_rules()['events'])),
        'passport' => $pp + ['url' => passport_url((string) $u['handle'])],
        'self_max' => JOURNEY_SELF_MAX,
    ];
}
/** Progression à jour : celle gardée en cache si rien n'a changé depuis, sinon recalculée. */
function journey_state(int $uid): array
{
    $p = row('SELECT stats, dirty FROM journey_progress WHERE uid = ?', [$uid]);
    $st = $p && (int) $p['dirty'] === 0 ? json_decode((string) $p['stats'], true) : null;
    return is_array($st) && isset($st['level'], $st['xp'], $st['team']) ? $st : journey_eval($uid);
}
/** GET journey_next : le jalon suivant (accueil). */
function api_journey_next(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $st = journey_state($uid);
    return ['level' => journey_level_view($st), 'next' => journey_next($uid, $st, 1)];
}
function journey_own_ua(int $id, array $u): array
{
    $ua = row('SELECT * FROM user_achievements WHERE id = ? AND uid = ?', [$id, $u['id']]);
    if (!$ua) not_found('This achievement');
    return $ua;
}
/**
 * Preuves d'une réalisation. mode : owner (noms des projets, Studios et cartes seulement si la personne y a encore accès) |
 * public (aucun nom de projet, de client ou de fichier ; le nom du client seulement s'il l'a accepté) | admin (tout).
 */
function journey_evidence_view(array $ua, array $u, string $mode): array
{
    $public = $mode === 'public';
    $out = [];
    $labels = ['studio' => 'Studio creation record', 'card' => 'Completed card record', 'project' => 'Completed project record', 'approval' => 'Client approval record', 'collab' => 'Shared card completion record'];
    foreach (rows('SELECT * FROM achievement_evidence WHERE ua_id = ? ORDER BY at', [$ua['id']]) as $e) {
        $v = ['kind' => (string) $e['kind'], 'label' => $labels[$e['kind']] ?? 'Record', 'at' => (int) $e['at']];
        if ((int) $e['client_uid'] > 0 || (int) $e['client_guest'] > 0) {
            $name = '';
            if ((int) $e['client_uid'] > 0 && ((int) $e['client_named'] === 1 || $mode === 'admin')) $name = (string) (val('SELECT name FROM users WHERE id = ?', [$e['client_uid']]) ?? '');
            $v['client'] = $name !== '' ? 'Approved by ' . $name : ((int) $e['client_guest'] > 0 ? 'Approved by a verified guest client' : 'Approved by a verified client');
            if ($mode === 'admin') $v['client'] .= (int) $e['client_uid'] > 0 ? ' (account #' . $e['client_uid'] . ($e['client_named'] ? ', name allowed' : ', name hidden') . ')' : ' (guest ' . (string) (val('SELECT email FROM share_guests WHERE id = ?', [$e['client_guest']]) ?? '?') . ')';
        }
        if (!$public) {
            $p = (int) $e['project_id'] ? row('SELECT * FROM projects WHERE id = ?', [$e['project_id']]) : null;
            $access = $p && ($mode === 'admin' || project_role((int) $u['id'], $p) !== '' || val('SELECT 1 FROM studio_members sm JOIN studios s ON s.id = sm.studio_id WHERE s.project_id = ? AND sm.uid = ?', [$p['id'], $u['id']]));
            if ($p && $access) {
                $v['project'] = (string) $p['name'];
                if ((int) $e['studio_id'] && ($s = row('SELECT id, name FROM studios WHERE id = ?', [$e['studio_id']]))) $v['studio'] = (string) $s['name'];
                if ((int) $e['card_id'] && ($c = row('SELECT id, studio_id, title FROM cards WHERE id = ?', [$e['card_id']]))) {
                    $v['card'] = (string) $c['title'];
                    if ($mode === 'owner') $v['href'] = '#/studio/' . $c['studio_id'] . '/card/' . $c['id'];
                } elseif ((int) $e['project_id'] && $mode === 'owner') {
                    $v['href'] = '#/project/' . $p['id'];
                }
            } elseif ((int) $e['project_id'] || (int) $e['card_id']) {
                $v['note'] = $p ? 'You no longer have access to this project: its details stay private.' : 'The underlying record was deleted after the achievement was earned.';
            }
            if ($e['xp_id'] && ($x = row('SELECT xp, revoked FROM xp_transactions WHERE id = ?', [$e['xp_id']]))) $v['xp'] = (int) $x['revoked'] ? 0 : (int) $x['xp'];
        }
        $out[] = $v;
    }
    return $out;
}
/** GET journey_achievement {id} (réalisation obtenue) ou {aid} (définition) : la page de détails. */
function api_journey_achievement(array $b, array $u): array
{
    $uid = (int) $u['id'];
    if (int_in($b, 'id')) {
        $ua = journey_own_ua(int_in($b, 'id'), $u);
        $def = JOURNEY_ACHIEVEMENTS[$ua['aid']] ?? null;
        $v = journey_ua_view($ua) + [
            'evidence_label' => $def ? $def['evidence'] : ($ua['url'] !== '' ? 'Link provided by the creator' : 'Description provided by the creator'),
            'proves' => $def ? $def['proves'] : 'This achievement was added by its owner. Studioma did not check it.',
            'not' => $def ? $def['not'] : 'It is not verified by Studioma or by a client.',
            'evidence' => journey_evidence_view($ua, $u, 'owner'), 'body' => (string) $ua['body'], 'shares' => (int) $ua['shared'], 'views' => (int) $ua['views'],
        ];
        return ['achievement' => $v, 'passport' => passport_settings($uid) + ['url' => passport_url((string) $u['handle'])]];
    }
    $aid = (string) ($b['aid'] ?? '');
    if (!isset(JOURNEY_ACHIEVEMENTS[$aid])) not_found('This achievement');
    $own = row('SELECT id FROM user_achievements WHERE uid = ? AND aid = ?', [$uid, $aid]);
    if ($own) return api_journey_achievement(['id' => $own['id']], $u);
    $st = journey_eval($uid);
    foreach (journey_achievements_view($uid, $st) as $a) {
        if ($a['aid'] !== $aid) continue;
        $def = JOURNEY_ACHIEVEMENTS[$aid];
        return ['achievement' => $a + ['evidence_label' => $def['evidence'], 'proves' => $def['proves'], 'not' => $def['not'], 'evidence' => []]];
    }
    not_found('This achievement');
}
/** POST journey_visibility {id, visibility} : privée, visible avec le lien, ou aussi sur le passeport. */
function api_journey_visibility(array $b, array $u): array
{
    $ua = journey_own_ua(int_in($b, 'id'), $u);
    $vis = in_array($b['visibility'] ?? '', ['private', 'link', 'passport'], true) ? (string) $b['visibility'] : 'private';
    if ($ua['status'] === 'revoked' && $vis !== 'private') fail('A revoked achievement can\'t be shared.');
    q('UPDATE user_achievements SET visibility = ?, updated = ? WHERE id = ?', [$vis, time(), $ua['id']]);
    return ['achievement' => journey_ua_view(row('SELECT * FROM user_achievements WHERE id = ?', [$ua['id']]))];
}
/** POST journey_shared {id} : la personne a copié le lien, partagé ou téléchargé l'image (statistiques seulement). */
function api_journey_shared(array $b, array $u): array
{
    $ua = journey_own_ua(int_in($b, 'id'), $u);
    q('UPDATE user_achievements SET shared = shared + 1 WHERE id = ?', [$ua['id']]);
    return [];
}
/** POST journey_self_save {id?, title, body, date, url} : réalisation déclarée (jamais présentée comme vérifiée, sans XP). */
function api_journey_self_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $title = line_clean($b['title'] ?? '', 100);
    if (mb_strlen($title) < 3) fail('Give this achievement a title.');
    $body = str_clean($b['body'] ?? '', 1000);
    $url = url_clean($b['url'] ?? '');
    $date = date_clean($b['date'] ?? '');
    $at = $date !== '' ? min(time(), (int) strtotime($date . ' 12:00')) : time();
    $id = int_in($b, 'id');
    if ($id) {
        $ua = journey_own_ua($id, $u);
        if ($ua['aid'] !== 'self') fail('Only self-reported achievements can be edited.');
        q('UPDATE user_achievements SET title = ?, body = ?, url = ?, earned = ?, updated = ? WHERE id = ?', [$title, $body, $url, $at, time(), $id]);
    } else {
        rate('journey_self', 30, 86400, 'u' . $uid);
        if ((int) val("SELECT COUNT(*) FROM user_achievements WHERE uid = ? AND aid = 'self'", [$uid]) >= JOURNEY_SELF_MAX) fail('You can keep up to ' . JOURNEY_SELF_MAX . ' self-reported achievements.');
        $id = insert('user_achievements', ['uid' => $uid, 'aid' => 'self', 'status' => 'self', 'verification' => 'self', 'public_id' => bin2hex(random_bytes(8)),
            'title' => $title, 'body' => $body, 'url' => $url, 'earned' => $at, 'seen' => 1, 'created' => time(), 'updated' => time()]);
    }
    return ['achievement' => journey_ua_view(row('SELECT * FROM user_achievements WHERE id = ?', [$id]))];
}
function api_journey_self_delete(array $b, array $u): array
{
    $ua = journey_own_ua(int_in($b, 'id'), $u);
    if ($ua['aid'] !== 'self') fail('Verified achievements can\'t be deleted.');
    q('DELETE FROM user_achievements WHERE id = ?', [$ua['id']]);
    return [];
}
/** GET journey_timeline {filter} */
function api_journey_timeline(array $b, array $u): array
{
    $f = in_array($b['filter'] ?? '', JOURNEY_TIMELINE_FILTERS, true) ? (string) $b['filter'] : 'all';
    return ['items' => journey_timeline((int) $u['id'], $f)];
}
/** GET journey_celebrations : ce qui n'a pas encore été célébré (réalisations, niveau atteint), et le jalon suivant. */
function api_journey_celebrations(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $items = [];
    foreach (rows("SELECT * FROM user_achievements WHERE uid = ? AND seen = 0 AND status = 'verified' ORDER BY earned", [$uid]) as $ua) {
        $def = JOURNEY_ACHIEVEMENTS[$ua['aid']] ?? null;
        if (!$def) continue;
        $items[] = ['kind' => 'achievement', 'id' => (int) $ua['id'], 'title' => $def['title'], 'icon' => $def['icon'], 'text' => $def['celebrate'], 'xp' => (int) $ua['xp'], 'label' => journey_status_label($ua)];
    }
    foreach (rows("SELECT * FROM journey_log WHERE uid = ? AND seen = 0 AND kind = 'level' ORDER BY id", [$uid]) as $l) {
        $items[] = ['kind' => 'level', 'id' => (int) $l['id'], 'title' => (string) $l['text'], 'icon' => 'trophy', 'text' => 'Congratulations! You\'ve reached a new milestone in your Creator Journey.', 'xp' => 0];
    }
    return ['items' => $items, 'next' => $items ? journey_next($uid, journey_state($uid), 1) : []];
}
/** POST journey_seen : célébrations vues (ou fermées). */
function api_journey_seen(array $b, array $u): array
{
    q('UPDATE user_achievements SET seen = 1 WHERE uid = ? AND seen = 0', [$u['id']]);
    q('UPDATE journey_log SET seen = 1 WHERE uid = ? AND seen = 0', [$u['id']]);
    return [];
}
/** GET journey_settings : passeport, visibilité de chaque réalisation, validations données en tant que client. */
function api_journey_settings(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $mine = array_map('journey_ua_view', rows("SELECT * FROM user_achievements WHERE uid = ? ORDER BY status = 'revoked', earned DESC", [$uid]));
    $given = [];
    foreach (rows('SELECT e.id, e.client_named, e.at, ua.uid, ua.aid FROM achievement_evidence e JOIN user_achievements ua ON ua.id = e.ua_id WHERE e.client_uid = ? ORDER BY e.at DESC LIMIT 200', [$uid]) as $e) {
        $given[] = ['id' => (int) $e['id'], 'named' => (bool) $e['client_named'], 'at' => (int) $e['at'], 'creator' => user_cards([(int) $e['uid']])[(int) $e['uid']] ?? null,
            'achievement' => JOURNEY_ACHIEVEMENTS[$e['aid']]['title'] ?? 'Achievement'];
    }
    return ['passport' => passport_settings($uid) + ['url' => passport_url((string) $u['handle'])], 'achievements' => $mine, 'given' => $given, 'self_max' => JOURNEY_SELF_MAX];
}
/** POST journey_passport_save : ouvrir ou fermer le passeport public, et ce qu'il montre. */
function api_journey_passport_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    q('INSERT OR IGNORE INTO passport_settings (uid) VALUES (?)', [$uid]);
    q('UPDATE passport_settings SET enabled = ?, headline = ?, show_level = ?, show_stats = ?, show_work = ?, show_journey = ?, updated = ? WHERE uid = ?', [
        empty($b['enabled']) ? 0 : 1, line_clean($b['headline'] ?? '', 120), empty($b['show_level']) ? 0 : 1, empty($b['show_stats']) ? 0 : 1,
        empty($b['show_work']) ? 0 : 1, empty($b['show_journey']) ? 0 : 1, time(), $uid,
    ]);
    return ['passport' => passport_settings($uid) + ['url' => passport_url((string) $u['handle'])]];
}
/** POST journey_consent {id, named} : la personne cliente accepte (ou non) que son nom apparaisse sur la réalisation. */
function api_journey_consent(array $b, array $u): array
{
    $e = row('SELECT * FROM achievement_evidence WHERE id = ? AND client_uid = ?', [int_in($b, 'id'), $u['id']]);
    if (!$e) not_found('This approval');
    q('UPDATE achievement_evidence SET client_named = ? WHERE id = ?', [empty($b['named']) ? 0 : 1, $e['id']]);
    return [];
}

/* --------------------------------------------- après chaque requête (api.php) */

/** Actions qui peuvent faire avancer le parcours de la personne qui agit. */
const JOURNEY_ACTIONS = ['card_create', 'card_save', 'card_move', 'card_handoff', 'cards_bulk', 'check_save', 'upload', 'approval_request', 'approval_decide',
    'invite_accept', 'pub_save', 'pub_status', 'project_save', 'project_complete', 'studio_create', 'share_save', 'share_guest', 'invite_create', 'member_role'];
/**
 * Recalcule le parcours de la personne connectée si quelque chose a changé, et signale ce qu'il reste à célébrer
 * (« _journey » dans la réponse, lu par assets/journey.js).
 */
function journey_after(string $action, bool $post, ?array $u): array
{
    if (!$u || empty($u['id']) || !empty($u['guest'])) return [];
    if (!$post && !in_array($action, ['me', 'counts'], true)) return [];
    try {
        $uid = (int) $u['id'];
        if ($post && in_array($action, JOURNEY_ACTIONS, true)) journey_dirty([$uid]);
        $d = val('SELECT dirty FROM journey_progress WHERE uid = ?', [$uid]);
        if ($d === null || (int) $d === 1) journey_eval($uid);
        $n = (int) val("SELECT (SELECT COUNT(*) FROM user_achievements WHERE uid = ? AND seen = 0 AND status = 'verified') + (SELECT COUNT(*) FROM journey_log WHERE uid = ? AND seen = 0)", [$uid, $uid]);
        return $n > 0 ? ['_journey' => ['celebrate' => $n]] : [];
    } catch (Throwable $e) {
        // Le parcours ne doit jamais faire échouer l'action elle-même.
        tx_rollback();
        error_log('studioma journey: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        return [];
    }
}

/* ------------------------------------------------------------ administration */

function api_admin_journey(array $b, array $a): array
{
    $rules = journey_rules();
    $t30 = time() - 30 * 86400;
    $verified = (int) val("SELECT COUNT(*) FROM user_achievements WHERE status = 'verified'");
    $byAch = [];
    foreach (rows("SELECT aid, status, COUNT(*) AS n FROM user_achievements GROUP BY aid, status") as $r) $byAch[$r['aid']][$r['status']] = (int) $r['n'];
    $buckets = [[0, 0], [1, 99], [100, 499], [500, 1499], [1500, 2999], [3000, 5999], [6000, PHP_INT_MAX]];
    $dist = [];
    foreach ($buckets as [$lo, $hi]) $dist[] = ['label' => $hi === PHP_INT_MAX ? $lo . '+' : ($lo === $hi ? (string) $lo : $lo . '–' . $hi), 'n' => (int) val('SELECT COUNT(*) FROM journey_progress WHERE xp BETWEEN ? AND ?', [$lo, $hi])];
    $levels = [];
    foreach ($rules['levels'] as $n => $l) $levels[] = ['n' => $n, 'name' => $l['name'], 'users' => (int) val('SELECT COUNT(*) FROM journey_progress WHERE level = ?', [$n])];
    // Activité à examiner (aucune sanction automatique) : limites du jour atteintes souvent, gains très rapides, validations
    // en série d'un même client pour une même personne.
    $flags = [];
    foreach (rows("SELECT uid, COUNT(DISTINCT day) AS d FROM xp_transactions WHERE note = 'daily limit' AND at > ? GROUP BY uid HAVING d >= 3 LIMIT 50", [time() - 14 * 86400]) as $r) {
        $flags[] = ['uid' => (int) $r['uid'], 'reason' => 'Reached a daily XP limit on ' . $r['d'] . ' days in the last 14 days'];
    }
    foreach (rows('SELECT uid, SUM(xp) AS xp FROM xp_transactions WHERE created > ? AND revoked = 0 GROUP BY uid HAVING xp >= 500 LIMIT 50', [time() - 86400]) as $r) {
        $flags[] = ['uid' => (int) $r['uid'], 'reason' => 'Earned ' . $r['xp'] . ' XP in the last 24 hours'];
    }
    foreach (rows("SELECT x.uid, a.approver_uid, COUNT(*) AS n FROM xp_transactions x JOIN card_approvals a ON a.id = x.ref WHERE x.kind = 'approval' AND x.revoked = 0 AND x.at > ? AND a.approver_uid > 0
        GROUP BY x.uid, a.approver_uid HAVING n >= 5 LIMIT 50", [time() - 86400]) as $r) {
        $flags[] = ['uid' => (int) $r['uid'], 'reason' => $r['n'] . ' client approvals from the same account (#' . $r['approver_uid'] . ') in 24 hours'];
    }
    $people = user_cards(array_values(array_unique(array_column($flags, 'uid'))));
    foreach ($flags as &$f) $f['user'] = $people[$f['uid']] ?? null;
    unset($f);
    $achievements = [];
    foreach (JOURNEY_ACHIEVEMENTS as $k => $d) {
        $achievements[] = ['aid' => $k, 'title' => $d['title'], 'verification' => JOURNEY_VERIFICATIONS[$d['verification']], 'active' => $rules['active'][$k],
            'granted' => $byAch[$k]['verified'] ?? 0, 'revoked' => $byAch[$k]['revoked'] ?? 0];
    }
    return [
        'metrics' => [
            'participants' => (int) val('SELECT COUNT(*) FROM journey_progress WHERE xp > 0'),
            'active' => (int) val('SELECT COUNT(DISTINCT uid) FROM xp_transactions WHERE xp > 0 AND revoked = 0 AND at > ?', [$t30]),
            'achievements' => $verified, 'self' => (int) val("SELECT COUNT(*) FROM user_achievements WHERE status = 'self'"),
            'projects' => (int) val('SELECT COUNT(*) FROM projects WHERE completed_at > 0'),
            'client' => (int) val("SELECT COUNT(*) FROM user_achievements WHERE status = 'verified' AND verification = 'client'"),
            'shared' => (int) val("SELECT COUNT(*) FROM user_achievements WHERE status = 'verified' AND (shared > 0 OR visibility <> 'private')"),
            'share_rate' => $verified ? (int) round(100 * (int) val("SELECT COUNT(*) FROM user_achievements WHERE status = 'verified' AND (shared > 0 OR visibility <> 'private')") / $verified) : 0,
            'passports' => (int) val('SELECT COUNT(*) FROM passport_settings WHERE enabled = 1'),
            'passport_views' => (int) val('SELECT COALESCE(SUM(views), 0) FROM passport_settings'),
            'verify_views' => (int) val('SELECT COALESCE(SUM(views), 0) FROM user_achievements'),
            'xp_30' => (int) val('SELECT COALESCE(SUM(xp), 0) FROM xp_transactions WHERE revoked = 0 AND at > ?', [$t30]),
            'revoked' => (int) val('SELECT COUNT(*) FROM xp_transactions WHERE revoked > 0'),
        ],
        'xp_distribution' => $dist, 'level_distribution' => $levels, 'achievements' => $achievements, 'flags' => $flags,
        'rules' => [
            'v' => $rules['v'],
            'events' => array_map(function (string $k, array $e): array { return ['key' => $k, 'label' => $e['label'], 'xp' => (int) $e['xp'], 'cap' => (int) $e['cap'], 'default_xp' => JOURNEY_EVENTS[$k]['xp'], 'default_cap' => JOURNEY_EVENTS[$k]['cap']]; }, array_keys($rules['events']), $rules['events']),
            'params' => array_map(function (string $k, array $p) use ($rules): array { return ['key' => $k, 'label' => $p[0], 'value' => $rules['params'][$k], 'default' => $p[1], 'min' => $p[2], 'max' => $p[3]]; }, array_keys(JOURNEY_PARAMS), JOURNEY_PARAMS),
            'levels' => array_map(function (int $n, array $l): array { return ['n' => $n, 'name' => $l['name'], 'req' => $l['req'], 'default' => JOURNEY_LEVELS[$n]['req']]; }, array_keys($rules['levels']), $rules['levels']),
        ],
        'log' => rows("SELECT * FROM admin_log WHERE action LIKE 'journey_%' ORDER BY id DESC LIMIT 30"),
    ];
}
/** POST admin_journey_rules {events: {k: {xp, cap}}, params: {k: v}, levels: {n: {k: v}}, off: [aid]} */
function api_admin_journey_rules(array $b, array $a): array
{
    $cur = journey_rules();
    $o = ['v' => $cur['v'] + 1, 'events' => [], 'params' => [], 'levels' => [], 'off' => []];
    foreach (JOURNEY_EVENTS as $k => $e) {
        foreach (['xp', 'cap'] as $f) if (isset($b['events'][$k][$f]) && is_numeric($b['events'][$k][$f])) $o['events'][$k][$f] = max(0, min(1000, (int) $b['events'][$k][$f]));
    }
    foreach (JOURNEY_PARAMS as $k => $p) if (isset($b['params'][$k]) && is_numeric($b['params'][$k])) $o['params'][$k] = max($p[2], min($p[3], (int) $b['params'][$k]));
    foreach (JOURNEY_LEVELS as $n => $l) {
        foreach ($l['req'] as $k => $v) if (isset($b['levels'][$n][$k]) && is_numeric($b['levels'][$n][$k])) $o['levels'][$n][$k] = max(1, min(1000000, (int) $b['levels'][$n][$k]));
    }
    foreach ((array) ($b['off'] ?? []) as $k) if (is_string($k) && isset(JOURNEY_ACHIEVEMENTS[$k])) $o['off'][$k] = 1;
    meta_set('journey_rules', jenc($o));
    journey_rules(true);
    // Les points déjà accordés gardent leur valeur (version de règle inscrite sur chaque ligne) ; niveaux recalculés.
    q('UPDATE journey_progress SET dirty = 1');
    admin_log('journey_rules', '', 'rules version ' . $o['v']);
    return api_admin_journey([], $a);
}
/** GET admin_journey_user {id} : grand livre, réalisations et preuves d'un compte. */
function api_admin_journey_user(array $b, array $a): array
{
    $uid = int_in($b, 'id');
    $u = row('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u) not_found('This account');
    $st = journey_eval($uid);
    $ach = [];
    foreach (rows('SELECT * FROM user_achievements WHERE uid = ? ORDER BY earned DESC', [$uid]) as $ua) {
        $ach[] = journey_ua_view($ua) + ['revoked_at' => (int) $ua['revoked_at'], 'evidence' => journey_evidence_view($ua, $u, 'admin')];
    }
    return [
        'user' => user_card($u) + ['email' => (string) $u['email'], 'created' => (int) $u['created']],
        'level' => journey_level_view($st), 'stats' => $st, 'achievements' => $ach,
        'ledger' => rows('SELECT id, kind, ikey, xp, rule_v, project_id, studio_id, card_id, ref, peer, note, at, revoked, revoked_reason FROM xp_transactions WHERE uid = ? ORDER BY at DESC, id DESC LIMIT 300', [$uid]),
    ];
}
/** POST admin_journey_revoke {type: achievement | xp, id, reason, restore} : retirer (ou rendre), avec une raison inscrite. */
function api_admin_journey_revoke(array $b, array $a): array
{
    $type = ($b['type'] ?? '') === 'xp' ? 'xp' : 'achievement';
    $restore = !empty($b['restore']);
    $reason = line_clean($b['reason'] ?? '', 300);
    if (!$restore && mb_strlen($reason) < 3) fail('Give the reason (it is kept in the audit log and shown to the member).');
    if ($type === 'xp') {
        $x = row('SELECT * FROM xp_transactions WHERE id = ?', [int_in($b, 'id')]);
        if (!$x) not_found('This XP record');
        q('UPDATE xp_transactions SET revoked = ?, revoked_reason = ? WHERE id = ?', [$restore ? 0 : time(), $restore ? '' : $reason, $x['id']]);
        $uid = (int) $x['uid'];
        admin_log($restore ? 'journey_xp_restore' : 'journey_xp_revoke', 'user:' . $uid, $x['ikey'] . ' (' . $x['xp'] . ' XP)' . ($restore ? '' : ' — ' . $reason));
    } else {
        $ua = row('SELECT * FROM user_achievements WHERE id = ?', [int_in($b, 'id')]);
        if (!$ua) not_found('This achievement');
        $uid = (int) $ua['uid'];
        $title = JOURNEY_ACHIEVEMENTS[$ua['aid']]['title'] ?? (string) $ua['title'];
        if ($restore) {
            q("UPDATE user_achievements SET status = ?, revoked_at = 0, revoked_reason = '', updated = ? WHERE id = ?", [$ua['aid'] === 'self' ? 'self' : 'verified', time(), $ua['id']]);
            insert('journey_log', ['uid' => $uid, 'kind' => 'restored', 'ref' => (string) $ua['id'], 'text' => '“' . $title . '” was restored', 'seen' => 1, 'created' => time()]);
        } else {
            q("UPDATE user_achievements SET status = 'revoked', visibility = 'private', revoked_at = ?, revoked_reason = ?, updated = ? WHERE id = ?", [time(), $reason, time(), $ua['id']]);
            insert('journey_log', ['uid' => $uid, 'kind' => 'revoked', 'ref' => (string) $ua['id'], 'text' => '“' . $title . '” was revoked: ' . $reason, 'seen' => 1, 'created' => time()]);
            notify($uid, 0, 'journey', 'Studioma revoked your achievement “' . $title . '”: ' . $reason, 'achievements/' . $ua['id']);
        }
        admin_log($restore ? 'journey_achievement_restore' : 'journey_achievement_revoke', 'user:' . $uid, $title . ($restore ? '' : ' — ' . $reason));
    }
    journey_dirty([$uid]);
    return api_admin_journey_user(['id' => $uid], $a);
}
