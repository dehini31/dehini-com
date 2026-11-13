"""
Prépare la reconnaissance d'image de la recherche par photo (Maillage), exécutée dans le navigateur
du client, sans service extérieur : écrit maillage/assets/vision/
  - model.onnx      encodeur d'image de TinyCLIP-ViT-40M-32 (Microsoft, licence MIT) : couches denses en
                    4 bits (MatMulNBits), autres poids en float16 (≈ 26 Mo) ;
  - vocab.json      types de produits, marques et couleurs (vocab.py) avec leurs vecteurs de texte,
                    calculés ici une fois pour toutes (le navigateur n'a pas besoin du modèle de texte) ;
  - ort.wasm.min.js, ort-wasm-simd-threaded.mjs, ort-wasm-simd-threaded.wasm : ONNX Runtime Web.

Usage : python3 tools/vision/build.py [--test image …]
Dépendances (machine de développement seulement) : pip install onnx onnx_ir onnxruntime tokenizers numpy pillow ; npm.
"""
import base64, hashlib, json, os, subprocess, sys, tarfile, tempfile, urllib.request
import numpy as np
import onnx, onnxruntime as ort
from onnx import helper, numpy_helper, TensorProto
from tokenizers import Tokenizer

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
OUT = os.path.join(REPO, 'maillage', 'assets', 'vision')
CACHE = os.environ.get('VISION_CACHE', os.path.join(tempfile.gettempdir(), 'maillage-vision'))
HF = 'https://huggingface.co/onnx-community/TinyCLIP-ViT-40M-32-Text-19M-LAION400M-ONNX/resolve/main/'
SIZE = 224
MEAN = [0.48145466, 0.4578275, 0.40821073]
STD = [0.26862954, 0.26130258, 0.27577711]
ORT_VERSION = '1.22.0'
sys.path.insert(0, HERE)
from vocab import TYPES, BRANDS, COLORS, OTHER  # noqa: E402

def fetch(name):
    os.makedirs(CACHE, exist_ok=True)
    dst = os.path.join(CACHE, os.path.basename(name))
    if not os.path.exists(dst):
        print('téléchargement', name)
        urllib.request.urlretrieve(HF + name, dst + '.part')
        os.replace(dst + '.part', dst)
    return dst

def split(src):
    """Le modèle publié réunit texte et image : on en tire l'encodeur d'image et celui du texte."""
    from onnx.utils import Extractor
    vision, text = os.path.join(CACHE, 'vision.onnx'), os.path.join(CACHE, 'text.onnx')
    if not (os.path.exists(vision) and os.path.exists(text)):
        e = Extractor(onnx.load(src))
        onnx.save(e.extract_model(['pixel_values'], ['image_embeds']), vision)
        onnx.save(e.extract_model(['input_ids', 'attention_mask'], ['text_embeds']), text)
    return vision, text

def compress(src, dst):
    from onnxruntime.quantization.matmul_nbits_quantizer import MatMulNBitsQuantizer
    q = MatMulNBitsQuantizer(onnx.load(src), block_size=32, is_symmetric=True)
    q.process()
    m = q.model.model
    g = m.graph
    inits, pre = [], []
    for init in list(g.initializer):
        a = numpy_helper.to_array(init)
        if a.dtype != np.float32 or a.size < 1024:
            inits.append(init)
            continue
        inits.append(numpy_helper.from_array(a.astype(np.float16), init.name + '__h'))
        pre.append(helper.make_node('Cast', [init.name + '__h'], [init.name], to=TensorProto.FLOAT))
    del g.initializer[:]
    g.initializer.extend(inits)
    nodes = list(g.node)
    del g.node[:]
    g.node.extend(pre + nodes)
    onnx.save(m, dst)

class Text:
    def __init__(self, path):
        self.tok = Tokenizer.from_file(fetch('tokenizer.json'))
        self.s = ort.InferenceSession(path)

    def one(self, t):
        ids = self.tok.encode(t).ids[:77]
        feed = {'input_ids': np.array([ids], dtype=np.int64), 'attention_mask': np.ones((1, len(ids)), dtype=np.int64)}
        e = self.s.run(None, feed)[0][0]
        return e / np.linalg.norm(e)

    def mean(self, texts):
        e = np.mean([self.one(t) for t in texts], axis=0)
        return e / np.linalg.norm(e)

def f16(vectors):
    return base64.b64encode(np.asarray(vectors, dtype=np.float16).tobytes()).decode()

def image_vecs(sess, path):
    """Les deux vues de vision.js : le centre recadré et la photo entière bordée de blanc."""
    from PIL import Image
    im = Image.open(path).convert('RGB')
    w, h = im.size
    m, M = min(w, h), max(w, h)
    center = im.crop(((w - m) // 2, (h - m) // 2, (w - m) // 2 + m, (h - m) // 2 + m))
    whole = Image.new('RGB', (M, M), (255, 255, 255))
    whole.paste(im, ((M - w) // 2, (M - h) // 2))
    out = []
    mean, std = np.array(MEAN, np.float32).reshape(3, 1, 1), np.array(STD, np.float32).reshape(3, 1, 1)
    for v in (center, whole):
        a = (np.asarray(v.resize((SIZE, SIZE), Image.BICUBIC), dtype=np.float32) / 255.0).transpose(2, 0, 1)
        e = sess.run(None, {'pixel_values': ((a - mean) / std)[None]})[0][0]
        out.append(e / np.linalg.norm(e))
    return out

def main():
    os.makedirs(OUT, exist_ok=True)
    model = os.path.join(OUT, 'model.onnx')
    vision, text_path = split(fetch('onnx/model.onnx'))
    compress(vision, model)
    text = Text(text_path)
    types = [text.mean([f'a photo of {en}.', f'a product photo of {en}.', f'{en} for sale in a shop.']) for _, en, _, _ in TYPES]
    other = [text.mean([f'a photo of {x}.']) for x in OTHER]
    brands = [text.mean([f'a photo of a product with the brand name "{b}".', f'the {b} logo.']) for b, _, _ in BRANDS]
    colors = [text.mean([f'a photo of a {c} object.', f'a {c} product.']) for c, _, _ in COLORS]
    vocab = {
        'dim': 512,
        'size': SIZE, 'mean': MEAN, 'std': STD,
        'types': [{'cat': c, 'fr': fr, 'ar': ar} for c, _, fr, ar in TYPES],
        'other': len(OTHER),
        'brands': [{'name': b, 'cat': c, 'fr': t[0] if t else '', 'ar': t[1] if t else ''} for b, c, t in BRANDS],
        'colors': [{'fr': fr, 'ar': ar} for _, fr, ar in COLORS],
        'vec': {'types': f16(types + other), 'brands': f16(brands), 'colors': f16(colors)},
    }
    with open(os.path.join(OUT, 'vocab.json'), 'w') as fh:
        json.dump(vocab, fh, ensure_ascii=False, separators=(',', ':'))

    # ONNX Runtime Web (exécution WebAssembly seulement).
    with tempfile.TemporaryDirectory() as tmp:
        subprocess.run(['npm', 'pack', '-q', f'onnxruntime-web@{ORT_VERSION}'], cwd=tmp, check=True, stdout=subprocess.DEVNULL)
        with tarfile.open(os.path.join(tmp, f'onnxruntime-web-{ORT_VERSION}.tgz')) as tf:
            for f in ['ort.wasm.min.js', 'ort-wasm-simd-threaded.mjs', 'ort-wasm-simd-threaded.wasm']:
                data = tf.extractfile('package/dist/' + f).read()
                with open(os.path.join(OUT, f), 'wb') as fh:
                    fh.write(data)
    sizes = {f: os.path.getsize(os.path.join(OUT, f)) for f in sorted(os.listdir(OUT))}
    print(json.dumps(sizes, indent=1))

    if '--test' in sys.argv:
        sess = ort.InferenceSession(model)
        T, B, C = np.array(types + other), np.array(brands), np.array(colors)
        for path in sys.argv[sys.argv.index('--test') + 1:]:
            each = image_vecs(sess, path)
            v = sum(each)
            v /= np.linalg.norm(v)
            st = 100 * T @ v
            p = np.exp(st - st.max()); p /= p.sum()
            names = [t[2] for t in TYPES] + ['~' + o for o in OTHER]
            agg = {}
            for i, n in enumerate(names):
                agg[n] = agg.get(n, 0) + p[i]
            top = sorted(((round(float(b), 2), a) for a, b in agg.items()), reverse=True)[:4]
            sc = 100 * C @ v
            oc = np.argsort(-sc)[:2]
            print(os.path.basename(path), top, 'couleur', [(COLORS[i][1], round(float(sc[i]), 1)) for i in oc])

if __name__ == '__main__':
    main()
