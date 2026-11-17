<?php
/**
 * OAuth 2.1 de Studioma, pour les assistants IA qui se connectent au serveur MCP :
 *   GET  .well-known/oauth-protected-resource           métadonnées de la ressource (RFC 9728)
 *   GET  .well-known/oauth-authorization-server         métadonnées du serveur d'autorisation (RFC 8414)
 *   GET  .well-known/openid-configuration               mêmes métadonnées (découverte « OpenID »)
 *   POST oauth/register    enregistrement d'une application (RFC 7591)
 *   GET  oauth/authorize   demande d'accès → écran « Allow » de l'application (#/connect/…)
 *   POST oauth/token       code (+ PKCE) ou jeton de renouvellement → jetons
 *   POST oauth/revoke      révocation d'un jeton (RFC 7009)
 * Les adresses propres (oauth/…, .well-known/…) sont réécrites vers ce fichier par .htaccess.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/oauth.php';

cors_headers();
header('X-Content-Type-Options: nosniff');
$httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($httpMethod === 'OPTIONS') { http_response_code(204); exit; }

try {
    db();
} catch (Throwable $e) {
    error_log('studioma oauth db: ' . $e->getMessage());
    oauth_error('temporarily_unavailable', 'The service is temporarily unavailable.', 503);
}
$wk = is_string($_GET['wk'] ?? null) ? $_GET['wk'] : '';
$op = is_string($_GET['op'] ?? null) ? $_GET['op'] : '';
try {
    if ($wk === 'oauth-protected-resource') oauth_json(oauth_resource_metadata());
    if (in_array($wk, ['oauth-authorization-server', 'openid-configuration'], true)) oauth_json(oauth_as_metadata());
    switch ($op) {
        case 'authorize':
            oauth_authorize();
            break;
        case 'register':
        case 'token':
        case 'revoke':
            if ($httpMethod !== 'POST') oauth_error('invalid_request', 'Use POST.', 405);
            ($op === 'register' ? 'oauth_register' : ($op === 'token' ? 'oauth_token' : 'oauth_revoke'))();
            break;
    }
    oauth_error('invalid_request', 'Unknown endpoint.', 404);
} catch (Throwable $e) {
    tx_rollback();
    error_log('studioma oauth ' . $op . $wk . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    oauth_error('server_error', 'Something went wrong. Please try again.', 500);
}
