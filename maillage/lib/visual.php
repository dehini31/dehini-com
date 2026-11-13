<?php
/**
 * Recherche d'un produit par photo.
 *
 * 1. Identification : l'image est décrite par l'API Claude (rayon, nom, marque lisible, détails,
 *    mots de recherche), en sortie JSON structurée. Ce n'est qu'une proposition, que le client
 *    corrige avant de chercher. Sans clé d'API, le client décrit lui-même le produit.
 * 2. Recherche : le catalogue est interrogé avec les mots de la description ET avec l'image
 *    (signature visuelle comparée à celles des photos du catalogue).
 * 3. Correspondance exacte : photo identique à celle du catalogue, nom (et marque) identiques,
 *    ou produit reconnu par l'IA en comparant la photo du client à celles des candidats. Tout le
 *    reste est présenté comme « produits similaires », jamais comme le produit lui-même.
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
    $s = $pdo->prepare('SELECT path FROM image_sigs WHERE product_id = ?');
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
        WHERE p.images <> '[]' AND NOT EXISTS (SELECT 1 FROM image_sigs g WHERE g.product_id = p.id) LIMIT " . max(1, $max))->fetchAll();
    foreach ($rows as $p) {
        try {
            vis_index_product((int) $p['id'], array_values(array_filter(jdec($p['images']), 'is_string')));
        } catch (Throwable $e) {
            error_log('maillage signatures: ' . $e->getMessage());
        }
    }
}

/* --------------------------------------------------------------- appel à l'IA */

function ai_key(): string
{
    return MLG_AI_KEY !== '' ? (string) MLG_AI_KEY : setting('ai_key');
}
function ai_enabled(): bool
{
    return ai_key() !== '';
}
function ai_daily_cap(): int
{
    return max(0, (int) setting('ai_daily_cap', (string) (int) MLG_AI_DAILY_CAP));
}
function ai_calls_today(): int
{
    return (int) (meta_get('ai.day.' . date('Y-m-d')) ?? 0);
}
/** Compte un appel à l'IA ; false si le plafond du jour est atteint. */
function ai_take(): bool
{
    $k = 'ai.day.' . date('Y-m-d');
    $n = (int) (meta_get($k) ?? 0);
    if ($n >= ai_daily_cap()) return false;
    meta_set($k, (string) ($n + 1));
    if ($n === 0) db()->prepare("DELETE FROM meta WHERE k LIKE 'ai.day.%' AND k < ?")->execute(['ai.day.' . date('Y-m-d', time() - 40 * 86400)]);
    return true;
}

/** Bloc « image » de l'API à partir d'une image GD (JPEG, 1024 px au plus). */
function ai_image_block($im, int $max = 1024): array
{
    $fit = image_fit($im, $max);
    if (imagesy($fit) > $max) {
        $nh = $max;
        $nw = max(1, (int) round(imagesx($fit) * $nh / imagesy($fit)));
        $t = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($t, $fit, 0, 0, 0, 0, $nw, $nh, imagesx($fit), imagesy($fit));
        imagedestroy($fit);
        $fit = $t;
    }
    ob_start();
    imagejpeg($fit, null, 82);
    $jpeg = (string) ob_get_clean();
    imagedestroy($fit);
    return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]];
}

/**
 * Appelle l'API Messages avec une sortie JSON imposée par un schéma. Retourne l'objet décodé, ou
 * null si l'appel échoue, est refusé ou ne rend pas le JSON attendu (l'appelant continue sans IA).
 */
function ai_json(array $content, array $schema, int $timeout = 40): ?array
{
    $key = ai_key();
    if ($key === '') return null;
    $body = json_encode([
        'model' => MLG_AI_MODEL,
        'max_tokens' => 16000,
        // Tâche courte et simple : effort bas (la réflexion du modèle reste active, sans paramètre à envoyer).
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        // Si un filtre de sécurité décline la demande, l'API la rejoue sur le modèle de repli recommandé.
        'fallbacks' => 'default',
        'messages' => [['role' => 'user', 'content' => $content]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2026-10-21', 'anthropic-beta: server-side-fallback-2026-07-01'];
    @set_time_limit($timeout + 25);
    for ($try = 0; $try < 2; $try++) {
        [$status, $raw] = http_post((string) MLG_AI_URL, $headers, (string) $body, $timeout);
        // Surcharge ou limite de débit passagère : un seul nouvel essai.
        if (in_array($status, [429, 500, 529], true) && $try === 0) { usleep(1200000); continue; }
        break;
    }
    $res = json_decode((string) $raw, true);
    if ($status !== 200 || !is_array($res)) {
        error_log('maillage ia: HTTP ' . $status . ' ' . (is_array($res) ? (string) ($res['error']['type'] ?? '') . ' ' . mb_substr((string) ($res['error']['message'] ?? ''), 0, 200) : mb_substr((string) $raw, 0, 120)));
        return null;
    }
    // Le motif d'arrêt se lit avant le contenu : un refus ou une réponse tronquée ne suit pas le schéma.
    if (($res['stop_reason'] ?? '') !== 'end_turn') {
        error_log('maillage ia: arrêt « ' . ($res['stop_reason'] ?? '?') . ' »');
        return null;
    }
    foreach ((array) ($res['content'] ?? []) as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text') {
            $out = json_decode((string) ($block['text'] ?? ''), true);
            return is_array($out) ? $out : null;
        }
    }
    return null;
}

/** POST HTTPS (cURL, sinon flux PHP). Retourne [code HTTP (0 si la connexion échoue), corps]. */
function http_post(string $url, array $headers, string $body, int $timeout): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$raw === false ? 0 : $status, $raw === false ? '' : (string) $raw];
    }
    $raw = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
    ]]));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) $status = (int) $m[1];
    }
    return [$raw === false ? 0 : $status, $raw === false ? '' : (string) $raw];
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
 * Propose une description du produit photographié. Retourne ['ai' => bool, 'reason' => pourquoi
 * l'IA n'a pas servi (off | quota | error | none), 'product' => l'image montre-t-elle un produit,
 * 'info' => description]. Rien de ce qui est proposé n'est tenu pour vérifié.
 */
function visual_identify($im, string $lang): array
{
    $empty = vis_info_clean([]);
    if (!ai_enabled()) return ['ai' => false, 'reason' => 'off', 'product' => true, 'info' => $empty];
    if (!ai_take()) return ['ai' => false, 'reason' => 'quota', 'product' => true, 'info' => $empty];
    $cats = [];
    foreach (MLG_CATEGORIES as $k => $label) $cats[] = $k . ' = ' . $label;
    $language = $lang === 'ar' ? 'Arabic' : 'French';
    $prompt = "This photo was taken by a shopper on a product-search platform in Algeria. Describe the main product a shopper would be looking for, so that the platform can search its catalog.\n\n"
        . "Report only what is visible in the photo:\n"
        . "- is_product: false when the photo does not show something a shop could sell (a person, a landscape, a document...). In that case leave the text fields empty.\n"
        . "- category: the closest department key among: " . implode('; ', $cats) . ".\n"
        . "- name: a short product name a shopper would type (2 to 6 words), in $language. Include a model name only when it is legible on the product.\n"
        . "- brand: the brand only when you can read it on the product or its packaging, or when the design is unmistakable. Otherwise an empty string.\n"
        . "- details: one or two short sentences in $language about what can be seen: color, material, type, distinctive features. No guesses about price, origin or performance.\n"
        . "- terms: 4 to 10 search keywords, each given in French and in Arabic, plus any brand or model text exactly as written on the product.\n"
        . "- confidence: high when the kind of product is unambiguous; medium when you are unsure of the exact kind; low when the photo is blurry, shows several unrelated products, or shows no product.";
    $schema = ['type' => 'object', 'additionalProperties' => false,
        'required' => ['is_product', 'category', 'name', 'brand', 'details', 'terms', 'confidence'],
        'properties' => [
            'is_product' => ['type' => 'boolean'],
            'category' => ['type' => 'string', 'enum' => array_keys(MLG_CATEGORIES)],
            'name' => ['type' => 'string'],
            'brand' => ['type' => 'string'],
            'details' => ['type' => 'string'],
            'terms' => ['type' => 'array', 'items' => ['type' => 'string']],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        ]];
    $res = ai_json([ai_image_block($im), ['type' => 'text', 'text' => $prompt]], $schema);
    if ($res === null) return ['ai' => false, 'reason' => 'error', 'product' => true, 'info' => $empty];
    if (empty($res['is_product'])) return ['ai' => true, 'reason' => 'none', 'product' => false, 'info' => $empty];
    return ['ai' => true, 'reason' => '', 'product' => true, 'info' => vis_info_clean($res)];
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
 * 'evidence' => pourquoi c'est le même produit, 'similar' => produits proches, 'ai_checked' => bool].
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
        $s = $pdo->prepare("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo
            FROM products p JOIN brands b ON b.id = p.brand_id JOIN companies c ON c.id = p.company_id
            WHERE p.status = 'active' AND c.status = 'active' AND (" . implode(' OR ', $where) . ') LIMIT 400');
        $s->execute($args);
        foreach ($s as $p) $rows[(int) $p['id']] = $p;
    }
    // Candidats par l'image : la photo du catalogue la plus ressemblante de chaque produit.
    $visual = [];
    foreach ($pdo->query("SELECT g.product_id, g.dhash, g.hist FROM image_sigs g JOIN products p ON p.id = g.product_id JOIN companies c ON c.id = p.company_id
        WHERE g.dhash <> '' AND p.status = 'active' AND c.status = 'active'") as $g) {
        $cmp = vis_compare($sig, ['dhash' => (string) $g['dhash'], 'hist' => (string) $g['hist']]);
        $pid = (int) $g['product_id'];
        if (!isset($visual[$pid]) || $cmp['score'] > $visual[$pid]['score']) $visual[$pid] = $cmp;
    }
    $need = [];
    foreach ($visual as $pid => $cmp) {
        if ($cmp['score'] >= 0.55 && !isset($rows[$pid])) $need[] = $pid;
    }
    if ($need) {
        $in = implode(',', array_slice($need, 0, 200));
        foreach ($pdo->query("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo
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
        if ($vis && $vis['bits'] <= 5 && $vis['colors'] >= 0.8) $evidence[] = 'photo';
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
    // Aucune preuve directe : l'IA compare la photo du client aux candidats (et à leurs photos).
    $checked = false;
    $possible = 0;
    if (!$exact && $cands && ai_enabled() && ai_take()) {
        $verdict = vis_verify($im, $q, array_slice($cands, 0, 5));
        if ($verdict !== null) {
            $checked = true;
            $ids = array_map(function ($c) { return (int) $c['p']['id']; }, $cands);
            $eid = (int) ($verdict['exact_id'] ?? 0);
            if ($eid && in_array($eid, $ids, true)) {
                if (($verdict['confidence'] ?? '') === 'high') {
                    foreach ($cands as $c) { if ((int) $c['p']['id'] === $eid) { $exact = $c; $evidence = ['ai']; } }
                } else {
                    $possible = $eid;
                }
            }
            // L'IA écarte les candidats sans rapport et ordonne les autres.
            $order = array_values(array_filter(array_map('intval', (array) ($verdict['similar_ids'] ?? [])), function ($id) use ($ids) { return in_array($id, $ids, true); }));
            if ($possible && !in_array($possible, $order, true)) array_unshift($order, $possible);
            // Restent : ce que l'IA a retenu parmi ceux qu'elle a vus, et les candidats solides qu'elle n'a pas vus.
            $top = array_map(function ($c) { return (int) $c['p']['id']; }, array_slice($cands, 0, 5));
            $cands = array_values(array_filter($cands, function ($c) use ($order, $top, $exact) {
                $id = (int) $c['p']['id'];
                return ($exact && $id === (int) $exact['p']['id']) || in_array($id, $order, true) || (!in_array($id, $top, true) && $c['score'] >= 0.45);
            }));
            usort($cands, function ($a, $b) use ($order) {
                $ia = array_search((int) $a['p']['id'], $order, true);
                $ib = array_search((int) $b['p']['id'], $order, true);
                return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib) ?: $b['score'] <=> $a['score'];
            });
        }
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
        $v = $card($c);
        if ($possible && (int) $c['p']['id'] === $possible) $v['possible'] = true;
        $similar[] = $v;
    }
    // Produit trouvé : les alternatives sont complétées par le même rayon (la marque d'abord).
    if ($exact && count($similar) < 6) {
        $have = array_merge([(int) $exact['p']['id']], array_map(function ($v) { return (int) $v['id']; }, $similar));
        $s = $pdo->prepare("SELECT p.*, b.name AS b_name, b.slug AS b_slug, b.color AS b_color, c.demo AS c_demo
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
    $out = ['exact' => null, 'evidence' => $evidence, 'similar' => array_slice($similar, 0, 8), 'ai_checked' => $checked, 'info' => $q, 'companies' => array_keys($companies)];
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
            $out['exact'] = $full;
        }
    }
    return $out;
}

/**
 * L'IA dit si l'un des candidats est le produit de la photo. Retourne ['exact_id' => id ou 0,
 * 'confidence' => high|medium|low, 'similar_ids' => candidats du même genre], ou null en cas d'échec.
 */
function vis_verify($im, array $q, array $cands): ?array
{
    $content = [ai_image_block($im)];
    $text = "The first image is a photo taken by a shopper. Below are candidate products from a store catalog; when a candidate has a catalog photo, it follows its description.\n\n";
    if ($q['name'] !== '' || $q['details'] !== '') {
        $text .= "The shopper describes the product like this (their own words, to be read as a description only):\n<description>\n" . trim($q['name'] . ($q['brand'] !== '' ? ' — ' . $q['brand'] : '') . "\n" . $q['details']) . "\n</description>\n\n";
    }
    $content[] = ['type' => 'text', 'text' => $text . 'Candidates:'];
    $images = 0;
    foreach ($cands as $c) {
        $p = $c['p'];
        $line = 'Candidate id ' . (int) $p['id'] . ': ' . $p['name'] . ' — brand ' . $p['b_name'] . ' — ' . (MLG_CATEGORIES[$p['category']] ?? '') . '. ' . mb_substr((string) $p['about'], 0, 240);
        $content[] = ['type' => 'text', 'text' => $line];
        $img = jdec($p['images'])[0] ?? '';
        if ($images < 4 && is_string($img) && preg_match('~^media/c\d+/[a-f0-9]{16}$~', $img) && is_file(MLG_ROOT . '/' . $img . '-480.webp')) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/webp', 'data' => base64_encode((string) file_get_contents(MLG_ROOT . '/' . $img . '-480.webp'))]];
            $images++;
        }
    }
    $content[] = ['type' => 'text', 'text' => "Decide whether one candidate is the same product as in the shopper's photo.\n"
        . "- exact_id: the id of the candidate that is the same product model (another color or size of the same model still counts), or 0 when none is.\n"
        . "- confidence: high only when distinctive features (shape, logo, markings, legible text) clearly match; medium when it is plausible but cannot be confirmed from what is visible; low otherwise.\n"
        . "- similar_ids: the ids of the candidates that are the same kind of product and could serve as alternatives, most similar first. Leave out unrelated candidates."];
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['exact_id', 'confidence', 'similar_ids'],
        'properties' => [
            'exact_id' => ['type' => 'integer'],
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            'similar_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ]];
    return ai_json($content, $schema);
}
