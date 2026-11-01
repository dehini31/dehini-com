<?php
/**
 * Fausse API Meta (Conversions API) et Telegram pour les tests : enregistre chaque requête
 * dans le fichier FAKE_LOG et répond comme le vrai service.
 *   FAKE_LOG=/tmp/x.jsonl php -S 127.0.0.1:8091 tests/swanbida/fake-services.php
 */
$log = getenv('FAKE_LOG') ?: sys_get_temp_dir() . '/swb-fake.jsonl';
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$body = file_get_contents('php://input');
file_put_contents($log, json_encode(['path' => $path, 'query' => $q, 'body' => json_decode($body, true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if (strpos($path, '/bot') === 0) { echo '{"ok":true,"result":{}}'; return; }
if (($q['access_token'] ?? '') === 'BAD') { http_response_code(400); echo '{"error":{"message":"Invalid OAuth access token.","type":"OAuthException","code":190}}'; return; }
$n = count(json_decode($body, true)['data'] ?? []);
echo json_encode(['events_received' => $n, 'messages' => [], 'fbtrace_id' => 'TEST' . bin2hex(random_bytes(4))]);
