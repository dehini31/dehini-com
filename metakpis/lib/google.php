<?php
/**
 * « Continuer avec Google » : la page reçoit de Google un jeton d'identité signé
 * (JWT RS256) ; on vérifie ici sa signature avec les clés publiques de Google, puis
 * son émetteur, son destinataire (notre ID client) et sa date d'expiration.
 * Aucun secret n'est nécessaire, seulement l'ID client OAuth.
 */
declare(strict_types=1);

/**
 * ID client Google : réglage de l'admin Stats Meta KPIs, sinon celui déjà saisi pour le
 * défi Impact (même site, même origine autorisée https://dehini.com).
 */
function google_client_id(): string
{
    $v = trim(setting('google_client_id'));
    if ($v !== '') return $v;
    static $impact = null;
    if ($impact === null) {
        $impact = '';
        if (is_file(METAKPIS_IMPACT_DB)) {
            try {
                $p = new PDO('sqlite:' . METAKPIS_IMPACT_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $s = $p->prepare('SELECT v FROM meta WHERE k = ?');
                $s->execute(['cfg.google_client_id']);
                $impact = trim((string) $s->fetchColumn());
            } catch (Throwable $e) {
                $impact = '';
            }
        }
    }
    return $impact;
}

function b64url_decode(string $s): string
{
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? '' : $d;
}

/** Certificats publics de Google ({kid: PEM}), gardés en base le temps indiqué par Google. */
function google_certs(bool $refresh = false): array
{
    $c = json_decode((string) meta_get('google_certs'), true);
    $fresh = is_array($c) && ($c['exp'] ?? 0) > time();
    if ($fresh && !$refresh) return $c['keys'];
    if ($refresh && is_array($c) && ($c['at'] ?? 0) > time() - 60) return $c['keys'] ?? [];
    try {
        [$code, $body, $headers] = http_req(METAKPIS_GOOGLE_CERTS, [], null, 10);
        $keys = json_decode($body, true);
        if ($code !== 200 || !is_array($keys) || !$keys) throw new RuntimeException('réponse ' . $code);
    } catch (Throwable $e) {
        error_log('metakpis google certs: ' . $e->getMessage());
        return is_array($c) ? ($c['keys'] ?? []) : [];
    }
    $ttl = preg_match('/max-age=(\d+)/', $headers['cache-control'] ?? '', $m) ? (int) $m[1] : 3600;
    meta_set('google_certs', (string) json_encode(['exp' => time() + min(max($ttl, 300), 86400), 'at' => time(), 'keys' => $keys]));
    return $keys;
}

/** Vérifie un jeton d'identité Google ; renvoie ses informations ou null. */
function google_verify(string $jwt, string $clientId): ?array
{
    $parts = explode('.', $jwt);
    if ($clientId === '' || count($parts) !== 3 || strlen($jwt) > 8192) return null;
    [$h64, $c64, $s64] = $parts;
    $head = json_decode(b64url_decode($h64), true);
    $claims = json_decode(b64url_decode($c64), true);
    $sig = b64url_decode($s64);
    if (!is_array($head) || !is_array($claims) || $sig === '' || ($head['alg'] ?? '') !== 'RS256') return null;
    $kid = (string) ($head['kid'] ?? '');
    $certs = google_certs();
    if (!isset($certs[$kid])) $certs = google_certs(true);
    if (!isset($certs[$kid]) || !is_string($certs[$kid])) return null;
    $key = openssl_pkey_get_public($certs[$kid]);
    if ($key === false || openssl_verify($h64 . '.' . $c64, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) return null;
    $now = time();
    if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) return null;
    if (($claims['aud'] ?? '') !== $clientId) return null;
    if ((int) ($claims['exp'] ?? 0) < $now - 60 || (int) ($claims['iat'] ?? 0) > $now + 300) return null;
    $verified = $claims['email_verified'] ?? false;
    if (empty($claims['sub']) || empty($claims['email']) || !($verified === true || $verified === 'true')) return null;
    return $claims;
}
