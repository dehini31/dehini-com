<?php
/**
 * Contenu de départ de la boutique (copié dans la base au premier passage). Ensuite, c'est
 * l'administration qui fait foi : modifier ce fichier n'a plus d'effet sur un site installé.
 */
declare(strict_types=1);

/** Image livrée avec le site (assets/img/<nom>-<largeur>.webp). */
function seed_img(string $name, int $w, int $h, string $fr, string $ar): array
{
    return ['src' => 'assets/img/' . $name, 'w' => $w, 'h' => $h, 'widths' => [480, 960, 1600], 'avif' => false, 'alt' => ['fr' => $fr, 'ar' => $ar]];
}

function default_product(): array
{
    return [
        'name' => ['fr' => 'SWANBIDA – Pack Serviette Blanc', 'ar' => 'SWANBIDA – طقم المناشف الأبيض'],
        'tagline' => [
            'fr' => 'Le pack de bain complet en coton, présenté avec soin et prêt à offrir.',
            'ar' => 'طقم الحمّام الكامل من القطن، مغلّف بعناية وجاهز للإهداء.',
        ],
        'short' => [
            'fr' => 'Trois serviettes, un tapis de bain et une paire de slippers en coton 540 g/m², dans l\'emballage soigné SWANBIDA.',
            'ar' => 'ثلاث مناشف وسجّادة حمّام وزوج من الشباشب من القطن بكثافة 540 غ/م²، في تغليف SWANBIDA الأنيق.',
        ],
        'highlights' => [
            ['fr' => '100 % coton', 'ar' => 'قطن 100٪'],
            ['fr' => '540 g/m²', 'ar' => '540 غ/م²'],
            ['fr' => 'Prêt à offrir', 'ar' => 'جاهز للإهداء'],
        ],
        'contents' => [
            ['fr' => 'Une serviette 50 × 90 cm', 'ar' => 'منشفة 50 × 90 سم'],
            ['fr' => 'Une serviette 70 × 140 cm', 'ar' => 'منشفة 70 × 140 سم'],
            ['fr' => 'Une serviette 90 × 150 cm', 'ar' => 'منشفة 90 × 150 سم'],
            ['fr' => 'Un tapis de bain 50 × 70 cm', 'ar' => 'سجّادة حمّام 50 × 70 سم'],
            ['fr' => 'Une paire de slippers', 'ar' => 'زوج من الشباشب'],
        ],
        'specs' => [
            ['label' => ['fr' => 'Matière', 'ar' => 'المادة'], 'value' => ['fr' => '100 % coton', 'ar' => 'قطن 100٪']],
            ['label' => ['fr' => 'Densité', 'ar' => 'الكثافة'], 'value' => ['fr' => '540 g/m²', 'ar' => '540 غ/م²']],
            ['label' => ['fr' => 'Couleur', 'ar' => 'اللون'], 'value' => ['fr' => 'Blanc', 'ar' => 'أبيض']],
            ['label' => ['fr' => 'Serviettes', 'ar' => 'المناشف'], 'value' => ['fr' => '50 × 90 cm · 70 × 140 cm · 90 × 150 cm', 'ar' => '50 × 90 سم · 70 × 140 سم · 90 × 150 سم']],
            ['label' => ['fr' => 'Tapis de bain', 'ar' => 'سجّادة الحمّام'], 'value' => ['fr' => '50 × 70 cm', 'ar' => '50 × 70 سم']],
            ['label' => ['fr' => 'Slippers', 'ar' => 'الشباشب'], 'value' => ['fr' => 'Une paire', 'ar' => 'زوج واحد']],
        ],
        'packaging' => [
            [
                'title' => ['fr' => 'Un emballage extérieur résistant', 'ar' => 'تغليف خارجي متين'],
                'text' => [
                    'fr' => 'Il protège votre pack pendant tout le transport, jusqu\'à votre porte.',
                    'ar' => 'يحمي طقمك طوال مدة النقل، حتى باب منزلك.',
                ],
            ],
            [
                'title' => ['fr' => 'Un emballage intérieur transparent', 'ar' => 'تغليف داخلي شفاف'],
                'text' => [
                    'fr' => 'Il préserve la propreté des serviettes jusqu\'à leur première utilisation.',
                    'ar' => 'يحافظ على نظافة المناشف حتى أول استعمال لها.',
                ],
            ],
            [
                'title' => ['fr' => 'Un cordon décoratif', 'ar' => 'شريط تزييني'],
                'text' => [
                    'fr' => 'Il donne au pack une présentation élégante, idéale pour offrir.',
                    'ar' => 'يمنح الطقم مظهراً أنيقاً، مثالياً للإهداء.',
                ],
            ],
        ],
        'description' => [
            'fr' => "Le **Pack Serviette Blanc SWANBIDA** réunit tout ce qu'il faut pour une salle de bain soignée : trois serviettes de tailles complémentaires, un tapis de bain assorti et une paire de slippers.\n\nSa densité de **540 g/m²** donne des serviettes épaisses et généreuses, en **100 % coton**, une matière naturelle appréciée pour sa douceur et sa capacité à absorber l'eau.\n\nLe blanc, intemporel, s'accorde avec toutes les salles de bain et met en valeur la qualité du tissu. Présenté dans l'emballage SWANBIDA — carton de protection, housse transparente et cordon décoratif —, le pack est aussi un cadeau élégant pour un mariage, une naissance, une crémaillère ou une fête.",
            'ar' => "يجمع **طقم المناشف الأبيض من SWANBIDA** كل ما تحتاجه لحمّام أنيق: ثلاث مناشف بمقاسات متكاملة، وسجّادة حمّام متناسقة، وزوجاً من الشباشب.\n\nبكثافة **540 غ/م²**، تأتي المناشف سميكة وسخيّة، مصنوعة من **القطن 100٪**، المادة الطبيعية المعروفة بنعومتها وقدرتها على امتصاص الماء.\n\nاللون الأبيض الخالد يناسب كل الحمّامات ويُبرز جودة القماش. ويأتي الطقم في تغليف SWANBIDA — علبة حماية، وغلاف شفاف، وشريط تزييني — ليكون أيضاً هدية أنيقة لعرس أو مولود جديد أو منزل جديد أو أي مناسبة.",
        ],
        'tiers' => [
            ['qty' => 1, 'price' => 7750, 'compare' => 0, 'default' => true,
             'label' => ['fr' => '1 pack', 'ar' => 'طقم واحد'], 'badge' => ['fr' => '', 'ar' => '']],
            ['qty' => 2, 'price' => 13500, 'compare' => 15500, 'default' => false,
             'label' => ['fr' => '2 packs', 'ar' => 'طقمان'], 'badge' => ['fr' => 'Offre spéciale', 'ar' => 'عرض خاص']],
        ],
        'images' => [
            seed_img('pack-hero', 1600, 1600, 'Pack Serviette Blanc SWANBIDA : serviettes blanches pliées et nouées d\'un cordon vert', 'طقم المناشف الأبيض من SWANBIDA: مناشف بيضاء مطوية ومربوطة بشريط أخضر'),
            seed_img('pack-contents', 1600, 1600, 'Contenu du pack : trois serviettes, un tapis de bain et une paire de slippers', 'محتوى الطقم: ثلاث مناشف وسجّادة حمّام وزوج من الشباشب'),
            seed_img('pack-packaging', 1600, 1600, 'Emballage SWANBIDA : carton de protection, housse transparente et cordon décoratif', 'تغليف SWANBIDA: علبة حماية وغلاف شفاف وشريط تزييني'),
            seed_img('pack-texture', 1600, 1600, 'Détail du tissu éponge en coton 540 g/m²', 'تفاصيل قماش المناشف القطني بكثافة 540 غ/م²'),
        ],
        'videos' => [],
        'seo' => [
            'title' => [
                'fr' => 'Pack Serviette Blanc 100 % coton 540 g/m² – SWANBIDA Algérie',
                'ar' => 'طقم مناشف أبيض قطن 100٪ بكثافة 540 غ/م² – SWANBIDA الجزائر',
            ],
            'description' => [
                'fr' => 'Pack de bain blanc : 3 serviettes (50×90, 70×140, 90×150 cm), tapis de bain et slippers en coton 540 g/m². 7 750 DA, 2 packs 13 500 DA. Paiement à la livraison.',
                'ar' => 'طقم حمّام أبيض: 3 مناشف (50×90، 70×140، 90×150 سم) وسجّادة حمّام وشباشب من القطن بكثافة 540 غ/م². 7750 دج، وطقمان بـ 13500 دج. الدفع عند الاستلام.',
            ],
        ],
    ];
}

function default_collections(): array
{
    return [
        ['slug' => 'packs-de-bain', 'pos' => 1, 'data' => [
            'name' => ['fr' => 'Packs de bain', 'ar' => 'أطقم الحمّام'],
            'description' => [
                'fr' => 'Serviettes, tapis et slippers assortis, réunis dans un pack complet en coton.',
                'ar' => 'مناشف وسجّادة وشباشب متناسقة، في طقم كامل من القطن.',
            ],
            'image' => seed_img('pack-contents', 1600, 1600, 'Packs de bain SWANBIDA', 'أطقم الحمّام من SWANBIDA'),
            'seo' => ['title' => ['fr' => 'Packs de bain en coton – SWANBIDA', 'ar' => 'أطقم حمّام من القطن – SWANBIDA'],
                'description' => ['fr' => 'Packs de bain SWANBIDA en coton : serviettes, tapis de bain et slippers assortis. Livraison partout en Algérie, paiement à la livraison.',
                    'ar' => 'أطقم حمّام SWANBIDA من القطن: مناشف وسجّادة حمّام وشباشب متناسقة. توصيل إلى كل ولايات الجزائر والدفع عند الاستلام.']],
        ]],
        ['slug' => 'idees-cadeaux', 'pos' => 2, 'data' => [
            'name' => ['fr' => 'Idées cadeaux', 'ar' => 'أفكار هدايا'],
            'description' => [
                'fr' => 'Des coffrets de bain présentés avec soin, prêts à offrir pour un mariage, une naissance ou une fête.',
                'ar' => 'أطقم حمّام مغلّفة بعناية، جاهزة للإهداء في الأعراس والمواليد والمناسبات.',
            ],
            'image' => seed_img('pack-packaging', 1600, 1600, 'Coffret cadeau SWANBIDA', 'هدية SWANBIDA'),
            'seo' => ['title' => ['fr' => 'Idées cadeaux linge de bain – SWANBIDA', 'ar' => 'أفكار هدايا من مستلزمات الحمّام – SWANBIDA'],
                'description' => ['fr' => 'Offrez un pack de bain SWANBIDA : emballage soigné, cordon décoratif, coton 540 g/m². Idéal pour un mariage, une naissance ou une crémaillère.',
                    'ar' => 'أهدِ طقم حمّام من SWANBIDA: تغليف أنيق وشريط تزييني وقطن بكثافة 540 غ/م². مثالي للأعراس والمواليد والمنازل الجديدة.']],
        ]],
    ];
}

/** Contenus des pages (modifiables dans l'admin → Contenus). */
function default_content(): array
{
    return [
        'global' => [
            'announcement' => ['fr' => 'Paiement à la livraison · Livraison dans toute l\'Algérie', 'ar' => 'الدفع عند الاستلام · التوصيل إلى كل ولايات الجزائر'],
            'tagline' => ['fr' => 'L\'élégance dans chaque détail', 'ar' => 'الأناقة في كل التفاصيل'],
            'footer_about' => [
                'fr' => 'SWANBIDA crée des articles de bain en coton, choisis pour leur qualité et présentés avec soin.',
                'ar' => 'تقدّم SWANBIDA مستلزمات حمّام من القطن، مختارة لجودتها ومغلّفة بعناية.',
            ],
            'whatsapp' => '', 'phone' => '', 'email' => '',
            'facebook' => '', 'instagram' => '', 'tiktok' => '',
            'address' => ['fr' => 'Algérie', 'ar' => 'الجزائر'],
            'hours' => ['fr' => 'Tous les jours, de 9 h à 20 h', 'ar' => 'كل يوم، من 9 صباحاً إلى 8 مساءً'],
        ],
        'home' => [
            'hero_eyebrow' => ['fr' => 'Linge de bain en coton', 'ar' => 'مستلزمات حمّام من القطن'],
            'hero_title' => ['fr' => 'L\'élégance dans chaque détail', 'ar' => 'الأناقة في كل التفاصيل'],
            'hero_text' => [
                'fr' => 'Des serviettes épaisses en coton 540 g/m², un pack complet et un emballage pensé pour offrir.',
                'ar' => 'مناشف سميكة من القطن بكثافة 540 غ/م²، في طقم كامل وتغليف مصمّم للإهداء.',
            ],
            'hero_cta' => ['fr' => 'Découvrir le pack', 'ar' => 'اكتشف الطقم'],
            'hero_image' => seed_img('pack-hero', 1600, 1600, 'Serviettes blanches SWANBIDA nouées d\'un cordon vert', 'مناشف SWANBIDA البيضاء مربوطة بشريط أخضر'),
            'featured' => 'pack-serviette-blanc',
            'offer_title' => ['fr' => '2 packs à 13 500 DA au lieu de 15 500 DA', 'ar' => 'طقمان بـ 13500 دج بدلاً من 15500 دج'],
            'offer_text' => [
                'fr' => 'Un pack pour vous, un pack à offrir : économisez 2 000 DA en commandant deux packs.',
                'ar' => 'طقم لك وطقم للإهداء: وفّر 2000 دج عند طلب طقمين.',
            ],
            'benefits' => [
                ['icon' => 'cotton', 'title' => ['fr' => '100 % coton', 'ar' => 'قطن 100٪'],
                 'text' => ['fr' => 'Une matière naturelle, douce et absorbante.', 'ar' => 'مادة طبيعية، ناعمة وماصّة.']],
                ['icon' => 'layers', 'title' => ['fr' => '540 g/m²', 'ar' => '540 غ/م²'],
                 'text' => ['fr' => 'Des serviettes épaisses et généreuses.', 'ar' => 'مناشف سميكة وسخيّة.']],
                ['icon' => 'gift', 'title' => ['fr' => 'Emballage soigné', 'ar' => 'تغليف أنيق'],
                 'text' => ['fr' => 'Protégé, propre et prêt à offrir.', 'ar' => 'محمي ونظيف وجاهز للإهداء.']],
                ['icon' => 'cash', 'title' => ['fr' => 'Paiement à la livraison', 'ar' => 'الدفع عند الاستلام'],
                 'text' => ['fr' => 'Vous payez à la réception, après vérification.', 'ar' => 'تدفع عند الاستلام، بعد المعاينة.']],
            ],
            'packaging_title' => ['fr' => 'L\'expérience SWANBIDA, dès l\'ouverture', 'ar' => 'تجربة SWANBIDA منذ لحظة الفتح'],
            'packaging_text' => [
                'fr' => 'Chaque pack est préparé en trois couches de protection et de présentation.',
                'ar' => 'يُحضَّر كل طقم بثلاث طبقات من الحماية والعرض.',
            ],
            'testimonials' => [],
            'final_title' => ['fr' => 'Offrez-vous l\'élégance au quotidien', 'ar' => 'امنح نفسك الأناقة كل يوم'],
            'final_text' => [
                'fr' => 'Commandez en moins d\'une minute. Nous vous appelons pour confirmer, puis vous payez à la livraison.',
                'ar' => 'اطلب في أقل من دقيقة. نتصل بك للتأكيد، ثم تدفع عند الاستلام.',
            ],
        ],
        'story' => [
            'title' => ['fr' => 'Notre histoire', 'ar' => 'قصّتنا'],
            'intro' => [
                'fr' => 'SWANBIDA est née d\'une idée simple : le linge de bain du quotidien mérite la même attention que les plus beaux cadeaux.',
                'ar' => 'وُلدت SWANBIDA من فكرة بسيطة: مستلزمات الحمّام اليومية تستحق الاهتمام نفسه الذي نوليه لأجمل الهدايا.',
            ],
            'image' => seed_img('pack-packaging', 1600, 1600, 'Pack SWANBIDA dans son emballage', 'طقم SWANBIDA في تغليفه'),
            'body' => [
                'fr' => "## Le cygne, notre emblème\n\nLe cygne évoque la grâce, la pureté et la sérénité. C'est ce que nous voulons apporter dans votre salle de bain : des pièces blanches, douces et élégantes, qui transforment un geste quotidien en moment de bien-être.\n\n## Notre philosophie\n\nNous préférons peu de produits, mais bien choisis. Nos serviettes sont en coton, avec une densité qui leur donne de l'épaisseur et de la tenue. Chaque pack est complet, pour équiper une salle de bain ou faire un cadeau sans rien oublier.\n\n## Le soin du détail\n\nDe l'emballage extérieur qui protège le transport à la housse transparente qui préserve la propreté, jusqu'au cordon qui ferme le pack : chaque étape compte, parce que la première impression compte.",
                'ar' => "## البجعة، رمزنا\n\nترمز البجعة إلى الرشاقة والنقاء والسكينة. وهذا ما نريد أن نضيفه إلى حمّامك: قطع بيضاء ناعمة وأنيقة تحوّل عادة يومية إلى لحظة راحة.\n\n## فلسفتنا\n\nنفضّل منتجات قليلة لكن مختارة بعناية. مناشفنا من القطن، بكثافة تمنحها السُّمك والمتانة. وكل طقم كامل، لتجهيز حمّام أو تقديم هدية دون أن ينقصها شيء.\n\n## العناية بالتفاصيل\n\nمن التغليف الخارجي الذي يحمي أثناء النقل، إلى الغلاف الشفاف الذي يحافظ على النظافة، وصولاً إلى الشريط الذي يُغلق الطقم: كل مرحلة مهمّة، لأن الانطباع الأول مهمّ.",
            ],
            'values' => [
                ['title' => ['fr' => 'Qualité', 'ar' => 'الجودة'], 'text' => ['fr' => 'Des matières choisies et des caractéristiques annoncées avec précision.', 'ar' => 'مواد مختارة وخصائص معلنة بدقّة.']],
                ['title' => ['fr' => 'Élégance', 'ar' => 'الأناقة'], 'text' => ['fr' => 'Des lignes simples, du blanc, des détails soignés.', 'ar' => 'خطوط بسيطة، لون أبيض، وتفاصيل متقنة.']],
                ['title' => ['fr' => 'Confiance', 'ar' => 'الثقة'], 'text' => ['fr' => 'Paiement à la réception, après vérification du produit.', 'ar' => 'الدفع عند الاستلام، بعد معاينة المنتج.']],
            ],
        ],
        'delivery' => [
            'title' => ['fr' => 'Livraison et commande', 'ar' => 'التوصيل والطلب'],
            'intro' => [
                'fr' => 'Commandez en ligne, nous vous appelons pour confirmer, et vous payez à la réception.',
                'ar' => 'اطلب عبر الموقع، نتصل بك للتأكيد، وتدفع عند الاستلام.',
            ],
            'sections' => [
                ['title' => ['fr' => 'Comment commander ?', 'ar' => 'كيف أطلب؟'],
                 'text' => ['fr' => "1. Choisissez votre pack (1 ou 2 packs).\n2. Indiquez votre nom, votre téléphone, votre wilaya, votre commune et votre adresse.\n3. Validez : vous recevez un numéro de commande.\n4. Notre équipe vous appelle pour confirmer la commande avant l'expédition.",
                     'ar' => "1. اختر الطقم (طقم واحد أو طقمان).\n2. أدخل اسمك ورقم هاتفك وولايتك وبلديتك وعنوانك.\n3. أكّد الطلب: تحصل على رقم الطلب.\n4. يتصل بك فريقنا لتأكيد الطلب قبل الشحن."]],
                ['title' => ['fr' => 'Délais et zones de livraison', 'ar' => 'مدة التوصيل والمناطق'],
                 'text' => ['fr' => "Nous livrons dans toutes les wilayas d'Algérie. Le délai dépend de votre wilaya ; il vous est précisé lors de l'appel de confirmation. Les frais de livraison éventuels sont affichés dans le formulaire avant la validation.",
                     'ar' => "نوصل إلى كل ولايات الجزائر. تختلف مدة التوصيل حسب ولايتك، ونحدّدها لك أثناء مكالمة التأكيد. تظهر مصاريف التوصيل، إن وُجدت، في الاستمارة قبل التأكيد."]],
                ['title' => ['fr' => 'Paiement à la réception', 'ar' => 'الدفع عند الاستلام'],
                 'text' => ['fr' => "Aucun paiement en ligne n'est demandé. Vous réglez en espèces au livreur, au moment de la réception.",
                     'ar' => "لا نطلب أي دفع عبر الإنترنت. تدفع نقداً لعامل التوصيل عند الاستلام."]],
                ['title' => ['fr' => 'Vérification du produit', 'ar' => 'معاينة المنتج'],
                 'text' => ['fr' => "Vous pouvez vérifier votre pack à la réception, avant de payer : contenu, tailles et état de l'emballage.",
                     'ar' => "يمكنك معاينة الطقم عند الاستلام وقبل الدفع: المحتوى والمقاسات وحالة التغليف."]],
                ['title' => ['fr' => 'Échanges et retours', 'ar' => 'الاستبدال والإرجاع'],
                 'text' => ['fr' => "Un article ne correspond pas à votre commande ou présente un défaut ? Contactez-nous dans les 7 jours suivant la réception : nous organisons l'échange. Le produit doit être non utilisé et dans son emballage d'origine.",
                     'ar' => "هل لا يطابق أحد المنتجات طلبك أو به عيب؟ تواصل معنا خلال 7 أيام من الاستلام وسننظّم الاستبدال. يجب أن يكون المنتج غير مستعمل وفي تغليفه الأصلي."]],
            ],
        ],
        'contact' => [
            'title' => ['fr' => 'Contact', 'ar' => 'اتصل بنا'],
            'intro' => [
                'fr' => 'Une question sur un produit ou une commande ? Écrivez-nous sur WhatsApp ou appelez-nous : nous répondons rapidement.',
                'ar' => 'لديك سؤال عن منتج أو طلب؟ راسلنا عبر واتساب أو اتصل بنا: نردّ بسرعة.',
            ],
        ],
        'privacy' => [
            'title' => ['fr' => 'Confidentialité', 'ar' => 'الخصوصية'],
            'body' => [
                'fr' => "## Les informations que nous recevons\n\nPour traiter votre commande, nous enregistrons votre nom, votre numéro de téléphone, votre wilaya, votre commune et votre adresse. Ces informations servent uniquement à confirmer, préparer et livrer votre commande, et à vous contacter à son sujet.\n\n## Mesure d'audience et publicité\n\nCe site utilise le pixel Meta (Facebook, Instagram) pour mesurer l'efficacité de nos publicités. Lors d'une commande, des informations techniques (navigateur, adresse IP, identifiant de clic publicitaire) et une empreinte irréversible (hachage) de votre téléphone, de votre nom, de votre commune et de votre wilaya peuvent être transmises à Meta. Votre adresse détaillée n'est jamais transmise.\n\n## Conservation et sécurité\n\nVos données sont conservées sur un serveur sécurisé, accessibles uniquement à l'équipe SWANBIDA chargée des commandes. Les données techniques de navigation sont supprimées au bout de 45 jours.\n\n## Vos droits\n\nConformément à la loi algérienne n° 18-07 relative à la protection des personnes physiques dans le traitement des données à caractère personnel, vous pouvez demander à consulter, corriger ou supprimer vos informations en nous contactant.",
                'ar' => "## المعلومات التي نتلقّاها\n\nلمعالجة طلبك، نسجّل اسمك ورقم هاتفك وولايتك وبلديتك وعنوانك. تُستعمل هذه المعلومات فقط لتأكيد طلبك وتحضيره وتوصيله، وللتواصل معك بشأنه.\n\n## قياس الجمهور والإعلانات\n\nيستعمل هذا الموقع بكسل ميتا (فيسبوك، إنستغرام) لقياس فعالية إعلاناتنا. عند الطلب، قد تُرسل إلى ميتا معلومات تقنية (المتصفح، عنوان IP، معرّف النقر على الإعلان) وبصمة غير قابلة للاسترجاع (تجزئة) لرقم هاتفك واسمك وبلديتك وولايتك. لا يُرسل عنوانك المفصّل أبداً.\n\n## الحفظ والأمان\n\nتُحفظ بياناتك على خادم آمن، ولا يطّلع عليها إلا فريق SWANBIDA المكلّف بالطلبات. وتُحذف البيانات التقنية للتصفّح بعد 45 يوماً.\n\n## حقوقك\n\nوفقاً للقانون الجزائري رقم 18-07 المتعلق بحماية الأشخاص الطبيعيين في مجال معالجة المعطيات ذات الطابع الشخصي، يمكنك طلب الاطلاع على معلوماتك أو تصحيحها أو حذفها بالتواصل معنا.",
            ],
        ],
        'seo' => [
            'home' => [
                'title' => ['fr' => 'SWANBIDA – Serviettes et linge de bain en coton en Algérie', 'ar' => 'SWANBIDA – مناشف ومستلزمات حمّام من القطن في الجزائر'],
                'description' => ['fr' => 'SWANBIDA, linge de bain élégant : serviettes 100 % coton 540 g/m², packs de bain prêts à offrir. Livraison dans toute l\'Algérie, paiement à la livraison.',
                    'ar' => 'SWANBIDA، مستلزمات حمّام أنيقة: مناشف قطن 100٪ بكثافة 540 غ/م² وأطقم حمّام جاهزة للإهداء. توصيل إلى كل الولايات والدفع عند الاستلام.'],
            ],
            'shop' => [
                'title' => ['fr' => 'Boutique – Serviettes et packs de bain | SWANBIDA', 'ar' => 'المتجر – مناشف وأطقم حمّام | SWANBIDA'],
                'description' => ['fr' => 'Tous les articles de bain SWANBIDA : serviettes en coton, packs de bain complets et idées cadeaux. Paiement à la livraison partout en Algérie.',
                    'ar' => 'كل مستلزمات الحمّام من SWANBIDA: مناشف قطنية وأطقم حمّام كاملة وأفكار هدايا. الدفع عند الاستلام في كل الجزائر.'],
            ],
            'story' => [
                'title' => ['fr' => 'Notre histoire – SWANBIDA', 'ar' => 'قصّتنا – SWANBIDA'],
                'description' => ['fr' => 'L\'identité, la philosophie et les valeurs de SWANBIDA, marque algérienne de linge de bain élégant.',
                    'ar' => 'هوية SWANBIDA وفلسفتها وقيمها، علامة مستلزمات الحمّام الأنيقة.'],
            ],
            'delivery' => [
                'title' => ['fr' => 'Livraison, paiement à la réception et retours – SWANBIDA', 'ar' => 'التوصيل والدفع عند الاستلام والإرجاع – SWANBIDA'],
                'description' => ['fr' => 'Livraison dans toutes les wilayas, paiement à la réception, vérification du produit avant paiement, échanges et retours : tout sur votre commande SWANBIDA.',
                    'ar' => 'التوصيل إلى كل الولايات، الدفع عند الاستلام، معاينة المنتج قبل الدفع، الاستبدال والإرجاع: كل ما يخصّ طلبك من SWANBIDA.'],
            ],
            'contact' => [
                'title' => ['fr' => 'Contact – SWANBIDA', 'ar' => 'اتصل بنا – SWANBIDA'],
                'description' => ['fr' => 'Contactez SWANBIDA par WhatsApp, téléphone, email ou sur nos réseaux sociaux.',
                    'ar' => 'تواصل مع SWANBIDA عبر واتساب أو الهاتف أو البريد الإلكتروني أو شبكاتنا الاجتماعية.'],
            ],
            'guides' => [
                'title' => ['fr' => 'Guides et conseils linge de bain – SWANBIDA', 'ar' => 'أدلّة ونصائح حول مستلزمات الحمّام – SWANBIDA'],
                'description' => ['fr' => 'Bien choisir ses serviettes, les entretenir, idées cadeaux et aménagement de la salle de bain : les guides SWANBIDA.',
                    'ar' => 'كيف تختار مناشفك وتعتني بها، أفكار هدايا وتنسيق الحمّام: أدلّة SWANBIDA.'],
            ],
            'track' => [
                'title' => ['fr' => 'Suivre ma commande – SWANBIDA', 'ar' => 'تتبّع طلبي – SWANBIDA'],
                'description' => ['fr' => 'Suivez votre commande SWANBIDA avec son numéro et votre téléphone.', 'ar' => 'تتبّع طلبك من SWANBIDA برقم الطلب ورقم هاتفك.'],
            ],
        ],
    ];
}

function default_articles(): array
{
    return [
        ['slug' => 'choisir-serviette-de-bain-grammage-coton', 'days' => 3, 'data' => [
            'title' => ['fr' => 'Comment choisir une serviette de bain : grammage, coton et tailles', 'ar' => 'كيف تختار منشفة الحمّام: الكثافة والقطن والمقاسات'],
            'excerpt' => [
                'fr' => 'Que signifie 540 g/m² ? Quelle taille pour quel usage ? Les repères simples pour choisir des serviettes qui durent.',
                'ar' => 'ماذا تعني 540 غ/م²؟ وأي مقاس لأي استعمال؟ معايير بسيطة لاختيار مناشف تدوم.',
            ],
            'cover' => seed_img('pack-texture', 1600, 1600, 'Tissu éponge en coton', 'قماش مناشف قطني'),
            'body' => [
                'fr' => "Une bonne serviette se reconnaît au toucher, mais quelques repères permettent de bien choisir, même à distance.\n\n## Le grammage (g/m²)\n\nLe grammage indique le poids du tissu par mètre carré. Plus il est élevé, plus la serviette est épaisse :\n\n- **moins de 400 g/m²** : serviettes fines, qui sèchent vite (sport, voyage) ;\n- **de 400 à 500 g/m²** : un bon usage quotidien ;\n- **plus de 500 g/m²** : des serviettes épaisses et généreuses, au toucher plus moelleux.\n\nAvec **540 g/m²**, les serviettes SWANBIDA se situent dans la catégorie des serviettes épaisses.\n\n## La matière\n\nLe **coton** reste la référence pour le linge de bain : c'est une fibre naturelle, douce, qui absorbe bien l'eau et supporte les lavages répétés.\n\n## Les tailles et leurs usages\n\n- **50 × 90 cm** : visage et mains ;\n- **70 × 140 cm** : la serviette de toilette pour le corps ;\n- **90 × 150 cm** : un grand format pour s'envelopper à la sortie du bain ;\n- **tapis de bain 50 × 70 cm** : pour poser le pied au sec.\n\nUn pack qui réunit ces formats évite d'avoir à les assortir soi-même : couleurs et matières sont identiques.\n\n## Le blanc, un choix intemporel\n\nLe blanc s'accorde avec toutes les salles de bain, donne une impression de propreté et met en valeur la qualité du tissu.",
                'ar' => "تُعرف المنشفة الجيدة باللمس، لكن هناك معايير تساعدك على الاختيار الصحيح حتى عن بُعد.\n\n## الكثافة (غ/م²)\n\nتدلّ الكثافة على وزن القماش في المتر المربع. كلما ارتفعت، كانت المنشفة أسمك:\n\n- **أقل من 400 غ/م²**: مناشف رقيقة تجفّ بسرعة (للرياضة والسفر)؛\n- **من 400 إلى 500 غ/م²**: مناسبة للاستعمال اليومي؛\n- **أكثر من 500 غ/م²**: مناشف سميكة وسخيّة، أنعم ملمساً.\n\nبكثافة **540 غ/م²**، تنتمي مناشف SWANBIDA إلى فئة المناشف السميكة.\n\n## المادة\n\nيبقى **القطن** المرجع في مستلزمات الحمّام: ألياف طبيعية ناعمة، تمتصّ الماء جيداً وتتحمّل الغسل المتكرر.\n\n## المقاسات واستعمالاتها\n\n- **50 × 90 سم**: للوجه واليدين؛\n- **70 × 140 سم**: منشفة الجسم؛\n- **90 × 150 سم**: مقاس كبير للالتفاف بعد الاستحمام؛\n- **سجّادة حمّام 50 × 70 سم**: لوضع القدمين على سطح جاف.\n\nالطقم الذي يجمع هذه المقاسات يغنيك عن تنسيقها بنفسك: الألوان والمواد متطابقة.\n\n## الأبيض، اختيار خالد\n\nيناسب اللون الأبيض كل الحمّامات، ويعطي إحساساً بالنظافة ويُبرز جودة القماش.",
            ],
            'seo' => ['title' => ['fr' => 'Choisir une serviette de bain : grammage, coton, tailles | SWANBIDA', 'ar' => 'كيف تختار منشفة الحمّام: الكثافة والقطن والمقاسات | SWANBIDA'],
                'description' => ['fr' => 'Grammage (g/m²), coton, tailles 50×90, 70×140, 90×150 cm : notre guide pour bien choisir vos serviettes de bain.',
                    'ar' => 'الكثافة (غ/م²) والقطن والمقاسات 50×90 و70×140 و90×150 سم: دليلنا لاختيار مناشف الحمّام.']],
        ]],
        ['slug' => 'idees-cadeaux-coffret-de-bain', 'days' => 2, 'data' => [
            'title' => ['fr' => 'Idées cadeaux : pourquoi offrir un pack de bain élégant', 'ar' => 'أفكار هدايا: لماذا تُهدي طقم حمّام أنيقاً'],
            'excerpt' => [
                'fr' => 'Mariage, naissance, crémaillère, Aïd : le pack de bain est un cadeau utile, élégant et toujours apprécié.',
                'ar' => 'عرس، مولود جديد، منزل جديد، عيد: طقم الحمّام هدية مفيدة وأنيقة ومحبوبة دائماً.',
            ],
            'cover' => seed_img('pack-packaging', 1600, 1600, 'Pack de bain présenté comme un cadeau', 'طقم حمّام مقدّم كهدية'),
            'body' => [
                'fr' => "Trouver un cadeau à la fois utile et élégant n'est pas toujours simple. Le linge de bain coche les deux cases.\n\n## Un cadeau utile au quotidien\n\nDes serviettes de qualité servent tous les jours, pendant des années. C'est un cadeau qu'on ne range pas au fond d'un placard.\n\n## Pour quelles occasions ?\n\n- **Mariage** : pour équiper le nouveau foyer ;\n- **Naissance** : un cadeau pour les jeunes parents ;\n- **Crémaillère** : une salle de bain prête dès l'installation ;\n- **Aïd et fêtes** : un présent soigné pour la famille.\n\n## La présentation fait la différence\n\nUn cadeau se découvre d'abord par son emballage. Chez SWANBIDA, chaque pack arrive dans un **emballage extérieur résistant**, protégé par une **housse transparente**, et fermé par un **cordon décoratif** : il peut être offert tel quel.\n\n## Astuce\n\nL'offre **2 packs** permet de garder un pack pour soi et d'en offrir un, à un prix plus avantageux.",
                'ar' => "ليس من السهل دائماً إيجاد هدية مفيدة وأنيقة في آن واحد. مستلزمات الحمّام تجمع بين الأمرين.\n\n## هدية مفيدة كل يوم\n\nالمناشف الجيدة تُستعمل يومياً ولسنوات. إنها هدية لا تُركن في الخزانة.\n\n## لأي مناسبات؟\n\n- **العرس**: لتجهيز البيت الجديد؛\n- **المولود الجديد**: هدية للوالدين؛\n- **المنزل الجديد**: حمّام جاهز منذ أول يوم؛\n- **العيد والمناسبات**: هدية أنيقة للعائلة.\n\n## التقديم يصنع الفرق\n\nتُكتشف الهدية أولاً من تغليفها. لدى SWANBIDA، يصل كل طقم في **تغليف خارجي متين**، محمياً بـ**غلاف شفاف**، ومغلقاً بـ**شريط تزييني**: يمكن إهداؤه كما هو.\n\n## نصيحة\n\nيتيح عرض **الطقمين** أن تحتفظ بطقم لنفسك وتُهدي الآخر، بسعر أفضل.",
            ],
            'seo' => ['title' => ['fr' => 'Idée cadeau : offrir un pack de bain élégant | SWANBIDA', 'ar' => 'فكرة هدية: طقم حمّام أنيق | SWANBIDA'],
                'description' => ['fr' => 'Mariage, naissance, crémaillère, Aïd : pourquoi un pack de bain SWANBIDA présenté avec soin est un cadeau réussi.',
                    'ar' => 'عرس، مولود، منزل جديد، عيد: لماذا يُعدّ طقم الحمّام من SWANBIDA هدية موفّقة.']],
        ]],
        ['slug' => 'entretenir-serviettes-coton', 'days' => 1, 'data' => [
            'title' => ['fr' => 'Entretenir ses serviettes en coton pour qu\'elles restent douces', 'ar' => 'كيف تعتني بمناشفك القطنية لتبقى ناعمة'],
            'excerpt' => [
                'fr' => 'Premier lavage, température, adoucissant, séchage : les bons gestes pour garder des serviettes douces et absorbantes.',
                'ar' => 'الغسلة الأولى، درجة الحرارة، المنعّم، التجفيف: العادات الصحيحة لمناشف ناعمة وماصّة.',
            ],
            'cover' => seed_img('pack-hero', 1600, 1600, 'Serviettes blanches pliées', 'مناشف بيضاء مطوية'),
            'body' => [
                'fr' => "Des serviettes bien entretenues gardent leur douceur et leur pouvoir absorbant beaucoup plus longtemps.\n\n## Avant la première utilisation\n\nLavez vos serviettes neuves une première fois : cela retire les apprêts de fabrication et améliore leur capacité d'absorption.\n\n## Le lavage\n\n- Lavez les serviettes blanches **séparément** des couleurs ;\n- une température de **40 à 60 °C** convient à la plupart des serviettes en coton (suivez l'étiquette) ;\n- ne surchargez pas la machine : les serviettes doivent pouvoir bouger.\n\n## Doser la lessive et l'adoucissant\n\nTrop de lessive laisse des résidus qui rendent le tissu rêche. Quant à l'adoucissant, utilisé en excès, il dépose un film qui réduit l'absorption : mieux vaut l'utiliser avec modération, voire pas du tout.\n\n## Le séchage\n\nSecouez les serviettes avant de les étendre pour redresser les boucles du tissu. Un séchage à l'air libre, à l'ombre, préserve le blanc ; au sèche-linge, choisissez une température modérée.\n\n## Entre deux utilisations\n\nÉtendez la serviette bien à plat pour qu'elle sèche vite, et changez-la régulièrement.",
                'ar' => "المناشف التي يُعتنى بها جيداً تحافظ على نعومتها وقدرتها على الامتصاص لمدة أطول بكثير.\n\n## قبل أول استعمال\n\nاغسل مناشفك الجديدة مرة أولى: يزيل ذلك مواد التصنيع ويحسّن قدرتها على الامتصاص.\n\n## الغسل\n\n- اغسل المناشف البيضاء **منفصلة** عن الملوّنة؛\n- تناسب درجة حرارة **من 40 إلى 60 درجة** معظم المناشف القطنية (اتّبع الملصق)؛\n- لا تملأ الغسالة أكثر من اللازم: يجب أن تتحرك المناشف بحرية.\n\n## مقدار مسحوق الغسيل والمنعّم\n\nالإفراط في مسحوق الغسيل يترك بقايا تجعل القماش خشناً. أما المنعّم، فإن استُعمل بكثرة يترك طبقة تقلّل الامتصاص: الأفضل استعماله باعتدال أو الاستغناء عنه.\n\n## التجفيف\n\nانفض المناشف قبل نشرها لتستقيم حلقات القماش. التجفيف في الهواء وفي الظل يحافظ على البياض؛ وفي المجفّف اختر حرارة معتدلة.\n\n## بين استعمالين\n\nانشر المنشفة جيداً لتجفّ بسرعة، وغيّرها بانتظام.",
            ],
            'seo' => ['title' => ['fr' => 'Entretenir ses serviettes en coton : lavage et séchage | SWANBIDA', 'ar' => 'العناية بالمناشف القطنية: الغسل والتجفيف | SWANBIDA'],
                'description' => ['fr' => 'Premier lavage, température, adoucissant, séchage : nos conseils pour garder vos serviettes en coton douces et absorbantes.',
                    'ar' => 'الغسلة الأولى ودرجة الحرارة والمنعّم والتجفيف: نصائحنا لتبقى مناشفك القطنية ناعمة وماصّة.']],
        ]],
    ];
}

/** Premier passage : produit principal, collections, guides et wilayas. */
function seed_all(PDO $pdo): void
{
    $now = time();
    if (!(int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
        $p = default_product();
        $pdo->prepare('INSERT INTO products (slug, sku, status, featured, pos, price, compare_price, stock, stock_alert, data, created, updated) VALUES (?, ?, ?, 1, 1, ?, ?, ?, 5, ?, ?, ?)')
            ->execute(['pack-serviette-blanc', 'SWB-PSB-01', 'active', 7750, 0, 100, json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
        $pid = (int) $pdo->lastInsertId();
        foreach (default_collections() as $c) {
            $pdo->prepare('INSERT INTO collections (slug, active, pos, data, created, updated) VALUES (?, 1, ?, ?, ?, ?)')
                ->execute([$c['slug'], $c['pos'], json_encode($c['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now]);
            $pdo->prepare('INSERT INTO product_collections (pid, cid) VALUES (?, ?)')->execute([$pid, (int) $pdo->lastInsertId()]);
        }
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM articles')->fetchColumn()) {
        foreach (default_articles() as $a) {
            $t = $now - $a['days'] * 86400;
            $pdo->prepare('INSERT INTO articles (slug, status, published, data, created, updated) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$a['slug'], 'published', $t, json_encode($a['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $t, $t]);
        }
    }
    if (!(int) $pdo->query('SELECT COUNT(*) FROM wilayas')->fetchColumn()) {
        $geo = json_decode((string) file_get_contents(__DIR__ . '/data/algeria.json'), true) ?: [];
        $iw = $pdo->prepare('INSERT INTO wilayas (code, fr, ar, fee_home, fee_desk, delay, active, pos) VALUES (?, ?, ?, 0, 0, \'\', 1, ?)');
        $ic = $pdo->prepare('INSERT INTO communes (wilaya, fr, ar) VALUES (?, ?, ?)');
        foreach ($geo as $w) {
            $iw->execute([$w['code'], $w['fr'], $w['ar'], (int) $w['code']]);
            foreach ($w['communes'] as $c) $ic->execute([$w['code'], $c[0], $c[1]]);
        }
    }
}
