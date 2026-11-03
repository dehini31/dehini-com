<?php
/**
 * Pages professionnelles du défi Impact : entreprises, auto-entrepreneurs, professions
 * libérales, associations… Une page par compte, liée à des questions (choisies par le
 * pro, avec des suggestions selon son domaine et ses mots-clés), et des expériences
 * publiées au nom de la page. Les pros apparaissent à côté des questions qui les
 * concernent : c'est la visibilité qu'ils viennent chercher (et qui pourra se vendre :
 * « mise en avant » réglée depuis l'admin).
 */
declare(strict_types=1);

const PRO_KINDS = [
    'company'     => ['Entreprise', 'شركة', 'Company'],
    'freelance'   => ['Auto-entrepreneur / indépendant', 'مقاول ذاتي / مستقل', 'Self-employed / freelancer'],
    'liberal'     => ['Profession libérale (médecin, avocat, psychologue…)', 'مهنة حرة (طبيب، محامٍ، أخصائي نفسي…)', 'Licensed professional (doctor, lawyer, therapist…)'],
    'association' => ['Association / ONG', 'جمعية / منظمة', 'Non-profit / NGO'],
    'other'       => ['Autre profil professionnel', 'ملف مهني آخر', 'Other professional profile'],
];

/**
 * Domaines : libellés (fr, ar, en), univers le plus proche, et mots servant à proposer des
 * questions (racines sans accents en français/anglais, mots arabes sans « ال »).
 */
const PRO_DOMAINS = [
    'coaching'    => [['Coaching et développement personnel', 'التدريب والتطوير الذاتي', 'Coaching & personal development'], 'perso',
        ['confian', 'motivat', 'objecti', 'peur', 'echec', 'changem', 'estime', 'stress', 'decisio', 'reussit', 'ثقة', 'هدف', 'خوف', 'فشل', 'confide', 'goal', 'fear', 'failure']],
    'psy'         => [['Psychologie et accompagnement', 'علم النفس والمرافقة', 'Psychology & counselling'], 'perso',
        ['deuil', 'perte', 'rupture', 'anxie', 'depress', 'solitud', 'traumat', 'divorce', 'deces', 'حزن', 'فقدان', 'وحدة', 'قلق', 'وفاة', 'grief', 'loss', 'breakup', 'lonel', 'anxiety']],
    'sante'       => [['Santé et bien-être', 'الصحة والرفاهية', 'Health & wellbeing'], 'perso',
        ['sante', 'maladi', 'medeci', 'hopital', 'sommeil', 'aliment', 'regime', 'handica', 'operati', 'صحة', 'مرض', 'مستشفى', 'health', 'illness', 'hospita', 'sleep', 'diet']],
    'education'   => [['Éducation et formation', 'التعليم والتكوين', 'Education & training'], 'perso',
        ['etude', 'ecole', 'univers', 'formati', 'examen', 'diplom', 'apprent', 'langue', 'enseign', 'دراسة', 'جامعة', 'مدرسة', 'تعلم', 'study', 'school', 'exam', 'learn', 'languag']],
    'emploi'      => [['Emploi, RH et recrutement', 'التوظيف والموارد البشرية', 'Jobs, HR & recruiting'], 'pro',
        ['emploi', 'travail', 'chomage', 'recrute', 'entreti', 'licenci', 'collegu', 'manager', 'salaire', 'promoti', 'carrier', 'عمل', 'وظيفة', 'بطالة', 'زميل', 'job', 'work', 'unemplo', 'intervi', 'career', 'salary']],
    'entreprise'  => [['Entrepreneuriat et conseil', 'ريادة الأعمال والاستشارة', 'Entrepreneurship & consulting'], 'pro',
        ['entrepr', 'projet', 'client', 'associe', 'faillit', 'creatio', 'commerc', 'startup', 'مشروع', 'شركة', 'زبون', 'busines', 'company', 'custome']],
    'finance'     => [['Finance, banque et assurance', 'المالية والبنوك والتأمين', 'Finance, banking & insurance'], 'pro',
        ['argent', 'dette', 'credit', 'epargne', 'budget', 'investi', 'banque', 'assuran', 'impot', 'مال', 'دين', 'قرض', 'money', 'debt', 'loan', 'saving', 'insuran']],
    'immobilier'  => [['Immobilier et déménagement', 'العقار والانتقال', 'Real estate & moving'], 'perso',
        ['demenag', 'maison', 'logemen', 'apparte', 'quartie', 'loyer', 'constru', 'سكن', 'منزل', 'بيت', 'انتقال', 'home', 'house', 'moving', 'apartme', 'rent']],
    'famille'     => [['Famille, mariage et enfants', 'الأسرة والزواج والأطفال', 'Family, marriage & children'], 'social',
        ['mariage', 'enfant', 'famille', 'naissan', 'parent', 'divorce', 'couple', 'bebe', 'زواج', 'طفل', 'أسرة', 'عائلة', 'marriag', 'child', 'family', 'baby', 'parent']],
    'voyage'      => [['Voyage et tourisme', 'السفر والسياحة', 'Travel & tourism'], 'perso',
        ['voyage', 'pays', 'etrange', 'vacance', 'expatri', 'immigr', 'سفر', 'بلد', 'غربة', 'هجرة', 'travel', 'abroad', 'holiday', 'country']],
    'juridique'   => [['Droit et justice', 'القانون والعدالة', 'Law & justice'], 'social',
        ['litige', 'proces', 'justice', 'contrat', 'heritag', 'avocat', 'droit', 'police', 'قانون', 'محكمة', 'عقد', 'ميراث', 'law', 'court', 'contrac', 'lawyer']],
    'digital'     => [['Digital, tech et réseaux sociaux', 'الرقمي والتقنية', 'Digital & tech'], 'social',
        ['interne', 'reseau', 'numeriq', 'ordinat', 'telepho', 'applica', 'ligne', 'digital', 'انترنت', 'هاتف', 'تواصل', 'شبكات', 'interne', 'social', 'phone', 'online']],
    'sport'       => [['Sport et loisirs', 'الرياضة والترفيه', 'Sport & leisure'], 'perso',
        ['sport', 'competi', 'equipe', 'blessur', 'loisir', 'musique', 'رياضة', 'فريق', 'هواية', 'team', 'injury', 'hobby', 'music']],
    'association' => [['Associatif et humanitaire', 'العمل الجمعوي والإنساني', 'Non-profit & humanitarian'], 'social',
        ['benevol', 'associa', 'aide', 'solidar', 'communa', 'تطوع', 'جمعية', 'مساعدة', 'volunte', 'charity', 'communi', 'help']],
    'autre'       => [['Autre domaine', 'مجال آخر', 'Other field'], '', []],
];

/** Libellé d'un type ou d'un domaine dans une langue (fr = 0, ar = 1, en = 2). */
function pro_label(string $what, string $key, string $lang): string
{
    $i = ['fr' => 0, 'ar' => 1, 'en' => 2][$lang] ?? 0;
    if ($what === 'kind') return PRO_KINDS[$key][$i] ?? $key;
    return PRO_DOMAINS[$key][0][$i] ?? $key;
}

/** Adresse lisible d'une page pro, unique : « cabinet-benali », « cabinet-benali-2 »… */
function pro_slug(string $name, int $selfId = 0): string
{
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', fold($name)), '-');
    if ($base === '') $base = 'pro';
    $base = substr($base, 0, 50);
    $slug = $base;
    $s = db()->prepare('SELECT 1 FROM pros WHERE slug = ? AND id != ?');
    for ($i = 2; ; $i++) {
        $s->execute([$slug, $selfId]);
        if (!$s->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
    }
}

function pro_logo_url(string $logo): string
{
    return $logo !== '' ? 'uploads/pros/' . $logo : '';
}

/** Ce que tout le monde peut voir d'une page pro. */
function pro_public(array $p): array
{
    return [
        'id' => (int) $p['id'], 'slug' => (string) $p['slug'], 'name' => (string) $p['name'], 'kind' => (string) $p['kind'],
        'domain' => (string) $p['domain'], 'city' => (string) $p['city'], 'logo' => pro_logo_url((string) $p['logo']),
        'verified' => (bool) $p['verified'], 'featured' => (bool) $p['featured'],
    ];
}

/** Pages pro en ligne, indexées par id (chargées une fois par requête). */
function pros_active(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (db()->query("SELECT * FROM pros WHERE status = 'active' ORDER BY featured DESC, verified DESC, id") as $p) $cache[(int) $p['id']] = $p;
    return $cache;
}

/**
 * Pros liés à chaque question : ceux qui l'ont choisie et ceux qui y ont publié une
 * expérience. [qid => [ids]], mises en avant d'abord, puis pages vérifiées.
 */
function pros_by_question(): array
{
    $active = pros_active();
    $map = [];
    foreach (db()->query('SELECT qid, pro_id FROM pro_questions') as $r) {
        if (isset($active[(int) $r['pro_id']])) $map[(int) $r['qid']][(int) $r['pro_id']] = 1;
    }
    foreach (db()->query("SELECT DISTINCT qid, pro_id FROM stories WHERE status = 'published' AND pro_id IS NOT NULL") as $r) {
        if (isset($active[(int) $r['pro_id']])) $map[(int) $r['qid']][(int) $r['pro_id']] = 2;
    }
    $rank = array_flip(array_keys($active)); // ordre de pros_active() : mises en avant, vérifiées, anciennes
    foreach ($map as $qid => $ids) {
        $list = array_keys($ids);
        usort($list, function ($a, $b) use ($ids, $rank) {
            return [$rank[$a], -$ids[$a]] <=> [$rank[$b], -$ids[$b]];
        });
        $map[$qid] = $list;
    }
    return $map;
}

/** Mots-clés saisis par le pro → racines comparables aux textes des questions. */
function pro_terms(string $keywords): array
{
    $out = [];
    foreach (preg_split('/[\s,;\/\n]+/u', fold($keywords)) ?: [] as $w) {
        $w = trim($w, " .'\"-");
        if (preg_match('/\p{Arabic}/u', $w)) {
            if (mb_strlen($w) > 4 && mb_substr($w, 0, 2) === 'ال') $w = mb_substr($w, 2);
            if (mb_strlen($w) >= 3) $out[$w] = 1;
        } elseif (strlen($w) >= 4) {
            $out[substr($w, 0, 7)] = 1;
        }
    }
    return array_keys($out);
}

/**
 * Questions proposées à une page pro : celles dont le texte (dans les trois langues)
 * contient les mots de son domaine ou ses propres mots-clés. Les mots-clés du pro
 * comptent plus que ceux du domaine.
 * @return list<int> ids, du plus pertinent au moins pertinent
 */
function pro_suggest(string $domain, string $keywords, int $limit = 40): array
{
    $dom = PRO_DOMAINS[$domain] ?? PRO_DOMAINS['autre'];
    $own = pro_terms($keywords);
    $scored = [];
    $order = 0;
    foreach (questions() as $q) {
        $order++;
        $text = fold($q['fr'] . ' ' . $q['en'] . ' ' . $q['ar']);
        $score = 0;
        foreach ($dom[2] as $w) if (strpos($text, $w) !== false) $score += 2;
        foreach ($own as $w) if (strpos($text, $w) !== false) $score += 3;
        if ($score && $dom[1] === $q['cat']) $score += 1;
        if ($score) $scored[] = [$score, $order, $q['id']];
    }
    usort($scored, function ($a, $b) { return [$b[0], $a[1]] <=> [$a[0], $b[1]]; });
    return array_map(function ($x) { return $x[2]; }, array_slice($scored, 0, $limit));
}

/**
 * Logo envoyé par le pro : vraie image (JPEG, PNG ou WebP, 3 Mo max), réduite à 400 px et
 * réenregistrée (ce qui retire tout contenu caché). Renvoie le nom du fichier créé.
 */
function pro_store_logo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('upload');
    if ($file['size'] > 3 * 1024 * 1024) throw new RuntimeException('too_big');
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) throw new RuntimeException('bad_image');
    $dir = dirname(__DIR__) . '/uploads/pros';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('dir');
    $name = token(10);
    if (function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if (!$src) throw new RuntimeException('bad_image');
        [$w, $h] = [imagesx($src), imagesy($src)];
        $k = min(1, 400 / max($w, $h));
        $dst = imagecreatetruecolor(max(1, (int) round($w * $k)), max(1, (int) round($h * $k)));
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        if (function_exists('imagewebp')) { $name .= '.webp'; imagewebp($dst, "$dir/$name", 85); }
        else { $name .= '.png'; imagepng($dst, "$dir/$name"); }
        return $name;
    }
    $name .= '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) throw new RuntimeException('move');
    return $name;
}

function pro_delete_logo(string $logo): void
{
    if ($logo !== '' && preg_match('/^[a-f0-9]{20}\.(webp|png|jpg)$/', $logo)) @unlink(dirname(__DIR__) . '/uploads/pros/' . $logo);
}
