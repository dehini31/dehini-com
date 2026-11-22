<?php
/**
 * Le réseau professionnel : profils publics, fil d'actualité, abonnements et relations,
 * portfolio (études de cas), recommandations, preuves de travail, recherche.
 *
 * Confidentialité : rien de ce qui se passe dans un Studio n'apparaît ici sans publication
 * explicite. Une étude de cas tirée d'un Studio n'est publiée qu'après l'accord du propriétaire
 * du projet — et d'un client si le projet en compte.
 */
declare(strict_types=1);

const LINK_KEYS = ['instagram', 'tiktok', 'linkedin', 'youtube', 'facebook', 'x', 'behance'];
const RELATIONS = ['client' => 'Client', 'manager' => 'Manager', 'colleague' => 'Colleague', 'partner' => 'Partner'];

/* ---------------------------------------------------------------- profils */

function pair(int $a, int $b): array
{
    return $a < $b ? [$a, $b] : [$b, $a];
}
/** Relation entre le compte connecté et un autre : none | out (demande envoyée) | in (reçue) | connected. */
function connection_state(int $me, int $other): string
{
    if ($me === $other) return 'self';
    [$a, $b] = pair($me, $other);
    $c = row('SELECT * FROM connections WHERE a = ? AND b = ?', [$a, $b]);
    if (!$c) return 'none';
    if ($c['status'] === 'accepted') return 'connected';
    return (int) $c['by'] === $me ? 'out' : 'in';
}
function follows(int $uid, string $type, int $id): bool
{
    return (bool) val('SELECT 1 FROM follows WHERE uid = ? AND ttype = ? AND tid = ?', [$uid, $type, $id]);
}
/** Fiche d'un compte dans une liste de personnes (annuaire, réseau, recherche). */
function person_view(array $t): array
{
    return user_card($t) + [
        'location' => (string) $t['location'], 'specialization' => (string) $t['specialization'], 'skills' => jarr($t['skills']),
        'platforms' => jarr($t['platforms']), 'industries' => jarr($t['industries']), 'languages' => jarr($t['languages']),
        'experience' => (int) $t['experience'], 'availability' => (string) $t['availability'], 'pro' => (bool) $t['pro'],
    ];
}
function people_view(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $map = [];
    foreach (rows('SELECT * FROM users WHERE id IN (' . marks($ids) . ')', $ids) as $t) $map[(int) $t['id']] = person_view($t);
    $out = [];
    foreach ($ids as $id) {
        if (isset($map[$id])) $out[] = $map[$id];
    }
    return $out;
}

/**
 * Preuves de travail d'un professionnel : des faits vérifiables et leur origine, jamais un score
 * ni un classement. Trois niveaux sont distingués : vérifié par un Studio, confirmé par un
 * client, déclaré par la personne.
 */
function proof_of_work(array $t): array
{
    $uid = (int) $t['id'];
    $p = ['cases' => 0, 'studio' => 0, 'client' => 0, 'self' => 0, 'pieces' => 0];
    foreach (rows("SELECT source, pieces, client_ok, confirm_uid FROM portfolio WHERE uid = ? AND status = 'published'", [$uid]) as $c) {
        $p['cases']++;
        $confirmed = (int) $c['client_ok'] > 0 || (int) $c['confirm_uid'] > 0;
        if ($c['source'] === 'studio') { $p['studio']++; $p['pieces'] += (int) $c['pieces']; }
        if ($confirmed) $p['client']++;
        if ($c['source'] !== 'studio' && !$confirmed) $p['self']++;
    }
    $r = row("SELECT COUNT(*) AS n, SUM(CASE WHEN relation = 'client' THEN 1 ELSE 0 END) AS clients, SUM(together) AS together FROM recommendations WHERE to_uid = ? AND status = 'visible'", [$uid]);
    $p['recommendations'] = (int) $r['n'];
    $p['client_recommendations'] = (int) $r['clients'];
    $p['worked_together'] = (int) $r['together'];
    // Activité dans les Studios : seulement si la personne a choisi de l'afficher, et sans aucun nom de projet.
    if (user_settings($t)['show_activity']) {
        $p['activity'] = [
            'delivered' => (int) val("SELECT COUNT(*) FROM cards c JOIN cols co ON co.id = c.col_id WHERE co.kind = 'done' AND c.archived = 0
                AND (c.assignee = ? OR EXISTS (SELECT 1 FROM card_members m WHERE m.card_id = c.id AND m.uid = ?))", [$uid, $uid]),
            'projects' => (int) val('SELECT COUNT(*) FROM (SELECT project_id FROM project_members WHERE uid = ? UNION SELECT s.project_id FROM studio_members m JOIN studios s ON s.id = m.studio_id WHERE m.uid = ?)', [$uid, $uid]),
        ];
    }
    return $p;
}

function api_profile_get(array $b, ?array $u): array
{
    $t = row('SELECT * FROM users WHERE handle = ?', [strtolower(line_clean($b['handle'] ?? '', 40))]);
    // Compte suspendu par l'administration : son profil n'est plus visible.
    if (!$t || (int) $t['suspended'] > 0) not_found('This profile');
    $tid = (int) $t['id'];
    $me = $u ? (int) $u['id'] : 0;
    $self = $me === $tid;
    $links = [];
    foreach (jarr($t['links']) as $k => $v) {
        if (in_array($k, LINK_KEYS, true) && is_string($v) && $v !== '') $links[$k] = $v;
    }
    $profile = person_view($t) + [
        'bio' => (string) $t['bio'], 'cover' => (string) $t['cover'], 'website' => (string) $t['website'], 'contact' => (string) $t['contact'],
        'links' => (object) $links, 'kind' => (string) $t['kind'], 'created' => (int) $t['created'],
        'followers' => (int) val("SELECT COUNT(*) FROM follows WHERE ttype = 'user' AND tid = ?", [$tid]),
        'following' => (int) val('SELECT COUNT(*) FROM follows WHERE uid = ?', [$tid]),
        'connections' => (int) val("SELECT COUNT(*) FROM connections WHERE (a = ? OR b = ?) AND status = 'accepted'", [$tid, $tid]),
    ];
    if ($self) $profile['settings'] = user_settings($t);
    $companies = [];
    foreach (rows('SELECT c.*, m.role AS m_role, m.title AS m_title FROM company_members m JOIN companies c ON c.id = m.company_id WHERE m.uid = ? ORDER BY m.created', [$tid]) as $c) {
        $companies[] = company_card($c) + ['member_title' => (string) $c['m_title'], 'member_role' => (string) $c['m_role']];
    }
    $recs = rows('SELECT * FROM recommendations WHERE to_uid = ?' . ($self ? '' : " AND status = 'visible'") . ' ORDER BY id DESC', [$tid]);
    $authors = user_cards(array_column($recs, 'from_uid'));
    return [
        'profile' => $profile,
        'rel' => ['self' => $self, 'following' => $me && follows($me, 'user', $tid), 'connection' => $me ? connection_state($me, $tid) : 'none',
            'saved' => $me && (bool) val('SELECT 1 FROM saved_pros WHERE uid = ? AND pro_uid = ?', [$me, $tid])],
        'companies' => $companies,
        'portfolio' => array_map('portfolio_view', rows('SELECT * FROM portfolio WHERE uid = ?' . ($self ? '' : " AND status = 'published'") . ' ORDER BY id DESC', [$tid])),
        'achievements' => profile_achievements($tid, $u),   // cartes publiées en réalisations (lib/pubs.php)
        'recommendations' => array_map(function (array $r) use ($authors): array {
            return ['id' => (int) $r['id'], 'body' => (string) $r['body'], 'relation' => (string) $r['relation'], 'together' => (bool) $r['together'],
                'status' => (string) $r['status'], 'created' => (int) $r['created'], 'from' => $authors[(int) $r['from_uid']] ?? null];
        }, $recs),
        'proof' => proof_of_work($t),
    ];
}
function api_profile_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $name = line_clean($b['name'] ?? $u['name'], 60);
    if (mb_strlen($name) < 2) fail('Enter your name.');
    $handle = strtolower(line_clean($b['handle'] ?? $u['handle'], 30));
    if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,29}$/', $handle) || preg_match('/[._-]$/', $handle)) fail('Your username must be 3 to 30 characters: letters, digits, dots, dashes or underscores.');
    if (val('SELECT 1 FROM users WHERE handle = ? AND id <> ?', [$handle, $uid])) fail('This username is already taken.');
    $links = [];
    foreach (LINK_KEYS as $k) {
        $v = url_clean($b['links'][$k] ?? '', 200);
        if ($v !== '') $links[$k] = $v;
    }
    $contact = strtolower(line_clean($b['contact'] ?? '', 190));
    if ($contact !== '' && !valid_email($contact)) fail('Enter a valid contact email, or leave it empty.');
    $set = [
        'name' => $name, 'handle' => $handle, 'title' => line_clean($b['title'] ?? '', 100), 'bio' => str_clean($b['bio'] ?? '', 1500),
        'specialization' => line_clean($b['specialization'] ?? '', 80), 'location' => line_clean($b['location'] ?? '', 80),
        'skills' => jenc(list_clean($b['skills'] ?? [], 20)), 'platforms' => jenc(keys_clean($b['platforms'] ?? [], STM_PLATFORMS)),
        'industries' => jenc(list_clean($b['industries'] ?? [], 8)), 'languages' => jenc(list_clean($b['languages'] ?? [], 8, 30)),
        'experience' => max(0, min(60, int_in($b, 'experience'))),
        'availability' => isset(STM_AVAILABILITY[$b['availability'] ?? '']) ? $b['availability'] : 'open',
        'website' => url_clean($b['website'] ?? ''), 'contact' => $contact, 'links' => jenc((object) $links),
        'pro' => empty($b['pro']) ? 0 : 1,
    ];
    foreach (['avatar', 'cover'] as $k) {
        if (array_key_exists($k, $b)) $set[$k] = $b[$k] === $u[$k] ? $u[$k] : own_image($b[$k], $uid);
    }
    $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set)));
    q("UPDATE users SET $sets WHERE id = ?", array_merge(array_values($set), [$uid]));
    user_reindex($uid);
    return ['handle' => $handle];
}

/* ------------------------------------------------------------ fil, posts */

function post_views(array $posts, ?array $u, bool $withShared = true): array
{
    // Les publications des comptes suspendus par l'administration ne sont plus montrées.
    if ($posts) {
        $authors = array_values(array_unique(array_map('intval', array_column($posts, 'uid'))));
        $hidden = array_map('intval', array_column(rows('SELECT id FROM users WHERE suspended > 0 AND id IN (' . marks($authors) . ')', $authors), 'id'));
        if ($hidden) $posts = array_values(array_filter($posts, function (array $p) use ($hidden): bool { return !in_array((int) $p['uid'], $hidden, true); }));
    }
    // Publication liée à une réalisation (lib/pubs.php) : montrée seulement tant que la réalisation est en ligne.
    $pubs = $posts ? feed_pubs(array_column($posts, 'pub_id')) : [];
    $posts = array_values(array_filter($posts, function (array $p) use ($pubs): bool { return !(int) ($p['pub_id'] ?? 0) || isset($pubs[(int) $p['pub_id']]); }));
    if (!$posts) return [];
    $me = $u ? (int) $u['id'] : 0;
    $ids = array_map('intval', array_column($posts, 'id'));
    $in = marks($ids);
    $likes = [];
    foreach (rows("SELECT post_id, COUNT(*) AS n FROM post_likes WHERE post_id IN ($in) GROUP BY post_id", $ids) as $r) $likes[(int) $r['post_id']] = (int) $r['n'];
    $comments = [];
    foreach (rows("SELECT post_id, COUNT(*) AS n FROM post_comments WHERE post_id IN ($in) GROUP BY post_id", $ids) as $r) $comments[(int) $r['post_id']] = (int) $r['n'];
    $liked = $me ? array_map('intval', array_column(rows("SELECT post_id FROM post_likes WHERE uid = ? AND post_id IN ($in)", array_merge([$me], $ids)), 'post_id')) : [];
    $saved = $me ? array_map('intval', array_column(rows("SELECT post_id FROM post_saves WHERE uid = ? AND post_id IN ($in)", array_merge([$me], $ids)), 'post_id')) : [];
    $users = user_cards(array_column($posts, 'uid'));
    $companies = company_cards(array_column($posts, 'company_id'));
    $shared = [];
    if ($withShared) {
        $sids = array_values(array_unique(array_filter(array_map('intval', array_column($posts, 'share_id')))));
        if ($sids) {
            foreach (post_views(rows('SELECT * FROM posts WHERE id IN (' . marks($sids) . ')', $sids), $u, false) as $sp) $shared[$sp['id']] = $sp;
        }
    }
    $out = [];
    foreach ($posts as $p) {
        $id = (int) $p['id'];
        $cid = (int) $p['company_id'];
        $out[] = [
            'id' => $id, 'body' => (string) $p['body'], 'media' => files_view(jarr($p['media'])), 'created' => (int) $p['created'],
            'author' => $users[(int) $p['uid']] ?? null, 'company' => $companies[$cid] ?? null,
            'likes' => $likes[$id] ?? 0, 'comments' => $comments[$id] ?? 0, 'liked' => in_array($id, $liked, true), 'saved' => in_array($id, $saved, true),
            'share_id' => (int) $p['share_id'], 'shared' => (int) $p['share_id'] ? ($shared[(int) $p['share_id']] ?? null) : null,
            'pub' => (int) ($p['pub_id'] ?? 0) ? ($pubs[(int) $p['pub_id']] ?? null) : null,
            'mine' => $me && ((int) $p['uid'] === $me || ($cid && company_admin($me, $cid))),
        ];
    }
    return $out;
}
/** GET feed : tab = home | discover | saved | user (id) | company (id) | post (id) ; before = pagination. */
function api_feed(array $b, ?array $u): array
{
    $tab = (string) ($b['tab'] ?? 'home');
    $before = int_in($b, 'before') ?: PHP_INT_MAX;
    $me = $u ? (int) $u['id'] : 0;
    $limit = 15;
    if (in_array($tab, ['home', 'saved'], true) && !$u) fail('Please sign in to continue.', 401, 'auth');
    switch ($tab) {
        case 'home':
            // Mes publications, celles des comptes et entreprises que je suis, et de mes relations.
            $posts = rows("SELECT p.* FROM posts p WHERE p.id < ? AND (p.uid = ?
                    OR (p.company_id = 0 AND p.uid IN (SELECT tid FROM follows WHERE uid = ? AND ttype = 'user'))
                    OR (p.company_id > 0 AND p.company_id IN (SELECT tid FROM follows WHERE uid = ? AND ttype = 'company'))
                    OR (p.company_id = 0 AND p.uid IN (SELECT CASE WHEN a = ? THEN b ELSE a END FROM connections WHERE (a = ? OR b = ?) AND status = 'accepted'))
                    -- Opportunités publiées dans la communauté : publiques par choix de leur auteur, montrées à tous.
                    OR p.pub_id IN (SELECT id FROM pubs WHERE kind = 'opportunity' AND status = 'open'))
                ORDER BY p.id DESC LIMIT $limit", [$before, $me, $me, $me, $me, $me, $me]);
            break;
        case 'saved':
            $posts = rows("SELECT p.* FROM posts p JOIN post_saves s ON s.post_id = p.id WHERE s.uid = ? AND p.id < ? ORDER BY p.id DESC LIMIT $limit", [$me, $before]);
            break;
        case 'user':
            $posts = rows("SELECT * FROM posts WHERE uid = ? AND company_id = 0 AND id < ? ORDER BY id DESC LIMIT $limit", [int_in($b, 'id'), $before]);
            break;
        case 'company':
            $posts = rows("SELECT * FROM posts WHERE company_id = ? AND id < ? ORDER BY id DESC LIMIT $limit", [int_in($b, 'id'), $before]);
            break;
        case 'post':
            $posts = rows('SELECT * FROM posts WHERE id = ?', [int_in($b, 'id')]);
            if (!$posts) not_found('This post');
            break;
        default:
            $posts = rows("SELECT * FROM posts WHERE id < ? ORDER BY id DESC LIMIT $limit", [$before]);
    }
    return ['posts' => post_views($posts, $u), 'more' => count($posts) === $limit];
}
function api_post_create(array $b, array $u): array
{
    $uid = (int) $u['id'];
    rate('post', 30, 3600, 'u' . $uid);
    $body = str_clean($b['body'] ?? '', 3000);
    $media = own_media($b['media'] ?? [], $uid, 6);
    $share = int_in($b, 'share_id');
    if ($share) {
        $orig = row('SELECT id, share_id FROM posts WHERE id = ?', [$share]);
        if (!$orig) fail('The post you are sharing no longer exists.');
        if ((int) $orig['share_id']) $share = (int) $orig['share_id'];   // on partage toujours l'original
    }
    if ($body === '' && !$media && !$share) fail('Write something or add a photo or a video.');
    $cid = int_in($b, 'company_id');
    if ($cid && !company_admin($uid, $cid)) forbidden('Only admins can post on behalf of this company.');
    $id = insert('posts', ['uid' => $uid, 'company_id' => $cid, 'body' => $body, 'media' => jenc($media), 'share_id' => $share, 'created' => time()]);
    if ($share) {
        $o = row('SELECT uid FROM posts WHERE id = ?', [$share]);
        if ($o) notify((int) $o['uid'], $uid, 'post', 'shared your post', 'post/' . $id);
    }
    return ['post' => post_views(rows('SELECT * FROM posts WHERE id = ?', [$id]), $u)[0]];
}
function api_post_delete(array $b, array $u): array
{
    $p = row('SELECT * FROM posts WHERE id = ?', [int_in($b, 'id')]);
    if (!$p) return [];
    $uid = (int) $u['id'];
    if ((int) $p['uid'] !== $uid && !((int) $p['company_id'] && company_admin($uid, (int) $p['company_id']))) forbidden('You can only delete your own posts.');
    // Publication d'une réalisation : la supprimer du fil retire la réalisation de la communauté (elle reste
    // dans la carte et peut être republiée).
    if ((int) ($p['pub_id'] ?? 0)) q("UPDATE pubs SET status = 'hidden', post_id = 0, updated = ? WHERE id = ? AND post_id = ?", [time(), $p['pub_id'], $p['id']]);
    q('DELETE FROM posts WHERE id = ?', [$p['id']]);
    files_release(jarr($p['media']));
    return [];
}
function api_post_like(array $b, array $u): array
{
    $p = row('SELECT id, uid FROM posts WHERE id = ?', [int_in($b, 'id')]);
    if (!$p) not_found('This post');
    $uid = (int) $u['id'];
    if (!empty($b['on'])) {
        $new = q('INSERT OR IGNORE INTO post_likes (post_id, uid, created) VALUES (?, ?, ?)', [$p['id'], $uid, time()])->rowCount();
        if ($new) notify((int) $p['uid'], $uid, 'post', 'liked your post', 'post/' . $p['id']);
    } else {
        q('DELETE FROM post_likes WHERE post_id = ? AND uid = ?', [$p['id'], $uid]);
    }
    return ['likes' => (int) val('SELECT COUNT(*) FROM post_likes WHERE post_id = ?', [$p['id']])];
}
function api_post_save(array $b, array $u): array
{
    $p = row('SELECT id FROM posts WHERE id = ?', [int_in($b, 'id')]);
    if (!$p) not_found('This post');
    if (!empty($b['on'])) q('INSERT OR IGNORE INTO post_saves (post_id, uid, created) VALUES (?, ?, ?)', [$p['id'], $u['id'], time()]);
    else q('DELETE FROM post_saves WHERE post_id = ? AND uid = ?', [$p['id'], $u['id']]);
    return [];
}
function api_post_comments(array $b, ?array $u): array
{
    $pid = int_in($b, 'id');
    $list = rows('SELECT * FROM post_comments WHERE post_id = ? ORDER BY id LIMIT 200', [$pid]);
    $users = user_cards(array_column($list, 'uid'));
    $me = $u ? (int) $u['id'] : 0;
    $owner = (int) (val('SELECT uid FROM posts WHERE id = ?', [$pid]) ?? 0);
    return ['comments' => array_map(function (array $c) use ($users, $me, $owner): array {
        return ['id' => (int) $c['id'], 'body' => (string) $c['body'], 'created' => (int) $c['created'], 'user' => $users[(int) $c['uid']] ?? null,
            'mine' => $me && ((int) $c['uid'] === $me || $owner === $me)];
    }, $list)];
}
function api_post_comment(array $b, array $u): array
{
    $p = row('SELECT id, uid FROM posts WHERE id = ?', [int_in($b, 'id')]);
    if (!$p) not_found('This post');
    $uid = (int) $u['id'];
    rate('pcomment', 60, 600, 'u' . $uid);
    $body = str_clean($b['body'] ?? '', 1500);
    if ($body === '') fail('Write a comment first.');
    insert('post_comments', ['post_id' => $p['id'], 'uid' => $uid, 'body' => $body, 'created' => time()]);
    notify((int) $p['uid'], $uid, 'post', 'commented on your post: ' . mb_substr($body, 0, 80), 'post/' . $p['id']);
    return api_post_comments(['id' => $p['id']], $u);
}
function api_post_comment_delete(array $b, array $u): array
{
    $c = row('SELECT c.*, p.uid AS owner FROM post_comments c JOIN posts p ON p.id = c.post_id WHERE c.id = ?', [int_in($b, 'id')]);
    if (!$c) return [];
    if ((int) $c['uid'] !== (int) $u['id'] && (int) $c['owner'] !== (int) $u['id']) forbidden('You can only delete your own comments.');
    q('DELETE FROM post_comments WHERE id = ?', [$c['id']]);
    return [];
}

/* ------------------------------------------------------------- relations */

function api_follow(array $b, array $u): array
{
    $type = ($b['type'] ?? '') === 'company' ? 'company' : 'user';
    $id = int_in($b, 'id');
    $uid = (int) $u['id'];
    if ($type === 'user' && $id === $uid) fail('You can\'t follow yourself.');
    if (!val('SELECT 1 FROM ' . ($type === 'user' ? 'users' : 'companies') . ' WHERE id = ?', [$id])) not_found('This page');
    if (!empty($b['on'])) {
        $new = q('INSERT OR IGNORE INTO follows (uid, ttype, tid, created) VALUES (?, ?, ?, ?)', [$uid, $type, $id, time()])->rowCount();
        if ($new && $type === 'user') notify($id, $uid, 'network', 'started following you', 'u/' . $u['handle']);
    } else {
        q('DELETE FROM follows WHERE uid = ? AND ttype = ? AND tid = ?', [$uid, $type, $id]);
    }
    return [];
}
/** Demande de mise en relation (ou acceptation, si l'autre l'avait déjà demandée). */
function api_connect(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $other = int_in($b, 'uid');
    if ($other === $uid || !user_by_id($other)) fail('This person no longer exists.');
    rate('connect', 60, 3600, 'u' . $uid);
    [$a, $c] = pair($uid, $other);
    $state = connection_state($uid, $other);
    if ($state === 'none') {
        q('INSERT INTO connections (a, b, status, by, created) VALUES (?, ?, ?, ?, ?)', [$a, $c, 'pending', $uid, time()]);
        notify($other, $uid, 'network', 'wants to connect with you', 'network');
    } elseif ($state === 'in') {
        q("UPDATE connections SET status = 'accepted' WHERE a = ? AND b = ?", [$a, $c]);
        notify($other, $uid, 'network', 'accepted your connection request', 'u/' . $u['handle']);
    }
    return ['connection' => connection_state($uid, $other)];
}
/** Refuse une demande, annule la sienne ou retire une relation. */
function api_disconnect(array $b, array $u): array
{
    [$a, $c] = pair((int) $u['id'], int_in($b, 'uid'));
    q('DELETE FROM connections WHERE a = ? AND b = ?', [$a, $c]);
    return ['connection' => 'none'];
}
function api_network(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $conn = rows('SELECT * FROM connections WHERE a = ? OR b = ? ORDER BY created DESC', [$uid, $uid]);
    $other = function (array $c) use ($uid): int { return (int) $c['a'] === $uid ? (int) $c['b'] : (int) $c['a']; };
    $connected = $in = $out = [];
    foreach ($conn as $c) {
        if ($c['status'] === 'accepted') $connected[] = $other($c);
        elseif ((int) $c['by'] === $uid) $out[] = $other($c);
        else $in[] = $other($c);
    }
    $following = array_map('intval', array_column(rows("SELECT tid FROM follows WHERE uid = ? AND ttype = 'user' ORDER BY created DESC", [$uid]), 'tid'));
    $followers = array_map('intval', array_column(rows("SELECT uid FROM follows WHERE ttype = 'user' AND tid = ? ORDER BY created DESC", [$uid]), 'uid'));
    $companies = array_map('intval', array_column(rows("SELECT tid FROM follows WHERE uid = ? AND ttype = 'company' ORDER BY created DESC", [$uid]), 'tid'));
    return [
        'connections' => people_view($connected), 'requests' => people_view($in), 'sent' => people_view($out),
        'following' => people_view($following), 'followers' => people_view($followers),
        'companies' => array_values(company_cards($companies)),
        'suggestions' => suggestions($u, array_merge($connected, $in, $out, $following), 9),
    ];
}
/** Personnes à découvrir : coéquipiers d'abord, puis profils partageant des compétences, puis les plus récents. */
function suggestions(array $u, array $exclude, int $limit): array
{
    $uid = (int) $u['id'];
    $exclude[] = $uid;
    $ids = [];
    $add = function (array $list) use (&$ids, $exclude, $limit): void {
        foreach ($list as $id) {
            $id = (int) $id;
            if (!in_array($id, $exclude, true) && !in_array($id, $ids, true) && count($ids) < $limit) $ids[] = $id;
        }
    };
    $add(array_column(rows('SELECT DISTINCT m2.uid FROM project_members m1 JOIN project_members m2 ON m2.project_id = m1.project_id WHERE m1.uid = ? LIMIT 30', [$uid]), 'uid'));
    foreach (array_slice(jarr($u['skills']), 0, 4) as $skill) {
        $add(array_column(rows("SELECT id FROM users WHERE pro = 1 AND suspended = 0 AND search LIKE ? ESCAPE '\\' ORDER BY last_seen DESC LIMIT 12", [like((string) $skill)]), 'id'));
    }
    $add(array_column(rows("SELECT id FROM users WHERE pro = 1 AND suspended = 0 AND title <> '' ORDER BY last_seen DESC LIMIT 40"), 'id'));
    $add(array_column(rows('SELECT id FROM users WHERE suspended = 0 ORDER BY id DESC LIMIT 30'), 'id'));
    return people_view($ids);
}

/* -------------------------------------------------------------- portfolio */

function portfolio_view(array $p): array
{
    $metrics = [];
    foreach (jarr($p['metrics']) as $m) {
        if (is_array($m) && isset($m['label'], $m['value'])) $metrics[] = ['label' => (string) $m['label'], 'value' => (string) $m['value']];
    }
    $confirmer = (int) $p['client_ok'] ?: (int) $p['confirm_uid'];
    return [
        'id' => (int) $p['id'], 'uid' => (int) $p['uid'], 'title' => (string) $p['title'], 'brand' => (string) $p['brand'], 'role' => (string) $p['role'],
        'period' => (string) $p['period'], 'objectives' => (string) $p['objectives'], 'body' => (string) $p['body'], 'results' => (string) $p['results'],
        'metrics' => $metrics, 'media' => files_view(jarr($p['media'])), 'links' => array_values(array_filter(jarr($p['links']), 'is_string')),
        'platforms' => jarr($p['platforms']), 'source' => (string) $p['source'], 'pieces' => (int) $p['pieces'], 'status' => (string) $p['status'],
        'need_owner' => (bool) $p['need_owner'], 'owner_ok' => (int) $p['owner_ok'] > 0, 'need_client' => (bool) $p['need_client'], 'client_ok' => (int) $p['client_ok'] > 0,
        // Origine des résultats : studio (vérifié par la plateforme), client (confirmé), self (déclaré).
        'verified' => $p['source'] === 'studio', 'confirmed_by' => $confirmer ? (user_cards([$confirmer])[$confirmer] ?? null) : null,
        'confirm_ask' => (int) $p['confirm_ask'], 'created' => (int) $p['created'],
    ];
}
function portfolio_fields(array $b, int $uid): array
{
    $title = line_clean($b['title'] ?? '', 120);
    if ($title === '') fail('Give this case study a title.');
    $metrics = [];
    foreach (is_array($b['metrics'] ?? null) ? $b['metrics'] : [] as $m) {
        $label = line_clean($m['label'] ?? '', 40);
        $value = line_clean($m['value'] ?? '', 30);
        if ($label !== '' && $value !== '' && count($metrics) < 8) $metrics[] = ['label' => $label, 'value' => $value];
    }
    $links = [];
    foreach (is_array($b['links'] ?? null) ? $b['links'] : [] as $l) {
        $l = url_clean($l, 500);
        if ($l !== '' && count($links) < 10) $links[] = $l;
    }
    return [
        'title' => $title, 'brand' => line_clean($b['brand'] ?? '', 80), 'role' => line_clean($b['role'] ?? '', 120), 'period' => line_clean($b['period'] ?? '', 40),
        'objectives' => str_clean($b['objectives'] ?? '', 1500), 'body' => str_clean($b['body'] ?? '', 4000), 'results' => str_clean($b['results'] ?? '', 1500),
        'metrics' => jenc($metrics), 'media' => jenc(own_media($b['media'] ?? [], $uid, 8)), 'links' => jenc($links),
        'platforms' => jenc(keys_clean($b['platforms'] ?? [], STM_PLATFORMS)), 'updated' => time(),
    ];
}
function own_portfolio(int $id, array $u): array
{
    $p = row('SELECT * FROM portfolio WHERE id = ? AND uid = ?', [$id, $u['id']]);
    if (!$p) not_found('This case study');
    return $p;
}
/** Rend publics (ou privés) les médias d'une étude de cas. */
function portfolio_media_scope(array $p, string $scope): void
{
    $tokens = jarr($p['media']);
    if ($tokens) q('UPDATE files SET scope = ? WHERE uid = ? AND scope <> \'studio\' AND token IN (' . marks($tokens) . ')', array_merge([$scope, $p['uid']], $tokens));
}
function api_portfolio_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $data = portfolio_fields($b, $uid);
    $id = int_in($b, 'id');
    $publish = !empty($b['publish']);
    if ($id) {
        $p = own_portfolio($id, $u);
        // Résultats modifiés après la confirmation d'un client : la confirmation ne vaut plus.
        if ((int) $p['confirm_uid'] && ($data['results'] !== $p['results'] || $data['metrics'] !== $p['metrics'])) $data += ['confirm_uid' => 0, 'confirm_at' => 0];
        $sets = implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($data)));
        q("UPDATE portfolio SET $sets WHERE id = ?", array_merge(array_values($data), [$id]));
    } else {
        if ((int) val('SELECT COUNT(*) FROM portfolio WHERE uid = ?', [$uid]) >= 60) fail('Your portfolio is full.');
        $id = insert('portfolio', $data + ['uid' => $uid, 'status' => 'draft', 'created' => time()]);
    }
    $status = portfolio_set_status(own_portfolio($id, $u), $publish, $u);
    return ['id' => $id, 'status' => $status];
}
/**
 * Publie une étude de cas (ou la repasse en brouillon). Tirée d'un Studio, elle attend d'abord
 * les autorisations exigées (status = pending) ; les personnes concernées sont prévenues.
 */
function portfolio_set_status(array $p, bool $publish, array $u): string
{
    $uid = (int) $u['id'];
    if (!$publish) {
        $status = 'draft';
    } elseif (((int) $p['need_owner'] && !(int) $p['owner_ok']) || ((int) $p['need_client'] && !(int) $p['client_ok'])) {
        $status = 'pending';
        if ($p['status'] !== 'pending') {
            foreach (portfolio_authorizers($p) as $who => $role) {
                notify($who, $uid, 'portfolio', 'asks your authorization to publish the case study “' . $p['title'] . '” on their profile', 'requests', true);
            }
        }
    } else {
        $status = 'published';
    }
    q('UPDATE portfolio SET status = ? WHERE id = ?', [$status, $p['id']]);
    portfolio_media_scope($p, $status === 'published' ? 'public' : 'private');
    return $status;
}
/** Qui peut autoriser la publication : [uid => owner | client] (propriétaires et managers du projet, clients). */
function portfolio_authorizers(array $p): array
{
    $project = row('SELECT * FROM projects WHERE id = ?', [$p['project_id']]);
    if (!$project) return [];
    $out = [];
    $all = rows('SELECT uid, role FROM project_members WHERE project_id = ?', [$project['id']]);
    if ((int) $p['studio_id']) $all = array_merge($all, rows('SELECT uid, role FROM studio_members WHERE studio_id = ?', [$p['studio_id']]));
    foreach ($all as $m) {
        $who = (int) $m['uid'];
        if ($who === (int) $p['uid']) continue;
        if ((int) $p['need_client'] && !(int) $p['client_ok'] && $m['role'] === 'client') $out[$who] = 'client';
        elseif ((int) $p['need_owner'] && !(int) $p['owner_ok'] && in_array($m['role'], ['owner', 'manager'], true)) $out[$who] = 'owner';
    }
    return $out;
}
function api_portfolio_delete(array $b, array $u): array
{
    $p = own_portfolio(int_in($b, 'id'), $u);
    q('DELETE FROM portfolio WHERE id = ?', [$p['id']]);
    files_release(jarr($p['media']));
    return [];
}
/**
 * Transforme des cartes publiées d'un Studio en brouillon d'étude de cas. Le brouillon reste
 * privé ; sa publication exige l'accord du propriétaire du projet (si ce n'est pas l'auteur) et
 * d'un client si le projet en compte.
 */
function api_portfolio_from_studio(array $b, array $u): array
{
    $s = load_studio(int_in($b, 'studio'), $u, 'work');
    $uid = (int) $u['id'];
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', is_array($b['card_ids'] ?? null) ? $b['card_ids'] : [])))), 0, 30);
    if (!$ids) fail('Select at least one published card.');
    $cards = rows("SELECT c.* FROM cards c JOIN cols co ON co.id = c.col_id WHERE c.studio_id = ? AND c.archived = 0 AND co.kind = 'done' AND c.id IN (" . marks($ids) . ')', array_merge([$s['id']], $ids));
    if (count($cards) !== count($ids)) fail('Only cards in a “Done” column (published work) can become a case study.');
    $platforms = [];
    $links = [];
    $sum = [];
    $media = [];
    $pieces = 0;
    foreach ($cards as $c) {
        if (!can($s['role'], 'edit') && !card_involves($c, $uid)) forbidden('You can only showcase cards you worked on.');
        foreach (jarr($c['platforms']) as $p) $platforms[$p] = true;
        if ($c['publish_url'] !== '') { $links[] = $c['publish_url']; $pieces++; }
        foreach (jarr($c['metrics']) as $k => $v) $sum[$k] = ($sum[$k] ?? 0) + (int) $v;
    }
    if (!$pieces) fail('Add the publication link to at least one of these cards first: it is what makes the work verifiable.');
    $p = $s['project'];
    $hasClient = (bool) val("SELECT 1 FROM project_members WHERE project_id = ? AND role = 'client' AND uid <> ? UNION SELECT 1 FROM studio_members WHERE studio_id = ? AND role = 'client' AND uid <> ?", [$p['id'], $uid, $s['id'], $uid]);
    $isOwner = project_role($uid, $p) === 'owner';
    $id = tx(function () use ($cards, $ids, $s, $p, $uid, $platforms, $links, $sum, $pieces, $hasClient, $isOwner, &$media): int {
        // Les images jointes aux cartes sont copiées en privé : l'auteur choisit ensuite lesquelles garder.
        foreach (rows("SELECT f.* FROM card_files cf JOIN files f ON f.id = cf.file_id WHERE f.kind = 'image' AND cf.card_id IN (" . marks($ids) . ') ORDER BY cf.id LIMIT 6', $ids) as $f) {
            $media[] = file_copy($f, $uid, 'private')['token'];
        }
        $metrics = [];
        foreach ($sum as $k => $v) {
            if ($v > 0) $metrics[] = ['label' => ucfirst((string) $k), 'value' => number_format($v)];
        }
        $now = time();
        return insert('portfolio', [
            'uid' => $uid, 'title' => mb_substr($p['name'] . ' — ' . $s['name'], 0, 120), 'brand' => $p['name'], 'metrics' => jenc($metrics), 'media' => jenc($media),
            'links' => jenc(array_slice(array_values(array_unique($links)), 0, 10)), 'platforms' => jenc(array_keys($platforms)), 'source' => 'studio',
            'project_id' => $p['id'], 'studio_id' => $s['id'], 'card_ids' => jenc(array_map('intval', array_column($cards, 'id'))), 'pieces' => $pieces, 'status' => 'draft',
            'need_owner' => $isOwner ? 0 : 1, 'need_client' => $hasClient ? 1 : 0, 'created' => $now, 'updated' => $now,
        ]);
    });
    return ['id' => $id, 'need_owner' => !$isOwner, 'need_client' => $hasClient];
}
/** Demandes d'autorisation de publication, et demandes de confirmation de résultats, adressées au compte. */
function api_requests(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $auth = [];
    foreach (rows("SELECT * FROM portfolio WHERE status = 'pending' AND uid <> ?", [$uid]) as $p) {
        $who = portfolio_authorizers($p);
        if (!isset($who[$uid])) continue;
        $auth[] = portfolio_view($p) + ['as' => $who[$uid], 'author' => user_cards([(int) $p['uid']])[(int) $p['uid']] ?? null,
            'project_name' => (string) val('SELECT name FROM projects WHERE id = ?', [$p['project_id']]),
            'card_titles' => array_column(rows('SELECT title FROM cards WHERE id IN (' . (marks(jarr($p['card_ids'])) ?: 'NULL') . ')', jarr($p['card_ids'])), 'title')];
    }
    $confirm = [];
    foreach (rows("SELECT * FROM portfolio WHERE confirm_ask = ? AND confirm_uid = 0 AND status = 'published'", [$uid]) as $p) {
        $confirm[] = portfolio_view($p) + ['author' => user_cards([(int) $p['uid']])[(int) $p['uid']] ?? null];
    }
    $recs = rows("SELECT r.*, u.name, u.handle, u.title, u.avatar FROM recommendations r JOIN users u ON u.id = r.from_uid WHERE r.to_uid = ? AND r.status = 'pending'", [$uid]);
    return ['authorizations' => $auth, 'confirmations' => $confirm, 'recommendations' => count($recs)];
}
function api_portfolio_authorize(array $b, array $u): array
{
    $p = row("SELECT * FROM portfolio WHERE id = ? AND status = 'pending'", [int_in($b, 'id')]);
    $uid = (int) $u['id'];
    $who = $p ? portfolio_authorizers($p) : [];
    if (!$p || !isset($who[$uid])) not_found('This request');
    if (empty($b['approve'])) {
        q("UPDATE portfolio SET status = 'declined' WHERE id = ?", [$p['id']]);
        notify((int) $p['uid'], $uid, 'portfolio', 'declined the publication of your case study “' . $p['title'] . '”', 'u/' . val('SELECT handle FROM users WHERE id = ?', [$p['uid']]), true);
        return ['status' => 'declined'];
    }
    q('UPDATE portfolio SET ' . ($who[$uid] === 'client' ? 'client_ok' : 'owner_ok') . ' = ? WHERE id = ?', [$uid, $p['id']]);
    $p = row('SELECT * FROM portfolio WHERE id = ?', [$p['id']]);
    $author = user_by_id((int) $p['uid']);
    $status = portfolio_set_status($p, true, $author);
    notify((int) $p['uid'], $uid, 'portfolio', ($status === 'published' ? 'authorized your case study “' . $p['title'] . '”: it is now on your profile' : 'authorized your case study “' . $p['title'] . '” (one more authorization needed)'), 'u/' . $author['handle'], true);
    return ['status' => $status];
}
/** L'auteur demande à un client de confirmer les résultats d'une étude de cas publiée. */
function api_portfolio_confirm_ask(array $b, array $u): array
{
    $p = own_portfolio(int_in($b, 'id'), $u);
    $t = row('SELECT * FROM users WHERE handle = ?', [strtolower(ltrim(line_clean($b['handle'] ?? '', 40), '@'))]);
    if (!$t || (int) $t['id'] === (int) $u['id']) fail('Enter the username of the client who can confirm these results.');
    if ($p['status'] !== 'published') fail('Publish the case study first.');
    q('UPDATE portfolio SET confirm_ask = ? WHERE id = ?', [$t['id'], $p['id']]);
    notify((int) $t['id'], (int) $u['id'], 'portfolio', 'asks you to confirm the results of the case study “' . $p['title'] . '”', 'requests', true);
    return [];
}
function api_portfolio_confirm(array $b, array $u): array
{
    $p = row('SELECT * FROM portfolio WHERE id = ? AND confirm_ask = ? AND confirm_uid = 0', [int_in($b, 'id'), $u['id']]);
    if (!$p) not_found('This request');
    if (empty($b['confirm'])) {
        q('UPDATE portfolio SET confirm_ask = 0 WHERE id = ?', [$p['id']]);
    } else {
        q('UPDATE portfolio SET confirm_uid = ?, confirm_at = ?, confirm_ask = 0 WHERE id = ?', [$u['id'], time(), $p['id']]);
        notify((int) $p['uid'], (int) $u['id'], 'portfolio', 'confirmed the results of your case study “' . $p['title'] . '”', 'u/' . val('SELECT handle FROM users WHERE id = ?', [$p['uid']]));
    }
    return [];
}

/* -------------------------------------------------------- recommandations */

function api_recommend(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $to = int_in($b, 'uid');
    $t = user_by_id($to);
    if (!$t || $to === $uid) fail('This person no longer exists.');
    rate('recommend', 20, 3600, 'u' . $uid);
    $body = str_clean($b['body'] ?? '', 1500);
    if (mb_strlen($body) < 20) fail('Write a few sentences about your work together.');
    $relation = isset(RELATIONS[$b['relation'] ?? '']) ? (string) $b['relation'] : 'colleague';
    q('DELETE FROM recommendations WHERE from_uid = ? AND to_uid = ?', [$uid, $to]);
    insert('recommendations', ['from_uid' => $uid, 'to_uid' => $to, 'relation' => $relation, 'body' => $body, 'together' => worked_together($uid, $to) ? 1 : 0, 'created' => time()]);
    notify($to, $uid, 'network', 'wrote you a recommendation. Review it to show it on your profile', 'u/' . $t['handle'], true);
    return [];
}
/** Le destinataire affiche ou masque une recommandation ; l'auteur ou le destinataire peut la supprimer. */
function api_recommend_set(array $b, array $u): array
{
    $r = row('SELECT * FROM recommendations WHERE id = ?', [int_in($b, 'id')]);
    if (!$r) return [];
    $uid = (int) $u['id'];
    if (($b['status'] ?? '') === 'delete') {
        if ((int) $r['to_uid'] !== $uid && (int) $r['from_uid'] !== $uid) forbidden();
        q('DELETE FROM recommendations WHERE id = ?', [$r['id']]);
        return [];
    }
    if ((int) $r['to_uid'] !== $uid) forbidden();
    q('UPDATE recommendations SET status = ? WHERE id = ?', [($b['status'] ?? '') === 'visible' ? 'visible' : 'hidden', $r['id']]);
    return [];
}

/* -------------------------------------------------------------- recherche */

function api_search(array $b, ?array $u): array
{
    $term = line_clean($b['q'] ?? '', 60);
    if (mb_strlen($term) < 2) return ['people' => [], 'companies' => [], 'posts' => [], 'opps' => []];
    rate('search', 120, 600);
    $l = like($term);
    $people = rows("SELECT * FROM users WHERE suspended = 0 AND search LIKE ? ESCAPE '\\' ORDER BY pro DESC, last_seen DESC LIMIT 12", [$l]);
    $companies = rows("SELECT * FROM companies WHERE search LIKE ? ESCAPE '\\' ORDER BY name LIMIT 8", [$l]);
    $posts = rows("SELECT * FROM posts WHERE body LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT 10", ['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%']);
    $opps = rows("SELECT * FROM opps WHERE status = 'open' AND search LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT 8", [$l]);
    return [
        'people' => array_map('person_view', $people), 'companies' => array_map('company_card', $companies),
        'posts' => post_views($posts, $u), 'opps' => opp_views($opps, $u),
    ];
}
