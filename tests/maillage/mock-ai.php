<?php
/**
 * Faux service d'IA pour les tests de la recherche par photo : imite POST /v1/messages de l'API
 * Claude. Il refuse une requête mal formée comme le ferait le vrai service (clé, version, sortie
 * JSON structurée, paramètres retirés du modèle), note chaque appel dans <dossier>/mock-ai.log et
 * répond avec le contenu de <dossier>/mock-ai.json :
 *   {"identify": {…}, "verify": {…}, "status": 200, "stop_reason": "end_turn"}
 * Copié dans public_html/ par setup.sh ; maillage/config.php y pointe MLG_AI_URL.
 */
$dir = dirname(__DIR__);
$raw = (string) file_get_contents('php://input');
$req = json_decode($raw, true);
$h = function (string $name): string { return (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? ''); };
$out = function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('request-id: req_mock_' . bin2hex(random_bytes(4)));
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};
$err = function (int $status, string $type, string $msg) use ($out): void {
    $out($status, ['type' => 'error', 'error' => ['type' => $type, 'message' => $msg]]);
};

$cfg = is_file("$dir/mock-ai.json") ? (json_decode((string) file_get_contents("$dir/mock-ai.json"), true) ?: []) : [];
$schema = $req['output_config']['format']['schema'] ?? [];
$props = array_keys($schema['properties'] ?? []);
$kind = in_array('exact_id', $props, true) ? 'verify' : (in_array('is_product', $props, true) ? 'identify' : 'test');
$images = 0;
$text = '';
foreach ((array) ($req['messages'][0]['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'image') {
        $images++;
        $data = base64_decode((string) ($block['source']['data'] ?? ''), true);
        if (($block['source']['type'] ?? '') !== 'base64' || $data === false || !@getimagesizefromstring($data)) $err(400, 'invalid_request_error', 'image: invalid base64 image');
    } elseif (($block['type'] ?? '') === 'text') {
        $text .= $block['text'] . "\n";
    }
}
file_put_contents("$dir/mock-ai.log", json_encode([
    'kind' => $kind, 'model' => $req['model'] ?? '', 'images' => $images, 'effort' => $req['output_config']['effort'] ?? '', 'fallbacks' => $req['fallbacks'] ?? null,
    'version' => $h('anthropic-version'), 'beta' => $h('anthropic-beta'), 'key' => substr($h('x-api-key'), 0, 11), 'text' => mb_substr($text, 0, 4000),
], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($req)) $err(400, 'invalid_request_error', 'expected a JSON body');
if (strpos($h('x-api-key'), 'sk-ant-test') !== 0) $err(401, 'authentication_error', 'invalid x-api-key');
if ($h('anthropic-version') === '') $err(400, 'invalid_request_error', 'anthropic-version: header is required');
if (empty($req['model']) || empty($req['max_tokens']) || empty($req['messages'])) $err(400, 'invalid_request_error', 'model, max_tokens and messages are required');
// Ce que le modèle par défaut refuse : réflexion désactivée ou budgétée, appel d'outil forcé, réglages d'échantillonnage, préremplissage.
if (isset($req['thinking']) && ($req['thinking']['type'] ?? '') !== 'adaptive') $err(400, 'invalid_request_error', 'thinking: only adaptive is supported');
if (in_array($req['tool_choice']['type'] ?? '', ['any', 'tool'], true)) $err(400, 'invalid_request_error', 'tool_choice: type "tool" and "any" are not supported for this model.');
foreach (['temperature', 'top_p', 'top_k', 'output_format'] as $gone) { if (array_key_exists($gone, $req)) $err(400, 'invalid_request_error', "$gone: not supported"); }
if (($req['messages'][count($req['messages']) - 1]['role'] ?? '') !== 'user') $err(400, 'invalid_request_error', 'prefill is not supported');
if (($req['output_config']['format']['type'] ?? '') !== 'json_schema' || ($schema['additionalProperties'] ?? null) !== false || empty($schema['required'])) {
    $err(400, 'invalid_request_error', 'output_config.format: a json_schema with additionalProperties false and required is expected');
}
if (isset($req['fallbacks']) && $req['fallbacks'] === 'default' && strpos($h('anthropic-beta'), 'server-side-fallback-2026-07-01') === false) {
    $err(400, 'invalid_request_error', 'fallbacks: "default" needs the server-side-fallback-2026-07-01 beta');
}

$status = (int) ($cfg['status'] ?? 200);
if ($status !== 200) $err($status, $status === 529 ? 'overloaded_error' : 'api_error', 'mock failure');
$stop = (string) ($cfg['stop_reason'] ?? 'end_turn');
$defaults = [
    'identify' => ['is_product' => true, 'category' => 'autre', 'name' => 'Produit', 'brand' => '', 'details' => '', 'terms' => [], 'confidence' => 'medium'],
    'verify' => ['exact_id' => 0, 'confidence' => 'low', 'similar_ids' => []],
    'test' => ['ok' => true],
];
$answer = ($cfg[$kind] ?? []) + $defaults[$kind];
// Une réponse commence par un bloc de réflexion (vide par défaut) : le texte n'est pas forcément content[0].
$content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'mock']];
if ($stop !== 'refusal') $content[] = ['type' => 'text', 'text' => json_encode($answer, JSON_UNESCAPED_UNICODE)];
$out(200, ['id' => 'msg_mock_' . bin2hex(random_bytes(6)), 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'],
    'content' => $stop === 'refusal' ? [] : $content, 'stop_reason' => $stop, 'stop_details' => $stop === 'refusal' ? ['type' => 'refusal', 'category' => null, 'explanation' => 'mock'] : null,
    'usage' => ['input_tokens' => 1200, 'output_tokens' => 80]]);
