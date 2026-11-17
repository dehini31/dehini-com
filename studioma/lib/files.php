<?php
/**
 * Fichiers envoyés : images (ré-encodées en WebP, ce qui retire les métadonnées dont la position
 * GPS), vidéos et pièces jointes. Tout est rangé hors de public_html et servi par l'API, qui
 * vérifie l'accès : une pièce jointe de Studio n'est lisible que par les membres de ce Studio.
 */
declare(strict_types=1);

const IMAGE_MAX_BYTES = 12 * 1024 * 1024;
const IMAGE_WIDTH = 1600;
const IMAGE_SMALL = 480;
/** Extensions de pièces jointes acceptées (toujours servies en téléchargement). */
const DOC_EXTS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'md', 'zip', 'psd', 'ai', 'eps', 'srt', 'vtt', 'mp3', 'wav', 'm4a'];
const VIDEO_MIMES = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-m4v' => 'mp4'];

/** Taille maximale réellement acceptée, en Mo : le réglage de Studioma, borné par ceux de PHP. */
function upload_limit_mb(): int
{
    $toMb = function (string $v): float {
        $v = trim($v);
        if ($v === '' || (float) $v <= 0) return INF;   // 0 ou vide : pas de limite côté PHP
        $n = (float) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g': return $n * 1024;
            case 'm': return $n;
            case 'k': return $n / 1024;
        }
        return $n / 1048576;
    };
    // Le formulaire ajoute quelques octets au fichier : on garde une marge sous post_max_size.
    $limit = min((float) STM_MAX_UPLOAD_MB, $toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size')) - 1);
    return max(1, (int) floor($limit));
}
/** Type du contenu d'un fichier ('' si le serveur ne sait pas le dire). */
function file_mime(string $path): string
{
    if (class_exists('finfo')) return (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    return function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
}

function files_dir(): string
{
    $d = STM_DATA_DIR . '/files';
    if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) throw new RuntimeException('Dossier des fichiers impossible à créer');
    return $d;
}
function file_path(array $f, bool $small = false): string
{
    $sub = files_dir() . '/' . substr($f['token'], 0, 2);
    if (!is_dir($sub)) @mkdir($sub, 0750, true);
    return $sub . '/' . $f['token'] . ($small ? '-s' : '') . '.' . $f['ext'];
}
function file_by_token(string $token): ?array
{
    return preg_match('/^[a-f0-9]{32}$/', $token) ? row('SELECT * FROM files WHERE token = ?', [$token]) : null;
}
function file_view(?array $f): ?array
{
    if (!$f) return null;
    return [
        'token' => (string) $f['token'], 'kind' => (string) $f['kind'], 'name' => (string) $f['name'],
        'size' => (int) $f['size'], 'w' => (int) $f['w'], 'h' => (int) $f['h'],
    ];
}
/** Fiches de fichiers à partir de leurs jetons, dans l'ordre demandé (les inconnus sont ignorés). */
function files_view(array $tokens): array
{
    $tokens = array_values(array_filter($tokens, function ($t): bool { return is_string($t) && (bool) preg_match('/^[a-f0-9]{32}$/', $t); }));
    if (!$tokens) return [];
    $map = [];
    foreach (rows('SELECT * FROM files WHERE token IN (' . marks($tokens) . ')', $tokens) as $f) $map[$f['token']] = file_view($f);
    $out = [];
    foreach ($tokens as $t) {
        if (isset($map[$t])) $out[] = $map[$t];
    }
    return $out;
}
/**
 * Jetons de fichiers publics appartenant au compte (médias d'une publication, d'une étude de cas…).
 * $kinds : types acceptés.
 */
function own_media($tokens, int $uid, int $max, array $kinds = ['image', 'video']): array
{
    if (!is_array($tokens)) return [];
    $out = [];
    foreach ($tokens as $t) {
        $f = is_string($t) ? file_by_token($t) : null;
        if ($f && (int) $f['uid'] === $uid && $f['scope'] !== 'studio' && in_array($f['kind'], $kinds, true) && !in_array($f['token'], $out, true)) $out[] = $f['token'];
        if (count($out) >= $max) break;
    }
    return $out;
}
/** Une image de profil ou de couverture : jeton d'une image publique du compte, ou ''. */
function own_image($token, int $uid): string
{
    $m = own_media([$token], $uid, 1, ['image']);
    return $m[0] ?? '';
}

/** Ouvre une image envoyée, orientation des photos de téléphone corrigée. */
function image_open(string $tmp, int $type)
{
    $fn = ['', 'imagecreatefromgif', 'imagecreatefromjpeg', 'imagecreatefrompng'][$type] ?? ($type === IMAGETYPE_WEBP ? 'imagecreatefromwebp' : '');
    @ini_set('memory_limit', '512M');
    $src = $fn !== '' && function_exists($fn) ? @$fn($tmp) : false;
    if (!$src) throw new InvalidArgumentException('This image could not be read. Try another one.');
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
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
function image_save($src, int $maxW, string $dest): array
{
    $w = imagesx($src);
    $h = imagesy($src);
    $nw = min($maxW, $w);
    $nh = max(1, (int) round($h * $nw / $w));
    $dst = imagecreatetruecolor($nw, $nh);
    // La transparence des PNG est gardée.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!imagewebp($dst, $dest, 84)) throw new RuntimeException('Écriture de l\'image impossible');
    imagedestroy($dst);
    return [$nw, $nh];
}

/**
 * Enregistre un fichier envoyé ($_FILES['file']). $accept : types acceptés parmi image, video, doc.
 * Le type est vérifié sur le contenu réel, jamais sur le nom. Retourne la ligne de la table files.
 */
function file_ingest(array $up, int $uid, string $scope, int $studioId, array $accept): array
{
    if (($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($up['tmp_name'] ?? ''))) {
        $tooBig = in_array($up['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        throw new InvalidArgumentException($tooBig ? 'This file is too large (' . upload_limit_mb() . ' MB maximum).' : 'The upload failed. Please try again.');
    }
    $tmp = (string) $up['tmp_name'];
    $size = (int) filesize($tmp);
    $name = line_clean(basename((string) ($up['name'] ?? 'file')), 120);
    if ($size <= 0) throw new InvalidArgumentException('This file is empty.');
    if ($size > upload_limit_mb() * 1048576) throw new InvalidArgumentException('This file is too large (' . upload_limit_mb() . ' MB maximum).');
    $mime = file_mime($tmp);
    $extIn = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $f = ['token' => token(16), 'uid' => $uid, 'scope' => $scope, 'studio_id' => $studioId, 'name' => $name, 'size' => $size, 'w' => 0, 'h' => 0, 'created' => time()];

    $info = @getimagesize($tmp);
    $imgTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if ($info && in_array($info[2], $imgTypes, true)) {
        if (!in_array('image', $accept, true)) throw new InvalidArgumentException('Images are not accepted here.');
        if ($size > IMAGE_MAX_BYTES) throw new InvalidArgumentException('This image is too large (12 MB maximum).');
        if ($info[0] * $info[1] > 40000000) throw new InvalidArgumentException('This image is too large (40 megapixels maximum).');
        $f['kind'] = 'image';
        $gd = function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
        if ($gd && $info[2] !== IMAGETYPE_GIF) {
            $src = image_open($tmp, $info[2]);
            $f['mime'] = 'image/webp';
            $f['ext'] = 'webp';
            [$f['w'], $f['h']] = image_save($src, IMAGE_WIDTH, file_path($f));
            image_save($src, IMAGE_SMALL, file_path($f, true));
            imagedestroy($src);
            $f['size'] = (int) filesize(file_path($f));
        } else {
            // GIF animé (ou serveur sans GD) : l'original est gardé tel quel.
            $f['mime'] = image_type_to_mime_type($info[2]);
            $f['ext'] = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2]];
            [$f['w'], $f['h']] = [$info[0], $info[1]];
            if (!move_uploaded_file($tmp, file_path($f))) throw new RuntimeException('Enregistrement du fichier impossible');
            copy(file_path($f), file_path($f, true));
        }
    } elseif (isset(VIDEO_MIMES[$mime])) {
        if (!in_array('video', $accept, true)) throw new InvalidArgumentException('Videos are not accepted here.');
        $f['kind'] = 'video';
        $f['mime'] = $mime === 'video/x-m4v' ? 'video/mp4' : $mime;
        $f['ext'] = VIDEO_MIMES[$mime];
        if (!move_uploaded_file($tmp, file_path($f))) throw new RuntimeException('Enregistrement du fichier impossible');
    } elseif (in_array('doc', $accept, true) && in_array($extIn, DOC_EXTS, true) && strpos($mime, 'php') === false) {
        $f['kind'] = 'doc';
        $f['mime'] = $extIn === 'pdf' && $mime === 'application/pdf' ? 'application/pdf' : 'application/octet-stream';
        $f['ext'] = 'bin';
        if (!move_uploaded_file($tmp, file_path($f))) throw new RuntimeException('Enregistrement du fichier impossible');
    } else {
        $what = in_array('doc', $accept, true) ? 'images, videos (MP4, WebM, MOV) and documents (PDF, Office, ZIP…)' : (in_array('video', $accept, true) ? 'images and videos (MP4, WebM, MOV)' : 'images (JPG, PNG, WebP, GIF)');
        throw new InvalidArgumentException('This file type is not accepted. You can upload ' . $what . '.');
    }
    $f['id'] = insert('files', $f);
    return $f;
}

function file_delete(array $f): void
{
    foreach ([false, true] as $small) {
        $p = file_path($f, $small);
        if (is_file($p)) @unlink($p);
    }
    q('DELETE FROM files WHERE id = ?', [$f['id']]);
}
/** Supprime les fichiers d'un compte qui ne sont plus utilisés nulle part ($tokens : ceux à vérifier). */
function files_release(array $tokens): void
{
    foreach ($tokens as $t) {
        $f = is_string($t) ? file_by_token($t) : null;
        if ($f) file_delete($f);
    }
}
/** Copie d'un fichier (ex. une pièce jointe de Studio reprise dans une étude de cas). */
function file_copy(array $f, int $uid, string $scope): array
{
    $n = ['token' => token(16), 'uid' => $uid, 'scope' => $scope, 'studio_id' => 0, 'kind' => $f['kind'], 'name' => $f['name'],
        'mime' => $f['mime'], 'ext' => $f['ext'], 'size' => $f['size'], 'w' => $f['w'], 'h' => $f['h'], 'created' => time()];
    foreach ([false, true] as $small) {
        if (is_file(file_path($f, $small))) copy(file_path($f, $small), file_path($n, $small));
    }
    $n['id'] = insert('files', $n);
    return $n;
}

/** Le compte (ou le visiteur) peut-il lire ce fichier ? */
function file_readable(array $f, ?array $u): bool
{
    if ($f['scope'] === 'public') return true;
    if (!$u) return false;
    if ((int) $f['uid'] === (int) $u['id']) return true;
    if ($f['scope'] !== 'studio') return false;
    $s = row('SELECT * FROM studios WHERE id = ?', [$f['studio_id']]);
    $p = $s ? row('SELECT * FROM projects WHERE id = ?', [$s['project_id']]) : null;
    return $s && $p && studio_role((int) $u['id'], $s, $p) !== '';
}

/** Envoie le fichier au navigateur (lecture partielle acceptée : indispensable aux vidéos). */
function file_serve(array $f, bool $small, bool $download): void
{
    $path = file_path($f, $small && $f['kind'] === 'image');
    if (!is_file($path)) $path = file_path($f);
    if (!is_file($path)) { http_response_code(404); exit; }
    $size = (int) filesize($path);
    $inline = in_array($f['kind'], ['image', 'video'], true) && !$download;
    header_remove('Content-Type');
    header('Content-Type: ' . $f['mime']);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: ' . ($f['scope'] === 'public' ? 'public, max-age=31536000, immutable' : 'private, max-age=3600'));
    header('Accept-Ranges: bytes');
    $safe = preg_replace('/[^\w.\- ]+/u', '_', $f['name']) ?: 'file';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $safe) . '"');
    $start = 0;
    $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', (string) $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int) $m[2]); }
        else { $start = (int) $m[1]; if ($m[2] !== '') $end = min($end, (int) $m[2]); }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    while (ob_get_level()) ob_end_clean();
    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fp)) {
        $chunk = fread($fp, (int) min(262144, $left));
        if ($chunk === false) break;
        echo $chunk;
        $left -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}
