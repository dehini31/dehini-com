<?php
/**
 * API du Marketing Dashboard (dehini.com/marketing/). Réservée à l'administration :
 * même mot de passe et même session que /admin (connexion via ../admin/api.php).
 *
 * GET  bootstrap                         réglages, schéma des formulaires, listes, référentiels
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
if (!$okAdmin) fail('Session expirée, reconnecte-toi.', 401);
if ($isPost && (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], (string) ($_SERVER['HTTP_X_CSRF'] ?? '')))) {
    fail('Jeton de sécurité invalide, recharge la page.', 403);
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
                'mail' => ['smtp' => MK_SMTP_HOST !== '', 'from' => mk_mail_from()],
                'url' => mk_site_url(),
            ]);
            break;
        }

        case 'refs': {
            ok(['refs' => refs(), 'enums' => mk_enums()]);
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
            $enums = mk_enums();
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
                    if ($fd['t'] === 'enum') $v = $enums[$fd['opts']][$v] ?? $v;
                    elseif ($fd['t'] === 'multi') $v = implode(', ', array_map(fn ($x) => $enums[$fd['opts']][$x] ?? $x, array_filter(explode(',', (string) $v))));
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
    fail('Erreur serveur : ' . $e->getMessage(), 500);
}
