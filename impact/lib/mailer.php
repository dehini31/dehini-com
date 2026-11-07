<?php
/**
 * Emails du défi Impact : textes (fr / ar / en), gabarit HTML et envoi.
 * L'envoi passe par SMTP si IMPACT_SMTP_HOST est renseigné (recommandé : un
 * compte email cPanel, ex. impact@dehini.com), sinon par la fonction mail().
 */
declare(strict_types=1);

const MAIL_STR = [
    'fr' => [
        'brand' => 'Défi Impact', 'tagline' => 'Le défi des expériences positives', 'address' => 'Alger, Algérie',
        'hello' => 'Bonjour {name},',
        'neg' => 'Impact négatif', 'none' => 'Aucun impact', 'pos' => 'Impact positif',
        'daily_subject' => 'Question du jour : {q}',
        'daily_intro' => 'Voici votre question du jour. Une seule touche suffit pour répondre :',
        'daily_progress' => 'Vous avez déjà répondu à {n} questions sur {t}.',
        'story_subject' => 'Et si vous racontiez cet impact positif ?',
        'story_intro' => 'Vous avez répondu « impact positif » à cette question. Racontez en quelques lignes comment cette expérience vous a fait grandir : votre récit pourrait inspirer quelqu\'un qui l\'a vécue difficilement.',
        'story_cta' => 'Raconter mon expérience',
        'digest_subject_1' => '1 expérience positive qui pourrait vous inspirer',
        'digest_subject_n' => '{n} expériences positives qui pourraient vous inspirer',
        'digest_intro' => 'Certaines expériences vous ont marqué négativement, ou n\'ont rien changé pour vous. D\'autres participants les ont vécues autrement — voici ce qu\'ils en ont tiré :',
        'digest_impact' => 'Son impact positif :', 'digest_cta' => 'Voir tous les récits', 'anon' => 'Anonyme',
        'welcome_subject' => 'Bienvenue dans le défi Impact — confirmez votre adresse',
        'welcome_intro' => 'Merci d\'avoir rejoint le défi ! Vos réponses et vos récits sont désormais sauvegardés automatiquement dans votre compte.',
        'welcome_confirm' => 'Confirmez votre adresse pour recevoir la question du jour et les impacts positifs de la communauté :',
        'welcome_cta' => 'Confirmer mon adresse',
        'reset_subject' => 'Choisissez un nouveau mot de passe',
        'reset_intro' => 'Vous avez demandé à réinitialiser le mot de passe de votre compte du défi Impact. Ce lien est valable une heure.',
        'reset_cta' => 'Choisir un nouveau mot de passe',
        'reset_ignore' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement cet email.',
        'footer_why' => 'Vous recevez cet email car vous participez au défi Impact sur dehini.com.',
        'unsub_daily' => 'Ne plus recevoir la question du jour', 'unsub_digest' => 'Ne plus recevoir les impacts positifs',
        'unsub_all' => 'Me désabonner de tout',
        'unsub_title' => 'Désabonnement',
        'unsub_q_daily' => 'Ne plus recevoir la question du jour ?',
        'unsub_q_digest' => 'Ne plus recevoir les impacts positifs de la communauté ?',
        'unsub_q_all' => 'Ne plus recevoir aucun email du défi Impact ?',
        'unsub_q_tips' => 'Ne plus recevoir les conseils, paliers et relances ?', 'unsub_q_pro' => 'Ne plus recevoir les emails de votre page pro (bienvenue, rappels, rapport mensuel) ?',
        'unsub_confirm' => 'Confirmer',
        'unsub_done' => 'C\'est fait : vous ne recevrez plus ces emails. Vous pouvez les réactiver à tout moment depuis « Mon compte ».',
        'unsub_bad' => 'Ce lien n\'est plus valide.', 'back' => 'Retour au défi',
        'msg_subject_owner' => 'Nouveau message à propos de votre récit',
        'msg_subject_guest' => 'L\'auteur du récit vous a répondu',
        'msg_intro_owner' => '{name} vous a écrit à propos de votre récit :',
        'msg_intro_guest' => '{name} vous a répondu :',
        'msg_author' => 'L\'auteur du récit', 'msg_cta' => 'Lire et répondre',
        'msg_private' => 'Conversation privée : seuls votre interlocuteur et vous pouvez la lire.',
        'unsub_msg' => 'Ne plus être prévenu des messages',
        'unsub_q_msg' => 'Ne plus être prévenu par email des nouveaux messages ?',
        'thanks_subject' => 'Merci pour votre soutien au défi Impact',
        'thanks_intro' => 'Votre soutien de {amount} a bien été reçu. Il aide le défi Impact à rester gratuit, sans publicité, et à grandir.',
        'thanks_sign' => 'Merci du fond du cœur. — Mohammed Dehini',
        'sub_subject' => 'Confirmez votre adresse pour recevoir les expériences positives',
        'sub_intro' => 'Vous avez demandé à recevoir par email les expériences positives publiées sur les questions où vous avez répondu « impact négatif ». Confirmez votre adresse pour les recevoir :',
        'sub_cta' => 'Confirmer mon adresse', 'sub_ignore' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email : rien ne vous sera envoyé.',
        'alert_subject_1' => 'Une expérience positive sur « {q} »', 'alert_subject_n' => '{n} nouvelles expériences positives pour vous',
        'alert_intro' => 'Vous avez répondu « impact négatif » à ces questions. Quelqu\'un vient de raconter comment il l\'a vécue positivement :',
        'alert_read' => 'Lire et répondre', 'alert_pro' => 'Page professionnelle', 'alert_hello' => 'Bonjour,',
        'sub_unsub' => 'Ne plus recevoir ces emails', 'sub_unsub_q' => 'Ne plus recevoir les expériences positives par email ?',
        'sub_unsub_done' => 'C\'est fait : vous ne recevrez plus ces emails, et votre adresse a été effacée.',
    ],
    'ar' => [
        'brand' => 'تحدي الأثر', 'tagline' => 'تحدّي التجارب الإيجابية', 'address' => 'الجزائر العاصمة، الجزائر',
        'hello' => 'مرحباً {name}،',
        'neg' => 'أثر سلبي', 'none' => 'بلا أثر', 'pos' => 'أثر إيجابي',
        'daily_subject' => 'سؤال اليوم: {q}',
        'daily_intro' => 'إليك سؤال اليوم. لمسة واحدة تكفي للإجابة:',
        'daily_progress' => 'عدد الأسئلة التي أجبت عنها: {n} من {t}.',
        'story_subject' => 'ما رأيك أن تروي هذا الأثر الإيجابي؟',
        'story_intro' => 'أجبت بـ«أثر إيجابي» على هذا السؤال. احكِ في بضعة أسطر كيف ساعدتك هذه التجربة على النمو: قد تُلهم قصتك شخصاً عاشها بصعوبة.',
        'story_cta' => 'أروي تجربتي',
        'digest_subject_1' => 'تجربة إيجابية قد تلهمك',
        'digest_subject_n' => 'تجارب إيجابية قد تلهمك ({n})',
        'digest_intro' => 'بعض التجارب تركت فيك أثراً سلبياً أو لم تغيّر فيك شيئاً. مشاركون آخرون عاشوها بشكل مختلف — إليك ما استخلصوه منها:',
        'digest_impact' => 'أثرها الإيجابي:', 'digest_cta' => 'عرض كل القصص', 'anon' => 'مجهول',
        'welcome_subject' => 'مرحباً بك في تحدي الأثر — أكّد عنوان بريدك',
        'welcome_intro' => 'شكراً لانضمامك إلى التحدي! أصبحت إجاباتك وقصصك تُحفظ تلقائياً في حسابك.',
        'welcome_confirm' => 'أكّد عنوان بريدك لتصلك أسئلة اليوم والآثار الإيجابية التي يشاركها المجتمع:',
        'welcome_cta' => 'تأكيد بريدي الإلكتروني',
        'reset_subject' => 'اختر كلمة مرور جديدة',
        'reset_intro' => 'طلبت إعادة تعيين كلمة مرور حسابك في تحدي الأثر. هذا الرابط صالح لمدة ساعة واحدة.',
        'reset_cta' => 'اختيار كلمة مرور جديدة',
        'reset_ignore' => 'إن لم تكن أنت من طلب ذلك، فتجاهل هذه الرسالة ببساطة.',
        'footer_why' => 'تصلك هذه الرسالة لأنك تشارك في تحدي الأثر على dehini.com.',
        'unsub_daily' => 'إيقاف سؤال اليوم', 'unsub_digest' => 'إيقاف رسائل الآثار الإيجابية',
        'unsub_all' => 'إلغاء الاشتراك في كل الرسائل',
        'unsub_title' => 'إلغاء الاشتراك',
        'unsub_q_daily' => 'إيقاف رسائل سؤال اليوم؟',
        'unsub_q_digest' => 'إيقاف رسائل الآثار الإيجابية من المجتمع؟',
        'unsub_q_all' => 'إيقاف كل رسائل تحدي الأثر؟',
        'unsub_q_tips' => 'إيقاف النصائح والمراحل والتذكيرات؟', 'unsub_q_pro' => 'إيقاف رسائل صفحتك المهنية (الترحيب، التذكيرات، التقرير الشهري)؟',
        'unsub_confirm' => 'تأكيد',
        'unsub_done' => 'تمّ: لن تصلك هذه الرسائل بعد الآن. يمكنك إعادة تفعيلها في أي وقت من «حسابي».',
        'unsub_bad' => 'هذا الرابط لم يعد صالحاً.', 'back' => 'العودة إلى التحدي',
        'msg_subject_owner' => 'رسالة جديدة حول قصتك',
        'msg_subject_guest' => 'ردّ عليك صاحب القصة',
        'msg_intro_owner' => 'كتب إليك {name} حول قصتك:',
        'msg_intro_guest' => 'ردّ عليك {name}:',
        'msg_author' => 'صاحب القصة', 'msg_cta' => 'اقرأ وردّ',
        'msg_private' => 'محادثة خاصة: لا يطّلع عليها أحد سواكما.',
        'unsub_msg' => 'إيقاف إشعارات الرسائل',
        'unsub_q_msg' => 'إيقاف إشعارات البريد عند وصول رسائل جديدة؟',
        'thanks_subject' => 'شكراً على دعمك لتحدي الأثر',
        'thanks_intro' => 'وصلنا دعمك بقيمة {amount}. إنه يساعد تحدي الأثر على البقاء مجانياً وبلا إعلانات، وعلى النمو.',
        'thanks_sign' => 'شكراً من القلب. — محمد دهيني',
        'sub_subject' => 'أكّد عنوانك لتصلك التجارب الإيجابية',
        'sub_intro' => 'طلبت أن تصلك عبر البريد التجارب الإيجابية المنشورة على الأسئلة التي أجبت عنها بـ«أثر سلبي». أكّد عنوانك لتصلك:',
        'sub_cta' => 'تأكيد عنواني', 'sub_ignore' => 'إن لم تكن أنت من طلب ذلك، فتجاهل هذه الرسالة: لن يصلك أي شيء.',
        'alert_subject_1' => 'تجربة إيجابية حول «{q}»', 'alert_subject_n' => '{n} تجارب إيجابية جديدة لك',
        'alert_intro' => 'أجبت بـ«أثر سلبي» على هذه الأسئلة. شخص ما روى للتو كيف عاشها بشكل إيجابي:',
        'alert_read' => 'اقرأ وأجب', 'alert_pro' => 'الصفحة المهنية', 'alert_hello' => 'مرحباً،',
        'sub_unsub' => 'إيقاف هذه الرسائل', 'sub_unsub_q' => 'إيقاف رسائل التجارب الإيجابية عبر البريد؟',
        'sub_unsub_done' => 'تمّ: لن تصلك هذه الرسائل بعد الآن، وحُذف عنوانك.',
    ],
    'en' => [
        'brand' => 'Impact Challenge', 'tagline' => 'The positive experiences challenge', 'address' => 'Algiers, Algeria',
        'hello' => 'Hi {name},',
        'neg' => 'Negative impact', 'none' => 'No impact', 'pos' => 'Positive impact',
        'daily_subject' => 'Question of the day: {q}',
        'daily_intro' => 'Here is your question of the day. One tap is all it takes:',
        'daily_progress' => 'You have answered {n} of {t} questions so far.',
        'story_subject' => 'Why not tell the story of this positive impact?',
        'story_intro' => 'You answered “positive impact” to this question. Tell us in a few lines how this experience helped you grow: your story could inspire someone who went through it the hard way.',
        'story_cta' => 'Tell my story',
        'digest_subject_1' => '1 positive experience that might inspire you',
        'digest_subject_n' => '{n} positive experiences that might inspire you',
        'digest_intro' => 'Some experiences left a negative mark on you, or changed nothing. Other participants lived them differently — here is what they took from them:',
        'digest_impact' => 'Its positive impact:', 'digest_cta' => 'See all stories', 'anon' => 'Anonymous',
        'welcome_subject' => 'Welcome to the Impact Challenge — please confirm your email',
        'welcome_intro' => 'Thanks for joining the challenge! Your answers and stories are now saved automatically to your account.',
        'welcome_confirm' => 'Confirm your address to receive the question of the day and the community\'s positive impacts:',
        'welcome_cta' => 'Confirm my email',
        'reset_subject' => 'Choose a new password',
        'reset_intro' => 'You asked to reset the password of your Impact Challenge account. This link is valid for one hour.',
        'reset_cta' => 'Choose a new password',
        'reset_ignore' => 'If you didn\'t request this, simply ignore this email.',
        'footer_why' => 'You are receiving this email because you take part in the Impact Challenge on dehini.com.',
        'unsub_daily' => 'Stop the question of the day', 'unsub_digest' => 'Stop positive impact emails',
        'unsub_all' => 'Unsubscribe from everything',
        'unsub_title' => 'Unsubscribe',
        'unsub_q_daily' => 'Stop receiving the question of the day?',
        'unsub_q_digest' => 'Stop receiving the community\'s positive impacts?',
        'unsub_q_all' => 'Stop receiving all Impact Challenge emails?',
        'unsub_q_tips' => 'Stop tips, milestones and reminders?', 'unsub_q_pro' => 'Stop your pro page emails (welcome, reminders, monthly report)?',
        'unsub_confirm' => 'Confirm',
        'unsub_done' => 'Done: you won\'t receive these emails anymore. You can turn them back on anytime from “My account”.',
        'unsub_bad' => 'This link is no longer valid.', 'back' => 'Back to the challenge',
        'msg_subject_owner' => 'New message about your story',
        'msg_subject_guest' => 'The story\'s author replied to you',
        'msg_intro_owner' => '{name} wrote to you about your story:',
        'msg_intro_guest' => '{name} replied to you:',
        'msg_author' => 'The story\'s author', 'msg_cta' => 'Read and reply',
        'msg_private' => 'Private conversation: only the two of you can read it.',
        'unsub_msg' => 'Stop message notifications',
        'unsub_q_msg' => 'Stop email notifications for new messages?',
        'thanks_subject' => 'Thank you for supporting the Impact Challenge',
        'thanks_intro' => 'Your support of {amount} has been received. It helps the Impact Challenge stay free, ad-free, and keep growing.',
        'thanks_sign' => 'Thank you from the bottom of our hearts. — Mohammed Dehini',
        'sub_subject' => 'Confirm your address to receive positive experiences',
        'sub_intro' => 'You asked to receive by email the positive experiences published on questions you answered “negative impact”. Confirm your address to receive them:',
        'sub_cta' => 'Confirm my address', 'sub_ignore' => 'If you didn\'t request this, ignore this email: nothing will be sent to you.',
        'alert_subject_1' => 'A positive experience about “{q}”', 'alert_subject_n' => '{n} new positive experiences for you',
        'alert_intro' => 'You answered “negative impact” to these questions. Someone just shared how they lived it positively:',
        'alert_read' => 'Read and answer', 'alert_pro' => 'Professional page', 'alert_hello' => 'Hi,',
        'sub_unsub' => 'Stop these emails', 'sub_unsub_q' => 'Stop receiving positive experiences by email?',
        'sub_unsub_done' => 'Done: you won\'t receive these emails anymore, and your address has been erased.',
    ],
];

function ms(string $lang, string $key, array $vars = []): string
{
    $s = MAIL_STR[$lang][$key] ?? MAIL_STR['fr'][$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string) $v, $s);
    return typo($lang, $s);
}

/** Typographie française : espace insécable avant « : ? ! ; » et après « (pas de retour à la ligne orphelin). */
function typo(string $lang, string $s): string
{
    if ($lang !== 'fr') return $s;
    $s = preg_replace('/ ([:?!;»])/u', "\u{00A0}$1", $s) ?? $s;
    return preg_replace('/« /u', "«\u{00A0}", $s) ?? $s;
}

/** Texte d'un participant : isolé (dir="auto") pour qu'un récit arabe s'affiche bien dans un email français, et inversement. */
function ugc(string $s): string
{
    return '<span dir="auto" style="unicode-bidi:isolate">' . nl2br(h($s)) . '</span>';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------ gabarit */

const MAIL_COLORS = ['neg' => '#ff5d5d', 'none' => '#8b93a7', 'pos' => '#17b26a', 'ink' => '#0b1020', 'cobalt' => '#2f5bff', 'pro' => '#e8860c'];
/** Couleurs des boutons : un ton plus foncé, pour un texte blanc lisible (contraste ≥ 4,5:1). */
const MAIL_BTN = ['#17b26a' => '#07854b', '#ff5d5d' => '#d93b3b', '#8b93a7' => '#5f677c', '#e8860c' => '#b25f00',
    'pos' => '#07854b', 'neg' => '#d93b3b', 'none' => '#5f677c', 'ink' => '#0b1020', 'cobalt' => '#2f5bff', 'pro' => '#b25f00'];
/** Couleurs du gabarit (texte, fonds) : versions claires ; le mode sombre est géré par les classes em-*. */
const MAIL_UI = ['page' => '#eef0f4', 'card' => '#ffffff', 'text' => '#20283f', 'title' => '#0b1020', 'muted' => '#6b7185',
    'soft' => '#f5f6f8', 'line' => '#e6e8ee'];

function mail_font(string $lang): string
{
    return $lang === 'ar'
        ? "Tahoma,'Segoe UI',Arial,sans-serif"
        : "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
}

/** Pourcentage arrondi : « 42 % » en français, « 42% » sinon. */
function mail_pct(string $l, float $x): string
{
    $n = (string) (int) round($x);
    return $l === 'fr' ? $n . "\u{00A0}%" : $n . '%';
}

/** Nombre avec séparateur de milliers (espace fine en français). */
function mail_num(string $l, int $n): string
{
    return $l === 'fr' ? number_format($n, 0, ',', "\u{202F}") : number_format($n);
}

/** Sens du texte : début / fin de ligne selon la langue. */
function mail_align(string $lang): string
{
    return $lang === 'ar' ? 'right' : 'left';
}

/** Bouton « à toute épreuve » (table + lien : fonctionne aussi dans Outlook). */
function m_btn(string $l, string $href, string $label, string $tone = 'pos', bool $center = false): string
{
    $bg = MAIL_BTN[$tone] ?? $tone;
    $f = mail_font($l);
    return '<table role="presentation" cellpadding="0" cellspacing="0" class="em-btn" style="margin:24px ' . ($center ? 'auto' : '0') . ' 6px"' . ($center ? ' align="center"' : '') . '><tr>'
        . '<td bgcolor="' . $bg . '" style="border-radius:999px;background:' . $bg . ';text-align:center">'
        . '<a href="' . h($href) . '" style="display:inline-block;padding:15px 30px;font:700 16px/1.2 ' . $f . ';color:#ffffff;text-decoration:none;border-radius:999px">' . h($label) . '</a>'
        . '</td></tr></table>';
}

/** Ancien nom, gardé pour les emails existants. */
function mail_button(string $href, string $label, string $bg = MAIL_COLORS['ink'], string $lang = 'fr'): string
{
    return m_btn($lang, $href, $label, $bg);
}

function m_p(string $l, string $text, string $style = ''): string
{
    return '<p class="em-t" style="margin:0 0 16px;font:400 16px/1.65 ' . mail_font($l) . ';color:' . MAIL_UI['text'] . ';' . $style . '">' . h($text) . '</p>';
}

/** Paragraphe en gris (précisions, mentions). */
function m_small(string $l, string $text, string $style = ''): string
{
    return '<p class="em-m" style="margin:0 0 12px;font:400 13px/1.6 ' . mail_font($l) . ';color:' . MAIL_UI['muted'] . ';' . $style . '">' . h($text) . '</p>';
}

/** Intertitre de section. */
function m_h(string $l, string $text): string
{
    return '<p class="em-t" style="margin:28px 0 12px;font:800 18px/1.35 ' . mail_font($l) . ';color:' . MAIL_UI['title'] . '">' . h($text) . '</p>';
}

/** Séparateur fin. */
function m_hr(): string
{
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td class="em-line" style="border-top:1px solid ' . MAIL_UI['line'] . ';font-size:0;line-height:0;height:1px;padding:0">&nbsp;</td></tr></table>';
}

/** Mot personnel (invitation) : texte libre, avec signature. */
function m_note(string $l, string $text, string $sign = ''): string
{
    $f = mail_font($l);
    $side = $l === 'ar' ? 'right' : 'left';
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px"><tr>'
        . '<td class="em-soft" style="background:' . MAIL_UI['soft'] . ';border-' . $side . ':4px solid #17b26a;border-radius:14px;padding:16px 18px">'
        . '<p class="em-t" style="margin:0;font:400 15px/1.65 ' . $f . ';color:' . MAIL_UI['text'] . '">' . nl2br(h($text)) . '</p>'
        . ($sign !== '' ? '<p class="em-m" style="margin:10px 0 0;font:700 13px/1.4 ' . $f . ';color:' . MAIL_UI['muted'] . '">— ' . h($sign) . '</p>' : '')
        . '</td></tr></table>';
}

/**
 * Carte « question » avec trois boutons de réponse directe (un clic enregistre la réponse sur le site).
 * $href(int $v) donne le lien de chaque réponse (-1, 0, 1).
 */
function m_question(string $l, array $q, callable $href, string $label = ''): string
{
    $f = mail_font($l);
    $cells = '';
    foreach (['neg' => -1, 'none' => 0, 'pos' => 1] as $k => $v) {
        $bg = MAIL_BTN[$k];
        $cells .= '<td class="em-col" width="33%" style="padding:4px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td bgcolor="' . $bg . '" style="border-radius:12px;background:' . $bg . ';text-align:center">'
            . '<a href="' . h($href($v)) . '" style="display:block;padding:13px 6px;font:700 14px/1.25 ' . $f . ';color:#ffffff;text-decoration:none">' . h(ms($l, $k)) . '</a>'
            . '</td></tr></table></td>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px"><tr>'
        . '<td class="em-soft" style="background:' . MAIL_UI['soft'] . ';border:1px solid ' . MAIL_UI['line'] . ';border-radius:18px;padding:20px 18px 16px">'
        . ($label !== '' ? '<p style="margin:0 0 8px;font:800 11px/1.4 ' . $f . ';letter-spacing:.06em;text-transform:uppercase;color:#07854b">' . h($label) . '</p>' : '')
        . '<p class="em-t" style="margin:0 0 14px;font:800 21px/1.35 ' . $f . ';color:' . MAIL_UI['title'] . '">' . h(typo($l, qtext($q, $l))) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>'
        . '</td></tr></table>';
}

/** Rangée de chiffres clés : [[valeur, libellé, (couleur)], …] — 2 à 4 cases. */
function m_stats(string $l, array $items): string
{
    $f = mail_font($l);
    $w = (int) floor(100 / max(1, count($items)));
    $cells = '';
    foreach ($items as $it) {
        $cells .= '<td class="em-col" width="' . $w . '%" style="padding:5px;vertical-align:top">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td class="em-soft" style="background:' . MAIL_UI['soft'] . ';border-radius:14px;padding:14px 14px 12px;text-align:' . mail_align($l) . '">'
            . '<p class="em-t" style="margin:0;font:800 24px/1.15 ' . $f . ';color:' . ($it[2] ?? MAIL_UI['title']) . '">' . h((string) $it[0]) . '</p>'
            . '<p class="em-m" style="margin:4px 0 0;font:400 12px/1.4 ' . $f . ';color:' . MAIL_UI['muted'] . '">' . h((string) $it[1]) . '</p>'
            . '</td></tr></table></td>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px -5px 18px;width:calc(100% + 10px)"><tr>' . $cells . '</tr></table>';
}

/** Étapes numérotées : [[titre, texte], …]. */
function m_steps(string $l, array $items, string $tone = 'pos'): string
{
    $f = mail_font($l);
    $bg = MAIL_BTN[$tone] ?? $tone;
    $rows = '';
    foreach (array_values($items) as $i => [$title, $text]) {
        $rows .= '<tr><td width="40" style="vertical-align:top;padding:0 0 14px">'
            . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="' . $bg . '" width="28" height="28" style="width:28px;height:28px;border-radius:50%;background:' . $bg . ';text-align:center;font:800 14px/28px ' . $f . ';color:#ffffff">' . ($i + 1) . '</td></tr></table></td>'
            . '<td style="vertical-align:top;padding:0 0 14px">'
            . '<p class="em-t" style="margin:3px 0 2px;font:700 15px/1.4 ' . $f . ';color:' . MAIL_UI['title'] . '">' . h($title) . '</p>'
            . ($text !== '' ? '<p class="em-m" style="margin:0;font:400 14px/1.55 ' . $f . ';color:' . MAIL_UI['muted'] . '">' . h($text) . '</p>' : '')
            . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '" style="margin:4px 0 10px">' . $rows . '</table>';
}

/** Liste à cocher : [[fait (bool), texte], …] — ✓ vert si fait, cercle vide sinon. */
function m_checks(string $l, array $items): string
{
    $f = mail_font($l);
    $rows = '';
    foreach ($items as [$done, $text]) {
        $mark = $done
            ? '<td bgcolor="#07854b" width="22" height="22" style="width:22px;height:22px;border-radius:50%;background:#07854b;text-align:center;font:700 13px/22px Arial,sans-serif;color:#ffffff">&#10003;</td>'
            : '<td><div style="width:18px;height:18px;border-radius:11px;border:2px solid #c3c8d4;font-size:1px;line-height:1px">&nbsp;</div></td>';
        $rows .= '<tr><td width="34" style="vertical-align:top;padding:0 0 12px"><table role="presentation" cellpadding="0" cellspacing="0"><tr>' . $mark . '</tr></table></td>'
            . '<td class="' . ($done ? 'em-m' : 'em-t') . '" style="vertical-align:top;padding:1px 0 12px;font:' . ($done ? '400' : '700') . ' 15px/1.5 ' . $f . ';color:' . ($done ? MAIL_UI['muted'] : MAIL_UI['title']) . ($done ? ';text-decoration:line-through' : '') . '">' . h($text) . '</td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '" style="margin:4px 0 8px">' . $rows . '</table>';
}

/** Avantages : [[titre, texte], …] avec une pastille de couleur. */
function m_bullets(string $l, array $items, string $tone = 'pos'): string
{
    $f = mail_font($l);
    $c = MAIL_BTN[$tone] ?? $tone;
    $rows = '';
    foreach ($items as [$title, $text]) {
        $rows .= '<tr><td width="26" style="vertical-align:top;padding:7px 0 12px"><div style="width:9px;height:9px;border-radius:5px;background:' . $c . ';font-size:1px;line-height:1px">&nbsp;</div></td>'
            . '<td style="vertical-align:top;padding:0 0 12px"><p class="em-t" style="margin:0;font:400 15px/1.55 ' . $f . ';color:' . MAIL_UI['text'] . '"><b class="em-t" style="color:' . MAIL_UI['title'] . '">' . h($title) . '</b>'
            . ($text !== '' ? ' — ' . h($text) : '') . '</p></td></tr>';
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="' . ($l === 'ar' ? 'rtl' : 'ltr') . '" style="margin:4px 0 10px">' . $rows . '</table>';
}

/** Un récit (expérience positive) : question, texte, impact, auteur, lien. */
function m_story(string $l, array $st, ?array $q, string $who = '', string $link = '', string $linkLabel = ''): string
{
    $f = mail_font($l);
    $side = $l === 'ar' ? 'right' : 'left';
    $exp = mb_strimwidth((string) $st['exp'], 0, 320, '…');
    $imp = mb_strimwidth((string) $st['imp'], 0, 240, '…');
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px"><tr>'
        . '<td class="em-soft" style="background:' . MAIL_UI['soft'] . ';border-radius:16px;padding:18px 20px">'
        . ($q ? '<p class="em-m" style="margin:0 0 10px;font:700 13px/1.45 ' . $f . ';color:' . MAIL_UI['muted'] . '">' . h(typo($l, qtext($q, $l))) . '</p>' : '')
        . '<p class="em-t" style="margin:0 0 10px;font:400 15px/1.65 ' . $f . ';color:' . MAIL_UI['title'] . ';border-' . $side . ':3px solid #17b26a;padding-' . $side . ':12px">' . ugc($exp) . '</p>'
        . '<p style="margin:0 0 8px;font:400 14px/1.6 ' . $f . ';color:#07854b"><b>' . h(ms($l, 'digest_impact')) . '</b> ' . ugc($imp) . '</p>'
        . ($who !== '' ? '<p class="em-m" style="margin:0;font:400 12px/1.4 ' . $f . ';color:' . MAIL_UI['muted'] . '">' . $who . '</p>' : '')
        . ($link !== '' ? '<p style="margin:10px 0 0"><a href="' . h($link) . '" style="font:700 14px ' . $f . ';color:#07854b;text-decoration:none">' . h($linkLabel) . ' &rarr;</a></p>' : '')
        . '</td></tr></table>';
}

/**
 * Gabarit commun : en-tête de marque (avec un bandeau titre optionnel), carte blanche, pied de page.
 * @param array<string,string> $unsub libellé => url (pied de page)
 * @param array{hero?:array{kicker?:string,title:string,sub?:string},label?:string,why?:string} $opts
 */
function mail_layout(string $lang, string $title, string $preheader, string $inner, array $unsub = [], array $opts = []): string
{
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $align = mail_align($lang);
    $end = $lang === 'ar' ? 'left' : 'right';
    $f = mail_font($lang);
    $dots = '';
    foreach (['neg', 'none', 'pos'] as $c) {
        $dots .= '<td style="padding:0 5px 0 0;vertical-align:middle"><div style="width:10px;height:10px;border-radius:5px;background:' . MAIL_COLORS[$c] . ';font-size:1px;line-height:1px">&nbsp;</div></td>';
    }
    $links = [];
    foreach ($unsub as $label => $url) {
        $links[] = '<a href="' . h($url) . '" class="em-m" style="color:' . MAIL_UI['muted'] . ';text-decoration:underline">' . h($label) . '</a>';
    }
    $site = site_url();
    $label = $opts['label'] ?? '';
    $hero = $opts['hero'] ?? null;
    $heroHtml = '';
    if ($hero) {
        $heroHtml = '<tr><td class="em-pad" style="padding:6px 36px 36px;text-align:' . $align . '">'
            . (!empty($hero['kicker']) ? '<p style="margin:0 0 10px;font:800 12px/1.4 ' . $f . ';letter-spacing:.08em;text-transform:uppercase;color:#5ef0a6">' . h($hero['kicker']) . '</p>' : '')
            . '<h1 class="em-h1" style="margin:0;font:800 30px/1.22 ' . $f . ';color:#ffffff;letter-spacing:-.01em">' . h($hero['title']) . '</h1>'
            . (!empty($hero['sub']) ? '<p style="margin:12px 0 0;font:400 16px/1.6 ' . $f . ';color:#c4cadb">' . h($hero['sub']) . '</p>' : '')
            . '</td></tr>';
    }
    $css = '<style>'
        . ':root{color-scheme:light dark;supported-color-schemes:light dark}'
        . 'a{text-decoration:none}'
        . '@media (max-width:620px){.em-pad{padding-left:22px!important;padding-right:22px!important}.em-h1{font-size:25px!important}.em-col{display:block!important;width:100%!important;box-sizing:border-box}.em-wrap{padding:0!important}.em-shell{border-radius:0!important}}'
        . '@media (prefers-color-scheme:dark){.em-bg{background:#05070f!important}.em-card{background:#121829!important}.em-t{color:#e9ecf4!important}.em-m{color:#9aa3b5!important}'
        . '.em-soft{background:#1b2238!important;border-color:#2a3350!important}.em-line{border-color:#2a3350!important}}'
        . '</style>';
    return '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
        . '<meta name="x-apple-disable-message-reformatting"><title>' . h($title) . '</title>' . $css . '</head>'
        . '<body class="em-bg" style="margin:0;padding:0;background:' . MAIL_UI['page'] . ';-webkit-text-size-adjust:100%;word-spacing:normal">'
        // Aperçu affiché par la messagerie à côté du sujet (puis des espaces pour ne pas laisser apparaître le début du texte).
        . '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all">' . h($preheader) . str_repeat('&nbsp;&zwnj;', 30) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="em-bg" style="background:' . MAIL_UI['page'] . '"><tr><td align="center" class="em-wrap" style="padding:32px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="' . $dir . '" class="em-shell" style="max-width:600px;border-radius:24px;overflow:hidden;background:' . MAIL_COLORS['ink'] . '">'
        // En-tête : marque à gauche, étiquette (ex. « Invitation ») à droite.
        . '<tr><td bgcolor="' . MAIL_COLORS['ink'] . '" style="background:' . MAIL_COLORS['ink'] . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td class="em-pad" style="padding:26px 36px 22px;text-align:' . $align . '">'
        . '<a href="' . h($site) . '" style="text-decoration:none"><table role="presentation" cellpadding="0" cellspacing="0" style="display:inline-table"><tr>' . $dots
        . '<td width="6" style="font-size:0">&nbsp;</td><td style="font:800 18px/1 ' . $f . ';color:#ffffff;white-space:nowrap">' . h(ms($lang, 'brand')) . '</td></tr></table></a>'
        . '</td>'
        . ($label !== '' ? '<td class="em-pad" style="padding:26px 36px 22px;text-align:' . $end . '"><span style="display:inline-block;padding:5px 12px;border-radius:999px;border:1px solid #2d3756;font:700 12px/1.3 ' . $f . ';color:#c4cadb;white-space:nowrap">' . h($label) . '</span></td>' : '')
        . '</tr></table></td></tr>'
        . $heroHtml
        // Carte blanche du contenu.
        . '<tr><td class="em-card em-pad" bgcolor="' . MAIL_UI['card'] . '" style="background:' . MAIL_UI['card'] . ';padding:34px 36px 30px;text-align:' . $align . ';font:400 16px/1.65 ' . $f . ';color:' . MAIL_UI['text'] . '">' . $inner . '</td></tr>'
        . '</table>'
        // Pied de page.
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px"><tr><td dir="' . $dir . '" class="em-pad" style="padding:22px 28px 8px;text-align:center">'
        . '<p class="em-m" style="margin:0 0 6px;font:700 13px/1.5 ' . $f . ';color:#4b5470">' . h(ms($lang, 'brand')) . ' · ' . h(ms($lang, 'tagline')) . '</p>'
        . '<p class="em-m" style="margin:0 0 8px;font:400 12px/1.6 ' . $f . ';color:' . MAIL_UI['muted'] . '">' . h($opts['why'] ?? ms($lang, 'footer_why')) . '</p>'
        . ($links ? '<p class="em-m" style="margin:0 0 8px;font:400 12px/1.8 ' . $f . ';color:' . MAIL_UI['muted'] . '">' . implode(' &nbsp;·&nbsp; ', $links) . '</p>' : '')
        . '<p class="em-m" style="margin:0;font:400 12px/1.6 ' . $f . ';color:#9aa3b5">dehini.com · ' . h(ms($lang, 'address')) . '</p>'
        . '</td></tr></table>'
        . '</td></tr></table></body></html>';
}

/* --------------------------------------------------------------- liens */

function unsub_url(array $user, string $type): string
{
    return site_url() . 'api.php?action=unsub&type=' . $type . '&token=' . rawurlencode((string) $user['utoken']);
}

function unsub_links(array $user): array
{
    $l = $user['lang'];
    return [
        ms($l, 'unsub_daily')  => unsub_url($user, 'daily'),
        ms($l, 'unsub_digest') => unsub_url($user, 'digest'),
        ms($l, 'unsub_all')    => unsub_url($user, 'all'),
    ];
}

/* --------------------------------------------------------------- messages */

/** Question du jour avec trois boutons de réponse directe. */
function compose_daily(array $user, array $q, int $answered, int $total): array
{
    $l = $user['lang'];
    $site = site_url();
    $link = function (int $v) use ($site, $l, $q): string { return $site . '?lang=' . $l . '&q=' . $q['id'] . '&a=' . $v . '#resultat/' . $q['id']; };
    $qt = typo($l, qtext($q, $l));
    $inner = m_p($l, ms($l, 'hello', ['name' => $user['name']]))
        . m_p($l, ms($l, 'daily_intro'), 'color:#4b5470')
        . m_question($l, $q, $link)
        . m_small($l, ms($l, 'daily_progress', ['n' => $answered, 't' => $total]), 'margin:0');
    $text = ms($l, 'hello', ['name' => $user['name']]) . "\n\n" . ms($l, 'daily_intro') . "\n\n" . $qt . "\n\n";
    foreach (['neg' => -1, 'none' => 0, 'pos' => 1] as $k => $v) $text .= '- ' . ms($l, $k) . ' : ' . $link($v) . "\n";
    $text .= "\n" . ms($l, 'daily_progress', ['n' => $answered, 't' => $total]);
    $subject = ms($l, 'daily_subject', ['q' => $qt]);
    return [$subject, mail_layout($l, $subject, $qt, $inner, unsub_links($user)), $text . mail_text_footer($user)];
}

/** Rappel « racontez votre impact positif » quand toutes les questions ont une réponse. */
function compose_story_prompt(array $user, array $q): array
{
    $l = $user['lang'];
    $f = mail_font($l);
    $href = site_url() . '?lang=' . $l . '#resultat/' . $q['id'];
    $qt = typo($l, qtext($q, $l));
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'hello', ['name' => $user['name']])) . '</p>'
        . '<p style="margin:0 0 18px;font:800 22px/1.35 ' . $f . ';color:#0b1020">' . h($qt) . '</p>'
        . '<p style="margin:0;color:#4b5470">' . h(ms($l, 'story_intro')) . '</p>'
        . mail_button($href, ms($l, 'story_cta'), MAIL_COLORS['pos'], $l);
    $text = ms($l, 'hello', ['name' => $user['name']]) . "\n\n" . $qt . "\n\n" . ms($l, 'story_intro') . "\n\n" . ms($l, 'story_cta') . ' : ' . $href;
    $subject = ms($l, 'story_subject');
    return [$subject, mail_layout($l, $subject, $qt, $inner, unsub_links($user)), $text . mail_text_footer($user)];
}

/** Impacts positifs des autres sur les questions où l'utilisateur a répondu négatif / aucun. */
function compose_digest(array $user, array $stories): array
{
    $l = $user['lang'];
    $f = mail_font($l);
    $qs = questions(true);
    $n = count($stories);
    $blocks = '';
    $text = ms($l, 'hello', ['name' => $user['name']]) . "\n\n" . ms($l, 'digest_intro') . "\n\n";
    foreach ($stories as $s) {
        $q = $qs[(int) $s['qid']] ?? null;
        if (!$q) continue;
        $author = $s['author'] !== '' ? $s['author'] : ms($l, 'anon');
        $exp = mb_strimwidth((string) $s['exp'], 0, 320, '…');
        $imp = mb_strimwidth((string) $s['imp'], 0, 240, '…');
        $blocks .= m_story($l, $s, $q, '— ' . ugc($author));
        $text .= '* ' . qtext($q, $l) . "\n  « " . $exp . " »\n  " . ms($l, 'digest_impact') . ' ' . $imp . "\n  — " . $author . "\n\n";
    }
    $href = site_url() . '?lang=' . $l . '#resultat';
    $inner = m_p($l, ms($l, 'hello', ['name' => $user['name']])) . m_p($l, ms($l, 'digest_intro'), 'color:#4b5470')
        . $blocks . m_btn($l, $href, ms($l, 'digest_cta'), 'cobalt');
    $text .= ms($l, 'digest_cta') . ' : ' . $href;
    $subject = $n === 1 ? ms($l, 'digest_subject_1') : ms($l, 'digest_subject_n', ['n' => $n]);
    return [$subject, mail_layout($l, $subject, ms($l, 'digest_intro'), $inner, unsub_links($user)), $text . mail_text_footer($user)];
}

function compose_welcome(array $user): array
{
    $l = $user['lang'];
    $href = site_url() . 'api.php?action=verify&token=' . rawurlencode((string) $user['vtoken']);
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'hello', ['name' => $user['name']])) . '</p>'
        . '<p style="margin:0 0 14px">' . h(ms($l, 'welcome_intro')) . '</p>'
        . '<p style="margin:0;color:#4b5470">' . h(ms($l, 'welcome_confirm')) . '</p>'
        . mail_button($href, ms($l, 'welcome_cta'), MAIL_COLORS['pos'], $l);
    $text = ms($l, 'hello', ['name' => $user['name']]) . "\n\n" . ms($l, 'welcome_intro') . "\n\n" . ms($l, 'welcome_confirm') . "\n" . $href;
    $subject = ms($l, 'welcome_subject');
    return [$subject, mail_layout($l, $subject, ms($l, 'welcome_intro'), $inner), $text];
}

function compose_reset(array $user, string $tokenPlain): array
{
    $l = $user['lang'];
    $href = site_url() . '?lang=' . $l . '&reset=' . rawurlencode($tokenPlain);
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'hello', ['name' => $user['name']])) . '</p>'
        . '<p style="margin:0">' . h(ms($l, 'reset_intro')) . '</p>'
        . mail_button($href, ms($l, 'reset_cta'), MAIL_COLORS['ink'], $l)
        . '<p style="margin:0;font-size:13px;color:#6b7185">' . h(ms($l, 'reset_ignore')) . '</p>';
    $text = ms($l, 'hello', ['name' => $user['name']]) . "\n\n" . ms($l, 'reset_intro') . "\n\n" . $href . "\n\n" . ms($l, 'reset_ignore');
    $subject = ms($l, 'reset_subject');
    return [$subject, mail_layout($l, $subject, ms($l, 'reset_intro'), $inner), $text];
}

/** Avis de nouveau message dans une conversation privée (l'extrait part au seul destinataire). */
function compose_message(array $to, string $from, string $question, string $body, int $tid, bool $toOwner): array
{
    $l = $to['lang'];
    $f = mail_font($l);
    $href = site_url() . '?lang=' . $l . '#messages/' . $tid;
    $intro = ms($l, $toOwner ? 'msg_intro_owner' : 'msg_intro_guest', ['name' => $from]);
    $excerpt = mb_strimwidth($body, 0, 600, '…');
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'hello', ['name' => $to['name']])) . '</p>'
        . '<p style="margin:0 0 8px;font:700 13px/1.4 ' . $f . ';color:#6b7185">' . h(typo($l, $question)) . '</p>'
        . '<p style="margin:0 0 10px">' . h($intro) . '</p>'
        . '<p style="margin:0;padding:14px 16px;border-radius:14px;background:#f2f4f8;color:#0b1020">' . ugc($excerpt) . '</p>'
        . mail_button($href, ms($l, 'msg_cta'), MAIL_COLORS['pos'], $l)
        . '<p style="margin:0;font-size:13px;color:#6b7185">' . h(ms($l, 'msg_private')) . '</p>';
    $subject = ms($l, $toOwner ? 'msg_subject_owner' : 'msg_subject_guest');
    $unsub = [ms($l, 'unsub_msg') => unsub_url($to, 'msg'), ms($l, 'unsub_all') => unsub_url($to, 'all')];
    $text = ms($l, 'hello', ['name' => $to['name']]) . "\n\n" . $question . "\n\n" . $intro . "\n\n« " . $excerpt . " »\n\n"
        . ms($l, 'msg_cta') . ' : ' . $href . "\n\n" . ms($l, 'msg_private') . "\n\n--\n" . ms($l, 'footer_why') . "\n";
    foreach ($unsub as $label => $url) $text .= $label . ' : ' . $url . "\n";
    return [$subject, mail_layout($l, $subject, $intro, $inner, $unsub), $text];
}

/** Montant en dinars, écrit à la façon de chaque langue. */
function money_da(string $l, int $n): string
{
    if ($l === 'ar') return $n . ' دج';
    if ($l === 'en') return number_format($n) . ' DZD';
    return number_format($n, 0, ',', "\u{00A0}") . "\u{00A0}DA";
}

/** Confirmation d'un abonnement sans compte (rien ne part avant le clic). */
function compose_sub_confirm(array $sub): array
{
    $l = impact_lang($sub['lang']);
    $href = site_url() . 'api.php?action=sub_confirm&token=' . rawurlencode((string) $sub['token']);
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'alert_hello')) . '</p>'
        . '<p style="margin:0">' . h(ms($l, 'sub_intro')) . '</p>'
        . mail_button($href, ms($l, 'sub_cta'), MAIL_COLORS['pos'], $l)
        . '<p style="margin:0;font-size:13px;color:#6b7185">' . h(ms($l, 'sub_ignore')) . '</p>';
    $text = ms($l, 'alert_hello') . "\n\n" . ms($l, 'sub_intro') . "\n" . $href . "\n\n" . ms($l, 'sub_ignore');
    $subject = ms($l, 'sub_subject');
    return [$subject, mail_layout($l, $subject, ms($l, 'sub_intro'), $inner), $text];
}

/**
 * Alerte : de nouvelles expériences positives sur des questions où la personne a répondu
 * « négatif ». $to = ['email', 'lang', 'name' (vide pour un abonné sans compte)].
 * Chaque récit renvoie vers la page de sa question ; celui d'un pro, vers sa page.
 */
function compose_alert(array $to, array $stories, string $unsubUrl): array
{
    require_once __DIR__ . '/seo.php';
    $l = impact_lang($to['lang']);
    $f = mail_font($l);
    $qs = questions(true);
    $pros = function_exists('pros_active') ? pros_active() : [];
    $blocks = '';
    $hello = $to['name'] !== '' ? ms($l, 'hello', ['name' => $to['name']]) : ms($l, 'alert_hello');
    $text = $hello . "\n\n" . ms($l, 'alert_intro') . "\n\n";
    foreach ($stories as $s) {
        $q = $qs[(int) $s['qid']] ?? null;
        if (!$q) continue;
        $pro = !empty($s['pro_id']) && isset($pros[(int) $s['pro_id']]) ? $pros[(int) $s['pro_id']] : null;
        $author = $s['author'] !== '' ? $s['author'] : ms($l, 'anon');
        $exp = mb_strimwidth((string) $s['exp'], 0, 320, '…');
        $imp = mb_strimwidth((string) $s['imp'], 0, 240, '…');
        $url = q_url($q, $l);
        $who = '— ' . ugc($author) . ($pro ? ' · <a href="' . h(pro_url($pro, $l)) . '" style="color:#2f5bff">' . h(ms($l, 'alert_pro')) . '</a>' : '');
        $blocks .= m_story($l, $s, $q, $who, $url, ms($l, 'alert_read'));
        $text .= '* ' . qtext($q, $l) . "\n  « " . $exp . " »\n  " . ms($l, 'digest_impact') . ' ' . $imp . "\n  — " . $author
            . ($pro ? ' (' . pro_url($pro, $l) . ')' : '') . "\n  " . $url . "\n\n";
    }
    $inner = m_p($l, $hello) . m_p($l, ms($l, 'alert_intro'), 'color:#4b5470') . $blocks;
    $n = count($stories);
    $first = $qs[(int) $stories[0]['qid']] ?? [];
    $subject = $n === 1 ? ms($l, 'alert_subject_1', ['q' => mb_strimwidth(qtext($first, $l), 0, 70, '…')]) : ms($l, 'alert_subject_n', ['n' => $n]);
    $unsub = [ms($l, 'sub_unsub') => $unsubUrl];
    $text .= "--\n" . ms($l, 'footer_why') . "\n" . ms($l, 'sub_unsub') . ' : ' . $unsubUrl . "\n";
    return [$subject, mail_layout($l, $subject, ms($l, 'alert_intro'), $inner, $unsub), $text];
}

/** Remerciement après un soutien payé en ligne. */
function compose_thanks(array $don): array
{
    $l = impact_lang($don['lang']);
    $intro = ms($l, 'thanks_intro', ['amount' => money_da($l, (int) $don['amount'])]);
    $href = site_url() . '?lang=' . $l;
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'hello', ['name' => $don['name']])) . '</p>'
        . '<p style="margin:0 0 14px">' . h($intro) . '</p>'
        . '<p style="margin:0;font-weight:700;color:#079455">' . h(ms($l, 'thanks_sign')) . '</p>'
        . mail_button($href, ms($l, 'back'), MAIL_COLORS['ink'], $l);
    $text = ms($l, 'hello', ['name' => $don['name']]) . "\n\n" . $intro . "\n\n" . ms($l, 'thanks_sign') . "\n\n" . $href;
    $subject = ms($l, 'thanks_subject');
    return [$subject, mail_layout($l, $subject, $intro, $inner), $text];
}

function mail_text_footer(array $user): string
{
    $l = $user['lang'];
    $out = "\n\n--\n" . ms($l, 'footer_why') . "\n";
    foreach (unsub_links($user) as $label => $url) $out .= $label . ' : ' . $url . "\n";
    return $out;
}

/* --------------------------------------------------------------- envoi */

function mail_from(): string
{
    if (IMPACT_MAIL_FROM !== '') return IMPACT_MAIL_FROM;
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'dehini.com';
    return 'impact@' . preg_replace('/^www\./', '', (string) $host);
}

/**
 * En-tête non ASCII (sujet, nom) en « encoded-words » RFC 2047 courts (lignes de moins de 78 caractères), repliés
 * sur plusieurs lignes ($fold) : un seul long bloc est mal noté par certains filtres anti-spam.
 */
function encode_header(string $s, string $fold = "\r\n "): string
{
    if (!preg_match('/[^\x20-\x7E]/', $s)) return $s;
    $words = [];
    $chunk = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (strlen($chunk . $ch) > 39) { $words[] = $chunk; $chunk = ''; }
        $chunk .= $ch;
    }
    if ($chunk !== '') $words[] = $chunk;
    return implode($fold, array_map(function ($w) { return '=?UTF-8?B?' . base64_encode($w) . '?='; }, $words));
}

/**
 * Construit le message MIME (texte + HTML).
 * @return array{0:string,1:string} [en-têtes sans To/Subject, corps]
 */
function build_mime(string $html, string $text, ?string $unsubUrl): array
{
    $from = mail_from();
    $domain = substr(strrchr($from, '@') ?: '@dehini.com', 1);
    $b = 'b_' . token(12);
    $headers = [
        'From: ' . encode_header(IMPACT_MAIL_FROM_NAME) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . token(16) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ];
    if ($unsubUrl) {
        $headers[] = 'List-Unsubscribe: <' . $unsubUrl . '>';
        $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }
    $body = '--' . $b . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . mime_qp($text) . "\r\n"
        . '--' . $b . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
        . mime_qp($html) . "\r\n"
        . '--' . $b . "--\r\n";
    return [implode("\r\n", $headers), $body];
}

/**
 * Corps en « quoted-printable » (lisible, lignes de 76 caractères) : l'encodage attendu des emails
 * courants. Le base64 sur du texte est un signal (faible) de spam pour plusieurs filtres.
 */
function mime_qp(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    return quoted_printable_encode(str_replace("\n", "\r\n", $s));
}

/** Envoie un email. Lève une exception en cas d'échec. */
function send_mail(string $to, string $subject, string $html, string $text, ?string $unsubUrl = null): void
{
    [$headers, $body] = build_mime($html, $text, $unsubUrl);
    if (IMPACT_SMTP_HOST === '') {
        // mail() : le sujet reste sur une ligne (segments séparés par une espace).
        if (!@mail($to, encode_header($subject, ' '), $body, $headers, '-f' . mail_from())) {
            throw new RuntimeException('mail() a échoué');
        }
        return;
    }
    $data = 'To: <' . $to . ">\r\n" . 'Subject: ' . encode_header($subject) . "\r\n" . $headers . "\r\n\r\n" . $body;
    Smtp::shared()->send(mail_from(), $to, $data);
}

/** Client SMTP minimal (SSL / STARTTLS, AUTH LOGIN), une connexion réutilisée pour tout un lot. */
final class Smtp
{
    private static ?Smtp $inst = null;
    /** @var resource|null */
    private $fp = null;

    public static function shared(): Smtp
    {
        return self::$inst ??= new Smtp();
    }

    public static function close(): void
    {
        if (self::$inst && self::$inst->fp) {
            @fwrite(self::$inst->fp, "QUIT\r\n");
            @fclose(self::$inst->fp);
        }
        self::$inst = null;
    }

    private function connect(): void
    {
        $secure = IMPACT_SMTP_SECURE;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => (bool) IMPACT_SMTP_VERIFY,
            'verify_peer_name' => (bool) IMPACT_SMTP_VERIFY,
            'allow_self_signed' => !IMPACT_SMTP_VERIFY,
            'peer_name' => IMPACT_SMTP_HOST,
        ]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . IMPACT_SMTP_HOST . ':' . (int) IMPACT_SMTP_PORT;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new RuntimeException("SMTP : connexion impossible ($errstr)");
        stream_set_timeout($fp, 30);
        $this->fp = $fp;
        $this->expect([220]);
        $helo = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
        $this->cmd('EHLO ' . $helo, [250]);
        if ($secure === 'tls') {
            $this->cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP : STARTTLS a échoué');
            }
            $this->cmd('EHLO ' . $helo, [250]);
        }
        if (IMPACT_SMTP_USER !== '') {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode(IMPACT_SMTP_USER), [334]);
            $this->cmd(base64_encode(IMPACT_SMTP_PASS), [235]);
        }
    }

    public function send(string $from, string $to, string $data): void
    {
        if (!$this->fp) $this->connect();
        try {
            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        } catch (RuntimeException $e) {
            // Connexion fermée par le serveur entre deux envois : on la rouvre une fois.
            @fclose($this->fp);
            $this->fp = null;
            $this->connect();
            $this->cmd('MAIL FROM:<' . $from . '>', [250]);
        }
        try {
            $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $this->cmd('DATA', [354]);
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));
            fwrite($this->fp, $data . "\r\n.\r\n");
            $this->expect([250]);
        } catch (RuntimeException $e) {
            @fwrite($this->fp, "RSET\r\n");
            @$this->read();
            throw $e;
        }
    }

    private function cmd(string $line, array $codes): string
    {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect($codes);
    }

    private function read(): string
    {
        $out = '';
        while (($l = fgets($this->fp, 1024)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $out;
    }

    private function expect(array $codes): string
    {
        $r = $this->read();
        if (!in_array((int) substr($r, 0, 3), $codes, true)) {
            throw new RuntimeException('SMTP : réponse inattendue « ' . trim($r) . ' »');
        }
        return $r;
    }
}
