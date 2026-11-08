/* Marketing Dashboard — interface (sans dépendance). Les données viennent de api.php ;
   les rapports sont calculés côté serveur (lib/report.php), ici on ne fait que les afficher. */
(() => {
"use strict";

/* ================================================================ utilitaires */
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const V = $("#view");
const S = { csrf: null, boot: null, today: null, tok: 0, cache: new Map(), rows: {}, range: loadRange() };

function loadRange() {
  try { const r = JSON.parse(localStorage.getItem("mk_range") || "null"); if (r && r.preset) return r; } catch (e) {}
  return { preset: "30d" };
}
function saveRange() { try { localStorage.setItem("mk_range", JSON.stringify(S.range)); } catch (e) {} }

const num = (v, d = 0) => v == null || v === "" || isNaN(v) ? "—" : Number(v).toLocaleString("fr-FR", { minimumFractionDigits: d, maximumFractionDigits: d });
const cur = () => (S.boot && S.boot.settings.currency) || "DA";
const money = v => v == null || v === "" ? "—" : num(v) + " " + cur();
const pct = (v, d = 0) => v == null ? "—" : num(v * 100, d) + " %";
const ratio = (a, b) => +b > 0 ? +a / +b : null;
const MS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];
const ML = ["janvier", "février", "mars", "avril", "mai", "juin", "juillet", "août", "septembre", "octobre", "novembre", "décembre"];
const isDate = d => /^\d{4}-\d{2}-\d{2}$/.test(d || "");
const fdate = (d, y = true) => { if (!isDate(d)) return "—"; const [Y, M, D] = d.split("-"); return +D + " " + MS[M - 1] + (y ? " " + Y : ""); };
const iso = t => t.getFullYear() + "-" + String(t.getMonth() + 1).padStart(2, "0") + "-" + String(t.getDate()).padStart(2, "0");
const addDays = (d, n) => { const t = new Date(d + "T12:00:00"); t.setDate(t.getDate() + n); return iso(t); };
const addMonths = (d, n) => { const t = new Date(d.slice(0, 8) + "01T12:00:00"); t.setMonth(t.getMonth() + n); return iso(t); };
const monday = d => { const t = new Date(d + "T12:00:00"); const wd = (t.getDay() + 6) % 7; return addDays(d, -wd); };
const daysBetween = (a, b) => Math.round((new Date(b + "T12:00:00") - new Date(a + "T12:00:00")) / 864e5);

function toast(m) { const n = document.createElement("div"); n.className = "toast"; n.textContent = m; document.body.append(n); setTimeout(() => n.remove(), 3200); }

/* ================================================================ API */
async function call(url, body) {
  const opt = { credentials: "same-origin", headers: {} };
  if (body !== undefined) {
    opt.method = "POST";
    opt.headers["Content-Type"] = "application/json";
    opt.headers["X-CSRF"] = S.csrf || "";
    opt.body = JSON.stringify(body);
  }
  const r = await fetch(url, opt);
  let d = {};
  try { d = await r.json(); } catch (e) {}
  if (!r.ok || d.error) {
    const e = new Error(d.error || "Erreur serveur (" + r.status + ")");
    e.status = r.status;
    if (r.status === 401) showLogin();
    throw e;
  }
  return d;
}
function qs(params) {
  return Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== "").map(([k, v]) => "&" + encodeURIComponent(k) + "=" + encodeURIComponent(v)).join("");
}
const api = (action, params = {}, body) => call("api.php?action=" + action + qs(params), body);
/** GET mis en cache jusqu'à la prochaine modification. */
function cached(action, params = {}) {
  const key = action + qs(params);
  if (!S.cache.has(key)) S.cache.set(key, api(action, params).catch(e => { S.cache.delete(key); throw e; }));
  return S.cache.get(key);
}
async function afterMutation(entity) {
  S.cache.clear();
  if (["campaigns", "pages", "emails", "funnels"].includes(entity)) {
    const d = await api("refs");
    S.boot.refs = d.refs;
    S.boot.enums = d.enums;
  }
  route();
}

/* ================================================================ schéma & libellés */
const SC = e => S.boot.schema[e];
const EN = n => S.boot.enums[n] || {};
const L = (n, k) => k == null || k === "" ? "" : (EN(n)[k] != null ? EN(n)[k] : k);
const REF = e => (S.boot.refs[e] || []);
const allStages = () => REF("funnels").flatMap(f => f.stages.map(s => Object.assign({ funnel: f.name }, s)));
function refName(e, id) {
  if (id == null || id === "") return "";
  if (e === "funnel_stages") { const s = allStages().find(x => +x.id === +id); return s ? s.name : "#" + id; }
  const r = REF(e).find(x => +x.id === +id);
  return r ? r.name : "#" + id;
}
function fmtField(entity, k, row) {
  const f = SC(entity).fields[k];
  const v = row[k];
  if (!f) return esc(v);
  switch (f.t) {
    case "date": return v ? fdate(v) : "—";
    case "enum": return v ? esc(L(f.opts, v)) : '<span class="muted">' + esc(f.empty || "—") + "</span>";
    case "multi": return String(v || "").split(",").filter(Boolean).map(x => '<span class="tag">' + esc(L(f.opts, x)) + "</span>").join("") || "—";
    case "ref": return v == null ? '<span class="muted">—</span>' : esc(refName(f.ref, v));
    case "money": return money(v);
    case "int": return num(v);
    case "url": return v ? '<a href="' + esc(v) + '" target="_blank" rel="noopener">' + esc(v.replace(/^https?:\/\/(www\.)?/, "").slice(0, 40)) + "</a>" : "—";
    case "longtext": return v ? esc(v.length > 140 ? v.slice(0, 140) + "…" : v) : "—";
    default: return v ? esc(v) : "—";
  }
}
function remember(entity, rows) {
  S.rows[entity] = S.rows[entity] || {};
  for (const r of rows) S.rows[entity][r.id] = r;
  return rows;
}

/* ================================================================ période */
function presetRange(p) {
  const t = S.today;
  switch (p) {
    case "7d": return [addDays(t, -6), t];
    case "90d": return [addDays(t, -89), t];
    case "week": return [monday(t), t];
    case "month": return [t.slice(0, 8) + "01", t];
    case "last_month": { const f = addMonths(t, -1); return [f, addDays(t.slice(0, 8) + "01", -1)]; }
    case "year": return [t.slice(0, 4) + "-01-01", t];
    default: return [addDays(t, -29), t];
  }
}
function curRange() {
  if (S.range.preset === "custom" && isDate(S.range.from) && isDate(S.range.to)) {
    return S.range.from <= S.range.to ? [S.range.from, S.range.to] : [S.range.to, S.range.from];
  }
  return presetRange(S.range.preset);
}
const rangeLabel = () => { const [a, b] = curRange(); return a === b ? fdate(a) : "du " + fdate(a, a.slice(0, 4) !== b.slice(0, 4)) + " au " + fdate(b); };
const rangeReport = () => { const [from, to] = curRange(); return cached("report", { type: "custom", date: from, to }).then(d => d.report); };
const rangeList = (entity, extra = {}) => { const [from, to] = curRange(); return cached("list", Object.assign({ entity, from, to }, extra)).then(d => remember(entity, d.rows)); };
const allList = (entity, extra = {}) => cached("list", Object.assign({ entity }, extra)).then(d => remember(entity, d.rows));

function syncRangeCtrl() {
  $("#rangePreset").value = S.range.preset;
  $("#rangeCustom").hidden = S.range.preset !== "custom";
  const [a, b] = curRange();
  $("#rangeFrom").value = a;
  $("#rangeTo").value = b;
}
$("#rangePreset").addEventListener("change", e => {
  S.range.preset = e.target.value;
  if (S.range.preset === "custom") { const [a, b] = presetRange("30d"); S.range.from = S.range.from || a; S.range.to = S.range.to || b; }
  saveRange(); syncRangeCtrl(); route();
});
for (const id of ["rangeFrom", "rangeTo"]) {
  $("#" + id).addEventListener("change", () => {
    if (!isDate($("#rangeFrom").value) || !isDate($("#rangeTo").value)) return;
    S.range = { preset: "custom", from: $("#rangeFrom").value, to: $("#rangeTo").value };
    saveRange(); route();
  });
}

/* ================================================================ navigation */
const NAV = [
  { id: "overview", ic: "🏠", label: "Overview", tabs: [["daily", "Daily Monitoring"], ["weekly", "Weekly Reports"], ["monthly", "Monthly Reports"], ["archives", "Archives"]] },
  { id: "funnels", ic: "📊", label: "Funnel Analytics", tabs: [["list", "Funnels"], ["stages", "Étapes & tags"], ["conversions", "Conversions"]] },
  { id: "social", ic: "📱", label: "Social Media", tabs: [["leads", "Leads & Contacts"], ["inquiries", "Questions & Inquiries"], ["activity", "Activité"], ["performance", "Performances"]] },
  { id: "ads", ic: "📢", label: "Ads Manager", tabs: [["campaigns", "Campagnes"], ["objectives", "Objectifs"], ["budgets", "Budgets"], ["results", "Résultats"]] },
  { id: "web", ic: "🌐", label: "Website Analytics", tabs: [["website", "Website"], ["pages", "Landing Pages"], ["registrations", "Inscriptions"], ["leads", "Leads & Contacts"]] },
  { id: "email", ic: "✉️", label: "Email Marketing", tabs: [["campaigns", "Campagnes"], ["sent", "Emails envoyés"], ["engagement", "Ouvertures & clics"], ["objectives", "Objectifs atteints"]] },
  { id: "leads", ic: "👥", label: "Leads & Contacts", tabs: [["contacts", "Contacts"], ["profiles", "Investisseurs / Porteurs"], ["sources", "Sources"], ["assignment", "Assignation"], ["treatment", "Traitement"]] },
  { id: "projects", ic: "📁", label: "Projets", tabs: [["requests", "Demandes de projets"], ["funding", "En cours de financement"]] },
  { id: "settings", ic: "⚙️", label: "Réglages", tabs: [], hidden: true },
];
const ALIAS = { "ads/campaign": "campaigns" }; // onglet surligné pour une page sans onglet propre

function parseHash() {
  const h = decodeURIComponent(location.hash.replace(/^#\/?/, "")) || "overview/daily";
  const [sec, tab, ...rest] = h.split("/");
  return { sec, tab: tab || "", param: rest.join("/") };
}
function renderNav(sec, tab) {
  $("#nav").innerHTML = NAV.filter(n => !n.hidden).map(n => `<a class="sec${n.id === sec ? " on" : ""}" href="#${n.id}"><span class="ic">${n.ic}</span>${esc(n.label)}</a>`
    + (n.id === sec && n.tabs.length ? `<div class="subs">${n.tabs.map(([k, l]) => `<a href="#${n.id}/${k}" class="${k === tab ? "on" : ""}">${esc(l)}</a>`).join("")}</div>` : "")).join("");
}
function renderTabs(def, tab) {
  $("#tabs").innerHTML = def.tabs.map(([k, l]) => `<a href="#${def.id}/${k}" class="${k === tab ? "on" : ""}">${esc(l)}</a>`).join("");
}
async function route() {
  if (!S.boot) return;
  const { sec, tab, param } = parseHash();
  const def = NAV.find(n => n.id === sec) || NAV[0];
  const t = tab || (def.tabs[0] ? def.tabs[0][0] : "");
  const view = VIEWS[def.id + "/" + t] || VIEWS[def.id] || VIEWS["overview/daily"];
  const shown = ALIAS[def.id + "/" + t] || t;
  renderNav(def.id, shown);
  renderTabs(def, shown);
  $("#title").textContent = def.label;
  document.title = def.label + " — Marketing Dashboard";
  $("#rangeCtrl").hidden = !view.range;
  if (view.range) syncRangeCtrl();
  $("#side").classList.remove("open");
  const tok = ++S.tok;
  V.innerHTML = '<p class="loading">Chargement…</p>';
  try {
    const html = await view.fn(param, tok);
    if (tok !== S.tok) return;
    if (typeof html === "string") V.innerHTML = html;
    if (view.after) view.after(param);
  } catch (e) {
    if (tok === S.tok && e.status !== 401) V.innerHTML = `<div class="empty">${esc(e.message)}</div>`;
  }
}
window.addEventListener("hashchange", route);
$("#burger").addEventListener("click", () => $("#side").classList.toggle("open"));

/* ================================================================ briques d'affichage */
const empty = (m, btn = "") => `<div class="empty">${esc(m)}${btn ? "<div style=\"margin-top:10px\">" + btn + "</div>" : ""}</div>`;
const addBtn = (entity, label, preset, cls = "btn") => `<button type="button" class="${cls}" data-add="${entity}"${preset ? ` data-preset="${esc(JSON.stringify(preset))}"` : ""}>＋ ${esc(label)}</button>`;
const card = (title, body, right = "", id = "") => `<section class="card"${id ? ` id="${id}"` : ""}><div class="card-h"><h2>${title}</h2><div class="ctrl">${right}</div></div>${body}</section>`;
const actBtns = (entity, r) => `<button type="button" class="icon-btn" title="Modifier" data-edit="${entity}:${r.id}">✏️</button><button type="button" class="icon-btn del" title="Supprimer" data-del="${entity}:${r.id}">🗑</button>`;

/** Tableau générique. cols : [{l, k?, n?, h?(row)=>html, wrap?}] */
function table(rows, cols, { entity = null, foot = null, max = 300, emptyMsg = "Aucune donnée sur la période.", more = "" } = {}) {
  if (!rows.length) return empty(emptyMsg);
  const shown = rows.slice(0, max);
  const th = cols.map(c => `<th${c.n ? ' class="n"' : ""}>${esc(c.l)}</th>`).join("") + (entity ? "<th></th>" : "");
  const body = shown.map(r => "<tr>" + cols.map(c => `<td class="${c.n ? "n" : ""}${c.wrap ? " wrap" : ""}">${c.h ? c.h(r) : fmtField(entity, c.k, r)}</td>`).join("")
    + (entity ? `<td class="act">${actBtns(entity, r)}</td>` : "") + "</tr>").join("");
  const tf = foot ? "<tfoot><tr>" + foot.map((v, i) => `<td class="${cols[i] && cols[i].n ? "n" : ""}">${v}</td>`).join("") + (entity ? "<td></td>" : "") + "</tr></tfoot>" : "";
  return `<div class="tw"><table><thead><tr>${th}</tr></thead><tbody>${body}</tbody>${tf}</table></div>`
    + (rows.length > max ? `<p class="more">${num(max)} lignes affichées sur ${num(rows.length)}${more ? " · " + more : " — réduisez la période ou exportez en CSV."}</p>` : "");
}
const col = (entity, k, extra = {}) => Object.assign({ l: SC(entity).fields[k].l, k, n: ["int", "money"].includes(SC(entity).fields[k].t) }, extra);

/** Liste déroulante modifiable directement dans un tableau (statut, commercial…). */
function inlineSel(entity, row, k) {
  const f = SC(entity).fields[k];
  const opts = Object.assign({}, EN(f.opts));
  if (row[k] && !(row[k] in opts)) opts[row[k]] = row[k];
  return `<select data-patch="${entity}:${row.id}:${k}" aria-label="${esc(f.l)}">` + (f.req ? "" : `<option value="">${esc(f.empty || "—")}</option>`)
    + Object.entries(opts).map(([v, l]) => `<option value="${esc(v)}"${v === row[k] ? " selected" : ""}>${esc(l)}</option>`).join("") + "</select>";
}

function niceMax(v) {
  if (v <= 4) return 4;
  if (v < 10) return Math.ceil(v / 2) * 2;
  const p = Math.pow(10, Math.floor(Math.log10(v)));
  const m = v / p;
  return (m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10) * p;
}
const roundTop = (x, y, w, h, r) => `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;

/** Barres empilées dans le temps (une barre par jour ou par mois), avec info-bulle. */
function stackChart(series, keys, labels, colors, aria) {
  const tot = series.map(p => keys.reduce((a, k) => a + (p[k] || 0), 0));
  if (!tot.some(Boolean)) return empty("Aucun lead sur la période.");
  const W = 760, H = 210, pl = 34, pr = 6, pt = 10, pb = 24;
  const max = niceMax(Math.max(...tot));
  const n = series.length, cw = (W - pl - pr) / n, bw = Math.max(2, Math.min(26, cw * 0.62));
  const y = v => pt + (H - pt - pb) * (1 - v / max);
  let g = "";
  for (const t of [0, max / 2, max]) g += `<line class="grid" x1="${pl}" x2="${W - pr}" y1="${y(t)}" y2="${y(t)}"/><text class="axis" x="${pl - 6}" y="${y(t) + 3.5}" text-anchor="end">${num(t, t % 1 ? 1 : 0)}</text>`;
  const step = Math.ceil(n / 10);
  series.forEach((p, i) => {
    const x = pl + cw * i + (cw - bw) / 2;
    let base = y(0);
    const segs = keys.filter(k => p[k] > 0);
    segs.forEach((k, j) => {
      const h = (H - pt - pb) * p[k] / max;
      const top = base - h;
      if (j === segs.length - 1) g += `<path d="${roundTop(x, top, bw, h, Math.min(4, bw / 2, h))}" style="fill:var(${colors[k]})"/>`;
      else g += `<rect x="${x}" y="${top + Math.min(2, h / 2)}" width="${bw}" height="${Math.max(0, h - Math.min(2, h / 2))}" style="fill:var(${colors[k]})"/>`;
      base = top;
    });
    const tip = `<b>${esc(p.label)}</b>${keys.map(k => esc(labels[k]) + " : " + num(p[k] || 0)).join("<br>")}<br>Total : ${num(tot[i])}`;
    g += `<rect class="hit" x="${pl + cw * i}" y="${pt}" width="${cw}" height="${H - pt - pb}" data-tip="${esc(tip)}"/>`;
    if (i % step === 0) g += `<text class="axis" x="${pl + cw * i + cw / 2}" y="${H - 6}" text-anchor="middle">${esc(p.label)}</text>`;
  });
  const legend = `<div class="legend">${keys.map(k => `<span><i style="background:var(${colors[k]})"></i>${esc(labels[k])}</span>`).join("")}</div>`;
  return `<div class="chart">${legend}<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(aria)}">${g}</svg></div>`;
}

/** Barres horizontales (une seule couleur), valeurs écrites. */
function hbars(items, fmt = num) {
  if (!items.length) return empty("Aucune donnée sur la période.");
  const max = Math.max(1, ...items.map(i => i.value));
  return `<div class="hbars">${items.map(i => `<div class="hbar" data-tip="${esc("<b>" + esc(i.label) + "</b>" + (i.tip || fmt(i.value)))}"><span>${esc(i.label)}</span><span class="track"><i style="width:${(100 * i.value / max).toFixed(1)}%"></i></span><span class="v">${fmt(i.value)}${i.sub ? ` <span class="muted small">${i.sub}</span>` : ""}</span></div>`).join("")}</div>`;
}
const mapBars = (map, enumName, total) => hbars(Object.entries(map || {}).map(([k, v]) => ({ label: enumName ? (L(enumName, k) || "Non renseigné") : k, value: v, sub: total ? pct(v / total) : "" })));

/** Jauge (0 → 100 %) avec repère optionnel (ex. durée écoulée). */
function meter(p, cls = "", mark = null, tip = "") {
  const w = Math.max(0, Math.min(1, p || 0)) * 100;
  return `<div class="meter"${tip ? ` data-tip="${esc(tip)}"` : ""}><i class="${cls}" style="width:${w.toFixed(1)}%"></i>${mark != null ? `<span class="mark" style="left:calc(${(Math.min(1, mark) * 100).toFixed(1)}% - 1px)"></span>` : ""}</div>`;
}
function budgetCell(c) {
  if (!(c.budget > 0)) return '<span class="muted">pas de budget</span>';
  const p = c.budget_pct || 0;
  const cls = p > 1 ? "crit" : p >= 0.9 ? "warn" : "";
  const tip = `<b>${esc(c.name)}</b>Dépensé : ${money(c.spent)}<br>Budget : ${money(c.budget)}${c.elapsed != null ? "<br>Durée écoulée : " + pct(c.elapsed) + " (repère)" : ""}`;
  return `<div class="mcell">${meter(p, cls, c.elapsed, tip)}<span>${pct(p)}</span></div>`
    + (p > 1 ? '<span class="pill crit">⛔ Dépassé</span>' : p >= 0.9 ? '<span class="pill warn">⚠ Presque épuisé</span>' : "");
}
function targetCell(achieved, target, elapsed, unit = "") {
  if (!(target > 0)) return '<span class="muted">pas d\'objectif chiffré</span>';
  const p = achieved / target;
  const verdict = p >= 1 ? '<span class="pill good">✅ Atteint</span>'
    : elapsed != null && elapsed >= 0.5 && p < elapsed * 0.7 ? '<span class="pill warn">⚠ En retard</span>'
    : elapsed != null && p >= elapsed ? '<span class="pill blue">En avance</span>' : "";
  return `<div class="mcell">${meter(p, p >= 1 ? "good" : "", elapsed, `${num(achieved)} / ${num(target)} ${esc(unit)}`)}<span>${pct(p)}</span></div><div class="sub">${num(achieved)} / ${num(target)} ${esc(unit)} ${verdict}</div>`;
}

/* ================================================================ rapport */
const KHREF = {
  leads: "#leads/contacts", qualified: "#leads/treatment", qual_rate: "#leads/treatment", inquiries: "#social/inquiries/all",
  project_requests: "#projects/requests", raised: "#projects/funding", visits: "#web/pages", registrations: "#web/registrations",
  web_conv: "#web/pages", ads_spend: "#ads/budgets", ads_leads: "#ads/results", cpl: "#ads/results", posts: "#social/activity",
  social_reach: "#social/performance", emails_sent: "#email/sent", email_conversions: "#email/objectives",
};
const kval = (k, v) => v == null ? "—" : k.fmt === "pct" ? pct(v, 1) : k.fmt === "money" ? money(v) : num(v);
function kdelta(k) {
  const v = k.value, p = k.prev;
  if (v == null || p == null) return ["", ""];
  let up, txt;
  if (k.fmt === "pct") {
    const d = (v - p) * 100;
    if (Math.abs(d) < 0.05) return ["=", ""];
    up = d > 0; txt = (up ? "▲ " : "▼ ") + num(Math.abs(d), 1) + " pt";
  } else {
    if (+p === 0) return [v > 0 ? "nouveau" : "", ""];
    const d = (v - p) / p;
    if (Math.abs(d) < 0.005) return ["=", ""];
    up = d > 0; txt = (up ? "▲ " : "▼ ") + pct(Math.abs(d));
  }
  const cls = k.better === "neutral" ? "" : (up !== (k.better === "down")) ? "up" : "down";
  return [txt, cls];
}
function kpiTile(k, extraCls = "") {
  const [d, cls] = kdelta(k);
  const vs = d && d !== "nouveau" ? ` <span class="muted">vs ${kval(k, k.prev)}</span>` : "";
  const inner = `<small>${esc(k.label)}</small><b>${kval(k, k.value)}</b><span class="d ${cls}">${d || "&nbsp;"}${vs}</span>`;
  const href = KHREF[k.key];
  return href ? `<a class="kpi ${extraCls}" href="${href}">${inner}</a>` : `<div class="kpi ${extraCls}">${inner}</div>`;
}
const LV = { critical: ["⛔", "Urgent"], warning: ["⚠️", "Attention"], good: ["✅", "Positif"], info: ["ℹ️", "Info"] };
function insightsCard(list, title = "Analyse & décisions") {
  const body = list.length ? `<ul class="ins">${list.map(i => `<li class="${i.level}"><span class="lv">${LV[i.level][0]} ${LV[i.level][1]}</span><span>${esc(i.text)}</span></li>`).join("")}</ul>`
    : '<p class="muted">Rien de particulier à signaler sur la période.</p>';
  return card(title, body);
}
function backlogTiles(b) {
  const t = (label, v, href, cls = "") => `<a class="kpi ${v > 0 ? cls : ""}" href="${href}"><small>${esc(label)}</small><b>${num(v)}</b><span class="d">&nbsp;</span></a>`;
  return t("Leads à traiter", b.untreated, "#leads/treatment", "warn") + t("Sans contact depuis 48 h", b.stale, "#leads/treatment", "alert")
    + t("Leads non assignés", b.unassigned, "#leads/assignment", "warn") + t("Questions en attente", b.open_inquiries, "#social/inquiries/all", "warn")
    + t("Projets à étudier", b.pending_projects, "#projects/requests");
}
const TYPE_COLORS = { investisseur: "--s1", porteur: "--s2", partenaire: "--s3", autre: "--s4" };
function leadsChart(L) {
  if (!L.series || L.series.length < 2) return "";
  const keys = Object.keys(EN("contact_types"));
  return stackChart(L.series, keys, EN("contact_types"), TYPE_COLORS, "Nouveaux leads par " + (L.series_monthly ? "mois" : "jour") + " et par profil");
}
function sourceTable(L) {
  const rows = Object.entries(L.source_quality || {}).map(([s, v]) => ({ s, ...v }));
  return table(rows, [
    { l: "Source", h: r => esc(L_("sources", r.s)) },
    { l: "Leads", n: 1, h: r => num(r.total) },
    { l: "Part", n: 1, h: r => pct(ratio(r.total, L.total)) },
    { l: "Qualifiés", n: 1, h: r => num(r.qualified) },
    { l: "Taux de qualification", h: r => `<div class="mcell">${meter(ratio(r.qualified, r.total), "good")}<span>${pct(ratio(r.qualified, r.total))}</span></div>` },
    { l: "Convertis", n: 1, h: r => num(r.converted) },
  ], { foot: ["Total", num(L.total), "100 %", num(L.qualified), pct(L.qual_rate), num(L.converted)] });
}
const L_ = (n, k) => L(n, k) || "Non renseigné";
function commercialTable(L) {
  const rows = Object.entries(L.by_commercial || {}).map(([c, v]) => ({ c, ...v }));
  return table(rows, [
    { l: "Commercial", h: r => r.c ? esc(r.c) : '<span class="pill warn">⚠ Non assigné</span>' },
    { l: "Leads", n: 1, h: r => num(r.total) },
    { l: "À traiter", n: 1, h: r => r.untreated ? `<b>${num(r.untreated)}</b>` : "0" },
    { l: "Qualifiés", n: 1, h: r => num(r.qualified) },
    { l: "Convertis", n: 1, h: r => num(r.converted) },
    { l: "Taux de qualification", n: 1, h: r => pct(ratio(r.qualified, r.total)) },
  ]);
}
function fundingTable(list) {
  return table(list, [
    { l: "Projet", h: p => `<b>${esc(p.name)}</b><div class="sub">${esc(p.owner || "")}${p.sector ? " · " + esc(p.sector) : ""}</div>` },
    { l: "Levé", n: 1, h: p => money(p.raised) },
    { l: "Objectif", n: 1, h: p => money(p.target) },
    { l: "Avancement", h: p => `<div class="mcell">${meter(p.pct, p.pct >= 1 ? "good" : "")}<span>${pct(p.pct)}</span></div>` },
    { l: "Investisseurs", n: 1, h: p => num(p.investors) },
    { l: "Fin de la levée", h: p => p.end ? fdate(p.end) + (p.days_left != null && p.days_left >= 0 ? `<div class="sub">${p.days_left} jour(s) restant(s)</div>` : '<div class="sub">terminée</div>') : "—" },
    { l: "", h: p => `<button type="button" class="btn sm" data-edit="projects:${p.id}" data-preset="${esc(JSON.stringify({ as_of: S.today }))}">Mettre à jour</button>` },
  ], { emptyMsg: "Aucun projet en cours de financement." });
}
function webTable(W) {
  const rows = W.pages || [];
  return table(rows, [
    { l: "Page", h: p => `<b>${esc(p.name)}</b> <span class="pill ${p.type === "site" ? "blue" : ""}">${esc(L("page_types", p.type))}</span>${p.url ? `<div class="sub"><a href="${esc(p.url)}" target="_blank" rel="noopener">${esc(p.url.replace(/^https?:\/\//, ""))}</a></div>` : ""}` },
    { l: "Visites", n: 1, h: p => num(p.visits) },
    { l: "Visiteurs", n: 1, h: p => num(p.visitors) },
    { l: "Inscr. investisseurs", n: 1, h: p => num(p.reg_investors) },
    { l: "Inscr. porteurs", n: 1, h: p => num(p.reg_owners) },
    { l: "Formulaires", n: 1, h: p => num(p.forms) },
    { l: "Conversion", n: 1, h: p => pct(p.conv, 1) },
  ], { foot: ["Total", num(W.totals.visits), num(W.totals.visitors), num(W.totals.reg_investors), num(W.totals.reg_owners), num(W.totals.forms), pct(W.totals.conv, 1)], emptyMsg: "Aucune page : ajoutez le site et vos landing pages." });
}
function socialTable(SOC) {
  const rows = Object.entries(SOC.platforms || {}).map(([p, v]) => ({ p, ...v }));
  const t = SOC.totals;
  return table(rows, [
    { l: "Réseau", h: r => `<b>${esc(L("platforms", r.p))}</b>` },
    { l: "Publications", n: 1, h: r => num(r.posts) },
    { l: "Portée", n: 1, h: r => num(r.reach) },
    { l: "Impressions", n: 1, h: r => num(r.impressions) },
    { l: "Engagement", n: 1, h: r => num(r.engagement) },
    { l: "Taux d'engagement", n: 1, h: r => pct(r.eng_rate, 1) },
    { l: "Clics", n: 1, h: r => num(r.clicks) },
    { l: "Nouveaux abonnés", n: 1, h: r => num(r.new_followers) },
    { l: "Abonnés", n: 1, h: r => r.followers == null ? "—" : num(r.followers) },
  ], { foot: ["Total", num(SOC.posts), num(t.reach), num(t.impressions), num(t.engagement), pct(t.eng_rate, 1), num(t.clicks), num(t.new_followers), num(t.followers)] });
}
function adsTable(A) {
  const t = A.totals;
  return table(A.campaigns || [], [
    { l: "Campagne", h: c => `<a href="#ads/campaign/${c.id}"><b>${esc(c.name)}</b></a><div class="sub">${esc(L("ad_platforms", c.platform))}</div>` },
    { l: "Dépense", n: 1, h: c => money(c.spend) },
    { l: "Impressions", n: 1, h: c => num(c.impressions) },
    { l: "Clics", n: 1, h: c => num(c.clicks) },
    { l: "CTR", n: 1, h: c => pct(c.ctr, 2) },
    { l: "Leads", n: 1, h: c => num(c.leads) },
    { l: "Coût / lead", n: 1, h: c => money(c.cpl) },
    { l: "Conversions", n: 1, h: c => num(c.conversions) },
    { l: "Coût / conversion", n: 1, h: c => money(c.cpa) },
  ], { foot: ["Total", money(t.spend), num(t.impressions), num(t.clicks), pct(t.ctr, 2), num(t.leads), money(t.cpl), num(t.conversions), money(t.cpa)], emptyMsg: "Aucun résultat publicitaire saisi sur la période." });
}
function budgetsTable(list) {
  return table(list, [
    { l: "Campagne", h: c => `<a href="#ads/campaign/${c.id}"><b>${esc(c.name)}</b></a><div class="sub">${esc(L("campaign_status", c.status))} · ${fdate(c.start)} → ${c.end ? fdate(c.end) : "sans fin"}</div>` },
    { l: "Budget", n: 1, h: c => money(c.budget) },
    { l: "Dépensé", n: 1, h: c => money(c.spent) },
    { l: "Restant", n: 1, h: c => c.remaining == null ? "—" : c.remaining < 0 ? `<b style="color:var(--crit-ink)">${money(c.remaining)}</b>` : money(c.remaining) },
    { l: "Consommation (repère = durée écoulée)", h: budgetCell },
    { l: "Objectif", h: c => targetCell(c.achieved, c.target_value, c.elapsed, L("target_metrics", c.target_metric).toLowerCase()) },
  ], { emptyMsg: "Aucune campagne active sur la période." });
}
function emailTable(list) {
  return table(list, [
    { l: "Campagne", h: e => `<b>${esc(e.name)}</b><div class="sub">${fdate(e.date)}${e.subject ? " · " + esc(e.subject) : ""}</div>` },
    { l: "Envoyés", n: 1, h: e => num(e.sent) },
    { l: "Ouvertures*", n: 1, h: e => num(e.opens) },
    { l: "Taux d'ouverture*", n: 1, h: e => pct(e.open_rate) },
    { l: "Clics", n: 1, h: e => num(e.clicks) },
    { l: "Taux de clic", n: 1, h: e => pct(e.click_rate, 1) },
    { l: "Objectifs atteints", n: 1, h: e => num(e.conversions) + (e.target > 0 ? ` / ${num(e.target)}` : "") },
  ], { emptyMsg: "Aucune campagne email envoyée sur la période." });
}
const ESTIMATE = '<p class="small muted" style="margin:8px 0 0">* Les ouvertures sont des estimations : certaines messageries (Apple Mail, protections de la vie privée) bloquent ou faussent leur mesure.</p>';

function funnelChart(f) {
  if (!f.stages.length) return empty("Ce funnel n'a pas encore d'étapes.");
  const max = Math.max(1, ...f.stages.map(s => s.value));
  const ramp = ["--o1", "--o2", "--o3", "--o4", "--o5"];
  return `<div class="funnel">${f.stages.map((s, i) => {
    const color = ramp[Math.min(ramp.length - 1, Math.round(i * (ramp.length - 1) / Math.max(1, f.stages.length - 1)))];
    const conv = i > 0 ? `<div class="fs-conv${f.worst === i ? " worst" : ""}">↓ ${pct(s.conv_prev, 1)} passent à l'étape suivante · abandon ${pct(s.drop, 1)}${s.lost ? " (" + num(s.lost) + " perdus)" : ""}${f.worst === i ? " · ⚠ plus forte perte" : ""}</div>` : "";
    const tip = `<b>${esc(s.name)}</b>Prospects : ${num(s.value)}${s.conv_start != null ? "<br>Depuis l'entrée : " + pct(s.conv_start, 1) : ""}${s.target ? "<br>Objectif : " + num(s.target) + " (" + pct(s.target_pct) + ")" : ""}<br>Source : ${esc(L("stage_sources", s.source))}`;
    return conv + `<div data-tip="${esc(tip)}"><div class="fs-h"><span class="n">${i + 1}. ${esc(s.name)} ${s.tags.map(t => `<span class="tag">#${esc(t)}</span>`).join("")}</span><span class="v">${num(s.value)}${s.target ? ` <span class="muted small">/ ${num(s.target)}</span>` : ""}</span></div><div class="fs-bar"><i style="width:${(100 * s.value / max).toFixed(1)}%;background:var(${color})"></i></div></div>`;
  }).join("")}</div>`;
}
function funnelTable(f) {
  return table(f.stages, [
    { l: "Étape", h: s => `<b>${esc(s.name)}</b>${s.tags.length ? "<div>" + s.tags.map(t => `<span class="tag">#${esc(t)}</span>`).join("") + "</div>" : ""}` },
    { l: "Source des chiffres", h: s => `<span class="small">${esc(L("stage_sources", s.source))}</span>` },
    { l: "Prospects", n: 1, h: s => num(s.value) },
    { l: "Conv. étape préc.", n: 1, h: s => pct(s.conv_prev, 1) },
    { l: "Conv. depuis l'entrée", n: 1, h: s => pct(s.conv_start, 1) },
    { l: "Abandon", n: 1, h: s => s.drop == null ? "—" : (s === f.stages[f.worst] ? `<span class="pill warn">⚠ ${pct(s.drop, 1)}</span>` : pct(s.drop, 1)) },
    { l: "Objectif", h: s => s.target ? `<div class="mcell">${meter(s.target_pct, s.target_pct >= 1 ? "good" : "")}<span>${pct(s.target_pct)}</span></div>` : '<span class="muted">—</span>' },
  ]);
}
function funnelCard(f, extra = "") {
  const links = [];
  if (f.links.campaigns) links.push(f.links.campaigns + " campagne(s)");
  if (f.links.pages) links.push(f.links.pages + " page(s)");
  if (f.links.emails) links.push(f.links.emails + " email(s)");
  if (f.links.social.length) links.push(f.links.social.map(p => L("platforms", p)).join(", "));
  const head = `<p class="sub" style="margin:-4px 0 12px">${f.objective ? esc(f.objective) + " · " : ""}${links.length ? "Associé à : " + esc(links.join(" · ")) : "Aucune association"}${f.overall != null ? ` · <b>conversion globale ${pct(f.overall, 1)}</b>` : ""}</p>`;
  return card(`📊 ${esc(f.name)}`, head + funnelChart(f) + extra, `<a class="btn sm" href="#funnels/stages/${f.id}">Ouvrir</a>`);
}

/** En-tête d'un rapport : période, navigation, actions. */
function periodHead(tab, type, r, date) {
  const p = r.period;
  const unit = { day: "jour", week: "semaine", month: "mois" }[type];
  const cmp = p.partial ? `${unit === "jour" ? "" : "Période en cours, "}comparée au même nombre de jours : ${fdate(p.prev_from, false)} → ${fdate(p.prev_to)}` : `Comparé à : ${p.prev_from === p.prev_to ? fdate(p.prev_from) : fdate(p.prev_from, false) + " → " + fdate(p.prev_to)}`;
  return `<div class="toolbar no-print-keep">
      <button type="button" class="btn" data-shift="${tab}:${type}:${date}:-1" title="Précédent">◀</button>
      <input type="date" value="${date}" data-pick="${tab}" aria-label="Date">
      <button type="button" class="btn" data-shift="${tab}:${type}:${date}:1" title="Suivant">▶</button>
      <a class="btn ghost" href="#overview/${tab}">Aujourd'hui</a>
      <span class="grow"></span>
      <button type="button" class="btn" data-report="send:${type}:${date}">📧 Envoyer par email</button>
      <button type="button" class="btn" data-report="archive:${type}:${date}">🗄 Archiver</button>
      <button type="button" class="btn" data-report="csv:${type}:${date}">⬇ CSV</button>
      <button type="button" class="btn" data-report="print">🖨 Imprimer / PDF</button>
    </div>
    <div class="period"><h2>Rapport ${esc(r.type_label)} · ${esc(p.label)}</h2></div>
    <p class="compare">${esc(cmp)} · généré automatiquement à partir de toutes les sections.</p>`;
}
function shiftDate(type, date, dir) {
  return type === "day" ? addDays(date, dir) : type === "week" ? addDays(date, 7 * dir) : addMonths(date, dir);
}
S.lastReport = null;

/** Rapport complet (semaine, mois, archive). */
function reportBody(r) {
  const LD = r.leads;
  const typeSub = Object.entries(LD.by_type).map(([t, n]) => esc(L_("contact_types", t)) + " : " + num(n)).join(" · ");
  return `<div class="kpis">${r.kpis.map(k => kpiTile(k)).join("")}</div>
    ${insightsCard(r.insights)}
    <section class="card"><div class="card-h"><h2>À traiter maintenant</h2></div><div class="kpis" style="margin:0">${backlogTiles(r.backlog)}</div></section>
    ${card("👥 Leads & Contacts", `<p class="sub" style="margin:-4px 0 10px">${num(LD.total)} nouveau(x) — ${typeSub || "aucun"} · ${num(LD.qualified)} qualifié(s), ${num(LD.non_qualified)} non qualifié(s), ${num(LD.converted)} converti(s)</p>
      ${leadsChart(LD)}
      <h3>Par source</h3>${sourceTable(LD)}
      <div class="grid2" style="margin-top:12px"><div><h3>Traitement</h3>${mapBars(LD.by_status, "contact_status", LD.total)}</div><div><h3>Raisons de non-qualification</h3>${mapBars(LD.reasons, "reasons", LD.non_qualified)}</div></div>
      <h3>Par commercial</h3>${commercialTable(LD)}`, `<a class="btn sm" href="#leads/contacts">Voir les leads</a>`)}
    ${card("💬 Questions & Inquiries", `<p class="sub" style="margin:-4px 0 10px">${num(r.inquiries.total)} reçue(s), ${num(r.inquiries.answered)} répondue(s) (${pct(r.inquiries.answer_rate)})</p><div class="grid2"><div><h3>Par canal</h3>${mapBars(r.inquiries.by_source, "sources", r.inquiries.total)}</div><div><h3>Par sujet</h3>${mapBars(r.inquiries.by_category, "inq_categories", r.inquiries.total)}</div></div>`)}
    ${card("📁 Projets", `<p class="sub" style="margin:-4px 0 10px">${num(r.projects.requests)} nouvelle(s) demande(s) · <b>${money(r.projects.raised)}</b> levés sur la période · ${num(r.projects.investors)} nouvelle(s) participation(s)${r.projects.funded.length ? " · financé(s) : " + esc(r.projects.funded.join(", ")) : ""}</p>
      <h3>Projets en cours de financement</h3>${fundingTable(r.projects.funding)}
      <h3>Nouvelles demandes de projets</h3>${table(r.projects.requests_list, [{ l: "Date", h: p => fdate(p.date) }, { l: "Projet", h: p => `<b>${esc(p.name)}</b><div class="sub">${esc(p.owner || "")}</div>` }, { l: "Secteur", h: p => esc(p.sector || "—") }, { l: "Montant recherché", n: 1, h: p => money(p.amount_target) }, { l: "Statut", h: p => esc(L("project_status", p.status)) }], { emptyMsg: "Aucune nouvelle demande sur la période." })}`)}
    ${card("🌐 Website Analytics", webTable(r.web), `<a class="btn sm" href="#web/pages">Détails</a>`)}
    ${card("📱 Social Media", socialTable(r.social), `<a class="btn sm" href="#social/performance">Détails</a>`)}
    ${card("📢 Ads Manager", `<h3 style="margin-top:0">Résultats de la période</h3>${adsTable(r.ads)}<h3>Budgets & objectifs (cumul depuis le début de chaque campagne)</h3>${budgetsTable(r.ads.budgets)}`, `<a class="btn sm" href="#ads/campaigns">Campagnes</a>`)}
    ${card("✉️ Email Marketing", emailTable(r.email.list) + (r.email.list.length ? ESTIMATE : ""), `<a class="btn sm" href="#email/campaigns">Campagnes</a>`)}
    ${r.funnels.length ? `<h2 style="margin:22px 0 12px">📊 Funnel Analytics</h2>` + r.funnels.map(f => funnelCard(f)).join("") : ""}`;
}

/* ================================================================ vues : Overview */
const VIEWS = {};

VIEWS["overview/daily"] = { fn: async (param, tok) => {
  const date = isDate(param) ? param : S.today;
  const r = (await api("report", { type: "day", date })).report;
  S.lastReport = r;
  const k = Object.fromEntries(r.kpis.map(x => [x.key, x]));
  const d = r.day, b = r.backlog;
  remember("contacts", [...d.contacts, ...b.leads_list]);
  remember("inquiries", [...d.inquiries, ...b.inquiries_list]);
  remember("posts", d.posts);
  remember("projects", d.projects);
  const leadCols = [
    { l: "Reçu le", h: c => fdate(c.date) },
    { l: "Contact", h: c => `<b>${esc(c.name)}</b><div class="sub">${esc([c.phone, c.email, c.city].filter(Boolean).join(" · "))}</div>` },
    { l: "Profil", h: c => esc(L("contact_types", c.type)) },
    { l: "Source", h: c => esc(L("sources", c.source)) + (c.campaign_id ? `<div class="sub">${esc(refName("campaigns", c.campaign_id))}</div>` : "") },
    { l: "Commercial", h: c => inlineSel("contacts", c, "assigned_to") },
    { l: "Traitement", h: c => inlineSel("contacts", c, "status") },
  ];
  const inqCols = [
    { l: "Reçue le", h: q => fdate(q.date) },
    { l: "Question", wrap: 1, h: q => `<b>${esc(q.name || "Anonyme")}</b> <span class="muted small">${esc(L("sources", q.source))} · ${esc(L("inq_categories", q.category))}</span><div class="sub">${esc(q.question)}</div>` },
    { l: "Statut", h: q => inlineSel("inquiries", q, "status") },
  ];
  return periodHead("daily", "day", r, date)
    + `<div class="kpis">${["leads", "qualified", "inquiries", "posts", "project_requests"].map(x => kpiTile(k[x])).join("")}${backlogTiles(b)}</div>
    <div class="toolbar">${addBtn("contacts", "Lead / contact", { date }, "btn pri")}${addBtn("inquiries", "Question", { date })}${addBtn("posts", "Publication", { date })}${addBtn("projects", "Demande de projet", { date })}</div>
    ${insightsCard(r.insights, "Analyse du jour & décisions")}
    ${card(`👥 Nouveaux leads & contacts du jour <span class="muted small">(${num(d.contacts.length)})</span>`, table(d.contacts, leadCols, { entity: "contacts", emptyMsg: "Aucun lead enregistré ce jour." }), addBtn("contacts", "Lead", { date }, "btn sm"))}
    <div class="grid2">
      ${card(`💬 Questions & inquiries du jour <span class="muted small">(${num(d.inquiries.length)})</span>`, table(d.inquiries, inqCols, { entity: "inquiries", emptyMsg: "Aucune question ce jour." }), addBtn("inquiries", "Question", { date }, "btn sm"))}
      ${card(`📱 Activité social media du jour <span class="muted small">(${num(d.posts.length)})</span>`, table(d.posts, [{ l: "Réseau", h: p => `<b>${esc(L("platforms", p.platform))}</b><div class="sub">${esc(L("post_formats", p.format))}</div>` }, { l: "Sujet", wrap: 1, h: p => esc(p.topic) + (p.link ? ` <a href="${esc(p.link)}" target="_blank" rel="noopener">↗</a>` : "") }, { l: "Portée", n: 1, h: p => num(p.reach) }], { entity: "posts", emptyMsg: "Aucune publication ce jour." }), addBtn("posts", "Publication", { date }, "btn sm"))}
    </div>
    ${card(`📁 Nouvelles demandes de projets <span class="muted small">(${num(d.projects.length)})</span>`, table(d.projects, [{ l: "Projet", h: p => `<b>${esc(p.name)}</b><div class="sub">${esc(p.owner || "")}</div>` }, { l: "Secteur", h: p => esc(p.sector || "—") }, { l: "Montant recherché", n: 1, h: p => money(p.amount_target) }, { l: "Source", h: p => esc(L("sources", p.source) || "—") }, { l: "Statut", h: p => inlineSel("projects", p, "status") }], { entity: "projects", emptyMsg: "Aucune demande de projet ce jour." }), addBtn("projects", "Demande", { date }, "btn sm"))}
    <h2 style="margin:22px 0 12px">🔔 À traiter (tous jours confondus, du plus ancien au plus récent)</h2>
    ${card(`Leads en attente de traitement ou d'assignation <span class="muted small">(${num(b.leads_list.length)}${b.leads_list.length >= 60 ? "+" : ""})</span>`, table(b.leads_list, leadCols, { entity: "contacts", max: 15, more: '<a href="#leads/treatment/nouveau">voir tous les leads à traiter</a>', emptyMsg: "Aucun lead en attente 🎉" }))}
    ${card(`Questions en attente de réponse <span class="muted small">(${num(b.inquiries_list.length)})</span>`, table(b.inquiries_list, inqCols, { entity: "inquiries", max: 15, more: '<a href="#social/inquiries/all">voir toutes les questions</a>', emptyMsg: "Aucune question en attente 🎉" }))}`;
} };

for (const [tab, type] of [["weekly", "week"], ["monthly", "month"]]) {
  VIEWS["overview/" + tab] = { fn: async param => {
    const date = isDate(param) ? param : S.today;
    const r = (await api("report", { type, date })).report;
    S.lastReport = r;
    return periodHead(tab, type, r, date) + reportBody(r);
  } };
}

VIEWS["overview/archives"] = { fn: async param => {
  if (param) {
    const d = await api("archive", { id: param });
    const r = d.report;
    S.lastReport = r;
    return `<div class="banner">🗄 Archive figée le ${fdate(new Date(d.meta.created * 1000).toISOString().slice(0, 10))}${d.meta.sent_to ? " · envoyée à " + esc(d.meta.sent_to) : ""} — les chiffres sont ceux du moment de l'archivage.</div>
      <div class="toolbar"><a class="btn" href="#overview/archives">← Toutes les archives</a><span class="grow"></span><button type="button" class="btn" data-report="print">🖨 Imprimer / PDF</button></div>
      <div class="period"><h2>Rapport ${esc(r.type_label)} · ${esc(r.period.label)}</h2></div><p class="compare">Comparé à ${fdate(r.period.prev_from, false)} → ${fdate(r.period.prev_to)}</p>` + reportBody(r);
  }
  const rows = (await api("archives")).rows;
  const T = { day: "Quotidien", week: "Hebdomadaire", month: "Mensuel", custom: "Période" };
  return `<p class="muted" style="margin-top:0">Chaque matin, la tâche automatique archive (et envoie par email) le rapport de la veille, le lundi celui de la semaine écoulée et le 1er celui du mois écoulé. Un rapport archivé garde les chiffres du moment où il a été produit.</p>`
    + card("🗄 Rapports archivés", table(rows, [
      { l: "Rapport", h: a => `<a href="#overview/archives/${a.id}"><b>${esc(a.label)}</b></a>` },
      { l: "Type", h: a => `<span class="pill blue">${T[a.type] || a.type}</span>` },
      { l: "Produit le", h: a => fdate(new Date(a.created * 1000).toISOString().slice(0, 10)) + " · " + (a.origin === "cron" ? "automatique" : a.origin.endsWith("-echec") ? '<span class="pill warn">⚠ envoi échoué</span>' : "manuel") },
      { l: "Envoyé à", wrap: 1, h: a => esc(a.sent_to || "—") },
      { l: "", h: a => `<a class="btn sm" href="#overview/archives/${a.id}">Ouvrir</a> <button type="button" class="icon-btn del" data-archive-del="${a.id}" title="Supprimer">🗑</button>` },
    ], { emptyMsg: "Aucun rapport archivé pour l'instant (le premier arrivera demain matin si la tâche automatique est installée)." }));
} };

/* ================================================================ vues : Funnels */
function funnelPicker(selected, base) {
  const fs = REF("funnels");
  return `<select data-goto="${base}" aria-label="Funnel">${fs.map(f => `<option value="${f.id}"${+f.id === +selected ? " selected" : ""}>${esc(f.name)}${f.status !== "actif" ? " (" + esc(L("funnel_status", f.status)) + ")" : ""}</option>`).join("")}</select>`;
}
const NEW_FUNNEL = `<button type="button" class="btn pri" data-funnel-edit="0">＋ Créer un funnel</button>`;

VIEWS["funnels/list"] = { range: true, fn: async () => {
  const fs = REF("funnels");
  const intro = `<p class="muted" style="margin-top:0">Un funnel suit les étapes d'un parcours (ex. Publicité → Landing Page → Inscription → Investissement). Chaque étape prend ses chiffres dans une saisie manuelle ou automatiquement dans les campagnes, pages, emails ou réseaux associés. Période : ${esc(rangeLabel())}.</p>`;
  if (!fs.length) return intro + empty("Aucun funnel pour l'instant.", NEW_FUNNEL);
  const [from, to] = curRange();
  const data = await Promise.all(fs.map(f => cached("funnel", { id: f.id, from, to })));
  return intro + `<div class="toolbar">${NEW_FUNNEL}</div>` + data.map(d => funnelCard(d.data,
    `<div class="toolbar" style="margin:12px 0 0"><span class="pill ${d.funnel.status === "actif" ? "good" : ""}">${esc(L("funnel_status", d.funnel.status))}</span><span class="grow"></span><button type="button" class="btn sm" data-funnel-edit="${d.funnel.id}">✏️ Modifier</button><button type="button" class="btn sm del" data-del="funnels:${d.funnel.id}">🗑 Supprimer</button></div>`)).join("");
} };

VIEWS["funnels/stages"] = { range: true, fn: async param => {
  const fs = REF("funnels");
  if (!fs.length) return empty("Aucun funnel pour l'instant.", NEW_FUNNEL);
  const id = +param || +fs[0].id;
  const [from, to] = curRange();
  const d = await cached("funnel", { id, from, to });
  const f = d.funnel;
  remember("funnel_entries", d.entries);
  const manual = f.stages.filter(s => s.source === "manual");
  const L_links = [
    ...REF("campaigns").filter(c => f.links.campaigns.includes(+c.id)).map(c => `<a class="tag" href="#ads/campaign/${c.id}">📢 ${esc(c.name)}</a>`),
    ...REF("pages").filter(p => f.links.pages.includes(+p.id)).map(p => `<span class="tag">🌐 ${esc(p.name)}</span>`),
    ...REF("emails").filter(e => f.links.emails.includes(+e.id)).map(e => `<span class="tag">✉️ ${esc(e.name)}</span>`),
    ...f.links.social.map(p => `<span class="tag">📱 ${esc(L("platforms", p))}</span>`),
  ];
  return `<div class="toolbar">${funnelPicker(id, "funnels/stages")}<span class="grow"></span><button type="button" class="btn" data-funnel-edit="${f.id}">✏️ Étapes, tags & associations</button>${NEW_FUNNEL}</div>`
    + card(`📊 ${esc(f.name)}`, `<p class="sub" style="margin:-4px 0 10px">${esc(f.objective || "")} · ${esc(L("audiences", f.audience) || "Toutes cibles")} · ${esc(L("funnel_status", f.status))}${f.description ? "<br>" + esc(f.description) : ""}</p>
      <h3>Associé à</h3><div>${L_links.join(" ") || '<span class="muted">Rien pour l\'instant : associez des campagnes, pages, emails ou réseaux dans « Étapes, tags & associations ».</span>'}</div>
      <h3>Étapes (${esc(rangeLabel())})</h3>${funnelTable(d.data)}`)
    + card("Évolution des prospects par étape", funnelChart(d.data))
    + card("✍️ Chiffres saisis à la main", (manual.length ? `<p class="sub" style="margin:-4px 0 10px">Étapes en saisie manuelle : ${manual.map(s => "« " + esc(s.name) + " »").join(", ")}. Les autres étapes se remplissent toutes seules.</p>` : '<p class="sub">Toutes les étapes se remplissent automatiquement.</p>')
      + table(d.entries, [{ l: "Date", h: e => fdate(e.date) }, { l: "Étape", h: e => esc(refName("funnel_stages", e.stage_id)) }, { l: "Prospects", n: 1, h: e => num(e.count) }, { l: "Note", h: e => esc(e.notes || "") }], { entity: "funnel_entries", emptyMsg: "Aucun chiffre saisi." }),
      manual.length ? addBtn("funnel_entries", "Saisir des chiffres", { funnel_id: f.id, stage_id: manual[0].id }, "btn sm pri") : "");
} };

VIEWS["funnels/conversions"] = { range: true, fn: async param => {
  const fs = REF("funnels");
  if (!fs.length) return empty("Aucun funnel pour l'instant.", NEW_FUNNEL);
  const [from, to] = curRange();
  const list = param ? fs.filter(f => +f.id === +param) : fs;
  const data = await Promise.all(list.map(f => cached("funnel", { id: f.id, from, to })));
  const chips = `<div class="chips"><a class="chip${param ? "" : " on"}" href="#funnels/conversions">Tous</a>${fs.map(f => `<a class="chip${+param === +f.id ? " on" : ""}" href="#funnels/conversions/${f.id}">${esc(f.name)}</a>`).join("")}</div>`;
  return chips + `<p class="muted" style="margin-top:0">Prospects par étape, taux de conversion, taux d'abandon et étape où l'on perd le plus — ${esc(rangeLabel())}.</p>`
    + data.map(d => card(`📊 ${esc(d.data.name)}${d.data.overall != null ? ` <span class="pill blue">conversion globale ${pct(d.data.overall, 1)}</span>` : ""}`, funnelTable(d.data) + `<div style="margin-top:14px">${funnelChart(d.data)}</div>`, `<a class="btn sm" href="#funnels/stages/${d.data.id}">Étapes & tags</a>`)).join("");
} };

/* ================================================================ vues : leads & questions (réutilisées par Social / Website) */
const socialKeys = () => Object.keys(EN("platforms"));
function contactCols() {
  return [
    { l: "Date", h: c => fdate(c.date) },
    { l: "Contact", h: c => `<b>${esc(c.name)}</b><div class="sub">${esc([c.phone, c.email, c.city].filter(Boolean).join(" · "))}</div>` },
    { l: "Profil", h: c => esc(L("contact_types", c.type)) },
    { l: "Source", h: c => esc(L("sources", c.source)) + `<div class="sub">${esc([refName("campaigns", c.campaign_id), refName("pages", c.page_id), refName("emails", c.email_id)].filter(Boolean).join(" · "))}</div>` },
    { l: "Commercial", h: c => inlineSel("contacts", c, "assigned_to") },
    { l: "Traitement", h: c => inlineSel("contacts", c, "status") + (c.status === "non_qualifie" && c.reason ? `<div class="sub">${esc(L("reasons", c.reason))}${c.reason_note ? " — " + esc(c.reason_note) : ""}</div>` : "") },
    { l: "Notes", wrap: 1, h: c => c.notes ? `<span class="small">${esc(c.notes.slice(0, 120))}</span>` : "" },
  ];
}
function inquiryCols() {
  return [
    { l: "Date", h: q => fdate(q.date) },
    { l: "Canal", h: q => esc(L("sources", q.source)) },
    { l: "De", h: q => `<b>${esc(q.name || "Anonyme")}</b><div class="sub">${esc(q.contact || "")}${q.type ? " · " + esc(L("contact_types", q.type)) : ""}</div>` },
    { l: "Question", wrap: 1, h: q => `<span class="pill">${esc(L("inq_categories", q.category))}</span><div class="sub">${esc(q.question)}</div>${q.answer ? `<div class="sub">↳ ${esc(q.answer.slice(0, 160))}</div>` : ""}` },
    { l: "Pris en charge", h: q => inlineSel("inquiries", q, "assigned_to") },
    { l: "Statut", h: q => inlineSel("inquiries", q, "status") },
  ];
}
/** Filtre de canal : social (tous les réseaux), un réseau, all, other. */
function channelChips(base, sel, withAll = true) {
  const opts = [["social", "Tous les réseaux"], ...socialKeys().map(p => [p, L("platforms", p)])];
  if (withAll) opts.push(["other", "Autres canaux"], ["all", "Tous les canaux"]);
  return `<div class="chips">${opts.map(([k, l]) => `<a class="chip${k === sel ? " on" : ""}" href="#${base}/${k}">${esc(l)}</a>`).join("")}<a class="chip" href="#settings" style="margin-left:auto">⚙️ Gérer les réseaux</a></div>`;
}
const chanFilter = sel => r => sel === "all" ? true : sel === "social" ? socialKeys().includes(r.source) : sel === "other" ? !socialKeys().includes(r.source) : r.source === sel;

VIEWS["social/leads"] = { range: true, fn: async param => {
  const sel = param || "social";
  const rows = (await rangeList("contacts")).filter(chanFilter(sel));
  const by = {};
  for (const r of rows) by[r.source] = (by[r.source] || 0) + 1;
  const q = rows.filter(r => ["qualifie", "converti"].includes(r.status)).length;
  const preset = socialKeys().includes(sel) ? { source: sel } : {};
  return channelChips("social/leads", sel)
    + `<div class="kpis"><div class="kpi"><small>Leads & contacts</small><b>${num(rows.length)}</b><span class="d">${esc(rangeLabel())}</span></div><div class="kpi"><small>Qualifiés</small><b>${num(q)}</b><span class="d">${pct(ratio(q, rows.length))}</span></div><div class="kpi"><small>À traiter</small><b>${num(rows.filter(r => r.status === "nouveau").length)}</b><span class="d">&nbsp;</span></div><div class="kpi"><small>Non assignés</small><b>${num(rows.filter(r => !r.assigned_to).length)}</b><span class="d">&nbsp;</span></div></div>`
    + (sel === "social" || sel === "all" || sel === "other" ? card("Répartition par source", mapBars(by, "sources", rows.length)) : "")
    + card("Leads & contacts", table(rows, contactCols(), { entity: "contacts", emptyMsg: "Aucun lead sur ce canal pour la période." }), addBtn("contacts", "Lead", preset, "btn sm pri") + exportBtn("contacts"));
} };

VIEWS["social/inquiries"] = { range: true, fn: async param => {
  const sel = param || "social";
  const rows = (await rangeList("inquiries")).filter(chanFilter(sel));
  const open = rows.filter(r => ["nouveau", "en_cours"].includes(r.status)).length;
  const cat = {};
  for (const r of rows) cat[r.category] = (cat[r.category] || 0) + 1;
  return channelChips("social/inquiries", sel)
    + `<div class="kpis"><div class="kpi"><small>Questions reçues</small><b>${num(rows.length)}</b><span class="d">${esc(rangeLabel())}</span></div><div class="kpi ${open ? "warn" : ""}"><small>En attente</small><b>${num(open)}</b><span class="d">&nbsp;</span></div><div class="kpi"><small>Taux de réponse</small><b>${pct(ratio(rows.length - open, rows.length))}</b><span class="d">&nbsp;</span></div></div>`
    + card("Sujets des questions", mapBars(cat, "inq_categories", rows.length))
    + card("Questions & inquiries", table(rows, inquiryCols(), { entity: "inquiries", emptyMsg: "Aucune question sur ce canal pour la période." }), addBtn("inquiries", "Question", socialKeys().includes(sel) ? { source: sel } : {}, "btn sm pri") + exportBtn("inquiries"));
} };

VIEWS["social/activity"] = { range: true, fn: async param => {
  const sel = param || "social";
  const rows = (await rangeList("posts")).filter(r => sel === "social" || r.platform === sel);
  const byP = {}, byF = {};
  let reach = 0, eng = 0;
  for (const r of rows) { byP[r.platform] = (byP[r.platform] || 0) + 1; byF[r.format] = (byF[r.format] || 0) + 1; reach += +r.reach; eng += +r.engagement; }
  return channelChips("social/activity", sel, false)
    + `<div class="kpis"><div class="kpi"><small>Publications</small><b>${num(rows.length)}</b><span class="d">${esc(rangeLabel())}</span></div><div class="kpi"><small>Portée des publications</small><b>${num(reach)}</b><span class="d">&nbsp;</span></div><div class="kpi"><small>Engagement</small><b>${num(eng)}</b><span class="d">${pct(ratio(eng, reach), 1)} de la portée</span></div></div>`
    + `<div class="grid2">${card("Par réseau", mapBars(byP, "platforms", rows.length))}${card("Par format", mapBars(byF, "post_formats", rows.length))}</div>`
    + card("Publications", table(rows, [col("posts", "date", { h: r => fdate(r.date) }), { l: "Réseau", h: r => `<b>${esc(L("platforms", r.platform))}</b><div class="sub">${esc(L("post_formats", r.format))}</div>` }, { l: "Sujet", wrap: 1, h: r => esc(r.topic) + (r.link ? ` <a href="${esc(r.link)}" target="_blank" rel="noopener">↗</a>` : "") }, col("posts", "reach"), col("posts", "impressions"), col("posts", "engagement"), col("posts", "clicks")], { entity: "posts", emptyMsg: "Aucune publication enregistrée sur la période." }), addBtn("posts", "Publication", socialKeys().includes(sel) ? { platform: sel } : {}, "btn sm pri") + exportBtn("posts"));
} };

VIEWS["social/performance"] = { range: true, fn: async () => {
  const [r, rows] = await Promise.all([rangeReport(), rangeList("social_stats")]);
  const t = r.social.totals, p = r.prev.social.totals;
  const k = (label, v, pv, fmt = "int") => kpiTile({ key: "", label, value: v, prev: pv, fmt, better: "up" });
  return `<p class="muted" style="margin-top:0">Saisissez chaque semaine (ou chaque jour) les chiffres de chaque réseau, relevés dans Meta Business Suite, LinkedIn et TikTok. ${esc(rangeLabel())}, comparé à la période précédente.</p>`
    + `<div class="kpis">${k("Portée", t.reach, p.reach)}${k("Impressions", t.impressions, p.impressions)}${k("Engagement", t.engagement, p.engagement)}${k("Taux d'engagement", t.eng_rate, p.eng_rate, "pct")}${k("Clics", t.clicks, p.clicks)}${k("Nouveaux abonnés", t.new_followers, p.new_followers)}<div class="kpi"><small>Abonnés (total)</small><b>${num(t.followers)}</b><span class="d">dernier relevé</span></div></div>`
    + card("Performances par réseau", socialTable(r.social))
    + card("Relevés saisis", table(rows, [col("social_stats", "date", { h: x => fdate(x.date) }), { l: "Réseau", h: x => `<b>${esc(L("platforms", x.platform))}</b>` }, col("social_stats", "followers"), col("social_stats", "new_followers"), col("social_stats", "reach"), col("social_stats", "impressions"), { l: "Engagement", n: 1, h: x => num(x.engagement) }, col("social_stats", "clicks")], { entity: "social_stats", emptyMsg: "Aucun relevé sur la période." }), addBtn("social_stats", "Relevé", null, "btn sm pri") + exportBtn("social_stats"));
} };

/* ================================================================ vues : Ads Manager */
const NEW_CAMPAIGN = addBtn("campaigns", "Nouvelle campagne", null, "btn pri");
VIEWS["ads/campaigns"] = { fn: async () => {
  const rows = (await cached("campaigns_overview")).rows;
  remember("campaigns", REF("campaigns"));
  const active = rows.filter(c => c.status === "active");
  const sum = (l, k) => l.reduce((a, c) => a + (+c[k] || 0), 0);
  return `<div class="toolbar">${NEW_CAMPAIGN}${addBtn("ad_results", "Saisir des résultats")}<span class="grow"></span>${exportBtn("campaigns")}</div>`
    + `<div class="kpis"><div class="kpi"><small>Campagnes actives</small><b>${num(active.length)}</b><span class="d">sur ${num(rows.length)}</span></div><div class="kpi"><small>Budget des actives</small><b>${money(sum(active, "budget"))}</b><span class="d">&nbsp;</span></div><div class="kpi"><small>Dépensé (actives)</small><b>${money(sum(active, "spent"))}</b><span class="d">${pct(ratio(sum(active, "spent"), sum(active, "budget")))} du budget</span></div><div class="kpi"><small>Leads (actives)</small><b>${num(active.reduce((a, c) => a + c.totals.leads, 0))}</b><span class="d">&nbsp;</span></div></div>`
    + card("📢 Campagnes", table(rows, [
      { l: "Campagne", h: c => `<a href="#ads/campaign/${c.id}"><b>${esc(c.name)}</b></a><div class="sub">${esc(L("ad_platforms", c.platform))} · ${esc(L("ad_objectives", c.objective))}</div>` },
      { l: "Statut", h: c => `<span class="pill ${c.status === "active" ? "good" : c.status === "pause" ? "warn" : ""}">${esc(L("campaign_status", c.status))}</span>` },
      { l: "Dates", h: c => `${fdate(c.start)}<div class="sub">→ ${c.end ? fdate(c.end) : "sans fin"}</div>` },
      { l: "Budget", n: 1, h: c => money(c.budget) },
      { l: "Dépensé", n: 1, h: c => money(c.spent) },
      { l: "Leads", n: 1, h: c => num(c.totals.leads) },
      { l: "Coût / lead", n: 1, h: c => money(c.totals.cpl) },
      { l: "Conversions", n: 1, h: c => num(c.totals.conversions) },
      { l: "Funnel", h: c => c.funnel_id ? `<a href="#funnels/stages/${c.funnel_id}">${esc(refName("funnels", c.funnel_id))}</a>` : '<span class="muted">—</span>' },
      { l: "", h: c => `<a class="btn sm" href="#ads/campaign/${c.id}">Fiche</a>` },
    ], { entity: "campaigns", emptyMsg: "Aucune campagne pour l'instant." }));
} };

VIEWS["ads/objectives"] = { fn: async () => {
  const rows = (await cached("campaigns_overview")).rows.filter(c => c.status !== "brouillon");
  return `<p class="muted" style="margin-top:0">Objectif marketing et objectif chiffré de chaque campagne, comparés au temps écoulé (le repère vertical sur la jauge).</p>`
    + card("🎯 Objectifs des campagnes", table(rows, [
      { l: "Campagne", h: c => `<a href="#ads/campaign/${c.id}"><b>${esc(c.name)}</b></a><div class="sub">${esc(L("campaign_status", c.status))}</div>` },
      { l: "Objectif marketing", h: c => esc(L("ad_objectives", c.objective)) },
      { l: "Indicateur", h: c => esc(L("target_metrics", c.target_metric)) },
      { l: "Avancement (repère = durée écoulée)", h: c => targetCell(c.achieved, c.target_value, c.elapsed, L("target_metrics", c.target_metric).toLowerCase()) },
      { l: "Durée écoulée", n: 1, h: c => c.elapsed == null ? "—" : pct(c.elapsed) + (c.days_left != null && c.status === "active" ? `<div class="sub">${c.days_left} j restant(s)</div>` : "") },
      { l: "", h: c => `<button type="button" class="btn sm" data-edit="campaigns:${c.id}">Modifier l'objectif</button>` },
    ], { emptyMsg: "Aucune campagne." }), NEW_CAMPAIGN);
} };

VIEWS["ads/budgets"] = { fn: async () => {
  const rows = (await cached("campaigns_overview")).rows.filter(c => c.status !== "brouillon");
  const act = rows.filter(c => c.status === "active");
  const b = act.reduce((a, c) => a + c.budget, 0), s = act.reduce((a, c) => a + c.spent, 0);
  return `<div class="kpis"><div class="kpi"><small>Budget prévu (actives)</small><b>${money(b)}</b><span class="d">&nbsp;</span></div><div class="kpi"><small>Dépensé</small><b>${money(s)}</b><span class="d">${pct(ratio(s, b))}</span></div><div class="kpi ${b - s < 0 ? "alert" : ""}"><small>Restant</small><b>${money(b - s)}</b><span class="d">&nbsp;</span></div></div>`
    + card("💰 Budgets", budgetsTable(rows) + `<p class="small muted" style="margin:8px 0 0">La jauge montre la part du budget dépensée ; le repère vertical, la part de la durée de la campagne déjà écoulée. Une jauge très en avance sur le repère = la campagne dépense trop vite.</p>`, addBtn("ad_results", "Saisir une dépense", null, "btn sm pri"));
} };

VIEWS["ads/results"] = { range: true, fn: async () => {
  const [r, rows] = await Promise.all([rangeReport(), rangeList("ad_results")]);
  const t = r.ads.totals, p = r.prev.ads.totals;
  const k = (label, v, pv, fmt = "int", better = "up") => kpiTile({ key: "", label, value: v, prev: pv, fmt, better });
  return `<div class="kpis">${k("Dépense", t.spend, p.spend, "money", "neutral")}${k("Impressions", t.impressions, p.impressions)}${k("Clics", t.clicks, p.clicks)}${k("CTR", t.ctr, p.ctr, "pct")}${k("Coût par clic", t.cpc, p.cpc, "money", "down")}${k("Leads", t.leads, p.leads)}${k("Coût par lead", t.cpl, p.cpl, "money", "down")}${k("Conversions", t.conversions, p.conversions)}${k("Coût par conversion", t.cpa, p.cpa, "money", "down")}</div>`
    + card("Résultats par campagne · " + esc(rangeLabel()), adsTable(r.ads))
    + card("Résultats saisis", table(rows, [col("ad_results", "date", { h: x => fdate(x.date) }), { l: "Campagne", h: x => `<a href="#ads/campaign/${x.campaign_id}">${esc(refName("campaigns", x.campaign_id))}</a>` }, col("ad_results", "spend"), col("ad_results", "impressions"), col("ad_results", "clicks"), col("ad_results", "leads"), col("ad_results", "conversions")], { entity: "ad_results", emptyMsg: "Aucun résultat saisi sur la période." }), addBtn("ad_results", "Saisir des résultats", null, "btn sm pri") + exportBtn("ad_results"));
} };

VIEWS["ads/campaign"] = { fn: async param => {
  const d = await cached("campaign", { id: param });
  const c = d.campaign, s = d.snapshot, t = s.totals;
  remember("campaigns", [c]);
  remember("ad_results", d.results);
  remember("contacts", d.leads);
  const st = Object.fromEntries(d.leads_stats.map(x => [x.status, +x.n]));
  const tile = (l, v, sub = "&nbsp;") => `<div class="kpi"><small>${esc(l)}</small><b>${v}</b><span class="d">${sub}</span></div>`;
  const info = [["Plateforme", L("ad_platforms", c.platform)], ["Objectif marketing", L("ad_objectives", c.objective)], ["Cible", L("audiences", c.audience) || "—"], ["Dates", fdate(c.start) + " → " + (c.end ? fdate(c.end) : "sans fin")], ["Landing page", refName("pages", c.page_id) || "—"], ["Funnel", refName("funnels", c.funnel_id) || "—"]];
  return `<div class="toolbar"><a class="btn" href="#ads/campaigns">← Campagnes</a><span class="grow"></span><button type="button" class="btn" data-edit="campaigns:${c.id}">✏️ Modifier la campagne</button>${addBtn("ad_results", "Saisir des résultats", { campaign_id: c.id }, "btn pri")}</div>`
    + card(`📢 ${esc(c.name)} <span class="pill ${c.status === "active" ? "good" : c.status === "pause" ? "warn" : ""}">${esc(L("campaign_status", c.status))}</span>`,
      `<div class="kpis" style="margin-bottom:8px">${info.map(([l, v]) => `<div class="kpi txt"><small>${esc(l)}</small><b>${esc(v)}</b></div>`).join("")}</div>${c.notes ? `<p class="sub">${esc(c.notes)}</p>` : ""}
      <div class="grid2"><div><h3>🎯 Objectif</h3>${targetCell(s.achieved, s.target_value, s.elapsed, L("target_metrics", s.target_metric).toLowerCase())}</div><div><h3>💰 Budget</h3>${budgetCell(Object.assign({ name: c.name }, s))}<div class="sub">${money(s.spent)} dépensés sur ${money(s.budget)} · reste ${money(s.remaining)}</div></div></div>`)
    + `<div class="kpis">${tile("Impressions", num(t.impressions))}${tile("Clics", num(t.clicks), "CTR " + pct(t.ctr, 2))}${tile("Coût par clic", money(t.cpc))}${tile("Leads (déclarés)", num(t.leads))}${tile("Coût par lead", money(t.cpl))}${tile("Conversions", num(t.conversions))}${tile("Coût par conversion", money(t.cpa))}${tile("Leads suivis", num(d.leads.length), `${num((st.qualifie || 0) + (st.converti || 0))} qualifiés · ${num(st.converti || 0)} convertis`)}</div>`
    + (d.funnel ? funnelCard(d.funnel) : card("📊 Funnel", empty("Aucun funnel associé à cette campagne.", `<button type="button" class="btn sm" data-edit="campaigns:${c.id}">Associer un funnel</button>`)))
    + card("Résultats saisis", table(d.results, [col("ad_results", "date", { h: x => fdate(x.date) }), col("ad_results", "spend"), col("ad_results", "impressions"), col("ad_results", "clicks"), col("ad_results", "leads"), col("ad_results", "conversions")], { entity: "ad_results", emptyMsg: "Aucun résultat saisi." }))
    + card("Leads issus de la campagne", table(d.leads, contactCols(), { entity: "contacts", emptyMsg: "Aucun lead rattaché à cette campagne (champ « Campagne publicitaire » du lead)." }), addBtn("contacts", "Lead", { campaign_id: c.id }, "btn sm"));
} };

/* ================================================================ vues : Website */
function webKpis(t, p) {
  const k = (label, v, pv, fmt = "int") => kpiTile({ key: "", label, value: v, prev: pv, fmt, better: "up" });
  return `<div class="kpis">${k("Visites", t.visits, p && p.visits)}${k("Visiteurs uniques", t.visitors, p && p.visitors)}${k("Inscriptions investisseurs", t.reg_investors, p && p.reg_investors)}${k("Inscriptions porteurs", t.reg_owners, p && p.reg_owners)}${k("Formulaires / contacts", t.forms, p && p.forms)}${k("Taux de conversion", t.conv, p && p.conv, "pct")}</div>`;
}
const statCols = () => [col("web_stats", "date", { h: x => fdate(x.date) }), { l: "Page", h: x => esc(refName("pages", x.page_id)) }, col("web_stats", "visits"), col("web_stats", "visitors"), { l: "Inscr. invest.", n: 1, h: x => num(x.reg_investors) }, { l: "Inscr. porteurs", n: 1, h: x => num(x.reg_owners) }, col("web_stats", "forms")];

VIEWS["web/website"] = { range: true, fn: async () => {
  const sites = REF("pages").filter(p => p.type === "site");
  if (!sites.length) return empty("Ajoutez d'abord la page du site principal.", addBtn("pages", "Site principal", { type: "site", name: "Site " + (S.boot.settings.org || "YINVESTI") }, "btn pri"));
  const [r, rows] = await Promise.all([rangeReport(), rangeList("web_stats")]);
  const ids = sites.map(p => +p.id);
  const W = { pages: r.web.pages.filter(p => ids.includes(p.id)), totals: r.web.site };
  return `<p class="muted" style="margin-top:0">Site principal ${esc(S.boot.settings.org)} · ${esc(rangeLabel())}. Les chiffres viennent de Google Analytics (ou de l'outil de statistiques du site), saisis par jour ou par semaine.</p>` + webKpis(r.web.site)
    + card("🌐 Site principal", webTable(W))
    + card("Statistiques saisies", table(rows.filter(x => ids.includes(+x.page_id)), statCols(), { entity: "web_stats", emptyMsg: "Aucune statistique saisie sur la période." }), addBtn("web_stats", "Saisir des statistiques", { page_id: sites[0].id }, "btn sm pri"));
} };

VIEWS["web/pages"] = { range: true, fn: async () => {
  const [r, rows] = await Promise.all([rangeReport(), rangeList("web_stats")]);
  remember("pages", REF("pages"));
  return webKpis(r.web.totals, r.prev.web.totals)
    + card("🔗 Performances par page · " + esc(rangeLabel()), webTable(r.web), addBtn("pages", "Page / landing page", null, "btn sm") + addBtn("web_stats", "Statistiques", null, "btn sm pri"))
    + card("Pages suivies", table(REF("pages"), [col("pages", "name", { h: p => `<b>${esc(p.name)}</b>` }), col("pages", "type"), col("pages", "url"), col("pages", "audience"), col("pages", "funnel_id")], { entity: "pages", emptyMsg: "Aucune page." }))
    + card("Statistiques saisies", table(rows, statCols(), { entity: "web_stats", emptyMsg: "Aucune statistique saisie sur la période." }), exportBtn("web_stats"));
} };

VIEWS["web/registrations"] = { range: true, fn: async () => {
  const [r, rows] = await Promise.all([rangeReport(), rangeList("web_stats")]);
  const t = r.web.totals, p = r.prev.web.totals;
  const k = (label, v, pv) => kpiTile({ key: "", label, value: v, prev: pv, fmt: "int", better: "up" });
  const pages = r.web.pages.filter(x => x.registrations > 0 || x.visits > 0);
  return `<div class="kpis">${k("Nouveaux investisseurs inscrits", t.reg_investors, p.reg_investors)}${k("Nouveaux porteurs inscrits", t.reg_owners, p.reg_owners)}${k("Total inscriptions", t.registrations, p.registrations)}${kpiTile({ key: "", label: "Visites → inscription", value: ratio(t.registrations, t.visits), prev: ratio(p.registrations, p.visits), fmt: "pct", better: "up" })}</div>`
    + card("Inscriptions par page", table(pages, [
      { l: "Page", h: x => `<b>${esc(x.name)}</b>` },
      { l: "Investisseurs", n: 1, h: x => num(x.reg_investors) },
      { l: "Porteurs de projets", n: 1, h: x => num(x.reg_owners) },
      { l: "Total", n: 1, h: x => num(x.registrations) },
      { l: "Visites", n: 1, h: x => num(x.visits) },
      { l: "Taux d'inscription", n: 1, h: x => pct(ratio(x.registrations, x.visits), 1) },
    ], { emptyMsg: "Aucune inscription saisie sur la période." }))
    + card("Statistiques saisies", table(rows.filter(x => +x.reg_investors + +x.reg_owners > 0), statCols(), { entity: "web_stats", emptyMsg: "Aucune inscription saisie." }), addBtn("web_stats", "Saisir des statistiques", null, "btn sm pri"));
} };

VIEWS["web/leads"] = { range: true, fn: async () => {
  const rows = (await rangeList("contacts")).filter(c => ["site", "landing"].includes(c.source) || c.page_id);
  const byPage = {};
  for (const c of rows) { const k = c.page_id ? refName("pages", c.page_id) : L("sources", c.source); byPage[k] = (byPage[k] || 0) + 1; }
  return `<p class="muted" style="margin-top:0">Contacts et formulaires reçus depuis le site et les landing pages (source « Site web » ou « Landing page », ou page renseignée) · ${esc(rangeLabel())}.</p>`
    + card("Par page", mapBars(byPage, null, rows.length))
    + card("Leads & contacts du site", table(rows, contactCols(), { entity: "contacts", emptyMsg: "Aucun contact venu du site sur la période." }), addBtn("contacts", "Lead", { source: "landing" }, "btn sm pri"));
} };

/* ================================================================ vues : Email */
async function emailRows() {
  const rows = await rangeList("emails");
  return rows.map(e => Object.assign({}, e, { open_rate: ratio(e.opens, e.sent), click_rate: ratio(e.clicks, e.sent), ctor: ratio(e.clicks, e.opens), conv_rate: ratio(e.conversions, e.sent), target_pct: ratio(e.conversions, e.target), unsub_rate: ratio(e.unsubscribes, e.sent) }));
}
function emailKpis(rows) {
  const s = k => rows.filter(e => e.status === "envoyee").reduce((a, e) => a + +e[k], 0);
  const sent = s("sent");
  const tile = (l, v, sub = "&nbsp;") => `<div class="kpi"><small>${esc(l)}</small><b>${v}</b><span class="d">${sub}</span></div>`;
  return `<div class="kpis">${tile("Campagnes envoyées", num(rows.filter(e => e.status === "envoyee").length), esc(rangeLabel()))}${tile("Emails envoyés", num(sent))}${tile("Taux d'ouverture*", pct(ratio(s("opens"), sent)), num(s("opens")) + " ouvertures")}${tile("Taux de clic", pct(ratio(s("clicks"), sent), 1), num(s("clicks")) + " clics")}${tile("Objectifs atteints", num(s("conversions")), pct(ratio(s("conversions"), sent), 1) + " des envois")}${tile("Désabonnements", num(s("unsubscribes")), pct(ratio(s("unsubscribes"), sent), 2))}</div>`;
}
const NEW_EMAIL = addBtn("emails", "Campagne email", null, "btn pri");
const emailName = e => `<b>${esc(e.name)}</b><div class="sub">${fdate(e.date)}${e.subject ? " · " + esc(e.subject) : ""}</div>`;
const emailStatus = e => `<span class="pill ${e.status === "envoyee" ? "good" : "blue"}">${esc(L("email_status", e.status))}</span>`;

VIEWS["email/campaigns"] = { range: true, fn: async () => {
  const rows = await emailRows();
  return emailKpis(rows) + card("✉️ Campagnes email", table(rows, [
    { l: "Campagne", h: emailName }, { l: "Statut", h: emailStatus }, { l: "Liste / segment", h: e => esc(e.segment || "—") },
    { l: "Envoyés", n: 1, h: e => num(e.sent) }, { l: "Ouverture*", n: 1, h: e => pct(e.open_rate) }, { l: "Clic", n: 1, h: e => pct(e.click_rate, 1) },
    { l: "Objectifs", n: 1, h: e => num(e.conversions) + (e.target ? " / " + num(e.target) : "") },
    { l: "Funnel", h: e => e.funnel_id ? `<a href="#funnels/stages/${e.funnel_id}">${esc(refName("funnels", e.funnel_id))}</a>` : '<span class="muted">—</span>' },
  ], { entity: "emails", emptyMsg: "Aucune campagne email sur la période." }) + ESTIMATE, NEW_EMAIL + exportBtn("emails"));
} };
VIEWS["email/sent"] = { range: true, fn: async () => {
  const rows = await emailRows();
  return emailKpis(rows) + card("📤 Emails envoyés", table(rows, [
    { l: "Campagne", h: emailName }, { l: "Statut", h: emailStatus }, { l: "Liste / segment", h: e => esc(e.segment || "—") },
    { l: "Envoyés", n: 1, h: e => num(e.sent) }, { l: "Désabonnements", n: 1, h: e => num(e.unsubscribes) }, { l: "Taux de désabonnement", n: 1, h: e => pct(e.unsub_rate, 2) },
  ], { entity: "emails", emptyMsg: "Aucun envoi sur la période." }), NEW_EMAIL);
} };
VIEWS["email/engagement"] = { range: true, fn: async () => {
  const rows = (await emailRows()).filter(e => e.status === "envoyee");
  return emailKpis(rows) + card("👁 Ouvertures & clics", table(rows, [
    { l: "Campagne", h: emailName }, { l: "Envoyés", n: 1, h: e => num(e.sent) },
    { l: "Ouvertures*", n: 1, h: e => num(e.opens) }, { l: "Taux d'ouverture*", h: e => `<div class="mcell">${meter(e.open_rate)}<span>${pct(e.open_rate)}</span></div>` + (e.sent >= 50 && e.open_rate != null && e.open_rate < 0.15 ? '<span class="pill warn">⚠ Faible</span>' : "") },
    { l: "Clics", n: 1, h: e => num(e.clicks) }, { l: "Taux de clic", n: 1, h: e => pct(e.click_rate, 1) }, { l: "Clics / ouvertures", n: 1, h: e => pct(e.ctor, 1) },
  ], { entity: "emails", emptyMsg: "Aucun envoi sur la période." }) + ESTIMATE);
} };
VIEWS["email/objectives"] = { range: true, fn: async () => {
  const rows = (await emailRows()).filter(e => e.status === "envoyee");
  return emailKpis(rows) + card("🎯 Objectifs atteints", table(rows, [
    { l: "Campagne", h: emailName }, { l: "Objectif", wrap: 1, h: e => esc(e.objective || "—") },
    { l: "Atteints", n: 1, h: e => num(e.conversions) }, { l: "Avancement", h: e => targetCell(+e.conversions, +e.target, null, "") },
    { l: "Taux de conversion", n: 1, h: e => pct(e.conv_rate, 2) },
    { l: "Funnel", h: e => e.funnel_id ? `<a href="#funnels/stages/${e.funnel_id}">${esc(refName("funnels", e.funnel_id))}</a>` : '<span class="muted">—</span>' },
  ], { entity: "emails", emptyMsg: "Aucun envoi sur la période." }));
} };

/* ================================================================ vues : Leads & Contacts */
const exportBtn = (entity) => { const [from, to] = curRange(); return `<a class="btn sm" href="api.php?action=export&entity=${entity}${SC(entity).date ? "&from=" + from + "&to=" + to : ""}">⬇ CSV</a>`; };

VIEWS["leads/contacts"] = { range: true, fn: async () => {
  const rows = await rangeList("contacts");
  const sel = (id, label, opts) => `<select id="${id}" aria-label="${esc(label)}"><option value="">${esc(label)} : tous</option>${Object.entries(opts).map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join("")}</select>`;
  S.contactRows = rows;
  return `<div class="toolbar">${addBtn("contacts", "Lead / contact", null, "btn pri")}<input type="search" id="fQ" placeholder="Rechercher (nom, téléphone, email…)" style="min-width:220px">
      ${sel("fType", "Profil", EN("contact_types"))}${sel("fSource", "Source", EN("sources"))}${sel("fStatus", "Traitement", EN("contact_status"))}${sel("fCom", "Commercial", Object.assign({ "-": "Non assigné" }, EN("commercials")))}
      <span class="grow"></span>${exportBtn("contacts")}</div><div id="contactsOut"></div>`;
}, after: () => {
  const draw = () => {
    const q = ($("#fQ").value || "").toLowerCase().trim();
    const f = { type: $("#fType").value, source: $("#fSource").value, status: $("#fStatus").value, com: $("#fCom").value };
    const rows = S.contactRows.filter(c => (!f.type || c.type === f.type) && (!f.source || c.source === f.source) && (!f.status || c.status === f.status)
      && (!f.com || (f.com === "-" ? !c.assigned_to : c.assigned_to === f.com))
      && (!q || [c.name, c.phone, c.email, c.city, c.notes].join(" ").toLowerCase().includes(q)));
    $("#contactsOut").innerHTML = card(`👥 Contacts <span class="muted small">(${num(rows.length)} · ${esc(rangeLabel())})</span>`, table(rows, contactCols(), { entity: "contacts", emptyMsg: "Aucun contact ne correspond." }));
  };
  for (const id of ["fQ", "fType", "fSource", "fStatus", "fCom"]) $("#" + id).addEventListener("input", draw);
  draw();
} };

VIEWS["leads/profiles"] = { range: true, fn: async param => {
  const sel = param || "investisseur";
  const [r, rows] = await Promise.all([rangeReport(), rangeList("contacts")]);
  const tq = r.leads.type_quality;
  const list = rows.filter(c => c.type === sel);
  return `<div class="chips">${Object.entries(EN("contact_types")).map(([k, l]) => `<a class="chip${k === sel ? " on" : ""}" href="#leads/profiles/${k}">${esc(l)} (${num((tq[k] || {}).total || 0)})</a>`).join("")}</div>`
    + card("Investisseurs ou porteurs de projets · " + esc(rangeLabel()), table(Object.entries(tq).map(([t, v]) => ({ t, ...v })), [
      { l: "Profil", h: x => `<b>${esc(L("contact_types", x.t))}</b>` }, { l: "Leads", n: 1, h: x => num(x.total) }, { l: "Part", n: 1, h: x => pct(ratio(x.total, r.leads.total)) },
      { l: "Qualifiés", n: 1, h: x => num(x.qualified) }, { l: "Taux de qualification", n: 1, h: x => pct(ratio(x.qualified, x.total)) }, { l: "Convertis", n: 1, h: x => num(x.converted) },
    ]) + leadsChart(r.leads))
    + card(esc(L("contact_types", sel)) + ` <span class="muted small">(${num(list.length)})</span>`, table(list, contactCols(), { entity: "contacts", emptyMsg: "Aucun lead de ce profil sur la période." }), addBtn("contacts", "Lead", { type: sel }, "btn sm pri"));
} };

VIEWS["leads/sources"] = { range: true, fn: async () => {
  const r = await rangeReport();
  const L0 = r.leads;
  return `<p class="muted" style="margin-top:0">D'où viennent les leads, et surtout lesquels se qualifient : une source qui apporte beaucoup de leads peu qualifiés coûte du temps commercial. ${esc(rangeLabel())}.</p>`
    + card("Leads par source", hbars(Object.entries(L0.source_quality).map(([s, v]) => ({ label: L("sources", s), value: v.total, sub: pct(ratio(v.qualified, v.total)) + " qualifiés", tip: `${num(v.total)} leads<br>${num(v.qualified)} qualifiés<br>${num(v.converted)} convertis` }))))
    + card("Qualité par source", sourceTable(L0));
} };

VIEWS["leads/assignment"] = { range: true, fn: async () => {
  const [r, rows] = await Promise.all([rangeReport(), rangeList("contacts")]);
  const un = rows.filter(c => !c.assigned_to && !["non_qualifie", "converti"].includes(c.status));
  const hint = Object.keys(EN("commercials")).length ? "" : `<div class="banner">Ajoutez les commerciaux dans <a href="#settings">Réglages</a> pour pouvoir leur assigner des leads.</div>`;
  return hint + card("Par commercial · " + esc(rangeLabel()), commercialTable(r.leads))
    + card(`⚠ Leads non assignés <span class="muted small">(${num(un.length)})</span>`, table(un, contactCols(), { entity: "contacts", emptyMsg: "Tous les leads de la période sont assignés 🎉" }));
} };

VIEWS["leads/treatment"] = { range: true, fn: async param => {
  const sel = param || "nouveau";
  const [r, rows] = await Promise.all([rangeReport(), rangeList("contacts")]);
  const L0 = r.leads;
  const list = rows.filter(c => sel === "all" || c.status === sel);
  return `<div class="grid2">${card("Traitement des leads · " + esc(rangeLabel()), mapBars(L0.by_status, "contact_status", L0.total))}${card("Pourquoi non qualifiés ?", mapBars(L0.reasons, "reasons", L0.non_qualified))}</div>`
    + `<div class="chips">${Object.entries(EN("contact_status")).map(([k, l]) => `<a class="chip${k === sel ? " on" : ""}" href="#leads/treatment/${k}">${esc(l)} (${num(L0.by_status[k] || 0)})</a>`).join("")}<a class="chip${sel === "all" ? " on" : ""}" href="#leads/treatment/all">Tous</a></div>`
    + card(esc(sel === "all" ? "Tous les leads" : L("contact_status", sel)) + ` <span class="muted small">(${num(list.length)})</span>`, table(list, contactCols(), { entity: "contacts", emptyMsg: "Aucun lead dans ce statut sur la période." }));
} };

/* ================================================================ vues : Projets */
VIEWS["projects/requests"] = { range: true, fn: async () => {
  const rows = await rangeList("projects");
  const by = {};
  for (const p of rows) by[p.status] = (by[p.status] || 0) + 1;
  return `<div class="kpis"><div class="kpi"><small>Demandes reçues</small><b>${num(rows.length)}</b><span class="d">${esc(rangeLabel())}</span></div><div class="kpi"><small>Montant total recherché</small><b>${money(rows.reduce((a, p) => a + +p.amount_target, 0))}</b><span class="d">&nbsp;</span></div><div class="kpi"><small>Acceptées</small><b>${num(rows.filter(p => ["accepte", "en_financement", "finance"].includes(p.status)).length)}</b><span class="d">&nbsp;</span></div></div>`
    + card("Par statut", mapBars(by, "project_status", rows.length))
    + card("📁 Demandes de projets", table(rows, [
      { l: "Date", h: p => fdate(p.date) }, { l: "Projet", h: p => `<b>${esc(p.name)}</b><div class="sub">${esc([p.owner, p.contact].filter(Boolean).join(" · "))}</div>` },
      { l: "Secteur", h: p => esc(p.sector || "—") }, { l: "Montant recherché", n: 1, h: p => money(p.amount_target) }, { l: "Source", h: p => esc(L("sources", p.source) || "—") },
      { l: "Statut", h: p => inlineSel("projects", p, "status") },
    ], { entity: "projects", emptyMsg: "Aucune demande de projet sur la période." }), addBtn("projects", "Demande de projet", null, "btn sm pri") + exportBtn("projects"));
} };

VIEWS["projects/funding"] = { range: true, fn: async () => {
  const r = await rangeReport();
  const f = r.projects.funding;
  const raised = f.reduce((a, p) => a + p.raised, 0), target = f.reduce((a, p) => a + p.target, 0);
  remember("projects", (await allList("projects", { status: "en_financement" })));
  return `<div class="kpis"><div class="kpi"><small>Projets en financement</small><b>${num(f.length)}</b><span class="d">&nbsp;</span></div><div class="kpi"><small>Levé (total)</small><b>${money(raised)}</b><span class="d">${pct(ratio(raised, target))} de ${money(target)}</span></div>${kpiTile({ key: "", label: "Levé sur la période", value: r.projects.raised, prev: null, fmt: "money", better: "up" })}<div class="kpi"><small>Nouvelles participations</small><b>${num(r.projects.investors)}</b><span class="d">${esc(rangeLabel())}</span></div></div>`
    + card("💹 Projets en cours de financement", fundingTable(f) + `<p class="small muted" style="margin:8px 0 0">« Mettre à jour » : saisissez le montant levé total et la date du relevé ; le montant levé pendant chaque semaine et chaque mois en est déduit automatiquement.</p>`, addBtn("projects", "Projet", { status: "en_financement" }, "btn sm pri"));
} };

/* ================================================================ vue : Réglages */
VIEWS.settings = { fn: async () => {
  const s = S.boot.settings;
  const cronCmd = "/usr/local/bin/php ~/public_html/marketing/cron.php > /dev/null 2>&1";
  const usage = (await api("platforms_usage")).usage;
  S.platState = { plats: Object.entries(S.boot.enums.platforms).map(([key, label]) => ({ key, label })), usage };
  return `<div class="set-grid">
    ${card("Organisation", `<div class="form" style="grid-template-columns:1fr 1fr"><div class="fld"><label for="sOrg">Nom</label><input type="text" id="sOrg" value="${esc(s.org)}"></div><div class="fld"><label for="sCur">Devise</label><input type="text" id="sCur" value="${esc(s.currency)}"></div>
      <div class="fld full"><label for="sCom">Commerciaux (un par ligne)</label><textarea id="sCom" rows="5">${esc(s.commercials.join("\n"))}</textarea><small>Proposés dans « Commercial responsable » des leads et des questions.</small></div></div>`)}
    ${card("Réseaux sociaux", `<p class="sub" style="margin:-4px 0 10px">Utilisés dans Social Media, Funnel Analytics et pour filtrer les sources des leads. Renommer un réseau garde ses publications et relevés déjà saisis.</p>
      <div class="stages" id="platStages"></div>
      <div class="toolbar" style="margin:10px 0 0"><button type="button" class="btn sm" id="platAdd">＋ Ajouter un réseau</button><span class="grow"></span><button type="button" class="btn pri sm" id="platSave">Enregistrer les réseaux</button></div>`)}
    ${card("Rapports automatiques par email", `<div class="form" style="grid-template-columns:1fr"><div class="fld"><label for="sRec">Destinataires (une adresse par ligne)</label><textarea id="sRec" rows="4">${esc(s.recipients.join("\n"))}</textarea></div>
      <div class="checks"><label><input type="checkbox" id="sD"${s.send_daily ? " checked" : ""}> Quotidien (chaque matin, la veille)</label><label><input type="checkbox" id="sW"${s.send_weekly ? " checked" : ""}> Hebdomadaire (le lundi)</label><label><input type="checkbox" id="sM"${s.send_monthly ? " checked" : ""}> Mensuel (le 1er)</label></div>
      <div class="fld"><label for="sUrl">Adresse du tableau de bord (lien dans les emails)</label><input type="url" id="sUrl" value="${esc(s.dashboard_url)}" placeholder="${esc(S.boot.url)}"></div>
      <div class="ctrl"><input type="email" id="sTest" placeholder="adresse pour un email de test"><button type="button" class="btn" id="sTestBtn">Envoyer un test</button></div>
      <small class="muted">Envoi : ${S.boot.mail.smtp ? "compte SMTP " + esc(S.boot.mail.from) : "fonction mail() du serveur (" + esc(S.boot.mail.from) + ")"}.</small></div>`)}
  </div>
  <div class="toolbar"><button type="button" class="btn pri" id="sSave">Enregistrer les réglages</button></div>
  ${card("Installer l'envoi automatique (une seule fois)", `<p style="margin-top:0">Dans <b>cPanel → Tâches Cron</b>, ajouter une tâche <b>une fois par jour</b> (par exemple Minute <code>5</code>, Heure <code>7</code>, autres champs <code>*</code>) avec la commande :</p><pre class="code">${esc(cronCmd)}</pre>
    <p class="small muted">Chaque matin : rapport de la veille ; le lundi : rapport de la semaine écoulée ; le 1er : rapport du mois écoulé. Les rapports sont archivés dans Overview → Archives, même sans destinataire.</p>`)}
  ${card("Exporter les données (CSV / Excel)", `<div class="ctrl">${Object.keys(S.boot.schema).map(e => `<a class="btn sm" href="api.php?action=export&entity=${e}">⬇ ${esc(S.boot.schema[e].label)}</a>`).join("")}</div>`)}`;
}, after: () => {
  $("#sSave").addEventListener("click", async () => {
    try {
      const d = await api("settings_save", {}, { org: $("#sOrg").value, currency: $("#sCur").value, commercials: $("#sCom").value, recipients: $("#sRec").value, send_daily: $("#sD").checked, send_weekly: $("#sW").checked, send_monthly: $("#sM").checked, dashboard_url: $("#sUrl").value });
      S.boot.settings = d.settings; S.boot.enums = d.enums;
      $("#orgName").textContent = d.settings.org;
      S.cache.clear();
      toast("Réglages enregistrés ✓");
      route();
    } catch (e) { toast(e.message); }
  });
  $("#sTestBtn").addEventListener("click", async () => {
    try { const d = await api("test_mail", {}, { email: $("#sTest").value }); toast("Email de test envoyé à " + d.to); } catch (e) { toast(e.message); }
  });

  // Réseaux sociaux : liste modifiable (ajouter, renommer, réordonner, retirer).
  const st = S.platState;
  const drawPlats = () => {
    $("#platStages").innerHTML = st.plats.map((p, i) => {
      const n = p.key ? st.usage[p.key] : 0;
      return `<div class="plat-row" data-i="${i}" data-key="${esc(p.key)}"><span class="num">${i + 1}</span>
        <input type="text" data-k="label" value="${esc(p.label)}" placeholder="ex. YouTube" aria-label="Nom du réseau ${i + 1}">
        <span class="usage">${n ? n + " donnée(s)" : ""}</span>
        <span class="ctrl"><button type="button" class="icon-btn" data-mv="${i}:-1" title="Monter">↑</button><button type="button" class="icon-btn" data-mv="${i}:1" title="Descendre">↓</button><button type="button" class="icon-btn del" data-rm="${i}" title="Retirer">✕</button></span></div>`;
    }).join("");
  };
  const syncPlats = () => {
    st.plats = $$(".plat-row[data-i]", $("#platStages")).map(r => ({ key: r.dataset.key || "", label: $("[data-k=label]", r).value }));
  };
  drawPlats();
  $("#platAdd").addEventListener("click", () => {
    if (st.plats.length >= 12) return toast("12 réseaux sociaux au maximum.");
    syncPlats();
    st.plats.push({ key: "", label: "" });
    drawPlats();
    const inputs = $$("#platStages [data-k=label]");
    inputs[inputs.length - 1].focus();
  });
  $("#platStages").addEventListener("click", e => {
    const mv = e.target.closest("[data-mv]"), rm = e.target.closest("[data-rm]");
    if (mv) {
      syncPlats();
      const [i, d] = mv.dataset.mv.split(":").map(Number);
      const j = i + d;
      if (j >= 0 && j < st.plats.length) { [st.plats[i], st.plats[j]] = [st.plats[j], st.plats[i]]; drawPlats(); }
    }
    if (rm) {
      syncPlats();
      if (st.plats.length <= 1) return toast("Gardez au moins un réseau social.");
      const p = st.plats[+rm.dataset.rm];
      const n = p.key ? st.usage[p.key] : 0;
      if (n && !confirm(`« ${p.label} » est cité par ${n} donnée(s) (publications, relevés…). Le retirer de la liste ? Les données déjà saisies garderont l'ancien nom.`)) return;
      st.plats.splice(+rm.dataset.rm, 1);
      drawPlats();
    }
  });
  $("#platSave").addEventListener("click", async () => {
    syncPlats();
    if (st.plats.some(p => !p.label.trim())) return toast("Donnez un nom à chaque réseau.");
    try {
      const d = await api("settings_save", {}, { social_platforms: st.plats.map(p => ({ key: p.key, label: p.label.trim() })) });
      S.boot.settings = d.settings; S.boot.enums = d.enums;
      S.cache.clear();
      toast("Réseaux sociaux enregistrés ✓");
      route();
    } catch (e) { toast(e.message); }
  });
} };

/* ================================================================ formulaires */
function openModal(html) { $("#modalBox").innerHTML = html; $("#modal").hidden = false; const f = $("#modalBox input:not([type=hidden]), #modalBox select, #modalBox textarea"); if (f) f.focus(); }
function closeModal() { $("#modal").hidden = true; $("#modalBox").innerHTML = ""; }
$("#modal").addEventListener("mousedown", e => { if (e.target.id === "modal") closeModal(); });
document.addEventListener("keydown", e => { if (e.key === "Escape" && !$("#modal").hidden) closeModal(); });

function defaults(entity) {
  const out = {};
  for (const [k, f] of Object.entries(SC(entity).fields)) out[k] = f.def === "today" ? S.today : (f.def != null ? f.def : "");
  return out;
}
function optionsFor(entity, k, f, v, vals) {
  if (f.t === "ref") {
    let list = REF(f.ref).map(r => [String(r.id), r.name]);
    if (f.ref === "funnel_stages") {
      const fun = REF("funnels").find(x => +x.id === +vals.funnel_id) || REF("funnels")[0];
      list = fun ? fun.stages.filter(s => s.source === "manual").map(s => [String(s.id), s.name]) : [];
    }
    return (f.req ? "" : '<option value="">—</option>') + list.map(([id, n]) => `<option value="${id}"${String(v) === id ? " selected" : ""}>${esc(n)}</option>`).join("");
  }
  const opts = Object.assign({}, EN(f.opts));
  if (v && !(v in opts)) opts[v] = v;
  return (f.req ? "" : `<option value="">${esc(f.empty || "—")}</option>`) + Object.entries(opts).map(([o, l]) => `<option value="${esc(o)}"${o === v ? " selected" : ""}>${esc(l)}</option>`).join("");
}
function fieldHtml(entity, k, f, v, vals) {
  const id = "f_" + k, req = f.req ? " required" : "";
  const val = v == null ? "" : v;
  let input, full = false;
  switch (f.t) {
    case "date": input = `<input type="date" id="${id}" name="${k}" value="${esc(val)}"${req}>`; break;
    case "int": input = `<input type="number" id="${id}" name="${k}" min="0" step="1" inputmode="numeric" value="${val === "" || val === 0 && !vals.id ? "" : esc(val)}"${req}>`; break;
    case "money": input = `<input type="number" id="${id}" name="${k}" min="0" step="any" inputmode="decimal" value="${val === "" || +val === 0 && !vals.id ? "" : esc(+val)}"${req}>`; break;
    case "longtext": full = true; input = `<textarea id="${id}" name="${k}"${req}>${esc(val)}</textarea>`; break;
    case "email": input = `<input type="email" id="${id}" name="${k}" value="${esc(val)}"${req}>`; break;
    case "url": input = `<input type="url" id="${id}" name="${k}" value="${esc(val)}" placeholder="https://"${req}>`; break;
    case "multi": {
      full = true;
      const sel = String(val).split(",");
      input = `<div class="checks">${Object.entries(EN(f.opts)).map(([o, l]) => `<label><input type="checkbox" name="${k}" value="${esc(o)}"${sel.includes(o) ? " checked" : ""}> ${esc(l)}</label>`).join("")}</div>`;
      break;
    }
    case "enum": case "ref": input = `<select id="${id}" name="${k}"${req}>${optionsFor(entity, k, f, v == null ? "" : String(v), vals)}</select>`; break;
    default: input = `<input type="text" id="${id}" name="${k}" value="${esc(val)}"${req}>`;
  }
  const when = f.when ? ` data-when="${f.when[0]}:${f.when[1]}"` : "";
  return `<div class="fld${full ? " full" : ""}"${when}><label for="${id}">${esc(f.l)}${f.req ? ' <span class="req">*</span>' : ""}</label>${input}${f.help ? `<small>${esc(f.help)}</small>` : ""}</div>`;
}
function readForm(form, entity) {
  const out = {};
  for (const [k, f] of Object.entries(SC(entity).fields)) {
    if (f.t === "multi") { out[k] = $$(`input[name="${k}"]:checked`, form).map(i => i.value); continue; }
    const el = form.elements[k];
    if (!el) continue;
    out[k] = el.value;
    if ((f.t === "int" || f.t === "money") && el.value === "") out[k] = 0;
  }
  return out;
}
function applyWhen(form) {
  for (const el of $$("[data-when]", form)) {
    const [k, v] = el.dataset.when.split(":");
    el.hidden = !form.elements[k] || form.elements[k].value !== v;
  }
}
const FORM_TITLES = { contacts: "lead / contact", inquiries: "question / inquiry", projects: "projet", posts: "publication", social_stats: "relevé de performances", campaigns: "campagne publicitaire", ad_results: "résultats publicitaires", pages: "page", web_stats: "statistiques de page", emails: "campagne email", funnel_entries: "chiffres de funnel" };

function openForm(entity, row = null, preset = {}) {
  if (entity === "funnels") return openFunnelEditor(row ? row.id : 0);
  if (entity === "funnel_entries" && !REF("funnels").some(f => f.stages.some(s => s.source === "manual"))) return toast("Aucune étape de funnel en saisie manuelle.");
  for (const [k, f] of Object.entries(SC(entity).fields)) {
    if (f.t === "ref" && f.req && f.ref !== "funnel_stages" && !REF(f.ref).length) {
      return toast(`Créez d'abord ${f.ref === "campaigns" ? "une campagne publicitaire" : f.ref === "pages" ? "une page (site ou landing page)" : "un funnel"}.`);
    }
  }
  const vals = Object.assign(defaults(entity), row || {}, preset || {});
  const fields = SC(entity).fields;
  openModal(`<h2>${row ? "Modifier" : "Ajouter"} : ${esc(FORM_TITLES[entity] || SC(entity).label)}</h2>
    <form id="mForm" novalidate><div class="form">${Object.entries(fields).map(([k, f]) => fieldHtml(entity, k, f, vals[k], vals)).join("")}</div>
    <p class="form-err" id="mErr"></p>
    <div class="form-foot">${row ? `<button type="button" class="btn del" data-del="${entity}:${row.id}">Supprimer</button><span style="flex:1"></span>` : ""}<button type="button" class="btn" data-close>Annuler</button>${row ? "" : '<button type="submit" class="btn" data-again>Enregistrer et ajouter un autre</button>'}<button type="submit" class="btn pri">Enregistrer</button></div></form>`);
  const form = $("#mForm");
  applyWhen(form);
  form.addEventListener("change", e => {
    applyWhen(form);
    if (entity === "funnel_entries" && e.target.name === "funnel_id") {
      form.elements.stage_id.innerHTML = optionsFor(entity, "stage_id", fields.stage_id, "", { funnel_id: e.target.value });
    }
  });
  form.addEventListener("submit", async e => {
    e.preventDefault();
    const again = e.submitter && e.submitter.hasAttribute("data-again");
    $("#mErr").textContent = "";
    for (const [k, f] of Object.entries(fields)) {
      const el = form.elements[k];
      if (f.req && el && !el.value && !(el.closest("[data-when]") || {}).hidden) { $("#mErr").textContent = f.l + " : champ obligatoire."; el.focus(); return; }
    }
    const data = readForm(form, entity);
    try {
      await api("save", {}, { entity, id: row ? +row.id : 0, row: data });
      toast(row ? "Modifié ✓" : "Ajouté ✓");
      if (again) {
        const keep = {};
        for (const k of ["date", "source", "platform", "campaign_id", "page_id", "funnel_id", "type", "assigned_to"]) if (k in data) keep[k] = data[k];
        afterMutation(entity);
        openForm(entity, null, keep);
      } else {
        closeModal();
        afterMutation(entity);
      }
    } catch (err) { $("#mErr").textContent = err.message; }
  });
}

/* ---------- éditeur de funnel : étapes, tags, sources, associations */
const FUNNEL_TEMPLATE = [
  { name: "Publicité", source: "ads_clicks", target: 0, tags: "" },
  { name: "Landing Page", source: "page_visits", target: 0, tags: "" },
  { name: "Inscription", source: "page_registrations", target: 0, tags: "" },
  { name: "Investissement", source: "manual", target: 0, tags: "" },
];
function openFunnelEditor(id) {
  const f = id ? REF("funnels").find(x => +x.id === +id) : null;
  let stages = (f ? f.stages : FUNNEL_TEMPLATE).map(s => ({ id: s.id || 0, name: s.name, source: s.source, target: +s.target || 0, tags: s.tags || "" }));
  const links = f ? f.links : { campaigns: [], pages: [], emails: [], social: [] };
  const vals = Object.assign(defaults("funnels"), f || {});
  const srcOpts = v => Object.entries(EN("stage_sources")).map(([k, l]) => `<option value="${k}"${k === v ? " selected" : ""}>${esc(l)}</option>`).join("");
  const box = (t, label, list) => `<div class="fld"><label>${label}</label><div class="linkbox">${list.length ? list.map(x => `<label><input type="checkbox" name="link_${t}" value="${x.id}"${links[t].includes(+x.id) ? " checked" : ""}> ${esc(x.name)}</label>`).join("") : '<span class="muted small">Rien à associer pour l\'instant.</span>'}</div></div>`;
  openModal(`<h2>${f ? "Modifier le funnel" : "Créer un funnel"}</h2><form id="fForm" novalidate>
    <div class="form">${Object.entries(SC("funnels").fields).map(([k, fd]) => fieldHtml("funnels", k, fd, vals[k], vals)).join("")}</div>
    <h3>Étapes & objectifs</h3>
    <p class="small muted" style="margin-top:-4px">Chaque étape prend ses chiffres dans une saisie manuelle, ou automatiquement dans les campagnes, pages, emails ou réseaux associés ci-dessous. Tags séparés par des virgules.</p>
    <div class="stage-row stage-head"><span></span><span>Nom de l'étape</span><span>Source des chiffres</span><span>Objectif</span><span>Tags</span><span></span></div>
    <div class="stages" id="stagesBox"></div>
    <button type="button" class="btn sm" id="addStage" style="margin-top:8px">＋ Ajouter une étape</button>
    <h3>Associations</h3>
    <div class="form">${box("campaigns", "Campagnes publicitaires", REF("campaigns"))}${box("pages", "Landing pages / site", REF("pages"))}${box("emails", "Campagnes email", REF("emails"))}<div class="fld"><label>Réseaux sociaux</label><p class="small muted" style="margin:0">Cochés plus haut (« Réseaux sociaux associés »).</p></div></div>
    <p class="form-err" id="fErr"></p>
    <div class="form-foot"><button type="button" class="btn" data-close>Annuler</button><button type="submit" class="btn pri">Enregistrer le funnel</button></div></form>`);
  const form = $("#fForm");
  const sync = () => {
    stages = $$(".stage-row[data-i]", form).map(r => ({ id: +r.dataset.id || 0, name: $("[data-k=name]", r).value, source: $("[data-k=source]", r).value, target: +$("[data-k=target]", r).value || 0, tags: $("[data-k=tags]", r).value }));
  };
  const draw = () => {
    $("#stagesBox").innerHTML = stages.map((s, i) => `<div class="stage-row" data-i="${i}" data-id="${s.id}"><span class="num">${i + 1}</span>
      <input type="text" data-k="name" value="${esc(s.name)}" placeholder="ex. Landing Page" aria-label="Nom de l'étape ${i + 1}">
      <select data-k="source" class="src" aria-label="Source des chiffres">${srcOpts(s.source)}</select>
      <input type="number" data-k="target" min="0" value="${s.target || ""}" placeholder="—" aria-label="Objectif">
      <input type="text" data-k="tags" value="${esc(s.tags)}" placeholder="ex. meta" aria-label="Tags">
      <span class="ctrl"><button type="button" class="icon-btn" data-mv="${i}:-1" title="Monter">↑</button><button type="button" class="icon-btn" data-mv="${i}:1" title="Descendre">↓</button><button type="button" class="icon-btn del" data-rm="${i}" title="Retirer">✕</button></span></div>`).join("");
  };
  draw();
  form.addEventListener("click", e => {
    const mv = e.target.closest("[data-mv]"), rm = e.target.closest("[data-rm]");
    if (e.target.id === "addStage") { sync(); stages.push({ id: 0, name: "", source: "manual", target: 0, tags: "" }); draw(); $$("[data-k=name]", form).pop().focus(); }
    if (mv) { sync(); const [i, d] = mv.dataset.mv.split(":").map(Number); const j = i + d; if (j >= 0 && j < stages.length) { [stages[i], stages[j]] = [stages[j], stages[i]]; draw(); } }
    if (rm) { sync(); if (stages.length > 1 && (!stages[+rm.dataset.rm].id || confirm("Retirer cette étape ? Ses chiffres saisis à la main seront supprimés."))) { stages.splice(+rm.dataset.rm, 1); draw(); } }
  });
  form.addEventListener("submit", async e => {
    e.preventDefault();
    sync();
    const data = readForm(form, "funnels");
    if (!data.name.trim()) { $("#fErr").textContent = "Donnez un nom au funnel."; return; }
    const ln = t => $$(`input[name="link_${t}"]:checked`, form).map(i => +i.value);
    try {
      const d = await api("funnel_save", {}, { id: f ? +f.id : 0, funnel: data, stages, links: { campaigns: ln("campaigns"), pages: ln("pages"), emails: ln("emails") } });
      closeModal();
      toast(f ? "Funnel modifié ✓" : "Funnel créé ✓");
      if (!f) location.hash = "#funnels/stages/" + d.funnel.id;
      afterMutation("funnels");
    } catch (err) { $("#fErr").textContent = err.message; }
  });
}

/* ================================================================ actions globales */
document.addEventListener("click", async e => {
  const t = e.target.closest("[data-add],[data-edit],[data-del],[data-close],[data-funnel-edit],[data-shift],[data-report],[data-archive-del]");
  if (!t) return;
  if (t.hasAttribute("data-close")) return closeModal();
  if (t.dataset.add) {
    let preset = {};
    try { preset = t.dataset.preset ? JSON.parse(t.dataset.preset) : {}; } catch (err) {}
    $("#addMenu").hidden = true;
    return openForm(t.dataset.add, null, preset);
  }
  if (t.dataset.edit) {
    const [ent, id] = t.dataset.edit.split(":");
    let row = (S.rows[ent] || {})[id] || REF(ent).find(x => +x.id === +id);
    if (!row) {
      try { row = remember(ent, (await api("list", { entity: ent })).rows).find(x => +x.id === +id); } catch (err) {}
    }
    if (!row) return toast("Élément introuvable, rechargez la page.");
    let preset = {};
    try { preset = t.dataset.preset ? JSON.parse(t.dataset.preset) : {}; } catch (err) {}
    return openForm(ent, row, preset);
  }
  if (t.dataset.funnelEdit !== undefined) return openFunnelEditor(+t.dataset.funnelEdit);
  if (t.dataset.del) {
    const [ent, id] = t.dataset.del.split(":");
    const warn = { campaigns: "Supprimer cette campagne et tous ses résultats saisis ?", pages: "Supprimer cette page et toutes ses statistiques ?", funnels: "Supprimer ce funnel, ses étapes et ses chiffres saisis ?", projects: "Supprimer ce projet et son historique de financement ?" }[ent] || "Supprimer définitivement cette ligne ?";
    if (!confirm(warn)) return;
    try {
      await api("delete", {}, { entity: ent, id: +id });
      closeModal();
      toast("Supprimé");
      if (ent === "campaigns" && location.hash.startsWith("#ads/campaign/")) location.hash = "#ads/campaigns";
      afterMutation(ent);
    } catch (err) { toast(err.message); }
    return;
  }
  if (t.dataset.shift) {
    const [tab, type, date, dir] = t.dataset.shift.split(":");
    location.hash = "#overview/" + tab + "/" + shiftDate(type, date, +dir);
    return;
  }
  if (t.dataset.archiveDel) {
    if (!confirm("Supprimer ce rapport archivé ?")) return;
    try { await api("archive_delete", {}, { id: +t.dataset.archiveDel }); route(); } catch (err) { toast(err.message); }
    return;
  }
  if (t.dataset.report) {
    const [act, type, date] = t.dataset.report.split(":");
    if (act === "print") return window.print();
    if (act === "csv") return reportCsv(S.lastReport);
    if (act === "archive") {
      try { await api("archive_save", {}, { type, date }); toast("Rapport archivé ✓ (Overview → Archives)"); } catch (err) { toast(err.message); }
      return;
    }
    if (act === "send") {
      const rec = S.boot.settings.recipients;
      const ans = prompt("Envoyer ce rapport à (adresses séparées par des virgules) :", rec.join(", "));
      if (ans === null) return;
      const emails = ans.split(/[\s,;]+/).filter(Boolean);
      if (!emails.length) return toast("Aucune adresse.");
      t.disabled = true;
      try { const d = await api("send_report", {}, { type, date, emails }); toast("Rapport envoyé à " + d.sent.join(", ") + (d.errors.length ? " — échecs : " + d.errors.join(" ; ") : "")); }
      catch (err) { toast(err.message); }
      t.disabled = false;
    }
  }
});
document.addEventListener("change", async e => {
  const t = e.target;
  if (t.dataset.pick) { if (isDate(t.value)) location.hash = "#overview/" + t.dataset.pick + "/" + t.value; return; }
  if (t.dataset.goto) { location.hash = "#" + t.dataset.goto + "/" + t.value; return; }
  if (!t.dataset.patch) return;
  const [ent, id, field] = t.dataset.patch.split(":");
  const row = (S.rows[ent] || {})[id];
  // « Non qualifié » : on ouvre la fiche pour demander pourquoi.
  if (ent === "contacts" && field === "status" && t.value === "non_qualifie" && row) {
    const prev = row.status;
    t.value = prev;
    return openForm("contacts", row, { status: "non_qualifie" });
  }
  t.disabled = true;
  try {
    const d = await api("patch", {}, { entity: ent, id: +id, field, value: t.value });
    if (row) Object.assign(row, d.row);
    toast("Enregistré ✓");
    S.cache.clear();
  } catch (err) { toast(err.message); route(); }
  t.disabled = false;
});

function reportCsv(r) {
  if (!r) return;
  const q = v => '"' + String(v == null ? "" : v).replace(/"/g, '""') + '"';
  const lines = [[r.org + " — Rapport " + r.type_label, r.period.label].map(q).join(";"), "", ["Indicateur", "Valeur", "Période précédente", "Variation"].map(q).join(";")];
  for (const k of r.kpis) lines.push([k.label, k.value == null ? "" : String(k.fmt === "pct" ? (k.value * 100).toFixed(1) + " %" : Math.round(k.value * 100) / 100).replace(".", ","), k.prev == null ? "" : String(k.fmt === "pct" ? (k.prev * 100).toFixed(1) + " %" : Math.round(k.prev * 100) / 100).replace(".", ","), kdelta(k)[0]].map(q).join(";"));
  lines.push("", q("Analyse & décisions"));
  for (const i of r.insights) lines.push([LV[i.level][1], i.text].map(q).join(";"));
  const blob = new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "rapport-" + r.type + "-" + r.period.from + ".csv";
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

/* ---------- info-bulles */
const tip = $("#tip");
document.addEventListener("mouseover", e => {
  const t = e.target.closest("[data-tip]");
  if (!t) { tip.hidden = true; return; }
  tip.innerHTML = t.dataset.tip;
  tip.hidden = false;
});
document.addEventListener("mousemove", e => {
  if (tip.hidden) return;
  const w = tip.offsetWidth, h = tip.offsetHeight;
  let x = e.clientX + 14, y = e.clientY + 14;
  if (x + w > innerWidth - 8) x = e.clientX - w - 14;
  if (y + h > innerHeight - 8) y = e.clientY - h - 14;
  tip.style.left = Math.max(8, x) + "px";
  tip.style.top = Math.max(8, y) + "px";
});
document.addEventListener("scroll", () => { tip.hidden = true; }, true);

/* ---------- menu « Ajouter » */
const ADD = [["contacts", "👥 Lead / contact"], ["inquiries", "💬 Question / inquiry"], ["projects", "📁 Demande de projet"], ["posts", "📱 Publication social media"], "-",
  ["social_stats", "📈 Performances d'un réseau"], ["ad_results", "📢 Résultats publicitaires"], ["web_stats", "🌐 Statistiques de page"], ["emails", "✉️ Campagne email"], ["funnel_entries", "📊 Chiffres de funnel"], "-",
  ["campaigns", "🎯 Campagne publicitaire"], ["pages", "🔗 Page / landing page"], ["funnels", "📊 Funnel"]];
$("#addMenu").innerHTML = ADD.map(a => a === "-" ? "<hr>" : `<button type="button" data-add="${a[0]}">${a[1]}</button>`).join("");
$("#addBtn").addEventListener("click", e => { e.stopPropagation(); $("#addMenu").hidden = !$("#addMenu").hidden; });
document.addEventListener("click", e => { if (!e.target.closest(".addmenu")) $("#addMenu").hidden = true; });

/* ---------- thème */
function applyTheme() {
  let t = null;
  try { t = localStorage.getItem("mk_theme"); } catch (e) {}
  if (t) document.documentElement.dataset.theme = t; else delete document.documentElement.dataset.theme;
}
$("#themeBtn").addEventListener("click", () => {
  const dark = document.documentElement.dataset.theme ? document.documentElement.dataset.theme === "dark" : matchMedia("(prefers-color-scheme: dark)").matches;
  try { localStorage.setItem("mk_theme", dark ? "light" : "dark"); } catch (e) {}
  applyTheme();
});
applyTheme();

/* ================================================================ démarrage */
function showLogin() { $("#app").hidden = true; $("#loginView").hidden = false; closeModal(); }
async function start() {
  const d = await api("bootstrap");
  S.boot = d;
  S.today = d.today;
  $("#orgName").textContent = d.settings.org;
  $("#loginView").hidden = true;
  $("#app").hidden = false;
  route();
}
$("#loginForm").addEventListener("submit", async e => {
  e.preventDefault();
  $("#loginErr").textContent = "";
  try {
    const d = await call("../admin/api.php?action=login", { password: e.target.password.value });
    S.csrf = d.csrf;
    e.target.reset();
    await start();
  } catch (err) { $("#loginErr").textContent = err.message; }
});
$("#logoutBtn").addEventListener("click", async () => {
  try { await call("../admin/api.php?action=logout", {}); } catch (e) {}
  S.boot = null;
  showLogin();
});
call("../admin/api.php?action=session").then(d => { S.csrf = d.csrf; return start(); }).catch(e => { if (e.status !== 401) toast(e.message); showLogin(); });
})();
