<?php
/**
 * Socle de Studioma (dehini.com/studioma/) : configuration, base SQLite, outils de texte,
 * listes de référence (plateformes, compétences…) et envoi d'emails. Aucune dépendance externe.
 */
declare(strict_types=1);

define('STM_ROOT', dirname(__DIR__));

// Configuration locale facultative — jamais dans Git (voir config.example.php).
$cfg = STM_ROOT . '/config.php';
if (is_file($cfg)) require $cfg;
// Sans réglage SMTP propre, on réutilise le compte email du défi Impact s'il est configuré.
$impactCfg = dirname(STM_ROOT) . '/impact/config.php';
if (!defined('STM_SMTP_HOST') && is_file($impactCfg)) {
    try { require_once $impactCfg; } catch (Throwable $e) { /* config Impact illisible : on s'en passe */ }
}

$defaults = [
    'STM_NAME'           => 'Studioma',
    'STM_SITE_URL'       => '',          // ex. https://dehini.com/studioma/ — déduit de la requête si vide
    'STM_DATA_DIR'       => dirname(STM_ROOT, 2) . '/studioma-data', // base + fichiers, hors public_html
    'STM_TIMEZONE'       => 'Africa/Algiers',
    'STM_MAX_UPLOAD_MB'  => 64,          // vidéos et pièces jointes (les images sont limitées à 12 Mo)
    'STM_INVITE_DAYS'    => 14,          // durée de validité d'une invitation
    // « Continue with Google » : ID client OAuth. Vide : celui du défi Impact est repris (même origine).
    'STM_GOOGLE_CLIENT_ID'  => '',
    'STM_GOOGLE_FROM_IMPACT' => true,
    'STM_GOOGLE_CERTS'   => 'https://www.googleapis.com/oauth2/v1/certs', // clés publiques de Google
    'STM_MAIL_FROM'      => defined('IMPACT_MAIL_FROM') ? IMPACT_MAIL_FROM : '',
    'STM_MAIL_FROM_NAME' => 'Studioma',
    'STM_SMTP_HOST'      => defined('IMPACT_SMTP_HOST') ? IMPACT_SMTP_HOST : '',
    'STM_SMTP_PORT'      => defined('IMPACT_SMTP_PORT') ? IMPACT_SMTP_PORT : 465,
    'STM_SMTP_SECURE'    => defined('IMPACT_SMTP_SECURE') ? IMPACT_SMTP_SECURE : 'ssl',
    'STM_SMTP_USER'      => defined('IMPACT_SMTP_USER') ? IMPACT_SMTP_USER : '',
    'STM_SMTP_PASS'      => defined('IMPACT_SMTP_PASS') ? IMPACT_SMTP_PASS : '',
    'STM_SMTP_VERIFY'    => defined('IMPACT_SMTP_VERIFY') ? IMPACT_SMTP_VERIFY : true,
    'STM_MAIL_LOG'       => '',          // tests : les emails sont écrits dans ce fichier au lieu d'être envoyés
    // Campagnes (administration) : au plus ce nombre d'emails par heure, tous emails confondus. Les hébergeurs
    // mutualisés limitent souvent l'envoi (100 à 500 par heure) : rester en dessous de leur limite.
    'STM_MAIL_PER_HOUR'  => 200,
    // Meta Pixel + API Conversions (lib/meta.php). Le jeton n'est jamais dans le code : config.php
    // (STM_META_TOKEN) ou le fichier meta-token.txt dans le dossier des données.
    'STM_META_PIXEL_ID'  => '1718566156353795',
    'STM_META_TOKEN'     => '',
    'STM_META_TEST_CODE' => '',          // code de l'onglet « Événements de test » (à retirer ensuite)
    'STM_META_GRAPH'     => 'https://graph.facebook.com',
    'STM_META_LOG'       => '',          // tests : les événements sont écrits dans ce fichier au lieu d'être envoyés
];
foreach ($defaults as $k => $v) {
    if (!defined($k)) define($k, $v);
}
date_default_timezone_set(STM_TIMEZONE);

/* ------------------------------------------------------ listes de référence */

const STM_PLATFORMS = [
    'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn',
    'youtube' => 'YouTube', 'x' => 'X', 'snapchat' => 'Snapchat', 'pinterest' => 'Pinterest', 'threads' => 'Threads',
    'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'website' => 'Website / Blog', 'newsletter' => 'Newsletter',
];
const STM_CONTENT_TYPES = [
    'reel' => 'Reel', 'video' => 'Video', 'short' => 'Short', 'story' => 'Story', 'carousel' => 'Carousel',
    'image' => 'Image', 'text' => 'Text', 'live' => 'Live', 'article' => 'Article', 'podcast' => 'Podcast',
];
const STM_SKILLS = [
    'Community management', 'Content strategy', 'Copywriting', 'Scriptwriting', 'Video editing', 'Motion design',
    'Graphic design', 'Photography', 'Social ads', 'Influencer marketing', 'Analytics & reporting', 'SEO',
    'Email marketing', 'Brand strategy', 'Moderation & customer care', 'UGC creation', 'Live streaming',
    'Podcasting', 'Localization', 'Project management',
];
const STM_INDUSTRIES = [
    'Fashion & beauty', 'Food & beverage', 'Tech & SaaS', 'E-commerce & retail', 'Health & wellness', 'Education',
    'Finance & fintech', 'Real estate', 'Travel & hospitality', 'Entertainment & media', 'Sports & fitness',
    'Automotive', 'NGO & public sector', 'B2B services', 'Gaming', 'Other',
];
const STM_LANGUAGES = ['Arabic', 'French', 'English', 'Spanish', 'German', 'Italian', 'Turkish', 'Portuguese', 'Tamazight'];
const STM_CONTRACTS = [
    'freelance' => 'Freelance', 'project' => 'One-off project', 'full-time' => 'Full-time', 'part-time' => 'Part-time',
    'contract' => 'Fixed-term contract', 'internship' => 'Internship',
];
const STM_WORK_MODES = ['remote' => 'Remote', 'onsite' => 'On-site', 'hybrid' => 'Hybrid'];
const STM_AVAILABILITY = ['open' => 'Available for work', 'limited' => 'Limited availability', 'off' => 'Not available'];
const STM_COLORS = ['iris', 'coral', 'amber', 'green', 'teal', 'blue', 'pink', 'slate'];
const STM_PRIORITIES = [0 => 'None', 1 => 'Low', 2 => 'Medium', 3 => 'High', 4 => 'Urgent'];

/** URL publique du dossier /studioma/ (avec la barre finale). */
function site_url(): string
{
    if (STM_SITE_URL !== '') return rtrim(STM_SITE_URL, '/') . '/';
    if (PHP_SAPI === 'cli') return 'https://dehini.com/studioma/';
    $host  = $_SERVER['HTTP_HOST'] ?? 'dehini.com';
    // Les liens (partage, emails, OAuth) sont toujours en https sur un vrai domaine, même si la page a été
    // ouverte en http ; http seulement sur la machine locale (tests).
    $local = (bool) preg_match('/^(localhost|127\.\d+\.\d+\.\d+|\[::1\])(:\d+)?$/i', $host);
    $https = !$local || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/studioma/api.php')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}
/** Lien vers un écran de l'application (ex. app_url('studio/12')). */
function app_url(string $route = ''): string
{
    return site_url() . 'app/#/' . ltrim($route, '/');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = STM_DATA_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer le dossier des données : ' . $dir);
    }
    $pdo = new PDO('sqlite:' . $dir . '/studioma.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 8000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (Throwable $e) { /* base occupée : la prochaine connexion s'en chargera */ }
    migrate($pdo);
    return $pdo;
}

/*
 * Transactions d'écriture. Le verrou est pris dès le début (BEGIN IMMEDIATE) : deux déplacements
 * de cartes simultanés s'exécutent l'un après l'autre, jamais entremêlés.
 */
function tx_begin(): void
{
    db()->exec('BEGIN IMMEDIATE');
    $GLOBALS['stm_tx'] = true;
}
function tx_commit(): void
{
    db()->exec('COMMIT');
    $GLOBALS['stm_tx'] = false;
}
/** Annule la transaction en cours, s'il y en a une (sans effet sinon). */
function tx_rollback(): void
{
    if (empty($GLOBALS['stm_tx'])) return;
    $GLOBALS['stm_tx'] = false;
    try { db()->exec('ROLLBACK'); } catch (Throwable $e) { /* déjà annulée par SQLite */ }
}
/** Exécute $fn dans une transaction d'écriture (réutilise celle en cours s'il y en a une). */
function tx(callable $fn)
{
    if (!empty($GLOBALS['stm_tx'])) return $fn();
    tx_begin();
    try {
        $r = $fn();
        tx_commit();
        return $r;
    } catch (Throwable $e) {
        tx_rollback();
        throw $e;
    }
}

/** Raccourcis de requête. */
function q(string $sql, array $args = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($args);
    return $s;
}
function row(string $sql, array $args = []): ?array
{
    return q($sql, $args)->fetch() ?: null;
}
function rows(string $sql, array $args = []): array
{
    return q($sql, $args)->fetchAll();
}
function val(string $sql, array $args = [])
{
    $v = q($sql, $args)->fetchColumn();
    return $v === false ? null : $v;
}
function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    q('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($data));
    return (int) db()->lastInsertId();
}
/** « ?, ?, ? » pour une clause IN. */
function marks(array $a): string
{
    return implode(', ', array_fill(0, count($a), '?'));
}

/** Schéma compatible avec les vieilles versions de SQLite des hébergeurs mutualisés. */
function migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= 9) return;
    // Deux premières visites simultanées : la seconde attend la fin de la création, puis ne fait rien.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $v = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v < 1) {
            $pdo->exec(<<<SQL
        -- Un compte = une personne. kind : type choisi à l'inscription (individual | company).
        -- pro = 1 : visible dans l'annuaire des talents.
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            pass TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL,
            handle TEXT NOT NULL UNIQUE,
            kind TEXT NOT NULL DEFAULT 'individual',
            pro INTEGER NOT NULL DEFAULT 1,
            title TEXT NOT NULL DEFAULT '',
            bio TEXT NOT NULL DEFAULT '',
            specialization TEXT NOT NULL DEFAULT '',
            location TEXT NOT NULL DEFAULT '',
            skills TEXT NOT NULL DEFAULT '[]',
            platforms TEXT NOT NULL DEFAULT '[]',
            industries TEXT NOT NULL DEFAULT '[]',
            languages TEXT NOT NULL DEFAULT '[]',
            experience INTEGER NOT NULL DEFAULT 0,
            availability TEXT NOT NULL DEFAULT 'open',
            avatar TEXT NOT NULL DEFAULT '',
            cover TEXT NOT NULL DEFAULT '',
            website TEXT NOT NULL DEFAULT '',
            contact TEXT NOT NULL DEFAULT '',
            links TEXT NOT NULL DEFAULT '{}',
            settings TEXT NOT NULL DEFAULT '{}',
            search TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            last_seen INTEGER NOT NULL DEFAULT 0,
            rhash TEXT,
            rexp INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS sessions (
            hash TEXT PRIMARY KEY,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf TEXT NOT NULL,
            exp INTEGER NOT NULL
        );
        -- Page d'une entreprise (marque, agence, organisation) ; ses membres sont des comptes.
        CREATE TABLE IF NOT EXISTS companies (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            tagline TEXT NOT NULL DEFAULT '',
            about TEXT NOT NULL DEFAULT '',
            industry TEXT NOT NULL DEFAULT '',
            size TEXT NOT NULL DEFAULT '',
            location TEXT NOT NULL DEFAULT '',
            website TEXT NOT NULL DEFAULT '',
            logo TEXT NOT NULL DEFAULT '',
            cover TEXT NOT NULL DEFAULT '',
            search TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL
        );
        -- role : owner | admin | member.
        CREATE TABLE IF NOT EXISTS company_members (
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role TEXT NOT NULL DEFAULT 'member',
            title TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            PRIMARY KEY (company_id, uid)
        );
        CREATE INDEX IF NOT EXISTS company_members_u ON company_members(uid);

        -- ------------------------------------------------------------ studio
        -- Un projet = une marque, un client, une campagne. company_id > 0 : projet de l'entreprise.
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            company_id INTEGER NOT NULL DEFAULT 0,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT 'iris',
            logo TEXT NOT NULL DEFAULT '',
            archived INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        -- Membre de tout le projet (tous ses Studios). role : owner | manager | creator | collaborator | client | viewer.
        CREATE TABLE IF NOT EXISTS project_members (
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role TEXT NOT NULL,
            created INTEGER NOT NULL,
            PRIMARY KEY (project_id, uid)
        );
        CREATE INDEX IF NOT EXISTS project_members_u ON project_members(uid);
        -- rev augmente à chaque modification : les navigateurs ouverts sur le Studio se resynchronisent.
        CREATE TABLE IF NOT EXISTS studios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            rev INTEGER NOT NULL DEFAULT 1,
            archived INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS studios_p ON studios(project_id);
        -- Membre d'un seul Studio (sans accès au reste du projet).
        CREATE TABLE IF NOT EXISTS studio_members (
            studio_id INTEGER NOT NULL REFERENCES studios(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            role TEXT NOT NULL,
            created INTEGER NOT NULL,
            PRIMARY KEY (studio_id, uid)
        );
        CREATE INDEX IF NOT EXISTS studio_members_u ON studio_members(uid);
        -- Un niveau = une grande étape. handoff : auto | manual | off ; handoff_col = colonne finale
        -- (validation) ; handoff_level = niveau de destination (0 = le suivant).
        CREATE TABLE IF NOT EXISTS levels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            studio_id INTEGER NOT NULL REFERENCES studios(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT '',
            color TEXT NOT NULL DEFAULT 'iris',
            pos INTEGER NOT NULL DEFAULT 0,
            handoff TEXT NOT NULL DEFAULT 'auto',
            handoff_col INTEGER NOT NULL DEFAULT 0,
            handoff_level INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS levels_s ON levels(studio_id);
        -- principal = 1 : colonne d'arrivée du niveau. kind : '' | done | cancelled.
        -- passed : nombre de cartes passées au niveau suivant depuis cette colonne.
        CREATE TABLE IF NOT EXISTS cols (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            studio_id INTEGER NOT NULL REFERENCES studios(id) ON DELETE CASCADE,
            level_id INTEGER NOT NULL REFERENCES levels(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            pos INTEGER NOT NULL DEFAULT 0,
            principal INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL DEFAULT '',
            passed INTEGER NOT NULL DEFAULT 0,
            archived INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS cols_l ON cols(level_id);
        CREATE INDEX IF NOT EXISTS cols_s ON cols(studio_id);
        -- Une carte garde le même id d'un bout à l'autre du parcours : seul col_id change.
        CREATE TABLE IF NOT EXISTS cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            studio_id INTEGER NOT NULL REFERENCES studios(id) ON DELETE CASCADE,
            col_id INTEGER NOT NULL REFERENCES cols(id),
            pos INTEGER NOT NULL DEFAULT 0,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            assignee INTEGER NOT NULL DEFAULT 0,
            due TEXT NOT NULL DEFAULT '',
            priority INTEGER NOT NULL DEFAULT 0,
            tags TEXT NOT NULL DEFAULT '[]',
            platforms TEXT NOT NULL DEFAULT '[]',
            ctype TEXT NOT NULL DEFAULT '',
            publish_at TEXT NOT NULL DEFAULT '',
            publish_url TEXT NOT NULL DEFAULT '',
            metrics TEXT NOT NULL DEFAULT '{}',
            approval TEXT NOT NULL DEFAULT '',
            archived INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL,
            done_at INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS cards_s ON cards(studio_id);
        CREATE INDEX IF NOT EXISTS cards_c ON cards(col_id, pos);
        CREATE INDEX IF NOT EXISTS cards_a ON cards(assignee);
        CREATE TABLE IF NOT EXISTS card_members (
            card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            PRIMARY KEY (card_id, uid)
        );
        CREATE INDEX IF NOT EXISTS card_members_u ON card_members(uid);
        CREATE TABLE IF NOT EXISTS card_checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            text TEXT NOT NULL,
            done INTEGER NOT NULL DEFAULT 0,
            pos INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS card_checks_c ON card_checks(card_id);
        CREATE TABLE IF NOT EXISTS card_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            body TEXT NOT NULL,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS card_comments_c ON card_comments(card_id);
        CREATE TABLE IF NOT EXISTS card_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            file_id INTEGER NOT NULL,
            uid INTEGER NOT NULL,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS card_files_c ON card_files(card_id);
        -- status : pending | approved | changes | cancelled.
        CREATE TABLE IF NOT EXISTS card_approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            card_id INTEGER NOT NULL REFERENCES cards(id) ON DELETE CASCADE,
            level_id INTEGER NOT NULL DEFAULT 0,
            by_uid INTEGER NOT NULL,
            approver_uid INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            note TEXT NOT NULL DEFAULT '',
            reply TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            decided INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS card_approvals_c ON card_approvals(card_id);
        CREATE INDEX IF NOT EXISTS card_approvals_a ON card_approvals(approver_uid, status);
        -- Historique : card_id = 0 pour les événements du Studio lui-même (structure, membres).
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            studio_id INTEGER NOT NULL REFERENCES studios(id) ON DELETE CASCADE,
            card_id INTEGER NOT NULL DEFAULT 0,
            uid INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL,
            text TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS events_s ON events(studio_id, id);
        CREATE INDEX IF NOT EXISTS events_c ON events(card_id, id);
        -- scope : public (avatars, publications, portfolio) | studio (pièces jointes, membres du Studio
        -- seulement) | private (brouillon : son auteur seulement).
        CREATE TABLE IF NOT EXISTS files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            uid INTEGER NOT NULL,
            scope TEXT NOT NULL DEFAULT 'public',
            studio_id INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT '',
            mime TEXT NOT NULL,
            ext TEXT NOT NULL,
            size INTEGER NOT NULL DEFAULT 0,
            w INTEGER NOT NULL DEFAULT 0,
            h INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS files_u ON files(uid);
        -- Invitation par email (email), à un compte (uid) ou par lien (ni l'un ni l'autre, réutilisable).
        CREATE TABLE IF NOT EXISTS invites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            token TEXT NOT NULL UNIQUE,
            project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
            studio_id INTEGER NOT NULL DEFAULT 0,
            email TEXT NOT NULL DEFAULT '',
            uid INTEGER NOT NULL DEFAULT 0,
            role TEXT NOT NULL,
            by_uid INTEGER NOT NULL,
            message TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            uses INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            expires INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS invites_p ON invites(project_id);
        CREATE INDEX IF NOT EXISTS invites_e ON invites(email);
        CREATE INDEX IF NOT EXISTS invites_u ON invites(uid);
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            actor INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL,
            text TEXT NOT NULL,
            link TEXT NOT NULL DEFAULT '',
            seen INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS notifications_u ON notifications(uid, id);

        -- ------------------------------------------------------------ réseau
        -- ttype : user | company.
        CREATE TABLE IF NOT EXISTS follows (
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            ttype TEXT NOT NULL,
            tid INTEGER NOT NULL,
            created INTEGER NOT NULL,
            PRIMARY KEY (uid, ttype, tid)
        );
        CREATE INDEX IF NOT EXISTS follows_t ON follows(ttype, tid);
        -- a < b ; by = celui qui a envoyé la demande ; status : pending | accepted.
        CREATE TABLE IF NOT EXISTS connections (
            a INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            b INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT 'pending',
            by INTEGER NOT NULL,
            created INTEGER NOT NULL,
            PRIMARY KEY (a, b)
        );
        CREATE INDEX IF NOT EXISTS connections_b ON connections(b);
        -- company_id > 0 : publié au nom de l'entreprise ; share_id > 0 : partage d'une publication.
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            company_id INTEGER NOT NULL DEFAULT 0,
            body TEXT NOT NULL DEFAULT '',
            media TEXT NOT NULL DEFAULT '[]',
            share_id INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS posts_u ON posts(uid, id);
        CREATE INDEX IF NOT EXISTS posts_c ON posts(company_id, id);
        CREATE TABLE IF NOT EXISTS post_likes (
            post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            created INTEGER NOT NULL,
            PRIMARY KEY (post_id, uid)
        );
        CREATE TABLE IF NOT EXISTS post_saves (
            post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            created INTEGER NOT NULL,
            PRIMARY KEY (post_id, uid)
        );
        CREATE INDEX IF NOT EXISTS post_saves_u ON post_saves(uid);
        CREATE TABLE IF NOT EXISTS post_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            body TEXT NOT NULL,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS post_comments_p ON post_comments(post_id);
        -- Étude de cas du portfolio. source : manual | studio (issue de cartes publiées dans un Studio).
        -- status : draft | pending (autorisations attendues) | published | declined.
        -- need_owner / need_client : autorisations exigées ; owner_ok / client_ok : compte qui l'a donnée.
        CREATE TABLE IF NOT EXISTS portfolio (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            title TEXT NOT NULL,
            brand TEXT NOT NULL DEFAULT '',
            role TEXT NOT NULL DEFAULT '',
            period TEXT NOT NULL DEFAULT '',
            objectives TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            results TEXT NOT NULL DEFAULT '',
            metrics TEXT NOT NULL DEFAULT '[]',
            media TEXT NOT NULL DEFAULT '[]',
            links TEXT NOT NULL DEFAULT '[]',
            platforms TEXT NOT NULL DEFAULT '[]',
            source TEXT NOT NULL DEFAULT 'manual',
            project_id INTEGER NOT NULL DEFAULT 0,
            studio_id INTEGER NOT NULL DEFAULT 0,
            card_ids TEXT NOT NULL DEFAULT '[]',
            pieces INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'published',
            need_owner INTEGER NOT NULL DEFAULT 0,
            owner_ok INTEGER NOT NULL DEFAULT 0,
            need_client INTEGER NOT NULL DEFAULT 0,
            client_ok INTEGER NOT NULL DEFAULT 0,
            confirm_uid INTEGER NOT NULL DEFAULT 0,
            confirm_ask INTEGER NOT NULL DEFAULT 0,
            confirm_at INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS portfolio_u ON portfolio(uid);
        -- relation : client | manager | colleague | partner ; status : pending | visible | hidden.
        -- together = 1 : les deux comptes ont partagé un projet sur la plateforme.
        CREATE TABLE IF NOT EXISTS recommendations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            to_uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            relation TEXT NOT NULL DEFAULT 'colleague',
            body TEXT NOT NULL,
            together INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'pending',
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS recommendations_t ON recommendations(to_uid);

        -- ------------------------------------------------- opportunités, talents
        -- apply_kind : inapp | url | email ; status : open | closed.
        CREATE TABLE IF NOT EXISTS opps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL DEFAULT '',
            skills TEXT NOT NULL DEFAULT '[]',
            mode TEXT NOT NULL DEFAULT 'remote',
            location TEXT NOT NULL DEFAULT '',
            contract TEXT NOT NULL DEFAULT 'freelance',
            apply_kind TEXT NOT NULL DEFAULT 'inapp',
            apply_to TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'open',
            search TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS opps_c ON opps(company_id);
        -- status : new | shortlisted | declined | hired.
        CREATE TABLE IF NOT EXISTS opp_apps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            opp_id INTEGER NOT NULL REFERENCES opps(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            message TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'new',
            created INTEGER NOT NULL,
            UNIQUE (opp_id, uid)
        );
        -- Professionnels enregistrés : pour soi (company_id = 0) ou pour une entreprise.
        CREATE TABLE IF NOT EXISTS saved_pros (
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            company_id INTEGER NOT NULL DEFAULT 0,
            pro_uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            note TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            PRIMARY KEY (uid, company_id, pro_uid)
        );
        -- Conversation privée entre deux comptes (a < b) ; a_read / b_read : dernier message lu.
        CREATE TABLE IF NOT EXISTS threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            a INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            b INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            a_read INTEGER NOT NULL DEFAULT 0,
            b_read INTEGER NOT NULL DEFAULT 0,
            last_id INTEGER NOT NULL DEFAULT 0,
            last_at INTEGER NOT NULL DEFAULT 0,
            UNIQUE (a, b)
        );
        CREATE INDEX IF NOT EXISTS threads_b ON threads(b);
        CREATE TABLE IF NOT EXISTS msgs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL REFERENCES threads(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            body TEXT NOT NULL,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS msgs_t ON msgs(thread_id, id);

        CREATE TABLE IF NOT EXISTS rate (
            k TEXT PRIMARY KEY,
            n INTEGER NOT NULL,
            reset INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS meta (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        );
        PRAGMA user_version = 1;
        SQL);
        }
        if ($v < 2) {
            // Connexion avec Google : identité Google du compte, et adresse confirmée (par Google ou
            // par un lien reçu à cette adresse). Les comptes existants restent « non confirmés ».
            $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
            if (!in_array('google_sub', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN google_sub TEXT');
            if (!in_array('email_ok', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN email_ok INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_google ON users(google_sub)');
            $pdo->exec('PRAGMA user_version = 2');
        }
        if ($v < 3) {
            // Lien de confirmation de l'adresse (hash du jeton, expiration) : distinct de celui de « mot de
            // passe oublié », il confirme l'adresse sans jamais permettre de changer le mot de passe.
            $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
            if (!in_array('chash', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN chash TEXT');
            if (!in_array('cexp', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN cexp INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('PRAGMA user_version = 3');
        }
        if ($v < 4) {
            // Assistants IA (serveur MCP) : applications enregistrées (OAuth), autorisations en cours,
            // accès accordés à un compte (une application ou un jeton personnel) et leurs jetons.
            $pdo->exec(<<<SQL
        -- Application qui s'est enregistrée elle-même (RFC 7591). secret = hash du secret ('' = client
        -- public, protégé par PKCE seulement).
        CREATE TABLE IF NOT EXISTS oauth_clients (
            id TEXT PRIMARY KEY,
            secret TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL DEFAULT '',
            uri TEXT NOT NULL DEFAULT '',
            redirects TEXT NOT NULL DEFAULT '[]',
            created INTEGER NOT NULL,
            used INTEGER NOT NULL DEFAULT 0
        );
        -- Demande d'autorisation en attente (écran « Allow ») ; après l'accord, code = hash du code
        -- remis à l'application, à échanger contre des jetons dans les 10 minutes.
        CREATE TABLE IF NOT EXISTS oauth_requests (
            id TEXT PRIMARY KEY,
            client_id TEXT NOT NULL,
            redirect TEXT NOT NULL,
            challenge TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT '',
            scope TEXT NOT NULL DEFAULT '',
            resource TEXT NOT NULL DEFAULT '',
            uid INTEGER NOT NULL DEFAULT 0,
            code TEXT,
            expires INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS oauth_requests_code ON oauth_requests(code);
        -- Accès accordé : une application (client_id) ou un jeton personnel (client_id = '').
        CREATE TABLE IF NOT EXISTS ai_grants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            client_id TEXT NOT NULL DEFAULT '',
            name TEXT NOT NULL,
            created INTEGER NOT NULL,
            used INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS ai_grants_u ON ai_grants(uid);
        -- Jetons (seul leur hash est gardé). kind : access | refresh | personal ; expires = 0 : sans fin.
        CREATE TABLE IF NOT EXISTS ai_tokens (
            hash TEXT PRIMARY KEY,
            grant_id INTEGER NOT NULL REFERENCES ai_grants(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,
            hint TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            expires INTEGER NOT NULL DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS ai_tokens_g ON ai_tokens(grant_id);
        PRAGMA user_version = 4;
        SQL);
        }
        if ($v < 5) {
            // Partage d'un Studio avec des invités sans compte : liens (public, privé), invités vérifiés par
            // email, codes à usage unique, sessions d'invité, journal. Les actions d'un invité gardent son
            // identité (guest_id) dans l'historique, les commentaires, les validations et les notifications.
            foreach (['events' => 'guest_id', 'card_comments' => 'guest_id', 'card_approvals' => 'approver_guest', 'cards' => 'created_guest'] as $t => $c) {
                if (!in_array($c, array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(), 'name'), true)) $pdo->exec("ALTER TABLE $t ADD COLUMN $c INTEGER NOT NULL DEFAULT 0");
            }
            if (!in_array('guest', array_column($pdo->query('PRAGMA table_info(notifications)')->fetchAll(), 'name'), true)) $pdo->exec("ALTER TABLE notifications ADD COLUMN guest TEXT NOT NULL DEFAULT ''");
            $pdo->exec(<<<SQL
        -- Lien de partage d'un Studio : un public et un privé au plus. token : 48 caractères aléatoires
        -- (192 bits) ; le régénérer rend l'ancien lien inutilisable. levels : niveaux montrés ([] = tous).
        -- expires = 0 : sans fin.
        CREATE TABLE IF NOT EXISTS shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            studio_id INTEGER NOT NULL REFERENCES studios(id) ON DELETE CASCADE,
            kind TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            levels TEXT NOT NULL DEFAULT '[]',
            active INTEGER NOT NULL DEFAULT 1,
            expires INTEGER NOT NULL DEFAULT 0,
            created_by INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS shares_sk ON shares(studio_id, kind);
        -- Invité d'un lien, identifié par son adresse email vérifiée. status : invited (ajouté, pas encore
        -- vérifié) | active | revoked | removed. role : viewer | commenter | editor. scope : ce qu'un
        -- éditeur peut modifier {levels, cols, cards} (vide = tout ce que le lien montre). req_* : demande
        -- de droits en attente.
        CREATE TABLE IF NOT EXISTS share_guests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_id INTEGER NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
            studio_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'viewer',
            status TEXT NOT NULL DEFAULT 'invited',
            scope TEXT NOT NULL DEFAULT '{}',
            req_role TEXT NOT NULL DEFAULT '',
            req_note TEXT NOT NULL DEFAULT '',
            req_at INTEGER NOT NULL DEFAULT 0,
            verified INTEGER NOT NULL DEFAULT 0,
            last_seen INTEGER NOT NULL DEFAULT 0,
            mailed_at INTEGER NOT NULL DEFAULT 0,
            added_by INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS share_guests_se ON share_guests(share_id, email);
        CREATE INDEX IF NOT EXISTS share_guests_s ON share_guests(studio_id);
        -- Code à usage unique (6 chiffres) et lien magique envoyés par email : seuls leurs hash sont gardés.
        -- req_* : demande de droits à enregistrer une fois l'adresse vérifiée.
        CREATE TABLE IF NOT EXISTS share_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            share_id INTEGER NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
            email TEXT NOT NULL,
            code TEXT NOT NULL,
            link TEXT NOT NULL UNIQUE,
            req_role TEXT NOT NULL DEFAULT '',
            req_note TEXT NOT NULL DEFAULT '',
            tries INTEGER NOT NULL DEFAULT 0,
            used INTEGER NOT NULL DEFAULT 0,
            expires INTEGER NOT NULL,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS share_codes_se ON share_codes(share_id, email);
        -- Session d'un invité vérifié, limitée à un lien (hash du cookie).
        CREATE TABLE IF NOT EXISTS guest_sessions (
            hash TEXT PRIMARY KEY,
            share_id INTEGER NOT NULL REFERENCES shares(id) ON DELETE CASCADE,
            guest_id INTEGER NOT NULL REFERENCES share_guests(id) ON DELETE CASCADE,
            created INTEGER NOT NULL,
            expires INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS guest_sessions_g ON guest_sessions(guest_id);
        -- Journal du partage : accès (liens, invités, droits) et modifications faites par les invités.
        -- uid : membre auteur de l'action, ou 0 pour un invité (guest_id, email). mailed = 0 : modification
        -- d'un invité pas encore envoyée par email au responsable.
        CREATE TABLE IF NOT EXISTS share_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            studio_id INTEGER NOT NULL REFERENCES studios(id) ON DELETE CASCADE,
            share_id INTEGER NOT NULL DEFAULT 0,
            guest_id INTEGER NOT NULL DEFAULT 0,
            email TEXT NOT NULL DEFAULT '',
            uid INTEGER NOT NULL DEFAULT 0,
            card_id INTEGER NOT NULL DEFAULT 0,
            kind TEXT NOT NULL,
            text TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            mailed INTEGER NOT NULL DEFAULT 1
        );
        CREATE INDEX IF NOT EXISTS share_log_s ON share_log(studio_id, id);
        CREATE INDEX IF NOT EXISTS share_log_m ON share_log(mailed, guest_id);
        PRAGMA user_version = 5;
        SQL);
        }
        if ($v < 6) {
            // Administration (lib/admin.php) : compte suspendu (date, motif), jours d'activité de chaque compte
            // (statistiques d'utilisateurs actifs), journal de tous les emails envoyés, campagnes d'emails et leur
            // file d'envoi, journal des actions de l'administrateur.
            $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
            if (!in_array('suspended', $cols, true)) $pdo->exec('ALTER TABLE users ADD COLUMN suspended INTEGER NOT NULL DEFAULT 0');
            if (!in_array('suspend_note', $cols, true)) $pdo->exec("ALTER TABLE users ADD COLUMN suspend_note TEXT NOT NULL DEFAULT ''");
            $pdo->exec(<<<SQL
        -- Un jour (AAAA-MM-JJ, heure du serveur) où le compte a utilisé Studioma (application ou assistant IA).
        CREATE TABLE IF NOT EXISTS activity_days (
            day TEXT NOT NULL,
            uid INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            PRIMARY KEY (day, uid)
        );
        CREATE INDEX IF NOT EXISTS activity_days_u ON activity_days(uid);
        -- Chaque email envoyé (ou dont l'envoi a échoué). kind : system | campaign | admin | test.
        -- status : sent | failed. Les codes à 6 chiffres sont masqués dans l'objet.
        CREATE TABLE IF NOT EXISTS mail_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created INTEGER NOT NULL,
            email TEXT NOT NULL,
            subject TEXT NOT NULL DEFAULT '',
            kind TEXT NOT NULL DEFAULT 'system',
            campaign_id INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'sent',
            error TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS mail_log_c ON mail_log(created);
        CREATE INDEX IF NOT EXISTS mail_log_e ON mail_log(email);
        -- Campagne d'emails (annonce, nouveautés) écrite dans l'administration. audience : clé d'une liste fixe
        -- (ADMIN_AUDIENCES). status : draft | sending | paused | sent | cancelled.
        CREATE TABLE IF NOT EXISTS campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            body TEXT NOT NULL DEFAULT '',
            btn_label TEXT NOT NULL DEFAULT '',
            btn_url TEXT NOT NULL DEFAULT '',
            audience TEXT NOT NULL DEFAULT 'all',
            status TEXT NOT NULL DEFAULT 'draft',
            total INTEGER NOT NULL DEFAULT 0,
            sent INTEGER NOT NULL DEFAULT 0,
            failed INTEGER NOT NULL DEFAULT 0,
            skipped INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL,
            started INTEGER NOT NULL DEFAULT 0,
            finished INTEGER NOT NULL DEFAULT 0
        );
        -- Destinataires d'une campagne, envoyés par petits lots. status : pending | sending | sent | failed | skipped.
        -- claim : lot qui a pris la ligne (deux requêtes simultanées n'envoient jamais le même email).
        CREATE TABLE IF NOT EXISTS mail_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
            uid INTEGER NOT NULL,
            email TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            claim TEXT NOT NULL DEFAULT '',
            error TEXT NOT NULL DEFAULT '',
            updated INTEGER NOT NULL DEFAULT 0
        );
        CREATE UNIQUE INDEX IF NOT EXISTS mail_queue_cu ON mail_queue(campaign_id, uid);
        CREATE INDEX IF NOT EXISTS mail_queue_s ON mail_queue(status, id);
        -- Actions de l'administrateur (suspensions, suppressions, campagnes…).
        CREATE TABLE IF NOT EXISTS admin_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created INTEGER NOT NULL,
            action TEXT NOT NULL,
            target TEXT NOT NULL DEFAULT '',
            text TEXT NOT NULL DEFAULT ''
        );
        CREATE INDEX IF NOT EXISTS admin_log_c ON admin_log(created);
        PRAGMA user_version = 6;
        SQL);
        }
        if ($v < 7) {
            // Acceptation des conditions d'utilisation (date ; 0 = pas encore). Inscription par email : acceptées en
            // créant le compte. Compte créé avec Google : fenêtre à l'arrivée dans l'application. Les comptes existants
            // sans Google sont passés par le formulaire d'inscription, qui les exigeait déjà ; ceux reliés à Google
            // (créés parfois depuis la page de connexion, sans case à cocher) les acceptent une fois à leur prochaine visite.
            if (!in_array('terms_at', array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name'), true)) {
                $pdo->exec('ALTER TABLE users ADD COLUMN terms_at INTEGER NOT NULL DEFAULT 0');
                $pdo->exec("UPDATE users SET terms_at = created WHERE google_sub IS NULL OR google_sub = ''");
            }
            $pdo->exec('PRAGMA user_version = 7');
        }
        if ($v < 8) {
            // Visiteurs en temps réel (administration) : un visiteur = un compte connecté (« u<id> ») ou, sans compte,
            // une empreinte anonyme du jour (hash salé de l'IP et du navigateur, sans cookie, change chaque jour).
            // page : type d'écran seulement (landing, studio, u…), jamais une adresse complète ni une clé.
            $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS visitors (
            vid TEXT PRIMARY KEY,
            uid INTEGER NOT NULL DEFAULT 0,
            page TEXT NOT NULL DEFAULT '',
            device TEXT NOT NULL DEFAULT '',
            first_seen INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            hits INTEGER NOT NULL DEFAULT 1
        );
        CREATE INDEX IF NOT EXISTS visitors_l ON visitors(last_seen);
        CREATE TABLE IF NOT EXISTS visit_days (
            day TEXT NOT NULL,
            vid TEXT NOT NULL,
            uid INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (day, vid)
        );
        PRAGMA user_version = 8;
        SQL);
        }
        if ($v < 9) {
            // Abonnements (lib/billing.php, lib/paddle.php). Aucune ligne n'est créée pour les comptes existants :
            // sans abonnement valable, un compte est sur la formule Free (pas de doublon possible, aucune donnée touchée).
            $pdo->exec(<<<SQL
        -- Un abonnement d'un compte. provider : paddle | manual (offert par l'administration). env : sandbox |
        -- production (Paddle) ou manual. status : trialing | active | past_due | paused | canceled | expired.
        -- amount : prix de l'abonnement en centimes (donnée vérifiée de Paddle) ; interval : month | year.
        -- provider_updated : date de mise à jour chez Paddle (les événements arrivés dans le désordre sont ignorés).
        CREATE TABLE IF NOT EXISTS subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL,
            provider TEXT NOT NULL,
            env TEXT NOT NULL DEFAULT '',
            provider_sub_id TEXT NOT NULL DEFAULT '',
            provider_customer_id TEXT NOT NULL DEFAULT '',
            plan TEXT NOT NULL,
            interval TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL,
            amount INTEGER NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT '',
            period_start INTEGER NOT NULL DEFAULT 0,
            period_end INTEGER NOT NULL DEFAULT 0,
            cancel_at INTEGER NOT NULL DEFAULT 0,
            canceled_at INTEGER NOT NULL DEFAULT 0,
            past_due_since INTEGER NOT NULL DEFAULT 0,
            provider_updated TEXT NOT NULL DEFAULT '',
            note TEXT NOT NULL DEFAULT '',
            created INTEGER NOT NULL,
            updated INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS subscriptions_u ON subscriptions(uid);
        CREATE UNIQUE INDEX IF NOT EXISTS subscriptions_p ON subscriptions(provider, provider_sub_id) WHERE provider_sub_id <> '';
        -- Client Paddle d'un compte (un par environnement).
        CREATE TABLE IF NOT EXISTS billing_customers (
            uid INTEGER NOT NULL,
            env TEXT NOT NULL,
            customer_id TEXT NOT NULL,
            created INTEGER NOT NULL,
            PRIMARY KEY (uid, env)
        );
        CREATE UNIQUE INDEX IF NOT EXISTS billing_customers_c ON billing_customers(env, customer_id);
        -- Notifications reçues de Paddle (webhooks) : une seule fois chacune (event_id), avec le résultat.
        CREATE TABLE IF NOT EXISTS billing_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            event_id TEXT NOT NULL,
            type TEXT NOT NULL,
            occurred TEXT NOT NULL DEFAULT '',
            uid INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL,
            error TEXT NOT NULL DEFAULT '',
            payload TEXT NOT NULL DEFAULT '',
            received INTEGER NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS billing_events_e ON billing_events(provider, event_id);
        CREATE INDEX IF NOT EXISTS billing_events_r ON billing_events(received);
        -- Paiements (transactions Paddle terminées ou en échec) : montants vérifiés, jamais de données de carte.
        CREATE TABLE IF NOT EXISTS billing_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid INTEGER NOT NULL,
            env TEXT NOT NULL DEFAULT '',
            provider_txn_id TEXT NOT NULL UNIQUE,
            provider_sub_id TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL,
            amount INTEGER NOT NULL DEFAULT 0,
            currency TEXT NOT NULL DEFAULT '',
            origin TEXT NOT NULL DEFAULT '',
            billed_at INTEGER NOT NULL DEFAULT 0,
            created INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS billing_transactions_u ON billing_transactions(uid);
        -- Compteurs d'usage par compte et par mois civil (« AAAA-MM ») : n'augmentent jamais à rebours.
        CREATE TABLE IF NOT EXISTS usage_counters (
            uid INTEGER NOT NULL,
            period TEXT NOT NULL,
            metric TEXT NOT NULL,
            n INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (uid, period, metric)
        );
        PRAGMA user_version = 9;
        SQL);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) { /* rien à annuler */ }
        throw $e;
    }
}

function meta_get(string $k): ?string
{
    $v = val('SELECT v FROM meta WHERE k = ?', [$k]);
    return $v === null ? null : (string) $v;
}
function meta_set(string $k, string $v): void
{
    q('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)', [$k, $v]);
}
function token(int $bytes = 24): string
{
    return bin2hex(random_bytes($bytes));
}
function install_salt(): string
{
    $s = meta_get('salt');
    if ($s === null) {
        $s = bin2hex(random_bytes(16));
        meta_set('salt', $s);
    }
    return $s;
}

/* ------------------------------------------------------------------ texte */

function str_clean($s, int $max): string
{
    if (!is_string($s) && !is_int($s) && !is_float($s)) return '';
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s) ?? '');
    $s = preg_replace("/\r\n?/", "\n", $s) ?? '';
    return mb_substr($s, 0, $max);
}
/** Une seule ligne (noms, titres, villes…). */
function line_clean($s, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', str_clean($s, $max * 2)) ?? ''), 0, $max);
}
/** Minuscules sans accents : sert à la recherche et aux comparaisons. */
function norm(string $s): string
{
    static $map = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
        '’' => "'", '‘' => "'",
    ];
    $s = strtr(mb_strtolower($s, 'UTF-8'), $map);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}
function slugify(string $s): string
{
    $s = preg_replace('/[^a-z0-9]+/', '-', norm($s)) ?? '';
    return substr(trim($s, '-'), 0, 30);
}
/** Motif LIKE pour une recherche « contient » (les jokers saisis sont neutralisés). */
function like(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], norm($s)) . '%';
}
/** Identifiant unique dans une colonne (suffixe -2, -3… s'il est déjà pris). */
function unique_slug(string $table, string $col, string $name, int $exceptId = 0): string
{
    if (!in_array($table . '.' . $col, ['users.handle', 'companies.slug'], true)) throw new InvalidArgumentException('table');
    $base = slugify($name);
    if (strlen($base) < 3 || ctype_digit($base)) $base = ($table === 'users' ? 'member-' : 'team-') . substr(token(4), 0, 6);
    $slug = $base;
    for ($i = 2; $i < 500; $i++) {
        if (!val("SELECT id FROM $table WHERE $col = ? AND id <> ?", [$slug, $exceptId])) return $slug;
        $slug = substr($base, 0, 26) . '-' . $i;
    }
    return substr($base, 0, 22) . '-' . substr(token(4), 0, 6);
}
function valid_email(string $e): bool
{
    return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false;
}
/** Adresse web saisie : http(s) uniquement (« exemple.com » devient « https://exemple.com »), ou ''. */
function url_clean($u, int $max = 300): string
{
    $u = line_clean($u, $max);
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $u)) return '';
        $u = 'https://' . $u;
    }
    return filter_var($u, FILTER_VALIDATE_URL) !== false && strpos($u, '"') === false && strpos($u, '<') === false ? $u : '';
}
/** Décode une colonne JSON (liste ou objet) ; tableau vide si elle est illisible. */
function jarr($s): array
{
    $d = is_string($s) && $s !== '' ? json_decode($s, true) : null;
    return is_array($d) ? $d : [];
}
function jenc($v): string
{
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
/** Liste de courts libellés saisis (compétences, mots-clés…) : nettoyés, sans doublon. */
function list_clean($v, int $maxItems, int $maxLen = 40): array
{
    if (is_string($v)) $v = explode(',', $v);
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $item) {
        $item = line_clean($item, $maxLen);
        if ($item === '') continue;
        $out[norm($item)] = $item;
        if (count($out) >= $maxItems) break;
    }
    return array_values($out);
}
/** Liste de clés prises dans une liste de référence. */
function keys_clean($v, array $allowed, int $maxItems = 20): array
{
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $k) {
        if (is_string($k) && isset($allowed[$k]) && !in_array($k, $out, true)) $out[] = $k;
    }
    return array_slice($out, 0, $maxItems);
}
function date_clean($d): string
{
    $d = is_string($d) ? trim($d) : '';
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return '';
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : '';
}
/** Date et heure locales « AAAA-MM-JJTHH:MM » (ou une date seule), ou ''. */
function datetime_clean($d): string
{
    $d = is_string($d) ? trim($d) : '';
    if (date_clean($d) !== '') return $d;
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):([0-5]\d)$/', $d, $m)) return '';
    return date_clean($m[1]) !== '' ? $d : '';
}

/**
 * Texte mis en forme (scripts, notes) : seules quelques balises sont gardées, reconstruites
 * sans aucun attribut (sauf le lien d'un <a>, limité à http(s) et mailto).
 */
function rich_clean($html, int $max = 60000): string
{
    if (!is_string($html)) return '';
    $html = str_clean($html, $max);
    $allowed = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'h2', 'h3', 'ul', 'ol', 'li', 'blockquote', 'a', 'code', 'pre', 'hr', 'div'];
    // Le contenu des balises de script et de style part avec elles.
    $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $html) ?? '';
    $html = strip_tags($html, $allowed);
    $html = preg_replace_callback('~<(/?)([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>~', function (array $m) use ($allowed): string {
        $tag = strtolower($m[2]);
        if (!in_array($tag, $allowed, true)) return '';
        if ($tag === 'div') $tag = 'p';
        if ($m[1] === '/') return in_array($tag, ['br', 'hr'], true) ? '' : '</' . $tag . '>';
        if ($tag === 'a') {
            $href = '';
            if (preg_match('~href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $m[3], $h)) {
                $href = html_entity_decode($h[1] !== '' ? $h[1] : (($h[2] ?? '') !== '' ? $h[2] : ($h[3] ?? '')), ENT_QUOTES, 'UTF-8');
            }
            $href = preg_match('~^mailto:[^\s"<>]+$~i', $href) ? $href : url_clean($href, 500);
            return $href === '' ? '<a>' : '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
        }
        return '<' . $tag . '>';
    }, $html) ?? '';
    // Un « < » isolé (balise jamais fermée) ne doit pas rester interprétable.
    $html = preg_replace('~<(?!/?(?:' . implode('|', $allowed) . ')\b)~i', '&lt;', $html) ?? '';
    return trim($html);
}
/** Version texte d'un contenu mis en forme (aperçus, recherche). */
function rich_text(string $html, int $max = 300): string
{
    $t = html_entity_decode(strip_tags(preg_replace('~<(br|/p|/li|/h\d|/blockquote)[^>]*>~i', ' ', $html) ?? ''), ENT_QUOTES, 'UTF-8');
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $t) ?? ''), 0, $max);
}

/* ------------------------------------------------------------------- email */

function mail_from(): string
{
    if (STM_MAIL_FROM !== '') return STM_MAIL_FROM;
    if (STM_SMTP_USER !== '' && strpos(STM_SMTP_USER, '@') !== false) return STM_SMTP_USER;
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'dehini.com';
    return 'studioma@' . preg_replace('/^www\./', '', (string) $host);
}
function encode_header(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}
/** Gabarit commun des emails : un titre, des paragraphes, un bouton facultatif, puis des liens ([texte, adresse]). */
function mail_layout(string $title, array $lines, string $btnLabel = '', string $btnUrl = '', array $links = []): array
{
    $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1b1a22">'
        . '<p style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#5b4bff;font-weight:bold;margin:0 0 8px">' . $e(STM_NAME) . '</p>'
        . '<h1 style="font-size:20px;margin:0 0 16px">' . $e($title) . '</h1>';
    foreach ($lines as $l) $html .= '<p style="font-size:15px;line-height:1.55;margin:0 0 12px">' . nl2br($e($l)) . '</p>';
    if ($btnUrl !== '') {
        $html .= '<p style="margin:20px 0"><a href="' . $e($btnUrl) . '" style="background:#5b4bff;color:#fff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;display:inline-block">' . $e($btnLabel) . '</a></p>';
    }
    if ($links) {
        $html .= '<ul style="padding-left:18px;margin:8px 0 0">';
        foreach ($links as $l) $html .= '<li style="font-size:14px;line-height:1.5;margin:0 0 8px"><a href="' . $e($l[1]) . '" style="color:#5b4bff">' . $e($l[0]) . '</a></li>';
        $html .= '</ul>';
    }
    $html .= '</div>';
    $text = $title . "\n\n" . implode("\n\n", $lines) . ($btnUrl !== '' ? "\n\n" . $btnLabel . ': ' . $btnUrl : '')
        . implode('', array_map(function (array $l): string { return "\n\n- " . $l[0] . "\n  " . $l[1]; }, $links)) . "\n";
    return [$html, $text];
}
/**
 * Email envoyé après la réponse (quand le serveur le permet) : une panne ou une lenteur d'email
 * ne retarde ni ne bloque jamais une action.
 */
function mail_later(string $to, string $subject, string $title, array $lines, string $btnLabel = '', string $btnUrl = '', array $links = []): void
{
    if (!valid_email($to)) return;
    static $queue = null;
    if ($queue === null) {
        $queue = new ArrayObject();
        register_shutdown_function(function () use ($queue): void {
            if (!count($queue)) return;
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            foreach ($queue as $m) {
                [$html, $text] = mail_layout($m[2], $m[3], $m[4], $m[5], $m[6]);
                mail_send_logged($m[0], $m[1], $html, $text, $m[7]);
            }
        });
    }
    $queue->append([$to, $subject, $title, $lines, $btnLabel, $btnUrl, $links, $GLOBALS['stm_mail_kind'] ?? 'system']);
}

/**
 * Envoie un email et l'inscrit au journal (mail_log, visible dans l'administration). Retourne '' si l'envoi a
 * réussi, sinon le message d'erreur (jamais d'exception : un email en échec ne casse rien).
 */
function mail_send_logged(string $to, string $subject, string $html, string $text, string $kind = 'system', int $campaignId = 0, array $headers = []): string
{
    $error = '';
    try {
        send_mail($to, $subject, $html, $text, $headers);
    } catch (Throwable $e) {
        $error = mb_substr($e->getMessage(), 0, 300);
        error_log('studioma mail: ' . $error);
    }
    try {
        // Les codes de vérification (6 chiffres) ne sont jamais gardés.
        insert('mail_log', ['created' => time(), 'email' => $to, 'subject' => mb_substr((string) preg_replace('/\b\d{6}\b/', '••••••', $subject), 0, 200),
            'kind' => $kind, 'campaign_id' => $campaignId, 'status' => $error === '' ? 'sent' : 'failed', 'error' => $error]);
        if ($error !== '') meta_set('mail_last_error', jenc(['at' => time(), 'error' => $error]));
        if (random_int(1, 200) === 1) q('DELETE FROM mail_log WHERE created < ?', [time() - 180 * 86400]);
    } catch (Throwable $e) {
        error_log('studioma mail log: ' . $e->getMessage());
    }
    return $error;
}

/**
 * Envoie un email texte + HTML (SMTP si configuré, sinon mail()). Lève une exception en cas d'échec.
 * $extra : en-têtes supplémentaires (ex. List-Unsubscribe des campagnes).
 */
function send_mail(string $to, string $subject, string $html, string $text, array $extra = []): void
{
    $extra = array_values(array_filter(array_map(function ($h): string { return str_replace(["\r", "\n"], '', (string) $h); }, $extra)));
    if (STM_MAIL_LOG !== '') {
        $log = ['to' => $to, 'subject' => $subject, 'text' => $text];
        if ($extra) $log['headers'] = $extra;
        file_put_contents(STM_MAIL_LOG, json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    $from = mail_from();
    $domain = substr(strrchr($from, '@') ?: '@dehini.com', 1);
    $b = 'b_' . token(12);
    $headers = implode("\r\n", array_merge([
        'From: ' . encode_header(STM_MAIL_FROM_NAME) . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'Date: ' . date('r'),
        'Message-ID: <' . token(16) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $b . '"',
    ], $extra));
    $body = '--' . $b . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text)) . "\r\n--" . $b . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html)) . "\r\n--" . $b . "--\r\n";
    if (STM_SMTP_HOST === '') {
        if (!@mail($to, encode_header($subject), $body, $headers, '-f' . $from)) throw new RuntimeException('mail() a échoué');
        return;
    }
    $ctx = stream_context_create(['ssl' => [
        'verify_peer' => (bool) STM_SMTP_VERIFY, 'verify_peer_name' => (bool) STM_SMTP_VERIFY,
        'allow_self_signed' => !STM_SMTP_VERIFY, 'peer_name' => STM_SMTP_HOST,
    ]]);
    $secure = STM_SMTP_SECURE;
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . STM_SMTP_HOST . ':' . (int) STM_SMTP_PORT, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
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
        $helo = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
        $cmd(null, [220]);
        $cmd('EHLO ' . $helo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP : STARTTLS a échoué');
            $cmd('EHLO ' . $helo, [250]);
        }
        if (STM_SMTP_USER !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(STM_SMTP_USER), [334]);
            $cmd(base64_encode(STM_SMTP_PASS), [235]);
        }
        $cmd('MAIL FROM:<' . $from . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $data = 'To: <' . $to . ">\r\nSubject: " . encode_header($subject) . "\r\n" . $headers . "\r\n\r\n" . $body;
        fwrite($fp, preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data)) . "\r\n.\r\n");
        $cmd(null, [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        @fclose($fp);
    }
}
