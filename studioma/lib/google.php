<?php
/**
 * « Continue with Google » : la page reçoit de Google un jeton d'identité signé (JWT RS256) ; on
 * vérifie ici sa signature avec les clés publiques de Google, puis son émetteur, son destinataire
 * (notre ID client), sa date d'expiration et l'adresse confirmée. Aucun secret n'est nécessaire,
 * seulement l'ID client OAuth — le même que celui du défi Impact et de Stats Meta KPIs, puisque
 * c'est la même origine (https://dehini.com).
 */
declare(strict_types=1);

/**
 * ID client Google : celui de studioma/config.php s'il est défini, sinon celui du défi Impact
 * (réglage de son administration, puis impact/config.php). '' : bouton Google masqué.
 */
function google_client_id(): string
{
    static $id = null;
    if ($id !== null) return $id;
    $id = trim((string) STM_GOOGLE_CLIENT_ID);
    if ($id === '' && STM_GOOGLE_FROM_IMPACT) {
        $db = dirname(STM_ROOT, 2) . '/impact-data/impact.sqlite';
        if (is_file($db)) {
            try {
                $p = new PDO('sqlite:' . $db, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $s = $p->prepare('SELECT v FROM meta WHERE k = ?');
                $s->execute(['cfg.google_client_id']);
                $id = trim((string) $s->fetchColumn());
            } catch (Throwable $e) {
                $id = '';
            }
        }
        if ($id === '' && defined('IMPACT_GOOGLE_CLIENT_ID')) $id = trim((string) IMPACT_GOOGLE_CLIENT_ID);
    }
    if (!preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $id)) $id = '';
    return $id;
}

function b64url_decode(string $s): string
{
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? '' : $d;
}

/** GET simple : [code HTTP, corps, en-têtes en minuscules]. Lève une exception si le réseau échoue. */
function http_get(string $url, int $timeout = 10, int $maxBytes = 2097152): array
{
    $headers = [];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_MAXFILESIZE => $maxBytes,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) $headers[strtolower(trim($p[0]))] = trim($p[1]);
                return strlen($line);
            },
        ]);
        $body = curl_exec($ch);
        if ($body === false) throw new RuntimeException('HTTP : ' . curl_error($ch));
        return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), substr((string) $body, 0, $maxBytes), $headers];
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]), 0, $maxBytes);
    if ($body === false) throw new RuntimeException('HTTP : échec');
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) $code = (int) $m[1];
        elseif (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    return [$code, (string) $body, $headers];
}

/**
 * Clés publiques de Google ({kid: PEM}), gardées en base le temps indiqué par Google.
 * STM_GOOGLE_CERTS peut être un chemin de fichier local (tests).
 */
function google_certs(bool $refresh = false): array
{
    $c = jarr(meta_get('google_certs'));
    $fresh = ($c['exp'] ?? 0) > time();
    if ($fresh && !$refresh) return (array) ($c['keys'] ?? []);
    // Un jeton signé d'une clé inconnue ne déclenche qu'un rechargement par minute.
    if ($refresh && ($c['at'] ?? 0) > time() - 60) return (array) ($c['keys'] ?? []);
    try {
        $src = (string) STM_GOOGLE_CERTS;
        if (preg_match('~^https://~', $src)) {
            [$code, $body, $headers] = http_get($src);
        } else {
            [$code, $body, $headers] = [is_file($src) ? 200 : 404, (string) @file_get_contents($src), []];
        }
        $keys = json_decode($body, true);
        if ($code !== 200 || !is_array($keys) || !$keys) throw new RuntimeException('réponse ' . $code);
    } catch (Throwable $e) {
        error_log('studioma google certs: ' . $e->getMessage());
        return (array) ($c['keys'] ?? []);
    }
    $ttl = preg_match('/max-age=(\d+)/', $headers['cache-control'] ?? '', $m) ? (int) $m[1] : 3600;
    meta_set('google_certs', jenc(['exp' => time() + min(max($ttl, 300), 86400), 'at' => time(), 'keys' => $keys]));
    return $keys;
}

/** Vérifie un jeton d'identité Google ; renvoie ses informations, ou null s'il n'est pas valable. */
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
    if (empty($claims['sub']) || !is_string($claims['sub']) || strlen($claims['sub']) > 64) return null;
    if (empty($claims['email']) || !($verified === true || $verified === 'true')) return null;
    return $claims;
}

/** Jeton reçu du navigateur → informations vérifiées ; sinon échec affichable. */
function google_claims(array $b): array
{
    $cid = google_client_id();
    if ($cid === '') fail('Google sign-in is not available right now.', 400, 'google_off');
    $c = google_verify(is_string($b['credential'] ?? null) ? $b['credential'] : '', $cid);
    if (!$c) fail('Google could not confirm your identity. Please try again.', 401, 'google_bad');
    $email = strtolower(trim((string) $c['email']));
    if (!valid_email($email)) fail('Your Google account has no usable email address.', 400, 'google_bad');
    $c['email'] = $email;
    return $c;
}

/**
 * Photo de profil Google reprise comme photo du compte (au mieux : sans photo, rien ne change).
 * Elle est téléchargée et ré-encodée ici : la page ne charge jamais d'image d'un autre site.
 */
function google_avatar(int $uid, $url): void
{
    if (!is_string($url) || !preg_match('~^https://[a-z0-9.-]+\.googleusercontent\.com/~i', $url)) return;
    try {
        [$code, $body] = http_get(preg_replace('/=s\d+(-c)?$/', '', $url) . '=s400-c', 8, IMAGE_MAX_BYTES);
        if ($code !== 200 || $body === '') return;
        $tmp = tempnam(sys_get_temp_dir(), 'stm');
        file_put_contents($tmp, $body);
        try {
            $f = image_ingest_path($tmp, $uid, 'google.jpg');
        } finally {
            @unlink($tmp);
        }
        q("UPDATE users SET avatar = ? WHERE id = ? AND avatar = ''", [$f['token'], $uid]);
    } catch (Throwable $e) {
        error_log('studioma google avatar: ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------- API */

/** Comptes : une seule identité Google par compte, et un compte par identité Google. */
function google_user(string $sub): ?array
{
    return row('SELECT * FROM users WHERE google_sub = ?', [$sub]);
}

/**
 * Relie l'identité Google à un compte existant dont l'adresse est la même. L'adresse n'ayant jamais
 * été confirmée, un mot de passe choisi par quelqu'un d'autre a pu être posé sur ce compte avant
 * que son vrai propriétaire n'arrive : dans ce cas, le mot de passe est retiré et les autres
 * sessions sont fermées. Le propriétaire, confirmé par Google, peut en choisir un nouveau.
 */
function google_link(array $u, string $sub): array
{
    $reset = (string) $u['pass'] !== '' && !(int) $u['email_ok'];
    // Compte repris à quelqu'un qui avait pris l'adresse : c'est à son vrai propriétaire d'accepter les conditions.
    q('UPDATE users SET google_sub = ?, email_ok = 1' . ($reset ? ", pass = '', terms_at = 0" : '') . ' WHERE id = ?', [$sub, $u['id']]);
    if ($reset) q('DELETE FROM sessions WHERE uid = ?', [$u['id']]);
    promo_on_verified((int) $u['id']);   // adresse vérifiée par Google : parrainage en attente (lib/promo.php)
    return ['reset' => $reset];
}

/**
 * Connexion (ou inscription) avec Google. Si aucun compte n'existe pour cette identité ni pour
 * cette adresse, un compte est créé : kind et company viennent de la page d'inscription.
 */
function api_google(array $b, ?array $u): array
{
    rate('google', 20, 900);
    $c = google_claims($b);
    $sub = (string) $c['sub'];
    $t = google_user($sub);
    $extra = ['created' => false, 'password_reset' => false];
    if (!$t) {
        $t = row('SELECT * FROM users WHERE email = ?', [$c['email']]);
        if ($t) {
            if ((string) $t['google_sub'] !== '') fail('This email is already linked to another Google account. Sign in with that one, or with your password.', 409, 'google_other');
            $extra['password_reset'] = google_link($t, $sub)['reset'];
        } else {
            $kind = ($b['kind'] ?? '') === 'company' ? 'company' : 'individual';
            $company = line_clean($b['company'] ?? '', 80);
            if ($kind === 'company' && mb_strlen($company) < 2) fail('Enter the name of your company first.');
            $name = line_clean($c['name'] ?? '', 60);
            if (mb_strlen($name) < 2) $name = line_clean(trim(($c['given_name'] ?? '') . ' ' . ($c['family_name'] ?? '')), 60);
            if (mb_strlen($name) < 2) $name = line_clean(ucfirst((string) strstr($c['email'], '@', true)), 60) ?: 'Member';
            $uid = tx(function () use ($c, $name, $kind, $company, $sub): int {
                $uid = account_create($c['email'], $name, '', $kind);
                q('UPDATE users SET google_sub = ?, email_ok = 1 WHERE id = ?', [$sub, $uid]);
                if ($kind === 'company') company_create(['name' => $company], $uid);
                return $uid;
            });
            google_avatar($uid, $c['picture'] ?? '');
            // Offre de lancement : essai de 30 jours, parrainage (adresse vérifiée par Google) — lib/promo.php.
            $extra['trial'] = promo_on_signup($uid, true, $u);
            $t = ['id' => $uid];
            mail_later($c['email'], 'Welcome to ' . STM_NAME, 'Welcome, ' . $name . '!', [
                'Your account is ready — you signed up with Google. ' . ($kind === 'company' ? 'Set up your company page, create your first Studio and invite your team.' : 'Complete your profile, create your first Studio and invite your collaborators.'),
                'Create together. Grow professionally. Get discovered.',
            ], 'Open ' . STM_NAME, app_url());
            $extra['created'] = true;
        }
        $t = user_by_id((int) $t['id']);
    }
    // Google ne confirme l'adresse du compte que si c'est la sienne : le compte a pu changer d'adresse
    // depuis qu'il a été relié à Google, et cette nouvelle adresse, Google ne l'a pas vérifiée.
    if (!(int) $t['email_ok'] && (string) $t['email'] === $c['email']) {
        q('UPDATE users SET email_ok = 1 WHERE id = ?', [$t['id']]);
        $t['email_ok'] = 1;
        promo_on_verified((int) $t['id']);
    }
    return me_view($t, start_session((int) $t['id'])) + $extra;
}
/** Relie Google au compte connecté (depuis les réglages). */
function api_google_link(array $b, array $u): array
{
    rate('google_link', 20, 900, 'u' . $u['id']);
    $c = google_claims($b);
    $other = google_user((string) $c['sub']);
    if ($other && (int) $other['id'] !== (int) $u['id']) fail('This Google account is already linked to another Studioma account.', 409, 'google_other');
    if ((string) $u['google_sub'] !== '' && $u['google_sub'] !== $c['sub']) fail('Your account is already linked to another Google account. Disconnect it first.', 409, 'google_other');
    // Le compte Google prouve l'adresse s'il porte la même ; sinon le lien est fait sans la confirmer.
    q('UPDATE users SET google_sub = ?, email_ok = CASE WHEN email = ? THEN 1 ELSE email_ok END WHERE id = ?', [$c['sub'], $c['email'], $u['id']]);
    return me_view(user_by_id((int) $u['id']), (string) $u['s_csrf']);
}
/** Retire le lien avec Google — seulement si le compte a un mot de passe pour se connecter ensuite. */
function api_google_unlink(array $b, array $u): array
{
    if ((string) $u['pass'] === '') fail('Choose a password first: without Google, it will be the only way to sign in.');
    q('UPDATE users SET google_sub = NULL WHERE id = ?', [$u['id']]);
    return me_view(user_by_id((int) $u['id']), (string) $u['s_csrf']);
}
