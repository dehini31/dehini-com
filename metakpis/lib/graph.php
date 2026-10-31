<?php
/**
 * API Marketing de Meta (Graph API) pour Stats Meta KPIs :
 *  - connexion « Facebook Login » (jeton longue durée, chiffré en base) ;
 *  - statistiques avec ventilations (âge, genre, placement) et textes des annonces ;
 *  - création d'une campagne proposée (campagne + ensemble + publicité, EN PAUSE),
 *    puis publication à la demande de l'utilisateur.
 *
 * L'app Meta (ID + clé secrète) se règle dans metakpis/admin.html ou config.php.
 */
declare(strict_types=1);

const META_SCOPES = 'ads_read,ads_management';
const PURCHASE_TYPES = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'onsite_web_purchase'];
const INSIGHT_FIELDS = 'spend,impressions,reach,frequency,clicks,inline_link_clicks,inline_link_click_ctr,cost_per_inline_link_click,cpm,ctr,actions,action_values,video_thruplay_watched_actions';
const CREATIVE_FIELDS = 'id,name,title,body,object_type,call_to_action_type,link_url,object_url,thumbnail_url,image_url,object_story_spec,asset_feed_spec,effective_object_story_id';
// Devises sans décimales pour l'API (budget envoyé en unités, pas en centimes).
const ZERO_DECIMAL = ['CLP', 'COP', 'CRC', 'HUF', 'ISK', 'IDR', 'JPY', 'KRW', 'PYG', 'TWD', 'VND'];
// Placements acceptés à la création (noms de l'API de ciblage).
const POSITIONS = [
    'facebook'         => ['feed', 'right_hand_column', 'marketplace', 'video_feeds', 'story', 'search', 'instream_video', 'facebook_reels', 'facebook_reels_overlay', 'profile_feed', 'notification'],
    'instagram'        => ['stream', 'story', 'explore', 'explore_home', 'reels', 'profile_feed', 'ig_search', 'profile_reels'],
    'audience_network' => ['classic', 'rewarded_video'],
    'messenger'        => ['messenger_home', 'story'],
];
// Anciens objectifs → objectifs actuels (les anciens ne sont plus acceptés à la création).
const OBJECTIVE_MAP = [
    'CONVERSIONS' => 'OUTCOME_SALES', 'PRODUCT_CATALOG_SALES' => 'OUTCOME_SALES', 'LINK_CLICKS' => 'OUTCOME_TRAFFIC',
    'POST_ENGAGEMENT' => 'OUTCOME_ENGAGEMENT', 'PAGE_LIKES' => 'OUTCOME_ENGAGEMENT', 'EVENT_RESPONSES' => 'OUTCOME_ENGAGEMENT',
    'MESSAGES' => 'OUTCOME_ENGAGEMENT', 'VIDEO_VIEWS' => 'OUTCOME_ENGAGEMENT', 'LEAD_GENERATION' => 'OUTCOME_LEADS',
    'BRAND_AWARENESS' => 'OUTCOME_AWARENESS', 'REACH' => 'OUTCOME_AWARENESS', 'APP_INSTALLS' => 'OUTCOME_APP_PROMOTION',
    'STORE_VISITS' => 'OUTCOME_AWARENESS',
];

final class GraphError extends RuntimeException
{
    public array $err;
    public function __construct(string $msg, array $err = [])
    {
        parent::__construct($msg);
        $this->err = $err;
    }
    /** Message lisible pour l'utilisateur (Meta le fournit souvent dans sa langue). */
    public function userMessage(): string
    {
        $e = $this->err;
        $t = trim((string) ($e['error_user_title'] ?? ''));
        $m = trim((string) ($e['error_user_msg'] ?? ''));
        if ($m !== '') return $t !== '' ? $t . ' — ' . $m : $m;
        return (string) ($e['message'] ?? $this->getMessage());
    }
}

/**
 * Variables Meta d'ecom.dehini.com, lues dans son fichier .env (même compte cPanel, ~/ecom/.env).
 * Seules META_APP_ID et META_APP_SECRET sont lues ; le reste du fichier est ignoré.
 */
function ecom_meta_env(): array
{
    static $env = null;
    if ($env !== null) return $env;
    $env = [];
    $f = (string) METAKPIS_ECOM_ENV;
    if ($f === '' || !is_file($f) || !is_readable($f)) return $env;
    foreach ((array) @file($f, FILE_IGNORE_NEW_LINES) as $line) {
        if (!preg_match('/^\s*(?:export\s+)?(META_APP_ID|META_APP_SECRET)\s*=\s*(.*)$/', (string) $line, $m)) continue;
        $v = trim($m[2]);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            $q = $v[0];
            $end = strpos($v, $q, 1);
            $v = $end === false ? substr($v, 1) : substr($v, 1, $end - 1);
        } else {
            $v = trim((string) preg_replace('/\s+#.*$/', '', $v));
        }
        $env[$m[1]] = $v;
    }
    return $env;
}

/** D'où vient l'app Meta : « admin », « config », « ecom » ou « » (aucune). */
function meta_app_source(): string
{
    if (trim(setting('meta_app_id')) !== '') return 'admin';
    if (defined('METAKPIS_META_APP_ID') && (string) METAKPIS_META_APP_ID !== '') return 'config';
    return (ecom_meta_env()['META_APP_ID'] ?? '') !== '' ? 'ecom' : '';
}

/**
 * App Meta : réglage de l'admin, sinon config.php, sinon celle d'ecom.dehini.com.
 * L'ID et la clé secrète viennent toujours de la même source (jamais l'ID de l'une avec la clé de l'autre).
 */
function meta_app_id(): string
{
    switch (meta_app_source()) {
        case 'admin': return trim(setting('meta_app_id'));
        case 'config': return (string) METAKPIS_META_APP_ID;
        case 'ecom': return (string) ecom_meta_env()['META_APP_ID'];
    }
    return '';
}
function meta_app_secret(): string
{
    switch (meta_app_source()) {
        case 'admin': return trim(setting('meta_app_secret'));
        case 'config': return defined('METAKPIS_META_APP_SECRET') ? (string) METAKPIS_META_APP_SECRET : '';
        case 'ecom': return (string) (ecom_meta_env()['META_APP_SECRET'] ?? '');
    }
    return '';
}
function meta_config_id(): string
{
    return trim(setting('meta_config_id'));
}
function graph_ver(): string
{
    $v = trim(setting('graph_version'));
    return preg_match('/^v\d{2}\.0$/', $v) ? $v : METAKPIS_GRAPH_VERSION;
}
function meta_ready(): bool
{
    return meta_app_id() !== '' && meta_app_secret() !== '';
}
function meta_redirect_uri(): string
{
    return site_url() . 'meta.php';
}

/** Appel Graph API. Les tableaux sont envoyés en JSON, comme l'attend l'API. */
function graph(string $method, string $path, array $params, string $token): array
{
    $params['access_token'] = $token;
    $params['appsecret_proof'] = hash_hmac('sha256', $token, meta_app_secret());
    foreach ($params as $k => $v) {
        if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        elseif (is_bool($v)) $params[$k] = $v ? 'true' : 'false';
    }
    $url = rtrim(METAKPIS_GRAPH_URL, '/') . '/' . graph_ver() . '/' . ltrim($path, '/');
    [$code, $body] = $method === 'GET' ? http_req($url, $params, null, 90) : http_req($url, [], $params, 90);
    $d = json_decode($body, true);
    if (!is_array($d)) throw new GraphError('Réponse illisible de Meta (HTTP ' . $code . ')', ['code' => $code]);
    if (isset($d['error']) && is_array($d['error'])) throw new GraphError((string) ($d['error']['message'] ?? 'Erreur Meta'), $d['error']);
    return $d;
}

/** Toutes les pages d'une liste (curseur « after »), dans la limite de $maxRows lignes. */
function graph_all(string $path, array $params, string $token, int $maxRows = 5000): array
{
    $rows = [];
    $params['limit'] = $params['limit'] ?? 500;
    for ($i = 0; $i < 40; $i++) {
        $d = graph('GET', $path, $params, $token);
        foreach (($d['data'] ?? []) as $r) $rows[] = $r;
        $after = $d['paging']['cursors']['after'] ?? null;
        if (!$after || empty($d['paging']['next']) || count($rows) >= $maxRows) break;
        $params['after'] = $after;
    }
    return $rows;
}

/* ------------------------------------------------------------ connexion */

function meta_login_url(string $state): string
{
    $q = ['client_id' => meta_app_id(), 'redirect_uri' => meta_redirect_uri(), 'state' => $state, 'response_type' => 'code'];
    if (meta_config_id() !== '') $q['config_id'] = meta_config_id(); // Facebook Login for Business
    else $q['scope'] = META_SCOPES;
    return 'https://www.facebook.com/' . graph_ver() . '/dialog/oauth?' . http_build_query($q);
}

/** Code OAuth → jeton longue durée (≈ 60 jours) + identité Meta. */
function meta_exchange(string $code): array
{
    $base = rtrim(METAKPIS_GRAPH_URL, '/') . '/' . graph_ver() . '/oauth/access_token';
    [, $body] = http_req($base, ['client_id' => meta_app_id(), 'redirect_uri' => meta_redirect_uri(), 'client_secret' => meta_app_secret(), 'code' => $code]);
    $d = json_decode($body, true);
    if (empty($d['access_token'])) throw new GraphError('Échange du code refusé', $d['error'] ?? []);
    [, $body] = http_req($base, ['grant_type' => 'fb_exchange_token', 'client_id' => meta_app_id(), 'client_secret' => meta_app_secret(), 'fb_exchange_token' => $d['access_token']]);
    $l = json_decode($body, true);
    $tok = !empty($l['access_token']) ? (string) $l['access_token'] : (string) $d['access_token'];
    $exp = !empty($l['expires_in']) ? time() + (int) $l['expires_in'] : (!empty($d['expires_in']) ? time() + (int) $d['expires_in'] : 0);
    $me = graph('GET', 'me', ['fields' => 'id,name'], $tok);
    return ['token' => $tok, 'expires' => $exp, 'id' => (string) $me['id'], 'name' => (string) ($me['name'] ?? '')];
}

function meta_conn(int $uid): ?array
{
    $s = db()->prepare('SELECT * FROM meta_conn WHERE uid = ?');
    $s->execute([$uid]);
    return $s->fetch() ?: null;
}

/** Jeton Meta déchiffré de l'utilisateur, ou erreur « meta_off » / « meta_expired ». */
function meta_token(int $uid): string
{
    if (!meta_ready()) fail('meta_unconfigured', 503);
    $c = meta_conn($uid);
    if (!$c) fail('meta_off', 409);
    if ((int) $c['expires'] && (int) $c['expires'] < time()) {
        db()->prepare('DELETE FROM meta_conn WHERE uid = ?')->execute([$uid]);
        fail('meta_expired', 409);
    }
    $t = unseal((string) $c['token']);
    if ($t === null) fail('meta_expired', 409);
    return $t;
}

/** Erreur Graph → réponse JSON (jeton révoqué : on oublie la connexion). */
function graph_fail(GraphError $e, int $uid): void
{
    $code = (int) ($e->err['code'] ?? 0);
    if ($code === 190) {
        db()->prepare('DELETE FROM meta_conn WHERE uid = ?')->execute([$uid]);
        fail('meta_expired', 409);
    }
    if (in_array($code, [4, 17, 32, 613, 80000, 80004], true)) out(['error' => 'meta_rate', 'message' => $e->userMessage()], 429);
    out(['error' => 'meta_error', 'message' => $e->userMessage(), 'code' => $code], 400);
}

function valid_act($a): string
{
    $a = preg_replace('/^act_/', '', (string) $a);
    if (!preg_match('/^\d{3,20}$/', $a)) fail('bad_account');
    return $a;
}
function valid_id($a): string
{
    $a = (string) $a;
    if (!preg_match('/^\d{3,25}$/', $a)) fail('bad_id');
    return $a;
}
function valid_date($d): string
{
    $d = (string) $d;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !strtotime($d)) fail('bad_date');
    return $d;
}

/** Infos du compte publicitaire (nom, devise), gardées en cache pour le profil. */
function account_info(int $uid, string $act, string $token): array
{
    $a = graph('GET', 'act_' . $act, ['fields' => 'name,currency,account_status,timezone_name'], $token);
    $cur = (string) ($a['currency'] ?? '');
    db()->prepare('INSERT OR REPLACE INTO accounts (uid, act, name, currency, updated) VALUES (?, ?, ?, ?, ?)')
        ->execute([$uid, $act, str_clean($a['name'] ?? '', 120), $cur, time()]);
    return ['id' => $act, 'name' => (string) ($a['name'] ?? ''), 'currency' => $cur, 'status' => (int) ($a['account_status'] ?? 0),
        'timezone' => (string) ($a['timezone_name'] ?? ''), 'offset' => in_array($cur, ZERO_DECIMAL, true) ? 1 : 100];
}

/* ------------------------------------------------------------ statistiques */

function action_val(array $list, array $types): ?float
{
    $by = [];
    foreach ($list as $a) {
        if (isset($a['action_type'])) $by[$a['action_type']] = (float) ($a['value'] ?? 0);
    }
    foreach ($types as $t) {
        if (array_key_exists($t, $by)) return $by[$t];
    }
    return null;
}
function fnum($v): ?float
{
    return $v === null || $v === '' || !is_numeric($v) ? null : (float) $v;
}

/** Ligne Insights → KPI utilisés par l'extension (mêmes définitions que le tableau de bord). */
function norm_insight(array $r): array
{
    $spend = fnum($r['spend'] ?? null) ?? 0.0;
    $impr = fnum($r['impressions'] ?? null) ?? 0.0;
    $acts = is_array($r['actions'] ?? null) ? $r['actions'] : [];
    $vals = is_array($r['action_values'] ?? null) ? $r['action_values'] : [];
    $ptype = null;
    foreach (PURCHASE_TYPES as $t) {
        foreach ($acts as $a) {
            if (($a['action_type'] ?? '') === $t) { $ptype = $t; break 2; }
        }
    }
    $purch = $ptype ? action_val($acts, [$ptype]) : 0.0;
    $value = $ptype ? action_val($vals, [$ptype]) : null;
    $link = fnum($r['inline_link_clicks'] ?? null) ?? action_val($acts, ['link_click']);
    $lpv = action_val($acts, ['landing_page_view', 'omni_landing_page_view']);
    $v3s = action_val($acts, ['video_view']);
    $thru = is_array($r['video_thruplay_watched_actions'] ?? null) ? action_val($r['video_thruplay_watched_actions'], ['video_view']) : null;
    $o = [
        'campaign_id' => (string) ($r['campaign_id'] ?? ''), 'campaign' => (string) ($r['campaign_name'] ?? ''),
        'adset_id' => (string) ($r['adset_id'] ?? ''), 'adset' => (string) ($r['adset_name'] ?? ''),
        'ad_id' => (string) ($r['ad_id'] ?? ''), 'ad' => (string) ($r['ad_name'] ?? ''),
        'spend' => round($spend, 2), 'impressions' => (int) $impr, 'reach' => (int) (fnum($r['reach'] ?? null) ?? 0),
        'frequency' => fnum($r['frequency'] ?? null), 'clicks' => (int) (fnum($r['clicks'] ?? null) ?? 0),
        'link_clicks' => $link === null ? null : (int) $link, 'ctr_link' => fnum($r['inline_link_click_ctr'] ?? null),
        'cpc_link' => fnum($r['cost_per_inline_link_click'] ?? null), 'cpm' => fnum($r['cpm'] ?? null), 'ctr' => fnum($r['ctr'] ?? null),
        'purchases' => $purch === null ? 0 : (int) round($purch), 'value' => $value === null ? null : round($value, 2),
        'lpv' => $lpv === null ? null : (int) $lpv, 'v3s' => $v3s === null ? null : (int) $v3s, 'thru' => $thru === null ? null : (int) $thru,
    ];
    $o['cpa'] = $o['purchases'] > 0 ? round($spend / $o['purchases'], 2) : null;
    $o['roas'] = $o['value'] !== null && $spend > 0 ? round($o['value'] / $spend, 2) : null;
    $o['hook'] = $o['v3s'] !== null && $impr > 0 ? round($o['v3s'] / $impr * 100, 2) : null;
    $o['hold'] = $o['thru'] !== null && $o['v3s'] ? round($o['thru'] / $o['v3s'] * 100, 2) : null;
    foreach (['age', 'gender', 'publisher_platform', 'platform_position'] as $k) {
        if (isset($r[$k])) $o[$k] = (string) $r[$k];
    }
    return $o;
}

function insights(string $act, string $token, string $level, string $since, string $until, string $breakdowns = '', int $maxRows = 5000): array
{
    $fields = INSIGHT_FIELDS;
    if ($level !== 'account') $fields = 'campaign_id,campaign_name,' . $fields;
    if ($level === 'adset' || $level === 'ad') $fields = 'adset_id,adset_name,' . $fields;
    if ($level === 'ad') $fields = 'ad_id,ad_name,' . $fields;
    $p = ['level' => $level, 'fields' => $fields, 'time_range' => ['since' => $since, 'until' => $until],
        'use_unified_attribution_setting' => true, 'limit' => 500];
    if ($breakdowns !== '') $p['breakdowns'] = $breakdowns;
    $rows = array_map('norm_insight', graph_all('act_' . $act . '/insights', $p, $token, $maxRows));
    usort($rows, function ($a, $b) { return $b['spend'] <=> $a['spend']; });
    return $rows;
}

/** Textes d'une création : texte principal, titre, description, lien, bouton, et si on peut les modifier. */
function creative_texts(array $cr): array
{
    $oss = is_array($cr['object_story_spec'] ?? null) ? $cr['object_story_spec'] : [];
    $afs = is_array($cr['asset_feed_spec'] ?? null) ? $cr['asset_feed_spec'] : [];
    $t = ['mode' => 'post', 'editable' => false, 'body' => '', 'title' => '', 'description' => '', 'link' => '', 'cta' => '',
        'image' => (string) ($cr['image_url'] ?? ($cr['thumbnail_url'] ?? '')), 'variants' => ['body' => [], 'title' => [], 'description' => []]];
    $list = function ($arr, string $k): array {
        $o = [];
        foreach ((is_array($arr) ? $arr : []) as $x) {
            $v = trim((string) ($x[$k] ?? ''));
            if ($v !== '' && !in_array($v, $o, true)) $o[] = $v;
        }
        return $o;
    };
    if ($afs) {
        $t['mode'] = 'dynamic';
        $t['variants'] = ['body' => $list($afs['bodies'] ?? [], 'text'), 'title' => $list($afs['titles'] ?? [], 'text'), 'description' => $list($afs['descriptions'] ?? [], 'text')];
        $t['link'] = (string) ($afs['link_urls'][0]['website_url'] ?? '');
        $t['cta'] = (string) ($afs['call_to_action_types'][0] ?? '');
    }
    if (!empty($oss['link_data']) && is_array($oss['link_data'])) {
        $ld = $oss['link_data'];
        $carousel = !empty($ld['child_attachments']);
        $t['mode'] = $afs ? 'dynamic' : ($carousel ? 'carousel' : 'link');
        $t['editable'] = !$afs && !$carousel;
        $t['body'] = (string) ($ld['message'] ?? '');
        $t['title'] = (string) ($ld['name'] ?? ($carousel ? ($ld['child_attachments'][0]['name'] ?? '') : ''));
        $t['description'] = (string) ($ld['description'] ?? ($carousel ? ($ld['child_attachments'][0]['description'] ?? '') : ''));
        $t['link'] = (string) ($ld['link'] ?? $t['link']);
        $t['cta'] = (string) ($ld['call_to_action']['type'] ?? $t['cta']);
    } elseif (!empty($oss['video_data']) && is_array($oss['video_data'])) {
        $vd = $oss['video_data'];
        $t['mode'] = $afs ? 'dynamic' : 'video';
        $t['editable'] = !$afs;
        $t['body'] = (string) ($vd['message'] ?? '');
        $t['title'] = (string) ($vd['title'] ?? '');
        $t['description'] = (string) ($vd['link_description'] ?? '');
        $t['link'] = (string) ($vd['call_to_action']['value']['link'] ?? $t['link']);
        $t['cta'] = (string) ($vd['call_to_action']['type'] ?? $t['cta']);
        if (!empty($vd['image_url'])) $t['image'] = (string) $vd['image_url'];
    } elseif (!empty($oss['photo_data'])) {
        $t['mode'] = 'photo';
        $t['body'] = (string) ($oss['photo_data']['caption'] ?? '');
    }
    if ($t['body'] === '') $t['body'] = $t['variants']['body'][0] ?? (string) ($cr['body'] ?? '');
    if ($t['title'] === '') $t['title'] = $t['variants']['title'][0] ?? (string) ($cr['title'] ?? '');
    if ($t['description'] === '') $t['description'] = $t['variants']['description'][0] ?? '';
    if ($t['link'] === '') $t['link'] = (string) ($cr['link_url'] ?? ($cr['object_url'] ?? ''));
    if ($t['cta'] === '') $t['cta'] = (string) ($cr['call_to_action_type'] ?? '');
    foreach (['body', 'title', 'description'] as $k) {
        if (!$t['variants'][$k] && $t[$k] !== '') $t['variants'][$k] = [$t[$k]];
    }
    return $t;
}

/** Détails de plusieurs publicités (par lots de 50 identifiants). */
function ads_details(array $ids, string $token): array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
        $d = graph('GET', '', ['ids' => implode(',', $chunk), 'fields' => 'name,effective_status,campaign{name,objective},adset{name},creative{' . CREATIVE_FIELDS . '}'], $token);
        foreach ($d as $id => $ad) {
            if (is_array($ad)) $out[(string) $id] = $ad;
        }
    }
    return $out;
}

/* ------------------------------------------------------------ création */

/** Publicité source (la meilleure) : ensemble de publicités, campagne et création. */
function source_ad(string $adId, string $token): array
{
    return graph('GET', $adId, ['fields' => 'name,account_id,'
        . 'adset{id,name,targeting,optimization_goal,billing_event,promoted_object,destination_type,attribution_spec,is_dynamic_creative},'
        . 'campaign{id,name,objective,special_ad_categories,smart_promotion_type},creative{' . CREATIVE_FIELDS . '}'], $token);
}

function targeting_summary(array $t): array
{
    $geo = is_array($t['geo_locations'] ?? null) ? $t['geo_locations'] : [];
    $interests = [];
    foreach ((is_array($t['flexible_spec'] ?? null) ? $t['flexible_spec'] : []) as $fs) {
        foreach (['interests', 'behaviors', 'work_positions', 'life_events'] as $k) {
            foreach ((is_array($fs[$k] ?? null) ? $fs[$k] : []) as $i) $interests[] = (string) ($i['name'] ?? '');
        }
    }
    return [
        'countries' => array_values(array_map('strval', $geo['countries'] ?? [])),
        'regions' => count($geo['regions'] ?? []), 'cities' => count($geo['cities'] ?? []),
        'age_min' => (int) ($t['age_min'] ?? 18), 'age_max' => (int) ($t['age_max'] ?? 65),
        'genders' => array_values(array_map('intval', $t['genders'] ?? [])),
        'advantage_audience' => (int) ($t['targeting_automation']['advantage_audience'] ?? 0),
        'custom_audiences' => count($t['custom_audiences'] ?? []), 'excluded' => count($t['excluded_custom_audiences'] ?? []),
        'interests' => array_slice(array_values(array_filter($interests)), 0, 12),
        'publisher_platforms' => array_values($t['publisher_platforms'] ?? []),
        'facebook_positions' => array_values($t['facebook_positions'] ?? []),
        'instagram_positions' => array_values($t['instagram_positions'] ?? []),
    ];
}

/** Ciblage de l'ensemble source + âges, genres, placements et Advantage+ choisis par l'utilisateur. */
function build_targeting(array $src, array $in): array
{
    $t = $src;
    $min = max(13, min(65, (int) ($in['age_min'] ?? 18)));
    $max = max($min, min(65, (int) ($in['age_max'] ?? 65)));
    $adv = !empty($in['advantage_audience']);
    unset($t['age_range'], $t['genders']);
    $genders = array_values(array_intersect([1, 2], array_map('intval', (array) ($in['genders'] ?? []))));
    if ($adv) {
        // Advantage+ audience : l'âge choisi devient une suggestion ; contrôle d'âge minimum ≤ 25 ans, maximum 65.
        $t['age_min'] = min($min, 25);
        $t['age_max'] = 65;
        if ($min > 18 || $max < 65) $t['age_range'] = [$min, $max];
    } else {
        $t['age_min'] = $min;
        $t['age_max'] = $max;
        if (count($genders) === 1) $t['genders'] = $genders;
    }
    $t['targeting_automation'] = ['advantage_audience' => $adv ? 1 : 0];
    $pl = is_array($in['placements'] ?? null) ? $in['placements'] : ['auto' => true];
    foreach (['publisher_platforms', 'facebook_positions', 'instagram_positions', 'audience_network_positions', 'messenger_positions'] as $k) unset($t[$k]);
    if (empty($pl['auto'])) {
        $plats = [];
        foreach (POSITIONS as $plat => $allowed) {
            $key = $plat . '_positions';
            $pos = array_values(array_intersect($allowed, array_map('strval', (array) ($pl[$key] ?? []))));
            if ($plat === 'instagram' && array_intersect($pos, ['explore', 'explore_home', 'profile_feed', 'ig_search']) && !in_array('stream', $pos, true)) {
                $pos[] = 'stream'; // ces placements Instagram exigent le fil Instagram
            }
            if ($pos) {
                $plats[] = $plat;
                $t[$key] = $pos;
            }
        }
        if (!$plats) fail('bad_placements');
        $t['publisher_platforms'] = $plats;
    }
    return $t;
}

/** Création modifiée : mêmes visuels que la meilleure publicité, textes choisis par l'utilisateur. */
function build_story_spec(array $oss, array $ad): ?array
{
    $body = str_clean($ad['primary_text'] ?? '', 5000);
    $title = str_clean($ad['title'] ?? '', 255);
    $desc = str_clean($ad['description'] ?? '', 255);
    $link = trim((string) ($ad['link'] ?? ''));
    $cta = strtoupper(preg_replace('/[^A-Z_]/i', '', (string) ($ad['cta'] ?? '')));
    if ($link !== '' && !preg_match('~^https?://~i', $link)) fail('bad_link');
    if (!empty($oss['link_data']) && empty($oss['link_data']['child_attachments'])) {
        $ld = $oss['link_data'];
        $ld['message'] = $body;
        if ($title !== '') $ld['name'] = $title; else unset($ld['name']);
        if ($desc !== '') $ld['description'] = $desc; else unset($ld['description']);
        if ($link !== '') $ld['link'] = $link;
        if ($cta !== '') $ld['call_to_action'] = ['type' => $cta, 'value' => ['link' => $ld['link'] ?? $link]];
        elseif (isset($ld['call_to_action']['value']) && $link !== '') $ld['call_to_action']['value']['link'] = $link;
        $oss['link_data'] = $ld;
        return $oss;
    }
    if (!empty($oss['video_data'])) {
        $vd = $oss['video_data'];
        $vd['message'] = $body;
        if ($title !== '') $vd['title'] = $title; else unset($vd['title']);
        if ($desc !== '') $vd['link_description'] = $desc; else unset($vd['link_description']);
        $prev = $vd['call_to_action'] ?? [];
        $l = $link !== '' ? $link : (string) ($prev['value']['link'] ?? '');
        if ($cta !== '' || $l !== '') {
            $vd['call_to_action'] = ['type' => $cta !== '' ? $cta : (string) ($prev['type'] ?? 'LEARN_MORE'), 'value' => array_merge((array) ($prev['value'] ?? []), $l !== '' ? ['link' => $l] : [])];
        }
        $oss['video_data'] = $vd;
        return $oss;
    }
    return null;
}

/** Montant dans l'unité attendue par l'API (centimes, sauf devises sans décimales). */
function minor_units(float $amount, string $currency): int
{
    return (int) round($amount * (in_array($currency, ZERO_DECIMAL, true) ? 1 : 100));
}

function ads_manager_url(string $act, string $campaignId): string
{
    return 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act=' . $act . ($campaignId !== '' ? '&selected_campaign_ids=' . $campaignId : '');
}
