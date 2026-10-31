<?php
/**
 * Téléchargement de l'extension Chrome : le dossier extension/ est zippé à la volée,
 * donc le zip est toujours à jour avec le code déployé.
 */
declare(strict_types=1);

$dir = __DIR__ . '/extension';
$man = json_decode((string) @file_get_contents($dir . '/manifest.json'), true);
$ver = is_array($man) && preg_match('/^[\d.]+$/', (string) ($man['version'] ?? '')) ? $man['version'] : 'latest';
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "L'extension PHP « zip » n'est pas activée sur ce serveur (cPanel → Select PHP Version → Extensions → zip).";
    exit;
}
$tmp = tempnam(sys_get_temp_dir(), 'smk');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit;
}
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
    if ($rel === '' || $rel[0] === '.' || preg_match('~(^|/)\.~', $rel)) continue;
    $zip->addFile($f->getPathname(), 'stats-meta-kpis/' . $rel);
}
$zip->close();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="stats-meta-kpis-' . $ver . '.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
readfile($tmp);
@unlink($tmp);
