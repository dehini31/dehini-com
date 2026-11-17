<?php
/**
 * Routeur pour le serveur PHP intégré : reproduit studioma/.htaccess (dossiers internes interdits,
 * en-têtes de sécurité dont la Content-Security-Policy) afin de tester la plateforme sous
 * /studioma/ comme sur l'hébergement cPanel.
 *   php -S 127.0.0.1:8097 -t <public_html> tests/studioma/router.php
 */
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
// Réécritures de studioma/.htaccess et de .well-known/.htaccess (serveur MCP, OAuth, découverte).
$target = null;
if (preg_match('~^/studioma/mcp/?$~', $uri)) {
    $target = 'mcp.php';
} elseif (preg_match('~^/studioma/oauth/(register|authorize|token|revoke)/?$~', $uri, $m)) {
    $target = 'oauth.php';
    $_GET['op'] = $m[1];
} elseif (preg_match('~^/studioma/\.well-known/(oauth-protected-resource|oauth-authorization-server|openid-configuration)(/.*)?$~', $uri, $m)
    || preg_match('~^/\.well-known/(oauth-authorization-server)(/studioma)?/?$~', $uri, $m)
    || preg_match('~^/\.well-known/(openid-configuration)/studioma/?$~', $uri, $m)
    || preg_match('~^/\.well-known/(oauth-protected-resource)(/studioma(/mcp)?)?/?$~', $uri, $m)) {
    $target = 'oauth.php';
    $_GET['wk'] = $m[1];
}
if ($target !== null) {
    $_SERVER['SCRIPT_NAME'] = '/studioma/' . $target;
    $_SERVER['SCRIPT_FILENAME'] = $root . '/studioma/' . $target;
    chdir($root . '/studioma');
    require $root . '/studioma/' . $target;
    return true;
}
if (strpos($uri, '/studioma') !== 0) return false;
$deny = preg_match('~^/studioma/lib(/|$)~', $uri)
    || preg_match('~/(config\.php|config\.example\.php|\.user\.ini|\.htaccess|[^/]*\.sqlite[^/]*)$~', $uri);
if ($deny) { http_response_code(403); echo 'Forbidden'; return true; }
$file = $root . $uri;
if (is_dir($file)) {
    if (substr($uri, -1) !== '/') { header('Location: ' . $uri . '/', true, 301); return true; }
    $file .= 'index.html';
}
if (is_file($file) && substr($file, -5) === '.html') {
    // Les mêmes en-têtes que studioma/.htaccess : la CSP est donc vérifiée par les tests.
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://accounts.google.com/gsi/client; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://accounts.google.com/gsi/style; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self' https://accounts.google.com/gsi/; frame-src https://accounts.google.com/gsi/; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    readfile($file);
    return true;
}
return false;
