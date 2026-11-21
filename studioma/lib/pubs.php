<?php
/**
 * Publier une carte dans la communauté de Studioma.
 *
 *   achievement   carte terminée (colonne « done ») : le travail montré sur le profil et dans la communauté,
 *                 avec mentions J'aime, commentaires et partages (une publication du fil lui est liée : posts.pub_id)
 *   opportunity   carte en cours : on cherche un professionnel, des propositions, une collaboration, de l'aide ;
 *                 les professionnels connectés envoient une proposition (approche, prix, délai, travaux)
 *
 * Règles (docs/DECISIONS.md S25–S27) :
 *   - le contenu publié est une copie choisie par l'auteur : textes saisis dans le formulaire, pièces jointes
 *     cochées copiées en fichiers publics. La carte n'est ni déplacée, ni dupliquée, ni modifiée, et rien de
 *     son espace de travail (commentaires, historique, équipe, autres fichiers) n'est exposé ;
 *   - publier demande le droit « edit » du Studio ; gérer une publication : son auteur, ou un responsable
 *     (« manage ») du Studio de la carte ;
 *   - une réalisation et une opportunité au plus par carte (republier réutilise la même ligne) ;
 *   - une opportunité se ferme d'elle-même quand sa carte est terminée, annulée, archivée ou supprimée, ou à sa
 *     date limite de candidature ; fermée, elle ne reçoit plus de proposition ;
 *   - accepter une proposition ne donne aucun accès à la carte : l'auteur peut ensuite inviter la personne sur
 *     cette seule carte (lien privé, lib/share.php) ou dans le Studio (invitation habituelle, lib/collab.php).
 */
declare(strict_types=1);

const OPP_KINDS = [
    'pro' => 'Find a professional', 'proposals' => 'Request proposals', 'collab' => 'Request collaboration', 'help' => 'Request feedback or help',
];
const PUB_CATEGORIES = [
    'Social media management', 'Video & editing', 'Design & visuals', 'Copywriting', 'Photography', 'Content strategy',
    'Ads & growth', 'Influencer marketing', 'Community engagement', 'Branding', 'Other',
];
const PUB_CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SAR', 'MAD', 'DZD', 'TND'];
const PROPOSAL_STATUSES = ['new' => 'New', 'shortlisted' => 'Shortlisted', 'accepted' => 'Accepted', 'declined' => 'Not selected', 'withdrawn' => 'Withdrawn'];
const PUB_MAX_FILES = 8;
const PUB_CLOSED = [
    'manual' => 'Closed by its author.', 'card_done' => 'The work is done.', 'card_gone' => 'The project behind it is no longer available.',
    'deadline' => 'The application deadline has passed.', 'admin' => 'Closed by Studioma.',
];

/* ------------------------------------------------------ carte d'origine */

/** Carte d'origine et son étape : open | done | cancelled, ou gone (supprimée, archivée, Studio ou projet archivé). */
function pub_source(array $p): array
{
    $c = (int) $p['card_id'] ? row('SELECT c.*, co.kind AS col_kind FROM cards c JOIN cols co ON co.id = c.col_id WHERE c.id = ?', [$p['card_id']]) : null;
    $s = $c ? row('SELECT s.archived, pr.archived AS p_archived FROM studios s JOIN projects pr ON pr.id = s.project_id WHERE s.id = ?', [$c['studio_id']]) : null;
    if (!$c || (int) $c['archived'] || !$s || (int) $s['archived'] || (int) $s['p_archived']) return ['card' => null, 'stage' => 'gone'];
    return ['card' => $c, 'stage' => $c['col_kind'] === 'done' ? 'done' : ($c['col_kind'] === 'cancelled' ? 'cancelled' : 'open')];
}
/** Étape d'une carte (pour publier) : open | done | cancelled. */
function card_stage(array $card): string
{
    $kind = (string) val('SELECT kind FROM cols WHERE id = ?', [$card['col_id']]);
    return $kind === 'done' ? 'done' : ($kind === 'cancelled' ? 'cancelled' : 'open');
}
/**
 * Ferme une opportunité ouverte dont la carte n'est plus en cours, ou dont la date limite est passée.
 * Appelée à chaque lecture et après chaque changement de la carte : l'état publié suit toujours la carte.
 */
function pub_sync(array $p): array
{
    if ($p['kind'] !== 'opportunity' || $p['status'] !== 'open') return $p;
    $src = pub_source($p);
    $reason = $src['stage'] === 'gone' ? 'card_gone' : ($src['stage'] !== 'open' ? 'card_done' : '');
    if ($reason === '' && $p['apply_until'] !== '' && $p['apply_until'] < date('Y-m-d')) $reason = 'deadline';
    if ($reason === '') return $p;
    q("UPDATE pubs SET status = 'closed', closed_reason = ?, updated = ? WHERE id = ? AND status = 'open'", [$reason, time(), $p['id']]);
    return row('SELECT * FROM pubs WHERE id = ?', [$p['id']]) ?? $p;
}
/** Une carte a changé d'étape, été archivée ou supprimée : ses opportunités ouvertes suivent (lib/studio.php). */
function pubs_card_changed(int $cardId): void
{
    foreach (rows("SELECT * FROM pubs WHERE card_id = ? AND kind = 'opportunity' AND status = 'open'", [$cardId]) as $p) pub_sync($p);
}
/** Publications d'une carte, pour son panneau (membres du Studio). */
function card_pubs_view(int $cardId): array
{
    $out = [];
    foreach (rows('SELECT * FROM pubs WHERE card_id = ? ORDER BY id', [$cardId]) as $p) {
        $p = pub_sync($p);
        $out[] = ['id' => (int) $p['id'], 'kind' => (string) $p['kind'], 'status' => (string) $p['status'], 'title' => (string) $p['title'],
            'proposals' => $p['kind'] === 'opportunity' ? (int) val("SELECT COUNT(*) FROM proposals WHERE pub_id = ? AND status <> 'withdrawn'", [$p['id']]) : 0,
            'new' => $p['kind'] === 'opportunity' ? (int) val("SELECT COUNT(*) FROM proposals WHERE pub_id = ? AND status = 'new'", [$p['id']]) : 0];
    }
    return $out;
}
/** Partage d'une carte seule, pour son panneau : liens actifs et invités. */
function card_share_summary(int $sid, int $cardId): array
{
    $links = rows('SELECT * FROM shares WHERE studio_id = ? AND card_id = ?', [$sid, $cardId]);
    $on = array_values(array_filter($links, function (array $sh): bool { return share_state($sh) === ''; }));
    $ids = array_map('intval', array_column($links, 'id'));
    return [
        'links' => count($on),
        'guests' => $ids ? (int) val("SELECT COUNT(*) FROM share_guests WHERE share_id IN (" . marks($ids) . ") AND status = 'active'", $ids) : 0,
        'requests' => $ids ? (int) val("SELECT COUNT(*) FROM share_guests WHERE share_id IN (" . marks($ids) . ") AND req_at > 0 AND status <> 'revoked'", $ids) : 0,
    ];
}
/** Pastilles du tableau : [card_id => {a: réalisation publiée, o: open | closed, s: partagée seule}]. */
function studio_pub_badges(int $sid): array
{
    $out = [];
    foreach (rows("SELECT card_id, kind, status FROM pubs WHERE studio_id = ? AND card_id > 0", [$sid]) as $r) {
        $id = (int) $r['card_id'];
        $out[$id] = $out[$id] ?? ['a' => false, 'o' => '', 's' => false];
        if ($r['kind'] === 'achievement' && $r['status'] === 'published') $out[$id]['a'] = true;
        if ($r['kind'] === 'opportunity') $out[$id]['o'] = (string) $r['status'];
    }
    foreach (rows('SELECT card_id, active, expires FROM shares WHERE studio_id = ? AND card_id > 0', [$sid]) as $r) {
        if (!(int) $r['active'] || ((int) $r['expires'] && (int) $r['expires'] <= time())) continue;
        $id = (int) $r['card_id'];
        $out[$id] = $out[$id] ?? ['a' => false, 'o' => '', 's' => false];
        $out[$id]['s'] = true;
    }
    return $out;
}

/* ------------------------------------------------------------- droits */

/** Peut gérer la publication : son auteur, ou un responsable du Studio de la carte. */
function pub_manager(array $p, int $uid): bool
{
    if ($uid <= 0) return false;
    if ((int) $p['uid'] === $uid) return true;
    $s = (int) $p['studio_id'] ? row('SELECT * FROM studios WHERE id = ?', [$p['studio_id']]) : null;
    $pr = $s ? row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]) : null;
    return $s && $pr && can(studio_role($uid, $s, $pr), 'manage');
}
/** A-t-il accès au Studio de la carte ? (Seuls ses membres voient le lien vers la carte d'origine.) */
function pub_member(array $p, int $uid): bool
{
    if ($uid <= 0 || !(int) $p['studio_id']) return false;
    $s = row('SELECT * FROM studios WHERE id = ?', [$p['studio_id']]);
    $pr = $s ? row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]) : null;
    return $s && $pr && studio_role($uid, $s, $pr) !== '';
}
/** Publication visible par ce compte ? Réalisation masquée ou auteur suspendu : ses gestionnaires seulement. */
function pub_visible(array $p, int $me): bool
{
    $public = $p['kind'] === 'opportunity' || $p['status'] === 'published';
    if ($public && !(int) val('SELECT suspended FROM users WHERE id = ?', [$p['uid']])) return true;
    return pub_manager($p, $me);
}
function load_pub(int $id, ?array $u): array
{
    $p = row('SELECT * FROM pubs WHERE id = ?', [$id]);
    if (!$p || !pub_visible($p, $u ? (int) $u['id'] : 0)) not_found('This publication');
    return pub_sync($p);
}
function managed_pub(int $id, array $u): array
{
    $p = row('SELECT * FROM pubs WHERE id = ?', [$id]);
    if (!$p || !pub_visible($p, (int) $u['id'])) not_found('This publication');
    if (!pub_manager($p, (int) $u['id'])) forbidden('Only its author or a Studio manager can manage this publication.');
    return pub_sync($p);
}

/* -------------------------------------------------------------- vues */

function pub_tokens(array $p): array
{
    return array_values(array_filter(array_map(function ($m): string { return is_array($m) ? (string) ($m['t'] ?? '') : ''; }, jarr($p['media']))));
}
/** Publication telle que montrée. $full : avec propositions (gestionnaires) ou la sienne (professionnel). */
function pub_view(array $p, ?array $u, bool $full = false): array
{
    $me = $u ? (int) $u['id'] : 0;
    $id = (int) $p['id'];
    $manage = $me && pub_manager($p, $me);
    $author = user_cards([(int) $p['uid']])[(int) $p['uid']] ?? null;
    $media = files_view(pub_tokens($p));
    $src = pub_source($p);
    $v = [
        'id' => $id, 'kind' => (string) $p['kind'], 'status' => (string) $p['status'], 'title' => (string) $p['title'], 'body' => (string) $p['body'],
        'category' => (string) $p['category'], 'skills' => jarr($p['skills']), 'media' => $media, 'cover' => $media[0] ?? null, 'author' => $author,
        'created' => (int) $p['created'], 'updated' => (int) $p['updated'], 'manage' => $manage, 'mine' => $me > 0 && (int) $p['uid'] === $me,
        // La carte d'origine : son existence pour tous, son adresse pour les seuls membres de son Studio.
        'source' => ['available' => $src['stage'] !== 'gone', 'stage' => $src['stage']]
            + ($me && ($manage || pub_member($p, $me)) && $src['card'] ? ['studio_id' => (int) $src['card']['studio_id'], 'card_id' => (int) $src['card']['id']] : []),
    ];
    if ($p['kind'] === 'achievement') {
        $post = (int) $p['post_id'];
        $v += [
            'outcome' => (string) $p['outcome'], 'team' => array_values(user_cards(jarr($p['team']))), 'post_id' => $post,
            'likes' => $post ? (int) val('SELECT COUNT(*) FROM post_likes WHERE post_id = ?', [$post]) : 0,
            'comments' => $post ? (int) val('SELECT COUNT(*) FROM post_comments WHERE post_id = ?', [$post]) : 0,
            'liked' => $post && $me ? (bool) val('SELECT 1 FROM post_likes WHERE post_id = ? AND uid = ?', [$post, $me]) : false,
            'saved' => $post && $me ? (bool) val('SELECT 1 FROM post_saves WHERE post_id = ? AND uid = ?', [$post, $me]) : false,
        ];
    } else {
        $v += [
            'opp_kind' => (string) $p['opp_kind'], 'opp_label' => OPP_KINDS[$p['opp_kind']] ?? '', 'deliverables' => (string) $p['deliverables'],
            'budget_min' => (int) $p['budget_min'], 'budget_max' => (int) $p['budget_max'], 'currency' => (string) $p['currency'],
            'deadline' => (string) $p['deadline'], 'apply_until' => (string) $p['apply_until'], 'mode' => (string) $p['mode'], 'location' => (string) $p['location'],
            'closed_reason' => $p['status'] === 'closed' ? (PUB_CLOSED[$p['closed_reason']] ?? PUB_CLOSED['manual']) : '',
            'proposals_count' => (int) val("SELECT COUNT(*) FROM proposals WHERE pub_id = ? AND status <> 'withdrawn'", [$id]),
            'my_proposal' => $me ? (string) (val('SELECT status FROM proposals WHERE pub_id = ? AND uid = ?', [$id, $me]) ?? '') : '',
        ];
        if ($full && $manage) {
            $list = rows("SELECT * FROM proposals WHERE pub_id = ? ORDER BY CASE status WHEN 'accepted' THEN 0 WHEN 'shortlisted' THEN 1 WHEN 'new' THEN 2 WHEN 'declined' THEN 3 ELSE 4 END, id DESC", [$id]);
            $people = [];
            foreach (people_view(array_column($list, 'uid')) as $pp) $people[$pp['id']] = $pp;
            $v['proposals'] = array_map(function (array $x) use ($people, $p): array { return proposal_view($x, $p, $people[(int) $x['uid']] ?? null); }, $list);
        }
        if ($full && $me && !$manage) {
            $mine = row('SELECT * FROM proposals WHERE pub_id = ? AND uid = ?', [$id, $me]);
            $v['proposal'] = $mine ? proposal_view($mine, $p, null) : null;
        }
    }
    return $v;
}
/** Travaux joints à une proposition : études de cas publiées et réalisations du professionnel. */
function proposal_work_view(array $work): array
{
    $out = [];
    foreach ($work as $w) {
        if (!is_array($w)) continue;
        if (($w['t'] ?? '') === 'case' && ($c = row("SELECT id, uid, title, media FROM portfolio WHERE id = ? AND status = 'published'", [(int) ($w['id'] ?? 0)]))) {
            $out[] = ['t' => 'case', 'id' => (int) $c['id'], 'title' => (string) $c['title'], 'cover' => files_view(array_slice(jarr($c['media']), 0, 1))[0] ?? null,
                'handle' => (string) val('SELECT handle FROM users WHERE id = ?', [$c['uid']])];
        } elseif (($w['t'] ?? '') === 'achievement' && ($a = row("SELECT * FROM pubs WHERE id = ? AND kind = 'achievement' AND status = 'published'", [(int) ($w['id'] ?? 0)]))) {
            $out[] = ['t' => 'achievement', 'id' => (int) $a['id'], 'title' => (string) $a['title'], 'cover' => files_view(array_slice(pub_tokens($a), 0, 1))[0] ?? null];
        }
    }
    return $out;
}
function proposal_view(array $x, array $p, ?array $person): array
{
    return [
        'id' => (int) $x['id'], 'pub_id' => (int) $x['pub_id'], 'message' => (string) $x['message'], 'price' => (int) $x['price'], 'currency' => (string) $p['currency'],
        'timeline' => (string) $x['timeline'], 'work' => proposal_work_view(jarr($x['work'])), 'status' => (string) $x['status'],
        'created' => (int) $x['created'], 'updated' => (int) $x['updated'], 'decided' => (int) $x['decided'], 'user' => $person,
    ];
}

/* ---------------------------------------------------------- publier */

/** Pièces jointes de la carte choisies pour la publication : [ligne card_files + fichier], couverture d'abord. */
function pub_pick_files(int $cardId, $ids, int $cover): array
{
    $want = ids_in($ids);
    if ($cover) $want = array_values(array_unique(array_merge([$cover], $want)));
    if (count($want) > PUB_MAX_FILES) fail('Choose ' . PUB_MAX_FILES . ' files at most.');
    if (!$want) return [];
    $map = [];
    foreach (rows('SELECT cf.id AS cf_id, f.* FROM card_files cf JOIN files f ON f.id = cf.file_id WHERE cf.card_id = ? AND cf.id IN (' . marks($want) . ')', array_merge([$cardId], $want)) as $f) $map[(int) $f['cf_id']] = $f;
    if (count($map) !== count($want)) fail('Choose files attached to this card.');
    if ($cover && $map[$cover]['kind'] !== 'image') fail('The cover must be an image.');
    return array_map(function (int $id) use ($map): array { return $map[$id]; }, $want);
}
/**
 * Copies publiques des fichiers choisis, en réutilisant celles déjà faites pour la même pièce jointe ; les
 * copies qui ne servent plus sont supprimées. Les fichiers de la carte ne changent jamais.
 */
function pub_media_sync(array $old, array $files, int $owner, string $scope): array
{
    $keep = [];
    foreach ($old as $m) {
        if (is_array($m) && isset($m['src'], $m['t'])) $keep[(int) $m['src']] = (string) $m['t'];
    }
    $out = [];
    foreach ($files as $f) {
        $src = (int) $f['cf_id'];
        $t = $keep[$src] ?? '';
        if ($t === '' || !file_by_token($t)) $t = file_copy($f, $owner, $scope)['token'];
        $out[] = ['t' => $t, 'src' => $src];
    }
    $used = array_column($out, 't');
    files_release(array_values(array_diff(array_values($keep), $used)));
    return $out;
}
function pub_media_scope(array $media, string $scope): void
{
    $tokens = array_values(array_filter(array_map(function ($m): string { return is_array($m) ? (string) ($m['t'] ?? '') : ''; }, $media)));
    if ($tokens) q('UPDATE files SET scope = ? WHERE token IN (' . marks($tokens) . ')', array_merge([$scope], $tokens));
}
function money_in($v): int
{
    $n = (int) round((float) (is_numeric($v) ? $v : 0));
    if ($n < 0 || $n > 10000000) fail('Enter an amount between 0 and 10,000,000.');
    return $n;
}
/** Champs d'une publication validés depuis le formulaire. */
function pub_fields(array $b, string $kind): array
{
    $title = line_clean($b['title'] ?? '', 120);
    if ($title === '') fail('Give this publication a title.');
    $body = str_clean($b['body'] ?? '', 4000);
    $cat = in_array($b['category'] ?? '', PUB_CATEGORIES, true) ? (string) $b['category'] : '';
    $set = ['title' => $title, 'body' => $body, 'category' => $cat, 'skills' => jenc(list_clean($b['skills'] ?? [], 12))];
    if ($kind === 'achievement') {
        if (mb_strlen($body) < 10) fail('Describe the work in a sentence or two.');
        $set['outcome'] = str_clean($b['outcome'] ?? '', 1500);
        return $set;
    }
    if (mb_strlen($body) < 30) fail('Describe what you need in a few sentences.');
    $okind = (string) ($b['opp_kind'] ?? '');
    if (!isset(OPP_KINDS[$okind])) fail('Choose what you are looking for.');
    $min = money_in($b['budget_min'] ?? 0);
    $max = money_in($b['budget_max'] ?? 0);
    if ($max && $min > $max) fail('The maximum budget must be higher than the minimum.');
    $today = date('Y-m-d');
    $deadline = date_clean((string) ($b['deadline'] ?? ''));
    if (($b['deadline'] ?? '') !== '' && $deadline === '') fail('Enter the deadline as YYYY-MM-DD.');
    if ($deadline !== '' && $deadline < $today) fail('Choose a deadline in the future.');
    $until = date_clean((string) ($b['apply_until'] ?? ''));
    if (($b['apply_until'] ?? '') !== '' && $until === '') fail('Enter the application deadline as YYYY-MM-DD.');
    if ($until !== '' && $until < $today) fail('Choose an application deadline from today on.');
    if ($until !== '' && $deadline !== '' && $until > $deadline) fail('Proposals must close before the work is due.');
    return $set + [
        'opp_kind' => $okind, 'deliverables' => str_clean($b['deliverables'] ?? '', 2000), 'budget_min' => $min, 'budget_max' => $max,
        'currency' => in_array($b['currency'] ?? '', PUB_CURRENCIES, true) ? (string) $b['currency'] : 'USD',
        'deadline' => $deadline, 'apply_until' => $until, 'mode' => isset(STM_WORK_MODES[$b['mode'] ?? '']) ? (string) $b['mode'] : 'remote',
        'location' => line_clean($b['location'] ?? '', 80),
    ];
}
/** Équipe montrée avec une réalisation : responsable et collaborateurs de la carte qui ont un compte actif. */
function card_team(array $card): array
{
    $ids = array_merge([(int) $card['assignee']], array_map('intval', array_column(rows('SELECT uid FROM card_members WHERE card_id = ?', [$card['id']]), 'uid')));
    $ids = array_values(array_unique(array_filter($ids)));
    return $ids ? array_map('intval', array_column(rows('SELECT id FROM users WHERE suspended = 0 AND id IN (' . marks($ids) . ')', $ids), 'id')) : [];
}
function pub_search(array $set, int $uid): string
{
    return norm(implode(' ', [$set['title'], $set['body'], $set['category'], implode(' ', jarr($set['skills'])), $set['deliverables'] ?? '', $set['location'] ?? '',
        (string) val('SELECT name FROM users WHERE id = ?', [$uid])]));
}

/**
 * POST pub_save : publie une carte, ou modifie une publication.
 *   créer / republier  {card, kind: achievement | opportunity, …champs, files: [ids de pièces jointes], cover?, show_team?}
 *   modifier           {id, …champs, files?, cover?, show_team?}
 * Une réalisation exige une carte terminée ; une opportunité, une carte en cours.
 */
function api_pub_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $id = int_in($b, 'id');
    if ($id) {
        $p = managed_pub($id, $u);
        $card = (int) $p['card_id'] ? row('SELECT * FROM cards WHERE id = ?', [$p['card_id']]) : null;
        $kind = (string) $p['kind'];
    } else {
        $kind = (string) ($b['kind'] ?? '');
        if (!in_array($kind, ['achievement', 'opportunity'], true)) fail('Choose what to publish.');
        $card = load_card(int_in($b, 'card'), $u, 'view');
        if (!can($card['studio']['role'], 'edit')) forbidden('Only creators, managers and owners of this Studio can publish its cards.');
        if ((int) $card['archived']) fail('Restore this card before publishing it.');
        $stage = card_stage($card);
        if ($kind === 'achievement' && $stage !== 'done') fail('Only a finished card (in a “Done” column) can be published as an achievement.', 409, 'stage');
        if ($kind === 'opportunity' && $stage !== 'open') fail('Only an unfinished card can be published as an opportunity.', 409, 'stage');
        $p = row('SELECT * FROM pubs WHERE card_id = ? AND kind = ?', [$card['id'], $kind]);
        // Déjà publiée par quelqu'un d'autre : seul son auteur (ou un responsable du Studio) la reprend.
        if ($p && !pub_manager($p, $uid)) fail('This card was already published by someone else on the team.', 409, 'taken');
        rate('pub', 30, 3600, 'u' . $uid);
    }
    $set = pub_fields($b, $kind);
    $owner = $p ? (int) $p['uid'] : $uid;
    $live = $p ? ($kind === 'achievement' ? $p['status'] === 'published' : $p['status'] === 'open') : true;
    $republish = !$id;   // depuis la carte : la publication est (re)mise en ligne
    $files = null;
    if ($card && (array_key_exists('files', $b) || array_key_exists('cover', $b))) $files = pub_pick_files((int) $card['id'], $b['files'] ?? [], int_in($b, 'cover'));
    if ($kind === 'achievement' && array_key_exists('show_team', $b)) $set['team'] = jenc(!empty($b['show_team']) && $card ? card_team($card) : []);
    $set['search'] = pub_search($set, $owner);
    $now = time();
    $pid = tx(function () use ($p, $card, $kind, $set, $owner, $files, $republish, $live, $now): int {
        $status = $kind === 'achievement' ? 'published' : 'open';
        if ($p) {
            $set['updated'] = $now;
            if ($republish) { $set['status'] = $status; $set['closed_reason'] = ''; }
            if ($files !== null) $set['media'] = jenc(pub_media_sync(jarr($p['media']), $files, $owner, ($republish || $live) ? 'public' : 'private'));
            q('UPDATE pubs SET ' . implode(', ', array_map(function (string $k): string { return "$k = ?"; }, array_keys($set))) . ' WHERE id = ?', array_merge(array_values($set), [$p['id']]));
            $id = (int) $p['id'];
        } else {
            $set += ['team' => '[]'];
            $id = insert('pubs', $set + [
                'kind' => $kind, 'uid' => $owner, 'card_id' => (int) $card['id'], 'studio_id' => (int) $card['studio_id'], 'project_id' => (int) $card['studio']['project']['id'],
                'status' => $status, 'media' => jenc($files ? pub_media_sync([], $files, $owner, 'public') : []), 'created' => $now, 'updated' => $now,
            ]);
        }
        $row = row('SELECT * FROM pubs WHERE id = ?', [$id]);
        if ($republish) pub_media_scope(jarr($row['media']), 'public');
        // Réalisation en ligne : sa publication du fil (mentions J'aime, commentaires, partages).
        if ($kind === 'achievement' && $row['status'] === 'published' && (!(int) $row['post_id'] || !val('SELECT 1 FROM posts WHERE id = ?', [$row['post_id']]))) {
            $post = insert('posts', ['uid' => $owner, 'company_id' => 0, 'body' => '', 'media' => '[]', 'share_id' => 0, 'pub_id' => $id, 'created' => $now]);
            q('UPDATE pubs SET post_id = ? WHERE id = ?', [$post, $id]);
        }
        return $id;
    });
    $p = row('SELECT * FROM pubs WHERE id = ?', [$pid]);
    if ($card && $republish) log_event((int) $card['studio_id'], (int) $card['id'], $uid, 'publish', $kind === 'achievement' ? 'published this card as an achievement' : 'published this card as an opportunity');
    return ['pub' => pub_view($p, $u, true)];
}
/**
 * POST pub_status {id, status} : réalisation published | hidden (retirée de la communauté et du profil) ;
 * opportunité open | closed (plus aucune proposition). Rouvrir exige une carte toujours en cours.
 */
function api_pub_status(array $b, array $u): array
{
    $p = managed_pub(int_in($b, 'id'), $u);
    $status = (string) ($b['status'] ?? '');
    $now = time();
    if ($p['kind'] === 'achievement') {
        if (!in_array($status, ['published', 'hidden'], true)) fail('Choose to publish or unpublish.');
        if ($status === 'published' && pub_source($p)['stage'] === 'gone' && !(int) $p['card_id']) fail('This achievement can\'t be published again.');
        q('UPDATE pubs SET status = ?, updated = ? WHERE id = ?', [$status, $now, $p['id']]);
        pub_media_scope(jarr($p['media']), $status === 'published' ? 'public' : 'private');
        if ($status === 'published' && (!(int) $p['post_id'] || !val('SELECT 1 FROM posts WHERE id = ?', [$p['post_id']]))) {
            $post = insert('posts', ['uid' => (int) $p['uid'], 'company_id' => 0, 'body' => '', 'media' => '[]', 'share_id' => 0, 'pub_id' => $p['id'], 'created' => $now]);
            q('UPDATE pubs SET post_id = ? WHERE id = ?', [$post, $p['id']]);
        }
    } else {
        if (!in_array($status, ['open', 'closed'], true)) fail('Choose to open or close.');
        if ($status === 'open') {
            $src = pub_source($p);
            if ($src['stage'] === 'gone') fail('The card behind this opportunity is no longer available.', 409, 'stage');
            if ($src['stage'] !== 'open') fail('The card is finished: move it back to an unfinished column to reopen the opportunity.', 409, 'stage');
            if ($p['apply_until'] !== '' && $p['apply_until'] < date('Y-m-d')) fail('Change the application deadline first: it has passed.');
        }
        q('UPDATE pubs SET status = ?, closed_reason = ?, updated = ? WHERE id = ?', [$status, $status === 'closed' ? 'manual' : '', $now, $p['id']]);
        if ($status === 'open') pub_media_scope(jarr($p['media']), 'public');
    }
    return ['pub' => pub_view(row('SELECT * FROM pubs WHERE id = ?', [$p['id']]), $u, true)];
}
/** POST pub_delete {id} : supprime la publication, ses propositions, sa publication du fil et ses copies de fichiers. */
function api_pub_delete(array $b, array $u): array
{
    $p = managed_pub(int_in($b, 'id'), $u);
    tx(function () use ($p): void {
        if ((int) $p['post_id']) q('DELETE FROM posts WHERE id = ? AND pub_id = ?', [$p['post_id'], $p['id']]);
        q('DELETE FROM pubs WHERE id = ?', [$p['id']]);
    });
    files_release(pub_tokens($p));
    return [];
}
/** GET pub_get {id} : une publication (publique), avec les propositions pour ses gestionnaires. */
function api_pub_get(array $b, ?array $u): array
{
    return ['pub' => pub_view(load_pub(int_in($b, 'id'), $u), $u, true)];
}

/* ------------------------------------------------------- propositions */

/** POST proposal_save {pub, message, price?, timeline?, work?: [{t: case | achievement, id}]} : envoie ou modifie sa proposition. */
function api_proposal_save(array $b, array $u): array
{
    $uid = (int) $u['id'];
    $p = load_pub(int_in($b, 'pub'), $u);
    if ($p['kind'] !== 'opportunity') fail('This publication doesn\'t take proposals.');
    if ($p['status'] !== 'open') fail('This opportunity is closed: it no longer takes proposals.', 410, 'closed');
    if (pub_manager($p, $uid)) fail('You manage this opportunity: you can\'t send it a proposal.', 403, 'own');
    $message = str_clean($b['message'] ?? '', 3000);
    if (mb_strlen($message) < 30) fail('Describe your approach in a few sentences.');
    $price = money_in($b['price'] ?? 0);
    $timeline = line_clean($b['timeline'] ?? '', 80);
    $work = [];
    foreach (is_array($b['work'] ?? null) ? $b['work'] : [] as $w) {
        $t = is_array($w) ? (string) ($w['t'] ?? '') : '';
        $wid = is_array($w) ? (int) ($w['id'] ?? 0) : 0;
        $ok = $t === 'case' ? val("SELECT 1 FROM portfolio WHERE id = ? AND uid = ? AND status = 'published'", [$wid, $uid])
            : ($t === 'achievement' ? val("SELECT 1 FROM pubs WHERE id = ? AND uid = ? AND kind = 'achievement' AND status = 'published'", [$wid, $uid]) : null);
        if (!$ok) fail('Attach work from your own published portfolio.');
        $work[] = ['t' => $t, 'id' => $wid];
        if (count($work) >= 6) break;
    }
    $cur = row('SELECT * FROM proposals WHERE pub_id = ? AND uid = ?', [$p['id'], $uid]);
    if ($cur && in_array($cur['status'], ['accepted', 'declined'], true)) fail('This proposal was already answered.', 409, 'answered');
    if ($cur && !array_key_exists('work', $b)) $work = jarr($cur['work']);   // modification sans la liste : travaux gardés
    rate('proposal', 30, 3600, 'u' . $uid);
    $now = time();
    $fields = ['message' => $message, 'price' => $price, 'timeline' => $timeline, 'work' => jenc($work), 'updated' => $now];
    if ($cur) {
        q('UPDATE proposals SET message = ?, price = ?, timeline = ?, work = ?, updated = ?, status = ? WHERE id = ?',
            [$message, $price, $timeline, jenc($work), $now, $cur['status'] === 'withdrawn' ? 'new' : $cur['status'], $cur['id']]);
        $xid = (int) $cur['id'];
    } else {
        $xid = insert('proposals', $fields + ['pub_id' => $p['id'], 'uid' => $uid, 'status' => 'new', 'created' => $now]);
    }
    if (!$cur || $cur['status'] === 'withdrawn') notify((int) $p['uid'], $uid, 'opp', 'sent a proposal for “' . $p['title'] . '”', 'pub/' . $p['id'], true);
    return ['proposal' => proposal_view(row('SELECT * FROM proposals WHERE id = ?', [$xid]), $p, null)];
}
/** POST proposal_withdraw {id} : le professionnel retire sa proposition. */
function api_proposal_withdraw(array $b, array $u): array
{
    $x = row('SELECT * FROM proposals WHERE id = ? AND uid = ?', [int_in($b, 'id'), $u['id']]);
    if (!$x) not_found('This proposal');
    if ($x['status'] === 'withdrawn') return [];
    q("UPDATE proposals SET status = 'withdrawn', updated = ? WHERE id = ?", [time(), $x['id']]);
    $p = row('SELECT * FROM pubs WHERE id = ?', [$x['pub_id']]);
    if ($p) notify((int) $p['uid'], (int) $u['id'], 'opp', 'withdrew their proposal for “' . $p['title'] . '”', 'pub/' . $p['id']);
    return [];
}
/** POST proposal_decide {id, status: new | shortlisted | accepted | declined} : réponse de l'auteur (ou d'un responsable). */
function api_proposal_decide(array $b, array $u): array
{
    $x = row('SELECT * FROM proposals WHERE id = ?', [int_in($b, 'id')]);
    if (!$x) not_found('This proposal');
    $p = managed_pub((int) $x['pub_id'], $u);
    $status = (string) ($b['status'] ?? '');
    if (!in_array($status, ['new', 'shortlisted', 'accepted', 'declined'], true)) fail('Choose a status.');
    if ($x['status'] === 'withdrawn') fail('This proposal was withdrawn.', 409, 'withdrawn');
    if ($status === $x['status']) return ['pub' => pub_view($p, $u, true)];
    q('UPDATE proposals SET status = ?, decided = ?, updated = ? WHERE id = ?', [$status, in_array($status, ['accepted', 'declined'], true) ? time() : 0, time(), $x['id']]);
    $text = ['shortlisted' => 'shortlisted your proposal for', 'accepted' => 'accepted your proposal for', 'declined' => 'did not select your proposal for'][$status] ?? '';
    if ($text !== '') notify((int) $x['uid'], (int) $u['id'], 'opp', $text . ' “' . $p['title'] . '”', 'pub/' . $p['id'], true);
    return ['pub' => pub_view(row('SELECT * FROM pubs WHERE id = ?', [$p['id']]), $u, true)];
}
/**
 * POST pub_invite {proposal, how: card | studio, role} : invite le professionnel d'une proposition acceptée à
 * travailler sur la carte d'origine — sur cette seule carte (lien privé de la carte, rôle d'invité), ou dans le
 * Studio (invitation de membre habituelle). Les règles de partage et de formule s'appliquent telles quelles ;
 * rien n'est donné sans cette action explicite.
 */
function api_pub_invite(array $b, array $u): array
{
    $x = row('SELECT * FROM proposals WHERE id = ?', [int_in($b, 'proposal')]);
    if (!$x) not_found('This proposal');
    $p = managed_pub((int) $x['pub_id'], $u);
    if ($x['status'] !== 'accepted') fail('Accept the proposal before inviting this person.', 409, 'not_accepted');
    $src = pub_source($p);
    if (!$src['card']) fail('The card behind this opportunity is no longer available.', 409, 'stage');
    $pro = user_by_id((int) $x['uid']);
    if (!$pro) fail('This person no longer exists.');
    $card = $src['card'];
    $how = (string) ($b['how'] ?? 'card');
    if ($how === 'studio') {
        $role = in_array($b['role'] ?? '', ['viewer', 'client', 'collaborator', 'creator'], true) ? (string) $b['role'] : 'collaborator';
        api_invite_create(['studio_id' => (int) $card['studio_id'], 'uid' => (int) $pro['id'], 'role' => $role,
            'message' => 'Your proposal for “' . $p['title'] . '” was accepted.'], $u);
        return ['invited' => 'studio'];
    }
    $role = in_array($b['role'] ?? '', ['viewer', 'commenter', 'editor'], true) ? (string) $b['role'] : 'editor';
    api_share_guest(['studio' => (int) $card['studio_id'], 'card' => (int) $card['id'], 'op' => 'add', 'kind' => 'private', 'emails' => [(string) $pro['email']],
        'role' => $role, 'send' => true, 'message' => 'Your proposal for “' . $p['title'] . '” was accepted.'], $u);
    notify((int) $pro['id'], (int) $u['id'], 'opp', 'gave you access to the card of “' . $p['title'] . '” — check your email to open it', 'pub/' . $p['id']);
    return ['invited' => 'card'];
}

/* ----------------------------------------------------------- communauté */

/**
 * GET community : tab = achievements | opportunities | mine (mes publications) | proposals (mes propositions).
 * Filtres : q, category, skill, kind (type d'opportunité), mode, budget (1 = avec budget), status (open | closed |
 * all), before (pagination par id). Les opportunités de cartes sont présentées avec les offres des entreprises
 * (opps), qui gardent leur page et leurs candidatures.
 */
function api_community(array $b, ?array $u): array
{
    $tab = (string) ($b['tab'] ?? 'achievements');
    $me = $u ? (int) $u['id'] : 0;
    $limit = 24;
    $before = int_in($b, 'before') ?: PHP_INT_MAX;
    $term = line_clean($b['q'] ?? '', 60);
    $skill = line_clean($b['skill'] ?? '', 40);
    $cat = in_array($b['category'] ?? '', PUB_CATEGORIES, true) ? (string) $b['category'] : '';
    if (in_array($tab, ['mine', 'proposals'], true) && !$me) fail('Please sign in to continue.', 401, 'auth');
    if ($tab === 'proposals') {
        $out = [];
        foreach (rows('SELECT x.*, p.id AS p_id FROM proposals x JOIN pubs p ON p.id = x.pub_id WHERE x.uid = ? ORDER BY x.updated DESC LIMIT 100', [$me]) as $x) {
            $p = row('SELECT * FROM pubs WHERE id = ?', [$x['p_id']]);
            if (!$p) continue;
            $p = pub_sync($p);
            $out[] = ['proposal' => proposal_view($x, $p, null), 'pub' => pub_view($p, $u)];
        }
        return ['items' => $out, 'more' => false];
    }
    $where = ['p.id < ?'];
    $args = [$before];
    if ($tab === 'mine') {
        $where[] = 'p.uid = ?';
        $args[] = $me;
    } else {
        $kind = $tab === 'opportunities' ? 'opportunity' : 'achievement';
        $where[] = 'p.kind = ?';
        $args[] = $kind;
        $where[] = 'p.uid NOT IN (SELECT id FROM users WHERE suspended > 0)';
        if ($kind === 'achievement') {
            $where[] = "p.status = 'published'";
        } else {
            $st = (string) ($b['status'] ?? 'open');
            if (in_array($st, ['open', 'closed'], true)) { $where[] = 'p.status = ?'; $args[] = $st; }
            if (isset(OPP_KINDS[$b['kind'] ?? ''])) { $where[] = 'p.opp_kind = ?'; $args[] = $b['kind']; }
            if (isset(STM_WORK_MODES[$b['mode'] ?? ''])) { $where[] = 'p.mode = ?'; $args[] = $b['mode']; }
            if (!empty($b['budget'])) $where[] = '(p.budget_min > 0 OR p.budget_max > 0)';
        }
    }
    if ($term !== '') { $where[] = "p.search LIKE ? ESCAPE '\\'"; $args[] = like($term); }
    if ($skill !== '') { $where[] = "p.search LIKE ? ESCAPE '\\'"; $args[] = like($skill); }
    if ($cat !== '') { $where[] = 'p.category = ?'; $args[] = $cat; }
    // Les opportunités ouvertes dont la carte a changé se ferment avant d'être listées.
    if ($tab === 'opportunities') {
        foreach (rows("SELECT * FROM pubs WHERE kind = 'opportunity' AND status = 'open'") as $o) pub_sync($o);
    }
    $list = rows('SELECT p.* FROM pubs p WHERE ' . implode(' AND ', $where) . " ORDER BY p.id DESC LIMIT $limit", $args);
    $items = array_map(function (array $p) use ($u): array { return ['type' => $p['kind'], 'pub' => pub_view($p, $u)]; }, $list);
    $jobs = [];
    // Offres des entreprises : sur la première page, quand aucun filtre propre aux cartes n'est choisi.
    if ($tab === 'opportunities' && $before === PHP_INT_MAX && ($b['status'] ?? 'open') !== 'closed' && !isset(OPP_KINDS[$b['kind'] ?? '']) && empty($b['budget']) && $cat === '') {
        $jw = ["o.status = 'open'"];
        $ja = [];
        if ($term !== '') { $jw[] = "o.search LIKE ? ESCAPE '\\'"; $ja[] = like($term); }
        if ($skill !== '') { $jw[] = "o.search LIKE ? ESCAPE '\\'"; $ja[] = like($skill); }
        if (isset(STM_WORK_MODES[$b['mode'] ?? ''])) { $jw[] = 'o.mode = ?'; $ja[] = $b['mode']; }
        $jobs = opp_views(rows('SELECT o.* FROM opps o WHERE ' . implode(' AND ', $jw) . ' ORDER BY o.id DESC LIMIT 12', $ja), $u);
    }
    return ['items' => $items, 'jobs' => $jobs, 'more' => count($list) === $limit, 'next' => $list ? (int) end($list)['id'] : 0];
}
/** Réalisations publiées d'un profil (section du profil). */
function profile_achievements(int $uid, ?array $u): array
{
    return array_map(function (array $p) use ($u): array { return pub_view($p, $u); },
        rows("SELECT * FROM pubs WHERE uid = ? AND kind = 'achievement' AND status = 'published' ORDER BY id DESC LIMIT 12", [$uid]));
}
/** Résumé d'une réalisation dans le fil (publication liée), ou null si elle n'est pas en ligne. */
function feed_pubs(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $out = [];
    foreach (rows("SELECT * FROM pubs WHERE status = 'published' AND id IN (" . marks($ids) . ')', $ids) as $p) {
        $media = files_view(pub_tokens($p));
        $out[(int) $p['id']] = ['id' => (int) $p['id'], 'kind' => (string) $p['kind'], 'title' => (string) $p['title'], 'body' => mb_substr((string) $p['body'], 0, 280),
            'category' => (string) $p['category'], 'skills' => array_slice(jarr($p['skills']), 0, 4), 'cover' => $media[0] ?? null, 'files' => count($media)];
    }
    return $out;
}
