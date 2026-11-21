<?php
/**
 * Test unitaire (PHP en ligne de commande, sans serveur) du contrôle des réglages Paddle :
 * billing_config_check() doit repérer un mélange sandbox / production et les erreurs de saisie courantes,
 * sans jamais citer les valeurs. Lancé par tests/studioma/run.sh :  php tests/studioma/billing-config.php
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
$prices = ['solo' => ['month' => 'pri_01aaaaaaaaaaaaaaaaaaaaaaaa', 'year' => 'pri_01bbbbbbbbbbbbbbbbbbbbbbbb'],
           'team' => ['month' => 'pri_01cccccccccccccccccccccccc', 'year' => 'pri_01dddddddddddddddddddddddd']];
$sandbox = ['env' => 'sandbox', 'api_key' => 'pdl_sdbx_apikey_01xxxxxxxx_yyyy', 'client_token' => 'test_0123456789abcdef',
            'webhook_secret' => 'pdl_ntfset_01zzzz_wwww', 'prices' => $prices, 'site_url' => ''];
$live = ['env' => 'production', 'api_key' => 'pdl_live_apikey_01xxxxxxxx_yyyy', 'client_token' => 'live_0123456789abcdef',
         'webhook_secret' => 'pdl_ntfset_01zzzz_wwww', 'prices' => $prices, 'site_url' => 'https://dehini.com/studioma/'];
$has = fn (array $c, string $needle) => (bool) array_filter(billing_config_check($c), fn ($p) => str_contains($p, $needle));

check(billing_config_check($sandbox) === [], 'réglages sandbox cohérents : aucun problème');
check(billing_config_check($live) === [], 'réglages production cohérents : aucun problème');
check(billing_config_check(['env' => 'sandbox']) === [], 'réglages vides : rien à signaler (billing_missing() s\'en charge)');
check(billing_config_check(['env' => 'sandbox', 'api_key' => 'abcdef0123456789', 'client_token' => 'ctok_legacy']) === [], 'valeurs sans préfixe connu (ancien format) : pas signalées');
check($has(['env' => 'live'] + $live, 'STM_PADDLE_ENV'), 'STM_PADDLE_ENV « live » : refusé (sinon lu comme sandbox)');
check($has(['api_key' => $sandbox['api_key']] + $live, 'STM_PADDLE_API_KEY is a sandbox key'), 'clé sandbox avec production : signalée');
check($has(['api_key' => $live['api_key']] + $sandbox, 'STM_PADDLE_API_KEY is a live key'), 'clé live avec sandbox : signalée');
check($has(['client_token' => $sandbox['client_token']] + $live, 'STM_PADDLE_CLIENT_TOKEN is a sandbox token'), 'jeton client sandbox avec production : signalé');
check($has(['client_token' => $live['client_token']] + $sandbox, 'STM_PADDLE_CLIENT_TOKEN is a live token'), 'jeton client live avec sandbox : signalé');
check($has(['webhook_secret' => $live['api_key']] + $live, 'STM_PADDLE_WEBHOOK_SECRET looks like an API key'), 'clé d\'API collée comme secret de webhook : signalée');
check($has(['client_token' => $live['api_key']] + $live, 'client-side token'), 'clé d\'API collée comme jeton client : signalée');
check($has(['prices' => ['solo' => ['month' => 'pro_01abc', 'year' => 'pri_x'], 'team' => []]] + $live, 'STM_PADDLE_PRICE_SOLO_MONTHLY is not a Paddle price ID'), 'identifiant de produit à la place d\'un prix : signalé');
check($has(['prices' => ['solo' => ['month' => 'pri_same', 'year' => 'pri_same'], 'team' => []]] + $live, 'uses the same price as'), 'même prix pour deux formules : signalé');
check($has(['site_url' => 'http://dehini.com/studioma/'] + $live, 'STM_SITE_URL'), 'site en http en production : signalé');
$all = implode(' ', billing_config_check(['env' => 'production', 'api_key' => 'pdl_sdbx_apikey_SECRETVALUE', 'client_token' => 'test_TOKENVALUE',
    'webhook_secret' => 'pdl_live_apikey_OTHERSECRET', 'prices' => $prices]));
check(!str_contains($all, 'SECRETVALUE') && !str_contains($all, 'TOKENVALUE') && !str_contains($all, 'OTHERSECRET'), 'les messages ne citent jamais les valeurs');

echo "billing-config : " . ($n - $fails) . "/$n vérifications réussies\n";
exit($fails ? 1 : 0);
