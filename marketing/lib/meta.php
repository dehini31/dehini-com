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
    // « Se connecter avec Instagram » (Business Login for Instagram), sans passer par une Page Facebook.
    'MK_META_IG_GRAPH_URL'  => 'https://graph.instagram.com/',
    'MK_META_IG_OAUTH_URL'  => 'https://api.instagram.com/',
    'MK_META_IG_AUTH_URL'   => 'https://www.instagram.com/oauth/authorize',
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
    // Messages Messenger et Instagram (lecture seule : le tableau de bord n'envoie aucun message).
    'pages_messaging', 'pages_manage_metadata', 'instagram_manage_messages',
];
/** Autorisations de « Se connecter avec Instagram » (lecture seule). */
const MK_META_IG_SCOPES = ['instagram_business_basic', 'instagram_business_manage_insights', 'instagram_business_manage_comments', 'instagram_business_manage_messages'];
/** État d'un compte publicitaire Meta (account_status) → libellé. */
const MK_META_AD_STATUS = [1 => 'Actif', 2 => 'Désactivé', 3 => 'Paiement en attente', 7 => 'En vérification', 8 => 'Règlement en attente', 9 => 'Période de grâce', 100 => 'Fermeture en cours', 101 => 'Fermé', 201 => 'Actif', 202 => 'Fermé'];
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
        'ig_username' => '', 'ad_accounts' => [], 'fx' => 1.0, 'backfill' => 30, 'questions' => true, 'messages' => true, 'auto_sync' => 60,
        'default_profile' => 'investisseur', 'rules' => [], 'last_sync' => 0, 'last_until' => '', 'last_result' => null, 'full_cursor' => [],
        'metrics' => [], 'oauth_error' => '', 'token_invalid' => false,
        // Instagram : « page » (compte relié à la Page Facebook) ou « instagram » (Se connecter avec Instagram).
        'ig_via' => '', 'ig_app_id' => '', 'ig_app_secret' => '', 'ig_token' => '', 'ig_token_expires' => 0, 'ig_connected_at' => 0, 'ig_oauth_error' => '',
        'ig_node' => '', 'ig_diag' => '',
        // Temps réel : jeton de vérification des webhooks, état de l'abonnement, dernière vérification rapide.
        'wh_token' => '', 'webhook' => [], 'last_light' => 0, 'msg_diag' => [],
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
    return $st['user_token'] !== '' || $st['page_token'] !== '' || $st['ig_token'] !== '';
}
/** Connexion Facebook (Pages, Ads, Lead Ads) présente ? */
function mk_meta_fb_connected(): bool
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
        'fb_connected' => mk_meta_fb_connected(),
        'page' => $st['page_id'] !== '' ? ['id' => $st['page_id'], 'name' => $st['page_name']] : null,
        'instagram' => $st['ig_id'] !== '' ? ['id' => $st['ig_id'], 'username' => $st['ig_username'], 'via' => $st['ig_via'] ?: 'page',
            'days_left' => $st['ig_via'] === 'instagram' && $st['ig_token_expires'] ? (int) floor(((int) $st['ig_token_expires'] - time()) / 86400) : null] : null,
        'ig_app_id' => $st['ig_app_id'], 'ig_app_ready' => $st['ig_app_id'] !== '' && $st['ig_app_secret'] !== '', 'ig_scopes' => MK_META_IG_SCOPES,
        'ig_oauth_error' => (string) $st['ig_oauth_error'], 'ig_diag' => (string) $st['ig_diag'],
        'ad_accounts' => array_values($st['ad_accounts']),
        'fx' => (float) $st['fx'], 'backfill' => (int) $st['backfill'], 'questions' => (bool) $st['questions'], 'messages' => (bool) $st['messages'], 'auto_sync' => (int) $st['auto_sync'],
        'default_profile' => $st['default_profile'],
        'rules' => mk_meta_rules(), 'rules_default' => mk_meta_default_rules(),
        'last_sync' => (int) $st['last_sync'], 'last_result' => $st['last_result'], 'running' => mk_meta_running(),
        'webhook' => mk_meta_webhook_public(), 'last_light' => (int) $st['last_light'], 'oauth_error' => (string) $st['oauth_error'], 'token_invalid' => (bool) $st['token_invalid'],
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

/** Appel GET de l'API Graph. $token vide : jeton de l'utilisateur. $base « ig » : API d'Instagram (graph.instagram.com). */
function mk_graph(string $path, array $params = [], string $token = '', string $base = ''): array
{
    $st = mk_meta_state();
    if ($token === '') $token = mk_unseal($st['user_token']) ?: mk_unseal($st['page_token']);
    if ($token === '') throw new MkMetaError('Aucune connexion Meta : connectez-vous dans Connexions.', ['code' => 190]);
    $params['access_token'] = $token;
    [, $secret] = mk_meta_app();
    // Preuve de la clé secrète, pour les jetons obtenus avec notre app (un jeton collé peut venir d'une autre app).
    if ($base === '' && $secret !== '' && $st['token_kind'] === 'oauth') $params['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
    foreach ($params as $k => $v) if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    static $igNoVersion = false; // l'API d'Instagram a refusé le numéro de version : on s'en passe pour la suite
    $ver = $base === 'ig' && $igNoVersion ? '' : MK_META_GRAPH_VERSION . '/';
    $url = rtrim($base === 'ig' ? MK_META_IG_GRAPH_URL : MK_META_GRAPH_URL, '/') . '/' . $ver . ltrim($path, '/');
    [$code, $body] = mk_meta_http($url, $params);
    $d = json_decode($body, true);
    // L'API d'Instagram refuse parfois une version précise (« Unsupported request - method type: get ») : on réessaie sans.
    if ($base === 'ig' && $ver !== '' && is_array($d) && stripos((string) ($d['error']['message'] ?? ''), 'Unsupported request') !== false) {
        [$code, $body] = mk_meta_http(rtrim(MK_META_IG_GRAPH_URL, '/') . '/' . ltrim($path, '/'), $params);
        $d = json_decode($body, true);
        if (is_array($d) && !isset($d['error'])) $igNoVersion = true;
    }
    if (!is_array($d)) throw new MkMetaError('Réponse illisible de Meta (HTTP ' . $code . ').', ['code' => $code]);
    if (isset($d['error']) && is_array($d['error'])) throw new MkMetaError((string) ($d['error']['message'] ?? 'Erreur Meta'), $d['error']);
    return $d;
}

/** Toutes les pages d'une liste (curseur « after »), dans la limite de $max lignes. */
function mk_graph_all(string $path, array $params, string $token = '', int $max = 2000, string $base = ''): array
{
    $rows = [];
    $params['limit'] = $params['limit'] ?? 100;
    for ($i = 0; $i < 50; $i++) {
        $d = mk_graph($path, $params, $token, $base);
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
                'currency' => (string) ($a['currency'] ?? ''), 'active' => in_array((int) ($a['account_status'] ?? 1), [1, 201], true),
                'status' => MK_META_AD_STATUS[(int) ($a['account_status'] ?? 1)] ?? 'Inactif', 'timezone' => (string) ($a['timezone_name'] ?? '')];
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
        $st0 = mk_meta_state();
        $keepIg = $st0['ig_via'] === 'instagram' && $st0['ig_token'] !== '';
        if ($pid === '') {
            $patch += ['page_id' => '', 'page_name' => '', 'page_token' => ''];
            if (!$keepIg) $patch += ['ig_id' => '', 'ig_username' => '', 'ig_via' => ''];
        } else {
            $found = null;
            foreach (mk_graph_all('me/accounts', ['fields' => 'id,name,access_token,instagram_business_account{id,username}']) as $p) {
                if ((string) $p['id'] === $pid) $found = $p;
            }
            if (!$found) throw new MkInputError("Cette Page n'est plus accessible avec votre compte Facebook. Vérifiez vos accès dans Meta Business Suite.");
            $ig = $found['instagram_business_account'] ?? null;
            $patch += ['page_id' => $pid, 'page_name' => (string) ($found['name'] ?? ''), 'page_token' => mk_seal((string) ($found['access_token'] ?? ''))];
            // Un Instagram connecté directement reste prioritaire ; sinon, celui relié à la Page.
            if (!$keepIg) $patch += ['ig_id' => $ig ? (string) $ig['id'] : '', 'ig_username' => $ig ? (string) ($ig['username'] ?? '') : '', 'ig_via' => $ig ? 'page' : ''];
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
    if (array_key_exists('messages', $in)) $patch['messages'] = (bool) $in['messages'];
    if (array_key_exists('auto_sync', $in)) $patch['auto_sync'] = in_array((int) $in['auto_sync'], [0, 15, 30, 60, 180, 360, 720], true) ? (int) $in['auto_sync'] : 60;
    if (array_key_exists('default_profile', $in)) $patch['default_profile'] = array_key_exists((string) $in['default_profile'], mk_enums()['contact_types']) ? (string) $in['default_profile'] : 'investisseur';
    if (array_key_exists('rules', $in) && is_array($in['rules'])) $patch['rules'] = mk_meta_clean_rules($in['rules']);
    if (array_key_exists('ig_app_id', $in) || array_key_exists('ig_app_secret', $in)) {
        $id = preg_replace('/\D/', '', (string) ($in['ig_app_id'] ?? ''));
        $sec = trim((string) ($in['ig_app_secret'] ?? ''));
        if (strlen($id) < 5) throw new MkInputError("L'identifiant de l'app Instagram est un long nombre : copiez-le depuis votre app Meta › Instagram › Configuration de l'API avec la connexion Instagram (« ID de l'app Instagram »). Il est différent de l'App ID Facebook.");
        if ($id === mk_meta_app()[0]) throw new MkInputError("Ce numéro est l'App ID Facebook, pas l'ID de l'app Instagram (c'est ce qui provoque « Invalid platform app »). Dans votre app Meta › Cas d'utilisation › Instagram › Configuration de l'API avec la connexion Instagram, copiez « ID de l'app Instagram » et « Clé secrète de l'app Instagram ».");
        if ($sec !== '' && !preg_match('/^[a-f0-9]{32}$/i', $sec)) throw new MkInputError("La clé secrète de l'app Instagram compte 32 caractères (chiffres et lettres a à f) : cliquez sur « Afficher » à côté de « Clé secrète de l'app Instagram » puis copiez-la en entier.");
        if ($sec === '' && mk_meta_state()['ig_app_secret'] === '') throw new MkInputError("Ajoutez aussi la clé secrète de l'app Instagram.");
        $patch['ig_app_id'] = $id;
        $patch['ig_oauth_error'] = '';
        if ($sec !== '') $patch['ig_app_secret'] = mk_seal($sec);
    }
    if (!empty($in['ig_disconnect'])) {
        $st0 = mk_meta_state();
        $patch += ['ig_token' => '', 'ig_token_expires' => 0, 'ig_connected_at' => 0, 'ig_via' => '', 'ig_id' => '', 'ig_username' => '', 'ig_node' => '', 'ig_diag' => ''];
        // Retour au compte Instagram relié à la Page, s'il y en a un.
        if ($st0['page_id'] !== '' && $st0['page_token'] !== '') {
            try {
                $pg = mk_graph($st0['page_id'], ['fields' => 'instagram_business_account{id,username}'], mk_unseal($st0['page_token']));
                if (!empty($pg['instagram_business_account']['id'])) $patch = array_merge($patch, ['ig_id' => (string) $pg['instagram_business_account']['id'], 'ig_username' => (string) ($pg['instagram_business_account']['username'] ?? ''), 'ig_via' => 'page']);
            } catch (MkMetaError $e) { /* sans Instagram relié */ }
        }
    }
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
        'ig_via' => '', 'ig_token' => '', 'ig_token_expires' => 0, 'ig_connected_at' => 0,
        'app_id' => $st['app_id'], 'app_secret' => $st['app_secret']]);
}

/* ============================================================ « Se connecter avec Instagram » */

function mk_meta_ig_login_url(string $state): string
{
    $st = mk_meta_state();
    if ($st['ig_app_id'] === '' || $st['ig_app_secret'] === '') throw new MkInputError("Renseignez d'abord l'ID et la clé secrète de l'app Instagram (Connexions › Instagram).");
    return MK_META_IG_AUTH_URL . '?' . http_build_query([
        'client_id' => $st['ig_app_id'], 'redirect_uri' => mk_meta_redirect_uri(), 'response_type' => 'code',
        'scope' => implode(',', MK_META_IG_SCOPES), 'state' => $state,
    ]);
}

/** Code reçu d'Instagram → jeton longue durée (60 jours, renouvelé tout seul à chaque synchronisation). */
function mk_meta_ig_exchange(string $code): array
{
    $st = mk_meta_state();
    $sec = mk_unseal($st['ig_app_secret']);
    [, $body] = mk_meta_http(rtrim(MK_META_IG_OAUTH_URL, '/') . '/oauth/access_token', [], [
        'client_id' => $st['ig_app_id'], 'client_secret' => $sec, 'grant_type' => 'authorization_code',
        'redirect_uri' => mk_meta_redirect_uri(), 'code' => preg_replace('/#_$/', '', $code),
    ]);
    $d = json_decode($body, true);
    $short = is_array($d) ? (string) ($d['access_token'] ?? ($d['data'][0]['access_token'] ?? '')) : '';
    if ($short === '') {
        $msg = rtrim(trim((string) ($d['error_message'] ?? ($d['error']['message'] ?? ''))), '.');
        $hint = stripos($msg, 'secret') !== false ? " La clé secrète Instagram enregistrée ne correspond pas (réinitialisée chez Meta ?) : recollez-la dans « Modifier l'app Instagram »."
            : (stripos($msg, 'redirect') !== false ? " L'adresse de retour doit être enregistrée dans l'app Meta › connexion Instagram › Paramètres de connexion entreprise." : '');
        throw new MkMetaError('Instagram a refusé la connexion' . ($msg !== '' ? ' : « ' . $msg . ' ».' : ' (réponse vide).') . $hint, ['message' => 'Instagram a refusé la connexion' . ($msg !== '' ? ' : « ' . $msg . ' ».' : ' (réponse vide).') . $hint]);
    }
    // Diagnostic de chaque étape, affiché dans la carte Instagram si quelque chose coince (jamais le jeton lui-même).
    $userId = is_array($d) ? (string) ($d['user_id'] ?? ($d['data'][0]['user_id'] ?? '')) : '';
    $diag = ['jeton reçu (' . mk_meta_token_kind($short) . ')' . ($userId !== '' ? ', compte ' . $userId : '')];
    [$lcode, $body] = mk_meta_http(rtrim(MK_META_IG_GRAPH_URL, '/') . '/access_token', ['grant_type' => 'ig_exchange_token', 'client_secret' => $sec, 'access_token' => $short]);
    $l = json_decode($body, true);
    $tok = is_array($l) && !empty($l['access_token']) ? (string) $l['access_token'] : $short;
    $exp = is_array($l) && !empty($l['expires_in']) ? time() + (int) $l['expires_in'] : time() + 3600;
    if ($tok === $short) $diag[] = 'jeton 60 jours refusé (HTTP ' . $lcode . ' : ' . mk_meta_err_text($l) . ') : jeton d\'une heure utilisé';
    // Le compte connecté : « me », sinon l'identifiant donné avec le jeton.
    $me = null;
    $node = 'me';
    foreach (['user_id,username', 'id,username'] as $fields) {
        try {
            $me = mk_graph('me', ['fields' => $fields], $tok, 'ig');
            break;
        } catch (MkMetaError $e) {
            if ($e->metaCode() === 190) throw $e;
            $diag[] = "lecture de « me » ($fields) refusée : " . $e->getMessage() . mk_meta_err_code($e);
        }
    }
    if (!$me && $userId !== '') {
        try {
            $me = mk_graph($userId, ['fields' => 'id,username'], $tok, 'ig');
            $node = $userId;
        } catch (MkMetaError $e) {
            if ($e->metaCode() === 190) throw $e;
            $diag[] = "lecture du compte $userId refusée : " . $e->getMessage() . mk_meta_err_code($e);
        }
    }
    if (!$me && $userId === '') throw new MkMetaError("Instagram n'a pas indiqué le compte connecté. Détail : " . implode(' · ', $diag) . '.');
    $id = (string) ($me['user_id'] ?? '') ?: ($userId !== '' ? $userId : (string) ($me['id'] ?? ''));
    if (!$me) $node = $userId; // la connexion est gardée : la synchronisation réessaiera avec l'identifiant du compte
    return mk_meta_put(['ig_token' => mk_seal($tok), 'ig_token_expires' => $exp, 'ig_connected_at' => time(), 'ig_via' => 'instagram',
        'ig_id' => $id, 'ig_node' => $node, 'ig_username' => (string) ($me['username'] ?? ''), 'ig_oauth_error' => '',
        'ig_diag' => $me ? '' : implode(' · ', $diag) . '.']);
}

/** Type d'un jeton, sans le dévoiler : Instagram (IG…), Facebook (EA…) ou inconnu. */
function mk_meta_token_kind(string $t): string
{
    return strncmp($t, 'IG', 2) === 0 ? 'jeton Instagram' : (strncmp($t, 'EA', 2) === 0 ? 'jeton Facebook' : 'jeton de type inconnu');
}
/** Message d'erreur d'une réponse d'Instagram ou de Facebook. */
function mk_meta_err_text($d): string
{
    if (!is_array($d)) return 'réponse illisible';
    return rtrim(trim((string) ($d['error']['message'] ?? $d['error_message'] ?? 'réponse inattendue')), '.');
}
function mk_meta_err_code(MkMetaError $e): string
{
    $parts = array_filter([isset($e->err['code']) ? 'code ' . $e->err['code'] : '', $e->err['type'] ?? ($e->err['error_type'] ?? '')]);
    return $parts ? ' (' . implode(', ', $parts) . ')' : '';
}

/** Renouvelle le jeton Instagram quand il lui reste moins de 20 jours (Instagram l'accepte après 24 h). */
function mk_meta_ig_refresh(): void
{
    $st = mk_meta_state(true);
    if ($st['ig_via'] !== 'instagram' || $st['ig_token'] === '') return;
    if ((int) $st['ig_token_expires'] > time() + 20 * 86400 || time() - (int) $st['ig_connected_at'] < 86400) return;
    try {
        [, $body] = mk_meta_http(rtrim(MK_META_IG_GRAPH_URL, '/') . '/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => mk_unseal($st['ig_token'])]);
        $d = json_decode($body, true);
        if (is_array($d) && !empty($d['access_token'])) {
            mk_meta_put(['ig_token' => mk_seal((string) $d['access_token']), 'ig_token_expires' => time() + (int) ($d['expires_in'] ?? 5184000), 'ig_connected_at' => time()]);
        }
    } catch (MkMetaError $e) { /* on réessaiera à la prochaine synchronisation */ }
}

/** Jeton et API à utiliser pour Instagram : connexion Instagram directe, sinon la Page Facebook. */
function mk_meta_ig_ctx(): array
{
    $st = mk_meta_state();
    // Avec « Se connecter avec Instagram », on passe par « me » : pas de doute sur l'identifiant du compte.
    if ($st['ig_via'] === 'instagram' && $st['ig_token'] !== '') return [mk_unseal($st['ig_token']), 'ig', $st['ig_node'] !== '' ? $st['ig_node'] : 'me'];
    return [mk_unseal($st['page_token']) ?: '', '', $st['ig_id']];
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
/** Synchronisation en cours en mode « tout l'historique » (messages et commentaires). */
function mk_meta_full(?bool $set = null): bool
{
    static $full = false;
    if ($set !== null) $full = $set;
    return $full;
}

/**
 * Temps de travail d'une synchronisation : un lot court (~40 s) depuis le navigateur, que les hébergeurs
 * ne coupent pas ; la tâche cron (ligne de commande) a 25 minutes. Ce qui reste est fait au lot suivant.
 */
function mk_meta_clock(bool $reset = false): float
{
    static $t0 = null;
    if ($reset || $t0 === null) $t0 = microtime(true);
    return $t0;
}
function mk_meta_time_left(): float
{
    $budget = defined('MK_META_SYNC_BUDGET') ? (float) MK_META_SYNC_BUDGET : (PHP_SAPI === 'cli' ? 1500.0 : 40.0);
    $max = (int) ini_get('max_execution_time');
    if ($max > 0) $budget = min($budget, $max - 8);
    return $budget - (microtime(true) - mk_meta_clock());
}
/** Une étape s'est arrêtée faute de temps : elle reprendra au lot suivant. */
function mk_meta_more(?bool $set = null): bool
{
    static $more = false;
    if ($set !== null) $more = $set;
    return $more;
}
/** Une synchronisation tourne-t-elle en ce moment (navigateur, autre onglet ou tâche cron) ? */
function mk_meta_running(): bool
{
    $f = dirname(MK_DB_PATH) . '/meta-sync.lock';
    if (!is_file($f) || !($h = @fopen($f, 'c'))) return false;
    $busy = !flock($h, LOCK_EX | LOCK_NB);
    if (!$busy) flock($h, LOCK_UN);
    fclose($h);
    return $busy;
}

function mk_meta_sync(array $opt = []): array
{
    if (!mk_meta_connected()) throw new MkInputError("Connectez d'abord votre compte Meta (Connexions).");
    $lock = @fopen(dirname(MK_DB_PATH) . '/meta-sync.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) throw new MkInputError('Une synchronisation est déjà en cours : patientez une minute.');
    mk_meta_clock(true);
    @set_time_limit(PHP_SAPI === 'cli' ? 1800 : 120);
    mk_meta_full(!empty($opt['full']));
    mk_meta_ig_refresh();
    $st = mk_meta_state(true);
    $to = mk_today();
    $days = (int) ($opt['days'] ?? 0);
    if ($days > 0) $from = mk_add_days($to, -min(90, $days) + 1);
    elseif ($st['last_until'] !== '' && mk_valid_date($st['last_until'])) $from = max(mk_add_days($to, -89), min(mk_add_days($st['last_until'], -3), mk_add_days($to, -3)));
    else $from = mk_add_days($to, -max(7, min(90, (int) $st['backfill'])) + 1);
    $only = (array) ($opt['only'] ?? []);
    $steps = [
        'fb_stats' => ['Relevés de la Page Facebook', 'mk_meta_sync_fb_stats', $st['page_id'] !== '' && $st['page_token'] !== ''],
        'ig_stats' => ['Relevés du compte Instagram', 'mk_meta_sync_ig_stats', $st['ig_id'] !== ''],
        'fb_posts' => ['Publications Facebook', 'mk_meta_sync_fb_posts', $st['page_id'] !== ''],
        'ig_posts' => ['Publications Instagram', 'mk_meta_sync_ig_posts', $st['ig_id'] !== ''],
        'ads' => ['Campagnes Meta Ads', 'mk_meta_sync_ads', (bool) $st['ad_accounts']],
        'leads' => ['Leads des formulaires', 'mk_meta_sync_leads', $st['page_id'] !== ''],
        'messages' => ['Messages Messenger et Instagram', 'mk_meta_sync_messages', $st['page_id'] !== '' && $st['page_token'] !== '' && $st['messages']],
        'comments' => ['Commentaires Facebook et Instagram', 'mk_meta_sync_comments', $st['page_id'] !== '' || $st['ig_id'] !== ''],
    ];
    $out = ['at' => time(), 'from' => $from, 'to' => $to, 'steps' => [], 'ok' => true, 'full' => mk_meta_full(), 'totals' => ['added' => 0, 'updated' => 0], 'pending' => []];
    foreach ($steps as $key => [$label, $fn, $on]) {
        if ($only && !in_array($key, $only, true)) continue;
        $res = ['key' => $key, 'label' => $label, 'status' => 'skip', 'counts' => [], 'message' => ''];
        mk_meta_more(false);
        if ($on && mk_meta_time_left() < 8) {
            // Plus le temps dans ce lot : l'étape passe au lot suivant.
            $res['message'] = 'À suivre : cette étape passe au lot suivant.';
            $out['pending'][] = $key;
            $out['steps'][] = $res;
            continue;
        }
        if (!$on) {
            $res['message'] = $key === 'messages' && !$st['messages'] ? 'Import des messages désactivé (Connexions › étape 3).' : ($key === 'ads' ? 'Aucun compte publicitaire choisi.' : ($key === 'ig_stats' || $key === 'ig_posts' ? "Aucun compte Instagram professionnel n'est relié à la Page." : 'Aucune Page Facebook choisie : choisissez-la à l\'étape 3 « Page, Instagram et comptes publicitaires », puis « Enregistrer ces choix ».'));
        } else {
            try {
                $res['counts'] = $fn($from, $to);
                $res['status'] = 'ok';
                foreach ($res['counts'] as $c) { $out['totals']['added'] += (int) ($c['added'] ?? 0); $out['totals']['updated'] += (int) ($c['updated'] ?? 0); }
                $notes = array_filter(array_map(fn ($c) => $c['note'] ?? '', $res['counts']));
                if (mk_meta_more()) {
                    $out['pending'][] = $key;
                    $notes[] = 'À suivre : la suite est importée au lot suivant.';
                }
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
    $out['more'] = (bool) $out['pending'];
    $out['seconds'] = (int) round(microtime(true) - mk_meta_clock());
    if (!empty($opt['light']) && $only && !array_diff($only, ['messages', 'comments'])) {
        // Vérification rapide des nouveaux messages et commentaires (toutes les 2 minutes) : discrète.
        mk_meta_put(['last_light' => $out['at'], 'token_invalid' => !empty($out['token_invalid'])]);
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        mk_meta_full(false);
        return $out;
    }
    $patch = ['last_sync' => $out['at'], 'last_result' => $out, 'token_invalid' => !empty($out['token_invalid'])];
    // La fenêtre de dates n'avance qu'une fois tout importé sans erreur (sur un ou plusieurs lots).
    if ($out['ok'] && !$out['more'] && !mk_meta_full() && (!$only || (!empty($opt['resume']) && !empty($opt['clean'])))) $patch['last_until'] = $to;
    mk_meta_put($patch);
    db()->prepare('INSERT INTO meta_sync_log (at, date_from, date_to, ok, added, updated, result) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$out['at'], $from, $to, $out['ok'] ? 1 : 0, $out['totals']['added'], $out['totals']['updated'], json_encode($out, JSON_UNESCAPED_UNICODE)]);
    db()->exec('DELETE FROM meta_sync_log WHERE id NOT IN (SELECT id FROM meta_sync_log ORDER BY id DESC LIMIT 60)');
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    mk_meta_full(false);
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
    [$tok, $gb, $node] = mk_meta_ig_ctx();
    $ig = $st['ig_id'];
    $c = mk_meta_counter('relevés');
    // Séries jour par jour (fenêtres de 30 jours au plus) : portée et nouveaux abonnés.
    $series = function (string $metric) use ($node, $from, $to, $tok, $gb): array {
        $vals = [];
        try {
            foreach (mk_meta_windows($from, $to, 30) as [$a, $b]) {
                $d = mk_graph("$node/insights", ['metric' => $metric, 'period' => 'day', 'since' => $a, 'until' => mk_add_days($b, 1)], $tok, $gb);
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
                mk_graph("$node/insights", ['metric' => $n, 'metric_type' => 'total_value', 'period' => 'day', 'since' => $probe, 'until' => mk_add_days($probe, 1)], $tok, $gb);
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
            $r = mk_graph("$node/insights", ['metric' => implode(',', array_values($use)), 'metric_type' => 'total_value', 'period' => 'day', 'since' => $d, 'until' => mk_add_days($d, 1)], $tok, $gb);
            foreach (($r['data'] ?? []) as $m) {
                $k = array_search((string) ($m['name'] ?? ''), $use, true);
                if ($k !== false) $tot[$d][$k] = (int) ($m['total_value']['value'] ?? 0);
            }
        }
    }
    $acc = mk_graph($node, ['fields' => 'followers_count,username'], $tok, $gb);
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
function mk_meta_post_insights(string $id, array $want, string $token, array &$use, string $base = ''): array
{
    if ($use === []) {
        foreach ($want as $k => $names) {
            foreach ($names as $n) {
                try {
                    mk_graph("$id/insights", ['metric' => $n], $token, $base);
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
        $d = mk_graph("$id/insights", ['metric' => implode(',', $names)], $token, $base);
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
    $use = [];
    foreach ($posts as $p) {
        if (mk_meta_time_left() < 6) { mk_meta_more(true); break; }
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
    }
    return [$c];
}

function mk_meta_sync_ig_posts(string $from, string $to): array
{
    $st = mk_meta_state();
    if (!mk_meta_has_channel('instagram')) throw new MkInputError('Le canal « Instagram » a été retiré de Canaux & supports : ajoutez-le de nouveau pour importer les publications.');
    [$tok, $gb, $node] = mk_meta_ig_ctx();
    $ig = $st['ig_id'];
    $since = min($from, mk_add_days($to, -13));
    $fields = 'id,caption,media_type,media_product_type,timestamp,permalink,like_count,comments_count';
    $media = [];
    foreach (mk_graph_all("$node/media", ['fields' => $fields, 'since' => strtotime($since . ' 00:00:00')], $tok, 500, $gb) as $m) {
        if (mk_meta_date((string) ($m['timestamp'] ?? '')) >= $since) $media[] = $m;
    }
    try {
        foreach (mk_graph_all("$node/stories", ['fields' => $fields], $tok, 100, $gb) as $m) $media[] = $m;
    } catch (MkMetaError $e) {
        if ($e->metaCode() === 190) throw $e;
    }
    $c = mk_meta_counter('publications');
    $useFeed = [];
    $useStory = [];
    foreach ($media as $m) {
        if (mk_meta_time_left() < 6) { mk_meta_more(true); break; }
        $mpt = strtoupper((string) ($m['media_product_type'] ?? 'FEED'));
        if ($mpt === 'AD') continue; // publicité : comptée dans Ads Manager
        $mt = strtoupper((string) ($m['media_type'] ?? 'IMAGE'));
        $fmt = $mpt === 'REELS' ? 'reel' : ($mpt === 'STORY' ? 'story' : ($mt === 'CAROUSEL_ALBUM' ? 'carrousel' : ($mt === 'VIDEO' ? 'reel' : 'post')));
        $text = (string) ($m['caption'] ?? '');
        $date = mk_meta_date((string) ($m['timestamp'] ?? ''));
        $want = ['reach' => ['reach'], 'impressions' => ['views', 'impressions', 'plays'], 'engagement' => ['total_interactions'], 'shares' => ['shares'], 'saved' => ['saved']];
        if ($mpt === 'STORY') $ins = mk_meta_post_insights((string) $m['id'], ['reach' => ['reach'], 'impressions' => ['views', 'impressions'], 'engagement' => ['total_interactions', 'replies']], $tok, $useStory, $gb);
        else $ins = mk_meta_post_insights((string) $m['id'], $want, $tok, $useFeed, $gb);
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
    }
    return [$c];
}

/** Un commentaire qui pose une question devient une question à traiter (sujet et profil devinés). */
function mk_meta_question(array &$q, string $channel, string $id, string $text, string $when, string $name, string $username, string $link = ''): void
{
    if (!mk_is_question($text)) return;
    $st = mk_meta_state();
    $who = $name !== '' ? $name : ($username !== '' ? '@' . $username : '');
    // Meta ne donne le nom d'un commentateur Facebook qu'aux apps « en ligne » ayant pages_read_user_content :
    // sans lui, le lien permet d'ouvrir le commentaire pour voir qui l'a écrit. Un nom reçu plus tard complète la ligne.
    $owned = ['link'];
    if ($who !== '') $owned[] = 'name';
    try {
        mk_meta_count($q, mk_meta_upsert('inquiries', "meta:cmt:$id", [
            'date' => mk_meta_date($when), 'source' => $channel, 'name' => $who,
            'contact' => $username !== '' ? '@' . $username . ' (Instagram)' : 'Commentaire Facebook', 'type' => mk_classify_profile($text),
            'category' => mk_classify($text, mk_meta_rules()['question']) ?: 'autre', 'question' => mk_str($text, 2000) ?: '—', 'status' => 'nouveau',
            'link' => preg_match('~^https?://~', $link) ? mb_substr($link, 0, 500) : '',
        ], $owned));
    } catch (MkInputError $e) {
        $q['errors']++;
        $q['note'] = $e->getMessage();
    }
}

/* ============================================================ messages Messenger et Instagram */

/** Wilayas d'Algérie (et quelques noms en arabe), pour reconnaître la ville d'un prospect dans ses messages. */
const MK_WILAYAS = ['Adrar', 'Chlef', 'Laghouat', 'Oum El Bouaghi', 'Batna', 'Béjaïa', 'Biskra', 'Béchar', 'Blida', 'Bouira', 'Tamanrasset', 'Tébessa',
    'Tlemcen', 'Tiaret', 'Tizi Ouzou', 'Alger', 'Djelfa', 'Jijel', 'Sétif', 'Saïda', 'Skikda', 'Sidi Bel Abbès', 'Annaba', 'Guelma', 'Constantine',
    'Médéa', 'Mostaganem', "M'Sila", 'Mascara', 'Ouargla', 'Oran', 'El Bayadh', 'Illizi', 'Bordj Bou Arreridj', 'Boumerdès', 'El Tarf', 'Tindouf',
    'Tissemsilt', 'El Oued', 'Khenchela', 'Souk Ahras', 'Tipaza', 'Mila', 'Aïn Defla', 'Naâma', 'Aïn Témouchent', 'Ghardaïa', 'Relizane', 'Timimoun',
    'Bordj Badji Mokhtar', 'Ouled Djellal', 'Béni Abbès', 'In Salah', 'In Guezzam', 'Touggourt', 'Djanet', "El M'Ghair", 'El Meniaa'];
const MK_WILAYAS_AR = ['الجزائر' => 'Alger', 'وهران' => 'Oran', 'قسنطينة' => 'Constantine', 'سطيف' => 'Sétif', 'عنابة' => 'Annaba', 'البليدة' => 'Blida',
    'باتنة' => 'Batna', 'تيزي وزو' => 'Tizi Ouzou', 'بجاية' => 'Béjaïa', 'تلمسان' => 'Tlemcen', 'بسكرة' => 'Biskra', 'ورقلة' => 'Ouargla', 'غرداية' => 'Ghardaïa',
    'الشلف' => 'Chlef', 'المدية' => 'Médéa', 'مستغانم' => 'Mostaganem', 'بومرداس' => 'Boumerdès', 'تيبازة' => 'Tipaza', 'جيجل' => 'Jijel', 'سكيكدة' => 'Skikda'];
/** Mots qui montrent une intention forte (vouloir investir, être rappelé, donner ses coordonnées). */
const MK_INTEREST_WORDS = ['interesse', 'je veux investir', 'je souhaite investir', 'je souhaite', 'comment participer', 'comment investir', 'comment faire pour',
    'rendez-vous', 'rdv', 'rappel', 'rappelez', 'appelez-moi', 'contactez-moi', 'mon numero', 'mon whatsapp', 'disponible', 'je suis partant', 'inscrire',
    'نحب نستثمر', 'نحب نشارك', 'كيفاش نشارك', 'رقمي', 'اتصلوا بي', 'مهتم', 'نحب'];

/** Chiffres arabes orientaux → chiffres occidentaux. */
function mk_digits(string $s): string
{
    return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
}
/** Premier numéro de téléphone algérien trouvé (mobile ou fixe), au format 0X XX XX XX XX. */
function mk_extract_phone(string $text): string
{
    $t = mk_digits($text);
    if (!preg_match('/(?:(?:\+|00)\s*213[\s.\-]*0?|\b0)([5-7](?:[\s.\-]*\d){8}|[2-4](?:[\s.\-]*\d){7})/u', $t, $m)) return '';
    $d = '0' . preg_replace('/\D/', '', $m[1]);
    return strlen($d) === 10 ? substr($d, 0, 4) . ' ' . substr($d, 4, 2) . ' ' . substr($d, 6, 2) . ' ' . substr($d, 8, 2) : $d;
}
function mk_extract_email(string $text): string
{
    return preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) ? strtolower($m[0]) : '';
}
function mk_extract_city(string $text): string
{
    $f = ' ' . mk_fold($text) . ' ';
    $f = (string) preg_replace('/[^\p{L}\p{N}\' ]+/u', ' ', $f);
    foreach (MK_WILAYAS as $w) {
        if (mb_strpos($f, ' ' . mk_fold($w) . ' ') !== false) return $w;
    }
    foreach (MK_WILAYAS_AR as $ar => $w) if (mb_strpos($text, $ar) !== false) return $w;
    return '';
}

/**
 * Analyse d'une conversation : sujet, profil, coordonnées laissées, intention, chaleur (score 0-100),
 * temps de première réponse. Seuls les messages de la personne comptent pour le classement.
 */
function mk_conv_analyze(int $id): void
{
    $msgs = q('SELECT from_me, text, at FROM conversation_messages WHERE conv_id = ? ORDER BY at, id', [$id]);
    $in = array_values(array_filter($msgs, fn ($m) => !(int) $m['from_me']));
    $text = implode("\n", array_map(fn ($m) => (string) $m['text'], $in));
    $rules = mk_meta_rules();
    $phone = mk_extract_phone($text);
    $email = mk_extract_email($text);
    $city = mk_extract_city($text);
    $profile = mk_classify_profile($text);
    $question = (bool) array_filter($in, fn ($m) => mk_is_question((string) $m['text']));
    $f = mk_fold($text);
    $interest = (bool) array_filter(MK_INTEREST_WORDS, fn ($w) => mb_strpos($f, mk_fold($w)) !== false);
    $score = min(100, ($phone !== '' ? 35 : 0) + ($email !== '' ? 20 : 0) + ($interest ? 25 : 0) + ($question ? 10 : 0) + ($profile !== '' ? 10 : 0) + min(20, max(0, count($in) - 1) * 5));
    $heat = $score >= 60 ? 'chaud' : ($score >= 30 ? 'tiede' : 'froid');
    // Temps de première réponse : du premier message de la personne au premier message de la Page qui suit.
    $reply = null;
    $firstIn = null;
    foreach ($msgs as $m) {
        if (!(int) $m['from_me'] && $firstIn === null) $firstIn = strtotime((string) $m['at']);
        if ((int) $m['from_me'] && $firstIn !== null) { $reply = max(0, (int) round((strtotime((string) $m['at']) - $firstIn) / 60)); break; }
    }
    $last = end($msgs) ?: null;
    db()->prepare('UPDATE conversations SET category = ?, profile = ?, heat = ?, score = ?, is_question = ?, interest = ?, phone = ?, email = ?, city = ?,
        reply_minutes = ?, in_count = ?, out_count = ?, last_from_me = ?, snippet = ? WHERE id = ?')->execute([
        mk_classify($text, $rules['question']) ?: 'autre', $profile, $heat, $score, $question ? 1 : 0, $interest ? 1 : 0, $phone, $email, $city,
        $reply, count($in), count($msgs) - count($in), $last ? (int) $last['from_me'] : 0, $last ? mb_substr((string) $last['text'], 0, 180) : '', $id,
    ]);
}

/* ------------------------------------------------------------ commentaires */

/**
 * Tous les commentaires (et leurs réponses) des publications Facebook et Instagram. Une publication
 * n'est relue que si son nombre de commentaires a changé depuis la dernière fois : les nouveaux
 * commentaires des anciennes publications (3 derniers mois, 2 ans en mode « tout l'historique »)
 * arrivent donc aussi. Un commentaire qui pose une question devient une question à traiter.
 */
function mk_meta_sync_comments(string $from, string $to): array
{
    $st = mk_meta_state();
    $full = mk_meta_full();
    $since = mk_add_days($to, $full ? -730 : -89);
    $c = mk_meta_counter('commentaires');
    $q = mk_meta_counter('questions');
    $notes = [];
    $done = 0;
    $tried = 0;
    if ($st['page_id'] !== '' && $st['page_token'] !== '' && mk_meta_has_channel('facebook')) {
        $tried++;
        try { mk_meta_comments_fb($since, $full, $c, $q); $done++; }
        catch (MkMetaError $e) {
            if ($e->metaCode() === 190) throw $e;
            $notes[] = 'Commentaires Facebook inaccessibles (« ' . rtrim($e->getMessage(), '.') . ' »).';
        }
    }
    if ($st['ig_id'] !== '' && mk_meta_has_channel('instagram') && !mk_meta_more()) {
        $tried++;
        try { mk_meta_comments_ig($since, $full, $c, $q); $done++; }
        catch (MkMetaError $e) {
            if ($e->metaCode() === 190) throw $e;
            $notes[] = 'Commentaires Instagram inaccessibles (« ' . rtrim($e->getMessage(), '.') . ' »).';
        }
    }
    if ($tried && !$done) throw new MkInputError(implode(' ', $notes));
    $c['note'] = implode(' ', $notes);
    return $st['questions'] ? [$c, $q] : [$c];
}

/** Faut-il relire les commentaires de cette publication ? (nouveau total, ou import incomplet en mode « tout »). */
function mk_meta_comments_changed(string $postExt, int $total, bool $full): bool
{
    if ($total <= 0) return false;
    $seen = qv('SELECT total FROM meta_comment_seen WHERE post_ext = ?', [$postExt]);
    if ($seen === false || $seen === null || (int) $seen !== $total) return true;
    return $full && (int) qv('SELECT COUNT(*) FROM comments WHERE post_ext = ?', [$postExt]) < $total;
}
function mk_meta_comments_seen(string $postExt, int $total): void
{
    db()->prepare('INSERT OR REPLACE INTO meta_comment_seen (post_ext, total, at) VALUES (?, ?, ?)')->execute([$postExt, $total, time()]);
    mk_meta_comments_thread($postExt);
}
/** Réponses : nombre par commentaire, et « la Page a répondu ». */
function mk_meta_comments_thread(string $postExt): void
{
    db()->prepare("UPDATE comments SET replies = (SELECT COUNT(*) FROM comments r WHERE r.parent_ext = comments.ext_id),
        page_replied = (SELECT COUNT(*) FROM comments r WHERE r.parent_ext = comments.ext_id AND r.from_me = 1) > 0
        WHERE post_ext = ? AND parent_ext = ''")->execute([$postExt]);
}

function mk_meta_comments_fb(string $since, bool $full, array &$c, array &$q): void
{
    $st = mk_meta_state();
    $tok = mk_unseal($st['page_token']);
    $pid = $st['page_id'];
    $posts = mk_graph_all("$pid/published_posts", [
        'fields' => 'id,message,story,created_time,permalink_url,comments.filter(stream).limit(0).summary(total_count)',
        'since' => strtotime($since . ' 00:00:00'),
    ], $tok, $full ? 3000 : 600);
    foreach ($posts as $p) {
        if (mk_meta_time_left() < 6) { mk_meta_more(true); return; }
        $total = (int) ($p['comments']['summary']['total_count'] ?? 0);
        $post = ['ext' => 'meta:fbpost:' . $p['id'], 'link' => (string) ($p['permalink_url'] ?? ''), 'text' => (string) ($p['message'] ?? $p['story'] ?? '')];
        if (!mk_meta_comments_changed($post['ext'], $total, $full)) continue;
        $list = mk_graph_all($p['id'] . '/comments', ['fields' => 'id,message,created_time,permalink_url,from{id,name},parent{id},like_count', 'filter' => 'stream', 'order' => 'chronological'], $tok, 5000);
        foreach ($list as $cm) {
            mk_meta_count($c, mk_meta_store_comment('facebook', $post, [
                'id' => (string) $cm['id'], 'parent' => (string) ($cm['parent']['id'] ?? ''), 'who_id' => (string) ($cm['from']['id'] ?? ''),
                'name' => (string) ($cm['from']['name'] ?? ''), 'username' => '', 'text' => (string) ($cm['message'] ?? ''), 'at' => (string) ($cm['created_time'] ?? ''),
                'link' => (string) ($cm['permalink_url'] ?? ''), 'likes' => (int) ($cm['like_count'] ?? 0), 'mine' => (string) ($cm['from']['id'] ?? '') === $pid,
            ], $q));
        }
        mk_meta_comments_seen($post['ext'], $total);
    }
}

function mk_meta_comments_ig(string $since, bool $full, array &$c, array &$q): void
{
    $st = mk_meta_state();
    [$tok, $gb, $node] = mk_meta_ig_ctx();
    $media = mk_graph_all("$node/media", ['fields' => 'id,caption,timestamp,permalink,comments_count,media_product_type', 'since' => strtotime($since . ' 00:00:00')], $tok, $full ? 3000 : 600, $gb);
    $mine = fn (string $u) => $u !== '' && strcasecmp($u, (string) $st['ig_username']) === 0;
    foreach ($media as $m) {
        if (mk_meta_time_left() < 6) { mk_meta_more(true); return; }
        if (mk_meta_date((string) ($m['timestamp'] ?? '')) < $since || strtoupper((string) ($m['media_product_type'] ?? '')) === 'AD') continue;
        $total = (int) ($m['comments_count'] ?? 0);
        $post = ['ext' => 'meta:igmedia:' . $m['id'], 'link' => (string) ($m['permalink'] ?? ''), 'text' => (string) ($m['caption'] ?? '')];
        if (!mk_meta_comments_changed($post['ext'], $total, $full)) continue;
        $f = 'id,text,timestamp,username,like_count';
        foreach (mk_graph_all($m['id'] . '/comments', ['fields' => "$f,replies.limit(100){" . $f . '}'], $tok, 5000, $gb) as $cm) {
            $one = fn (array $x, string $parent) => [
                'id' => (string) $x['id'], 'parent' => $parent, 'who_id' => '', 'name' => '', 'username' => (string) ($x['username'] ?? ''),
                'text' => (string) ($x['text'] ?? ''), 'at' => (string) ($x['timestamp'] ?? ''), 'link' => '', 'likes' => (int) ($x['like_count'] ?? 0),
                'mine' => $mine((string) ($x['username'] ?? '')),
            ];
            mk_meta_count($c, mk_meta_store_comment('instagram', $post, $one($cm, ''), $q));
            foreach (($cm['replies']['data'] ?? []) as $r) mk_meta_count($c, mk_meta_store_comment('instagram', $post, $one($r, (string) $cm['id']), $q));
        }
        mk_meta_comments_seen($post['ext'], $total);
    }
}

/** Enregistre un commentaire (ou une réponse) et l'analyse. Renvoie added / updated / same / ignored. */
function mk_meta_store_comment(string $platform, array $post, array $cm, array &$q): string
{
    $ext = 'meta:comment:' . $cm['id'];
    if (qv('SELECT 1 FROM ext_ignored WHERE ext_id = ?', [$ext])) return 'ignored';
    $text = mk_str($cm['text'], 4000);
    $at = mk_meta_local($cm['at']);
    $link = preg_match('~^https?://~', $cm['link']) ? mb_substr($cm['link'], 0, 500) : '';
    $postLink = preg_match('~^https?://~', $post['link']) ? mb_substr($post['link'], 0, 500) : '';
    $postId = qv('SELECT id FROM posts WHERE ext_id = ?', [$post['ext']]);
    $isQ = !$cm['mine'] && mk_is_question($text);
    $an = $cm['mine'] ? ['', '', '', '', ''] : [mk_classify($text, mk_meta_rules()['question']) ?: 'autre', mk_classify_profile($text), mk_extract_phone($text), mk_extract_email($text), mk_extract_city($text)];
    $now = time();
    $row = q1('SELECT id, text, likes, who_name FROM comments WHERE ext_id = ?', [$ext]);
    $parent = $cm['parent'] !== '' ? 'meta:comment:' . $cm['parent'] : '';
    if ($row) {
        db()->prepare("UPDATE comments SET text = ?, likes = ?, who_name = CASE WHEN ? != '' THEN ? ELSE who_name END, who_username = CASE WHEN ? != '' THEN ? ELSE who_username END,
            post_id = ?, post_link = ?, post_text = ?, link = CASE WHEN ? != '' THEN ? ELSE link END, is_question = ?, category = ?, profile = ?, phone = ?, email = ?, city = ?, synced = ? WHERE id = ?")
            ->execute([$text, $cm['likes'], $cm['name'], $cm['name'], $cm['username'], $cm['username'], $postId ?: null, $postLink, mb_substr($post['text'], 0, 300), $link, $link,
                $isQ ? 1 : 0, ...$an, $now, (int) $row['id']]);
        $res = $row['text'] === $text && (int) $row['likes'] === $cm['likes'] && ($cm['name'] === '' || $cm['name'] === $row['who_name']) ? 'same' : 'updated';
    } else {
        db()->prepare('INSERT INTO comments (ext_id, platform, post_ext, post_id, post_link, post_text, parent_ext, who_id, who_name, who_username, from_me, text, at, date, link, likes,
            is_question, category, profile, phone, email, city, status, created, synced) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ext, $platform, $post['ext'], $postId ?: null, $postLink, mb_substr($post['text'], 0, 300), $parent, $cm['who_id'], $cm['name'], $cm['username'], $cm['mine'] ? 1 : 0,
                $text, $at, substr($at, 0, 10), $link, $cm['likes'], $isQ ? 1 : 0, ...$an, $cm['mine'] ? 'traite' : 'a_traiter', $now, $now]);
        $res = 'added';
    }
    if ($isQ && mk_meta_state()['questions']) {
        mk_meta_question($q, $platform, (string) $cm['id'], $text, $cm['at'], $cm['name'], $cm['username'], $link ?: $postLink);
        $inq = qv('SELECT id FROM inquiries WHERE ext_id = ?', ['meta:cmt:' . $cm['id']]);
        if ($inq) db()->prepare('UPDATE comments SET inquiry_id = ? WHERE ext_id = ?')->execute([(int) $inq, $ext]);
    }
    return $res;
}

/** Nom affichable de l'auteur d'un commentaire. */
function mk_comment_name(array $c): string
{
    if ((int) $c['from_me']) return 'Vous (la Page)';
    if ((string) $c['who_name'] !== '') return (string) $c['who_name'];
    if ((string) $c['who_username'] !== '') return '@' . $c['who_username'];
    return $c['platform'] === 'instagram' ? 'Compte Instagram' : 'Nom masqué par Meta';
}

/**
 * Lead : nouveau, ou relié à un lead existant (même téléphone, à défaut même email).
 * Renvoie ['contact' => ligne, 'linked' => bool].
 */
function mk_prospect_save(array $data): array
{
    $match = null;
    $digits = substr((string) preg_replace('/\D/', '', (string) ($data['phone'] ?? '')), -9);
    if (strlen($digits) === 9) {
        foreach (q("SELECT id, phone FROM contacts WHERE phone != '' ORDER BY id DESC LIMIT 3000") as $r) {
            if (substr((string) preg_replace('/\D/', '', (string) $r['phone']), -9) === $digits) { $match = $r; break; }
        }
    }
    if (!$match && (string) ($data['email'] ?? '') !== '') $match = q1('SELECT id FROM contacts WHERE lower(email) = lower(?)', [$data['email']]);
    if ($match) return ['contact' => q1('SELECT * FROM contacts WHERE id = ?', [(int) $match['id']]), 'linked' => true];
    return ['contact' => mk_save('contacts', $data), 'linked' => false];
}

/** Crée le prospect de l'auteur d'un commentaire (ou le relie à un lead existant). */
function mk_comment_prospect(int $id, array $in): array
{
    $c = q1('SELECT * FROM comments WHERE id = ?', [$id]);
    if (!$c) throw new MkInputError('Commentaire introuvable (supprimé entre-temps ?).');
    if ($c['contact_id'] && q1('SELECT id FROM contacts WHERE id = ?', [(int) $c['contact_id']])) throw new MkInputError('Ce commentaire a déjà son prospect.');
    $plat = $c['platform'] === 'instagram' ? 'instagram' : 'facebook';
    $source = mk_meta_has_channel($plat) ? $plat : (mk_channels()[0]['key'] ?? '');
    $data = [
        'date' => (string) ($c['date'] ?: mk_today()),
        'name' => mk_str($in['name'] ?? mk_comment_name($c), 200),
        'phone' => mk_str($in['phone'] ?? $c['phone'], 60), 'email' => mk_str($in['email'] ?? $c['email'], 190), 'city' => mk_str($in['city'] ?? $c['city'], 80),
        'type' => (string) ($in['type'] ?? ($c['profile'] ?: mk_meta_state()['default_profile'])),
        'source' => (string) ($in['source'] ?? $source), 'status' => 'nouveau', 'assigned_to' => (string) ($in['assigned_to'] ?? ''),
        'notes' => trim(mk_str($in['notes'] ?? '', 1000) . "\n\nCommentaire " . ($plat === 'instagram' ? 'Instagram' : 'Facebook') . ' : ' . ($c['link'] ?: $c['post_link']) . "\n« " . mb_substr((string) $c['text'], 0, 600) . ' »'),
    ];
    $r = mk_prospect_save($data);
    db()->prepare("UPDATE comments SET contact_id = ?, status = 'traite' WHERE id = ?")->execute([(int) $r['contact']['id'], $id]);
    return $r;
}

/* ============================================================ temps réel : webhooks Meta */

/** Adresse que Meta appelle à chaque nouveau message ou commentaire. */
function mk_meta_webhook_url(): string
{
    return preg_replace('~meta\.php$~', 'webhook.php', mk_meta_redirect_uri());
}
/** Jeton que Meta renvoie pour vérifier l'adresse (créé une fois). */
function mk_meta_wh_token(): string
{
    $t = (string) mk_meta_state()['wh_token'];
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        mk_meta_put(['wh_token' => $t]);
    }
    return $t;
}
function mk_meta_webhook_public(): array
{
    $st = mk_meta_state();
    $w = (array) $st['webhook'];
    return ['on' => !empty($w['on']), 'at' => (int) ($w['at'] ?? 0), 'last_event' => (int) ($w['last_event'] ?? 0), 'events' => (int) ($w['events'] ?? 0),
        'steps' => array_values((array) ($w['steps'] ?? [])), 'url' => mk_meta_webhook_url(), 'verify_token' => (string) $st['wh_token']];
}

/** POST de l'API Graph (abonnements aux webhooks). */
function mk_graph_post(string $path, array $params, string $token, string $base = ''): array
{
    $params['access_token'] = $token;
    foreach ($params as $k => $v) if (is_array($v)) $params[$k] = implode(',', $v);
    $url = rtrim($base === 'ig' ? MK_META_IG_GRAPH_URL : MK_META_GRAPH_URL, '/') . '/' . MK_META_GRAPH_VERSION . '/' . ltrim($path, '/');
    [$code, $body] = mk_meta_http($url, [], $params, 30);
    $d = json_decode($body, true);
    if (!is_array($d)) throw new MkMetaError('Réponse illisible de Meta (HTTP ' . $code . ').', ['code' => $code]);
    if (isset($d['error']) && is_array($d['error'])) throw new MkMetaError((string) ($d['error']['message'] ?? 'Erreur Meta'), $d['error']);
    return $d;
}

/**
 * Active le temps réel : l'app Meta envoie au tableau de bord chaque nouveau message (Messenger,
 * Instagram) et chaque nouveau commentaire (Facebook, Instagram), et la Page (et le compte
 * Instagram) sont abonnés à l'app. Renvoie le résultat de chaque abonnement.
 */
function mk_meta_webhook_setup(): array
{
    $st = mk_meta_state();
    [$appId, $secret] = mk_meta_app();
    if ($appId === '' || $secret === '') throw new MkInputError("Ajoutez d'abord l'ID et la clé secrète de l'app Meta (étape 1 de Connexions) : le temps réel en a besoin.");
    $url = mk_meta_webhook_url();
    if (strpos($url, 'https://') !== 0 && !defined('MK_META_WEBHOOK_INSECURE')) throw new MkInputError("Le temps réel demande une adresse en https:// (Meta refuse le http). Adresse actuelle : $url");
    $verify = mk_meta_wh_token();
    $steps = [];
    $try = function (string $label, callable $fn) use (&$steps): void {
        try { $fn(); $steps[] = ['label' => $label, 'ok' => true, 'message' => '']; }
        catch (MkMetaError $e) { $steps[] = ['label' => $label, 'ok' => false, 'message' => $e->getMessage()]; }
    };
    $app = $appId . '|' . $secret;
    $try("Réception des messages et commentaires Facebook par l'app", fn () => mk_graph_post("$appId/subscriptions", ['object' => 'page', 'callback_url' => $url, 'verify_token' => $verify, 'fields' => ['messages', 'message_echoes', 'feed'], 'include_values' => 'true'], $app));
    if ($st['ig_id'] !== '') {
        // Toujours avec l'app Meta principale : l'« ID de l'app Instagram » (Se connecter avec Instagram) n'est pas
        // une app que l'API Graph sait valider (« Error validating application »).
        $try("Réception des messages et commentaires Instagram par l'app", fn () => mk_graph_post("$appId/subscriptions", ['object' => 'instagram', 'callback_url' => $url, 'verify_token' => $verify, 'fields' => ['comments', 'messages'], 'include_values' => 'true'], $app));
        $last = count($steps) - 1;
        if (!$steps[$last]['ok']) {
            $steps[$last]['message'] .= " — Réglez-le à la main dans l'app Meta : Instagram › Configuration de l'API (avec la connexion Instagram) › « Configurer les webhooks », "
                . "ou produit Webhooks › objet « Instagram » : URL de rappel et jeton de vérification ci-dessous, puis abonnez-vous à comments et messages.";
        }
    }
    if ($st['page_id'] !== '' && $st['page_token'] !== '') {
        $try('Abonnement de la Page « ' . $st['page_name'] . ' »', fn () => mk_graph_post($st['page_id'] . '/subscribed_apps', ['subscribed_fields' => ['messages', 'message_echoes', 'feed']], mk_unseal($st['page_token'])));
    }
    if ($st['ig_via'] === 'instagram' && $st['ig_token'] !== '') {
        $try('Abonnement du compte Instagram @' . $st['ig_username'], fn () => mk_graph_post('me/subscribed_apps', ['subscribed_fields' => ['comments', 'messages']], mk_unseal($st['ig_token']), 'ig'));
    }
    $okAll = !array_filter($steps, fn ($x) => !$x['ok']);
    $w = (array) $st['webhook'];
    mk_meta_put(['webhook' => array_merge($w, ['on' => (bool) array_filter($steps, fn ($x) => $x['ok']), 'at' => time(), 'steps' => $steps])]);
    return ['ok' => $okAll, 'steps' => $steps];
}

/** Envoi signé par Meta avec la clé secrète de l'app (ou de l'app Instagram) ? */
function mk_meta_wh_signed(string $raw, string $sig): bool
{
    if (!preg_match('/^sha256=([0-9a-f]{64})$/', $sig, $m)) return false;
    $st = mk_meta_state();
    $secrets = array_filter([mk_meta_app()[1], $st['ig_app_secret'] !== '' ? mk_unseal($st['ig_app_secret']) : '']);
    foreach ($secrets as $sec) if (hash_equals(hash_hmac('sha256', $raw, $sec), $m[1])) return true;
    return false;
}

/** Traite un envoi de Meta : nouveaux messages et commentaires. Renvoie le nombre d'événements pris en compte. */
function mk_meta_webhook_handle(array $d): int
{
    $obj = (string) ($d['object'] ?? '');
    $plat = $obj === 'instagram' ? 'instagram' : 'messenger';
    $n = 0;
    foreach ((array) ($d['entry'] ?? []) as $e) {
        if (!is_array($e)) continue;
        foreach ((array) ($e['messaging'] ?? []) as $ev) if (is_array($ev) && mk_meta_wh_message($plat, $ev)) $n++;
        foreach ((array) ($e['changes'] ?? []) as $ch) {
            if (!is_array($ch)) continue;
            $f = (string) ($ch['field'] ?? '');
            $v = is_array($ch['value'] ?? null) ? $ch['value'] : [];
            if ($obj === 'page' && $f === 'feed' && ($v['item'] ?? '') === 'comment') $n += mk_meta_wh_fb_comment($v) ? 1 : 0;
            elseif ($obj === 'instagram' && in_array($f, ['comments', 'live_comments'], true)) $n += mk_meta_wh_ig_comment($v) ? 1 : 0;
            elseif ($f === 'messages' && isset($v['message'])) $n += mk_meta_wh_message($plat, $v) ? 1 : 0;
        }
    }
    $w = (array) mk_meta_state(true)['webhook'];
    mk_meta_put(['webhook' => array_merge($w, ['last_event' => time(), 'events' => (int) ($w['events'] ?? 0) + $n])]);
    return $n;
}

/** Nom et nom d'utilisateur d'une personne qui écrit à la Page (quand Meta accepte de les donner). */
function mk_meta_wh_profile(string $platform, string $who): array
{
    $st = mk_meta_state();
    try {
        if ($platform === 'instagram' && $st['ig_via'] === 'instagram' && $st['ig_token'] !== '') $d = mk_graph($who, ['fields' => 'name,username'], mk_unseal($st['ig_token']), 'ig');
        else $d = mk_graph($who, ['fields' => $platform === 'instagram' ? 'name,username' : 'name'], mk_unseal($st['page_token']));
        return [(string) ($d['name'] ?? ''), (string) ($d['username'] ?? '')];
    } catch (Throwable $e) {
        return ['', ''];
    }
}

/** Un message reçu (ou envoyé par la Page) en temps réel. */
function mk_meta_wh_message(string $platform, array $ev): bool
{
    $m = $ev['message'] ?? null;
    if (!is_array($m) || empty($m['mid']) || !empty($m['is_deleted'])) return false;
    $st = mk_meta_state();
    $own = array_values(array_filter([$st['page_id'], $st['ig_id'], $st['ig_node'] !== 'me' ? $st['ig_node'] : '']));
    $from = (string) ($ev['sender']['id'] ?? '');
    $to = (string) ($ev['recipient']['id'] ?? '');
    $mine = !empty($m['is_echo']) || in_array($from, $own, true);
    $who = $mine ? $to : $from;
    if ($who === '') return false;
    $text = trim((string) ($m['text'] ?? ''));
    if ($text === '') $text = !empty($m['attachments']) ? '[pièce jointe]' : '[message]';
    $ts = (int) ($ev['timestamp'] ?? 0);
    $at = mk_meta_local($ts > 0 ? gmdate('c', $ts > 20000000000 ? intdiv($ts, 1000) : $ts) : '');
    $conv = q1('SELECT id FROM conversations WHERE platform = ? AND who_id = ? ORDER BY id DESC LIMIT 1', [$platform, $who]);
    if ($conv) {
        $id = (int) $conv['id'];
    } else {
        [$name, $user] = $mine ? ['', ''] : mk_meta_wh_profile($platform, $who);
        $now = time();
        db()->prepare('INSERT INTO conversations (ext_id, platform, channel, who_id, who_name, who_username, updated, last_date, first_date, link, status, created, synced)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'a_traiter\', ?, ?)')->execute(['meta:psid:' . $platform . ':' . $who, $platform, $platform === 'instagram' ? 'instagram' : 'facebook', $who,
            $name, $user, $at, substr($at, 0, 10), substr($at, 0, 10), 'https://business.facebook.com/latest/inbox/all?asset_id=' . rawurlencode($st['page_id']), $now, $now]);
        $id = (int) db()->lastInsertId();
    }
    $ins = db()->prepare('INSERT OR IGNORE INTO conversation_messages (conv_id, ext_id, from_me, text, at) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$id, 'meta:msg:' . $m['mid'], $mine ? 1 : 0, mb_substr($text, 0, 4000), $at]);
    if (!$ins->rowCount()) return false;
    db()->prepare('UPDATE conversations SET updated = MAX(updated, ?), last_date = MAX(last_date, ?), message_count = (SELECT COUNT(*) FROM conversation_messages WHERE conv_id = ?), synced = ? WHERE id = ?')
        ->execute([$at, substr($at, 0, 10), $id, time(), $id]);
    mk_conv_reopen($id);
    mk_conv_analyze($id);
    return true;
}

/** Un commentaire Facebook ajouté, modifié ou supprimé en temps réel. */
function mk_meta_wh_fb_comment(array $v): bool
{
    $st = mk_meta_state();
    $cid = (string) ($v['comment_id'] ?? '');
    $pid = (string) ($v['post_id'] ?? '');
    if ($cid === '' || $pid === '') return false;
    if (($v['verb'] ?? '') === 'remove') {
        db()->prepare('DELETE FROM comments WHERE ext_id = ?')->execute(['meta:comment:' . $cid]);
        return true;
    }
    if (!mk_meta_has_channel('facebook')) return false;
    $postExt = 'meta:fbpost:' . $pid;
    $p = q1('SELECT link, topic FROM posts WHERE ext_id = ?', [$postExt]) ?: ['link' => '', 'topic' => ''];
    $parent = (string) ($v['parent_id'] ?? '');
    $created = $v['created_time'] ?? time();
    $q = mk_meta_counter('questions');
    $from = (string) ($v['from']['id'] ?? '');
    mk_meta_store_comment('facebook', ['ext' => $postExt, 'link' => (string) $p['link'], 'text' => (string) $p['topic']], [
        'id' => $cid, 'parent' => $parent !== '' && $parent !== $pid ? $parent : '', 'who_id' => $from, 'name' => (string) ($v['from']['name'] ?? ''), 'username' => '',
        'text' => (string) ($v['message'] ?? ''), 'at' => is_numeric($created) ? gmdate('c', (int) $created) : (string) $created, 'link' => '',
        'likes' => (int) qv('SELECT likes FROM comments WHERE ext_id = ?', ['meta:comment:' . $cid]), 'mine' => $from !== '' && $from === $st['page_id'],
    ], $q);
    mk_meta_comments_thread($postExt);
    return true;
}

/** Un commentaire Instagram reçu en temps réel. */
function mk_meta_wh_ig_comment(array $v): bool
{
    $st = mk_meta_state();
    $cid = (string) ($v['id'] ?? '');
    $mid = (string) ($v['media']['id'] ?? '');
    if ($cid === '' || $mid === '' || !mk_meta_has_channel('instagram')) return false;
    $postExt = 'meta:igmedia:' . $mid;
    $p = q1('SELECT link, topic FROM posts WHERE ext_id = ?', [$postExt]) ?: ['link' => '', 'topic' => ''];
    $user = (string) ($v['from']['username'] ?? '');
    $q = mk_meta_counter('questions');
    mk_meta_store_comment('instagram', ['ext' => $postExt, 'link' => (string) $p['link'], 'text' => (string) $p['topic']], [
        'id' => $cid, 'parent' => (string) ($v['parent_id'] ?? ''), 'who_id' => (string) ($v['from']['id'] ?? ''), 'name' => '', 'username' => $user,
        'text' => (string) ($v['text'] ?? ''), 'at' => gmdate('c'), 'link' => '', 'likes' => (int) qv('SELECT likes FROM comments WHERE ext_id = ?', ['meta:comment:' . $cid]),
        'mine' => ($user !== '' && strcasecmp($user, (string) $st['ig_username']) === 0) || in_array((string) ($v['from']['id'] ?? ''), [$st['ig_id']], true),
    ], $q);
    mk_meta_comments_thread($postExt);
    return true;
}

/**
 * Champs demandés pour la liste des conversations, du plus complet au plus simple : selon le compte
 * (Messenger, Instagram) Meta en refuse certains ; on passe alors au suivant, et on s'en souvient.
 */
const MK_META_CONV_FIELDS = [
    'id,updated_time,link,participants,message_count,messages.limit(25){id,message,from,created_time,sticker,attachments{mime_type,name}}',
    'id,updated_time,link,participants,messages.limit(25){id,message,from,created_time}',
    'id,updated_time,participants,messages.limit(25){id,message,from,created_time}',
    'id,updated_time,participants,messages{id,message,from,created_time}',
];

/** Accès possibles aux messages d'une plateforme : [nom, nœud, jeton, base]. */
function mk_meta_msg_routes(string $platform): array
{
    $st = mk_meta_state();
    $r = [];
    if ($st['page_id'] !== '' && $st['page_token'] !== '') $r[] = ['Page Facebook', $st['page_id'], mk_unseal($st['page_token']), ''];
    // Instagram relié par « Se connecter avec Instagram » : ses messages se lisent aussi avec son propre jeton.
    if ($platform === 'instagram' && $st['ig_via'] === 'instagram' && $st['ig_token'] !== '') $r[] = ['compte Instagram', $st['ig_node'] !== '' ? $st['ig_node'] : 'me', mk_unseal($st['ig_token']), 'ig'];
    return $r;
}

/** Ce qu'il faut faire quand Meta refuse de donner les messages. */
function mk_meta_msg_hint(string $platform, MkMetaError $e): string
{
    $c = $e->metaCode();
    if (in_array($c, [4, 17, 32, 613, 80006], true)) return 'Meta limite les requêtes pour le moment : nouvel essai au prochain passage.';
    if ($platform === 'instagram') return "Vérifiez : 1) l'autorisation instagram_manage_messages (et pages_manage_metadata) dans la configuration Facebook Login for Business, puis « Reconnecter » ; 2) dans l'application Instagram : Paramètres › Messages et réponses aux stories › Outils connectés › « Autoriser l'accès aux messages ».";
    return "Vérifiez que pages_messaging et pages_manage_metadata sont dans la configuration Facebook Login for Business, puis cliquez sur « Reconnecter » (Connexions, étape 2).";
}

/** Messages Messenger (Page) et Instagram (compte relié à la Page, ou relié par « Se connecter avec Instagram »). */
function mk_meta_sync_messages(string $from, string $to): array
{
    $st = mk_meta_state();
    $out = [];
    $full = mk_meta_full();
    $since = $full ? 0 : strtotime(mk_add_days($from, -1) . ' 00:00:00');
    foreach (['messenger' => 'facebook', 'instagram' => 'instagram'] as $platform => $channel) {
        if ($platform === 'instagram' && $st['ig_id'] === '') continue;
        $c = mk_meta_counter($platform === 'messenger' ? 'conversations Messenger' : 'conversations Instagram');
        $c['messages'] = 0;
        $c['platform'] = $platform;
        if (mk_meta_more()) { $out[] = $c; continue; } // plus le temps : Instagram au lot suivant
        $routes = mk_meta_msg_routes($platform);
        $lastErr = null;
        foreach ($routes as [$via, $node, $tok, $base]) {
            $level = (int) (mk_meta_state()['metrics']['conv_fields_' . $platform . $base] ?? 0);
            $params = ['platform' => $platform, 'limit' => 25];
            $cursor = $full ? (string) (mk_meta_state()['full_cursor'][$platform] ?? '') : '';
            if ($cursor !== '') $params['after'] = $cursor;
            try {
                for ($page = 0; $page < ($full ? 400 : 40); $page++) {
                    // Champs refusés par Meta : on réessaie avec une liste plus simple, et on la garde pour la suite.
                    for (;;) {
                        $params['fields'] = MK_META_CONV_FIELDS[min($level, count(MK_META_CONV_FIELDS) - 1)];
                        try {
                            $d = mk_graph("$node/conversations", $params, $tok, $base);
                            break;
                        } catch (MkMetaError $e) {
                            if ($level >= count(MK_META_CONV_FIELDS) - 1 || !in_array($e->metaCode(), [1, 2, 12, 100], true)) throw $e;
                            $level++;
                            mk_meta_put(['metrics' => array_merge((array) mk_meta_state()['metrics'], ['conv_fields_' . $platform . $base => $level])]);
                        }
                    }
                    $stop = false;
                    foreach (($d['data'] ?? []) as $cv) {
                        if (strtotime((string) ($cv['updated_time'] ?? 'now')) < $since) { $stop = true; break; }
                        if (mk_meta_time_left() < 6) { mk_meta_more(true); break 2; }
                        // Une conversation illisible ne bloque pas les autres.
                        try {
                            mk_meta_count($c, mk_meta_store_conversation($cv, $platform, $channel, $tok, $c['messages'], $base));
                        } catch (MkMetaError $e) {
                            if (in_array($e->metaCode(), [190, 4, 17, 32, 613, 80006], true)) throw $e;
                            $c['errors']++;
                            if ($c['note'] === '') $c['note'] = 'Une conversation n\'a pas pu être lue (« ' . rtrim($e->getMessage(), '.') . ' »).';
                        }
                    }
                    $after = $d['paging']['cursors']['after'] ?? null;
                    if ($stop || !$after || empty($d['paging']['next'])) break;
                    $params['after'] = $after;
                }
                if ($full) {
                    // Lot interrompu : on reprendra à la page en cours (ses conversations déjà lues passent vite).
                    $fc = (array) (mk_meta_state()['full_cursor'] ?? []);
                    if (mk_meta_more()) $fc[$platform] = (string) ($params['after'] ?? ''); else unset($fc[$platform]);
                    mk_meta_put(['full_cursor' => $fc]);
                }
                $c['via'] = $via;
                $lastErr = null;
                break; // cet accès marche : pas besoin du suivant
            } catch (MkMetaError $e) {
                if ($e->metaCode() === 190) throw $e;
                $lastErr = $e;
            }
        }
        if (!$routes) $c['note'] = $platform === 'messenger' ? 'Aucune Page Facebook choisie (Connexions, étape 3).' : 'Aucun accès au compte Instagram.';
        if ($lastErr || !$routes) {
            if ($lastErr) $c['note'] = ($platform === 'messenger' ? 'Messages Messenger' : 'Messages Instagram') . ' inaccessibles : Meta répond « ' . rtrim($lastErr->getMessage(), '.') . ' ». ' . mk_meta_msg_hint($platform, $lastErr);
            $c['failed'] = true;
        }
        $out[] = $c;
    }
    // Bilan affiché dans Leads › Messages (et gardé même pour les vérifications rapides).
    mk_meta_put(['msg_diag' => ['at' => time(), 'items' => array_map(fn ($c) => ['label' => $c['platform'] === 'instagram' ? 'Instagram' : 'Messenger', 'ok' => empty($c['failed']),
        'added' => (int) $c['added'], 'updated' => (int) $c['updated'], 'messages' => (int) $c['messages'], 'errors' => (int) $c['errors'], 'note' => (string) $c['note'], 'via' => (string) ($c['via'] ?? '')], $out)]]);
    // Aucune messagerie lisible : l'étape est en erreur, avec la marche à suivre.
    if ($out && !array_filter($out, fn ($c) => empty($c['failed']))) throw new MkInputError(implode(' ', array_column($out, 'note')));
    return $out;
}

/** Texte d'un message : son texte, ou ce qu'il contient (photo, vocal, autocollant…). */
function mk_meta_msg_text(array $m): string
{
    $t = trim((string) ($m['message'] ?? ''));
    if ($t !== '') return $t;
    if (!empty($m['sticker'])) return '[autocollant]';
    $a = $m['attachments']['data'][0] ?? null;
    if (is_array($a)) {
        $mime = (string) ($a['mime_type'] ?? '');
        if (strpos($mime, 'image/') === 0) return '[photo]';
        if (strpos($mime, 'video/') === 0) return '[vidéo]';
        if (strpos($mime, 'audio/') === 0) return '[message vocal]';
        if (!empty($a['name'])) return '[fichier : ' . mb_substr((string) $a['name'], 0, 80) . ']';
    }
    return '[pièce jointe]';
}

/** Enregistre une conversation et ses derniers messages, puis l'analyse. Renvoie added / updated / same. */
function mk_meta_store_conversation(array $cv, string $platform, string $channel, string $tok, int &$msgCount, string $base = ''): string
{
    $st = mk_meta_state();
    $own = array_values(array_filter([$st['page_id'], $st['ig_id'], $st['ig_node'] !== 'me' ? $st['ig_node'] : '']));
    $who = null;
    foreach (($cv['participants']['data'] ?? []) as $p) if (!in_array((string) ($p['id'] ?? ''), $own, true)) { $who = $p; break; }
    $msgs = $cv['messages']['data'] ?? [];
    // Certains comptes ne renvoient que les identifiants des messages : on lit alors les 20 plus récents un par un.
    if ($msgs && !array_key_exists('message', $msgs[0]) && !array_key_exists('from', $msgs[0])) {
        $full = [];
        foreach (array_slice($msgs, 0, 20) as $m) {
            try { $full[] = mk_graph((string) $m['id'], ['fields' => 'id,message,from,created_time'], $tok, $base); } catch (MkMetaError $e) { if ($e->metaCode() === 190) throw $e; }
        }
        $msgs = $full;
    }
    $ext = 'meta:conv:' . $cv['id'];
    if (qv('SELECT 1 FROM ext_ignored WHERE ext_id = ?', [$ext])) return 'ignored';
    $row = q1('SELECT id FROM conversations WHERE ext_id = ?', [$ext]);
    if (!$row && !empty($who['id'])) {
        // Conversation déjà créée par le temps réel (webhook) : elle prend son identifiant Meta.
        $row = q1("SELECT id FROM conversations WHERE platform = ? AND who_id = ? AND ext_id LIKE 'meta:psid:%'", [$platform, (string) $who['id']]);
        if ($row) db()->prepare('UPDATE conversations SET ext_id = ? WHERE id = ?')->execute([$ext, (int) $row['id']]);
    }
    $whoName = (string) ($who['name'] ?? '');
    $whoUser = (string) ($who['username'] ?? '');
    $updated = mk_meta_local((string) ($cv['updated_time'] ?? ''));
    $link = (string) ($cv['link'] ?? '');
    if ($link !== '' && $link[0] === '/') $link = 'https://www.facebook.com' . $link;
    if ($link === '') $link = 'https://business.facebook.com/latest/inbox/all?asset_id=' . rawurlencode($st['page_id']);
    $now = time();
    if ($row) {
        $id = (int) $row['id'];
        db()->prepare('UPDATE conversations SET who_name = CASE WHEN ? != \'\' THEN ? ELSE who_name END, who_username = CASE WHEN ? != \'\' THEN ? ELSE who_username END,
            updated = ?, last_date = ?, link = ?, synced = ? WHERE id = ?')->execute([$whoName, $whoName, $whoUser, $whoUser, $updated, substr($updated, 0, 10), $link, $now, $id]);
        $res = 'updated';
    } else {
        db()->prepare('INSERT INTO conversations (ext_id, platform, channel, who_id, who_name, who_username, updated, last_date, first_date, link, status, created, synced)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'a_traiter\', ?, ?)')->execute([$ext, $platform, $channel, (string) ($who['id'] ?? ''), $whoName, $whoUser,
            $updated, substr($updated, 0, 10), substr($updated, 0, 10), $link, $now, $now]);
        $id = (int) db()->lastInsertId();
        $res = 'added';
    }
    $store = function (array $list) use ($id, $own, $whoName, $whoUser): int {
        $n = 0;
        foreach ($list as $m) {
            $text = mk_meta_msg_text($m);
            $fromId = (string) ($m['from']['id'] ?? '');
            $fromMe = in_array($fromId, $own, true) ? 1 : 0;
            $s = db()->prepare('INSERT OR IGNORE INTO conversation_messages (conv_id, ext_id, from_me, text, at) VALUES (?, ?, ?, ?, ?)');
            $s->execute([$id, 'meta:msg:' . ($m['id'] ?? md5($text . ($m['created_time'] ?? ''))), $fromMe, mb_substr($text, 0, 4000), mk_meta_local((string) ($m['created_time'] ?? ''))]);
            $n += $s->rowCount();
            if (!$fromMe && $whoName === '' && !empty($m['from']['name'])) {
                db()->prepare("UPDATE conversations SET who_name = ? WHERE id = ? AND who_name = ''")->execute([(string) $m['from']['name'], $id]);
            }
            if (!$fromMe && $whoUser === '' && !empty($m['from']['username'])) {
                db()->prepare("UPDATE conversations SET who_username = ? WHERE id = ? AND who_username = ''")->execute([(string) $m['from']['username'], $id]);
            }
        }
        return $n;
    };
    $n = $store($msgs);
    $new = $n;
    // Conversation plus longue que ce que Meta renvoie d'un coup : on lit les messages plus anciens,
    // jusqu'à retrouver ceux déjà importés (ou jusqu'au tout premier en mode « tout l'historique »).
    $paging = $cv['messages']['paging'] ?? [];
    $full = mk_meta_full();
    // Tout l'historique : on lit les pages tant qu'il manque des messages (Meta donne leur nombre).
    $total = isset($cv['message_count']) ? (int) $cv['message_count'] : null;
    $missing = fn () => $total === null || (int) qv('SELECT COUNT(*) FROM conversation_messages WHERE conv_id = ?', [$id]) < $total;
    try {
        for ($p = 0; $msgs && $p < ($full ? 200 : 40) && !empty($paging['next']) && !empty($paging['cursors']['after']) && ($full ? $missing() : $n >= count($msgs)) && mk_meta_time_left() > 4; $p++) {
            $d = mk_graph((string) $cv['id'] . '/messages', ['fields' => 'id,message,from,created_time', 'limit' => 100, 'after' => $paging['cursors']['after']], $tok, $base);
            $msgs = $d['data'] ?? [];
            $n = $store($msgs);
            $new += $n;
            $paging = $d['paging'] ?? [];
        }
    } catch (MkMetaError $e) {
        // Les messages plus anciens n'ont pas pu être lus : on garde ceux déjà importés.
        if (in_array($e->metaCode(), [190, 4, 17, 32, 613], true)) throw $e;
    }
    $msgCount += $new;
    if ($new) mk_conv_reopen($id);
    db()->prepare('UPDATE conversations SET first_date = COALESCE((SELECT substr(MIN(at), 1, 10) FROM conversation_messages WHERE conv_id = ?), first_date), message_count = (SELECT COUNT(*) FROM conversation_messages WHERE conv_id = ?) WHERE id = ?')->execute([$id, $id, $id]);
    mk_conv_analyze($id);
    return $res === 'updated' && !$new ? 'same' : $res;
}

/** Nouveau message de la personne dans une conversation « traitée » : elle redevient à traiter. */
function mk_conv_reopen(int $id): void
{
    $last = q1('SELECT from_me FROM conversation_messages WHERE conv_id = ? ORDER BY at DESC, id DESC LIMIT 1', [$id]);
    if ($last && !(int) $last['from_me']) db()->prepare("UPDATE conversations SET status = 'a_traiter' WHERE id = ? AND status = 'traite'")->execute([$id]);
}

/** Date et heure locales (AAAA-MM-JJ HH:MM:SS) d'un horodatage Meta. */
function mk_meta_local(string $iso): string
{
    try {
        return (new DateTimeImmutable($iso !== '' ? $iso : 'now'))->setTimezone(new DateTimeZone(MK_TIMEZONE))->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d H:i:s');
    }
}

/** Nom affichable d'une conversation. */
function mk_conv_name(array $c): string
{
    if ((string) $c['who_name'] !== '') return (string) $c['who_name'];
    if ((string) $c['who_username'] !== '') return '@' . $c['who_username'];
    return $c['platform'] === 'instagram' ? 'Compte Instagram' : 'Personne sur Messenger';
}

/**
 * Crée le prospect (lead) d'une conversation avec les informations trouvées, ou le relie à un lead
 * existant (même téléphone ou même email). $in : champs du formulaire, déjà relus par l'équipe.
 */
function mk_conv_prospect(int $id, array $in): array
{
    $c = q1('SELECT * FROM conversations WHERE id = ?', [$id]);
    if (!$c) throw new MkInputError('Conversation introuvable (supprimée entre-temps ?).');
    if ($c['contact_id'] && q1('SELECT id FROM contacts WHERE id = ?', [(int) $c['contact_id']])) throw new MkInputError('Cette conversation a déjà son prospect.');
    $msgs = q('SELECT from_me, text, at FROM conversation_messages WHERE conv_id = ? ORDER BY at DESC, id DESC LIMIT 6', [$id]);
    $excerpt = implode("\n", array_map(fn ($m) => ((int) $m['from_me'] ? 'Nous' : 'Prospect') . ' (' . substr((string) $m['at'], 0, 16) . ') : ' . mb_substr((string) $m['text'], 0, 200), array_reverse($msgs)));
    $source = mk_meta_has_channel($c['platform'] === 'instagram' ? 'instagram' : 'messenger') ? ($c['platform'] === 'instagram' ? 'instagram' : 'messenger') : (mk_meta_has_channel('facebook') ? 'facebook' : (mk_channels()[0]['key'] ?? ''));
    $data = [
        'date' => (string) ($c['first_date'] ?: mk_today()),
        'name' => mk_str($in['name'] ?? mk_conv_name($c), 200),
        'phone' => mk_str($in['phone'] ?? $c['phone'], 60), 'email' => mk_str($in['email'] ?? $c['email'], 190), 'city' => mk_str($in['city'] ?? $c['city'], 80),
        'type' => (string) ($in['type'] ?? ($c['profile'] ?: mk_meta_state()['default_profile'])),
        'source' => (string) ($in['source'] ?? $source), 'status' => 'nouveau', 'assigned_to' => (string) ($in['assigned_to'] ?? ''),
        'notes' => trim(mk_str($in['notes'] ?? '', 1000) . "\n\nConversation " . ($c['platform'] === 'instagram' ? 'Instagram' : 'Messenger') . ' : ' . $c['link'] . "\n" . $excerpt),
    ];
    // Même personne déjà enregistrée (même téléphone ou même email) : on relie au lieu de dupliquer.
    ['contact' => $contact, 'linked' => $linked] = mk_prospect_save($data);
    db()->prepare("UPDATE conversations SET contact_id = ?, status = 'prospect' WHERE id = ?")->execute([(int) $contact['id'], $id]);
    return ['contact' => $contact, 'linked' => $linked];
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
