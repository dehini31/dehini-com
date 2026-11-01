<?php /** Confidentialité. */ $lang = current_lang(); ?>
<div class="wrap"><?= breadcrumbs_html($page['breadcrumbs']) ?></div>
<section class="page-head wrap"><h1 class="display-sm"><?= e(tr($c['title'] ?? [], $lang)) ?></h1></section>
<section class="section"><div class="wrap narrow prose"><?= markdown(tr($c['body'] ?? [], $lang)) ?></div></section>
