<?php
/**
 * Page du défi (index.html) servie avec un en-tête adapté à la langue demandée :
 * /impact/?lang=ar et /impact/?lang=en ont leur propre adresse canonique, leur langue,
 * leur titre et leur description, pour que les moteurs indexent chaque version.
 * /impact/ sans paramètre reste la page d'accueil (x-default) qui suit la langue du visiteur.
 * Au moindre souci, la page est servie telle quelle.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

$html = (string) @file_get_contents(__DIR__ . '/index.html');
$lang = $_GET['lang'] ?? '';
if ($html === '' || !in_array($lang, ['fr', 'ar', 'en'], true)) {
    echo $html;
    exit;
}

const HEAD = [
    'fr' => ['Défi Impact — partagez vos expériences positives · dehini.com',
        '1000 questions sur votre vie personnelle, sociale et professionnelle. Pour chacune : impact négatif, aucun ou positif ? Découvrez votre empreinte et inspirez les autres avec vos expériences positives.', 'fr_DZ'],
    'ar' => ['تحدي الأثر — شارك تجاربك الإيجابية · dehini.com',
        '1000 سؤال عن حياتك الشخصية والاجتماعية والمهنية. لكل سؤال: أثر سلبي أم بلا أثر أم أثر إيجابي؟ اكتشف بصمتك وألهِم الآخرين بتجاربك الإيجابية.', 'ar_DZ'],
    'en' => ['Impact Challenge — share your positive experiences · dehini.com',
        '1000 questions about your personal, social and professional life. For each one: negative, no or positive impact? Discover your footprint and inspire others with your positive experiences.', 'en_US'],
];
[$title, $desc, $locale] = HEAD[$lang];
$e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
$canonical = 'https://dehini.com/impact/?lang=' . $lang;

$out = preg_replace(
    [
        '~<html lang="fr" dir="ltr">~',
        '~<title>[^<]*</title>~u',
        '~<meta name="description" content="[^"]*">~u',
        '~<link rel="canonical" href="[^"]*">~',
        '~<meta property="og:title" content="[^"]*">~u',
        '~<meta property="og:description" content="[^"]*">~u',
        '~<meta property="og:url" content="[^"]*">~',
        '~<meta property="og:locale" content="[^"]*">~',
    ],
    [
        '<html lang="' . $lang . '" dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '">',
        '<title>' . $e($title) . '</title>',
        '<meta name="description" content="' . $e($desc) . '">',
        '<link rel="canonical" href="' . $canonical . '">',
        '<meta property="og:title" content="' . $e($title) . '">',
        '<meta property="og:description" content="' . $e($desc) . '">',
        '<meta property="og:url" content="' . $canonical . '">',
        '<meta property="og:locale" content="' . $locale . '">',
    ],
    $html,
    1
);
echo is_string($out) && $out !== '' ? $out : $html;
