<?php
/**
 * Connexion Meta du Marketing Dashboard : Page Facebook, compte Instagram professionnel relié
 * à la Page, comptes publicitaires Meta Ads et formulaires Lead Ads.
 *
 * La synchronisation remplit toute seule :
 *   - Social Media › Relevés des comptes (social_stats) : un relevé par jour et par réseau ;
 *   - Communication › Actions (posts) : chaque publication Facebook / Instagram, avec sa portée,
 *     ses vues, son engagement et ses clics ;
 *   - Ads Manager (campaigns, ad_results) : campagnes, budgets et résultats jour par jour ;
 *   - Leads & Contacts (contacts) : les leads des formulaires Lead Ads ;
 *   - Leads & Contacts › Questions (inquiries), au choix : les commentaires qui posent une question.
 * Chaque donnée importée est classée : canal, support (Reel, carrousel, story…), cible
 * (investisseurs / porteurs de projets), objectif, profil du lead, sujet de la question.
 *
 * Une donnée importée garde son identifiant Meta (colonne ext_id) : une synchronisation suivante la
 * met à jour (chiffres) sans jamais toucher à ce que l'équipe a complété (statut d'un lead,
 * commercial, objectif chiffré d'une campagne…). Une donnée importée puis supprimée n'est pas
 * réimportée (table ext_ignored).
 */
declare(strict_types=1);

$mkMetaDefaults = [
    'MK_META_APP_ID'        => '',  // sinon : réglé dans le tableau de bord, ou app Meta d'ecom.dehini.com
    'MK_META_APP_SECRET'    => '',
    'MK_META_GRAPH_URL'     => 'https://graph.facebook.com/', // tests : une fausse API peut la remplacer
    'MK_META_GRAPH_VERSION' => 'v24.0',
    'MK_META_ECOM_ENV'      => dirname(__DIR__, 3) . '/ecom/.env', // même compte cPanel : on reprend son app Meta
    'MK_META_KEY_FILE'      => dirname(MK_DB_PATH) . '/meta.key',  // clé de chiffrement des jetons, hors public_html
];
foreach ($mkMetaDefaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
unset($mkMetaDefaults);

/** Autorisations demandées à la connexion (lecture seule : rien n'est publié ni modifié chez Meta). */
const MK_META_SCOPES = [
    'pages_show_list', 'pages_read_engagement', 'pages_read_user_content', 'read_insights', 'business_management',
    'instagram_basic', 'instagram_manage_insights', 'instagram_manage_comments',
    'ads_read', 'leads_retrieval', 'pages_manage_ads',
];
/** Devises sans centimes pour l'API publicitaire (budgets envoyés en unités). */
const MK_META_ZERO_DECIMAL = ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'];
/** Objectif de campagne Meta → objectif du tableau de bord. */
const MK_META_OBJECTIVES = [
    'OUTCOME_AWARENESS' => 'notoriete', 'OUTCOME_TRAFFIC' => 'trafic', 'OUTCOME_ENGAGEMENT' => 'engagement',
    'OUTCOME_LEADS' => 'leads', 'OUTCOME_SALES' => 'conversions', 'OUTCOME_APP_PROMOTION' => 'inscriptions',
    'BRAND_AWARENESS' => 'notoriete', 'REACH' => 'notoriete', 'LINK_CLICKS' => 'trafic', 'POST_ENGAGEMENT' => 'engagement',
    'PAGE_LIKES' => 'engagement', 'VIDEO_VIEWS' => 'engagement', 'EVENT_RESPONSES' => 'engagement', 'MESSAGES' => 'messages',
    'LEAD_GENERATION' => 'leads', 'CONVERSIONS' => 'conversions', 'PRODUCT_CATALOG_SALES' => 'conversions', 'APP_INSTALLS' => 'inscriptions',
];
/** Indicateur suivi par défaut selon l'objectif. */
const MK_META_TARGET_METRIC = ['leads' => 'leads', 'messages' => 'leads', 'conversions' => 'conversions', 'inscriptions' => 'conversions', 'trafic' => 'clicks', 'notoriete' => 'impressions', 'engagement' => 'impressions'];
const MK_META_LEAD_ACTIONS = ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead', 'onsite_conversion.lead', 'leadgen_grouped'];
const MK_META_REG_ACTIONS = ['complete_registration', 'offsite_conversion.fb_pixel_complete_registration', 'omni_complete_registration'];
const MK_META_PURCHASE_ACTIONS = ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'];

final class MkMetaError extends RuntimeException
{
    public array $err;
    public function __construct(string $msg, array $err = [])
    {
        parent::__construct($msg);
        $this->err = $err;
    }
    public function metaCode(): int
    {
        return (int) ($this->err['code'] ?? 0);
    }
    /** Message lisible (Meta le donne souvent déjà en français). */
    public function userMessage(): string
    {
        $m = trim((string) ($this->err['error_user_msg'] ?? ''));
        if ($m !== '') return $m;
        $c = $this->metaCode();
        if ($c === 190) return 'La connexion à Meta a expiré ou a été révoquée : reconnectez-vous.';
        if (in_array($c, [4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004], true)) return 'Meta limite le nombre de requêtes pour le moment : la synchronisation reprendra au prochain passage.';
        if ($c === 10 || $c === 200 || ($c >= 200 && $c < 300)) return 'Autorisation manquante : ' . trim((string) ($this->err['message'] ?? '')) . ' Reconnectez-vous en acceptant toutes les autorisations demandées.';
        return trim((string) ($this->err['message'] ?? $this->getMessage()));
    }
}

/* ============================================================ état de la connexion */

/** Réglages et jetons de la connexion (clé « meta » de la table settings, jamais envoyée telle quelle au navigateur). */
function mk_meta_state(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $raw = qv('SELECT v FROM settings WHERE k = ?', ['meta']);
    $d = $raw ? json_decode((string) $raw, true) : [];
    $cache = array_merge([
        'app_id' => '', 'app_secret' => '', 'config_id' => '', 'user_token' => '', 'token_kind' => '', 'user_id' => '', 'user_name' => '',
        'token_expires' => 0, 'connected_at' => 0, 'page_id' => '', 'page_name' => '', 'page_token' => '', 'ig_id' => '',
        'ig_username' => '', 'ad_accounts' => [], 'fx' => 1.0, 'backfill' => 30, 'questions' => true,
        'default_profile' => 'investisseur', 'rules' => [], 'last_sync' => 0, 'last_until' => '', 'last_result' => null,
        'metrics' => [], 'oauth_error' => '', 'token_invalid' => false,
    ], is_array($d) ? $d : []);
    return $cache;
}

function mk_meta_put(array $patch): array
{
    $next = array_merge(mk_meta_state(true), $patch);
    db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['meta', json_encode($next, JSON_UNESCAPED_UNICODE)]);
    return mk_meta_state(true);
}

/** Clé de chiffrement des jetons (créée au premier usage, à côté de la base, hors public_html). */
function mk_meta_key(): string
{
    static $k = null;
    if ($k !== null) return $k;
    $f = MK_META_KEY_FILE;
    if (!is_file($f)) {
        $dir = dirname($f);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (@file_put_contents($f, base64_encode(random_bytes(32)), LOCK_EX) === false) throw new RuntimeException('Impossible de créer la clé de chiffrement : ' . $f);
        @chmod($f, 0600);
    }
    $raw = base64_decode(trim((string) @file_get_contents($f)), true);
    if ($raw === false || strlen($raw) !== 32) throw new RuntimeException('Clé de chiffrement illisible : ' . $f);
    return $k = $raw;
}
function mk_seal(string $plain): string
{
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', mk_meta_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}
function mk_unseal(string $box): string
{
    if (strncmp($box, 'v1:', 3) !== 0) return '';
    $raw = base64_decode(substr($box, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', mk_meta_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

/** App Meta d'ecom.dehini.com (fichier .env du même compte cPanel) : META_APP_ID et META_APP_SECRET seulement. */
function mk_meta_ecom_env(): array
{
    static $env = null;
    if ($env !== null) return $env;
    $env = [];
    $f = (string) MK_META_ECOM_ENV;
    if ($f === '' || !is_file($f) || !is_readable($f)) return $env;
    foreach ((array) @file($f, FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/^\s*(?:export\s+)?(META_APP_ID|META_APP_SECRET)\s*=\s*(.*)$/', (string) $line, $m)) continue;
        $v = trim($m[2]);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            $end = strpos($v, $v[0], 1);
            $v = $end === false ? substr($v, 1) : substr($v, 1, $end - 1);
        } else {
            $v = trim((string) preg_replace('/\s+#.*$/', '', $v));
        }
        $env[$m[1]] = $v;
    }
    return $env;
}

/** D'où vient l'app Meta : « dashboard » (Réglages), « config », « ecom » ou « » (aucune). */
function mk_meta_app_source(): string
{
    $st = mk_meta_state();
    if ($st['app_id'] !== '' && $st['app_secret'] !== '') return 'dashboard';
    if (MK_META_APP_ID !== '' && MK_META_APP_SECRET !== '') return 'config';
    $e = mk_meta_ecom_env();
    return ($e['META_APP_ID'] ?? '') !== '' && ($e['META_APP_SECRET'] ?? '') !== '' ? 'ecom' : '';
}
/** [ID, clé secrète] de l'app, toujours de la même source. */
function mk_meta_app(): array
{
    $st = mk_meta_state();
    switch (mk_meta_app_source()) {
        case 'dashboard': return [$st['app_id'], mk_unseal($st['app_secret'])];
        case 'config': return [(string) MK_META_APP_ID, (string) MK_META_APP_SECRET];
        case 'ecom': $e = mk_meta_ecom_env(); return [(string) $e['META_APP_ID'], (string) $e['META_APP_SECRET']];
    }
    return ['', ''];
}

function mk_meta_redirect_uri(): string
{
    $s = mk_settings();
    $base = $s['dashboard_url'] !== '' ? rtrim($s['dashboard_url'], '/') . '/' : mk_site_url();
    return $base . 'meta.php';
}

function mk_meta_connected(): bool
{
    $st = mk_meta_state();
    return $st['user_token'] !== '' || $st['page_token'] !== '';
}

/** Ce que le navigateur peut voir de la connexion (aucun jeton, aucune clé secrète). */
function mk_meta_public(): array
{
    $st = mk_meta_state();
    [$appId] = mk_meta_app();
    $exp = (int) $st['token_expires'];
    return [
        'connected' => mk_meta_connected(),
        'app_source' => mk_meta_app_source(), 'app_id' => $appId, 'config_id' => $st['config_id'],
        'app_url' => $appId !== '' ? 'https://developers.facebook.com/apps/' . rawurlencode($appId) . '/' : '',
        'redirect_uri' => mk_meta_redirect_uri(), 'scopes' => MK_META_SCOPES,
        'user_name' => $st['user_name'], 'token_kind' => $st['token_kind'], 'token_expires' => $exp,
        'days_left' => $exp ? (int) floor(($exp - time()) / 86400) : null, 'connected_at' => (int) $st['connected_at'],
        'page' => $st['page_id'] !== '' ? ['id' => $st['page_id'], 'name' => $st['page_name']] : null,
        'instagram' => $st['ig_id'] !== '' ? ['id' => $st['ig_id'], 'username' => $st['ig_username']] : null,
        'ad_accounts' => array_values($st['ad_accounts']),
        'fx' => (float) $st['fx'], 'backfill' => (int) $st['backfill'], 'questions' => (bool) $st['questions'],
        'default_profile' => $st['default_profile'],
        'rules' => mk_meta_rules(), 'rules_default' => mk_meta_default_rules(),
        'last_sync' => (int) $st['last_sync'], 'last_result' => $st['last_result'], 'oauth_error' => (string) $st['oauth_error'], 'token_invalid' => (bool) $st['token_invalid'],
        'currency' => mk_settings()['currency'],
    ];
}

/* ============================================================ appels à l'API Graph */

function mk_meta_http(string $url, array $query = [], ?array $post = null, int $timeout = 60): array
{
    if ($query) $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => ['Accept: application/json']];
        if ($post !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($post); }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) throw new MkMetaError('Meta ne répond pas (' . curl_error($ch) . '). Réessayez dans quelques minutes.');
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $body];
    }
    $http = ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"];
    if ($post !== null) { $http['method'] = 'POST'; $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n"; $http['content'] = http_build_query($post); }
    $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));
    if ($body === false) throw new MkMetaError('Meta ne répond pas. Réessayez dans quelques minutes.');
    $code = 0;
    foreach ($http_response_header ?? [] as $line) if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) $code = (int) $m[1];
    return [$code, $body];
}

/** Appel GET de l'API Graph. $token vide : jeton de l'utilisateur. */
function mk_graph(string $path, array $params = [], string $token = ''): array
{
    $st = mk_meta_state();
    if ($token === '') $token = mk_unseal($st['user_token']) ?: mk_unseal($st['page_token']);
    if ($token === '') throw new MkMetaError('Aucune connexion Meta : connectez-vous dans Connexions.', ['code' => 190]);
    $params['access_token'] = $token;
    [, $secret] = mk_meta_app();
    // Preuve de la clé secrète, pour les jetons obtenus avec notre app (un jeton collé peut venir d'une autre app).
    if ($secret !== '' && $st['token_kind'] === 'oauth') $params['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
    foreach ($params as $k => $v) if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $url = rtrim(MK_META_GRAPH_URL, '/') . '/' . MK_META_GRAPH_VERSION . '/' . ltrim($path, '/');
    [$code, $body] = mk_meta_http($url, $params);
    $d = json_decode($body, true);
    if (!is_array($d)) throw new MkMetaError('Réponse illisible de Meta (HTTP ' . $code . ').', ['code' => $code]);
    if (isset($d['error']) && is_array($d['error'])) throw new MkMetaError((string) ($d['error']['message'] ?? 'Erreur Meta'), $d['error']);
    return $d;
}

/** Toutes les pages d'une liste (curseur « after »), dans la limite de $max lignes. */
function mk_graph_all(string $path, array $params, string $token = '', int $max = 2000): array
{
    $rows = [];
    $params['limit'] = $params['limit'] ?? 100;
    for ($i = 0; $i < 50; $i++) {
        $d = mk_graph($path, $params, $token);
        foreach (($d['data'] ?? []) as $r) $rows[] = $r;
        $after = $d['paging']['cursors']['after'] ?? null;
        if (!$after || empty($d['paging']['next']) || count($rows) >= $max) break;
        $params['after'] = $after;
    }
    return $rows;
}

/** Erreur « indicateur inconnu » (indicateur retiré par Meta, ou pas disponible pour ce compte). */
function mk_meta_invalid_metric(MkMetaError $e): bool
{
    return $e->metaCode() === 100 || stripos($e->getMessage(), 'metric') !== false;
}

/* ============================================================ connexion */

function mk_meta_login_url(string $state): string
{
    [$appId] = mk_meta_app();
    if ($appId === '') throw new MkInputError("Renseignez d'abord l'app Meta (ID et clé secrète) dans Connexions.");
    $q = ['client_id' => $appId, 'redirect_uri' => mk_meta_redirect_uri(), 'state' => $state, 'response_type' => 'code'];
    // App « Facebook Login for Business » : les autorisations viennent de sa configuration (config_id).
    $cfg = mk_meta_state()['config_id'];
    if ($cfg !== '') {
        $q['config_id'] = $cfg;
        // Sans ce paramètre, Facebook Login for Business ignore response_type=code (page « Sorry, something went wrong »).
        $q['override_default_response_type'] = 'true';
    } else {
        $q['scope'] = implode(',', MK_META_SCOPES);
    }
    return 'https://www.facebook.com/' . MK_META_GRAPH_VERSION . '/dialog/oauth?' . http_build_query($q);
}

/** Code reçu de Facebook → jeton longue durée (≈ 60 jours), enregistré chiffré. */
function mk_meta_exchange(string $code): array
{
    [$appId, $secret] = mk_meta_app();
    $base = rtrim(MK_META_GRAPH_URL, '/') . '/' . MK_META_GRAPH_VERSION . '/oauth/access_token';
    [, $body] = mk_meta_http($base, ['client_id' => $appId, 'redirect_uri' => mk_meta_redirect_uri(), 'client_secret' => $secret, 'code' => $code]);
    $d = json_decode($body, true);
    if (empty($d['access_token'])) throw new MkMetaError('Facebook a refusé la connexion.', is_array($d['error'] ?? null) ? $d['error'] : []);
    [, $body] = mk_meta_http($base, ['grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $d['access_token']]);
    $l = json_decode($body, true);
    $tok = !empty($l['access_token']) ? (string) $l['access_token'] : (string) $d['access_token'];
    $exp = !empty($l['expires_in']) ? time() + (int) $l['expires_in'] : (!empty($d['expires_in']) ? time() + (int) $d['expires_in'] : 0);
    return mk_meta_store_user_token($tok, 'oauth', $exp);
}

/** Jeton collé à la main (utilisateur système du Business Manager : il n'expire pas). */
function mk_meta_use_token(string $token): array
{
    $token = trim($token);
    if (!preg_match('/^[A-Za-z0-9_\-|.]{20,600}$/', $token)) throw new MkInputError("Ce jeton n'a pas le bon format : copiez-le en entier depuis le Business Manager (Utilisateurs système → Générer un jeton).");
    return mk_meta_store_user_token($token, 'manual', 0);
}

function mk_meta_store_user_token(string $tok, string $kind, int $exp): array
{
    $prev = mk_meta_state();
    mk_meta_put(['user_token' => mk_seal($tok), 'token_kind' => $kind, 'token_expires' => $exp]);
    try {
        $me = mk_graph('me', ['fields' => 'id,name'], $tok);
    } catch (MkMetaError $e) {
        mk_meta_put(['user_token' => $prev['user_token'], 'token_kind' => $prev['token_kind'], 'token_expires' => $prev['token_expires']]);
        throw $e;
    }
    // Jeton collé : Meta indique sa date d'expiration s'il en a une (l'app doit être connue pour le demander).
    if ($kind === 'manual') {
        [$appId, $secret] = mk_meta_app();
        if ($appId !== '' && $secret !== '') {
            try {
                $dbg = mk_graph('debug_token', ['input_token' => $tok], $appId . '|' . $secret);
                $exp = (int) ($dbg['data']['expires_at'] ?? 0);
            } catch (MkMetaError $e) { /* sans importance */ }
        }
    }
    return mk_meta_put(['user_id' => (string) ($me['id'] ?? ''), 'user_name' => (string) ($me['name'] ?? ''), 'token_expires' => $exp, 'connected_at' => time(), 'token_invalid' => false, 'oauth_error' => '']);
}

/** Pages Facebook (avec le compte Instagram relié) et comptes publicitaires accessibles. */
function mk_meta_assets(): array
{
    $pages = [];
    foreach (mk_graph_all('me/accounts', ['fields' => 'id,name,category,tasks,instagram_business_account{id,username,followers_count}']) as $p) {
        $ig = $p['instagram_business_account'] ?? null;
        $pages[] = ['id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'category' => (string) ($p['category'] ?? ''),
            'instagram' => $ig ? ['id' => (string) $ig['id'], 'username' => (string) ($ig['username'] ?? ''), 'followers' => (int) ($ig['followers_count'] ?? 0)] : null];
    }
    $ads = [];
    try {
        foreach (mk_graph_all('me/adaccounts', ['fields' => 'id,account_id,name,currency,account_status,timezone_name']) as $a) {
            $ads[] = ['id' => (string) ($a['account_id'] ?? preg_replace('/^act_/', '', (string) $a['id'])), 'name' => (string) ($a['name'] ?? ''),
                'currency' => (string) ($a['currency'] ?? ''), 'active' => (int) ($a['account_status'] ?? 1) === 1, 'timezone' => (string) ($a['timezone_name'] ?? '')];
        }
    } catch (MkMetaError $e) {
        if ($e->metaCode() === 190) throw $e; // sinon : pas d'accès publicitaire, on continue sans
    }
    return ['pages' => $pages, 'ad_accounts' => $ads];
}

/** Enregistre la Page (et son Instagram), les comptes publicitaires et les options de synchronisation. */
function mk_meta_select(array $in): array
{
    $patch = [];
    if (array_key_exists('page_id', $in)) {
        $pid = (string) $in['page_id'];
        if ($pid === '') {
            $patch += ['page_id' => '', 'page_name' => '', 'page_token' => '', 'ig_id' => '', 'ig_username' => ''];
        } else {
            $found = null;
            foreach (mk_graph_all('me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}']) as $p) {
                if ((string) $p['id'] === $pid) $found = $p;
            }
            if (!$found) throw new MkInputError("Cette Page n'est plus accessible avec votre compte Facebook. Vérifiez vos accès dans Meta Business Suite.");
            $ig = $found['instagram_business_account'] ?? null;
            $patch += ['page_id' => $pid, 'page_name' => (string) ($found['name'] ?? ''), 'page_token' => mk_seal((string) ($found['access_token'] ?? '')),
                'ig_id' => $ig ? (string) $ig['id'] : '', 'ig_username' => $ig ? (string) ($ig['username'] ?? '') : ''];
        }
    }
    if (array_key_exists('ad_accounts', $in)) {
        $want = array_map(fn ($x) => preg_replace('/\D/', '', (string) $x), (array) $in['ad_accounts']);
        $list = [];
        foreach ($want ? mk_meta_assets()['ad_accounts'] : [] as $a) if (in_array($a['id'], $want, true)) $list[] = $a;
        $patch['ad_accounts'] = $list;
    }
    if (array_key_exists('fx', $in)) {
        $fx = (float) str_replace(',', '.', (string) $in['fx']);
        if ($fx <= 0 || $fx > 1000000) throw new MkInputError('Le taux de conversion doit être un nombre positif (ex. 1 dollar = 250 DA : tapez 250).');
        $patch['fx'] = round($fx, 4);
    }
    if (array_key_exists('backfill', $in)) $patch['backfill'] = max(7, min(90, (int) $in['backfill']));
    if (array_key_exists('questions', $in)) $patch['questions'] = (bool) $in['questions'];
    if (array_key_exists('default_profile', $in)) $patch['default_profile'] = array_key_exists((string) $in['default_profile'], mk_enums()['contact_types']) ? (string) $in['default_profile'] : 'investisseur';
    if (array_key_exists('rules', $in) && is_array($in['rules'])) $patch['rules'] = mk_meta_clean_rules($in['rules']);
    if (array_key_exists('config_id', $in)) {
        $cfg = preg_replace('/\D/', '', (string) $in['config_id']);
        if ($cfg !== '' && strlen($cfg) < 5) throw new MkInputError("L'ID de configuration est un long nombre : copiez-le depuis Facebook Login for Business › Configurations.");
        if ($cfg !== '' && $cfg === mk_meta_app()[0]) throw new MkInputError("Ce numéro est l'ID de l'app, pas celui de la configuration. Ouvrez Facebook Login for Business › Configurations, cliquez sur votre configuration et copiez l'ID affiché sous son nom.");
        $patch['config_id'] = $cfg;
    }
    if (array_key_exists('app_id', $in) || array_key_exists('app_secret', $in)) {
        $id = preg_replace('/\D/', '', (string) ($in['app_id'] ?? ''));
        $sec = trim((string) ($in['app_secret'] ?? ''));
        if ($id === '' && $sec === '') { $patch['app_id'] = ''; $patch['app_secret'] = ''; }
        else {
            if (strlen($id) < 5) throw new MkInputError("L'identifiant de l'app (App ID) est un nombre de 15 à 16 chiffres : copiez-le depuis developers.facebook.com › votre app › Paramètres › Général.");
            if ($sec !== '' && !preg_match('/^[a-f0-9]{32}$/i', $sec)) throw new MkInputError("La clé secrète de l'app compte 32 caractères (chiffres et lettres a à f) : cliquez sur « Afficher » à côté de « Clé secrète » puis copiez-la en entier.");
            if ($sec === '' && mk_meta_state()['app_secret'] === '') throw new MkInputError("Ajoutez aussi la clé secrète de l'app.");
            $patch['app_id'] = $id;
            if ($sec !== '') $patch['app_secret'] = mk_seal($sec);
        }
    }
    mk_meta_put($patch);
    return mk_meta_public();
}

function mk_meta_disconnect(): void
{
    $st = mk_meta_state();
    mk_meta_put(['user_token' => '', 'token_kind' => '', 'user_id' => '', 'user_name' => '', 'token_expires' => 0, 'connected_at' => 0,
        'page_id' => '', 'page_name' => '', 'page_token' => '', 'ig_id' => '', 'ig_username' => '', 'ad_accounts' => [],
        'app_id' => $st['app_id'], 'app_secret' => $st['app_secret']]);
}

/* ============================================================ classement automatique */

function mk_meta_default_rules(): array
{
    return [
        'audience' => [
            'investisseurs' => ['investir', 'investisseur', 'investissement', 'rendement', 'placement', 'placer votre argent', 'épargne', 'dividende', 'ticket', 'rentabilité', 'revenu passif', 'faire fructifier', 'استثمار', 'مستثمر', 'ربح'],
            'porteurs' => ['porteur', 'financer votre projet', 'financement de votre projet', 'projet à financer', 'lever des fonds', 'levée de fonds', 'entrepreneur', 'startup', 'start-up', 'créer votre entreprise', 'déposer votre projet', 'déposez votre projet', 'dépôt de projet', 'secteur', 'تمويل', 'مشروعك', 'مقاول'],
        ],
        'objective' => [
            'inscriptions' => ['inscri', 'créez votre compte', 'créer un compte', 'ouvrez votre compte', 'rejoignez', 'sign up', 'تسجيل', 'سجل'],
            'leads' => ['formulaire', 'remplissez', 'laissez vos coordonnées', 'demandez', 'être rappelé', 'rappel gratuit'],
            'messages' => ['écrivez-nous', 'contactez-nous', 'message privé', 'en mp', 'whatsapp', 'appelez', 'راسلونا'],
            'engagement' => ['live', 'webinaire', 'webinar', 'en direct', 'commentez', 'partagez', 'sondage', 'quiz', 'concours', 'jeu'],
            'trafic' => ['lien en bio', 'sur notre site', 'découvrez sur', 'cliquez', 'visitez', 'yinvesti.com', 'www.'],
        ],
        'question' => [
            'investissement' => ['investir', 'investissement', 'ticket', 'minimum', 'rendement', 'combien', 'gain', 'bénéfice', 'risque', 'dividende', 'placement', 'شحال', 'بكم', 'ربح', 'استثمار'],
            'projet' => ['financer', 'mon projet', 'financement', 'lever', 'porteur', 'dossier', 'تمويل', 'مشروع'],
            'plateforme' => ['inscription', 'inscrire', 'compte', 'application', 'appli', 'site', 'connexion', 'comment ça marche', 'fonctionne', 'halal', 'agrément', 'légal', 'تسجيل', 'كيفاش', 'حلال'],
            'partenariat' => ['partenariat', 'partenaire', 'collaboration', 'collaborer', 'sponsor', 'شراكة'],
            'reclamation' => ['problème', 'arnaque', 'rembourse', 'retard', 'plainte', 'bloqué', 'erreur', 'مشكل'],
        ],
    ];
}

/** Règles en vigueur : celles de l'équipe, sinon celles par défaut (groupe par groupe). */
function mk_meta_rules(): array
{
    $def = mk_meta_default_rules();
    $own = mk_meta_state()['rules'];
    foreach ($def as $g => $cats) {
        foreach ($cats as $c => $words) if (isset($own[$g][$c]) && is_array($own[$g][$c])) $def[$g][$c] = $own[$g][$c];
    }
    return $def;
}

function mk_meta_clean_rules(array $in): array
{
    $out = [];
    foreach (mk_meta_default_rules() as $g => $cats) {
        foreach ($cats as $c => $words) {
            if (!isset($in[$g][$c])) continue;
            $v = $in[$g][$c];
            $list = is_array($v) ? $v : preg_split('/[\r\n,;]+/', (string) $v);
            $clean = [];
            foreach ($list as $w) {
                $w = mb_strtolower(mk_str($w, 60));
                if ($w !== '' && !in_array($w, $clean, true)) $clean[] = $w;
            }
            $out[$g][$c] = array_slice($clean, 0, 80);
        }
    }
    return $out;
}

/** Texte sans accents ni majuscules, pour comparer des mots-clés. */
function mk_fold(string $s): string
{
    $s = mb_strtolower($s);
    $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ç' => 'c', '’' => "'"]);
    return (string) preg_replace('/\s+/u', ' ', $s);
}

/** Catégorie dont les mots-clés apparaissent le plus dans le texte ('' si aucun). */
function mk_classify(string $text, array $groups): string
{
    $t = mk_fold($text);
    if (trim($t) === '') return '';
    $best = '';
    $score = 0;
    foreach ($groups as $cat => $words) {
        $n = 0;
        foreach ($words as $w) {
            $w = mk_fold((string) $w);
            if ($w !== '' && mb_strpos($t, $w) !== false) $n++;
        }
        if ($n > $score) { $best = (string) $cat; $score = $n; }
    }
    return $best;
}

/** Cible d'un texte : investisseurs, porteurs, tous (les deux) ou ''. */
function mk_classify_audience(string $text): string
{
    $r = mk_meta_rules()['audience'];
    $t = mk_fold($text);
    $hit = fn (array $ws) => (bool) array_filter($ws, fn ($w) => ($w = mk_fold((string) $w)) !== '' && mb_strpos($t, $w) !== false);
    $i = $hit($r['investisseurs']);
    $p = $hit($r['porteurs']);
    return $i && $p ? 'tous' : ($i ? 'investisseurs' : ($p ? 'porteurs' : ''));
}

/** Profil d'un lead ou d'une question, déduit de la cible. */
function mk_classify_profile(string $text, string $default = ''): string
{
    $a = mk_classify_audience($text);
    return $a === 'investisseurs' ? 'investisseur' : ($a === 'porteurs' ? 'porteur' : $default);
}

/** Un commentaire pose-t-il une question ? (français, arabe, darija) */
function mk_is_question(string $text): bool
{
    $t = trim($text);
    if (mb_strlen($t) < 4) return false;
    if (preg_match('/^(@\S+\s*)+$/u', $t)) return false; // seulement des identifications
    if (strpos($t, '?') !== false || strpos($t, '؟') !== false) return true;
    $f = ' ' . mk_fold($t) . ' ';
    foreach ([' combien', ' comment ', ' pourquoi', ' quel ', ' quelle', ' quels ', ' est-ce', "c'est quoi", ' ou se', ' quand ', ' info', ' svp', "s'il vous plait", ' prix', ' tarif', 'شحال', 'كيفاش', 'واش', 'بكم', 'علاش', 'وين', 'وقتاش', 'كيف', ' هل ', 'معلومات'] as $w) {
        if (mb_strpos($f, $w) !== false) return true;
    }
    return false;
}

/* ============================================================ enregistrement des données importées */

/**
 * Crée ou met à jour la ligne importée $ext. À la mise à jour, seuls les champs $owned (ceux qui
 * viennent de Meta : chiffres, dates…) sont réécrits. $match : retrouve une ligne saisie à la main
 * qui correspond (même nom de campagne, même téléphone…) pour la relier au lieu de créer un doublon.
 * Renvoie added, updated, linked, same ou ignored.
 */
function mk_meta_upsert(string $entity, string $ext, array $data, array $owned, ?callable $match = null): string
{
    if (qv('SELECT 1 FROM ext_ignored WHERE ext_id = ?', [$ext])) return 'ignored';
    $ex = q1("SELECT * FROM $entity WHERE ext_id = ?", [$ext]);
    $linked = false;
    if (!$ex && $match) {
        $m = $match();
        if ($m) {
            db()->prepare("UPDATE $entity SET ext_id = ? WHERE id = ?")->execute([$ext, (int) $m['id']]);
            $ex = q1("SELECT * FROM $entity WHERE id = ?", [(int) $m['id']]);
            $linked = true;
            // Ligne saisie à la main reliée : ses champs vides sont complétés avec ce que Meta sait.
            foreach ($data as $k => $v) {
                if (!in_array($k, $owned, true) && ($ex[$k] === '' || $ex[$k] === null) && $v !== '' && $v !== null && isset(mk_schema()[$entity]['fields'][$k])) $owned[] = $k;
            }
        }
    }
    if ($ex) {
        if (!$owned) return $linked ? 'linked' : 'same';
        $row = mk_clean_row($entity, array_merge($ex, array_intersect_key($data, array_flip($owned))), $ex);
        $diff = [];
        foreach ($owned as $k) {
            $a = $row[$k];
            $b = $ex[$k];
            $same = is_numeric($a) && is_numeric($b) ? abs((float) $a - (float) $b) < 0.005 : (string) $a === (string) $b;
            if (!$same) $diff[$k] = $a;
        }
        if (!$diff) return $linked ? 'linked' : 'same';
        $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($diff)));
        db()->prepare("UPDATE $entity SET $set, updated = ? WHERE id = ?")->execute([...array_values($diff), time(), (int) $ex['id']]);
        return $linked ? 'linked' : 'updated';
    }
    $row = mk_clean_row($entity, $data);
    $row['ext_id'] = $ext;
    $now = time();
    $cols = implode(', ', array_keys($row));
    db()->prepare("INSERT INTO $entity ($cols, created, updated) VALUES (" . mk_in($row) . ', ?, ?)')->execute([...array_values($row), $now, $now]);
    return 'added';
}

/** Date locale (fuseau du tableau de bord) d'un horodatage Meta. */
function mk_meta_date(string $iso): string
{
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(MK_TIMEZONE))->format('Y-m-d');
    } catch (Throwable $e) {
        return mk_today();
    }
}

function mk_meta_has_channel(string $key): bool
{
    foreach (mk_channels() as $c) if ($c['key'] === $key) return true;
    return false;
}
/** Support s'il existe dans Canaux & supports, sinon le premier support proposé par le canal. */
function mk_meta_support(string $want, string $channel): string
{
    $all = array_column(mk_supports(), 'key');
    if (in_array($want, $all, true)) return $want;
    foreach (mk_channels() as $c) if ($c['key'] === $channel) foreach ($c['supports'] as $s) if (in_array($s, $all, true)) return $s;
    return $all[0] ?? '';
}
function mk_meta_topic(string $text, string $fallback): string
{
    $first = explode("\n", trim($text))[0] ?? '';
    $line = trim((string) preg_replace('/\s+/u', ' ', $first));
    if ($line === '') return $fallback;
    return mb_strlen($line) > 150 ? rtrim(mb_substr($line, 0, 147)) . '…' : $line;
}
function mk_meta_fdate(string $d): string
{
    return function_exists('mk_fdate') ? mk_fdate($d, false) : $d;
}
function mk_meta_objective(string $text): string
{
    return mk_classify($text, mk_meta_rules()['objective']) ?: 'notoriete';
}

/* ============================================================ synchronisation */

/**
 * Récupère les données de Meta depuis la dernière synchronisation (moins 3 jours, le temps que
 * Meta consolide ses chiffres) ou, la première fois, sur les $backfill derniers jours.
 * $opt : days (forcer le nombre de jours), only (liste d'étapes).
 */
function mk_meta_sync(array $opt = []): array
{
    if (!mk_meta_connected()) throw new MkInputError("Connectez d'abord votre compte Meta (Connexions).");
    $lock = @fopen(dirname(MK_DB_PATH) . '/meta-sync.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) throw new MkInputError('Une synchronisation est déjà en cours : patientez une minute.');
    @set_time_limit(600);
    $st = mk_meta_state(true);
    $to = mk_today();
    $days = (int) ($opt['days'] ?? 0);
    if ($days > 0) $from = mk_add_days($to, -min(90, $days) + 1);
    elseif ($st['last_until'] !== '' && mk_valid_date($st['last_until'])) $from = max(mk_add_days($to, -89), min(mk_add_days($st['last_until'], -3), mk_add_days($to, -3)));
    else $from = mk_add_days($to, -max(7, min(90, (int) $st['backfill'])) + 1);
    $only = (array) ($opt['only'] ?? []);
    $steps = [
        'fb_stats' => ['Relevés de la Page Facebook', 'mk_meta_sync_fb_stats', $st['page_id'] !== ''],
        'ig_stats' => ['Relevés du compte Instagram', 'mk_meta_sync_ig_stats', $st['ig_id'] !== ''],
        'fb_posts' => ['Publications Facebook', 'mk_meta_sync_fb_posts', $st['page_id'] !== ''],
        'ig_posts' => ['Publications Instagram', 'mk_meta_sync_ig_posts', $st['ig_id'] !== ''],
        'ads' => ['Campagnes Meta Ads', 'mk_meta_sync_ads', (bool) $st['ad_accounts']],
        'leads' => ['Leads des formulaires', 'mk_meta_sync_leads', $st['page_id'] !== ''],
    ];
    $out = ['at' => time(), 'from' => $from, 'to' => $to, 'steps' => [], 'ok' => true, 'totals' => ['added' => 0, 'updated' => 0]];
    foreach ($steps as $key => [$label, $fn, $on]) {
        if ($only && !in_array($key, $only, true)) continue;
        $res = ['key' => $key, 'label' => $label, 'status' => 'skip', 'counts' => [], 'message' => ''];
        if (!$on) {
            $res['message'] = $key === 'ads' ? 'Aucun compte publicitaire choisi.' : ($key === 'ig_stats' || $key === 'ig_posts' ? "Aucun compte Instagram professionnel n'est relié à la Page." : 'Aucune Page choisie.');
        } else {
            try {
                $res['counts'] = $fn($from, $to);
                $res['status'] = 'ok';
                foreach ($res['counts'] as $c) { $out['totals']['added'] += (int) ($c['added'] ?? 0); $out['totals']['updated'] += (int) ($c['updated'] ?? 0); }
                $notes = array_filter(array_map(fn ($c) => $c['note'] ?? '', $res['counts']));
                $res['message'] = implode(' ', $notes);
            } catch (MkMetaError $e) {
                $res['status'] = 'error';
                $res['message'] = $e->userMessage();
                $out['ok'] = false;
                if ($e->metaCode() === 190) { $out['steps'][] = $res; $out['token_invalid'] = true; break; } // jeton expiré : inutile de continuer
            } catch (MkInputError $e) {
                $res['status'] = 'error';
                $res['message'] = $e->getMessage();
                $out['ok'] = false;
            } catch (Throwable $e) {
                error_log('[marketing/meta] ' . $key . ' : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $res['status'] = 'error';
                $res['message'] = 'Erreur inattendue (' . $e->getMessage() . ').';
                $out['ok'] = false;
            }
        }
        $out['steps'][] = $res;
    }
    $patch = ['last_sync' => $out['at'], 'last_result' => $out, 'token_invalid' => !empty($out['token_invalid'])];
    if ($out['ok'] && !$only) $patch['last_until'] = $to;
    mk_meta_put($patch);
    db()->prepare('INSERT INTO meta_sync_log (at, date_from, date_to, ok, added, updated, result) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$out['at'], $from, $to, $out['ok'] ? 1 : 0, $out['totals']['added'], $out['totals']['updated'], json_encode($out, JSON_UNESCAPED_UNICODE)]);
    db()->exec('DELETE FROM meta_sync_log WHERE id NOT IN (SELECT id FROM meta_sync_log ORDER BY id DESC LIMIT 60)');
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $out;
}

/** Compteur d'une étape : ['label' => …, 'added' => n, 'updated' => n, …]. */
function mk_meta_counter(string $label): array
{
    return ['label' => $label, 'added' => 0, 'updated' => 0, 'linked' => 0, 'same' => 0, 'ignored' => 0, 'errors' => 0, 'note' => ''];
}
function mk_meta_count(array &$c, string $res): void
{
    $c[$res] = ($c[$res] ?? 0) + 1;
}

/** Fenêtres de $n jours au plus entre deux dates (limites de l'API). */
function mk_meta_windows(string $from, string $to, int $n): array
{
    $out = [];
    for ($a = $from; $a <= $to; $a = mk_add_days($a, $n)) $out[] = [$a, min($to, mk_add_days($a, $n - 1))];
    return $out;
}

/**
 * Série jour par jour d'un indicateur de Page : essaie chaque nom proposé (Meta en retire
 * régulièrement : « impressions » remplacées par « vues » en 2025) et garde le premier qui marche.
 * Renvoie [date => valeur].
 */
function mk_meta_page_series(string $pageId, array $names, string $from, string $to, string $token): array
{
    $st = mk_meta_state();
    $cacheKey = 'page:' . implode('|', $names);
    $known = $st['metrics'][$cacheKey] ?? null;
    $try = $known ? array_merge([$known], array_diff($names, [$known])) : $names;
    foreach ($try as $name) {
        try {
            $vals = [];
            foreach (mk_meta_windows($from, $to, 90) as [$a, $b]) {
                $d = mk_graph("$pageId/insights", ['metric' => $name, 'period' => 'day', 'since' => $a, 'until' => mk_add_days($b, 1)], $token);
                foreach (($d['data'][0]['values'] ?? []) as $v) {
                    $day = mk_add_days(substr((string) ($v['end_time'] ?? ''), 0, 10), -1);
                    if (mk_valid_date($day) && $day >= $from && $day <= $to) $vals[$day] = is_array($v['value'] ?? null) ? array_sum($v['value']) : (int) ($v['value'] ?? 0);
                }
            }
            if ($known !== $name) mk_meta_put(['metrics' => array_merge($st['metrics'], [$cacheKey => $name])]);
            return $vals;
        } catch (MkMetaError $e) {
            if (!mk_meta_invalid_metric($e)) throw $e;
        }
    }
    return [];
}

/** Abonnés jour par jour : total actuel, puis on remonte le temps avec les nouveaux abonnés de chaque jour. */
function mk_meta_followers_back(int $now, array $newByDay, string $from, string $to): array
{
    $out = [];
    $f = $now;
    for ($d = $to; $d >= $from; $d = mk_add_days($d, -1)) {
        $out[$d] = max(0, $f);
        $f -= (int) ($newByDay[$d] ?? 0);
    }
    return $out;
}

function mk_meta_sync_fb_stats(string $from, string $to): array
{
    $st = mk_meta_state();
    if (!mk_meta_has_channel('facebook')) throw new MkInputError('Le canal « Facebook » a été retiré de Canaux & supports : ajoutez-le de nouveau (catégorie Réseaux sociaux) pour importer ses chiffres.');
    $tok = mk_unseal($st['page_token']);
    $pid = $st['page_id'];
    $c = mk_meta_counter('relevés');
    $reach = mk_meta_page_series($pid, ['page_total_media_view_unique', 'page_impressions_unique'], $from, $to, $tok);
    $views = mk_meta_page_series($pid, ['page_media_view', 'page_impressions'], $from, $to, $tok);
    $eng = mk_meta_page_series($pid, ['page_post_engagements'], $from, $to, $tok);
    $new = mk_meta_page_series($pid, ['page_daily_follows_unique', 'page_fan_adds_unique'], $from, $to, $tok);
    $clicks = mk_meta_page_series($pid, ['page_total_actions'], $from, $to, $tok);
    $total = mk_meta_page_series($pid, ['page_follows', 'page_fans'], $from, $to, $tok);
    if (!$total) {
        $p = mk_graph($pid, ['fields' => 'followers_count,fan_count'], $tok);
        $total = mk_meta_followers_back((int) ($p['followers_count'] ?? $p['fan_count'] ?? 0), $new, $from, $to);
    }
    for ($d = $from; $d <= $to; $d = mk_add_days($d, 1)) {
        if (!isset($reach[$d]) && !isset($views[$d]) && !isset($eng[$d]) && !isset($new[$d])) continue;
        mk_meta_count($c, mk_meta_upsert('social_stats', "meta:fb:$pid:$d", [
            'date' => $d, 'platform' => 'facebook', 'followers' => (int) ($total[$d] ?? 0), 'new_followers' => (int) ($new[$d] ?? 0),
            'reach' => (int) ($reach[$d] ?? 0), 'impressions' => (int) ($views[$d] ?? 0), 'engagement' => (int) ($eng[$d] ?? 0), 'clicks' => (int) ($clicks[$d] ?? 0),
        ], ['followers', 'new_followers', 'reach', 'impressions', 'engagement', 'clicks']));
    }
    return [$c];
}

function mk_meta_sync_ig_stats(string $from, string $to): array
{
    $st = mk_meta_state();
    if (!mk_meta_has_channel('instagram')) throw new MkInputError('Le canal « Instagram » a été retiré de Canaux & supports : ajoutez-le de nouveau (catégorie Réseaux sociaux) pour importer ses chiffres.');
    $tok = mk_unseal($st['page_token']) ?: '';
    $ig = $st['ig_id'];
    $c = mk_meta_counter('relevés');
    // Séries jour par jour (fenêtres de 30 jours au plus) : portée et nouveaux abonnés.
    $series = function (string $metric) use ($ig, $from, $to, $tok): array {
        $vals = [];
        try {
            foreach (mk_meta_windows($from, $to, 30) as [$a, $b]) {
                $d = mk_graph("$ig/insights", ['metric' => $metric, 'period' => 'day', 'since' => $a, 'until' => mk_add_days($b, 1)], $tok);
                foreach (($d['data'][0]['values'] ?? []) as $v) {
                    $day = mk_add_days(substr((string) ($v['end_time'] ?? ''), 0, 10), -1);
                    if (mk_valid_date($day) && $day >= $from && $day <= $to) $vals[$day] = (int) ($v['value'] ?? 0);
                }
            }
        } catch (MkMetaError $e) {
            if (!mk_meta_invalid_metric($e)) throw $e;
        }
        return $vals;
    };
    $reach = $series('reach');
    $new = $series('follower_count'); // indisponible sous 100 abonnés : reste vide
    // Totaux d'une journée : vues, interactions, clics vers le site (noms actuels d'abord, anciens ensuite).
    $want = ['impressions' => ['views', 'impressions'], 'engagement' => ['total_interactions', 'accounts_engaged'], 'clicks' => ['website_clicks', 'profile_links_taps']];
    $use = [];
    $probe = mk_add_days($to, -1) >= $from ? mk_add_days($to, -1) : $to;
    foreach ($want as $k => $names) {
        foreach ($names as $n) {
            try {
                mk_graph("$ig/insights", ['metric' => $n, 'metric_type' => 'total_value', 'period' => 'day', 'since' => $probe, 'until' => mk_add_days($probe, 1)], $tok);
                $use[$k] = $n;
                break;
            } catch (MkMetaError $e) {
                if (!mk_meta_invalid_metric($e)) throw $e;
            }
        }
    }
    $tot = [];
    if ($use) {
        for ($d = $from; $d <= $to; $d = mk_add_days($d, 1)) {
            $r = mk_graph("$ig/insights", ['metric' => implode(',', array_values($use)), 'metric_type' => 'total_value', 'period' => 'day', 'since' => $d, 'until' => mk_add_days($d, 1)], $tok);
            foreach (($r['data'] ?? []) as $m) {
                $k = array_search((string) ($m['name'] ?? ''), $use, true);
                if ($k !== false) $tot[$d][$k] = (int) ($m['total_value']['value'] ?? 0);
            }
        }
    }
    $acc = mk_graph($ig, ['fields' => 'followers_count,username'], $tok);
    $followers = mk_meta_followers_back((int) ($acc['followers_count'] ?? 0), $new, $from, $to);
    for ($d = $from; $d <= $to; $d = mk_add_days($d, 1)) {
        if (!isset($reach[$d]) && !isset($tot[$d]) && !isset($new[$d])) continue;
        mk_meta_count($c, mk_meta_upsert('social_stats', "meta:ig:$ig:$d", [
            'date' => $d, 'platform' => 'instagram', 'followers' => (int) $followers[$d], 'new_followers' => (int) ($new[$d] ?? 0),
            'reach' => (int) ($reach[$d] ?? 0), 'impressions' => (int) ($tot[$d]['impressions'] ?? 0), 'engagement' => (int) ($tot[$d]['engagement'] ?? 0), 'clicks' => (int) ($tot[$d]['clicks'] ?? 0),
        ], ['followers', 'new_followers', 'reach', 'impressions', 'engagement', 'clicks']));
    }
    return [$c];
}

/**
 * Indicateurs d'une publication (portée, vues, clics) : les noms qui marchent sont cherchés une
 * fois, sur la première publication, puis demandés ensemble pour les suivantes.
 */
function mk_meta_post_insights(string $id, array $want, string $token, array &$use): array
{
    if ($use === []) {
        foreach ($want as $k => $names) {
            foreach ($names as $n) {
                try {
                    mk_graph("$id/insights", ['metric' => $n], $token);
                    $use[$k] = $n;
                    break;
                } catch (MkMetaError $e) {
                    if (!mk_meta_invalid_metric($e)) throw $e;
                }
            }
        }
        if (!$use) $use = ['_none' => ''];
    }
    $names = array_filter($use);
    if (!$names) return [];
    try {
        $d = mk_graph("$id/insights", ['metric' => implode(',', $names)], $token);
    } catch (MkMetaError $e) {
        if (mk_meta_invalid_metric($e)) return []; // ex. story expirée, publication partagée
        throw $e;
    }
    $out = [];
    foreach (($d['data'] ?? []) as $m) {
        $k = array_search((string) ($m['name'] ?? ''), $use, true);
        if ($k === false) continue;
        $v = $m['values'][0]['value'] ?? ($m['total_value']['value'] ?? 0);
        $out[$k] = is_array($v) ? array_sum($v) : (int) $v;
    }
    return $out;
}

function mk_meta_sync_fb_posts(string $from, string $to): array
{
    $st = mk_meta_state();
    if (!mk_meta_has_channel('facebook')) throw new MkInputError('Le canal « Facebook » a été retiré de Canaux & supports : ajoutez-le de nouveau pour importer les publications.');
    $tok = mk_unseal($st['page_token']);
    $pid = $st['page_id'];
    $since = min($from, mk_add_days($to, -13)); // les chiffres d'une publication évoluent encore 2 semaines
    $posts = mk_graph_all("$pid/published_posts", [
        'fields' => 'id,message,story,created_time,permalink_url,status_type,attachments{media_type,type},shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
        'since' => strtotime($since . ' 00:00:00'), 'until' => strtotime($to . ' 23:59:59'),
    ], $tok, 500);
    $c = mk_meta_counter('publications');
    $q = mk_meta_counter('questions');
    $use = [];
    foreach ($posts as $p) {
        $text = (string) ($p['message'] ?? $p['story'] ?? '');
        $type = (string) ($p['attachments']['data'][0]['media_type'] ?? $p['attachments']['data'][0]['type'] ?? '');
        $status = (string) ($p['status_type'] ?? '');
        $link = (string) ($p['permalink_url'] ?? '');
        $fmt = $status === 'added_live_video' ? 'live' : ($type === 'album' ? 'carrousel' : ($type === 'video' || strpos($link, '/reel/') !== false || strpos($link, '/videos/') !== false ? 'reel' : 'post'));
        $date = mk_meta_date((string) $p['created_time']);
        $ins = mk_meta_post_insights((string) $p['id'], ['reach' => ['post_total_media_view_unique', 'post_impressions_unique'], 'impressions' => ['post_media_view', 'post_impressions'], 'clicks' => ['post_clicks']], $tok, $use);
        $eng = (int) ($p['reactions']['summary']['total_count'] ?? 0) + (int) ($p['comments']['summary']['total_count'] ?? 0) + (int) ($p['shares']['count'] ?? 0);
        try {
            mk_meta_count($c, mk_meta_upsert('posts', 'meta:fbpost:' . $p['id'], [
                'date' => $date, 'platform' => 'facebook', 'format' => mk_meta_support($fmt, 'facebook'),
                'topic' => mk_meta_topic($text, 'Publication Facebook du ' . mk_meta_fdate($date)), 'audience' => mk_classify_audience($text), 'objective' => mk_meta_objective($text),
                'link' => $link, 'reach' => (int) ($ins['reach'] ?? 0), 'impressions' => (int) ($ins['impressions'] ?? 0), 'engagement' => $eng, 'clicks' => (int) ($ins['clicks'] ?? 0),
            ], ['reach', 'impressions', 'engagement', 'clicks', 'link']));
        } catch (MkInputError $e) {
            $c['errors']++;
            $c['note'] = $e->getMessage();
        }
        if ($st['questions'] && (int) ($p['comments']['summary']['total_count'] ?? 0) > 0 && $date >= $from) {
            foreach (mk_graph_all($p['id'] . '/comments', ['fields' => 'id,message,created_time,from{id,name}', 'filter' => 'toplevel'], $tok, 200) as $cm) {
                if ((string) ($cm['from']['id'] ?? '') === $pid) continue; // réponse de la Page elle-même
                mk_meta_question($q, 'facebook', (string) $cm['id'], (string) ($cm['message'] ?? ''), (string) ($cm['created_time'] ?? ''), (string) ($cm['from']['name'] ?? ''), '');
            }
        }
    }
    return $st['questions'] ? [$c, $q] : [$c];
}

function mk_meta_sync_ig_posts(string $from, string $to): array
{
    $st = mk_meta_state();
    if (!mk_meta_has_channel('instagram')) throw new MkInputError('Le canal « Instagram » a été retiré de Canaux & supports : ajoutez-le de nouveau pour importer les publications.');
    $tok = mk_unseal($st['page_token']) ?: '';
    $ig = $st['ig_id'];
    $since = min($from, mk_add_days($to, -13));
    $fields = 'id,caption,media_type,media_product_type,timestamp,permalink,like_count,comments_count';
    $media = [];
    foreach (mk_graph_all("$ig/media", ['fields' => $fields, 'since' => strtotime($since . ' 00:00:00')], $tok, 500) as $m) {
        if (mk_meta_date((string) ($m['timestamp'] ?? '')) >= $since) $media[] = $m;
    }
    try {
        foreach (mk_graph_all("$ig/stories", ['fields' => $fields], $tok, 100) as $m) $media[] = $m;
    } catch (MkMetaError $e) {
        if ($e->metaCode() === 190) throw $e;
    }
    $c = mk_meta_counter('publications');
    $q = mk_meta_counter('questions');
    $useFeed = [];
    $useStory = [];
    foreach ($media as $m) {
        $mpt = strtoupper((string) ($m['media_product_type'] ?? 'FEED'));
        if ($mpt === 'AD') continue; // publicité : comptée dans Ads Manager
        $mt = strtoupper((string) ($m['media_type'] ?? 'IMAGE'));
        $fmt = $mpt === 'REELS' ? 'reel' : ($mpt === 'STORY' ? 'story' : ($mt === 'CAROUSEL_ALBUM' ? 'carrousel' : ($mt === 'VIDEO' ? 'reel' : 'post')));
        $text = (string) ($m['caption'] ?? '');
        $date = mk_meta_date((string) ($m['timestamp'] ?? ''));
        $want = ['reach' => ['reach'], 'impressions' => ['views', 'impressions', 'plays'], 'engagement' => ['total_interactions'], 'shares' => ['shares'], 'saved' => ['saved']];
        if ($mpt === 'STORY') $ins = mk_meta_post_insights((string) $m['id'], ['reach' => ['reach'], 'impressions' => ['views', 'impressions'], 'engagement' => ['total_interactions', 'replies']], $tok, $useStory);
        else $ins = mk_meta_post_insights((string) $m['id'], $want, $tok, $useFeed);
        $eng = isset($ins['engagement']) ? (int) $ins['engagement'] : (int) ($m['like_count'] ?? 0) + (int) ($m['comments_count'] ?? 0) + (int) ($ins['shares'] ?? 0) + (int) ($ins['saved'] ?? 0);
        try {
            mk_meta_count($c, mk_meta_upsert('posts', 'meta:igmedia:' . $m['id'], [
                'date' => $date, 'platform' => 'instagram', 'format' => mk_meta_support($fmt, 'instagram'),
                'topic' => mk_meta_topic($text, ($fmt === 'story' ? 'Story' : 'Publication') . ' Instagram du ' . mk_meta_fdate($date)), 'audience' => mk_classify_audience($text), 'objective' => mk_meta_objective($text),
                'link' => (string) ($m['permalink'] ?? ''), 'reach' => (int) ($ins['reach'] ?? 0), 'impressions' => (int) ($ins['impressions'] ?? 0), 'engagement' => $eng, 'clicks' => 0,
            ], ['reach', 'impressions', 'engagement', 'link']));
        } catch (MkInputError $e) {
            $c['errors']++;
            $c['note'] = $e->getMessage();
        }
        if ($st['questions'] && (int) ($m['comments_count'] ?? 0) > 0 && $date >= $from) {
            foreach (mk_graph_all($m['id'] . '/comments', ['fields' => 'id,text,timestamp,username'], $tok, 200) as $cm) {
                if (strcasecmp((string) ($cm['username'] ?? ''), $st['ig_username']) === 0) continue;
                mk_meta_question($q, 'instagram', (string) $cm['id'], (string) ($cm['text'] ?? ''), (string) ($cm['timestamp'] ?? ''), '', (string) ($cm['username'] ?? ''));
            }
        }
    }
    return $st['questions'] ? [$c, $q] : [$c];
}

/** Un commentaire qui pose une question devient une question à traiter (sujet et profil devinés). */
function mk_meta_question(array &$q, string $channel, string $id, string $text, string $when, string $name, string $username): void
{
    if (!mk_is_question($text)) return;
    $st = mk_meta_state();
    try {
        mk_meta_count($q, mk_meta_upsert('inquiries', "meta:cmt:$id", [
            'date' => mk_meta_date($when), 'source' => $channel, 'name' => $name !== '' ? $name : ($username !== '' ? '@' . $username : ''),
            'contact' => $username !== '' ? '@' . $username . ' (Instagram)' : 'Commentaire Facebook', 'type' => mk_classify_profile($text),
            'category' => mk_classify($text, mk_meta_rules()['question']) ?: 'autre', 'question' => mk_str($text, 2000) ?: '—', 'status' => 'nouveau',
        ], []));
    } catch (MkInputError $e) {
        $q['errors']++;
        $q['note'] = $e->getMessage();
    }
}

/** Plus grande valeur parmi des types d'actions équivalents (Meta renvoie souvent le même chiffre sous plusieurs noms). */
function mk_meta_action(array $actions, array $types): int
{
    $best = 0;
    foreach ($actions as $a) if (in_array((string) ($a['action_type'] ?? ''), $types, true)) $best = max($best, (int) round((float) ($a['value'] ?? 0)));
    return $best;
}

function mk_meta_sync_ads(string $from, string $to): array
{
    $st = mk_meta_state();
    $channel = mk_meta_has_channel('meta_ads') ? 'meta_ads' : (array_key_first(mk_enums()['ad_channels']) ?? '');
    if ($channel === '') throw new MkInputError('Aucun canal dans la catégorie « Publicité digitale » : ajoutez « Meta Ads » dans Canaux & supports.');
    $fx = (float) $st['fx'] > 0 ? (float) $st['fx'] : 1.0;
    $cc = mk_meta_counter('campagnes');
    $rc = mk_meta_counter('jours de résultats');
    $notes = [];
    foreach ($st['ad_accounts'] as $acc) {
        $act = 'act_' . $acc['id'];
        $offset = in_array(strtoupper((string) $acc['currency']), MK_META_ZERO_DECIMAL, true) ? 1 : 100;
        $camps = mk_graph_all("$act/campaigns", ['fields' => 'id,name,objective,status,effective_status,daily_budget,lifetime_budget,start_time,stop_time,created_time', 'limit' => 200], '', 1000);
        // Budget au niveau des ensembles de publicités quand la campagne n'en a pas.
        $adsetBudget = [];
        if (array_filter($camps, fn ($x) => empty($x['daily_budget']) && empty($x['lifetime_budget']))) {
            foreach (mk_graph_all("$act/adsets", ['fields' => 'campaign_id,daily_budget,lifetime_budget,effective_status', 'limit' => 500], '', 3000) as $s) {
                if (in_array((string) ($s['effective_status'] ?? ''), ['DELETED', 'ARCHIVED'], true)) continue;
                $k = (string) $s['campaign_id'];
                $adsetBudget[$k]['daily'] = ($adsetBudget[$k]['daily'] ?? 0) + (int) ($s['daily_budget'] ?? 0);
                $adsetBudget[$k]['lifetime'] = ($adsetBudget[$k]['lifetime'] ?? 0) + (int) ($s['lifetime_budget'] ?? 0);
            }
        }
        foreach ($camps as $cp) {
            $eff = strtoupper((string) ($cp['effective_status'] ?? $cp['status'] ?? ''));
            if ($eff === 'DELETED') continue;
            $start = mk_meta_date((string) ($cp['start_time'] ?? $cp['created_time'] ?? ''));
            $end = !empty($cp['stop_time']) ? mk_meta_date((string) $cp['stop_time']) : '';
            if ($end !== '' && $end < $start) $end = $start;
            // Campagne terminée avant la période et jamais importée : inutile de l'ajouter.
            if ($end !== '' && $end < mk_add_days($from, -60) && !qv('SELECT 1 FROM campaigns WHERE ext_id = ?', ['meta:camp:' . $cp['id']])) continue;
            $status = in_array($eff, ['ACTIVE', 'IN_PROCESS', 'WITH_ISSUES', 'PENDING_REVIEW', 'PREAPPROVED'], true) ? 'active' : (in_array($eff, ['ARCHIVED', 'COMPLETED'], true) ? 'terminee' : 'pause');
            if ($end !== '' && $end < mk_today()) $status = 'terminee';
            $daily = (int) ($cp['daily_budget'] ?? 0) ?: (int) ($adsetBudget[(string) $cp['id']]['daily'] ?? 0);
            $life = (int) ($cp['lifetime_budget'] ?? 0) ?: (int) ($adsetBudget[(string) $cp['id']]['lifetime'] ?? 0);
            $days = $end !== '' ? max(1, (int) ((strtotime($end) - strtotime($start)) / 86400) + 1) : 30;
            $budget = $life > 0 ? $life / $offset : ($daily > 0 ? $daily / $offset * $days : 0);
            $obj = MK_META_OBJECTIVES[strtoupper((string) ($cp['objective'] ?? ''))] ?? 'leads';
            $name = mk_str((string) ($cp['name'] ?? 'Campagne Meta'), 200);
            $note = 'Importée de Meta Ads (compte ' . $acc['name'] . ')' . ($life <= 0 && $daily > 0 ? ' · budget quotidien ' . round($daily / $offset, 2) . ' ' . $acc['currency'] . ($end === '' ? ', compté sur 30 jours' : '') : '');
            try {
                mk_meta_count($cc, mk_meta_upsert('campaigns', 'meta:camp:' . $cp['id'], [
                    'name' => $name, 'platform' => $channel, 'objective' => $obj, 'audience' => mk_classify_audience($name),
                    'target_metric' => MK_META_TARGET_METRIC[$obj] ?? 'leads', 'target_value' => 0, 'budget' => round($budget * $fx, 2),
                    'start' => $start, 'end' => $end, 'status' => $status, 'notes' => $note,
                ], ['name', 'budget', 'start', 'end', 'status'], function () use ($name) {
                    return q1("SELECT id FROM campaigns WHERE ext_id = '' AND lower(name) = lower(?)", [$name]);
                }));
            } catch (MkInputError $e) {
                $cc['errors']++;
                $cc['note'] = $e->getMessage();
            }
        }
        $rows = mk_graph_all("$act/insights", [
            'level' => 'campaign', 'time_increment' => 1, 'time_range' => ['since' => $from, 'until' => $to],
            'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,inline_link_clicks,actions', 'limit' => 500,
        ], '', 10000);
        foreach ($rows as $r) {
            $cid = (int) qv('SELECT id FROM campaigns WHERE ext_id = ?', ['meta:camp:' . ($r['campaign_id'] ?? '')]);
            if (!$cid) continue;
            $acts = is_array($r['actions'] ?? null) ? $r['actions'] : [];
            $date = (string) ($r['date_start'] ?? '');
            if (!mk_valid_date($date)) continue;
            try {
                mk_meta_count($rc, mk_meta_upsert('ad_results', 'meta:ins:' . $r['campaign_id'] . ':' . $date, [
                    'date' => $date, 'campaign_id' => $cid, 'spend' => round((float) ($r['spend'] ?? 0) * $fx, 2),
                    'impressions' => (int) ($r['impressions'] ?? 0), 'clicks' => (int) ($r['inline_link_clicks'] ?? $r['clicks'] ?? 0),
                    'leads' => mk_meta_action($acts, MK_META_LEAD_ACTIONS),
                    'conversions' => mk_meta_action($acts, MK_META_REG_ACTIONS) + mk_meta_action($acts, MK_META_PURCHASE_ACTIONS),
                ], ['spend', 'impressions', 'clicks', 'leads', 'conversions']));
            } catch (MkInputError $e) {
                $rc['errors']++;
                $rc['note'] = $e->getMessage();
            }
        }
        if (strtoupper((string) $acc['currency']) !== strtoupper(mk_settings()['currency']) && abs($fx - 1) < 0.0001) {
            $notes[] = "Le compte « {$acc['name']} » est en {$acc['currency']} : réglez le taux de conversion vers " . mk_settings()['currency'] . ' dans Connexions pour des montants justes.';
        }
    }
    if ($notes) $cc['note'] = trim($cc['note'] . ' ' . implode(' ', array_unique($notes)));
    return [$cc, $rc];
}

/** Réponses d'un formulaire Lead Ads → champs du lead. */
function mk_meta_lead_fields(array $fieldData): array
{
    $v = [];
    foreach ($fieldData as $f) $v[mb_strtolower((string) ($f['name'] ?? ''))] = trim(implode(', ', array_map('strval', (array) ($f['values'] ?? []))));
    $pick = function (array $keys) use (&$v): string {
        foreach ($keys as $k) if (isset($v[$k]) && $v[$k] !== '') { $x = $v[$k]; unset($v[$k]); return $x; }
        return '';
    };
    $name = $pick(['full_name', 'nom_complet', 'nom']);
    $first = $pick(['first_name', 'prenom', 'prénom']);
    $last = $pick(['last_name', 'nom_de_famille']);
    if ($name === '') $name = trim($first . ' ' . $last);
    $phone = $pick(['phone_number', 'phone', 'telephone', 'téléphone', 'numero_de_telephone']);
    $email = $pick(['email', 'e-mail', 'adresse_email']);
    $city = $pick(['city', 'ville', 'wilaya', 'state', 'province']);
    $other = [];
    foreach ($v as $k => $x) if ($x !== '') $other[] = ucfirst(str_replace('_', ' ', $k)) . ' : ' . $x;
    return ['name' => $name, 'phone' => $phone, 'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '', 'city' => $city, 'answers' => $other];
}

function mk_meta_sync_leads(string $from, string $to): array
{
    $st = mk_meta_state();
    $tok = mk_unseal($st['page_token']);
    $c = mk_meta_counter('leads');
    $supports = array_column(mk_supports(), 'key');
    try {
        $forms = mk_graph_all($st['page_id'] . '/leadgen_forms', ['fields' => 'id,name,status'], $tok, 300);
    } catch (MkMetaError $e) {
        if ($e->metaCode() === 190) throw $e;
        $c['note'] = 'Formulaires Lead Ads inaccessibles : ' . $e->userMessage();
        return [$c];
    }
    $since = strtotime($from . ' 00:00:00') - 1;
    foreach ($forms as $form) {
        $leads = mk_graph_all($form['id'] . '/leads', [
            'fields' => 'id,created_time,field_data,ad_id,ad_name,adset_name,campaign_id,campaign_name,form_id,is_organic,platform',
            'filtering' => [['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since]],
        ], $tok, 5000);
        foreach ($leads as $l) {
            $f = mk_meta_lead_fields(is_array($l['field_data'] ?? null) ? $l['field_data'] : []);
            $organic = !empty($l['is_organic']);
            $plat = strtolower((string) ($l['platform'] ?? ''));
            $src = $organic ? ($plat === 'ig' || $plat === 'instagram' ? 'instagram' : 'facebook') : 'meta_ads';
            if (!mk_meta_has_channel($src)) $src = mk_meta_has_channel('facebook') ? 'facebook' : (mk_channels()[0]['key'] ?? '');
            $camp = !empty($l['campaign_id']) ? q1('SELECT id, funnel_id, name, audience FROM campaigns WHERE ext_id = ?', ['meta:camp:' . $l['campaign_id']]) : null;
            $context = implode(' ', [(string) ($form['name'] ?? ''), (string) ($l['campaign_name'] ?? ''), $camp ? (string) $camp['name'] : '', (string) ($l['adset_name'] ?? ''), implode(' ', $f['answers'])]);
            $profile = $camp && $camp['audience'] === 'investisseurs' ? 'investisseur' : ($camp && $camp['audience'] === 'porteurs' ? 'porteur' : mk_classify_profile($context, (string) $st['default_profile']));
            $notes = array_filter(['Formulaire Lead Ads « ' . ($form['name'] ?? '') . ' »' . (!empty($l['campaign_name']) ? ' · campagne « ' . $l['campaign_name'] . ' »' : '') . ($organic ? ' · organique' : ''), implode("\n", $f['answers'])]);
            $phone = $f['phone'];
            $email = $f['email'];
            try {
                mk_meta_count($c, mk_meta_upsert('contacts', 'meta:lead:' . $l['id'], [
                    'date' => mk_meta_date((string) ($l['created_time'] ?? '')), 'name' => $f['name'] !== '' ? $f['name'] : ($email ?: $phone ?: 'Lead Meta'),
                    'phone' => $phone, 'email' => $email, 'city' => $f['city'],
                    'type' => $profile, 'source' => $src,
                    'support' => in_array('formulaire', $supports, true) ? 'formulaire' : '',
                    'campaign_id' => $camp ? (int) $camp['id'] : null, 'funnel_id' => $camp && $camp['funnel_id'] ? (int) $camp['funnel_id'] : null,
                    'status' => 'nouveau', 'notes' => implode("\n", $notes),
                ], [], function () use ($phone, $email) {
                    // Même personne déjà saisie à la main (même téléphone ou même email) : on relie, sans doublon.
                    $digits = substr((string) preg_replace('/\D/', '', $phone), -9);
                    if (strlen($digits) === 9) {
                        foreach (q("SELECT id, phone FROM contacts WHERE ext_id = '' AND phone != '' ORDER BY id DESC LIMIT 2000") as $r) {
                            if (substr((string) preg_replace('/\D/', '', (string) $r['phone']), -9) === $digits) return $r;
                        }
                    }
                    return $email !== '' ? q1("SELECT id FROM contacts WHERE ext_id = '' AND lower(email) = lower(?)", [$email]) : null;
                }));
            } catch (MkInputError $e) {
                $c['errors']++;
                $c['note'] = $e->getMessage();
            }
        }
    }
    return [$c];
}

/** Dernières synchronisations (pour l'historique). */
function mk_meta_log(int $n = 20): array
{
    return array_map(fn ($r) => ['at' => (int) $r['at'], 'from' => $r['date_from'], 'to' => $r['date_to'], 'ok' => (bool) $r['ok'], 'added' => (int) $r['added'], 'updated' => (int) $r['updated'], 'result' => json_decode((string) $r['result'], true)],
        q('SELECT * FROM meta_sync_log ORDER BY id DESC LIMIT ' . max(1, min(60, $n))));
}
