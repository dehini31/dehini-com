<?php
/**
 * Liste des préinscriptions Yinvesti × ASC 2026 — réservée à l'admin de
 * dehini.com (même mot de passe et même session que /admin).
 *
 *   candidatures.php                 liste, la plus récente en premier
 *   candidatures.php?dossier=…       fiche complète
 *   candidatures.php?fichier=…       télécharge le document joint
 *   candidatures.php?export=csv      télécharge candidatures.csv (Excel)
 */
declare(strict_types=1);

require __DIR__ . '/_lib.php';

session_name('dehini_admin');
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$connecte = !empty($_SESSION['admin']) && !empty($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) < 12 * 60 * 60;
$erreur = '';

if (!$connecte && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $config = dirname(__DIR__) . '/admin/config.php';
    if (is_file($config)) {
        require_once $config; // ADMIN_PASSWORD_HASH
    }
    $essais = (int) ($_SESSION['fails'] ?? 0);
    if ($essais >= 5) {
        usleep(1500000);
    }
    if (defined('ADMIN_PASSWORD_HASH') && password_verify((string) ($_POST['password'] ?? ''), ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['login_at'] = time();
        $_SESSION['fails'] = 0;
        header('Location: candidatures.php');
        exit;
    }
    $_SESSION['fails'] = $essais + 1;
    usleep(400000);
    $erreur = defined('ADMIN_PASSWORD_HASH') ? 'Mot de passe incorrect.' : 'admin/config.php manquant — voir README.md.';
}

if (isset($_GET['deconnexion'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: candidatures.php');
    exit;
}

$base = yinv_dossier();

if ($connecte && ($_GET['export'] ?? '') === 'csv') {
    $csv = $base . '/candidatures.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="candidatures-yinvesti-asc2026-' . date('Y-m-d') . '.csv"');
    if (is_file($csv)) {
        readfile($csv);
    }
    exit;
}

if ($connecte && isset($_GET['fichier'])) {
    $nom = basename((string) $_GET['fichier']);
    $chemin = $base . '/fichiers/' . $nom;
    if (!preg_match('/^YINV-ASC26-\d{4}-[A-Za-z0-9._-]+$/', $nom) || !is_file($chemin)) {
        http_response_code(404);
        exit('Fichier introuvable.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($chemin));
    readfile($chemin);
    exit;
}

$dossiers = [];
if ($connecte) {
    foreach (glob($base . '/dossiers/YINV-ASC26-*.json') ?: [] as $f) {
        $j = json_decode((string) file_get_contents($f), true);
        if (is_array($j)) {
            $dossiers[$j['dossier']] = $j;
        }
    }
    krsort($dossiers);
}
$fiche = $connecte && isset($_GET['dossier']) ? ($dossiers[(string) $_GET['dossier']] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Candidatures ASC 2026 — Yinvesti</title>
<style>
:root{--navy:#091C3F;--blue:#16479D;--green:#4EB648;--sky:#EFF5FF;--line:#dbe4f3;--mut:#5a6785}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:var(--sky);color:var(--navy)}
header{background:var(--navy);color:#fff;padding:14px 20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
header img{height:26px}
header b{flex:1}
header a{color:#cfe0ff;text-decoration:none;font-size:14px}
main{max-width:1200px;margin:0 auto;padding:20px}
.carte{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px}
.btn{display:inline-block;background:var(--blue);color:#fff;border:0;border-radius:10px;padding:10px 16px;font-weight:600;text-decoration:none;cursor:pointer;font-size:14px}
.btn.vert{background:var(--green)}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:start;padding:10px 8px;border-bottom:1px solid var(--line);vertical-align:top}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut)}
tr:hover td{background:#f7faff}
.tag{display:inline-block;border-radius:99px;padding:2px 9px;font-size:12px;font-weight:600;background:var(--sky);color:var(--blue)}
.tag.vert{background:#e6f6e4;color:#2c7d28}
dl{display:grid;grid-template-columns:220px 1fr;gap:8px 16px;margin:0}
dt{color:var(--mut);font-size:14px}
dd{margin:0;word-break:break-word}
input[type=password]{width:100%;padding:12px;border:1px solid var(--line);border-radius:10px;font-size:15px;margin:10px 0}
.err{color:#b42318;font-size:14px}
.vide{color:var(--mut);text-align:center;padding:30px}
@media(max-width:720px){dl{grid-template-columns:1fr}.cache-mobile{display:none}}
</style>
</head>
<body>
<header>
  <img src="assets/yinvesti-blanc.png" alt="Yinvesti">
  <b>Candidatures · African Startup Conference 2026</b>
  <?php if ($connecte): ?>
    <a href="candidatures.php">Liste</a>
    <a href="candidatures.php?export=csv">Exporter (Excel)</a>
    <a href="candidatures.php?deconnexion=1">Déconnexion</a>
  <?php endif; ?>
</header>
<main>
<?php if (!$connecte): ?>
  <form class="carte" method="post" style="max-width:380px;margin:40px auto">
    <b>Accès réservé</b>
    <p style="color:var(--mut);font-size:14px">Même mot de passe que l'admin de dehini.com.</p>
    <input type="password" name="password" autocomplete="current-password" autofocus required>
    <?php if ($erreur): ?><p class="err"><?= yinv_e($erreur) ?></p><?php endif; ?>
    <button class="btn">Se connecter</button>
  </form>
<?php elseif ($fiche): ?>
  <p><a href="candidatures.php">← Toutes les candidatures</a></p>
  <div class="carte">
    <h2 style="margin-top:0"><?= yinv_e($fiche['nom_projet'] ?? '') ?> <span class="tag"><?= yinv_e($fiche['dossier']) ?></span></h2>
    <dl>
      <?php foreach (yinv_colonnes() as $k => $titre): ?>
        <?php if (($fiche[$k] ?? '') === '') continue; ?>
        <dt><?= yinv_e($titre) ?></dt>
        <dd>
          <?php if ($k === 'fichier'): ?>
            <a class="btn vert" href="candidatures.php?fichier=<?= rawurlencode($fiche[$k]) ?>">Télécharger <?= yinv_e($fiche[$k]) ?></a>
          <?php elseif ($k === 'email'): ?>
            <a href="mailto:<?= yinv_e($fiche[$k]) ?>"><?= yinv_e($fiche[$k]) ?></a>
          <?php elseif ($k === 'telephone'): ?>
            <a href="tel:<?= yinv_e(preg_replace('/[^\d+]/', '', $fiche[$k])) ?>"><?= yinv_e($fiche[$k]) ?></a>
          <?php else: ?>
            <?= nl2br(yinv_e($fiche[$k])) ?>
          <?php endif; ?>
        </dd>
      <?php endforeach; ?>
    </dl>
  </div>
<?php else: ?>
  <div class="carte" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <b style="font-size:22px"><?= count($dossiers) ?></b> <span>candidature(s) reçue(s)</span>
    <span style="flex:1"></span>
    <a class="btn" href="candidatures.php?export=csv">Exporter en CSV (Excel)</a>
  </div>
  <div class="carte" style="overflow-x:auto">
    <?php if (!$dossiers): ?>
      <p class="vide">Aucune candidature pour l'instant.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Dossier</th><th>Projet</th><th>Porteur</th><th class="cache-mobile">Stade</th><th class="cache-mobile">ASC</th><th class="cache-mobile">Reçu le</th></tr></thead>
      <tbody>
      <?php foreach ($dossiers as $c): ?>
        <tr>
          <td><a href="candidatures.php?dossier=<?= rawurlencode($c['dossier']) ?>"><?= yinv_e($c['dossier']) ?></a></td>
          <td><b><?= yinv_e($c['nom_projet'] ?? '') ?></b><br><span style="color:var(--mut);font-size:13px"><?= yinv_e($c['secteur'] ?? '') ?></span></td>
          <td><?= yinv_e($c['nom_complet'] ?? '') ?><br><span style="color:var(--mut);font-size:13px"><?= yinv_e($c['telephone'] ?? '') ?> · <?= yinv_e($c['wilaya'] ?? '') ?></span></td>
          <td class="cache-mobile"><span class="tag <?= ($c['stade_projet'] ?? '') === 'Projet en cours de création' ? '' : 'vert' ?>"><?= yinv_e($c['stade_projet'] ?? '') ?></span></td>
          <td class="cache-mobile"><?= yinv_e($c['asc_statut'] ?? '') ?><br><span style="color:var(--mut);font-size:13px"><?= yinv_e($c['asc_rdv'] ?? '') ?></span></td>
          <td class="cache-mobile"><?= yinv_e($c['recu_le'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
<?php endif; ?>
</main>
</body>
</html>
