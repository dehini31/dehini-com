/*
 * Maillage — reconnaissance d'image de la recherche par photo, exécutée dans le navigateur du
 * client : la photo n'est envoyée à aucun service extérieur.
 *
 * Le modèle (assets/vision/model.onnx, encodeur d'image de TinyCLIP, Microsoft, licence MIT)
 * transforme la photo en un vecteur, comparé aux vecteurs pré-calculés d'environ 250 types de
 * produits, de marques et de couleurs (assets/vision/vocab.json, produits par tools/vision/build.py).
 * Il est téléchargé à la première recherche par photo (≈ 28 Mo), puis gardé par le navigateur.
 *
 * ML.Vision.load() commence le téléchargement (dès que le client s'apprête à choisir une photo).
 * ML.Vision.analyze(blob, onProgress) → {
 *   types: [{ fr, ar, cat, p }] (du plus probable au moins probable), other: bool (pas un produit),
 *   brand: { name, cat, fr, ar } | null, color: { fr, ar } | null }
 * Rien de ce qui en sort n'est une certitude : la page le présente comme une proposition à vérifier.
 */
(function () {
  'use strict';
  const VERSION = '2';
  const BASE = new URL('vision/', document.currentScript ? document.currentScript.src : location.href).href;
  let SIZE = 224;
  let MEAN = [0, 0, 0];
  let STD = [1, 1, 1];
  let ready = null;
  // Avancement du téléchargement : le chargement peut commencer avant que la page ne l'affiche.
  let listener = null;
  let progress = 0;
  const report = (x) => { progress = x; if (listener) listener(x); };

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = src;
      s.onload = resolve;
      s.onerror = () => reject(new Error('runtime'));
      document.head.appendChild(s);
    });
  }

  /** Téléchargement avec avancement (0 à 1) ; le navigateur garde ensuite le fichier en cache. */
  async function download(url, onProgress) {
    const r = await fetch(url);
    if (!r.ok) throw new Error('download ' + r.status);
    const total = +r.headers.get('content-length') || 0;
    if (!r.body || !total || !onProgress) return new Uint8Array(await r.arrayBuffer());
    const reader = r.body.getReader();
    const parts = [];
    let got = 0;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      parts.push(value);
      got += value.length;
      onProgress(Math.min(1, got / total));
    }
    const out = new Uint8Array(got);
    let at = 0;
    parts.forEach((p) => { out.set(p, at); at += p.length; });
    return out;
  }

  function f16(b64, dim) {
    const bin = atob(b64);
    const n = bin.length / 2;
    const dv = new DataView(new ArrayBuffer(bin.length));
    for (let i = 0; i < bin.length; i++) dv.setUint8(i, bin.charCodeAt(i));
    const out = new Float32Array(n);
    for (let i = 0; i < n; i++) {
      const h = dv.getUint16(i * 2, true);
      const s = h & 0x8000 ? -1 : 1;
      const e = (h >> 10) & 0x1f;
      const m = h & 0x3ff;
      out[i] = e === 0 ? s * m * 2 ** -24 : e === 31 ? (m ? NaN : s * Infinity) : s * (1 + m / 1024) * 2 ** (e - 15);
    }
    const rows = [];
    for (let i = 0; i < n; i += dim) rows.push(out.subarray(i, i + dim));
    return rows;
  }

  function load() {
    if (ready) return ready;
    ready = (async () => {
      if (typeof WebAssembly !== 'object') throw new Error('wasm');
      if (!window.ort) await loadScript(BASE + 'ort.wasm.min.js?v=' + VERSION);
      const ort = window.ort;
      ort.env.wasm.wasmPaths = BASE;
      ort.env.wasm.numThreads = 1;
      ort.env.wasm.proxy = false;
      ort.env.logLevel = 'error';
      const [vocab, model] = await Promise.all([
        fetch(BASE + 'vocab.json?v=' + VERSION).then((r) => { if (!r.ok) throw new Error('vocab'); return r.json(); }),
        download(BASE + 'model.onnx?v=' + VERSION, report),
      ]);
      const session = await ort.InferenceSession.create(model, { executionProviders: ['wasm'], graphOptimizationLevel: 'all' });
      SIZE = vocab.size || SIZE;
      MEAN = vocab.mean || MEAN;
      STD = vocab.std || STD;
      return {
        ort, session, vocab,
        types: f16(vocab.vec.types, vocab.dim),
        brands: f16(vocab.vec.brands, vocab.dim),
        colors: f16(vocab.vec.colors, vocab.dim),
      };
    })();
    ready.catch(() => { ready = null; });
    return ready;
  }

  /** Deux vues carrées de la photo : le centre (recadré) et la photo entière (bordée de blanc). */
  async function views(blob) {
    const bmp = await createImageBitmap(blob);
    const w = bmp.width;
    const h = bmp.height;
    const cv = document.createElement('canvas');
    cv.width = SIZE;
    cv.height = SIZE;
    const ctx = cv.getContext('2d', { willReadFrequently: true });
    const read = () => ctx.getImageData(0, 0, SIZE, SIZE).data;
    const m = Math.min(w, h);
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bmp, (w - m) / 2, (h - m) / 2, m, m, 0, 0, SIZE, SIZE);
    const center = read();
    const M = Math.max(w, h);
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, SIZE, SIZE);
    ctx.drawImage(bmp, 0, 0, w, h, ((M - w) / 2) * SIZE / M, ((M - h) / 2) * SIZE / M, w * SIZE / M, h * SIZE / M);
    const whole = read();
    if (bmp.close) bmp.close();
    return [center, whole];
  }

  function tensor(ort, rgba) {
    const n = SIZE * SIZE;
    const data = new Float32Array(3 * n);
    for (let i = 0; i < n; i++) {
      data[i] = (rgba[i * 4] / 255 - MEAN[0]) / STD[0];
      data[n + i] = (rgba[i * 4 + 1] / 255 - MEAN[1]) / STD[1];
      data[2 * n + i] = (rgba[i * 4 + 2] / 255 - MEAN[2]) / STD[2];
    }
    return new ort.Tensor('float32', data, [1, 3, SIZE, SIZE]);
  }

  /**
   * Famille de couleur du centre de la photo (moitié centrale), d'après ses pixels : sert à écarter
   * une couleur que le modèle propose à tort (une chemise bleu pâle prise pour grise, par exemple).
   */
  function pixelFamily(rgba) {
    const hs = [];
    const lo = Math.floor(SIZE / 4);
    const hi = Math.ceil((SIZE * 3) / 4);
    for (let y = lo; y < hi; y += 2) {
      for (let x = lo; x < hi; x += 2) {
        const i = (y * SIZE + x) * 4;
        const r = rgba[i] / 255;
        const g = rgba[i + 1] / 255;
        const b = rgba[i + 2] / 255;
        const mx = Math.max(r, g, b);
        const mn = Math.min(r, g, b);
        const sat = mx ? (mx - mn) / mx : 0;
        let h = 0;
        if (mx !== mn) h = mx === r ? ((g - b) / (mx - mn) + 6) % 6 : mx === g ? (b - r) / (mx - mn) + 2 : (r - g) / (mx - mn) + 4;
        hs.push([h * 60, sat, mx]);
      }
    }
    const med = (k) => hs.map((v) => v[k]).sort((a, b) => a - b)[hs.length >> 1];
    const sat = med(1);
    const val = med(2);
    if (sat < 0.1 || val < 0.12) return 'neutral';
    // Teinte dominante parmi les pixels assez colorés.
    const bins = new Array(12).fill(0);
    hs.forEach(([h, sv, v]) => { if (sv >= 0.1 && v >= 0.12) bins[Math.floor(h / 30) % 12]++; });
    const hue = bins.indexOf(Math.max(...bins)) * 30 + 15;
    if (hue < 20 || hue >= 330) return 'red';
    if (hue < 45) return 'orange';
    if (hue < 70) return 'yellow';
    if (hue < 165) return 'green';
    if (hue < 255) return 'blue';
    if (hue < 290) return 'purple';
    return 'red';
  }
  const FAMILY = {
    blanc: ['neutral'], noir: ['neutral'], gris: ['neutral'], 'argenté': ['neutral'], transparent: ['neutral', 'blue', 'green', 'yellow', 'orange', 'red', 'purple'],
    bleu: ['blue'], 'bleu clair': ['blue', 'neutral'], 'bleu marine': ['blue', 'neutral'], rouge: ['red'], bordeaux: ['red', 'purple'], rose: ['red', 'purple'],
    vert: ['green'], jaune: ['yellow', 'orange'], 'doré': ['yellow', 'orange'], beige: ['yellow', 'orange', 'neutral'], orange: ['orange', 'red'], marron: ['orange', 'red', 'neutral'], violet: ['purple', 'blue'],
  };

  const dot = (a, b) => { let s = 0; for (let i = 0; i < a.length; i++) s += a[i] * b[i]; return s; };
  function normalize(v) {
    let n = 0;
    for (let i = 0; i < v.length; i++) n += v[i] * v[i];
    n = Math.sqrt(n) || 1;
    return v.map((x) => x / n);
  }
  function softmax(scores) {
    const mx = Math.max(...scores);
    const e = scores.map((s) => Math.exp(s - mx));
    const sum = e.reduce((a, b) => a + b, 0);
    return e.map((x) => x / sum);
  }

  async function analyze(blob, onProgress) {
    listener = onProgress || null;
    if (listener && progress) listener(progress);
    const M = await load().finally(() => { listener = null; });
    const name = M.session.inputNames[0];
    const each = [];
    const pics = await views(blob);
    const family = pixelFamily(pics[0]);
    for (const px of pics) {
      const out = await M.session.run({ [name]: tensor(M.ort, px) });
      each.push(normalize(Float32Array.from(out[M.session.outputNames[0]].data)));
    }
    const emb = normalize(each[0].map((x, i) => each.reduce((s, v) => s + v[i], 0)));

    // Types de produits (+ « pas un produit ») : probabilités, additionnées par nom.
    const nT = M.vocab.types.length;
    const p = softmax(M.types.map((t) => 100 * dot(t, emb)));
    const byName = new Map();
    M.vocab.types.forEach((t, i) => {
      const k = t.fr;
      const cur = byName.get(k) || { fr: t.fr, ar: t.ar, cat: t.cat, p: 0 };
      cur.p += p[i];
      byName.set(k, cur);
    });
    const types = Array.from(byName.values()).sort((a, b) => b.p - a.p);
    let otherP = 0;
    for (let i = nT; i < p.length; i++) otherP += p[i];

    // Marque : seulement si elle se détache nettement (logo ou nom lisible) sur l'une des deux vues
    // (un nom écrit petit se lit mieux sur le centre agrandi).
    let brand = null;
    let best = 0;
    each.forEach((v) => {
      const order = M.brands.map((b, i) => [100 * dot(b, v), i]).sort((a, b) => b[0] - a[0]);
      if (order.length > 1 && order[0][0] >= 24 && order[0][0] - order[1][0] >= 3.5 && order[0][0] > best) {
        best = order[0][0];
        brand = M.vocab.brands[order[0][1]];
      }
    });

    // Couleur : la plus probable selon le modèle parmi les trois premières, à condition qu'elle
    // s'accorde avec les pixels du centre de la photo ; sinon aucune.
    const cs = M.colors.map((c) => 100 * dot(c, emb)).map((s, i) => [s, i]).sort((a, b) => b[0] - a[0]);
    const pick = cs.slice(0, 3).find(([, i]) => (FAMILY[M.vocab.colors[i].fr] || []).includes(family));
    const color = pick ? M.vocab.colors[pick[1]] : null;

    return { types, other: otherP > 0.5, brand, color };
  }

  window.ML = window.ML || {};
  window.ML.Vision = { analyze, load, supported: () => typeof WebAssembly === 'object' && typeof createImageBitmap === 'function' };
})();
