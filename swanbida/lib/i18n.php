<?php
/** Textes fixes de l'interface (les contenus éditables sont dans l'admin). */
declare(strict_types=1);

const UI = [
    'fr' => [
        'skip' => 'Aller au contenu',
        'nav_home' => 'Accueil', 'nav_shop' => 'Boutique', 'nav_story' => 'Notre histoire', 'nav_delivery' => 'Livraison',
        'nav_contact' => 'Contact', 'nav_guides' => 'Guides', 'nav_track' => 'Suivre ma commande',
        'menu' => 'Menu', 'close' => 'Fermer', 'lang_other' => 'العربية', 'lang_other_label' => 'Voir le site en arabe',
        'order_now' => 'Commander', 'order_now_long' => 'Commander maintenant', 'discover' => 'Découvrir', 'view_product' => 'Voir le produit',
        'from' => 'à partir de', 'save' => 'Économisez {x}', 'instead_of' => 'au lieu de {x}',
        'in_stock' => 'En stock, expédié rapidement', 'low_stock' => 'Plus que {n} disponibles', 'out_of_stock' => 'Rupture de stock',
        'out_of_stock_text' => 'Ce produit revient bientôt. Écrivez-nous sur WhatsApp pour être prévenu.',
        'trust_cod' => 'Paiement à la livraison', 'trust_delivery' => 'Livraison dans toutes les wilayas',
        'trust_check' => 'Vérification avant paiement', 'trust_exchange' => 'Échange possible',
        'read_more' => 'Lire la suite', 'back_home' => 'Retour à l\'accueil',
        'contents_title' => 'Contenu du pack', 'specs_title' => 'Caractéristiques', 'packaging_title' => 'L\'emballage SWANBIDA',
        'description_title' => 'Description', 'faq_title' => 'Livraison, paiement et retours', 'related' => 'Vous aimerez aussi',
        'gallery' => 'Galerie photos', 'photo_n' => 'Photo {n} sur {t}', 'play_video' => 'Lire la vidéo', 'zoom' => 'Agrandir',
        'prev' => 'Précédent', 'next' => 'Suivant',
        'form_title' => 'Commandez en 1 minute', 'form_sub' => 'Remplissez le formulaire : nous vous appelons pour confirmer, puis vous payez à la réception.',
        'choose_pack' => 'Choisissez votre offre', 'f_name' => 'Nom et prénom', 'f_phone' => 'Numéro de téléphone', 'f_wilaya' => 'Wilaya',
        'f_commune' => 'Commune', 'f_address' => 'Adresse détaillée', 'f_note' => 'Remarque (facultatif)', 'f_delivery' => 'Mode de livraison',
        'ph_name' => 'Ex. : Amina Benali', 'ph_phone' => '05 / 06 / 07 XX XX XX XX', 'ph_address' => 'Quartier, rue, n° de maison, point de repère…',
        'ph_note' => 'Un horaire de livraison, une précision…', 'select_wilaya' => 'Choisir la wilaya', 'select_commune' => 'Choisir la commune',
        'select_wilaya_first' => 'Choisissez d\'abord la wilaya', 'other_commune' => 'Autre commune…', 'type_commune' => 'Écrivez votre commune',
        'delivery_home' => 'À domicile', 'delivery_desk' => 'Au bureau du transporteur',
        'subtotal' => 'Sous-total', 'shipping' => 'Livraison', 'shipping_free' => 'Gratuite', 'shipping_pending' => 'Selon la wilaya', 'total' => 'Total à payer à la livraison',
        'submit' => 'Confirmer ma commande', 'submit_note' => 'Aucun paiement maintenant. Vous payez à la réception, après vérification.',
        'sending' => 'Envoi en cours…', 'privacy_note' => 'Vos informations servent uniquement à traiter votre commande.',
        'err_name' => 'Indiquez votre nom et prénom.', 'err_phone' => 'Numéro invalide : 10 chiffres commençant par 05, 06 ou 07.',
        'err_wilaya' => 'Choisissez votre wilaya.', 'err_commune' => 'Choisissez votre commune.', 'err_address' => 'Indiquez une adresse plus précise.',
        'err_tier' => 'Choisissez une offre.', 'err_generic' => 'La commande n\'a pas pu être envoyée. Réessayez ou contactez-nous sur WhatsApp.',
        'err_rate' => 'Trop de commandes envoyées depuis cette connexion. Réessayez plus tard ou contactez-nous.',
        'err_stock' => 'Stock insuffisant pour cette offre.', 'err_network' => 'Connexion impossible. Vérifiez votre réseau et réessayez.',
        'err_wilaya_off' => 'La livraison n\'est pas disponible dans cette wilaya pour le moment.',
        'thanks_title' => 'Merci, votre commande est enregistrée !', 'thanks_text' => 'Notre équipe vous appelle très bientôt au {phone} pour confirmer votre commande.',
        'thanks_keep' => 'Gardez ce numéro : il vous permet de suivre votre commande.', 'order_number' => 'Numéro de commande',
        'order_summary' => 'Récapitulatif', 'delivery_to' => 'Livraison à', 'customer' => 'Client', 'status' => 'Statut', 'order_date' => 'Date',
        'next_steps' => 'Et maintenant ?', 'step_call' => 'Nous vous appelons pour confirmer.', 'step_ship' => 'Nous préparons et expédions votre pack.',
        'step_pay' => 'Vous vérifiez et payez à la réception.', 'continue_shopping' => 'Continuer mes achats', 'track_link' => 'Suivre ma commande',
        'st_new' => 'Reçue, en attente de confirmation', 'st_confirmed' => 'Confirmée', 'st_preparing' => 'En préparation', 'st_shipped' => 'Expédiée',
        'st_delivered' => 'Livrée', 'st_cancelled' => 'Annulée', 'st_returned' => 'Retournée',
        'track_title' => 'Suivre ma commande', 'track_intro' => 'Entrez votre numéro de commande (ex. SW-7K4M2Q) et le téléphone utilisé lors de la commande.',
        'f_ref' => 'Numéro de commande', 'track_btn' => 'Voir ma commande', 'track_notfound' => 'Aucune commande trouvée avec ce numéro et ce téléphone.',
        'carrier' => 'Transporteur', 'tracking_number' => 'Numéro de suivi', 'my_orders' => 'Mes commandes sur cet appareil',
        'all_products' => 'Tous les produits', 'sort' => 'Trier', 'sort_reco' => 'Recommandés', 'sort_price_asc' => 'Prix croissant',
        'sort_price_desc' => 'Prix décroissant', 'sort_new' => 'Nouveautés', 'in_stock_only' => 'En stock uniquement', 'n_products' => '{n} produit(s)',
        'empty_shop' => 'Aucun produit ne correspond à ces critères.', 'collections' => 'Collections', 'filter' => 'Filtrer',
        'read_article' => 'Lire le guide', 'published_on' => 'Publié le {d}', 'min_read' => '{n} min de lecture', 'all_guides' => 'Tous les guides',
        'guides_title' => 'Guides et conseils', 'guides_intro' => 'Bien choisir, bien entretenir, bien offrir : nos conseils autour du linge de bain.',
        'whatsapp' => 'WhatsApp', 'phone' => 'Téléphone', 'email' => 'Email', 'socials' => 'Réseaux sociaux', 'hours' => 'Horaires',
        'write_whatsapp' => 'Écrire sur WhatsApp', 'call_us' => 'Appeler', 'follow_us' => 'Suivez-nous', 'address' => 'Adresse',
        'contact_empty' => 'Les coordonnées de contact seront bientôt disponibles.',
        'rights' => 'Tous droits réservés.', 'privacy' => 'Confidentialité', 'legal' => 'Informations',
        'nf_title' => 'Page introuvable', 'nf_text' => 'Cette page n\'existe pas ou a été déplacée.',
        'featured_title' => 'Le pack signature', 'collections_title' => 'Nos collections', 'benefits_title' => 'Pourquoi SWANBIDA',
        'testimonials_title' => 'Ils ont choisi SWANBIDA', 'special_offer' => 'Offre spéciale', 'see_offer' => 'Profiter de l\'offre',
        'sticky_cta' => 'Commander', 'pack_contains' => 'Ce pack contient', 'qty' => 'Quantité', 'unit_price' => 'Prix unitaire',
        'product' => 'Produit', 'offer' => 'Offre', 'per_pack' => 'soit {x} le pack', 'need_help' => 'Une question avant de commander ?',
        'wa_prefill' => 'Bonjour SWANBIDA, j\'ai une question sur {p}.', 'wa_order_prefill' => 'Bonjour SWANBIDA, ma commande : {ref}.',
        'home_title_alt' => 'Accueil SWANBIDA', 'breadcrumb' => 'Fil d\'Ariane', 'video_title' => 'Vidéo',
        'secure_note' => 'Commande sécurisée', 'confirm_call' => 'Confirmation par téléphone',
    ],
    'ar' => [
        'skip' => 'انتقل إلى المحتوى',
        'nav_home' => 'الرئيسية', 'nav_shop' => 'المتجر', 'nav_story' => 'قصّتنا', 'nav_delivery' => 'التوصيل',
        'nav_contact' => 'اتصل بنا', 'nav_guides' => 'أدلّة', 'nav_track' => 'تتبّع طلبي',
        'menu' => 'القائمة', 'close' => 'إغلاق', 'lang_other' => 'Français', 'lang_other_label' => 'عرض الموقع بالفرنسية',
        'order_now' => 'اطلب الآن', 'order_now_long' => 'اطلب الآن', 'discover' => 'اكتشف', 'view_product' => 'عرض المنتج',
        'from' => 'ابتداءً من', 'save' => 'وفّر {x}', 'instead_of' => 'بدلاً من {x}',
        'in_stock' => 'متوفّر، يُشحن بسرعة', 'low_stock' => 'بقي {n} فقط', 'out_of_stock' => 'نفد المخزون',
        'out_of_stock_text' => 'سيعود هذا المنتج قريباً. راسلنا عبر واتساب ليصلك الخبر.',
        'trust_cod' => 'الدفع عند الاستلام', 'trust_delivery' => 'التوصيل إلى كل الولايات',
        'trust_check' => 'المعاينة قبل الدفع', 'trust_exchange' => 'إمكانية الاستبدال',
        'read_more' => 'اقرأ المزيد', 'back_home' => 'العودة إلى الرئيسية',
        'contents_title' => 'محتوى الطقم', 'specs_title' => 'الخصائص', 'packaging_title' => 'تغليف SWANBIDA',
        'description_title' => 'الوصف', 'faq_title' => 'التوصيل والدفع والإرجاع', 'related' => 'قد يعجبك أيضاً',
        'gallery' => 'معرض الصور', 'photo_n' => 'الصورة {n} من {t}', 'play_video' => 'تشغيل الفيديو', 'zoom' => 'تكبير',
        'prev' => 'السابق', 'next' => 'التالي',
        'form_title' => 'اطلب في دقيقة واحدة', 'form_sub' => 'املأ الاستمارة: نتصل بك للتأكيد، ثم تدفع عند الاستلام.',
        'choose_pack' => 'اختر العرض', 'f_name' => 'الاسم واللقب', 'f_phone' => 'رقم الهاتف', 'f_wilaya' => 'الولاية',
        'f_commune' => 'البلدية', 'f_address' => 'العنوان بالتفصيل', 'f_note' => 'ملاحظة (اختياري)', 'f_delivery' => 'طريقة التوصيل',
        'ph_name' => 'مثال: أمينة بن علي', 'ph_phone' => '05 / 06 / 07 XX XX XX XX', 'ph_address' => 'الحي، الشارع، رقم المنزل، علامة مميّزة…',
        'ph_note' => 'وقت مناسب للتوصيل، أو أي توضيح…', 'select_wilaya' => 'اختر الولاية', 'select_commune' => 'اختر البلدية',
        'select_wilaya_first' => 'اختر الولاية أولاً', 'other_commune' => 'بلدية أخرى…', 'type_commune' => 'اكتب اسم بلديتك',
        'delivery_home' => 'إلى المنزل', 'delivery_desk' => 'في مكتب شركة التوصيل',
        'subtotal' => 'المجموع الفرعي', 'shipping' => 'التوصيل', 'shipping_free' => 'مجاني', 'shipping_pending' => 'حسب الولاية', 'total' => 'المبلغ المدفوع عند الاستلام',
        'submit' => 'تأكيد الطلب', 'submit_note' => 'لا دفع الآن. تدفع عند الاستلام، بعد المعاينة.',
        'sending' => 'جارٍ الإرسال…', 'privacy_note' => 'تُستعمل معلوماتك فقط لمعالجة طلبك.',
        'err_name' => 'أدخل الاسم واللقب.', 'err_phone' => 'رقم غير صحيح: 10 أرقام تبدأ بـ 05 أو 06 أو 07.',
        'err_wilaya' => 'اختر ولايتك.', 'err_commune' => 'اختر بلديتك.', 'err_address' => 'أدخل عنواناً أدقّ.',
        'err_tier' => 'اختر عرضاً.', 'err_generic' => 'تعذّر إرسال الطلب. أعد المحاولة أو تواصل معنا عبر واتساب.',
        'err_rate' => 'أُرسلت طلبات كثيرة من هذا الاتصال. أعد المحاولة لاحقاً أو تواصل معنا.',
        'err_stock' => 'المخزون غير كافٍ لهذا العرض.', 'err_network' => 'تعذّر الاتصال. تحقّق من الشبكة وأعد المحاولة.',
        'err_wilaya_off' => 'التوصيل غير متاح حالياً إلى هذه الولاية.',
        'thanks_title' => 'شكراً، تم تسجيل طلبك!', 'thanks_text' => 'سيتصل بك فريقنا قريباً جداً على الرقم {phone} لتأكيد طلبك.',
        'thanks_keep' => 'احتفظ بهذا الرقم: يتيح لك تتبّع طلبك.', 'order_number' => 'رقم الطلب',
        'order_summary' => 'ملخّص الطلب', 'delivery_to' => 'التوصيل إلى', 'customer' => 'الزبون', 'status' => 'الحالة', 'order_date' => 'التاريخ',
        'next_steps' => 'ماذا بعد؟', 'step_call' => 'نتصل بك لتأكيد الطلب.', 'step_ship' => 'نحضّر طقمك ونشحنه.',
        'step_pay' => 'تعاين الطقم وتدفع عند الاستلام.', 'continue_shopping' => 'متابعة التسوّق', 'track_link' => 'تتبّع طلبي',
        'st_new' => 'تم الاستلام، في انتظار التأكيد', 'st_confirmed' => 'مؤكَّد', 'st_preparing' => 'قيد التحضير', 'st_shipped' => 'تم الشحن',
        'st_delivered' => 'تم التسليم', 'st_cancelled' => 'ملغى', 'st_returned' => 'مُرجَع',
        'track_title' => 'تتبّع طلبي', 'track_intro' => 'أدخل رقم الطلب (مثال: SW-7K4M2Q) ورقم الهاتف المستعمل في الطلب.',
        'f_ref' => 'رقم الطلب', 'track_btn' => 'عرض طلبي', 'track_notfound' => 'لا يوجد طلب بهذا الرقم وهذا الهاتف.',
        'carrier' => 'شركة التوصيل', 'tracking_number' => 'رقم التتبّع', 'my_orders' => 'طلباتي على هذا الجهاز',
        'all_products' => 'كل المنتجات', 'sort' => 'ترتيب', 'sort_reco' => 'المقترحة', 'sort_price_asc' => 'السعر تصاعدياً',
        'sort_price_desc' => 'السعر تنازلياً', 'sort_new' => 'الأحدث', 'in_stock_only' => 'المتوفّر فقط', 'n_products' => '{n} منتج',
        'empty_shop' => 'لا توجد منتجات تطابق هذه المعايير.', 'collections' => 'المجموعات', 'filter' => 'تصفية',
        'read_article' => 'اقرأ الدليل', 'published_on' => 'نُشر في {d}', 'min_read' => '{n} دقائق قراءة', 'all_guides' => 'كل الأدلّة',
        'guides_title' => 'أدلّة ونصائح', 'guides_intro' => 'كيف تختار، وكيف تعتني، وكيف تُهدي: نصائحنا حول مستلزمات الحمّام.',
        'whatsapp' => 'واتساب', 'phone' => 'الهاتف', 'email' => 'البريد الإلكتروني', 'socials' => 'الشبكات الاجتماعية', 'hours' => 'أوقات العمل',
        'write_whatsapp' => 'راسلنا عبر واتساب', 'call_us' => 'اتصل', 'follow_us' => 'تابعونا', 'address' => 'العنوان',
        'contact_empty' => 'ستتوفّر معلومات الاتصال قريباً.',
        'rights' => 'جميع الحقوق محفوظة.', 'privacy' => 'الخصوصية', 'legal' => 'معلومات',
        'nf_title' => 'الصفحة غير موجودة', 'nf_text' => 'هذه الصفحة غير موجودة أو تم نقلها.',
        'featured_title' => 'الطقم المميّز', 'collections_title' => 'مجموعاتنا', 'benefits_title' => 'لماذا SWANBIDA',
        'testimonials_title' => 'اختاروا SWANBIDA', 'special_offer' => 'عرض خاص', 'see_offer' => 'استفد من العرض',
        'sticky_cta' => 'اطلب الآن', 'pack_contains' => 'يحتوي هذا الطقم على', 'qty' => 'الكمية', 'unit_price' => 'سعر الوحدة',
        'product' => 'المنتج', 'offer' => 'العرض', 'per_pack' => 'أي {x} للطقم', 'need_help' => 'لديك سؤال قبل الطلب؟',
        'wa_prefill' => 'مرحباً SWANBIDA، لدي سؤال عن {p}.', 'wa_order_prefill' => 'مرحباً SWANBIDA، طلبي رقم: {ref}.',
        'home_title_alt' => 'الصفحة الرئيسية SWANBIDA', 'breadcrumb' => 'مسار التنقّل', 'video_title' => 'فيديو',
        'secure_note' => 'طلب آمن', 'confirm_call' => 'تأكيد عبر الهاتف',
    ],
];

function t(string $key, array $vars = []): string
{
    $s = UI[current_lang()][$key] ?? UI['fr'][$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return $s;
}

function current_lang(?string $set = null): string
{
    static $lang = 'fr';
    if ($set !== null) $lang = in_array($set, SWB_LANGS, true) ? $set : 'fr';
    return $lang;
}

/** Libellé d'un statut de commande dans la langue courante. */
function status_label(string $s): string
{
    return t('st_' . $s);
}

/** Date lisible (« 12 novembre 2026 » / « 12 نوفمبر 2026 »). */
function date_long(int $ts, ?string $lang = null): string
{
    $lang = $lang ?? current_lang();
    $m = $lang === 'ar'
        ? ['جانفي', 'فيفري', 'مارس', 'أفريل', 'ماي', 'جوان', 'جويلية', 'أوت', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']
        : ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}
