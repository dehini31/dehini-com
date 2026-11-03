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
/** Page publique d'un professionnel, et l'annuaire des professionnels. */
function pro_url(array $pro, string $lang): string
{
    return seo_base() . seo_prefix($lang) . 'pro/' . rawurlencode((string) $pro['slug']);
}
function pros_url(string $lang): string
{
    return seo_base() . seo_prefix($lang) . 'pros';
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
        'pros' => 'Professionnels', 'all_pros' => 'Tous les professionnels', 'pros_title' => 'Professionnels et entreprises du Défi Impact',
        'pros_h1' => 'Des professionnels qui partagent leur expérience',
        'pros_desc' => 'Entreprises, auto-entrepreneurs et professionnels liés aux expériences de vie du Défi Impact : leurs domaines, leurs expériences et comment les contacter.',
        'pros_intro' => 'Ils ont choisi les questions qui touchent à leur métier et y partagent leur expérience. Vous êtes professionnel ? Créez votre page gratuitement.',
        'pros_cta' => 'Créer ma page professionnelle', 'pros_empty' => 'Aucune page professionnelle pour l\'instant : soyez le premier !',
        'pro_related' => 'Des professionnels pour en parler', 'pro_join' => 'Vous êtes professionnel ? Associez votre page à cette question',
        'pro_verified' => 'Vérifié', 'pro_featured' => 'Sponsorisé', 'pro_site' => 'Site web', 'pro_call' => 'Appeler', 'pro_mail' => 'Email', 'pro_wa' => 'WhatsApp',
        'pro_qs' => 'Questions sur lesquelles ce professionnel intervient', 'pro_exps' => 'Ses expériences', 'pro_contact' => 'Contacter',
        'pro_title' => '{name} — {domain} · Défi Impact', 'pro_desc' => '{name} ({domain}{city}) : expériences et questions de vie sur lesquelles ce professionnel intervient sur le Défi Impact.',
        'pro_nf' => 'Cette page professionnelle n\'existe pas ou n\'est plus en ligne.', 'pro_by' => 'Page professionnelle',
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
        'pros' => 'المهنيون', 'all_pros' => 'كل المهنيين', 'pros_title' => 'مهنيون وشركات في تحدي الأثر',
        'pros_h1' => 'مهنيون يشاركون تجاربهم',
        'pros_desc' => 'شركات ومقاولون ذاتيون ومهنيون مرتبطون بتجارب الحياة في تحدي الأثر: مجالاتهم وتجاربهم وطرق التواصل معهم.',
        'pros_intro' => 'اختاروا الأسئلة المرتبطة بمهنتهم ويشاركون فيها تجربتهم. هل أنت مهني؟ أنشئ صفحتك مجاناً.',
        'pros_cta' => 'إنشاء صفحتي المهنية', 'pros_empty' => 'لا توجد صفحات مهنية بعد: كن الأول!',
        'pro_related' => 'مهنيون يتحدثون عن هذا', 'pro_join' => 'هل أنت مهني؟ اربط صفحتك بهذا السؤال',
        'pro_verified' => 'موثّق', 'pro_featured' => 'إعلان', 'pro_site' => 'الموقع', 'pro_call' => 'اتصال', 'pro_mail' => 'البريد', 'pro_wa' => 'واتساب',
        'pro_qs' => 'أسئلة يتدخّل فيها هذا المهني', 'pro_exps' => 'تجاربه', 'pro_contact' => 'تواصل',
        'pro_title' => '{name} — {domain} · تحدي الأثر', 'pro_desc' => '{name} ({domain}{city}): تجارب وأسئلة حياتية يتدخّل فيها هذا المهني في تحدي الأثر.',
        'pro_nf' => 'هذه الصفحة المهنية غير موجودة أو لم تعد متاحة.', 'pro_by' => 'صفحة مهنية',
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
        'pros' => 'Professionals', 'all_pros' => 'All professionals', 'pros_title' => 'Professionals and companies on the Impact Challenge',
        'pros_h1' => 'Professionals who share their experience',
        'pros_desc' => 'Companies, freelancers and professionals linked to the life experiences of the Impact Challenge: their fields, their experiences and how to contact them.',
        'pros_intro' => 'They chose the questions related to their work and share their experience there. Are you a professional? Create your page for free.',
        'pros_cta' => 'Create my professional page', 'pros_empty' => 'No professional page yet: be the first!',
        'pro_related' => 'Professionals to talk about it', 'pro_join' => 'Are you a professional? Link your page to this question',
        'pro_verified' => 'Verified', 'pro_featured' => 'Sponsored', 'pro_site' => 'Website', 'pro_call' => 'Call', 'pro_mail' => 'Email', 'pro_wa' => 'WhatsApp',
        'pro_qs' => 'Questions this professional covers', 'pro_exps' => 'Their experiences', 'pro_contact' => 'Contact',
        'pro_title' => '{name} — {domain} · Impact Challenge', 'pro_desc' => '{name} ({domain}{city}): experiences and life questions this professional covers on the Impact Challenge.',
        'pro_nf' => 'This professional page doesn\'t exist or is no longer online.', 'pro_by' => 'Professional page',
    ],
];

function seo_t(string $lang, string $key, array $vars = []): string
{
    $s = SEO_STR[$lang][$key] ?? SEO_STR['fr'][$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}
