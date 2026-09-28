<?php
/**
 * API minimale de l'admin dehini.com — pas de dépendance externe.
 *
 * Actions (via ?action=...) :
 *   POST login    {password}                 -> {ok, csrf}
 *   POST logout                              -> {ok}
 *   GET  session                              -> {ok, csrf} ou 401
 *   GET  data                                 -> contenu de data.json (auth requise)
 *   POST save     {data, csrf}                -> écrit data.json (auth requise)
 *   POST upload   multipart: file, csrf       -> {ok, url} (auth requise)
 */
declare(strict_types=1);

session_name('dehini_admin');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

$ROOT        = dirname(__DIR__);            // dossier du site (index.html, data.json, uploads/)
$DATA_FILE   = $ROOT . '/data.json';
$UPLOADS_DIR = $ROOT . '/uploads';
$CONFIG_FILE = __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_file($CONFIG_FILE)) {
    http_response_code(500);
    echo json_encode(['error' => "config.php manquant — voir README.md pour l'installation."]);
    exit;
}
require $CONFIG_FILE; // définit ADMIN_PASSWORD_HASH

function input_json(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
    return is_array($d) ? $d : [];
}
function fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
function ok(array $extra = []): void
{
    echo json_encode(['ok' => true] + $extra);
    exit;
}
function is_logged_in(): bool
{
    return !empty($_SESSION['admin'])
        && !empty($_SESSION['login_at'])
        && (time() - (int) $_SESSION['login_at']) < 12 * 60 * 60; // 12h
}
function require_auth(): void
{
    if (!is_logged_in()) fail('Session expirée, reconnecte-toi.', 401);
}
function require_csrf_token(string $token): void
{
    if (empty($_SESSION['csrf']) || $token === '' || !hash_equals((string) $_SESSION['csrf'], $token)) {
        fail('Jeton de sécurité invalide, recharge la page.', 403);
    }
}

$action = $_GET['action'] ?? '';

switch ($action) {

    case 'login': {
        $body = input_json();
        $pass = (string) ($body['password'] ?? '');

        // Anti-force-brute simple : compteur d'essais en session + délai croissant.
        $fails = (int) ($_SESSION['fails'] ?? 0);
        if ($fails >= 5) {
            usleep(1500000);
        }
        if ($pass === '' || !password_verify($pass, ADMIN_PASSWORD_HASH)) {
            $_SESSION['fails'] = $fails + 1;
            usleep(400000);
            fail('Mot de passe incorrect.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['admin']    = true;
        $_SESSION['login_at'] = time();
        $_SESSION['fails']    = 0;
        $_SESSION['csrf']     = bin2hex(random_bytes(24));
        ok(['csrf' => $_SESSION['csrf']]);
        break;
    }

    case 'logout': {
        $_SESSION = [];
        session_destroy();
        ok();
        break;
    }

    case 'session': {
        if (!is_logged_in()) fail('Non connecté.', 401);
        if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
        ok(['csrf' => $_SESSION['csrf']]);
        break;
    }

    case 'data': {
        require_auth();
        if (!is_file($DATA_FILE)) fail('data.json introuvable sur le serveur.', 404);
        echo file_get_contents($DATA_FILE);
        exit;
    }

    case 'save': {
        require_auth();
        $body = input_json();
        require_csrf_token((string) ($body['csrf'] ?? ''));

        $data = $body['data'] ?? null;
        if (!is_array($data)) fail('Contenu invalide.');
        foreach (['hero', 'experiences', 'projects', 'skills', 'contact'] as $k) {
            if (!array_key_exists($k, $data)) fail("Champ manquant dans les données : $k");
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) fail('Échec de conversion en JSON.');

        $tmp = $DATA_FILE . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) fail('Écriture impossible (droits du dossier ?).', 500);
        if (!rename($tmp, $DATA_FILE)) fail('Écriture impossible (rename).', 500);
        @chmod($DATA_FILE, 0644);

        ok();
        break;
    }

    case 'upload': {
        require_auth();
        require_csrf_token((string) ($_POST['csrf'] ?? ''));

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            fail("Envoi du fichier échoué.");
        }
        $f = $_FILES['file'];
        if ($f['size'] > 6 * 1024 * 1024) fail('Image trop lourde (max 6 Mo).');

        $info = @getimagesize($f['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!$info || !isset($allowed[$info['mime']])) {
            fail("Format d'image non accepté (jpg, png, webp, gif uniquement).");
        }
        $ext = $allowed[$info['mime']];

        if (!is_dir($UPLOADS_DIR) && !mkdir($UPLOADS_DIR, 0755, true) && !is_dir($UPLOADS_DIR)) {
            fail("Impossible de créer le dossier uploads/.", 500);
        }
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $UPLOADS_DIR . '/' . $name;
        if (!move_uploaded_file($f['tmp_name'], $dest)) fail("Impossible d'enregistrer l'image.", 500);
        @chmod($dest, 0644);

        ok(['url' => 'uploads/' . $name]);
        break;
    }

    default:
        fail('Action inconnue.', 404);
}
