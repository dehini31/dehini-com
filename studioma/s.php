<?php
/**
 * Lien de partage d'un Studio : https://dehini.com/studioma/s/<clé>[/card/<n°>][?v=<lien magique>]
 * (réécrit par .htaccess). Renvoie vers l'application (#/s/<clé>…), où tout est vérifié par l'API.
 *
 * Rien n'est lu dans la base ici : une clé inconnue ou désactivée est refusée par l'application, avec le
 * même message pour tous. La clé ne part vers aucun autre site (no-referrer), la page n'est jamais mise en
 * cache ni indexée, et le renvoi se fait toujours en https sur le vrai domaine.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

$key = (string) ($_GET['t'] ?? '');
$card = (string) ($_GET['c'] ?? '');
$link = (string) ($_GET['v'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{32}$/', $key) || ($card !== '' && !ctype_digit($card)) || ($link !== '' && !preg_match('/^[a-f0-9]{48}$/', $link))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This link is not valid.';
    exit;
}
header('Location: ' . app_url('s/' . $key . ($card !== '' ? '/card/' . $card : '') . ($link !== '' ? '?v=' . $link : '')), true, 302);
