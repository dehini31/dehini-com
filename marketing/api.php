<?php
/**
 * API du Marketing Dashboard (dehini.com/marketing/). Réservée à l'administration :
 * même mot de passe et même session que /admin (connexion via ../admin/api.php).
 *
 * GET  bootstrap                         réglages, schéma des formulaires, listes, référentiels
 * GET  attention                         nombre de choses à traiter (cloche)
 * GET  search    q                       recherche dans les leads, questions, actions, projets, campagnes…
 * GET  activity_days  month=AAAA-MM      nombre de leads, questions, actions et projets par jour (calendrier)
 * GET  recent                            dernières saisies, toutes sections confondues
 * POST import    {entity: contacts, rows[]}  import CSV (une ligne refusée n'empêche pas les autres)
 * GET  taxonomy_usage                    nombre de données qui citent chaque canal / support
 * GET  performance  from, to            niveau 3 : performance par catégorie → canal → support
 * GET  list      entity, from?, to?      lignes d'une table (filtrées par date si la table en a une)
 * POST save      {entity, id?, row}      crée ou modifie une ligne
 * POST patch     {entity, id, field, value}  modifie un seul champ (ex. statut d'un lead)
 * POST delete    {entity, id}
 * POST funnel_save {id?, funnel, stages[], links}
 * GET  funnel    id, from, to            chiffres d'un funnel sur une période
 * GET  campaign  id                      fiche d'une campagne publicitaire
 * GET  campaigns_overview                cumul de chaque campagne (budget, objectif, résultats)
 * GET  report    type=day|week|month|custom, date, to?
 * POST send_report {type, date, to?, emails?}
 * GET  archives / archive id / POST archive_delete {id}
 * POST settings_save {...} / POST test_mail {email}
 * GET  export    entity, from?, to?      CSV (Excel)
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/report.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $extra = []): void
{
    echo json_encode(['ok' => true] + $extra, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
    return is_array($d) ? $d : [];
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$action = (string) ($_GET['action'] ?? '');

// Même session que /admin : connexion valable 12 h, jeton anti-CSRF sur chaque écriture.
session_name('dehini_admin');
session_start();
$okAdmin = !empty($_SESSION['admin']) && !empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) < 12 * 3600;
if (!$okAdmin) fail('Votre session a expiré. Reconnectez-vous pour continuer.', 401);
if ($isPost && (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? '')))) {
    fail('La page a expiré pour des raisons de sécurité. Rechargez-la, puis recommencez.', 403);
}
session_write_close();

$b = $isPost ? input_json() : [];

function entity_param(string $e): string
{
    if (!isset(mk_schema()[$e])) fail('Section inconnue.');
    return $e;
}

function date_param(string $k, string $def = ''): string
{
    $v = (string) ($_GET[$k] ?? $def);
    return mk_valid_date($v) ? $v : $def;
}

/** Listes de référence : campagnes, pages, emails, funnels (avec étapes et associations). */
function refs(): array
{
    $out = [];
    foreach (['campaigns' => 'start DESC, id DESC', 'pages' => "type DESC, name", 'emails' => 'date DESC, id DESC', 'funnels' => 'name'] as $t => $order) {
        $out[$t] = q("SELECT * FROM $t ORDER BY $order");
    }
    foreach ($out['funnels'] as $i => $f) {
        $out['funnels'][$i]['stages'] = mk_funnel_stages((int) $f['id']);
        $out['funnels'][$i]['links'] = mk_funnel_links($f);
    }
    return $out;
}

try {
    db();
    switch ($action) {

        case 'bootstrap': {
            ok([
                'csrf' => null, // le jeton vient de ../admin/api.php?action=session
                'today' => mk_today(),
                'settings' => mk_settings(),
                'schema' => mk_schema(),
                'enums' => mk_enums(),
                'refs' => refs(),
                // Niveaux 1 et 2 : catégories de canaux et types de supports (fixes), supports par défaut d'une catégorie.
                'taxonomy' => [
                    'categories' => array_map(fn ($k, $c) => ['key' => $k, 'icon' => $c[0], 'label' => $c[1]], array_keys(MK_CHANNEL_CATEGORIES), MK_CHANNEL_CATEGORIES),
                    'support_types' => array_map(fn ($k, $t) => ['key' => $k, 'group' => $t[0], 'label' => $t[1]], array_keys(MK_SUPPORT_TYPES), MK_SUPPORT_TYPES),
                    'category_supports' => array_combine(array_keys(MK_CHANNEL_CATEGORIES), array_map('mk_category_supports', array_keys(MK_CHANNEL_CATEGORIES))),
                ],
                'attention' => mk_backlog(false),
                'mail' => ['smtp' => MK_SMTP_HOST !== '', 'from' => mk_mail_from()],
                'url' => mk_site_url(),
            ]);
            break;
        }

        case 'refs': {
            ok(['refs' => refs(), 'enums' => mk_enums()]);
            break;
        }

        case 'attention': {
            ok(['attention' => mk_backlog(false)]);
            break;
        }

        case 'search': {
            $term = mk_str($_GET['q'] ?? '', 60);
            if (mb_strlen($term) < 2) ok(['groups' => []]);
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $find = function (string $sql, int $n) use ($like): array {
                $s = db()->prepare($sql);
                $s->execute(array_fill(0, $n, $like));
                return $s->fetchAll();
            };
            $E = mk_enums();
            $groups = [];
            $add = function (string $key, string $label, array $rows, callable $map) use (&$groups) {
                if ($rows) $groups[] = ['key' => $key, 'label' => $label, 'count' => count($rows), 'items' => array_map($map, array_slice($rows, 0, 6))];
            };
            $esc = " ESCAPE '\\'";
            $add('contacts', 'Leads', $find("SELECT id, name, phone, email, date, source, status FROM contacts WHERE name LIKE ?$esc OR phone LIKE ?$esc OR email LIKE ?$esc OR city LIKE ?$esc ORDER BY date DESC, id DESC LIMIT 50", 4),
                fn ($r) => ['id' => (int) $r['id'], 'title' => $r['name'], 'sub' => implode(' · ', array_filter([$r['phone'], mk_label('sources', $r['source']), mk_label('contact_status', $r['status'])])), 'type' => 'Lead']);
            $add('inquiries', 'Questions', $find("SELECT id, name, question, date, source FROM inquiries WHERE name LIKE ?$esc OR question LIKE ?$esc OR contact LIKE ?$esc ORDER BY date DESC, id DESC LIMIT 50", 3),
                fn ($r) => ['id' => (int) $r['id'], 'title' => mb_substr($r['question'], 0, 70), 'sub' => trim(($r['name'] ?: 'Anonyme') . ' · ' . mk_label('sources', $r['source'])), 'type' => 'Question']);
            $add('posts', 'Actions', $find("SELECT id, topic, date, platform, format FROM posts WHERE topic LIKE ?$esc OR link LIKE ?$esc ORDER BY date DESC, id DESC LIMIT 50", 2),
                fn ($r) => ['id' => (int) $r['id'], 'title' => $r['topic'], 'sub' => mk_label('sources', $r['platform']) . ($r['format'] ? ' · ' . mk_label('supports', $r['format']) : '') . ' · ' . $r['date'], 'type' => 'Action']);
            $add('projects', 'Projets', $find("SELECT id, name, owner, sector, status FROM projects WHERE name LIKE ?$esc OR owner LIKE ?$esc OR sector LIKE ?$esc ORDER BY date DESC, id DESC LIMIT 50", 3),
                fn ($r) => ['id' => (int) $r['id'], 'title' => $r['name'], 'sub' => implode(' · ', array_filter([$r['owner'], $r['sector'], mk_label('project_status', $r['status'])])), 'type' => 'Projet']);
            $add('campaigns', 'Campagnes', $find("SELECT id, name, platform, status FROM campaigns WHERE name LIKE ?$esc ORDER BY start DESC LIMIT 50", 1),
                fn ($r) => ['id' => (int) $r['id'], 'title' => $r['name'], 'sub' => mk_label('ad_channels', $r['platform']) . ' · ' . mk_label('campaign_status', $r['status']), 'type' => 'Campagne pub']);
            $add('emails', 'Emails', $find("SELECT id, name, subject, date FROM emails WHERE name LIKE ?$esc OR subject LIKE ?$esc ORDER BY date DESC LIMIT 50", 2),
                fn ($r) => ['id' => (int) $r['id'], 'title' => $r['name'], 'sub' => trim($r['subject'] . ' · ' . $r['date'], ' ·'), 'type' => 'Campagne email']);
            $add('funnels', 'Funnels', $find("SELECT id, name, objective FROM funnels WHERE name LIKE ?$esc OR objective LIKE ?$esc LIMIT 50", 2),
                fn ($r) => ['id' => (int) $r['id'], 'title' => $r['name'], 'sub' => $r['objective'], 'type' => 'Funnel']);
            $low = mb_strtolower($term);
            $tax = [];
            foreach (mk_channels() as $c) if (mb_strpos(mb_strtolower($c['label']), $low) !== false) $tax[] = ['id' => $c['key'], 'title' => $c['label'], 'sub' => 'Canal · ' . MK_CHANNEL_CATEGORIES[$c['category']][1], 'type' => 'Canal'];
            foreach (mk_supports() as $x) if (mb_strpos(mb_strtolower($x['label']), $low) !== false) $tax[] = ['id' => $x['key'], 'title' => $x['label'], 'sub' => 'Support · ' . MK_SUPPORT_TYPES[$x['type']][1], 'type' => 'Support'];
            if ($tax) $groups[] = ['key' => 'taxonomy', 'label' => 'Canaux & supports', 'count' => count($tax), 'items' => array_slice($tax, 0, 6)];
            ok(['groups' => $groups]);
            break;
        }

        case 'activity_days': {
            $m = (string) ($_GET['month'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $m)) $m = substr(mk_today(), 0, 7);
            $days = [];
            foreach (['contacts' => 'leads', 'inquiries' => 'questions', 'posts' => 'actions', 'projects' => 'projects'] as $t => $k) {
                foreach (q("SELECT date, COUNT(*) n FROM $t WHERE date LIKE ? GROUP BY date", [$m . '-%']) as $r) $days[$r['date']][$k] = (int) $r['n'];
            }
            ok(['month' => $m, 'days' => $days]);
            break;
        }

        case 'recent': {
            // Dernières saisies (date d'enregistrement), toutes sections confondues.
            $items = [];
            $push = function (string $sql, callable $map) use (&$items) { foreach (q($sql) as $r) $items[] = $map($r) + ['created' => (int) $r['created']]; };
            $push("SELECT id, name, source, created FROM contacts ORDER BY created DESC LIMIT 8", fn ($r) => ['kind' => 'contacts', 'id' => (int) $r['id'], 'text' => "Lead « {$r['name']} » ajouté", 'sub' => mk_label('sources', $r['source'])]);
            $push("SELECT id, name, question, created FROM inquiries ORDER BY created DESC LIMIT 8", fn ($r) => ['kind' => 'inquiries', 'id' => (int) $r['id'], 'text' => 'Question de ' . ($r['name'] ?: 'quelqu\'un'), 'sub' => mb_substr($r['question'], 0, 60)]);
            $push("SELECT id, topic, platform, format, created FROM posts ORDER BY created DESC LIMIT 8", fn ($r) => ['kind' => 'posts', 'id' => (int) $r['id'], 'text' => 'Action « ' . mb_substr($r['topic'], 0, 40) . ' »', 'sub' => mk_label('sources', $r['platform']) . ($r['format'] ? ' · ' . mk_label('supports', $r['format']) : '')]);
            $push("SELECT id, name, status, created FROM projects ORDER BY created DESC LIMIT 6", fn ($r) => ['kind' => 'projects', 'id' => (int) $r['id'], 'text' => "Projet « {$r['name']} »", 'sub' => mk_label('project_status', $r['status'])]);
            $push("SELECT id, name, created FROM campaigns ORDER BY created DESC LIMIT 4", fn ($r) => ['kind' => 'campaigns', 'id' => (int) $r['id'], 'text' => "Campagne « {$r['name']} » créée", 'sub' => 'Ads Manager']);
            $push("SELECT id, name, created FROM emails ORDER BY created DESC LIMIT 4", fn ($r) => ['kind' => 'emails', 'id' => (int) $r['id'], 'text' => "Campagne email « {$r['name']} »", 'sub' => 'Email Marketing']);
            usort($items, fn ($a, $b) => $b['created'] <=> $a['created']);
            ok(['items' => array_slice($items, 0, 12)]);
            break;
        }

        case 'import': {
            if (!$isPost) fail('Méthode invalide.', 405);
            $e = entity_param((string) ($b['entity'] ?? ''));
            if ($e !== 'contacts') fail("L'import CSV n'est possible que pour les leads.");
            $rows = is_array($b['rows'] ?? null) ? $b['rows'] : [];
            if (!$rows) fail('Le fichier ne contient aucune ligne à importer.');
            if (count($rows) > 1000) fail('1 000 lignes au maximum par import : découpez le fichier en plusieurs parties.');
            $done = 0;
            $errors = [];
            db()->beginTransaction();
            try {
                foreach ($rows as $i => $row) {
                    try {
                        mk_save('contacts', is_array($row) ? $row : []);
                        $done++;
                    } catch (MkInputError $err) {
                        $errors[] = ['line' => (int) ($row['_line'] ?? $i + 2), 'message' => $err->getMessage()];
                    }
                }
                db()->commit();
            } catch (Throwable $err) {
                db()->rollBack();
                throw $err;
            }
            ok(['imported' => $done, 'errors' => $errors]);
            break;
        }

        case 'taxonomy_usage': {
            // Combien de données citent chaque canal et chaque support (pour avertir avant d'en retirer un).
            $ch = [];
            foreach (mk_channels() as $c) if ($n = mk_channel_usage($c['key'])) $ch[$c['key']] = $n;
            $sp = [];
            foreach (mk_supports() as $x) if ($n = mk_support_usage($x['key'])) $sp[$x['key']] = $n;
            ok(['channels' => $ch, 'supports' => $sp]);
            break;
        }

        case 'performance': {
            // Niveau 3 : performance par catégorie → canal → support, avec la période précédente.
            $to = date_param('to', mk_today());
            $from = date_param('from', mk_add_days($to, -29));
            if ($from > $to) [$from, $to] = [$to, $from];
            $p = mk_period('custom', $from, $to);
            ok(['period' => $p, 'perf' => mk_comm_performance($p['from'], $p['to']), 'prev' => mk_comm_performance($p['prev_from'], $p['prev_to'])['totals']]);
            break;
        }

        case 'list': {
            $e = entity_param((string) ($_GET['entity'] ?? ''));
            $dateCol = mk_schema()[$e]['date'];
            $from = date_param('from');
            $to = date_param('to');
            $where = [];
            $p = [];
            if ($dateCol !== '' && $from !== '') { $where[] = "$dateCol >= ?"; $p[] = $from; }
            if ($dateCol !== '' && $to !== '') { $where[] = "$dateCol <= ?"; $p[] = $to; }
            foreach (['status', 'type', 'source', 'platform', 'campaign_id', 'page_id', 'funnel_id', 'assigned_to'] as $k) {
                if (isset($_GET[$k]) && isset(mk_schema()[$e]['fields'][$k])) { $where[] = "$k = ?"; $p[] = (string) $_GET[$k]; }
            }
            $order = $dateCol !== '' ? "$dateCol DESC, id DESC" : 'id DESC';
            $rows = q("SELECT * FROM $e" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY $order LIMIT 5000", $p);
            ok(['rows' => $rows, 'truncated' => count($rows) >= 5000]);
            break;
        }

        case 'save': {
            if (!$isPost) fail('Méthode invalide.', 405);
            $e = entity_param((string) ($b['entity'] ?? ''));
            if ($e === 'funnels') fail('Utiliser l\'éditeur de funnel.');
            $row = mk_save($e, is_array($b['row'] ?? null) ? $b['row'] : [], (int) ($b['id'] ?? 0));
            ok(['row' => $row]);
            break;
        }

        case 'patch': {
            if (!$isPost) fail('Méthode invalide.', 405);
            $e = entity_param((string) ($b['entity'] ?? ''));
            $field = (string) ($b['field'] ?? '');
            if (!isset(mk_schema()[$e]['fields'][$field])) fail('Champ inconnu.');
            $id = (int) ($b['id'] ?? 0);
            $row = mk_save($e, [$field => $b['value'] ?? ''], $id);
            ok(['row' => $row]);
            break;
        }

        case 'delete': {
            if (!$isPost) fail('Méthode invalide.', 405);
            $e = entity_param((string) ($b['entity'] ?? ''));
            mk_delete($e, (int) ($b['id'] ?? 0));
            ok();
            break;
        }

        case 'funnel_save': {
            if (!$isPost) fail('Méthode invalide.', 405);
            ok(['funnel' => mk_funnel_save($b)]);
            break;
        }

        case 'funnel': {
            $f = mk_funnel_full((int) ($_GET['id'] ?? 0));
            if (!$f) fail('Funnel introuvable.', 404);
            $from = date_param('from', mk_add_days(mk_today(), -29));
            $to = date_param('to', mk_today());
            ok(['funnel' => $f, 'data' => mk_funnel_compute($f, min($from, $to), max($from, $to)),
                'entries' => q('SELECT * FROM funnel_entries WHERE funnel_id = ? ORDER BY date DESC, id DESC LIMIT 500', [(int) $f['id']])]);
            break;
        }

        case 'campaign': {
            $id = (int) ($_GET['id'] ?? 0);
            $c = q1('SELECT * FROM campaigns WHERE id = ?', [$id]);
            if (!$c) fail('Campagne introuvable.', 404);
            $end = $c['end'] !== '' ? min($c['end'], mk_today()) : mk_today();
            if ($end < $c['start']) $end = $c['start'];
            $snap = mk_campaign_snapshot($c['start'], $end, $id)[0];
            $funnel = null;
            if ($c['funnel_id']) {
                $f = mk_funnel_full((int) $c['funnel_id']);
                if ($f) $funnel = mk_funnel_compute($f, $c['start'], $end);
            }
            ok([
                'campaign' => $c, 'snapshot' => $snap, 'funnel' => $funnel,
                'results' => q('SELECT * FROM ad_results WHERE campaign_id = ? ORDER BY date DESC, id DESC', [$id]),
                'leads' => q('SELECT * FROM contacts WHERE campaign_id = ? ORDER BY date DESC, id DESC LIMIT 500', [$id]),
                'leads_stats' => q('SELECT status, COUNT(*) n FROM contacts WHERE campaign_id = ? GROUP BY status', [$id]),
            ]);
            break;
        }

        case 'campaigns_overview': {
            // Cumul de chaque campagne depuis son début (budget, dépense, objectif, résultats).
            $rows = [];
            foreach (q('SELECT id, start, end FROM campaigns ORDER BY start DESC, id DESC') as $c) {
                $end = $c['end'] !== '' ? min($c['end'], mk_today()) : mk_today();
                if ($end < $c['start']) $end = $c['start'];
                $rows[] = mk_campaign_snapshot($c['start'], $end, (int) $c['id'])[0];
            }
            ok(['rows' => $rows]);
            break;
        }

        case 'report': {
            $type = (string) ($_GET['type'] ?? 'week');
            if (!isset(MK_TYPES[$type])) $type = 'week';
            ok(['report' => mk_report($type, date_param('date', mk_today()), date_param('to'))]);
            break;
        }

        case 'send_report': {
            if (!$isPost) fail('Méthode invalide.', 405);
            $type = (string) ($b['type'] ?? 'week');
            if (!isset(MK_TYPES[$type])) $type = 'week';
            $date = (string) ($b['date'] ?? '');
            if (!mk_valid_date($date)) $date = mk_today();
            $to = [];
            foreach ((array) ($b['emails'] ?? []) as $m) {
                $m = strtolower(trim((string) $m));
                if (filter_var($m, FILTER_VALIDATE_EMAIL)) $to[] = $m;
            }
            if (!$to && !mk_settings()['recipients']) fail('Aucun destinataire : ajoutez des adresses dans les Réglages.');
            $r = mk_report($type, $date, (string) ($b['to'] ?? ''));
            $res = mk_send_report($r, 'manuel', array_slice($to, 0, 20));
            if (!$res['sent']) fail('Envoi impossible : ' . implode(' ; ', $res['errors']), 502);
            ok($res);
            break;
        }

        case 'archive_save': {
            // Archiver le rapport affiché, sans l'envoyer.
            if (!$isPost) fail('Méthode invalide.', 405);
            $type = (string) ($b['type'] ?? 'week');
            if (!isset(MK_TYPES[$type])) $type = 'week';
            $date = (string) ($b['date'] ?? '');
            $id = mk_archive_report(mk_report($type, mk_valid_date($date) ? $date : mk_today(), (string) ($b['to'] ?? '')), 'manuel');
            ok(['id' => $id]);
            break;
        }

        case 'archives': {
            ok(['rows' => q('SELECT id, type, date_from, date_to, label, sent_to, origin, created FROM reports ORDER BY date_from DESC, id DESC LIMIT 400')]);
            break;
        }

        case 'archive': {
            $r = q1('SELECT * FROM reports WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
            if (!$r) fail('Rapport introuvable.', 404);
            ok(['meta' => array_diff_key($r, ['payload' => 1]), 'report' => json_decode($r['payload'], true)]);
            break;
        }

        case 'archive_delete': {
            if (!$isPost) fail('Méthode invalide.', 405);
            db()->prepare('DELETE FROM reports WHERE id = ?')->execute([(int) ($b['id'] ?? 0)]);
            ok();
            break;
        }

        case 'settings_save': {
            if (!$isPost) fail('Méthode invalide.', 405);
            ok(['settings' => mk_settings_save($b), 'enums' => mk_enums()]);
            break;
        }

        case 'test_mail': {
            if (!$isPost) fail('Méthode invalide.', 405);
            $to = strtolower(trim((string) ($b['email'] ?? '')));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('Adresse email invalide.');
            $r = mk_report('day', mk_add_days(mk_today(), -1));
            [$subject, $html, $text] = mk_report_email($r, mk_site_url());
            try {
                mk_send_mail($to, '[Test] ' . $subject, $html, $text);
            } catch (Throwable $e) {
                fail('Envoi impossible : ' . $e->getMessage(), 502);
            }
            ok(['to' => $to]);
            break;
        }

        case 'export': {
            $e = entity_param((string) ($_GET['entity'] ?? ''));
            $schema = mk_schema()[$e];
            $from = date_param('from');
            $to = date_param('to');
            $where = [];
            $p = [];
            if ($schema['date'] !== '' && $from !== '') { $where[] = "{$schema['date']} >= ?"; $p[] = $from; }
            if ($schema['date'] !== '' && $to !== '') { $where[] = "{$schema['date']} <= ?"; $p[] = $to; }
            $rows = q("SELECT * FROM $e" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . ($schema['date'] !== '' ? $schema['date'] . ' DESC, ' : '') . 'id DESC', $p);
            $refNames = [];
            foreach ($schema['fields'] as $k => $fd) {
                if ($fd['t'] !== 'ref') continue;
                $t = $fd['ref'];
                $refNames[$k] = array_column(q("SELECT id, name FROM $t"), 'name', 'id');
            }
            // Excel exécuterait une cellule qui commence par = + - @ : on la neutralise.
            $cell = function ($v): string {
                $v = (string) $v;
                return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            };
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $e . '-' . mk_today() . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
            fputcsv($out, array_merge(['id'], array_map(fn ($fd) => $fd['l'], array_values($schema['fields']))), ';');
            foreach ($rows as $r) {
                $line = [$r['id']];
                foreach ($schema['fields'] as $k => $fd) {
                    $v = $r[$k];
                    if ($fd['t'] === 'enum') $v = $v === '' ? '' : mk_label($fd['opts'], $v);
                    elseif ($fd['t'] === 'multi') $v = implode(', ', array_map(fn ($x) => mk_label($fd['opts'], $x), array_filter(explode(',', (string) $v))));
                    elseif ($fd['t'] === 'ref') $v = $v === null ? '' : ($refNames[$k][$v] ?? ('#' . $v));
                    elseif ($fd['t'] === 'money') $v = str_replace('.', ',', (string) (float) $v);
                    $line[] = $cell($v);
                }
                fputcsv($out, $line, ';');
            }
            fclose($out);
            exit;
        }

        default:
            fail('Action inconnue.', 404);
    }
} catch (MkInputError $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    error_log('[marketing] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail("Le serveur n'a pas pu terminer l'opération (" . $e->getMessage() . '). Réessayez dans un instant.', 500);
}
