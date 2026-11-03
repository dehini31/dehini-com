<?php
/**
 * Plan du site du défi Impact pour les moteurs de recherche (/impact/sitemap.xml) :
 * le défi et la page « toutes les questions » dans les trois langues, et chaque question
 * qui a au moins un récit publié. Chaque page indique ses versions dans les autres langues.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/seo.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

/** Un groupe de pages équivalentes : une entrée par langue, chacune listant toutes les versions. */
function sitemap_group(array $urls, ?int $lastmod, ?string $default = null): string
{
    $alts = '';
    foreach ($urls as $l => $u) $alts .= '    <xhtml:link rel="alternate" hreflang="' . $l . '" href="' . htmlspecialchars($u, ENT_XML1) . "\"/>\n";
    if ($default) $alts .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($default, ENT_XML1) . "\"/>\n";
    $out = '';
    foreach ($urls as $u) {
        $out .= "  <url>\n    <loc>" . htmlspecialchars($u, ENT_XML1) . "</loc>\n"
            . ($lastmod ? '    <lastmod>' . gmdate('Y-m-d', $lastmod) . "</lastmod>\n" : '') . $alts . "  </url>\n";
    }
    return $out;
}

$latest = (int) db()->query("SELECT MAX(updated) FROM stories WHERE status = 'published'")->fetchColumn();
$qs = questions();

$home = $hub = [];
foreach (IMPACT_LANGS as $l) {
    $home[$l] = home_url($l);
    $hub[$l] = hub_url($l);
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
echo sitemap_group($home, $latest ?: null, home_url());
echo sitemap_group($hub, $latest ?: null, $hub['fr']);
foreach (indexable_questions() as $id => $t) {
    $urls = [];
    foreach (IMPACT_LANGS as $l) $urls[$l] = q_url($qs[$id], $l);
    echo sitemap_group($urls, $t, $urls['fr']);
}
echo "</urlset>\n";
