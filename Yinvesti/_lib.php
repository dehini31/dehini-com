<?php
/**
 * Outils communs de la préinscription Yinvesti × ASC 2026 (candidature.php,
 * candidatures.php). Accès direct refusé par .htaccess.
 *
 * Les candidatures sont rangées HORS du dossier web : ~/yinvesti-candidatures/
 * (au-dessus de public_html), sauf si config.php définit YINV_DATA_DIR.
 */
declare(strict_types=1);

date_default_timezone_set('Africa/Algiers');

if (is_file(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
}

/** Dossier de stockage des candidatures (créé et verrouillé au premier appel). */
function yinv_dossier(string $sous = ''): string
{
    $base = defined('YINV_DATA_DIR') ? rtrim((string) YINV_DATA_DIR, '/') : dirname(__DIR__, 2) . '/yinvesti-candidatures';
    $dir  = $sous === '' ? $base : $base . '/' . $sous;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer {$dir}");
    }
    // Si le dossier se retrouve un jour dans le web, rien n'y est servi.
    if (!is_file($base . '/.htaccess')) {
        file_put_contents($base . '/.htaccess', "Require all denied\n");
    }

    return $dir;
}

function yinv_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function yinv_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Limite simple par IP : $max envois par fenêtre de $secondes. */
function yinv_limite(int $max, int $secondes): bool
{
    $f = yinv_dossier('limites') . '/' . hash('sha256', yinv_ip()) . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn ($t) => $now - (int) $t < $secondes));
    if (count($hits) >= $max) {
        return false;
    }
    $hits[] = $now;
    file_put_contents($f, json_encode($hits), LOCK_EX);

    return true;
}

/** Prochain numéro de dossier : YINV-ASC26-0001, 0002… (compteur verrouillé). */
function yinv_numero(): string
{
    $f = yinv_dossier() . '/compteur.txt';
    $h = fopen($f, 'c+');
    flock($h, LOCK_EX);
    $n = (int) stream_get_contents($h) + 1;
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, (string) $n);
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);

    return sprintf('YINV-ASC26-%04d', $n);
}

/** Les 58 wilayas + diaspora (mêmes valeurs que la page). */
function yinv_wilayas(): array
{
    $w = ['Adrar', 'Chlef', 'Laghouat', 'Oum El Bouaghi', 'Batna', 'Béjaïa', 'Biskra', 'Béchar', 'Blida', 'Bouira',
        'Tamanrasset', 'Tébessa', 'Tlemcen', 'Tiaret', 'Tizi Ouzou', 'Alger', 'Djelfa', 'Jijel', 'Sétif', 'Saïda',
        'Skikda', 'Sidi Bel Abbès', 'Annaba', 'Guelma', 'Constantine', 'Médéa', 'Mostaganem', "M'Sila", 'Mascara', 'Ouargla',
        'Oran', 'El Bayadh', 'Illizi', 'Bordj Bou Arreridj', 'Boumerdès', 'El Tarf', 'Tindouf', 'Tissemsilt', 'El Oued', 'Khenchela',
        'Souk Ahras', 'Tipaza', 'Mila', 'Aïn Defla', 'Naâma', 'Aïn Témouchent', 'Ghardaïa', 'Relizane', 'Timimoun', 'Bordj Badji Mokhtar',
        'Ouled Djellal', 'Béni Abbès', 'In Salah', 'In Guezzam', 'Touggourt', 'Djanet', "El M'Ghair", 'El Meniaa'];
    $out = [];
    foreach ($w as $i => $nom) {
        $out[] = sprintf('%02d - %s', $i + 1, $nom);
    }
    $out[] = 'Diaspora / International';

    return $out;
}

/**
 * Réponses à choix : clé envoyée par la page => libellé enregistré (français).
 * Toute valeur hors de ces listes est refusée.
 */
function yinv_choix(): array
{
    return [
        // Champs retirés de la page : gardés pour afficher les candidatures déjà reçues.
        'asc_statut' => [
            'exposant'    => "Exposant à l'ASC 2026",
            'visiteur'    => "Inscrit à l'ASC 2026 (visiteur / participant)",
            'non_inscrit' => "Pas encore inscrit à l'ASC 2026",
        ],
        'asc_rdv' => [
            '5dec'  => 'Samedi 5 décembre',
            '6dec'  => 'Dimanche 6 décembre',
            '7dec'  => 'Lundi 7 décembre',
            'apres' => "Après l'événement (siège ou téléphone)",
        ],
        'stade_projet' => [
            'creation'   => 'Projet en cours de création',
            'entreprise' => 'Entreprise / société / registre du commerce',
        ],
        'secteur' => [
            'deeptech'  => 'Deeptech / IA / Data',
            'fintech'   => 'Fintech / Assurtech',
            'agritech'  => 'Agritech / Agroalimentaire',
            'sante'     => 'Santé / Biotech',
            'energie'   => 'Énergie / Environnement',
            'industrie' => 'Industrie / BTP',
            'commerce'  => 'E-commerce / Retail',
            'education' => 'Éducation / Edtech',
            'tourisme'  => 'Tourisme / Services',
            'autre'     => 'Autre',
        ],
        // Entreprises existantes
        'cat1_exercice' => [
            'personnel' => 'À titre personnel (individu)',
            'societe'   => 'À travers une société enregistrée (SARL, EURL, SPA)',
        ],
        'cat1_besoin' => [
            'materiel'   => 'Acheter ou rénover du matériel / équipements',
            'produit'    => 'Lancer un nouveau produit, une nouvelle marque ou une filiale',
            'commercial' => 'Développer la fonction commerciale ou marketing',
            'autre'      => 'Autre',
        ],
        'cat1_montant' => [
            'moins5' => 'Moins de 5 millions DZD',
            '5a20'   => 'Entre 5 et 20 millions DZD',
            'plus20' => 'Plus de 20 millions DZD',
        ],
        'cat1_transformation_spa' => [
            'oui' => 'Oui', 'non' => 'Non', 'peutetre' => 'Peut-être', 'infos' => "Besoin d'informations",
        ],
        'cat1_plan_clients' => ['oui' => 'Oui', 'non' => 'Non'],
        // Projets en création
        'cat2_prototype' => ['oui' => "Oui (j'ai un prototype / MVP)", 'non' => 'Non'],
        'cat2_avancement_clients' => [
            'clients'  => "J'ai commencé les opérations et j'ai déjà des clients",
            'dev'      => "J'ai commencé le développement mais je n'ai pas encore de clients",
            'amorcage' => "Je n'ai pas encore commencé la phase opérationnelle",
        ],
        'cat2_marketing_audience' => [
            'active'  => "Stratégie définie, actions marketing lancées et audience",
            'definie' => "Stratégie définie, actions marketing pas encore lancées",
            'aucune'  => 'Pas de stratégie marketing pour le moment',
        ],
        'cat2_statut_spa' => [
            'oui' => 'Oui', 'non' => 'Non', 'peutetre' => 'Peut-être', 'infos' => "Besoin d'informations",
        ],
    ];
}

/** Colonnes de l'export CSV, dans l'ordre : clé => en-tête. */
function yinv_colonnes(): array
{
    return [
        'dossier' => 'Dossier', 'recu_le' => 'Reçu le', 'langue' => 'Langue',
        'nom_complet' => 'Nom complet', 'telephone' => 'Téléphone', 'email' => 'E-mail', 'wilaya' => 'Wilaya',
        'asc_statut' => 'Statut ASC', 'asc_rdv' => 'Rendez-vous souhaité',
        'stade_projet' => 'Stade', 'nom_projet' => 'Projet / entreprise', 'secteur' => 'Secteur',
        'cat1_exercice' => 'Exercice', 'cat1_besoin' => 'Besoin', 'cat1_montant' => 'Montant recherché',
        'cat1_transformation_spa' => 'Transformation en SPA', 'cat1_plan_clients' => 'Plan clients',
        'chiffre_affaires_societe' => "Chiffre d'affaires",
        'cat2_prototype' => 'Prototype / MVP', 'cat2_avancement_clients' => 'Avancement', 'cat2_marketing_audience' => 'Marketing',
        'cat2_statut_spa' => 'Accord forme SPA/SPAS', 'traction_prospects_ca' => 'Traction',
        'description_projet' => 'Pitch', 'site_web' => 'Site web', 'facebook' => 'Facebook', 'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'fichier' => 'Document joint', 'ip' => 'IP',
    ];
}

function yinv_e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
