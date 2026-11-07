<?php
/**
 * Campagnes d'invitation du défi Impact (onglet « Emails » de l'admin, envoi progressif par cron.php).
 *
 * Deux modèles, en français, arabe et anglais :
 *   - particulier : découvrir le défi (la question du jour à essayer en un clic, les chiffres du site,
 *     une expérience positive partagée) ;
 *   - pro : créer sa page professionnelle, décliné pour les 10 domaines principaux (accroche propre au
 *     domaine, vraies questions du domaine et nombre de réponses « négatif » qu'elles ont reçues).
 *
 * Chaque destinataire a un jeton : ses liens passent par api.php?action=go (clic noté, puis le site),
 * et son lien « ne plus recevoir d'invitation » l'ajoute à email_optout (jamais recontacté). Sont
 * ignorés à la création : les adresses refusées, déjà inscrites (déjà pro pour le modèle pro) ou déjà
 * invitées depuis moins de 90 jours. L'envoi suit une limite par heure (réglage « inv_per_hour »).
 */
declare(strict_types=1);

const CAMPAIGN_DOMAINS = ['psy', 'sante', 'coaching', 'education', 'emploi', 'entreprise', 'finance', 'immobilier', 'famille', 'juridique'];
const CAMPAIGN_MAX = 2000;
const CAMPAIGN_REINVITE_DAYS = 90;

const CAMP_STR = [
    'fr' => [
        'hello' => 'Bonjour {name},', 'hello0' => 'Bonjour,',
        'p_label' => 'Invitation', 'p_subject' => 'Votre vie en 1000 expériences : découvrez leur impact sur vous',
        'p_pre' => 'Négatif, aucun ou positif : découvrez votre empreinte en quelques minutes, gratuitement.',
        'p_kicker' => 'Le Défi Impact', 'p_title' => 'Votre vie en 1000 expériences. Quel impact ont-elles eu sur vous ?',
        'p_sub' => 'Un défi gratuit pour faire le point, question après question, et découvrir comment les autres ont vécu les mêmes épreuves.',
        'p_intro' => 'Pour chaque expérience de vie (un déménagement, un échec, une rencontre…), vous dites si elle a eu sur vous un impact négatif, aucun impact ou un impact positif. À la fin, vous découvrez votre empreinte.',
        'p_qlabel' => 'Essayez maintenant · question du jour',
        'p_how' => 'Comment ça marche',
        'p_s1' => 'Répondez', 'p_s1p' => 'Une touche par question, à votre rythme, sans inscription.',
        'p_s2' => 'Découvrez votre empreinte', 'p_s2p' => 'Votre part d\'impacts positifs, et où vous vous situez par rapport au monde.',
        'p_s3' => 'Trouvez du positif', 'p_s3p' => 'Là où une expérience vous a fait souffrir, lisez comment d\'autres l\'ont transformée en force.',
        'p_people' => 'participants', 'p_answers' => 'réponses', 'p_stories' => 'expériences positives partagées',
        'p_story_h' => 'Ils l\'ont vécue positivement', 'p_cta' => 'Relever le défi gratuitement',
        'p_free' => 'Gratuit, sans publicité, en français, en arabe et en anglais.',
        'pro_label' => 'Invitation pro', 'pro_kicker' => 'Pour les professionnels · {domain}',
        'pro_q_h' => 'Ce que vivent les gens dans votre domaine', 'pro_q_neg' => '{p} ont répondu « négatif »',
        'pro_neg_total' => '{n} réponses « négatif » sur les questions de votre domaine : autant de personnes qui cherchent une autre manière de vivre ces expériences.',
        'pro_why_h' => 'Ce que vous offre une page pro, gratuitement',
        'pro_b1' => 'Une page professionnelle', 'pro_b1p' => 'logo, présentation, WhatsApp, appel et site web, référencée sur Google.',
        'pro_b2' => 'Visible au bon moment', 'pro_b2p' => 'à côté des questions de votre domaine, quand les gens y répondent.',
        'pro_b3' => 'Vos expériences partagées', 'pro_b3p' => 'envoyées par email à ceux qui ont répondu « négatif ».',
        'pro_b4' => 'Des statistiques claires', 'pro_b4p' => 'affichages, visites et clics, avec un rapport chaque mois.',
        'pro_how' => 'En trois minutes',
        'pro_s1' => 'Créez votre compte', 'pro_s1p' => 'gratuit, en une minute.',
        'pro_s2' => 'Décrivez votre activité', 'pro_s2p' => 'le site vous propose les questions de votre domaine.',
        'pro_s3' => 'Partagez une expérience', 'pro_s3p' => 'elle s\'affiche sur les questions et part dans les emails.',
        'pro_cta' => 'Créer ma page pro gratuite', 'pro_ps' => 'Une question ? Répondez simplement à cet email.',
        'why' => 'Vous recevez cette invitation unique de la part de {sign}. Sans suite de votre part, vous ne serez pas recontacté.',
        'unsub' => 'Ne plus jamais recevoir d\'invitation',
        'u_title' => 'Invitations', 'u_q' => 'Ne plus jamais recevoir d\'invitation du Défi Impact à l\'adresse {email} ?',
        'u_ok' => 'Confirmer', 'u_done' => 'C\'est noté : cette adresse ne recevra plus jamais d\'invitation.', 'u_bad' => 'Ce lien n\'est plus valide.',
        'back' => 'Découvrir le défi',
    ],
    'ar' => [
        'hello' => 'مرحباً {name}،', 'hello0' => 'مرحباً،',
        'p_label' => 'دعوة', 'p_subject' => 'حياتك في 1000 تجربة: اكتشف أثرها عليك',
        'p_pre' => 'سلبي أو بلا أثر أو إيجابي: اكتشف بصمتك في دقائق، مجاناً.',
        'p_kicker' => 'تحدي الأثر', 'p_title' => 'حياتك في 1000 تجربة. ما الأثر الذي تركته فيك؟',
        'p_sub' => 'تحدٍّ مجاني لتقف مع نفسك، سؤالاً بعد سؤال، وتكتشف كيف عاش الآخرون المحن نفسها.',
        'p_intro' => 'لكل تجربة حياة (انتقال، فشل، لقاء…) تقول إن كان أثرها عليك سلبياً أو بلا أثر أو إيجابياً. وفي النهاية تكتشف بصمتك.',
        'p_qlabel' => 'جرّب الآن · سؤال اليوم',
        'p_how' => 'كيف يعمل',
        'p_s1' => 'أجب', 'p_s1p' => 'لمسة واحدة لكل سؤال، على راحتك، دون تسجيل.',
        'p_s2' => 'اكتشف بصمتك', 'p_s2p' => 'نسبة الأثر الإيجابي في حياتك، وموقعك مقارنة بالعالم.',
        'p_s3' => 'اعثر على الإيجابي', 'p_s3p' => 'حيث آلمتك تجربة، اقرأ كيف حوّلها آخرون إلى قوة.',
        'p_people' => 'مشاركاً', 'p_answers' => 'إجابة', 'p_stories' => 'تجربة إيجابية مشتركة',
        'p_story_h' => 'عاشوها بشكل إيجابي', 'p_cta' => 'خض التحدي مجاناً',
        'p_free' => 'مجاني، دون إشهار، بالعربية والفرنسية والإنجليزية.',
        'pro_label' => 'دعوة للمهنيين', 'pro_kicker' => 'للمهنيين · {domain}',
        'pro_q_h' => 'ما يعيشه الناس في مجالك', 'pro_q_neg' => '{p} أجابوا بـ«سلبي»',
        'pro_neg_total' => '{n} إجابة «سلبي» على أسئلة مجالك: أشخاص كثيرون يبحثون عن طريقة أخرى لعيش هذه التجارب.',
        'pro_why_h' => 'ما تقدّمه لك الصفحة المهنية، مجاناً',
        'pro_b1' => 'صفحة مهنية', 'pro_b1p' => 'شعار وتقديم وواتساب واتصال وموقع، تظهر في محركات البحث.',
        'pro_b2' => 'ظاهر في الوقت المناسب', 'pro_b2p' => 'بجانب أسئلة مجالك، حين يجيب الناس عنها.',
        'pro_b3' => 'تجاربك تصل إليهم', 'pro_b3p' => 'تُرسل بالبريد إلى من أجابوا بـ«سلبي».',
        'pro_b4' => 'إحصائيات واضحة', 'pro_b4p' => 'مرات الظهور والزيارات والنقرات، مع تقرير كل شهر.',
        'pro_how' => 'في ثلاث دقائق',
        'pro_s1' => 'أنشئ حسابك', 'pro_s1p' => 'مجاناً، في دقيقة.',
        'pro_s2' => 'صِف نشاطك', 'pro_s2p' => 'يقترح عليك الموقع أسئلة مجالك.',
        'pro_s3' => 'شارك تجربة', 'pro_s3p' => 'تظهر في الأسئلة وتُرسل في الرسائل.',
        'pro_cta' => 'أنشئ صفحتي المهنية مجاناً', 'pro_ps' => 'لديك سؤال؟ ردّ ببساطة على هذه الرسالة.',
        'why' => 'تصلك هذه الدعوة مرة واحدة من {sign}. إن لم تتابع، لن نتواصل معك مجدداً.',
        'unsub' => 'لا أريد أي دعوة بعد الآن',
        'u_title' => 'الدعوات', 'u_q' => 'إيقاف كل دعوات تحدي الأثر إلى العنوان {email} نهائياً؟',
        'u_ok' => 'تأكيد', 'u_done' => 'تم: لن يصل هذا العنوان أي دعوة بعد الآن.', 'u_bad' => 'هذا الرابط لم يعد صالحاً.',
        'back' => 'اكتشف التحدي',
    ],
    'en' => [
        'hello' => 'Hi {name},', 'hello0' => 'Hi,',
        'p_label' => 'Invitation', 'p_subject' => 'Your life in 1000 experiences: discover their impact on you',
        'p_pre' => 'Negative, none or positive: discover your footprint in a few minutes, for free.',
        'p_kicker' => 'The Impact Challenge', 'p_title' => 'Your life in 1000 experiences. What impact did they have on you?',
        'p_sub' => 'A free challenge to take stock, question after question, and see how others lived the same trials.',
        'p_intro' => 'For each life experience (a move, a failure, a meeting…), you say whether it had a negative, no or positive impact on you. At the end, you discover your footprint.',
        'p_qlabel' => 'Try it now · question of the day',
        'p_how' => 'How it works',
        'p_s1' => 'Answer', 'p_s1p' => 'One tap per question, at your own pace, no sign-up needed.',
        'p_s2' => 'Discover your footprint', 'p_s2p' => 'Your share of positive impacts, and where you stand in the world.',
        'p_s3' => 'Find the positive', 'p_s3p' => 'Where an experience hurt you, read how others turned it into strength.',
        'p_people' => 'participants', 'p_answers' => 'answers', 'p_stories' => 'positive experiences shared',
        'p_story_h' => 'They lived it positively', 'p_cta' => 'Take the challenge for free',
        'p_free' => 'Free, ad-free, in English, Arabic and French.',
        'pro_label' => 'Pro invitation', 'pro_kicker' => 'For professionals · {domain}',
        'pro_q_h' => 'What people live through in your field', 'pro_q_neg' => '{p} answered “negative”',
        'pro_neg_total' => '{n} “negative” answers to the questions in your field: that many people looking for another way to live these experiences.',
        'pro_why_h' => 'What a pro page gives you, for free',
        'pro_b1' => 'A professional page', 'pro_b1p' => 'logo, about, WhatsApp, call and website, indexed by search engines.',
        'pro_b2' => 'Seen at the right moment', 'pro_b2p' => 'next to the questions in your field, as people answer them.',
        'pro_b3' => 'Your experiences shared', 'pro_b3p' => 'emailed to people who answered “negative”.',
        'pro_b4' => 'Clear statistics', 'pro_b4p' => 'impressions, visits and clicks, with a report every month.',
        'pro_how' => 'In three minutes',
        'pro_s1' => 'Create your account', 'pro_s1p' => 'free, in one minute.',
        'pro_s2' => 'Describe your business', 'pro_s2p' => 'the site suggests the questions in your field.',
        'pro_s3' => 'Share an experience', 'pro_s3p' => 'it shows on the questions and goes out in the emails.',
        'pro_cta' => 'Create my free pro page', 'pro_ps' => 'Any question? Simply reply to this email.',
        'why' => 'You are receiving this one-time invitation from {sign}. If you don\'t follow up, you won\'t be contacted again.',
        'unsub' => 'Never receive invitations again',
        'u_title' => 'Invitations', 'u_q' => 'Stop all Impact Challenge invitations to {email} for good?',
        'u_ok' => 'Confirm', 'u_done' => 'Done: this address will never receive an invitation again.', 'u_bad' => 'This link is no longer valid.',
        'back' => 'Discover the challenge',
    ],
];

/** Accroche de chaque domaine : [objet, aperçu, titre, paragraphe]. */
const CAMP_DOMAIN = [
    'psy' => [
        'fr' => ['Deuil, rupture, solitude : des personnes cherchent votre regard', 'Créez votre page pro gratuite et soyez visible là où elles s\'interrogent.', 'Votre expérience de psychologue peut changer leur regard.', 'Sur le Défi Impact, des personnes disent, expérience après expérience, ce qui les a marquées. Quand un deuil, une rupture ou une période de solitude a été vécu « négativement », elles cherchent un autre regard. Le vôtre peut faire la différence.'],
        'ar' => ['حزن، انفصال، وحدة: أشخاص يبحثون عن نظرتك', 'أنشئ صفحتك المهنية مجاناً وكن ظاهراً حيث يتساءلون.', 'خبرتك كأخصائي نفسي قد تغيّر نظرتهم.', 'في تحدي الأثر يقول الناس، تجربة بعد تجربة، ما الذي ترك فيهم أثراً. وحين يُعاش فقدان أو انفصال أو فترة وحدة بشكل «سلبي»، يبحثون عن نظرة أخرى. نظرتك قد تصنع الفرق.'],
        'en' => ['Grief, breakups, loneliness: people are looking for your perspective', 'Create your free pro page and be seen where they wonder.', 'Your experience as a therapist can change how they see it.', 'On the Impact Challenge, people say, experience after experience, what marked them. When grief, a breakup or a lonely period was lived “negatively”, they look for another perspective. Yours can make the difference.'],
    ],
    'sante' => [
        'fr' => ['Professionnels de santé : soyez là où vos futurs patients s\'interrogent', 'Une page pro gratuite, visible à côté des questions de santé.', 'La santé fait partie des expériences les plus difficiles à vivre.', 'Maladie, hospitalisation, sommeil, alimentation : sur le Défi Impact, ces questions recueillent beaucoup de réponses « négatif ». Partagez votre expérience de soignant et devenez une référence rassurante pour ceux qui traversent ces épreuves.'],
        'ar' => ['مهنيو الصحة: كونوا حيث يتساءل مرضاكم القادمون', 'صفحة مهنية مجانية تظهر بجانب أسئلة الصحة.', 'الصحة من أصعب التجارب التي نعيشها.', 'المرض، دخول المستشفى، النوم، التغذية: في تحدي الأثر تتلقى هذه الأسئلة إجابات «سلبي» كثيرة. شارك خبرتك كمعالج وكن مرجعاً مطمئناً لمن يمرّون بهذه المحن.'],
        'en' => ['Health professionals: be where your future patients have questions', 'A free pro page, shown next to health questions.', 'Health is among the hardest experiences to go through.', 'Illness, hospital stays, sleep, diet: on the Impact Challenge these questions get many “negative” answers. Share your experience as a caregiver and become a reassuring reference for people going through them.'],
    ],
    'coaching' => [
        'fr' => ['Coachs : des personnes veulent transformer leurs échecs en force', 'Votre page pro gratuite, à côté des questions de confiance, d\'échec et de changement.', 'Échec, peur, confiance : ils cherchent ce que vous savez transmettre.', 'Sur le Défi Impact, chacun fait le bilan de ses expériences. Les échecs, les peurs et les grands changements y sont souvent vécus « négativement » : exactement là où un coach aide à voir autrement.'],
        'ar' => ['المدربون: أشخاص يريدون تحويل إخفاقاتهم إلى قوة', 'صفحتك المهنية المجانية بجانب أسئلة الثقة والفشل والتغيير.', 'الفشل، الخوف، الثقة: يبحثون عمّا تعرف كيف تنقله.', 'في تحدي الأثر يقف كل شخص على حصيلة تجاربه. والإخفاقات والمخاوف والتغييرات الكبرى تُعاش فيه غالباً بشكل «سلبي»: هنا بالضبط يساعد المدرب على الرؤية بشكل آخر.'],
        'en' => ['Coaches: people want to turn their failures into strength', 'Your free pro page, next to questions about confidence, failure and change.', 'Failure, fear, confidence: they want what you know how to pass on.', 'On the Impact Challenge, everyone takes stock of their experiences. Failures, fears and big changes are often lived “negatively” there: exactly where a coach helps people see things differently.'],
    ],
    'education' => [
        'fr' => ['Écoles et formateurs : rencontrez ceux qui veulent apprendre de leur parcours', 'Votre page pro gratuite, visible à côté des questions sur les études.', 'Examens, orientation, langues : les études marquent une vie.', 'Sur le Défi Impact, élèves, étudiants et parents racontent comment leurs études les ont marqués. Montrez comment votre établissement ou votre formation transforme ces expériences en réussites.'],
        'ar' => ['المدارس والمكوّنون: التقوا بمن يريدون التعلم من مسارهم', 'صفحتك المهنية المجانية بجانب أسئلة الدراسة.', 'الامتحانات، التوجيه، اللغات: الدراسة تطبع حياة كاملة.', 'في تحدي الأثر يحكي التلاميذ والطلاب والأولياء كيف أثّرت فيهم دراستهم. أظهر كيف تحوّل مؤسستك أو تكوينك هذه التجارب إلى نجاحات.'],
        'en' => ['Schools and trainers: meet people who want to learn from their journey', 'Your free pro page, shown next to questions about studies.', 'Exams, career choices, languages: studies shape a life.', 'On the Impact Challenge, pupils, students and parents tell how their studies marked them. Show how your school or your training turns these experiences into success.'],
    ],
    'emploi' => [
        'fr' => ['RH et recruteurs : chômage, entretiens, carrière… ils en parlent ici', 'Une page pro gratuite à côté des questions sur le travail.', 'Le travail est l\'un des univers les plus partagés du défi.', 'Chômage, premier emploi, licenciement, promotion : sur le Défi Impact, les expériences professionnelles reçoivent des milliers de réponses. Soyez présent quand candidats et salariés font le point sur leur carrière.'],
        'ar' => ['الموارد البشرية والتوظيف: البطالة، المقابلات، المسار المهني… يتحدثون عنها هنا', 'صفحة مهنية مجانية بجانب أسئلة العمل.', 'العمل من أكثر عوالم التحدي مشاركة.', 'البطالة، أول وظيفة، الفصل، الترقية: في تحدي الأثر تتلقى التجارب المهنية آلاف الإجابات. كن حاضراً حين يقف المترشحون والموظفون على حصيلة مسارهم.'],
        'en' => ['HR and recruiters: unemployment, interviews, careers… they talk about it here', 'A free pro page next to the questions about work.', 'Work is one of the most shared worlds of the challenge.', 'Unemployment, first job, layoffs, promotions: on the Impact Challenge, work experiences get thousands of answers. Be there when candidates and employees take stock of their careers.'],
    ],
    'entreprise' => [
        'fr' => ['Entrepreneurs et consultants : inspirez ceux qui se lancent', 'Votre page pro gratuite, à côté des questions sur l\'entrepreneuriat.', 'Se lancer, échouer, recommencer : votre expérience vaut de l\'or.', 'Lancer un projet, perdre un client, s\'associer : sur le Défi Impact, de futurs entrepreneurs font le bilan de ces expériences. Partagez la vôtre et faites connaître votre accompagnement.'],
        'ar' => ['رواد الأعمال والمستشارون: ألهموا من ينطلقون', 'صفحتك المهنية المجانية بجانب أسئلة ريادة الأعمال.', 'الانطلاق، الفشل، البدء من جديد: خبرتك لا تقدّر بثمن.', 'إطلاق مشروع، خسارة زبون، الشراكة: في تحدي الأثر يقف رواد أعمال المستقبل على حصيلة هذه التجارب. شارك تجربتك وعرّف بمرافقتك.'],
        'en' => ['Entrepreneurs and consultants: inspire those who are starting out', 'Your free pro page, next to the questions about entrepreneurship.', 'Starting, failing, starting again: your experience is worth gold.', 'Launching a project, losing a client, taking a partner: on the Impact Challenge, future entrepreneurs take stock of these experiences. Share yours and make your support known.'],
    ],
    'finance' => [
        'fr' => ['Banques, assurances, conseillers : parlez d\'argent sans tabou', 'Une page pro gratuite à côté des questions sur l\'argent et l\'épargne.', 'Dettes, épargne, premier investissement : des sujets qui pèsent lourd.', 'Sur le Défi Impact, les questions d\'argent font partie de celles qu\'on vit le plus « négativement ». Votre expérience peut aider à mieux les vivre, et faire connaître vos services de façon utile.'],
        'ar' => ['البنوك والتأمين والمستشارون: تحدثوا عن المال دون حرج', 'صفحة مهنية مجانية بجانب أسئلة المال والادخار.', 'الديون، الادخار، أول استثمار: مواضيع ثقيلة.', 'في تحدي الأثر، أسئلة المال من أكثر ما يُعاش بشكل «سلبي». خبرتك قد تساعد على عيشها بشكل أفضل، وتعرّف بخدماتك بطريقة مفيدة.'],
        'en' => ['Banks, insurers, advisers: talk about money without taboo', 'A free pro page next to the questions about money and savings.', 'Debt, savings, a first investment: heavy topics.', 'On the Impact Challenge, money questions are among those lived most “negatively”. Your experience can help people live them better, and make your services known in a useful way.'],
    ],
    'immobilier' => [
        'fr' => ['Agences immobilières : soyez là quand vos futurs clients déménagent', 'Une page pro gratuite à côté des questions sur le logement.', 'Déménager, acheter, construire : des moments de vie qui marquent.', 'Sur le Défi Impact, de nombreuses personnes racontent comment un déménagement ou l\'achat d\'un logement les a marquées. Soyez présent à ce moment précis, avec votre expérience et vos contacts.'],
        'ar' => ['الوكالات العقارية: كونوا حاضرين حين ينتقل زبائنكم القادمون', 'صفحة مهنية مجانية بجانب أسئلة السكن.', 'الانتقال، الشراء، البناء: لحظات حياة لا تُنسى.', 'في تحدي الأثر يحكي كثيرون كيف أثّر فيهم انتقال أو شراء مسكن. كن حاضراً في تلك اللحظة بالضبط، بخبرتك ووسائل التواصل معك.'],
        'en' => ['Real estate agencies: be there when your future clients move', 'A free pro page next to the questions about housing.', 'Moving, buying, building: life moments that leave a mark.', 'On the Impact Challenge, many people tell how a move or buying a home marked them. Be there at that exact moment, with your experience and your contact details.'],
    ],
    'famille' => [
        'fr' => ['Mariage, naissance, parentalité : accompagnez les familles', 'Une page pro gratuite à côté des questions sur la famille.', 'Les grandes étapes de la famille, racontées par ceux qui les vivent.', 'Mariage, naissance, éducation des enfants, séparation : sur le Défi Impact, ces expériences touchent chacun. Partagez votre expérience de professionnel de la famille et soyez visible auprès des parents et des couples.'],
        'ar' => ['الزواج، الولادة، الأبوة والأمومة: رافقوا الأسر', 'صفحة مهنية مجانية بجانب أسئلة الأسرة.', 'مراحل الأسرة الكبرى، يحكيها من يعيشونها.', 'الزواج، الولادة، تربية الأطفال، الانفصال: في تحدي الأثر تمسّ هذه التجارب الجميع. شارك خبرتك كمهني في شؤون الأسرة وكن ظاهراً لدى الأولياء والأزواج.'],
        'en' => ['Marriage, birth, parenting: support families', 'A free pro page next to the questions about family.', 'The big steps of family life, told by those who live them.', 'Marriage, birth, raising children, separation: on the Impact Challenge these experiences touch everyone. Share your experience as a family professional and be seen by parents and couples.'],
    ],
    'juridique' => [
        'fr' => ['Avocats et juristes : héritage, contrat, litige… vos futurs clients s\'interrogent', 'Une page pro gratuite à côté des questions de droit.', 'Les questions de droit changent des vies : votre expertise rassure.', 'Héritage, litige, contrat, démarches : sur le Défi Impact, ces expériences sont souvent vécues difficilement. Expliquez comment vous avez aidé à les traverser, et soyez trouvé au bon moment.'],
        'ar' => ['المحامون والقانونيون: الميراث، العقود، النزاعات… زبائنكم القادمون يتساءلون', 'صفحة مهنية مجانية بجانب أسئلة القانون.', 'المسائل القانونية تغيّر حياة الناس: خبرتك تطمئنهم.', 'الميراث، النزاع، العقد، الإجراءات: في تحدي الأثر تُعاش هذه التجارب غالباً بصعوبة. اشرح كيف ساعدت على تجاوزها، وكن موجوداً في الوقت المناسب.'],
        'en' => ['Lawyers: inheritance, contracts, disputes… your future clients have questions', 'A free pro page next to the questions about law.', 'Legal matters change lives: your expertise reassures.', 'Inheritance, disputes, contracts, paperwork: on the Impact Challenge these experiences are often lived the hard way. Explain how you helped people through them, and be found at the right moment.'],
    ],
];

function cm(string $l, string $k, array $vars = []): string
{
    $s = CAMP_STR[$l][$k] ?? CAMP_STR['fr'][$k] ?? $k;
    foreach ($vars as $a => $b) $s = str_replace('{' . $a . '}', (string) $b, $s);
    return typo($l, $s);
}

/** Texte du modèle d'un domaine : 0 objet, 1 aperçu, 2 titre, 3 accroche. */
function camp_dom(string $domain, string $l, int $i): string
{
    return typo($l, CAMP_DOMAIN[$domain][$l][$i] ?? CAMP_DOMAIN[$domain]['fr'][$i] ?? '');
}

/** Objet par défaut d'un modèle. « {name} » dans un objet est remplacé par le prénom du destinataire. */
function camp_default_subject(string $audience, string $domain, string $l): string
{
    return $audience === 'pro' ? camp_dom($domain, $l, 0) : cm($l, 'p_subject');
}

function camp_subject(string $subject, string $name): string
{
    if ($name === '') return trim((string) preg_replace(['/\{name\}\s*[,،:]\s*/u', '/\s*\{name\}/u'], ['', ''], $subject));
    return str_replace('{name}', $name, $subject);
}

function camp_sign(): string
{
    return setting('inv_sign') !== '' ? setting('inv_sign') : 'Mohammed Dehini, Défi Impact';
}

/* --------------------------------------------------------------- données des modèles */

/** Chiffres et contenus réels du site pour le modèle « particulier ». */
function camp_particulier_data(string $l): array
{
    $pdo = db();
    $qs = questions();
    $ids = array_keys($qs);
    sort($ids);
    $q = $ids ? $qs[$ids[abs(crc32(date('Y-m-d'))) % count($ids)]] : null;
    // Un récit dans la langue de l'email seulement : mélanger les écritures (arabe dans un email français…)
    // est un signal de spam pour plusieurs filtres.
    $story = $pdo->prepare("SELECT * FROM stories WHERE status = 'published' AND pro_id IS NULL AND lang = ? ORDER BY likes DESC, published DESC LIMIT 1");
    $story->execute([$l]);
    return [
        'q' => $q,
        'people' => (int) $pdo->query('SELECT COUNT(DISTINCT pid) FROM answers')->fetchColumn(),
        'answers' => (int) $pdo->query('SELECT COUNT(*) FROM answers')->fetchColumn(),
        'stories' => (int) $pdo->query("SELECT COUNT(*) FROM stories WHERE status = 'published'")->fetchColumn(),
        'story' => $story->fetch() ?: null,
    ];
}

/** Les questions d'un domaine (suggestions des pages pro) et leurs réponses « négatif ». */
function camp_pro_data(string $domain): array
{
    $qs = questions();
    $ids = array_values(array_filter(pro_suggest($domain, '', 40), function ($id) use ($qs) { return isset($qs[$id]); }));
    $stat = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach (db()->query("SELECT qid, SUM(v = -1) AS neg, COUNT(*) AS n FROM answers WHERE qid IN ($in) GROUP BY qid") as $r) {
            $stat[(int) $r['qid']] = [(int) $r['neg'], (int) $r['n']];
        }
    }
    // Trois questions à montrer : les plus vécues « négativement » parmi les plus proches du domaine.
    $top = array_slice($ids, 0, 12);
    usort($top, function ($a, $b) use ($stat, $ids) {
        return [($stat[$b][0] ?? 0), -array_search($b, $ids, true)] <=> [($stat[$a][0] ?? 0), -array_search($a, $ids, true)];
    });
    return [
        'qs' => array_map(function ($id) use ($qs, $stat) { return ['q' => $qs[$id], 'neg' => $stat[$id][0] ?? 0, 'n' => $stat[$id][1] ?? 0]; }, array_slice($top, 0, 3)),
        'neg_total' => array_sum(array_map(function ($x) { return $x[0]; }, $stat)),
    ];
}

/* --------------------------------------------------------------- email */

/**
 * Email d'invitation. $c : audience, domain, lang, subject, note, sign ; $r : email, name, token.
 * Sans jeton (aperçu, test), les liens vont directement au site.
 */
function camp_compose(array $c, array $r): array
{
    require_once __DIR__ . '/seo.php';
    $l = impact_lang($c['lang']);
    $name = trim((string) ($r['name'] ?? ''));
    $tok = (string) ($r['token'] ?? '');
    $sign = (string) ($c['sign'] ?? '') !== '' ? (string) $c['sign'] : camp_sign();
    $go = function (string $target) use ($tok): string {
        return $tok !== '' ? site_url() . 'api.php?action=go&t=' . $tok . '&to=' . rawurlencode($target) : site_url() . $target;
    };
    $unsubUrl = site_url() . 'api.php?action=inv_unsub&t=' . ($tok !== '' ? $tok : str_repeat('0', 48));
    $hello = $name !== '' ? cm($l, 'hello', ['name' => $name]) : cm($l, 'hello0');
    $note = trim((string) ($c['note'] ?? ''));
    $inner = m_p($l, $hello) . ($note !== '' ? m_note($l, $note, $sign) : '');
    $text = $hello . "\n\n" . ($note !== '' ? $note . "\n— " . $sign . "\n\n" : '');

    if (($c['audience'] ?? '') === 'pro') {
        $d = (string) $c['domain'];
        $data = camp_pro_data($d);
        $inner .= m_p($l, camp_dom($d, $l, 3));
        $text .= camp_dom($d, $l, 3) . "\n\n";
        if ($data['qs']) {
            $inner .= m_h($l, cm($l, 'pro_q_h'));
            $rows = '';
            foreach ($data['qs'] as $x) {
                $pct = $x['n'] >= 10 ? cm($l, 'pro_q_neg', ['p' => mail_pct($l, $x['neg'] / $x['n'] * 100)]) : '';
                $rows .= m_story_q($l, $x['q'], $pct, $go('?lang=' . $l . '&q=' . $x['q']['id'] . '#resultat/' . $x['q']['id']));
                $text .= '- ' . qtext($x['q'], $l) . ($pct !== '' ? ' (' . $pct . ')' : '') . "\n";
            }
            $inner .= $rows;
            if ($data['neg_total'] >= 30) {
                $inner .= m_small($l, cm($l, 'pro_neg_total', ['n' => mail_num($l, $data['neg_total'])]), 'margin:4px 0 8px');
                $text .= cm($l, 'pro_neg_total', ['n' => mail_num($l, $data['neg_total'])]) . "\n";
            }
        }
        $b = [[cm($l, 'pro_b1'), cm($l, 'pro_b1p')], [cm($l, 'pro_b2'), cm($l, 'pro_b2p')], [cm($l, 'pro_b3'), cm($l, 'pro_b3p')], [cm($l, 'pro_b4'), cm($l, 'pro_b4p')]];
        $steps = [[cm($l, 'pro_s1'), cm($l, 'pro_s1p')], [cm($l, 'pro_s2'), cm($l, 'pro_s2p')], [cm($l, 'pro_s3'), cm($l, 'pro_s3p')]];
        $cta = $go('?lang=' . $l . '#pro');
        $inner .= m_h($l, cm($l, 'pro_why_h')) . m_bullets($l, $b, 'pro') . m_h($l, cm($l, 'pro_how')) . m_steps($l, $steps, 'pro')
            . m_btn($l, $cta, cm($l, 'pro_cta'), 'pro') . m_small($l, cm($l, 'pro_ps'), 'margin:16px 0 0');
        $text .= "\n" . cm($l, 'pro_why_h') . "\n";
        foreach ($b as [$t, $p]) $text .= '- ' . $t . ' : ' . $p . "\n";
        $text .= "\n" . cm($l, 'pro_cta') . ' : ' . $cta . "\n\n" . cm($l, 'pro_ps');
        $domainLabel = pro_label('domain', $d, $l);
        $opts = ['label' => cm($l, 'pro_label'), 'hero' => ['kicker' => cm($l, 'pro_kicker', ['domain' => $domainLabel]), 'title' => camp_dom($d, $l, 2)]];
        $pre = camp_dom($d, $l, 1);
    } else {
        $data = camp_particulier_data($l);
        $inner .= m_p($l, cm($l, 'p_intro'));
        $text .= cm($l, 'p_intro') . "\n\n";
        if ($data['q']) {
            $q = $data['q'];
            $inner .= m_question($l, $q, function (int $v) use ($go, $l, $q) { return $go('?lang=' . $l . '&q=' . $q['id'] . '&a=' . $v . '#resultat/' . $q['id']); }, cm($l, 'p_qlabel'));
            $text .= cm($l, 'p_qlabel') . ' : ' . qtext($q, $l) . "\n";
            foreach (['neg' => -1, 'none' => 0, 'pos' => 1] as $k => $v) $text .= '- ' . ms($l, $k) . ' : ' . $go('?lang=' . $l . '&q=' . $q['id'] . '&a=' . $v . '#resultat/' . $q['id']) . "\n";
            $text .= "\n";
        }
        if ($data['people'] >= 50) {
            $stats = [[mail_num($l, $data['people']), cm($l, 'p_people')], [mail_num($l, $data['answers']), cm($l, 'p_answers')]];
            if ($data['stories'] >= 5) $stats[] = [mail_num($l, $data['stories']), cm($l, 'p_stories'), '#07854b'];
            $inner .= m_stats($l, $stats);
        }
        $steps = [[cm($l, 'p_s1'), cm($l, 'p_s1p')], [cm($l, 'p_s2'), cm($l, 'p_s2p')], [cm($l, 'p_s3'), cm($l, 'p_s3p')]];
        $inner .= m_h($l, cm($l, 'p_how')) . m_steps($l, $steps);
        if ($data['story']) {
            $qs = questions();
            $st = $data['story'];
            $inner .= m_h($l, cm($l, 'p_story_h')) . m_story($l, $st, $qs[(int) $st['qid']] ?? null, $st['author'] !== '' ? '— ' . ugc((string) $st['author']) : '');
        }
        $cta = $go('?lang=' . $l . '#defi/all');
        $inner .= m_btn($l, $cta, cm($l, 'p_cta'), 'pos') . m_small($l, cm($l, 'p_free'), 'margin:14px 0 0');
        $text .= cm($l, 'p_how') . "\n";
        foreach ($steps as $i => [$t, $p]) $text .= ($i + 1) . '. ' . $t . ' — ' . $p . "\n";
        $text .= "\n" . cm($l, 'p_cta') . ' : ' . $cta . "\n" . cm($l, 'p_free');
        $opts = ['label' => cm($l, 'p_label'), 'hero' => ['kicker' => cm($l, 'p_kicker'), 'title' => cm($l, 'p_title'), 'sub' => cm($l, 'p_sub')]];
        $pre = cm($l, 'p_pre');
    }
    $subject = camp_subject((string) ($c['subject'] ?? '') !== '' ? (string) $c['subject'] : camp_default_subject((string) $c['audience'], (string) ($c['domain'] ?? ''), $l), $name);
    $opts['why'] = cm($l, 'why', ['sign' => $sign]);
    $text .= "\n\n--\n" . $opts['why'] . "\n" . cm($l, 'unsub') . ' : ' . $unsubUrl . "\n";
    return [$subject, mail_layout($l, $subject, $pre, $inner, [cm($l, 'unsub') => $unsubUrl], $opts), $text, $unsubUrl];
}

/** Ligne « question du domaine » : texte, part de « négatif », lien. */
function m_story_q(string $l, array $q, string $meta, string $href): string
{
    $f = mail_font($l);
    $side = $l === 'ar' ? 'right' : 'left';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px"><tr>'
        . '<td class="em-soft" style="background:' . MAIL_UI['soft'] . ';border-radius:14px;border-' . $side . ':4px solid #d93b3b;padding:14px 16px">'
        . '<a href="' . h($href) . '" class="em-t" style="font:700 15px/1.45 ' . $f . ';color:' . MAIL_UI['title'] . ';text-decoration:none">' . h(typo($l, qtext($q, $l))) . '</a>'
        . ($meta !== '' ? '<p style="margin:4px 0 0;font:700 12px/1.4 ' . $f . ';color:#d93b3b">' . h($meta) . '</p>' : '')
        . '</td></tr></table>';
}

/* --------------------------------------------------------------- destinataires */

/**
 * Lit une liste collée (une personne par ligne) : « email », « Prénom <email> », « email;Prénom »,
 * « Prénom,email », colonnes d'Excel… Renvoie [[email, prénom], …] sans doublon, et le nombre de lignes illisibles.
 */
function camp_parse(string $raw): array
{
    $out = [];
    $bad = 0;
    foreach (preg_split('/\R/u', $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $line, $m)) { $bad++; continue; }
        $email = strtolower($m[0]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $bad++; continue; }
        $name = trim((string) preg_replace('/[<>"\'\t;,|]+/u', ' ', str_replace($m[0], '', $line)));
        $name = mb_substr((string) preg_replace('/\s+/u', ' ', $name), 0, 60);
        // Une colonne d'en-tête (« email ; nom ») ou un nom complet : on garde le premier mot comme prénom.
        if ($name !== '' && preg_match('/^(e-?mail|nom|name|prénom|prenom)\b/iu', $name)) $name = '';
        if (!isset($out[$email])) $out[$email] = [$email, $name !== '' ? explode(' ', $name)[0] : ''];
    }
    return [array_values($out), $bad];
}

/**
 * Crée une campagne (brouillon) et ses destinataires. Renvoie l'id et le détail des adresses ignorées.
 * @param array{audience:string,domain?:string,lang:string,subject?:string,note?:string,sign?:string,recipients:string} $b
 */
function camp_create(array $b): array
{
    $pdo = db();
    $aud = ($b['audience'] ?? '') === 'pro' ? 'pro' : 'particulier';
    $domain = $aud === 'pro' ? (string) ($b['domain'] ?? '') : '';
    if ($aud === 'pro' && !in_array($domain, CAMPAIGN_DOMAINS, true)) throw new InvalidArgumentException('Choisissez un domaine.');
    $l = impact_lang($b['lang'] ?? 'fr');
    [$list, $bad] = camp_parse((string) ($b['recipients'] ?? ''));
    if (!$list) throw new InvalidArgumentException('Aucune adresse email valide dans la liste.');
    if (count($list) > CAMPAIGN_MAX) throw new InvalidArgumentException('Au plus ' . CAMPAIGN_MAX . ' adresses par campagne.');
    $subject = mb_substr(trim((string) ($b['subject'] ?? '')), 0, 160);
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO campaigns (audience, domain, lang, subject, note, sign, status, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $aud, $domain, $l, $subject !== '' ? $subject : camp_default_subject($aud, $domain, $l),
        mb_substr(trim((string) ($b['note'] ?? '')), 0, 1200), mb_substr(trim((string) ($b['sign'] ?? '')), 0, 80), 'draft', time()]);
    $cid = (int) $pdo->lastInsertId();
    $optout = $pdo->prepare('SELECT 1 FROM email_optout WHERE email = ?');
    $user = $pdo->prepare($aud === 'pro' ? 'SELECT 1 FROM users u JOIN pros p ON p.uid = u.id WHERE u.email = ?' : 'SELECT 1 FROM users WHERE email = ?');
    $recent = $pdo->prepare("SELECT 1 FROM campaign_rcpt r JOIN campaigns c ON c.id = r.cid WHERE r.email = ? AND r.cid != ?
        AND ((r.status = 'sent' AND r.sent > ?) OR (r.status = 'queued' AND c.status IN ('draft', 'sending', 'paused')))");
    $ins = $pdo->prepare('INSERT INTO campaign_rcpt (cid, email, name, token, status, error) VALUES (?, ?, ?, ?, ?, ?)');
    $skip = ['optout' => 0, 'user' => 0, 'recent' => 0];
    $queued = 0;
    foreach ($list as [$email, $name]) {
        $reason = '';
        $optout->execute([$email]);
        if ($optout->fetchColumn()) $reason = 'optout';
        if ($reason === '') { $user->execute([$email]); if ($user->fetchColumn()) $reason = 'user'; }
        if ($reason === '') { $recent->execute([$email, $cid, time() - CAMPAIGN_REINVITE_DAYS * 86400]); if ($recent->fetchColumn()) $reason = 'recent'; }
        if ($reason !== '') $skip[$reason]++; else $queued++;
        $ins->execute([$cid, $email, $name, token(), $reason === '' ? 'queued' : 'skipped', $reason]);
    }
    $pdo->commit();
    return ['id' => $cid, 'queued' => $queued, 'skipped' => $skip, 'bad' => $bad];
}

/** Campagnes avec leurs résultats : envoyés, clics, inscriptions et pages pro créées par les invités. */
function camp_list(): array
{
    $rows = db()->query("SELECT c.*,
            COUNT(r.id) AS total,
            SUM(r.status = 'queued') AS queued, SUM(r.status = 'sent') AS sent, SUM(r.status = 'failed') AS failed, SUM(r.status = 'skipped') AS skipped,
            SUM(r.clicked > 0) AS clicked,
            SUM(r.status = 'sent' AND EXISTS (SELECT 1 FROM users u WHERE u.email = r.email AND u.created >= r.sent)) AS signups,
            SUM(r.status = 'sent' AND EXISTS (SELECT 1 FROM users u JOIN pros p ON p.uid = u.id WHERE u.email = r.email AND p.created >= r.sent)) AS pros,
            SUM(EXISTS (SELECT 1 FROM email_optout o WHERE o.email = r.email AND o.source = 'inv' AND o.at >= c.created)) AS optouts
        FROM campaigns c LEFT JOIN campaign_rcpt r ON r.cid = c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 100")->fetchAll();
    return array_map(function ($c) {
        $o = ['id' => (int) $c['id'], 'audience' => (string) $c['audience'], 'domain' => (string) $c['domain'], 'lang' => (string) $c['lang'],
            'subject' => (string) $c['subject'], 'note' => (string) $c['note'], 'sign' => (string) $c['sign'], 'status' => (string) $c['status'],
            'created' => (int) $c['created'], 'started' => (int) $c['started']];
        foreach (['total', 'queued', 'sent', 'failed', 'skipped', 'clicked', 'signups', 'pros', 'optouts'] as $k) $o[$k] = (int) $c[$k];
        return $o;
    }, $rows);
}

function camp_per_hour(): int
{
    $n = (int) setting('inv_per_hour');
    return $n > 0 ? min($n, 500) : 30;
}

/**
 * Envoie la suite des campagnes « en cours », dans la limite par heure. Renvoie le nombre d'emails partis.
 * $max : plafond de ce passage (cron ou bouton « envoyer un lot maintenant »).
 */
function camp_send(int $max, bool $dry, callable $say, int $onlyCid = 0): int
{
    $pdo = db();
    $hour = (int) $pdo->query("SELECT COUNT(*) FROM campaign_rcpt WHERE status IN ('sent', 'failed') AND sent > " . (time() - 3600))->fetchColumn();
    $n = min($max, camp_per_hour() - $hour);
    if ($n <= 0) { $say('Invitations : limite de ' . camp_per_hour() . ' par heure atteinte'); return 0; }
    $s = $pdo->prepare("SELECT r.*, c.audience, c.domain, c.lang, c.subject, c.note, c.sign FROM campaign_rcpt r JOIN campaigns c ON c.id = r.cid
        WHERE c.status = 'sending' AND r.status = 'queued'" . ($onlyCid ? ' AND c.id = ' . $onlyCid : '') . " ORDER BY c.started, c.id, r.id LIMIT $n");
    $s->execute();
    $list = $s->fetchAll();
    $say('Invitations : ' . count($list) . ' à envoyer');
    $optout = $pdo->prepare('SELECT 1 FROM email_optout WHERE email = ?');
    $mark = $pdo->prepare('UPDATE campaign_rcpt SET status = ?, sent = ?, error = ? WHERE id = ?');
    $done = 0;
    foreach ($list as $r) {
        $optout->execute([$r['email']]);
        if ($optout->fetchColumn()) { if (!$dry) $mark->execute(['skipped', 0, 'optout', $r['id']]); continue; }
        [$subject, $html, $text, $unsub] = camp_compose($r, $r);
        if ($dry) { $say("  (dry-run) → {$r['email']} : $subject"); $done++; continue; }
        try {
            send_mail((string) $r['email'], $subject, $html, $text, $unsub);
            $mark->execute(['sent', time(), '', $r['id']]);
            $say("  ✓ {$r['email']} : $subject");
        } catch (Throwable $e) {
            $mark->execute(['failed', time(), mb_substr($e->getMessage(), 0, 200), $r['id']]);
            $say("  ✗ {$r['email']} : " . $e->getMessage());
        }
        $done++;
    }
    if (!$dry) $pdo->exec("UPDATE campaigns SET status = 'done' WHERE status = 'sending' AND NOT EXISTS (SELECT 1 FROM campaign_rcpt r WHERE r.cid = campaigns.id AND r.status = 'queued')");
    return $done;
}
