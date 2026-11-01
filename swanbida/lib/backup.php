<?php
/** Sauvegardes : copie cohérente de la base (même pendant des commandes) + archive avec les médias. */
declare(strict_types=1);

function backup_dir(): string
{
    $d = SWB_DATA_DIR . '/backups';
    if (!is_dir($d) && !@mkdir($d, 0750, true) && !is_dir($d)) throw new RuntimeException('Dossier de sauvegarde impossible à créer');
    return $d;
}

/** Copie à chaud de la base SQLite vers $dest. */
function backup_snapshot(string $dest): void
{
    db(); // crée / migre la base si besoin
    @unlink($dest);
    if (class_exists('SQLite3')) {
        $src = new SQLite3(SWB_DB_PATH, SQLITE3_OPEN_READONLY);
        $src->busyTimeout(8000);
        $dst = new SQLite3($dest);
        $ok = $src->backup($dst);
        $dst->close();
        $src->close();
        if ($ok) return;
    }
    // Repli : VACUUM INTO (SQLite 3.27+), sinon copie du fichier.
    try {
        db()->exec('VACUUM INTO ' . db()->quote($dest));
        return;
    } catch (Throwable $e) {
        if (!@copy(SWB_DB_PATH, $dest)) throw new RuntimeException('Sauvegarde impossible');
    }
}

/** Sauvegarde quotidienne compressée, 30 jours conservés. Retourne le chemin créé (ou null si déjà faite). */
function backup_daily(bool $force = false): ?string
{
    $dir = backup_dir();
    $file = $dir . '/swanbida-' . date('Y-m-d') . '.sqlite.gz';
    if (!$force && is_file($file)) return null;
    $tmp = $dir . '/tmp-' . token(6) . '.sqlite';
    backup_snapshot($tmp);
    $in = fopen($tmp, 'rb');
    $out = gzopen($file, 'wb6');
    while (!feof($in)) gzwrite($out, (string) fread($in, 1 << 20));
    fclose($in);
    gzclose($out);
    @unlink($tmp);
    @chmod($file, 0600);
    $all = glob($dir . '/swanbida-*.sqlite.gz') ?: [];
    rsort($all);
    foreach (array_slice($all, 30) as $old) @unlink($old);
    meta_set('last_backup', (string) time());
    return $file;
}

function backups_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/swanbida-*.sqlite.gz') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}
