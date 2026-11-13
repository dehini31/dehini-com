<?php
/**
 * Réseau de démonstration : trois marques fictives, deux distributeurs, leurs points de vente,
 * leur catalogue, deux mois de recherches et de commandes. Il sert à montrer la plateforme
 * avant l'arrivée des premières entreprises ; tout est marqué « démo » et se supprime en un
 * clic depuis l'administration. Les noms, adresses et clients sont inventés.
 */
declare(strict_types=1);

/**
 * Charge la démonstration à la première visite (une seule fois, sauf MLG_SEED_DEMO = false).
 * Un verrou fait patienter les autres requêtes pendant le chargement : sans lui, elles
 * afficheraient une plateforme vide pendant la première seconde.
 */
function seed_maybe(): void
{
    if (!MLG_SEED_DEMO) return;
    if (meta_get('seeded') !== null) { seed_radar_maybe(); return; }
    $lock = @fopen(dirname(MLG_DB_PATH) . '/seed.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        if (meta_get('seeded') !== null) return; // une autre requête vient de s'en charger
        $state = 'done';
        try {
            if ((int) db()->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 0) seed_demo();
        } catch (Throwable $e) {
            $state = 'failed';
            tx_rollback();
            error_log('maillage seed: ' . $e->getMessage());
        }
        meta_set('seeded', $state);
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** Une démonstration chargée avant l'arrivée du radar reçoit ses exemples de commandes radar (une seule fois). */
function seed_radar_maybe(): void
{
    if (meta_get('radar_seeded') !== null) return;
    $lock = @fopen(dirname(MLG_DB_PATH) . '/seed.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        if (meta_get('radar_seeded') !== null) return;
        meta_set('radar_seeded', '1');
        if (demo_exists()) seed_radar_demo();
    } catch (Throwable $e) {
        tx_rollback();
        error_log('maillage seed radar: ' . $e->getMessage());
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/**
 * Exemples de commandes radar dans la démonstration : trois encore libres (une par niveau :
 * commune, wilaya, partout), une prise en charge, une en livraison, une livrée.
 */
function seed_radar_demo(): void
{
    $pdo = db();
    $now = time();
    $rows = $pdo->query("SELECT v.id AS vid, s.id AS sid, s.wilaya, s.commune, s.company_id AS scid
        FROM stock k JOIN variants v ON v.id = k.variant_id JOIN products p ON p.id = v.product_id
        JOIN stores s ON s.id = k.store_id JOIN companies c ON c.id = s.company_id JOIN companies pc ON pc.id = p.company_id
        WHERE k.qty >= 3 AND c.demo = 1 AND pc.demo = 1 AND s.status = 'active' AND s.commune <> '' ORDER BY p.id, v.id, s.id")->fetchAll();
    if (!$rows) return;
    $withStore = [];
    foreach ($pdo->query("SELECT DISTINCT s.wilaya FROM stores s JOIN companies c ON c.id = s.company_id WHERE c.demo = 1") as $r) $withStore[(string) $r['wilaya']] = true;
    $far = '';
    foreach (['11', '39', '01', '33'] as $z) { if (!isset($withStore[$z]) && !empty(communes()[$z])) { $far = $z; break; } }
    $clients = ['Yacine B.', 'Amina K.', 'Sofiane M.', 'Lina H.', 'Karim A.', 'Nadia T.', 'Mehdi S.'];
    // [minutes écoulées, lieu du client, issue, position dans le catalogue (0 à 1)]
    $plan = [[4, 'commune', 'open', .03], [9, 'commune', 'open', .13], [22, 'wilaya', 'open', .21], [55, 'far', 'open', .29],
        [110, 'commune', 'claimed', .47], [420, 'wilaya', 'shipped', .72], [1700, 'commune', 'done', .91]];
    foreach ($plan as $i => [$ago, $where, $end, $pos]) {
        $r = $rows[min(count($rows) - 1, (int) floor($pos * count($rows)))];
        $zone = (string) $r['wilaya'];
        $commune = (string) $r['commune'];
        if ($where === 'wilaya') {
            foreach (communes()[$zone] ?? [] as $c) { if (commune_key($c[0]) !== commune_key($commune)) { $commune = (string) $c[0]; break; } }
        } elseif ($where === 'far' && $far !== '') {
            $zone = $far;
            $commune = (string) communes()[$far][0][0];
        }
        $at = $now - $ago * 60;
        try {
            $res = radar_create(['variant_id' => (int) $r['vid'], 'qty' => 1, 'name' => $clients[$i % count($clients)],
                'phone' => '05' . sprintf('%08d', 50000000 + $i * 1234567), 'zone' => $zone, 'commune' => $commune, 'address' => $i % 2 ? 'Adresse de démonstration' : ''], $at);
            if ($end === 'open') continue;
            $t = $at + 70 * 60;
            $o = radar_claim((int) $r['scid'], (int) $res['order']['id'], (int) $r['sid'], $t)['order'];
            if ($end === 'claimed') continue;
            $o = order_set_status($o, 'shipped', '', 'store', $t + 90 * 60);
            if ($end === 'done') order_set_status($o, 'done', '', 'store', $t + 900 * 60);
        } catch (Throwable $e) {
            tx_rollback();
        }
    }
}

function demo_exists(): bool
{
    return (int) db()->query('SELECT COUNT(*) FROM companies WHERE demo = 1')->fetchColumn() > 0;
}

function demo_delete(): int
{
    $ids = db()->query('SELECT id FROM companies WHERE demo = 1')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) company_delete((int) $id);
    return count($ids);
}

function seed_demo(): void
{
    $pdo = db();
    @set_time_limit(120);
    $pdo->exec('PRAGMA synchronous = OFF');
    mt_srand(20261110);
    $now = time();
    $today = strtotime('today');
    $rnd = function (int $a, int $b): int { return mt_rand($a, $b); };
    $pick = function (array $a) { return $a[mt_rand(0, count($a) - 1)]; };
    /** Tirage pondéré : [valeur => poids]. */
    $weighted = function (array $w) {
        $r = mt_rand(1, (int) array_sum($w));
        foreach ($w as $k => $p) {
            $r -= $p;
            if ($r <= 0) return $k;
        }
        return array_key_last($w);
    };

    $C = [
        'noir' => ['Noir', '#1b1b1f'], 'blanc' => ['Blanc', '#f5f5f4'], 'bleu' => ['Bleu', '#2563eb'], 'corail' => ['Corail', '#ff6b4a'],
        'vert' => ['Vert', '#15803d'], 'nuit' => ['Bleu nuit', '#1e293b'], 'jaune' => ['Jaune', '#facc15'], 'prune' => ['Prune', '#7e22ce'],
        'gris' => ['Gris', '#9ca3af'], 'kaki' => ['Kaki', '#6b7a3a'], 'inox' => ['Inox', '#c0c4c9'], 'orange' => ['Orange', '#f97316'],
        'sable' => ['Sable', '#d9c7a8'], 'terra' => ['Terracotta', '#c2613f'], 'sauge' => ['Sauge', '#9caf88'], 'ecru' => ['Écru', '#efe8d8'],
        'moutarde' => ['Moutarde', '#d4a017'], 'dore' => ['Doré', '#c9a227'], 'transp' => ['Transparent', '#e5e7eb'], 'creme' => ['Crème', '#f1e9d2'],
        'rouge' => ['Rouge', '#dc2626'], 'violet' => ['Violet', '#7c3aed'], 'menthe' => ['Menthe', '#7dd3c0'],
    ];

    $companies = [
        'velora' => ['kind' => 'brand', 'company' => 'Vélora Sport', 'city' => 'Alger', 'wilaya' => '16', 'sector' => 'mode',
            'about' => 'Vêtements et chaussures de sport conçus pour courir, bouger et voyager léger.',
            'brand' => ['name' => 'Vélora', 'tagline' => 'Le sport en mouvement', 'color' => '#2563eb', 'category' => 'mode',
                'about' => 'Vélora dessine des chaussures de running, des vêtements techniques et des accessoires pensés pour l\'entraînement de tous les jours.']],
        'darlina' => ['kind' => 'brand', 'company' => 'Dar Lina', 'city' => 'Blida', 'wilaya' => '09', 'sector' => 'maison',
            'about' => 'Linge de maison et décoration aux couleurs chaudes, en matières naturelles.',
            'brand' => ['name' => 'Dar Lina', 'tagline' => 'La maison, en douceur', 'color' => '#b45309', 'category' => 'maison',
                'about' => 'Dar Lina habille la maison : linge de lit en coton lavé, serviettes épaisses, tapis et objets du quotidien.']],
        'kivo' => ['kind' => 'brand', 'company' => 'Kivo Électro', 'city' => 'Sétif', 'wilaya' => '19', 'sector' => 'electro',
            'about' => 'Petit électroménager simple, robuste et garanti deux ans.',
            'brand' => ['name' => 'Kivo', 'tagline' => 'L\'électroménager qui dure', 'color' => '#7c3aed', 'category' => 'electro',
                'about' => 'Kivo fabrique le petit électroménager de la cuisine et de la maison, avec un service après-vente dans tout le réseau.']],
        'numidia' => ['kind' => 'distributor', 'company' => 'Comptoir Numidia', 'city' => 'Alger', 'wilaya' => '16', 'sector' => 'mode',
            'about' => 'Magasins multimarques dans le nord et l\'est du pays.'],
        'relais' => ['kind' => 'distributor', 'company' => 'Relais du Sud', 'city' => 'Ghardaïa', 'wilaya' => '47', 'sector' => 'maison',
            'about' => 'Distributeur des marques de la maison et de l\'électroménager dans le sud.'],
    ];
    $cid = [];
    $bid = [];
    foreach ($companies as $key => $c) {
        [$id] = account_create([
            'kind' => $c['kind'], 'company' => $c['company'], 'name' => 'Compte de démonstration', 'email' => $key . '@demo.maillage',
            'phone' => '', 'password' => '', 'wilaya' => $c['wilaya'], 'city' => $c['city'], 'sector' => $c['sector'], 'size' => '',
            'status' => 'active', 'about' => $c['about'], 'created' => $today - 75 * 86400,
        ], true);
        // L'entreprise de démonstration n'a pas d'adresse de contact : aucun email ne part vers elle.
        $pdo->prepare("UPDATE companies SET email = '' WHERE id = ?")->execute([$id]);
        $cid[$key] = $id;
        if (isset($c['brand'])) $bid[$key] = brand_save($id, $c['brand'])['id'];
    }

    /* --- catalogue : [nom, rayon, prix, tailles, couleurs, caractéristiques, description, prix par taille] */
    $catalog = [
        'velora' => [
            ['Chaussure de running Aéro 2', 'mode', 12900, ['39', '40', '41', '42', '43', '44', '45'], ['noir', 'bleu', 'corail'], ['Poids' => '248 g', 'Drop' => '8 mm', 'Usage' => 'Route', 'Amorti' => 'Mousse EVA'], 'Une chaussure légère et amortie pour les sorties quotidiennes sur route.'],
            ['Chaussure de trail Sierra', 'mode', 15900, ['40', '41', '42', '43', '44', '45'], ['gris', 'orange'], ['Poids' => '310 g', 'Crampons' => '4 mm', 'Usage' => 'Sentier'], 'Accroche et maintien sur les sentiers caillouteux.'],
            ['T-shirt technique Souffle', 'mode', 2900, ['S', 'M', 'L', 'XL'], ['blanc', 'noir', 'vert'], ['Matière' => 'Polyester recyclé', 'Séchage' => 'Rapide'], 'Respirant, sans coutures gênantes, il sèche en quelques minutes.'],
            ['Veste coupe-vent Mistral', 'mode', 8900, ['S', 'M', 'L', 'XL'], ['nuit', 'jaune'], ['Matière' => 'Nylon ripstop', 'Poids' => '120 g', 'Capuche' => 'Oui'], 'Elle se plie dans sa poche et coupe le vent sans peser.'],
            ['Legging Flex', 'mode', 4500, ['S', 'M', 'L'], ['noir', 'prune'], ['Matière' => 'Maille gainante', 'Poche' => 'Ceinture'], 'Taille haute, maintien ferme, poche à la ceinture.'],
            ['Survêtement Stade', 'mode', 9800, ['S', 'M', 'L', 'XL'], ['gris', 'noir'], ['Matière' => 'Molleton brossé', 'Coupe' => 'Droite'], 'L\'ensemble veste et pantalon pour l\'échauffement et les jours de repos.'],
            ['Short Tempo', 'mode', 3200, ['S', 'M', 'L', 'XL'], ['noir', 'bleu'], ['Longueur' => '13 cm', 'Slip intégré' => 'Oui'], 'Un short court et léger pour les séances rapides.'],
            ['Sac à dos Trek 25 L', 'mode', 6900, [], ['noir', 'kaki', 'corail'], ['Volume' => '25 L', 'Poids' => '640 g', 'Poche ordinateur' => '15 pouces'], 'Du bureau au sentier : dos aéré et poche ordinateur.'],
            ['Casquette Sprint', 'mode', 1900, [], ['noir', 'blanc', 'bleu'], ['Matière' => 'Microfibre', 'Réglage' => 'Sangle'], 'Visière souple et tissu perforé.'],
            ['Gourde isotherme 750 ml', 'mode', 2400, [], ['inox', 'noir', 'corail'], ['Contenance' => '750 ml', 'Froid' => '24 h', 'Chaud' => '12 h'], 'Double paroi en inox, garde la boisson fraîche toute la journée.'],
        ],
        'darlina' => [
            ['Parure de lit Coton lavé', 'maison', 11500, ['140×200', '160×200', '180×200'], ['sable', 'terra', 'nuit'], ['Matière' => '100 % coton', 'Tissage' => 'Percale 80 fils', 'Lavage' => '40 °C'], 'Housse de couette et deux taies en coton lavé, doux dès la première nuit.'],
            ['Serviette de bain Nuage', 'maison', 1800, ['50×100', '70×140'], ['blanc', 'sable', 'sauge', 'terra'], ['Matière' => 'Coton éponge', 'Grammage' => '550 g/m²'], 'Une éponge épaisse et absorbante.', ['70×140' => 2900]],
            ['Peignoir Hammam', 'maison', 6500, ['S/M', 'L/XL'], ['blanc', 'sable'], ['Matière' => 'Nid d\'abeille', 'Poches' => '2'], 'Léger, il sèche vite et se range facilement.'],
            ['Plaid Laine douce', 'maison', 5900, [], ['ecru', 'gris', 'moutarde'], ['Dimensions' => '130×170 cm', 'Matière' => 'Laine mélangée'], 'Un plaid chaud à franges pour le canapé.'],
            ['Coussin Zellige 45×45', 'maison', 2200, [], ['terra', 'nuit', 'sauge'], ['Dimensions' => '45×45 cm', 'Housse' => 'Déhoussable'], 'Motif brodé inspiré des zelliges.'],
            ['Tapis Kilim', 'maison', 18900, ['120×170', '160×230'], ['ecru', 'terra'], ['Matière' => 'Coton tissé', 'Envers' => 'Antidérapant'], 'Tissage plat, facile à vivre dans le salon.', ['160×230' => 29500]],
            ['Nappe Lin', 'maison', 4200, ['140×200', '140×250'], ['ecru', 'sauge'], ['Matière' => 'Lin mélangé', 'Lavage' => '30 °C'], 'Une nappe au tombé souple pour tous les jours.'],
            ['Rideau Voilage', 'maison', 3900, ['140×240', '140×260'], ['blanc', 'sable'], ['Fixation' => 'Œillets', 'Vendu' => 'À l\'unité'], 'Il tamise la lumière sans assombrir la pièce.'],
            ['Set de 6 verres à thé', 'maison', 2600, [], ['dore', 'transp'], ['Contenance' => '12 cl', 'Lot' => '6 verres'], 'Verres décorés pour le thé à la menthe.'],
            ['Bougie parfumée Fleur d\'oranger', 'maison', 1500, [], [], ['Durée' => '40 h', 'Cire' => 'Végétale'], 'Parfum de fleur d\'oranger, mèche en coton.'],
        ],
        'kivo' => [
            ['Bouilloire Kivo 1,7 L', 'electro', 5400, [], ['noir', 'inox', 'creme'], ['Puissance' => '2200 W', 'Capacité' => '1,7 L', 'Garantie' => '24 mois'], 'Arrêt automatique et filtre anti-calcaire.'],
            ['Mixeur plongeant Kivo Mix', 'electro', 6900, [], ['noir', 'blanc'], ['Puissance' => '800 W', 'Accessoires' => 'Fouet, hachoir', 'Garantie' => '24 mois'], 'Soupes, sauces et purées en quelques secondes.'],
            ['Machine à café Barista Mini', 'electro', 24900, [], ['noir', 'rouge'], ['Pression' => '15 bars', 'Réservoir' => '1,2 L', 'Garantie' => '24 mois'], 'Un expresso serré et une buse vapeur pour le lait.'],
            ['Fer à repasser Vapeur 2400', 'electro', 7800, [], ['bleu'], ['Puissance' => '2400 W', 'Débit vapeur' => '40 g/min', 'Garantie' => '24 mois'], 'Semelle céramique et jet de vapeur vertical.'],
            ['Aspirateur balai Kivo Air', 'electro', 32000, [], ['gris', 'violet'], ['Autonomie' => '45 min', 'Poids' => '2,4 kg', 'Garantie' => '24 mois'], 'Sans fil, il passe sous les meubles et se vide d\'un geste.'],
            ['Ventilateur sur pied Brise', 'electro', 8900, [], ['blanc', 'noir'], ['Diamètre' => '40 cm', 'Vitesses' => '3', 'Garantie' => '24 mois'], 'Oscillant, silencieux, hauteur réglable.'],
            ['Friteuse sans huile Crisp', 'electro', 17500, ['4 L', '6 L'], ['noir'], ['Puissance' => '1700 W', 'Programmes' => '8', 'Garantie' => '24 mois'], 'Croustillant sans huile, panier compatible lave-vaisselle.', ['6 L' => 22900]],
            ['Robot pâtissier Chef 5 L', 'electro', 28500, [], ['creme', 'noir'], ['Puissance' => '1200 W', 'Bol' => '5 L inox', 'Garantie' => '24 mois'], 'Pétrin, fouet et batteur pour les pâtes et les gâteaux.'],
            ['Grille-pain Duo', 'electro', 4300, [], ['inox', 'noir'], ['Fentes' => '2', 'Niveaux' => '7', 'Garantie' => '24 mois'], 'Deux fentes larges et un tiroir ramasse-miettes.'],
            ['Écouteurs sans fil Pulse', 'high-tech', 6500, [], ['noir', 'blanc', 'menthe'], ['Autonomie' => '28 h avec boîtier', 'Bluetooth' => '5.3', 'Garantie' => '12 mois'], 'Un son clair et une réduction du bruit pour les trajets.'],
        ],
    ];
    $products = [];   // [company key][] = ['id', 'name', 'variants' => [...], 'weight']
    foreach ($catalog as $key => $list) {
        foreach ($list as $rank => $p) {
            [$name, $cat, $price, $sizes, $colors, $specs, $about] = $p;
            $override = $p[7] ?? [];
            $variants = [];
            foreach ($sizes ?: [''] as $sz) {
                foreach ($colors ?: [''] as $col) {
                    $variants[] = ['size' => $sz, 'color' => $col !== '' ? $C[$col][0] : '', 'hex' => $col !== '' ? $C[$col][1] : '', 'price' => $override[$sz] ?? null];
                }
            }
            $sp = [];
            foreach ($specs as $k => $v) $sp[] = ['k' => $k, 'v' => $v];
            $saved = product_save($cid[$key], ['brand_id' => $bid[$key], 'name' => $name, 'category' => $cat, 'price' => $price, 'about' => $about, 'specs' => $sp, 'variants' => $variants]);
            $pdo->prepare('UPDATE products SET created = ?, updated = ? WHERE id = ?')->execute([$today - (70 - $rank) * 86400, $today - (70 - $rank) * 86400, $saved['id']]);
            $products[$key][] = ['id' => $saved['id'], 'name' => $name, 'price' => $price, 'variants' => $saved['variants'], 'weight' => max(2, 12 - $rank)];
        }
    }

    /* --- points de vente : [entreprise, nom, type, ville, wilaya, lat, lng, adresse, livraison, poids] */
    $H = ['open' => '09:00', 'close' => '19:30', 'days' => [0, 1, 2, 3, 4, 6]];
    $storeDefs = [
        ['velora', 'Vélora Alger Centre', 'magasin', 'Alger', '16', 36.7731, 3.0588, 'Quartier du centre', 1, 10],
        ['velora', 'Vélora Bab Ezzouar', 'magasin', 'Bab Ezzouar', '16', 36.7262, 3.1830, 'Quartier d\'affaires', 1, 8],
        ['velora', 'Vélora Oran', 'magasin', 'Oran', '31', 35.6990, -0.6359, 'Centre-ville', 1, 7],
        ['velora', 'Vélora Constantine', 'succursale', 'Constantine', '25', 36.3650, 6.6147, 'Centre-ville', 0, 5],
        ['velora', 'Vélora Tizi Ouzou', 'succursale', 'Tizi Ouzou', '15', 36.7118, 4.0459, 'Nouvelle ville', 0, 4],
        ['darlina', 'Dar Lina Hydra', 'magasin', 'Hydra', '16', 36.7436, 3.0420, 'Quartier commerçant', 1, 9],
        ['darlina', 'Dar Lina Blida', 'magasin', 'Blida', '09', 36.4700, 2.8277, 'Centre-ville', 1, 6],
        ['darlina', 'Dar Lina Oran', 'magasin', 'Oran', '31', 35.7220, -0.5890, 'Quartier est', 1, 6],
        ['darlina', 'Dar Lina Annaba', 'succursale', 'Annaba', '23', 36.9000, 7.7667, 'Centre-ville', 0, 4],
        ['darlina', 'Dar Lina Tlemcen', 'succursale', 'Tlemcen', '13', 34.8828, -1.3167, 'Centre-ville', 0, 3],
        ['kivo', 'Kivo Store Chéraga', 'magasin', 'Chéraga', '16', 36.7672, 2.9592, 'Zone commerciale', 1, 9],
        ['kivo', 'Kivo Store Sétif', 'magasin', 'Sétif', '19', 36.1911, 5.4137, 'Centre-ville', 1, 7],
        ['kivo', 'Kivo Store Oran', 'magasin', 'Oran', '31', 35.6911, -0.6417, 'Centre-ville', 1, 6],
        ['numidia', 'Comptoir Numidia Kouba', 'distributeur', 'Kouba', '16', 36.7260, 3.0870, 'Avenue principale', 1, 8],
        ['numidia', 'Comptoir Numidia Béjaïa', 'distributeur', 'Béjaïa', '06', 36.7509, 5.0567, 'Centre-ville', 0, 5],
        ['numidia', 'Comptoir Numidia Annaba', 'distributeur', 'Annaba', '23', 36.8980, 7.7550, 'Cours central', 0, 5],
        ['numidia', 'Comptoir Numidia Batna', 'distributeur', 'Batna', '05', 35.5560, 6.1740, 'Centre-ville', 0, 4],
        ['numidia', 'Comptoir Numidia Mostaganem', 'distributeur', 'Mostaganem', '27', 35.9310, 0.0890, 'Centre-ville', 0, 3],
        ['relais', 'Relais du Sud Ghardaïa', 'distributeur', 'Ghardaïa', '47', 32.4900, 3.6730, 'Centre-ville', 1, 4],
        ['relais', 'Relais du Sud Ouargla', 'distributeur', 'Ouargla', '30', 31.9490, 5.3250, 'Centre-ville', 0, 3],
        ['relais', 'Relais du Sud Biskra', 'distributeur', 'Biskra', '07', 34.8500, 5.7280, 'Centre-ville', 0, 4],
        ['relais', 'Relais du Sud Béchar', 'distributeur', 'Béchar', '08', 31.6170, -2.2160, 'Centre-ville', 0, 2],
    ];
    // Ce que chaque distributeur référence : [entreprise du magasin][index du magasin dans son réseau] => marques.
    $carries = [
        'numidia' => [['velora', 'kivo'], ['velora', 'kivo'], ['velora'], ['kivo'], ['kivo']],
        'relais' => [['darlina', 'kivo'], ['darlina', 'kivo'], ['darlina', 'kivo'], ['kivo']],
    ];
    $stores = [];
    $nth = [];
    foreach ($storeDefs as $d) {
        [$key, $name, $type, $city, $zone, $lat, $lng, $addr, $deliv, $w] = $d;
        $i = $nth[$key] = ($nth[$key] ?? -1) + 1;
        $v = store_save($cid[$key], ['name' => $name, 'type' => $type, 'city' => $city, 'wilaya' => $zone, 'lat' => $lat, 'lng' => $lng, 'address' => $addr . ', ' . $city,
            'phone' => '0' . $pick([5, 6, 7]) . sprintf('%08d', $rnd(10000000, 99999999)), 'hours' => $H, 'pickup' => 1, 'delivery' => $deliv, 'reserve' => 1]);
        $pdo->prepare('UPDATE stores SET created = ? WHERE id = ?')->execute([$today - $rnd(40, 74) * 86400, $v['id']]);
        $stores[] = ['id' => $v['id'], 'key' => $key, 'name' => $name, 'zone' => $zone, 'lat' => $lat, 'lng' => $lng, 'delivery' => $deliv, 'weight' => $w,
            'brands' => isset($carries[$key]) ? $carries[$key][$i] : [$key], 'slow' => $rnd(1, 5), 'cancel' => $rnd(5, 22)];
    }

    $now2 = $today - 60 * 86400;
    $pdo->exec("INSERT INTO partnerships (brand_cid, dist_cid, status, initiator, created, updated) VALUES
        ({$cid['velora']}, {$cid['numidia']}, 'active', {$cid['velora']}, $now2, $now2),
        ({$cid['kivo']}, {$cid['numidia']}, 'active', {$cid['numidia']}, $now2, $now2),
        ({$cid['darlina']}, {$cid['relais']}, 'active', {$cid['darlina']}, $now2, $now2),
        ({$cid['kivo']}, {$cid['relais']}, 'active', {$cid['relais']}, $now2, $now2),
        ({$cid['velora']}, {$cid['relais']}, 'pending', {$cid['relais']}, " . ($today - 2 * 86400) . ', ' . ($today - 2 * 86400) . ')');

    /* --- assortiment : chaque magasin référence une partie du catalogue (stock plein pendant la génération des commandes) */
    $assort = [];   // [index magasin] => [[produit, clé de la marque], ...]
    tx_begin();
    $ins = $pdo->prepare('INSERT OR REPLACE INTO stock (store_id, variant_id, qty, updated) VALUES (?, ?, 999, ?)');
    foreach ($stores as $si => $st) {
        foreach ($st['brands'] as $bk) {
            $own = $st['key'] === $bk;
            foreach ($products[$bk] as $p) {
                if (!$own && $rnd(1, 100) > 65) continue;
                if ($own && $rnd(1, 100) > 92) continue;
                $assort[$si][] = [$p, $bk];
                foreach ($p['variants'] as $v) $ins->execute([$st['id'], $v['id'], $today]);
            }
        }
    }
    tx_commit();

    /* --- commandes des 60 derniers jours */
    $first = ['Yacine', 'Amina', 'Sofiane', 'Lina', 'Karim', 'Nadia', 'Mehdi', 'Sara', 'Walid', 'Imène', 'Rachid', 'Meriem', 'Anis', 'Yasmine', 'Hamza', 'Salma', 'Nabil', 'Ines', 'Farid', 'Asma'];
    $storeW = [];
    foreach ($stores as $si => $st) $storeW[$si] = $st['weight'];
    $zoneList = array_keys(zones());
    for ($day = 59; $day >= 0; $day--) {
        $n = (int) round((2.6 + (59 - $day) * 0.07) * (mt_rand(60, 150) / 100));
        for ($k = 0; $k < $n; $k++) {
            $si = $weighted($storeW);
            $st = $stores[$si];
            if (empty($assort[$si])) continue;
            [$p] = $pick($assort[$si]);
            $v = $pick($p['variants']);
            $at = $today - $day * 86400 + $rnd(9 * 3600, 21 * 3600);
            if ($at > $now - 600) $at = $now - $rnd(600, 7200);
            $kind = $rnd(1, 100) <= 30 ? 'reservation' : 'order';
            $mode = ($kind === 'order' && $st['delivery'] && $rnd(1, 100) <= 40) ? 'delivery' : 'pickup';
            try {
                $res = order_create([
                    'store_id' => $st['id'], 'kind' => $kind, 'mode' => $mode, 'items' => [['variant_id' => $v['id'], 'qty' => $rnd(1, 100) <= 82 ? 1 : 2]],
                    'name' => $pick($first) . ' ' . chr($rnd(65, 90)) . '.', 'phone' => '0' . $pick([5, 6, 7]) . sprintf('%08d', $rnd(10000000, 99999999)),
                    'address' => $mode === 'delivery' ? 'Adresse de démonstration' : '', 'city' => $mode === 'delivery' ? zone_name($st['zone']) : '',
                    'zone' => $mode === 'delivery' ? ($rnd(1, 100) <= 70 ? $st['zone'] : $pick($zoneList)) : '',
                ], $at);
            } catch (Throwable $e) {
                continue;
            }
            $o = $res['order'];
            $age = $now - $at;
            // Parcours : annulée, ou confirmée → prête / expédiée → terminée, selon l'ancienneté.
            $steps = [];
            if ($rnd(1, 100) <= $st['cancel'] && $age > 6 * 3600) {
                $steps[] = ['cancelled', $rnd(1, 20) * 1800];
            } else {
                $steps[] = ['confirmed', $st['slow'] * $rnd(600, 5400)];
                $steps[] = [$mode === 'delivery' ? 'shipped' : 'ready', $rnd(2, 20) * 3600];
                $steps[] = ['done', $rnd(4, 40) * 3600];
            }
            $t = $at;
            foreach ($steps as [$status, $delay]) {
                $t += $delay;
                if ($t > $now - 60) break;
                try {
                    $o = order_set_status($o, $status, $status === 'cancelled' ? $pick(['Client injoignable', 'Annulée à la demande du client', 'Article défectueux en réserve']) : '', 'store', $t);
                } catch (Throwable $e) {
                    break;
                }
            }
            // Une réservation ancienne restée ouverte aurait expiré.
            if ($o['kind'] === 'reservation' && in_array($o['status'], ['pending', 'confirmed', 'ready'], true) && (int) $o['expires'] < $now) {
                try { order_set_status($o, 'cancelled', 'Réservation expirée : l\'article n\'a pas été retiré à temps.', 'system', (int) $o['expires']); } catch (Throwable $e) { /* déjà close */ }
            }
        }
    }

    /* --- stock réel : quelques ruptures et stocks limités */
    tx_begin();
    $set = $pdo->prepare('UPDATE stock SET qty = ?, updated = ? WHERE store_id = ? AND variant_id = ?');
    foreach ($stores as $si => $st) {
        foreach ($assort[$si] ?? [] as [$p]) {
            foreach ($p['variants'] as $v) {
                $r = $rnd(1, 100);
                $qty = $r <= 14 ? 0 : ($r <= 32 ? $rnd(1, 3) : $rnd(4, 24));
                $set->execute([$qty, $today - $rnd(0, 6) * 86400 + $rnd(8, 18) * 3600, $st['id'], $v['id']]);
            }
        }
    }
    tx_commit();

    /* --- recherches des 60 derniers jours */
    $zoneW = ['16' => 22, '31' => 12, '25' => 8, '19' => 10, '09' => 5, '15' => 5, '06' => 4, '23' => 4, '05' => 6, '07' => 5, '13' => 3, '02' => 5,
        '27' => 3, '47' => 2, '30' => 2, '17' => 4, '26' => 2, '08' => 1, '21' => 2, '28' => 3, '14' => 2, '34' => 2, '35' => 2, '39' => 2, '18' => 1, '22' => 2];
    $brandW = ['velora' => 45, 'darlina' => 28, 'kivo' => 27];
    $terms = [
        'velora' => ['chaussure running', 'chaussures de course', 't-shirt sport', 'veste coupe-vent', 'legging', 'sac à dos', 'survêtement', 'short', 'casquette', 'gourde', 'trail'],
        'darlina' => ['parure de lit', 'serviette de bain', 'tapis', 'plaid', 'coussin', 'nappe', 'rideau', 'peignoir', 'verres à thé', 'bougie'],
        'kivo' => ['bouilloire', 'machine à café', 'friteuse sans huile', 'aspirateur', 'mixeur', 'ventilateur', 'robot pâtissier', 'grille-pain', 'écouteurs', 'fer à repasser'],
    ];
    // Demandes sans réponse : dans le catalogue d'une marque, et sur toute la plateforme.
    $missBrand = ['velora' => ['chaussettes', 'brassière', 'tapis de yoga', 'maillot de bain'], 'darlina' => ['couette', 'oreiller', 'drap housse'], 'kivo' => ['micro-ondes', 'climatiseur', 'cafetière filtre']];
    $missAll = ['montre connectée', 'haltères', 'robot aspirateur', 'trottinette', 'poussette', 'vélo d\'appartement'];
    tx_begin();
    for ($day = 59; $day >= 0; $day--) {
        $n = (int) round((22 + (59 - $day) * 0.55) * (mt_rand(70, 135) / 100));
        for ($k = 0; $k < $n; $k++) {
            $at = $today - $day * 86400 + $rnd(7 * 3600, 23 * 3600);
            if ($at > $now) $at = $now - $rnd(60, 3600);
            $zone = (string) $weighted($zoneW);
            $z = zones()[$zone];
            $geo = $rnd(1, 100) <= 55;
            $loc = ['zone' => $zone, 'lat' => $geo ? $z['lat'] + mt_rand(-80, 80) / 1000 : null, 'lng' => $geo ? $z['lng'] + mt_rand(-80, 80) / 1000 : null];
            $bk = $weighted($brandW);
            $r = $rnd(1, 100);
            if ($r <= 58) {
                // Où trouver ce produit ? (taille et couleur précisées deux fois sur trois)
                $pw = [];
                foreach ($products[$bk] as $pi => $p) $pw[$pi] = $p['weight'];
                $p = $products[$bk][$weighted($pw)];
                $v = $pick($p['variants']);
                $size = $rnd(1, 100) <= 68 ? $v['size'] : '';
                $color = $rnd(1, 100) <= 55 ? $v['color'] : '';
                $lat = $loc['lat'] ?? (float) $z['lat'];
                $lng = $loc['lng'] ?? (float) $z['lng'];
                $found = availability($p['id'], $size, $color, $lat, $lng);
                log_search('availability', $loc + ['product' => $p['id'], 'brand' => $bid[$bk], 'size' => $size, 'color' => $color,
                    'results' => count($found), 'nearest_km' => $found ? $found[0]['km'] : null], [$cid[$bk]], $at);
            } elseif ($r <= 86) {
                $q = $pick($terms[$bk]);
                $res = catalog_search(['q' => $q]);
                log_search('catalog', $loc + ['q' => $q, 'results' => $res['total']], $res['companies'], $at);
            } elseif ($r <= 91) {
                $q = $pick($missBrand[$bk]);
                $res = catalog_search(['q' => $q, 'brand' => $bid[$bk]]);
                log_search('catalog', $loc + ['q' => $q, 'brand' => $bid[$bk], 'results' => $res['total']], $res['companies'] ?: [$cid[$bk]], $at);
            } elseif ($r <= 95) {
                $q = $pick($missAll);
                $res = catalog_search(['q' => $q]);
                log_search('catalog', $loc + ['q' => $q, 'results' => $res['total']], $res['companies'], $at);
            } else {
                $count = 0;
                foreach ($stores as $st) { if (in_array($bk, $st['brands'], true)) $count++; }
                log_search('map', $loc + ['brand' => $bid[$bk], 'results' => $count], [$cid[$bk]], $at);
            }
        }
    }
    // Fiches consultées, appels et itinéraires.
    for ($day = 59; $day >= 0; $day--) {
        $d = date('Y-m-d', $today - $day * 86400);
        foreach ($products as $list) {
            foreach ($list as $p) view_hit('product', $p['id'], $d, (int) round($p['weight'] * mt_rand(40, 260) / 100 * (1 + (59 - $day) / 90)));
        }
        foreach ($stores as $st) {
            view_hit('store', $st['id'], $d, (int) round($st['weight'] * mt_rand(30, 200) / 100));
            if ($rnd(1, 100) <= 60) view_hit('call', $st['id'], $d, $rnd(1, max(1, (int) ($st['weight'] / 2))));
            if ($rnd(1, 100) <= 50) view_hit('route', $st['id'], $d, $rnd(1, max(1, (int) ($st['weight'] / 2))));
        }
    }
    // Journal : on retire les ajouts en rafale du chargement, on garde les commandes et quelques mises à jour de stock.
    $in = implode(',', $cid);
    $pdo->exec("DELETE FROM activity WHERE company_id IN ($in) AND kind IN ('brand', 'product', 'store')");
    foreach ($stores as $si => $st) {
        if (empty($assort[$si])) continue;
        $per = [];
        foreach ($assort[$si] as [$p, $bk]) $per[$bk] = ($per[$bk] ?? 0) + count($p['variants']);
        foreach ($per as $bk => $k) {
            log_activity($cid[$st['key']], 'stock', 'Disponibilité mise à jour : ' . $k . ' références · ' . $st['name'], $st['id'], $cid[$bk], min($now - $rnd(1200, 20000), $today - $rnd(0, 5) * 86400 + $rnd(8, 18) * 3600));
        }
    }
    log_activity($cid['relais'], 'partner', 'Demande de partenariat envoyée à Vélora Sport', 0, $cid['velora'], $today - 2 * 86400 + 11 * 3600);
    tx_commit();
    seed_radar_demo();
    meta_set('radar_seeded', '1');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    mt_srand();
}
