<?php
/**
 * Routeur pour le serveur PHP intégré : reproduit les .htaccess de maillage/ (dossiers internes
 * interdits, scripts interdits dans media/, en-têtes de sécurité dont la Content-Security-Policy)
 * afin de tester la plateforme sous /maillage/ comme sur l'hébergement cPanel.
 *   php -S 127.0.0.1:8095 -t <public_html> tests/maillage/router.php
 */
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
if (strpos($uri, '/maillage') !== 0) return false;
$deny = preg_match('~^/maillage/lib(/|$)~', $uri)
    || preg_match('~/(config\.php|config\.example\.php|\.user\.ini|\.htaccess|[^/]*\.sqlite[^/]*)$~', $uri)
    || preg_match('~^/maillage/media/.*\.(php\d?|phtml|phar|html?|svg|js)$~i', $uri);
if ($deny) { http_response_code(403); echo 'Forbidden'; return true; }
$file = $root . $uri;
if (is_dir($file)) {
    if (substr($uri, -1) !== '/') { header('Location: ' . $uri . '/', true, 301); return true; }
    $file .= 'index.html';
}
if (is_file($file) && substr($file, -5) === '.html') {
    // Les mêmes en-têtes que maillage/.htaccess : la CSP est donc vérifiée par les tests.
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self' https://nominatim.openstreetmap.org; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
    readfile($file);
    return true;
}
return false;
