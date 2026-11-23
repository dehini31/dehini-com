<?php
/**
 * Test unitaire (PHP en ligne de commande, sans serveur) du contrôle des réglages PayPal :
 * billing_config_check() doit repérer les erreurs de saisie courantes (environnement mal écrit, secret à la place
 * de l'identifiant client, identifiant de webhook ou de formule mal copié, doublons, http en live), sans jamais
 * citer les valeurs. Lancé par tests/studioma/run.sh :  php tests/studioma/billing-config.php
 */
declare(strict_types=1);

require __DIR__ . '/../../studioma/lib/plans.php';
require __DIR__ . '/../../studioma/lib/billing.php';

$fails = 0; $n = 0;
function check(bool $cond, string $label): void
{
    global $fails, $n;
    $n++;
    if (!$cond) { $fails++; echo "ÉCHEC : $label\n"; }
}
$plans = ['solo' => ['month' => 'P-1AB23456CD789012EFGHIJKL', 'year' => 'P-2AB23456CD789012EFGHIJKL'],
          'team' => ['month' => 'P-3AB23456CD789012EFGHIJKL', 'year' => 'P-4AB23456CD789012EFGHIJKL']];
$sandbox = ['env' => 'sandbox', 'client_id' => 'AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPp0123456789', 'secret' => 'EeFfGgHhIiJjKkLlMmNnOoPp0123456789QqRr',
            'webhook_id' => '8PT597110X687430LKGECATA', 'prices' => $plans, 'site_url' => ''];
$live = ['env' => 'live', 'site_url' => 'https://dehini.com/studioma/'] + $sandbox;
$has = fn (array $c, string $needle) => (bool) array_filter(billing_config_check($c), fn ($p) => str_contains($p, $needle));

check(billing_config_check($sandbox) === [], 'réglages sandbox cohérents : aucun problème');
check(billing_config_check($live) === [], 'réglages live cohérents : aucun problème');
check(billing_config_check(['env' => 'sandbox']) === [], 'réglages vides : rien à signaler (billing_missing() s\'en charge)');
check($has(['env' => 'production'] + $live, 'STM_PAYPAL_ENV'), 'STM_PAYPAL_ENV « production » : refusé (PayPal dit « live », sinon lu comme sandbox)');
check($has(['secret' => $sandbox['client_id']] + $sandbox, 'same as STM_PAYPAL_CLIENT_ID'), 'identifiant client collé comme secret : signalé');
check($has(['client_id' => 'AaBb Cc'] + $sandbox, 'STM_PAYPAL_CLIENT_ID looks incomplete'), 'identifiant client tronqué ou avec espaces : signalé');
check($has(['secret' => 'short'] + $sandbox, 'STM_PAYPAL_SECRET looks incomplete'), 'secret tronqué : signalé');
check($has(['webhook_id' => 'https://dehini.com/studioma/paypal-webhook.php'] + $sandbox, 'STM_PAYPAL_WEBHOOK_ID'), 'adresse du webhook au lieu de son ID : signalée');
check($has(['prices' => ['solo' => ['month' => 'PROD-1AB23456CD789012', 'year' => ''], 'team' => []]] + $live, 'STM_PAYPAL_PLAN_SOLO_MONTHLY is not a PayPal plan ID'), 'identifiant de produit à la place d\'une formule : signalé');
check($has(['prices' => ['solo' => ['month' => 'P-1AB23456CD789012EFGHIJKL', 'year' => 'P-1AB23456CD789012EFGHIJKL'], 'team' => []]] + $live, 'uses the same plan as'), 'même formule pour deux réglages : signalé');
check($has(['site_url' => 'http://dehini.com/studioma/'] + $live, 'STM_SITE_URL'), 'site en http en live : signalé');
check(!$has(['site_url' => 'http://localhost/studioma/'] + $sandbox, 'STM_SITE_URL'), 'site en http en sandbox : permis (tests)');
$all = implode(' ', billing_config_check(['env' => 'live', 'client_id' => 'SECRETVALUE1234567890', 'secret' => 'SECRETVALUE1234567890',
    'webhook_id' => 'bad hook value', 'prices' => ['solo' => ['month' => 'pri_PLANVALUE']]]));
check(!str_contains($all, 'SECRETVALUE') && !str_contains($all, 'bad hook value') && !str_contains($all, 'PLANVALUE'), 'les messages ne citent jamais les valeurs');

echo "billing-config : " . ($n - $fails) . "/$n vérifications réussies\n";
exit($fails ? 1 : 0);
