/*
 * Studioma — petit encodeur de QR code (norme ISO/IEC 18004) pour l'image d'une réalisation (assets/journey.js) :
 * mode octets (UTF-8), correction d'erreurs M, versions 1 à 10 (jusqu'à 213 octets), masque choisi par pénalité.
 * window.StmQr(texte) → { size, dark(x, y) } ; null si le texte est trop long. Aucune bibliothèque externe.
 */
(function (root) {
  'use strict';
  // Version → [codes de correction par bloc, [[nombre de blocs, octets de données par bloc], …]] (niveau M).
  const BLOCKS = [null,
    [10, [[1, 16]]], [16, [[1, 28]]], [26, [[1, 44]]], [18, [[2, 32]]], [24, [[2, 43]]],
    [16, [[4, 27]]], [18, [[4, 31]]], [22, [[2, 38], [2, 39]]], [22, [[3, 36], [2, 37]]], [26, [[4, 43], [1, 44]]]];
  const ALIGN = [null, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];

  // Corps de Galois GF(256), polynôme 0x11D.
  const EXP = new Array(512);
  const LOG = new Array(256);
  for (let i = 0, x = 1; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 256) x ^= 0x11D; }
  for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
  const mul = (a, b) => (a && b ? EXP[LOG[a] + LOG[b]] : 0);
  /** Codes de Reed-Solomon (reste de la division par le polynôme générateur de degré n). */
  function rs(data, n) {
    let gen = [1];
    for (let i = 0; i < n; i++) {
      const next = new Array(gen.length + 1).fill(0);
      for (let j = 0; j < gen.length; j++) { next[j] ^= gen[j]; next[j + 1] ^= mul(gen[j], EXP[i]); }
      gen = next;
    }
    const rem = new Array(n).fill(0);
    data.forEach((b) => {
      const f = b ^ rem.shift();
      rem.push(0);
      for (let j = 0; j < n; j++) rem[j] ^= mul(gen[j + 1], f);
    });
    return rem;
  }
  const bit = (x, i) => ((x >>> i) & 1) === 1;

  function encode(text) {
    const bytes = Array.from(new TextEncoder().encode(String(text)));
    let ver = 0;
    let cap = 0;
    for (let v = 1; v <= 10; v++) {
      cap = BLOCKS[v][1].reduce((s, [n, k]) => s + n * k, 0);
      const need = 4 + (v < 10 ? 8 : 16) + bytes.length * 8;
      if (need <= cap * 8) { ver = v; break; }
    }
    if (!ver) return null;
    // Données : mode octets, longueur, octets, terminaison, octets de remplissage.
    const bits = [];
    const push = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
    push(4, 4);
    push(bytes.length, ver < 10 ? 8 : 16);
    bytes.forEach((b) => push(b, 8));
    push(0, Math.min(4, cap * 8 - bits.length));
    while (bits.length % 8) bits.push(0);
    const data = [];
    for (let i = 0; i < bits.length; i += 8) data.push(bits.slice(i, i + 8).reduce((a, b) => (a << 1) | b, 0));
    for (let pad = 0xEC; data.length < cap; pad ^= 0xEC ^ 0x11) data.push(pad);
    // Blocs, codes de correction, entrelacement.
    const [ecLen, groups] = BLOCKS[ver];
    const blocks = [];
    let k = 0;
    groups.forEach(([n, len]) => { for (let i = 0; i < n; i++) { const d = data.slice(k, k + len); k += len; blocks.push({ d, e: rs(d, ecLen) }); } });
    const out = [];
    const maxD = Math.max(...blocks.map((b) => b.d.length));
    for (let i = 0; i < maxD; i++) blocks.forEach((b) => { if (i < b.d.length) out.push(b.d[i]); });
    for (let i = 0; i < ecLen; i++) blocks.forEach((b) => out.push(b.e[i]));

    const size = ver * 4 + 17;
    const mods = Array.from({ length: size }, () => new Array(size).fill(false));
    const fn = Array.from({ length: size }, () => new Array(size).fill(false));
    const set = (x, y, dark) => { mods[y][x] = dark; fn[y][x] = true; };
    for (let i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    [[3, 3], [size - 4, 3], [3, size - 4]].forEach(([cx, cy]) => {
      for (let dy = -4; dy <= 4; dy++) for (let dx = -4; dx <= 4; dx++) {
        const x = cx + dx;
        const y = cy + dy;
        const d = Math.max(Math.abs(dx), Math.abs(dy));
        if (x >= 0 && x < size && y >= 0 && y < size) set(x, y, d !== 2 && d !== 4);
      }
    });
    const al = ALIGN[ver];
    al.forEach((ay, i) => al.forEach((ax, j) => {
      if ((i === 0 && j === 0) || (i === 0 && j === al.length - 1) || (i === al.length - 1 && j === 0)) return;
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }));
    const drawFormat = (mask) => {
      const d = (0 << 3) | mask;   // niveau M = 00
      let rem = d;
      for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
      const f = ((d << 10) | rem) ^ 0x5412;
      for (let i = 0; i <= 5; i++) set(8, i, bit(f, i));
      set(8, 7, bit(f, 6)); set(8, 8, bit(f, 7)); set(7, 8, bit(f, 8));
      for (let i = 9; i < 15; i++) set(14 - i, 8, bit(f, i));
      for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(f, i));
      for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(f, i));
      set(8, size - 8, true);
    };
    drawFormat(0);
    if (ver >= 7) {
      let rem = ver;
      for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
      const v = (ver << 12) | rem;
      for (let i = 0; i < 18; i++) {
        const a = size - 11 + (i % 3);
        const b = Math.floor(i / 3);
        set(a, b, bit(v, i)); set(b, a, bit(v, i));
      }
    }
    // Placement en zigzag des octets.
    let n = 0;
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (let vert = 0; vert < size; vert++) {
        for (let j = 0; j < 2; j++) {
          const x = right - j;
          const up = ((right + 1) & 2) === 0;
          const y = up ? size - 1 - vert : vert;
          if (!fn[y][x] && n < out.length * 8) { mods[y][x] = bit(out[n >>> 3], 7 - (n & 7)); n++; }
        }
      }
    }
    const MASKS = [
      (x, y) => (x + y) % 2 === 0, (x, y) => y % 2 === 0, (x) => x % 3 === 0, (x, y) => (x + y) % 3 === 0,
      (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0, (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
      (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0, (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
    ];
    const applyMask = (m) => { for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!fn[y][x] && MASKS[m](x, y)) mods[y][x] = !mods[y][x]; };
    // Pénalité (règles 1, 2 et 4 de la norme ; la règle 3 ne change pas la validité du code).
    const penalty = () => {
      let p = 0;
      let dark = 0;
      for (let y = 0; y < size; y++) {
        for (let pass = 0; pass < 2; pass++) {
          let run = 1;
          for (let x = 1; x <= size; x++) {
            const a = pass ? mods[x - 1] && mods[x - 1][y] : mods[y][x - 1];
            const b = x < size ? (pass ? mods[x][y] : mods[y][x]) : null;
            if (b === a) run++; else { if (run >= 5) p += run - 2; run = 1; }
          }
        }
        for (let x = 0; x < size; x++) {
          if (mods[y][x]) dark++;
          if (y < size - 1 && x < size - 1) { const c = mods[y][x]; if (c === mods[y][x + 1] && c === mods[y + 1][x] && c === mods[y + 1][x + 1]) p += 3; }
        }
      }
      return p + Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
    };
    let best = 0;
    let bestScore = Infinity;
    for (let m = 0; m < 8; m++) {
      applyMask(m); drawFormat(m);
      const s = penalty();
      if (s < bestScore) { bestScore = s; best = m; }
      applyMask(m);
    }
    const mask = root.StmQrMask != null ? root.StmQrMask : best;   // tests : masque imposé
    applyMask(mask); drawFormat(mask);
    return { size, version: ver, mask, dark: (x, y) => mods[y][x] };
  }
  root.StmQr = encode;
})(typeof window !== 'undefined' ? window : globalThis);
