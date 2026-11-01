<?php
/**
 * Routeur pour le serveur PHP intégré : reproduit le .htaccess de swanbida/ (réécriture vers
 * index.php, dossiers internes interdits, scripts interdits dans media/) afin de tester la
 * boutique sous /swanbida/ comme sur l'hébergement cPanel.
 *   php -S 127.0.0.1:8090 -t <public_html> tests/swanbida/router.php
 */
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
if (strpos($uri, '/swanbida') !== 0) return false;
$deny = preg_match('~^/swanbida/(lib|templates)(/|$)~', $uri)
    || preg_match('~/(config\.php|config\.example\.php|cron\.php|\.user\.ini|\.htaccess|[^/]*\.sqlite[^/]*|[^/]*\.md)$~', $uri)
    || preg_match('~^/swanbida/media/.*\.(php\d?|phtml|phar|html?|svg|js)$~i', $uri);
if ($deny) { http_response_code(403); echo 'Forbidden'; return true; }
$file = $root . $uri;
if (is_file($file)) {
    if (substr($file, -4) === '.php') {
        $_SERVER['SCRIPT_NAME'] = $uri;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        chdir(dirname($file));
        require $file;
        return true;
    }
    return false;
}
if (is_dir($file)) {
    if (substr($uri, -1) !== '/') { header('Location: ' . $uri . '/', true, 301); return true; }
    if (is_file($file . 'index.html')) { header('Content-Type: text/html; charset=utf-8'); readfile($file . 'index.html'); return true; }
}
$_SERVER['SCRIPT_NAME'] = '/swanbida/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/swanbida/index.php';
chdir($root . '/swanbida');
require $root . '/swanbida/index.php';
return true;
