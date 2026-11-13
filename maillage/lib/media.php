<?php
/**
 * Photos envoyées depuis l'espace entreprise (produits, logos). Chaque image est ré-encodée
 * en WebP (ce qui retire les métadonnées, dont la position GPS) dans deux largeurs, et rangée
 * dans media/c<id entreprise>/. La base garde le chemin sans suffixe : media/c12/ab12…  →
 * media/c12/ab12…-480.webp et …-1200.webp.
 */
declare(strict_types=1);

const MEDIA_WIDTHS = [480, 1200];
const MEDIA_MAX_BYTES = 8 * 1024 * 1024;

function media_dir(int $cid): string
{
    $d = MLG_ROOT . '/media/c' . $cid;
    if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) throw new RuntimeException('Dossier media/ impossible à créer');
    return $d;
}

/**
 * Ouvre une image envoyée (JPG, PNG ou WebP), orientation des photos de téléphone corrigée.
 * Lève InvalidArgumentException (message affichable) si le fichier n'est pas une image acceptée.
 */
function image_open(string $tmp, int $minSide = 120)
{
    if (!function_exists('imagecreatefromjpeg') || !function_exists('imagewebp')) throw new RuntimeException('Extension GD absente sur le serveur');
    $info = @getimagesize($tmp);
    $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) throw new InvalidArgumentException('Format non accepté : JPG, PNG ou WebP uniquement.');
    [$w, $h] = $info;
    if ($w < $minSide || $h < $minSide) throw new InvalidArgumentException('Image trop petite (' . $minSide . ' px minimum).');
    if ($w * $h > 40000000) throw new InvalidArgumentException('Image trop grande (40 mégapixels maximum).');
    $fn = 'imagecreatefrom' . $types[$info[2]];
    @ini_set('memory_limit', '512M');
    $src = @$fn($tmp);
    if (!$src) throw new InvalidArgumentException('Image illisible.');
    // Orientation des photos de téléphone (EXIF).
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($rot) {
            $r = imagerotate($src, $rot, 0);
            if ($r) { imagedestroy($src); $src = $r; }
        }
    }
    if (!imageistruecolor($src)) imagepalettetotruecolor($src);
    return $src;
}

/** Copie réduite d'une image (largeur maximale $tw), sur fond blanc (PNG détourés). */
function image_fit($src, int $tw)
{
    $w = imagesx($src);
    $h = imagesy($src);
    $nw = min($tw, $w);
    $nh = max(1, (int) round($h * $nw / $w));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/** Écrit une image en WebP dans les largeurs de MEDIA_WIDTHS : $dir/$id-480.webp, $dir/$id-1200.webp. */
function image_write($src, string $dir, string $id): void
{
    foreach (MEDIA_WIDTHS as $tw) {
        $dst = image_fit($src, $tw);
        if (!imagewebp($dst, "$dir/$id-$tw.webp", 82)) throw new RuntimeException('Écriture de l\'image impossible');
        imagedestroy($dst);
    }
}

/** Traite une photo envoyée ; retourne son chemin sans suffixe. */
function image_ingest(string $tmp, int $cid): string
{
    $src = image_open($tmp);
    $id = bin2hex(random_bytes(8));
    image_write($src, media_dir($cid), $id);
    imagedestroy($src);
    return 'media/c' . $cid . '/' . $id;
}

/** Vrai si $path est une image de cette entreprise présente sur le disque. */
function media_owned(string $path, int $cid): bool
{
    return (bool) preg_match('~^media/c' . $cid . '/[a-f0-9]{16}$~', $path) && is_file(MLG_ROOT . '/' . $path . '-480.webp');
}

function media_delete(string $path, int $cid): void
{
    if (!preg_match('~^media/c' . $cid . '/[a-f0-9]{16}$~', $path)) return;
    foreach (MEDIA_WIDTHS as $tw) @unlink(MLG_ROOT . '/' . $path . '-' . $tw . '.webp');
}

/** Supprime toutes les photos d'une entreprise (suppression du compte). */
function media_purge(int $cid): void
{
    $d = MLG_ROOT . '/media/c' . $cid;
    if (!is_dir($d)) return;
    foreach (glob($d . '/*.webp') ?: [] as $f) @unlink($f);
    @rmdir($d);
}
