"""
Prépare la « base Algérie » (produits, marques, points de vente relevés dans des sources publiques le
13 novembre 2026) pour Maillage : lit le dossier livré (csv/ et photos/) et écrit
maillage/lib/data/import/algerie.json + les photos dans maillage/lib/data/import/algerie/.
L'import dans le site se fait ensuite depuis l'administration (lib/import.php).

Usage : python3 tools/import/prepare_algerie.py <dossier contenant csv/ et photos/>
Positions : géocodées avec OpenStreetMap (Nominatim) puis vérifiées à la main (GEO ci-dessous).
"""
import csv, json, os, shutil, sys

SRC = sys.argv[1]
HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(os.path.dirname(os.path.dirname(HERE)), 'maillage', 'lib', 'data', 'import')

def rd(f):
    return list(csv.DictReader(open(os.path.join(SRC, 'csv', f), encoding='utf-8-sig'), delimiter=';'))

ZONES = {'Alger': '16', 'Oran': '31', 'Constantine': '25', 'Sétif': '19'}
CATS = {'C001': 'high-tech', 'C002': 'mode'}
# Commune de la liste officielle de Maillage (la source donne parfois un quartier ou la ville).
COMMUNES = {'S002': 'El Khroub', 'S003': 'Setif', 'S009': 'Oran', 'S012': 'Dely Ibrahim', 'S013': 'El Kerma', 'S016': 'El Kerma',
            'S018': 'Cheraga', 'S019': 'Douira', 'S020': 'Oran', 'S021': 'Oran', 'S022': 'El Khroub', 'S023': 'Cheraga', 'S024': 'Setif'}
# lat, lng, précision : lieu (le centre commercial ou le magasin), rue, commune (centre de la commune : approximatif).
GEO = {
    'S001': (36.728993, 3.179501, 'lieu'), 'S002': (36.251021, 6.572698, 'lieu'), 'S003': (36.190823, 5.409202, 'lieu'),
    'S004': (35.638041, -0.590721, 'lieu'), 'S005': (36.760103, 3.013075, 'lieu'), 'S006': (36.7404, 3.143527, 'lieu'),
    'S007': (36.712133, 3.196851, 'lieu'), 'S008': (36.75039, 2.952846, 'lieu'), 'S009': (35.688555, -0.622575, 'rue'),
    'S010': (36.7404, 3.143527, 'lieu'), 'S011': (36.712133, 3.196851, 'lieu'), 'S012': (36.75039, 2.952846, 'lieu'),
    'S013': (35.638041, -0.590721, 'lieu'), 'S014': (36.251021, 6.572698, 'lieu'), 'S015': (36.760103, 3.013075, 'rue'),
    'S016': (35.638041, -0.590721, 'lieu'), 'S017': (36.735525, 2.950906, 'commune'), 'S018': (36.762564, 2.956314, 'rue'),
    'S019': (36.669409, 2.942103, 'commune'), 'S020': (35.704442, -0.650298, 'commune'), 'S021': (35.704442, -0.650298, 'commune'),
    'S022': (36.253904, 6.575866, 'commune'), 'S023': (36.766927, 2.960257, 'commune'), 'S024': (36.189349, 5.404688, 'commune'),
}
# Entreprise propriétaire de chaque marque (les marques maison Decathlon appartiennent à Decathlon).
DECATHLON = {'Decathlon', 'Domyos', 'Forclaz', 'Kalenji', 'Kiprun', 'Kipsta', 'Quechua', 'Simond', 'Tarmak', 'Wedze'}
OWNER = {'Redmi': 'Xiaomi'}
COLORS = {'Apple': '#1d1d1f', 'Samsung': '#1428a0', 'Xiaomi': '#ff6900', 'OPPO': '#1ba784', 'HONOR': '#0f4fd6', 'realme': '#e8b400',
          'OnePlus': '#eb0029', 'Google': '#4285f4', 'Nothing': '#111111', 'Infinix': '#0a7c5a', 'vivo': '#415fff', 'Tecno': '#0046be',
          'Huawei': '#c7000b', 'Itel': '#e60012', 'ZTE': '#0066b3', 'Kiabi': '#00a4b4', 'Lufian': '#222222', 'Decathlon': '#0082c3'}

brands = rd('brands.csv')
bname = {b['brand_id']: b['name'] for b in brands}
stores = rd('stores.csv')
products = rd('products.csv')
offers = rd('online_offers.csv')
store_brands = rd('store_brands.csv')
images = {i['product_id']: i for i in rd('product_images.csv')}

companies = {}
def company(name, kind):
    if name not in companies:
        companies[name] = {'ext': 'DZ-' + name.lower().replace(' ', '-').replace('.', '').replace('é', 'e'), 'name': name, 'kind': kind,
                           'website': '', 'email': '', 'phone': '', 'src': '', 'checked': '2026-11-13', 'wilaya': '', 'city': '', 'notes': ''}
    return companies[name]

out_brands = []
for b in brands:
    owner = 'Decathlon' if b['name'] in DECATHLON else OWNER.get(b['name'], b['name'])
    c = company(owner, 'brand')
    if b['name'] == owner:
        c['website'] = c['website'] or b['official_website_url']
        c['src'] = c['src'] or b['source_url']
    out_brands.append({'ext': b['brand_id'], 'company': c['ext'], 'name': b['name'], 'website': b['official_website_url'],
                       'src': b['source_url'], 'color': COLORS.get(b['name'], COLORS.get(owner, '')), 'notes': b['notes']})

out_stores = []
for s in stores:
    kind = 'brand' if s['business_name'] in ('Kiabi', 'Decathlon', 'Lufian') else 'distributor'
    c = company(s['business_name'], kind)
    for k, f in (('website', 'website_url'), ('email', 'email'), ('phone', 'phone')):
        if not c[k] and s[f]:
            c[k] = s[f]
    if s['type'] == 'en_ligne':
        c['online'] = s['website_url']
        c['src'] = c['src'] or s['source_url']
        continue
    lat, lng, prec = GEO[s['store_id']]
    zone = ZONES[s['target_area']]
    if not c['wilaya']:
        c['wilaya'], c['city'] = zone, s['city']
    c['src'] = c['src'] or s['source_url']
    out_stores.append({'ext': s['store_id'], 'company': c['ext'], 'name': s['name'], 'address': s['address'], 'city': s['city'] or s['commune'],
                       'wilaya': zone, 'commune': COMMUNES.get(s['store_id'], s['commune']), 'lat': lat, 'lng': lng, 'precision': prec,
                       'phone': s['phone'], 'email': s['email'], 'src': s['source_url'], 'source_kind': s['source_kind'],
                       'checked': s['verified_on'], 'notes': s['notes']})

ext_of_store = {s['store_id']: s for s in stores}
out_products = []
os.makedirs(os.path.join(OUT, 'algerie'), exist_ok=True)
for p in products:
    owner = 'Decathlon' if bname[p['brand_id']] in DECATHLON else OWNER.get(bname[p['brand_id']], bname[p['brand_id']])
    img = images.get(p['product_id'])
    photo = ''
    if img and img['local_path']:
        photo = p['product_id'] + '.jpg'
        shutil.copyfile(os.path.join(SRC, img['local_path']), os.path.join(OUT, 'algerie', photo))
    specs = [{'k': 'Type', 'v': p['subcategory']}]
    if p['catalog_title'] and p['catalog_title'] != p['name']:
        specs.append({'k': 'Référence vendeur', 'v': p['catalog_title']})
    out_products.append({'ext': p['product_id'], 'company': companies[owner]['ext'], 'brand': p['brand_id'], 'name': p['name'],
                         'category': CATS[p['category_id']], 'about': p['description_fr'], 'specs': specs, 'photo': photo,
                         'photo_src': img['image_url'] if img else '', 'official': p['official_product_url'], 'src': p['source_url'],
                         'checked': p['verified_on'], 'notes': p['notes']})

out_offers = [{'ext': o['offer_id'], 'product': o['product_id'], 'company': companies[ext_of_store[o['store_id']]['business_name']]['ext'],
               'url': o['product_url'], 'status': o['availability_status'], 'checked': o['verified_on']} for o in offers]
out_sb = [{'store': r['store_id'], 'brand': r['brand_id']} for r in store_brands if ext_of_store[r['store_id']]['type'] == 'physique']

data = {'source': 'Base produits et points de vente — Algérie (sources publiques)', 'checked': '2026-11-13',
        'companies': list(companies.values()), 'brands': out_brands, 'stores': out_stores, 'products': out_products,
        'offers': out_offers, 'store_brands': out_sb}
with open(os.path.join(OUT, 'algerie.json'), 'w', encoding='utf-8') as fh:
    json.dump(data, fh, ensure_ascii=False, indent=1)
print({k: len(v) for k, v in data.items() if isinstance(v, list)})
