<?php
/**
 * Référencement du défi Impact : adresses des pages publiques lisibles par les moteurs
 * de recherche (une page par question, une page « toutes les questions », en trois
 * langues), textes de ces pages et liste des pages à indexer (sitemap).
 *
 *   /impact/q/12-quitter-la-maison-familiale        question 12 en français
 *   /impact/ar/q/12-…  /impact/en/q/12-…             la même en arabe, en anglais
 *   /impact/questions  /impact/ar/questions  …       toutes les questions
 *
 * Une question n'est proposée à l'indexation que si au moins un récit y est publié :
 * c'est ce contenu unique (l'expérience vécue) qui lui donne sa valeur pour un moteur.
 */
declare(strict_types=1);

const SEO_BASE = 'https://dehini.com/impact/';

/** Adresse publique de base (https://dehini.com/impact/ en production, l'adresse locale en test). */
function seo_base(): string
{
    return IMPACT_SITE_URL !== '' ? rtrim(IMPACT_SITE_URL, '/') . '/' : SEO_BASE;
}

/** Préfixe de langue dans les adresses : rien pour le français, « ar/ » ou « en/ » sinon. */
function seo_prefix(string $lang): string
{
    return $lang === 'fr' ? '' : $lang . '/';
}

/** Partie lisible de l'adresse d'une question (sans accents en français/anglais, en lettres arabes en arabe). */
function q_slug(array $q, string $lang): string
{
    $t = mb_strtolower(qtext($q, $lang));
    if ($lang !== 'ar' || !preg_match('/\p{Arabic}/u', $t)) {
        $t = strtr($t, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '’' => '-', "'" => '-']);
        $t = (string) preg_replace('/[^a-z0-9]+/', '-', $t);
    } else {
        // Lettres arabes et chiffres seulement (la ponctuation arabe « ؟ ، ؛ » fait aussi partie du bloc arabe).
        $t = (string) preg_replace('/(?:[^\p{Arabic}0-9]|[\x{060C}\x{061B}\x{061F}\x{0640}\x{066A}-\x{066D}\x{06D4}])+/u', '-', $t);
    }
    $t = trim($t, '-');
    if (mb_strlen($t) > 70) $t = rtrim(mb_substr($t, 0, (int) (mb_strrpos(mb_substr($t, 0, 70), '-') ?: 70)), '-');
    return $t;
}

function q_path(array $q, string $lang): string
{
    $slug = q_slug($q, $lang);
    return seo_prefix($lang) . 'q/' . $q['id'] . ($slug !== '' ? '-' . rawurlencode($slug) : '');
}
function q_url(array $q, string $lang): string
{
    return seo_base() . q_path($q, $lang);
}
function hub_url(string $lang): string
{
    return seo_base() . seo_prefix($lang) . 'questions';
}
/** Le défi lui-même, dans une langue donnée (la page d'accueil sans paramètre choisit la langue du visiteur). */
function home_url(?string $lang = null): string
{
    return seo_base() . ($lang ? '?lang=' . $lang : '');
}

/**
 * Questions qui ont au moins un récit publié : [id => date du dernier récit].
 * Ce sont les seules pages de question proposées à l'indexation.
 */
function indexable_questions(): array
{
    $qs = questions();
    $out = [];
    foreach (db()->query("SELECT qid, MAX(updated) AS t FROM stories WHERE status = 'published' GROUP BY qid") as $r) {
        if (isset($qs[(int) $r['qid']])) $out[(int) $r['qid']] = (int) $r['t'];
    }
    return $out;
}

const SEO_CATS = [
    'fr' => ['perso' => 'Vie personnelle', 'social' => 'Vie sociale', 'pro' => 'Vie professionnelle'],
    'ar' => ['perso' => 'الحياة الشخصية', 'social' => 'الحياة الاجتماعية', 'pro' => 'الحياة المهنية'],
    'en' => ['perso' => 'Personal life', 'social' => 'Social life', 'pro' => 'Professional life'],
];

const SEO_STR = [
    'fr' => [
        'brand' => 'Défi Impact', 'home' => 'Le défi', 'all' => 'Toutes les questions',
        'q_title' => '{q} — expériences et témoignages', 'q_desc_n' => '{n} personnes ont répondu : {p} % y voient un impact positif. Lisez leurs expériences et dites quel impact cela a eu sur vous.',
        'q_desc_0' => 'Impact négatif, aucun impact ou impact positif ? Répondez et lisez les expériences positives partagées par d\'autres sur le Défi Impact.',
        'your' => 'Et pour vous, quel impact ?', 'neg' => 'Négatif', 'none' => 'Aucun', 'pos' => 'Positif',
        'answer_hint' => 'Un clic suffit : votre réponse est enregistrée et vous continuez le défi (1000 questions, sans inscription).',
        'stats' => '{n} réponses', 'stats_few' => 'Soyez parmi les premiers à répondre à cette question.',
        'pos_share' => '{p} % impact positif', 'stories' => 'Leurs expériences positives', 'impact' => 'Son impact positif',
        'anon' => 'Anonyme', 'likes' => '{n} j\'aime', 'more' => 'Lire plus de récits dans le défi',
        'none_yet' => 'Personne n\'a encore raconté son expérience positive pour cette question.',
        'tell' => 'Vous l\'avez vécue positivement ? Racontez-la', 'related' => 'Questions proches', 'share' => 'Partager cette question',
        'copy' => 'Copier le lien', 'copied' => 'Lien copié !', 'hub_title' => 'Les 1000 questions du Défi Impact', 'hub_h1' => 'Les {n} questions du Défi Impact',
        'hub_desc' => '{n} questions sur la vie personnelle, sociale et professionnelle. Pour chacune : impact négatif, aucun ou positif ? Lisez les expériences positives partagées par les participants.',
        'hub_intro' => 'Chaque question parle d\'une expérience de vie : un déménagement, un échec, une rencontre, un premier emploi… Ouvrez-en une pour voir comment les autres l\'ont vécue et lire leurs expériences positives, puis donnez votre réponse.',
        'with_stories' => '{n} récit(s)', 'start' => 'Relever le défi', 'lang_name' => 'Français',
        'foot' => 'Un projet de Mohammed Dehini · dehini.com', 'nf' => 'Cette question n\'existe pas ou n\'est plus en ligne.',
    ],
    'ar' => [
        'brand' => 'تحدي الأثر', 'home' => 'التحدي', 'all' => 'كل الأسئلة',
        'q_title' => '{q} — تجارب وشهادات', 'q_desc_n' => 'أجاب {n} شخصاً: يرى {p}% منهم أثراً إيجابياً. اقرأ تجاربهم وقل ما الأثر الذي تركته فيك.',
        'q_desc_0' => 'أثر سلبي أم بلا أثر أم أثر إيجابي؟ أجب واقرأ التجارب الإيجابية التي شاركها الآخرون في تحدي الأثر.',
        'your' => 'وأنت، ما الأثر الذي تركته فيك؟', 'neg' => 'سلبي', 'none' => 'بلا أثر', 'pos' => 'إيجابي',
        'answer_hint' => 'نقرة واحدة تكفي: تُسجَّل إجابتك وتواصل التحدي (1000 سؤال، دون تسجيل).',
        'stats' => '{n} إجابة', 'stats_few' => 'كن من أوائل من يجيبون عن هذا السؤال.',
        'pos_share' => '{p}% أثر إيجابي', 'stories' => 'تجاربهم الإيجابية', 'impact' => 'أثرها الإيجابي',
        'anon' => 'مجهول', 'likes' => '{n} إعجاب', 'more' => 'اقرأ المزيد من القصص في التحدي',
        'none_yet' => 'لم يروِ أحد بعدُ تجربته الإيجابية مع هذا السؤال.',
        'tell' => 'عشتها بشكل إيجابي؟ احكِها', 'related' => 'أسئلة قريبة', 'share' => 'شارك هذا السؤال',
        'copy' => 'نسخ الرابط', 'copied' => 'تم نسخ الرابط!', 'hub_title' => 'أسئلة تحدي الأثر الألف', 'hub_h1' => 'أسئلة تحدي الأثر ({n})',
        'hub_desc' => '{n} سؤال عن الحياة الشخصية والاجتماعية والمهنية. لكل سؤال: أثر سلبي أم بلا أثر أم أثر إيجابي؟ اقرأ التجارب الإيجابية التي شاركها المشاركون.',
        'hub_intro' => 'كل سؤال يتناول تجربة من تجارب الحياة: انتقال، فشل، لقاء، أول عمل… افتح أحدها لترى كيف عاشها الآخرون واقرأ تجاربهم الإيجابية، ثم أجب أنت.',
        'with_stories' => '{n} قصة', 'start' => 'خُض التحدي', 'lang_name' => 'العربية',
        'foot' => 'مشروع من إعداد محمد دهيني · dehini.com', 'nf' => 'هذا السؤال غير موجود أو لم يعد متاحاً.',
    ],
    'en' => [
        'brand' => 'Impact Challenge', 'home' => 'The challenge', 'all' => 'All questions',
        'q_title' => '{q} — experiences and stories', 'q_desc_n' => '{n} people answered: {p}% see a positive impact. Read their experiences and say what impact it had on you.',
        'q_desc_0' => 'Negative impact, no impact or positive impact? Answer and read the positive experiences others shared on the Impact Challenge.',
        'your' => 'And for you, what impact?', 'neg' => 'Negative', 'none' => 'None', 'pos' => 'Positive',
        'answer_hint' => 'One click is enough: your answer is saved and you continue the challenge (1000 questions, no sign-up).',
        'stats' => '{n} answers', 'stats_few' => 'Be among the first to answer this question.',
        'pos_share' => '{p}% positive impact', 'stories' => 'Their positive experiences', 'impact' => 'Its positive impact',
        'anon' => 'Anonymous', 'likes' => '{n} likes', 'more' => 'Read more stories in the challenge',
        'none_yet' => 'Nobody has shared a positive experience for this question yet.',
        'tell' => 'Lived it positively? Tell your story', 'related' => 'Related questions', 'share' => 'Share this question',
        'copy' => 'Copy link', 'copied' => 'Link copied!', 'hub_title' => 'The 1000 questions of the Impact Challenge', 'hub_h1' => 'The {n} questions of the Impact Challenge',
        'hub_desc' => '{n} questions about personal, social and professional life. For each one: negative, no or positive impact? Read the positive experiences shared by participants.',
        'hub_intro' => 'Each question is about a life experience: a move, a failure, a meeting, a first job… Open one to see how others lived it and read their positive experiences, then give your answer.',
        'with_stories' => '{n} story(ies)', 'start' => 'Take the challenge', 'lang_name' => 'English',
        'foot' => 'A project by Mohammed Dehini · dehini.com', 'nf' => 'This question doesn\'t exist or is no longer online.',
    ],
];

function seo_t(string $lang, string $key, array $vars = []): string
{
    $s = SEO_STR[$lang][$key] ?? SEO_STR['fr'][$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}
