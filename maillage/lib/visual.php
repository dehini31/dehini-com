<?php
/**
 * Recherche d'un produit par photo.
 *
 * Tout se fait dans la plateforme, sans service extérieur : l'image n'est envoyée nulle part.
 *
 * 1. Identification : la photo est comparée aux photos du catalogue (signature visuelle). Si elle
 *    ressemble nettement à un produit, sa fiche sert de proposition (rayon, nom, marque) ; sinon,
 *    le rayon des produits ressemblants est proposé ; sinon le client décrit le produit. Ce n'est
 *    qu'une proposition, que le client corrige avant de chercher.
 * 2. Recherche : le catalogue est interrogé avec les mots de la description ET avec l'image.
 * 3. Correspondance exacte : photo identique à celle du catalogue, ou nom (et marque) identiques.
 *    Tout le reste est présenté comme « produits similaires », jamais comme le produit lui-même.
 * 4. Apprentissage : quand un client clôt une recherche active « trouvée » et qu'un point de vente
 *    y avait répondu avec une fiche du catalogue, la photo du client est rattachée à ce produit :
 *    les photos suivantes du même produit le retrouvent (comme proposition, jamais comme preuve).
 *
 * L'image du client n'est pas conservée par la recherche ; elle ne l'est que s'il lance une
 * recherche active (voir research.php).
 */
declare(strict_types=1);

const VIS_STOPWORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'un', 'une', 'et', 'en', 'pour', 'avec', 'sans', 'sur', 'au', 'aux', 'ou', 'the', 'and', 'for', 'with',
    'من', 'في', 'على', 'مع', 'او', 'أو', 'و'];

/* ----------------------------------------------------- signature visuelle */

/**
 * Signature d'une image : empreinte de structure (dHash 64 bits) et répartition des couleurs
 * de sa zone centrale (64 cases). Sert à retrouver une photo identique ou ressemblante.
 */
function vis_signature($im): array
{
    $w = imagesx($im);
    $h = imagesy($im);
    $t = imagecreatetruecolor(9, 8);
    imagecopyresampled($t, $im, 0, 0, 0, 0, 9, 8, $w, $h);
    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        $prev = null;
        for ($x = 0; $x < 9; $x++) {
            $c = imagecolorat($t, $x, $y);
            $g = (($c >> 16) & 255) * 299 + (($c >> 8) & 255) * 587 + ($c & 255) * 114;
            if ($prev !== null) $bits .= $prev > $g ? '1' : '0';
            $prev = $g;
        }
    }
    imagedestroy($t);
    $dhash = '';
    foreach (str_split($bits, 4) as $n) $dhash .= dechex((int) bindec($n));

    $cw = max(1, (int) ($w * 0.8));
    $ch = max(1, (int) ($h * 0.8));
    $s = imagecreatetruecolor(24, 24);
    imagecopyresampled($s, $im, 0, 0, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), 24, 24, $cw, $ch);
    $bins = array_fill(0, 64, 0);
    for ($y = 0; $y < 24; $y++) {
        for ($x = 0; $x < 24; $x++) {
            $c = imagecolorat($s, $x, $y);
            $bins[((($c >> 16) & 255) >> 6) * 16 + ((($c >> 8) & 255) >> 6) * 4 + (($c & 255) >> 6)]++;
        }
    }
    imagedestroy($s);
    $hist = '';
    foreach ($bins as $n) $hist .= sprintf('%02x', (int) round($n * 255 / 576));
    return ['dhash' => $dhash, 'hist' => $hist];
}

/** Ressemblance de deux signatures : score (0 à 1), écart de structure (bits) et couleurs communes (0 à 1). */
function vis_compare(array $a, array $b): array
{
    if (strlen($a['dhash']) !== 16 || strlen($b['dhash']) !== 16 || strlen($a['hist']) !== 128 || strlen($b['hist']) !== 128) return ['score' => 0.0, 'bits' => 64, 'colors' => 0.0];
    $bits = 0;
    for ($i = 0; $i < 16; $i++) {
        $x = hexdec($a['dhash'][$i]) ^ hexdec($b['dhash'][$i]);
        $bits += [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4][$x];
    }
    $common = 0;
    $total = 0;
    for ($i = 0; $i < 128; $i += 2) {
        $x = hexdec(substr($a['hist'], $i, 2));
        $y = hexdec(substr($b['hist'], $i, 2));
        $common += min($x, $y);
        $total += max($x, $y);
    }
    $colors = $total > 0 ? $common / $total : 0.0;
    return ['score' => round(0.6 * $colors + 0.4 * (1 - $bits / 64), 3), 'bits' => $bits, 'colors' => round($colors, 3)];
}

/** Met à jour les signatures des photos d'un produit (à l'enregistrement de sa fiche). */
function vis_index_product(int $productId, array $images): void
{
    if (!function_exists('imagecreatefromwebp')) return;
    $pdo = db();
    $known = [];
    $s = $pdo->prepare("SELECT path FROM image_sigs WHERE product_id = ? AND path LIKE 'media/c%'");
    $s->execute([$productId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $p) $known[(string) $p] = true;
    $del = $pdo->prepare('DELETE FROM image_sigs WHERE path = ?');
    foreach (array_keys($known) as $p) {
        if (!in_array($p, $images, true)) $del->execute([$p]);
    }
    $ins = $pdo->prepare('INSERT OR REPLACE INTO image_sigs (path, product_id, dhash, hist, created) VALUES (?, ?, ?, ?, ?)');
    foreach ($images as $path) {
        if (!is_string($path) || isset($known[$path]) || !preg_match('~^media/c\d+/[a-f0-9]{16}$~', $path)) continue;
        $sig = ['dhash' => '', 'hist' => ''];
        $im = @imagecreatefromwebp(MLG_ROOT . '/' . $path . '-480.webp');
        if ($im) {
            $sig = vis_signature($im);
            imagedestroy($im);
        }
        // Une photo illisible garde une ligne vide : elle n'est pas réessayée à chaque recherche.
        $ins->execute([$path, $productId, $sig['dhash'], $sig['hist'], time()]);
    }
}

/** Calcule les signatures qui manquent (produits enregistrés avant la recherche par photo), par petits lots. */
function vis_backfill(int $max = 25): void
{
    $rows = db()->query("SELECT p.id, p.images FROM products p
        WHERE p.images <> '[]' AND NOT EXISTS (SELECT 1 FROM image_sigs g WHERE g.product_id = p.id AND g.path LIKE 'media/c%') LIMIT " . max(1, $max))->fetchAll();
    foreach ($rows as $p) {
        try {
            vis_index_product((int) $p['id'], array_values(array_filter(jdec($p['images']), 'is_string')));
        } catch (Throwable $e) {
            error_log('maillage signatures: ' . $e->getMessage());
        }
    }
}

/**
 * Compare une signature à toutes les photos connues des produits en ligne. Retourne, par produit,
 * sa photo la plus ressemblante : ['score', 'bits', 'colors', 'same' => photo du catalogue identique].
 */
function vis_matches(array $sig): array
{
    $out = [];
    foreach (db()->query("SELECT g.product_id, g.path, g.dhash, g.hist FROM image_sigs g JOIN products p ON p.id = g.product_id JOIN companies c ON c.id = p.company_id
        WHERE g.dhash <> '' AND p.status = 'active' AND c.status = 'active'") as $g) {
        $cmp = vis_compare($sig, ['dhash' => (string) $g['dhash'], 'hist' => (string) $g['hist']]);
        // Seule une photo publiée par la marque peut prouver que c'est le même produit ; une photo apprise ne fait que rapprocher.
        $cmp['same'] = strncmp((string) $g['path'], 'media/c', 7) === 0 && $cmp['bits'] <= 5 && $cmp['colors'] >= 0.8;
        $pid = (int) $g['product_id'];
        if (!isset($out[$pid])) { $out[$pid] = $cmp; continue; }
        $same = $out[$pid]['same'] || $cmp['same'];
        if ($cmp['score'] > $out[$pid]['score']) $out[$pid] = $cmp;
        $out[$pid]['same'] = $same;
    }
    return $out;
}

/** Rattache la photo d'une recherche active à un produit du catalogue (voir research_close). */
function vis_learn(int $productId, string $image): void
{
    if (!function_exists('imagecreatefromwebp') || !preg_match('~^media/r/[a-f0-9]{16}$~', $image)) return;
    $im = @imagecreatefromwebp(MLG_ROOT . '/' . $image . '-480.webp');
    if (!$im) return;
    $sig = vis_signature($im);
    imagedestroy($im);
    $pdo = db();
    $pdo->prepare('INSERT OR REPLACE INTO image_sigs (path, product_id, dhash, hist, created) VALUES (?, ?, ?, ?, ?)')->execute([$image, $productId, $sig['dhash'], $sig['hist'], time()]);
    // Au plus 30 photos apprises par produit : les plus anciennes laissent la place.
    $pdo->prepare("DELETE FROM image_sigs WHERE product_id = ? AND path LIKE 'media/r/%' AND path NOT IN
        (SELECT path FROM image_sigs WHERE product_id = ? AND path LIKE 'media/r/%' ORDER BY created DESC, path LIMIT 30)")->execute([$productId, $productId]);
}

/* ------------------------------------------------------------ identification */

/** Description saisie ou proposée, nettoyée : rayon connu, textes d'une ligne, mots de recherche. */
function vis_info_clean($d): array
{
    $d = is_array($d) ? $d : [];
    $terms = [];
    foreach ((array) ($d['terms'] ?? []) as $t) {
        $t = line_clean($t, 40);
        if ($t !== '' && !in_array($t, $terms, true)) $terms[] = $t;
    }
    $conf = (string) ($d['confidence'] ?? '');
    return [
        'category' => isset(MLG_CATEGORIES[$d['category'] ?? '']) ? (string) $d['category'] : '',
        'name' => line_clean($d['name'] ?? '', 120),
        'brand' => line_clean($d['brand'] ?? '', 60),
        'details' => str_clean($d['details'] ?? '', 600),
        'terms' => array_slice($terms, 0, 12),
        'confidence' => in_array($conf, ['high', 'medium', 'low'], true) ? $conf : '',
    ];
}

/**
 * Propose une description du produit photographié, d'après les photos ressemblantes du catalogue.
 * Retourne ['auto' => une proposition est faite, 'info' => description]. « confidence » : high
 * (photo identique à celle d'une fiche), medium (photo très ressemblante), low (rayon seulement).
 * Rien de ce qui est proposé n'est tenu pour vérifié.
 */
function visual_identify($im): array
{
    try { vis_backfill(); } catch (Throwable $e) { error_log('maillage signatures: ' . $e->getMessage()); }
    $matches = vis_matches(vis_signature($im));
    uasort($matches, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $near = array_slice(array_filter($matches, function ($m) { return $m['score'] >= 0.62; }), 0, 8, true);
    if (!$near) return ['auto' => false, 'info' => vis_info_clean([])];
    $rows = [];
    foreach (db()->query('SELECT p.id, p.name, p.category, b.name AS b_name FROM products p JOIN brands b ON b.id = p.brand_id WHERE p.id IN (' . implode(',', array_keys($near)) . ')') as $p) $rows[(int) $p['id']] = $p;
    $best = (int) array_key_first($near);
    $second = array_values($near)[1]['score'] ?? 0.0;
    // Un produit se détache nettement : sa fiche sert de proposition.
    if (isset($rows[$best]) && ($near[$best]['same'] || ($near[$best]['score'] >= 0.8 && $near[$best]['score'] - $second >= 0.04))) {
        $p = $rows[$best];
        return ['auto' => true, 'info' => vis_info_clean(['category' => $p['category'], 'name' => $p['name'], 'brand' => $p['b_name'], 'confidence' => $near[$best]['same'] ? 'high' : 'medium'])];
    }
    // Sinon, le rayon sur lequel s'accordent les produits ressemblants.
    $votes = [];
    foreach ($near as $pid => $m) {
        if (isset($rows[$pid])) $votes[(string) $rows[$pid]['category']] = ($votes[(string) $rows[$pid]['category']] ?? 0) + $m['score'];
    }
    arsort($votes);
    $cat = (string) array_key_first($votes);
    if ($votes && reset($votes) >= 0.6 * array_sum($votes) && reset($votes) >= 1.3) {
        return ['auto' => true, 'info' => vis_info_clean(['category' => $cat, 'confidence' => 'low'])];
    }
    return ['auto' => false, 'info' => vis_info_clean([])];
}

/* ------------------------------------------------------------------ recherche */

/** Mots utiles d'un texte (sans accents, sans mots vides, 2 caractères au moins ; pluriels en -s / -x ramenés au singulier). */
function vis_tokens(string $s): array
{
    $out = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', norm($s)) ?: [] as $t) {
        if (mb_strlen($t) < 2 || in_array($t, VIS_STOPWORDS, true)) continue;
        if (mb_strlen($t) > 3 && preg_match('/^[a-z]+[sx]$/', $t)) $t = substr($t, 0, -1);
        $out[$t] = true;
    }
    return array_keys($out);
}

/**
 * Cherche le produit de la photo dans le catalogue public.
 * $q : description (category, name, brand, details, terms). Retourne ['exact' => fiche ou null,
 * 'evidence' => pourquoi c'est le même produit, 'similar' => produits proches].
 */
function visual_search($im, array $q, ?float $lat, ?float $lng): array
{
    $pdo = db();
    $q = vis_info_clean($q);
    $sig = vis_signature($im);
    try { vis_backfill(); } catch (Throwable $e) { error_log('maillage signatures: ' . $e->getMessage()); }

    $nameTok = vis_tokens($q['name']);
    $brandTok = vis_tokens($q['brand']);
    $otherTok = array_values(array_diff(vis_tokens($q['details'] . ' ' . implode(' ', $q['terms'])), $nameTok, $brandTok));
    $all = array_slice(array_merge($nameTok, $brandTok, $otherTok), 0, 16);

    // Candidats par le texte : tout produit en ligne qui contient au moins un des mots.
    $rows = [];
    if ($all) {
        $where = [];
        $args = [];
        foreach ($all as $t) { $where[] = "p.search LIKE ? ESCAPE '\\'"; $args[] = '%' . like_escape($t) . '%'; }
        $s = $pdo->prepare("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo, c.listing AS c_listing
            FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id
            WHERE p.status = 'active' AND c.status = 'active' AND (" . implode(' OR ', $where) . ') LIMIT 400');
        $s->execute($args);
        foreach ($s as $p) $rows[(int) $p['id']] = $p;
    }
    // Candidats par l'image : la photo du catalogue la plus ressemblante de chaque produit.
    $visual = vis_matches($sig);
    $need = [];
    foreach ($visual as $pid => $cmp) {
        if ($cmp['score'] >= 0.55 && !isset($rows[$pid])) $need[] = $pid;
    }
    if ($need) {
        $in = implode(',', array_slice($need, 0, 200));
        foreach ($pdo->query("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo, c.listing AS c_listing
            FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id WHERE p.id IN ($in)") as $p) $rows[(int) $p['id']] = $p;
    }

    $cands = [];
    foreach ($rows as $pid => $p) {
        $hay = ' ' . (string) $p['search'] . ' ';
        $pname = vis_tokens((string) $p['name']);
        $hit = function (array $toks) use ($hay): int {
            $n = 0;
            foreach ($toks as $t) { if (strpos($hay, $t) !== false) $n++; }
            return $n;
        };
        // Le nom du produit pèse plus que le reste de sa fiche : un mot du rayon ne fait pas un « nom proche ».
        $nameCov = $nameTok ? count(array_intersect($nameTok, $pname)) / count($nameTok) : 0.0;
        $textCov = $nameTok ? $hit($nameTok) / count($nameTok) : 0.0;
        $backCov = $pname && $all ? count(array_intersect($pname, $all)) / count($pname) : 0.0;
        $otherCov = $otherTok ? $hit($otherTok) / count($otherTok) : 0.0;
        $brandHit = $brandTok && count(array_intersect($brandTok, vis_tokens((string) $p['b_name']))) === count($brandTok);
        $sameCat = $q['category'] !== '' && $p['category'] === $q['category'];
        $text = 0.45 * $nameCov + 0.15 * $textCov + 0.25 * $backCov + 0.15 * $otherCov;
        if ($nameCov > 0 || $backCov > 0 || $otherCov >= 0.5) $text = min(1.0, $text + ($brandHit ? 0.15 : 0) + ($sameCat ? 0.08 : 0));
        $vis = $visual[$pid] ?? null;
        $vscore = $vis ? $vis['score'] : 0.0;
        $score = $vis ? max(0.65 * $text + 0.35 * $vscore, $vscore >= 0.75 ? $vscore : 0) : 0.85 * $text;
        $why = [];
        if ($vis && $vscore >= 0.6) $why[] = 'photo';
        if ($nameCov >= 0.5 || ($backCov >= 0.5 && $nameCov > 0)) $why[] = 'name';
        if ($brandHit) $why[] = 'brand';
        if ($sameCat) $why[] = 'category';
        // Même produit sans ambiguïté : photo identique à celle du catalogue, ou nom (et marque) identiques.
        $evidence = [];
        if ($vis && $vis['same']) $evidence[] = 'photo';
        if ($nameTok && $pname && !array_diff($nameTok, $pname) && !array_diff($pname, $nameTok) && (!$brandTok || $brandHit)) $evidence[] = 'name';
        if ($score < 0.28 && !$evidence) continue;
        $cands[] = ['p' => $p, 'score' => round($score, 3), 'why' => $why, 'evidence' => $evidence, 'visual' => $vscore];
    }
    usort($cands, function ($a, $b) { return count($b['evidence']) <=> count($a['evidence']) ?: $b['score'] <=> $a['score'] ?: $a['p']['id'] <=> $b['p']['id']; });
    $cands = array_slice($cands, 0, 12);

    $exact = null;
    $evidence = [];
    if ($cands && $cands[0]['evidence']) {
        $exact = $cands[0];
        $evidence = $exact['evidence'];
    }
    $ids = array_map(function ($c) { return (int) $c['p']['id']; }, $cands);
    $variants = variants_of($ids);
    $counts = product_store_counts();
    $card = function (array $c) use ($variants, $counts): array {
        $pid = (int) $c['p']['id'];
        return product_card($c['p'], $variants[$pid] ?? [], $counts[$pid] ?? 0) + ['why' => $c['why'], 'score' => $c['score']];
    };
    $similar = [];
    foreach ($cands as $c) {
        if ($exact && (int) $c['p']['id'] === (int) $exact['p']['id']) continue;
        $similar[] = $card($c);
    }
    // Produit trouvé : les alternatives sont complétées par le même rayon (la marque d'abord).
    if ($exact && count($similar) < 6) {
        $have = array_merge([(int) $exact['p']['id']], array_map(function ($v) { return (int) $v['id']; }, $similar));
        $s = $pdo->prepare("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo, c.listing AS c_listing
            FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id
            WHERE p.status = 'active' AND c.status = 'active' AND p.category = ? AND c.demo = ? AND p.id NOT IN (" . implode(',', $have) . ')
            ORDER BY (p.brand_id = ?) DESC, p.updated DESC LIMIT ' . (6 - count($similar)));
        $s->execute([(string) $exact['p']['category'], (int) $exact['p']['c_demo'], (int) $exact['p']['brand_id']]);
        $more = $s->fetchAll();
        $mv = variants_of(array_column($more, 'id'));
        foreach ($more as $p) {
            $similar[] = product_card($p, $mv[(int) $p['id']] ?? [], $counts[(int) $p['id']] ?? 0)
                + ['why' => array_merge((int) $p['brand_id'] === (int) $exact['p']['brand_id'] ? ['brand'] : [], ['category']), 'score' => 0.0];
        }
    }
    $companies = [];
    foreach ($cands as $c) $companies[(int) $c['p']['company_id']] = true;
    $out = ['exact' => null, 'evidence' => $evidence, 'similar' => array_slice($similar, 0, 8), 'info' => $q, 'companies' => array_keys($companies)];
    if ($exact) {
        $full = public_product((string) $exact['p']['slug']);
        if ($full) {
            $stores = availability((int) $exact['p']['id'], '', '', $lat, $lng);
            $s = $pdo->prepare('SELECT b.tagline, b.about, c.name AS c_name, c.city AS c_city, c.wilaya AS c_wilaya FROM brands b JOIN companies c ON c.id = b.company_id WHERE b.id = ?');
            $s->execute([(int) $exact['p']['brand_id']]);
            $br = $s->fetch() ?: [];
            $full['brand'] += ['tagline' => (string) ($br['tagline'] ?? ''), 'about' => (string) ($br['about'] ?? ''), 'company' => (string) ($br['c_name'] ?? ''),
                'city' => (string) ($br['c_city'] ?? ''), 'wilaya' => (string) ($br['c_wilaya'] ?? '')];
            $full['near'] = array_slice($stores, 0, 4);
            $full['near_total'] = count($stores);
            // Fiche publique sans stock connu : les magasins qui vendent la marque (stock à confirmer).
            $full['near_brand'] = $stores ? [] : array_slice(brand_stores((int) $exact['p']['id'], [], $lat, $lng), 0, 4);
            $out['exact'] = $full;
        }
    }
    return $out;
}
