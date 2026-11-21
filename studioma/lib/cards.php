<?php
/**
 * Détail d'une carte : check-lists, commentaires et mentions, pièces jointes, demandes de
 * validation, historique. Envoi des fichiers (cartes, profils, publications).
 */
declare(strict_types=1);

/** Carte complète, telle qu'affichée dans son panneau. */
function card_full(int $id, array $u, array $studio): array
{
    $uid = (int) $u['id'];
    $c = row('SELECT c.*, ' . CARD_COUNTS . ' FROM cards c WHERE c.id = ?', [$id]);
    $col = row('SELECT * FROM cols WHERE id = ?', [$c['col_id']]);
    $level = $col ? row('SELECT * FROM levels WHERE id = ?', [$col['level_id']]) : null;
    $target = $level ? level_target($level) : null;
    $final = $level && (int) $level['handoff_col'] > 0 ? row('SELECT id, name FROM cols WHERE id = ? AND archived = 0', [$level['handoff_col']]) : null;

    $files = rows('SELECT cf.id AS cf_id, cf.uid AS cf_uid, cf.created AS cf_created, f.* FROM card_files cf JOIN files f ON f.id = cf.file_id WHERE cf.card_id = ? ORDER BY cf.id', [$id]);
    $comments = rows('SELECT * FROM card_comments WHERE card_id = ? ORDER BY id', [$id]);
    $approvals = rows('SELECT * FROM card_approvals WHERE card_id = ? ORDER BY id DESC LIMIT 20', [$id]);
    $events = rows('SELECT * FROM events WHERE card_id = ? ORDER BY id DESC LIMIT 80', [$id]);
    $g0 = !empty($u['guest']) ? $u['guest'] : null;
    if ($g0 && !empty($g0['card'])) {
        // Invité du lien de cette seule carte : les pièces jointes et les commentaires que le responsable a choisi
        // de montrer, jamais l'historique (il nomme l'équipe et le reste du travail).
        $show = $g0['opts']['files'];
        $files = array_values(array_filter($files, function (array $f) use ($show): bool { return in_array((int) $f['cf_id'], $show, true); }));
        if (!$g0['opts']['comments']) $comments = [];
        $events = [];
    }
    $users = user_cards(array_merge(
        array_column($files, 'cf_uid'), array_column($comments, 'uid'), array_column($events, 'uid'),
        array_column($approvals, 'by_uid'), array_column($approvals, 'approver_uid'), [(int) $c['created_by']]
    ));
    // Auteurs invités (lien de partage) : leur adresse vérifiée tient lieu de nom.
    $guests = guest_cards(array_merge(array_column($comments, 'guest_id'), array_column($events, 'guest_id'), array_column($approvals, 'approver_guest'), [(int) $c['created_guest']]));
    $who = function (int $uid, int $gid) use ($users, $guests): ?array { return $uid > 0 ? ($users[$uid] ?? null) : ($guests[$gid] ?? null); };
    $role = (string) $studio['role'];
    $g = !empty($u['guest']) ? $u['guest'] : null;
    $gid = $g ? (int) $g['gid'] : 0;
    $canEdit = $g ? ($col && guest_card_editable($g, $id, (int) $col['id'], (int) $col['level_id'])) : (can($role, 'edit') || (can($role, 'work') && card_involves($c, $uid)));
    $pending = null;
    foreach ($approvals as $a) {
        if ($a['status'] === 'pending') { $pending = $a; break; }
    }
    return card_lite($c, card_members_map([$id])) + [
        'body' => (string) $c['body'], 'metrics' => (object) jarr($c['metrics']), 'created' => (int) $c['created'],
        'creator' => $who((int) $c['created_by'], (int) $c['created_guest']),
        'col' => $col ? ['id' => (int) $col['id'], 'name' => (string) $col['name'], 'kind' => (string) $col['kind']] : null,
        'level' => $level ? ['id' => (int) $level['id'], 'name' => (string) $level['name'], 'icon' => (string) $level['icon'], 'color' => (string) $level['color'],
            'handoff' => (string) $level['handoff'], 'final' => $final ? ['id' => (int) $final['id'], 'name' => (string) $final['name']] : null,
            'target' => $target ? (string) $target['name'] : ''] : null,
        'checks' => array_map(function (array $k): array {
            return ['id' => (int) $k['id'], 'text' => (string) $k['text'], 'done' => (bool) $k['done']];
        }, rows('SELECT * FROM card_checks WHERE card_id = ? ORDER BY pos, id', [$id])),
        'files' => array_map(function (array $f) use ($users, $uid, $role, $g): array {
            return file_view($f) + ['id' => (int) $f['cf_id'], 'created' => (int) $f['cf_created'], 'user' => $users[(int) $f['cf_uid']] ?? null,
                'mine' => !$g && ((int) $f['cf_uid'] === $uid || can($role, 'manage'))];
        }, $files),
        'comments' => array_map(function (array $m) use ($who, $uid, $gid, $role, $g): array {
            return ['id' => (int) $m['id'], 'body' => (string) $m['body'], 'created' => (int) $m['created'], 'user' => $who((int) $m['uid'], (int) $m['guest_id']),
                'mine' => $g ? ($gid > 0 && (int) $m['guest_id'] === $gid) : ((int) $m['uid'] === $uid || can($role, 'manage'))];
        }, $comments),
        'approvals' => array_map(function (array $a) use ($users, $who): array {
            return ['id' => (int) $a['id'], 'status' => (string) $a['status'], 'note' => (string) $a['note'], 'reply' => (string) $a['reply'],
                'created' => (int) $a['created'], 'decided' => (int) $a['decided'], 'by' => $users[(int) $a['by_uid']] ?? null, 'approver' => $who((int) $a['approver_uid'], (int) $a['approver_guest'])];
        }, $approvals),
        'events' => array_map(function (array $e) use ($who): array {
            return ['id' => (int) $e['id'], 'kind' => (string) $e['kind'], 'text' => (string) $e['text'], 'created' => (int) $e['created'], 'user' => $who((int) $e['uid'], (int) $e['guest_id'])];
        }, $events),
        'can' => [
            'edit' => $canEdit, 'reassign' => !$g && can($role, 'edit'),
            'comment' => can($role, 'comment') && (!$g || empty($g['card']) || !empty($g['opts']['comments'])),
            // Seule la personne (ou l'invité) désignée valide ; un propriétaire peut le faire à sa place.
            'decide' => $pending && ($g ? $gid > 0 && (int) $pending['approver_guest'] === $gid : (((int) $pending['approver_uid'] === $uid && $uid > 0) || can($role, 'own'))),
            'delete' => !$g && card_can_delete($c, $role, $uid),
            'handoff' => !$g && $canEdit && $level && $level['handoff'] !== 'off' && (int) $level['handoff_col'] === (int) $c['col_id'] && $target !== null,
            // Pièces jointes et demandes de validation : membres seulement.
            'upload' => !$g && $canEdit, 'request' => !$g && $canEdit,
            // Partager la carte seule (gestion du partage : manage) ; la publier dans la communauté (edit).
            'share' => !$g && can($role, 'manage'), 'publish' => !$g && can($role, 'edit'),
        ],
        // Étape de la carte pour « Share & publish » : open (en cours) | done (terminée) | cancelled.
        'stage' => $col && $col['kind'] === 'done' ? 'done' : ($col && $col['kind'] === 'cancelled' ? 'cancelled' : 'open'),
        'pubs' => $g ? [] : card_pubs_view($id),
        'shared' => $g ? null : card_share_summary((int) $c['studio_id'], $id),
    ] + ($g ? ['editable' => $canEdit, 'comments_shared' => empty($g['card']) || !empty($g['opts']['comments'])] : []);
}

/* ------------------------------------------------------------ check-lists */

function api_check_save(array $b, array $u): array
{
    $id = int_in($b, 'id');
    if ($id) {
        $k = row('SELECT * FROM card_checks WHERE id = ?', [$id]);
        if (!$k) not_found('This item');
        $card = load_card((int) $k['card_id'], $u, 'cardedit');
        if (isset($b['text'])) {
            $t = line_clean($b['text'], 300);
            if ($t === '') fail('Write something first.');
            q('UPDATE card_checks SET text = ? WHERE id = ?', [$t, $id]);
        }
        if (isset($b['done'])) q('UPDATE card_checks SET done = ? WHERE id = ?', [empty($b['done']) ? 0 : 1, $id]);
        guest_log_change((int) $card['id'], 'check', isset($b['done']) && !isset($b['text']) ? (empty($b['done']) ? 'unchecked “' : 'checked “') . $k['text'] . '”' : 'edited the checklist item “' . line_clean($b['text'] ?? $k['text'], 300) . '”');
    } else {
        $card = load_card(int_in($b, 'card_id'), $u, 'cardedit');
        $t = line_clean($b['text'] ?? '', 300);
        if ($t === '') fail('Write something first.');
        if ((int) val('SELECT COUNT(*) FROM card_checks WHERE card_id = ?', [$card['id']]) >= 100) fail('This checklist is full.');
        $id = insert('card_checks', ['card_id' => $card['id'], 'text' => $t, 'pos' => (int) val('SELECT COALESCE(MAX(pos), -1) + 1 FROM card_checks WHERE card_id = ?', [$card['id']])]);
        guest_log_change((int) $card['id'], 'check', 'added the checklist item “' . $t . '”');
    }
    studio_touch((int) $card['studio_id']);
    return ['id' => $id];
}
function api_check_delete(array $b, array $u): array
{
    $k = row('SELECT * FROM card_checks WHERE id = ?', [int_in($b, 'id')]);
    if (!$k) return [];
    $card = load_card((int) $k['card_id'], $u, 'cardedit');
    q('DELETE FROM card_checks WHERE id = ?', [$k['id']]);
    guest_log_change((int) $card['id'], 'check', 'removed the checklist item “' . $k['text'] . '”');
    studio_touch((int) $card['studio_id']);
    return [];
}

/* ----------------------------------------------------------- commentaires */

/** Comptes mentionnés (« @identifiant ») parmi les membres du Studio. */
function mentions_in(string $text, array $studio): array
{
    if (!preg_match_all('/(?<![\w@])@([a-z0-9][a-z0-9._-]{1,29})/i', $text, $m)) return [];
    $handles = array_unique(array_map(function (string $h): string { return rtrim(strtolower($h), '.'); }, $m[1]));
    $people = studio_people($studio, $studio['project']);
    $out = [];
    foreach (rows('SELECT id FROM users WHERE handle IN (' . marks($handles) . ')', array_values($handles)) as $r) {
        if (isset($people[(int) $r['id']])) $out[] = (int) $r['id'];
    }
    return $out;
}
function api_comment_add(array $b, array $u): array
{
    $card = load_card(int_in($b, 'card_id'), $u, 'comment');
    $body = str_clean($b['body'] ?? '', 4000);
    if ($body === '') fail('Write a comment first.');
    $uid = (int) $u['id'];
    rate('comment', 60, 600, actor_key($u));
    $id = insert('card_comments', ['card_id' => $card['id'], 'uid' => $uid, 'guest_id' => !empty($u['guest']) ? (int) $u['guest']['gid'] : 0, 'body' => $body, 'created' => time()]);
    guest_log_change((int) $card['id'], 'comment', 'commented: “' . mb_substr($body, 0, 300) . '”');
    $mentioned = mentions_in($body, $card['studio']);
    $short = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? '', 0, 90);
    foreach ($mentioned as $m) notify($m, $uid, 'mention', 'mentioned you on “' . $card['title'] . '”: ' . $short, card_link($card), true);
    foreach (array_diff(card_watchers($card, $uid), $mentioned) as $w) notify($w, $uid, 'comment', 'commented on “' . $card['title'] . '”: ' . $short, card_link($card));
    studio_touch((int) $card['studio_id']);
    return ['id' => $id];
}
function api_comment_delete(array $b, array $u): array
{
    $m = row('SELECT * FROM card_comments WHERE id = ?', [int_in($b, 'id')]);
    if (!$m) return [];
    $card = load_card((int) $m['card_id'], $u);
    // Un invité ne retire que ses propres commentaires ; un membre, les siens (ou tous s'il gère le Studio).
    $mine = !empty($u['guest']) ? ((int) $u['guest']['gid'] > 0 && (int) $m['guest_id'] === (int) $u['guest']['gid']) : ((int) $m['uid'] === (int) $u['id'] && (int) $m['uid'] > 0);
    if (!$mine && (!empty($u['guest']) || !can($card['studio']['role'], 'manage'))) forbidden('You can only delete your own comments.');
    q('DELETE FROM card_comments WHERE id = ?', [$m['id']]);
    studio_touch((int) $card['studio_id']);
    return [];
}

/* --------------------------------------------------------------- fichiers */

/**
 * Envoi d'un fichier (multipart : file + kind). kind : avatar | cover | logo (images) ;
 * post | portfolio (images et vidéos) ; card (tout type accepté, avec card_id — pièce jointe privée).
 */
function api_upload(array $b, array $u): array
{
    $uid = (int) $u['id'];
    rate('upload', 120, 3600, 'u' . $uid);
    $kind = (string) ($b['kind'] ?? '');
    $up = $_FILES['file'] ?? null;
    // Au-delà de post_max_size, PHP vide la requête : ni champ ni fichier n'arrivent.
    if (!is_array($up)) fail(!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 ? 'This file is too large (' . upload_limit_mb() . ' MB maximum).' : 'The upload failed. Please try again.');
    try {
        if ($kind === 'card') {
            $card = load_card(int_in($b, 'card_id'), $u, 'cardedit');
            if ((int) val('SELECT COUNT(*) FROM card_files WHERE card_id = ?', [$card['id']]) >= 40) fail('This card already has 40 attachments.');
            $f = file_ingest($up, $uid, 'studio', (int) $card['studio_id'], ['image', 'video', 'doc']);
            $cfId = insert('card_files', ['card_id' => $card['id'], 'file_id' => $f['id'], 'uid' => $uid, 'created' => time()]);
            log_event((int) $card['studio_id'], (int) $card['id'], $uid, 'attach', 'attached “' . $f['name'] . '”');
            studio_touch((int) $card['studio_id']);
            return ['file' => file_view($f) + ['id' => $cfId]];
        }
        if (in_array($kind, ['avatar', 'cover', 'logo'], true)) return ['file' => file_view(file_ingest($up, $uid, 'public', 0, ['image']))];
        if (in_array($kind, ['post', 'portfolio'], true)) return ['file' => file_view(file_ingest($up, $uid, 'public', 0, ['image', 'video']))];
    } catch (InvalidArgumentException $e) {
        fail($e->getMessage());
    }
    fail('Unknown upload type.');
    return [];
}
function api_attach_delete(array $b, array $u): array
{
    $cf = row('SELECT * FROM card_files WHERE id = ?', [int_in($b, 'id')]);
    if (!$cf) return [];
    $card = load_card((int) $cf['card_id'], $u, 'cardedit');
    if ((int) $cf['uid'] !== (int) $u['id'] && !can($card['studio']['role'], 'edit')) forbidden('You can only remove your own attachments.');
    $f = row('SELECT * FROM files WHERE id = ?', [$cf['file_id']]);
    q('DELETE FROM card_files WHERE id = ?', [$cf['id']]);
    if ($f) {
        file_delete($f);
        log_event((int) $card['studio_id'], (int) $card['id'], (int) $u['id'], 'attach', 'removed the attachment “' . $f['name'] . '”');
    }
    studio_touch((int) $card['studio_id']);
    return [];
}
/** Sert un fichier (GET file&t=<jeton>[&s=1 vignette][&dl=1 téléchargement]) après vérification de l'accès. */
function api_file(array $b, ?array $u): array
{
    $f = file_by_token((string) ($b['t'] ?? ''));
    if (!$f || !file_readable($f, $u)) {
        http_response_code(404);
        exit;
    }
    file_serve($f, !empty($b['s']), !empty($b['dl']));
    return [];
}

/* ------------------------------------------------------------ validations */

function api_approval_request(array $b, array $u): array
{
    $card = load_card(int_in($b, 'card_id'), $u, 'cardedit');
    $s = $card['studio'];
    need_feature(project_account($s['project']), 'approvals');   // validations : Solo et Team (lib/billing.php)
    $uid = (int) $u['id'];
    // Validateur : un membre (id) ou un invité d'un lien de partage (« g » + id).
    $guest = preg_match('/^g(\d+)$/', (string) ($b['approver'] ?? ''), $gm) ? guest_approver((int) $gm[1], $card) : null;
    $approver = $guest ? 0 : int_in($b, 'approver');
    if (!$guest) {
        $people = studio_people($s, $s['project']);
        if (!isset($people[$approver]) || !can($people[$approver]['role'], 'approve')) fail('Choose an owner, a manager or a client of this Studio as the approver.');
        if ($approver === $uid) fail('Ask someone else to approve your work.');
    }
    $level = (int) val('SELECT level_id FROM cols WHERE id = ?', [$card['col_id']]);
    $note = str_clean($b['note'] ?? '', 1000);
    tx(function () use ($card, $uid, $approver, $guest, $level, $note): void {
        q("UPDATE card_approvals SET status = 'cancelled', decided = ? WHERE card_id = ? AND status = 'pending'", [time(), $card['id']]);
        insert('card_approvals', ['card_id' => $card['id'], 'level_id' => $level, 'by_uid' => $uid, 'approver_uid' => $approver, 'approver_guest' => $guest ? (int) $guest['id'] : 0, 'note' => $note, 'created' => time()]);
        q("UPDATE cards SET approval = 'pending', updated = ? WHERE id = ?", [time(), $card['id']]);
        $name = $guest ? $guest['email'] . ' (guest)' : (string) val('SELECT name FROM users WHERE id = ?', [$approver]);
        log_event((int) $card['studio_id'], (int) $card['id'], $uid, 'approval', 'asked ' . $name . ' to approve this card');
        studio_touch((int) $card['studio_id']);
    });
    if ($guest) guest_approval_mail($guest, $card, $u, $note);
    else notify($approver, $uid, 'approval', 'asked you to approve “' . $card['title'] . '”', card_link($card), true);
    return [];
}
/**
 * Décision : approved | changes. Une carte validée avance dans la colonne finale de son niveau
 * (et passe au niveau suivant si le relais est automatique).
 */
function api_approval_decide(array $b, array $u): array
{
    $a = row('SELECT * FROM card_approvals WHERE id = ?', [int_in($b, 'id')]);
    if (!$a) not_found('This approval request');
    $card = load_card((int) $a['card_id'], $u);
    $uid = (int) $u['id'];
    $gid = !empty($u['guest']) ? (int) $u['guest']['gid'] : 0;
    // La personne désignée (membre ou invité vérifié) décide ; un propriétaire du Studio peut le faire à sa place.
    $isApprover = !empty($u['guest']) ? ($gid > 0 && (int) $a['approver_guest'] === $gid) : ($uid > 0 && (int) $a['approver_uid'] === $uid);
    if (!$isApprover && (!empty($u['guest']) || !can($card['studio']['role'], 'own'))) forbidden('Only the requested approver can decide.');
    if ($a['status'] !== 'pending') fail('This request has already been answered.', 409, 'stale');
    $decision = ($b['decision'] ?? '') === 'approved' ? 'approved' : 'changes';
    $reply = str_clean($b['reply'] ?? '', 1000);
    if ($decision === 'changes' && $reply === '') fail('Explain what should be changed.');
    $res = tx(function () use ($a, $card, $uid, $decision, $reply): array {
        q('UPDATE card_approvals SET status = ?, reply = ?, decided = ? WHERE id = ?', [$decision, $reply, time(), $a['id']]);
        q('UPDATE cards SET approval = ?, updated = ? WHERE id = ?', [$decision, time(), $card['id']]);
        log_event((int) $card['studio_id'], (int) $card['id'], $uid, 'approval', $decision === 'approved' ? 'approved this card' : 'requested changes: ' . mb_substr($reply, 0, 200));
        $out = ['moved' => null, 'handoff' => null];
        if ($decision === 'approved') {
            $level = row('SELECT l.* FROM levels l JOIN cols co ON co.level_id = l.id WHERE co.id = ?', [$card['col_id']]);
            if ($level && $level['handoff'] !== 'off' && (int) $level['handoff_col'] > 0 && (int) $level['handoff_col'] !== (int) $card['col_id']) {
                $final = row('SELECT * FROM cols WHERE id = ? AND archived = 0', [$level['handoff_col']]);
                if ($final) {
                    $out['moved'] = (string) $final['name'];
                    $out['handoff'] = card_move($card, $final, PHP_INT_MAX, $uid);
                    // La carte garde la trace de sa validation, même après le passage de relais.
                    if (!$out['handoff']) q("UPDATE cards SET approval = 'approved' WHERE id = ?", [$card['id']]);
                }
            }
        }
        studio_touch((int) $card['studio_id']);
        return $out;
    });
    notify((int) $a['by_uid'], $uid, 'approval', ($decision === 'approved' ? 'approved' : 'requested changes on') . ' “' . $card['title'] . '”', card_link($card), true);
    return $res;
}
function api_approval_cancel(array $b, array $u): array
{
    $a = row("SELECT * FROM card_approvals WHERE id = ? AND status = 'pending'", [int_in($b, 'id')]);
    if (!$a) return [];
    $card = load_card((int) $a['card_id'], $u, 'cardedit');
    q("UPDATE card_approvals SET status = 'cancelled', decided = ? WHERE id = ?", [time(), $a['id']]);
    q("UPDATE cards SET approval = '', updated = ? WHERE id = ?", [time(), $card['id']]);
    log_event((int) $card['studio_id'], (int) $card['id'], (int) $u['id'], 'approval', 'cancelled the approval request');
    studio_touch((int) $card['studio_id']);
    return [];
}
