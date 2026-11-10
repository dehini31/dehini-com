/*
 * Maillage — graphiques des analyses (SVG et HTML, sans bibliothèque).
 *
 * Règles suivies : une seule échelle par graphique ; marques fines (barres ≤ 24 px, lignes 2 px) ;
 * grille pleine et discrète ; la couleur suit la mesure (recherches = bleu, commandes = orange,
 * chiffre d'affaires = vert) et jamais son rang ; le texte reste à l'encre, jamais à la couleur
 * de la série ; chaque graphique a un survol (et le focus clavier) et un tableau équivalent.
 * Les trois couleurs ont été vérifiées (écart daltonien et contraste) sur fond blanc ; le vert
 * passe sous 3:1, d'où la valeur écrite en bout de courbe et le tableau.
 */
(function () {
  'use strict';
  const { html, raw, esc } = ML;
  const C = { searches: '#2a78d6', orders: '#eb6834', revenue: '#1baf7a', grid: '#e9e6de', axis: '#c9c4b8', muted: '#6f6d68', ink: '#101828' };
  const NS = 'http://www.w3.org/2000/svg';

  /** Graduations « rondes » : 0 … max. */
  function ticks(max, n) {
    if (max <= 0) return [0, 1];
    const rawStep = max / (n || 4);
    const pow = Math.pow(10, Math.floor(Math.log10(rawStep)));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * pow).find((s) => s >= rawStep) || rawStep;
    const out = [];
    for (let v = 0; v <= max + step * 0.999; v += step) out.push(Math.round(v * 1e6) / 1e6);
    return out;
  }
  const axisFmt = (max) => (v) => (max >= 10000 ? ML.fmt.compact(v) : ML.fmt.num(v));
  const dayLabel = (iso) => { const [y, m, d] = iso.split('-'); return new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' }).format(new Date(+y, +m - 1, +d)); };

  let tip;
  function tipShow(target, content, x, y) {
    if (!tip) { tip = document.createElement('div'); tip.className = 'ch-tip'; tip.setAttribute('role', 'tooltip'); document.body.appendChild(tip); }
    tip.innerHTML = content;
    tip.hidden = false;
    const r = tip.getBoundingClientRect();
    let left = x - r.width / 2;
    left = Math.max(8, Math.min(left, window.innerWidth - r.width - 8));
    let top = y - r.height - 12;
    if (top < 8) top = y + 16;
    tip.style.left = left + window.scrollX + 'px';
    tip.style.top = top + window.scrollY + 'px';
  }
  function tipHide() { if (tip) tip.hidden = true; }
  window.addEventListener('scroll', tipHide, { passive: true });

  /** Redessine quand la largeur du conteneur change. */
  function responsive(el, draw) {
    let w = 0;
    const run = () => { const nw = el.clientWidth; if (nw && nw !== w) { w = nw; draw(nw); } };
    if (el._ro) el._ro.disconnect();
    el._ro = new ResizeObserver(run);
    el._ro.observe(el);
    run();
  }

  /**
   * Courbe (une série dans le temps) ou colonnes (un comptage par jour).
   * o : { labels: ['2026-11-01', …], values: [...], color, name, format, kind: 'line' | 'columns' }
   */
  function timeSeries(el, o) {
    const fmtV = o.format || ML.fmt.num;
    responsive(el, (W) => {
      const n = o.values.length;
      // La marge de droite accueille la valeur écrite en bout de courbe.
      const H = 230; const padL = 44; const padR = o.kind === 'line' ? Math.max(40, String(fmtV(o.values[n - 1])).length * 7.4 + 18) : 12; const padT = 14; const padB = 28;
      const max = Math.max(1, ...o.values);
      const tk = ticks(max, 4);
      const top = tk[tk.length - 1];
      const iw = W - padL - padR; const ih = H - padT - padB;
      const x = (i) => padL + (n === 1 ? iw / 2 : (o.kind === 'line' ? (i / (n - 1)) * iw : (i + 0.5) * (iw / n)));
      const y = (v) => padT + ih - (v / top) * ih;
      const af = axisFmt(top);
      const every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 78))));
      let s = `<svg class="ch-svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="${esc(o.name)}">`;
      tk.forEach((t) => { s += `<line x1="${padL}" x2="${W - padR}" y1="${y(t)}" y2="${y(t)}" stroke="${t === 0 ? C.axis : C.grid}" stroke-width="1"/><text x="${padL - 8}" y="${y(t) + 4}" text-anchor="end" class="ch-tick">${esc(af(t))}</text>`; });
      for (let i = 0; i < n; i += every) {
        if (n - 1 - i < every * 0.6 && i !== n - 1 && o.kind === 'line') continue;
        s += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle" class="ch-tick">${esc(dayLabel(o.labels[i]))}</text>`;
      }
      if (o.kind === 'line') {
        const pts = o.values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`);
        s += `<path d="M${padL},${y(0)} L${pts.join(' L')} L${x(n - 1)},${y(0)} Z" fill="${o.color}" opacity=".1"/>`;
        s += `<path d="M${pts.join(' L')}" fill="none" stroke="${o.color}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>`;
        s += `<circle cx="${x(n - 1)}" cy="${y(o.values[n - 1])}" r="5" fill="${o.color}" stroke="#fff" stroke-width="2"/>`;
        s += `<text x="${x(n - 1) + 10}" y="${y(o.values[n - 1]) + 4}" class="ch-end">${esc(fmtV(o.values[n - 1]))}</text>`;
        s += `<g class="ch-cross" visibility="hidden"><line y1="${padT}" y2="${padT + ih}" stroke="${C.axis}" stroke-width="1"/><circle r="5" fill="${o.color}" stroke="#fff" stroke-width="2"/></g>`;
      } else {
        const bw = Math.max(2, Math.min(24, (iw / n) - 2));
        o.values.forEach((v, i) => {
          if (v <= 0) return;
          const h = Math.max(1.5, (v / top) * ih); const r = Math.min(4, bw / 2, h);
          const x0 = x(i) - bw / 2; const y0 = y(0) - h;
          s += `<path class="ch-bar" data-i="${i}" d="M${x0},${y0 + h} V${y0 + r} Q${x0},${y0} ${x0 + r},${y0} H${x0 + bw - r} Q${x0 + bw},${y0} ${x0 + bw},${y0 + r} V${y0 + h} Z" fill="${o.color}"/>`;
        });
      }
      // Zone de survol : toute la surface du tracé, découpée par jour (cible bien plus large que la marque).
      s += `<rect class="ch-hit" x="${padL}" y="${padT}" width="${iw}" height="${ih}" fill="transparent" tabindex="0" aria-label="${esc(o.name)} : parcourir avec les flèches"/></svg>`;
      el.innerHTML = s;
      const svg = el.firstChild;
      const cross = svg.querySelector('.ch-cross');
      const bars = Array.from(svg.querySelectorAll('.ch-bar'));
      let cur = -1;
      const show = (i) => {
        cur = Math.max(0, Math.min(n - 1, i));
        const box = svg.getBoundingClientRect();
        if (cross) {
          cross.setAttribute('visibility', 'visible');
          cross.querySelector('line').setAttribute('x1', x(cur)); cross.querySelector('line').setAttribute('x2', x(cur));
          cross.querySelector('circle').setAttribute('cx', x(cur)); cross.querySelector('circle').setAttribute('cy', y(o.values[cur]));
        }
        bars.forEach((b) => b.classList.toggle('is-dim', +b.dataset.i !== cur));
        tipShow(svg, `<small>${esc(dayLabel(o.labels[cur]))}</small><b>${esc(fmtV(o.values[cur]))}</b><span>${esc(o.name)}</span>`, box.left + x(cur), box.top + y(o.values[cur]));
      };
      const hide = () => { cur = -1; if (cross) cross.setAttribute('visibility', 'hidden'); bars.forEach((b) => b.classList.remove('is-dim')); tipHide(); };
      const hitEl = svg.querySelector('.ch-hit');
      const at = (e) => {
        const box = svg.getBoundingClientRect();
        const px = (e.touches ? e.touches[0].clientX : e.clientX) - box.left;
        const i = o.kind === 'line' ? Math.round(((px - padL) / iw) * (n - 1)) : Math.floor(((px - padL) / iw) * n);
        show(i);
      };
      hitEl.addEventListener('mousemove', at);
      hitEl.addEventListener('touchstart', at, { passive: true });
      hitEl.addEventListener('touchmove', at, { passive: true });
      hitEl.addEventListener('mouseleave', hide);
      hitEl.addEventListener('blur', hide);
      hitEl.addEventListener('focus', () => show(n - 1));
      hitEl.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowLeft') { show((cur < 0 ? n : cur) - 1); e.preventDefault(); }
        if (e.key === 'ArrowRight') { show(cur + 1); e.preventDefault(); }
        if (e.key === 'Escape') hide();
      });
    });
  }

  /**
   * Barres horizontales classées (une mesure, une couleur ; la valeur est écrite au bout).
   * o : { items: [{ label, value, sub }], color, format }
   */
  function bars(el, o) {
    const fmtV = o.format || ML.fmt.num;
    const max = Math.max(1, ...o.items.map((i) => i.value));
    el.innerHTML = o.items.length ? html`<ul class="ch-bars">${o.items.map((it) => html`
      <li>
        <span class="ch-bars-label" title="${it.label}">${it.label}${it.sub ? html`<small>${it.sub}</small>` : ''}</span>
        <span class="ch-bars-track"><i style="width:${Math.max(it.value > 0 ? 1.5 : 0, (it.value / max) * 100).toFixed(1)}%;background:${o.color}"></i></span>
        <b class="num">${fmtV(it.value)}</b>
      </li>`)}</ul>` : html`<p class="ch-empty">Pas encore de données sur cette période.</p>`;
  }

  /**
   * Barre segmentée haute : un tout découpé en parts (≤ 6). La légende nomme les parts.
   * o : { parts: [{ label, value, color }], format }
   */
  function segments(el, o) {
    const fmtV = o.format || ML.fmt.num;
    const parts = o.parts.filter((p) => p.value > 0);
    const total = parts.reduce((a, p) => a + p.value, 0);
    if (!total) { el.innerHTML = '<p class="ch-empty">Pas encore de données sur cette période.</p>'; return; }
    const dark = (hex) => { const n = parseInt(hex.slice(1), 16); return ((n >> 16) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000 > 165; };
    el.innerHTML = html`
      <div class="ch-seg" role="img" aria-label="${parts.map((p) => p.label + ' : ' + fmtV(p.value) + ' (' + Math.round((p.value / total) * 100) + ' %)').join(', ')}">
        ${parts.map((p) => html`<span tabindex="0" data-tip="${p.label} · ${fmtV(p.value)} · ${Math.round((p.value / total) * 100)} %" style="flex:${p.value} 1 0;background:${p.color};color:${dark(p.color) ? '#0b0b0b' : '#fff'}"><em>${Math.round((p.value / total) * 100)} %</em></span>`)}
      </div>
      <ul class="ch-legend">${parts.map((p) => html`<li><i style="background:${p.color}"></i>${p.label}</li>`)}</ul>`;
    // Une part trop étroite pour son étiquette n'en porte pas (la valeur reste dans le tableau et au survol).
    el.querySelectorAll('.ch-seg span').forEach((s) => { const em = s.firstElementChild; if (em.scrollWidth + 12 > s.clientWidth) em.hidden = true; });
    el.querySelectorAll('.ch-seg span').forEach((s) => {
      const on = () => { const r = s.getBoundingClientRect(); tipShow(s, `<b>${esc(s.dataset.tip)}</b>`, r.left + r.width / 2, r.top); };
      s.addEventListener('mouseenter', on); s.addEventListener('focus', on);
      s.addEventListener('mouseleave', tipHide); s.addEventListener('blur', tipHide);
    });
  }

  /** Mini-courbe d'une tuile (12 à 30 points), sans axe. */
  function spark(values, colorHex) {
    const n = values.length;
    if (n < 2) return '';
    const W = 120; const H = 34; const max = Math.max(1, ...values);
    const pts = values.map((v, i) => `${((i / (n - 1)) * (W - 6) + 3).toFixed(1)},${(H - 4 - (v / max) * (H - 9)).toFixed(1)}`);
    const last = pts[n - 1].split(',');
    return raw(`<svg class="ch-spark" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" aria-hidden="true"><path d="M${pts.join(' L')}" fill="none" stroke="${colorHex}" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/><circle cx="${last[0]}" cy="${last[1]}" r="3.5" fill="${colorHex}" stroke="#fff" stroke-width="2"/></svg>`);
  }

  /** Jauge fine (0–100 %) accompagnée de sa valeur. */
  function meter(pct, tone) {
    if (pct == null) return raw('<span class="dim">—</span>');
    const c = tone === 'bad' ? (pct >= 20 ? '#d03b3b' : pct >= 10 ? '#ec835a' : '#2a78d6') : (pct < 50 ? '#d03b3b' : pct < 75 ? '#ec835a' : '#2a78d6');
    return html`<span class="ch-meter"><span><i style="width:${Math.max(2, Math.min(100, pct))}%;background:${c}"></i></span><b class="num">${ML.fmt.num(pct)} %</b></span>`;
  }

  /**
   * Carte de graphique avec son tableau équivalent.
   * o : { title, sub, draw(el), head: [...], rows: [[...]], align: ['', 'r', …] }
   */
  let uid = 0;
  function card(o) {
    const id = 'ch' + (++uid);
    queueMicrotask(() => {
      const root = document.getElementById(id);
      if (!root) return;
      const plot = root.querySelector('[data-plot]');
      const table = root.querySelector('[data-table]');
      o.draw(plot);
      const btn = root.querySelector('[data-toggle]');
      if (btn) {
        btn.addEventListener('click', () => {
          const showTable = table.hidden;
          table.hidden = !showTable; plot.hidden = showTable;
          btn.setAttribute('aria-pressed', showTable);
          btn.lastChild.textContent = showTable ? 'Graphique' : 'Tableau';
          if (!showTable) o.draw(plot);
        });
      }
    });
    return html`
      <section class="card card-pad ch-card ${o.cls || ''}" id="${id}">
        <div class="card-head">
          <div><h3 class="card-title">${o.title}</h3>${o.sub ? html`<p class="card-sub">${o.sub}</p>` : ''}</div>
          ${o.rows ? html`<button class="btn btn-ghost btn-sm" type="button" data-toggle aria-pressed="false">${ML.icon('table', 'ico-sm')}<span>Tableau</span></button>` : ''}
        </div>
        <div data-plot></div>
        ${o.rows ? html`<div data-table hidden>${table(o.head, o.rows, o.align)}</div>` : ''}
      </section>`;
  }
  function table(head, rows, align) {
    align = align || [];
    return html`<div class="tbl-wrap"><table class="tbl"><thead><tr>${head.map((h, i) => html`<th class="${align[i] === 'r' ? 'r' : ''}">${h}</th>`)}</tr></thead>
      <tbody>${rows.length ? rows.map((r) => html`<tr>${r.map((c, i) => html`<td class="${align[i] === 'r' ? 'r num' : ''}">${c}</td>`)}</tr>`) : html`<tr><td colspan="${head.length}" class="dim">Pas encore de données.</td></tr>`}</tbody></table></div>`;
  }

  ML.charts = { C, timeSeries, bars, segments, spark, meter, card, table, dayLabel };
})();
