<?php
/**
 * API de Maillage — dehini.com/maillage/
 *
 * Réponses JSON : {ok:true,…} ou {error:"code", message:"texte affichable"}.
 * Toute écriture (POST) exige l'en-tête X-Requested-With: maillage ; les écritures d'un compte
 * connecté exigent en plus l'en-tête X-CSRF (reçu à la connexion).
 *
 * Public   GET  bootstrap | stores | store {id} | brand {slug} | products {q,brand,category,size,color,pmin,pmax,store,instock,sort,page,log}
 *          GET  product {slug} | availability {product,size,color,lat,lng,zone,log} | track {token | ref,phone} | communes {zone}
 *          GET  research_get {token | ref,phone}
 *          POST order {…} | order_radar {…} | order_cancel {token} | event {kind,id} | log_map {brand,zone,lat,lng,results}
 *          POST visual_identify (multipart : file) | visual_search (multipart : file + description) — recherche par photo
 *          POST research_create (multipart : file + description + coordonnées) | research_close {token | ref,phone, found}
 * Comptes  GET  me    POST signup (marque, distributeur ou point de vente) | login | logout | forgot | reset | demo_login
 * Espace   GET  overview | brands | dash_products | dash_product {id} | dash_stores | stock {store} | orders | order_get {id} | network | analytics {days} | radar
 *          POST brand_save | brand_delete | product_save | product_delete | upload (multipart) | store_save | store_delete | stock_save
 *          POST order_status | radar_claim | radar_release | research_reply | research_dismiss
 *          POST partner_request | partner_respond | partner_remove | company_save | account_save | password
 * Admin    GET  adm_overview    POST adm_company | adm_open | adm_demo | adm_settings   (session de /admin)
 *
 * Un compte limité à un point de vente (users.store_id) n'accède qu'à son magasin : vue d'ensemble,
 * commandes, stock, fiche, radar et son propre compte ; tout le reste exige le compte de l'entreprise.
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/media.php';
require __DIR__ . '/lib/catalog.php';
require __DIR__ . '/lib/orders.php';
require __DIR__ . '/lib/radar.php';
require __DIR__ . '/lib/visual.php';
require __DIR__ . '/lib/research.php';
require __DIR__ . '/lib/dash.php';
require __DIR__ . '/lib/analytics.php';
require __DIR__ . '/lib/seed.php';
require __DIR__ . '/lib/import.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

$action = (string) ($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    db();
    seed_maybe();
    orders_expire();
    research_expire();
} catch (Throwable $e) {
    error_log('maillage db: ' . $e->getMessage());
    fail('Le service est momentanément indisponible.', 500, 'server');
}

if ($isPost && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'maillage') fail('Requête refusée.', 403, 'forbidden');

const POST_ONLY = ['order', 'order_radar', 'order_cancel', 'event', 'log_map', 'signup', 'login', 'logout', 'forgot', 'reset', 'demo_login',
    'brand_save', 'brand_delete', 'product_save', 'product_delete', 'upload', 'store_save', 'store_delete', 'stock_save', 'order_status',
    'radar_claim', 'radar_release', 'visual_identify', 'visual_search', 'research_create', 'research_close', 'research_reply', 'research_dismiss',
    'partner_request', 'partner_respond', 'partner_remove', 'company_save', 'account_save', 'password',
    'adm_company', 'adm_open', 'adm_demo', 'adm_settings', 'adm_import'];
if (in_array($action, POST_ONLY, true) && !$isPost) fail('Méthode non autorisée.', 405, 'method');

// Les envois de fichier arrivent en multipart : leurs champs sont dans $_POST.
const MULTIPART = ['upload', 'visual_identify', 'visual_search', 'research_create'];
$b = !$isPost ? [] : (in_array($action, MULTIPART, true) ? $_POST : input_json());
$pdo = db();

/** Image envoyée par un visiteur (recherche par photo) : vérifiée et ouverte. InvalidArgumentException si ce n'est pas une image acceptée. */
function visitor_image()
{
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        fail('L\'envoi de l\'image a échoué. Réessayez avec une autre image.');
    }
    if ((int) $f['size'] > MEDIA_MAX_BYTES) fail('Image trop lourde (' . (int) (MEDIA_MAX_BYTES / 1048576) . ' Mo maximum).');
    return image_open((string) $f['tmp_name'], 64);
}

function g(string $k): string
{
    $v = $_GET[$k] ?? '';
    return is_string($v) ? trim($v) : '';
}
function password_in($p): string
{
    $p = is_string($p) ? $p : '';
    if (strlen($p) < 8) fail('Le mot de passe doit contenir au moins 8 caractères.');
    if (strlen($p) > 200) fail('Mot de passe trop long.');
    return $p;
}

try {
    switch ($action) {

        /* ------------------------------------------------------------ public */

        case 'bootstrap': {
            $cats = [];
            foreach (MLG_CATEGORIES as $k => $label) $cats[] = ['key' => $k, 'label' => $label];
            $one = function (string $sql) use ($pdo): int { return (int) $pdo->query($sql)->fetchColumn(); };
            $vis = "FROM stores s JOIN companies c ON c.id = s.company_id WHERE s.status = 'active' AND c.status = 'active'";
            ok([
                'config' => [
                    'name' => MLG_NAME, 'currency' => MLG_CURRENCY, 'tiles' => MLG_TILES_URL, 'attr' => MLG_TILES_ATTR,
                    'center' => MLG_MAP_CENTER, 'zoom' => (int) MLG_MAP_ZOOM, 'reservation_hours' => (int) MLG_RESERVATION_HOURS,
                    'cover_km' => (float) MLG_COVER_KM, 'tz' => MLG_TIMEZONE, 'demo' => demo_exists(),
                    'radar_step_min' => intdiv(radar_step(), 60),
                ],
                'zones' => array_values(zones()),
                'categories' => $cats,
                'store_types' => MLG_STORE_TYPES,
                'statuses' => ORDER_STATUSES,
                'brands' => public_brands(),
                'counts' => [
                    'stores' => $one("SELECT COUNT(*) $vis"),
                    'zones' => $one("SELECT COUNT(DISTINCT s.wilaya) $vis"),
                    'products' => $one("SELECT COUNT(*) FROM products p JOIN companies c ON c.id = p.company_id WHERE p.status = 'active' AND c.status = 'active'"),
                ],
            ]);
        }

        case 'stores':
            ok(['stores' => public_stores()]);

        case 'store': {
            $st = public_store((int) g('id'));
            if (!$st) fail('Point de vente introuvable.', 404, 'notfound');
            ok(['store' => $st]);
        }

        case 'brand': {
            foreach (public_brands() as $br) {
                if ($br['slug'] === g('slug')) ok(['brand' => $br]);
            }
            fail('Marque introuvable.', 404, 'notfound');
        }

        case 'products': {
            $f = ['q' => mb_substr(g('q'), 0, 80), 'brand' => (int) g('brand'), 'category' => g('category'), 'size' => mb_substr(g('size'), 0, 30),
                'color' => mb_substr(g('color'), 0, 30), 'pmin' => g('pmin'), 'pmax' => g('pmax'), 'store' => (int) g('store'),
                'instock' => g('instock') === '1', 'sort' => g('sort'), 'page' => (int) g('page')];
            $res = catalog_search($f);
            // Une recherche « posée » (texte, taille ou couleur) est comptée pour les analyses.
            if (g('log') === '1' && ($f['q'] !== '' || $f['size'] !== '' || $f['color'] !== '') && $f['page'] <= 1 && rate('search', 150, 3600, '', true)) {
                $cids = $res['companies'];
                if (!$cids && $f['brand']) {
                    $s = $pdo->prepare('SELECT company_id FROM brands WHERE id = ?');
                    $s->execute([$f['brand']]);
                    if ($c = $s->fetchColumn()) $cids = [(int) $c];
                }
                log_search('catalog', $f + ['results' => $res['total'], 'zone' => g('zone'), 'lat' => g('lat'), 'lng' => g('lng')], $cids);
            }
            unset($res['companies']);
            ok($res);
        }

        case 'product': {
            $p = public_product(g('slug'));
            if (!$p) fail('Produit introuvable.', 404, 'notfound');
            ok(['product' => $p]);
        }

        case 'availability': {
            $pid = (int) g('product');
            $s = $pdo->prepare("SELECT p.id, p.company_id, p.brand_id FROM products p JOIN companies c ON c.id = p.company_id WHERE p.id = ? AND p.status = 'active' AND c.status = 'active'");
            $s->execute([$pid]);
            $p = $s->fetch();
            if (!$p) fail('Produit introuvable.', 404, 'notfound');
            $lat = coord(g('lat'), 90);
            $lng = coord(g('lng'), 180);
            if ($lat === null || $lng === null) { $lat = null; $lng = null; }
            $size = mb_substr(g('size'), 0, 30);
            $color = mb_substr(g('color'), 0, 30);
            $found = availability($pid, $size, $color, $lat, $lng);
            if (g('log') === '1' && rate('search', 150, 3600, '', true)) {
                log_search('availability', ['product' => $pid, 'brand' => (int) $p['brand_id'], 'size' => $size, 'color' => $color, 'zone' => g('zone'),
                    'lat' => $lat, 'lng' => $lng, 'results' => count($found), 'nearest_km' => $found && $found[0]['km'] !== null ? $found[0]['km'] : null], [(int) $p['company_id']]);
            }
            // Sans stock confirmé : les points de vente qui vendent la marque, présentés comme tels.
            $except = array_map(function ($st) { return (int) $st['id']; }, $found);
            ok(['stores' => $found, 'brand_stores' => brand_stores($pid, $except, $lat, $lng)]);
        }

        case 'track': {
            rate('track', 40, 600);
            $o = null;
            if (preg_match('/^[a-f0-9]{40}$/', g('token'))) {
                $s = $pdo->prepare('SELECT * FROM orders WHERE thash = ?');
                $s->execute([hash('sha256', g('token'))]);
                $o = $s->fetch();
            } else {
                $s = $pdo->prepare('SELECT * FROM orders WHERE ref = ?');
                $s->execute([strtoupper(mb_substr(g('ref'), 0, 12))]);
                $o = $s->fetch();
                if ($o && (phone_key(g('phone')) === '' || phone_key(g('phone')) !== phone_key((string) $o['cphone']))) $o = null;
            }
            if (!$o) fail('Aucune commande ne correspond. Vérifiez le numéro et le téléphone.', 404, 'notfound');
            ok(['order' => order_view($o)]);
        }

        case 'communes': {
            $zone = zone_code(g('zone'));
            if ($zone === '') fail('Wilaya inconnue.', 404, 'notfound');
            header('Cache-Control: public, max-age=86400');
            ok(['zone' => $zone, 'communes' => communes()[$zone] ?? []]);
        }

        case 'order': {
            rate('order', 40, 3600);
            if (!empty($b['website'])) ok(['ref' => 'ML-000000', 'token' => str_repeat('0', 40)]); // champ piège rempli par un robot
            $res = order_create($b);
            order_notify_created($res);
            ok(['ref' => $res['order']['ref'], 'token' => $res['token'], 'order' => order_view($res['order'])]);
        }

        // Commande sans point de vente choisi : le radar la propose aux points de vente les plus proches du client.
        case 'order_radar': {
            rate('order', 40, 3600);
            if (!empty($b['website'])) ok(['ref' => 'ML-000000', 'token' => str_repeat('0', 40)]);
            $res = radar_create($b);
            radar_notify_created($res);
            ok(['ref' => $res['order']['ref'], 'token' => $res['token'], 'order' => order_view($res['order'])]);
        }

        /* ------------------------------------------------- recherche par photo */

        // 1. La photo est comparée à celles du catalogue : une proposition de description, à vérifier par le client.
        case 'visual_identify': {
            rate('visual', 40, 3600);
            $im = visitor_image();
            $res = visual_identify($im);
            imagedestroy($im);
            ok($res);
        }

        // 2. Recherche avec l'image et la description (corrigée par le client).
        case 'visual_search': {
            rate('vsearch', 80, 3600);
            $im = visitor_image();
            $terms = json_decode(is_string($b['terms'] ?? null) ? $b['terms'] : '[]', true);
            $lat = coord($b['lat'] ?? null, 90);
            $lng = coord($b['lng'] ?? null, 180);
            if ($lat === null || $lng === null) { $lat = null; $lng = null; }
            $res = visual_search($im, ['category' => $b['category'] ?? '', 'name' => $b['name'] ?? '', 'brand' => $b['brand'] ?? '', 'details' => $b['details'] ?? '',
                'terms' => is_array($terms) ? $terms : []], $lat, $lng);
            imagedestroy($im);
            // Comptée comme une recherche du catalogue : sans résultat, c'est une « demande sans réponse » dans les analyses.
            if ($res['info']['name'] !== '' && rate('search', 150, 3600, '', true)) {
                log_search('catalog', ['q' => $res['info']['name'], 'category' => $res['info']['category'], 'results' => ($res['exact'] ? 1 : 0) + count($res['similar']),
                    'zone' => $b['zone'] ?? '', 'lat' => $lat, 'lng' => $lng], $res['companies']);
            }
            unset($res['companies']);
            ok($res);
        }

        // 3. Recherche active : la demande part dans le Radar des points de vente et des marques.
        case 'research_create': {
            rate('research', 8, 3600);
            if (!empty($b['website'])) ok(['ref' => 'RS-000000', 'token' => str_repeat('0', 40)]); // champ piège rempli par un robot
            $im = visitor_image();
            $path = research_image($im);
            imagedestroy($im);
            try {
                $res = research_create($b, $path, !empty($b['auto']));
            } catch (Throwable $e) {
                research_image_delete($path);
                throw $e;
            }
            research_notify_created($res);
            ok(['ref' => $res['research']['ref'], 'token' => $res['token'], 'research' => research_view($res['research'])]);
        }

        case 'research_get': {
            rate('track', 40, 600);
            $r = research_find(g('token'), g('ref'), g('phone'));
            if (!$r) fail('Aucune recherche ne correspond. Vérifiez le numéro et le téléphone.', 404, 'notfound');
            ok(['research' => research_view($r)]);
        }

        case 'research_close': {
            rate('track', 40, 600);
            $r = research_find((string) ($b['token'] ?? ''), (string) ($b['ref'] ?? ''), (string) ($b['phone'] ?? ''));
            if (!$r) fail('Recherche introuvable.', 404, 'notfound');
            ok(['research' => research_view(research_close($r, !empty($b['found']) ? 'found' : 'closed'))]);
        }

        case 'order_cancel': {
            rate('track', 40, 600);
            $tok = (string) ($b['token'] ?? '');
            if (!preg_match('/^[a-f0-9]{40}$/', $tok)) fail('Commande introuvable.', 404, 'notfound');
            $s = $pdo->prepare('SELECT * FROM orders WHERE thash = ?');
            $s->execute([hash('sha256', $tok)]);
            $o = $s->fetch();
            if (!$o) fail('Commande introuvable.', 404, 'notfound');
            if ($o['status'] !== 'pending') fail('Cette commande est déjà prise en charge : contactez le point de vente pour l\'annuler.');
            $o = order_set_status($o, 'cancelled', 'Annulée par le client', 'client');
            ok(['order' => order_view($o)]);
        }

        case 'event': {
            if (rate('event', 300, 3600, '', true)) view_hit((string) ($b['kind'] ?? ''), (int) ($b['id'] ?? 0));
            ok();
        }

        case 'log_map': {
            $s = $pdo->prepare("SELECT b.id, b.company_id FROM brands b JOIN companies c ON c.id = b.company_id WHERE b.id = ? AND c.status = 'active'");
            $s->execute([(int) ($b['brand'] ?? 0)]);
            $br = $s->fetch();
            if ($br && rate('search', 150, 3600, '', true)) {
                log_search('map', ['brand' => (int) $br['id'], 'zone' => $b['zone'] ?? '', 'lat' => $b['lat'] ?? null, 'lng' => $b['lng'] ?? null,
                    'results' => max(0, (int) ($b['results'] ?? 0)), 'nearest_km' => is_numeric($b['nearest_km'] ?? null) ? (float) $b['nearest_km'] : null], [(int) $br['company_id']]);
            }
            ok();
        }

        /* ----------------------------------------------------------- comptes */

        case 'me': {
            $u = current_user();
            if (!$u) ok(['user' => null]);
            ok(me_view($u, (string) $u['s_csrf']));
        }

        case 'signup': {
            rate('signup', 6, 3600);
            if (!empty($b['website'])) fail('Inscription refusée.');
            $kind = isset(MLG_KINDS[$b['kind'] ?? '']) ? (string) $b['kind'] : '';
            $company = line_clean($b['company'] ?? '', 80);
            $name = line_clean($b['name'] ?? '', 80);
            $email = strtolower(line_clean($b['email'] ?? '', 190));
            $phone = phone_clean($b['phone'] ?? '');
            if ($kind === '') fail('Choisissez votre profil : entreprise / marque, distributeur ou point de vente.');
            $pos = $kind === 'pos';
            if (mb_strlen($company) < 2) fail($pos ? 'Indiquez le nom de votre point de vente.' : 'Indiquez le nom de votre entreprise.');
            if (mb_strlen($name) < 2) fail('Indiquez votre nom.');
            if (!valid_email($email)) fail('Adresse email invalide.');
            if (!phone_ok($phone)) fail('Indiquez un numéro de téléphone valide.');
            $pass = password_in($b['password'] ?? '');
            $zone = zone_code($b['wilaya'] ?? '');
            $commune = $pos ? commune_clean($zone, $b['commune'] ?? '') : '';
            if ($pos && ($zone === '' || $commune === '')) fail('Choisissez la wilaya puis la commune de votre point de vente.');
            if (empty($b['accept'])) fail('Merci d\'accepter les conditions d\'utilisation.');
            $s = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
            $s->execute([$email]);
            if ($s->fetchColumn()) fail('Un compte existe déjà avec cette adresse. Connectez-vous.', 409, 'exists');
            $sizes = ['1', '2-5', '6-20', '21+'];
            tx_begin();
            [$cid, $uid] = account_create([
                'kind' => $kind, 'company' => $company, 'name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $pass,
                'wilaya' => $zone, 'city' => $pos ? $commune : line_clean($b['city'] ?? '', 80),
                'sector' => isset(MLG_CATEGORIES[$b['sector'] ?? '']) ? (string) $b['sector'] : '',
                'size' => in_array($b['size'] ?? '', $sizes, true) ? (string) $b['size'] : '',
                'status' => moderation_on() ? 'pending' : 'active',
            ]);
            tx_commit();
            // Une marque démarre avec sa marque déjà créée : une étape de moins.
            if ($kind === 'brand') brand_save($cid, ['name' => $company, 'category' => $b['sector'] ?? 'autre']);
            if ($pos) {
                // Un point de vente démarre avec sa fiche et ses demandes aux marques choisies. Sans position
                // donnée, il est rangé au chef-lieu de sa wilaya en attendant qu'il place son épingle.
                $lat = coord($b['lat'] ?? null, 90);
                $lng = coord($b['lng'] ?? null, 180);
                $located = $lat !== null && $lng !== null && !($lat == 0.0 && $lng == 0.0);
                $z = zones()[$zone];
                store_save($cid, [
                    'name' => $company, 'type' => 'magasin', 'wilaya' => $zone, 'commune' => $commune, 'city' => $commune, 'address' => line_clean($b['address'] ?? '', 200),
                    'lat' => $located ? $lat : (float) $z['lat'], 'lng' => $located ? $lng : (float) $z['lng'], 'phone' => $phone, 'email' => $email,
                    'hours' => ['open' => '09:00', 'close' => '19:00', 'days' => [0, 1, 2, 3, 4, 6]], 'pickup' => 1, 'delivery' => 1, 'reserve' => 1,
                ], ['unlocated' => !$located]);
                $q = $pdo->prepare('SELECT company_id FROM brands WHERE id = ?');
                $asked = [];
                foreach (array_slice((array) ($b['brands'] ?? []), 0, 20) as $bid) {
                    $q->execute([(int) $bid]);
                    $bc = (int) $q->fetchColumn();
                    if (!$bc || isset($asked[$bc])) continue;
                    $asked[$bc] = true;
                    try { partner_request($cid, 'pos', $bc, true); } catch (InvalidArgumentException $e) { /* marque indisponible : il la demandera depuis son espace */ }
                }
            }
            $csrf = start_session($uid);
            try_mail($email, 'Bienvenue sur ' . MLG_NAME, 'Votre espace est créé', [
                'Bonjour ' . $name . ',',
                $pos ? 'L\'espace de ' . $company . ' est prêt. Indiquez les produits que vous avez en stock : les commandes des clients proches de chez vous arrivent dans votre radar.'
                    : 'L\'espace de ' . $company . ' est prêt. Ajoutez vos points de vente, votre catalogue et la disponibilité de vos produits.',
                moderation_on() ? 'Votre réseau apparaîtra sur la carte publique dès que nous aurons validé votre inscription.' : 'Votre réseau apparaît sur la carte publique dès que vos points de vente sont enregistrés.',
            ], 'Ouvrir mon espace', site_url() . 'espace/');
            if (($to = setting('notify_email')) !== '') {
                try_mail($to, 'Nouvelle inscription : ' . $company, 'Nouvelle inscription sur ' . MLG_NAME, [
                    $company . ' — ' . MLG_KINDS[$kind],
                    $name . ' · ' . $email . ' · ' . $phone,
                    moderation_on() ? 'Cette inscription attend votre validation.' : 'Cette inscription est publiée automatiquement.',
                ], 'Ouvrir l\'administration', site_url() . 'admin.html');
            }
            $s = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $s->execute([$uid]);
            ok(me_view($s->fetch(), $csrf));
        }

        case 'login': {
            rate('login', 12, 900);
            $email = strtolower(line_clean($b['email'] ?? '', 190));
            $pass = is_string($b['password'] ?? null) ? (string) $b['password'] : '';
            rate('login', 8, 900, 'e:' . substr(hash('sha256', $email), 0, 24));
            $s = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $s->execute([$email]);
            $u = $s->fetch();
            if (!$u || $u['pass'] === '' || !password_verify($pass, (string) $u['pass'])) fail('Email ou mot de passe incorrect.', 401, 'credentials');
            if (password_needs_rehash((string) $u['pass'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            }
            ok(me_view($u, start_session((int) $u['id'])));
        }

        case 'demo_login': {
            rate('demo', 30, 3600);
            $kind = in_array($b['kind'] ?? '', ['distributor', 'store'], true) ? (string) $b['kind'] : 'brand';
            if ($kind === 'store') {
                // Espace privé d'un point de vente de la démonstration.
                $s = $pdo->query("SELECT u.* FROM users u JOIN companies c ON c.id = u.company_id WHERE c.demo = 1 AND u.store_id > 0 AND c.status = 'active' ORDER BY u.id LIMIT 1");
            } else {
                $s = $pdo->prepare("SELECT u.* FROM users u JOIN companies c ON c.id = u.company_id WHERE c.demo = 1 AND c.kind = ? AND u.store_id = 0 AND c.status = 'active' ORDER BY c.id, u.id LIMIT 1");
                $s->execute([$kind]);
            }
            $u = $s->fetch();
            if (!$u) fail('La démonstration n\'est pas disponible pour le moment.', 404, 'notfound');
            ok(me_view($u, start_session((int) $u['id'], true)) + ['readonly' => true]);
        }

        case 'logout':
            end_session();
            ok();

        case 'forgot': {
            rate('forgot', 5, 3600);
            $email = strtolower(line_clean($b['email'] ?? '', 190));
            $s = $pdo->prepare('SELECT u.*, c.demo AS c_demo FROM users u JOIN companies c ON c.id = u.company_id WHERE u.email = ?');
            $s->execute([$email]);
            $u = $s->fetch();
            if ($u && !(int) $u['c_demo']) {
                $tok = token(24);
                $pdo->prepare('UPDATE users SET rhash = ?, rexp = ? WHERE id = ?')->execute([hash('sha256', $tok), time() + 3600, $u['id']]);
                try_mail($email, 'Nouveau mot de passe ' . MLG_NAME, 'Choisir un nouveau mot de passe', [
                    'Vous avez demandé à changer le mot de passe de votre espace. Ce lien est valable une heure.',
                    'Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet email.',
                ], 'Choisir un nouveau mot de passe', site_url() . 'espace/#/reset/' . $tok);
            }
            ok(); // même réponse que le compte existe ou non
        }

        case 'reset': {
            rate('reset', 10, 3600);
            $tok = (string) ($b['token'] ?? '');
            if (!preg_match('/^[a-f0-9]{48}$/', $tok)) fail('Ce lien n\'est plus valable. Demandez-en un nouveau.');
            $pass = password_in($b['password'] ?? '');
            $s = $pdo->prepare('SELECT * FROM users WHERE rhash = ? AND rexp > ?');
            $s->execute([hash('sha256', $tok), time()]);
            $u = $s->fetch();
            if (!$u) fail('Ce lien n\'est plus valable. Demandez-en un nouveau.');
            $pdo->prepare('UPDATE users SET pass = ?, rhash = NULL, rexp = 0 WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
            $pdo->prepare('DELETE FROM sessions WHERE uid = ?')->execute([$u['id']]);
            ok(me_view($u, start_session((int) $u['id'])));
        }

        /* ---------------------------------------------------- espace entreprise */

        case 'overview': {
            $u = require_user();
            if ($sid = user_store($u)) ok(store_overview((int) $u['company_id'], $sid));
            ok(dash_overview((int) $u['company_id'], (string) $u['c_kind']));
        }

        case 'brands': {
            $u = require_company();
            ok(['brands' => brands_list((int) $u['company_id'])]);
        }
        case 'brand_save': {
            $u = require_company();
            ok(['brand' => brand_save((int) $u['company_id'], $b)]);
        }
        case 'brand_delete': {
            $u = require_company();
            brand_delete((int) $u['company_id'], (int) ($b['id'] ?? 0));
            ok();
        }

        case 'dash_products': {
            $u = require_company();
            ok(['products' => dash_products((int) $u['company_id']), 'brands' => brands_list((int) $u['company_id'])]);
        }
        case 'dash_product': {
            $u = require_company();
            $p = dash_product((int) $u['company_id'], (int) g('id'));
            if (!$p) fail('Produit introuvable.', 404, 'notfound');
            ok(['product' => $p]);
        }
        case 'product_save': {
            $u = require_company();
            ok(['product' => product_save((int) $u['company_id'], $b)]);
        }
        case 'product_delete': {
            $u = require_company();
            product_delete((int) $u['company_id'], (int) ($b['id'] ?? 0));
            ok();
        }

        case 'upload': {
            $u = require_company();
            rate('upload', 200, 3600, 'c' . $u['company_id']);
            $f = $_FILES['file'] ?? null;
            if (!$f || !is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                fail('L\'envoi a échoué. L\'image fait peut-être plus de ' . (int) (MEDIA_MAX_BYTES / 1048576) . ' Mo.');
            }
            if ((int) $f['size'] > MEDIA_MAX_BYTES) fail('Image trop lourde (' . (int) (MEDIA_MAX_BYTES / 1048576) . ' Mo maximum).');
            ok(['path' => image_ingest((string) $f['tmp_name'], (int) $u['company_id'])]);
        }

        case 'dash_stores': {
            $u = require_user();
            ok(['stores' => dash_stores((int) $u['company_id'], user_store($u))]);
        }
        case 'store_save': {
            $u = require_user();
            $sid = user_store($u);
            if ($sid) $b['id'] = $sid;
            ok(['store' => store_save((int) $u['company_id'], $b, ['strict' => true, 'only' => $sid])]);
        }
        case 'store_delete': {
            $u = require_company();
            store_delete((int) $u['company_id'], (int) ($b['id'] ?? 0));
            ok();
        }

        case 'stock': {
            $u = require_user();
            ok(['products' => stock_get((int) $u['company_id'], (string) $u['c_kind'], user_store($u) ?: (int) g('store'))]);
        }
        case 'stock_save': {
            $u = require_user();
            ok(['saved' => stock_save((int) $u['company_id'], (string) $u['c_kind'], user_store($u) ?: (int) ($b['store_id'] ?? 0), (array) ($b['items'] ?? []))]);
        }

        case 'orders': {
            $u = require_user();
            ok(dash_orders((int) $u['company_id'], ['status' => g('status') ?: 'open', 'store' => (int) g('store'), 'q' => g('q'), 'only' => user_store($u)]));
        }
        case 'order_get': {
            $u = require_user();
            $cid = (int) $u['company_id'];
            $o = own_order($cid, (int) g('id'), user_store($u));
            if (!$o) fail('Commande introuvable.', 404, 'notfound');
            $view = order_view($o, true);
            $mine = (int) $o['seller_cid'] === $cid;
            // Commande radar prise par le point de vente d'un partenaire : la marque la suit sans la modifier.
            if (!$mine) $view['next'] = [];
            $view['foreign'] = !$mine;
            $view['can_release'] = $mine && (int) $o['radar'] && (int) $o['store_id'] && $o['status'] === 'confirmed';
            ok(['order' => $view]);
        }
        case 'order_status': {
            $u = require_user();
            $cid = (int) $u['company_id'];
            $o = own_order($cid, (int) ($b['id'] ?? 0), user_store($u));
            if (!$o) fail('Commande introuvable.', 404, 'notfound');
            if ((int) $o['seller_cid'] !== $cid) fail('Cette commande est traitée par le point de vente qui l\'a prise.', 403, 'forbidden');
            $o = order_set_status($o, (string) ($b['status'] ?? ''), (string) ($b['note'] ?? ''), 'store');
            order_notify_status($o);
            $view = order_view($o, true);
            $view['foreign'] = false;
            $view['can_release'] = (int) $o['radar'] && (int) $o['store_id'] && $o['status'] === 'confirmed';
            ok(['order' => $view]);
        }

        /* ---------------------------------------------------------------- radar */

        case 'radar': {
            $u = require_user();
            $cid = (int) $u['company_id'];
            $sid = user_store($u);
            ok(['nearby' => radar_nearby($cid, $sid), 'received' => $sid ? [] : radar_received($cid), 'step_min' => intdiv(radar_step(), 60),
                'research' => research_nearby($cid, (string) $u['c_kind'], $sid)]);
        }
        case 'radar_claim': {
            $u = require_user();
            rate('claim', 120, 600, 'u' . $u['id']);
            $res = radar_claim((int) $u['company_id'], (int) ($b['id'] ?? 0), user_store($u) ?: (int) ($b['store_id'] ?? 0));
            radar_notify_claimed($res);
            ok(['order' => order_view($res['order'], true)]);
        }
        case 'radar_release': {
            $u = require_user();
            $cid = (int) $u['company_id'];
            $o = own_order($cid, (int) ($b['id'] ?? 0), user_store($u));
            if (!$o || (int) $o['seller_cid'] !== $cid) fail('Commande introuvable.', 404, 'notfound');
            radar_release($o, (string) ($b['note'] ?? ''));
            ok();
        }

        // Recherches actives des clients : « j'ai ce produit » ou « je ne l'ai pas ».
        case 'research_reply': {
            $u = require_user();
            $cid = (int) $u['company_id'];
            $sid = user_store($u);
            $item = research_reply($cid, (string) $u['c_kind'], (int) ($b['id'] ?? 0), $b, $sid);
            research_notify_reply((int) $item['id'], $cid, (int) $item['replied']['store_id']);
            ok(['research' => $item]);
        }
        case 'research_dismiss': {
            $u = require_user();
            research_dismiss((int) $u['company_id'], (int) ($b['id'] ?? 0), user_store($u));
            ok();
        }

        case 'network': {
            $u = require_company();
            ok(network_get((int) $u['company_id'], (string) $u['c_kind']));
        }
        case 'partner_request': {
            $u = require_company();
            partner_request((int) $u['company_id'], (string) $u['c_kind'], (int) ($b['company_id'] ?? 0));
            ok();
        }
        case 'partner_respond': {
            $u = require_company();
            partner_respond((int) $u['company_id'], (string) $u['c_kind'], (int) ($b['id'] ?? 0), !empty($b['accept']));
            ok();
        }
        case 'partner_remove': {
            $u = require_company();
            partner_remove((int) $u['company_id'], (string) $u['c_kind'], (int) ($b['id'] ?? 0));
            ok();
        }

        case 'analytics': {
            $u = require_company();
            ok(analytics((int) $u['company_id'], (string) $u['c_kind'], (int) g('days')));
        }

        case 'company_save': {
            $u = require_company();
            ok(['company' => company_save((int) $u['company_id'], $b)]);
        }
        case 'account_save': {
            $u = require_user();
            $name = line_clean($b['name'] ?? '', 80);
            $email = strtolower(line_clean($b['email'] ?? '', 190));
            if (mb_strlen($name) < 2) fail('Indiquez votre nom.');
            if (!valid_email($email)) fail('Adresse email invalide.');
            $s = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id <> ?');
            $s->execute([$email, $u['id']]);
            if ($s->fetchColumn()) fail('Cette adresse est déjà utilisée par un autre compte.', 409, 'exists');
            $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?')->execute([$name, $email, phone_clean($b['phone'] ?? ''), $u['id']]);
            ok(['user' => ['id' => (int) $u['id'], 'name' => $name, 'email' => $email, 'phone' => phone_clean($b['phone'] ?? ''), 'store_id' => user_store($u)]]);
        }
        case 'password': {
            $u = require_user();
            rate('password', 8, 900, 'u' . $u['id']);
            if ($u['pass'] !== '' && !password_verify(is_string($b['current'] ?? null) ? $b['current'] : '', (string) $u['pass'])) fail('Le mot de passe actuel est incorrect.');
            $pdo->prepare('UPDATE users SET pass = ? WHERE id = ?')->execute([password_hash(password_in($b['password'] ?? ''), PASSWORD_DEFAULT), $u['id']]);
            // Les autres appareils sont déconnectés.
            $pdo->prepare('DELETE FROM sessions WHERE uid = ? AND hash <> ?')->execute([$u['id'], hash('sha256', (string) ($_COOKIE[SID_COOKIE] ?? ''))]);
            ok();
        }

        /* ----------------------------------------------------- administration */

        case 'adm_overview': {
            require_admin();
            $one = function (string $sql) use ($pdo): int { return (int) $pdo->query($sql)->fetchColumn(); };
            $rows = $pdo->query("SELECT c.*, u.name AS u_name, u.email AS u_email, u.phone AS u_phone, u.last_seen AS u_seen,
                    (SELECT COUNT(*) FROM stores s WHERE s.company_id = c.id AND s.status <> 'archived') AS n_stores,
                    (SELECT COUNT(*) FROM products p WHERE p.company_id = c.id) AS n_products,
                    (SELECT COUNT(*) FROM orders o WHERE o.seller_cid = c.id) AS n_orders
                FROM companies c LEFT JOIN users u ON u.id = (SELECT MIN(id) FROM users WHERE company_id = c.id AND store_id = 0)
                ORDER BY c.demo, CASE c.status WHEN 'pending' THEN 0 ELSE 1 END, c.created DESC")->fetchAll();
            $list = [];
            foreach ($rows as $c) {
                $list[] = company_view($c) + ['size' => (string) $c['size'], 'zone' => zone_name((string) $c['wilaya']),
                    'contact' => ['name' => (string) $c['u_name'], 'email' => (string) $c['u_email'], 'phone' => (string) $c['u_phone'], 'seen' => (int) $c['u_seen']],
                    'stores' => (int) $c['n_stores'], 'products' => (int) $c['n_products'], 'orders' => (int) $c['n_orders']];
            }
            $week = time() - 7 * 86400;
            ok([
                'companies' => $list,
                'counts' => [
                    'companies' => $one('SELECT COUNT(*) FROM companies WHERE demo = 0'),
                    'pending' => $one("SELECT COUNT(*) FROM companies WHERE demo = 0 AND status = 'pending'"),
                    'new7' => $one("SELECT COUNT(*) FROM companies WHERE demo = 0 AND created >= $week"),
                    'stores' => $one("SELECT COUNT(*) FROM stores s JOIN companies c ON c.id = s.company_id WHERE c.demo = 0 AND s.status = 'active'"),
                    'products' => $one('SELECT COUNT(*) FROM products p JOIN companies c ON c.id = p.company_id WHERE c.demo = 0'),
                    'orders' => $one('SELECT COUNT(*) FROM orders o JOIN companies c ON c.id = o.seller_cid WHERE c.demo = 0'),
                    'searches7' => $one("SELECT COUNT(*) FROM searches WHERE created >= $week"),
                    'research' => $one("SELECT COUNT(*) FROM research WHERE demo = 0 AND status = 'open'"),
                ],
                'settings' => ['moderation' => moderation_on(), 'notify_email' => setting('notify_email'), 'radar_step_min' => intdiv(radar_step(), 60)],
                'demo' => demo_exists(),
                'mail' => MLG_SMTP_HOST !== '',
                'import' => import_algerie_status(),
            ]);
        }

        // Base Algérie (sources publiques) : import ou mise à jour des fiches publiques.
        case 'adm_import': {
            require_admin();
            ok(['result' => import_algerie(), 'import' => import_algerie_status()]);
        }

        case 'adm_company': {
            require_admin();
            $c = company_row((int) ($b['id'] ?? 0));
            if (!$c) fail('Entreprise introuvable.', 404, 'notfound');
            $act = (string) ($b['action'] ?? '');
            if ($act === 'delete') {
                company_delete((int) $c['id']);
            } elseif ($act === 'activate' || $act === 'suspend') {
                $new = $act === 'activate' ? 'active' : 'suspended';
                $pdo->prepare('UPDATE companies SET status = ? WHERE id = ?')->execute([$new, $c['id']]);
                if ($new === 'active' && $c['status'] === 'pending' && !(int) $c['demo']) {
                    $s = $pdo->prepare('SELECT email, name FROM users WHERE company_id = ? AND store_id = 0 ORDER BY id LIMIT 1');
                    $s->execute([$c['id']]);
                    if ($usr = $s->fetch()) {
                        try_mail((string) $usr['email'], 'Votre espace ' . MLG_NAME . ' est validé', 'Votre réseau est en ligne', [
                            'Bonjour ' . $usr['name'] . ',',
                            'L\'inscription de ' . $c['name'] . ' est validée : vos points de vente et vos produits apparaissent maintenant sur la plateforme.',
                        ], 'Ouvrir mon espace', site_url() . 'espace/');
                    }
                }
            } else {
                fail('Action inconnue.');
            }
            ok();
        }

        case 'adm_open': {
            require_admin();
            $s = $pdo->prepare('SELECT * FROM users WHERE company_id = ? AND store_id = 0 ORDER BY id LIMIT 1');
            $s->execute([(int) ($b['id'] ?? 0)]);
            $u = $s->fetch();
            if (!$u) fail('Entreprise introuvable.', 404, 'notfound');
            start_session((int) $u['id'], false, true);
            ok();
        }

        case 'adm_demo': {
            require_admin();
            if (($b['action'] ?? '') === 'delete') {
                ok(['deleted' => demo_delete()]);
            }
            if (demo_exists()) fail('La démonstration est déjà chargée.');
            seed_demo();
            ok();
        }

        case 'adm_settings': {
            require_admin();
            $email = strtolower(line_clean($b['notify_email'] ?? '', 190));
            if ($email !== '' && !valid_email($email)) fail('Adresse email invalide.');
            setting_set('moderation', !empty($b['moderation']) ? '1' : '0');
            setting_set('notify_email', $email);
            if (isset($b['radar_step_min']) && is_numeric($b['radar_step_min'])) setting_set('radar_step_min', (string) max(0, min(1440, (int) $b['radar_step_min'])));
            ok();
        }
    }
} catch (InvalidArgumentException $e) {
    tx_rollback();
    fail($e->getMessage());
} catch (Throwable $e) {
    tx_rollback();
    error_log('maillage api ' . $action . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    fail('Une erreur est survenue. Réessayez dans un instant.', 500, 'server');
}

fail('Action inconnue.', 404, 'notfound');
