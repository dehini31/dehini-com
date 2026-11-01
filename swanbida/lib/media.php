<?php
/**
 * Photos et vidéos envoyées depuis l'admin. Chaque photo est ré-encodée (ce qui retire les
 * métadonnées, dont la position GPS) en WebP — et en AVIF si le serveur le permet — dans trois
 * largeurs (480, 960, 1600 px) pour un chargement rapide sur mobile.
 */
declare(strict_types=1);

const IMG_WIDTHS = [480, 960, 1600];
const MEDIA_DIR = 'media';

function media_path(string $sub = ''): string
{
    $d = SWB_ROOT . '/' . MEDIA_DIR . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) throw new RuntimeException('Dossier media/ impossible à créer');
    return $d;
}

/** Traite une photo envoyée ; retourne l'objet image enregistré dans les contenus. */
function image_ingest(string $tmp): array
{
    if (!function_exists('imagecreatefromjpeg')) throw new RuntimeException('Extension GD absente sur le serveur');
    $info = @getimagesize($tmp);
    $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (defined('IMAGETYPE_AVIF')) $types[IMAGETYPE_AVIF] = 'avif';
    if (!$info || !isset($types[$info[2]])) throw new InvalidArgumentException('Format non accepté : JPG, PNG, WebP ou AVIF uniquement (les photos HEIC d\'iPhone doivent être converties en JPG).');
    [$w, $h] = $info;
    if ($w < 200 || $h < 200) throw new InvalidArgumentException('Photo trop petite (200 px minimum).');
    if ($w * $h > 40000000) throw new InvalidArgumentException('Photo trop grande (40 mégapixels maximum).');
    $fn = 'imagecreatefrom' . $types[$info[2]];
    if (!function_exists($fn)) throw new RuntimeException('Format non pris en charge par ce serveur.');
    @ini_set('memory_limit', '512M');
    $src = @$fn($tmp);
    if (!$src) throw new InvalidArgumentException('Photo illisible.');
    // Orientation des photos de téléphone (EXIF).
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $o = (int) ($exif['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) {
            $r = imagerotate($src, $rot, 0);
            if ($r) { imagedestroy($src); $src = $r; }
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $id = bin2hex(random_bytes(8));
    $dir = media_path('img');
    $avif = function_exists('imageavif');
    $widths = [];
    foreach (IMG_WIDTHS as $tw) {
        $nw = min($tw, $w);
        $nh = (int) round($h * $nw / $w);
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (!imagewebp($dst, "$dir/$id-$tw.webp", 82)) throw new RuntimeException('Écriture WebP impossible');
        if ($avif && !@imageavif($dst, "$dir/$id-$tw.avif", 52, 6)) $avif = false;
        imagedestroy($dst);
        $widths[] = $tw;
    }
    // Version JPEG pour les aperçus de partage (Facebook, WhatsApp) qui ne lisent pas toujours le WebP.
    $ow = min(1200, $w);
    $oh = (int) round($h * $ow / $w);
    $og = imagecreatetruecolor($ow, $oh);
    imagefill($og, 0, 0, imagecolorallocate($og, 250, 247, 241));
    imagecopyresampled($og, $src, 0, 0, 0, 0, $ow, $oh, $w, $h);
    imagejpeg($og, "$dir/$id-og.jpg", 84);
    imagedestroy($og);
    imagedestroy($src);
    return ['src' => MEDIA_DIR . '/img/' . $id, 'w' => min($w, 1600), 'h' => (int) round($h * min($w, 1600) / $w), 'widths' => $widths, 'avif' => $avif, 'alt' => ['fr' => '', 'ar' => '']];
}

/** Vidéo MP4 / WebM (60 Mo max). */
function video_ingest(string $tmp, int $size): array
{
    if ($size > 60 * 1024 * 1024) throw new InvalidArgumentException('Vidéo trop lourde (60 Mo maximum).');
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($tmp) : '';
    $head = (string) @file_get_contents($tmp, false, null, 0, 12);
    $ext = '';
    if ($mime === 'video/mp4' || $mime === 'video/quicktime' || substr($head, 4, 4) === 'ftyp') $ext = 'mp4';
    elseif ($mime === 'video/webm' || substr($head, 0, 4) === "\x1A\x45\xDF\xA3") $ext = 'webm';
    if ($ext === '') throw new InvalidArgumentException('Format vidéo non accepté : MP4 ou WebM uniquement.');
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = media_path('video') . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest) && !@rename($tmp, $dest)) throw new RuntimeException('Enregistrement de la vidéo impossible');
    @chmod($dest, 0644);
    return ['type' => 'file', 'src' => MEDIA_DIR . '/video/' . $name, 'poster' => null, 'title' => ['fr' => '', 'ar' => '']];
}

/** Identifiant d'une vidéo YouTube à partir de son lien. */
function youtube_id(string $url): string
{
    if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/))([A-Za-z0-9_-]{11})~', $url, $m)) return $m[1];
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $url) ? $url : '';
}

/** Supprime les fichiers d'une image importée (jamais ceux livrés avec le site). */
function image_delete_files(array $img): void
{
    $src = (string) ($img['src'] ?? '');
    if (!preg_match('~^media/img/[a-f0-9]{16}$~', $src)) return;
    foreach (IMG_WIDTHS as $w) {
        foreach (['webp', 'avif'] as $x) @unlink(SWB_ROOT . "/$src-$w.$x");
    }
    @unlink(SWB_ROOT . "/$src-og.jpg");
}

/** Nettoie un objet image reçu de l'admin (chemin limité aux dossiers autorisés). */
function clean_image($img): ?array
{
    if (!is_array($img)) return null;
    $src = (string) ($img['src'] ?? '');
    if (!preg_match('~^(media/img/[a-f0-9]{16}|assets/img/[a-z0-9-]{2,60})$~', $src)) return null;
    $widths = array_values(array_filter(array_map('intval', (array) ($img['widths'] ?? IMG_WIDTHS)), fn($w) => in_array($w, IMG_WIDTHS, true)));
    return [
        'src' => $src, 'w' => max(1, (int) ($img['w'] ?? 1600)), 'h' => max(1, (int) ($img['h'] ?? 1600)),
        'widths' => $widths ?: IMG_WIDTHS, 'avif' => !empty($img['avif']),
        'alt' => ['fr' => one_line($img['alt']['fr'] ?? '', 200), 'ar' => one_line($img['alt']['ar'] ?? '', 200)],
    ];
}

function clean_video($v): ?array
{
    if (!is_array($v)) return null;
    $title = ['fr' => one_line($v['title']['fr'] ?? '', 120), 'ar' => one_line($v['title']['ar'] ?? '', 120)];
    if (($v['type'] ?? '') === 'youtube') {
        $id = youtube_id((string) ($v['src'] ?? ''));
        return $id === '' ? null : ['type' => 'youtube', 'src' => $id, 'poster' => null, 'title' => $title];
    }
    $src = (string) ($v['src'] ?? '');
    if (!preg_match('~^media/video/[a-f0-9]{16}\.(mp4|webm)$~', $src)) return null;
    return ['type' => 'file', 'src' => $src, 'poster' => clean_image($v['poster'] ?? null), 'title' => $title];
}
