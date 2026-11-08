<?php
/**
 * Socle du Marketing Dashboard (dehini.com/marketing/) : configuration, base SQLite,
 * schéma des données saisies à la main, réglages et envoi d'emails.
 * Les rapports (jour / semaine / mois) sont calculés dans lib/report.php.
 * Aucune dépendance externe.
 */
declare(strict_types=1);

// Configuration locale facultative — jamais dans Git (voir config.example.php).
$cfg = dirname(__DIR__) . '/config.php';
if (is_file($cfg)) require $cfg;
// Sans réglage SMTP propre, on réutilise le compte email du défi Impact s'il est configuré.
$impactCfg = dirname(__DIR__, 2) . '/impact/config.php';
if (!defined('MK_SMTP_HOST') && is_file($impactCfg)) {
    try { require_once $impactCfg; } catch (Throwable $e) { /* config Impact illisible : on s'en passe */ }
}

$defaults = [
    'MK_SITE_URL'       => '',   // ex. https://dehini.com/marketing/ — déduit de la requête si vide
    'MK_DB_PATH'        => dirname(__DIR__, 3) . '/marketing-data/marketing.sqlite', // hors public_html
    'MK_TIMEZONE'       => 'Africa/Algiers',
    'MK_MAIL_FROM'      => defined('IMPACT_MAIL_FROM') ? IMPACT_MAIL_FROM : '',
    'MK_MAIL_FROM_NAME' => 'Marketing Dashboard · dehini.com',
    'MK_SMTP_HOST'      => defined('IMPACT_SMTP_HOST') ? IMPACT_SMTP_HOST : '',
    'MK_SMTP_PORT'      => defined('IMPACT_SMTP_PORT') ? IMPACT_SMTP_PORT : 465,
    'MK_SMTP_SECURE'    => defined('IMPACT_SMTP_SECURE') ? IMPACT_SMTP_SECURE : 'ssl',
    'MK_SMTP_USER'      => defined('IMPACT_SMTP_USER') ? IMPACT_SMTP_USER : '',
    'MK_SMTP_PASS'      => defined('IMPACT_SMTP_PASS') ? IMPACT_SMTP_PASS : '',
    'MK_SMTP_VERIFY'    => defined('IMPACT_SMTP_VERIFY') ? IMPACT_SMTP_VERIFY : true,
];
foreach ($defaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
date_default_timezone_set(MK_TIMEZONE);

/** Erreur de saisie : le message est affiché tel quel dans le tableau de bord. */
final class MkInputError extends RuntimeException {}

/* ================================================================ listes */

/** Transforme un nom de réseau en identifiant stable (ex. « X (Twitter) » → « x_twitter »). */
function mk_slug(string $s): string
{
    $s = mb_strtolower(trim($s));
    $map = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = trim($s, '_');
    return $s !== '' ? mb_substr($s, 0, 30) : 'reseau';
}

/** Réseaux sociaux configurés (Réglages → Réseaux sociaux) : clé stable => libellé, dans l'ordre choisi. */
function mk_social_platforms(): array
{
    $out = [];
    foreach (mk_settings()['social_platforms'] as $p) {
        $k = (string) ($p['key'] ?? '');
        if ($k !== '') $out[$k] = (string) ($p['label'] ?? $k);
    }
    return $out;
}

/**
 * Valide la liste envoyée depuis Réglages → Réseaux sociaux. Un élément qui reprend la clé
 * d'un réseau existant ne fait que le renommer (les publications et relevés déjà saisis le
 * suivent) ; un élément sans clé connue en est un nouveau, dont la clé est dérivée du nom.
 * Un réseau retiré de la liste reste affiché sur les anciennes données qui le citaient.
 */
function mk_clean_platforms(array $items): array
{
    if (!$items) throw new MkInputError('Ajoutez au moins un réseau social.');
    if (count($items) > 12) throw new MkInputError('12 réseaux sociaux au maximum.');
    $existing = array_column(mk_settings()['social_platforms'], 'key');
    $out = [];
    $used = [];
    foreach ($items as $it) {
        $label = mk_str(is_array($it) ? ($it['label'] ?? '') : '', 40);
        if ($label === '') throw new MkInputError('Donnez un nom à chaque réseau social.');
        $key = (string) (is_array($it) ? ($it['key'] ?? '') : '');
        if ($key === '' || !in_array($key, $existing, true)) $key = mk_slug($label);
        $base = $key;
        $i = 2;
        while (in_array($key, $used, true)) { $key = $base . '_' . $i; $i++; }
        $used[] = $key;
        $out[] = ['key' => $key, 'label' => $label];
    }
    return $out;
}

/** Listes de choix (clé stockée => libellé affiché). */
function mk_enums(): array
{
    static $e = null;
    if ($e === null) {
        $e = [
            'sources' => [
                'site' => 'Site web YINVESTI', 'landing' => 'Landing page', 'email' => 'Email marketing',
                'telephone' => 'Appel / WhatsApp', 'evenement' => 'Événement / salon',
                'recommandation' => 'Recommandation', 'autre' => 'Autre',
            ],
            'ad_platforms' => [
                'meta' => 'Meta (Facebook & Instagram)', 'google' => 'Google Ads', 'linkedin' => 'LinkedIn Ads',
                'tiktok' => 'TikTok Ads', 'autre' => 'Autre',
            ],
            'contact_types' => ['investisseur' => 'Investisseur', 'porteur' => 'Porteur de projet', 'partenaire' => 'Partenaire', 'autre' => 'Autre'],
            'contact_status' => [
                'nouveau' => 'À traiter', 'contacte' => 'Contacté', 'qualifie' => 'Qualifié',
                'non_qualifie' => 'Non qualifié', 'converti' => 'Converti',
            ],
            'reasons' => [
                'injoignable' => 'Injoignable', 'pas_interesse' => 'Pas intéressé', 'budget' => 'Budget insuffisant',
                'hors_cible' => 'Hors cible', 'doublon' => 'Doublon', 'infos_erronees' => 'Coordonnées erronées',
                'autre' => 'Autre raison',
            ],
            'inq_categories' => [
                'investissement' => 'Investir sur la plateforme', 'projet' => 'Financer un projet',
                'plateforme' => 'Fonctionnement / inscription', 'partenariat' => 'Partenariat',
                'reclamation' => 'Réclamation', 'autre' => 'Autre',
            ],
            'inq_status' => ['nouveau' => 'Nouvelle', 'en_cours' => 'En cours', 'repondu' => 'Répondue', 'clos' => 'Close'],
            'project_status' => [
                'demande' => 'Nouvelle demande', 'etude' => 'En étude', 'accepte' => 'Accepté',
                'en_financement' => 'En cours de financement', 'finance' => 'Financé',
                'refuse' => 'Refusé', 'abandonne' => 'Abandonné',
            ],
            'post_formats' => [
                'post' => 'Post', 'carrousel' => 'Carrousel', 'video' => 'Vidéo / Reel', 'story' => 'Story',
                'live' => 'Live', 'article' => 'Article',
            ],
            'ad_objectives' => [
                'notoriete' => 'Notoriété', 'trafic' => 'Trafic', 'engagement' => 'Engagement',
                'leads' => 'Génération de leads', 'inscriptions' => 'Inscriptions',
                'conversions' => 'Conversions / investissements', 'messages' => 'Messages',
            ],
            'target_metrics' => ['leads' => 'Leads', 'conversions' => 'Conversions', 'clicks' => 'Clics', 'impressions' => 'Impressions'],
            'campaign_status' => ['brouillon' => 'Brouillon', 'active' => 'Active', 'pause' => 'En pause', 'terminee' => 'Terminée'],
            'page_types' => ['site' => 'Site principal', 'landing' => 'Landing page'],
            'audiences' => ['investisseurs' => 'Investisseurs', 'porteurs' => 'Porteurs de projets', 'tous' => 'Tous'],
            'email_status' => ['brouillon' => 'Brouillon', 'planifiee' => 'Planifiée', 'envoyee' => 'Envoyée'],
            'funnel_status' => ['actif' => 'Actif', 'pause' => 'En pause', 'archive' => 'Archivé'],
            // Source des chiffres de chaque étape d'un funnel : saisie à la main, ou reprise
            // automatiquement des données des autres sections liées au funnel.
            'stage_sources' => [
                'manual' => 'Saisie manuelle',
                'ads_impressions' => 'Impressions des campagnes liées', 'ads_clicks' => 'Clics des campagnes liées',
                'ads_leads' => 'Leads des campagnes liées', 'ads_conversions' => 'Conversions des campagnes liées',
                'social_reach' => 'Portée des réseaux liés', 'social_engagement' => 'Engagement des réseaux liés',
                'social_clicks' => 'Clics des réseaux liés',
                'page_visits' => 'Visites des pages liées', 'page_registrations' => 'Inscriptions des pages liées',
                'page_forms' => 'Formulaires des pages liées',
                'email_sent' => 'Emails envoyés (liés)', 'email_opens' => 'Ouvertures (emails liés)',
                'email_clicks' => 'Clics (emails liés)', 'email_conversions' => 'Objectifs atteints (emails liés)',
                'contacts' => 'Leads & contacts liés', 'contacts_qualified' => 'Leads qualifiés liés',
                'contacts_converted' => 'Leads convertis liés',
            ],
        ];
    }
    $out = $e;
    $out['commercials'] = [];
    foreach (mk_settings()['commercials'] as $name) $out['commercials'][$name] = $name;
    // Réseaux sociaux configurables (Réglages) : en tête des sources, et seule liste des « platforms ».
    $plats = mk_social_platforms();
    $out['platforms'] = $plats;
    $out['sources'] = $plats + $out['sources'];
    return $out;
}

function mk_label(string $enum, $key): string
{
    $e = mk_enums()[$enum] ?? [];
    return (string) ($e[(string) $key] ?? (string) $key);
}

/* ================================================================ schéma */

/** Petit constructeur de définition de champ. */
function f(string $type, string $label, array $extra = []): array
{
    return ['t' => $type, 'l' => $label] + $extra;
}

/**
 * Toutes les données saisies dans le tableau de bord. Le même schéma sert à créer les
 * tables, à valider chaque saisie côté serveur et à construire les formulaires côté navigateur.
 * Types : date, text, longtext, email, url, int (entier ≥ 0), money (montant ≥ 0),
 * enum (liste), multi (plusieurs valeurs d'une liste), ref (lien vers une autre table).
 */
function mk_schema(): array
{
    static $s = null;
    if ($s !== null) return $s;
    $s = [
        'contacts' => ['label' => 'Lead / contact', 'date' => 'date', 'title' => 'name', 'fields' => [
            'date'        => f('date', "Date d'arrivée", ['req' => 1, 'def' => 'today']),
            'name'        => f('text', 'Nom complet', ['req' => 1]),
            'phone'       => f('text', 'Téléphone'),
            'email'       => f('email', 'Email'),
            'city'        => f('text', 'Wilaya / ville'),
            'type'        => f('enum', 'Profil', ['opts' => 'contact_types', 'req' => 1, 'def' => 'investisseur']),
            'source'      => f('enum', 'Source', ['opts' => 'sources', 'req' => 1]),
            'campaign_id' => f('ref', 'Campagne publicitaire', ['ref' => 'campaigns']),
            'page_id'     => f('ref', 'Landing page / page du site', ['ref' => 'pages']),
            'email_id'    => f('ref', 'Campagne email', ['ref' => 'emails']),
            'funnel_id'   => f('ref', 'Funnel', ['ref' => 'funnels']),
            'assigned_to' => f('enum', 'Commercial responsable', ['opts' => 'commercials', 'empty' => 'Non assigné']),
            'status'      => f('enum', 'Traitement', ['opts' => 'contact_status', 'req' => 1, 'def' => 'nouveau']),
            'reason'      => f('enum', 'Pourquoi non qualifié ?', ['opts' => 'reasons', 'when' => ['status', 'non_qualifie']]),
            'reason_note' => f('text', 'Précision', ['when' => ['status', 'non_qualifie']]),
            'notes'       => f('longtext', 'Notes'),
        ]],
        'inquiries' => ['label' => 'Question / inquiry', 'date' => 'date', 'title' => 'question', 'fields' => [
            'date'        => f('date', 'Date de réception', ['req' => 1, 'def' => 'today']),
            'source'      => f('enum', 'Canal', ['opts' => 'sources', 'req' => 1]),
            'name'        => f('text', 'Nom'),
            'contact'     => f('text', 'Téléphone / email'),
            'type'        => f('enum', 'Profil', ['opts' => 'contact_types']),
            'category'    => f('enum', 'Sujet', ['opts' => 'inq_categories', 'req' => 1]),
            'question'    => f('longtext', 'Question / message', ['req' => 1]),
            'status'      => f('enum', 'Statut', ['opts' => 'inq_status', 'req' => 1, 'def' => 'nouveau']),
            'assigned_to' => f('enum', 'Pris en charge par', ['opts' => 'commercials', 'empty' => 'Personne']),
            'answer'      => f('longtext', 'Réponse apportée'),
        ]],
        'projects' => ['label' => 'Projet', 'date' => 'date', 'title' => 'name', 'fields' => [
            'date'          => f('date', 'Date de la demande', ['req' => 1, 'def' => 'today']),
            'name'          => f('text', 'Nom du projet', ['req' => 1]),
            'owner'         => f('text', 'Porteur de projet'),
            'contact'       => f('text', 'Téléphone / email'),
            'sector'        => f('text', "Secteur d'activité"),
            'source'        => f('enum', 'Source', ['opts' => 'sources']),
            'status'        => f('enum', 'Statut', ['opts' => 'project_status', 'req' => 1, 'def' => 'demande']),
            'amount_target' => f('money', 'Montant recherché'),
            'raised'        => f('money', 'Montant levé (total)'),
            'investors'     => f('int', "Nombre d'investisseurs"),
            'as_of'         => f('date', 'Chiffres arrêtés au', ['def' => 'today', 'help' => "Date du montant levé : sert à calculer ce qui a été levé chaque semaine."]),
            'funding_start' => f('date', 'Début de la levée'),
            'funding_end'   => f('date', 'Fin de la levée'),
            'notes'         => f('longtext', 'Notes'),
        ]],
        'posts' => ['label' => 'Publication', 'date' => 'date', 'title' => 'topic', 'fields' => [
            'date'        => f('date', 'Date de publication', ['req' => 1, 'def' => 'today']),
            'platform'    => f('enum', 'Réseau', ['opts' => 'platforms', 'req' => 1]),
            'format'      => f('enum', 'Format', ['opts' => 'post_formats', 'req' => 1, 'def' => 'post']),
            'topic'       => f('text', 'Sujet / titre', ['req' => 1]),
            'link'        => f('url', 'Lien'),
            'reach'       => f('int', 'Portée'),
            'impressions' => f('int', 'Impressions'),
            'engagement'  => f('int', 'Engagement'),
            'clicks'      => f('int', 'Clics'),
        ]],
        'social_stats' => ['label' => 'Performances réseau social', 'date' => 'date', 'title' => 'platform', 'fields' => [
            'date'          => f('date', 'Date (dernier jour couvert)', ['req' => 1, 'def' => 'today', 'help' => "Une ligne = les chiffres d'un jour ou d'une semaine, datés du dernier jour couvert."]),
            'platform'      => f('enum', 'Réseau', ['opts' => 'platforms', 'req' => 1]),
            'followers'     => f('int', 'Abonnés (total)'),
            'new_followers' => f('int', 'Nouveaux abonnés'),
            'reach'         => f('int', 'Portée'),
            'impressions'   => f('int', 'Impressions'),
            'engagement'    => f('int', 'Engagement (réactions, commentaires, partages)'),
            'clicks'        => f('int', 'Clics'),
        ]],
        'campaigns' => ['label' => 'Campagne publicitaire', 'date' => '', 'title' => 'name', 'fields' => [
            'name'         => f('text', 'Nom de la campagne', ['req' => 1]),
            'platform'     => f('enum', 'Plateforme', ['opts' => 'ad_platforms', 'req' => 1, 'def' => 'meta']),
            'objective'    => f('enum', 'Objectif marketing', ['opts' => 'ad_objectives', 'req' => 1, 'def' => 'leads']),
            'audience'     => f('enum', 'Cible', ['opts' => 'audiences']),
            'target_metric' => f('enum', 'Indicateur suivi', ['opts' => 'target_metrics', 'def' => 'leads']),
            'target_value' => f('int', 'Objectif chiffré'),
            'budget'       => f('money', 'Budget prévu'),
            'start'        => f('date', 'Début', ['req' => 1, 'def' => 'today']),
            'end'          => f('date', 'Fin'),
            'status'       => f('enum', 'Statut', ['opts' => 'campaign_status', 'req' => 1, 'def' => 'active']),
            'funnel_id'    => f('ref', 'Funnel', ['ref' => 'funnels']),
            'page_id'      => f('ref', 'Landing page', ['ref' => 'pages']),
            'notes'        => f('longtext', 'Notes'),
        ]],
        'ad_results' => ['label' => 'Résultats publicitaires', 'date' => 'date', 'title' => 'campaign_id', 'fields' => [
            'date'        => f('date', 'Date (dernier jour couvert)', ['req' => 1, 'def' => 'today', 'help' => "Une ligne = les résultats d'un jour ou d'une semaine, datés du dernier jour couvert."]),
            'campaign_id' => f('ref', 'Campagne', ['ref' => 'campaigns', 'req' => 1]),
            'spend'       => f('money', 'Dépense'),
            'impressions' => f('int', 'Impressions'),
            'clicks'      => f('int', 'Clics'),
            'leads'       => f('int', 'Leads'),
            'conversions' => f('int', 'Conversions'),
        ]],
        'pages' => ['label' => 'Page', 'date' => '', 'title' => 'name', 'fields' => [
            'name'      => f('text', 'Nom de la page', ['req' => 1]),
            'url'       => f('url', 'Adresse (URL)'),
            'type'      => f('enum', 'Type', ['opts' => 'page_types', 'req' => 1, 'def' => 'landing']),
            'audience'  => f('enum', 'Cible', ['opts' => 'audiences']),
            'funnel_id' => f('ref', 'Funnel', ['ref' => 'funnels']),
            'notes'     => f('longtext', 'Notes'),
        ]],
        'web_stats' => ['label' => 'Statistiques de page', 'date' => 'date', 'title' => 'page_id', 'fields' => [
            'date'          => f('date', 'Date (dernier jour couvert)', ['req' => 1, 'def' => 'today', 'help' => "Une ligne = les chiffres d'un jour ou d'une semaine, datés du dernier jour couvert."]),
            'page_id'       => f('ref', 'Page', ['ref' => 'pages', 'req' => 1]),
            'visits'        => f('int', 'Visites'),
            'visitors'      => f('int', 'Visiteurs uniques'),
            'reg_investors' => f('int', 'Inscriptions investisseurs'),
            'reg_owners'    => f('int', 'Inscriptions porteurs de projets'),
            'forms'         => f('int', 'Formulaires / contacts reçus'),
        ]],
        'emails' => ['label' => 'Campagne email', 'date' => 'date', 'title' => 'name', 'fields' => [
            'name'         => f('text', 'Nom de la campagne', ['req' => 1]),
            'date'         => f('date', "Date d'envoi", ['req' => 1, 'def' => 'today']),
            'subject'      => f('text', 'Objet du mail'),
            'segment'      => f('text', 'Liste / segment'),
            'status'       => f('enum', 'Statut', ['opts' => 'email_status', 'req' => 1, 'def' => 'envoyee']),
            'objective'    => f('text', 'Objectif (ex. inscription au webinaire)'),
            'target'       => f('int', 'Objectif chiffré'),
            'sent'         => f('int', 'Emails envoyés'),
            'opens'        => f('int', 'Ouvertures (estimées)'),
            'clicks'       => f('int', 'Clics'),
            'conversions'  => f('int', 'Objectifs atteints'),
            'unsubscribes' => f('int', 'Désabonnements'),
            'funnel_id'    => f('ref', 'Funnel', ['ref' => 'funnels']),
            'page_id'      => f('ref', 'Page de destination', ['ref' => 'pages']),
        ]],
        'funnels' => ['label' => 'Funnel', 'date' => '', 'title' => 'name', 'fields' => [
            'name'        => f('text', 'Nom du funnel', ['req' => 1]),
            'objective'   => f('text', 'Objectif'),
            'audience'    => f('enum', 'Cible', ['opts' => 'audiences']),
            'status'      => f('enum', 'Statut', ['opts' => 'funnel_status', 'req' => 1, 'def' => 'actif']),
            'social'      => f('multi', 'Réseaux sociaux associés', ['opts' => 'platforms']),
            'description' => f('longtext', 'Description'),
        ]],
        'funnel_entries' => ['label' => 'Chiffres de funnel', 'date' => 'date', 'title' => 'stage_id', 'fields' => [
            'date'      => f('date', 'Date (dernier jour couvert)', ['req' => 1, 'def' => 'today']),
            'funnel_id' => f('ref', 'Funnel', ['ref' => 'funnels', 'req' => 1]),
            'stage_id'  => f('ref', 'Étape', ['ref' => 'funnel_stages', 'req' => 1]),
            'count'     => f('int', 'Prospects', ['req' => 1]),
            'notes'     => f('text', 'Note'),
        ]],
    ];
    return $s;
}

/** Tables qui servent de listes de référence (chargées en entier par le navigateur). */
const MK_REF_ENTITIES = ['campaigns', 'pages', 'emails', 'funnels'];

function mk_sqltype(array $f): string
{
    switch ($f['t']) {
        case 'ref':   return 'INTEGER';
        case 'int':   return 'INTEGER NOT NULL DEFAULT 0';
        case 'money': return 'REAL NOT NULL DEFAULT 0';
        default:      return "TEXT NOT NULL DEFAULT ''";
    }
}

/* ================================================================ base */

function mk_site_url(): string
{
    $set = trim((string) (mk_settings()['dashboard_url'] ?? ''));
    if ($set !== '') return rtrim($set, '/') . '/';
    if (MK_SITE_URL !== '') return rtrim(MK_SITE_URL, '/') . '/';
    if (PHP_SAPI === 'cli') return 'https://dehini.com/marketing/';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $host  = $_SERVER['HTTP_HOST'] ?? 'dehini.com';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/marketing/api.php')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = dirname(MK_DB_PATH);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier de la base : ' . $dir);
    }
    $pdo = new PDO('sqlite:' . MK_DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    mk_migrate($pdo);
    return $pdo;
}

/**
 * Crée les tables à partir du schéma, et ajoute toute seule les colonnes d'un champ
 * ajouté plus tard (rien n'est jamais supprimé). Ne tourne que si le schéma a changé.
 */
function mk_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $hash = md5(json_encode(mk_schema()) . '|v1');
    $s = $pdo->prepare('SELECT v FROM settings WHERE k = ?');
    $s->execute(['schema_hash']);
    if ($s->fetchColumn() === $hash) return;

    foreach (mk_schema() as $table => $e) {
        $cols = ['id INTEGER PRIMARY KEY AUTOINCREMENT'];
        foreach ($e['fields'] as $k => $fd) $cols[] = $k . ' ' . mk_sqltype($fd);
        $cols[] = 'created INTEGER NOT NULL DEFAULT 0';
        $cols[] = 'updated INTEGER NOT NULL DEFAULT 0';
        $pdo->exec("CREATE TABLE IF NOT EXISTS $table (" . implode(', ', $cols) . ')');
        $have = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
        foreach ($e['fields'] as $k => $fd) {
            if (!in_array($k, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $k " . mk_sqltype($fd));
        }
        if ($e['date'] !== '') $pdo->exec("CREATE INDEX IF NOT EXISTS {$table}_date ON $table({$e['date']})");
    }
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS funnel_stages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        funnel_id INTEGER NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        name TEXT NOT NULL DEFAULT '',
        target INTEGER NOT NULL DEFAULT 0,
        tags TEXT NOT NULL DEFAULT '',
        source TEXT NOT NULL DEFAULT 'manual'
    );
    CREATE INDEX IF NOT EXISTS funnel_stages_f ON funnel_stages(funnel_id, position);
    -- Historique du financement des projets : permet de savoir ce qui a été levé pendant une période.
    CREATE TABLE IF NOT EXISTS project_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        raised REAL NOT NULL DEFAULT 0,
        investors INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT '',
        created INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS project_history_p ON project_history(project_id, date);
    -- Rapports générés (envoyés par le cron ou à la demande) : une photo figée des chiffres.
    CREATE TABLE IF NOT EXISTS reports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        date_from TEXT NOT NULL,
        date_to TEXT NOT NULL,
        label TEXT NOT NULL DEFAULT '',
        payload TEXT NOT NULL,
        sent_to TEXT NOT NULL DEFAULT '',
        origin TEXT NOT NULL DEFAULT '',
        created INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS reports_t ON reports(type, date_from);
    SQL);
    $pdo->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['schema_hash', $hash]);
}

function q(string $sql, array $params = []): array
{
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function q1(string $sql, array $params = []): ?array
{
    $s = db()->prepare($sql);
    $s->execute($params);
    $r = $s->fetch();
    return $r === false ? null : $r;
}

function qv(string $sql, array $params = [])
{
    $s = db()->prepare($sql);
    $s->execute($params);
    $v = $s->fetchColumn();
    return $v === false ? null : $v;
}

/** « ?, ?, ? » pour une liste IN (…). */
function mk_in(array $ids): string
{
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

function mk_today(): string
{
    return date('Y-m-d');
}

function mk_valid_date(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100;
}

function mk_add_days(string $d, int $n): string
{
    return (new DateTimeImmutable($d))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}

/* ============================================================== réglages */

function mk_settings_defaults(): array
{
    return [
        'org'           => 'YINVESTI',
        'currency'      => 'DA',
        'commercials'   => [],
        'recipients'    => [],
        'social_platforms' => [
            ['key' => 'facebook', 'label' => 'Facebook'],
            ['key' => 'instagram', 'label' => 'Instagram'],
            ['key' => 'linkedin', 'label' => 'LinkedIn'],
            ['key' => 'tiktok', 'label' => 'TikTok'],
        ],
        'send_daily'    => true,
        'send_weekly'   => true,
        'send_monthly'  => true,
        'dashboard_url' => '',
    ];
}

function mk_settings(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $raw = qv('SELECT v FROM settings WHERE k = ?', ['app']);
    $d = $raw ? json_decode((string) $raw, true) : [];
    $cache = array_merge(mk_settings_defaults(), is_array($d) ? $d : []);
    return $cache;
}

function mk_settings_save(array $in): array
{
    $cur = mk_settings();
    $lines = function ($v): array {
        $list = is_array($v) ? $v : preg_split('/[\r\n,;]+/', (string) $v);
        $out = [];
        foreach ($list as $x) {
            $x = mk_str($x, 80);
            if ($x !== '' && !in_array($x, $out, true)) $out[] = $x;
        }
        return array_slice($out, 0, 50);
    };
    $next = $cur;
    if (array_key_exists('org', $in)) $next['org'] = mk_str($in['org'], 60) ?: 'YINVESTI';
    if (array_key_exists('currency', $in)) $next['currency'] = mk_str($in['currency'], 8) ?: 'DA';
    if (array_key_exists('commercials', $in)) $next['commercials'] = $lines($in['commercials']);
    if (array_key_exists('social_platforms', $in)) {
        $next['social_platforms'] = mk_clean_platforms(is_array($in['social_platforms']) ? $in['social_platforms'] : []);
    }
    if (array_key_exists('recipients', $in)) {
        $mails = [];
        foreach ($lines($in['recipients']) as $m) {
            $m = strtolower($m);
            if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new MkInputError("Adresse email invalide : $m");
            $mails[] = $m;
        }
        $next['recipients'] = $mails;
    }
    foreach (['send_daily', 'send_weekly', 'send_monthly'] as $k) {
        if (array_key_exists($k, $in)) $next[$k] = (bool) $in[$k];
    }
    if (array_key_exists('dashboard_url', $in)) {
        $u = trim((string) $in['dashboard_url']);
        if ($u !== '' && !preg_match('~^https?://~i', $u)) throw new MkInputError("L'adresse du tableau de bord doit commencer par https://");
        $next['dashboard_url'] = mb_substr($u, 0, 200);
    }
    db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')
        ->execute(['app', json_encode($next, JSON_UNESCAPED_UNICODE)]);
    return mk_settings(true);
}

/* ============================================================ validation */

function mk_str($v, int $max): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '');
    $s = preg_replace('/[ \t]+/', ' ', $s) ?? '';
    return mb_substr($s, 0, $max);
}

/** Nettoie et valide une ligne saisie ; renvoie les valeurs à enregistrer. */
function mk_clean_row(string $entity, array $in, ?array $existing = null): array
{
    $schema = mk_schema()[$entity];
    $enums = mk_enums();
    $out = [];
    foreach ($schema['fields'] as $k => $fd) {
        // Mise à jour partielle : un champ absent garde sa valeur.
        if (!array_key_exists($k, $in)) {
            if ($existing !== null) { $out[$k] = $existing[$k]; continue; }
            $in[$k] = ($fd['def'] ?? '') === 'today' ? mk_today() : ($fd['def'] ?? '');
        }
        $v = $in[$k];
        $label = $fd['l'];
        switch ($fd['t']) {
            case 'date':
                $v = trim((string) $v);
                if ($v !== '' && !mk_valid_date($v)) throw new MkInputError("$label : date invalide.");
                break;
            case 'int':
                $v = is_numeric($v) ? (int) round((float) $v) : 0;
                if ($v < 0) throw new MkInputError("$label : le nombre ne peut pas être négatif.");
                break;
            case 'money':
                $v = is_numeric(str_replace([' ', ','], ['', '.'], (string) $v)) ? round((float) str_replace([' ', ','], ['', '.'], (string) $v), 2) : 0.0;
                if ($v < 0) throw new MkInputError("$label : le montant ne peut pas être négatif.");
                break;
            case 'email':
                $v = strtolower(mk_str($v, 190));
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new MkInputError("$label : adresse email invalide.");
                break;
            case 'url':
                $v = mk_str($v, 500);
                if ($v !== '' && !preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_URL)) throw new MkInputError("$label : adresse invalide.");
                break;
            case 'longtext':
                $v = mb_substr(trim(str_replace("\r", '', (string) $v)), 0, 5000);
                break;
            case 'enum':
                $v = (string) $v;
                $opts = $enums[$fd['opts']] ?? [];
                // Un commercial retiré de la liste reste affiché sur ses anciens leads.
                $keep = $existing !== null && (string) ($existing[$k] ?? '') === $v;
                if ($v !== '' && !array_key_exists($v, $opts) && !$keep) throw new MkInputError("$label : valeur inconnue.");
                break;
            case 'multi':
                $list = is_array($v) ? $v : explode(',', (string) $v);
                $opts = $enums[$fd['opts']] ?? [];
                $v = implode(',', array_values(array_unique(array_filter(array_map('strval', $list), fn ($x) => array_key_exists($x, $opts)))));
                break;
            case 'ref':
                $v = (int) $v > 0 ? (int) $v : null;
                if ($v !== null) {
                    $t = $fd['ref'];
                    if (!qv("SELECT 1 FROM $t WHERE id = ?", [$v])) throw new MkInputError("$label : élément introuvable.");
                }
                break;
            default:
                $v = mk_str($v, 200);
        }
        if (!empty($fd['req']) && ($v === '' || $v === null)) throw new MkInputError("$label : champ obligatoire.");
        $out[$k] = $v;
    }
    // Champs conditionnels : vidés quand la condition n'est plus remplie.
    foreach ($schema['fields'] as $k => $fd) {
        if (!empty($fd['when']) && (string) ($out[$fd['when'][0]] ?? '') !== $fd['when'][1]) $out[$k] = '';
    }
    if ($entity === 'funnel_entries' && $out['stage_id'] !== null) {
        if ((int) qv('SELECT funnel_id FROM funnel_stages WHERE id = ?', [$out['stage_id']]) !== (int) $out['funnel_id']) {
            throw new MkInputError("Cette étape n'appartient pas au funnel choisi.");
        }
    }
    if ($entity === 'campaigns' && $out['end'] !== '' && $out['end'] < $out['start']) {
        throw new MkInputError('La date de fin est avant la date de début.');
    }
    if ($entity === 'projects' && $out['funding_end'] !== '' && $out['funding_start'] !== '' && $out['funding_end'] < $out['funding_start']) {
        throw new MkInputError('La fin de la levée est avant son début.');
    }
    return $out;
}

/** Enregistre une ligne (création si $id vaut 0). Renvoie la ligne enregistrée. */
function mk_save(string $entity, array $in, int $id = 0): array
{
    $pdo = db();
    $existing = null;
    if ($id > 0) {
        $existing = q1("SELECT * FROM $entity WHERE id = ?", [$id]);
        if (!$existing) throw new MkInputError('Élément introuvable (supprimé entre-temps ?).');
    }
    $row = mk_clean_row($entity, $in, $existing);
    $now = time();
    if ($existing) {
        $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($row)));
        $pdo->prepare("UPDATE $entity SET $set, updated = ? WHERE id = ?")->execute([...array_values($row), $now, $id]);
    } else {
        $cols = implode(', ', array_keys($row));
        $pdo->prepare("INSERT INTO $entity ($cols, created, updated) VALUES (" . mk_in($row) . ', ?, ?)')
            ->execute([...array_values($row), $now, $now]);
        $id = (int) $pdo->lastInsertId();
    }
    if ($entity === 'projects') mk_project_history($id, $row, $existing);
    return q1("SELECT * FROM $entity WHERE id = ?", [$id]);
}

/** Garde une trace du montant levé, pour calculer ce qui a été levé pendant chaque période. */
function mk_project_history(int $id, array $row, ?array $old): void
{
    $changed = $old === null
        || (float) $old['raised'] !== (float) $row['raised']
        || (int) $old['investors'] !== (int) $row['investors']
        || (string) $old['status'] !== (string) $row['status'];
    if (!$changed) return;
    $date = $row['as_of'] !== '' ? $row['as_of'] : mk_today();
    // Une seule ligne par projet et par date : la dernière saisie du jour fait foi.
    db()->prepare('DELETE FROM project_history WHERE project_id = ? AND date = ?')->execute([$id, $date]);
    db()->prepare('INSERT INTO project_history (project_id, date, raised, investors, status, created) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $date, (float) $row['raised'], (int) $row['investors'], (string) $row['status'], time()]);
}

/** Supprime une ligne et ce qui en dépend (résultats d'une campagne, chiffres d'une page…). */
function mk_delete(string $entity, int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Liens vers cette ligne dans les autres tables : remis à vide.
        foreach (mk_schema() as $t => $e) {
            foreach ($e['fields'] as $k => $fd) {
                if ($fd['t'] !== 'ref' || $fd['ref'] !== $entity) continue;
                if (!empty($fd['req'])) $pdo->prepare("DELETE FROM $t WHERE $k = ?")->execute([$id]); // ex. résultats d'une campagne
                else $pdo->prepare("UPDATE $t SET $k = NULL WHERE $k = ?")->execute([$id]);
            }
        }
        if ($entity === 'funnels') $pdo->prepare('DELETE FROM funnel_stages WHERE funnel_id = ?')->execute([$id]);
        if ($entity === 'projects') $pdo->prepare('DELETE FROM project_history WHERE project_id = ?')->execute([$id]);
        $pdo->prepare("DELETE FROM $entity WHERE id = ?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ================================================================ funnels */

function mk_funnel_stages(int $fid): array
{
    return q('SELECT * FROM funnel_stages WHERE funnel_id = ? ORDER BY position, id', [$fid]);
}

/** Ce qui est associé au funnel : campagnes, pages, emails (par leur champ funnel_id) et réseaux. */
function mk_funnel_links(array $f): array
{
    $fid = (int) $f['id'];
    return [
        'campaigns' => array_map('intval', array_column(q('SELECT id FROM campaigns WHERE funnel_id = ?', [$fid]), 'id')),
        'pages'     => array_map('intval', array_column(q('SELECT id FROM pages WHERE funnel_id = ?', [$fid]), 'id')),
        'emails'    => array_map('intval', array_column(q('SELECT id FROM emails WHERE funnel_id = ?', [$fid]), 'id')),
        'social'    => array_values(array_filter(explode(',', (string) $f['social']))),
    ];
}

/**
 * Crée ou modifie un funnel avec ses étapes (nom, objectif, tags, source des chiffres)
 * et ses associations. Une étape retirée perd ses chiffres saisis.
 */
function mk_funnel_save(array $in): array
{
    $pdo = db();
    $id = (int) ($in['id'] ?? 0);
    $stages = is_array($in['stages'] ?? null) ? $in['stages'] : [];
    if (!$stages) throw new MkInputError('Ajoutez au moins une étape au funnel.');
    if (count($stages) > 20) throw new MkInputError('20 étapes au maximum.');
    $sources = mk_enums()['stage_sources'];
    $clean = [];
    foreach ($stages as $i => $st) {
        $name = mk_str($st['name'] ?? '', 80);
        if ($name === '') throw new MkInputError('Étape ' . ($i + 1) . ' : donnez-lui un nom.');
        $src = (string) ($st['source'] ?? 'manual');
        if (!array_key_exists($src, $sources)) $src = 'manual';
        $tags = [];
        foreach (is_array($st['tags'] ?? null) ? $st['tags'] : explode(',', (string) ($st['tags'] ?? '')) as $t) {
            $t = mk_str(ltrim(trim((string) $t), '#'), 40);
            if ($t !== '' && !in_array($t, $tags, true)) $tags[] = $t;
        }
        $clean[] = ['id' => (int) ($st['id'] ?? 0), 'name' => $name, 'target' => max(0, (int) ($st['target'] ?? 0)),
            'tags' => implode(',', array_slice($tags, 0, 12)), 'source' => $src];
    }
    $pdo->beginTransaction();
    try {
        $f = mk_save('funnels', $in['funnel'] ?? [], $id);
        $fid = (int) $f['id'];
        $keep = [];
        foreach ($clean as $pos => $st) {
            $sid = $st['id'];
            if ($sid > 0 && qv('SELECT 1 FROM funnel_stages WHERE id = ? AND funnel_id = ?', [$sid, $fid])) {
                $pdo->prepare('UPDATE funnel_stages SET position = ?, name = ?, target = ?, tags = ?, source = ? WHERE id = ?')
                    ->execute([$pos, $st['name'], $st['target'], $st['tags'], $st['source'], $sid]);
            } else {
                $pdo->prepare('INSERT INTO funnel_stages (funnel_id, position, name, target, tags, source) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$fid, $pos, $st['name'], $st['target'], $st['tags'], $st['source']]);
                $sid = (int) $pdo->lastInsertId();
            }
            $keep[] = $sid;
        }
        $gone = array_column(q('SELECT id FROM funnel_stages WHERE funnel_id = ? AND id NOT IN (' . mk_in($keep) . ')', [$fid, ...$keep]), 'id');
        foreach ($gone as $sid) {
            $pdo->prepare('DELETE FROM funnel_entries WHERE stage_id = ?')->execute([$sid]);
            $pdo->prepare('DELETE FROM funnel_stages WHERE id = ?')->execute([$sid]);
        }
        // Associations : campagnes, landing pages et emails cochés pointent vers ce funnel.
        $links = is_array($in['links'] ?? null) ? $in['links'] : null;
        if ($links !== null) {
            foreach (['campaigns', 'pages', 'emails'] as $t) {
                $ids = array_values(array_filter(array_map('intval', (array) ($links[$t] ?? [])), fn ($x) => $x > 0));
                $pdo->prepare("UPDATE $t SET funnel_id = NULL WHERE funnel_id = ?" . ($ids ? ' AND id NOT IN (' . mk_in($ids) . ')' : ''))
                    ->execute([$fid, ...$ids]);
                if ($ids) $pdo->prepare("UPDATE $t SET funnel_id = ? WHERE id IN (" . mk_in($ids) . ')')->execute([$fid, ...$ids]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return mk_funnel_full((int) $f['id']);
}

function mk_funnel_full(int $id): ?array
{
    $f = q1('SELECT * FROM funnels WHERE id = ?', [$id]);
    if (!$f) return null;
    $f['stages'] = mk_funnel_stages($id);
    $f['links'] = mk_funnel_links($f);
    return $f;
}

/** Valeur d'une étape sur une période, selon sa source. */
function mk_stage_value(array $st, array $f, array $links, string $from, string $to): int
{
    $src = (string) $st['source'];
    $sumIn = function (string $table, string $col, string $key, array $ids) use ($from, $to): int {
        if (!$ids) return 0;
        return (int) round((float) qv("SELECT COALESCE(SUM($col), 0) FROM $table WHERE $key IN (" . mk_in($ids) . ') AND date BETWEEN ? AND ?', [...$ids, $from, $to]));
    };
    switch ($src) {
        case 'manual':
            return (int) qv('SELECT COALESCE(SUM(count), 0) FROM funnel_entries WHERE stage_id = ? AND date BETWEEN ? AND ?', [(int) $st['id'], $from, $to]);
        case 'ads_impressions': return $sumIn('ad_results', 'impressions', 'campaign_id', $links['campaigns']);
        case 'ads_clicks':      return $sumIn('ad_results', 'clicks', 'campaign_id', $links['campaigns']);
        case 'ads_leads':       return $sumIn('ad_results', 'leads', 'campaign_id', $links['campaigns']);
        case 'ads_conversions': return $sumIn('ad_results', 'conversions', 'campaign_id', $links['campaigns']);
        case 'social_reach':      return $sumIn('social_stats', 'reach', 'platform', $links['social']);
        case 'social_engagement': return $sumIn('social_stats', 'engagement', 'platform', $links['social']);
        case 'social_clicks':     return $sumIn('social_stats', 'clicks', 'platform', $links['social']);
        case 'page_visits':        return $sumIn('web_stats', 'visits', 'page_id', $links['pages']);
        case 'page_registrations': return $sumIn('web_stats', 'reg_investors + reg_owners', 'page_id', $links['pages']);
        case 'page_forms':         return $sumIn('web_stats', 'forms', 'page_id', $links['pages']);
        case 'email_sent':        return $sumIn('emails', 'sent', 'id', $links['emails']);
        case 'email_opens':       return $sumIn('emails', 'opens', 'id', $links['emails']);
        case 'email_clicks':      return $sumIn('emails', 'clicks', 'id', $links['emails']);
        case 'email_conversions': return $sumIn('emails', 'conversions', 'id', $links['emails']);
        case 'contacts':
        case 'contacts_qualified':
        case 'contacts_converted':
            $or = ['funnel_id = ?'];
            $p = [(int) $f['id']];
            foreach (['campaigns' => 'campaign_id', 'pages' => 'page_id', 'emails' => 'email_id', 'social' => 'source'] as $lk => $col) {
                if ($links[$lk]) { $or[] = "$col IN (" . mk_in($links[$lk]) . ')'; array_push($p, ...$links[$lk]); }
            }
            $status = $src === 'contacts_qualified' ? " AND status IN ('qualifie','converti')" : ($src === 'contacts_converted' ? " AND status = 'converti'" : '');
            return (int) qv('SELECT COUNT(*) FROM contacts WHERE (' . implode(' OR ', $or) . ") AND date BETWEEN ? AND ?$status", [...$p, $from, $to]);
    }
    return 0;
}

/**
 * Chiffres d'un funnel sur une période : prospects par étape, taux de conversion depuis
 * l'étape précédente et depuis l'entrée, taux d'abandon, atteinte de l'objectif, et l'étape
 * où l'on perd le plus de prospects.
 */
function mk_funnel_compute(array $f, string $from, string $to): array
{
    $stages = $f['stages'] ?? mk_funnel_stages((int) $f['id']);
    $links = $f['links'] ?? mk_funnel_links($f);
    $out = [];
    $first = null;
    $prev = null;
    $worst = null;
    foreach ($stages as $i => $st) {
        $v = mk_stage_value($st, $f, $links, $from, $to);
        if ($i === 0) $first = $v;
        $convPrev = ($prev !== null && $prev > 0) ? $v / $prev : null;
        $row = [
            'id' => (int) $st['id'], 'name' => $st['name'], 'tags' => array_values(array_filter(explode(',', (string) $st['tags']))),
            'source' => $st['source'], 'target' => (int) $st['target'], 'value' => $v,
            'conv_prev' => $convPrev,
            'conv_start' => ($i > 0 && $first > 0) ? $v / $first : null,
            'drop' => $convPrev === null ? null : max(0.0, 1 - $convPrev),
            'lost' => $prev === null ? null : max(0, $prev - $v),
            'target_pct' => (int) $st['target'] > 0 ? $v / (int) $st['target'] : null,
        ];
        if ($row['drop'] !== null && ($worst === null || $row['drop'] > $out[$worst]['drop'])) $worst = $i;
        $out[] = $row;
        $prev = $v;
    }
    $n = count($out);
    return [
        'id' => (int) $f['id'], 'name' => $f['name'], 'objective' => $f['objective'], 'status' => $f['status'],
        'from' => $from, 'to' => $to, 'stages' => $out,
        'overall' => ($n > 1 && $first > 0) ? $out[$n - 1]['value'] / $first : null,
        'worst' => $worst, // index de l'étape où la perte depuis l'étape précédente est la plus forte
        'links' => [
            'campaigns' => count($links['campaigns']), 'pages' => count($links['pages']),
            'emails' => count($links['emails']), 'social' => $links['social'],
        ],
    ];
}

/* ================================================================== email */

function mk_mail_ready(): bool
{
    return MK_SMTP_HOST !== '' || function_exists('mail');
}

function mk_mail_from(): string
{
    if (MK_MAIL_FROM !== '') return MK_MAIL_FROM;
    if (MK_SMTP_USER !== '' && strpos(MK_SMTP_USER, '@') !== false) return MK_SMTP_USER;
    return 'marketing@dehini.com';
}

function mk_encode_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

/** Envoie un email texte + HTML (SMTP si configuré, sinon mail()). Lève une exception en cas d'échec. */
function mk_send_mail(string $to, string $subject, string $html, string $text): void
{
    $from = mk_mail_from();
    $domain = substr(strrchr($from, '@') ?: '@dehini.com', 1);
    $b = 'b_' . bin2hex(random_bytes(12));
    $headers = implode("\r\n", [
        'From: ' . mk_encode_header(MK_MAIL_FROM_NAME) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ]);
    $body = '--' . $b . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n--" . $b . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n--" . $b . "--\r\n";
    if (MK_SMTP_HOST === '') {
        if (!@mail($to, mk_encode_header($subject), $body, $headers, '-f' . $from)) throw new RuntimeException('mail() a échoué');
        return;
    }
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool) MK_SMTP_VERIFY, 'verify_peer_name' => (bool) MK_SMTP_VERIFY,
        'allow_self_signed' => !MK_SMTP_VERIFY, 'peer_name' => MK_SMTP_HOST,
    ]]);
    $secure = MK_SMTP_SECURE;
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . MK_SMTP_HOST . ':' . (int) MK_SMTP_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP : connexion impossible ($errstr)");
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $out .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        return $out;
    };
    $cmd = function (?string $line, array $codes) use ($fp, $read): void {
        if ($line !== null) fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), $codes, true)) throw new RuntimeException('SMTP : « ' . trim($r) . ' »');
    };
    try {
        $helo = parse_url(mk_site_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, [220]);
        $cmd('EHLO ' . $helo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP : STARTTLS a échoué');
            $cmd('EHLO ' . $helo, [250]);
        }
        if (MK_SMTP_USER !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(MK_SMTP_USER), [334]);
            $cmd(base64_encode(MK_SMTP_PASS), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = 'To: <' . $to . ">\r\nSubject: " . mk_encode_header($subject) . "\r\n" . $headers . "\r\n\r\n" . $body;
        fwrite($fp, preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data)) . "\r\n.\r\n");
        $cmd(null, [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}
