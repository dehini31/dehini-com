<?php
/**
 * Désinscription des emails « news » de Studioma (campagnes envoyées depuis l'administration), sans se
 * connecter, avec le lien signé de chaque email de campagne (lib/admin.php › unsubscribe_url()).
 *
 * GET : page de confirmation — un logiciel qui ouvre les liens des emails ne désinscrit donc personne.
 * POST : désinscription (aussi « en un clic » depuis la messagerie, RFC 8058 : List-Unsubscribe-Post), ou
 * réinscription (action=subscribe). Les emails de service (mot de passe, invitations…) ne changent pas.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/admin.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$uid = (int) ($_GET['u'] ?? 0);
$sig = (string) ($_GET['t'] ?? '');
$state = 'invalid';
try {
    $t = $uid > 0 && preg_match('/^[a-f0-9]{32}$/', $sig) && hash_equals(unsubscribe_sig($uid), $sig) ? user_by_id($uid) : null;
    if ($t) {
        $s = user_settings($t);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $s['email_news'] = ($_POST['action'] ?? '') === 'subscribe';
            q('UPDATE users SET settings = ? WHERE id = ?', [jenc($s), $uid]);
            $state = $s['email_news'] ? 'subscribed' : 'unsubscribed';
        } else {
            $state = $s['email_news'] === false ? 'already' : 'confirm';
        }
    }
} catch (Throwable $e) {
    error_log('studioma unsubscribe: ' . $e->getMessage());
    http_response_code(500);
    $state = 'error';
}
if ($state === 'invalid') http_response_code(404);

$e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
$name = STM_NAME;
$texts = [
    'confirm' => ['Unsubscribe from ' . $name . ' news?', 'You will no longer receive announcements and news from the ' . $name . ' team. Emails about your account (passwords, invitations, notifications you turned on) are not affected.'],
    'already' => ['You are already unsubscribed', 'You don\'t receive ' . $name . ' news anymore. Changed your mind? Subscribe again below.'],
    'unsubscribed' => ['You are unsubscribed', 'You will no longer receive ' . $name . ' news. You can subscribe again at any time, here or in Settings.'],
    'subscribed' => ['You are subscribed again', 'Welcome back: you will receive ' . $name . ' news again. You can unsubscribe at any time.'],
    'invalid' => ['This link doesn\'t work', 'It may be incomplete. Open the link from the email again, or change your email preferences in ' . $name . ' › Settings.'],
    'error' => ['Something went wrong', 'Please try again in a few minutes.'],
][$state];
$button = '';
if ($state === 'confirm' || $state === 'subscribed') $button = '<form method="post"><button type="submit">Unsubscribe</button></form>';
if ($state === 'already' || $state === 'unsubscribed') $button = '<form method="post"><input type="hidden" name="action" value="subscribe"><button type="submit" class="soft">Subscribe again</button></form>';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($texts[0]) ?> — <?= $e($name) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<style>
  :root { color-scheme: light dark; --bg: #f5f4f0; --card: #fff; --text: #1b1a22; --muted: #777483; --primary: #5b4bff; --soft: #eeecff; }
  @media (prefers-color-scheme: dark) { :root { --bg: #111015; --card: #1a1920; --text: #f1f0f5; --muted: #9390a1; --primary: #7c6eff; --soft: #25224a; } }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: var(--bg); color: var(--text); font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; padding: 16px; box-sizing: border-box; }
  main { max-width: 440px; width: 100%; background: var(--card); border-radius: 20px; padding: 30px; box-shadow: 0 6px 18px rgba(27, 26, 34, .09); }
  .brand { font-size: 13px; letter-spacing: .08em; text-transform: uppercase; color: var(--primary); font-weight: 700; margin: 0 0 10px; }
  h1 { font-size: 21px; line-height: 1.25; margin: 0 0 10px; }
  p { margin: 0 0 18px; color: var(--muted); }
  button { font: inherit; font-weight: 700; border: 0; border-radius: 10px; padding: 11px 18px; background: var(--primary); color: #fff; cursor: pointer; }
  button.soft { background: var(--soft); color: var(--primary); }
  a { color: var(--primary); }
  .foot { margin: 18px 0 0; font-size: 13px; }
</style>
</head>
<body>
<main>
  <p class="brand"><?= $e($name) ?></p>
  <h1><?= $e($texts[0]) ?></h1>
  <p><?= $e($texts[1]) ?></p>
  <?= $button ?>
  <p class="foot"><a href="app/">Open <?= $e($name) ?></a></p>
</main>
</body>
</html>
