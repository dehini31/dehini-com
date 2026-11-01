<?php
/**
 * Mise en forme très simple des textes saisis dans l'admin (sans HTML libre) :
 * ## titre, ### sous-titre, listes « - » et « 1. », **gras**, *italique*, [lien](https://…),
 * paragraphes séparés par une ligne vide. Tout le reste est échappé.
 */
declare(strict_types=1);

function md_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s) ?? $s;
    $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $s) ?? $s;
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) {
        $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!preg_match('~^(https?://|mailto:|tel:|/|#)~i', $href)) return $m[1];
        $ext = preg_match('~^https?://~i', $href) && strpos($href, site_origin()) !== 0;
        return '<a href="' . e($href) . '"' . ($ext ? ' rel="noopener" target="_blank"' : '') . '>' . $m[1] . '</a>';
    }, $s) ?? $s;
    return $s;
}

function markdown(string $text): string
{
    $lines = preg_split("/\n/", str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [];
    $html = '';
    $para = [];
    $list = null; // 'ul' | 'ol'
    $flushPara = function () use (&$para, &$html) {
        if ($para) $html .= '<p>' . implode('<br>', array_map('md_inline', $para)) . "</p>\n";
        $para = [];
    };
    $closeList = function () use (&$list, &$html) {
        if ($list) $html .= "</$list>\n";
        $list = null;
    };
    foreach ($lines as $line) {
        $l = rtrim($line);
        if ($l === '') { $flushPara(); $closeList(); continue; }
        if (preg_match('/^(#{2,4})\s+(.+)$/u', $l, $m)) {
            $flushPara(); $closeList();
            $lvl = strlen($m[1]);
            $html .= "<h$lvl>" . md_inline($m[2]) . "</h$lvl>\n";
            continue;
        }
        if (preg_match('/^\s*[-•]\s+(.+)$/u', $l, $m) || preg_match('/^\s*\d+[.)]\s+(.+)$/u', $l, $m2)) {
            $flushPara();
            $type = isset($m[1]) && preg_match('/^\s*[-•]\s+/u', $l) ? 'ul' : 'ol';
            $item = $type === 'ul' ? $m[1] : $m2[1];
            if ($list !== $type) { $closeList(); $html .= "<$type>\n"; $list = $type; }
            $html .= '<li>' . md_inline($item) . "</li>\n";
            continue;
        }
        $closeList();
        $para[] = $l;
    }
    $flushPara();
    $closeList();
    return $html;
}

/** Texte brut (pour les descriptions et Schema.org). */
function md_plain(string $text, int $max = 0): string
{
    $s = preg_replace('/^#{1,4}\s+/m', '', $text) ?? $text;
    $s = preg_replace('/\*\*?(.+?)\*\*?/u', '$1', $s) ?? $s;
    $s = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $s) ?? $s;
    $s = preg_replace('/^\s*([-•]|\d+[.)])\s+/m', '', $s) ?? $s;
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    if ($max && mb_strlen($s) > $max) $s = rtrim(mb_substr($s, 0, $max - 1)) . '…';
    return $s;
}

function reading_minutes(string $text): int
{
    $words = count(preg_split('/\s+/u', trim(md_plain($text))) ?: []);
    return max(1, (int) round($words / 200));
}
