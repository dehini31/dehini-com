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

require_once __DIR__ . '/taxonomy.php'; // canaux (niveau 1) et supports (niveau 2) de communication

/* ================================================================ listes */

/** Transforme un nom en identifiant stable (ex. « Posts & Reels » → « posts_reels »). */
function mk_slug(string $s): string
{
    $s = mb_strtolower(trim($s));
    $map = ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = trim($s, '_');
    return $s !== '' ? mb_substr($s, 0, 30) : 'canal';
}

/** Listes de choix (clé stockée => libellé affiché). */
function mk_enums(): array
{
    static $e = null;
    if ($e === null) {
        $e = [
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
                'stage_posts' => "Actions de communication des canaux de l'étape",
                'stage_reach' => "Portée des actions de l'étape", 'stage_engagement' => "Engagement des actions de l'étape",
                'stage_clicks' => "Clics des actions de l'étape", 'stage_conversions' => "Conversions des actions de l'étape",
                'stage_leads' => "Leads venus des canaux de l'étape", 'stage_leads_qualified' => "Leads qualifiés venus des canaux de l'étape",
                'stage_inquiries' => "Questions reçues par les canaux de l'étape",
                'ads_impressions' => 'Impressions des campagnes liées', 'ads_clicks' => 'Clics des campagnes liées',
                'ads_leads' => 'Leads des campagnes liées', 'ads_conversions' => 'Conversions des campagnes liées',
                'social_reach' => "Portée des comptes sociaux de l'étape (relevés)", 'social_engagement' => "Engagement des comptes sociaux de l'étape (relevés)",
                'social_clicks' => "Clics des comptes sociaux de l'étape (relevés)",
                'page_visits' => 'Visites des pages liées', 'page_registrations' => 'Inscriptions des pages liées',
                'page_forms' => 'Formulaires des pages liées',
                'email_sent' => 'Emails envoyés (liés)', 'email_opens' => 'Ouvertures (emails liés)',
                'email_clicks' => 'Clics (emails liés)', 'email_conversions' => 'Objectifs atteints (emails liés)',
                'contacts' => 'Leads & contacts liés', 'contacts_qualified' => 'Leads qualifiés liés',
                'contacts_converted' => 'Leads convertis liés',
                'stage_conversations' => "Conversations Messenger / Instagram des canaux de l'étape",
                'stage_conversations_hot' => "Conversations chaudes des canaux de l'étape",
                'stage_prospects_messages' => "Prospects créés depuis les messages des canaux de l'étape",
                'parents_sum' => 'Total des étapes précédentes (aiguillage)',
            ],
            // Parcours à branches : nature d'une étape, et filtre des leads d'une branche.
            'stage_kinds' => ['entry' => "Point d'entrée", 'step' => 'Étape', 'split' => 'Aiguillage', 'goal' => 'Objectif final'],
            'stage_kpis' => array_map(fn ($x) => $x[0], MK_STAGE_KPIS),
            'stage_kpi_group' => array_map(fn ($x) => $x[1], MK_STAGE_KPIS),
            'stage_kpi_fmt' => array_map(fn ($x) => $x[2], MK_STAGE_KPIS),
            'stage_kpi_groups' => MK_STAGE_KPI_GROUPS,
            'stage_segments' => ['' => 'Tous les leads', 'investisseur' => 'Investisseurs', 'porteur' => 'Porteurs de projets',
                'qualifie' => 'Qualifiés (et convertis)', 'non_qualifie' => 'Non qualifiés', 'converti' => 'Convertis'],
        ];
    }
    $out = $e;
    $out['commercials'] = [];
    foreach (mk_settings()['commercials'] as $name) $out['commercials'][$name] = $name;
    // Niveau 1 — canaux : « sources » = tous les canaux, « platforms » = réseaux sociaux,
    // « ad_channels » = publicité digitale (Ads Manager). Niveau 2 — « supports ».
    $out['channel_categories'] = array_map(fn ($c) => $c[1], MK_CHANNEL_CATEGORIES);
    $out['support_types'] = array_map(fn ($t) => $t[1], MK_SUPPORT_TYPES);
    $out['sources'] = [];
    $out['platforms'] = [];
    $out['ad_channels'] = [];
    foreach (mk_channels() as $c) {
        $out['sources'][$c['key']] = $c['label'];
        if ($c['category'] === 'social') $out['platforms'][$c['key']] = $c['label'];
        if ($c['category'] === 'ads') $out['ad_channels'][$c['key']] = $c['label'];
    }
    $out['supports'] = [];
    foreach (mk_supports() as $sp) $out['supports'][$sp['key']] = $sp['label'];
    // Tous les noms connus, éléments retirés compris (affichage des anciennes données seulement).
    $set = mk_settings();
    $out['channel_labels'] = $out['sources'] + (is_array($set['channels_archive'] ?? null) ? array_map('strval', $set['channels_archive']) : []);
    $out['support_labels'] = $out['supports'] + (is_array($set['supports_archive'] ?? null) ? array_map('strval', $set['supports_archive']) : []);
    return $out;
}

function mk_label(string $enum, $key): string
{
    $all = mk_enums();
    $v = $all[$enum][(string) $key] ?? null;
    if ($v === null && in_array($enum, ['sources', 'platforms', 'ad_channels'], true)) $v = $all['channel_labels'][(string) $key] ?? null;
    if ($v === null && $enum === 'supports') $v = $all['support_labels'][(string) $key] ?? null;
    return (string) ($v ?? (string) $key);
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
            'source'      => f('enum', 'Canal (source)', ['opts' => 'sources', 'req' => 1]),
            'support'     => f('enum', 'Support', ['opts' => 'supports', 'empty' => 'Non précisé', 'help' => 'Le format qui a amené le lead (Reel, carrousel, newsletter…).']),
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
            'source'      => f('enum', 'Canal de communication', ['opts' => 'sources', 'req' => 1]),
            'name'        => f('text', 'Nom'),
            'contact'     => f('text', 'Téléphone / email'),
            'type'        => f('enum', 'Profil', ['opts' => 'contact_types']),
            'category'    => f('enum', 'Sujet', ['opts' => 'inq_categories', 'req' => 1]),
            'question'    => f('longtext', 'Question / message', ['req' => 1]),
            'status'      => f('enum', 'Statut', ['opts' => 'inq_status', 'req' => 1, 'def' => 'nouveau']),
            'assigned_to' => f('enum', 'Pris en charge par', ['opts' => 'commercials', 'empty' => 'Personne']),
            'answer'      => f('longtext', 'Réponse apportée'),
            'link'        => f('url', 'Lien (commentaire, message…)'),
        ]],
        'projects' => ['label' => 'Projet', 'date' => 'date', 'title' => 'name', 'fields' => [
            'date'          => f('date', 'Date de la demande', ['req' => 1, 'def' => 'today']),
            'name'          => f('text', 'Nom du projet', ['req' => 1]),
            'owner'         => f('text', 'Porteur de projet'),
            'contact'       => f('text', 'Téléphone / email'),
            'sector'        => f('text', "Secteur d'activité"),
            'source'        => f('enum', 'Canal (source)', ['opts' => 'sources']),
            'status'        => f('enum', 'Statut', ['opts' => 'project_status', 'req' => 1, 'def' => 'demande']),
            'amount_target' => f('money', 'Montant recherché'),
            'raised'        => f('money', 'Montant levé (total)'),
            'investors'     => f('int', "Nombre d'investisseurs"),
            'as_of'         => f('date', 'Chiffres arrêtés au', ['def' => 'today', 'help' => "Date du montant levé : sert à calculer ce qui a été levé chaque semaine."]),
            'funding_start' => f('date', 'Début de la levée'),
            'funding_end'   => f('date', 'Fin de la levée'),
            'notes'         => f('longtext', 'Notes'),
        ]],
        // Action de communication : Catégorie → Canal → Support → Contenu → Cible → Objectif → KPI.
        // (La table s'appelle « posts » pour garder les données des versions précédentes.)
        'posts' => ['label' => 'Action de communication', 'date' => 'date', 'title' => 'topic', 'fields' => [
            'date'        => f('date', 'Date', ['req' => 1, 'def' => 'today']),
            'platform'    => f('enum', 'Canal', ['opts' => 'sources', 'req' => 1]),
            'format'      => f('enum', 'Support', ['opts' => 'supports', 'req' => 1]),
            'topic'       => f('text', 'Contenu / message', ['req' => 1]),
            'audience'    => f('enum', 'Cible', ['opts' => 'audiences']),
            'objective'   => f('enum', 'Objectif', ['opts' => 'ad_objectives']),
            'link'        => f('url', 'Lien'),
            'reach'       => f('int', 'Portée'),
            'impressions' => f('int', 'Impressions'),
            'engagement'  => f('int', 'Engagement'),
            'clicks'      => f('int', 'Clics'),
            'leads'       => f('int', 'Leads'),
            'conversions' => f('int', 'Conversions'),
            'cost'        => f('money', 'Coût'),
        ]],
        'social_stats' => ['label' => 'Performances réseau social', 'date' => 'date', 'title' => 'platform', 'fields' => [
            'date'          => f('date', 'Date (dernier jour couvert)', ['req' => 1, 'def' => 'today', 'help' => "Une ligne = les chiffres d'un jour ou d'une semaine, datés du dernier jour couvert."]),
            'platform'      => f('enum', 'Réseau social', ['opts' => 'platforms', 'req' => 1]),
            'followers'     => f('int', 'Abonnés (total)'),
            'new_followers' => f('int', 'Nouveaux abonnés'),
            'reach'         => f('int', 'Portée'),
            'impressions'   => f('int', 'Impressions'),
            'engagement'    => f('int', 'Engagement (réactions, commentaires, partages)'),
            'clicks'        => f('int', 'Clics'),
        ]],
        'campaigns' => ['label' => 'Campagne publicitaire', 'date' => '', 'title' => 'name', 'fields' => [
            'name'         => f('text', 'Nom de la campagne', ['req' => 1]),
            'platform'     => f('enum', 'Canal publicitaire', ['opts' => 'ad_channels', 'req' => 1, 'def' => 'meta_ads']),
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
            'support'      => f('enum', 'Support', ['opts' => 'supports', 'def' => 'newsletter']),
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

/** Tables qui reçoivent des données importées automatiquement (colonne ext_id). */
const MK_EXT_TABLES = ['posts', 'social_stats', 'campaigns', 'ad_results', 'contacts', 'inquiries'];

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
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' || strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
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
    $hash = md5(json_encode(mk_schema()) . '|v7');
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
        source TEXT NOT NULL DEFAULT 'manual',
        channels TEXT NOT NULL DEFAULT ''
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
    // Canaux de communication de chaque étape de funnel (ajouté après coup aux bases existantes).
    $stageCols = array_column($pdo->query('PRAGMA table_info(funnel_stages)')->fetchAll(), 'name');
    if (!in_array('channels', $stageCols, true)) $pdo->exec("ALTER TABLE funnel_stages ADD COLUMN channels TEXT NOT NULL DEFAULT ''");
    // Supports de communication et KPIs affichés de chaque étape ; un chiffre saisi peut être celui d'un KPI manuel.
    if (!in_array('supports', $stageCols, true)) {
        $pdo->exec("ALTER TABLE funnel_stages ADD COLUMN supports TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE funnel_stages ADD COLUMN kpis TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('kpi', array_column($pdo->query('PRAGMA table_info(funnel_entries)')->fetchAll(), 'name'), true)) {
        $pdo->exec("ALTER TABLE funnel_entries ADD COLUMN kpi TEXT NOT NULL DEFAULT ''");
    }
    // Parcours à branches : étapes précédentes (plusieurs = circuits qui se rejoignent), nature, libellé de branche, segment.
    if (!in_array('parents', $stageCols, true)) {
        $pdo->exec("ALTER TABLE funnel_stages ADD COLUMN parents TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE funnel_stages ADD COLUMN kind TEXT NOT NULL DEFAULT 'step'");
        $pdo->exec("ALTER TABLE funnel_stages ADD COLUMN branch TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE funnel_stages ADD COLUMN segment TEXT NOT NULL DEFAULT ''");
        // Les funnels d'avant étaient en ligne droite : chaque étape suit la précédente.
        $prevBy = [];
        foreach ($pdo->query('SELECT id, funnel_id FROM funnel_stages ORDER BY funnel_id, position, id')->fetchAll() as $r) {
            $fid = (int) $r['funnel_id'];
            if (isset($prevBy[$fid])) $pdo->prepare('UPDATE funnel_stages SET parents = ? WHERE id = ?')->execute([(string) $prevBy[$fid], (int) $r['id']]);
            else $pdo->prepare("UPDATE funnel_stages SET kind = 'entry' WHERE id = ?")->execute([(int) $r['id']]);
            $prevBy[$fid] = (int) $r['id'];
        }
    }
    // Les anciens « réseaux sociaux associés » au funnel (doublon des canaux d'étape) passent sur
    // sa première étape quand aucune étape n'a encore de canal, puis la colonne est vidée.
    $funnelCols = array_column($pdo->query('PRAGMA table_info(funnels)')->fetchAll(), 'name');
    foreach (in_array('social', $funnelCols, true) ? $pdo->query("SELECT id, social FROM funnels WHERE social != ''")->fetchAll() : [] as $fu) {
        $has = (int) $pdo->query('SELECT COUNT(*) FROM funnel_stages WHERE funnel_id = ' . (int) $fu['id'] . " AND channels != ''")->fetchColumn();
        if (!$has) $pdo->prepare('UPDATE funnel_stages SET channels = ? WHERE id = (SELECT id FROM funnel_stages WHERE funnel_id = ? ORDER BY position, id LIMIT 1)')->execute([$fu['social'], (int) $fu['id']]);
        $pdo->prepare("UPDATE funnels SET social = '' WHERE id = ?")->execute([(int) $fu['id']]);
    }
    // Données importées automatiquement (Meta…) : leur identifiant d'origine, pour les mettre à jour sans doublon.
    foreach (MK_EXT_TABLES as $t) {
        $have = array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name');
        if (!in_array('ext_id', $have, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN ext_id TEXT NOT NULL DEFAULT ''");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$t}_ext ON $t(ext_id) WHERE ext_id != ''");
    }
    $pdo->exec(<<<SQL
    -- Conversations Messenger et Instagram (importées de Meta), analysées pour en faire des prospects.
    CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ext_id TEXT NOT NULL UNIQUE,
        platform TEXT NOT NULL DEFAULT '',
        channel TEXT NOT NULL DEFAULT '',
        who_id TEXT NOT NULL DEFAULT '',
        who_name TEXT NOT NULL DEFAULT '',
        who_username TEXT NOT NULL DEFAULT '',
        updated TEXT NOT NULL DEFAULT '',
        first_date TEXT NOT NULL DEFAULT '',
        last_date TEXT NOT NULL DEFAULT '',
        message_count INTEGER NOT NULL DEFAULT 0,
        in_count INTEGER NOT NULL DEFAULT 0,
        out_count INTEGER NOT NULL DEFAULT 0,
        last_from_me INTEGER NOT NULL DEFAULT 0,
        snippet TEXT NOT NULL DEFAULT '',
        link TEXT NOT NULL DEFAULT '',
        category TEXT NOT NULL DEFAULT '',
        profile TEXT NOT NULL DEFAULT '',
        heat TEXT NOT NULL DEFAULT '',
        score INTEGER NOT NULL DEFAULT 0,
        is_question INTEGER NOT NULL DEFAULT 0,
        interest INTEGER NOT NULL DEFAULT 0,
        phone TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        reply_minutes INTEGER,
        contact_id INTEGER,
        status TEXT NOT NULL DEFAULT 'a_traiter',
        created INTEGER NOT NULL DEFAULT 0,
        synced INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS conversations_d ON conversations(last_date);
    CREATE TABLE IF NOT EXISTS conversation_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conv_id INTEGER NOT NULL,
        ext_id TEXT NOT NULL UNIQUE,
        from_me INTEGER NOT NULL DEFAULT 0,
        text TEXT NOT NULL DEFAULT '',
        at TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS conversation_messages_c ON conversation_messages(conv_id, at);
    CREATE TABLE IF NOT EXISTS comments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ext_id TEXT NOT NULL UNIQUE,
        platform TEXT NOT NULL DEFAULT '',
        post_ext TEXT NOT NULL DEFAULT '',
        post_id INTEGER,
        post_link TEXT NOT NULL DEFAULT '',
        post_text TEXT NOT NULL DEFAULT '',
        parent_ext TEXT NOT NULL DEFAULT '',
        who_id TEXT NOT NULL DEFAULT '',
        who_name TEXT NOT NULL DEFAULT '',
        who_username TEXT NOT NULL DEFAULT '',
        from_me INTEGER NOT NULL DEFAULT 0,
        text TEXT NOT NULL DEFAULT '',
        at TEXT NOT NULL DEFAULT '',
        date TEXT NOT NULL DEFAULT '',
        link TEXT NOT NULL DEFAULT '',
        likes INTEGER NOT NULL DEFAULT 0,
        replies INTEGER NOT NULL DEFAULT 0,
        page_replied INTEGER NOT NULL DEFAULT 0,
        is_question INTEGER NOT NULL DEFAULT 0,
        category TEXT NOT NULL DEFAULT '',
        profile TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        email TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        inquiry_id INTEGER,
        contact_id INTEGER,
        status TEXT NOT NULL DEFAULT 'a_traiter',
        created INTEGER NOT NULL DEFAULT 0,
        synced INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS comments_d ON comments(date);
    CREATE INDEX IF NOT EXISTS comments_p ON comments(post_ext);
    CREATE INDEX IF NOT EXISTS comments_r ON comments(parent_ext);
    CREATE TABLE IF NOT EXISTS meta_comment_seen (
        post_ext TEXT PRIMARY KEY,
        total INTEGER NOT NULL DEFAULT 0,
        at INTEGER NOT NULL DEFAULT 0
    );
    -- Données importées puis supprimées par l'équipe : à ne pas réimporter.
    CREATE TABLE IF NOT EXISTS ext_ignored (ext_id TEXT PRIMARY KEY, at INTEGER NOT NULL DEFAULT 0);
    -- Historique des synchronisations avec Meta.
    CREATE TABLE IF NOT EXISTS meta_sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        at INTEGER NOT NULL,
        date_from TEXT NOT NULL DEFAULT '',
        date_to TEXT NOT NULL DEFAULT '',
        ok INTEGER NOT NULL DEFAULT 1,
        added INTEGER NOT NULL DEFAULT 0,
        updated INTEGER NOT NULL DEFAULT 0,
        result TEXT NOT NULL DEFAULT ''
    );
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
        'owner_name'    => '', // prénom affiché dans le message d'accueil (« Bonjour, Mohammed »)
        'currency'      => 'DA',
        'commercials'   => [],
        'recipients'    => [],
        'channels'      => mk_default_channels(),
        'supports'      => mk_default_supports(),
        'channels_archive' => [], // nom des canaux retirés (clé => libellé), pour les données qui les citent encore
        'supports_archive' => [],
        'taxonomy'      => 2,
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
    if (!is_array($d)) $d = [];
    // Une seule fois : passage aux canaux par catégorie et aux supports (lib/taxonomy.php).
    if ((int) ($d['taxonomy'] ?? 0) < 2) {
        $d = mk_taxonomy_migrate($d);
        db()->prepare('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)')->execute(['app', json_encode($d, JSON_UNESCAPED_UNICODE)]);
    }
    $cache = array_merge(mk_settings_defaults(), $d);
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
    if (array_key_exists('owner_name', $in)) $next['owner_name'] = mk_str($in['owner_name'], 40);
    if (array_key_exists('commercials', $in)) $next['commercials'] = $lines($in['commercials']);
    if (array_key_exists('channels', $in) || array_key_exists('supports', $in)) $next = mk_taxonomy_apply($next, $in);
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
        // Un prospect supprimé : sa conversation redevient « à traiter ».
        if ($entity === 'contacts') {
            $pdo->prepare("UPDATE conversations SET contact_id = NULL, status = 'a_traiter' WHERE contact_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE comments SET contact_id = NULL, status = 'a_traiter' WHERE contact_id = ?")->execute([$id]);
        }
        if ($entity === 'inquiries') $pdo->prepare('UPDATE comments SET inquiry_id = NULL WHERE inquiry_id = ?')->execute([$id]);
        // Une donnée importée (Meta…) puis supprimée ne doit pas revenir à la synchronisation suivante.
        if (in_array($entity, MK_EXT_TABLES, true)) {
            $ext = (string) qv("SELECT ext_id FROM $entity WHERE id = ?", [$id]);
            if ($ext !== '') $pdo->prepare('INSERT OR REPLACE INTO ext_ignored (ext_id, at) VALUES (?, ?)')->execute([$ext, time()]);
            if ($entity === 'campaigns') {
                foreach (q("SELECT ext_id FROM ad_results WHERE campaign_id = ? AND ext_id != ''", [$id]) as $r) $pdo->prepare('INSERT OR REPLACE INTO ext_ignored (ext_id, at) VALUES (?, ?)')->execute([$r['ext_id'], time()]);
            }
        }
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

/**
 * Ce qui est associé au funnel : campagnes, pages, emails (par leur champ funnel_id), et les
 * canaux de communication de ses étapes (une seule fois chacun).
 */
function mk_funnel_links(array $f): array
{
    $fid = (int) $f['id'];
    $chs = [];
    foreach (q("SELECT channels FROM funnel_stages WHERE funnel_id = ? AND channels != ''", [$fid]) as $r) {
        foreach (explode(',', (string) $r['channels']) as $c) if ($c !== '' && !in_array($c, $chs, true)) $chs[] = $c;
    }
    return [
        'campaigns' => array_map('intval', array_column(q('SELECT id FROM campaigns WHERE funnel_id = ?', [$fid]), 'id')),
        'pages'     => array_map('intval', array_column(q('SELECT id FROM pages WHERE funnel_id = ?', [$fid]), 'id')),
        'emails'    => array_map('intval', array_column(q('SELECT id FROM emails WHERE funnel_id = ?', [$fid]), 'id')),
        'channels'  => $chs,
    ];
}

/**
 * Crée ou modifie un funnel et son parcours. Chaque étape arrive avec une clé (« key », provisoire
 * pour une nouvelle étape) et la liste des clés des étapes qui y mènent (« parents ») : plusieurs
 * points d'entrée, des aiguillages (plusieurs suites) et des circuits qui se rejoignent (plusieurs
 * parents) sont possibles ; une boucle ne l'est pas. Une étape retirée perd ses chiffres saisis.
 */
function mk_funnel_save(array $in): array
{
    $pdo = db();
    $id = (int) ($in['id'] ?? 0);
    $stages = is_array($in['stages'] ?? null) ? array_values($in['stages']) : [];
    if (!$stages) throw new MkInputError('Ajoutez au moins une étape au parcours.');
    if (count($stages) > 40) throw new MkInputError('40 étapes au maximum par funnel.');
    $E = mk_enums();
    $sources = $E['stage_sources'];
    $channels = $E['sources'];
    $clean = [];
    $keys = [];
    foreach ($stages as $i => $st) {
        $key = (string) ($st['key'] ?? ((int) ($st['id'] ?? 0) > 0 ? 'id' . (int) $st['id'] : 'n' . $i));
        if ($key === '' || isset($keys[$key])) $key = 'n' . $i . '_' . count($keys);
        $keys[$key] = $i;
        $name = mk_str($st['name'] ?? '', 80);
        if ($name === '') throw new MkInputError('Étape ' . ($i + 1) . ' : donnez-lui un nom.');
        $src = (string) ($st['source'] ?? 'manual');
        if (!array_key_exists($src, $sources)) $src = 'manual';
        $kind = array_key_exists((string) ($st['kind'] ?? ''), $E['stage_kinds']) ? (string) $st['kind'] : 'step';
        $seg = (string) ($st['segment'] ?? '');
        if (!array_key_exists($seg, $E['stage_segments'])) $seg = '';
        $tags = [];
        foreach (is_array($st['tags'] ?? null) ? $st['tags'] : explode(',', (string) ($st['tags'] ?? '')) as $t) {
            $t = mk_str(ltrim(trim((string) $t), '#'), 40);
            if ($t !== '' && !in_array($t, $tags, true)) $tags[] = $t;
        }
        // Canaux de communication de l'étape : ceux de la liste, plus ceux qu'elle avait déjà
        // (un canal retiré des Réglages n'est pas effacé des étapes sans qu'on le décoche).
        $had = [];
        if ((int) ($st['id'] ?? 0) > 0) $had = explode(',', (string) qv('SELECT channels FROM funnel_stages WHERE id = ?', [(int) $st['id']]));
        $chs = [];
        foreach (is_array($st['channels'] ?? null) ? $st['channels'] : explode(',', (string) ($st['channels'] ?? '')) as $c) {
            $c = (string) $c;
            if ($c !== '' && (isset($channels[$c]) || in_array($c, $had, true)) && !in_array($c, $chs, true)) $chs[] = $c;
        }
        // Supports : ceux de la liste, plus ceux que l'étape avait déjà.
        $hadSup = (int) ($st['id'] ?? 0) > 0 ? explode(',', (string) qv('SELECT supports FROM funnel_stages WHERE id = ?', [(int) $st['id']])) : [];
        $sups = [];
        foreach (is_array($st['supports'] ?? null) ? $st['supports'] : explode(',', (string) ($st['supports'] ?? '')) as $x) {
            $x = (string) $x;
            if ($x !== '' && (isset($E['supports'][$x]) || in_array($x, $hadSup, true)) && !in_array($x, $sups, true)) $sups[] = $x;
        }
        $clean[] = ['key' => $key, 'id' => (int) ($st['id'] ?? 0), 'name' => $name, 'target' => max(0, (int) ($st['target'] ?? 0)),
            'supports' => implode(',', array_slice($sups, 0, 30)), 'kpis' => mk_stage_kpis_clean($st['kpis'] ?? []),
            'tags' => implode(',', array_slice($tags, 0, 12)), 'source' => $src, 'channels' => implode(',', $chs),
            'kind' => $kind, 'segment' => $seg, 'branch' => mk_str($st['branch'] ?? '', 40),
            'parents' => array_values(array_unique(array_map('strval', is_array($st['parents'] ?? null) ? $st['parents'] : array_filter(explode(',', (string) ($st['parents'] ?? '')))))),
        ];
    }
    // Ancien format (liste sans « parents ») : les étapes se suivent en ligne droite.
    if (!array_filter($stages, fn ($st) => is_array($st) && array_key_exists('parents', $st))) {
        foreach ($clean as $i => $st) {
            $clean[$i]['parents'] = $i ? [$clean[$i - 1]['key']] : [];
            if (!$i && $st['kind'] === 'step') $clean[$i]['kind'] = 'entry';
        }
    }
    // Les parents doivent exister ; le parcours ne doit pas boucler.
    foreach ($clean as $i => $st) {
        $clean[$i]['parents'] = array_values(array_filter($st['parents'], fn ($k) => isset($keys[$k]) && $k !== $st['key']));
        // Une étape sans étape précédente est un point d'entrée ; une étape qui en a une n'en est plus un.
        if (!$clean[$i]['parents']) $clean[$i]['kind'] = 'entry';
        elseif ($clean[$i]['kind'] === 'entry') $clean[$i]['kind'] = 'step';
    }
    $state = [];
    $visit = function (string $k) use (&$visit, &$state, $clean, $keys): void {
        if (($state[$k] ?? 0) === 2) return;
        if (($state[$k] ?? 0) === 1) throw new MkInputError("Le parcours tourne en rond : « {$clean[$keys[$k]]['name']} » mène à une étape qui la précède. Retirez une des flèches.");
        $state[$k] = 1;
        foreach ($clean[$keys[$k]]['parents'] as $pk) $visit($pk);
        $state[$k] = 2;
    };
    foreach ($clean as $st) $visit($st['key']);
    $pdo->beginTransaction();
    try {
        $f = mk_save('funnels', $in['funnel'] ?? [], $id);
        $fid = (int) $f['id'];
        $keep = [];
        $idOf = [];
        foreach ($clean as $pos => $st) {
            $sid = $st['id'];
            $vals = [$pos, $st['name'], $st['target'], $st['tags'], $st['source'], $st['channels'], $st['kind'], $st['branch'], $st['segment'], $st['supports'], json_encode($st['kpis'], JSON_UNESCAPED_UNICODE)];
            if ($sid > 0 && qv('SELECT 1 FROM funnel_stages WHERE id = ? AND funnel_id = ?', [$sid, $fid])) {
                $pdo->prepare('UPDATE funnel_stages SET position = ?, name = ?, target = ?, tags = ?, source = ?, channels = ?, kind = ?, branch = ?, segment = ?, supports = ?, kpis = ? WHERE id = ?')
                    ->execute([...$vals, $sid]);
                // KPI manuel retiré : ses chiffres saisis partent avec lui.
                $manual = array_values(array_filter(array_column($st['kpis'], 'k'), fn ($k) => strpos($k, 'm_') === 0));
                $pdo->prepare("DELETE FROM funnel_entries WHERE stage_id = ? AND kpi != ''" . ($manual ? ' AND kpi NOT IN (' . mk_in($manual) . ')' : ''))->execute([$sid, ...$manual]);
            } else {
                $pdo->prepare('INSERT INTO funnel_stages (position, name, target, tags, source, channels, kind, branch, segment, supports, kpis, funnel_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([...$vals, $fid]);
                $sid = (int) $pdo->lastInsertId();
            }
            $idOf[$st['key']] = $sid;
            $keep[] = $sid;
        }
        foreach ($clean as $st) {
            $pdo->prepare('UPDATE funnel_stages SET parents = ? WHERE id = ?')->execute([implode(',', array_map(fn ($k) => $idOf[$k], $st['parents'])), $idOf[$st['key']]]);
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

/**
 * KPIs qu'une étape peut afficher : clé => [libellé, groupe, format]. Format : num (nombre), pct (taux,
 * entre 0 et 1) ou money. Les KPIs « manuels » (clé m_…) sont créés par l'équipe et saisis à la main.
 */
const MK_STAGE_KPIS = [
    'actions' => ['Actions publiées', 'diffusion', 'num'], 'reach' => ['Portée', 'diffusion', 'num'], 'impressions' => ['Impressions', 'diffusion', 'num'],
    'engagement' => ['Engagement', 'diffusion', 'num'], 'engagement_rate' => ["Taux d'engagement", 'diffusion', 'pct'], 'clicks' => ['Clics', 'diffusion', 'num'],
    'ctr' => ['Taux de clic', 'diffusion', 'pct'], 'conversions' => ['Conversions', 'diffusion', 'num'], 'post_leads' => ['Leads des actions', 'diffusion', 'num'],
    'cost' => ['Coût des actions', 'diffusion', 'money'],
    'followers' => ['Abonnés', 'comptes', 'num'], 'new_followers' => ['Nouveaux abonnés', 'comptes', 'num'], 'social_reach' => ['Portée des comptes', 'comptes', 'num'],
    'social_engagement' => ['Engagement des comptes', 'comptes', 'num'],
    'leads' => ['Leads', 'leads', 'num'], 'leads_qualified' => ['Leads qualifiés', 'leads', 'num'], 'leads_converted' => ['Leads convertis', 'leads', 'num'],
    'qual_rate' => ['Taux de qualification', 'leads', 'pct'], 'conv_rate' => ['Taux de conversion des leads', 'leads', 'pct'], 'inquiries' => ['Questions reçues', 'leads', 'num'],
    'conversations' => ['Conversations', 'messages', 'num'], 'conversations_hot' => ['Conversations chaudes', 'messages', 'num'],
    'prospects_messages' => ['Prospects issus des messages', 'messages', 'num'], 'comments' => ['Commentaires', 'messages', 'num'],
    'comments_questions' => ['Commentaires qui posent une question', 'messages', 'num'], 'reply_rate' => ['Taux de réponse aux commentaires', 'messages', 'pct'],
    'ads_spend' => ['Dépense publicitaire', 'ads', 'money'], 'ads_impressions' => ['Impressions pub', 'ads', 'num'], 'ads_clicks' => ['Clics pub', 'ads', 'num'],
    'ads_ctr' => ['Taux de clic pub (CTR)', 'ads', 'pct'], 'ads_leads' => ['Leads pub', 'ads', 'num'], 'ads_cpl' => ['Coût par lead', 'ads', 'money'],
    'ads_conversions' => ['Conversions pub', 'ads', 'num'],
    'page_visits' => ['Visites des pages', 'web', 'num'], 'page_registrations' => ['Inscriptions', 'web', 'num'], 'page_forms' => ['Formulaires envoyés', 'web', 'num'],
    'page_conv_rate' => ["Taux d'inscription", 'web', 'pct'],
    'email_sent' => ['Emails envoyés', 'email', 'num'], 'email_opens' => ['Ouvertures', 'email', 'num'], 'open_rate' => ["Taux d'ouverture", 'email', 'pct'],
    'email_clicks' => ['Clics email', 'email', 'num'], 'click_rate' => ['Taux de clic email', 'email', 'pct'],
];
const MK_STAGE_KPI_GROUPS = [
    'diffusion' => "Actions des canaux et supports de l'étape", 'comptes' => "Relevés des comptes (canaux de l'étape)",
    'leads' => "Leads des canaux et supports de l'étape", 'messages' => 'Messages et commentaires (Messenger, Facebook, Instagram)',
    'ads' => 'Campagnes associées au funnel', 'web' => 'Pages associées au funnel', 'email' => 'Emails associés au funnel',
];

/** KPIs d'une étape tels qu'enregistrés : [{k, t}] pour un KPI du catalogue, [{k: m_…, l, f, a, t}] pour un KPI manuel. */
function mk_stage_kpis_clean($in): array
{
    if (is_string($in)) $in = json_decode($in, true);
    $out = [];
    $seen = [];
    foreach (is_array($in) ? $in : [] as $x) {
        if (!is_array($x)) continue;
        $k = (string) ($x['k'] ?? '');
        if ($k === '' || isset($seen[$k])) continue;
        $t = max(0, round((float) ($x['t'] ?? 0), 2));
        if (isset(MK_STAGE_KPIS[$k])) {
            $out[] = ['k' => $k, 't' => $t];
        } elseif (preg_match('/^m_[a-z0-9_]{1,40}$/', $k)) {
            $l = mk_str($x['l'] ?? '', 60);
            if ($l === '') throw new MkInputError('Donnez un nom à chaque KPI manuel.');
            $out[] = ['k' => $k, 'l' => $l, 'f' => in_array($x['f'] ?? '', ['num', 'pctv', 'money'], true) ? $x['f'] : 'num',
                'a' => ($x['a'] ?? '') === 'last' ? 'last' : 'sum', 't' => $t];
        } else {
            continue;
        }
        $seen[$k] = true;
        if (count($out) >= 12) break;
    }
    return $out;
}

/**
 * Requêtes communes d'une étape : ses canaux, ses supports (actions et leads de ces supports
 * seulement) et le segment de leads de sa branche.
 */
function mk_stage_q(array $st, string $from, string $to): array
{
    $chs = array_values(array_filter(explode(',', (string) ($st['channels'] ?? ''))));
    $sups = array_values(array_filter(explode(',', (string) ($st['supports'] ?? ''))));
    $supIn = $sups ? ' IN (' . implode(',', array_map(fn ($x) => db()->quote($x), $sups)) . ')' : '';
    $seg = (string) ($st['segment'] ?? '');
    $segSql = ['investisseur' => " AND type = 'investisseur'", 'porteur' => " AND type = 'porteur'", 'qualifie' => " AND status IN ('qualifie','converti')",
        'non_qualifie' => " AND status = 'non_qualifie'", 'converti' => " AND status = 'converti'"][$seg] ?? '';
    $extraFor = function (string $table) use ($supIn, $segSql): string {
        if ($table === 'posts') return $supIn ? " AND format$supIn" : '';
        if ($table === 'contacts') return $segSql . ($supIn ? " AND support$supIn" : '');
        if ($table === 'inquiries' && in_array($segSql, [" AND type = 'investisseur'", " AND type = 'porteur'"], true)) return $segSql;
        return '';
    };
    $sum = function (string $table, string $col, string $key, array $ids, bool $float = false) use ($from, $to, $extraFor) {
        if (!$ids) return 0;
        $v = (float) qv("SELECT COALESCE(SUM($col), 0) FROM $table WHERE $key IN (" . mk_in($ids) . ') AND date BETWEEN ? AND ?' . $extraFor($table), [...$ids, $from, $to]);
        return $float ? $v : (int) round($v);
    };
    $count = function (string $table, string $key, array $vals, string $extra = '') use ($from, $to, $extraFor): int {
        if (!$vals) return 0;
        return (int) qv("SELECT COUNT(*) FROM $table WHERE $key IN (" . mk_in($vals) . ") AND date BETWEEN ? AND ?$extra" . $extraFor($table), [...$vals, $from, $to]);
    };
    // Messagerie : canal Messenger ou Facebook → Messenger ; Instagram → Instagram Direct.
    $plats = [];
    if (array_intersect(['messenger', 'facebook'], $chs)) $plats[] = 'messenger';
    if (in_array('instagram', $chs, true)) $plats[] = 'instagram';
    $convs = function (string $extra) use ($plats, $from, $to): int {
        if (!$plats) return 0;
        return (int) qv('SELECT COUNT(*) FROM conversations WHERE platform IN (' . mk_in($plats) . ") AND first_date BETWEEN ? AND ?$extra", [...$plats, $from, $to]);
    };
    $cplats = array_values(array_intersect(['facebook', 'instagram'], $chs));
    $cmts = function (string $extra) use ($cplats, $from, $to): int {
        if (!$cplats) return 0;
        return (int) qv('SELECT COUNT(*) FROM comments WHERE platform IN (' . mk_in($cplats) . ") AND date BETWEEN ? AND ?$extra", [...$cplats, $from, $to]);
    };
    return ['chs' => $chs, 'segSql' => $segSql, 'sum' => $sum, 'count' => $count, 'convs' => $convs, 'cmts' => $cmts];
}

/** Valeur d'une étape sur une période, selon sa source. */
function mk_stage_value(array $st, array $f, array $links, string $from, string $to): int
{
    $src = (string) $st['source'];
    ['chs' => $chs, 'segSql' => $segSql, 'sum' => $sumIn, 'count' => $countIn, 'convs' => $convs] = mk_stage_q($st, $from, $to);
    switch ($src) {
        case 'manual':
            return (int) round((float) qv("SELECT COALESCE(SUM(count), 0) FROM funnel_entries WHERE stage_id = ? AND kpi = '' AND date BETWEEN ? AND ?", [(int) $st['id'], $from, $to]));
        case 'stage_posts':           return $countIn('posts', 'platform', $chs);
        case 'stage_reach':           return $sumIn('posts', 'reach', 'platform', $chs);
        case 'stage_engagement':      return $sumIn('posts', 'engagement', 'platform', $chs);
        case 'stage_clicks':          return $sumIn('posts', 'clicks', 'platform', $chs);
        case 'stage_conversions':     return $sumIn('posts', 'conversions', 'platform', $chs);
        case 'stage_leads':           return $countIn('contacts', 'source', $chs);
        case 'stage_leads_qualified': return $countIn('contacts', 'source', $chs, " AND status IN ('qualifie','converti')");
        case 'stage_inquiries':       return $countIn('inquiries', 'source', $chs);
        case 'stage_conversations':      return $convs('');
        case 'stage_conversations_hot':  return $convs(" AND heat = 'chaud'");
        case 'stage_prospects_messages': return $convs(' AND contact_id IS NOT NULL');
        case 'ads_impressions': return $sumIn('ad_results', 'impressions', 'campaign_id', $links['campaigns']);
        case 'ads_clicks':      return $sumIn('ad_results', 'clicks', 'campaign_id', $links['campaigns']);
        case 'ads_leads':       return $sumIn('ad_results', 'leads', 'campaign_id', $links['campaigns']);
        case 'ads_conversions': return $sumIn('ad_results', 'conversions', 'campaign_id', $links['campaigns']);
        case 'social_reach':      return $sumIn('social_stats', 'reach', 'platform', $chs);
        case 'social_engagement': return $sumIn('social_stats', 'engagement', 'platform', $chs);
        case 'social_clicks':     return $sumIn('social_stats', 'clicks', 'platform', $chs);
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
            foreach (['campaigns' => 'campaign_id', 'pages' => 'page_id', 'emails' => 'email_id', 'channels' => 'source'] as $lk => $col) {
                if ($links[$lk]) { $or[] = "$col IN (" . mk_in($links[$lk]) . ')'; array_push($p, ...$links[$lk]); }
            }
            $status = ($src === 'contacts_qualified' ? " AND status IN ('qualifie','converti')" : ($src === 'contacts_converted' ? " AND status = 'converti'" : '')) . $segSql;
            return (int) qv('SELECT COUNT(*) FROM contacts WHERE (' . implode(' OR ', $or) . ") AND date BETWEEN ? AND ?$status", [...$p, $from, $to]);
    }
    return 0;
}

/** Valeur d'un KPI d'une étape sur une période (null : pas calculable, par exemple un taux sur zéro). */
function mk_stage_kpi(array $kd, array $st, array $links, string $from, string $to): ?float
{
    $k = $kd['k'];
    if (strpos($k, 'm_') === 0) {
        if (($kd['a'] ?? 'sum') === 'last') {
            $v = qv('SELECT count FROM funnel_entries WHERE stage_id = ? AND kpi = ? AND date BETWEEN ? AND ? ORDER BY date DESC, id DESC LIMIT 1', [(int) $st['id'], $k, $from, $to]);
            return $v === false || $v === null ? null : (float) $v;
        }
        return (float) qv('SELECT COALESCE(SUM(count), 0) FROM funnel_entries WHERE stage_id = ? AND kpi = ? AND date BETWEEN ? AND ?', [(int) $st['id'], $k, $from, $to]);
    }
    ['chs' => $chs, 'sum' => $sum, 'count' => $count, 'convs' => $convs, 'cmts' => $cmts] = mk_stage_q($st, $from, $to);
    $div = fn ($a, $b) => $b > 0 ? $a / $b : null;
    $posts = fn (string $col) => $sum('posts', $col, 'platform', $chs);
    $ads = fn (string $col, bool $fl = false) => $sum('ad_results', $col, 'campaign_id', $links['campaigns'], $fl);
    $web = fn (string $col) => $sum('web_stats', $col, 'page_id', $links['pages']);
    $mail = fn (string $col) => $sum('emails', $col, 'id', $links['emails']);
    $leads = fn (string $extra = '') => $count('contacts', 'source', $chs, $extra);
    switch ($k) {
        case 'actions': return $count('posts', 'platform', $chs);
        case 'reach': case 'impressions': case 'engagement': case 'clicks': case 'conversions': return $posts($k);
        case 'post_leads': return $posts('leads');
        case 'cost': return $sum('posts', 'cost', 'platform', $chs, true);
        case 'engagement_rate': return $div($posts('engagement'), $posts('reach'));
        case 'ctr': return $div($posts('clicks'), $posts('impressions'));
        case 'followers':
            // Dernier relevé de chaque compte sur la période, additionnés.
            $last = [];
            if ($chs) foreach (q('SELECT platform, followers FROM social_stats WHERE platform IN (' . mk_in($chs) . ') AND date BETWEEN ? AND ? ORDER BY date, id', [...$chs, $from, $to]) as $r) $last[$r['platform']] = (int) $r['followers'];
            return $last ? (float) array_sum($last) : null;
        case 'new_followers': return $sum('social_stats', 'new_followers', 'platform', $chs);
        case 'social_reach': return $sum('social_stats', 'reach', 'platform', $chs);
        case 'social_engagement': return $sum('social_stats', 'engagement', 'platform', $chs);
        case 'leads': return $leads();
        case 'leads_qualified': return $leads(" AND status IN ('qualifie','converti')");
        case 'leads_converted': return $leads(" AND status = 'converti'");
        case 'qual_rate': return $div($leads(" AND status IN ('qualifie','converti')"), $leads());
        case 'conv_rate': return $div($leads(" AND status = 'converti'"), $leads());
        case 'inquiries': return $count('inquiries', 'source', $chs);
        case 'conversations': return $convs('');
        case 'conversations_hot': return $convs(" AND heat = 'chaud'");
        case 'prospects_messages': return $convs(' AND contact_id IS NOT NULL');
        case 'comments': return $cmts(' AND from_me = 0');
        case 'comments_questions': return $cmts(' AND from_me = 0 AND is_question = 1');
        case 'reply_rate': return $div($cmts(" AND from_me = 0 AND parent_ext = '' AND page_replied = 1"), $cmts(" AND from_me = 0 AND parent_ext = ''"));
        case 'ads_spend': return $ads('spend', true);
        case 'ads_impressions': case 'ads_clicks': case 'ads_leads': case 'ads_conversions': return $ads(substr($k, 4));
        case 'ads_ctr': return $div($ads('clicks'), $ads('impressions'));
        case 'ads_cpl': return $div($ads('spend', true), $ads('leads'));
        case 'page_visits': return $web('visits');
        case 'page_registrations': return $web('reg_investors + reg_owners');
        case 'page_forms': return $web('forms');
        case 'page_conv_rate': return $div($web('reg_investors + reg_owners'), $web('visits'));
        case 'email_sent': return $mail('sent');
        case 'email_opens': return $mail('opens');
        case 'email_clicks': return $mail('clicks');
        case 'open_rate': return $div($mail('opens'), $mail('sent'));
        case 'click_rate': return $div($mail('clicks'), $mail('sent'));
    }
    return null;
}

/** KPIs d'une étape avec leurs valeurs : [{key, label, fmt, value, target, target_pct, manual}]. */
function mk_stage_kpi_values(array $st, array $links, string $from, string $to): array
{
    $out = [];
    foreach (mk_stage_kpis_clean((string) ($st['kpis'] ?? '')) as $kd) {
        $manual = strpos($kd['k'], 'm_') === 0;
        $fmt = $manual ? $kd['f'] : MK_STAGE_KPIS[$kd['k']][2];
        $v = mk_stage_kpi($kd, $st, $links, $from, $to);
        $t = (float) $kd['t'];
        // Objectif d'un taux : saisi en %, comparé à la valeur en %.
        $cmp = $v === null ? null : ($fmt === 'pct' ? $v * 100 : $v);
        $out[] = ['key' => $kd['k'], 'label' => $manual ? $kd['l'] : MK_STAGE_KPIS[$kd['k']][0], 'fmt' => $fmt, 'value' => $v,
            'target' => $t > 0 ? $t : null, 'target_pct' => $t > 0 && $cmp !== null ? $cmp / $t : null, 'manual' => $manual, 'agg' => $manual ? $kd['a'] : ''];
    }
    return $out;
}

/** Chiffres saisis à la main pour une date (prospects d'une étape manuelle, ou valeur d'un KPI manuel). */
function mk_funnel_entries_save(int $fid, string $date, array $values, string $notes = ''): int
{
    if (!mk_valid_date($date)) throw new MkInputError('Choisissez une date valide.');
    $f = mk_funnel_full($fid);
    if (!$f) throw new MkInputError('Funnel introuvable.');
    $stages = [];
    foreach ($f['stages'] as $st) $stages[(int) $st['id']] = $st;
    $n = 0;
    $pdo = db();
    foreach ($values as $v) {
        $sid = (int) ($v['stage_id'] ?? 0);
        $kpi = (string) ($v['kpi'] ?? '');
        $raw = trim(str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], (string) ($v['value'] ?? '')));
        if ($raw === '' || !isset($stages[$sid])) continue;
        if (!is_numeric($raw) || (float) $raw < 0) throw new MkInputError("« {$stages[$sid]['name']} » : saisissez un nombre positif.");
        if ($kpi !== '' && !in_array($kpi, array_column(mk_stage_kpis_clean((string) $stages[$sid]['kpis']), 'k'), true)) continue;
        $val = (float) $raw == (int) $raw ? (int) $raw : round((float) $raw, 2);
        $ex = qv('SELECT id FROM funnel_entries WHERE stage_id = ? AND kpi = ? AND date = ?', [$sid, $kpi, $date]);
        if ($ex) {
            $pdo->prepare('UPDATE funnel_entries SET count = ?, notes = CASE WHEN ? != \'\' THEN ? ELSE notes END WHERE id = ?')->execute([$val, $notes, $notes, (int) $ex]);
        } else {
            $pdo->prepare('INSERT INTO funnel_entries (date, funnel_id, stage_id, count, notes, kpi) VALUES (?, ?, ?, ?, ?, ?)')->execute([$date, $fid, $sid, $val, $notes, $kpi]);
        }
        $n++;
    }
    if (!$n) throw new MkInputError('Saisissez au moins un chiffre.');
    return $n;
}

/**
 * Chiffres d'un funnel sur une période, sur un parcours à branches : prospects de chaque étape,
 * conversion depuis la ou les étapes précédentes (part de chaque branche), depuis l'entrée,
 * abandon, objectif ; étape où l'on perd le plus ; et chaque circuit complet (de l'entrée à une
 * fin) avec sa conversion, pour voir quel circuit marche le mieux.
 */
function mk_funnel_compute(array $f, string $from, string $to): array
{
    $stages = $f['stages'] ?? mk_funnel_stages((int) $f['id']);
    $links = $f['links'] ?? mk_funnel_links($f);
    $byId = [];
    foreach ($stages as $st) $byId[(int) $st['id']] = $st;
    $parentsOf = [];
    foreach ($stages as $st) {
        $parentsOf[(int) $st['id']] = array_values(array_filter(array_map('intval', explode(',', (string) ($st['parents'] ?? ''))), fn ($p) => isset($byId[$p]) && $p !== (int) $st['id']));
    }
    // Ordre de calcul : une étape après ses parents (le parcours ne boucle pas ; sécurité sinon).
    $order = [];
    $seen = [];
    $walk = function (int $id, int $depth = 0) use (&$walk, &$order, &$seen, $parentsOf): void {
        if (isset($seen[$id]) || $depth > 60) return;
        foreach ($parentsOf[$id] as $p) $walk($p, $depth + 1);
        $seen[$id] = true;
        $order[] = $id;
    };
    foreach ($stages as $st) $walk((int) $st['id']);
    $val = [];
    $level = [];
    foreach ($order as $id) {
        $st = $byId[$id];
        $ps = $parentsOf[$id];
        $val[$id] = $st['source'] === 'parents_sum' ? array_sum(array_map(fn ($p) => $val[$p] ?? 0, $ps)) : mk_stage_value($st, $f, $links, $from, $to);
        $level[$id] = $ps ? 1 + max(array_map(fn ($p) => $level[$p] ?? 0, $ps)) : 0;
    }
    // Entrées en amont de chaque étape (pour la conversion depuis l'entrée).
    $rootsOf = [];
    foreach ($order as $id) {
        $r = [];
        foreach ($parentsOf[$id] as $p) $r = array_merge($r, $rootsOf[$p]);
        $rootsOf[$id] = $parentsOf[$id] ? array_values(array_unique($r)) : [$id];
    }
    $children = [];
    foreach ($parentsOf as $id => $ps) foreach ($ps as $p) $children[$p][] = $id;
    $out = [];
    $idx = [];
    $worst = null;
    foreach ($order as $id) {
        $st = $byId[$id];
        $ps = $parentsOf[$id];
        $v = $val[$id];
        $pv = $ps ? array_sum(array_map(fn ($p) => $val[$p], $ps)) : null;
        $rv = array_sum(array_map(fn ($r) => $val[$r], $rootsOf[$id]));
        $convPrev = $pv !== null && $pv > 0 ? $v / $pv : null;
        $row = [
            'id' => $id, 'name' => $st['name'], 'kind' => !$ps ? 'entry' : (in_array($st['kind'] ?? '', ['split', 'goal'], true) ? $st['kind'] : 'step'), 'branch' => (string) ($st['branch'] ?? ''),
            'segment' => (string) ($st['segment'] ?? ''), 'parents' => $ps, 'children' => $children[$id] ?? [], 'level' => $level[$id],
            'tags' => array_values(array_filter(explode(',', (string) $st['tags']))), 'source' => $st['source'], 'target' => (int) $st['target'], 'value' => $v,
            'channels' => array_values(array_filter(explode(',', (string) ($st['channels'] ?? '')))),
            'supports' => array_values(array_filter(explode(',', (string) ($st['supports'] ?? '')))),
            'kpis' => mk_stage_kpi_values($st, $links, $from, $to),
            'parents_value' => $pv, 'from_name' => implode(' + ', array_map(fn ($p) => $byId[$p]['name'], $ps)),
            'conv_prev' => $convPrev,
            'conv_start' => $ps && $rv > 0 ? $v / $rv : null,
            'drop' => $convPrev === null ? null : max(0.0, 1 - $convPrev),
            'lost' => $pv === null ? null : max(0, $pv - $v),
            'target_pct' => (int) $st['target'] > 0 ? $v / (int) $st['target'] : null,
        ];
        $idx[$id] = count($out);
        // Une branche d'aiguillage ne « perd » pas ce qui part dans les autres branches : seule
        // une étape unique après ses parents compte pour la plus forte perte.
        $siblings = $ps ? count(array_unique(array_merge(...array_map(fn ($p) => $children[$p] ?? [], $ps)))) : 0;
        if ($row['drop'] !== null && $siblings <= 1 && ($worst === null || $row['drop'] > $out[$worst]['drop'])) $worst = count($out);
        $out[] = $row;
    }
    // Circuits complets : de chaque entrée jusqu'à chaque fin (50 au plus).
    $paths = [];
    $leafs = array_filter($order, fn ($id) => empty($children[$id]));
    $follow = function (int $id, array $path) use (&$follow, &$paths, $children): void {
        if (count($paths) >= 50 || count($path) > 40) return;
        $path[] = $id;
        if (empty($children[$id])) { $paths[] = $path; return; }
        foreach ($children[$id] as $c) $follow($c, $path);
    };
    foreach ($order as $id) if (!$parentsOf[$id]) $follow($id, []);
    // Conversion d'un circuit : produit des conversions de chaque passage (une ligne droite donne
    // exactement fin / début ; quand des circuits se rejoignent, c'est une estimation au prorata).
    $convOf = [];
    foreach ($out as $row) $convOf[$row['id']] = $row['conv_prev'];
    $pathRows = [];
    foreach ($paths as $pth) {
        $a = $val[$pth[0]];
        $conv = count($pth) > 1 && $a > 0 ? 1.0 : null;
        foreach (array_slice($pth, 1) as $sid) {
            if ($conv === null || $convOf[$sid] === null) { $conv = null; break; }
            $conv *= $convOf[$sid];
        }
        $pathRows[] = ['ids' => $pth, 'names' => array_map(fn ($i) => $byId[$i]['name'], $pth), 'start' => $a, 'end' => $val[end($pth)],
            'flow' => $conv === null ? null : (int) round($a * $conv), 'conv' => $conv];
    }
    $best = null;
    foreach ($pathRows as $i => $pr) if ($pr['conv'] !== null && ($best === null || $pr['conv'] > $pathRows[$best]['conv'])) $best = $i;
    $roots = array_filter($order, fn ($id) => !$parentsOf[$id]);
    $ends = array_values(array_filter($order, fn ($id) => (string) ($byId[$id]['kind'] ?? '') === 'goal')) ?: array_values($leafs);
    $rootSum = array_sum(array_map(fn ($id) => $val[$id], $roots));
    $endSum = array_sum(array_map(fn ($id) => $val[$id], $ends));
    return [
        'id' => (int) $f['id'], 'name' => $f['name'], 'objective' => $f['objective'], 'status' => $f['status'],
        'from' => $from, 'to' => $to, 'stages' => $out,
        'overall' => count($out) > 1 && $rootSum > 0 ? $endSum / $rootSum : null,
        'worst' => $worst, // index (dans stages) de l'étape où la perte est la plus forte
        'paths' => $pathRows, 'best_path' => $best, 'branched' => count($paths) > 1,
        'links' => [
            'campaigns' => count($links['campaigns']), 'pages' => count($links['pages']),
            'emails' => count($links['emails']), 'channels' => $links['channels'],
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
