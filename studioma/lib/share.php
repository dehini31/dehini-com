<?php
/**
 * Partage d'un Studio avec des invités sans compte.
 *
 *   lien public   n'importe qui le consulte en lecture seule, sans compte ni email
 *   lien privé    seules les adresses ajoutées par le responsable, après vérification de l'adresse
 *
 * Pour aller plus loin que la lecture, l'invité vérifie son adresse (code à 6 chiffres ou lien magique
 * reçu par email), demande un droit, et le responsable accepte ou refuse :
 *
 *   viewer     consulte ce que le lien montre
 *   commenter  consulte, commente et valide (ou demande des modifications) quand on le lui demande
 *   editor     modifie les cartes que le responsable lui ouvre (tout, ou certains niveaux, colonnes
 *              ou cartes), en crée et les déplace dans ce périmètre
 *
 * Un invité n'a jamais de compte Studioma : il agit avec une session d'invité limitée à un lien et à un
 * Studio (cookie stm_g<id du lien>). Ses requêtes portent le jeton du lien (en-tête X-Share) et n'ont
 * accès qu'à une liste fermée d'actions (GUEST_ROUTES dans api.php), qui passent par les mêmes
 * fonctions que celles des membres : load_studio() et load_card() renvoient ici vers
 * guest_load_studio() et guest_load_card(), qui appliquent le lien (Studio, niveaux montrés) et les
 * droits de l'invité (rôle, périmètre). Toute modification d'un invité est notée dans l'historique de la
 * carte sous son adresse, dans le journal du partage, et envoyée par email au responsable.
 */
declare(strict_types=1);

const SHARE_KINDS = ['public', 'private'];
const GUEST_ROLES = ['viewer' => 'Viewer', 'commenter' => 'Commenter', 'editor' => 'Editor'];
const GUEST_RANK = ['viewer' => 1, 'commenter' => 2, 'editor' => 3];
/** Rôle de Studio équivalent (lib/access.php), pour les fonctions partagées avec les membres. */
const GUEST_ROLE_MAP = ['viewer' => 'viewer', 'commenter' => 'client', 'editor' => 'creator'];
const GUEST_SESSION_DAYS = 14;
const SHARE_CODE_MINUTES = 15;
const SHARE_CODE_TRIES = 5;
/** Les modifications d'un invité sont envoyées tout de suite, puis regroupées au plus toutes les 10 minutes. */
const GUEST_DIGEST_SECONDS = 600;
const SHARE_GONE = [
    'disabled' => 'This link was turned off by the person who shared it.',
    'expired' => 'This link has expired. Ask the person who shared it for a new one.',
    'unavailable' => 'This Studio is no longer available.',
];

/* ------------------------------------------------------------------ liens */

/** Clé d'un lien : 24 octets aléatoires (192 bits) en base64url, soit 32 caractères — impossible à deviner. */
const SHARE_TOKEN_RE = '[A-Za-z0-9_-]{32}';
function share_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}
/**
 * Adresse à partager : https://dehini.com/studioma/s/<clé>. s.php renvoie vers l'application
 * (#/s/<clé>), sans transmettre la clé à d'autres sites (Referrer-Policy: no-referrer).
 */
function share_url(array $sh): string
{
    return site_url() . 's/' . $sh['token'];
}
function share_by_token(string $token): ?array
{
    return preg_match('/^' . SHARE_TOKEN_RE . '$/', $token) ? row('SELECT * FROM shares WHERE token = ?', [$token]) : null;
}
/** '' si le lien fonctionne, sinon la raison : disabled | expired | unavailable. */
function share_state(array $sh): string
{
    if (!(int) $sh['active']) return 'disabled';
    if ((int) $sh['expires'] > 0 && (int) $sh['expires'] <= time()) return 'expired';
    $s = row('SELECT s.archived, p.archived AS p_archived FROM studios s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$sh['studio_id']]);
    if (!$s || (int) $s['archived'] || (int) $s['p_archived']) return 'unavailable';
    return '';
}
function ids_in($v): array
{
    return array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), function (int $x): bool { return $x > 0; })));
}
/** Périmètre d'édition d'un invité : {levels, cols, cards}, listes d'ids (tout vide = tout ce que le lien montre). */
function guest_scope(?array $g): array
{
    $s = $g ? jarr($g['scope']) : [];
    return ['levels' => ids_in($s['levels'] ?? []), 'cols' => ids_in($s['cols'] ?? []), 'cards' => ids_in($s['cards'] ?? [])];
}
function scope_all(array $sc): bool
{
    return !$sc['levels'] && !$sc['cols'] && !$sc['cards'];
}

/* -------------------------------------------------------- sessions d'invité */

function guest_cookie(int $shareId): string
{
    return 'stm_g' . $shareId;
}
function guest_session_start(array $sh, int $gid): void
{
    $raw = token(24);
    $exp = time() + GUEST_SESSION_DAYS * 86400;
    q('INSERT INTO guest_sessions (hash, share_id, guest_id, created, expires) VALUES (?, ?, ?, ?, ?)', [hash('sha256', $raw), $sh['id'], $gid, time(), $exp]);
    q('DELETE FROM guest_sessions WHERE expires < ?', [time()]);
    setcookie(guest_cookie((int) $sh['id']), $raw, cookie_opts($exp));
}
/** Invité vérifié de ce lien d'après son cookie (session valide, accès non retiré), ou null. */
function guest_session(array $sh): ?array
{
    $raw = (string) ($_COOKIE[guest_cookie((int) $sh['id'])] ?? '');
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return null;
    return row("SELECT g.* FROM guest_sessions s JOIN share_guests g ON g.id = s.guest_id
        WHERE s.hash = ? AND s.share_id = ? AND s.expires > ? AND g.status = 'active' AND g.share_id = s.share_id", [hash('sha256', $raw), $sh['id'], time()]);
}
function guest_session_end(array $sh): void
{
    $raw = (string) ($_COOKIE[guest_cookie((int) $sh['id'])] ?? '');
    if ($raw !== '') q('DELETE FROM guest_sessions WHERE hash = ?', [hash('sha256', $raw)]);
    setcookie(guest_cookie((int) $sh['id']), '', cookie_opts(time() - 3600));
}

/* ------------------------------------------------- l'invité d'une requête */

/**
 * « Compte » d'une requête faite avec un lien de partage : id 0 et une clé « guest » qui décrit le lien,
 * l'invité vérifié (gid > 0) ou anonyme (gid = 0), son rôle et ce qu'il peut voir et modifier.
 * locked : lien privé ouvert sans adresse vérifiée (rien n'est montré).
 */
function guest_principal(string $token): array
{
    $sh = share_by_token($token);
    if (!$sh) fail('This link doesn\'t work. Ask the person who shared it for a new one.', 404, 'share_gone');
    $state = share_state($sh);
    if ($state !== '') fail(SHARE_GONE[$state], 410, 'share_' . $state);
    $g = guest_session($sh);
    $info = [
        'share' => $sh, 'gid' => $g ? (int) $g['id'] : 0, 'email' => $g ? (string) $g['email'] : '', 'row' => $g,
        'role' => $g && isset(GUEST_RANK[$g['role']]) ? (string) $g['role'] : 'viewer',
        'locked' => $sh['kind'] === 'private' && !$g,
        'levels' => ids_in(jarr($sh['levels'])), 'scope' => guest_scope($g),
    ];
    if ($g) {
        $GLOBALS['stm_guest'] = $info;
        if ((int) $g['last_seen'] < time() - 300) q('UPDATE share_guests SET last_seen = ? WHERE id = ?', [time(), $g['id']]);
    }
    return ['id' => 0, 'name' => $info['email'] !== '' ? $info['email'] : 'Guest', 'email' => $info['email'], 's_csrf' => '', 'guest' => $info];
}
/** Invité vérifié qui fait la requête en cours (pour attribuer ses actions), ou null. */
function guest_now(): ?array
{
    return $GLOBALS['stm_guest'] ?? null;
}
/** Identifiant de limitation de débit : le compte ou l'invité. */
function actor_key(array $u): string
{
    return !empty($u['guest']) ? 'g' . (int) $u['guest']['gid'] . '|' . ip_hash() : 'u' . (int) $u['id'];
}
/** Fiche d'un invité dans les listes (commentaires, historique) : même forme que celle d'un membre. */
function guest_cards(array $ids): array
{
    $ids = ids_in($ids);
    if (!$ids) return [];
    $out = [];
    foreach (rows('SELECT id, email FROM share_guests WHERE id IN (' . marks($ids) . ')', $ids) as $g) {
        $out[(int) $g['id']] = ['id' => 0, 'guest_id' => (int) $g['id'], 'name' => (string) $g['email'], 'handle' => '', 'title' => 'Guest', 'avatar' => '', 'guest' => true];
    }
    return $out;
}
function guest_forbidden(array $g): void
{
    if ($g['gid'] === 0) fail('Verify your email and request editing permission to do this.', 403, 'guest_verify');
    forbidden($g['role'] === 'viewer' ? 'You have view-only access. Request editing permission to do this.' : 'Your guest access doesn\'t allow this.');
}

/**
 * Le Studio du lien, avec le rôle équivalent de l'invité. Tout autre Studio est introuvable ; les
 * capacités de gestion (structure, membres, validations demandées) ne sont jamais accordées.
 */
function guest_load_studio(int $id, array $u, string $cap): array
{
    $g = $u['guest'];
    if ($g['locked']) fail('Verify your email address to open this Studio.', 401, 'verify');
    if ($id !== (int) $g['share']['studio_id']) not_found('This Studio');
    $s = row('SELECT * FROM studios WHERE id = ?', [$id]);
    $p = $s ? row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]) : null;
    if (!$s || !$p) not_found('This Studio');
    $s['role'] = GUEST_ROLE_MAP[$g['role']];
    $s['project'] = $p;
    $s['guest'] = true;
    if (in_array($cap, ['manage', 'own', 'approve'], true) || !can($s['role'], $cap)) guest_forbidden($g);
    return $s;
}
/** Colonne et niveau d'une carte si le lien la montre (carte active, dans un niveau partagé), sinon null. */
function guest_card_place(array $card, array $g): ?array
{
    if ((int) $card['archived']) return null;
    $x = row('SELECT co.id, co.level_id FROM cols co JOIN levels l ON l.id = co.level_id WHERE co.id = ? AND co.archived = 0 AND l.archived = 0', [$card['col_id']]);
    if (!$x || ($g['levels'] && !in_array((int) $x['level_id'], $g['levels'], true))) return null;
    return ['col' => (int) $x['id'], 'level' => (int) $x['level_id']];
}
/** L'invité peut-il ajouter ou déposer des cartes dans cette colonne ? */
function guest_col_writable(array $g, int $colId, int $levelId): bool
{
    if ($g['role'] !== 'editor' || ($g['levels'] && !in_array($levelId, $g['levels'], true))) return false;
    $sc = $g['scope'];
    return scope_all($sc) || in_array($colId, $sc['cols'], true) || in_array($levelId, $sc['levels'], true);
}
/** L'invité peut-il modifier cette carte ? */
function guest_card_editable(array $g, int $cardId, int $colId, int $levelId): bool
{
    if ($g['role'] !== 'editor' || ($g['levels'] && !in_array($levelId, $g['levels'], true))) return false;
    return guest_col_writable($g, $colId, $levelId) || in_array($cardId, $g['scope']['cards'], true);
}
function guest_load_card(int $id, array $u, string $cap): array
{
    $g = $u['guest'];
    $s = guest_load_studio((int) $g['share']['studio_id'], $u, 'view');
    $c = row('SELECT * FROM cards WHERE id = ? AND studio_id = ?', [$id, $s['id']]);
    $place = $c ? guest_card_place($c, $g) : null;
    if (!$place) not_found('This card');
    if ($cap === 'cardedit') {
        if (!guest_card_editable($g, (int) $c['id'], $place['col'], $place['level'])) {
            if ($g['role'] === 'editor') forbidden('This card isn\'t part of what you can edit.');
            guest_forbidden($g);
        }
    } elseif ($cap === 'comment') {
        if (GUEST_RANK[$g['role']] < GUEST_RANK['commenter']) guest_forbidden($g);
    } elseif ($cap !== 'view') {
        guest_forbidden($g);
    }
    $c['studio'] = $s;
    return $c;
}
/** Colonne où l'invité ajoute ou dépose une carte : elle doit être dans son périmètre. */
function guest_need_col(array $u, array $col): void
{
    $g = $u['guest'];
    if (!guest_col_writable($g, (int) $col['id'], (int) $col['level_id'])) {
        if ($g['role'] === 'editor') forbidden('You can\'t add or move cards to this column.');
        guest_forbidden($g);
    }
}
/** Un fichier est lisible par l'invité s'il est joint à une carte que le lien montre. */
function guest_file_readable(array $f, array $u): bool
{
    if ($f['scope'] === 'public') return true;
    $g = $u['guest'];
    if ($g['locked'] || $f['scope'] !== 'studio' || (int) $f['studio_id'] !== (int) $g['share']['studio_id']) return false;
    foreach (rows('SELECT c.* FROM card_files cf JOIN cards c ON c.id = cf.card_id WHERE cf.file_id = ?', [$f['id']]) as $c) {
        if ((int) $c['studio_id'] === (int) $g['share']['studio_id'] && guest_card_place($c, $g)) return true;
    }
    return false;
}

/** Tableau vu par un invité : niveaux partagés seulement, droits par colonne et par carte. */
function guest_board(array $s, array $u): array
{
    $g = $u['guest'];
    $d = board_data($s, $u);
    $levels = array_values(array_filter($d['levels'], function (array $l) use ($g): bool { return !$g['levels'] || in_array($l['id'], $g['levels'], true); }));
    $shown = array_column($levels, 'id');
    $cols = [];
    foreach ($d['cols'] as $c) {
        if (in_array($c['level_id'], $shown, true)) $cols[] = $c + ['writable' => guest_col_writable($g, $c['id'], $c['level_id'])];
    }
    $levelOf = array_column($cols, 'level_id', 'id');
    $cards = [];
    $people = [];
    foreach ($d['cards'] as $c) {
        if (!isset($levelOf[$c['col_id']])) continue;
        $cards[] = $c + ['editable' => guest_card_editable($g, $c['id'], $c['col_id'], $levelOf[$c['col_id']])];
        if ($c['assignee']) $people[$c['assignee']] = true;
        foreach ($c['members'] as $m) $people[$m] = true;
    }
    // Équipe : seulement les personnes qui apparaissent sur les cartes partagées (nom, photo).
    $members = [];
    foreach ($d['members'] as $m) {
        if (isset($people[$m['id']])) $members[] = ['id' => $m['id'], 'name' => $m['name'], 'handle' => $m['handle'], 'title' => $m['title'], 'avatar' => $m['avatar'], 'role' => $m['role'], 'via' => ''];
    }
    $d['studio']['role'] = 'guest';
    $d['studio']['can'] = [
        'comment' => GUEST_RANK[$g['role']] >= GUEST_RANK['commenter'], 'approve' => false,
        'work' => (bool) array_filter($cols, function (array $c): bool { return $c['writable']; }), 'edit' => false, 'manage' => false, 'own' => false,
    ];
    $d['studio']['guest'] = guest_view($u);
    return ['studio' => $d['studio'], 'levels' => $levels, 'cols' => $cols, 'cards' => $cards, 'members' => $members];
}
/** Ce que l'interface de l'invité doit savoir de lui. */
function guest_view(array $u): array
{
    $g = $u['guest'];
    $row = $g['gid'] ? row('SELECT * FROM share_guests WHERE id = ?', [$g['gid']]) : null;
    $scope = $g['scope'];
    return [
        'verified' => $g['gid'] > 0, 'email' => $g['email'], 'role' => $g['role'], 'kind' => (string) $g['share']['kind'],
        'pending' => $row && (int) $row['req_at'] > 0 ? (string) $row['req_role'] : '',
        'limited' => $g['role'] === 'editor' && !scope_all($scope),
        'levels_limited' => (bool) $g['levels'],
    ];
}
/** Invités qu'un membre peut désigner pour valider une carte (rôle commenter ou editor, adresse vérifiée). */
function guest_approvers(int $studioId): array
{
    $out = [];
    foreach (rows("SELECT g.*, s.kind FROM share_guests g JOIN shares s ON s.id = g.share_id
        WHERE g.studio_id = ? AND g.status = 'active' AND g.role IN ('commenter', 'editor') ORDER BY g.email", [$studioId]) as $g) {
        $out[] = ['id' => 'g' . $g['id'], 'name' => (string) $g['email'], 'role' => (string) $g['role'], 'kind' => (string) $g['kind']];
    }
    return $out;
}

/* ------------------------------------------------- journal et notifications */

function share_log(int $studioId, int $shareId, int $uid, ?array $guest, int $cardId, string $kind, string $text, bool $digest = false): void
{
    insert('share_log', [
        'studio_id' => $studioId, 'share_id' => $shareId, 'guest_id' => $guest ? (int) $guest['id'] : 0, 'email' => $guest ? (string) $guest['email'] : '',
        'uid' => $uid, 'card_id' => $cardId, 'kind' => $kind, 'text' => mb_substr($text, 0, 400), 'created' => time(), 'mailed' => $digest ? 0 : 1,
    ]);
}
/** Modification faite par l'invité de la requête en cours (appelé par log_event et les actions sans historique). */
function guest_log_change(int $cardId, string $kind, string $text): void
{
    $g = guest_now();
    if (!$g) return;
    share_log((int) $g['share']['studio_id'], (int) $g['share']['id'], 0, ['id' => $g['gid'], 'email' => $g['email']], $cardId, $kind, $text, true);
}
/** Personnes prévenues pour un lien : celle qui l'a créé si elle gère encore le Studio, sinon les propriétaires du projet. */
function share_managers(array $sh): array
{
    $s = row('SELECT * FROM studios WHERE id = ?', [$sh['studio_id']]);
    $p = $s ? row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]) : null;
    if (!$s || !$p) return [];
    $by = (int) $sh['created_by'];
    if ($by > 0 && can(studio_role($by, $s, $p), 'manage')) return [$by];
    return array_map('intval', array_column(rows("SELECT uid FROM project_members WHERE project_id = ? AND role = 'owner'", [$p['id']]), 'uid'));
}
function guests_url(int $studioId, string $extra = ''): string
{
    return app_url('studio/' . $studioId . '?guests=1' . $extra);
}

/**
 * Emails des modifications faites par les invités : la première tout de suite, les suivantes regroupées
 * (au plus un email par invité toutes les 10 minutes). Appelé après chaque requête de l'API : un envoi
 * en attente part dès que la fenêtre est passée.
 */
function share_digests(): void
{
    // L'action de la requête est déjà enregistrée : un envoi qui échoue (base occupée…) ne doit pas la faire échouer.
    try {
        $ids = array_column(rows('SELECT DISTINCT l.guest_id FROM share_log l JOIN share_guests g ON g.id = l.guest_id
            WHERE l.mailed = 0 AND g.mailed_at <= ? LIMIT 10', [time() - GUEST_DIGEST_SECONDS]), 'guest_id');
        foreach ($ids as $gid) share_digest_send((int) $gid);
    } catch (Throwable $e) {
        error_log('studioma digests: ' . $e->getMessage());
    }
}
function share_digest_send(int $gid): void
{
    $now = time();
    // Une seule requête envoie un regroupement donné, même si plusieurs arrivent en même temps.
    if (!q('UPDATE share_guests SET mailed_at = ? WHERE id = ? AND mailed_at <= ?', [$now, $gid, $now - GUEST_DIGEST_SECONDS])->rowCount()) return;
    $g = row('SELECT * FROM share_guests WHERE id = ?', [$gid]);
    $list = rows('SELECT l.*, c.title AS card_title FROM share_log l LEFT JOIN cards c ON c.id = l.card_id WHERE l.guest_id = ? AND l.mailed = 0 ORDER BY l.id LIMIT 200', [$gid]);
    if (!$g || !$list) return;
    q('UPDATE share_log SET mailed = 1 WHERE guest_id = ? AND mailed = 0 AND id <= ?', [$gid, (int) end($list)['id']]);
    $sh = row('SELECT * FROM shares WHERE id = ?', [$g['share_id']]);
    $s = $sh ? row('SELECT s.*, p.name AS project_name FROM studios s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$sh['studio_id']]) : null;
    if (!$s) return;
    $n = count($list);
    $links = array_map(function (array $l) use ($s): array {
        $what = ($l['card_title'] !== null && $l['card_title'] !== '' ? '“' . $l['card_title'] . '” — ' : '') . $l['text'];
        return [date('j M Y, H:i', (int) $l['created']) . ' · ' . $what, $l['card_id'] ? app_url('studio/' . $s['id'] . '/card/' . $l['card_id']) : app_url('studio/' . $s['id'])];
    }, $list);
    foreach (share_managers($sh) as $uid) {
        $to = user_by_id($uid);
        if (!$to || !user_settings($to)['email_notifs']) continue;
        mail_later((string) $to['email'], $g['email'] . ' made ' . ($n === 1 ? 'a change' : $n . ' changes') . ' in ' . $s['name'], 'A guest changed your Studio', [
            $g['email'] . ' (guest, verified email, ' . strtolower(GUEST_ROLES[$g['role']] ?? 'guest') . ') made ' . ($n === 1 ? 'this change' : 'these changes') . ' in “' . $s['name'] . '” (project ' . $s['project_name'] . '). Times are ' . date('T') . '.',
            'Open a line to see the card. You can change or remove this person\'s access at any time.',
        ], 'Manage guest access', guests_url((int) $s['id']), $links);
    }
}

/* ----------------------------------------------------- l'invité : accès */

function share_info(array $sh): array
{
    $s = row('SELECT * FROM studios WHERE id = ?', [$sh['studio_id']]);
    $p = $s ? row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]) : null;
    $by = user_cards([(int) $sh['created_by']])[(int) $sh['created_by']] ?? null;
    return [
        'kind' => (string) $sh['kind'], 'studio_id' => (int) $sh['studio_id'], 'studio' => $s ? (string) $s['name'] : '',
        'project' => $p ? ['name' => (string) $p['name'], 'color' => (string) $p['color'], 'logo' => (string) $p['logo']] : null,
        'by' => $by ? ['name' => $by['name'], 'avatar' => $by['avatar']] : null, 'expires' => (int) $sh['expires'],
    ];
}
/** GET share_open : ce que montre le lien, et qui le consulte. */
function api_share_open(array $b, array $u): array
{
    $g = $u['guest'];
    $out = ['share' => share_info($g['share']), 'guest' => guest_view($u)];
    // Un membre du Studio connecté à son compte retrouve le Studio normal.
    $me = current_user();
    if ($me) {
        $s = row('SELECT * FROM studios WHERE id = ?', [$g['share']['studio_id']]);
        $p = $s ? row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]) : null;
        if ($s && $p && studio_role((int) $me['id'], $s, $p) !== '') $out['member'] = (int) $s['id'];
    }
    return $out;
}
/**
 * POST share_code {email, request?, note?} : envoie un code à 6 chiffres et un lien magique, valables
 * 15 minutes. Lien privé : seulement aux adresses ajoutées, mais la réponse est la même pour toutes (on ne
 * révèle pas qui figure sur la liste). request : droit demandé une fois l'adresse vérifiée.
 */
function api_share_code(array $b, array $u): array
{
    $sh = $u['guest']['share'];
    $email = strtolower(line_clean($b['email'] ?? '', 190));
    if (!valid_email($email)) fail('Enter a valid email address.');
    rate('share_code', 12, 3600);
    rate('share_code_e', 4, 900, $sh['id'] . '|' . $email);
    $req = in_array($b['request'] ?? '', ['commenter', 'editor'], true) ? (string) $b['request'] : '';
    $g = row('SELECT * FROM share_guests WHERE share_id = ? AND email = ?', [$sh['id'], $email]);
    $allowed = $sh['kind'] === 'public' ? !($g && $g['status'] === 'revoked') : ($g && in_array($g['status'], ['invited', 'active'], true));
    if ($allowed) {
        $code = (string) random_int(100000, 999999);
        $link = token(24);
        q('UPDATE share_codes SET used = 1 WHERE share_id = ? AND email = ? AND used = 0', [$sh['id'], $email]);
        insert('share_codes', [
            'share_id' => $sh['id'], 'email' => $email, 'code' => share_code_hash($sh, $email, $code), 'link' => hash('sha256', $link),
            'req_role' => $req, 'req_note' => str_clean($b['note'] ?? '', 500), 'expires' => time() + SHARE_CODE_MINUTES * 60, 'created' => time(),
        ]);
        $info = share_info($sh);
        mail_later($email, $code . ' is your code for ' . $info['studio'], 'Your verification code: ' . $code, [
            ($info['by'] ? $info['by']['name'] . ' shared' : 'You opened') . ' the Studio “' . $info['studio'] . '” on ' . STM_NAME . '. Enter this code on the page to confirm that this email address is yours, or use the button below. Both are valid for ' . SHARE_CODE_MINUTES . ' minutes and work once.',
            'No account or password is needed. If you didn\'t ask for this code, ignore this email: nothing happens without it.',
        ], 'Verify my email', share_url($sh) . '?v=' . $link);
    }
    return ['sent' => true, 'email' => $email, 'minutes' => SHARE_CODE_MINUTES];
}
function share_code_hash(array $sh, string $email, string $code): string
{
    return hash_hmac('sha256', $code, install_salt() . '|' . $sh['id'] . '|' . $email);
}
/**
 * POST share_verify {email, code} ou {link} : vérifie l'adresse et ouvre une session d'invité limitée à
 * ce lien. Un code faux compte un essai ; au 5e, il ne vaut plus.
 */
function api_share_verify(array $b, array $u): array
{
    $sh = $u['guest']['share'];
    rate('share_verify', 30, 900);
    $link = (string) ($b['link'] ?? '');
    if ($link !== '') {
        $c = preg_match('/^[a-f0-9]{48}$/', $link) ? row('SELECT * FROM share_codes WHERE link = ? AND share_id = ? AND used = 0 AND expires > ?', [hash('sha256', $link), $sh['id'], time()]) : null;
        if (!$c) fail('This verification link has expired or was already used. Ask for a new code.', 410, 'gone');
    } else {
        $email = strtolower(line_clean($b['email'] ?? '', 190));
        $code = preg_replace('/\D+/', '', (string) ($b['code'] ?? '')) ?? '';
        $c = row('SELECT * FROM share_codes WHERE share_id = ? AND email = ? AND used = 0 AND expires > ? ORDER BY id DESC LIMIT 1', [$sh['id'], $email, time()]);
        if (!$c) fail('This code has expired. Ask for a new one.', 410, 'gone');
        if (strlen($code) !== 6 || !hash_equals((string) $c['code'], share_code_hash($sh, $email, $code))) {
            $tries = (int) $c['tries'] + 1;
            q('UPDATE share_codes SET tries = ?, used = ? WHERE id = ?', [$tries, $tries >= SHARE_CODE_TRIES ? 1 : 0, $c['id']]);
            fail($tries >= SHARE_CODE_TRIES ? 'Too many wrong codes. Ask for a new one.' : 'This code is not correct. Check the email and try again.', 400, 'code');
        }
    }
    q('UPDATE share_codes SET used = 1 WHERE id = ?', [$c['id']]);
    $email = (string) $c['email'];
    $g = row('SELECT * FROM share_guests WHERE share_id = ? AND email = ?', [$sh['id'], $email]);
    if ($sh['kind'] === 'private' && (!$g || !in_array($g['status'], ['invited', 'active'], true))) fail('This email address doesn\'t have access to this Studio.', 403, 'forbidden');
    if ($g && $g['status'] === 'revoked') fail('Your access to this Studio was removed.', 403, 'forbidden');
    if (!$g) {
        $gid = insert('share_guests', ['share_id' => $sh['id'], 'studio_id' => $sh['studio_id'], 'email' => $email, 'status' => 'active', 'verified' => time(), 'last_seen' => time(), 'created' => time()]);
    } else {
        $gid = (int) $g['id'];
        q("UPDATE share_guests SET status = 'active', verified = ?, last_seen = ? WHERE id = ?", [time(), time(), $gid]);
    }
    $g = row('SELECT * FROM share_guests WHERE id = ?', [$gid]);
    share_log((int) $sh['studio_id'], (int) $sh['id'], 0, $g, 0, 'access', 'verified their email and opened the ' . $sh['kind'] . ' link');
    guest_session_start($sh, $gid);
    $principal = ['id' => 0, 'guest' => ['share' => $sh, 'gid' => $gid, 'email' => $email, 'role' => (string) $g['role'], 'locked' => false, 'levels' => ids_in(jarr($sh['levels'])), 'scope' => guest_scope($g), 'row' => $g]];
    $asked = '';
    if ($c['req_role'] !== '') $asked = guest_request($sh, $g, (string) $c['req_role'], (string) $c['req_note']);
    return ['guest' => guest_view($principal), 'requested' => $asked];
}
/** Enregistre une demande de droits et prévient le responsable. Retourne pending, ou already si l'invité a déjà ce droit. */
function guest_request(array $sh, array $g, string $role, string $note): string
{
    if (GUEST_RANK[$g['role']] >= GUEST_RANK[$role]) return 'already';
    rate('share_request', 5, 3600, 'g' . $g['id']);
    $first = (int) $g['req_at'] === 0 || $g['req_role'] !== $role;
    q('UPDATE share_guests SET req_role = ?, req_note = ?, req_at = ? WHERE id = ?', [$role, $note, time(), $g['id']]);
    share_log((int) $sh['studio_id'], (int) $sh['id'], 0, $g, 0, 'request', 'asked for ' . strtolower(GUEST_ROLES[$role]) . ' access' . ($note !== '' ? ': “' . mb_substr($note, 0, 200) . '”' : ''));
    if ($first) {
        $info = share_info($sh);
        $sid = (int) $sh['studio_id'];
        foreach (share_managers($sh) as $uid) {
            notify($uid, 0, 'guest', 'asks for ' . strtolower(GUEST_ROLES[$role]) . ' access to “' . $info['studio'] . '”', 'studio/' . $sid . '?guests=1', false, (string) $g['email']);
            $to = user_by_id($uid);
            if (!$to) continue;
            mail_later((string) $to['email'], $g['email'] . ' asks to ' . ($role === 'editor' ? 'edit' : 'comment on') . ' ' . $info['studio'], 'A guest asks for ' . strtolower(GUEST_ROLES[$role]) . ' access', array_values(array_filter([
                $g['email'] . ' (email verified) opened your ' . $sh['kind'] . ' link to the Studio “' . $info['studio'] . '”' . ($info['project'] ? ' (project ' . $info['project']['name'] . ')' : '') . ' and asks for ' . strtolower(GUEST_ROLES[$role]) . ' access: '
                    . ($role === 'editor' ? 'to edit the content you open to them.' : 'to comment, and to approve the cards you send them for approval.'),
                $note !== '' ? 'Their message: “' . $note . '”' : '',
                'Nothing changes until you answer. You can approve with a narrower access, or limit editing to some levels, columns or cards.',
            ])), 'Review the request', guests_url($sid, '&req=' . $g['id']), [
                ['Approve as ' . GUEST_ROLES[$role], guests_url($sid, '&req=' . $g['id'] . '&do=approve')],
                ['Reject the request', guests_url($sid, '&req=' . $g['id'] . '&do=reject')],
            ]);
        }
    }
    return 'pending';
}
/** POST share_request {role, note} : un invité vérifié demande à commenter ou à modifier. */
function api_share_request(array $b, array $u): array
{
    $g = $u['guest'];
    if (!$g['gid']) fail('Verify your email address first.', 401, 'verify');
    $role = (string) ($b['role'] ?? '');
    if (!in_array($role, ['commenter', 'editor'], true)) fail('Choose the access you need.');
    $res = guest_request($g['share'], $g['row'], $role, str_clean($b['note'] ?? '', 500));
    if ($res === 'already') fail('You already have this access.', 409, 'already');
    return ['guest' => guest_view(guest_principal((string) $g['share']['token']))];
}
/** POST share_leave : l'invité quitte sa session sur ce lien. */
function api_share_leave(array $b, array $u): array
{
    guest_session_end($u['guest']['share']);
    return [];
}

/* --------------------------------------------- le responsable : gestion */

function share_view(?array $sh): ?array
{
    if (!$sh) return null;
    return [
        'id' => (int) $sh['id'], 'kind' => (string) $sh['kind'], 'url' => share_url($sh), 'active' => (bool) $sh['active'],
        'expires' => (int) $sh['expires'], 'levels' => ids_in(jarr($sh['levels'])), 'state' => share_state($sh), 'created' => (int) $sh['created'],
    ];
}
function guest_row_view(array $g, array $kinds): array
{
    $sc = guest_scope($g);
    return [
        'id' => (int) $g['id'], 'email' => (string) $g['email'], 'kind' => $kinds[(int) $g['share_id']] ?? '', 'role' => (string) $g['role'],
        'status' => (string) $g['status'], 'scope' => $sc, 'limited' => !scope_all($sc), 'verified' => (int) $g['verified'], 'last_seen' => (int) $g['last_seen'],
        'request' => (int) $g['req_at'] ? ['role' => (string) $g['req_role'], 'note' => (string) $g['req_note'], 'at' => (int) $g['req_at']] : null,
        'created' => (int) $g['created'],
    ];
}
/** GET share_list {studio} : liens, invités, demandes et journal du partage. */
function api_share_list(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u, 'manage');
    $sid = (int) $s['id'];
    $shares = rows('SELECT * FROM shares WHERE studio_id = ?', [$sid]);
    $kinds = array_column($shares, 'kind', 'id');
    $byKind = array_column($shares, null, 'kind');
    $guests = rows("SELECT * FROM share_guests WHERE studio_id = ? AND status <> 'removed' ORDER BY req_at DESC, last_seen DESC, id DESC", [$sid]);
    $log = rows('SELECT l.*, c.title AS card_title FROM share_log l LEFT JOIN cards c ON c.id = l.card_id WHERE l.studio_id = ? ORDER BY l.id DESC LIMIT 80', [$sid]);
    $users = user_cards(array_column($log, 'uid'));
    $levels = [];
    foreach (studio_levels($sid) as $l) {
        $levels[] = ['id' => (int) $l['id'], 'name' => (string) $l['name'], 'icon' => (string) $l['icon'],
            'cols' => array_map(function (array $c): array { return ['id' => (int) $c['id'], 'name' => (string) $c['name']]; }, rows('SELECT id, name FROM cols WHERE level_id = ? AND archived = 0 ORDER BY pos, id', [$l['id']]))];
    }
    return [
        'links' => ['public' => share_view($byKind['public'] ?? null), 'private' => share_view($byKind['private'] ?? null)],
        'guests' => array_map(function (array $g) use ($kinds): array { return guest_row_view($g, $kinds); }, $guests),
        'history' => array_map(function (array $l) use ($users): array {
            return ['id' => (int) $l['id'], 'kind' => (string) $l['kind'], 'text' => (string) $l['text'], 'created' => (int) $l['created'],
                'card_id' => (int) $l['card_id'], 'card_title' => (string) ($l['card_title'] ?? ''),
                'by' => $l['uid'] ? ($users[(int) $l['uid']] ?? null) : ['id' => 0, 'name' => (string) $l['email'], 'handle' => '', 'title' => 'Guest', 'avatar' => '', 'guest' => true]];
        }, $log),
        'levels' => $levels,
        'cards' => array_map(function (array $c): array { return ['id' => (int) $c['id'], 'title' => (string) $c['title'], 'col_id' => (int) $c['col_id']]; },
            rows('SELECT c.id, c.title, c.col_id FROM cards c JOIN cols co ON co.id = c.col_id JOIN levels l ON l.id = co.level_id WHERE c.studio_id = ? AND c.archived = 0 AND co.archived = 0 AND l.archived = 0 ORDER BY c.updated DESC LIMIT 500', [$sid])),
    ];
}
/** Lien d'un type, créé s'il n'existe pas encore. */
function share_get_or_create(array $s, string $kind, int $uid): array
{
    $sh = row('SELECT * FROM shares WHERE studio_id = ? AND kind = ?', [$s['id'], $kind]);
    if ($sh) return $sh;
    insert('shares', ['studio_id' => $s['id'], 'kind' => $kind, 'token' => share_token(), 'created_by' => $uid, 'created' => time(), 'updated' => time()]);
    share_log((int) $s['id'], 0, $uid, null, 0, 'link', 'created the ' . $kind . ' link');
    return row('SELECT * FROM shares WHERE studio_id = ? AND kind = ?', [$s['id'], $kind]);
}
/** Niveaux d'un Studio parmi ceux donnés. */
function studio_level_ids(int $sid, $ids): array
{
    $ids = ids_in($ids);
    return $ids ? array_map('intval', array_column(rows('SELECT id FROM levels WHERE studio_id = ? AND id IN (' . marks($ids) . ')', array_merge([$sid], $ids)), 'id')) : [];
}
/** POST share_save {studio, kind, active?, expires? (YYYY-MM-DD ou ''), levels?} : crée ou règle un lien. */
function api_share_save(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u, 'manage');
    $kind = (string) ($b['kind'] ?? '');
    if (!in_array($kind, SHARE_KINDS, true)) fail('Choose a public or a private link.');
    $uid = (int) $u['id'];
    tx(function () use ($s, $kind, $b, $uid): void {
        $sh = share_get_or_create($s, $kind, $uid);
        $set = [];
        $what = [];
        if (array_key_exists('active', $b) && (bool) $b['active'] !== (bool) $sh['active']) { $set['active'] = $b['active'] ? 1 : 0; $what[] = $b['active'] ? 'turned on the ' . $kind . ' link' : 'turned off the ' . $kind . ' link'; }
        if (array_key_exists('expires', $b)) {
            $d = date_clean((string) $b['expires']);
            if ($b['expires'] !== '' && $b['expires'] !== null && $d === '') fail('Enter the expiry date as YYYY-MM-DD.');
            $exp = $d === '' ? 0 : (int) strtotime($d . ' 23:59:59');
            if ($exp && $exp <= time()) fail('Choose an expiry date in the future.');
            if ($exp !== (int) $sh['expires']) { $set['expires'] = $exp; $what[] = $exp ? 'set the ' . $kind . ' link to expire on ' . $d : 'removed the expiry date of the ' . $kind . ' link'; }
        }
        if (array_key_exists('levels', $b)) {
            $lv = studio_level_ids((int) $s['id'], $b['levels']);
            if (is_array($b['levels']) && $b['levels'] && !$lv) fail('Choose levels of this Studio.');
            if (jenc($lv) !== jenc(ids_in(jarr($sh['levels'])))) { $set['levels'] = jenc($lv); $what[] = $lv ? 'limited the ' . $kind . ' link to ' . count($lv) . ' level' . (count($lv) > 1 ? 's' : '') : 'opened the ' . $kind . ' link to all levels'; }
        }
        if ($set) {
            $set['updated'] = time();
            q('UPDATE shares SET ' . implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set))) . ' WHERE id = ?', array_merge(array_values($set), [$sh['id']]));
            foreach ($what as $w) share_log((int) $s['id'], (int) $sh['id'], $uid, null, 0, 'link', $w);
            studio_touch((int) $s['id']);
        }
    });
    return api_share_list(['studio' => $s['id']], $u);
}
/** POST share_regen {studio, kind} : nouveau lien ; l'ancien ne fonctionne plus. */
function api_share_regen(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u, 'manage');
    $sh = row('SELECT * FROM shares WHERE studio_id = ? AND kind = ?', [$s['id'], (string) ($b['kind'] ?? '')]);
    if (!$sh) not_found('This link');
    q('UPDATE shares SET token = ?, updated = ? WHERE id = ?', [share_token(), time(), $sh['id']]);
    share_log((int) $s['id'], (int) $sh['id'], (int) $u['id'], null, 0, 'link', 'regenerated the ' . $sh['kind'] . ' link (the old one no longer works)');
    return api_share_list(['studio' => $s['id']], $u);
}
function guest_role_in($r): string
{
    return is_string($r) && isset(GUEST_ROLES[$r]) ? $r : '';
}
/** Invitation d'un invité par email (lien privé, ou lien public avec un droit déjà accordé). */
function guest_invite_mail(array $sh, string $email, string $role, array $by, string $message): void
{
    $info = share_info($sh);
    mail_later($email, $by['name'] . ' shared “' . $info['studio'] . '” with you', $by['name'] . ' shared a Studio with you', array_values(array_filter([
        $by['name'] . ' invites you to the Studio “' . $info['studio'] . '”' . ($info['project'] ? ' (project ' . $info['project']['name'] . ')' : '') . ' on ' . STM_NAME . ', as ' . strtolower(GUEST_ROLES[$role]) . '.',
        $message !== '' ? '“' . $message . '”' : '',
        'No account is needed: open the link and confirm your email address with the code you\'ll receive.',
    ])), 'Open the Studio', share_url($sh));
}
/**
 * POST share_guest {studio, op, …} :
 *   add      {kind, emails, role, send, message}  ajoute des adresses (vérification exigée à l'ouverture)
 *   role     {guest, role}                          change le droit
 *   scope    {guest, levels, cols, cards}           limite ce qu'un éditeur peut modifier
 *   approve  {guest, role?}  /  reject {guest}      répond à une demande
 *   revoke   {guest}  /  restore {guest}  /  remove {guest}
 */
function api_share_guest(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u, 'manage');
    $sid = (int) $s['id'];
    $uid = (int) $u['id'];
    $op = (string) ($b['op'] ?? '');
    if ($op === 'add') {
        $kind = (string) ($b['kind'] ?? 'private');
        if (!in_array($kind, SHARE_KINDS, true)) fail('Choose a public or a private link.');
        $role = guest_role_in($b['role'] ?? 'viewer') ?: 'viewer';
        $raw = is_array($b['emails'] ?? null) ? implode(',', $b['emails']) : (string) ($b['emails'] ?? '');
        $emails = array_values(array_unique(array_filter(array_map(function (string $e): string { return strtolower(trim($e)); }, preg_split('/[\s,;]+/', $raw) ?: []))));
        if (!$emails) fail('Enter at least one email address.');
        if (count($emails) > 50) fail('Add 50 addresses at most at once.');
        foreach ($emails as $e) {
            if (!valid_email($e) || mb_strlen($e) > 190) fail('“' . $e . '” is not a valid email address.');
        }
        rate('share_add', 300, 3600, 'u' . $uid);
        $message = str_clean($b['message'] ?? '', 500);
        $sh = tx(function () use ($s, $kind, $uid, $emails, $role, $sid): array {
            $sh = share_get_or_create($s, $kind, $uid);
            foreach ($emails as $e) {
                $g = row('SELECT * FROM share_guests WHERE share_id = ? AND email = ?', [$sh['id'], $e]);
                if ($g) {
                    $status = in_array($g['status'], ['active'], true) ? 'active' : ((int) $g['verified'] ? 'active' : 'invited');
                    q("UPDATE share_guests SET role = ?, status = ?, req_role = '', req_note = '', req_at = 0 WHERE id = ?", [$role, $status, $g['id']]);
                } else {
                    insert('share_guests', ['share_id' => $sh['id'], 'studio_id' => $sid, 'email' => $e, 'role' => $role, 'status' => 'invited', 'added_by' => $uid, 'created' => time()]);
                }
                share_log($sid, (int) $sh['id'], $uid, ['id' => (int) val('SELECT id FROM share_guests WHERE share_id = ? AND email = ?', [$sh['id'], $e]), 'email' => $e], 0, 'access', 'added ' . $e . ' to the ' . $kind . ' link as ' . strtolower(GUEST_ROLES[$role]));
            }
            studio_touch($sid);
            return $sh;
        });
        if (!empty($b['send'])) {
            foreach ($emails as $e) guest_invite_mail($sh, $e, $role, $u, $message);
        }
        return api_share_list(['studio' => $sid], $u);
    }
    $g = row('SELECT * FROM share_guests WHERE id = ? AND studio_id = ?', [int_in($b, 'guest'), $sid]);
    if (!$g || $g['status'] === 'removed') not_found('This guest');
    $sh = row('SELECT * FROM shares WHERE id = ?', [$g['share_id']]);
    $log = function (string $text) use ($sid, $sh, $uid, $g): void { share_log($sid, (int) $sh['id'], $uid, $g, 0, 'access', $text); };
    $email = (string) $g['email'];
    $by = (string) $u['name'];
    $tell = function (string $subject, string $title, array $lines, bool $open = true) use ($email, $sh): void {
        mail_later($email, $subject, $title, $lines, $open ? 'Open the Studio' : '', $open ? share_url($sh) : '');
    };
    $studioName = (string) $s['name'];
    switch ($op) {
        case 'role':
        case 'approve':
            if ($op === 'approve' && !(int) $g['req_at']) fail('This request was already answered.', 409, 'stale');
            $role = guest_role_in($b['role'] ?? '') ?: ($op === 'approve' ? (string) $g['req_role'] : '');
            if ($role === '') fail('Choose Viewer, Commenter or Editor.');
            $up = GUEST_RANK[$role] > GUEST_RANK[$g['role']];
            // Une demande à laquelle ce droit répond est close.
            $clear = $op === 'approve' || ((int) $g['req_at'] && GUEST_RANK[$role] >= (GUEST_RANK[$g['req_role']] ?? 9));
            q('UPDATE share_guests SET role = ?' . ($clear ? ", req_role = '', req_note = '', req_at = 0" : '') . ' WHERE id = ?', [$role, $g['id']]);
            $log(($op === 'approve' ? 'approved the request of ' : 'changed the access of ') . $email . ': ' . strtolower(GUEST_ROLES[$role]));
            if ($op === 'approve' || $up) {
                $tell($by . ' gave you ' . strtolower(GUEST_ROLES[$role]) . ' access to ' . $studioName, 'Your access to “' . $studioName . '” changed', [
                    $by . ' gave you ' . strtolower(GUEST_ROLES[$role]) . ' access to the Studio “' . $studioName . '”: '
                        . ['viewer' => 'you can view the shared content.', 'commenter' => 'you can comment, and approve the cards sent to you for approval.', 'editor' => 'you can edit the content opened to you.'][$role],
                    'Open the Studio with the button below. If you are asked, confirm your email address with the code you\'ll receive.',
                ]);
            }
            break;
        case 'reject':
            if (!(int) $g['req_at']) fail('This request was already answered.', 409, 'stale');
            q("UPDATE share_guests SET req_role = '', req_note = '', req_at = 0 WHERE id = ?", [$g['id']]);
            $log('rejected the request of ' . $email . ' (' . strtolower(GUEST_ROLES[$g['req_role']] ?? 'access') . ')');
            $tell('Your request for ' . $studioName . ' was declined', 'Your request was declined', [$by . ' declined your request for ' . strtolower(GUEST_ROLES[$g['req_role']] ?? '') . ' access to the Studio “' . $studioName . '”. Your current access doesn\'t change.']);
            break;
        case 'scope':
            $lv = studio_level_ids($sid, $b['levels'] ?? []);
            $cols = ids_in($b['cols'] ?? []);
            $cols = $cols ? array_map('intval', array_column(rows('SELECT id FROM cols WHERE studio_id = ? AND id IN (' . marks($cols) . ')', array_merge([$sid], $cols)), 'id')) : [];
            $cards = ids_in($b['cards'] ?? []);
            $cards = $cards ? array_map('intval', array_column(rows('SELECT id FROM cards WHERE studio_id = ? AND id IN (' . marks($cards) . ')', array_merge([$sid], $cards)), 'id')) : [];
            $sc = ['levels' => $lv, 'cols' => $cols, 'cards' => $cards];
            q('UPDATE share_guests SET scope = ? WHERE id = ?', [jenc($sc), $g['id']]);
            $log(scope_all($sc) ? 'let ' . $email . ' edit everything the link shows' : 'limited what ' . $email . ' can edit (' . implode(', ', array_filter([
                $lv ? count($lv) . ' level' . (count($lv) > 1 ? 's' : '') : '', $cols ? count($cols) . ' column' . (count($cols) > 1 ? 's' : '') : '', $cards ? count($cards) . ' card' . (count($cards) > 1 ? 's' : '') : '',
            ])) . ')');
            break;
        case 'revoke':
            q("UPDATE share_guests SET status = 'revoked', req_role = '', req_note = '', req_at = 0 WHERE id = ?", [$g['id']]);
            q('DELETE FROM guest_sessions WHERE guest_id = ?', [$g['id']]);
            q("UPDATE card_approvals SET status = 'cancelled', decided = ? WHERE approver_guest = ? AND status = 'pending'", [time(), $g['id']]);
            $log('revoked the access of ' . $email);
            break;
        case 'restore':
            q('UPDATE share_guests SET status = ? WHERE id = ?', [(int) $g['verified'] ? 'active' : 'invited', $g['id']]);
            $log('restored the access of ' . $email);
            break;
        case 'remove':
            // L'invité disparaît de la liste ; ses commentaires et son historique gardent son adresse.
            q("UPDATE share_guests SET status = 'removed', role = 'viewer', scope = '{}', req_role = '', req_note = '', req_at = 0 WHERE id = ?", [$g['id']]);
            q('DELETE FROM guest_sessions WHERE guest_id = ?', [$g['id']]);
            q("UPDATE card_approvals SET status = 'cancelled', decided = ? WHERE approver_guest = ? AND status = 'pending'", [time(), $g['id']]);
            $log('removed ' . $email . ' from the guests');
            break;
        default:
            fail('Unknown action.');
    }
    studio_touch($sid);
    return api_share_list(['studio' => $sid], $u);
}

/* -------------------------------------------- validation demandée à un invité */

/** Invité désigné pour valider une carte : actif, commenter ou editor, sur un lien qui fonctionne et montre la carte. */
function guest_approver(int $gid, array $card): array
{
    $g = row("SELECT * FROM share_guests WHERE id = ? AND studio_id = ? AND status = 'active' AND role IN ('commenter', 'editor')", [$gid, $card['studio_id']]);
    $sh = $g ? row('SELECT * FROM shares WHERE id = ?', [$g['share_id']]) : null;
    if (!$g || !$sh || share_state($sh) !== '') fail('This guest can\'t approve cards right now: give them commenter or editor access first.');
    if (!guest_card_place($card, ['levels' => ids_in(jarr($sh['levels']))])) fail('This guest doesn\'t see this card: its level isn\'t shared with them.');
    return $g + ['share' => $sh];
}
function guest_approval_mail(array $g, array $card, array $by, string $note): void
{
    $sh = $g['share'];
    mail_later((string) $g['email'], $by['name'] . ' asks you to approve “' . $card['title'] . '”', 'Your approval is requested', array_values(array_filter([
        $by['name'] . ' asks you to review and approve the card “' . $card['title'] . '” in the Studio “' . (string) val('SELECT name FROM studios WHERE id = ?', [$card['studio_id']]) . '”.',
        $note !== '' ? '“' . $note . '”' : '',
        'Open it, then approve it or request changes. If you are asked, confirm your email address with the code you\'ll receive.',
    ])), 'Review the card', share_url($sh) . '/card/' . $card['id']);
}
