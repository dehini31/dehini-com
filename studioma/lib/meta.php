<?php
/**
 * Meta Pixel + API Conversions (CAPI) de Studioma.
 *
 * Chaque action importante (inscription, création d'un Studio, candidature…) est décidée ici, côté serveur,
 * dans meta_after() : l'événement part vers Meta par l'API Conversions après la réponse, et la réponse de
 * l'API le renvoie au navigateur (clé « _meta ») pour que le pixel envoie le même, avec le même event_id :
 * Meta n'en compte qu'un (déduplication), même si le pixel est bloqué. PageView est envoyé par le pixel à
 * chaque écran.
 *
 * Confidentialité : on n'envoie jamais le contenu (titres, noms de Studios ou de projets, textes,
 * recherches libres) ni aucune clé secrète (liens de partage, invitations, mots de passe oubliés) : le
 * pixel se tait sur ces écrans. Identifiants de la personne connectée hachés en SHA-256 (email, nom, id) ;
 * pour un visiteur ou un invité sans compte : seulement l'IP, le navigateur et les cookies Meta (_fbp, _fbc).
 *
 * Réglages (config.php) : STM_META_PIXEL_ID (l'ensemble de données), STM_META_TOKEN (ou le fichier
 * meta-token.txt dans le dossier des données, hors du site), STM_META_TEST_CODE (onglet « Événements de
 * test » du Gestionnaire d'événements), STM_META_LOG (tests : les événements sont écrits dans ce fichier).
 */
declare(strict_types=1);

const META_GRAPH_VERSION = 'v24.0';
/** Événements standard de Meta (fbq('track')) ; les autres sont personnalisés (fbq('trackCustom')). */
const META_STANDARD = ['PageView', 'ViewContent', 'Search', 'Lead', 'CompleteRegistration', 'Contact', 'SubmitApplication', 'AddToWishlist'];

function meta_pixel_id(): string
{
    $id = (string) STM_META_PIXEL_ID;
    return preg_match('/^\d{5,20}$/', $id) ? $id : '';
}
/** Jeton de l'API Conversions : config.php, sinon <dossier des données>/meta-token.txt (jamais dans le dépôt). */
function meta_token(): string
{
    static $t = null;
    if ($t !== null) return $t;
    $t = (string) STM_META_TOKEN;
    if ($t === '') {
        $f = STM_DATA_DIR . '/meta-token.txt';
        $t = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    }
    return $t = preg_match('/^[A-Za-z0-9_-]{20,600}$/', $t) ? $t : '';
}
function meta_sha(string $v): string
{
    return hash('sha256', $v);
}
function meta_norm_name(string $s): string
{
    $s = mb_strtolower(trim($s));
    if (function_exists('transliterator_transliterate')) {
        $x = @transliterator_transliterate('Latin-ASCII', $s);
        if (is_string($x)) $s = $x;
    }
    return preg_replace('/[^\p{L}\p{N}]/u', '', $s) ?? '';
}

/** Données d'identification de l'événement (hachées, sauf IP, navigateur et cookies Meta). */
function meta_user_data(?array $u): array
{
    $d = [];
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua !== '') $d['client_user_agent'] = mb_substr($ua, 0, 500);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP)) $d['client_ip_address'] = $ip;
    $fbp = (string) ($_COOKIE['_fbp'] ?? '');
    if (preg_match('/^fb\.\d\.\d+\.\d+$/', $fbp)) $d['fbp'] = $fbp;
    $fbc = (string) ($_COOKIE['_fbc'] ?? '');
    if (preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_-]{1,500}$/', $fbc)) $d['fbc'] = $fbc;
    // Compte Studioma seulement (jamais un invité d'un lien de partage).
    if ($u && (int) ($u['id'] ?? 0) > 0 && empty($u['guest'])) {
        $email = strtolower(trim((string) ($u['email'] ?? '')));
        if ($email !== '') $d['em'] = [meta_sha($email)];
        $d['external_id'] = [meta_sha('studioma:' . (int) $u['id'])];
        $parts = preg_split('/\s+/u', trim((string) ($u['name'] ?? ''))) ?: [];
        if (count($parts) > 1) {
            $fn = meta_norm_name((string) array_shift($parts));
            $ln = meta_norm_name(implode('', $parts));
            if ($fn !== '') $d['fn'] = [meta_sha($fn)];
            if ($ln !== '') $d['ln'] = [meta_sha($ln)];
        }
    }
    return $d;
}
/** Page d'origine sans ce qui suit # ou ? (où peuvent se trouver des clés secrètes). */
function meta_source_url(): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $ref = preg_replace('/[?#].*$/s', '', $ref) ?? '';
    return $ref !== '' && strpos($ref, site_url()) === 0 ? $ref : site_url() . 'app/';
}

/**
 * Événement Meta. $u : compte concerné (identifiants hachés), null pour un visiteur ou un invité.
 * $browser : renvoyé aussi au navigateur (pixel, même event_id).
 */
function meta_track(string $name, array $custom = [], ?array $u = null, bool $browser = true): void
{
    if (meta_pixel_id() === '') return;
    $custom = array_filter($custom, function ($v): bool { return $v !== null && $v !== '' && $v !== []; });
    $id = 'stm.' . strtolower($name) . '.' . bin2hex(random_bytes(8));
    if ($browser) {
        $GLOBALS['stm_meta_out'][] = ['name' => $name, 'id' => $id, 'std' => in_array($name, META_STANDARD, true), 'data' => (object) $custom];
    }
    if (meta_token() === '') return;
    $user = meta_user_data($u);
    if (!isset($user['client_user_agent'])) return;   // Meta exige le navigateur pour un événement « website »
    $event = ['event_name' => $name, 'event_time' => time(), 'event_id' => $id, 'action_source' => 'website', 'event_source_url' => meta_source_url(), 'user_data' => $user];
    if ($custom) $event['custom_data'] = $custom;
    meta_queue($event);
}
/** Les événements d'une requête partent ensemble, après la réponse (une lenteur de Meta ne retarde rien). */
function meta_queue(array $event): void
{
    static $q = null;
    if ($q === null) {
        $q = new ArrayObject();
        register_shutdown_function(function () use ($q): void {
            if (!count($q)) return;
            if (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try {
                meta_send($q->getArrayCopy());
            } catch (Throwable $e) {
                error_log('studioma meta: ' . $e->getMessage());
            }
        });
    }
    $q->append($event);
}
/** Envoie des événements à l'API Conversions. Retourne [ok, message]. */
function meta_send(array $events): array
{
    $body = ['data' => array_values($events)];
    if (STM_META_TEST_CODE !== '') $body['test_event_code'] = (string) STM_META_TEST_CODE;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (STM_META_LOG !== '') {
        file_put_contents(STM_META_LOG, $json . "\n", FILE_APPEND | LOCK_EX);
        return [true, ''];
    }
    $url = rtrim((string) STM_META_GRAPH, '/') . '/' . META_GRAPH_VERSION . '/' . meta_pixel_id() . '/events?access_token=' . rawurlencode(meta_token());
    $code = 0;
    $res = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4]);
        $res = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $res = (string) @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $json, 'timeout' => 8, 'ignore_errors' => true]]));
        if (preg_match('~^HTTP/\S+ (\d{3})~', (string) ($http_response_header[0] ?? ''), $m)) $code = (int) $m[1];
    }
    $j = json_decode($res, true);
    if ($code >= 200 && $code < 300 && is_array($j) && isset($j['events_received'])) {
        meta_set('meta_capi_ok', date('Y-m-d H:i:s') . ' — ' . count($events) . ' event(s)');
        return [true, ''];
    }
    $msg = is_array($j) && isset($j['error']['message']) ? (string) $j['error']['message'] : 'HTTP ' . $code;
    error_log('studioma meta: ' . $msg);
    meta_set('meta_capi_error', date('Y-m-d H:i:s') . ' — ' . mb_substr($msg, 0, 300));
    return [false, $msg];
}

/* --------------------------------------------------- les événements de Studioma */

/**
 * Après une action réussie de l'API : l'événement Meta qui lui correspond, s'il y en a un. Seulement des
 * catégories (type de compte, rôle, type de contenu…) : jamais de titre, de nom ni de texte saisi.
 */
function meta_after(string $action, array $b, array $r, ?array $u): void
{
    if (meta_pixel_id() === '') return;
    if ($u && !empty($u['guest'])) { meta_after_guest($action, $r); return; }
    $by = function () use ($r, $u): ?array { return $u ?: (isset($r['user']['id']) ? user_by_id((int) $r['user']['id']) : null); };
    $on = !empty($b['on']);
    switch ($action) {
        // Comptes
        case 'signup':
            meta_track('CompleteRegistration', ['content_name' => ($b['kind'] ?? '') === 'company' ? 'company' : 'individual', 'status' => 'completed', 'registration_method' => 'email'], $by());
            break;
        case 'google':
            $nu = $by();
            if (!empty($r['created'])) meta_track('CompleteRegistration', ['content_name' => ($b['kind'] ?? '') === 'company' ? 'company' : 'individual', 'status' => 'completed', 'registration_method' => 'google'], $nu);
            else meta_track('Login', ['method' => 'google'], $nu);
            break;
        case 'login': meta_track('Login', ['method' => 'email'], $by()); break;
        case 'email_confirm': if (empty($r['already'])) meta_track('ConfirmEmail', [], $u); break;
        // Projets, Studios, cartes
        case 'project_save':
            if (!int_in($b, 'id')) {
                meta_track('CreateProject', ['content_category' => int_in($b, 'company_id') ? 'company' : 'personal'], $u);
                if (!empty($r['studio_id'])) meta_track('CreateStudio', ['template' => (string) ($b['template'] ?? '')], $u);
            }
            break;
        case 'studio_create': meta_track('CreateStudio', ['template' => (string) ($b['template'] ?? 'content')], $u); break;
        case 'card_create': meta_track('CreateCard', [], $u); break;
        case 'card_move':
        case 'card_handoff':
            if (!empty($r['handoff'])) meta_track('CardHandoff', [], $u);
            elseif ($action === 'card_move' && val("SELECT 1 FROM cols WHERE id = ? AND kind = 'done'", [int_in($b, 'col_id')])) meta_track('ContentPublished', [], $u);
            break;
        case 'cards_bulk': if (($b['op'] ?? '') === 'move' && !empty($r['handed'])) meta_track('CardHandoff', ['num_items' => (int) $r['handed']], $u); break;
        case 'comment_add': meta_track('AddComment', ['content_type' => 'card'], $u); break;
        case 'upload': if (($b['kind'] ?? '') === 'card') meta_track('AddAttachment', ['content_type' => (string) ($r['file']['kind'] ?? '')], $u); break;
        case 'approval_request': meta_track('RequestApproval', ['approver' => preg_match('/^g\d+$/', (string) ($b['approver'] ?? '')) ? 'guest' : 'member'], $u); break;
        case 'approval_decide': meta_track(($b['decision'] ?? '') === 'approved' ? 'ApproveContent' : 'RequestChanges', [], $u); break;
        case 'portfolio_from_studio': meta_track('CreateCaseStudy', ['source' => 'studio'], $u); break;
        // Équipe et partage
        case 'invite_create': meta_track('InviteMember', ['role' => (string) ($b['role'] ?? ''), 'method' => trim((string) ($b['email'] ?? '')) !== '' ? 'email' : 'link'], $u); break;
        case 'invite_accept': meta_track('JoinTeam', [], $u); break;
        case 'share_save':
        case 'share_guest':
            // Lien de partage créé pendant la requête (share_get_or_create, lib/share.php).
            if (!empty($GLOBALS['stm_share_created'])) meta_track('ShareStudio', ['link_type' => (string) $GLOBALS['stm_share_created']], $u);
            if ($action === 'share_save') break;
            if (($b['op'] ?? '') === 'add') meta_track('AddGuest', ['role' => (string) ($b['role'] ?? 'viewer')], $u);
            if (($b['op'] ?? '') === 'approve') meta_track('ApproveGuest', ['role' => (string) ($b['role'] ?? '')], $u);
            break;
        // Réseau et portfolio
        case 'profile_save': meta_track('UpdateProfile', [], $u); break;
        case 'post_create': meta_track('CreatePost', ['content_category' => int_in($b, 'company_id') ? 'company' : 'personal', 'media' => !empty($b['media']) ? 'yes' : 'no', 'shared' => int_in($b, 'share_id') ? 'yes' : 'no'], $u); break;
        case 'post_like': if ($on) meta_track('LikePost', [], $u); break;
        case 'post_save': if ($on) meta_track('SavePost', [], $u); break;
        case 'post_comment': meta_track('AddComment', ['content_type' => 'post'], $u); break;
        case 'follow': if ($on) meta_track('Follow', ['content_type' => ($b['type'] ?? '') === 'company' ? 'company' : 'profile'], $u); break;
        case 'connect': meta_track('ConnectRequest', [], $u); break;
        case 'recommend': meta_track('Recommend', ['relation' => (string) ($b['relation'] ?? '')], $u); break;
        case 'portfolio_save':
            if (($r['status'] ?? '') === 'published') meta_track('PublishCaseStudy', [], $u);
            elseif (!int_in($b, 'id')) meta_track('CreateCaseStudy', ['source' => 'manual'], $u);
            break;
        case 'portfolio_authorize': meta_track('AuthorizeCaseStudy', ['decision' => !empty($b['approve']) ? 'approved' : 'declined'], $u); break;
        case 'portfolio_confirm': if (!empty($b['confirm'])) meta_track('ConfirmResults', [], $u); break;
        // Entreprises, opportunités, talents, messages
        case 'company_save': if (!int_in($b, 'id')) meta_track('CreateCompany', ['industry' => in_array($b['industry'] ?? '', STM_INDUSTRIES, true) ? (string) $b['industry'] : ''], $u); break;
        case 'company_member': if (($b['op'] ?? '') === 'add') meta_track('AddTeamMember', ['role' => (string) ($b['role'] ?? 'member')], $u); break;
        case 'opp_save':
            if (!int_in($b, 'id')) meta_track('PublishOpportunity', ['contract' => (string) ($b['contract'] ?? ''), 'work_mode' => (string) ($b['mode'] ?? '')], $u);
            break;
        case 'opp_apply': meta_track('SubmitApplication', ['content_type' => 'opportunity', 'content_ids' => ['opportunity-' . int_in($b, 'id')]], $u); break;
        case 'opp_application': meta_track('AnswerApplication', ['status' => (string) ($b['status'] ?? '')], $u); break;
        case 'talent_save': if ($on) meta_track('AddToWishlist', ['content_type' => 'talent'], $u); break;
        case 'message_send': meta_track('Contact', ['content_type' => 'message'], $u); break;
        // Assistants IA
        case 'oauth_decide': if (!empty($b['allow'])) meta_track('ConnectAI', ['method' => 'oauth'], $u); break;
        case 'ai_token_create': meta_track('ConnectAI', ['method' => 'token'], $u); break;
        // Recherches et consultations (jamais le texte tapé : seulement des valeurs des listes de Studioma)
        case 'search':
            if (mb_strlen(trim((string) ($b['q'] ?? ''))) >= 2) meta_track('Search', ['content_category' => 'all'], $u);
            break;
        case 'talent':
            $f = array_values(array_filter([
                in_array($b['skill'] ?? '', STM_SKILLS, true) ? $b['skill'] : '', isset(STM_PLATFORMS[$b['platform'] ?? '']) ? STM_PLATFORMS[$b['platform']] : '',
                in_array($b['industry'] ?? '', STM_INDUSTRIES, true) ? $b['industry'] : '', in_array($b['language'] ?? '', STM_LANGUAGES, true) ? $b['language'] : '',
            ]));
            if ($f || trim((string) ($b['q'] ?? '')) !== '') meta_track('Search', ['content_category' => 'talent', 'search_string' => implode(', ', $f)], $u);
            break;
        case 'opps':
            if (empty($b['mine']) && empty($b['applied']) && (trim((string) ($b['q'] ?? '')) !== '' || !empty($b['mode']) || !empty($b['contract']) || !empty($b['skill']))) {
                meta_track('Search', ['content_category' => 'opportunities', 'search_string' => implode(', ', array_filter([
                    isset(STM_WORK_MODES[$b['mode'] ?? '']) ? STM_WORK_MODES[$b['mode']] : '', isset(STM_CONTRACTS[$b['contract'] ?? '']) ? STM_CONTRACTS[$b['contract']] : '',
                    in_array($b['skill'] ?? '', STM_SKILLS, true) ? $b['skill'] : '',
                ]))], $u);
            }
            break;
        case 'profile_get':
            $pid = (int) ($r['profile']['id'] ?? 0);
            if ($pid && empty($r['rel']['self'])) meta_track('ViewContent', ['content_type' => 'profile', 'content_ids' => ['profile-' . $pid]], $u);
            break;
        case 'company_get': meta_track('ViewContent', ['content_type' => 'company', 'content_ids' => ['company-' . (int) ($r['company']['id'] ?? 0)]], $u); break;
        case 'opp_get': meta_track('ViewContent', ['content_type' => 'opportunity', 'content_ids' => ['opportunity-' . int_in($b, 'id')]], $u); break;
        case 'feed': if (($b['tab'] ?? '') === 'post') meta_track('ViewContent', ['content_type' => 'post', 'content_ids' => ['post-' . int_in($b, 'id')]], $u); break;
    }
}
/**
 * Invités d'un lien de partage : serveur seulement (le pixel se tait sur ces pages, qui portent la clé du
 * lien dans leur adresse), et sans leur adresse email : ce ne sont pas des comptes Studioma.
 */
function meta_after_guest(string $action, array $r): void
{
    if ($action === 'share_verify') {
        meta_track('GuestVerified', [], null, false);
        if (($r['requested'] ?? '') === 'pending') meta_track('Lead', ['content_category' => 'guest_access', 'role' => (string) ($r['guest']['pending'] ?? '')], null, false);
    } elseif ($action === 'share_request') {
        meta_track('Lead', ['content_category' => 'guest_access', 'role' => (string) ($r['guest']['pending'] ?? '')], null, false);
    } elseif ($action === 'card_save' || $action === 'card_create' || $action === 'comment_add' || $action === 'approval_decide') {
        meta_track('GuestCollaboration', ['action' => $action], null, false);
    }
}
