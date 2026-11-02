<?php
/**
 * Pages publiques lisibles par les moteurs de recherche (rendues par le serveur) :
 *   ?q=12&lang=fr|ar|en      une question : chiffres, récits publiés, réponse en un clic, partage
 *   ?hub=1&lang=fr|ar|en     toutes les questions, par univers
 * Les adresses lisibles (/impact/q/12-…, /impact/ar/questions…) sont réécrites ici par .htaccess.
 */
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/mailer.php'; // h()
require __DIR__ . '/lib/seo.php';

header('X-Content-Type-Options: nosniff');
header('Content-Type: text/html; charset=utf-8');

$lang = impact_lang($_GET['lang'] ?? 'fr');
$dir = $lang === 'ar' ? 'rtl' : 'ltr';
$viaRewrite = strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), 'page.php') === false;

function nf(int $n, string $lang): string
{
    return $lang === 'fr' ? number_format($n, 0, ',', "\u{202F}") : number_format($n, 0, '.', $lang === 'en' ? ',' : '');
}
function pct(int $part, int $total): int
{
    return $total ? (int) round($part / $total * 100) : 0;
}
/** Pourcentage écrit à la façon de la langue (« 42 % » en français, « 42% » sinon). */
function pc(int $part, int $total, string $lang): string
{
    return pct($part, $total) . ($lang === 'fr' ? "\u{00A0}%" : '%');
}
/** Texte écrit par un participant : échappé, retours à la ligne gardés, sens d'écriture automatique. */
function ugc_html(string $s): string
{
    return nl2br(h($s), false);
}

/* ------------------------------------------------------------ données */

$qs = questions();
$id = (int) ($_GET['q'] ?? 0);
$isHub = !$id && !empty($_GET['hub']);
$q = $id ? ($qs[$id] ?? null) : null;

if (!$isHub && !$q) {
    http_response_code(404);
    $page = ['title' => seo_t($lang, 'brand'), 'desc' => '', 'canonical' => '', 'robots' => 'noindex,follow', 'alts' => [], 'ld' => []];
} elseif ($isHub) {
    $storyCount = [];
    foreach (db()->query("SELECT qid, COUNT(*) AS n FROM stories WHERE status = 'published' GROUP BY qid") as $r) $storyCount[(int) $r['qid']] = (int) $r['n'];
    $total = count($qs);
    $alts = [];
    foreach (IMPACT_LANGS as $l) $alts[$l] = hub_url($l);
    $page = [
        'title' => seo_t($lang, 'hub_title') . ' · dehini.com', 'desc' => seo_t($lang, 'hub_desc', ['n' => nf($total, $lang)]),
        'canonical' => hub_url($lang), 'robots' => 'index,follow', 'alts' => $alts,
        'ld' => [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => seo_t($lang, 'brand'), 'item' => home_url($lang)],
            ['@type' => 'ListItem', 'position' => 2, 'name' => seo_t($lang, 'all'), 'item' => hub_url($lang)],
        ]]],
    ];
} else {
    // Adresse non canonique (ancien libellé, sans libellé…) : redirection permanente vers la bonne.
    if ($viaRewrite && (string) ($_GET['s'] ?? '') !== q_slug($q, $lang)) {
        header('Location: ' . q_url($q, $lang), true, 301);
        exit;
    }
    $n = [-1 => 0, 0 => 0, 1 => 0];
    $s = db()->prepare('SELECT v, COUNT(*) AS c FROM answers WHERE qid = ? GROUP BY v');
    $s->execute([$id]);
    foreach ($s as $r) $n[(int) $r['v']] = (int) $r['c'];
    $answers = $n[-1] + $n[0] + $n[1];
    $s = db()->prepare("SELECT author, exp, imp, lang, likes FROM stories WHERE status = 'published' AND qid = ? ORDER BY (lang = ?) DESC, likes DESC, created DESC LIMIT 30");
    $s->execute([$id, $lang]);
    $stories = $s->fetchAll();
    $s = db()->prepare("SELECT COUNT(*) FROM stories WHERE status = 'published' AND qid = ?");
    $s->execute([$id]);
    $storyTotal = (int) $s->fetchColumn();
    // Questions proches : les suivantes du même univers, dans l'ordre du défi.
    $same = array_values(array_filter($qs, function ($x) use ($q) { return $x['cat'] === $q['cat']; }));
    $at = (int) array_search($id, array_column($same, 'id'), true);
    $related = [];
    for ($i = 1; $i <= min(8, count($same) - 1); $i++) $related[] = $same[($at + $i) % count($same)];
    $title = qtext($q, $lang);
    $alts = [];
    foreach (IMPACT_LANGS as $l) $alts[$l] = q_url($q, $l);
    $page = [
        'title' => seo_t($lang, 'q_title', ['q' => $title]),
        'desc' => $answers >= 5 ? seo_t($lang, 'q_desc_n', ['n' => nf($answers, $lang), 'p' => pct($n[1], $answers)]) : seo_t($lang, 'q_desc_0'),
        'canonical' => q_url($q, $lang),
        // Sans récit publié, la page reste accessible mais n'est pas proposée à l'indexation (contenu trop mince).
        'robots' => $storyTotal ? 'index,follow,max-image-preview:large' : 'noindex,follow',
        'alts' => $alts,
        'ld' => [['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => seo_t($lang, 'brand'), 'item' => home_url($lang)],
            ['@type' => 'ListItem', 'position' => 2, 'name' => seo_t($lang, 'all'), 'item' => hub_url($lang)],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => q_url($q, $lang)],
        ]]],
    ];
}

if (http_response_code() === 200) header('Cache-Control: public, max-age=600');
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) ob_start('ob_gzhandler');

/* ------------------------------------------------------------ rendu */

$answerUrl = function (int $v) use ($q, $lang): string {
    return home_url($lang) . '&q=' . $q['id'] . '&a=' . $v . '#resultat/' . $q['id'];
};
$catName = function (string $cat) use ($lang): string { return SEO_CATS[$lang][$cat] ?? $cat; };
?><!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $dir ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b1020">
<title><?= h($page['title']) ?></title>
<?php if ($page['desc'] !== ''): ?><meta name="description" content="<?= h($page['desc']) ?>">
<?php endif ?>
<meta name="robots" content="<?= $page['robots'] ?>">
<?php if ($page['canonical'] !== ''): ?>
<link rel="canonical" href="<?= h($page['canonical']) ?>">
<?php foreach ($page['alts'] as $l => $u): ?><link rel="alternate" hreflang="<?= $l ?>" href="<?= h($u) ?>">
<?php endforeach ?>
<link rel="alternate" hreflang="x-default" href="<?= h($page['alts']['fr']) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="<?= h(seo_t($lang, 'brand')) ?> · dehini.com">
<meta property="og:title" content="<?= h($page['title']) ?>">
<meta property="og:description" content="<?= h($page['desc']) ?>">
<meta property="og:url" content="<?= h($page['canonical']) ?>">
<meta property="og:locale" content="<?= ['fr' => 'fr_DZ', 'ar' => 'ar_DZ', 'en' => 'en_US'][$lang] ?>">
<meta property="og:image" content="<?= h(seo_base()) ?>assets/og-image.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<?php endif ?>
<link rel="icon" type="image/svg+xml" href="<?= h(seo_base()) ?>assets/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700&family=Readex+Pro:wght@400;500;700&display=swap" rel="stylesheet">
<?php foreach ($page['ld'] as $ld): ?><script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach ?>
<style>
  :root{--ink:#0b1020;--ink-soft:#4b5470;--muted:#6b7185;--paper:#fff;--paper-50:#f7f7f9;--paper-100:#f0f1f4;--paper-200:#e3e5ea;
    --neg:#ff5d5d;--none:#8b93a7;--pos:#17b26a;--pos-600:#07854b;--pos-50:#eafaf2;--cobalt:#2f5bff;
    --perso:#7c5cff;--social:#2f5bff;--pro:#b86700;--font:"DM Sans",system-ui,-apple-system,"Segoe UI",sans-serif}
  html[lang=ar]{--font:"Readex Pro",system-ui,-apple-system,"Segoe UI",sans-serif}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:var(--font);background:var(--paper-50);color:var(--ink);line-height:1.6}
  a{color:inherit}
  .wrap{max-width:820px;margin:0 auto;padding:0 18px}
  header{background:var(--ink);color:#fff}
  header .wrap{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:60px;flex-wrap:wrap}
  .brand{display:flex;align-items:center;gap:8px;font-weight:700;text-decoration:none}
  .dots{display:inline-flex;gap:4px}.dots i{width:9px;height:9px;border-radius:50%;background:var(--neg)}.dots i:nth-child(2){background:var(--none)}.dots i:nth-child(3){background:var(--pos)}
  .langs{display:flex;gap:4px;font-size:.85rem}
  .langs a{padding:4px 10px;border-radius:999px;text-decoration:none;color:#c9cfdf}
  .langs a[aria-current]{background:#fff;color:var(--ink);font-weight:700}
  main{padding:26px 0 60px}
  .crumbs{font-size:.84rem;color:var(--muted);margin-bottom:14px}
  .crumbs a{color:var(--muted)}
  .chip{display:inline-block;padding:3px 12px;border-radius:999px;font-size:.8rem;font-weight:700;background:var(--paper-100);margin-bottom:12px}
  .chip.perso{color:var(--perso)}.chip.social{color:var(--social)}.chip.pro{color:var(--pro)}
  h1{font-size:clamp(1.6rem,4.5vw,2.4rem);line-height:1.2;letter-spacing:-.01em;margin-bottom:14px}
  h2{font-size:1.25rem;margin:34px 0 14px}
  .card{background:var(--paper);border-radius:20px;padding:22px;box-shadow:0 1px 2px rgba(11,16,32,.05),0 10px 30px -14px rgba(11,16,32,.18)}
  .answers{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0 8px}
  .answers a{display:block;text-align:center;padding:14px 8px;border-radius:14px;font-weight:700;text-decoration:none;color:#fff}
  .a-neg{background:var(--neg)}.a-none{background:var(--none)}.a-pos{background:var(--pos)}
  .hint{font-size:.84rem;color:var(--muted)}
  .bar{display:flex;height:12px;border-radius:999px;overflow:hidden;background:var(--paper-100);margin:10px 0 6px}
  .bar i{display:block;height:100%}
  .stats{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:.9rem;color:var(--ink-soft)}
  .story{background:var(--paper);border-radius:18px;padding:18px 20px;margin-bottom:12px;box-shadow:0 1px 2px rgba(11,16,32,.05)}
  .story blockquote{font-size:1.02rem;margin-bottom:10px}
  .story .imp{background:var(--pos-50);color:#0a5c37;border-radius:12px;padding:10px 12px;font-size:.95rem}
  .story .imp b{display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
  html[lang=ar] .story .imp b{letter-spacing:0;text-transform:none}
  .story .who{margin-top:10px;font-size:.84rem;color:var(--muted)}
  .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:999px;font-weight:700;text-decoration:none;background:var(--ink);color:#fff;border:0;font:inherit;font-weight:700;cursor:pointer}
  .btn.line{background:#fff;color:var(--ink);border:1px solid var(--paper-200)}
  .btn.pos{background:var(--pos)}
  .share{display:flex;flex-wrap:wrap;gap:8px}
  .list{list-style:none;display:grid;gap:8px}
  .list a{display:flex;justify-content:space-between;gap:12px;background:#fff;border-radius:12px;padding:11px 14px;text-decoration:none;box-shadow:0 1px 2px rgba(11,16,32,.05)}
  .list a:hover{box-shadow:0 6px 18px -10px rgba(11,16,32,.3)}
  .list .n{font-size:.8rem;color:var(--pos-600);white-space:nowrap;font-weight:700}
  .cats{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0}
  .lead{font-size:1.05rem;color:var(--ink-soft);max-width:680px}
  footer{border-top:1px solid var(--paper-200);padding:22px 0;font-size:.85rem;color:var(--muted)}
  footer .wrap{display:flex;flex-wrap:wrap;gap:8px 18px;justify-content:space-between}
  @media(max-width:480px){.answers{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <div class="wrap">
    <a class="brand" href="<?= h(home_url($lang)) ?>"><span class="dots"><i></i><i></i><i></i></span><?= h(seo_t($lang, 'brand')) ?></a>
    <?php if ($page['alts']): ?>
    <nav class="langs" aria-label="Langue">
      <?php foreach ($page['alts'] as $l => $u): ?><a href="<?= h($u) ?>" hreflang="<?= $l ?>" lang="<?= $l ?>"<?= $l === $lang ? ' aria-current="page"' : '' ?>><?= h(seo_t($l, 'lang_name')) ?></a><?php endforeach ?>
    </nav>
    <?php endif ?>
  </div>
</header>

<main class="wrap">
<?php if (!$isHub && !$q): ?>
  <h1><?= h(seo_t($lang, 'nf')) ?></h1>
  <p style="margin-top:18px"><a class="btn" href="<?= h(hub_url($lang)) ?>"><?= h(seo_t($lang, 'all')) ?></a></p>

<?php elseif ($isHub): ?>
  <p class="crumbs"><a href="<?= h(home_url($lang)) ?>"><?= h(seo_t($lang, 'brand')) ?></a> › <?= h(seo_t($lang, 'all')) ?></p>
  <h1><?= h(seo_t($lang, 'hub_h1', ['n' => nf($total, $lang)])) ?></h1>
  <p class="lead"><?= h(seo_t($lang, 'hub_intro')) ?></p>
  <p style="margin-top:18px"><a class="btn pos" href="<?= h(home_url($lang)) ?>"><?= h(seo_t($lang, 'start')) ?></a></p>
  <nav class="cats">
    <?php foreach (IMPACT_CATS as $c): ?><a class="chip <?= $c ?>" href="#<?= $c ?>"><?= h($catName($c)) ?></a><?php endforeach ?>
  </nav>
  <?php foreach (IMPACT_CATS as $c): ?>
  <h2 id="<?= $c ?>"><?= h($catName($c)) ?></h2>
  <ul class="list">
    <?php foreach ($qs as $x): if ($x['cat'] !== $c) continue; ?>
    <li><a href="<?= h(q_url($x, $lang)) ?>"><span dir="auto"><?= h(qtext($x, $lang)) ?></span><?php if (!empty($storyCount[$x['id']])): ?><span class="n"><?= h(seo_t($lang, 'with_stories', ['n' => $storyCount[$x['id']]])) ?></span><?php endif ?></a></li>
    <?php endforeach ?>
  </ul>
  <?php endforeach ?>

<?php else: ?>
  <p class="crumbs"><a href="<?= h(home_url($lang)) ?>"><?= h(seo_t($lang, 'brand')) ?></a> › <a href="<?= h(hub_url($lang)) ?>"><?= h(seo_t($lang, 'all')) ?></a></p>
  <span class="chip <?= h($q['cat']) ?>"><?= h($catName($q['cat'])) ?></span>
  <h1><?= h($title) ?></h1>

  <section class="card">
    <h2 style="margin-top:0"><?= h(seo_t($lang, 'your')) ?></h2>
    <div class="answers">
      <a class="a-neg" href="<?= h($answerUrl(-1)) ?>" rel="nofollow"><?= h(seo_t($lang, 'neg')) ?></a>
      <a class="a-none" href="<?= h($answerUrl(0)) ?>" rel="nofollow"><?= h(seo_t($lang, 'none')) ?></a>
      <a class="a-pos" href="<?= h($answerUrl(1)) ?>" rel="nofollow"><?= h(seo_t($lang, 'pos')) ?></a>
    </div>
    <p class="hint"><?= h(seo_t($lang, 'answer_hint')) ?></p>
    <?php if ($answers >= 5): ?>
    <div class="bar" role="img" aria-label="<?= h(seo_t($lang, 'pos_share', ['p' => pct($n[1], $answers)])) ?>">
      <i style="width:<?= pct($n[1], $answers) ?>%;background:var(--pos)"></i><i style="width:<?= pct($n[0], $answers) ?>%;background:var(--none)"></i><i style="width:<?= pct($n[-1], $answers) ?>%;background:var(--neg)"></i>
    </div>
    <p class="stats"><span><?= h(seo_t($lang, 'stats', ['n' => nf($answers, $lang)])) ?></span><span><?= h(seo_t($lang, 'pos')) ?> <?= pc($n[1], $answers, $lang) ?></span><span><?= h(seo_t($lang, 'none')) ?> <?= pc($n[0], $answers, $lang) ?></span><span><?= h(seo_t($lang, 'neg')) ?> <?= pc($n[-1], $answers, $lang) ?></span></p>
    <?php else: ?>
    <p class="stats" style="margin-top:10px"><?= h(seo_t($lang, 'stats_few')) ?></p>
    <?php endif ?>
  </section>

  <h2><?= h(seo_t($lang, 'stories')) ?><?= $storyTotal ? ' (' . nf($storyTotal, $lang) . ')' : '' ?></h2>
  <?php if ($stories): ?>
    <?php foreach ($stories as $st): ?>
    <article class="story" lang="<?= h($st['lang']) ?>">
      <blockquote dir="auto"><?= ugc_html((string) $st['exp']) ?></blockquote>
      <div class="imp"><b><?= h(seo_t($lang, 'impact')) ?></b><p dir="auto"><?= ugc_html((string) $st['imp']) ?></p></div>
      <p class="who"><span dir="auto"><?= h($st['author'] !== '' ? (string) $st['author'] : seo_t($lang, 'anon')) ?></span><?= (int) $st['likes'] ? ' · ' . h(seo_t($lang, 'likes', ['n' => nf((int) $st['likes'], $lang)])) : '' ?></p>
    </article>
    <?php endforeach ?>
    <?php if ($storyTotal > count($stories)): ?><p><a class="btn line" href="<?= h(home_url($lang)) ?>"><?= h(seo_t($lang, 'more')) ?></a></p><?php endif ?>
  <?php else: ?>
    <p class="hint" style="margin-bottom:12px"><?= h(seo_t($lang, 'none_yet')) ?></p>
  <?php endif ?>
  <p style="margin-top:14px"><a class="btn pos" href="<?= h($answerUrl(1)) ?>" rel="nofollow"><?= h(seo_t($lang, 'tell')) ?></a></p>

  <h2><?= h(seo_t($lang, 'share')) ?></h2>
  <?php $u = rawurlencode($page['canonical']); $txt = rawurlencode($title); ?>
  <div class="share">
    <a class="btn line" href="https://wa.me/?text=<?= $txt ?>%20<?= $u ?>" target="_blank" rel="noopener nofollow">WhatsApp</a>
    <a class="btn line" href="https://www.facebook.com/sharer/sharer.php?u=<?= $u ?>" target="_blank" rel="noopener nofollow">Facebook</a>
    <a class="btn line" href="https://x.com/intent/post?text=<?= $txt ?>&amp;url=<?= $u ?>" target="_blank" rel="noopener nofollow">X</a>
    <a class="btn line" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= $u ?>" target="_blank" rel="noopener nofollow">LinkedIn</a>
    <button class="btn line" type="button" id="copy" data-url="<?= h($page['canonical']) ?>" data-ok="<?= h(seo_t($lang, 'copied')) ?>"><?= h(seo_t($lang, 'copy')) ?></button>
  </div>

  <h2><?= h(seo_t($lang, 'related')) ?></h2>
  <ul class="list">
    <?php foreach ($related as $x): ?><li><a href="<?= h(q_url($x, $lang)) ?>"><span dir="auto"><?= h(qtext($x, $lang)) ?></span></a></li><?php endforeach ?>
  </ul>
  <p style="margin-top:16px"><a class="btn line" href="<?= h(hub_url($lang)) ?>"><?= h(seo_t($lang, 'all')) ?></a></p>
<?php endif ?>
</main>

<footer>
  <div class="wrap">
    <span><?= h(seo_t($lang, 'foot')) ?></span>
    <span><a href="<?= h(home_url($lang)) ?>"><?= h(seo_t($lang, 'home')) ?></a> · <a href="<?= h(hub_url($lang)) ?>"><?= h(seo_t($lang, 'all')) ?></a> · <a href="https://dehini.com/">dehini.com</a></span>
  </div>
</footer>
<script>
  var c = document.getElementById("copy");
  if (c) c.addEventListener("click", function () {
    navigator.clipboard && navigator.clipboard.writeText(c.dataset.url).then(function () { c.textContent = c.dataset.ok; });
  });
</script>
</body>
</html>
