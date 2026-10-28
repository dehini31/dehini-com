<?php
/**
 * Réception d'une préinscription Yinvesti × ASC 2026 (envoyée par index.html).
 *
 * Enregistre : la fiche JSON du dossier, une ligne dans candidatures.csv
 * (ouvrable dans Excel) et le document joint. Prévient l'équipe par e-mail
 * (YINV_NOTIF_EMAIL dans config.php) et envoie un accusé de réception au
 * porteur. Répond en JSON : {ok, dossier} ou {error, champs}.
 */
declare(strict_types=1);

require __DIR__ . '/_lib.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    yinv_json(['error' => 'methode'], 405);
}

// Envoi plus gros que post_max_size : PHP vide $_POST et $_FILES.
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    yinv_json(['error' => 'trop_gros'], 413);
}

// Robots : champ piège invisible rempli, ou formulaire envoyé en moins de 5 s.
if (trim((string) ($_POST['site_entreprise'] ?? '')) !== '') {
    yinv_json(['ok' => true, 'dossier' => 'YINV-ASC26-0000']);
}
$ouvert = (int) ($_POST['t0'] ?? 0);
if ($ouvert > 0 && (int) (microtime(true) * 1000) - $ouvert < 5000) {
    yinv_json(['error' => 'trop_rapide'], 429);
}

if (!yinv_limite(5, 3600)) {
    yinv_json(['error' => 'limite'], 429);
}

$choix  = yinv_choix();
$p      = fn (string $k, int $max = 200) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $max);
$d      = [];
$champs = [];

// ── Coordonnées ──────────────────────────────────────────────────────────
$d['nom_complet'] = $p('nom_complet', 120);
if (mb_strlen($d['nom_complet']) < 3) {
    $champs[] = 'nom_complet';
}

$d['telephone'] = $p('telephone', 30);
$chiffres = preg_replace('/\D+/', '', $d['telephone']);
if (!preg_match('/^0[5-7]\d{8}$/', $chiffres) && !preg_match('/^(00|\+)?\d{9,15}$/', preg_replace('/[\s.\-()]/', '', $d['telephone']))) {
    $champs[] = 'telephone';
}

$d['email'] = $p('email', 160);
if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
    $champs[] = 'email';
}

$d['wilaya'] = $p('wilaya', 60);
if (!in_array($d['wilaya'], yinv_wilayas(), true)) {
    $champs[] = 'wilaya';
}

// ── Choix fermés ─────────────────────────────────────────────────────────
$fermes = ['asc_statut', 'asc_rdv', 'stade_projet', 'secteur'];
$stade  = $p('stade_projet', 20);
if ($stade === 'entreprise') {
    array_push($fermes, 'cat1_exercice', 'cat1_besoin', 'cat1_montant', 'cat1_transformation_spa', 'cat1_plan_clients');
} elseif ($stade === 'creation') {
    array_push($fermes, 'cat2_prototype', 'cat2_avancement_clients', 'cat2_marketing_audience', 'cat2_statut_spa');
}
foreach ($fermes as $k) {
    $v = $p($k, 30);
    if (!isset($choix[$k][$v])) {
        $champs[] = $k;
        continue;
    }
    $d[$k] = $choix[$k][$v];
}

// ── Projet ───────────────────────────────────────────────────────────────
$d['nom_projet'] = $p('nom_projet', 120);
if (mb_strlen($d['nom_projet']) < 2) {
    $champs[] = 'nom_projet';
}

if ($stade === 'entreprise') {
    $d['chiffre_affaires_societe'] = $p('chiffre_affaires_societe', 120);
    if ($d['chiffre_affaires_societe'] === '') {
        $champs[] = 'chiffre_affaires_societe';
    }
} elseif ($stade === 'creation') {
    $d['traction_prospects_ca'] = $p('traction_prospects_ca', 160);
    if ($d['traction_prospects_ca'] === '') {
        $champs[] = 'traction_prospects_ca';
    }
}

$d['description_projet'] = $p('description_projet', 600);
if (mb_strlen($d['description_projet']) < 20) {
    $champs[] = 'description_projet';
}

foreach (['site_web', 'facebook', 'instagram', 'linkedin', 'tiktok'] as $k) {
    $d[$k] = $p($k, 200);
}

if (($_POST['consentement'] ?? '') !== '1') {
    $champs[] = 'consentement';
}

// ── Document joint (facultatif) ──────────────────────────────────────────
$fichier = $_FILES['document'] ?? null;
$aFichier = $fichier && ($fichier['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
$ext = '';
if ($aFichier) {
    if (in_array($fichier['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        yinv_json(['error' => 'trop_gros'], 413);
    }
    $ext = strtolower(pathinfo((string) $fichier['name'], PATHINFO_EXTENSION));
    $types = [
        'pdf'  => ['application/pdf'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream', 'application/CDFV2'],
        'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    ];
    $mime = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $fichier['tmp_name']) : '';
    if ($fichier['error'] !== UPLOAD_ERR_OK || !isset($types[$ext]) || ($mime !== '' && !in_array($mime, $types[$ext], true))
        || $fichier['size'] > 25 * 1024 * 1024 || !is_uploaded_file($fichier['tmp_name'])) {
        $champs[] = 'document';
    }
}

if ($champs) {
    yinv_json(['error' => 'champs', 'champs' => array_values(array_unique($champs))], 422);
}

// ── Enregistrement ───────────────────────────────────────────────────────
$dossier = yinv_numero();
$d = ['dossier' => $dossier, 'recu_le' => date('Y-m-d H:i:s'), 'langue' => in_array($_POST['langue'] ?? '', ['fr', 'ar', 'en'], true) ? $_POST['langue'] : 'fr'] + $d;
$d['fichier'] = '';
$d['ip'] = yinv_ip();

if ($aFichier) {
    $nomOrigine = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo((string) $fichier['name'], PATHINFO_FILENAME)) ?: 'document';
    $d['fichier'] = $dossier . '-' . mb_substr($nomOrigine, 0, 60) . '.' . $ext;
    if (!move_uploaded_file($fichier['tmp_name'], yinv_dossier('fichiers') . '/' . $d['fichier'])) {
        $d['fichier'] = '';
    }
}

file_put_contents(yinv_dossier('dossiers') . '/' . $dossier . '.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

$csv = yinv_dossier() . '/candidatures.csv';
$nouveau = !is_file($csv);
$h = fopen($csv, 'a');
flock($h, LOCK_EX);
if ($nouveau) {
    fwrite($h, "\xEF\xBB\xBF"); // BOM : accents corrects dans Excel
    fputcsv($h, array_values(yinv_colonnes()), ';', '"', '');
}
// Une cellule qui commence par = + - @ serait exécutée comme formule par Excel.
fputcsv($h, array_map(fn ($k) => preg_replace('/^([=+\-@])/', "'$1", (string) ($d[$k] ?? '')), array_keys(yinv_colonnes())), ';', '"', '');
flock($h, LOCK_UN);
fclose($h);

// ── E-mails (sans effet si la fonction mail() n'est pas disponible) ─────
$hote = preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'dehini.com'));
$hote = preg_match('/^[a-z0-9.-]+$/i', $hote) ? $hote : 'dehini.com';
$entetes = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: Yinvesti ASC 2026 <noreply@{$hote}>\r\n";
$sujet = fn (string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';

if (defined('YINV_NOTIF_EMAIL') && filter_var(YINV_NOTIF_EMAIL, FILTER_VALIDATE_EMAIL) && function_exists('mail')) {
    $lignes = [];
    foreach (yinv_colonnes() as $k => $titre) {
        if (($d[$k] ?? '') !== '') {
            $lignes[] = $titre . ' : ' . $d[$k];
        }
    }
    $lignes[] = '';
    $lignes[] = 'Toutes les candidatures : https://' . $hote . '/Yinvesti/candidatures.php';
    @mail(YINV_NOTIF_EMAIL, $sujet("Nouvelle candidature ASC 2026 — {$d['nom_projet']} ({$dossier})"),
        implode("\n", $lignes), $entetes . "Reply-To: {$d['email']}\r\n");
}

if (!defined('YINV_ACCUSE') || YINV_ACCUSE) {
    $corps = [
        'fr' => "Bonjour {$d['nom_complet']},\n\nNous avons bien reçu votre préinscription au crowdfunding Yinvesti pendant l'African Startup Conference 2026 (5 – 7 décembre, CIC Alger).\n\nNuméro de dossier : {$dossier}\nProjet : {$d['nom_projet']}\n\nNotre comité étudie votre dossier sous 5 jours ouvrés, puis nous vous contactons pour fixer votre rendez-vous.\n\nL'équipe Yinvesti",
        'ar' => "مرحباً {$d['nom_complet']}،\n\nتلقّينا طلب تسجيلك المسبق في التمويل التشاركي مع Yinvesti خلال المؤتمر الإفريقي للمؤسسات الناشئة 2026 (5 – 7 ديسمبر، المركز الدولي للمؤتمرات، الجزائر).\n\nرقم الملف: {$dossier}\nالمشروع: {$d['nom_projet']}\n\nتدرس لجنتنا ملفك خلال 5 أيام عمل، ثم نتواصل معك لتحديد موعدك.\n\nفريق Yinvesti",
        'en' => "Hello {$d['nom_complet']},\n\nWe have received your Yinvesti crowdfunding pre-registration for the African Startup Conference 2026 (5 – 7 December, CIC Algiers).\n\nApplication number: {$dossier}\nProject: {$d['nom_projet']}\n\nOur committee reviews your application within 5 business days, then we contact you to schedule your meeting.\n\nThe Yinvesti team",
    ][$d['langue']];
    if (function_exists('mail')) {
        @mail($d['email'], $sujet("Yinvesti × ASC 2026 — {$dossier}"), $corps, $entetes);
    }
}

yinv_json(['ok' => true, 'dossier' => $dossier]);
