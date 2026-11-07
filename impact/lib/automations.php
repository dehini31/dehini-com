<?php
/**
 * Emails automatiques du défi Impact (lancés par cron.php, réglés dans l'onglet « Emails » de l'admin).
 *
 * Chaque scénario choisit ses destinataires, compose un email avec le gabarit commun (mailer.php)
 * et note l'envoi dans auto_log : un scénario ne part jamais deux fois pour la même raison
 * (même compte, même palier, même mois…). À la première activation d'un scénario à paliers,
 * ce qui est déjà atteint est noté sans envoi : on ne rattrape pas l'historique.
 *
 *   onboard      premiers pas, le lendemain de l'inscription (moins de 10 réponses)
 *   verify       rappel de confirmation de l'adresse, 2 jours après l'inscription (une fois)
 *   milestone    paliers de 100, 250, 500 et 1000 réponses
 *   comeback     relance après 21 jours sans visite (au plus tous les 60 jours)
 *   liked        un récit atteint 1, 5, 10, 25 ou 50 « j'aime » (au plus un email par jour)
 *   pro_welcome  bienvenue et étapes pour réussir sa page pro (dans l'heure)
 *   pro_nudge    page pro incomplète, 3 jours après sa création (une fois)
 *   pro_report   rapport mensuel du pro, le 1er du mois (affichages, visites, clics)
 *
 * Les emails « conseils » respectent users.opt_tips, ceux des pros users.opt_pro (liens de
 * désabonnement « tips » et « pro »). Le rappel de confirmation est un email de service.
 */
declare(strict_types=1);

const AUTOMATIONS = [
    'onboard'     => ['Premiers pas', "Le lendemain de l'inscription, si le compte a répondu à moins de 10 questions : comment marche le défi et une première question.", 'tips'],
    'verify'      => ['Rappel de confirmation', "2 jours après l'inscription, si l'adresse n'est pas confirmée (une seule fois).", ''],
    'milestone'   => ['Paliers de réponses', 'À 100, 250, 500 et 1000 réponses : son bilan (positif / aucun / négatif) et une invitation à raconter.', 'tips'],
    'comeback'    => ['Relance des absents', 'Après 21 jours sans visite (au plus tous les 60 jours) : sa progression, les nouveaux récits et une question pour reprendre.', 'tips'],
    'liked'       => ['Récit apprécié', "Quand un récit atteint 1, 5, 10, 25 ou 50 « j'aime » (au plus un email par jour).", 'tips'],
    'pro_welcome' => ['Bienvenue pro', "Dans l'heure qui suit la création d'une page pro : les étapes pour la réussir.", 'pro'],
    'pro_nudge'   => ['Page pro incomplète', "3 jours après sa création, s'il manque le logo, la présentation, des questions ou une expérience (une fois).", 'pro'],
    'pro_report'  => ['Rapport mensuel du pro', 'Le 1er de chaque mois : affichages, personnes touchées, visites et clics du mois écoulé.', 'pro'],
];
const MILESTONES = [100, 250, 500, 1000];
const LIKE_STEPS = [1, 5, 10, 25, 50];

const AUTO_STR = [
    'fr' => [
        'months' => ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'],
        'onb_label' => 'Premiers pas', 'onb_subject' => '{name}, votre défi commence ici',
        'onb_pre' => 'Une touche par question : voici comment tirer le meilleur du défi.',
        'onb_kicker' => 'Bienvenue', 'onb_title' => '{name}, votre défi commence ici.',
        'onb_sub' => 'Des expériences de vie, trois réponses possibles. Voici comment en tirer le meilleur.',
        'onb_s1' => 'Répondez à votre rythme', 'onb_s1p' => 'Négatif, aucun ou positif : une touche par question. Cinq minutes suffisent pour commencer.',
        'onb_s2' => 'Découvrez votre empreinte', 'onb_s2p' => 'Votre résultat se met à jour à chaque réponse, et vous vous situez par rapport au monde.',
        'onb_s3' => 'Inspirez les autres', 'onb_s3p' => 'Racontez une expérience positive : elle peut aider quelqu\'un qui l\'a vécue difficilement.',
        'onb_q' => 'Votre première question', 'onb_cta' => 'Continuer le défi',
        'ver_label' => 'Votre compte', 'ver_subject' => 'Plus qu\'un clic pour activer votre compte',
        'ver_pre' => 'Confirmez votre adresse pour recevoir la question du jour et vos messages.',
        'ver_kicker' => 'Dernière étape', 'ver_title' => 'Confirmez votre adresse en un clic.',
        'ver_p' => 'Sans confirmation, nous ne pouvons pas vous envoyer la question du jour, les expériences positives de la communauté, ni vous prévenir quand quelqu\'un vous écrit.',
        'ver_cta' => 'Confirmer mon adresse', 'ver_ignore' => 'Vous n\'avez pas créé de compte sur le Défi Impact ? Ignorez cet email, il ne se passera rien.',
        'mil_label' => 'Palier atteint', 'mil_subject' => '{n} réponses, bravo {name} !',
        'mil_pre' => 'Votre bilan : {p} d\'impacts positifs.', 'mil_kicker' => 'Palier atteint',
        'mil_title' => '{n} réponses ! Bravo {name}.', 'mil_sub' => 'Vous avez déjà exploré {p} du défi. Voici votre bilan.',
        'mil_pos' => 'positifs', 'mil_none' => 'sans impact', 'mil_neg' => 'négatifs',
        'mil_story' => 'Vous avez vécu positivement « {q} ». Racontez-le : votre récit peut aider quelqu\'un qui l\'a vécu autrement.',
        'mil_cta_story' => 'Raconter cette expérience', 'mil_cta' => 'Voir mon résultat',
        'cb_label' => 'On vous attend', 'cb_subject' => '{name}, votre défi vous attend toujours',
        'cb_pre' => 'Vous en êtes à {n} questions. Reprenez là où vous vous étiez arrêté.',
        'cb_kicker' => 'Vous nous manquez', 'cb_title' => '{name}, on vous a gardé votre place.',
        'cb_sub' => 'Vous en êtes à {n} questions sur {t}. Reprenez exactement là où vous vous étiez arrêté.',
        'cb_new' => 'nouveaux récits positifs', 'cb_done' => 'questions répondues', 'cb_people' => 'participants',
        'cb_q' => 'Une question pour reprendre', 'cb_cta' => 'Reprendre le défi',
        'lk_label' => 'Votre récit', 'lk_subject_1' => 'Votre récit a touché une première personne',
        'lk_subject_n' => 'Votre récit a touché {n} personnes',
        'lk_pre' => 'Chaque « j\'aime », c\'est quelqu\'un que votre expérience a aidé.', 'lk_kicker' => 'Merci pour votre récit',
        'lk_title_1' => 'Votre récit a touché une première personne.', 'lk_title_n' => 'Votre récit a touché {n} personnes.',
        'lk_p' => 'Chaque « j\'aime », c\'est quelqu\'un que votre expérience a aidé à voir les choses autrement. Et si vous en racontiez une autre ?',
        'lk_cta' => 'Raconter une autre expérience',
        'pw_label' => 'Espace pro', 'pw_subject' => '{pro} : votre page pro est en ligne',
        'pw_pre' => 'Les étapes pour être visible auprès des bonnes personnes.', 'pw_kicker' => 'Bienvenue',
        'pw_title' => '{pro}, votre page est en ligne.', 'pw_sub' => 'Voici comment la rendre visible auprès des personnes qui ont besoin de votre expérience.',
        'pw_h' => 'Pour une page qui donne confiance', 'pw_p' => 'Les pages complètes apparaissent à côté de leurs questions dans le défi et sont mieux référencées sur Google. Vous suivez ensuite leurs affichages, visites et clics dans « Vos statistiques ».',
        'pw_cta' => 'Compléter ma page', 'pw_view' => 'Voir ma page publique',
        'ck_logo' => 'Ajouter votre logo', 'ck_bio' => 'Écrire une présentation (80 caractères au moins)',
        'ck_qs' => 'Lier votre page à au moins 3 questions de votre domaine', 'ck_exp' => 'Publier votre première expérience',
        'ck_contact' => 'Indiquer un téléphone ou un site web',
        'pn_label' => 'Espace pro', 'pn_subject_1' => 'Il manque une étape à votre page pro', 'pn_subject_n' => 'Il manque {n} étapes à votre page pro',
        'pn_pre' => 'Quelques minutes pour être visible à côté des questions de votre domaine.', 'pn_kicker' => 'Votre page pro',
        'pn_title_1' => 'Plus qu\'une étape pour une page complète.', 'pn_title_n' => 'Plus que {n} étapes pour une page complète.',
        'pn_p' => 'Une page complète inspire confiance : elle apparaît à côté de ses questions et ses expériences partent par email à ceux qui ont répondu « négatif ».',
        'pr_label' => 'Rapport mensuel', 'pr_subject' => '{pro} : votre bilan du mois ({m})', 'pr_pre' => '{imp} affichages, {view} visites et {clicks} clics le mois dernier.',
        'pr_title' => 'Votre mois sur le Défi Impact', 'pr_imp' => 'affichages', 'pr_reach' => 'personnes touchées',
        'pr_view' => 'visites de votre page', 'pr_clicks' => 'clics sur vos contacts', 'pr_vs' => '{d} par rapport au mois précédent.',
        'pr_top' => 'Les questions qui vous ont le plus montré', 'pr_times' => '{n} affichages',
        'pr_low' => 'Pour être vu davantage : liez votre page à plus de questions de votre domaine et publiez une expérience par mois.',
        'pr_cta' => 'Voir toutes mes statistiques',
        'unsub_tips' => 'Ne plus recevoir les conseils et paliers', 'unsub_pro' => 'Ne plus recevoir les emails de ma page pro',
    ],
    'ar' => [
        'months' => ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        'onb_label' => 'الخطوات الأولى', 'onb_subject' => '{name}، تحدّيك يبدأ من هنا',
        'onb_pre' => 'لمسة واحدة لكل سؤال: إليك كيف تستفيد من التحدي.',
        'onb_kicker' => 'مرحباً بك', 'onb_title' => '{name}، تحدّيك يبدأ من هنا.',
        'onb_sub' => 'تجارب حياة، وثلاث إجابات ممكنة. إليك كيف تستفيد منها.',
        'onb_s1' => 'أجب على راحتك', 'onb_s1p' => 'سلبي أو بلا أثر أو إيجابي: لمسة واحدة لكل سؤال. خمس دقائق تكفي للبداية.',
        'onb_s2' => 'اكتشف بصمتك', 'onb_s2p' => 'نتيجتك تتحدّث مع كل إجابة، وترى موقعك مقارنة بالعالم.',
        'onb_s3' => 'ألهِم الآخرين', 'onb_s3p' => 'احكِ تجربة إيجابية: قد تساعد شخصاً عاشها بصعوبة.',
        'onb_q' => 'سؤالك الأول', 'onb_cta' => 'واصل التحدي',
        'ver_label' => 'حسابك', 'ver_subject' => 'نقرة واحدة لتفعيل حسابك',
        'ver_pre' => 'أكّد بريدك لتصلك أسئلة اليوم ورسائلك.',
        'ver_kicker' => 'الخطوة الأخيرة', 'ver_title' => 'أكّد بريدك بنقرة واحدة.',
        'ver_p' => 'دون تأكيد، لا يمكننا أن نرسل إليك سؤال اليوم ولا التجارب الإيجابية للمجتمع، ولا أن ننبّهك عندما يراسلك أحد.',
        'ver_cta' => 'تأكيد بريدي', 'ver_ignore' => 'لم تنشئ حساباً في تحدي الأثر؟ تجاهل هذه الرسالة، لن يحدث شيء.',
        'mil_label' => 'مرحلة جديدة', 'mil_subject' => '{n} إجابة، أحسنت يا {name}!',
        'mil_pre' => 'حصيلتك: {p} من الأثر الإيجابي.', 'mil_kicker' => 'مرحلة جديدة',
        'mil_title' => '{n} إجابة! أحسنت يا {name}.', 'mil_sub' => 'استكشفت {p} من التحدي. إليك حصيلتك.',
        'mil_pos' => 'إيجابي', 'mil_none' => 'بلا أثر', 'mil_neg' => 'سلبي',
        'mil_story' => 'عشت «{q}» بشكل إيجابي. احكِ ذلك: قد تساعد قصتك شخصاً عاشها بشكل آخر.',
        'mil_cta_story' => 'احكِ هذه التجربة', 'mil_cta' => 'عرض نتيجتي',
        'cb_label' => 'ننتظرك', 'cb_subject' => '{name}، تحدّيك ما زال بانتظارك',
        'cb_pre' => 'وصلت إلى {n} سؤالاً. واصل من حيث توقفت.',
        'cb_kicker' => 'اشتقنا إليك', 'cb_title' => '{name}، احتفظنا لك بمكانك.',
        'cb_sub' => 'وصلت إلى {n} سؤالاً من {t}. واصل من حيث توقفت تماماً.',
        'cb_new' => 'قصص إيجابية جديدة', 'cb_done' => 'أسئلة أجبت عنها', 'cb_people' => 'مشاركاً',
        'cb_q' => 'سؤال للعودة', 'cb_cta' => 'واصل التحدي',
        'lk_label' => 'قصتك', 'lk_subject_1' => 'قصتك أثّرت في أول شخص',
        'lk_subject_n' => 'قصتك أثّرت في {n} أشخاص',
        'lk_pre' => 'كل إعجاب هو شخص ساعدته تجربتك.', 'lk_kicker' => 'شكراً على قصتك',
        'lk_title_1' => 'قصتك أثّرت في أول شخص.', 'lk_title_n' => 'قصتك أثّرت في {n} أشخاص.',
        'lk_p' => 'كل إعجاب هو شخص ساعدته تجربتك على رؤية الأمور بشكل مختلف. ما رأيك أن تحكي تجربة أخرى؟',
        'lk_cta' => 'احكِ تجربة أخرى',
        'pw_label' => 'الفضاء المهني', 'pw_subject' => '{pro}: صفحتك المهنية منشورة',
        'pw_pre' => 'الخطوات لتكون ظاهراً لدى الأشخاص المناسبين.', 'pw_kicker' => 'مرحباً بك',
        'pw_title' => '{pro}، صفحتك منشورة.', 'pw_sub' => 'إليك كيف تجعلها ظاهرة لدى من يحتاجون خبرتك.',
        'pw_h' => 'لصفحة توحي بالثقة', 'pw_p' => 'الصفحات المكتملة تظهر بجانب أسئلتها في التحدي وتظهر أفضل في محركات البحث. وتتابع بعدها مرات ظهورها وزياراتها والنقرات في «إحصائياتك».',
        'pw_cta' => 'أكمل صفحتي', 'pw_view' => 'عرض صفحتي العامة',
        'ck_logo' => 'أضف شعارك', 'ck_bio' => 'اكتب تقديماً (80 حرفاً على الأقل)',
        'ck_qs' => 'اربط صفحتك بثلاثة أسئلة على الأقل من مجالك', 'ck_exp' => 'انشر تجربتك الأولى',
        'ck_contact' => 'أضف رقم هاتف أو موقعاً إلكترونياً',
        'pn_label' => 'الفضاء المهني', 'pn_subject_1' => 'بقيت خطوة واحدة لصفحتك المهنية', 'pn_subject_n' => 'بقيت {n} خطوات لصفحتك المهنية',
        'pn_pre' => 'بضع دقائق لتظهر بجانب أسئلة مجالك.', 'pn_kicker' => 'صفحتك المهنية',
        'pn_title_1' => 'بقيت خطوة واحدة لصفحة مكتملة.', 'pn_title_n' => 'بقيت {n} خطوات لصفحة مكتملة.',
        'pn_p' => 'الصفحة المكتملة توحي بالثقة: تظهر بجانب أسئلتها وتُرسل تجاربها بالبريد إلى من أجابوا بـ«سلبي».',
        'pr_label' => 'التقرير الشهري', 'pr_subject' => '{pro}: حصيلة الشهر ({m})', 'pr_pre' => '{imp} مرة ظهور و{view} زيارة و{clicks} نقرة في {m}.',
        'pr_title' => 'شهرك في تحدي الأثر', 'pr_imp' => 'مرة ظهور', 'pr_reach' => 'أشخاص وصلت إليهم',
        'pr_view' => 'زيارة لصفحتك', 'pr_clicks' => 'نقرة على وسائل التواصل', 'pr_vs' => '{d} مقارنة بالشهر السابق.',
        'pr_top' => 'الأسئلة التي أظهرتك أكثر', 'pr_times' => '{n} مرة ظهور',
        'pr_low' => 'لتظهر أكثر: اربط صفحتك بأسئلة أخرى من مجالك وانشر تجربة كل شهر.',
        'pr_cta' => 'عرض كل إحصائياتي',
        'unsub_tips' => 'إيقاف النصائح والمراحل', 'unsub_pro' => 'إيقاف رسائل صفحتي المهنية',
    ],
    'en' => [
        'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'onb_label' => 'Getting started', 'onb_subject' => '{name}, your challenge starts here',
        'onb_pre' => 'One tap per question: here is how to make the most of the challenge.',
        'onb_kicker' => 'Welcome', 'onb_title' => '{name}, your challenge starts here.',
        'onb_sub' => 'Life experiences, three possible answers. Here is how to make the most of it.',
        'onb_s1' => 'Answer at your own pace', 'onb_s1p' => 'Negative, none or positive: one tap per question. Five minutes is enough to start.',
        'onb_s2' => 'Discover your footprint', 'onb_s2p' => 'Your result updates with every answer, and you see where you stand in the world.',
        'onb_s3' => 'Inspire others', 'onb_s3p' => 'Share a positive experience: it may help someone who lived it the hard way.',
        'onb_q' => 'Your first question', 'onb_cta' => 'Continue the challenge',
        'ver_label' => 'Your account', 'ver_subject' => 'One click left to activate your account',
        'ver_pre' => 'Confirm your address to receive the question of the day and your messages.',
        'ver_kicker' => 'Last step', 'ver_title' => 'Confirm your address in one click.',
        'ver_p' => 'Without confirmation we can\'t send you the question of the day or the community\'s positive experiences, nor let you know when someone writes to you.',
        'ver_cta' => 'Confirm my address', 'ver_ignore' => 'Didn\'t create an account on the Impact Challenge? Just ignore this email, nothing will happen.',
        'mil_label' => 'Milestone', 'mil_subject' => '{n} answers, well done {name}!',
        'mil_pre' => 'Your summary: {p} positive impacts.', 'mil_kicker' => 'Milestone reached',
        'mil_title' => '{n} answers! Well done {name}.', 'mil_sub' => 'You have already explored {p} of the challenge. Here is your summary.',
        'mil_pos' => 'positive', 'mil_none' => 'no impact', 'mil_neg' => 'negative',
        'mil_story' => 'You lived “{q}” positively. Tell your story: it may help someone who lived it differently.',
        'mil_cta_story' => 'Tell this experience', 'mil_cta' => 'See my result',
        'cb_label' => 'We miss you', 'cb_subject' => '{name}, your challenge is still waiting',
        'cb_pre' => 'You are at {n} questions. Pick up where you left off.',
        'cb_kicker' => 'We miss you', 'cb_title' => '{name}, we saved your seat.',
        'cb_sub' => 'You are at {n} questions out of {t}. Pick up exactly where you left off.',
        'cb_new' => 'new positive stories', 'cb_done' => 'questions answered', 'cb_people' => 'participants',
        'cb_q' => 'A question to get back in', 'cb_cta' => 'Resume the challenge',
        'lk_label' => 'Your story', 'lk_subject_1' => 'Your story touched a first person',
        'lk_subject_n' => 'Your story touched {n} people',
        'lk_pre' => 'Every like is someone your experience helped.', 'lk_kicker' => 'Thank you for your story',
        'lk_title_1' => 'Your story touched a first person.', 'lk_title_n' => 'Your story touched {n} people.',
        'lk_p' => 'Every like is someone your experience helped see things differently. Why not share another one?',
        'lk_cta' => 'Share another experience',
        'pw_label' => 'Pro space', 'pw_subject' => '{pro}: your pro page is live',
        'pw_pre' => 'The steps to be seen by the right people.', 'pw_kicker' => 'Welcome',
        'pw_title' => '{pro}, your page is live.', 'pw_sub' => 'Here is how to make it visible to the people who need your experience.',
        'pw_h' => 'For a page people trust', 'pw_p' => 'Complete pages appear next to their questions in the challenge and rank better on search engines. You then follow their impressions, visits and clicks in “Your statistics”.',
        'pw_cta' => 'Complete my page', 'pw_view' => 'See my public page',
        'ck_logo' => 'Add your logo', 'ck_bio' => 'Write an “about” (at least 80 characters)',
        'ck_qs' => 'Link your page to at least 3 questions in your field', 'ck_exp' => 'Publish your first experience',
        'ck_contact' => 'Add a phone number or a website',
        'pn_label' => 'Pro space', 'pn_subject_1' => 'One step left on your pro page', 'pn_subject_n' => '{n} steps left on your pro page',
        'pn_pre' => 'A few minutes to show up next to the questions in your field.', 'pn_kicker' => 'Your pro page',
        'pn_title_1' => 'One step left for a complete page.', 'pn_title_n' => '{n} steps left for a complete page.',
        'pn_p' => 'A complete page builds trust: it shows next to its questions and its experiences are emailed to people who answered “negative”.',
        'pr_label' => 'Monthly report', 'pr_subject' => '{pro}: your {m} report', 'pr_pre' => '{imp} impressions, {view} visits and {clicks} clicks in {m}.',
        'pr_title' => 'Your month on the Impact Challenge', 'pr_imp' => 'impressions', 'pr_reach' => 'people reached',
        'pr_view' => 'visits to your page', 'pr_clicks' => 'contact clicks', 'pr_vs' => '{d} compared with the previous month.',
        'pr_top' => 'Questions that showed you the most', 'pr_times' => '{n} impressions',
        'pr_low' => 'To be seen more: link your page to more questions in your field and publish one experience a month.',
        'pr_cta' => 'See all my statistics',
        'unsub_tips' => 'Stop tips and milestones', 'unsub_pro' => 'Stop my pro page emails',
    ],
];

function am(string $l, string $k, array $vars = []): string
{
    $s = AUTO_STR[$l][$k] ?? AUTO_STR['fr'][$k] ?? $k;
    if (is_array($s)) return '';
    foreach ($vars as $a => $b) $s = str_replace('{' . $a . '}', (string) $b, $s);
    return typo($l, $s);
}

function auto_on(string $kind): bool
{
    return isset(AUTOMATIONS[$kind]) && setting('auto_' . $kind) !== '0';
}

/** « novembre 2026 » dans la langue de l'email. */
function mail_month(string $l, int $t): string
{
    $m = AUTO_STR[$l]['months'] ?? AUTO_STR['fr']['months'];
    return $m[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
}

/** Liens de désabonnement d'un email automatique : son type (conseils ou pro) et « tout ». */
function auto_unsub(array $u, string $opt): array
{
    $l = $u['lang'];
    $out = [];
    if ($opt !== '') $out[am($l, 'unsub_' . $opt)] = unsub_url($u, $opt);
    $out[ms($l, 'unsub_all')] = unsub_url($u, 'all');
    return $out;
}

function auto_text_footer(array $u, string $opt): string
{
    $out = "\n\n--\n" . ms($u['lang'], 'footer_why') . "\n";
    foreach (auto_unsub($u, $opt) as $label => $url) $out .= $label . ' : ' . $url . "\n";
    return $out;
}

/* ------------------------------------------------------------- données */

/** Dernière visite connue d'un compte (même calcul que la liste des inscrits). */
const AUTO_LAST_SEEN_SQL = "MAX(u.created,
    COALESCE((SELECT MAX(exp) FROM sessions se WHERE se.uid = u.id) - 5184000, 0),
    COALESCE((SELECT seen FROM people pe WHERE pe.pid = 'u' || u.id), 0),
    COALESCE((SELECT seen FROM presence pr WHERE pr.pid = 'u' || u.id), 0))";

/** Réponses d'un compte : [qid => v]. */
function auto_answers(int $uid): array
{
    $s = db()->prepare('SELECT qid, v FROM answers WHERE pid = ?');
    $s->execute(['u' . $uid]);
    $out = [];
    foreach ($s as $r) $out[(int) $r['qid']] = (int) $r['v'];
    return $out;
}

/** Une question pas encore répondue, stable dans la journée pour un même compte. */
function auto_open_question(int $uid, array $answers): ?array
{
    $qs = questions();
    $open = array_values(array_diff(array_keys($qs), array_keys($answers)));
    if (!$open) return null;
    sort($open);
    return $qs[$open[abs(crc32($uid . date('Y-m-d'))) % count($open)]];
}

/** Lien vers le défi qui enregistre une réponse en un clic. */
function answer_link(string $l, array $q, string $extra = ''): callable
{
    return function (int $v) use ($l, $q, $extra): string {
        return site_url() . '?lang=' . $l . '&q=' . $q['id'] . '&a=' . $v . $extra . '#resultat/' . $q['id'];
    };
}

/** Étapes d'une page pro : [clé => fait]. */
function pro_checklist(array $p): array
{
    $s = db()->prepare('SELECT COUNT(*) FROM pro_questions WHERE pro_id = ?');
    $s->execute([$p['id']]);
    $nq = (int) $s->fetchColumn();
    $s = db()->prepare("SELECT COUNT(*) FROM stories WHERE pro_id = ? AND status = 'published'");
    $s->execute([$p['id']]);
    $ns = (int) $s->fetchColumn();
    return [
        'ck_logo' => (string) $p['logo'] !== '', 'ck_bio' => mb_strlen((string) $p['bio']) >= 80, 'ck_qs' => $nq >= 3,
        'ck_exp' => $ns >= 1, 'ck_contact' => (string) $p['phone'] !== '' || (string) $p['website'] !== '',
    ];
}

/* --------------------------------------------------------------- emails */

function compose_onboard(array $u, ?array $q): array
{
    $l = $u['lang'];
    $name = (string) $u['name'];
    $steps = [[am($l, 'onb_s1'), am($l, 'onb_s1p')], [am($l, 'onb_s2'), am($l, 'onb_s2p')], [am($l, 'onb_s3'), am($l, 'onb_s3p')]];
    $href = site_url() . '?lang=' . $l . '#defi/all';
    $inner = m_steps($l, $steps);
    if ($q) $inner .= m_h($l, am($l, 'onb_q')) . m_question($l, $q, answer_link($l, $q));
    $inner .= m_btn($l, $href, am($l, 'onb_cta'), 'ink');
    $text = am($l, 'onb_title', ['name' => $name]) . "\n\n" . am($l, 'onb_sub') . "\n\n";
    foreach ($steps as $i => $st) $text .= ($i + 1) . '. ' . $st[0] . ' — ' . $st[1] . "\n";
    if ($q) $text .= "\n" . am($l, 'onb_q') . ' : ' . qtext($q, $l) . "\n";
    $text .= "\n" . am($l, 'onb_cta') . ' : ' . $href;
    $subject = am($l, 'onb_subject', ['name' => $name]);
    return [$subject, mail_layout($l, $subject, am($l, 'onb_pre'), $inner, auto_unsub($u, 'tips'), [
        'label' => am($l, 'onb_label'), 'hero' => ['kicker' => am($l, 'onb_kicker'), 'title' => am($l, 'onb_title', ['name' => $name]), 'sub' => am($l, 'onb_sub')],
    ]), $text . auto_text_footer($u, 'tips')];
}

function compose_verify(array $u): array
{
    $l = $u['lang'];
    $href = site_url() . 'api.php?action=verify&token=' . rawurlencode((string) $u['vtoken']);
    $inner = m_p($l, ms($l, 'hello', ['name' => $u['name']])) . m_p($l, am($l, 'ver_p'))
        . m_btn($l, $href, am($l, 'ver_cta'), 'pos') . m_small($l, am($l, 'ver_ignore'), 'margin:18px 0 0');
    $text = ms($l, 'hello', ['name' => $u['name']]) . "\n\n" . am($l, 'ver_p') . "\n\n" . am($l, 'ver_cta') . ' : ' . $href . "\n\n" . am($l, 'ver_ignore');
    $subject = am($l, 'ver_subject');
    return [$subject, mail_layout($l, $subject, am($l, 'ver_pre'), $inner, [], [
        'label' => am($l, 'ver_label'), 'hero' => ['kicker' => am($l, 'ver_kicker'), 'title' => am($l, 'ver_title')],
    ]), $text];
}

/** $split = [pos, none, neg] ; $storyQ = une question vécue positivement, sans récit écrit. */
function compose_milestone(array $u, int $n, array $split, ?array $storyQ): array
{
    $l = $u['lang'];
    $name = (string) $u['name'];
    $tot = max(1, array_sum($split));
    $total = max(1, count(questions()));
    $explored = mail_pct($l, min(100, $n / $total * 100));
    $pos = mail_pct($l, $split[0] / $tot * 100);
    $inner = m_stats($l, [
        [$pos, am($l, 'mil_pos'), '#07854b'],
        [mail_pct($l, $split[1] / $tot * 100), am($l, 'mil_none'), '#5f677c'],
        [mail_pct($l, $split[2] / $tot * 100), am($l, 'mil_neg'), '#d93b3b'],
    ]);
    $text = am($l, 'mil_title', ['n' => $n, 'name' => $name]) . "\n" . am($l, 'mil_sub', ['p' => $explored]) . "\n\n"
        . $pos . ' ' . am($l, 'mil_pos') . "\n";
    if ($storyQ) {
        $href = site_url() . '?lang=' . $l . '#resultat/' . $storyQ['id'];
        $inner .= m_p($l, am($l, 'mil_story', ['q' => qtext($storyQ, $l)])) . m_btn($l, $href, am($l, 'mil_cta_story'), 'pos');
        $text .= "\n" . am($l, 'mil_story', ['q' => qtext($storyQ, $l)]) . "\n" . $href;
    } else {
        $href = site_url() . '?lang=' . $l . '#resultat';
        $inner .= m_btn($l, $href, am($l, 'mil_cta'), 'ink');
        $text .= "\n" . am($l, 'mil_cta') . ' : ' . $href;
    }
    $subject = am($l, 'mil_subject', ['n' => $n, 'name' => $name]);
    return [$subject, mail_layout($l, $subject, am($l, 'mil_pre', ['p' => $pos]), $inner, auto_unsub($u, 'tips'), [
        'label' => am($l, 'mil_label'), 'hero' => ['kicker' => am($l, 'mil_kicker'), 'title' => am($l, 'mil_title', ['n' => $n, 'name' => $name]), 'sub' => am($l, 'mil_sub', ['p' => $explored])],
    ]), $text . auto_text_footer($u, 'tips')];
}

function compose_comeback(array $u, int $answered, int $total, int $newStories, int $people, ?array $q): array
{
    $l = $u['lang'];
    $name = (string) $u['name'];
    $stats = [[mail_num($l, $answered), am($l, 'cb_done')]];
    if ($newStories > 0) $stats[] = [mail_num($l, $newStories), am($l, 'cb_new'), '#07854b'];
    if ($people >= 20) $stats[] = [mail_num($l, $people), am($l, 'cb_people')];
    $href = site_url() . '?lang=' . $l . '#defi/all';
    $inner = m_stats($l, $stats);
    if ($q) $inner .= m_h($l, am($l, 'cb_q')) . m_question($l, $q, answer_link($l, $q));
    $inner .= m_btn($l, $href, am($l, 'cb_cta'), 'ink');
    $sub = am($l, 'cb_sub', ['n' => mail_num($l, $answered), 't' => mail_num($l, $total)]);
    $text = am($l, 'cb_title', ['name' => $name]) . "\n" . $sub . "\n\n" . ($q ? am($l, 'cb_q') . ' : ' . qtext($q, $l) . "\n\n" : '') . am($l, 'cb_cta') . ' : ' . $href;
    $subject = am($l, 'cb_subject', ['name' => $name]);
    return [$subject, mail_layout($l, $subject, am($l, 'cb_pre', ['n' => mail_num($l, $answered)]), $inner, auto_unsub($u, 'tips'), [
        'label' => am($l, 'cb_label'), 'hero' => ['kicker' => am($l, 'cb_kicker'), 'title' => am($l, 'cb_title', ['name' => $name]), 'sub' => $sub],
    ]), $text . auto_text_footer($u, 'tips')];
}

function compose_liked(array $u, array $story, ?array $q, int $likes): array
{
    $l = $u['lang'];
    $one = $likes <= 1;
    $title = $one ? am($l, 'lk_title_1') : am($l, 'lk_title_n', ['n' => mail_num($l, $likes)]);
    $href = site_url() . '?lang=' . $l . '#resultat';
    $inner = m_p($l, ms($l, 'hello', ['name' => $u['name']])) . m_story($l, $story, $q) . m_p($l, am($l, 'lk_p'), 'margin:6px 0 0')
        . m_btn($l, $href, am($l, 'lk_cta'), 'pos');
    $text = ms($l, 'hello', ['name' => $u['name']]) . "\n\n" . $title . "\n\n« " . mb_strimwidth((string) $story['exp'], 0, 320, '…') . " »\n\n" . am($l, 'lk_p') . "\n" . $href;
    $subject = $one ? am($l, 'lk_subject_1') : am($l, 'lk_subject_n', ['n' => mail_num($l, $likes)]);
    return [$subject, mail_layout($l, $subject, am($l, 'lk_pre'), $inner, auto_unsub($u, 'tips'), [
        'label' => am($l, 'lk_label'), 'hero' => ['kicker' => am($l, 'lk_kicker'), 'title' => $title],
    ]), $text . auto_text_footer($u, 'tips')];
}

function compose_pro_welcome(array $u, array $p, array $check): array
{
    require_once __DIR__ . '/seo.php';
    $l = $u['lang'];
    $items = [];
    foreach ($check as $k => $done) $items[] = [$done, am($l, $k)];
    $href = site_url() . '?lang=' . $l . '#pro';
    $page = pro_url($p, $l);
    $inner = m_h($l, am($l, 'pw_h')) . m_checks($l, $items) . m_p($l, am($l, 'pw_p'), 'margin-top:8px')
        . m_btn($l, $href, am($l, 'pw_cta'), 'pro')
        . '<p style="margin:14px 0 0;font:700 14px ' . mail_font($l) . '"><a href="' . h($page) . '" style="color:#2f5bff;text-decoration:none">' . h(am($l, 'pw_view')) . ' &rarr;</a></p>';
    $text = am($l, 'pw_title', ['pro' => $p['name']]) . "\n" . am($l, 'pw_sub') . "\n\n";
    foreach ($items as [$done, $t]) $text .= ($done ? '[x] ' : '[ ] ') . $t . "\n";
    $text .= "\n" . am($l, 'pw_p') . "\n\n" . am($l, 'pw_cta') . ' : ' . $href . "\n" . am($l, 'pw_view') . ' : ' . $page;
    $subject = am($l, 'pw_subject', ['pro' => $p['name']]);
    return [$subject, mail_layout($l, $subject, am($l, 'pw_pre'), $inner, auto_unsub($u, 'pro'), [
        'label' => am($l, 'pw_label'), 'hero' => ['kicker' => am($l, 'pw_kicker'), 'title' => am($l, 'pw_title', ['pro' => $p['name']]), 'sub' => am($l, 'pw_sub')],
    ]), $text . auto_text_footer($u, 'pro')];
}

function compose_pro_nudge(array $u, array $p, array $check): array
{
    $l = $u['lang'];
    $missing = count(array_filter($check, function ($d) { return !$d; }));
    $items = [];
    foreach ($check as $k => $done) $items[] = [$done, am($l, $k)];
    $href = site_url() . '?lang=' . $l . '#pro';
    $title = $missing === 1 ? am($l, 'pn_title_1') : am($l, 'pn_title_n', ['n' => $missing]);
    $inner = m_p($l, ms($l, 'hello', ['name' => $u['name']])) . m_p($l, am($l, 'pn_p')) . m_checks($l, $items) . m_btn($l, $href, am($l, 'pw_cta'), 'pro');
    $text = ms($l, 'hello', ['name' => $u['name']]) . "\n\n" . $title . "\n" . am($l, 'pn_p') . "\n\n";
    foreach ($items as [$done, $t]) $text .= ($done ? '[x] ' : '[ ] ') . $t . "\n";
    $text .= "\n" . am($l, 'pw_cta') . ' : ' . $href;
    $subject = $missing === 1 ? am($l, 'pn_subject_1') : am($l, 'pn_subject_n', ['n' => $missing]);
    return [$subject, mail_layout($l, $subject, am($l, 'pn_pre'), $inner, auto_unsub($u, 'pro'), [
        'label' => am($l, 'pn_label'), 'hero' => ['kicker' => am($l, 'pn_kicker') . ' · ' . $p['name'], 'title' => $title],
    ]), $text . auto_text_footer($u, 'pro')];
}

/** $st = pro_stats_between() du mois écoulé, $prev = celui du mois d'avant. */
function compose_pro_report(array $u, array $p, array $st, array $prev, int $monthTs): array
{
    $l = $u['lang'];
    $m = mail_month($l, $monthTs);
    $T = $st['totals'];
    $inner = m_stats($l, [[mail_num($l, $T['imp']), am($l, 'pr_imp'), '#b25f00'], [mail_num($l, $T['reach']), am($l, 'pr_reach')]])
        . m_stats($l, [[mail_num($l, $T['view']), am($l, 'pr_view')], [mail_num($l, $T['clicks']), am($l, 'pr_clicks')]]);
    $text = am($l, 'pr_title') . ' — ' . $m . "\n\n" . mail_num($l, $T['imp']) . ' ' . am($l, 'pr_imp') . "\n" . mail_num($l, $T['reach']) . ' ' . am($l, 'pr_reach') . "\n"
        . mail_num($l, $T['view']) . ' ' . am($l, 'pr_view') . "\n" . mail_num($l, $T['clicks']) . ' ' . am($l, 'pr_clicks') . "\n";
    if ($prev['totals']['imp'] > 0) {
        $d = (int) round(($T['imp'] - $prev['totals']['imp']) / $prev['totals']['imp'] * 100);
        $vs = am($l, 'pr_vs', ['d' => ($d >= 0 ? '+' : '−') . mail_pct($l, abs($d)) . ' ' . am($l, 'pr_imp')]);
        $inner .= m_small($l, $vs, 'margin:-6px 0 12px');
        $text .= $vs . "\n";
    }
    if ($st['top']) {
        $items = [];
        foreach ($st['top'] as [$qid, $n, $qt]) $items[] = [$qt, am($l, 'pr_times', ['n' => mail_num($l, $n)])];
        $inner .= m_h($l, am($l, 'pr_top')) . m_bullets($l, $items, 'pro');
        $text .= "\n" . am($l, 'pr_top') . "\n";
        foreach ($items as [$qt, $n]) $text .= '- ' . $qt . ' (' . $n . ")\n";
    }
    if ($T['imp'] < 50) {
        $inner .= m_note($l, am($l, 'pr_low'));
        $text .= "\n" . am($l, 'pr_low') . "\n";
    }
    $href = site_url() . '?lang=' . $l . '#pro';
    $inner .= m_btn($l, $href, am($l, 'pr_cta'), 'pro');
    $text .= "\n" . am($l, 'pr_cta') . ' : ' . $href;
    $vars = ['pro' => $p['name'], 'm' => $m, 'imp' => mail_num($l, $T['imp']), 'view' => mail_num($l, $T['view']), 'clicks' => mail_num($l, $T['clicks'])];
    $subject = am($l, 'pr_subject', $vars);
    return [$subject, mail_layout($l, $subject, am($l, 'pr_pre', $vars), $inner, auto_unsub($u, 'pro'), [
        'label' => am($l, 'pr_label'), 'hero' => ['kicker' => $m, 'title' => am($l, 'pr_title'), 'sub' => (string) $p['name']],
    ]), $text . auto_text_footer($u, 'pro')];
}

/* --------------------------------------------------------------- envoi */

/** A déjà été envoyé (ou noté sans envoi) ? */
function auto_logged(string $kind, int $uid, string $ref = ''): bool
{
    $s = db()->prepare('SELECT 1 FROM auto_log WHERE kind = ? AND uid = ? AND ref = ?');
    $s->execute([$kind, $uid, $ref]);
    return (bool) $s->fetchColumn();
}

function auto_log(string $kind, int $uid, string $ref = '', bool $mailed = true): void
{
    db()->prepare('INSERT OR IGNORE INTO auto_log (kind, uid, ref, sent, mailed) VALUES (?, ?, ?, ?, ?)')->execute([$kind, $uid, $ref, time(), $mailed ? 1 : 0]);
}

/**
 * Première activation d'un scénario à paliers : ce qui est déjà atteint est noté sans envoi.
 * (Remis à zéro quand l'admin réactive le scénario.)
 */
function auto_baseline(string $kind): void
{
    if (setting('auto_base_' . $kind) !== '') return;
    $pdo = db();
    if ($kind === 'milestone') {
        foreach ($pdo->query("SELECT u.id, (SELECT COUNT(*) FROM answers a WHERE a.pid = 'u' || u.id) AS n FROM users u") as $r) {
            foreach (MILESTONES as $m) if ((int) $r['n'] >= $m) auto_log('milestone', (int) $r['id'], (string) $m, false);
        }
    }
    if ($kind === 'liked') {
        foreach ($pdo->query("SELECT id, uid, likes FROM stories WHERE uid IS NOT NULL AND likes > 0") as $r) {
            foreach (LIKE_STEPS as $m) if ((int) $r['likes'] >= $m) auto_log('liked', (int) $r['uid'], $r['id'] . ':' . $m, false);
        }
    }
    setting_set('auto_base_' . $kind, (string) time());
}

/**
 * Envoie les emails automatiques dus, dans la limite de $budget.
 * $deliver(array $user, array $mail, string $unsubType): bool envoie (ou affiche en --dry-run).
 */
function run_automations(int $budget, bool $dry, callable $deliver, callable $say): int
{
    $pdo = db();
    $now = time();
    $qs = questions();
    $total = count($qs);
    $sent = 0;
    $send = function (string $kind, array $u, array $mail, string $ref = '') use (&$sent, $dry, $deliver): void {
        $sent++;
        $opt = AUTOMATIONS[$kind][2];
        if ($deliver($u, $mail, $opt !== '' ? $opt : 'all') && !$dry) auto_log($kind, (int) $u['id'], $ref);
    };
    $users = function (string $where, array $args, int $limit) use ($pdo): array {
        $s = $pdo->prepare("SELECT u.*, (SELECT COUNT(*) FROM answers a WHERE a.pid = 'u' || u.id) AS answered, " . AUTO_LAST_SEEN_SQL . " AS last_seen
            FROM users u WHERE $where ORDER BY u.id LIMIT $limit");
        $s->execute($args);
        return array_map(function ($u) { $u['lang'] = impact_lang($u['lang']); return $u; }, $s->fetchAll());
    };
    $notLogged = function (string $kind): string { return "NOT EXISTS (SELECT 1 FROM auto_log l WHERE l.kind = '$kind' AND l.uid = u.id)"; };

    // Premiers pas : le lendemain de l'inscription.
    if (auto_on('onboard') && $sent < $budget) {
        $list = $users('u.verified = 1 AND u.opt_tips = 1 AND u.created BETWEEN ? AND ? AND ' . $notLogged('onboard'), [$now - 3 * 86400, $now - 20 * 3600], $budget * 2);
        $say('Premiers pas : ' . count($list) . ' compte(s)');
        foreach ($list as $u) {
            if ($sent >= $budget) break;
            if ((int) $u['answered'] >= 10) { if (!$dry) auto_log('onboard', (int) $u['id'], '', false); continue; }
            $send('onboard', $u, compose_onboard($u, auto_open_question((int) $u['id'], auto_answers((int) $u['id']))));
        }
    }
    // Rappel de confirmation : 2 jours après, une fois.
    if (auto_on('verify') && $sent < $budget) {
        $list = $users('u.verified = 0 AND u.vtoken IS NOT NULL AND u.created BETWEEN ? AND ? AND ' . $notLogged('verify'), [$now - 7 * 86400, $now - 40 * 3600], $budget);
        $say('Rappel de confirmation : ' . count($list) . ' compte(s)');
        foreach ($list as $u) {
            if ($sent >= $budget) break;
            $send('verify', $u, compose_verify($u));
        }
    }
    // Paliers de réponses.
    if (auto_on('milestone') && $sent < $budget) {
        if (!$dry) auto_baseline('milestone');
        $list = $users("u.verified = 1 AND u.opt_tips = 1 AND (SELECT COUNT(*) FROM answers a WHERE a.pid = 'u' || u.id) >= " . MILESTONES[0], [], 500);
        $due = 0;
        foreach ($list as $u) {
            if ($sent >= $budget) break;
            $reached = array_values(array_filter(MILESTONES, function ($m) use ($u) { return (int) $u['answered'] >= $m; }));
            $top = end($reached);
            if (auto_logged('milestone', (int) $u['id'], (string) $top)) continue;
            $due++;
            $answers = auto_answers((int) $u['id']);
            $split = [0, 0, 0];
            foreach ($answers as $v) $split[$v === 1 ? 0 : ($v === 0 ? 1 : 2)]++;
            $w = $pdo->prepare('SELECT qid FROM stories WHERE uid = ?');
            $w->execute([$u['id']]);
            $cands = array_values(array_intersect(array_keys(array_filter($answers, function ($v) { return $v === 1; })), array_diff(array_keys($qs), array_map('intval', $w->fetchAll(PDO::FETCH_COLUMN)))));
            $storyQ = $cands ? $qs[$cands[abs(crc32((string) $u['id'])) % count($cands)]] : null;
            if (!$dry) foreach ($reached as $m) if ($m !== $top) auto_log('milestone', (int) $u['id'], (string) $m, false);
            $send('milestone', $u, compose_milestone($u, (int) $top, $split, $storyQ), (string) $top);
        }
        $say("Paliers : $due compte(s)");
    }
    // Relance après 21 jours sans visite (au plus tous les 60 jours).
    if (auto_on('comeback') && $sent < $budget) {
        $list = $users("u.verified = 1 AND u.opt_tips = 1 AND u.created < ? AND " . AUTO_LAST_SEEN_SQL . " BETWEEN CAST(? AS INTEGER) AND CAST(? AS INTEGER)
            AND NOT EXISTS (SELECT 1 FROM auto_log l WHERE l.kind = 'comeback' AND l.uid = u.id AND l.sent > ?)", [$now - 21 * 86400, $now - 180 * 86400, $now - 21 * 86400, $now - 60 * 86400], $budget * 2);
        $say('Relance des absents : ' . count($list) . ' compte(s)');
        $people = (int) $pdo->query('SELECT COUNT(DISTINCT pid) FROM answers')->fetchColumn();
        $newQ = $pdo->prepare("SELECT COUNT(*) FROM stories WHERE status = 'published' AND published > ?");
        foreach ($list as $u) {
            if ($sent >= $budget) break;
            $answers = auto_answers((int) $u['id']);
            if (count($answers) >= $total) { if (!$dry) auto_log('comeback', (int) $u['id'], date('Y-m-d'), false); continue; }
            $newQ->execute([(int) $u['last_seen']]);
            $send('comeback', $u, compose_comeback($u, count($answers), $total, (int) $newQ->fetchColumn(), $people, auto_open_question((int) $u['id'], $answers)), date('Y-m-d'));
        }
    }
    // Récit apprécié : 1, 5, 10, 25, 50 « j'aime ».
    if (auto_on('liked') && $sent < $budget) {
        if (!$dry) auto_baseline('liked');
        $s = $pdo->query("SELECT s.*, u.id AS uid FROM stories s JOIN users u ON u.id = s.uid
            WHERE s.status = 'published' AND s.likes >= 1 AND u.verified = 1 AND u.opt_tips = 1 ORDER BY s.likes DESC LIMIT 500");
        $byUser = [];
        foreach ($s as $st) {
            $step = 0;
            foreach (LIKE_STEPS as $m) if ((int) $st['likes'] >= $m) $step = $m;
            if (auto_logged('liked', (int) $st['uid'], $st['id'] . ':' . $step) || isset($byUser[(int) $st['uid']])) continue;
            $byUser[(int) $st['uid']] = [$st, $step];
        }
        $recent = $pdo->prepare("SELECT 1 FROM auto_log WHERE kind = 'liked' AND uid = ? AND mailed = 1 AND sent > ?");
        $userQ = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $say('Récits appréciés : ' . count($byUser) . ' compte(s)');
        foreach ($byUser as $uid => [$st, $step]) {
            if ($sent >= $budget) break;
            $recent->execute([$uid, $now - 86400]);
            if ($recent->fetchColumn()) continue;
            $userQ->execute([$uid]);
            $u = $userQ->fetch();
            $u['lang'] = impact_lang($u['lang']);
            if (!$dry) foreach (LIKE_STEPS as $m) if ($m < $step) auto_log('liked', $uid, $st['id'] . ':' . $m, false);
            $send('liked', $u, compose_liked($u, $st, $qs[(int) $st['qid']] ?? null, (int) $st['likes']), $st['id'] . ':' . $step);
        }
    }
    // Pages pro : bienvenue, page incomplète, rapport mensuel.
    $proOwners = function (string $where, array $args) use ($pdo): array {
        $s = $pdo->prepare("SELECT p.*, u.id AS owner_id, u.email AS owner_email, u.name AS owner_name, u.lang AS owner_lang, u.utoken AS owner_utoken
            FROM pros p JOIN users u ON u.id = p.uid WHERE p.status = 'active' AND u.opt_pro = 1 AND $where ORDER BY p.id LIMIT 500");
        $s->execute($args);
        return $s->fetchAll();
    };
    $owner = function (array $p): array {
        return ['id' => (int) $p['owner_id'], 'email' => (string) $p['owner_email'], 'name' => (string) $p['owner_name'],
            'lang' => impact_lang((string) $p['owner_lang']), 'utoken' => (string) $p['owner_utoken']];
    };
    if (auto_on('pro_welcome') && $sent < $budget) {
        $list = $proOwners("p.created > ? AND NOT EXISTS (SELECT 1 FROM auto_log l WHERE l.kind = 'pro_welcome' AND l.uid = u.id AND l.ref = 'p' || p.id)", [$now - 2 * 86400]);
        $say('Bienvenue pro : ' . count($list) . ' page(s)');
        foreach ($list as $p) {
            if ($sent >= $budget) break;
            $send('pro_welcome', $owner($p), compose_pro_welcome($owner($p), $p, pro_checklist($p)), 'p' . $p['id']);
        }
    }
    if (auto_on('pro_nudge') && $sent < $budget) {
        $list = $proOwners("p.created BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM auto_log l WHERE l.kind = 'pro_nudge' AND l.uid = u.id AND l.ref = 'p' || p.id)", [$now - 14 * 86400, $now - 3 * 86400]);
        $due = 0;
        foreach ($list as $p) {
            if ($sent >= $budget) break;
            $check = pro_checklist($p);
            if (!in_array(false, $check, true)) { if (!$dry) auto_log('pro_nudge', (int) $p['owner_id'], 'p' . $p['id'], false); continue; }
            $due++;
            $send('pro_nudge', $owner($p), compose_pro_nudge($owner($p), $p, $check), 'p' . $p['id']);
        }
        $say("Pages pro incomplètes : $due");
    }
    if (auto_on('pro_report') && $sent < $budget && (int) date('j') <= 3 && (int) date('G') >= 8) {
        $first = strtotime(date('Y-m-01 00:00:00'));
        $prevStart = strtotime('-1 month', $first);
        $prev2 = strtotime('-2 months', $first);
        $ref = date('Y-m', $prevStart);
        $list = $proOwners("p.created < ? AND NOT EXISTS (SELECT 1 FROM auto_log l WHERE l.kind = 'pro_report' AND l.uid = u.id AND l.ref = ? || ':' || p.id)", [$first, $ref]);
        $say('Rapports mensuels (' . $ref . ') : ' . count($list) . ' page(s)');
        foreach ($list as $p) {
            if ($sent >= $budget) break;
            $u = $owner($p);
            $st = pro_stats_between((int) $p['id'], date('Y-m-d', $prevStart), date('Y-m-d', $first - 1), $u['lang']);
            $pv = pro_stats_between((int) $p['id'], date('Y-m-d', $prev2), date('Y-m-d', $prevStart - 1), $u['lang']);
            $send('pro_report', $u, compose_pro_report($u, $p, $st, $pv, $prevStart), $ref . ':' . $p['id']);
        }
    }
    return $sent;
}

/* --------------------------------------------------------------- aperçus (admin) */

/** Exemple d'un email automatique avec de vraies données si possible (aperçu et envoi de test). */
function auto_sample(string $kind, string $l, string $email = 'vous@example.com'): array
{
    $pdo = db();
    $qs = questions();
    $q = $qs ? $qs[array_keys($qs)[abs(crc32(date('Y-m-d'))) % count($qs)]] : null;
    $u = ['id' => 0, 'email' => $email, 'name' => ['fr' => 'Amina', 'ar' => 'أمينة', 'en' => 'Amina'][$l], 'lang' => $l,
        'utoken' => str_repeat('0', 48), 'vtoken' => str_repeat('0', 48)];
    $pros = function_exists('pros_active') ? array_values(pros_active()) : [];
    $p = $pros[0] ?? ['id' => 0, 'name' => ['fr' => 'Cabinet Nour', 'ar' => 'عيادة نور', 'en' => 'Nour Practice'][$l], 'slug' => 'exemple', 'logo' => '', 'bio' => '', 'phone' => '0550 00 00 00', 'website' => ''];
    $story = $pdo->query("SELECT * FROM stories WHERE status = 'published' ORDER BY likes DESC, id DESC LIMIT 1")->fetch()
        ?: ['qid' => $q['id'] ?? 0, 'exp' => '(exemple) Le récit d\'un participant apparaîtra ici.', 'imp' => '(exemple) Et ce que cette expérience lui a apporté.', 'likes' => 5];
    switch ($kind) {
        case 'onboard': return compose_onboard($u, $q);
        case 'verify': return compose_verify($u);
        case 'milestone': return compose_milestone($u, 250, [148, 61, 41], $q);
        case 'comeback': return compose_comeback($u, 137, count($qs), 12, max(20, (int) $pdo->query('SELECT COUNT(DISTINCT pid) FROM answers')->fetchColumn()), $q);
        case 'liked': return compose_liked($u, $story, $qs[(int) $story['qid']] ?? $q, 5);
        case 'pro_welcome': return compose_pro_welcome($u, $p, $p['id'] ? pro_checklist($p) : ['ck_logo' => true, 'ck_bio' => false, 'ck_qs' => false, 'ck_exp' => false, 'ck_contact' => true]);
        case 'pro_nudge':
            $c = $p['id'] ? pro_checklist($p) : [];
            if (!in_array(false, $c, true)) $c = ['ck_logo' => true, 'ck_bio' => true, 'ck_qs' => false, 'ck_exp' => false, 'ck_contact' => true];
            return compose_pro_nudge($u, $p, $c);
        case 'pro_report':
            $first = strtotime(date('Y-m-01 00:00:00'));
            $prevStart = strtotime('-1 month', $first);
            if ($p['id']) {
                $st = pro_stats_between((int) $p['id'], date('Y-m-d', $prevStart), date('Y-m-d', $first - 1), $l);
                $pv = pro_stats_between((int) $p['id'], date('Y-m-d', strtotime('-2 months', $first)), date('Y-m-d', $prevStart - 1), $l);
            }
            if (empty($st['totals']['imp'])) {
                $st = ['totals' => ['imp' => 1240, 'reach' => 486, 'view' => 92, 'clicks' => 17], 'top' => array_map(function ($x) use ($l) { return [$x['id'], 120, qtext($x, $l)]; }, array_slice(array_values($qs), 0, 3))];
                $pv = ['totals' => ['imp' => 980, 'reach' => 0, 'view' => 0, 'clicks' => 0], 'top' => []];
            }
            return compose_pro_report($u, $p, $st, $pv, $prevStart);
    }
    throw new InvalidArgumentException('unknown');
}
