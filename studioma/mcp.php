<?php
/**
 * Serveur MCP de Studioma — https://dehini.com/studioma/mcp
 *
 * Transport « Streamable HTTP » sans état : chaque POST porte un message JSON-RPC (ou un lot) et
 * reçoit sa réponse en JSON. Pas de flux SSE (GET → 405), pas de session : rien ne reste ouvert,
 * ce qui convient à un hébergement mutualisé. Authentification : jeton « Bearer » obtenu par OAuth
 * (claude.ai, ChatGPT…) ou jeton personnel (Settings › AI assistants). Voir lib/oauth.php et
 * lib/mcp.php (les outils).
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/access.php';
require __DIR__ . '/lib/files.php';
require __DIR__ . '/lib/studio.php';
require __DIR__ . '/lib/cards.php';
require __DIR__ . '/lib/collab.php';
require __DIR__ . '/lib/social.php';
require __DIR__ . '/lib/talent.php';
require __DIR__ . '/lib/oauth.php';
require __DIR__ . '/lib/share.php';
require __DIR__ . '/lib/mcp.php';

cors_headers();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($httpMethod === 'OPTIONS') { http_response_code(204); exit; }

/** Réponse JSON du transport (hors JSON-RPC réussi). */
function mcp_reply($body, int $code = 200, array $headers = []): void
{
    http_response_code($code);
    foreach ($headers as $h) header($h);
    if ($body === null) exit;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
$rpcError = function (int $code, string $message) { return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]]; };

try {
    db();
} catch (Throwable $e) {
    error_log('studioma mcp db: ' . $e->getMessage());
    mcp_reply($rpcError(-32603, 'The service is temporarily unavailable.'), 503);
}
if ($httpMethod !== 'POST') {
    // Pas de flux d'événements ouvert par le serveur : seul POST est utilisé.
    mcp_reply($rpcError(-32000, 'Method not allowed: send JSON-RPC messages with POST.'), 405, ['Allow: POST, OPTIONS']);
}

$auth = bearer_user();
if (!$auth) {
    $given = isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    $challenge = 'Bearer realm="studioma", resource_metadata="' . resource_metadata_url() . '", scope="' . OAUTH_SCOPE . '"'
        . ($given ? ', error="invalid_token", error_description="The access token is missing, expired or revoked."' : '');
    mcp_reply($rpcError(-32001, 'Authentication required. Connect this assistant to Studioma first.'), 401, ['WWW-Authenticate: ' . $challenge]);
}
[$mcpUser] = $auth;
$GLOBALS['stm_throw'] = true;

$raw = (string) file_get_contents('php://input');
$msg = json_decode($raw, true);
if (!is_array($msg)) mcp_reply($rpcError(-32700, 'Parse error'), 400);
$isBatch = $msg !== [] && array_keys($msg) === range(0, count($msg) - 1);
$replies = [];
foreach ($isBatch ? $msg : [$msg] as $one) {
    try {
        $r = mcp_handle($one, $mcpUser);
    } catch (Throwable $e) {
        tx_rollback();
        error_log('studioma mcp: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $r = ['jsonrpc' => '2.0', 'id' => is_array($one) ? ($one['id'] ?? null) : null, 'error' => ['code' => -32603, 'message' => 'Internal error. Please try again.']];
    }
    if ($r !== null) $replies[] = $r;
}
// Seulement des notifications ou des réponses : accusé de réception sans corps.
if (!$replies) mcp_reply(null, 202);
mcp_reply($isBatch ? $replies : $replies[0]);
