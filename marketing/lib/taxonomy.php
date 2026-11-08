<?php
/**
 * Organisation de la communication en trois niveaux, sans mélanger les notions :
 *
 *   Niveau 1 — Canaux de communication (où / par quel moyen ?), rangés par catégorie :
 *              📱 Réseaux sociaux → Instagram, 📢 Publicité digitale → Meta Ads…
 *   Niveau 2 — Supports de communication (sous quelle forme ?), rangés par type :
 *              Vidéo → Reel, Publications → Carrousel, Documents imprimés → Brochure…
 *              Chaque canal a sa liste de supports (Instagram → Reel, Story, Carrousel…).
 *   Niveau 3 — Performance : chaque action de communication suit
 *              Catégorie → Canal → Support → Contenu → Cible → Objectif → KPI (lib/report.php).
 *
 * Catégories et types de supports sont fixes ; canaux et supports se gèrent dans
 * Communication → Canaux & supports (ajouter, renommer, déplacer, retirer).
 */
declare(strict_types=1);

/** Catégories de canaux : clé => [icône, libellé]. */
const MK_CHANNEL_CATEGORIES = [
    'social'    => ['📱', 'Réseaux sociaux'],
    'web'       => ['🌐', 'Web'],
    'messaging' => ['✉️', 'Messagerie directe'],
    'ads'       => ['📢', 'Publicité digitale'],
    'media'     => ['📰', 'Médias traditionnels'],
    'outdoor'   => ['🏙️', 'Communication extérieure'],
    'direct'    => ['🤝', 'Communication directe'],
    'events'    => ['🎤', 'Événementiel'],
    'pr'        => ['👥', 'Relations publiques'],
    'retail'    => ['📦', 'Points de vente'],
];

/** Types de supports : clé => [groupe (digital / physical), libellé]. */
const MK_SUPPORT_TYPES = [
    'visuels'      => ['digital', 'Visuels'],
    'publications' => ['digital', 'Publications'],
    'video'        => ['digital', 'Vidéo'],
    'documents'    => ['digital', 'Documents'],
    'email'        => ['digital', 'Email'],
    'web'          => ['digital', 'Web'],
    'audio'        => ['digital', 'Audio'],
    'interaction'  => ['digital', 'Interaction'],
    'imprimes'     => ['physical', 'Documents imprimés'],
    'exterieur'    => ['physical', 'Publicité extérieure'],
    'evenementiel' => ['physical', 'Événementiel'],
    'commercial'   => ['physical', 'Commercial'],
    'packaging'    => ['physical', 'Packaging'],
    'promotion'    => ['physical', 'Promotion'],
    'objets'       => ['physical', 'Objets publicitaires'],
];

/** Catalogue des supports proposé au départ. */
function mk_default_supports(): array
{
    $t = [
        'visuels'      => ['image' => 'Image', 'affiche_digitale' => 'Affiche digitale', 'infographie' => 'Infographie', 'photographie' => 'Photographie'],
        'publications' => ['post' => 'Post', 'carrousel' => 'Carrousel', 'story' => 'Story'],
        'video'        => ['reel' => 'Reel', 'video_pub' => 'Vidéo publicitaire', 'temoignage' => 'Témoignage', 'tutoriel' => 'Tutoriel', 'interview' => 'Interview', 'live' => 'Live'],
        'documents'    => ['pdf' => 'PDF', 'presentation' => 'Présentation', 'catalogue_digital' => 'Catalogue digital', 'livre_blanc' => 'Livre blanc'],
        'email'        => ['newsletter' => 'Newsletter', 'email_promo' => 'Email promotionnel', 'invitation' => 'Invitation'],
        'web'          => ['page_produit' => 'Page produit', 'article' => 'Article de blog', 'banniere' => 'Bannière', 'formulaire' => 'Formulaire interactif'],
        'audio'        => ['podcast' => 'Podcast', 'message_vocal' => 'Message vocal', 'pub_audio' => 'Publicité audio'],
        'interaction'  => ['sondage' => 'Sondage', 'questionnaire' => 'Questionnaire', 'quiz' => 'Quiz', 'chatbot' => 'Chatbot'],
        'imprimes'     => ['flyer' => 'Flyer', 'brochure' => 'Brochure', 'depliant' => 'Dépliant', 'catalogue' => 'Catalogue'],
        'exterieur'    => ['affiche' => 'Affiche', 'panneau' => 'Panneau publicitaire', 'bache' => 'Bâche'],
        'evenementiel' => ['roll_up' => 'Roll-up', 'kakemono' => 'Kakémono', 'stand' => 'Stand', 'badge' => 'Badge'],
        'commercial'   => ['carte_visite' => 'Carte de visite', 'fiche_produit' => 'Fiche produit', 'plaquette' => 'Plaquette commerciale'],
        'packaging'    => ['boite' => 'Boîte', 'sac' => 'Sac personnalisé', 'etiquette' => 'Étiquette'],
        'promotion'    => ['coupon' => 'Coupon', 'carte_fidelite' => 'Carte de fidélité', 'bon_reduction' => 'Bon de réduction'],
        'objets'       => ['stylo' => 'Stylo', 'carnet' => 'Carnet', 'textile' => 'Textile personnalisé'],
    ];
    $out = [];
    foreach ($t as $type => $list) {
        foreach ($list as $k => $l) $out[] = ['key' => $k, 'label' => $l, 'type' => $type];
    }
    return $out;
}

/** Supports proposés par défaut pour un nouveau canal d'une catégorie. */
function mk_category_supports(string $cat): array
{
    $m = [
        'social'    => ['post', 'carrousel', 'story', 'reel', 'live', 'video_pub', 'image', 'infographie', 'temoignage', 'sondage'],
        'web'       => ['page_produit', 'article', 'banniere', 'formulaire', 'video_pub', 'infographie', 'livre_blanc', 'chatbot'],
        'messaging' => ['newsletter', 'email_promo', 'invitation', 'image', 'video_pub', 'pdf', 'message_vocal', 'sondage', 'chatbot'],
        'ads'       => ['image', 'affiche_digitale', 'carrousel', 'story', 'reel', 'video_pub', 'banniere', 'formulaire'],
        'media'     => ['video_pub', 'pub_audio', 'interview', 'temoignage', 'photographie', 'infographie'],
        'outdoor'   => ['affiche', 'panneau', 'bache', 'affiche_digitale', 'video_pub'],
        'direct'    => ['presentation', 'plaquette', 'fiche_produit', 'carte_visite', 'brochure', 'catalogue', 'flyer', 'pdf'],
        'events'    => ['stand', 'roll_up', 'kakemono', 'badge', 'brochure', 'flyer', 'presentation', 'stylo', 'carnet', 'textile'],
        'pr'        => ['article', 'interview', 'temoignage', 'pdf', 'photographie', 'presentation', 'video_pub'],
        'retail'    => ['affiche', 'flyer', 'brochure', 'depliant', 'catalogue', 'fiche_produit', 'etiquette', 'sac', 'boite', 'coupon', 'carte_fidelite', 'bon_reduction', 'roll_up', 'kakemono'],
    ];
    return $m[$cat] ?? [];
}

/**
 * Canaux proposés au départ, par catégorie. Quelques clés reprennent celles des versions
 * précédentes (facebook, site, landing, email, telephone, evenement…) pour que les données
 * déjà saisies retrouvent tout de suite leur canal.
 */
function mk_default_channels(): array
{
    $chs = [
        'social'    => ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'x' => 'X', 'pinterest' => 'Pinterest', 'snapchat' => 'Snapchat'],
        'web'       => ['site' => 'Site internet', 'blog' => 'Blog', 'landing' => 'Landing page', 'plateformes' => 'Plateformes spécialisées'],
        'messaging' => ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'telegram' => 'Telegram', 'sms' => 'SMS'],
        'ads'       => ['meta_ads' => 'Meta Ads', 'google_ads' => 'Google Ads', 'tiktok_ads' => 'TikTok Ads', 'linkedin_ads' => 'LinkedIn Ads', 'youtube_ads' => 'YouTube Ads'],
        'media'     => ['television' => 'Télévision', 'radio' => 'Radio', 'presse' => 'Presse écrite', 'magazines' => 'Magazines'],
        'outdoor'   => ['affichage' => 'Affichage urbain', 'panneaux' => 'Panneaux publicitaires', 'ecrans_led' => 'Écrans LED', 'mobilier_urbain' => 'Mobilier urbain'],
        'direct'    => ['telephone' => 'Téléphone', 'rendez_vous' => 'Rendez-vous commerciaux', 'reunions' => 'Réunions', 'prospection' => 'Prospection terrain'],
        'events'    => ['evenement' => 'Salons professionnels', 'conferences' => 'Conférences', 'webinars' => 'Webinaires', 'portes_ouvertes' => 'Journées portes ouvertes'],
        'pr'        => ['relations_presse' => 'Relations presse', 'partenariats' => 'Partenariats', 'influenceurs' => 'Influenceurs', 'associations' => 'Associations professionnelles'],
        'retail'    => ['boutiques' => 'Boutiques', 'showrooms' => 'Showrooms', 'espaces_commerciaux' => 'Espaces commerciaux'],
    ];
    // Supports propres à certains canaux (sinon : ceux de la catégorie).
    $special = [
        'youtube'      => ['video_pub', 'reel', 'tutoriel', 'temoignage', 'interview', 'live'],
        'linkedin'     => ['post', 'carrousel', 'article', 'video_pub', 'pdf', 'infographie', 'temoignage', 'interview', 'sondage', 'live'],
        'tiktok'       => ['reel', 'video_pub', 'tutoriel', 'temoignage', 'live'],
        'pinterest'    => ['image', 'infographie', 'photographie'],
        'snapchat'     => ['story', 'video_pub', 'image'],
        'x'            => ['post', 'image', 'video_pub', 'sondage'],
        'blog'         => ['article', 'infographie', 'livre_blanc', 'video_pub'],
        'email'        => ['newsletter', 'email_promo', 'invitation', 'pdf', 'infographie'],
        'sms'          => ['invitation', 'coupon', 'bon_reduction'],
        'google_ads'   => ['banniere', 'image', 'video_pub', 'formulaire'],
        'youtube_ads'  => ['video_pub', 'reel'],
        'television'   => ['video_pub', 'interview', 'temoignage'],
        'radio'        => ['pub_audio', 'interview', 'podcast'],
        'webinars'     => ['presentation', 'pdf', 'livre_blanc', 'sondage', 'questionnaire', 'invitation', 'temoignage'],
        'influenceurs' => ['reel', 'story', 'post', 'video_pub', 'temoignage'],
    ];
    $out = [];
    foreach ($chs as $cat => $list) {
        foreach ($list as $k => $l) $out[] = ['key' => $k, 'label' => $l, 'category' => $cat, 'supports' => $special[$k] ?? mk_category_supports($cat)];
    }
    return $out;
}

/* ------------------------------------------------------------- lecture */

/** Canaux configurés, dans l'ordre choisi : [{key, label, category, supports[]}, …]. */
function mk_channels(): array
{
    $out = [];
    foreach (mk_settings()['channels'] as $c) {
        $k = (string) ($c['key'] ?? '');
        if ($k === '') continue;
        $cat = (string) ($c['category'] ?? '');
        $out[] = ['key' => $k, 'label' => (string) ($c['label'] ?? $k), 'category' => isset(MK_CHANNEL_CATEGORIES[$cat]) ? $cat : 'direct',
            'supports' => array_values(array_map('strval', is_array($c['supports'] ?? null) ? $c['supports'] : []))];
    }
    return $out;
}

/** Supports configurés : [{key, label, type}, …]. */
function mk_supports(): array
{
    $out = [];
    foreach (mk_settings()['supports'] as $s) {
        $k = (string) ($s['key'] ?? '');
        if ($k === '') continue;
        $t = (string) ($s['type'] ?? '');
        $out[] = ['key' => $k, 'label' => (string) ($s['label'] ?? $k), 'type' => isset(MK_SUPPORT_TYPES[$t]) ? $t : 'visuels'];
    }
    return $out;
}

/** Canaux d'une catégorie : clé => libellé. */
function mk_channels_of(string $cat): array
{
    $out = [];
    foreach (mk_channels() as $c) if ($c['category'] === $cat) $out[$c['key']] = $c['label'];
    return $out;
}

/** Canaux de la catégorie « Réseaux sociaux » (Social Media, performances, funnels). */
function mk_social_platforms(): array
{
    return mk_channels_of('social');
}

/** Catégorie d'un canal (ou '' s'il n'est plus dans la liste). */
function mk_channel_category(string $key): string
{
    foreach (mk_channels() as $c) if ($c['key'] === $key) return $c['category'];
    return '';
}

/* ------------------------------------------------------------- migration */

/** Valeurs de canal réellement utilisées dans les données saisies. */
function mk_used_channel_keys(): array
{
    $out = [];
    foreach ([['posts', 'platform'], ['social_stats', 'platform'], ['contacts', 'source'], ['inquiries', 'source'], ['projects', 'source'], ['campaigns', 'platform']] as [$t, $c]) {
        foreach (q("SELECT DISTINCT $c AS v FROM $t WHERE $c != ''") as $r) $out[] = (string) $r['v'];
    }
    foreach (q("SELECT channels FROM funnel_stages WHERE channels != ''") as $r) {
        foreach (explode(',', (string) $r['channels']) as $k) $out[] = $k;
    }
    return array_values(array_unique(array_filter($out)));
}

/**
 * Passage à l'organisation en trois niveaux (une seule fois par installation) :
 *  - anciens formats de publication : « Vidéo / Reel » devient le support « Reel » ;
 *  - anciennes plateformes d'Ads Manager (meta, google…) deviennent les canaux Meta Ads, Google Ads… ;
 *  - liste des canaux = canaux par défaut, plus tout ancien réseau / ancienne source / ancien canal
 *    encore cité par des données (dans la catégorie la plus proche), pour que rien ne perde son nom.
 */
function mk_taxonomy_migrate(array $stored): array
{
    $pdo = db();
    $pdo->exec("UPDATE posts SET format = 'reel' WHERE format = 'video'");
    foreach (['meta' => 'meta_ads', 'google' => 'google_ads', 'linkedin' => 'linkedin_ads', 'tiktok' => 'tiktok_ads'] as $old => $new) {
        $pdo->prepare('UPDATE campaigns SET platform = ? WHERE platform = ?')->execute([$new, $old]);
    }
    $list = mk_default_channels();
    $have = array_column($list, 'key');
    $legacy = []; // clé => [libellé, catégorie]
    foreach (is_array($stored['channels'] ?? null) ? $stored['channels'] : [] as $c) {
        if (!empty($c['key'])) $legacy[(string) $c['key']] = [(string) ($c['label'] ?? $c['key']), !empty($c['social']) ? 'social' : (string) ($c['category'] ?? 'direct')];
    }
    $oldSocial = is_array($stored['social_platforms'] ?? null) ? $stored['social_platforms'] : [];
    foreach ($oldSocial as $p) {
        if (!empty($p['key'])) $legacy[(string) $p['key']] = $legacy[(string) $p['key']] ?? [(string) ($p['label'] ?? $p['key']), 'social'];
    }
    foreach (['recommandation' => 'Recommandation', 'autre' => 'Autre'] as $k => $l) $legacy[$k] = $legacy[$k] ?? [$l, 'direct'];
    $used = mk_used_channel_keys();
    foreach ($legacy as $k => [$label, $cat]) {
        if (in_array($k, $used, true) && !in_array($k, $have, true)) {
            $cat = isset(MK_CHANNEL_CATEGORIES[$cat]) ? $cat : 'direct';
            $list[] = ['key' => $k, 'label' => $label, 'category' => $cat, 'supports' => mk_category_supports($cat)];
            $have[] = $k;
        }
    }
    $stored['channels'] = $list;
    $stored['supports'] = is_array($stored['supports'] ?? null) && $stored['supports'] ? $stored['supports'] : mk_default_supports();
    $stored['channels_archive'] = is_array($stored['channels_archive'] ?? null) ? $stored['channels_archive'] : [];
    $stored['supports_archive'] = is_array($stored['supports_archive'] ?? null) ? $stored['supports_archive'] : [];
    $stored['taxonomy'] = 2;
    unset($stored['social_platforms']);
    return $stored;
}

/* ------------------------------------------------------------- validation */

/** Clé pour un élément : la sienne s'il en a une valide, sinon dérivée du nom ; jamais deux fois la même. */
function mk_item_key(string $given, string $label, array $existing, array &$used): string
{
    $key = ($given !== '' && (in_array($given, $existing, true) || preg_match('/^[a-z0-9_]{1,40}$/', $given))) ? $given : mk_slug($label);
    $base = $key;
    $i = 2;
    while (in_array($key, $used, true)) { $key = $base . '_' . $i; $i++; }
    $used[] = $key;
    return $key;
}

/** Valide le catalogue des supports envoyé depuis Communication → Canaux & supports. */
function mk_clean_supports(array $items): array
{
    if (!$items) throw new MkInputError('Gardez au moins un support de communication.');
    if (count($items) > 250) throw new MkInputError('250 supports au maximum.');
    $existing = array_column(mk_supports(), 'key');
    $out = [];
    $used = [];
    $names = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $label = mk_str($it['label'] ?? '', 50);
        if ($label === '') throw new MkInputError('Donnez un nom à chaque support.');
        if (in_array(mb_strtolower($label), $names, true)) throw new MkInputError("Deux supports portent le même nom : « $label ».");
        $names[] = mb_strtolower($label);
        $type = (string) ($it['type'] ?? '');
        if (!isset(MK_SUPPORT_TYPES[$type])) throw new MkInputError("Support « $label » : type inconnu.");
        $out[] = ['key' => mk_item_key((string) ($it['key'] ?? ''), $label, $existing, $used), 'label' => $label, 'type' => $type];
    }
    return $out;
}

/** Valide la liste des canaux ; ne garde, pour chaque canal, que des supports qui existent. */
function mk_clean_channels(array $items, array $supportKeys): array
{
    if (!$items) throw new MkInputError('Gardez au moins un canal de communication.');
    if (count($items) > 150) throw new MkInputError('150 canaux au maximum.');
    $existing = array_column(mk_channels(), 'key');
    $out = [];
    $used = [];
    $names = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $label = mk_str($it['label'] ?? '', 50);
        if ($label === '') throw new MkInputError('Donnez un nom à chaque canal.');
        if (in_array(mb_strtolower($label), $names, true)) throw new MkInputError("Deux canaux portent le même nom : « $label ».");
        $names[] = mb_strtolower($label);
        $cat = (string) ($it['category'] ?? '');
        if (!isset(MK_CHANNEL_CATEGORIES[$cat])) throw new MkInputError("Canal « $label » : choisissez sa catégorie.");
        $sup = [];
        foreach (is_array($it['supports'] ?? null) ? $it['supports'] : [] as $s) {
            $s = (string) $s;
            if (in_array($s, $supportKeys, true) && !in_array($s, $sup, true)) $sup[] = $s;
        }
        $out[] = ['key' => mk_item_key((string) ($it['key'] ?? ''), $label, $existing, $used), 'label' => $label, 'category' => $cat, 'supports' => $sup];
    }
    return $out;
}

/**
 * Enregistre canaux et/ou supports. Un élément retiré garde son nom pour les données qui le
 * citent (archive) ; s'il est recréé avec le même nom, il les retrouve.
 */
function mk_taxonomy_apply(array $cur, array $in): array
{
    $next = $cur;
    $supports = mk_supports();
    if (array_key_exists('supports', $in)) {
        $supports = mk_clean_supports(is_array($in['supports']) ? $in['supports'] : []);
        $keys = array_column($supports, 'key');
        $arch = is_array($cur['supports_archive'] ?? null) ? $cur['supports_archive'] : [];
        foreach (mk_supports() as $s) if (!in_array($s['key'], $keys, true)) $arch[$s['key']] = $s['label'];
        foreach ($keys as $k) unset($arch[$k]);
        $next['supports'] = $supports;
        $next['supports_archive'] = $arch;
    }
    $supportKeys = array_column($supports, 'key');
    $channels = array_key_exists('channels', $in)
        ? mk_clean_channels(is_array($in['channels']) ? $in['channels'] : [], $supportKeys)
        : array_map(fn ($c) => ['supports' => array_values(array_intersect($c['supports'], $supportKeys))] + $c, mk_channels());
    $keys = array_column($channels, 'key');
    $arch = is_array($cur['channels_archive'] ?? null) ? $cur['channels_archive'] : [];
    foreach (mk_channels() as $c) if (!in_array($c['key'], $keys, true)) $arch[$c['key']] = $c['label'];
    foreach ($keys as $k) unset($arch[$k]);
    $next['channels'] = $channels;
    $next['channels_archive'] = $arch;
    return $next;
}

/* ------------------------------------------------------------- utilisation */

/** Nombre de données saisies qui citent un canal. */
function mk_channel_usage(string $key): int
{
    $like = '%,' . $key . ',%';
    return (int) qv('SELECT COUNT(*) FROM posts WHERE platform = ?', [$key])
        + (int) qv('SELECT COUNT(*) FROM social_stats WHERE platform = ?', [$key])
        + (int) qv('SELECT COUNT(*) FROM contacts WHERE source = ?', [$key])
        + (int) qv('SELECT COUNT(*) FROM inquiries WHERE source = ?', [$key])
        + (int) qv('SELECT COUNT(*) FROM projects WHERE source = ?', [$key])
        + (int) qv('SELECT COUNT(*) FROM campaigns WHERE platform = ?', [$key])
        + (int) qv("SELECT COUNT(*) FROM funnel_stages WHERE ',' || channels || ',' LIKE ?", [$like]);
}

/** Nombre de données saisies qui citent un support. */
function mk_support_usage(string $key): int
{
    return (int) qv('SELECT COUNT(*) FROM posts WHERE format = ?', [$key])
        + (int) qv('SELECT COUNT(*) FROM contacts WHERE support = ?', [$key])
        + (int) qv('SELECT COUNT(*) FROM emails WHERE support = ?', [$key]);
}
