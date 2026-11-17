<?php
/**
 * Accès des assistants IA (Claude, ChatGPT, Gemini…) au serveur MCP de Studioma.
 *
 * Deux façons d'obtenir un jeton :
 *  1. OAuth 2.1 (ce que font claude.ai et ChatGPT) : l'application s'enregistre elle-même (RFC 7591),
 *     envoie la personne sur l'écran « Allow » de Studioma (PKCE S256 obligatoire), puis échange le
 *     code contre un jeton d'accès (1 h) et un jeton de renouvellement (60 jours, à usage unique).
 *  2. Jeton personnel créé dans Settings › AI assistants (Claude Code, Gemini CLI, Cursor…).
 *
 * Un jeton agit au nom de la personne, avec exactement ses droits : chaque action passe par les
 * mêmes vérifications que l'application (lib/access.php). Seuls les hashs des jetons sont gardés.
 */
declare(strict_types=1);

const OAUTH_SCOPE = 'studioma';
const ACCESS_TTL = 3600;
const REFRESH_TTL = 60 * 86400;
const CODE_TTL = 600;
const REQUEST_TTL = 1800;

function issuer(): string
{
    return rtrim(site_url(), '/');
}
function mcp_url(): string
{
    return site_url() . 'mcp';
}
/** Adresse des métadonnées de la ressource protégée, annoncée dans les réponses 401 du serveur MCP. */
function resource_metadata_url(): string
{
    return site_url() . '.well-known/oauth-protected-resource';
}

/** Métadonnées du serveur d'autorisation (RFC 8414). */
function oauth_as_metadata(): array
{
    $base = site_url();
    return [
        'issuer' => issuer(),
        'authorization_endpoint' => $base . 'oauth/authorize',
        'token_endpoint' => $base . 'oauth/token',
        'registration_endpoint' => $base . 'oauth/register',
        'revocation_endpoint' => $base . 'oauth/revoke',
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'scopes_supported' => [OAUTH_SCOPE],
        'authorization_response_iss_parameter_supported' => true,
        'service_documentation' => $base,
    ];
}
/** Métadonnées de la ressource protégée — le serveur MCP (RFC 9728). */
function oauth_resource_metadata(): array
{
    return [
        'resource' => mcp_url(),
        'authorization_servers' => [issuer()],
        'scopes_supported' => [OAUTH_SCOPE],
        'bearer_methods_supported' => ['header'],
        'resource_name' => STM_NAME,
        'resource_documentation' => site_url(),
    ];
}

/* ------------------------------------------------------------- réponses */

function oauth_json(array $d, int $code = 200, array $headers = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $h) header($h);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function oauth_error(string $error, string $desc, int $code = 400): void
{
    tx_rollback();
    oauth_json(['error' => $error, 'error_description' => $desc], $code, $code === 401 ? ['WWW-Authenticate: Basic realm="studioma"'] : []);
}
/** En-têtes CORS : les métadonnées, l'enregistrement et les jetons peuvent être appelés depuis un navigateur. */
function cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID');
    header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id, MCP-Protocol-Version');
    header('Access-Control-Max-Age: 86400');
}
/** Page d'erreur affichée à la personne quand on ne peut pas la renvoyer vers l'application. */
function oauth_error_page(string $title, string $text): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
        . $e(STM_NAME) . '</title><style>body{font:16px/1.55 system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 20px;color:#1b1a22}h1{font-size:22px}p{color:#4e4c59}a{color:#5b4bff}</style></head><body><h1>'
        . $e($title) . '</h1><p>' . $e($text) . '</p><p><a href="' . $e(app_url()) . '">Open ' . $e(STM_NAME) . '</a></p></body></html>';
    exit;
}

/* --------------------------------------------------------------- outils */

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function token_hash(string $raw): string
{
    return hash('sha256', $raw);
}
/** Adresse de retour acceptée : https, http vers la machine locale, ou schéma d'application. */
function redirect_ok(string $u): bool
{
    if ($u === '' || strlen($u) > 500 || preg_match('/[\s<>"]/', $u) || strpos($u, '#') !== false) return false;
    $p = parse_url($u);
    if (!$p || empty($p['scheme'])) return false;
    $scheme = strtolower($p['scheme']);
    if ($scheme === 'https') return !empty($p['host']);
    if ($scheme === 'http') return in_array(strtolower($p['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    // Applications de bureau (ex. cursor://) : tout schéma propre, sauf ceux qui exécutent ou lisent du contenu.
    return (bool) preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) && !in_array($scheme, ['javascript', 'data', 'file', 'vbscript', 'blob', 'about'], true);
}
/** L'adresse demandée est-elle enregistrée ? Vers la machine locale, le port peut changer (RFC 8252). */
function redirect_registered(string $u, array $list): bool
{
    if (in_array($u, $list, true)) return true;
    $p = parse_url($u);
    if (!$p || strtolower($p['scheme'] ?? '') !== 'http') return false;
    $strip = function (array $q): string {
        return strtolower($q['host'] ?? '') . '|' . ($q['path'] ?? '') . '|' . ($q['query'] ?? '');
    };
    foreach ($list as $r) {
        $q = parse_url($r);
        if ($q && strtolower($q['scheme'] ?? '') === 'http' && $strip($q) === $strip($p)) return true;
    }
    return false;
}
function with_query(string $url, array $params): string
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}
/** La ressource demandée (RFC 8707) doit être notre serveur MCP. */
function resource_ok(string $r): bool
{
    if ($r === '') return true;
    $norm = function (string $s): string { return rtrim(strtolower($s), '/'); };
    return in_array($norm($r), [$norm(mcp_url()), $norm(issuer())], true);
}
/** Ménage occasionnel : demandes expirées, jetons expirés, applications jamais utilisées. */
function oauth_gc(): void
{
    if (random_int(1, 50) !== 1) return;
    $now = time();
    q('DELETE FROM oauth_requests WHERE expires < ?', [$now]);
    q('DELETE FROM ai_tokens WHERE expires > 0 AND expires < ?', [$now]);
    q('DELETE FROM oauth_clients WHERE used = 0 AND created < ? AND id NOT IN (SELECT client_id FROM ai_grants)', [$now - 30 * 86400]);
}

/* ---------------------------------------------------------- enregistrement */

/** Enregistrement dynamique d'une application (RFC 7591). */
function oauth_register(): void
{
    rate('oauth_register', 30, 3600);
    $b = input_json();
    $uris = $b['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10) oauth_error('invalid_redirect_uri', 'redirect_uris must list between 1 and 10 addresses.');
    $clean = [];
    foreach ($uris as $u) {
        if (!is_string($u) || !redirect_ok($u)) oauth_error('invalid_redirect_uri', 'This redirect URI is not accepted: use https, http://localhost or an application scheme.');
        $clean[] = $u;
    }
    $method = (string) ($b['token_endpoint_auth_method'] ?? 'client_secret_basic');
    if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) oauth_error('invalid_client_metadata', 'Unsupported token_endpoint_auth_method.');
    $grants = $b['grant_types'] ?? ['authorization_code', 'refresh_token'];
    if (!is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token'])) oauth_error('invalid_client_metadata', 'Only authorization_code and refresh_token are supported.');
    $types = $b['response_types'] ?? ['code'];
    if (!is_array($types) || array_diff($types, ['code'])) oauth_error('invalid_client_metadata', 'Only the code response type is supported.');
    $name = line_clean($b['client_name'] ?? '', 80);
    $uri = url_clean($b['client_uri'] ?? '', 300);
    $id = 'stm_' . token(12);
    $secret = $method === 'none' ? '' : token(24);
    insert('oauth_clients', ['id' => $id, 'secret' => $secret === '' ? '' : token_hash($secret), 'name' => $name !== '' ? $name : 'AI assistant',
        'uri' => $uri, 'redirects' => jenc(array_values(array_unique($clean))), 'created' => time()]);
    $out = [
        'client_id' => $id, 'client_id_issued_at' => time(), 'client_name' => $name !== '' ? $name : 'AI assistant',
        'redirect_uris' => array_values(array_unique($clean)), 'grant_types' => array_values($grants), 'response_types' => ['code'],
        'token_endpoint_auth_method' => $method, 'scope' => OAUTH_SCOPE,
    ];
    if ($uri !== '') $out['client_uri'] = $uri;
    if ($secret !== '') { $out['client_secret'] = $secret; $out['client_secret_expires_at'] = 0; }
    oauth_json($out, 201);
}
function oauth_client(string $id): ?array
{
    return preg_match('/^stm_[a-f0-9]{24}$/', $id) ? row('SELECT * FROM oauth_clients WHERE id = ?', [$id]) : null;
}

/* ---------------------------------------------------------- autorisation */

/**
 * GET oauth/authorize : la demande est vérifiée puis gardée ; la personne est envoyée sur l'écran
 * d'accord de l'application (#/connect/<id>), qui passe d'abord par la connexion si besoin.
 */
function oauth_authorize(): void
{
    $g = function (string $k): string { $v = $_GET[$k] ?? ''; return is_string($v) ? trim($v) : ''; };
    $client = oauth_client($g('client_id'));
    if (!$client) oauth_error_page('Unknown application', 'This application is not registered with ' . STM_NAME . '. Remove it from your AI assistant and add it again.');
    $redirect = $g('redirect_uri');
    $list = jarr($client['redirects']);
    if ($redirect === '' && count($list) === 1) $redirect = $list[0];
    if (!redirect_registered($redirect, $list)) oauth_error_page('Invalid return address', 'This application asked to come back to an address it did not register. For your security, the request was stopped.');
    $state = $g('state');
    $back = function (string $error, string $desc) use ($redirect, $state): void {
        $p = ['error' => $error, 'error_description' => $desc, 'iss' => issuer()];
        if ($state !== '') $p['state'] = $state;
        header('Location: ' . with_query($redirect, $p), true, 302);
        exit;
    };
    if ($g('response_type') !== 'code') $back('unsupported_response_type', 'Only response_type=code is supported.');
    $challenge = $g('code_challenge');
    if ($g('code_challenge_method') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) $back('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
    $resource = $g('resource');
    if (!resource_ok($resource)) $back('invalid_target', 'This authorization server only issues tokens for ' . mcp_url() . '.');
    $scope = $g('scope');
    if ($scope !== '' && array_diff(preg_split('/\s+/', $scope) ?: [], [OAUTH_SCOPE, 'openid', 'profile', 'email', 'offline_access'])) $back('invalid_scope', 'The only scope is ' . OAUTH_SCOPE . '.');
    rate('oauth_authorize', 60, 600);
    oauth_gc();
    $id = token(16);
    insert('oauth_requests', ['id' => $id, 'client_id' => $client['id'], 'redirect' => $redirect, 'challenge' => $challenge,
        'state' => mb_substr($state, 0, 500), 'scope' => OAUTH_SCOPE, 'resource' => $resource, 'expires' => time() + REQUEST_TTL]);
    header('Location: ' . app_url('connect/' . $id), true, 302);
    exit;
}
function oauth_pending(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) return null;
    return row('SELECT r.*, c.name AS client_name, c.uri AS client_uri FROM oauth_requests r JOIN oauth_clients c ON c.id = r.client_id
        WHERE r.id = ? AND r.expires > ? AND r.code IS NULL', [$id, time()]);
}
/** Ce que l'écran d'accord affiche (GET oauth_request). */
function api_oauth_request(array $b, array $u): array
{
    $r = oauth_pending((string) ($b['id'] ?? ''));
    if (!$r) fail('This connection request has expired. Start again from your AI assistant.', 404, 'notfound');
    $host = parse_url((string) $r['redirect'], PHP_URL_HOST) ?: (string) parse_url((string) $r['redirect'], PHP_URL_SCHEME);
    $existing = (int) (val('SELECT COUNT(*) FROM ai_grants WHERE uid = ? AND client_id = ?', [$u['id'], $r['client_id']]) ?? 0);
    return ['request' => [
        'id' => (string) $r['id'], 'client_name' => (string) $r['client_name'], 'client_uri' => (string) $r['client_uri'],
        'redirect_host' => (string) $host, 'already' => $existing > 0, 'mcp_url' => mcp_url(),
    ]];
}
/** Accord ou refus (POST oauth_decide) : renvoie l'adresse où l'application attend la réponse. */
function api_oauth_decide(array $b, array $u): array
{
    $r = oauth_pending((string) ($b['id'] ?? ''));
    if (!$r) fail('This connection request has expired. Start again from your AI assistant.', 404, 'notfound');
    $p = ['iss' => issuer()];
    if ($r['state'] !== '') $p['state'] = $r['state'];
    if (empty($b['allow'])) {
        q('DELETE FROM oauth_requests WHERE id = ?', [$r['id']]);
        return ['redirect' => with_query((string) $r['redirect'], ['error' => 'access_denied', 'error_description' => 'The person declined the connection.'] + $p)];
    }
    $code = token(24);
    q('UPDATE oauth_requests SET code = ?, uid = ?, expires = ? WHERE id = ?', [token_hash($code), $u['id'], time() + CODE_TTL, $r['id']]);
    return ['redirect' => with_query((string) $r['redirect'], ['code' => $code] + $p)];
}

/* ----------------------------------------------------------------- jetons */

/** Identifiants de l'application dans la requête (Basic, ou client_id / client_secret dans le corps). */
function oauth_client_auth(array $b): array
{
    $id = '';
    $secret = null;
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/^Basic\s+(\S+)$/i', $h, $m)) {
        $pair = base64_decode($m[1], true);
        if ($pair !== false && strpos($pair, ':') !== false) {
            [$i, $s] = explode(':', $pair, 2);
            $id = urldecode($i);
            $secret = urldecode($s);
        }
    }
    if ($id === '') $id = is_string($b['client_id'] ?? null) ? $b['client_id'] : '';
    if ($secret === null && is_string($b['client_secret'] ?? null)) $secret = $b['client_secret'];
    $c = oauth_client($id);
    if (!$c) oauth_error('invalid_client', 'Unknown client.', 401);
    if ($c['secret'] !== '' && ($secret === null || !hash_equals((string) $c['secret'], token_hash($secret)))) oauth_error('invalid_client', 'Client authentication failed.', 401);
    return $c;
}
/** Crée un jeton et renvoie sa valeur (seul son hash est gardé). */
function ai_token_new(int $grantId, string $kind, int $ttl): string
{
    $raw = ($kind === 'personal' ? 'stm_pat_' : ($kind === 'refresh' ? 'stm_rt_' : 'stm_at_')) . token(24);
    insert('ai_tokens', ['hash' => token_hash($raw), 'grant_id' => $grantId, 'kind' => $kind, 'hint' => substr($raw, -4),
        'created' => time(), 'expires' => $ttl > 0 ? time() + $ttl : 0]);
    return $raw;
}
function oauth_tokens_for(int $grantId): array
{
    return [
        'access_token' => ai_token_new($grantId, 'access', ACCESS_TTL), 'token_type' => 'Bearer', 'expires_in' => ACCESS_TTL,
        'refresh_token' => ai_token_new($grantId, 'refresh', REFRESH_TTL), 'scope' => OAUTH_SCOPE,
    ];
}
/** POST oauth/token : code d'autorisation (avec PKCE) ou jeton de renouvellement → jetons. */
function oauth_token(): void
{
    rate('oauth_token', 120, 600);
    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $b = strpos($ct, 'application/json') !== false ? input_json() : $_POST;
    $client = oauth_client_auth($b);
    $type = (string) ($b['grant_type'] ?? '');
    if ($type === 'authorization_code') {
        $code = is_string($b['code'] ?? null) ? $b['code'] : '';
        $verifier = is_string($b['code_verifier'] ?? null) ? $b['code_verifier'] : '';
        // Le code ne sert qu'une fois, quoi qu'il arrive ensuite : il est retiré (et ce retrait enregistré)
        // avant toute vérification, pour qu'une tentative ratée ne puisse pas être rejouée.
        $r = tx(function () use ($code): ?array {
            $r = $code !== '' ? row('SELECT * FROM oauth_requests WHERE code = ?', [token_hash($code)]) : null;
            if ($r) q('DELETE FROM oauth_requests WHERE id = ?', [$r['id']]);
            return $r;
        });
        if (!$r || (int) $r['expires'] < time() || $r['client_id'] !== $client['id']) oauth_error('invalid_grant', 'This authorization code is not valid (expired, already used, or issued to another client).');
        $res = tx(function () use ($r, $verifier, $client, $b): array {
            $redirect = is_string($b['redirect_uri'] ?? null) ? $b['redirect_uri'] : '';
            if ($redirect !== '' && $redirect !== $r['redirect']) oauth_error('invalid_grant', 'redirect_uri does not match the authorization request.');
            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) || !hash_equals((string) $r['challenge'], b64url(hash('sha256', $verifier, true)))) {
                oauth_error('invalid_grant', 'PKCE verification failed.');
            }
            $res = is_string($b['resource'] ?? null) ? $b['resource'] : '';
            if (!resource_ok($res)) oauth_error('invalid_target', 'Tokens are only issued for ' . mcp_url() . '.');
            $uid = (int) $r['uid'];
            if (!user_by_id($uid)) oauth_error('invalid_grant', 'This account no longer exists.');
            // Une application reconnectée par la même personne garde un seul accès dans la liste.
            $gid = (int) (val('SELECT id FROM ai_grants WHERE uid = ? AND client_id = ?', [$uid, $client['id']]) ?? 0);
            if (!$gid) $gid = insert('ai_grants', ['uid' => $uid, 'client_id' => $client['id'], 'name' => $client['name'], 'created' => time()]);
            q('UPDATE ai_grants SET used = ? WHERE id = ?', [time(), $gid]);
            q('UPDATE oauth_clients SET used = ? WHERE id = ?', [time(), $client['id']]);
            return oauth_tokens_for($gid);
        });
        oauth_json($res);
    }
    if ($type === 'refresh_token') {
        $raw = is_string($b['refresh_token'] ?? null) ? $b['refresh_token'] : '';
        $res = tx(function () use ($raw, $client): array {
            $t = $raw !== '' ? row("SELECT t.*, g.client_id, g.uid FROM ai_tokens t JOIN ai_grants g ON g.id = t.grant_id WHERE t.hash = ? AND t.kind = 'refresh'", [token_hash($raw)]) : null;
            if (!$t || ((int) $t['expires'] > 0 && (int) $t['expires'] < time()) || $t['client_id'] !== $client['id']) oauth_error('invalid_grant', 'This refresh token is not valid.');
            // Jeton à usage unique : il est remplacé par un nouveau.
            q('DELETE FROM ai_tokens WHERE hash = ?', [$t['hash']]);
            q('UPDATE ai_grants SET used = ? WHERE id = ?', [time(), $t['grant_id']]);
            return oauth_tokens_for((int) $t['grant_id']);
        });
        oauth_json($res);
    }
    oauth_error('unsupported_grant_type', 'Use authorization_code or refresh_token.');
}
/** POST oauth/revoke (RFC 7009) : toujours 200, même si le jeton est inconnu. */
function oauth_revoke(): void
{
    $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $b = strpos($ct, 'application/json') !== false ? input_json() : $_POST;
    $client = oauth_client_auth($b);
    $raw = is_string($b['token'] ?? null) ? $b['token'] : '';
    if ($raw !== '') {
        q("DELETE FROM ai_tokens WHERE hash = ? AND kind IN ('access', 'refresh') AND grant_id IN (SELECT id FROM ai_grants WHERE client_id = ?)", [token_hash($raw), $client['id']]);
    }
    oauth_json([]);
}

/* ----------------------------------------------- jeton présenté au serveur MCP */

/** Jeton « Bearer » de la requête → [compte, accès] ; null s'il est absent, inconnu ou expiré. */
function bearer_user(): ?array
{
    $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($h === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $k => $v) {
            if (strtolower((string) $k) === 'authorization') $h = (string) $v;
        }
    }
    if (!preg_match('/^Bearer\s+(stm_(?:at|pat)_[a-f0-9]{48})$/i', trim($h), $m)) return null;
    $t = row("SELECT t.grant_id, t.kind, g.uid, g.used, g.client_id, g.name FROM ai_tokens t JOIN ai_grants g ON g.id = t.grant_id
        WHERE t.hash = ? AND t.kind IN ('access', 'personal') AND (t.expires = 0 OR t.expires > ?)", [token_hash($m[1]), time()]);
    if (!$t) return null;
    $u = user_by_id((int) $t['uid']);
    if (!$u) return null;
    $u['id'] = (int) $u['id'];
    $u['s_csrf'] = '';
    if ((int) $t['used'] < time() - 300) q('UPDATE ai_grants SET used = ? WHERE id = ?', [time(), $t['grant_id']]);
    if ((int) $u['last_seen'] < time() - 600) q('UPDATE users SET last_seen = ? WHERE id = ?', [time(), $u['id']]);
    return [$u, $t];
}

/* ------------------------------------------- Settings › AI assistants (compte) */

function api_ai_access(array $b, array $u): array
{
    $list = rows("SELECT g.*, c.uri AS client_uri, (SELECT t.hint FROM ai_tokens t WHERE t.grant_id = g.id AND t.kind = 'personal' LIMIT 1) AS hint,
            (SELECT COUNT(*) FROM ai_tokens t WHERE t.grant_id = g.id AND (t.expires = 0 OR t.expires > ?)) AS live
        FROM ai_grants g LEFT JOIN oauth_clients c ON c.id = g.client_id WHERE g.uid = ? ORDER BY g.id DESC", [time(), $u['id']]);
    return [
        'mcp_url' => mcp_url(),
        'grants' => array_values(array_map(function (array $g): array {
            return ['id' => (int) $g['id'], 'kind' => $g['client_id'] === '' ? 'token' : 'app', 'name' => (string) $g['name'],
                'client_uri' => (string) ($g['client_uri'] ?? ''), 'hint' => (string) ($g['hint'] ?? ''),
                'created' => (int) $g['created'], 'used' => (int) $g['used'], 'active' => (int) $g['live'] > 0];
        }, array_filter($list, function (array $g): bool { return $g['client_id'] === '' || (int) $g['live'] > 0; }))),
    ];
}
/** Jeton personnel : montré une seule fois, valable jusqu'à sa suppression. */
function api_ai_token_create(array $b, array $u): array
{
    rate('ai_token', 20, 3600, 'u' . $u['id']);
    $name = line_clean($b['name'] ?? '', 60);
    if ($name === '') fail('Give this token a name, for example “Claude Code on my laptop”.');
    if ((int) val("SELECT COUNT(*) FROM ai_grants WHERE uid = ? AND client_id = ''", [$u['id']]) >= 20) fail('You already have 20 personal tokens. Delete one first.');
    $res = tx(function () use ($u, $name): array {
        $gid = insert('ai_grants', ['uid' => $u['id'], 'client_id' => '', 'name' => $name, 'created' => time()]);
        return ['id' => $gid, 'token' => ai_token_new($gid, 'personal', 0)];
    });
    return $res + ['mcp_url' => mcp_url()];
}
/** Retire un accès (application connectée ou jeton personnel) : ses jetons cessent de fonctionner. */
function api_ai_revoke(array $b, array $u): array
{
    q('DELETE FROM ai_grants WHERE id = ? AND uid = ?', [int_in($b, 'id'), $u['id']]);
    return [];
}
