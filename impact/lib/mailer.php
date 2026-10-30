<?php
/**
 * Emails du défi Impact : textes (fr / ar / en), gabarit HTML et envoi.
 * L'envoi passe par SMTP si IMPACT_SMTP_HOST est renseigné (recommandé : un
 * compte email cPanel, ex. impact@dehini.com), sinon par la fonction mail().
 */
declare(strict_types=1);

const MAIL_STR = [
    'fr' => [
        'brand' => 'Défi Impact', 'tagline' => 'Le défi des expériences positives',
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
    ],
    'ar' => [
        'brand' => 'تحدي الأثر', 'tagline' => 'تحدّي التجارب الإيجابية',
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
    ],
    'en' => [
        'brand' => 'Impact Challenge', 'tagline' => 'The positive experiences challenge',
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

const MAIL_COLORS = ['neg' => '#ff5d5d', 'none' => '#8b93a7', 'pos' => '#17b26a', 'ink' => '#0b1020', 'cobalt' => '#2f5bff'];

function mail_font(string $lang): string
{
    return $lang === 'ar'
        ? "Tahoma,'Segoe UI',Arial,sans-serif"
        : "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
}

function mail_button(string $href, string $label, string $bg = MAIL_COLORS['ink'], string $lang = 'fr'): string
{
    $f = mail_font($lang);
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0"><tr><td style="border-radius:999px;background:' . $bg . '">'
        . '<a href="' . h($href) . '" style="display:inline-block;padding:14px 26px;font:700 15px/1 ' . $f . ';color:#ffffff;text-decoration:none;border-radius:999px">' . h($label) . '</a>'
        . '</td></tr></table>';
}

/**
 * @param array<string,string> $unsub libellé => url (pied de page)
 */
function mail_layout(string $lang, string $title, string $preheader, string $inner, array $unsub = []): string
{
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $align = $lang === 'ar' ? 'right' : 'left';
    $f = mail_font($lang);
    $dots = '';
    foreach (['neg', 'none', 'pos'] as $c) {
        $dots .= '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . MAIL_COLORS[$c] . ';margin:0 3px"></span>';
    }
    $links = [];
    foreach ($unsub as $label => $url) {
        $links[] = '<a href="' . h($url) . '" style="color:#6b7185;text-decoration:underline">' . h($label) . '</a>';
    }
    $site = site_url();
    return '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">'
        . '<title>' . h($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f0f1f4;-webkit-text-size-adjust:100%">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . h($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f1f4"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="' . $dir . '" style="max-width:560px;background:#ffffff;border-radius:22px;overflow:hidden">'
        . '<tr><td style="background:' . MAIL_COLORS['ink'] . ';padding:22px 28px;text-align:' . $align . '">'
        . '<a href="' . h($site) . '" style="text-decoration:none">' . $dots
        . '<span style="font:800 18px/1 ' . $f . ';color:#ffffff;vertical-align:middle;margin:0 8px">' . h(ms($lang, 'brand')) . '</span></a>'
        . '<div style="font:400 13px/1.4 ' . $f . ';color:#9aa3b5;margin-top:8px">' . h(ms($lang, 'tagline')) . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:30px 28px 26px;font:400 16px/1.6 ' . $f . ';color:#20283f;text-align:' . $align . '">' . $inner . '</td></tr>'
        . '</table>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px"><tr><td dir="' . $dir . '" style="padding:18px 20px;font:400 12px/1.6 ' . $f . ';color:#6b7185;text-align:center">'
        . h(ms($lang, 'footer_why')) . ($links ? '<br>' . implode(' · ', $links) : '')
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
    $f = mail_font($l);
    $cells = '';
    foreach (['neg' => -1, 'none' => 0, 'pos' => 1] as $k => $v) {
        $href = $site . '?lang=' . $l . '&q=' . $q['id'] . '&a=' . $v . '#resultat/' . $q['id'];
        $cells .= '<td width="33%" style="padding:4px"><a href="' . h($href) . '" style="display:block;padding:14px 6px;border-radius:14px;background:'
            . MAIL_COLORS[$k] . ';color:#ffffff;text-decoration:none;text-align:center;font:700 14px/1.25 ' . $f . '">' . h(ms($l, $k)) . '</a></td>';
    }
    $qt = typo($l, qtext($q, $l));
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'hello', ['name' => $user['name']])) . '</p>'
        . '<p style="margin:0 0 18px;color:#4b5470">' . h(ms($l, 'daily_intro')) . '</p>'
        . '<p style="margin:0 0 20px;font:800 23px/1.35 ' . $f . ';color:#0b1020">' . h($qt) . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>' . $cells . '</tr></table>'
        . '<p style="margin:20px 0 0;font-size:13px;color:#6b7185">' . h(ms($l, 'daily_progress', ['n' => $answered, 't' => $total])) . '</p>';
    $text = ms($l, 'hello', ['name' => $user['name']]) . "\n\n" . ms($l, 'daily_intro') . "\n\n" . $qt . "\n\n";
    foreach (['neg' => -1, 'none' => 0, 'pos' => 1] as $k => $v) {
        $text .= '- ' . ms($l, $k) . ' : ' . $site . '?lang=' . $l . '&q=' . $q['id'] . '&a=' . $v . '#resultat/' . $q['id'] . "\n";
    }
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
        $blocks .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border-radius:16px;background:#f7f7f9"><tr><td style="padding:18px 20px">'
            . '<p style="margin:0 0 10px;font:700 14px/1.4 ' . $f . ';color:#4b5470">' . h(typo($l, qtext($q, $l))) . '</p>'
            . '<p style="margin:0 0 10px;font:400 15px/1.6 ' . $f . ';color:#0b1020;border-inline-start:3px solid #17b26a;padding-inline-start:12px">' . ugc($exp) . '</p>'
            . '<p style="margin:0 0 8px;font:400 14px/1.6 ' . $f . ';color:#079455"><b>' . h(ms($l, 'digest_impact')) . '</b> ' . ugc($imp) . '</p>'
            . '<p style="margin:0;font:400 12px/1.4 ' . $f . ';color:#6b7185">— ' . ugc($author) . '</p>'
            . '</td></tr></table>';
        $text .= '* ' . qtext($q, $l) . "\n  « " . $exp . " »\n  " . ms($l, 'digest_impact') . ' ' . $imp . "\n  — " . $author . "\n\n";
    }
    $href = site_url() . '?lang=' . $l . '#resultat';
    $inner = '<p style="margin:0 0 14px">' . h(ms($l, 'hello', ['name' => $user['name']])) . '</p>'
        . '<p style="margin:0 0 20px;color:#4b5470">' . h(ms($l, 'digest_intro')) . '</p>'
        . $blocks . mail_button($href, ms($l, 'digest_cta'), MAIL_COLORS['cobalt'], $l);
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

function encode_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
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
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n"
        . '--' . $b . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n"
        . '--' . $b . "--\r\n";
    return [implode("\r\n", $headers), $body];
}

/** Envoie un email. Lève une exception en cas d'échec. */
function send_mail(string $to, string $subject, string $html, string $text, ?string $unsubUrl = null): void
{
    [$headers, $body] = build_mime($html, $text, $unsubUrl);
    if (IMPACT_SMTP_HOST === '') {
        if (!@mail($to, encode_header($subject), $body, $headers, '-f' . mail_from())) {
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
